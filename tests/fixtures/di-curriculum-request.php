<?php
// CLI-only fixtures; all database changes are rolled back after each request.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__, 2);
require_once $root . '/backend/db.php';
class DiCurriculumFixtureConnection extends mysqli
{
    public bool $failCopy = false;
    public function startFixtures(): void { parent::begin_transaction(); }
    public function discardFixtures(): void { parent::rollback(); }
    public function begin_transaction($flags = null, $name = null): bool { return parent::query('SAVEPOINT di_curriculum_write'); }
    public function commit($flags = null, $name = null): bool { return parent::query('RELEASE SAVEPOINT di_curriculum_write'); }
    public function rollback($flags = null, $name = null): bool { parent::query('ROLLBACK TO SAVEPOINT di_curriculum_write'); return parent::query('RELEASE SAVEPOINT di_curriculum_write'); }
    #[\ReturnTypeWillChange]
    public function prepare($sql) {
        if ($this->failCopy && preg_match('/^\s*INSERT INTO tbl_prospectus_subjects/i', $sql)) throw new RuntimeException('Injected copy failure.');
        return parent::prepare($sql);
    }
    #[\ReturnTypeWillChange]
    public function query($sql, $result_mode = MYSQLI_STORE_RESULT) {
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $sql)) throw new RuntimeException('Fixtures cannot change schema.');
        return parent::query($sql, $result_mode);
    }
}
$conn->close();
$conn = new DiCurriculumFixtureConnection($servername, $username, $password, $dbase);
$conn->set_charset('utf8mb4'); $conn->startFixtures();
$case = $argv[1] ?? '';
$tag = 'DIQA' . bin2hex(random_bytes(4));
$insert = static function (string $sql) use ($conn): int { $conn->query($sql); return (int)$conn->insert_id; };
$colleges = $conn->query("SELECT c.college_id,c.campus_id FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.status='active' AND cp.status='active' LIMIT 2")->fetch_all(MYSQLI_ASSOC);
[$college, $otherCollege] = array_column($colleges, 'college_id'); $campus = (int)$colleges[0]['campus_id'];
$actor = $insert("INSERT INTO tbl_useraccount(username,email,password,role,status) VALUES('{$tag}','{$tag}@sksu.edu.ph','unusable','DI','active')");
$conn->query("INSERT INTO tbl_useraccount_roles(user_id,role,status,is_primary) VALUES({$actor},'DI','active',1)");
$program = $insert("INSERT INTO tbl_program(college_id,program_code,program_name,major) VALUES({$college},'{$tag}','{$tag} Program','')");
$targetProgram = $insert("INSERT INTO tbl_program(college_id,program_code,program_name,major) VALUES({$otherCollege},'{$tag}B','{$tag} Other Program','')");
$unusedProgram = $insert("INSERT INTO tbl_program(college_id,program_code,program_name,major) VALUES({$college},'{$tag}C','{$tag} Unused Program','')");
$subA = $insert("INSERT INTO tbl_subject_masterlist(sub_code,sub_description) VALUES('{$tag}A','{$tag} Subject A')");
$subB = $insert("INSERT INTO tbl_subject_masterlist(sub_code,sub_description) VALUES('{$tag}B','{$tag} Subject B')");
$header = $insert("INSERT INTO tbl_prospectus_header(program_id,cmo_no,effective_sy) VALUES({$program},'{$tag} CMO','2026-2027')");
$yearSem = $insert("INSERT INTO tbl_prospectus_year_sem(prospectus_id,year_level,semester) VALUES({$header},'1','1')");
$ps = $insert("INSERT INTO tbl_prospectus_subjects(pys_id,sub_id,lec_units,lab_units,total_units,sort_order) VALUES({$yearSem},{$subA},3,3,4,1)");
session_save_path($root . '/temp'); session_start();
$_SESSION = ['user_id'=>$actor,'role'=>'DI','college_id'=>0,'csrf_token'=>'di-curriculum-qa','scheduler_working_ay_id'=>991,'scheduler_working_semester'=>2];
$_GET = []; $_POST = ['csrf_token'=>'di-curriculum-qa']; $_SERVER['REQUEST_METHOD'] = 'POST';
$action = preg_replace('/^(?:used_|csrf_|direct_|section_subject_|section_|saved_)/', '', $case);
$catalogs = ['program'=>['load_programs','save_program','update_program','delete_program'], 'subject'=>['load_subjects','save_subject','update_subject','delete_subject']];
$route = 'backend/query_di_prospectus.php';
foreach ($catalogs as $catalog=>$actions) if (in_array($action, $actions, true)) { $route = 'backend/query_di_catalog.php'; $_GET['catalog'] = $catalog; }
if (in_array($action, ['load_browser_filters','load_browser_prospectuses','load_prospectus_by_program','update_prospectus_header','transfer_prospectus','delete_prospectus'], true)) $route = 'backend/query_di_prospectus_browser.php';
if (in_array($action, ['copy_prospectus','copy_failure'], true)) { $route = 'backend/query_di_prospectus_copy.php'; $action = 'copy_prospectus'; }
$_POST[$action] = '1';
$_POST += ['program_id'=>(string)$program,'prospectus_id'=>(string)$header,'pys_id'=>(string)$yearSem,'ps_id'=>(string)$ps,'sub_id'=>(string)$subB,
    'college_id'=>(string)$college,'campus_id'=>(string)$campus,'program_code'=>$tag.'N','program_name'=>$tag.' New Program','major'=>'','status'=>'active',
    'sub_code'=>$tag.'N','sub_description'=>$tag.' New Subject','cmo_no'=>$tag.' New CMO','effective_sy'=>'2027-2028','year_level'=>'2','semester'=>'2',
    'lec_units'=>'3','lab_units'=>'3','sort_order'=>'2','prerequisite_sub_ids'=>json_encode([(string)$subA]),'source_prospectus_id'=>(string)$header,'target_program_id'=>(string)$targetProgram];
