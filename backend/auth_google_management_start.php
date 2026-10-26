<?php
session_start();
require_once __DIR__ . '/auth_google.php';
require_once __DIR__ . '/management_portal_helper.php';
$settings = synk_auth_settings();
$token = $_POST['csrf_token'] ?? null;
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($token)
    || empty($_SESSION['management_login_csrf']) || !hash_equals($_SESSION['management_login_csrf'], $token)) {
    synk_auth_status_redirect('login_request_invalid', '../management/login.php');
}
if (!synk_google_login_enabled($settings) || !synk_google_auth_ready($settings)) {
    synk_auth_status_redirect('google_unavailable', '../management/login.php');
}
$_SESSION['management_google_oauth_state'] = bin2hex(random_bytes(32));
$_SESSION['management_google_oauth_nonce'] = bin2hex(random_bytes(32));
$_SESSION['management_google_oauth_started_at'] = time();
$_SESSION['google_oauth_portal'] = 'management';
$settings['allowed_domain'] = '';
header('Location: ' . synk_google_auth_url($settings, $_SESSION['management_google_oauth_state'], $_SESSION['management_google_oauth_nonce']));
exit;
