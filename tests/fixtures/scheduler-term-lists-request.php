<?php
if (!defined('SYNK_SCHEDULER_LIST_QA') || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(SYNK_SCHEDULER_LIST_QA, $_GET['qa'])) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__, 2);
require_once $root . '/backend/db.php';
require_once $root . '/backend/scheduler_term_list_helper.php';
class SchedulerListQAConnection extends mysqli
{
    public $requestMode = false;
    #[\ReturnTypeWillChange]
    public function begin_transaction($flags=0,$name=null) { return $this->requestMode ? $this->query('SAVEPOINT scheduler_list_request') : parent::begin_transaction($flags); }
    #[\ReturnTypeWillChange]
    public function commit($flags=0,$name=null) { return $this->requestMode ? $this->query('RELEASE SAVEPOINT scheduler_list_request') : parent::commit($flags); }
    #[\ReturnTypeWillChange]
    public function rollback($flags=0,$name=null) { return $this->requestMode ? $this->query('ROLLBACK TO SAVEPOINT scheduler_list_request') : parent::rollback($flags); }
    #[\ReturnTypeWillChange]
    public function query($sql,$mode=MYSQLI_STORE_RESULT) {
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $sql)) throw new RuntimeException('Fixtures cannot change the schema.');
        return parent::query($sql,$mode);
    }
}
$conn->close(); $conn = new SchedulerListQAConnection($servername,$username,$password,$dbase); $conn->set_charset('utf8mb4');
$conn->begin_transaction();
$scenario = (string)($_GET['scenario'] ?? 'faculty_preview');
$term = synk_scheduler_list_current_term($conn);
$source = ['ay_id' => $term['ay_id'], 'semester' => max(1, $term['semester']-1)];
if ($term['semester'] === 1) {
    $previous = synk_scheduler_list_query($conn, 'SELECT ay_id FROM tbl_academic_years WHERE ay<? ORDER BY ay DESC LIMIT 1', 's', [$term['ay_label']]);
    if (!$previous) throw new RuntimeException('A previous academic year is required for fixtures.');
    $source = ['ay_id' => (int)$previous[0]['ay_id'], 'semester' => 3];
}
$campusId = (int)$conn->query("SELECT campus_id FROM tbl_campus WHERE status='active' ORDER BY campus_id LIMIT 1")->fetch_assoc()['campus_id'];
$tag = 'SchedulerListQA' . bin2hex(random_bytes(6));
$conn->query("INSERT INTO tbl_college (campus_id,college_code,college_name,status) VALUES ({$campusId},'QA','List QA College','active')");
$collegeId = (int)$conn->insert_id;
$conn->query("INSERT INTO tbl_college (campus_id,college_code,college_name,status) VALUES ({$campusId},'QAF','Foreign List QA College','active')");
$foreignCollege = (int)$conn->insert_id;
$conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_id)
    VALUES ('List QA Scheduler','{$tag}@sksu.edu.ph','unusable-test-password','scheduler','active',{$collegeId})");
