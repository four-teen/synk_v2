<?php
/*
|--------------------------------------------------------------------------
| GET ACTIVE SECTIONS (CURRENT TERM)
|--------------------------------------------------------------------------
| Purpose:
| - Returns list of active sections for the current academic year & semester
| - Section is considered ACTIVE if it has a prospectus offering
| - Used by Admin Dashboard KPI drill-down
|--------------------------------------------------------------------------
*/

session_start();
include 'db.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized'
    ]);
    exit;
}

$currentTerm = synk_fetch_current_academic_term($conn);
$ayId = (int)($currentTerm['ay_id'] ?? 0);
$sem = (int)($currentTerm['semester'] ?? 0);

if ($ayId <= 0 || $sem <= 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Academic settings not configured.'
    ]);
    exit;
}

/*
|--------------------------------------------------------------------------
| ACTIVE SECTIONS QUERY (OPTION A – OFFERING BASED)
|--------------------------------------------------------------------------
*/
$liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('po', 's', 'sc', 'ps', 'pys', 'ph');

$sql = "
    SELECT
        s.section_id,
        s.section_name,
        p.program_code,
        p.program_name,
        c.college_name,
        camp.campus_name,
        COUNT(po.offering_id) AS offering_count
    FROM tbl_prospectus_offering po
    {$liveOfferingJoins}

    INNER JOIN tbl_program p
        ON p.program_id = s.program_id

    INNER JOIN tbl_college c
        ON c.college_id = p.college_id

    INNER JOIN tbl_campus camp
        ON camp.campus_id = c.campus_id

    WHERE po.ay_id = ?
      AND po.semester = ?
      AND p.status = 'active'

    GROUP BY s.section_id
    ORDER BY camp.campus_name, p.program_code, s.section_name
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $ayId, $sem);
$stmt->execute();
$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) {
    $data[] = $row;
}

$stmt->close();

echo json_encode([
    'status' => 'success',
    'data'   => $data
]);
