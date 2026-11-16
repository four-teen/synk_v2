<?php
if (!defined('SYNK_EVALUATION_RESET_QA') || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(SYNK_EVALUATION_RESET_QA,$_GET['qa'])) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root=dirname(__DIR__,2);
require_once $root.'/backend/db.php';
require_once __DIR__.'/student-evaluation-reset-data.php';
$conn->close();$conn=new StudentEvaluationResetQAConnection($servername,$username,$password,$dbase);$conn->set_charset('utf8mb4');$conn->begin_transaction();
ob_start();
$f=student_evaluation_reset_fixture($conn);$conn->requestMode=true;
$case=is_string($_GET['case'] ?? null)?$_GET['case']:'page';
if($case==='open')$conn->query('UPDATE tbl_college_evaluation_settings SET student_open=1 WHERE college_id='.$f['scope']['college_id']);
session_save_path($root.'/temp');session_id('evaluationresetqa'.bin2hex(random_bytes(10)));session_start();
$_SESSION=['user_id'=>$f['admin'],'role'=>'admin','username'=>'Reset QA Administrator','email'=>'reset-qa@example.test','student_evaluation_reset_csrf'=>bin2hex(random_bytes(32))];
$protected=['tbl_student_management','tbl_student_management_enrolled_subjects','tbl_faculty','tbl_useraccount','tbl_useraccount_roles','tbl_college','tbl_program','tbl_academic_settings','tbl_class_schedule','tbl_faculty_workload_sched','tbl_supervisor_evaluations','tbl_supervisor_evaluation_assignments','tbl_supervisor_evaluation_settings','tbl_supervisor_evaluation_audit','tbl_college_evaluation_settings','tbl_college_evaluation_audit','tbl_college_faculty_evaluation_clear_history'];
$snapshot=static function()use($conn,$protected):array{$out=[];foreach($protected as $table)$out[$table]=$conn->query('CHECKSUM TABLE '.$table)->fetch_all(MYSQLI_ASSOC);return $out;};
$before=$snapshot();
register_shutdown_function(static function()use($conn,$f,$before,$snapshot):void{
    $remaining=(int)$conn->query('SELECT COUNT(*) AS n FROM tbl_student_faculty_evaluations WHERE evaluation_id IN ('.implode(',',$f['target']).')')->fetch_assoc()['n'];
    $history=(int)$conn->query('SELECT COUNT(*) AS n FROM tbl_student_evaluation_reset_history WHERE college_id='.$f['scope']['college_id'])->fetch_assoc()['n'];
    header('X-QA-Reset-State: '.rawurlencode(json_encode(['remaining'=>$remaining,'history'=>$history,'protected_unchanged'=>$snapshot()===$before])));
    $conn->requestMode=false;$conn->rollback();if(session_status()===PHP_SESSION_ACTIVE)session_destroy();
});
$_GET=$f['scope'];$_POST=[];
if($case==='entry')$_GET=[];
if(in_array($case,['success','csrf','scope','expired','failure'],true)){
    $preview=synk_student_evaluation_reset_preview($conn,$f['scope']);
    $_POST=$f['scope']+['action'=>'confirm','csrf_token'=>$_SESSION['student_evaluation_reset_csrf'],'preview_token'=>$preview['preview_token'],'confirmation'=>'DELETE STUDENT EVALUATIONS','confirm_delete'=>'1'];
    if($case==='csrf')$_POST['csrf_token']='invalid';
    if($case==='scope')$_POST['college_id']=$f['other_college'];
    if($case==='expired')$_SESSION['student_evaluation_reset_previews'][$preview['preview_token']]['expires_at']=time()-1;
    if($case==='failure')$conn->failAt='headers';
}
if($case==='unauthorized')$_SESSION['role']='college_dean';
$_SERVER['REQUEST_METHOD']=$case==='method'?'DELETE':($_POST?'POST':'GET');
$_SERVER['PHP_SELF']='/synk/administrator/student-evaluation-reset.php';
session_write_close();chdir($root.'/administrator');
// Allow isolated browser rendering with the real local assets.
ob_start(static function(string $html):string{return preg_replace('/<head>/', '<head><base href="http://localhost/synk/administrator/">', $html, 1);});
require $root.'/administrator/student-evaluation-reset.php';
