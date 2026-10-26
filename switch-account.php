<?php
session_start();

require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/account_switch_helper.php';

header('Cache-Control: no-store, private');

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: index.php');
    exit;
}

$token = $_POST['account_switch_token'] ?? null;
$sessionToken = (string)($_SESSION['account_switch_token'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($token)
    || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
    http_response_code(403);
    exit('Unable to switch accounts. Please try again from your account menu.');
}

$context = synk_account_switch_context($conn);
if (!$context || empty($context['role_rows'])) {
    synk_clear_pending_role_login();
    synk_reset_authenticated_session_context();
    header('Location: index.php?auth_status=account_not_allowed');
    exit;
}

if (count($context['role_rows']) < 2) {
    header('Location: ' . (synk_role_redirect_path((string)$_SESSION['role']) ?? 'index.php'));
    exit;
}

$avatarUrl = (string)($_SESSION['user_avatar_url'] ?? '');
$currentRole = (string)$_SESSION['role'];
$authMethod = (string)($_SESSION['auth_method'] ?? 'legacy');

// Keep the verified identity in the chooser and discard the previous workspace's scope.
session_regenerate_id(true);
$_SESSION = [];
synk_store_pending_role_login($context['account'], $context['role_rows'], [
    'avatar_url' => $avatarUrl,
    'is_account_switch' => true,
    'current_role' => $currentRole,
    'auth_method' => $authMethod,
]);

header('Location: index.php?auth_status=choose_role');
exit;
