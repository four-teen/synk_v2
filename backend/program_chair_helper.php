<?php

require_once __DIR__ . '/auth_useraccount.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schedule_block_helper.php';

function synk_program_chair_require_login(mysqli $conn): void
{
    if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'program_chair') {
        $redirectPath = synk_role_redirect_path((string)($_SESSION['role'] ?? ''));
        header('Location: ../' . ($redirectPath ?? 'index.php'));
        exit;
    }
}

function synk_program_chair_role_label(string $role): string
{
    $label = trim((string)synk_role_label(strtolower(trim($role))));
    return $label !== '' ? $label : strtoupper(trim($role));
}

function synk_program_chair_program_display_name(array $row): string
{
    $code = strtoupper(trim((string)($row['program_code'] ?? '')));
    $name = trim((string)($row['program_name'] ?? ''));
    $major = trim((string)($row['major'] ?? ''));

    $label = trim(implode(' - ', array_filter([$code, $name])));
    if ($label === '') {
        $label = $major !== '' ? $major : 'Unnamed program';
    }

    if ($major !== '' && stripos($label, $major) === false) {
        $label .= ' (Major in ' . $major . ')';
    }

    return $label;
}

function synk_program_chair_fetch_college_context(mysqli $conn, int $collegeId): ?array
{
    if ($collegeId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            c.college_id,
            COALESCE(c.college_code, '') AS college_code,
            COALESCE(c.college_name, '') AS college_name,
            COALESCE(c.status, '') AS college_status,
            COALESCE(cp.campus_id, 0) AS campus_id,
            COALESCE(cp.campus_code, '') AS campus_code,
            COALESCE(cp.campus_name, '') AS campus_name
        FROM tbl_college c
        LEFT JOIN tbl_campus cp
            ON cp.campus_id = c.campus_id
        WHERE c.college_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $collegeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->close();
    }
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $row['college_id'] = (int)($row['college_id'] ?? 0);
    $row['campus_id'] = (int)($row['campus_id'] ?? 0);

    return $row;
}

function synk_program_chair_fetch_program_rows(mysqli $conn, int $collegeId): array
{
    if ($collegeId <= 0 || !synk_table_exists($conn, 'tbl_program')) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT
            p.program_id,
            p.college_id,
            COALESCE(p.program_code, '') AS program_code,
            COALESCE(p.program_name, '') AS program_name,
            COALESCE(p.major, '') AS major,
            COALESCE(p.status, 'inactive') AS status
        FROM tbl_program p
        WHERE p.college_id = ?
        ORDER BY
            CASE WHEN COALESCE(p.status, 'inactive') = 'active' THEN 0 ELSE 1 END ASC,
            p.program_code ASC,
            p.program_name ASC,
            p.major ASC,
            p.program_id ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $collegeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $row['program_id'] = (int)($row['program_id'] ?? 0);
            $row['college_id'] = (int)($row['college_id'] ?? 0);
            $row['display_name'] = synk_program_chair_program_display_name($row);
            $rows[] = $row;
        }

        $result->close();
    }

    $stmt->close();

    return $rows;
}

function synk_program_chair_fetch_section_rows(mysqli $conn, int $collegeId, int $ayId, int $semester): array
{
    if (
        $collegeId <= 0
        || $ayId <= 0
        || $semester <= 0
        || !synk_table_exists($conn, 'tbl_sections')
        || !synk_table_exists($conn, 'tbl_program')
    ) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT
            s.section_id,
            s.program_id,
            s.year_level,
            COALESCE(s.section_name, '') AS section_name,
            COALESCE(s.full_section, '') AS full_section,
            COALESCE(s.status, '') AS section_status,
            COALESCE(p.program_code, '') AS program_code,
            COALESCE(p.program_name, '') AS program_name,
            COALESCE(p.major, '') AS major
        FROM tbl_sections s
        INNER JOIN tbl_program p
            ON p.program_id = s.program_id
        WHERE p.college_id = ?
          AND s.ay_id = ?
          AND s.semester = ?
        ORDER BY
            p.program_code ASC,
            p.program_name ASC,
            p.major ASC,
            s.year_level ASC,
            s.section_name ASC,
            s.section_id ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('iii', $collegeId, $ayId, $semester);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $row['section_id'] = (int)($row['section_id'] ?? 0);
            $row['program_id'] = (int)($row['program_id'] ?? 0);
            $row['year_level'] = (int)($row['year_level'] ?? 0);

            $fullSection = trim((string)($row['full_section'] ?? ''));
            $sectionName = trim((string)($row['section_name'] ?? ''));
            $programCode = strtoupper(trim((string)($row['program_code'] ?? '')));
            $displaySection = $fullSection !== '' ? $fullSection : trim($programCode . ' ' . $sectionName);

            $row['section_display'] = $displaySection !== '' ? $displaySection : ('Section #' . (int)$row['section_id']);
            $rows[] = $row;
        }

        $result->close();
    }

    $stmt->close();

    return $rows;
}

