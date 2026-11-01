<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root=dirname(__DIR__,2);
require_once $root.'/backend/db.php';
require_once $root.'/backend/professor_evaluations_helper.php';
require_once __DIR__.'/professor-workload-students-data.php';
$conn->begin_transaction();
try {
    extract(professor_students_fixtures($conn));
    $case=$argv[1] ?? 'pending'; $college=(int)$offerings[0]['college_id'];
    synk_evaluation_query($conn,'INSERT INTO tbl_college_evaluation_settings (college_id,ay_id,semester,faculty_released) VALUES (?,?,?,1) ON DUPLICATE KEY UPDATE faculty_released=1','iii',[$college,$term['ay_id'],$term['semester']]);
    $token=bin2hex(random_bytes(16));
    synk_evaluation_query($conn,'INSERT INTO tbl_student_faculty_evaluations (student_id,faculty_id,college_id,ay_id,semester,faculty_name,instrument_version,question_count,total_score,average_rating,evaluation_token,submission_status,final_submission_token,comment_text) VALUES (?,?,?,?,?,\'QA Faculty\',?,15,60,4,?,\'submitted\',?,?)','iiiiissss',
        [$students['active'],$faculty_id,$college,$term['ay_id'],$term['semester'],synk_student_faculty_evaluation_current_version(),$token,$token,'COMMENT <script>bad()</script>']);
    $evaluationId=(int)$conn->insert_id;
    foreach (synk_student_faculty_evaluation_flat_questions() as $question) synk_evaluation_query($conn,'INSERT INTO tbl_student_faculty_evaluation_answers (evaluation_id,category_key,category_title,question_key,question_order,question_text,rating) VALUES (?,?,?,?,?,?,4)','isssis',[$evaluationId,$question['category_key'],$question['category_title'],$question['question_key'],$question['question_order'],$question['question_text']]);
    $label=trim($offerings[0]['sub_code'].' - '.$offerings[0]['sub_description']);
    synk_evaluation_capture_subject_scope($conn,$evaluationId,$students['active'],$faculty_id,$college,$term['ay_id'],$term['semester'],$label);
    session_save_path($root.'/.tmp'); session_id('evaluationrequestqa'.bin2hex(random_bytes(10))); session_start();
    $_SESSION=['user_id'=>$user_id,'role'=>'professor'];
    $_GET=['college_id'=>(string)$college,'ay_id'=>(string)$term['ay_id'],'semester'=>(string)$term['semester'],'offering_id'=>(string)$offerings[0]['offering_id'],'view'=>in_array($case,['results','export'],true)?$case:'pending'];
    if ($case==='anonymous') $_SESSION=[];
    if ($case==='wrong_role') $_SESSION['role']='student';
    if ($case==='disabled') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$user_id}");
    if ($case==='revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$user_id}");
    if ($case==='unlinked') $conn->query("DELETE FROM tbl_useraccount_faculty_links WHERE user_id={$user_id}");
    if ($case==='inactive_faculty') $conn->query("UPDATE tbl_faculty SET status='inactive' WHERE faculty_id={$faculty_id}");
    if ($case==='reassigned') $conn->query("DELETE FROM tbl_faculty_workload_sched WHERE faculty_id={$faculty_id}");
    if (in_array($case,['hidden','hidden_export'],true)) $conn->query("UPDATE tbl_college_evaluation_settings SET faculty_released=0 WHERE college_id={$college}");
    if ($case==='hidden_export') $_GET['view']='export';
    if ($case==='foreign') $_GET['offering_id']=(string)$offerings[2]['offering_id'];
    if ($case==='stale_term') $_GET['ay_id']='999999999';
    if ($case==='wrong_college') $_GET['college_id']='999999999';
    if ($case==='malformed') $_GET['offering_id']=['bad'];
    if ($case==='bad_view') $_GET['view']='supervisor';
    if ($case==='forged_identity') { $_GET['faculty_id']='999999999'; $_SESSION['faculty_id']=999999999; }
    session_write_close();
    $_SERVER['REQUEST_METHOD']=$case==='method'?'POST':'GET';
    ob_start(); require $root.'/professor/faculty-evaluation-details.php'; $body=ob_get_clean();
    $response=['status'=>http_response_code() ?: 200,'body'=>json_decode($body,true) ?? $body];
    if ($case==='export' && $response['status']===200) {
        $response['filename']=synk_professor_evaluation_pending_filename($evaluationDetail);
        $response['filename_context']=[$evaluationDetail['class']['subject_code'],explode(' - ',$evaluationDetail['class']['program_label'])[0],$evaluationDetail['class']['section_display']];
    }
} finally { $conn->rollback(); if (session_status()!==PHP_SESSION_ACTIVE) session_start(); session_destroy(); }
echo json_encode($response,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
