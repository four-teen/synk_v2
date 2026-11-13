<?php
if (!defined('SYNK_PROFESSOR_LMS_QA') || !in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)
    || !is_string($_GET['qa']??null) || !hash_equals(SYNK_PROFESSOR_LMS_QA,$_GET['qa'])) {http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
$root=dirname(__DIR__,2);
require_once $root.'/backend/db.php';require_once $root.'/backend/student_dashboard_helper.php';require_once $root.'/backend/professor_class_helper_lms.php';require_once $root.'/backend/student_profile_image_helper.php';
require_once __DIR__.'/professor-workload-students-data.php';
$tables='tbl_student_management,tbl_student_management_enrolled_subjects,tbl_faculty,tbl_useraccount,tbl_useraccount_roles,tbl_class_schedule,tbl_faculty_workload_sched,tbl_academic_settings,tbl_student_portal_sessions';
$before=$conn->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC);$conn->begin_transaction();$photo=null;
register_shutdown_function(static function()use($conn,$tables,$before,&$photo):void{
    $conn->rollback();if($photo!==null&&is_file($photo))unlink($photo);if(session_status()===PHP_SESSION_ACTIVE)session_destroy();
    header('X-QA-Core-Unchanged: '.($conn->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC)===$before?'yes':'no'));
});
$fixture=professor_students_fixtures($conn,true);$id=$fixture['students']['active'];
if (defined('SYNK_STUDENT_ANNOUNCEMENTS_QA')) {
    require_once $root.'/backend/db_lms.php';require_once $root.'/backend/announcement_helper_lms.php';
    $lms=synk_database_connection_lms($conn);$lms->begin_transaction();
    register_shutdown_function(static function()use($lms):void{$lms->rollback();});
    $context=['portal'=>['faculty_id'=>$fixture['faculty_id']],'class'=>array_replace($fixture['offerings'][0],['ay_id'=>$fixture['term']['ay_id'],'semester'=>$fixture['term']['semester']])];
    synk_announcement_write_lms($lms,$context,'save',['title'=>'Class reminder <img src=x onerror=alert(1)>','body'=>"Bring your notes.\nLiteral <script>code</script> stays text."],$fixture['user_id']);
    synk_assessment_publish_lms($lms,$context,['grading_period'=>'final','assessment_type'=>'assignment','title'=>'New class project','instructions'=>'Write your response.','total_points'=>100,'opens_at'=>'2099-01-01T08:00','due_at'=>'2099-01-02T17:00'],$fixture['user_id']);
}
$student=synk_student_fetch_directory_record_by_student_id($conn,$id);
session_save_path($root.'/temp');session_id('studentlmsqa'.bin2hex(random_bytes(10)));session_start();
synk_complete_student_login($student['email_address'],'Student LMS QA','qa-lms-'.bin2hex(random_bytes(12)));$_SESSION['student_id']=$id;
$candidatePhoto=synk_student_profile_image_path($id);if(is_file($candidatePhoto))throw new RuntimeException('Refusing to replace an existing profile image.');$photo=$candidatePhoto;
$bytes=file_get_contents(__DIR__.'/student-camera-portrait.jpg');if($bytes===false||file_put_contents($photo,$bytes)===false)throw new RuntimeException('Unable to prepare the isolated QA photo.');
$page=($_GET['view']??'')==='dashboard'?'index.php':'assessments_lms.php';
// The QA route's token is not a selector on the real student page.
$tamper=($_GET['view']??'')==='tampered';$_GET=$tamper?['student_id'=>$fixture['students']['foreign'],'assessment_id'=>2147483647,'student_term'=>'999999:3']:[];
$_SERVER['QUERY_STRING']=$tamper?http_build_query($_GET):'';
session_write_close();$_SERVER['REQUEST_METHOD']='GET';$_SERVER['PHP_SELF']='/synk/student/'.$page;chdir($root.'/student');require $root.'/student/'.$page;
