<?php
session_start();
include 'db.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['college_id'])) {
    echo json_encode([]);
    exit;
}

$college_id = (int)$_SESSION['college_id'];
$currentTerm = synk_fetch_current_academic_term($conn);
$currentAyId = (int)$currentTerm['ay_id'];
$currentSemester = (int)$currentTerm['semester'];

if ($currentAyId <= 0 || $currentSemester <= 0) {
    echo json_encode([]);
    exit;
}

/*
 PURPOSE:
 - Get total teaching units per faculty
 - Filtered by college
 - Scoped to the global academic settings term
*/

$liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');

$sql = "
SELECT
    workload.faculty_id,
    workload.faculty_name,
    SUM(workload.total_units) AS total_units
FROM (
    SELECT DISTINCT
        f.faculty_id,
        CONCAT(f.last_name, ', ', f.first_name, ' ', COALESCE(f.ext_name, '')) AS faculty_name,
        o.offering_id,
        ps.total_units
    FROM tbl_faculty_workload_sched fw
    JOIN tbl_faculty f
        ON f.faculty_id = fw.faculty_id
    JOIN tbl_class_schedule cs
        ON cs.schedule_id = fw.schedule_id
    JOIN tbl_prospectus_offering o
        ON o.offering_id = cs.offering_id
    {$liveOfferingJoins}
    JOIN tbl_college_faculty cf
        ON cf.faculty_id = f.faculty_id
    JOIN tbl_program p
        ON p.program_id = o.program_id
    WHERE
        cf.college_id = ?
        AND cf.status = 'active'
        AND f.status = 'active'
        AND p.college_id = ?
        AND o.ay_id = ?
        AND o.semester = ?
        AND fw.ay_id = ?
        AND fw.semester = ?
) AS workload
GROUP BY workload.faculty_id, workload.faculty_name
ORDER BY workload.faculty_name ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iiiiii", $college_id, $college_id, $currentAyId, $currentSemester, $currentAyId, $currentSemester);
$stmt->execute();
$res = $stmt->get_result();

$data = [];

while ($row = $res->fetch_assoc()) {
    $data[] = [
        "faculty" => $row['faculty_name'],
        "units"   => round((float)($row['total_units'] ?? 0), 2)
    ];
}

echo json_encode($data);
