<?php
/*
|--------------------------------------------------------------------------
| SAVE ACADEMIC SETTINGS
|--------------------------------------------------------------------------
| Supports:
| - Global academic term + default scheduling policy
| - Per-college scheduling policy overrides
|--------------------------------------------------------------------------
*/

session_start();
include 'db.php';
require_once __DIR__ . '/academic_schedule_policy_helper.php';
require_once __DIR__ . '/signatory_settings_helper.php';
header('Content-Type: application/json');

function academic_settings_response(array $payload): void
{
    echo json_encode($payload);
    exit;
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    academic_settings_response([
        'status' => 'error',
        'message' => 'Unauthorized access.'
    ]);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$policyScope = trim((string)($_POST['policy_scope'] ?? 'global'));

if ($policyScope === 'college_override') {
    $collegeId = (int)($_POST['college_id'] ?? 0);
    $isOverrideEnabled = ((int)($_POST['college_override_enabled'] ?? 0)) === 1;

    if ($collegeId <= 0) {
        academic_settings_response([
            'status' => 'error',
            'message' => 'Please select a college for the override policy.'
        ]);
    }

    $collegeStmt = $conn->prepare("
        SELECT college_id
        FROM tbl_college
        WHERE college_id = ?
        LIMIT 1
    ");

    if (!$collegeStmt) {
        academic_settings_response([
            'status' => 'error',
            'message' => 'Unable to validate the selected college.'
        ]);
    }

    $collegeStmt->bind_param('i', $collegeId);
    $collegeStmt->execute();
    $collegeExists = $collegeStmt->get_result()->fetch_assoc() !== null;
    $collegeStmt->close();

    if (!$collegeExists) {
        academic_settings_response([
            'status' => 'error',
            'message' => 'The selected college could not be found.'
        ]);
    }

    $validation = synk_schedule_policy_validate_payload($_POST);
    if (empty($validation['ok'])) {
        academic_settings_response([
            'status' => 'error',
            'message' => (string)($validation['message'] ?? 'Invalid college override policy.')
        ]);
    }

    $saved = synk_save_college_schedule_policy_settings(
        $conn,
        $collegeId,
        (array)($validation['policy'] ?? []),
        $isOverrideEnabled,
        $userId
    );

    if (!$saved) {
        academic_settings_response([
            'status' => 'error',
            'message' => 'Failed to save the college scheduling policy override.'
        ]);
    }

    academic_settings_response([
        'status' => 'success',
        'message' => $isOverrideEnabled
            ? 'College scheduling policy override updated successfully.'
            : 'College scheduling policy override saved and set to inherit the global default.',
        'selected_college_id' => $collegeId,
        'schedule_policy' => synk_fetch_schedule_policy($conn),
        'college_override_policy' => synk_fetch_college_schedule_policy_settings($conn, $collegeId),
        'college_effective_policy' => synk_fetch_effective_schedule_policy($conn, $collegeId)
    ]);
}

if ($policyScope === 'academic_term') {
    $ayId = (int)($_POST['ay_id'] ?? 0);
    $semester = (int)($_POST['semester'] ?? 0);

    if ($ayId <= 0 || !in_array($semester, [1, 2, 3], true)) {
        academic_settings_response([
            'status' => 'error',
            'message' => 'Invalid academic year or semester.'
        ]);
    }

    $stmt = $conn->prepare("
        UPDATE tbl_academic_settings
        SET current_ay_id = ?,
            current_semester = ?,
            updated_by = ?,
            date_updated = NOW()
        LIMIT 1
    ");

    if (!$stmt) {
        academic_settings_response([
            'status' => 'error',
            'message' => 'Failed to prepare academic term update.'
        ]);
    }

    $stmt->bind_param('iii', $ayId, $semester, $userId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        academic_settings_response([
            'status' => 'error',
            'message' => 'Failed to update the academic term.'
        ]);
    }

    academic_settings_response([
        'status' => 'success',
        'message' => 'Academic term updated successfully.',
        'schedule_policy' => synk_fetch_schedule_policy($conn)
    ]);
}

if ($policyScope === 'global_policy') {
    $validation = synk_schedule_policy_validate_payload($_POST);
    if (empty($validation['ok'])) {
        academic_settings_response([
            'status' => 'error',
            'message' => (string)($validation['message'] ?? 'Invalid schedule policy.')
        ]);
    }

    $policy = synk_schedule_policy_payload_to_array((array)($validation['policy'] ?? []));
    synk_schedule_policy_ensure_columns($conn);

    $blockedDaysJson = json_encode($policy['blocked_days']);
    $blockedTimesJson = json_encode($policy['blocked_times']);

    $stmt = $conn->prepare("
        UPDATE tbl_academic_settings
        SET schedule_day_start = ?,
            schedule_day_end = ?,
            blocked_schedule_days_json = ?,
            blocked_schedule_times_json = ?,
            updated_by = ?,
            date_updated = NOW()
        LIMIT 1
    ");

    if (!$stmt) {
        academic_settings_response([
            'status' => 'error',
            'message' => 'Failed to prepare global schedule policy update.'
        ]);
    }

    $stmt->bind_param(
        'ssssi',
        $policy['day_start'],
        $policy['day_end'],
        $blockedDaysJson,
        $blockedTimesJson,
        $userId
    );

    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        academic_settings_response([
            'status' => 'error',
            'message' => 'Failed to update the global schedule policy.'
        ]);
    }

    academic_settings_response([
        'status' => 'success',
        'message' => 'Global schedule policy updated successfully.',
        'schedule_policy' => synk_fetch_schedule_policy($conn)
    ]);
}

if ($policyScope === 'report_signatories') {
    $payload = [
        'checked_by_left' => [
            'signatory_name' => trim((string)($_POST['checked_by_left_name'] ?? '')),
            'signatory_title' => trim((string)($_POST['checked_by_left_title'] ?? ''))
        ],
        'checked_by_right' => [
            'signatory_name' => trim((string)($_POST['checked_by_right_name'] ?? '')),
            'signatory_title' => trim((string)($_POST['checked_by_right_title'] ?? ''))
        ],
        'recommending_approval' => [
            'signatory_name' => trim((string)($_POST['recommending_approval_name'] ?? '')),
            'signatory_title' => trim((string)($_POST['recommending_approval_title'] ?? ''))
        ],
        'approved_by' => [
            'signatory_name' => trim((string)($_POST['approved_by_name'] ?? '')),
            'signatory_title' => trim((string)($_POST['approved_by_title'] ?? ''))
        ]
    ];

    if (!synk_save_signatory_settings($conn, 'global', 0, $payload, $userId)) {
        academic_settings_response([
            'status' => 'error',
            'message' => 'Failed to save the report signatories.'
        ]);
    }

    academic_settings_response([
        'status' => 'success',
        'message' => 'Report signatories updated successfully.',
        'signatory_settings' => synk_fetch_signatory_settings($conn, 'global', 0)
    ]);
}

$ayId = (int)($_POST['ay_id'] ?? 0);
$semester = (int)($_POST['semester'] ?? 0);

if ($ayId <= 0 || !in_array($semester, [1, 2, 3], true)) {
    academic_settings_response([
        'status' => 'error',
        'message' => 'Invalid academic year or semester.'
    ]);
}

$validation = synk_schedule_policy_validate_payload($_POST);
if (empty($validation['ok'])) {
    academic_settings_response([
        'status' => 'error',
        'message' => (string)($validation['message'] ?? 'Invalid schedule policy.')
    ]);
}

$policy = synk_schedule_policy_payload_to_array((array)($validation['policy'] ?? []));
synk_schedule_policy_ensure_columns($conn);

$blockedDaysJson = json_encode($policy['blocked_days']);
$blockedTimesJson = json_encode($policy['blocked_times']);

$stmt = $conn->prepare("
    UPDATE tbl_academic_settings
    SET current_ay_id = ?,
        current_semester = ?,
        schedule_day_start = ?,
        schedule_day_end = ?,
        blocked_schedule_days_json = ?,
        blocked_schedule_times_json = ?,
        updated_by = ?,
        date_updated = NOW()
    LIMIT 1
");

if (!$stmt) {
    academic_settings_response([
        'status' => 'error',
        'message' => 'Failed to prepare academic settings update.'
    ]);
}

$stmt->bind_param(
    'iissssi',
    $ayId,
    $semester,
    $policy['day_start'],
    $policy['day_end'],
    $blockedDaysJson,
    $blockedTimesJson,
    $userId
);

$ok = $stmt->execute();
$stmt->close();

if (!$ok) {
    academic_settings_response([
        'status' => 'error',
        'message' => 'Failed to update academic settings.'
    ]);
}

academic_settings_response([
    'status' => 'success',
    'message' => 'Academic settings updated successfully.',
    'schedule_policy' => synk_fetch_schedule_policy($conn)
]);