function synk_program_chair_semester_basis_label(int $semester): string
{
    return synk_semester_label($semester);
}

function synk_program_chair_schedule_days_text($daysJson): string
{
    $decoded = json_decode((string)$daysJson, true);
    $days = synk_normalize_schedule_days(is_array($decoded) ? $decoded : []);
    return !empty($days) ? implode('/', $days) : 'Days TBA';
}

function synk_program_chair_schedule_time_text(?string $timeStart, ?string $timeEnd): string
{
    $timeStart = trim((string)$timeStart);
    $timeEnd = trim((string)$timeEnd);

    if ($timeStart === '' || $timeEnd === '') {
        return 'Time TBA';
    }

    $start = strtotime($timeStart);
    $end = strtotime($timeEnd);
    if ($start === false || $end === false) {
        return 'Time TBA';
    }

    return date('h:i A', $start) . ' - ' . date('h:i A', $end);
}

function synk_program_chair_faculty_short_name(array $row): string
{
    $lastName = trim((string)($row['last_name'] ?? ''));
    $firstName = trim((string)($row['first_name'] ?? ''));
    if ($lastName === '' && $firstName === '') {
        return '';
    }

    if ($lastName !== '' && $firstName !== '') {
        $initial = function_exists('mb_substr')
            ? mb_strtoupper((string)mb_substr($firstName, 0, 1))
            : strtoupper(substr($firstName, 0, 1));
        return $lastName . ', ' . $initial;
    }

    return $lastName !== '' ? $lastName : $firstName;
}

