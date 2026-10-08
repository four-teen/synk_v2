<?php
session_start();
include 'db.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schedule_merge_helper.php';
require_once __DIR__ . '/scheduler_access_helper.php';

header('Content-Type: application/json');

function empty_dashboard_unscheduled_payload(): array
{
    return [
        'scope' => 'college',
        'scope_label' => '',
        'term_text' => '',
        'total' => 0,
        'rows' => []
    ];
}

if (!isset($_SESSION['college_id'])) {
    echo json_encode(empty_dashboard_unscheduled_payload());
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
$termText = (string)($currentTerm['term_text'] ?? '');

if ($collegeId <= 0 || $currentAyId <= 0 || $currentSemester <= 0) {
    $payload = empty_dashboard_unscheduled_payload();
    $payload['scope'] = $scope;
    $payload['scope_label'] = $scopeLabel;
    $payload['term_text'] = $termText;
    echo json_encode($payload);
    exit;
}

$filterValue = $scope === 'campus' ? $campusId : $collegeId;
$filterSql = $scope === 'campus' ? 'c.campus_id = ?' : 'p.college_id = ?';
$liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
$scheduledOfferingJoin = synk_schedule_merge_scheduled_offering_join_sql($conn, 'sched', 'o');

$sql = "
    SELECT
        o.offering_id,
        sec.year_level,
        sec.section_name,
        COALESCE(
            NULLIF(TRIM(c.college_code), ''),
            NULLIF(TRIM(c.college_name), ''),
            CONCAT('College ', c.college_id)
        ) AS college_label,
        COALESCE(
            NULLIF(TRIM(p.program_code), ''),
            NULLIF(TRIM(p.program_name), ''),
            CONCAT('Program ', p.program_id)
        ) AS program_label,
        sm.sub_code,
        sm.sub_description,
        ps.lec_units,
        ps.lab_units,
        ps.total_units
    FROM tbl_prospectus_offering o
    {$liveOfferingJoins}
    INNER JOIN tbl_program p
        ON p.program_id = o.program_id
    INNER JOIN tbl_college c
        ON c.college_id = p.college_id
    INNER JOIN tbl_subject_masterlist sm
        ON sm.sub_id = ps.sub_id
    {$scheduledOfferingJoin}
    WHERE {$filterSql}
      AND c.status = 'active'
      AND p.status = 'active'
      AND o.ay_id = ?
      AND o.semester = ?
      AND sched.offering_id IS NULL
    ORDER BY
      college_label ASC,
      program_label ASC,
      sec.year_level ASC,
      sec.section_name ASC,
      sm.sub_code ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    $payload = empty_dashboard_unscheduled_payload();
    $payload['scope'] = $scope;
    $payload['scope_label'] = $scopeLabel;
    $payload['term_text'] = $termText;
    echo json_encode($payload);
    exit;
}

$stmt->bind_param("iii", $filterValue, $currentAyId, $currentSemester);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
while ($row = $res->fetch_assoc()) {
    $rows[] = [
        'offering_id' => (int)$row['offering_id'],
        'college_label' => (string)($row['college_label'] ?? ''),
        'program_label' => (string)($row['program_label'] ?? ''),
        'year_level' => (int)($row['year_level'] ?? 0),
        'section_name' => (string)($row['section_name'] ?? ''),
        'sub_code' => (string)($row['sub_code'] ?? ''),
        'sub_description' => (string)($row['sub_description'] ?? ''),
        'lec_units' => (float)($row['lec_units'] ?? 0),
        'lab_units' => (float)($row['lab_units'] ?? 0),
        'total_units' => (float)($row['total_units'] ?? 0)
    ];
}

$stmt->close();

echo json_encode([
    'scope' => $scope,
    'scope_label' => $scopeLabel,
    'term_text' => $termText,
    'total' => count($rows),
    'rows' => $rows
]);
exit;
?>
