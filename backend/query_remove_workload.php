<?php
session_start();
include 'db.php';
require_once __DIR__ . '/faculty_need_helper.php';
require_once __DIR__ . '/workload_audit_helper.php';

if (!isset($_SESSION['user_id'])) exit;

$assigneeType = strtolower(trim((string)($_POST['assignee_type'] ?? 'faculty')));
$isFacultyNeed = $assigneeType === 'faculty_need';

$ids = [];

if (isset($_POST['workload_ids'])) {
    $rawIds = $_POST['workload_ids'];

    if (!is_array($rawIds)) {
        $rawIds = explode(',', (string)$rawIds);
    }

    foreach ($rawIds as $rawId) {
        $id = intval($rawId);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
}

if (empty($ids)) {
    $id = intval($_POST['workload_id'] ?? 0);
    if ($id > 0) {
        $ids[$id] = $id;
    }
}

if (empty($ids)) exit;

$ids = array_values($ids);
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));

$targetTable = $isFacultyNeed ? synk_faculty_need_workload_table_name() : 'tbl_faculty_workload_sched';
$targetColumn = $isFacultyNeed ? 'need_workload_id' : 'workload_id';

if ($isFacultyNeed) {
    synk_faculty_need_ensure_tables($conn);
}

$auditRows = synk_workload_audit_fetch_workload_rows($conn, $assigneeType, $ids);

$stmt = $conn->prepare("
    DELETE FROM `{$targetTable}`
    WHERE {$targetColumn} IN ($placeholders)
");

if (!$stmt) exit;

$params = [$types];
foreach ($ids as $index => $idValue) {
    $params[] = &$ids[$index];
}

call_user_func_array([$stmt, 'bind_param'], $params);
$stmt->execute();
$deletedCount = max(0, (int)$stmt->affected_rows);
$stmt->close();

if ($deletedCount > 0 && !empty($auditRows)) {
    foreach ($auditRows as $auditRow) {
        synk_workload_audit_record_workload_event($conn, 'workload_delete', $auditRow, [
            'requested_workload_ids' => $ids,
            'deleted_count' => $deletedCount,
        ]);
    }
}

exit;
