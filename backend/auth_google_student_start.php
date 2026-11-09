<?php
session_start();

require_once __DIR__ . '/auth_google.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/student_login_helper.php';

$settings = synk_auth_settings();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') synk_auth_status_redirect('student_login_request_invalid', '../student/login.php');
if (!synk_student_login_check_csrf($_POST['csrf_token'] ?? null)) {
    synk_student_login_activity_record($conn,'','failure','student_login_request_invalid');
    synk_auth_status_redirect('student_login_request_invalid', '../student/login.php');
}
$requests = array_values(array_filter($_SESSION['student_login_requests'] ?? [], static function ($timestamp): bool { return $timestamp > time() - 900; }));
if (count($requests) >= 10) {
    synk_student_login_activity_record($conn,'','failure','student_login_rate_limited');
    synk_auth_status_redirect('student_login_rate_limited', '../student/login.php');
}
$requests[] = time();
$_SESSION['student_login_requests'] = $requests;
// Student identity comes only from the verified Google response, never POST data.
unset($_SESSION['student_login_issue_saved'], $_SESSION['student_login_last_email'], $_SESSION['student_google_oauth_email']);

if (!synk_google_login_enabled($settings) || !synk_google_auth_ready($settings)) {
    synk_student_login_activity_record($conn, '', 'failure', 'google_unavailable');
    synk_auth_status_redirect('google_unavailable', '../student/login.php');
}

try {
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));
} catch (Throwable $e) {
    synk_student_login_activity_record($conn, '', 'failure', 'student_portal_unavailable');
    synk_auth_status_redirect('student_portal_unavailable', '../student/login.php');
}

$_SESSION['student_google_oauth_state'] = $state;
$_SESSION['student_google_oauth_nonce'] = $nonce;
$_SESSION['student_google_oauth_started_at'] = time();
$_SESSION['google_oauth_portal'] = 'student';

header('Location: ' . synk_google_auth_url($settings, $state, $nonce));
exit;
