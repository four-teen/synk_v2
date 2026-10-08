<?php
session_start();
include 'db.php';
require_once __DIR__ . '/offering_scope_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'scheduler') {
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized access.'
    ]);
    exit;
}

$college_id = (int)($_SESSION['college_id'] ?? 0);

function mapSemester($value) {
    if ($value === '1st') {
        return 1;
    }

    if ($value === '2nd') {
        return 2;
    }

    if ($value === 'Midyear') {
        return 3;
    }

    return 0;
}

function normalize_iso_day_token($day) {
    $token = strtoupper(trim((string)$day));
    return $token === 'TH' ? 'TH' : $token;
}

function normalize_iso_day_key($days) {
    $order = [
        'M' => 1,
        'T' => 2,
        'W' => 3,
        'TH' => 4,
        'F' => 5,
        'S' => 6
    ];

    if (!is_array($days)) {
        return '';
    }

    $normalized = [];
    foreach ($days as $day) {
        $token = normalize_iso_day_token($day);
        if ($token !== '' && isset($order[$token])) {
            $normalized[$token] = true;
        }
    }

    $tokens = array_keys($normalized);
    usort($tokens, function ($left, $right) use ($order) {
        return ($order[$left] ?? 99) <=> ($order[$right] ?? 99);
    });

    return implode('', $tokens);
}

function format_program_option_label($programCode, $programName, $major) {
    $parts = [];
    $code = trim((string)$programCode);
    $name = trim((string)$programName);
    $majorLabel = trim((string)$major);

    if ($code !== '') {
        $parts[] = $code;
    }

    if ($name !== '') {
        $parts[] = $name;
    }

    $label = implode(' - ', $parts);
    if ($majorLabel !== '') {
        $label .= ($label !== '' ? ' ' : '') . '(Major in ' . $majorLabel . ')';
    }

    return $label;
}

