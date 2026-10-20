<?php
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/db.php';
require_once __DIR__.'/college_dean_login_report_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
function synk_dean_login_report_response(array $payload,int $status=200): void
{
    http_response_code($status);
    echo json_encode($payload,defined('JSON_INVALID_UTF8_SUBSTITUTE')?JSON_INVALID_UTF8_SUBSTITUTE:0);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') synk_dean_login_report_response(['status'=>'error','message'=>'Use a POST request.'],405);
if (($_SESSION['role'] ?? '')!=='college_dean') synk_dean_login_report_response(['status'=>'error','message'=>'College Dean access is required.'],403);
foreach (['csrf_token','college_id','campus_id','date_from','date_to','search','outcome','program_id','student_id','page','page_size','snapshot'] as $field) {
    if (isset($_POST[$field]) && !is_scalar($_POST[$field])) synk_dean_login_report_response(['status'=>'error','message'=>'Invalid request.'],400);
}
$scope=synk_college_dean_dashboard_require_scope($conn,$_POST);
if (empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null) || !hash_equals((string)$_SESSION['csrf_token'],$_POST['csrf_token'])) {
    synk_dean_login_report_response(['status'=>'error','message'=>'Your session changed. Reload the page and try again.'],403);
}
try {
    synk_dean_login_report_response(['status'=>'ok']+synk_dean_login_report($conn,$scope,synk_dean_login_report_filters($_POST)));
} catch (DomainException $error) {
    synk_dean_login_report_response(['status'=>'error','message'=>$error->getMessage()],422);
} catch (Throwable $error) {
    error_log('College Dean login report: '.$error->getMessage());
    synk_dean_login_report_response(['status'=>'error','message'=>'Unable to load login activity. Please try again.'],500);
}
