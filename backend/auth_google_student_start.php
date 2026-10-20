<?php
session_start();

require_once __DIR__ . '/auth_google.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/student_login_helper.php';

$settings = synk_auth_settings();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') synk_auth_status_redirect('student_login_request_invalid', '../student/login.php');
if (!synk_student_login_check_csrf($_POST['csrf_token'] ?? null)) {
    synk_student_login_activity_record($conn,is_string($_POST['email'] ?? null)?$_POST['email']:'','failure','student_login_request_invalid');
    synk_auth_status_redirect('student_login_request_invalid', '../student/login.php');
}
$requests = array_values(array_filter($_SESSION['student_login_requests'] ?? [], static function ($timestamp): bool { return $timestamp > time() - 900; }));
if (count($requests) >= 10) {
    synk_student_login_activity_record($conn,is_string($_POST['email'] ?? null)?$_POST['email']:'','failure','student_login_rate_limited');
    synk_auth_status_redirect('student_login_rate_limited', '../student/login.php');
}
$requests[] = time();
$_SESSION['student_login_requests'] = $requests;
$email = is_string($_POST['email'] ?? null) ? synk_normalize_email($_POST['email']) : '';
unset($_SESSION['student_login_issue_saved']);
$_SESSION['student_login_last_email'] = substr($email, 0, 255);
$failure = !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 ? 'student_email_invalid' : '';
if ($failure === '' && !synk_is_allowed_email_domain($email, (string)$settings['allowed_domain'])) $failure = 'email_domain_denied';
if ($failure !== '') {
    synk_student_login_record_issue($conn, $email, $failure);
    synk_auth_status_redirect($failure, '../student/login.php');
}
$directoryMatches = synk_student_login_find_directory($conn, $email);
if (count($directoryMatches) !== 1) {
    $failure = $directoryMatches ? 'student_email_ambiguous' : 'student_directory_access_denied';
    synk_student_login_record_issue($conn, $email, $failure);
    synk_auth_status_redirect($failure, '../student/login.php');
}

if (!synk_google_login_enabled($settings) || !synk_google_auth_ready($settings)) {
    synk_student_login_record_issue($conn, $email, 'google_unavailable');
    synk_auth_status_redirect('google_unavailable', '../student/login.php');
}

try {
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));
} catch (Throwable $e) {
    synk_student_login_record_issue($conn, $email, 'student_portal_unavailable');
    synk_auth_status_redirect('student_portal_unavailable', '../student/login.php');
}

$_SESSION['student_google_oauth_state'] = $state;
$_SESSION['student_google_oauth_nonce'] = $nonce;
$_SESSION['student_google_oauth_email'] = $email;
$_SESSION['student_google_oauth_started_at'] = time();
$_SESSION['google_oauth_portal'] = 'student';

header('Location: ' . synk_google_auth_url($settings, $state, $nonce) . '&login_hint=' . rawurlencode($email));
exit;