function load_program_options_for_term(mysqli $conn, int $collegeId, string $ayLabel, int $semester): array
{
    $sql = "
        SELECT DISTINCT
            p.program_id,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS major
        FROM tbl_sections s
        INNER JOIN tbl_program p
            ON p.program_id = s.program_id
        INNER JOIN tbl_academic_years ay
            ON ay.ay_id = s.ay_id
        WHERE p.college_id = ?
          AND ay.ay = ?
          AND s.semester = ?
        ORDER BY
            p.program_code ASC,
            p.program_name ASC,
            p.major ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("isi", $collegeId, $ayLabel, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $programs = [];
    while ($row = $res->fetch_assoc()) {
        $programs[] = [
            'program_id' => (int)($row['program_id'] ?? 0),
            'label' => format_program_option_label(
                $row['program_code'] ?? '',
                $row['program_name'] ?? '',
                $row['major'] ?? ''
            ),
            'program_code' => (string)($row['program_code'] ?? ''),
            'program_name' => (string)($row['program_name'] ?? ''),
            'major' => (string)($row['major'] ?? '')
        ];
    }

    $stmt->close();
    return $programs;
}

if (isset($_POST['load_program_options'])) {
    $ay = trim((string)($_POST['ay'] ?? ''));
    $semester = mapSemester($_POST['semester'] ?? '');

    if ($ay === '' || $semester <= 0 || $college_id <= 0) {
        echo json_encode([
            'status' => 'ok',
            'programs' => []
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'ok',
        'programs' => load_program_options_for_term($conn, $college_id, $ay, $semester)
    ]);
    exit;
}

if (isset($_POST['load_iso_class_program'])) {
    $ay = trim((string)($_POST['ay'] ?? ''));
    $semester = mapSemester($_POST['semester'] ?? '');
    $programId = (int)($_POST['program_id'] ?? 0);

    if ($ay === '' || $semester <= 0 || $programId <= 0 || $college_id <= 0) {
        echo json_encode([
            'status' => 'ok',
            'meta' => [],
            'sections' => [],
            'rows' => []
        ]);
        exit;
    }

    $metaSql = "
        SELECT
            p.program_id,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS major,
            c.college_name,
            COALESCE(camp.campus_name, '') AS campus_name
        FROM tbl_program p
        INNER JOIN tbl_college c
            ON c.college_id = p.college_id
        LEFT JOIN tbl_campus camp
            ON camp.campus_id = c.campus_id
        WHERE p.program_id = ?
          AND p.college_id = ?
        LIMIT 1
    ";

    $metaStmt = $conn->prepare($metaSql);
    if (!$metaStmt) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Unable to load the selected program.',
            'meta' => [],
            'sections' => [],
            'rows' => []
        ]);
        exit;
    }

    $metaStmt->bind_param("ii", $programId, $college_id);
    $metaStmt->execute();
    $meta = $metaStmt->get_result()->fetch_assoc();
    $metaStmt->close();

    if (!$meta) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Program not found.',
            'meta' => [],
            'sections' => [],
            'rows' => []
        ]);
        exit;
    }

    $sectionsSql = "
        SELECT
            s.section_id,
            s.full_section,
            s.year_level,
            s.section_name
        FROM tbl_sections s
        INNER JOIN tbl_academic_years ay
            ON ay.ay_id = s.ay_id
        WHERE s.program_id = ?
          AND ay.ay = ?
          AND s.semester = ?
        ORDER BY
            s.year_level ASC,
            s.section_name ASC,
            s.full_section ASC
    ";

    $sectionsStmt = $conn->prepare($sectionsSql);
    if (!$sectionsStmt) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Unable to load sections for the selected program.',
            'meta' => [],
            'sections' => [],
            'rows' => []
        ]);
        exit;
    }

    $sectionsStmt->bind_param("isi", $programId, $ay, $semester);
    $sectionsStmt->execute();
    $sectionsRes = $sectionsStmt->get_result();

    $sections = [];
    while ($row = $sectionsRes->fetch_assoc()) {
        $sections[] = [
            'section_id' => (int)($row['section_id'] ?? 0),
            'full_section' => (string)($row['full_section'] ?? ''),
            'year_level' => (int)($row['year_level'] ?? 0),
            'section_name' => (string)($row['section_name'] ?? '')
        ];
    }

    $sectionsStmt->close();

    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('po', 'sec', 'sc', 'ps', 'pys', 'ph');
    $scheduleSql = "
        SELECT
            cs.schedule_id,
            po.section_id,
            sec.full_section,
            cs.schedule_type,
            cs.time_start,
            cs.time_end,
            cs.days_json,
            sm.sub_code AS subject_code,
            COALESCE(
                NULLIF(TRIM(
                    GROUP_CONCAT(
                        DISTINCT CONCAT(f.last_name, ', ', f.first_name)
                        ORDER BY f.last_name ASC, f.first_name ASC
                        SEPARATOR ' / '
                    )
                ), ''),
                'TBA'
            ) AS faculty_name,
            COALESCE(
                NULLIF(TRIM(r.room_code), ''),
                NULLIF(TRIM(r.room_name), ''),
                'TBA'
            ) AS room_label
        FROM tbl_class_schedule cs
        INNER JOIN tbl_prospectus_offering po
            ON po.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = po.program_id
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        LEFT JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        LEFT JOIN tbl_faculty_workload_sched fws
            ON fws.schedule_id = cs.schedule_id
        LEFT JOIN tbl_faculty f
            ON f.faculty_id = fws.faculty_id
        INNER JOIN tbl_academic_years ay
            ON ay.ay_id = po.ay_id
        WHERE po.program_id = ?
          AND ay.ay = ?
          AND po.semester = ?
          AND p.college_id = ?
        GROUP BY
            cs.schedule_id,
            po.section_id,
            sec.full_section,
            cs.schedule_type,
            cs.time_start,
            cs.time_end,
            cs.days_json,
            sm.sub_code,
            r.room_code,
            r.room_name
        ORDER BY
            sec.full_section ASC,
            cs.time_start ASC,
            sm.sub_code ASC,
            cs.schedule_id ASC
    ";

    $scheduleStmt = $conn->prepare($scheduleSql);
    if (!$scheduleStmt) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Unable to load the ISO class program.',
            'meta' => [],
            'sections' => [],
            'rows' => []
        ]);
        exit;
    }

    $scheduleStmt->bind_param("isii", $programId, $ay, $semester, $college_id);
    $scheduleStmt->execute();
    $scheduleRes = $scheduleStmt->get_result();

    $rows = [];
    while ($row = $scheduleRes->fetch_assoc()) {
        $daysRaw = json_decode((string)($row['days_json'] ?? '[]'), true);
        if (!is_array($daysRaw)) {
            $daysRaw = [];
        }

        $rows[] = [
            'schedule_id' => (int)($row['schedule_id'] ?? 0),
            'section_id' => (int)($row['section_id'] ?? 0),
            'full_section' => (string)($row['full_section'] ?? ''),
            'schedule_type' => strtoupper(trim((string)($row['schedule_type'] ?? 'LEC'))),
            'time_start' => (string)($row['time_start'] ?? ''),
            'time_end' => (string)($row['time_end'] ?? ''),
            'subject_code' => (string)($row['subject_code'] ?? ''),
            'faculty_name' => (string)($row['faculty_name'] ?? 'TBA'),
            'room_label' => (string)($row['room_label'] ?? 'TBA'),
            'days_raw' => $daysRaw,
            'days_key' => normalize_iso_day_key($daysRaw)
        ];
    }

    $scheduleStmt->close();

    echo json_encode([
        'status' => 'ok',
        'meta' => [
            'program_id' => (int)($meta['program_id'] ?? 0),
            'program_code' => (string)($meta['program_code'] ?? ''),
            'program_name' => (string)($meta['program_name'] ?? ''),
            'major' => (string)($meta['major'] ?? ''),
            'college_name' => (string)($meta['college_name'] ?? ''),
            'campus_name' => (string)($meta['campus_name'] ?? '')
        ],
        'sections' => $sections,
        'rows' => $rows
    ]);
    exit;
}

echo json_encode([
    'status' => 'error',
    'message' => 'Invalid request.'
]);
exit;
