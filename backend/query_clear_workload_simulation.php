<?php
session_start();
include 'db.php';
require_once __DIR__ . '/schema_helper.php';

header('Content-Type: application/json');

function clear_simulation_response(array $payload, int $statusCode = 200): void
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
    clear_simulation_response(['status' => 'error', 'message' => 'Unauthorized.'], 403);
}

if (!synk_table_exists($conn, 'tbl_faculty_workload_simulation')) {
    clear_simulation_response(['status' => 'missing_table', 'message' => 'The simulation table does not exist yet.'], 500);
}

$collegeId = (int)($_SESSION['college_id'] ?? 0);
$facultyId = (int)($_POST['faculty_id'] ?? 0);
$ayId = (int)($_POST['ay_id'] ?? 0);
$semester = (int)($_POST['semester'] ?? 0);

if ($collegeId <= 0 || $facultyId <= 0 || $ayId <= 0 || $semester <= 0) {
    clear_simulation_response(['status' => 'error', 'message' => 'Invalid input.'], 422);
}

$stmt = $conn->prepare("
    DELETE FROM tbl_faculty_workload_simulation
    WHERE college_id = ?
      AND faculty_id = ?
      AND ay_id = ?
      AND semester = ?
");

if (!$stmt) {
    clear_simulation_response(['status' => 'error', 'message' => 'Unable to prepare the simulation clear statement.'], 500);
}

$stmt->bind_param('iiii', $collegeId, $facultyId, $ayId, $semester);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

clear_simulation_response([
    'status' => 'success',
    'removed' => max(0, $affected)
]);
