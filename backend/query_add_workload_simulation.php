<?php
session_start();
include 'db.php';
require_once __DIR__ . '/workload_simulation_helper.php';

header('Content-Type: application/json');

function simulation_json_response(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'scheduler'
) {
    simulation_json_response(['status' => 'error', 'message' => 'Unauthorized.'], 403);
}

if (!synk_table_exists($conn, 'tbl_faculty_workload_simulation')) {
    simulation_json_response([
        'status' => 'missing_table',
        'message' => 'The simulation table does not exist yet.'
    ], 500);
}

$collegeId = (int)($_SESSION['college_id'] ?? 0);
$createdBy = (int)($_SESSION['user_id'] ?? 0);
$facultyId = (int)($_POST['faculty_id'] ?? 0);
$ayId = (int)($_POST['ay_id'] ?? 0);
$semester = (int)($_POST['semester'] ?? 0);
$simKey = trim((string)($_POST['sim_key'] ?? ''));

if ($collegeId <= 0 || $facultyId <= 0 || $ayId <= 0 || $semester <= 0 || $simKey === '') {
    simulation_json_response(['status' => 'error', 'message' => 'Invalid input.'], 422);
}

$catalogRows = synk_fetch_workload_simulation_catalog($conn, $collegeId, $ayId, $semester);
$catalogMap = [];
foreach ($catalogRows as $row) {
    $catalogMap[(string)($row['sim_key'] ?? '')] = $row;
}

if (!isset($catalogMap[$simKey])) {
    simulation_json_response(['status' => 'error', 'message' => 'Selected simulation row was not found.'], 404);
}

$existingStmt = $conn->prepare("
    SELECT simulation_id, faculty_id
    FROM tbl_faculty_workload_simulation
    WHERE college_id = ?
      AND ay_id = ?
      AND semester = ?
      AND simulation_key = ?
    LIMIT 1
");

if ($existingStmt) {
    $existingStmt->bind_param('iiis', $collegeId, $ayId, $semester, $simKey);
    $existingStmt->execute();
    $existingRes = $existingStmt->get_result();
    $existingRow = $existingRes ? $existingRes->fetch_assoc() : null;
    $existingStmt->close();

    if (is_array($existingRow) && (int)($existingRow['faculty_id'] ?? 0) !== $facultyId) {
        simulation_json_response([
            'status' => 'error',
            'message' => 'This simulation row is already assigned to another faculty.'
        ], 409);
    }
}

$row = $catalogMap[$simKey];
$offeringId = (int)($row['offering_id'] ?? 0);
$scheduleId = (int)($row['schedule_id'] ?? 0);
$scheduleType = strtoupper(trim((string)($row['schedule_type'] ?? 'LEC')));
$sourceKind = trim((string)($row['source_kind'] ?? 'scheduled'));

$sql = "
    INSERT INTO tbl_faculty_workload_simulation
        (college_id, faculty_id, ay_id, semester, simulation_key, offering_id, schedule_id, schedule_type, source_kind, created_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        offering_id = VALUES(offering_id),
        schedule_id = VALUES(schedule_id),
        schedule_type = VALUES(schedule_type),
        source_kind = VALUES(source_kind),
        updated_at = CURRENT_TIMESTAMP
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    simulation_json_response(['status' => 'error', 'message' => 'Unable to prepare the simulation save statement.'], 500);
}

$scheduleIdParam = $scheduleId > 0 ? $scheduleId : 0;
$stmt->bind_param(
    'iiiisisssi',
    $collegeId,
    $facultyId,
    $ayId,
    $semester,
    $simKey,
    $offeringId,
    $scheduleIdParam,
    $scheduleType,
    $sourceKind,
    $createdBy
);

if (!$stmt->execute()) {
    $stmt->close();
    simulation_json_response(['status' => 'error', 'message' => 'Unable to save the simulation row.'], 500);
}

$stmt->close();

simulation_json_response([
    'status' => 'success',
    'message' => 'Simulation row saved.'
]);
