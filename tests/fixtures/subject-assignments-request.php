<?php
if (!defined('SYNK_SUBJECT_ASSIGNMENTS_QA')) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__, 2);
require_once $root . '/backend/db.php';
require_once $root . '/backend/subject_assignments_helper.php';

class SubjectAssignmentsQAConnection extends mysqli
{
    public $viewing = false;
    public $legacyWorkloadPage = false;
    #[\ReturnTypeWillChange]
    public function query($sql, $mode = MYSQLI_STORE_RESULT) {
        // Existing workload helpers may install tables. Skip only existing-table no-ops
        // while rendering that legacy page, so a fixture cannot be implicitly committed.
        if ($this->viewing && $this->legacyWorkloadPage && preg_match('/^\s*CREATE TABLE IF NOT EXISTS `?([a-z0-9_]+)`?/i', $sql, $match)) {
            $exists = parent::query("SHOW TABLES LIKE '" . $this->real_escape_string($match[1]) . "'");
            if ($exists && $exists->num_rows) return true;
        }
        if ($this->viewing && !preg_match('/^\s*(SELECT|SHOW)\b/i', $sql)) throw new RuntimeException('Viewing attempted a database mutation.');
        return parent::query($sql, $mode);
    }
    #[\ReturnTypeWillChange]
    public function prepare($sql) {
        if ($this->viewing && !preg_match('/^\s*(SELECT|SHOW)\b/i', $sql)) throw new RuntimeException('Viewing attempted a prepared database mutation.');
        return parent::prepare($sql);
    }
}
$conn->close();
$conn = new SubjectAssignmentsQAConnection($servername, $username, $password, $dbase);
$conn->set_charset('utf8mb4');
$conn->begin_transaction();
$scenario = (string)($_GET['scenario'] ?? 'list');
$input = $_POST;
$tag = 'SAQA' . bin2hex(random_bytes(5));
$term = synk_fetch_current_academic_term($conn);
$joins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
$template = $conn->query("SELECT o.*,p.college_id,pys.pys_id FROM tbl_prospectus_offering o {$joins}
    JOIN tbl_program p ON p.program_id=o.program_id WHERE o.ay_id={$term['ay_id']} AND o.semester={$term['semester']}
    AND ps.lec_units>0 AND ps.lab_units>0 ORDER BY o.offering_id LIMIT 1")->fetch_assoc();
if (!$template) throw new RuntimeException('A current lecture/lab offering is required for fixtures.');
$college = (int)$template['college_id'];
$foreignCollege = (int)$conn->query("SELECT college_id FROM tbl_college WHERE status='active' AND college_id<>{$college} LIMIT 1")->fetch_assoc()['college_id'];
$clone = static function(string $table, array $row, array $values) use ($conn): int {
    $fields = []; $params = [];
    foreach ($conn->query('SHOW COLUMNS FROM `' . $table . '`')->fetch_all(MYSQLI_ASSOC) as $column) {
        if (stripos($column['Extra'], 'auto_increment') !== false || stripos($column['Extra'], 'generated') !== false) continue;
        $field = $column['Field'];
        if (!array_key_exists($field, $values) && !array_key_exists($field, $row)) continue;
        $fields[] = '`' . $field . '`'; $params[] = array_key_exists($field, $values) ? $values[$field] : $row[$field];
    }
    $stmt = $conn->prepare('INSERT INTO `' . $table . '` (' . implode(',', $fields) . ') VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')');
    synk_bind_dynamic_params($stmt, str_repeat('s', count($params)), $params);
    $stmt->execute(); $id = (int)$stmt->insert_id; $stmt->close(); return $id;
};
$accountRow = $conn->query("SELECT * FROM tbl_useraccount WHERE status='active' LIMIT 1")->fetch_assoc();
$user = $clone('tbl_useraccount', $accountRow, ['username'=>$tag, 'email'=>$tag . '@qa.invalid', 'google_sub'=>null, 'role'=>'professor', 'college_id'=>$college]);
$conn->query("INSERT INTO tbl_useraccount_roles (user_id,role,is_primary,status) VALUES ({$user},'scheduler',0,'active'),({$user},'professor',1,'active')");
$conn->query("INSERT INTO tbl_user_college_access (user_id,college_id,is_default,status) VALUES ({$user},{$college},1,'active')");
$programRow = $conn->query('SELECT * FROM tbl_program WHERE program_id=' . (int)$template['program_id'])->fetch_assoc();
$headerRow = $conn->query('SELECT * FROM tbl_prospectus_header WHERE prospectus_id=' . (int)$template['prospectus_id'])->fetch_assoc();
$yearRow = $conn->query('SELECT * FROM tbl_prospectus_year_sem WHERE pys_id=' . (int)$template['pys_id'])->fetch_assoc();
$psRow = $conn->query('SELECT * FROM tbl_prospectus_subjects WHERE ps_id=' . (int)$template['ps_id'])->fetch_assoc();
$sectionRow = $conn->query('SELECT * FROM tbl_sections WHERE section_id=' . (int)$template['section_id'])->fetch_assoc();
$programs = []; $headers = []; $years = [];
foreach ([1=>$college, 2=>$college, 3=>$foreignCollege] as $i=>$ownerCollege) {
    $programs[$i] = $clone('tbl_program', $programRow, ['college_id'=>$ownerCollege, 'program_code'=>$tag . 'P' . $i, 'program_name'=>'QA Program ' . $i, 'major'=>'Niño']);
    $headers[$i] = $clone('tbl_prospectus_header', $headerRow, ['program_id'=>$programs[$i]]);
    $years[$i] = $clone('tbl_prospectus_year_sem', $yearRow, ['prospectus_id'=>$headers[$i]]);
}
$smRow = $conn->query('SELECT * FROM tbl_subject_masterlist WHERE sub_id=' . (int)$psRow['sub_id'])->fetch_assoc();
$subject = $clone('tbl_subject_masterlist', $smRow, ['sub_code'=>'QA 111', 'sub_description'=>'Computación Niño <img src=x onerror=alert(1)>']);
$different = $clone('tbl_subject_masterlist', $smRow, ['sub_code'=>'QA 111', 'sub_description'=>'Different Description']);
$neededSubject = $clone('tbl_subject_masterlist', $smRow, ['sub_code'=>'QA NEED', 'sub_description'=>'Staffing Required']);
$makeOffering = static function(int $i, int $subId, string $suffix, bool $lab = true, int $semester = 0) use ($conn, $clone, $programs, $headers, $years, $yearRow, $psRow, $sectionRow, $template, $tag, $user, $term): int {
    $semester = $semester ?: $term['semester'];
    $pys = $semester === $term['semester'] ? $years[$i] : $clone('tbl_prospectus_year_sem', $yearRow, ['prospectus_id'=>$headers[$i], 'semester'=>$semester]);
    $ps = $clone('tbl_prospectus_subjects', $psRow, ['pys_id'=>$pys, 'sub_id'=>$subId, 'lab_units'=>$lab ? $psRow['lab_units'] : 0]);
    $section = $clone('tbl_sections', $sectionRow, ['program_id'=>$programs[$i], 'section_name'=>substr($tag, -5) . $suffix,
        'full_section'=>$tag . $suffix, 'semester'=>$semester]);
    $conn->query("INSERT INTO tbl_section_curriculum (section_id,prospectus_id,assigned_by) VALUES ({$section},{$headers[$i]},{$user})");
    return $clone('tbl_prospectus_offering', $template, ['program_id'=>$programs[$i], 'prospectus_id'=>$headers[$i], 'ps_id'=>$ps,
        'section_id'=>$section, 'semester'=>$semester, 'status'=>'pending']);
};
$a = $makeOffering(1, $subject, 'A'); $b = $makeOffering(1, $subject, 'B');
$d = $makeOffering(2, $subject, 'D'); $e = $makeOffering(2, $subject, 'E');
$unassigned = $makeOffering(1, $different, 'U', false);
$needed = $makeOffering(1, $neededSubject, 'N', false);
$foreign = $makeOffering(3, $subject, 'F');
$oldSemester = $term['semester'] === 1 ? 2 : 1;
$historical = $makeOffering(1, $subject, 'H', false, $oldSemester);
$inactive = $makeOffering(1, $subject, 'I');
$conn->query("UPDATE tbl_sections SET status='inactive' WHERE section_id=(SELECT section_id FROM tbl_prospectus_offering WHERE offering_id={$inactive})");
$facultyRow = $conn->query("SELECT * FROM tbl_faculty WHERE status='active' LIMIT 1")->fetch_assoc();
$f1 = $clone('tbl_faculty', $facultyRow, ['last_name'=>'Peña', 'first_name'=>'José', 'middle_name'=>'Niño', 'ext_name'=>'']);
$f2 = $clone('tbl_faculty', $facultyRow, ['last_name'=>'Muñoz', 'first_name'=>'Ana', 'middle_name'=>'', 'ext_name'=>'']);
$f3 = $clone('tbl_faculty', $facultyRow, ['last_name'=>'Inactive QA', 'first_name'=>'Hidden', 'status'=>'inactive']);
foreach ([$f1,$f2] as $fid) $conn->query("INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status) VALUES ({$college},{$fid},{$term['ay_id']},{$term['semester']},'active')");
$makeSchedule = static function(int $offering, string $type, int $faculty = 0, string $status = 'tba', int $semester = 0) use ($conn, $term, $user): int {
    $semester = $semester ?: $term['semester'];
    $days = $status === 'tba' ? '[]' : '["M","W"]';
    $start = $status === 'tba' ? null : '09:00:00'; $end = $status === 'tba' ? null : '10:00:00';
    $stmt = $conn->prepare('INSERT INTO tbl_class_schedule (offering_id,schedule_type,schedule_status,days_json,time_start,time_end,created_by) VALUES (?,?,?,?,?,?,?)');
    $stmt->bind_param('isssssi', $offering, $type, $status, $days, $start, $end, $user);
    $stmt->execute(); $sid = (int)$stmt->insert_id; $stmt->close();
    if ($faculty) $conn->query("INSERT INTO tbl_faculty_workload_sched (schedule_id,faculty_id,ay_id,semester) VALUES ({$sid},{$faculty},{$term['ay_id']},{$semester})");
    return $sid;
};
$aLec = $makeSchedule($a, 'LEC', $f1, 'room_tba'); $makeSchedule($a, 'LEC', $f1, 'room_tba');
$aLab = $makeSchedule($a, 'LAB', $f2, 'room_tba');
$bLec = $makeSchedule($b, 'LEC', $f1); $bLab = $makeSchedule($b, 'LAB');
$makeSchedule($a, 'LAB', $f3);
$dLab = $makeSchedule($d, 'LAB', $f1);
$makeSchedule($d, 'LEC', $f2); // A stale local block must be ignored when lecture is inherited.
$makeSchedule($foreign, 'LEC', $f2); $makeSchedule($inactive, 'LEC', $f2);
$hLec = $makeSchedule($historical, 'LEC', $f1, 'tba', $oldSemester);
$wrongTerm = $makeSchedule($unassigned, 'LEC', $f2, 'tba', $oldSemester);
$need = $clone('tbl_faculty_need', [], ['college_id'=>$college, 'ay_id'=>$term['ay_id'], 'semester'=>$term['semester'], 'need_label'=>$tag . ' FACULTY NEED', 'status'=>'active']);
$needSchedule = $makeSchedule($needed, 'LEC');
foreach ([$bLab,$needSchedule] as $sid) $conn->query("INSERT INTO tbl_faculty_need_workload_sched (faculty_need_id,schedule_id,ay_id,semester) VALUES ({$need},{$sid},{$term['ay_id']},{$term['semester']})");
$conn->query("INSERT INTO tbl_class_schedule_merge (owner_offering_id,member_offering_id,merge_scope,created_by) VALUES ({$a},{$d},'LEC',{$user}),({$d},{$e},'FULL',{$user})");
if ($scenario === 'pagination') {
    for ($i=1; $i<=28; $i++) {
        $sub = $clone('tbl_subject_masterlist', $smRow, ['sub_code'=>'QA PAGE ' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), 'sub_description'=>'Pagination Subject']);
        $makeOffering(1, $sub, 'P' . $i, false);
    }
}
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION = ['user_id'=>$user, 'role'=>'scheduler', 'college_id'=>$college, 'csrf_token'=>$tag];
$query = ['college_id'=>$college, 'action'=>'list', 'term'=>$term['ay_id'] . ':' . $term['semester'], 'program_id'=>$programs[1]];
$handler = 'backend/query_subject_assignments.php';
switch ($scenario) {
    case 'options': $query['action']='options'; break;
    case 'detail': case 'historical': case 'merged': case 'chain': case 'foreign_detail':
        $query['action']='detail'; $query['subject_id']=$subject;
        if (in_array($scenario, ['merged','chain'], true)) $query['program_id']=$programs[2];
        if ($scenario==='historical') $query['term']=$term['ay_id'] . ':' . $oldSemester;
        if ($scenario==='foreign_detail') $query['subject_id']=2147483647;
        break;
    case 'wrong_role': $_SESSION['role']='professor'; break;
    case 'inactive_account': $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$user}"); break;
    case 'revoked_role': $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$user} AND role='scheduler'"); break;
    case 'revoked_college':
        $conn->query("UPDATE tbl_user_college_access SET status='inactive' WHERE user_id={$user}");
        $conn->query("UPDATE tbl_useraccount SET college_id=NULL WHERE user_id={$user}"); break;
    case 'foreign_program': $query['program_id']=$programs[3]; break;
    case 'foreign_college': $query['college_id']=$foreignCollege; break;
    case 'bad_term': $query['term']='2147483647:1'; break;
    case 'bad_array': $query['search']=['invalid']; break;
    case 'bad_search': $query['search']=str_repeat('a', 151); break;
    case 'bad_status': $query['assignment']='invalid'; break;
    case 'bad_page': $query['page']='-1'; break;
    case 'post': break;
    case 'page': $handler='scheduler/subject-assignments.php'; break;
    case 'workload_page': $handler='scheduler/manage-workload.php';
        $query=['faculty_id'=>$f1, 'ay_id'=>$term['ay_id'], 'semester'=>$term['semester'], 'college_id'=>$college]; break;
    case 'list': case 'pagination': break;
    default: throw new RuntimeException('Unknown subject assignment fixture.');
}
$query = array_merge($query, $input);
$known = ['college_id'=>$college, 'program'=>$programs[1], 'other_program'=>$programs[2], 'foreign_program'=>$programs[3],
    'subject'=>$subject, 'different_subject'=>$different, 'need_subject'=>$neededSubject, 'faculty'=>[$f1,$f2], 'term'=>$term['ay_id'] . ':' . $term['semester'],
    'old_term'=>$term['ay_id'] . ':' . $oldSemester, 'offering_a'=>$a, 'offering_b'=>$b, 'offering_d'=>$d, 'offering_e'=>$e, 'need'=>$need, 'tag'=>$tag];