if ($action === 'update_prospectus_subject') $_POST['sub_id'] = (string)$subA;
if ($action === 'update_prospectus_subject') $_POST['prerequisite_sub_ids'] = json_encode([(string)$subB]);
if ($action === 'delete_subject') $_POST['sub_id'] = (string)$subB;
if ($action === 'delete_program') $_POST['program_id'] = (string)$unusedProgram;
if (strpos($case, 'used_') === 0) {
    if (in_array($action, ['delete_subject','update_subject'], true)) $_POST['sub_id'] = (string)$subA;
    if ($action === 'update_subject') $_POST['status'] = 'inactive';
    if ($action === 'delete_program') $_POST['program_id'] = (string)$program;
    if ($action === 'update_program') { $_POST['college_id'] = (string)$otherCollege; $_POST['campus_id'] = (string)$colleges[1]['campus_id']; $_POST['status'] = 'inactive'; }
    $offering = $insert("INSERT INTO tbl_prospectus_offering(program_id,prospectus_id,ps_id,year_level,semester) VALUES({$program},{$header},{$ps},'1','1')");
    $schedule = $insert("INSERT INTO tbl_class_schedule(offering_id,days_json,time_start,time_end) VALUES({$offering},'[\"M\"]','08:00:00','09:00:00')");
    $faculty = (int)$conn->query('SELECT faculty_id FROM tbl_faculty LIMIT 1')->fetch_assoc()['faculty_id'];
    $ay = (int)$conn->query('SELECT ay_id FROM tbl_academic_years LIMIT 1')->fetch_assoc()['ay_id'];
    $insert("INSERT INTO tbl_faculty_workload_sched(schedule_id,faculty_id,ay_id,semester) VALUES({$schedule},{$faculty},{$ay},1)");
}
if (strpos($case, 'csrf_') === 0) $_POST['csrf_token'] = 'wrong';
if (strpos($case, 'section_') === 0 || strpos($case, 'saved_') === 0) {
    $ay = (int)$conn->query('SELECT ay_id FROM tbl_academic_years LIMIT 1')->fetch_assoc()['ay_id'];
    $section = $insert("INSERT INTO tbl_sections(program_id,ay_id,semester,year_level,section_name,full_section,status) VALUES({$program},{$ay},1,1,'A','{$tag}','active')");
    if (strpos($case, 'section_subject_') === 0) $insert("INSERT INTO tbl_section_prospectus(section_id,p_sub_id) VALUES({$section},{$ps})");
    elseif (strpos($case, 'section_') === 0) $insert("INSERT INTO tbl_section_curriculum(section_id,prospectus_id,assigned_by) VALUES({$section},{$header},{$actor})");
    else {
        $set = $insert("INSERT INTO tbl_program_schedule_set(college_id,program_id,ay_id,semester,set_name,created_by) VALUES({$college},{$program},{$ay},1,'{$tag}',{$actor})");
        $insert("INSERT INTO tbl_program_schedule_set_row(schedule_set_id,offering_id,program_id,ps_id,section_id,room_id,days_json,time_start,time_end) VALUES({$set},2147483601,{$program},{$ps},{$section},0,'[\"M\"]','08:00:00','09:00:00')");
    }
}
if (strpos($case, 'direct_') === 0) $route = str_replace('query_di_', 'query_', $route);
if ($case === 'copy_failure') $conn->failCopy = true;
if ($case === 'anonymous') { $_SESSION=[]; unset($_POST['anonymous']); $_POST['load_header']='1'; }
if ($case === 'duplicate_subject') { unset($_POST[$case]); $_POST['save_subject']='1'; $_GET['catalog']='subject'; $route='backend/query_di_catalog.php'; $_POST['sub_code']=$tag.'A'; $_POST['sub_description']=$tag.' Subject A'; }
if ($case === 'duplicate_program') { unset($_POST[$case]); $_POST['save_program']='1'; $_GET['catalog']='program'; $route='backend/query_di_catalog.php'; $_POST['program_code']=$tag; }
if (strpos($case, 'programs_') === 0) {
    unset($_POST[$case]); $_POST['load_programs']='1'; $_GET['catalog']='program'; $route='backend/query_di_catalog.php';
    $_POST['college_id']='';
    if ($case==='programs_college') $_POST['college_id']=(string)$college;
    if ($case==='programs_missing_campus') unset($_POST['campus_id']);
    if ($case==='programs_wrong_college') $_POST['college_id']='2147483647';
    if ($case==='programs_foreign_college') $_POST['college_id']=(string)$conn->query("SELECT college_id FROM tbl_college WHERE campus_id<>{$campus} LIMIT 1")->fetch_assoc()['college_id'];
    if ($case==='programs_inactive') $conn->query("UPDATE tbl_program SET status='inactive' WHERE program_id={$unusedProgram}");
}
if (in_array($case,['program_wrong_campus','program_reactivate'],true)) {
    unset($_POST[$case]); $_GET['catalog']='program'; $route='backend/query_di_catalog.php';
    if ($case==='program_wrong_campus') { $_POST['delete_program']='1'; $_POST['campus_id']='2147483647'; }
    else { $_POST['update_program']='1'; $conn->query("UPDATE tbl_program SET status='inactive' WHERE program_id={$program}"); }
}
if (in_array($case, ['wrong_role','revoked','inactive','method','array_input','multiple_actions','bad_hours','self_prerequisite'], true)) {
    unset($_POST[$case]); $_POST['save_prospectus_subject']='1';
    if ($case === 'wrong_role') $_SESSION['role']='professor';
    if ($case === 'revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$actor}");
    if ($case === 'inactive') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$actor}");
    if ($case === 'method') $_SERVER['REQUEST_METHOD']='GET';
    if ($case === 'array_input') $_POST['pys_id']=[$yearSem];
    if ($case === 'multiple_actions') $_POST['delete_year_sem']='1';
    if ($case === 'bad_hours') $_POST['lab_units']='-1';
    if ($case === 'self_prerequisite') $_POST['prerequisite_sub_ids']=json_encode([(string)$subB]);
}
if (strpos($case, 'page_') === 0) {
    $route='di/'.substr($case,5).'.php'; $_POST=[]; $_GET=['pid'=>(string)$header]; $_SERVER['REQUEST_METHOD']='GET';
}
if (isset($_POST['save_prospectus_subject'])) unset($_POST['ps_id']);
if ($case === 'view') { $route='backend/query_di_view_prospectus.php'; $_POST=[]; $_GET=['prospectus_id'=>(string)$header]; $_SERVER['REQUEST_METHOD']='GET'; }
$protected = ['tbl_prospectus_offering','tbl_class_schedule','tbl_faculty_workload_sched','tbl_faculty_need_workload_sched','tbl_faculty_workload_simulation','tbl_program_schedule_set','tbl_program_schedule_set_row','tbl_academic_settings','tbl_student_management','tbl_section_curriculum','tbl_section_prospectus'];
$snapshot = static function(array $tables) use ($conn): string { $data=[]; foreach($tables as $table) $data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC); return hash('sha256',json_encode($data)); };
$curriculum = ['tbl_program','tbl_subject_masterlist','tbl_prospectus_header','tbl_prospectus_year_sem','tbl_prospectus_subjects'];
$before=$snapshot($curriculum); $protectedBefore=$snapshot($protected); $sessionBefore=$_SESSION;
session_write_close(); ob_start();
register_shutdown_function(static function() use ($conn,$snapshot,$protected,$protectedBefore,$curriculum,$before,$sessionBefore,$program,$unusedProgram,$targetProgram,$header,$yearSem,$ps,$subA,$subB,$tag,$campus,$college): void {
    while(ob_get_level()>1) ob_end_flush();
    $body=ob_get_clean();
    try {
        $data=['status'=>http_response_code()?:200,'body'=>json_decode($body,true)??$body,'unchanged'=>$before===$snapshot($curriculum),'protected_unchanged'=>$protectedBefore===$snapshot($protected),'session_unchanged'=>$sessionBefore===array_intersect_key($_SESSION,$sessionBefore),
            'program'=>$program,'target_program'=>$targetProgram,'header'=>$header,'year_sem'=>$yearSem,'ps'=>$ps,'sub_a'=>$subA,'sub_b'=>$subB,'tag'=>$tag,
            'campus'=>$campus,'college'=>(int)$college,'unused_program'=>$unusedProgram,
            'programs'=>$conn->query("SELECT * FROM tbl_program WHERE program_id IN ({$program},{$targetProgram},{$unusedProgram}) ORDER BY program_id")->fetch_all(MYSQLI_ASSOC),
            'headers'=>$conn->query("SELECT * FROM tbl_prospectus_header WHERE program_id IN ({$program},{$targetProgram}) ORDER BY prospectus_id")->fetch_all(MYSQLI_ASSOC),
            'subjects'=>$conn->query("SELECT ps.* FROM tbl_prospectus_subjects ps JOIN tbl_prospectus_year_sem y ON y.pys_id=ps.pys_id JOIN tbl_prospectus_header h ON h.prospectus_id=y.prospectus_id WHERE h.program_id IN ({$program},{$targetProgram}) ORDER BY ps.ps_id")->fetch_all(MYSQLI_ASSOC)];
        $conn->discardFixtures();
        if (session_status() !== PHP_SESSION_ACTIVE) session_start(); session_destroy();
        echo json_encode($data);
    } catch(Throwable $exception) { $conn->discardFixtures(); echo json_encode(['fixture_error'=>$exception->getMessage(),'body'=>$body]); }
});
$_SERVER['PHP_SELF']='/synk/'.$route; $_SERVER['SCRIPT_NAME']=$_SERVER['PHP_SELF']; $_SERVER['SCRIPT_FILENAME']=$root.'/'.$route;
chdir(dirname($root.'/'.$route));
require $root.'/'.$route;
