<?php
session_start();

require_once __DIR__ . '/auth_google.php';

$settings = synk_auth_settings();

if (!synk_google_login_enabled($settings) || !synk_google_auth_ready($settings)) {
    synk_auth_status_redirect('google_unavailable');
}

try {
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));
} catch (Throwable $e) {
    $state = sha1(uniqid('state-', true));
    $nonce = sha1(uniqid('nonce-', true));
}

$_SESSION['google_oauth_state'] = $state;
$_SESSION['google_oauth_nonce'] = $nonce;
$_SESSION['google_oauth_portal'] = 'main';

header('Location: ' . synk_google_auth_url($settings, $state, $nonce));
exit;
