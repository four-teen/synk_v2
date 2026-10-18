<?php
if (PHP_SAPI!=='cli' && !defined('SYNK_CHAIR_CURRICULUM_QA_HTTP')) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__,2);
require_once $root.'/backend/db.php';
require_once $root.'/backend/program_chair_curriculum_helper.php';

class ChairCurriculumFixtureConnection extends mysqli
{
    public $failSubjectWrite = false;
    public function startFixtures(): void { parent::begin_transaction(); }
    public function discardFixtures(): void { parent::rollback(); }
    public function begin_transaction($flags = null,$name = null): bool { return parent::query('SAVEPOINT chair_curriculum_write'); }
    public function commit($flags = null,$name = null): bool { return parent::query('RELEASE SAVEPOINT chair_curriculum_write'); }
    public function rollback($flags = null,$name = null): bool {
        parent::query('ROLLBACK TO SAVEPOINT chair_curriculum_write');
        return parent::query('RELEASE SAVEPOINT chair_curriculum_write');
    }
    #[\ReturnTypeWillChange]
    public function prepare($query) {
        if ($this->failSubjectWrite && preg_match('/^\s*INSERT\s+INTO\s+tbl_prospectus_subjects/i',$query)) {
            throw new RuntimeException('Injected curriculum subject write failure.');
        }
        return parent::prepare($query);
    }
    #[\ReturnTypeWillChange]
    public function query($query,$result_mode=MYSQLI_STORE_RESULT) {
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i',$query)) throw new RuntimeException('Curriculum fixtures must not change the schema.');
        return parent::query($query,$result_mode);
    }
}
$conn->close();
$conn = new ChairCurriculumFixtureConnection($servername,$username,$password,$dbase);
$conn->set_charset('utf8mb4');
$scenario = PHP_SAPI==='cli' ? ($argv[1] ?? '') : (string)($_GET['scenario'] ?? '');
foreach (['tbl_useraccount','tbl_useraccount_roles','tbl_program','tbl_prospectus_header','tbl_prospectus_year_sem','tbl_prospectus_subjects','tbl_subject_masterlist'] as $table) {
    $engine = $conn->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='{$table}'")->fetch_assoc();
    if (strtolower($engine['ENGINE'] ?? '')!=='innodb') throw new RuntimeException('Transactional table required: '.$table);
}
$conn->startFixtures();
$colleges = $conn->query("SELECT c.college_id FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.status='active' AND cp.status='active' ORDER BY c.college_id LIMIT 2")->fetch_all(MYSQLI_ASSOC);
if (count($colleges)<2) throw new RuntimeException('Two active colleges are required.');
$collegeId = (int)$colleges[0]['college_id']; $otherCollege = (int)$colleges[1]['college_id'];
$campusId = (int)$conn->query("SELECT campus_id FROM tbl_college WHERE college_id={$collegeId}")->fetch_assoc()['campus_id'];
$otherCampusId = (int)$conn->query("SELECT campus_id FROM tbl_college WHERE college_id={$otherCollege}")->fetch_assoc()['campus_id'];
$tag = 'CurrQA'.bin2hex(random_bytes(5));
$insert = static function (string $sql) use ($conn): int { $conn->query($sql); return (int)$conn->insert_id; };
$actor = $insert("INSERT INTO tbl_useraccount(username,email,password,role,status,college_id) VALUES('{$tag}','{$tag}@sksu.edu.ph','unusable-test-password','program_chair','active',{$collegeId})");
$conn->query("INSERT INTO tbl_useraccount_roles(user_id,role,status,is_primary) VALUES({$actor},'program_chair','active',1)");
$ownProgram = $insert("INSERT INTO tbl_program(college_id,program_code,program_name,status) VALUES({$collegeId},'{$tag}A','{$tag} Own Program','active')");
$targetProgram = $insert("INSERT INTO tbl_program(college_id,program_code,program_name,status) VALUES({$collegeId},'{$tag}B','{$tag} Target Program','active')");
$foreignProgram = $insert("INSERT INTO tbl_program(college_id,program_code,program_name,status) VALUES({$otherCollege},'{$tag}X','{$tag} Foreign Program','active')");
$subA = $insert("INSERT INTO tbl_subject_masterlist(sub_code,sub_description,status) VALUES('{$tag}A','{$tag} Subject A','active')");
$subB = $insert("INSERT INTO tbl_subject_masterlist(sub_code,sub_description,status) VALUES('{$tag}B','{$tag} Subject B','active')");
$subC = $insert("INSERT INTO tbl_subject_masterlist(sub_code,sub_description,status) VALUES('{$tag}C','{$tag} Subject C','active')");
$inactiveSub = $insert("INSERT INTO tbl_subject_masterlist(sub_code,sub_description,status) VALUES('{$tag}I','{$tag} Inactive Subject','inactive')");
$header = $insert("INSERT INTO tbl_prospectus_header(program_id,cmo_no,effective_sy,remarks) VALUES({$ownProgram},'{$tag} CMO','2026-2027','Fixture remarks')");
$foreignHeader = $insert("INSERT INTO tbl_prospectus_header(program_id,cmo_no,effective_sy) VALUES({$foreignProgram},'{$tag} Foreign CMO','2026-2027')");
$yearSem = $insert("INSERT INTO tbl_prospectus_year_sem(prospectus_id,year_level,semester) VALUES({$header},'1','1')");
$foreignYearSem = $insert("INSERT INTO tbl_prospectus_year_sem(prospectus_id,year_level,semester) VALUES({$foreignHeader},'1','1')");
$subject = $insert("INSERT INTO tbl_prospectus_subjects(pys_id,sub_id,lec_units,lab_units,total_units,sort_order) VALUES({$yearSem},{$subA},3,3,4,1)");
$otherSubject = $insert("INSERT INTO tbl_prospectus_subjects(pys_id,sub_id,lec_units,lab_units,total_units,sort_order,prerequisites,prerequisite_sub_ids) VALUES({$yearSem},{$subC},3,0,3,2,'{$tag}A','[\"{$subA}\"]')");
$foreignSubject = $insert("INSERT INTO tbl_prospectus_subjects(pys_id,sub_id,lec_units,lab_units,total_units,sort_order) VALUES({$foreignYearSem},{$subA},3,0,3,1)");
session_save_path($root.'/temp'); session_start();
$_SESSION = ['user_id'=>$actor,'role'=>'program_chair','college_id'=>$otherCollege,'csrf_token'=>'curriculum-qa'];
$_GET=[]; $_POST=['csrf_token'=>'curriculum-qa']; $_SERVER['REQUEST_METHOD']='POST';
$handler='backend/query_program_chair_prospectus.php';
$action=$scenario;
if (strpos($scenario,'browser_')===0) $action='load_browser_prospectuses';
if ($scenario==='builder_program_versions') $action='load_prospectus_by_program';
if ($scenario==='duplicate_header') $action='save_header';
if (strpos($scenario,'admin_')===0) $action=substr($scenario,6);
if (strpos($scenario,'outside_')===0) $action=substr($scenario,8);
if (strpos($scenario,'csrf_')===0) $action=substr($scenario,5);
$builderActions=['save_header','save_year_sem','save_prospectus_subject','update_prospectus_subject','delete_prospectus_subject','delete_year_sem','load_header','load_year_sem','load_subject_for_edit','get_all_subjects','load_prospectus_list','list_headers'];
$browserActions=['load_browser_filters','load_browser_prospectuses','load_prospectus_by_program','update_prospectus_header','transfer_prospectus','delete_prospectus'];
if (in_array($action,$browserActions,true)) $handler='backend/query_program_chair_prospectus_browser.php';
if (in_array($action,['load_programs','copy_prospectus'],true)) $handler='backend/query_program_chair_prospectus_copy.php';
if (in_array($action,array_merge($builderActions,$browserActions,['load_programs','copy_prospectus']),true)) $_POST[$action]='1';
$_POST += ['program_id'=>(string)$ownProgram,'prospectus_id'=>(string)$header,'pys_id'=>(string)$yearSem,'ps_id'=>(string)$subject,
    'sub_id'=>(string)$subB,'lec_units'=>'1','lab_units'=>'3','sort_order'=>'3','prerequisite_sub_ids'=>json_encode([(string)$subA]),
    'year_level'=>'2','semester'=>'2','cmo_no'=>$tag.' New CMO','effective_sy'=>'2027-2028',
    'source_prospectus_id'=>(string)$header,'target_program_id'=>(string)$targetProgram];
