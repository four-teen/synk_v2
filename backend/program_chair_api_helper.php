<?php
// Enter a shared Dean handler only through an explicitly authorised Chair route.
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'program_chair') {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status'=>'error','message'=>'Program Chair access is required.']);
    exit;
}
define('SYNK_PROGRAM_CHAIR_API', true);
session_write_close();
