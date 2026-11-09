<?php
// Accessible only through a random loopback test wrapper. All fixture writes roll back.
if (!defined('SYNK_PROFESSOR_LMS_QA') || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(SYNK_PROFESSOR_LMS_QA, $_GET['qa'])) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root=dirname(__DIR__,2);
require_once $root.'/backend/db.php';
require_once $root.'/backend/auth_useraccount.php';
require_once $root.'/backend/professor_workload_students_helper.php';
require_once __DIR__.'/professor-workload-students-data.php';
$conn->begin_transaction();
$fixture=professor_students_fixtures($conn);
$case=is_string($_GET['case']??null)?$_GET['case']:'class';
session_save_path($root.'/temp');session_id('professorlmsqa'.bin2hex(random_bytes(10)));session_start();
$_SESSION=['user_id'=>$fixture['user_id'],'role'=>'professor','username'=>'Professor LMS Preview','email'=>'professor-lms@example.test'];
if($case==='anonymous')$_SESSION=[];
if($case==='wrong_role')$_SESSION['role']='student';
if($case==='inactive')$conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id=".$fixture['user_id']);
if($case==='revoked')$conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id=".$fixture['user_id']);
if($case==='inactive_faculty')$conn->query("UPDATE tbl_faculty SET status='inactive' WHERE faculty_id=".$fixture['faculty_id']);
if($case==='unlinked')$conn->query('DELETE FROM tbl_useraccount_faculty_links WHERE user_id='.$fixture['user_id']);
if($case==='reassigned')$conn->query('DELETE FROM tbl_faculty_workload_sched WHERE faculty_id='.$fixture['faculty_id']);
$_GET+=['offering_id'=>(string)$fixture['offerings'][0]['offering_id'],'ay_id'=>(string)$fixture['term']['ay_id'],'semester'=>(string)$fixture['term']['semester']];
if($case==='students')$_GET['view']='students';
if($case==='foreign')$_GET['offering_id']=(string)$fixture['offerings'][2]['offering_id'];
if($case==='bad_id')$_GET['offering_id']=['invalid'];
if($case==='wrong_term')$_GET['ay_id']='999999999';
if($case==='wrong_semester')$_GET['semester']='4';
if($case==='forged_identity'){$_GET['faculty_id']='999999999';$_GET['user_id']='999999999';}
$tables='tbl_class_schedule,tbl_faculty_workload_sched,tbl_academic_settings,tbl_student_management,tbl_student_management_enrolled_subjects,tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,'.synk_schedule_merge_table_name();
$before=$conn->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC);
register_shutdown_function(static function()use($conn,$tables,$before):void{
    header('X-QA-Core-Unchanged: '.($conn->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC)===$before?'yes':'no'));
    $conn->rollback();if(session_status()===PHP_SESSION_ACTIVE)session_destroy();
});
if(defined('SYNK_PROFESSOR_LMS_PREVIEW')){
    ob_start(static function($html):string{
        $html=preg_replace('/<head>/', '<head><base href="http://localhost/synk/professor/">', $html,1);
        return str_replace('href="class_lms.php?', 'href="'.rtrim(SYNK_PROFESSOR_LMS_PREVIEW,'/').'/class_lms.php?qa='.rawurlencode(SYNK_PROFESSOR_LMS_QA).'&amp;', $html);
    });
}
$_SERVER['REQUEST_METHOD']=$case==='method'?'POST':'GET';
$handler=$case==='workload'?'workload.php':'class_lms.php';
$_SERVER['PHP_SELF']='/synk/professor/'.$handler;
session_write_close();chdir($root.'/professor');require $root.'/professor/'.$handler;
