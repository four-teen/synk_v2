<?php
// ../backend/load_offerings.php
session_start();
include 'db.php';
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schedule_merge_helper.php';

function offering_year_label($yearLevel): string
{
    $year = (int)$yearLevel;
    if ($year === 1) return '1st Year';
    if ($year === 2) return '2nd Year';
    if ($year === 3) return '3rd Year';
    if ($year === 4) return '4th Year';
    if ($year === 5) return '5th Year';
    if ($year === 6) return '6th Year';
    return $year > 0 ? ($year . 'th Year') : '-';
}

function offering_curriculum_label($effectiveSy, $cmoNo): string
{
    $effectiveSy = trim((string)$effectiveSy);
    $cmoNo = trim((string)$cmoNo);

    if ($effectiveSy !== '' && $cmoNo !== '') {
        return 'SY ' . $effectiveSy . ' - ' . $cmoNo;
    }

    if ($effectiveSy !== '') {
        return 'SY ' . $effectiveSy;
    }

    if ($cmoNo !== '') {
        return $cmoNo;
    }

    return '-';
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'scheduler') {
    echo "<tr><td colspan='9' class='text-center text-danger'>Unauthorized access.</td></tr>";
    exit;
}

$college_id = (int)($_SESSION['college_id'] ?? 0);
if ($college_id <= 0) {
    echo "<tr><td colspan='9' class='text-center text-danger'>Missing college context.</td></tr>";
    exit;
}

if (!synk_table_exists($conn, 'tbl_section_curriculum')) {
    echo "<tr><td colspan='9' class='text-center text-warning'>Create tbl_section_curriculum first to load unified offerings.</td></tr>";
    exit;
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    echo "<tr><td colspan='9' class='text-center text-danger'>CSRF validation failed.</td></tr>";
    exit;
}

$program_id = (int)($_POST['program_id'] ?? 0);
$ay_id = (int)($_POST['ay_id'] ?? 0);
$semester = (int)($_POST['semester'] ?? 0);

if ($program_id <= 0 || $ay_id <= 0 || $semester <= 0) {
    echo "<tr><td colspan='9' class='text-center text-muted'>Missing filters.</td></tr>";
    exit;
}

$liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
$scheduledOfferingJoin = synk_schedule_merge_scheduled_offering_join_sql($conn, 'sched', 'o');

$sql = "
    SELECT
        o.offering_id,
        sec.year_level,
        sec.full_section,
        sec.section_name,
        COALESCE(ph.effective_sy, '') AS effective_sy,
        COALESCE(ph.cmo_no, '') AS cmo_no,
        sm.sub_code,
        sm.sub_description,
        ps.lec_units,
        ps.lab_units,
        ps.total_units,
        CASE
            WHEN sched.offering_id IS NOT NULL THEN 'scheduled'
            WHEN o.status = 'locked' THEN 'locked'
            ELSE 'pending'
        END AS display_status
    FROM tbl_prospectus_offering o
    {$liveOfferingJoins}
    {$scheduledOfferingJoin}
    INNER JOIN tbl_subject_masterlist sm
        ON sm.sub_id = ps.sub_id
    INNER JOIN tbl_program p
        ON p.program_id = o.program_id
    WHERE o.program_id = ?
      AND o.ay_id = ?
      AND o.semester = ?
      AND p.college_id = ?
    ORDER BY
        sec.year_level ASC,
        sec.section_name ASC,
        sm.sub_code ASC
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo "<tr><td colspan='9' class='text-center text-danger'>Unable to load offerings.</td></tr>";
    exit;
}

$stmt->bind_param("iiii", $program_id, $ay_id, $semester, $college_id);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || $res->num_rows === 0) {
    echo "<tr><td colspan='9' class='text-center text-muted'>No offerings found.</td></tr>";
    exit;
}

while ($row = $res->fetch_assoc()) {
    $statusRaw = strtolower(trim((string)($row['display_status'] ?? 'pending')));
    $status = ucfirst($statusRaw);
    $badgeClass = 'bg-secondary';
    if ($statusRaw === 'scheduled') {
        $badgeClass = 'bg-success';
    } elseif ($statusRaw === 'locked') {
        $badgeClass = 'bg-dark';
    }

    $curriculumLabel = offering_curriculum_label($row['effective_sy'] ?? '', $row['cmo_no'] ?? '');

    $yearLabel = htmlspecialchars(offering_year_label($row['year_level'] ?? 0), ENT_QUOTES, 'UTF-8');
    $sectionLabel = trim((string)($row['full_section'] ?? '')) !== ''
        ? (string)$row['full_section']
        : (string)($row['section_name'] ?? '');

    echo '<tr>';
    echo '<td>' . $yearLabel . '</td>';
    echo '<td>' . htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars($curriculumLabel, ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(strtoupper((string)($row['sub_code'] ?? '')), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td>' . htmlspecialchars(strtoupper((string)($row['sub_description'] ?? '')), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td class="text-center">' . htmlspecialchars((string)($row['lec_units'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td class="text-center">' . htmlspecialchars((string)($row['lab_units'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo '<td class="text-center">' . htmlspecialchars((string)($row['total_units'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td>';
    echo "<td class='text-center'><span class='badge {$badgeClass}'>" . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span></td>';
    echo '</tr>';
}

$stmt->close();