$take = static function() use ($conn): array {
    $data=[];
    foreach (['tbl_class_schedule','tbl_faculty_workload_sched','tbl_class_schedule_merge','tbl_faculty_need','tbl_faculty_need_workload_sched','tbl_useraccount_roles','tbl_user_college_access'] as $table) {
        $data[$table]=hash('sha256', json_encode($conn->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }
    return $data;
};
$before=$take(); $conn->viewing=true; $conn->legacyWorkloadPage=$scenario==='workload_page'; $_GET=$query; $_POST=[];
$_SERVER['PHP_SELF']='/synk/' . $handler;
$_SERVER['REQUEST_METHOD']=$scenario==='post' ? 'POST' : 'GET';
session_write_close(); ob_start();
register_shutdown_function(static function() use ($conn,$take,$before,$known): void {
    $body=''; while (ob_get_level()>0) $body=ob_get_clean() . $body;
    try {
        $code=http_response_code(); $same=$take()===$before;
        $conn->viewing=false; $conn->rollback();
        if (session_status()===PHP_SESSION_ACTIVE) session_destroy();
        http_response_code(200); header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['response'=>json_decode($body,true) ?: $body, 'http_code'=>$code, 'unchanged'=>$same,
            'known'=>$known, 'php_version'=>PHP_VERSION], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $error) {
        $conn->viewing=false; $conn->rollback();
        http_response_code(500); echo json_encode(['fixture_error'=>$error->getMessage(), 'output'=>substr($body,0,1500)]);
    }
});
$source=file_get_contents($root . '/' . $handler);
$source=preg_replace('/(?:require_once|include)\s+(?:__DIR__\s*\.\s*)?[\'"](?:\.\.\/backend\/|\/)?db\.php[\'"];/', '', $source);
$source=str_replace('__DIR__',var_export(dirname($root . '/' . $handler),true),$source);
chdir(dirname($root . '/' . $handler)); eval('?>' . $source);
