<?php
session_start();
include 'db.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    echo json_encode(["status" => "error", "message" => "Unauthorized"]);
    exit;
}

/* =====================================================
   GET SUBJECTS OFFERED FOR A SECTION (LEVEL 3 DRILLDOWN)
   -----------------------------------------------------
   LOGGING ENABLED (log_section_offerings.txt)
   Purpose:
   - Verify section_id / ay_id / semester received
   - Verify row count returned
   - Catch SQL/prepare/execute errors
===================================================== */


$section_id = intval($_GET['section_id'] ?? 0);

if (!$section_id) {
    echo json_encode(["status" => "error", "message" => "Invalid parameters"]);
    exit;
}

$currentTerm = synk_fetch_current_academic_term($conn);
$ay_id = (int)($currentTerm['ay_id'] ?? 0);
$semester = (int)($currentTerm['semester'] ?? 0);

if ($ay_id <= 0 || $semester <= 0) {

    echo json_encode(["status" => "error", "message" => "Academic settings not configured"]);
    exit;
}

/* ------------------------------
   MAIN QUERY
-------------------------------- */
$liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('po', 's', 'sc', 'ps', 'pys', 'ph');

$sql = "
SELECT
    sm.sub_code,
    sm.sub_description,
    ps.total_units
FROM tbl_prospectus_offering po
{$liveOfferingJoins}

INNER JOIN tbl_subject_masterlist sm
    ON sm.sub_id = ps.sub_id

WHERE po.section_id = ?
  AND po.program_id = s.program_id      -- 🔒 PROGRAM GUARD
  AND po.year_level = s.year_level      -- 🔒 YEAR LEVEL GUARD
  AND po.ay_id = ?
  AND po.semester = ?

ORDER BY ps.sort_order, sm.sub_code;

";


$stmt = $conn->prepare($sql);

if (!$stmt) {
    echo json_encode([
        "status"  => "error",
        "message" => "Prepare failed",
        "debug"   => $conn->error
    ]);
    exit;
}

/* ------------------------------
   EXECUTE
-------------------------------- */
$stmt->bind_param("iii", $section_id, $ay_id, $semester);

if (!$stmt->execute()) {
    echo json_encode([
        "status"  => "error",
        "message" => "Execute failed",
        "debug"   => $stmt->error
    ]);
    exit;
}

$res = $stmt->get_result();

$data = [];
while ($row = $res->fetch_assoc()) {
    $data[] = $row;
}

$stmt->close();

echo json_encode([
    "status" => "success",
    "data"   => $data
]);
