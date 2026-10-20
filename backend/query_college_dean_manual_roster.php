<?php
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/db.php';
require_once __DIR__.'/college_dean_manual_roster_helper.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store, private');
function synk_dean_manual_response(array $payload,int $status=200): void
{
    http_response_code($status); echo json_encode($payload,defined('JSON_INVALID_UTF8_SUBSTITUTE')?JSON_INVALID_UTF8_SUBSTITUTE:0); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') synk_dean_manual_response(['status'=>'error','message'=>'Use a POST request.'],405);
if (($_SESSION['role'] ?? '')!=='college_dean') synk_dean_manual_response(['status'=>'error','message'=>'Only the assigned College Dean can add students to classes.'],403);
foreach (['action','csrf_token','college_id','campus_id','ay_id','semester','faculty_id','offering_id','student_number','student_mode','profile_version','first_name','last_name','middle_name','suffix_name','email_address','student_program_id','student_year_level','reason_code','reason_note','preview_token','confirm_transfer'] as $field) {
    if (isset($_POST[$field]) && !is_scalar($_POST[$field])) synk_dean_manual_response(['status'=>'error','message'=>'Invalid request.'],400);
}
$scope=synk_college_dean_dashboard_require_scope($conn,$_POST);
if (empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null) || !hash_equals((string)$_SESSION['csrf_token'],$_POST['csrf_token'])) synk_dean_manual_response(['status'=>'error','message'=>'Your session changed. Reload the page and try again.'],403);
try {
    synk_dean_require_current_term_for_write($conn,$_POST); $term=synk_fetch_current_academic_term($conn);
    if (!synk_dean_roster_ready($conn) || !synk_roster_audit_ready($conn)) throw new DomainException('Manual class additions are not available yet. Contact your administrator.');
    $action=(string)($_POST['action'] ?? '');
    if (!in_array($action,['lookup','preview','confirm','cancel'],true)) throw new DomainException('Choose a valid class-list action.');
    if ($action==='cancel') { unset($_SESSION['dean_manual_roster_previews'][(string)($_POST['preview_token'] ?? '')]); synk_dean_manual_response(['status'=>'ok']); }
    $faculty=filter_var($_POST['faculty_id'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $offering=filter_var($_POST['offering_id'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$faculty || !$offering) throw new DomainException('Select an assigned class first.');
    $class=synk_dean_workload_class($conn,$scope,$term,$faculty,$offering);
    if ($action==='lookup') synk_dean_manual_response(['status'=>'ok']+synk_dean_manual_student($conn,$class,(string)($_POST['student_number'] ?? '')));
    if ($action==='preview') synk_dean_manual_response(['status'=>'ok']+synk_dean_manual_preview($conn,$class,$_POST));
    $token=(string)($_POST['preview_token'] ?? ''); $pending=$_SESSION['dean_manual_roster_previews'][$token] ?? null;
    if (!$pending || $pending['faculty_id']!==$faculty || $pending['offering_id']!==$offering) throw new DomainException('This review is no longer available. Review the addition again.');
    $result=synk_dean_manual_save($conn,$scope,$term,$pending,$token,($_POST['confirm_transfer'] ?? '')==='1');
    unset($_SESSION['dean_manual_roster_previews'][$token]);
    synk_dean_manual_response(['status'=>'ok','result'=>$result]);
} catch (DomainException $error) { synk_dean_manual_response(['status'=>'error','message'=>$error->getMessage()],422); }
catch (Throwable $error) { error_log('Manual class roster: '.$error->getMessage()); synk_dean_manual_response(['status'=>'error','message'=>'Unable to complete this class-list change. Refresh the class list before retrying.'],500); }
