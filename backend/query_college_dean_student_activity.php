<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/db.php';
require_once __DIR__.'/college_dean_student_activity_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
function synk_dean_student_activity_response(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') synk_dean_student_activity_response(['status'=>'error','message'=>'Use a POST request.'], 405);
if (($_SESSION['role'] ?? '') !== 'college_dean') synk_dean_student_activity_response(['status'=>'error','message'=>'College Dean access is required.'], 403);
foreach (['csrf_token','college_id','campus_id','ay_id','semester'] as $key) {
    if (isset($_POST[$key]) && !is_scalar($_POST[$key])) synk_dean_student_activity_response(['status'=>'error','message'=>'Invalid request.'], 400);
}
$scope = synk_college_dean_dashboard_require_scope($conn, $_POST);
if (!is_string($_POST['csrf_token'] ?? null) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    synk_dean_student_activity_response(['status'=>'error','message'=>'Your session changed. Reload the page.'], 403);
}
try {
    $term = synk_dean_read_term($conn, $_POST);
    synk_dean_student_activity_response(['status'=>'ok'] + synk_dean_student_activity($conn, $scope, $term));
} catch (DomainException $error) {
    synk_dean_student_activity_response(['status'=>'error','message'=>$error->getMessage()], 422);
}
catch (Throwable $error) {
    error_log('College student activity: '.$error->getMessage());
    synk_dean_student_activity_response(['status'=>'error','message'=>'Student activity is temporarily unavailable. Please retry.'], 503);
}
