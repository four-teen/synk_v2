<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/db.php';
require_once __DIR__.'/student_login_helper.php';
require_once __DIR__.'/student_presence_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
function synk_student_presence_response(array $data, int $status = 200): void
{
    http_response_code($status); echo json_encode($data); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') synk_student_presence_response(['status'=>'error'], 405);
if (($_SESSION['role'] ?? '') !== 'student' || empty($_SESSION['student_id'])) synk_student_presence_response(['status'=>'signed_out'], 401);
$csrf = $_POST['csrf_token'] ?? null;
if (!is_string($csrf) || empty($_SESSION['student_presence_csrf']) || !hash_equals($_SESSION['student_presence_csrf'], $csrf)) {
    synk_student_presence_response(['status'=>'error','message'=>'Your session changed. Reload the page.'], 403);
}
$action = $_POST['action'] ?? null;
if (!is_string($action) || !in_array($action, ['activity','status','logout'], true)) synk_student_presence_response(['status'=>'error'], 422);
try {
    if (!synk_student_login_session_record($conn)) {
        synk_student_presence_end($conn, 'identity_changed');
        synk_logout_session();
        synk_student_presence_response(['status'=>'signed_out','reason'=>'student_session_changed'], 401);
    }
    $state = synk_student_presence_check($conn, $action === 'activity');
    if ($action === 'logout') {
        synk_student_presence_end($conn);
        synk_logout_session();
        synk_student_presence_response(['status'=>'signed_out','reason'=>'student_signed_out'], 401);
    }
    synk_student_presence_response(['status'=>'ok'] + $state);
} catch (DomainException $error) {
    synk_logout_session();
    synk_student_presence_response(['status'=>'signed_out','reason'=>'student_idle_timeout'], 401);
} catch (Throwable $error) {
    error_log('Student presence: '.$error->getMessage());
    synk_student_presence_response(['status'=>'error','message'=>'Unable to check your session.'], 503);
}