if ($action==='load_browser_prospectuses') $_POST['program_id']='';
if ($scenario==='browser_empty_curriculum') $insert("INSERT INTO tbl_prospectus_header(program_id,cmo_no,effective_sy) VALUES({$targetProgram},'{$tag} Empty CMO','2027-2028')");
if ($scenario==='browser_selected_program') $_POST['program_id']=(string)$ownProgram;
if ($scenario==='browser_zero_program') $_POST['program_id']=(string)$targetProgram;
if ($scenario==='browser_foreign_college') $_POST['college_id']=(string)$otherCollege;
if ($scenario==='browser_foreign_program') $_POST['program_id']=(string)$foreignProgram;
if ($scenario==='browser_wrong_campus') $_POST['campus_id']='2147483647';
if ($scenario==='browser_array_program') $_POST['program_id']=[$ownProgram];
if ($scenario==='builder_program_versions') $insert("INSERT INTO tbl_prospectus_header(program_id,cmo_no,effective_sy) VALUES({$ownProgram},'{$tag} New Version','2027-2028')");
if ($scenario==='duplicate_header') { $_POST['cmo_no']=$tag.' CMO'; $_POST['effective_sy']='2026-2027'; }
if ($action==='save_prospectus_subject') unset($_POST['ps_id']);
if (strpos($scenario,'outside_')===0) {
    $_POST['program_id']=(string)$foreignProgram; $_POST['prospectus_id']=(string)$foreignHeader;
    $_POST['pys_id']=(string)$foreignYearSem; $_POST['ps_id']=(string)$foreignSubject;
    if ($action==='save_prospectus_subject') unset($_POST['ps_id']);
    if ($action==='copy_prospectus') $_POST['source_prospectus_id']=(string)$foreignHeader;
}
if (strpos($scenario,'csrf_')===0) $_POST['csrf_token']='wrong';
if (in_array($scenario,['copy_outside_target','transfer_outside_target'],true)) {
    $action=$scenario==='copy_outside_target'?'copy_prospectus':'transfer_prospectus';
    $_POST[$action]='1'; $_POST['target_program_id']=(string)$foreignProgram;
    $handler=$action==='copy_prospectus'?'backend/query_program_chair_prospectus_copy.php':'backend/query_program_chair_prospectus_browser.php';
}
if ($scenario==='duplicate_subject') { $_POST['save_prospectus_subject']='1'; $_POST['sub_id']=(string)$subA; unset($_POST['ps_id']); }
if ($scenario==='duplicate_edit') { $_POST['update_prospectus_subject']='1'; $_POST['sub_id']=(string)$subC; }
if ($scenario==='duplicate_year_sem') { $_POST['save_year_sem']='1'; $_POST['year_level']='1'; $_POST['semester']='1'; }
if ($scenario==='inactive_subject') { $_POST['save_prospectus_subject']='1'; $_POST['sub_id']=(string)$inactiveSub; unset($_POST['ps_id']); }
if ($scenario==='bad_prerequisite') { $_POST['save_prospectus_subject']='1'; $_POST['prerequisite_sub_ids']='[2147483647]'; unset($_POST['ps_id']); }
if ($scenario==='self_prerequisite') { $_POST['save_prospectus_subject']='1'; $_POST['prerequisite_sub_ids']=json_encode([(string)$subB]); unset($_POST['ps_id']); }
if ($scenario==='bad_hours') { $_POST['save_prospectus_subject']='1'; $_POST['lec_units']='-3'; unset($_POST['ps_id']); }
if ($scenario==='copy_failure') { $_POST['copy_prospectus']='1'; $handler='backend/query_program_chair_prospectus_copy.php'; $conn->failSubjectWrite=true; }
if ($scenario==='duplicate_copy') { $_POST['copy_prospectus']='1'; $handler='backend/query_program_chair_prospectus_copy.php'; $_POST['target_program_id']=(string)$ownProgram; $_POST['cmo_no']=$tag.' CMO'; $_POST['effective_sy']='2026-2027'; }
if (in_array($scenario,['wrong_role','revoked','reassigned','inactive','forged_college','array_input','multiple_actions','method'],true)) {
    $_POST['save_year_sem']='1';
    if ($scenario==='wrong_role') $_SESSION['role']='professor';
    if ($scenario==='revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$actor}");
    if ($scenario==='reassigned') $conn->query("UPDATE tbl_useraccount SET college_id={$otherCollege} WHERE user_id={$actor}");
    if ($scenario==='inactive') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$actor}");
    if ($scenario==='forged_college') $_POST['college_id']=(string)$otherCollege;
    if ($scenario==='array_input') $_POST['prospectus_id']=[$header];
    if ($scenario==='multiple_actions') $_POST['save_header']='1';
    if ($scenario==='method') $_SERVER['REQUEST_METHOD']='GET';
}
if (strpos($scenario,'page_')===0) {
    $pages=['page_builder'=>'manage-prospectus.php','page_builder_selected'=>'manage-prospectus.php','page_admin_builder'=>'manage-prospectus.php','page_browser'=>'manage-prospectus-browser.php','page_admin_browser'=>'manage-prospectus-browser.php','page_view'=>'view-prospectus.php','page_outside'=>'manage-prospectus.php'];
    $handler='program-chair/'.$pages[$scenario]; $_POST=[]; $_SERVER['REQUEST_METHOD']='GET';
    if (strpos($scenario,'page_admin_')===0) { $_SESSION['role']='admin'; $handler='administrator/'.$pages[$scenario]; }
    if ($scenario==='page_builder_selected') $_GET=['pid'=>(string)$header];
    if ($scenario==='page_view') $_GET=['pid'=>(string)$header];
    if ($scenario==='page_outside') $_GET=['pid'=>(string)$foreignHeader];
}
if ($scenario==='view' || $scenario==='outside_view') {
    $handler='backend/query_view_prospectus.php'; $_POST=[]; $_SERVER['REQUEST_METHOD']='GET';
    $_GET=['prospectus_id'=>(string)($scenario==='view'?$header:$foreignHeader)];
}
if (strpos($scenario,'admin_')===0) {
    $_SESSION['role']='admin';
    $handler=str_replace('query_program_chair_prospectus','query_prospectus',$handler);
    if ($action==='load_browser_prospectuses') {
        $_POST['campus_id']=(string)$campusId; $_POST['college_id']=(string)$collegeId;
    }
}
if ($scenario==='browser_admin_foreign_college') {
    $_SESSION['role']='admin'; $handler='backend/query_prospectus_browser.php';
    $_POST['campus_id']=(string)$otherCampusId; $_POST['college_id']=(string)$otherCollege;
}
if ($scenario==='browser_admin_wrong_campus') {
    $_SESSION['role']='admin'; $handler='backend/query_prospectus_browser.php';
    $_POST['campus_id']='2147483647'; $_POST['college_id']=(string)$collegeId;
}
if ($scenario==='browser_admin_foreign_program') {
    $_SESSION['role']='admin'; $handler='backend/query_prospectus_browser.php';
    $_POST['campus_id']=(string)$campusId; $_POST['college_id']=(string)$collegeId; $_POST['program_id']=(string)$foreignProgram;
}
if (in_array($scenario,['direct_builder','direct_browser','direct_copy'],true)) {
    $handler=['direct_builder'=>'backend/query_prospectus.php','direct_browser'=>'backend/query_prospectus_browser.php','direct_copy'=>'backend/query_prospectus_copy.php'][$scenario];
    $_POST['load_prospectus_list']='1';
}
if ($scenario==='scheduler_write') {
    $_SESSION['role']='scheduler'; $_SESSION['college_id']=$collegeId;
    $_POST['save_year_sem']='1'; $handler='backend/query_prospectus.php';
}
$snapshot=static function () use ($conn,$ownProgram,$targetProgram,$foreignProgram,$subA,$subB,$subC,$inactiveSub): string {
    $data=[];
    $data[]=$conn->query("SELECT * FROM tbl_prospectus_header WHERE program_id IN ({$ownProgram},{$targetProgram},{$foreignProgram}) ORDER BY prospectus_id")->fetch_all(MYSQLI_ASSOC);
    $data[]=$conn->query("SELECT y.* FROM tbl_prospectus_year_sem y JOIN tbl_prospectus_header h ON h.prospectus_id=y.prospectus_id WHERE h.program_id IN ({$ownProgram},{$targetProgram},{$foreignProgram}) ORDER BY y.pys_id")->fetch_all(MYSQLI_ASSOC);
    $data[]=$conn->query("SELECT ps.* FROM tbl_prospectus_subjects ps JOIN tbl_prospectus_year_sem y ON y.pys_id=ps.pys_id JOIN tbl_prospectus_header h ON h.prospectus_id=y.prospectus_id WHERE h.program_id IN ({$ownProgram},{$targetProgram},{$foreignProgram}) ORDER BY ps.ps_id")->fetch_all(MYSQLI_ASSOC);
    $data[]=$conn->query("SELECT * FROM tbl_subject_masterlist WHERE sub_id IN ({$subA},{$subB},{$subC},{$inactiveSub}) ORDER BY sub_id")->fetch_all(MYSQLI_ASSOC);
    return hash('sha256',json_encode($data));
};
$before=$snapshot();
$foreignBefore=$conn->query("SELECT * FROM tbl_prospectus_subjects WHERE pys_id={$foreignYearSem}")->fetch_all(MYSQLI_ASSOC);
$conn->query("SET @curriculum_qa=1");
session_write_close(); ob_start();
register_shutdown_function(static function () use ($conn,$actor,$collegeId,$campusId,$otherCollege,$tag,$ownProgram,$targetProgram,$foreignProgram,$header,$foreignHeader,$yearSem,$subject,$subA,$subB,$subC,$snapshot,$before,$foreignBefore,$foreignYearSem): void {
    $body=''; while(ob_get_level()>0) $body=ob_get_clean().$body;
    try {
        $rows=$conn->query("SELECT * FROM tbl_prospectus_header WHERE program_id IN ({$ownProgram},{$targetProgram}) ORDER BY prospectus_id")->fetch_all(MYSQLI_ASSOC);
        $result=['response'=>json_decode($body,true) ?? $body,'http_status'=>http_response_code()?:200,'unchanged'=>$snapshot()===$before,
            'foreign_unchanged'=>$foreignBefore===$conn->query("SELECT * FROM tbl_prospectus_subjects WHERE pys_id={$foreignYearSem}")->fetch_all(MYSQLI_ASSOC),
            'college_id'=>$collegeId,'other_college'=>$otherCollege,'campus_id'=>$campusId,'session_college'=>(int)($_SESSION['college_id'] ?? 0),'own_program'=>$ownProgram,'target_program'=>$targetProgram,'foreign_program'=>$foreignProgram,
            'header'=>$header,'year_sem'=>$yearSem,'subject'=>$subject,'sub_a'=>$subA,'sub_b'=>$subB,'sub_c'=>$subC,'tag'=>$tag,'headers'=>$rows,
            'year_sem_rows'=>$conn->query("SELECT y.* FROM tbl_prospectus_year_sem y JOIN tbl_prospectus_header h ON h.prospectus_id=y.prospectus_id WHERE h.program_id IN ({$ownProgram},{$targetProgram}) ORDER BY y.pys_id")->fetch_all(MYSQLI_ASSOC),
            'subject_rows'=>$conn->query("SELECT ps.* FROM tbl_prospectus_subjects ps JOIN tbl_prospectus_year_sem y ON y.pys_id=ps.pys_id JOIN tbl_prospectus_header h ON h.prospectus_id=y.prospectus_id WHERE h.program_id IN ({$ownProgram},{$targetProgram}) ORDER BY ps.ps_id")->fetch_all(MYSQLI_ASSOC),
            'master_subject_exists'=>(int)$conn->query("SELECT COUNT(*) AS total FROM tbl_subject_masterlist WHERE sub_id={$subA}")->fetch_assoc()['total']===1];
        $conn->discardFixtures();
        if (session_status()===PHP_SESSION_ACTIVE) session_destroy();
        http_response_code(200); header('Content-Type: application/json'); echo json_encode($result);
    } catch(Throwable $exception) { $conn->discardFixtures(); echo json_encode(['fixture_error'=>$exception->getMessage()]); }
});
$_SERVER['PHP_SELF']='/synk/'.$handler;
$_SERVER['SCRIPT_NAME']=$_SERVER['PHP_SELF']; $_SERVER['SCRIPT_FILENAME']=$root.'/'.$handler;
chdir(dirname($root.'/'.$handler));
if ($scenario==='copy_failure') {
    $log=$root.'/tmp/curriculum-failure-'.bin2hex(random_bytes(6)).'.log'; ini_set('error_log',$log);
    register_shutdown_function(static function () use ($log): void { if(is_file($log)) unlink($log); });
}
require $root.'/'.$handler;
