<?php
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/scheduler_access_helper.php';

header('Content-Type: application/json');

function scheduler_scope_respond(string $status, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'status' => $status,
        'message' => $message
    ], $extra));
    exit;
}

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'scheduler') {
    scheduler_scope_respond('error', 'Unauthorized.');
}

if (empty($_SESSION['csrf_token'])) {
    scheduler_scope_respond('error', 'Missing session token.');
}

$csrfToken = trim((string)($_POST['csrf_token'] ?? ''));
if ($csrfToken === '' || !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)) {
    scheduler_scope_respond('error', 'CSRF validation failed.');
}

synk_scheduler_bootstrap_session_scope($conn);

$requestedCollegeId = (int)($_POST['college_id'] ?? 0);
if ($requestedCollegeId <= 0) {
    scheduler_scope_respond('error', 'Invalid college selection.');
}

$accessRows = $_SESSION['scheduler_college_access'] ?? [];
$activeRow = synk_scheduler_access_row_for_college(is_array($accessRows) ? $accessRows : [], $requestedCollegeId);
if (!$activeRow) {
    scheduler_scope_respond('error', 'College is not assigned to this scheduler.');
}

$sessionRow = [
    'user_id' => (int)($_SESSION['user_id'] ?? 0),
    'username' => (string)($_SESSION['username'] ?? ''),
    'email' => (string)($_SESSION['email'] ?? ''),
    'role' => 'scheduler',
    'college_id' => $requestedCollegeId,
    'college_name' => (string)($activeRow['college_name'] ?? '')
];

synk_scheduler_store_session_scope($sessionRow, $accessRows, $requestedCollegeId);

scheduler_scope_respond('success', 'Scheduler workspace updated.', [
    'college_id' => (int)($_SESSION['college_id'] ?? 0),
    'college_name' => (string)($_SESSION['college_name'] ?? ''),
    'campus_id' => (int)($_SESSION['campus_id'] ?? 0),
    'campus_name' => (string)($_SESSION['campus_name'] ?? '')
]);