$userId = (int)$conn->insert_id; synk_persist_useraccount_roles($conn,$userId,['scheduler'],'scheduler');
$faculty = []; $assignments = [];
foreach (['first','second','existing','unavailable','foreign'] as $name) {
    $home = $name === 'foreign' ? $foreignCollege : $collegeId;
    $status = $name === 'unavailable' ? 'inactive' : 'active';
    $conn->query("INSERT INTO tbl_faculty (first_name,last_name,employment_classification,status,college_id,campus_id)
        VALUES ('{$name}','QA {$tag}','permanent','{$status}',{$home},{$campusId})");
    $faculty[$name] = (int)$conn->insert_id;
    $conn->query("INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status)
        VALUES ({$home},{$faculty[$name]},{$source['ay_id']},{$source['semester']},'active')");
}
foreach (['second' => 'inactive', 'existing' => 'active'] as $name => $status) {
    $conn->query("INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status)
        VALUES ({$collegeId},{$faculty[$name]},{$term['ay_id']},{$term['semester']},'{$status}')");
    $assignments[$name] = (int)$conn->insert_id;
}
$rooms = [];
$designations = [];
foreach (['dean' => 12, 'chair' => 6] as $name => $units) {
    $conn->query("INSERT INTO tbl_designation (designation_name,designation_units,status) VALUES ('QA {$name} {$tag}',{$units},'active')");
    $designations[$name] = ['designation_id' => (int)$conn->insert_id, 'designation_name' => 'QA '.$name.' '.$tag, 'designation_units' => $units];
}
$conn->query("UPDATE tbl_faculty SET designation_id={$designations['dean']['designation_id']} WHERE faculty_id={$faculty['existing']}");
synk_faculty_term_designation_record($conn, $faculty['existing'], $source['ay_id'], $source['semester'], $designations['dean'], $userId);
synk_faculty_term_designation_record($conn, $faculty['existing'], $term['ay_id'], $term['semester'], $designations['dean'], $userId);
synk_faculty_term_designation_record($conn, $faculty['first'], $source['ay_id'], $source['semester'], $designations['chair'], $userId);
if ($scenario==='faculty_copy_keeps_designation') synk_faculty_term_designation_record($conn,$faculty['first'],$term['ay_id'],$term['semester'],[], $userId);
foreach (['first','second','existing','unavailable','borrowed'] as $name) {
    $owner = $name === 'borrowed' ? $foreignCollege : $collegeId;
    $status = $name === 'unavailable' ? 'inactive' : 'active';
    $conn->query("INSERT INTO tbl_rooms (college_id,room_code,room_name,room_type,capacity,status)
        VALUES ({$owner},'QA-{$name}','{$name} {$tag}','lecture',40,'{$status}')");
    $rooms[$name] = (int)$conn->insert_id;
    $access = $name === 'borrowed' ? 'shared' : 'owner';
    $conn->query("INSERT INTO tbl_room_college_access (room_id,college_id,ay_id,semester,access_type)
        VALUES ({$rooms[$name]},{$collegeId},{$source['ay_id']},{$source['semester']},'{$access}')");
}
$conn->query("INSERT INTO tbl_room_college_access (room_id,college_id,ay_id,semester,access_type)
    VALUES ({$rooms['existing']},{$collegeId},{$term['ay_id']},{$term['semester']},'owner')");
$conn->query("INSERT INTO tbl_room_college_access (room_id,college_id,ay_id,semester,access_type)
    VALUES ({$rooms['first']},{$foreignCollege},{$source['ay_id']},{$source['semester']},'shared')");
$workload = $conn->query('SELECT schedule_id,ay_id,semester FROM tbl_faculty_workload_sched ORDER BY workload_id LIMIT 1')->fetch_assoc();
if ($workload) $conn->query("INSERT INTO tbl_faculty_workload_sched (schedule_id,faculty_id,ay_id,semester)
    VALUES ({$workload['schedule_id']},{$faculty['existing']},{$workload['ay_id']},{$workload['semester']})");
$snapshot = static function (array $tables) use ($conn): string {
    $data = []; foreach ($tables as $table) $data[$table] = $conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    return hash('sha256',json_encode($data));
};
$masterTables = ['tbl_faculty','tbl_rooms','tbl_faculty_workload_sched','tbl_class_schedule','tbl_academic_settings'];
$masterBefore = $snapshot($masterTables);
$previousRows = static function () use ($conn,$collegeId,$source): string {
    return json_encode([synk_scheduler_list_query($conn,'SELECT * FROM tbl_college_faculty WHERE college_id=? AND ay_id=? AND semester=? ORDER BY 1','iii',[$collegeId,$source['ay_id'],$source['semester']]),
        synk_scheduler_list_query($conn,'SELECT * FROM tbl_room_college_access WHERE ay_id=? AND semester=? ORDER BY 1','ii',[$source['ay_id'],$source['semester']]),
        synk_scheduler_list_query($conn,'SELECT * FROM tbl_faculty_term_designations WHERE ay_id=? AND semester=? ORDER BY 1','ii',[$source['ay_id'],$source['semester']])]);
};
$previousBefore = $previousRows();
session_save_path($root.'/temp'); session_id('schedulerlistqa'.bin2hex(random_bytes(10))); session_start();
$_SESSION = ['user_id'=>$userId,'username'=>'List QA Scheduler','email'=>$tag.'@sksu.edu.ph','role'=>'scheduler','college_id'=>$collegeId,'college_name'=>'List QA College','csrf_token'=>SYNK_SCHEDULER_LIST_QA];
$kind = strpos($scenario,'rooms') === 0 ? 'rooms' : 'faculty';
$_POST = ['kind'=>$kind,'college_id'=>(string)$collegeId,'csrf_token'=>SYNK_SCHEDULER_LIST_QA,
    'source_ay_id'=>(string)$source['ay_id'],'source_semester'=>(string)$source['semester'],'action'=>'preview'];
$scope = synk_scheduler_list_scope($conn,$collegeId);
$sourceTerm = synk_scheduler_list_source($conn,$scope,$kind,$source['ay_id'],$source['semester'],$term);
$preview = null;
if (strpos($scenario,'copy') !== false || in_array($scenario,['foreign_id','source_changed','token_expired','replay','late_duplicate','term_changed'],true)) {
    $preview = synk_scheduler_list_stage_preview($conn,$scope,$kind,$sourceTerm,$term);
    $_POST['action']='copy'; $_POST['preview_token']=$preview['preview_token'];
    $ids = $kind === 'rooms' ? [$rooms['first']] : [$faculty['first']];
    if ($scenario==='faculty_copy') $ids=[$faculty['first'],$faculty['second']];
    if ($scenario==='foreign_id') $ids=[$faculty['foreign']];
    if ($scenario==='rooms_borrowed_copy') $ids=[$rooms['borrowed']];
    if ($scenario==='duplicate_ids') $ids=[$faculty['first'],$faculty['first']];
    $_POST['selected_ids']=json_encode($ids);
    if ($scenario==='source_changed') $conn->query("UPDATE tbl_college_faculty SET status='inactive' WHERE college_id={$collegeId} AND faculty_id={$faculty['first']} AND ay_id={$source['ay_id']} AND semester={$source['semester']}");
    if ($scenario==='token_expired') $_SESSION['scheduler_list_copy_previews'][$preview['preview_token']]['expires_at']=time()-1;
    if ($scenario==='term_changed') $_SESSION['scheduler_list_copy_previews'][$preview['preview_token']]['target']['semester']=$source['semester'];
    if ($scenario==='late_duplicate') $conn->query("INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status) VALUES ({$collegeId},{$faculty['first']},{$term['ay_id']},{$term['semester']},'active')");
    if ($scenario==='replay') unset($_SESSION['scheduler_list_copy_previews'][$preview['preview_token']]);
}
if (strpos($scenario,'options') !== false) $_POST['action']='options';
if ($scenario==='invalid_source') $_POST['source_semester']='4';
if ($scenario==='foreign_college') $_POST['college_id']=(string)$foreignCollege;
if ($scenario==='bad_csrf') $_POST['csrf_token']='wrong';
if ($scenario==='wrong_role') $_SESSION['role']='professor';
if ($scenario==='revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId} AND role='scheduler'");
if ($scenario==='inactive_actor') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$userId}");
$handler = 'backend/query_scheduler_term_lists.php';
if (in_array($scenario,['remove','remove_old','remove_foreign','list'],true)) {
    $handler='backend/query_college_faculty.php';
    $_POST=['csrf_token'=>SYNK_SCHEDULER_LIST_QA,'college_id'=>(string)$collegeId,'remove_assignment'=>'1',
        'college_faculty_id'=>(string)$assignments['existing'],'ay_id'=>(string)$term['ay_id'],'semester'=>(string)$term['semester']];
    if ($scenario==='remove_old') $_POST['college_faculty_id']=(string)$conn->query("SELECT college_faculty_id FROM tbl_college_faculty WHERE faculty_id={$faculty['first']} AND semester={$source['semester']} LIMIT 1")->fetch_assoc()['college_faculty_id'];
    if ($scenario==='remove_foreign') $_POST['college_faculty_id']=(string)$conn->query("SELECT college_faculty_id FROM tbl_college_faculty WHERE faculty_id={$faculty['foreign']} LIMIT 1")->fetch_assoc()['college_faculty_id'];
    if ($scenario==='list') { unset($_POST['remove_assignment']); $_POST['load_college_faculty']='1'; }
}
if ($scenario==='legacy_rooms_copy') { $handler='backend/query_rooms.php'; $_POST['copy_rooms_from_term']='1'; $_POST['ay_id']=(string)$term['ay_id']; $_POST['semester']=(string)$term['semester']; }
if (strpos($scenario, 'designation_') === 0) {
    $handler='backend/query_college_faculty.php';
    $_POST=['csrf_token'=>SYNK_SCHEDULER_LIST_QA,'college_id'=>(string)$collegeId,'update_faculty_designation'=>'1',
        'faculty_id'=>(string)$faculty['existing'],'designation_id'=>'0','ay_id'=>(string)$term['ay_id'],'semester'=>(string)$term['semester']];
    if ($scenario==='designation_set') $_POST['designation_id']=(string)$designations['chair']['designation_id'];
    if ($scenario==='designation_foreign') $_POST['faculty_id']=(string)$faculty['foreign'];
    if ($scenario==='designation_old') { $_POST['ay_id']=(string)$source['ay_id']; $_POST['semester']=(string)$source['semester']; }
    if ($scenario==='designation_invalid') $_POST['designation_id']='-1';
    if ($scenario==='designation_array') $_POST['designation_id']=['0'];
    if ($scenario==='designation_bad_csrf') $_POST['csrf_token']='wrong';
    if ($scenario==='designation_revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId} AND role='scheduler'");
    if ($scenario==='designation_removed_membership') $conn->query("DELETE FROM tbl_college_faculty WHERE college_faculty_id={$assignments['existing']}");
    if ($scenario==='designation_inactive') { $_POST['designation_id']=(string)$designations['chair']['designation_id']; $conn->query("UPDATE tbl_designation SET status='inactive' WHERE designation_id={$designations['chair']['designation_id']}"); }
    if (in_array($scenario,['designation_list','designation_report_current','designation_report_old','designation_print_current','designation_print_old','designation_catalog_changed','designation_admin_list'],true)) {
        synk_faculty_term_designation_record($conn,$faculty['existing'],$term['ay_id'],$term['semester'],[], $userId);
        $_POST=['load_college_faculty'=>'1','college_id'=>(string)$collegeId];
        if ($scenario!=='designation_list' && $scenario!=='designation_admin_list') {
            $handler=strpos($scenario,'designation_print_')===0 ? 'backend/query_load_faculty_workload.php' : 'backend/query_workload_faculty_overview.php';
            $view = in_array($scenario,['designation_report_current','designation_print_current'],true) ? $term : $source;
            $_POST=['faculty_id'=>(string)$faculty['existing'],'faculty_ids'=>[$faculty['existing']],'ay_id'=>(string)$view['ay_id'],'semester'=>(string)$view['semester']];
            if ($scenario==='designation_catalog_changed') $conn->query("UPDATE tbl_designation SET designation_name='Renamed QA {$tag}',designation_units=99,status='inactive' WHERE designation_id={$designations['dean']['designation_id']}");
        }
    }
    if ($scenario==='designation_admin_set') {
        $handler='backend/query_faculty.php';
        $row=$conn->query("SELECT * FROM tbl_faculty WHERE faculty_id={$faculty['existing']}")->fetch_assoc();
        $_POST=['update_faculty'=>'1','faculty_id'=>(string)$faculty['existing'],'first_name'=>$row['first_name'],'last_name'=>$row['last_name'],
            'middle_name'=>'','ext_name'=>'','status'=>'active','designation_id'=>(string)$designations['chair']['designation_id']];
    }
    if ($scenario==='designation_admin_list') { $handler='backend/query_faculty.php'; $_POST=['load_faculty'=>'1']; }
}
if (in_array($scenario,['faculty_page','rooms_page'],true)) { $handler='scheduler/'.($scenario==='faculty_page'?'manage-college-faculty.php':'manage-rooms.php'); $_SERVER['PHP_SELF']='/synk/'.$handler; }
session_write_close();
$requestBefore = $snapshot(['tbl_college_faculty','tbl_room_college_access']);
ob_start(static function($html) use ($handler): string { return strpos($handler,'scheduler/')===0 ? str_replace('<head>','<head><base href="http://localhost/synk/scheduler/">',$html) : $html; });
register_shutdown_function(static function() use($conn,$snapshot,$masterTables,$masterBefore,$previousRows,$previousBefore,$requestBefore,$collegeId,$term,$faculty,$rooms): void {
    header('X-QA-Master-Unchanged: '.($snapshot($masterTables)===$masterBefore?'yes':'no'));
    header('X-QA-Previous-Unchanged: '.($previousRows()===$previousBefore?'yes':'no'));
    header('X-QA-Lists-Unchanged: '.($snapshot(['tbl_college_faculty','tbl_room_college_access'])===$requestBefore?'yes':'no'));
    $facultyRows=synk_scheduler_list_query($conn,'SELECT faculty_id,status FROM tbl_college_faculty WHERE college_id=? AND ay_id=? AND semester=? ORDER BY faculty_id','iii',[$collegeId,$term['ay_id'],$term['semester']]);
    header('X-QA-Faculty-Rows: '.rawurlencode(json_encode($facultyRows)));
    $roomRows=synk_scheduler_list_query($conn,'SELECT room_id,college_id,access_type FROM tbl_room_college_access WHERE ay_id=? AND semester=? AND room_id IN ('.implode(',',array_values($rooms)).') ORDER BY room_id,college_id','ii',[$term['ay_id'],$term['semester']]);
    header('X-QA-Room-Rows: '.rawurlencode(json_encode($roomRows)));
    header('X-QA-Faculty-Ids: '.rawurlencode(json_encode($faculty))); header('X-QA-Room-Ids: '.rawurlencode(json_encode($rooms)));
    header('X-QA-Term-Designations: '.rawurlencode(json_encode(synk_scheduler_list_query($conn,'SELECT faculty_id,ay_id,semester,designation_id,designation_name,designation_units,record_source FROM tbl_faculty_term_designations WHERE faculty_id IN ('.implode(',',array_values($faculty)).') ORDER BY faculty_id,ay_id,semester'))));
    $conn->requestMode=false; $conn->rollback(); if(session_status()===PHP_SESSION_ACTIVE) session_destroy();
});
$_SERVER['REQUEST_METHOD']='POST'; $conn->requestMode=true;
chdir($root.'/'.dirname($handler)); require $root.'/'.$handler;
