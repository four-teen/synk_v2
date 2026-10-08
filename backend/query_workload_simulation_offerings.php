<?php
session_start();
include 'db.php';
require_once __DIR__ . '/workload_simulation_helper.php';

header('Content-Type: application/json');

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'scheduler'
) {
    echo json_encode(['rows' => []]);
    exit;
}

$collegeId = (int)($_SESSION['college_id'] ?? 0);
$ayId = (int)($_POST['ay_id'] ?? 0);
$semester = (int)($_POST['semester'] ?? ($_POST['semester_num'] ?? 0));

$rows = synk_fetch_workload_simulation_catalog($conn, $collegeId, $ayId, $semester);

if (synk_table_exists($conn, 'tbl_faculty_workload_simulation') && $collegeId > 0 && $ayId > 0 && $semester > 0) {
    $assignedStmt = $conn->prepare("
        SELECT simulation_key
        FROM tbl_faculty_workload_simulation
        WHERE college_id = ?
          AND ay_id = ?
          AND semester = ?
    ");

    if ($assignedStmt) {
        $assignedStmt->bind_param('iii', $collegeId, $ayId, $semester);
        $assignedStmt->execute();
        $assignedRes = $assignedStmt->get_result();
        $assignedKeys = [];

        while ($assignedRes && ($assignedRow = $assignedRes->fetch_assoc())) {
            $key = trim((string)($assignedRow['simulation_key'] ?? ''));
            if ($key !== '') {
                $assignedKeys[$key] = true;
            }
        }

        $assignedStmt->close();

        if (!empty($assignedKeys)) {
            $rows = array_values(array_filter($rows, static function ($row) use ($assignedKeys) {
                $key = trim((string)($row['sim_key'] ?? ''));
                return $key === '' || !isset($assignedKeys[$key]);
            }));
        }
    }
}

echo json_encode([
    'rows' => $rows
]);
exit;
