<?php
session_start();
require_once __DIR__ . '/auth.php';
synk_hris_headers();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Use the Kawani account menu to switch roles.');
}
[, $account] = synk_hris_require_account();
if (!synk_hris_valid_post()) {
    http_response_code(403);
    exit('Refresh the Kawani workspace and try again.');
}

$role = $_POST['role_code'] ?? '';
if ($role === synk_hris_active_role($account) || !in_array($role, synk_hris_assigned_roles($account), true)) {
    http_response_code(403);
    exit('The selected Kawani role is unavailable.');
}

synk_hris_set_session_role($role);
session_write_close();
header('Location: ./', true, 303);
exit;
