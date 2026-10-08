<?php
session_start();
ob_start();
include 'db.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schedule_merge_helper.php';
require_once __DIR__ . '/scheduler_access_helper.php';

header('Content-Type: application/json');

function empty_dashboard_count_payload(): array
{
    return [
        'scope' => 'college',
        'scope_label' => '',
        'programs' => 0,
        'faculty' => 0,
        'offering_items' => 0,
        'unscheduled_classes' => 0
    ];
}

if (!isset($_SESSION['college_id'])) {
    echo json_encode(empty_dashboard_count_payload());
    exit;
}

synk_scheduler_bootstrap_session_scope($conn);

$collegeId = (int)($_SESSION['college_id'] ?? 0);
$campusId = (int)($_SESSION['campus_id'] ?? 0);
$scope = strtolower(trim((string)($_POST['scope'] ?? 'college')));
if ($scope !== 'campus' || $campusId <= 0) {
    $scope = 'college';
}

$scopeLabel = $scope === 'campus'
    ? (string)($_SESSION['campus_name'] ?? '')
    : (string)($_SESSION['college_name'] ?? '');

$currentTerm = synk_fetch_current_academic_term($conn);
$currentAyId = (int)($currentTerm['ay_id'] ?? 0);
$currentSemester = (int)($currentTerm['semester'] ?? 0);

if ($collegeId <= 0 || $currentAyId <= 0 || $currentSemester <= 0) {
    echo json_encode(empty_dashboard_count_payload());
    exit;
}

$filterValue = $scope === 'campus' ? $campusId : $collegeId;
$filterSql = $scope === 'campus' ? 'c.campus_id = ?' : 'p.college_id = ?';
$liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
$scheduledOfferingJoin = synk_schedule_merge_scheduled_offering_join_sql($conn, 'sched', 'o');

$sql = "
    SELECT
        (SELECT COUNT(DISTINCT o.program_id)
         FROM tbl_prospectus_offering o
         {$liveOfferingJoins}
         INNER JOIN tbl_program p ON p.program_id = o.program_id
         INNER JOIN tbl_college c ON c.college_id = p.college_id
         WHERE {$filterSql}
           AND c.status = 'active'
           AND p.status = 'active'
           AND o.ay_id = ?
           AND o.semester = ?) AS programs,

        (SELECT COUNT(DISTINCT fw.faculty_id)
         FROM tbl_faculty_workload_sched fw
         INNER JOIN tbl_class_schedule cs ON cs.schedule_id = fw.schedule_id
         INNER JOIN tbl_prospectus_offering o ON o.offering_id = cs.offering_id
         {$liveOfferingJoins}
         INNER JOIN tbl_program p ON p.program_id = o.program_id
         INNER JOIN tbl_college c ON c.college_id = p.college_id
         INNER JOIN tbl_faculty f ON f.faculty_id = fw.faculty_id
         WHERE {$filterSql}
           AND c.status = 'active'
           AND p.status = 'active'
           AND f.status = 'active'
           AND o.ay_id = ?
           AND o.semester = ?
           AND fw.ay_id = ?
           AND fw.semester = ?) AS faculty,

        (SELECT COUNT(*)
         FROM tbl_prospectus_offering o
         {$liveOfferingJoins}
         INNER JOIN tbl_program p ON p.program_id = o.program_id
         INNER JOIN tbl_college c ON c.college_id = p.college_id
         WHERE {$filterSql}
           AND c.status = 'active'
           AND p.status = 'active'
           AND o.ay_id = ?
           AND o.semester = ?) AS offering_items,

        (SELECT COUNT(*)
         FROM tbl_prospectus_offering o
         {$liveOfferingJoins}
         {$scheduledOfferingJoin}
         INNER JOIN tbl_program p ON p.program_id = o.program_id
         INNER JOIN tbl_college c ON c.college_id = p.college_id
         WHERE {$filterSql}
           AND c.status = 'active'
           AND p.status = 'active'
           AND o.ay_id = ?
           AND o.semester = ?
           AND sched.offering_id IS NULL) AS unscheduled_classes
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(empty_dashboard_count_payload());
    exit;
}

$stmt->bind_param(
    "iiiiiiiiiiiiii",
    $filterValue,
    $currentAyId,
    $currentSemester,
    $filterValue,
    $currentAyId,
    $currentSemester,
    $currentAyId,
    $currentSemester,
    $filterValue,
    $currentAyId,
    $currentSemester,
    $filterValue,
    $currentAyId,
    $currentSemester
);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo json_encode([
    'scope' => $scope,
    'scope_label' => $scopeLabel,
    'programs' => (int)($row['programs'] ?? 0),
    'faculty' => (int)($row['faculty'] ?? 0),
    'offering_items' => (int)($row['offering_items'] ?? 0),
    'unscheduled_classes' => (int)($row['unscheduled_classes'] ?? 0)
]);
exit;
?>
