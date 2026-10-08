<?php
session_start();
include 'db.php';
require_once __DIR__ . '/schema_helper.php';

header('Content-Type: application/json');

function remove_simulation_response(array $payload, int $statusCode = 200): void
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
    remove_simulation_response(['status' => 'error', 'message' => 'Unauthorized.'], 403);
}

if (!synk_table_exists($conn, 'tbl_faculty_workload_simulation')) {
    remove_simulation_response(['status' => 'missing_table', 'message' => 'The simulation table does not exist yet.'], 500);
}

$collegeId = (int)($_SESSION['college_id'] ?? 0);
$simulationId = (int)($_POST['simulation_id'] ?? 0);

if ($collegeId <= 0 || $simulationId <= 0) {
    remove_simulation_response(['status' => 'error', 'message' => 'Invalid input.'], 422);
}

$stmt = $conn->prepare("
    DELETE FROM tbl_faculty_workload_simulation
    WHERE simulation_id = ?
      AND college_id = ?
    LIMIT 1
");

if (!$stmt) {
    remove_simulation_response(['status' => 'error', 'message' => 'Unable to prepare the simulation delete statement.'], 500);
}

$stmt->bind_param('ii', $simulationId, $collegeId);
$stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

remove_simulation_response([
    'status' => 'success',
    'removed' => max(0, $affected)
]);
