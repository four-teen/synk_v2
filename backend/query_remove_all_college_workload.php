<?php
session_start();
include 'db.php';
require_once __DIR__ . '/workload_audit_helper.php';

header('Content-Type: application/json');

function respond(string $status, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'status' => $status,
        'message' => $message
    ], $extra));
    exit;
}

if (
    !isset($_SESSION['user_id']) ||
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'scheduler'
) {
    respond('error', 'Unauthorized.');
}

$collegeId = (int)($_SESSION['college_id'] ?? 0);
$ayId = (int)($_POST['ay_id'] ?? 0);
$semester = (int)($_POST['semester'] ?? 0);

if ($collegeId <= 0) {
    respond('error', 'Missing college context.');
}

if ($ayId <= 0 || !in_array($semester, [1, 2, 3], true)) {
    respond('error', 'Select Academic Year and Semester first.');
}

$auditRows = synk_workload_audit_fetch_college_workload_rows($conn, $collegeId, $ayId, $semester);

$deleteSql = "
    DELETE fw
    FROM tbl_faculty_workload_sched fw
    INNER JOIN tbl_class_schedule cs
        ON cs.schedule_id = fw.schedule_id
    INNER JOIN tbl_prospectus_offering o
        ON o.offering_id = cs.offering_id
    INNER JOIN tbl_program p
        ON p.program_id = o.program_id
    WHERE fw.ay_id = ?
      AND fw.semester = ?
      AND p.college_id = ?
";

$deleteStmt = $conn->prepare($deleteSql);
if (!$deleteStmt) {
    respond('error', 'Failed to prepare the workload removal request.');
}

$deleteStmt->bind_param('iii', $ayId, $semester, $collegeId);

if (!$deleteStmt->execute()) {
    $deleteStmt->close();
    respond('error', 'Failed to remove workload for the selected college term.');
}

$deletedCount = max(0, (int)$deleteStmt->affected_rows);
$deleteStmt->close();

if ($deletedCount > 0) {
    synk_workload_audit_record_bulk_delete($conn, $collegeId, $ayId, $semester, $auditRows, $deletedCount);
}

respond(
    'ok',
    $deletedCount > 0
        ? $deletedCount . ' workload row(s) removed for the selected college term.'
        : 'No assigned workload was found for the selected college term.',
    ['deleted_count' => $deletedCount]
);
?>
