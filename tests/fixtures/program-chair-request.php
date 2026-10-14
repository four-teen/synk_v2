<?php
// Execute real portal and request handlers with all fixture writes rolled back.
if (PHP_SAPI !== 'cli' && !defined('SYNK_PROGRAM_CHAIR_QA_HTTP')) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__, 2);
require_once $root . '/backend/db.php';
require_once $root . '/backend/program_chair_faculty_helper.php';
require_once $root . '/backend/program_chair_faculty_management_helper.php';
require_once $root . '/backend/professor_account_link_helper.php';

class ProgramChairRequestConnection extends mysqli
{
    public $failClassificationWrite = false;
    public $failAccountWrite = false;
    #[\ReturnTypeWillChange]
    public function prepare($query)
    {
        if ($this->failClassificationWrite && preg_match('/^\s*UPDATE\s+tbl_faculty\s+SET\s+employment_classification/i',$query)) {
            return new ProgramChairFailedWrite($this,$query);
        }
        if ($this->failAccountWrite && preg_match('/^\s*INSERT\s+INTO\s+`?tbl_useraccount_roles/i',$query)) return new ProgramChairFailedWrite($this,$query);
        return parent::prepare($query);
    }
    #[\ReturnTypeWillChange]
    public function query($query, $result_mode = MYSQLI_STORE_RESULT)
    {
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query)) throw new RuntimeException('Fixture requests must not change the schema.');
        return parent::query($query, $result_mode);
    }
    public function startFixtures(): void { parent::begin_transaction(); }
    public function discardFixtures(): void { parent::rollback(); }
    public function begin_transaction($flags = null, $name = null): bool { return $this->query('SAVEPOINT chair_request'); }
    public function commit($flags = null, $name = null): bool { return $this->query('RELEASE SAVEPOINT chair_request'); }
    public function rollback($flags = null, $name = null): bool
    {
        $this->query('ROLLBACK TO SAVEPOINT chair_request');
        return $this->query('RELEASE SAVEPOINT chair_request');
    }
}
class ProgramChairFailedWrite extends mysqli_stmt
{
    public function execute($params = null): bool { return false; }
}
$conn->close();
$conn = new ProgramChairRequestConnection($servername, $username, $password, $dbase);
$conn->set_charset('utf8mb4');
foreach (['tbl_useraccount', 'tbl_useraccount_roles', 'tbl_useraccount_faculty_links', 'tbl_user_college_access', 'tbl_faculty', 'tbl_college_faculty', 'tbl_faculty_term_designations', 'tbl_academic_settings', 'tbl_class_schedule', 'tbl_faculty_workload_sched'] as $table) {
    $engine = $conn->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}'")->fetch_assoc();
    if (strtolower($engine['ENGINE'] ?? '') !== 'innodb') throw new RuntimeException('Transactional table required: ' . $table);
}
$scenario = PHP_SAPI === 'cli' ? ($argv[1] ?? 'login') : (string)($_GET['scenario'] ?? '');
$conn->startFixtures();
$term = synk_fetch_current_academic_term($conn);
$colleges = $conn->query("SELECT c.college_id,c.campus_id FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.status='active' AND cp.status='active' ORDER BY c.college_id LIMIT 2")->fetch_all(MYSQLI_ASSOC);
if (count($colleges) < 2 || $term['ay_id'] <= 0 || $term['semester'] <= 0) throw new RuntimeException('Two active colleges and a current academic term are required.');
$collegeId = (int)$colleges[0]['college_id']; $otherCollege = (int)$colleges[1]['college_id'];
$tag = 'ChairQA' . bin2hex(random_bytes(8));
$conn->query("INSERT INTO tbl_faculty (first_name,last_name,status,employment_classification) VALUES ('Program Chair','{$tag}','active','permanent')");
$facultyId = (int)$conn->insert_id;
$conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_id,college_dean_college_id) VALUES ('{$tag}','{$tag}@sksu.edu.ph','unusable-test-password','professor','active',{$collegeId},{$otherCollege})");
$userId = (int)$conn->insert_id;
if (synk_persist_useraccount_roles($conn, $userId, ['professor','program_chair','college_dean'], 'professor') !== null
    || synk_persist_professor_faculty_link($conn, $userId, ['professor','program_chair','college_dean'], $facultyId) !== null) {
    throw new RuntimeException('Could not create shared faculty login fixture.');
}
session_save_path($root . '/temp');
session_start();
$_SESSION = ['user_id'=>$userId, 'role'=>'program_chair', 'college_id'=>$otherCollege, 'csrf_token'=>'chair-qa'];
$_POST = ['csrf_token'=>'chair-qa', 'college_id'=>(string)$collegeId, 'ay_id'=>(string)$term['ay_id'],
    'semester'=>(string)$term['semester'], 'faculty_id'=>(string)$facultyId, 'employment_classification'=>'temporary'];
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
ob_start();
$handler = 'backend/query_program_chair_faculty.php';
$case = $scenario;
if (strpos($scenario, 'page_') === 0) $case = 'page';
if (strpos($scenario, 'manage_') === 0) $case = 'management';
if (strpos($scenario, 'copy_') === 0) $case = 'copy';
if (strpos($scenario, 'account_') === 0) $case = 'account';
$accountBefore = null; $targetAccountId = 0;
switch ($case) {
    case 'account':
        define('SYNK_PROGRAM_CHAIR_API',true);
        $conn->query("INSERT INTO tbl_faculty (first_name,last_name,status,employment_classification,college_id,campus_id) VALUES ('Faculty Login','{$tag}Target','active','permanent',{$collegeId},{$colleges[0]['campus_id']})");
        $facultyId=(int)$conn->insert_id;
        $conn->query("INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status) VALUES ({$collegeId},{$facultyId},{$term['ay_id']},{$term['semester']},'active')");
        $email=strtolower($tag).'target@sksu.edu.ph';
        $newScenarios=['account_create','account_create_inactive','account_create_scheduler','account_duplicate_email','account_invalid_email','account_create_failure','account_long_email'];
        if (!in_array($scenario,$newScenarios,true)) {
            $conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_id) VALUES ('Faculty Login {$tag}Target','{$email}','unusable-faculty-login-password','professor','active',{$collegeId})");
            $targetAccountId=(int)$conn->insert_id;
            $targetRoles=['professor'];
            if ($scenario==='account_protected') $targetRoles[]='admin';
            synk_persist_useraccount_roles($conn,$targetAccountId,$targetRoles,'professor');
            if (synk_professor_insert_auto_account_link($conn,$targetAccountId,$facultyId)!==null) throw new RuntimeException('Could not link the faculty login fixture.');
            if ($scenario==='account_other_scope') {
                synk_persist_useraccount_roles($conn,$targetAccountId,['professor','scheduler'],'professor');
                synk_persist_scheduler_college_access($conn,$targetAccountId,['scheduler'],[$collegeId,$otherCollege],$collegeId);
            }
            if ($scenario==='account_activate') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$targetAccountId}");
            if ($scenario==='account_inactive_privilege') $conn->query("INSERT INTO tbl_useraccount_roles (user_id,role,status,is_primary) VALUES ({$targetAccountId},'DI','inactive',0)");
            if ($scenario==='account_other_home') $conn->query("UPDATE tbl_faculty SET college_id={$otherCollege} WHERE faculty_id={$facultyId}");
            $accountBefore=$conn->query("SELECT user_id,email,password,role,status,college_id FROM tbl_useraccount WHERE user_id={$targetAccountId}")->fetch_assoc();
        }
        $_POST=['action'=>'save','faculty_id'=>(string)$facultyId,'college_id'=>(string)$collegeId,'ay_id'=>(string)$term['ay_id'],
            'semester'=>(string)$term['semester'],'csrf_token'=>'chair-qa','email'=>strtoupper($email),'status'=>'active',
            'roles_json'=>'["professor","scheduler"]','primary_role'=>'professor'];
        $scope=synk_chair_faculty_require_scope($conn,$_POST);
        $viewTerm=synk_dean_term_from_ids($conn,(string)$term['ay_id'],(string)$term['semester']);
        $target=synk_chair_account_target($conn,$scope,$viewTerm,$facultyId);
        $_POST['account_revision']=$target['revision'];
        if ($scenario==='account_load') $_POST['action']='load';
        if ($scenario==='account_list') { $_POST=['load_college_faculty'=>'1','search'=>$email,'college_id'=>(string)$collegeId,'ay_id'=>(string)$term['ay_id'],'semester'=>(string)$term['semester'],'csrf_token'=>'chair-qa']; $handler='backend/query_program_chair_faculty.php'; break; }
        if ($scenario==='account_deactivate' || $scenario==='account_create_inactive' || $scenario==='account_login_inactive') $_POST['status']='inactive';
        if ($scenario==='account_create_scheduler' || $scenario==='account_scheduler_only') { $_POST['roles_json']='["scheduler"]'; $_POST['primary_role']='scheduler'; }
        if ($scenario==='account_professor_only') $_POST['roles_json']='["professor"]';
        if ($scenario==='account_escalation') $_POST['roles_json']='["professor","admin"]';
        if ($scenario==='account_empty_roles') $_POST['roles_json']='[]';
        if ($scenario==='account_duplicate_roles') $_POST['roles_json']='["professor","professor"]';
        if ($scenario==='account_bad_primary') $_POST['primary_role']='admin';
        if ($scenario==='account_invalid_status') $_POST['status']='enabled';
        if ($scenario==='account_email_change') $_POST['email']='different@sksu.edu.ph';
        if ($scenario==='account_duplicate_email') $_POST['email']=$tag.'@sksu.edu.ph';
        if ($scenario==='account_invalid_email') $_POST['email']='faculty@example.com';
        if ($scenario==='account_long_email') $_POST['email']=str_repeat('a',151).'@sksu.edu.ph';
        if ($scenario==='account_stale') { $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$targetAccountId}"); $accountBefore=$conn->query("SELECT user_id,email,password,role,status,college_id FROM tbl_useraccount WHERE user_id={$targetAccountId}")->fetch_assoc(); }
        if ($scenario==='account_cross_college') $_POST['college_id']=(string)$otherCollege;
        if ($scenario==='account_unassigned') $_POST['faculty_id']='2147483647';
        if ($scenario==='account_bad_csrf') $_POST['csrf_token']='wrong';
        if ($scenario==='account_old_term') $_POST['semester']=(string)($term['semester']===1?2:1);
        if ($scenario==='account_extra_fields') $_POST['user_id']=(string)$userId;
        if ($scenario==='account_array_input') $_POST['roles_json']=['professor'];
        if ($scenario==='account_inactive_faculty') {
            $conn->query("UPDATE tbl_faculty SET status='inactive' WHERE faculty_id={$facultyId}");
            $_POST['account_revision']=synk_chair_account_target($conn,$scope,$viewTerm,$facultyId)['revision'];
        }
        if ($scenario==='account_wrong_role') $_SESSION['role']='professor';
        if ($scenario==='account_revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId} AND role='program_chair'");
        if ($scenario==='account_reassigned') $conn->query("UPDATE tbl_useraccount SET college_id={$otherCollege} WHERE user_id={$userId}");
        if ($scenario==='account_create_failure') {
            $conn->failAccountWrite=true; $logPath=$root.'/tmp/chair-account-failure-'.bin2hex(random_bytes(8)).'.log';
            ini_set('error_log',$logPath); register_shutdown_function(static function () use ($logPath): void { if (is_file($logPath)) unlink($logPath); });
        }
        $handler='backend/query_program_chair_faculty_account.php';
        if ($scenario==='account_login_inactive' || $scenario==='account_login_scheduler') {
            $saved=synk_chair_account_save($conn,$_POST);
            $id=(int)$conn->query("SELECT user_id FROM tbl_useraccount_faculty_links WHERE faculty_id={$facultyId}")->fetch_assoc()['user_id'];
            $account=synk_find_useraccount_by_id($conn,$id);
            $_SESSION=[];
            synk_store_pending_role_login($account,synk_fetch_useraccount_role_rows($conn,$id,$account['role']));
            $_POST=['complete_role_login'=>1,'selected_role'=>'scheduler'];
            $handler='index.php';
        }
        break;
    case 'management':
        $membershipCollege = $scenario === 'manage_remove_outside' ? $otherCollege : $collegeId;
        $conn->query("INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status) VALUES ({$membershipCollege},{$facultyId},{$term['ay_id']},{$term['semester']},'active')");
        $assignmentId = (int)$conn->insert_id;
        $designation = $conn->query("SELECT designation_id,designation_name,designation_units FROM tbl_designation WHERE status='active' ORDER BY designation_id LIMIT 1")->fetch_assoc();
        if (!$designation) throw new RuntimeException('An active designation is required.');
        $_POST['designation_id']=(string)$designation['designation_id'];
        $_POST['college_faculty_id']=(string)$assignmentId;
        if ($scenario === 'manage_list') {
            for ($i=0;$i<25;$i++) {
                $label=$tag.sprintf('%03d',$i);
                $conn->query("INSERT INTO tbl_faculty (first_name,last_name,status,employment_classification) VALUES ('Page','{$label}','active','permanent')");
                $id=(int)$conn->insert_id;
                $conn->query("INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status) VALUES ({$collegeId},{$id},{$term['ay_id']},{$term['semester']},'active')");
            }
            $_POST += ['load_college_faculty'=>1,'search'=>$tag,'page'=>'2','page_size'=>'10'];
        } elseif ($scenario === 'manage_dropdown') $_POST['load_faculty_dropdown']=1;
        elseif ($scenario === 'manage_catalog') $_POST['load_designation_options']=1;
        elseif (strpos($scenario,'manage_remove')===0) {
            $_POST['remove_assignment']=1;
            $schedule=$conn->query('SELECT schedule_id FROM tbl_class_schedule ORDER BY schedule_id LIMIT 1')->fetch_assoc();
            if ($schedule) $conn->query("INSERT INTO tbl_faculty_workload_sched (schedule_id,faculty_id,ay_id,semester) VALUES ({$schedule['schedule_id']},{$facultyId},{$term['ay_id']},{$term['semester']})");
        } elseif ($scenario==='manage_classification') $_POST['update_employment_classification']=1;
        else $_POST['update_faculty_designation']=1;
        if ($scenario==='manage_clear') $_POST['designation_id']='0';
        if ($scenario==='manage_invalid_designation') $_POST['designation_id']='2147483647';
        if ($scenario==='manage_unassigned') $_POST['faculty_id']='2147483647';
        if ($scenario==='manage_old_term' || $scenario==='manage_remove_old_term') $_POST['semester']=(string)($term['semester']===1?2:1);
        if ($scenario==='manage_cross_college') $_POST['college_id']=(string)$otherCollege;
        if ($scenario==='manage_bad_csrf') $_POST['csrf_token']='wrong';
        break;
    case 'copy':
        $year=$conn->query("SELECT ay_id,ay FROM tbl_academic_years WHERE ay<'".$conn->real_escape_string($term['ay_label'])."' ORDER BY ay DESC LIMIT 1")->fetch_assoc();
        if (!$year) throw new RuntimeException('A previous academic year is required.');
        $source = synk_dean_term_from_ids($conn,(string)$year['ay_id'],'1');
        $conn->query("INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status) VALUES ({$collegeId},{$facultyId},{$source['ay_id']},1,'active')");
        $designation=$conn->query("SELECT designation_id,designation_name,designation_units FROM tbl_designation WHERE status='active' ORDER BY designation_id LIMIT 1")->fetch_assoc();
        synk_faculty_term_designation_record($conn,$facultyId,$source['ay_id'],1,$designation,$userId);
        $_POST += ['kind'=>'faculty','source_ay_id'=>(string)$source['ay_id'],'source_semester'=>'1'];
        $_POST['action']='options';
        $handler='backend/query_program_chair_term_lists.php';
        if ($scenario==='copy_preview') $_POST['action']='preview';
        elseif (!in_array($scenario,['copy_options','copy_old_term','copy_bad_csrf','copy_outside','copy_rooms'],true)) {
            define('SYNK_PROGRAM_CHAIR_API',true);
            $scope=synk_chair_faculty_require_scope($conn);
            $preview=synk_chair_faculty_stage_copy($conn,$scope,$source,$term);
            $_POST['action']='copy'; $_POST['preview_token']=$preview['preview_token']; $_POST['selected_ids']=json_encode([$facultyId]);
            if ($scenario==='copy_selected') {
                synk_faculty_term_designation_record($conn,$facultyId,$term['ay_id'],$term['semester'],['designation_id'=>null,'designation_name'=>'','designation_units'=>0],$userId);
            }
            if ($scenario==='copy_duplicate') $conn->query("INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status) VALUES ({$collegeId},{$facultyId},{$term['ay_id']},{$term['semester']},'active')");
            if ($scenario==='copy_forged') $_POST['selected_ids']='[2147483647]';
            if ($scenario==='copy_expired') $_SESSION['program_chair_list_copy_previews'][$preview['preview_token']]['expires_at']=0;
            if ($scenario==='copy_source_changed') $conn->query("UPDATE tbl_college_faculty SET status='inactive' WHERE college_id={$collegeId} AND faculty_id={$facultyId} AND ay_id={$source['ay_id']} AND semester=1");
            if ($scenario==='copy_replay') synk_chair_faculty_copy($conn,$scope,$_POST);
        }
        if ($scenario==='copy_old_term') $_POST['semester']=(string)($term['semester']===1?2:1);
        if ($scenario==='copy_bad_csrf') $_POST['csrf_token']='wrong';
        if ($scenario==='copy_outside') $_POST['college_id']=(string)$otherCollege;
        if ($scenario==='copy_rooms') $_POST['kind']='rooms';
        break;
    case 'login':
        $_SESSION = [];
        $account = synk_find_useraccount_by_id($conn, $userId);
        $rows = synk_fetch_useraccount_role_rows($conn, $userId, $account['role']);
        synk_store_pending_role_login($account, synk_filter_loginable_role_rows($conn, $account, $rows));
        $_POST = ['complete_role_login'=>1, 'selected_role'=>'program_chair'];
        $handler = 'index.php'; break;
    case 'missing_scope':
        $conn->query("UPDATE tbl_useraccount SET college_id=NULL WHERE user_id={$userId}");
        $account = synk_find_useraccount_by_id($conn, $userId);
        $rows = synk_filter_loginable_role_rows($conn, $account, synk_fetch_useraccount_role_rows($conn, $userId, $account['role']));
        echo json_encode(['roles'=>array_column($rows,'role')]);
        $handler = ''; break;
    case 'list': $_POST['action'] = 'list'; break;
    case 'assign': $_POST['action'] = 'assign'; break;
    case 'write_failure':
        $conn->failClassificationWrite=true;
        $logPath=$root.'/tmp/chair-failure-'.bin2hex(random_bytes(8)).'.log';
        ini_set('error_log',$logPath);
        register_shutdown_function(static function () use ($logPath): void { if (is_file($logPath)) unlink($logPath); });
        $_POST['action']='assign'; break;
    case 'scheduler_sync':
        define('SYNK_PROGRAM_CHAIR_API', true);
        synk_chair_faculty_assign($conn, $_POST);
        if (synk_persist_useraccount_roles($conn, $userId, ['professor','program_chair','college_dean','scheduler'], 'professor') !== null
            || synk_persist_scheduler_college_access($conn, $userId, ['scheduler'], [$collegeId], $collegeId) !== null) {
            throw new RuntimeException('Could not create scheduler scope fixture.');
        }
        $_SESSION['role']='scheduler';
        $_POST=['load_college_faculty'=>1,'college_id'=>(string)$collegeId,'search'=>$tag];
        $handler='backend/query_college_faculty.php'; break;
    case 'duplicate':
    case 'reactivate':
    case 'legacy_duplicate':
    case 'legacy_reactivate':
        $status = strpos($scenario, 'reactivate') !== false ? 'inactive' : 'active';
        $ay = strpos($scenario, 'legacy_') === 0 ? 'NULL' : (string)$term['ay_id'];
        $semester = strpos($scenario, 'legacy_') === 0 ? 'NULL' : (string)$term['semester'];
        $conn->query("INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status) VALUES ({$collegeId},{$facultyId},{$ay},{$semester},'{$status}')");
        $_POST['action'] = 'assign'; break;
    case 'csrf': $_POST['csrf_token'] = 'invalid'; $_POST['action'] = 'assign'; break;
    case 'cross_college': $_POST['college_id'] = (string)$otherCollege; $_POST['action'] = 'assign'; break;
    case 'reassigned':
        $conn->query("UPDATE tbl_useraccount SET college_id={$otherCollege} WHERE user_id={$userId}");
        $_POST['action'] = 'assign'; break;
    case 'revoked':
        $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId} AND role='program_chair'");
        $_POST['action'] = 'assign'; break;
    case 'inactive':
        $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$userId}");
        $_POST['action'] = 'assign'; break;
    case 'wrong_role': $_SESSION['role']='college_dean'; $_POST['action']='assign'; break;
    case 'inactive_faculty':
        $conn->query("UPDATE tbl_faculty SET status='inactive' WHERE faculty_id={$facultyId}");
        $_POST['action'] = 'assign'; break;
    case 'invalid_faculty': $_POST['faculty_id']='2147483647'; $_POST['action']='assign'; break;
    case 'invalid_classification': $_POST['employment_classification']='unknown'; $_POST['action']='assign'; break;
    case 'old_term':
        $_POST['semester'] = (string)($term['semester'] === 1 ? 2 : 1);
        $_POST['action'] = 'assign'; break;
    case 'method': $_SERVER['REQUEST_METHOD']='GET'; break;
    case 'array_input': $_POST['college_id']=[$collegeId]; $_POST['action']='assign'; break;
    case 'reassigned_page':
        $conn->query("UPDATE tbl_useraccount SET college_id={$otherCollege} WHERE user_id={$userId}");
        $handler = 'program-chair/index.php'; $_POST = []; $_SERVER['REQUEST_METHOD']='GET'; break;
    case 'historical_page':
        $_GET=['dean_ay_id'=>(string)$term['ay_id'],'dean_semester'=>(string)($term['semester']===1?2:1)];
        $_POST=[]; $_SERVER['REQUEST_METHOD']='GET'; $handler='program-chair/manage-college-faculty.php'; break;
    case 'page':
        $page = substr($scenario, 5);
        if (!in_array($page, ['index','programs','students','reports','faculty-workload','faculty-profile','manage-faculty-profile','faculty-evaluations','student-statistics','faculty-statistics','manage-college-faculty'], true)) throw new RuntimeException('Invalid fixture page.');
        // Put the faculty in its home college so the shared profile editor can open it.
        $conn->query("UPDATE tbl_faculty SET college_id={$collegeId} WHERE faculty_id={$facultyId}");
        $_POST = []; $_GET = $page === 'manage-faculty-profile' ? ['faculty_id'=>(string)$facultyId] : []; $_SERVER['REQUEST_METHOD']='GET';
        $handler = 'program-chair/' . $page . '.php'; break;
    case 'heatmap': $handler='backend/dashboard_program_chair_schedule_heatmap.php'; break;
    case 'matrix': $handler='backend/load_program_chair_room_time_matrix.php'; break;
    case 'rosters': $_POST['action']='bootstrap'; $handler='backend/query_program_chair_rosters.php'; break;
    case 'students': $_POST['action']='list'; $handler='backend/query_program_chair_students.php'; break;
    case 'reports': $_POST['action']='list'; $handler='backend/query_program_chair_faculty_students.php'; break;
    case 'script_workload': $_GET=['file'=>'faculty-workload.js']; $handler='program-chair/shared-script.php'; break;
    case 'script_students': $_GET=['file'=>'student-management.js']; $handler='program-chair/shared-script.php'; break;
    case 'script_reports': $_GET=['file'=>'reports.js']; $handler='program-chair/shared-script.php'; break;
    case 'script_invalid': $_GET=['file'=>'../backend/db_config.local.php']; $handler='program-chair/shared-script.php'; break;
    case 'prospectus':
    case 'prospectus_outside':
        $scopeCollege = $scenario === 'prospectus' ? $collegeId : $otherCollege;
        $header=$conn->query("SELECT h.prospectus_id FROM tbl_prospectus_header h JOIN tbl_program p ON p.program_id=h.program_id WHERE p.college_id={$scopeCollege} LIMIT 1")->fetch_assoc();
        if (!$header) throw new RuntimeException('A prospectus in each fixture college is required.');
        $_GET=['prospectus_id'=>(string)$header['prospectus_id']]; $handler='backend/query_view_prospectus.php'; break;
    default: throw new RuntimeException('Unknown fixture scenario.');
}
if ($handler !== '') {
    session_write_close();
    ob_start();
}
$protectedBefore=$conn->query('CHECKSUM TABLE tbl_class_schedule,tbl_faculty_workload_sched')->fetch_all(MYSQLI_ASSOC);
$accountSnapshot=static function () use ($conn,$facultyId): string {
    $account=$conn->query("SELECT u.* FROM tbl_useraccount u JOIN tbl_useraccount_faculty_links l ON l.user_id=u.user_id WHERE l.faculty_id={$facultyId}")->fetch_assoc();
    $id=(int)($account['user_id'] ?? 0);
    $roles=$id?$conn->query("SELECT role,status,is_primary FROM tbl_useraccount_roles WHERE user_id={$id} ORDER BY role")->fetch_all(MYSQLI_ASSOC):[];
    $scope=$id?$conn->query("SELECT college_id,status,is_default FROM tbl_user_college_access WHERE user_id={$id} ORDER BY college_id")->fetch_all(MYSQLI_ASSOC):[];
    return hash('sha256',json_encode([$account,$roles,$scope]));
};
$accountSnapshotBefore=$accountSnapshot();
$accountCountBefore=(int)$conn->query('SELECT COUNT(*) AS total FROM tbl_useraccount')->fetch_assoc()['total'];
register_shutdown_function(static function () use ($conn, $userId, $facultyId, $collegeId, $otherCollege, $handler, $protectedBefore, $accountBefore, $accountSnapshot, $accountSnapshotBefore, $accountCountBefore): void {
    $body='';
    while (ob_get_level()>0) $body=ob_get_clean().$body;
    try {
        $response=json_decode($body,true);
        $assignments=$conn->query("SELECT college_id,ay_id,semester,status FROM tbl_college_faculty WHERE faculty_id={$facultyId} ORDER BY college_faculty_id")->fetch_all(MYSQLI_ASSOC);
        $classification=$conn->query("SELECT employment_classification FROM tbl_faculty WHERE faculty_id={$facultyId}")->fetch_assoc()['employment_classification'];
        $designationRows=$conn->query("SELECT ay_id,semester,designation_id,designation_name,record_source FROM tbl_faculty_term_designations WHERE faculty_id={$facultyId} ORDER BY ay_id,semester")->fetch_all(MYSQLI_ASSOC);
        $login=$conn->query("SELECT u.* FROM tbl_useraccount u JOIN tbl_useraccount_faculty_links l ON l.user_id=u.user_id WHERE l.faculty_id={$facultyId}")->fetch_assoc();
        $loginRoles=$login?synk_fetch_useraccount_role_rows($conn,(int)$login['user_id'],$login['role']):[];
        $loginScopes=$login?$conn->query("SELECT college_id,status FROM tbl_user_college_access WHERE user_id=".(int)$login['user_id']." ORDER BY college_id")->fetch_all(MYSQLI_ASSOC):[];
        $result=['response'=>is_array($response)?$response:$body,'http_status'=>http_response_code()?:200,
            'php_version'=>PHP_VERSION,
            'session_role'=>$_SESSION['role']??'', 'session_college'=>(int)($_SESSION['college_id']??0),
            'college_id'=>$collegeId,'other_college'=>$otherCollege,'faculty_id'=>$facultyId,
            'assignments'=>$assignments,'classification'=>$classification,'designation_rows'=>$designationRows,
            'login_email'=>$login['email'] ?? '', 'login_status'=>$login['status'] ?? '', 'login_roles'=>array_column($loginRoles,'role'),
            'login_primary'=>$login['role'] ?? '', 'login_scopes'=>$loginScopes,'login_college'=>(int)($login['college_id'] ?? 0),
            'credentials_unchanged'=>!$accountBefore || ($login && $login['email']===$accountBefore['email'] && $login['password']===$accountBefore['password']),
            'account_unchanged'=>$accountSnapshotBefore===$accountSnapshot(),
            'account_count_unchanged'=>$accountCountBefore===(int)$conn->query('SELECT COUNT(*) AS total FROM tbl_useraccount')->fetch_assoc()['total'],
            'protected_unchanged'=>$protectedBefore===$conn->query('CHECKSUM TABLE tbl_class_schedule,tbl_faculty_workload_sched')->fetch_all(MYSQLI_ASSOC)];
        $conn->discardFixtures();
        if (session_status()===PHP_SESSION_ACTIVE) session_destroy();
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
    } catch (Throwable $exception) {
        $conn->discardFixtures();
        echo json_encode(['fixture_error'=>$exception->getMessage()]);
    }
});
if ($handler !== '') {
    $_SERVER['PHP_SELF']='/synk/'.$handler;
    $_SERVER['SCRIPT_NAME']='/synk/'.$handler;
    $_SERVER['SCRIPT_FILENAME']=$root.'/'.$handler;
    chdir(dirname($root.'/'.$handler));
    require $root.'/'.$handler;
}
