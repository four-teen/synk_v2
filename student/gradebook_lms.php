<?php
session_start();ob_start();
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/student_gradebook_helper_lms.php';
require_once __DIR__.'/../backend/db_lms.php';
synk_student_require_login($conn);
header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET'){header('Allow: GET');http_response_code(405);exit('Open your gradebook using GET.');}
if(!empty($_SERVER['PATH_INFO'])){http_response_code(404);exit('Gradebook unavailable.');}
if(!empty($_GET)||(string)($_SERVER['QUERY_STRING']??'')!==''){header('Location: gradebook_lms.php',true,303);exit;}
$student=synk_student_fetch_directory_record_by_student_id($conn,(int)$_SESSION['student_id']);
if(!$student){http_response_code(404);exit('Student record unavailable.');}
$studentPortalDisplayName=synk_student_directory_display_name($student);$studentPortalDisplayEmail=$student['email_address'];$studentPortalPreviewMode=false;
$gradebook=null;$gradebookError='';$coreReading=false;$lmsReading=false;
try{
    $lms=synk_database_connection_lms($conn);
    if(!$conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY))throw new RuntimeException('Unable to read enrollment.');$coreReading=true;
    if(!$lms->begin_transaction(MYSQLI_TRANS_START_READ_ONLY))throw new RuntimeException('Unable to read grades.');$lmsReading=true;
    $gradebook=synk_student_gradebook_workspace_lms($conn,$lms);
    if(!$lms->commit())throw new RuntimeException('Unable to complete the grade read.');$lmsReading=false;
    if(!$conn->commit())throw new RuntimeException('Unable to complete the enrollment read.');$coreReading=false;
}catch(Throwable $error){
    if($lmsReading)$lms->rollback();if($coreReading)$conn->rollback();
    error_log('Student LMS gradebook: '.$error->getMessage());http_response_code(503);$gradebook=null;
    $gradebookError='Your gradebook is temporarily unavailable. Please try again later.';
}
$studentPortalViewTerm=$gradebook['term']??synk_fetch_current_academic_term($conn);
require __DIR__.'/gradebook_view_lms.php';
