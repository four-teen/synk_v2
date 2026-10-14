<?php
session_start();
require_once 'db.php';
require_once __DIR__ . '/schedule_block_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    http_response_code(403);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$role = $_SESSION['role'];
$myCollege = (int)($_SESSION['college_id'] ?? 0);

if (!in_array($role, ['admin', 'scheduler', 'program_chair', 'college_dean'], true)) {
    http_response_code(403);
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

if ($role === 'college_dean') {
    require_once __DIR__ . '/college_dean_helper.php';
    $account = synk_find_useraccount_by_id($conn, (int)$_SESSION['user_id']);
    if (!$account || !synk_college_dean_account_has_access($conn, $account)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    synk_college_dean_store_session_scope($account);
    $myCollege = (int)$account['college_dean_college_id'];
}

if ($role === 'program_chair') {
    require_once __DIR__ . '/auth_useraccount.php';
    $account = synk_find_useraccount_by_id($conn, (int)$_SESSION['user_id']);
    if (!$account || !synk_program_chair_account_has_access($conn, $account)) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
    $_SESSION['college_id'] = (int)$account['college_id'];
    $_SESSION['college_name'] = (string)($account['college_name'] ?? '');
    $myCollege = (int)$account['college_id'];
}

$pid = intval($_GET['prospectus_id'] ?? 0);
if ($pid <= 0) {
    echo json_encode(["error" => "Invalid prospectus id"]);
    exit;
}

function normalize_prospectus_subject_values(float $lecHours, float $labValue, ?float $storedTotalUnits = null): array
{
    $safeLecHours = max(0.0, $lecHours);
    $displayLabHours = round(
        synk_lab_contact_hours($safeLecHours, max(0.0, $labValue), (float)($storedTotalUnits ?? 0.0)),
        2
    );

    return [
        'lec_units' => round($safeLecHours, 2),
        'lab_units' => $displayLabHours,
        'total_units' => round(synk_subject_units_total($safeLecHours, $displayLabHours, 0.0), 2)
    ];
}

if (in_array($role, ['scheduler', 'program_chair', 'college_dean'], true)) {
    $headerSql = "
        SELECT h.prospectus_id, h.program_id, h.cmo_no, h.effective_sy,
               p.program_name, p.program_code, p.major
        FROM tbl_prospectus_header h
        INNER JOIN tbl_program p ON p.program_id = h.program_id
        WHERE h.prospectus_id = ?
          AND p.college_id = ?
        LIMIT 1
    ";
    $headerStmt = $conn->prepare($headerSql);
    $headerStmt->bind_param("ii", $pid, $myCollege);
} else {
    $headerSql = "
        SELECT h.prospectus_id, h.program_id, h.cmo_no, h.effective_sy,
               p.program_name, p.program_code, p.major
        FROM tbl_prospectus_header h
        LEFT JOIN tbl_program p ON p.program_id = h.program_id
        WHERE h.prospectus_id = ?
        LIMIT 1
    ";
    $headerStmt = $conn->prepare($headerSql);
    $headerStmt->bind_param("i", $pid);
}
$headerStmt->execute();
$header = $headerStmt->get_result()->fetch_assoc();
$headerStmt->close();

if (!$header) {
    http_response_code(in_array($role, ['scheduler', 'program_chair', 'college_dean'], true) ? 403 : 404);
    echo json_encode([
        "error" => in_array($role, ['scheduler', 'program_chair', 'college_dean'], true)
            ? "Unauthorized prospectus access"
            : "Prospectus not found"
    ]);
    exit;
}

/* ===========================
   1) YEAR/SEM STRUCTURE + SUBJECTS
=========================== */
$structure = [];
$subjects = [];

$detailSql = "
    SELECT
        pys.pys_id,
        pys.year_level,
        pys.semester,
        ps.ps_id,
        s.sub_code,
        s.sub_description,
        ps.lec_units,
        ps.lab_units,
        ps.total_units,
        ps.prerequisites,
        ps.sort_order
    FROM tbl_prospectus_year_sem pys
    LEFT JOIN tbl_prospectus_subjects ps
        ON ps.pys_id = pys.pys_id
    LEFT JOIN tbl_subject_masterlist s
        ON s.sub_id = ps.sub_id
    WHERE pys.prospectus_id = ?
    ORDER BY pys.year_level ASC, pys.semester ASC, ps.sort_order ASC, s.sub_code ASC
";
$detailStmt = $conn->prepare($detailSql);
$detailStmt->bind_param("i", $pid);
$detailStmt->execute();
$detailRes = $detailStmt->get_result();

while ($row = $detailRes->fetch_assoc()) {
    $year = (string)$row['year_level'];
    $sem  = (string)$row['semester'];

    if (!isset($structure[$year])) {
        $structure[$year] = [];
    }
    if (!isset($structure[$year][$sem])) {
        $structure[$year][$sem] = [];
    }

    if ((int)($row['ps_id'] ?? 0) <= 0) {
        continue;
    }

    if (!isset($subjects[$year])) {
        $subjects[$year] = [];
    }
    if (!isset($subjects[$year][$sem])) {
        $subjects[$year][$sem] = [];
    }

    $normalized = normalize_prospectus_subject_values(
        (float)($row['lec_units'] ?? 0),
        (float)($row['lab_units'] ?? 0),
        isset($row['total_units']) && $row['total_units'] !== null ? (float)$row['total_units'] : null
    );

    $subjects[$year][$sem][] = [
        'sub_code' => $row['sub_code'],
        'sub_description' => $row['sub_description'],
        'lec_units' => $normalized['lec_units'],
        'lab_units' => $normalized['lab_units'],
        'total_units' => $normalized['total_units'],
        'prerequisites' => trim((string)($row['prerequisites'] ?? '')) !== ''
            ? trim((string)$row['prerequisites'])
            : 'None',
        'sort_order' => isset($row['sort_order']) ? (int)$row['sort_order'] : null,
    ];
}
$detailStmt->close();

echo json_encode([
    "header"    => $header,
    "structure" => $structure,
    "subjects"  => $subjects
]);
exit;