function synk_program_chair_fetch_section_scheduled_offerings(mysqli $conn, int $collegeId, int $sectionId, int $ayId, int $semester): array
{
    if (
        $collegeId <= 0
        || $sectionId <= 0
        || $ayId <= 0
        || $semester <= 0
        || !synk_table_exists($conn, 'tbl_prospectus_offering')
        || !synk_table_exists($conn, 'tbl_class_schedule')
    ) {
        return [];
    }

    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('po', 'sec', 'sc', 'ps', 'pys', 'ph');
    $stmt = $conn->prepare("
        SELECT
            po.offering_id,
            po.section_id,
            COALESCE(sec.section_name, '') AS section_name,
            COALESCE(sec.full_section, '') AS full_section,
            COALESCE(sec.year_level, 0) AS year_level,
            COALESCE(ps.sub_id, 0) AS subject_id,
            COALESCE(sm.sub_code, '') AS subject_code,
            COALESCE(sm.sub_description, '') AS subject_description,
            COALESCE(ps.lec_units, 0) AS lec_units,
            COALESCE(ps.lab_units, 0) AS lab_units,
            COALESCE(ps.total_units, 0) AS total_units,
            cs.schedule_id,
            COALESCE(cs.schedule_type, '') AS schedule_type,
            COALESCE(cs.days_json, '') AS days_json,
            COALESCE(cs.time_start, '') AS time_start,
            COALESCE(cs.time_end, '') AS time_end,
            COALESCE(NULLIF(TRIM(r.room_code), ''), NULLIF(TRIM(r.room_name), ''), '') AS room_label,
            COALESCE(f.faculty_id, 0) AS faculty_id,
            COALESCE(f.last_name, '') AS last_name,
            COALESCE(f.first_name, '') AS first_name
        FROM tbl_prospectus_offering po
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = po.program_id
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        LEFT JOIN tbl_class_schedule cs
            ON cs.offering_id = po.offering_id
        LEFT JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        LEFT JOIN tbl_faculty_workload_sched fws
            ON fws.schedule_id = cs.schedule_id
           AND fws.ay_id = ?
           AND fws.semester = ?
        LEFT JOIN tbl_faculty f
            ON f.faculty_id = fws.faculty_id
        WHERE po.section_id = ?
          AND po.ay_id = ?
          AND po.semester = ?
          AND p.college_id = ?
        ORDER BY
            sec.year_level ASC,
            sm.sub_code ASC,
            FIELD(cs.schedule_type, 'LEC', 'LAB'),
            cs.time_start ASC,
            cs.schedule_id ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('iiiiii', $ayId, $semester, $sectionId, $ayId, $semester, $collegeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $offerings = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $offeringId = (int)($row['offering_id'] ?? 0);
            if ($offeringId <= 0) {
                continue;
            }

            if (!isset($offerings[$offeringId])) {
                $offerings[$offeringId] = [
                    'offering_id' => $offeringId,
                    'section_id' => (int)($row['section_id'] ?? 0),
                    'section_name' => (string)($row['section_name'] ?? ''),
                    'full_section' => (string)($row['full_section'] ?? ''),
                    'year_level' => (int)($row['year_level'] ?? 0),
                    'subject_id' => (int)($row['subject_id'] ?? 0),
                    'subject_code' => (string)($row['subject_code'] ?? ''),
                    'subject_description' => (string)($row['subject_description'] ?? ''),
                    'lec_units' => round((float)($row['lec_units'] ?? 0), 2),
                    'lab_units' => round((float)($row['lab_units'] ?? 0), 2),
                    'total_units' => round((float)($row['total_units'] ?? 0), 2),
                    'faculty_names' => [],
                    'room_labels' => [],
                    'schedule_blocks' => [],
                    'is_scheduled' => false,
                ];
            }

            $facultyId = (int)($row['faculty_id'] ?? 0);
            $facultyName = synk_program_chair_faculty_short_name($row);
            if ($facultyId > 0 && $facultyName !== '' && !in_array($facultyName, $offerings[$offeringId]['faculty_names'], true)) {
                $offerings[$offeringId]['faculty_names'][] = $facultyName;
            }

            $roomLabel = trim((string)($row['room_label'] ?? ''));
            if ($roomLabel !== '' && !in_array($roomLabel, $offerings[$offeringId]['room_labels'], true)) {
                $offerings[$offeringId]['room_labels'][] = $roomLabel;
            }

            $scheduleId = (int)($row['schedule_id'] ?? 0);
            if ($scheduleId > 0) {
                $offerings[$offeringId]['is_scheduled'] = true;
                $daysText = synk_program_chair_schedule_days_text((string)($row['days_json'] ?? ''));
                $timeText = synk_program_chair_schedule_time_text((string)($row['time_start'] ?? ''), (string)($row['time_end'] ?? ''));
                $type = synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC'));
                $blockLabel = trim($type . ': ' . $daysText . ' - ' . $timeText);

                $offerings[$offeringId]['schedule_blocks'][] = [
                    'schedule_id' => $scheduleId,
                    'schedule_type' => $type,
                    'days_text' => $daysText,
                    'time_text' => $timeText,
                    'display_text' => $blockLabel,
                    'room_label' => $roomLabel !== '' ? $roomLabel : 'TBA',
                ];
            }
        }

        $result->close();
    }

    $stmt->close();

    foreach ($offerings as &$offering) {
        $offering['schedule_text'] = !empty($offering['schedule_blocks'])
            ? implode(' | ', array_map(static function (array $block): string {
                return (string)($block['display_text'] ?? '');
            }, $offering['schedule_blocks']))
            : 'Schedule pending';
        $offering['room_text'] = !empty($offering['room_labels']) ? implode(', ', $offering['room_labels']) : 'TBA';
        $offering['faculty_text'] = !empty($offering['faculty_names']) ? implode(', ', $offering['faculty_names']) : 'TBA';
    }
    unset($offering);

    return array_values($offerings);
}

function synk_program_chair_active_program_rows(array $programRows): array
{
    return array_values(array_filter($programRows, static function (array $row): bool {
        return strtolower(trim((string)($row['status'] ?? 'inactive'))) === 'active';
    }));
}

function synk_program_chair_prospectus_version_label(array $row): string
{
    $effectiveSy = trim((string)($row['effective_sy'] ?? ''));
    $cmoNo = trim((string)($row['cmo_no'] ?? ''));

    if ($effectiveSy !== '' && $cmoNo !== '') {
        return 'SY ' . $effectiveSy . ' - ' . $cmoNo;
    }

    return $effectiveSy !== '' ? ('SY ' . $effectiveSy) : ($cmoNo !== '' ? $cmoNo : 'Prospectus Version');
}

