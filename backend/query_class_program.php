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

function normalize_schedule_type_label($value) {
    $type = strtoupper(trim((string)$value));
    return $type === 'LAB' ? 'LAB' : 'LEC';
}

function load_sections_for_term(mysqli $conn, int $collegeId, string $ayLabel, int $semester): array
{
    $sql = "
        SELECT
            s.section_id,
            s.full_section,
            s.year_level,
            s.section_name,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS program_major
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
            p.major ASC,
            s.year_level ASC,
            s.section_name ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("isi", $collegeId, $ayLabel, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $sections = [];
    while ($row = $res->fetch_assoc()) {
        $fullSection = trim((string)($row['full_section'] ?? ''));
        $programCode = trim((string)($row['program_code'] ?? ''));
        $sectionName = trim((string)($row['section_name'] ?? ''));

        $sections[] = [
            'section_id' => (int)($row['section_id'] ?? 0),
            'label' => $fullSection !== '' ? $fullSection : trim($programCode . ' ' . $sectionName),
            'full_section' => $fullSection,
            'program_code' => $programCode,
            'program_name' => (string)($row['program_name'] ?? ''),
            'program_major' => (string)($row['program_major'] ?? '')
        ];
    }

    $stmt->close();
    return $sections;
}

if (isset($_POST['load_section_options'])) {
    $ay = trim((string)($_POST['ay'] ?? ''));
    $semester = mapSemester($_POST['semester'] ?? '');

    if ($ay === '' || $semester <= 0 || $college_id <= 0) {
        echo json_encode([
            'status' => 'ok',
            'sections' => []
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'ok',
        'sections' => load_sections_for_term($conn, $college_id, $ay, $semester)
    ]);
    exit;
}

if (isset($_POST['load_section_schedule'])) {
    $ay = trim((string)($_POST['ay'] ?? ''));
    $semester = mapSemester($_POST['semester'] ?? '');
    $sectionId = (int)($_POST['section_id'] ?? 0);

    if ($ay === '' || $semester <= 0 || $sectionId <= 0 || $college_id <= 0) {
        echo json_encode([
            'status' => 'ok',
            'meta' => [],
            'rows' => []
        ]);
        exit;
    }

    $contextSql = "
        SELECT
            s.section_id,
            s.section_name,
            s.full_section,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS program_major,
            c.college_name
        FROM tbl_sections s
        INNER JOIN tbl_program p
            ON p.program_id = s.program_id
        INNER JOIN tbl_college c
            ON c.college_id = p.college_id
        INNER JOIN tbl_academic_years ay
            ON ay.ay_id = s.ay_id
        WHERE s.section_id = ?
          AND p.college_id = ?
          AND ay.ay = ?
          AND s.semester = ?
        LIMIT 1
    ";

    $contextStmt = $conn->prepare($contextSql);
    if (!$contextStmt) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Unable to load the selected section context.',
            'meta' => [],
            'rows' => []
        ]);
        exit;
    }

    $contextStmt->bind_param("iisi", $sectionId, $college_id, $ay, $semester);
    $contextStmt->execute();
    $context = $contextStmt->get_result()->fetch_assoc();
    $contextStmt->close();

    if (!$context) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Section not found for the selected term.',
            'meta' => [],
            'rows' => []
        ]);
        exit;
    }

    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('po', 'sec', 'sc', 'ps', 'pys', 'ph');
    $scheduleSql = "
        SELECT
            cs.schedule_id,
            cs.schedule_type,
            cs.time_start,
            cs.time_end,
            cs.days_json,
            sm.sub_code AS subject_code,
            sm.sub_description AS subject_description,
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
        WHERE po.section_id = ?
          AND ay.ay = ?
          AND po.semester = ?
          AND p.college_id = ?
        GROUP BY
            cs.schedule_id,
            cs.schedule_type,
            cs.time_start,
            cs.time_end,
            cs.days_json,
            sm.sub_code,
            sm.sub_description,
            r.room_code,
            r.room_name
        ORDER BY
            cs.time_start ASC,
            sm.sub_code ASC,
            FIELD(cs.schedule_type, 'LEC', 'LAB'),
            cs.schedule_id ASC
    ";

    $scheduleStmt = $conn->prepare($scheduleSql);
    if (!$scheduleStmt) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Unable to load the selected class program.',
            'meta' => [],
            'rows' => []
        ]);
        exit;
    }

    $scheduleStmt->bind_param("isii", $sectionId, $ay, $semester, $college_id);
    $scheduleStmt->execute();
    $scheduleRes = $scheduleStmt->get_result();

    $rows = [];
    $rooms = [];

    while ($row = $scheduleRes->fetch_assoc()) {
        $daysRaw = json_decode((string)($row['days_json'] ?? '[]'), true);
        if (!is_array($daysRaw)) {
            $daysRaw = [];
        }

        $roomLabel = trim((string)($row['room_label'] ?? ''));
        if ($roomLabel !== '' && strtoupper($roomLabel) !== 'TBA') {
            $rooms[$roomLabel] = true;
        }

        $rows[] = [
            'schedule_id' => (int)($row['schedule_id'] ?? 0),
            'schedule_type' => normalize_schedule_type_label($row['schedule_type'] ?? 'LEC'),
            'time_start' => (string)($row['time_start'] ?? ''),
            'time_end' => (string)($row['time_end'] ?? ''),
            'subject_code' => (string)($row['subject_code'] ?? ''),
            'subject_description' => (string)($row['subject_description'] ?? ''),
            'faculty_name' => (string)($row['faculty_name'] ?? 'TBA'),
            'room_label' => $roomLabel !== '' ? $roomLabel : 'TBA',
            'days_raw' => $daysRaw
        ];
    }

    $scheduleStmt->close();

    echo json_encode([
        'status' => 'ok',
        'meta' => [
            'section_id' => (int)($context['section_id'] ?? 0),
            'section_name' => (string)($context['section_name'] ?? ''),
            'full_section' => (string)($context['full_section'] ?? ''),
            'program_code' => (string)($context['program_code'] ?? ''),
            'program_name' => (string)($context['program_name'] ?? ''),
            'program_major' => (string)($context['program_major'] ?? ''),
            'college_name' => (string)($context['college_name'] ?? ''),
            'rooms_text' => !empty($rooms) ? implode(', ', array_keys($rooms)) : 'TBA'
        ],
        'rows' => $rows
    ]);
    exit;
}

echo json_encode([
    'status' => 'error',
    'message' => 'Invalid request.'
]);
exit;
