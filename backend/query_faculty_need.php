<?php
session_start();
include 'db.php';
require_once __DIR__ . '/faculty_need_helper.php';
require_once __DIR__ . '/workload_audit_helper.php';

header('Content-Type: application/json');

function faculty_need_response(string $status, array $payload = []): void
{
    echo json_encode(array_merge(['status' => $status], $payload));
    exit;
}

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    ($_SESSION['role'] ?? '') !== 'scheduler'
) {
    faculty_need_response('error', ['message' => 'Unauthorized.']);
}

$collegeId = (int)($_SESSION['college_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$action = strtolower(trim((string)($_POST['action'] ?? 'list')));
$ayId = (int)($_POST['ay_id'] ?? 0);
$semester = (int)($_POST['semester'] ?? 0);
$facultyNeedId = (int)($_POST['faculty_need_id'] ?? 0);

if ($collegeId <= 0 || $ayId <= 0 || $semester <= 0) {
    faculty_need_response('error', ['message' => 'Missing college or term context.']);
}

if ($action === 'create') {
    $need = synk_faculty_need_create_next($conn, $collegeId, $ayId, $semester, $userId);
    if (!is_array($need)) {
        faculty_need_response('error', ['message' => 'Unable to create faculty need right now.']);
    }

    synk_workload_audit_record($conn, 'faculty_need_create', [
        'college_id' => $collegeId,
        'ay_id' => $ayId,
        'semester' => $semester,
        'assignee_type' => 'faculty_need',
        'faculty_need_id' => (int)($need['faculty_need_id'] ?? 0),
        'entity_type' => 'faculty_need',
        'entity_id' => (int)($need['faculty_need_id'] ?? 0),
        'details' => [
            'faculty_need_id' => (int)($need['faculty_need_id'] ?? 0),
            'need_label' => (string)($need['need_label'] ?? ''),
            'college_id' => $collegeId,
            'ay_id' => $ayId,
            'semester' => $semester,
        ],
    ]);

    faculty_need_response('ok', [
        'message' => 'Faculty need created successfully.',
        'need' => $need,
        'needs' => synk_faculty_need_fetch_options($conn, $collegeId, $ayId, $semester),
    ]);
}

if ($action === 'delete') {
    if ($facultyNeedId <= 0) {
        faculty_need_response('error', ['message' => 'Invalid faculty need selected.']);
    }

    $deleted = synk_faculty_need_delete($conn, $collegeId, $ayId, $semester, $facultyNeedId, $userId);
    if (!is_array($deleted)) {
        faculty_need_response('error', ['message' => 'Unable to delete faculty need right now.']);
    }

    faculty_need_response('ok', [
        'message' => 'Faculty need deleted successfully.',
        'deleted' => $deleted,
        'needs' => synk_faculty_need_fetch_options($conn, $collegeId, $ayId, $semester),
    ]);
}

faculty_need_response('ok', [
    'needs' => synk_faculty_need_fetch_options($conn, $collegeId, $ayId, $semester),
]);
?>
