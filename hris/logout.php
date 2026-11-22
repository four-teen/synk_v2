<?php
session_start();
require_once __DIR__ . '/auth.php';
synk_hris_headers();

if (!synk_hris_valid_post()) {
    http_response_code(403);
    exit('Refresh the workspace and try again.');
}

unset($_SESSION['hris_identity'], $_SESSION['hris_csrf'], $_SESSION['hris_google_oauth_state'],
    $_SESSION['hris_google_oauth_nonce'], $_SESSION['hris_google_oauth_started_at'], $_SESSION['hris_employee_delete_review']);
session_write_close();
header('Location: login.php', true, 303);
