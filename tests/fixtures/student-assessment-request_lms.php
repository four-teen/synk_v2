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
$fixture=professor_students_fixtures($conn);$id=$fixture['students']['active'];
$student=synk_student_fetch_directory_record_by_student_id($conn,$id);
session_save_path($root.'/temp');session_id('studentlmsqa'.bin2hex(random_bytes(10)));session_start();
synk_complete_student_login($student['email_address'],'Student LMS QA','qa-lms-'.bin2hex(random_bytes(12)));$_SESSION['student_id']=$id;
$candidatePhoto=synk_student_profile_image_path($id);if(is_file($candidatePhoto))throw new RuntimeException('Refusing to replace an existing profile image.');$photo=$candidatePhoto;
$bytes=file_get_contents(__DIR__.'/student-camera-portrait.jpg');if($bytes===false||file_put_contents($photo,$bytes)===false)throw new RuntimeException('Unable to prepare the isolated QA photo.');
session_write_close();$_SERVER['REQUEST_METHOD']='GET';$_SERVER['PHP_SELF']='/synk/student/assessments_lms.php';chdir($root.'/student');require $root.'/student/assessments_lms.php';
