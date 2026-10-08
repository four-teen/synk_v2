<?php
session_start();
include 'db.php';
require_once __DIR__ . '/workload_simulation_helper.php';

header('Content-Type: application/json');

function load_simulation_response(array $payload, int $statusCode = 200): void
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
    load_simulation_response(['rows' => [], 'status' => 'error', 'message' => 'Unauthorized.'], 403);
}

if (!synk_table_exists($conn, 'tbl_faculty_workload_simulation')) {
    load_simulation_response([
        'rows' => [],
        'status' => 'missing_table',
        'message' => 'The simulation table does not exist yet.'
    ], 500);
}

$collegeId = (int)($_SESSION['college_id'] ?? 0);
$facultyId = (int)($_POST['faculty_id'] ?? 0);
$ayId = (int)($_POST['ay_id'] ?? 0);
$semester = (int)($_POST['semester'] ?? 0);

if ($collegeId <= 0 || $facultyId <= 0 || $ayId <= 0 || $semester <= 0) {
    load_simulation_response(['rows' => []]);
}

$rows = synk_fetch_saved_workload_simulation_rows($conn, $collegeId, $facultyId, $ayId, $semester);

load_simulation_response([
    'status' => 'success',
    'rows' => $rows
]);
