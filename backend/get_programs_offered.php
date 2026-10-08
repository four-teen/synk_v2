<?php
session_start();
include 'db.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/*
|--------------------------------------------------------------------------
| SECURITY CHECK
|--------------------------------------------------------------------------
*/
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized access'
    ]);
    exit;
}

$currentTerm = synk_fetch_current_academic_term($conn);
$currentAyId = (int)($currentTerm['ay_id'] ?? 0);
$currentSem = (int)($currentTerm['semester'] ?? 0);

if (!$currentAyId || !$currentSem) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Academic term not set'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| PROGRAMS OFFERED QUERY (CANONICAL)
|--------------------------------------------------------------------------
*/
$liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('po', 's', 'sc', 'ps', 'pys', 'ph');

$sql = "
    SELECT
        p.program_code,
        p.program_name,
        p.major,
        col.college_name,
        camp.campus_name,
        COUNT(DISTINCT s.section_id) AS section_count
    FROM tbl_prospectus_offering po
    {$liveOfferingJoins}

    INNER JOIN tbl_program p
        ON p.program_id = s.program_id

    INNER JOIN tbl_college col
        ON col.college_id = p.college_id

    INNER JOIN tbl_campus camp
        ON camp.campus_id = col.campus_id

    WHERE po.ay_id = ?
      AND po.semester = ?
      AND p.status = 'active'

    GROUP BY
        p.program_code,
        p.program_name,
        p.major,
        col.college_name,
        camp.campus_name

    ORDER BY
        camp.campus_name ASC,
        col.college_name ASC,
        p.program_code ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $currentAyId, $currentSem);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) {
    $data[] = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/
echo json_encode([
    'status' => 'success',
    'data'   => $data
]);