function synk_program_chair_fetch_prospectus_versions_by_program(mysqli $conn, int $collegeId): array
{
    $versionsByProgram = [];

    if (
        $collegeId <= 0
        || !synk_table_exists($conn, 'tbl_prospectus_header')
        || !synk_table_exists($conn, 'tbl_prospectus_year_sem')
        || !synk_table_exists($conn, 'tbl_prospectus_subjects')
        || !synk_table_exists($conn, 'tbl_program')
    ) {
        return $versionsByProgram;
    }

    $stmt = $conn->prepare("
        SELECT
            h.prospectus_id,
            h.program_id,
            COALESCE(h.cmo_no, '') AS cmo_no,
            COALESCE(h.effective_sy, '') AS effective_sy,
            COUNT(DISTINCT ys.pys_id) AS term_count,
            COUNT(ps.ps_id) AS subject_count
        FROM tbl_prospectus_header h
        INNER JOIN tbl_program p
            ON p.program_id = h.program_id
        LEFT JOIN tbl_prospectus_year_sem ys
            ON ys.prospectus_id = h.prospectus_id
        LEFT JOIN tbl_prospectus_subjects ps
            ON ps.pys_id = ys.pys_id
        WHERE p.college_id = ?
        GROUP BY
            h.prospectus_id,
            h.program_id,
            h.cmo_no,
            h.effective_sy
        ORDER BY
            h.effective_sy DESC,
            h.prospectus_id DESC
    ");

    if (!$stmt) {
        return $versionsByProgram;
    }

    $stmt->bind_param('i', $collegeId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $programId = (int)($row['program_id'] ?? 0);
            if ($programId <= 0) {
                continue;
            }

            if (!isset($versionsByProgram[$programId])) {
                $versionsByProgram[$programId] = [];
            }

            $row['prospectus_id'] = (int)($row['prospectus_id'] ?? 0);
            $row['program_id'] = $programId;
            $row['term_count'] = (int)($row['term_count'] ?? 0);
            $row['subject_count'] = (int)($row['subject_count'] ?? 0);
            $row['label'] = synk_program_chair_prospectus_version_label($row);

            $versionsByProgram[$programId][] = $row;
        }

        $result->close();
    }

    $stmt->close();

    return $versionsByProgram;
}

function synk_program_chair_build_role_badges(): array
{
    $availableRoles = array_values(array_filter(array_map('strval', (array)($_SESSION['available_roles'] ?? []))));
    $badges = [];

    foreach ($availableRoles as $role) {
        $label = synk_program_chair_role_label($role);
        if ($label === '') {
            continue;
        }

        $badges[] = [
            'role' => strtolower(trim($role)),
            'label' => $label,
            'is_active' => strtolower(trim($role)) === 'program_chair',
        ];
    }

    return $badges;
}

function synk_program_chair_portal_context(mysqli $conn): array
{
    $collegeId = (int)($_SESSION['college_id'] ?? 0);
    $collegeRow = synk_program_chair_fetch_college_context($conn, $collegeId);
    $programRows = synk_program_chair_fetch_program_rows($conn, $collegeId);
    $activePrograms = 0;
    $inactivePrograms = 0;
    $majorPrograms = 0;

    foreach ($programRows as $programRow) {
        if (strtolower(trim((string)($programRow['status'] ?? ''))) === 'active') {
            $activePrograms++;
        } else {
            $inactivePrograms++;
        }

        if (trim((string)($programRow['major'] ?? '')) !== '') {
            $majorPrograms++;
        }
    }

    $currentTerm = synk_fetch_current_academic_term($conn);
    $accountName = trim((string)($_SESSION['username'] ?? 'Program Chair'));
    $email = trim((string)($_SESSION['email'] ?? ''));

    return [
        'account_name' => $accountName !== '' ? $accountName : 'Program Chair',
        'email' => $email,
        'role_badges' => synk_program_chair_build_role_badges(),
        'college_id' => $collegeId,
        'college' => $collegeRow,
        'program_rows' => $programRows,
        'program_count' => count($programRows),
        'active_program_count' => $activePrograms,
        'inactive_program_count' => $inactivePrograms,
        'major_program_count' => $majorPrograms,
        'current_term' => $currentTerm,
        'has_college_scope' => $collegeRow !== null,
        'has_programs' => !empty($programRows),
    ];
}
