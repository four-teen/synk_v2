<?php
require_once __DIR__ . '/faculty_term_designation_helper.php';
require_once __DIR__ . '/schedule_tba_helper.php';
session_start();
require_once 'db.php';
require_once __DIR__.'/scheduler_request_guard.php';
synk_scheduler_guard_request($conn, 'query_room_utilization.php');
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/offering_enrollee_helper.php';
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/schedule_block_helper.php';
require_once __DIR__ . '/schedule_merge_helper.php';
require_once __DIR__ . '/program_chair_signatory_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'scheduler') {
    echo json_encode([]);
    exit;
}

$college_id = (int)$_SESSION['college_id'];

/* ==========================================================
   MAP SEMESTER LABEL -> DB VALUE
========================================================== */
function mapSemester($s) {
    if ($s === '1st') return 1;
    if ($s === '2nd') return 2;
    if ($s === 'Midyear') return 3;
    return 0;
}

function room_access_table_exists($conn) {
    static $hasAccessTable = null;

    if ($hasAccessTable !== null) {
        return $hasAccessTable;
    }

    $result = $conn->query("SHOW TABLES LIKE 'tbl_room_college_access'");
    $hasAccessTable = $result && $result->num_rows > 0;
    return $hasAccessTable;
}

function build_room_label($row) {
    $code = trim((string)($row['room_code'] ?? ''));
    $name = trim((string)($row['room_name'] ?? ''));
    $label = $name !== '' ? $name : $code;

    if ($name !== '' && $code !== '' && strcasecmp($name, $code) !== 0) {
        $label = $code . ' - ' . $name;
    }

    $accessType = strtolower(trim((string)($row['access_type'] ?? 'owner')));
    $ownerCode = trim((string)($row['owner_code'] ?? ''));
    if ($accessType === 'shared') {
        $label .= $ownerCode !== ''
            ? " (Shared from {$ownerCode})"
            : " (Shared)";
    }

    return $label;
}

function load_accessible_rooms_for_term($conn, $college_id, $ayLabel, $semester) {
    $rooms = [];

    if (room_access_table_exists($conn)) {
        $sql = "
            SELECT DISTINCT
                r.room_id,
                r.room_code,
                r.room_name,
                acc.access_type,
                owner.college_code AS owner_code
            FROM tbl_room_college_access acc
            INNER JOIN tbl_rooms r
                ON r.room_id = acc.room_id
            INNER JOIN tbl_college owner
                ON owner.college_id = r.college_id
            INNER JOIN tbl_academic_years ay
                ON ay.ay_id = acc.ay_id
            WHERE acc.college_id = ?
              AND ay.ay = ?
              AND acc.semester = ?
              AND r.status = 'active'
            ORDER BY r.room_name ASC, r.room_code ASC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isi", $college_id, $ayLabel, $semester);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            $roomId = (int)$row['room_id'];
            $rooms[$roomId] = [
                "room_id" => $roomId,
                "room_code" => (string)$row['room_code'],
                "room_name" => (string)($row['room_name'] ?? ''),
                "room_label" => build_room_label($row),
                "groups" => []
            ];
        }

        $stmt->close();
        return $rooms;
    }

    $sql = "
        SELECT room_id, room_code, room_name
        FROM tbl_rooms
        WHERE college_id = ?
          AND status = 'active'
        ORDER BY room_name ASC, room_code ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $college_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $roomId = (int)$row['room_id'];
        $rooms[$roomId] = [
            "room_id" => $roomId,
            "room_code" => (string)$row['room_code'],
            "room_name" => (string)($row['room_name'] ?? ''),
            "room_label" => build_room_label($row),
            "groups" => []
        ];
    }

    $stmt->close();
    return $rooms;
}

function normalize_day_token($day) {
    $token = strtoupper(trim((string)$day));
    return $token === 'TH' ? 'Th' : $token;
}

function normalize_day_key($days) {
    $order = [
        'M' => 1,
        'T' => 2,
        'W' => 3,
        'Th' => 4,
        'F' => 5,
        'S' => 6
    ];

    if (!is_array($days)) {
        return '';
    }

    $normalized = [];
    foreach ($days as $day) {
        $token = normalize_day_token($day);
        if ($token !== '') {
            $normalized[$token] = true;
        }
    }

    $tokens = array_keys($normalized);
    usort($tokens, function ($a, $b) use ($order) {
        return ($order[$a] ?? 99) <=> ($order[$b] ?? 99);
    });

    return implode('', $tokens);
}

function format_workload_days($daysJson) {
    $days = json_decode((string)$daysJson, true);
    if (!is_array($days) || empty($days)) {
        return '';
    }

    return implode(', ', array_map(function ($day) {
        return normalize_day_token($day);
    }, $days));
}

function workload_title_case($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $value = strtolower($value);
    $value = preg_replace_callback('/(^|[\s,\/-])([a-z])/', static function ($matches) {
        return $matches[1] . strtoupper($matches[2]);
    }, $value);

    return (string)$value;
}

function room_workload_context_key_from_values(int $groupId, int $scheduleId, int $offeringId): string
{
    if ($groupId > 0) {
        return 'group:' . $groupId;
    }

    if ($scheduleId > 0) {
        return 'schedule:' . $scheduleId;
    }

    return 'offering:' . $offeringId;
}

function room_utilization_schedule_type($type): string
{
    return strtoupper(trim((string)$type)) === 'LAB' ? 'LAB' : 'LEC';
}

function room_utilization_default_section_label(int $offeringId, array $sectionRowsByOffering, string $fallback = ''): string
{
    $sectionRow = (array)($sectionRowsByOffering[$offeringId] ?? []);
    $label = trim((string)($sectionRow['full_section'] ?? ''));

    if ($label === '') {
        $label = trim((string)synk_schedule_merge_compose_base_section_label($sectionRow));
    }

    if ($label === '') {
        $label = trim($fallback);
    }

    return $label;
}

function room_utilization_program_label_for_scope(array $scopeDisplay, int $offeringId, array $sectionRowsByOffering): string
{
    $programCodes = [];
    $offeringIds = synk_schedule_merge_normalize_offering_ids(
        (array)($scopeDisplay['group_offering_ids'] ?? [$offeringId])
    );

    if (empty($offeringIds)) {
        $offeringIds = [$offeringId];
    }

    foreach ($offeringIds as $candidateOfferingId) {
        $programCode = strtoupper(trim((string)($sectionRowsByOffering[$candidateOfferingId]['program_code'] ?? '')));
        if ($programCode !== '') {
            $programCodes[$programCode] = $programCode;
        }
    }

    $labels = array_values($programCodes);
    natcasesort($labels);

    return implode('/', $labels);
}

function room_utilization_load_program_rows_by_offering(mysqli $conn, array $offeringIds): array
{
    $normalizedIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (empty($normalizedIds)) {
        return [];
    }

    $idList = implode(',', array_map('intval', $normalizedIds));
    $sql = "
        SELECT
            po.offering_id,
            p.program_id,
            COALESCE(p.program_code, '') AS program_code,
            COALESCE(p.program_name, '') AS program_name,
            COALESCE(p.major, '') AS major
        FROM tbl_prospectus_offering po
        INNER JOIN tbl_program p
            ON p.program_id = po.program_id
        WHERE po.offering_id IN ({$idList})
    ";

    $rows = [];
    $result = $conn->query($sql);
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $offeringId = (int)($row['offering_id'] ?? 0);
            if ($offeringId <= 0) {
                continue;
            }

            $rows[$offeringId] = [
                'program_id' => (int)($row['program_id'] ?? 0),
                'program_code' => trim((string)($row['program_code'] ?? '')),
                'program_name' => trim((string)($row['program_name'] ?? '')),
                'major' => trim((string)($row['major'] ?? '')),
            ];
        }
    }

    return $rows;
}

function room_utilization_programs_for_scope(array $scopeDisplay, int $offeringId, array $programRowsByOffering): array
{
    $offeringIds = synk_schedule_merge_normalize_offering_ids(
        (array)($scopeDisplay['group_offering_ids'] ?? [$offeringId])
    );

    if (empty($offeringIds)) {
        $offeringIds = [$offeringId];
    }

    $programs = [];
    foreach ($offeringIds as $candidateOfferingId) {
        $program = (array)($programRowsByOffering[$candidateOfferingId] ?? []);
        $programId = (int)($program['program_id'] ?? 0);
        $programCode = strtoupper(trim((string)($program['program_code'] ?? '')));

        if ($programId <= 0 && $programCode === '') {
            continue;
        }

        $programKey = $programId > 0 ? 'id:' . $programId : 'code:' . $programCode;
        if (isset($programs[$programKey])) {
            continue;
        }

        $programs[$programKey] = [
            'program_id' => $programId,
            'program_code' => $programCode,
            'program_name' => trim((string)($program['program_name'] ?? '')),
            'major' => trim((string)($program['major'] ?? '')),
        ];
    }

    return array_values($programs);
}

function room_utilization_program_label_from_programs(array $programs): string
{
    $programCodes = [];

    foreach ($programs as $program) {
        $programCode = strtoupper(trim((string)($program['program_code'] ?? '')));
        if ($programCode !== '') {
            $programCodes[$programCode] = $programCode;
        }
    }

    $labels = array_values($programCodes);
    natcasesort($labels);

    return implode('/', $labels);
}

function room_utilization_compact_text($value): string
{
    return preg_replace('/[^A-Z0-9]+/', '', strtoupper(trim((string)$value))) ?? '';
}

function room_utilization_faculty_print_name(array $row): string
{
    $lastName = trim((string)($row['last_name'] ?? ''));
    $firstName = trim((string)($row['first_name'] ?? ''));
    $middleName = trim((string)($row['middle_name'] ?? ''));
    $extName = trim((string)($row['ext_name'] ?? ''));

    $name = trim($lastName . ', ' . $firstName, ' ,');
    if ($middleName !== '') {
        $name .= ' ' . strtoupper(substr($middleName, 0, 1)) . '.';
    }

    if ($extName !== '') {
        $name .= ', ' . $extName;
    }

    return trim($name, ' ,');
}

function room_utilization_fetch_ay_id(mysqli $conn, string $ayLabel): int
{
    $ayLabel = trim($ayLabel);
    if ($ayLabel === '') {
        return 0;
    }

    $stmt = $conn->prepare("SELECT ay_id FROM tbl_academic_years WHERE ay = ? LIMIT 1");
    if (!($stmt instanceof mysqli_stmt)) {
        return 0;
    }

    $stmt->bind_param('s', $ayLabel);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = ($result instanceof mysqli_result) ? $result->fetch_assoc() : null;
    $stmt->close();

    return is_array($row) ? (int)($row['ay_id'] ?? 0) : 0;
}

function room_utilization_fetch_college_signatory_assignments(mysqli $conn, int $collegeId, int $ayId, int $semester): array
{
    if (
        $collegeId <= 0
        || !synk_table_exists($conn, 'tbl_college_faculty')
        || !synk_table_exists($conn, 'tbl_faculty')
        || !synk_table_exists($conn, 'tbl_designation')
        || !synk_table_has_column($conn, 'tbl_faculty', 'designation_id')
    ) {
        return [];
    }

    $assignmentHasAyId = synk_table_has_column($conn, 'tbl_college_faculty', 'ay_id');
    $assignmentHasSemester = synk_table_has_column($conn, 'tbl_college_faculty', 'semester');
    $designationHasStatus = synk_table_has_column($conn, 'tbl_designation', 'status');

    $whereParts = [
        'cf.college_id = ?',
        "LOWER(TRIM(cf.status)) = 'active'",
        "LOWER(TRIM(f.status)) = 'active'",
        "NULLIF(TRIM(d.designation_name), '') IS NOT NULL",
    ];
    $types = 'i';
    $params = [$collegeId];

    if ($designationHasStatus) {
        $whereParts[] = "LOWER(TRIM(d.status)) = 'active'";
    }

    if ($assignmentHasAyId && $assignmentHasSemester && $ayId > 0 && $semester > 0) {
        $whereParts[] = '((cf.ay_id = ? AND cf.semester = ?) OR (cf.ay_id IS NULL AND cf.semester IS NULL))';
        $types .= 'ii';
        $params[] = $ayId;
        $params[] = $semester;
    }

    $sql = "
        SELECT
            cf.college_faculty_id,
            f.faculty_id,
            f.last_name,
            f.first_name,
            " . (synk_table_has_column($conn, 'tbl_faculty', 'middle_name') ? 'f.middle_name' : "'' AS middle_name") . ",
            " . (synk_table_has_column($conn, 'tbl_faculty', 'ext_name') ? 'f.ext_name' : "'' AS ext_name") . ",
            d.designation_name
        FROM tbl_college_faculty cf
        INNER JOIN tbl_faculty f
            ON f.faculty_id = cf.faculty_id
" . synk_faculty_designation_join_sql($conn, $ayId, $semester) . "
        WHERE " . implode("\n          AND ", $whereParts) . "
        ORDER BY f.last_name ASC, f.first_name ASC, cf.college_faculty_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!($stmt instanceof mysqli_stmt)) {
        return [];
    }

    synk_bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'college_faculty_id' => (int)($row['college_faculty_id'] ?? 0),
                'faculty_id' => (int)($row['faculty_id'] ?? 0),
                'name' => room_utilization_faculty_print_name($row),
                'designation_name' => trim((string)($row['designation_name'] ?? '')),
            ];
        }
    }

    $stmt->close();

    return $rows;
}

function room_utilization_designation_is_program_chair(string $designationName): bool
{
    $designationName = strtoupper(trim($designationName));
    return $designationName !== ''
        && strpos($designationName, 'PROGRAM') !== false
        && (
            strpos($designationName, 'CHAIR') !== false
            || strpos($designationName, 'CHAIRMAN') !== false
            || strpos($designationName, 'CHAIRPERSON') !== false
            || strpos($designationName, 'CHAIPERSON') !== false
        );
}

function room_utilization_designation_is_dean(string $designationName): bool
{
    return preg_match('/\bDEAN\b/i', $designationName) === 1;
}

function room_utilization_program_keywords(array $program): array
{
    $text = strtoupper(trim(
        (string)($program['program_name'] ?? '')
        . ' '
        . (string)($program['major'] ?? '')
    ));

    if ($text === '') {
        return [];
    }

    $tokens = preg_split('/[^A-Z0-9]+/', $text) ?: [];
    $stopWords = [
        'A' => true,
        'AN' => true,
        'AND' => true,
        'ARTS' => true,
        'BACHELOR' => true,
        'BS' => true,
        'DEGREE' => true,
        'IN' => true,
        'MAJOR' => true,
        'OF' => true,
        'SCIENCE' => true,
        'THE' => true,
    ];

    $keywords = [];
    foreach ($tokens as $token) {
        $token = trim($token);
        if (strlen($token) < 3 || isset($stopWords[$token])) {
            continue;
        }

        $keywords[$token] = $token;
    }

    return array_values($keywords);
}

function room_utilization_program_chair_match_score(array $assignment, array $program): int
{
    $designation = strtoupper(trim((string)($assignment['designation_name'] ?? '')));
    $designationCompact = room_utilization_compact_text($designation);
    $score = 0;

    $programCodeCompact = room_utilization_compact_text($program['program_code'] ?? '');
    if ($programCodeCompact !== '' && strpos($designationCompact, $programCodeCompact) !== false) {
        $score += 120;
    }

    $programTextCompact = room_utilization_compact_text(
        trim((string)($program['program_name'] ?? '') . ' ' . (string)($program['major'] ?? ''))
    );
    if ($programTextCompact !== '' && strpos($designationCompact, $programTextCompact) !== false) {
        $score += 100;
    }

    $keywords = room_utilization_program_keywords($program);
    if (!empty($keywords)) {
        $matchedKeywords = 0;
        foreach ($keywords as $keyword) {
            if (strpos($designation, $keyword) !== false) {
                $matchedKeywords++;
            }
        }

        if ($matchedKeywords >= 2) {
            $score += 70 + $matchedKeywords;
        } elseif ($matchedKeywords === count($keywords) && $matchedKeywords === 1) {
            $score += 40;
        }
    }

    return $score;
}

function room_utilization_dominant_program_from_rows(array $rows): array
{
    $programs = [];
    $counts = [];
    $order = [];
    $nextOrder = 0;

    foreach ($rows as $row) {
        $rowPrograms = [];

        if (isset($row['program_scope']) && is_array($row['program_scope'])) {
            $rowPrograms = $row['program_scope'];
        } else {
            $rowPrograms[] = [
                'program_id' => (int)($row['program_id'] ?? 0),
                'program_code' => trim((string)($row['program_code'] ?? '')),
                'program_name' => trim((string)($row['program_name'] ?? '')),
                'major' => trim((string)($row['program_major'] ?? '')),
            ];
        }

        $countedInRow = [];
        foreach ($rowPrograms as $program) {
            $programId = (int)($program['program_id'] ?? 0);
            $programCode = strtoupper(trim((string)($program['program_code'] ?? '')));

            if ($programId <= 0 && $programCode === '') {
                continue;
            }

            $programKey = $programId > 0 ? 'id:' . $programId : 'code:' . $programCode;
            if (isset($countedInRow[$programKey])) {
                continue;
            }

            if (!isset($programs[$programKey])) {
                $programs[$programKey] = [
                    'program_id' => $programId,
                    'program_code' => $programCode,
                    'program_name' => trim((string)($program['program_name'] ?? '')),
                    'major' => trim((string)($program['major'] ?? '')),
                ];
                $counts[$programKey] = 0;
                $order[$programKey] = $nextOrder++;
            }

            $counts[$programKey]++;
            $countedInRow[$programKey] = true;
        }
    }

    if (empty($programs)) {
        return [];
    }

    $keys = array_keys($programs);
    usort($keys, static function ($left, $right) use ($counts, $order): int {
        $countCompare = ($counts[$right] ?? 0) <=> ($counts[$left] ?? 0);
        if ($countCompare !== 0) {
            return $countCompare;
        }

        return ($order[$left] ?? 0) <=> ($order[$right] ?? 0);
    });

    $dominantKey = (string)$keys[0];
    $dominantProgram = $programs[$dominantKey];
    $dominantProgram['subject_count'] = (int)($counts[$dominantKey] ?? 0);

    return $dominantProgram;
}

function room_utilization_pick_prepared_by(array $assignments, array $dominantProgram): array
{
    if (empty($dominantProgram)) {
        return ['name' => '', 'designation' => '', 'program_code' => ''];
    }

    $candidates = [];

    foreach ($assignments as $index => $assignment) {
        $designationName = trim((string)($assignment['designation_name'] ?? ''));
        if (!room_utilization_designation_is_program_chair($designationName)) {
            continue;
        }

        $candidates[] = [
            'assignment' => $assignment,
            'score' => room_utilization_program_chair_match_score($assignment, $dominantProgram),
            'order' => $index,
        ];
    }

    if (empty($candidates)) {
        return ['name' => '', 'designation' => '', 'program_code' => (string)($dominantProgram['program_code'] ?? '')];
    }

    usort($candidates, static function (array $left, array $right): int {
        $scoreCompare = ((int)$right['score']) <=> ((int)$left['score']);
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }

        return ((int)$left['order']) <=> ((int)$right['order']);
    });

    $selected = (array)$candidates[0]['assignment'];

    return [
        'name' => (string)($selected['name'] ?? ''),
        'designation' => (string)($selected['designation_name'] ?? ''),
        'program_code' => (string)($dominantProgram['program_code'] ?? ''),
    ];
}

function room_utilization_dean_score(array $assignment): int
{
    $designationName = strtoupper(trim((string)($assignment['designation_name'] ?? '')));
    if ($designationName === 'DEAN') {
        return 120;
    }

    if (strpos($designationName, 'DEAN (') === 0) {
        return 110;
    }

    if (strpos($designationName, 'COLLEGE DEAN') !== false) {
        return 100;
    }

    if (strpos($designationName, 'DEAN') === 0) {
        return 90;
    }

    return room_utilization_designation_is_dean($designationName) ? 70 : 0;
}

function room_utilization_pick_attested_by(array $assignments): array
{
    $candidates = [];

    foreach ($assignments as $index => $assignment) {
        if (!room_utilization_designation_is_dean((string)($assignment['designation_name'] ?? ''))) {
            continue;
        }

        $candidates[] = [
            'assignment' => $assignment,
            'score' => room_utilization_dean_score($assignment),
            'order' => $index,
        ];
    }

    if (empty($candidates)) {
        return ['name' => '', 'designation' => ''];
    }

    usort($candidates, static function (array $left, array $right): int {
        $scoreCompare = ((int)$right['score']) <=> ((int)$left['score']);
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }

        return ((int)$left['order']) <=> ((int)$right['order']);
    });

    $selected = (array)$candidates[0]['assignment'];

    return [
        'name' => (string)($selected['name'] ?? ''),
        'designation' => (string)($selected['designation_name'] ?? ''),
    ];
}

function room_utilization_build_print_signatories(
    mysqli $conn,
    int $collegeId,
    string $ayLabel,
    int $semester,
    array $rows
): array {
    $dominantProgram = room_utilization_dominant_program_from_rows($rows);
    $ayId = room_utilization_fetch_ay_id($conn, $ayLabel);
    $explicitProgramChair = null;

    if ((int)($dominantProgram['program_id'] ?? 0) > 0) {
        $explicitProgramChair = synk_program_chair_signatory_fetch_for_program(
            $conn,
            $collegeId,
            (int)$dominantProgram['program_id'],
            $ayId,
            $semester
        );
    }

    $assignments = room_utilization_fetch_college_signatory_assignments($conn, $collegeId, $ayId, $semester);
    $preparedBy = is_array($explicitProgramChair)
        ? [
            'name' => (string)($explicitProgramChair['name'] ?? ''),
            'designation' => (string)($explicitProgramChair['designation'] ?? 'Program Chairman'),
            'program_code' => (string)($explicitProgramChair['program_code'] ?? ($dominantProgram['program_code'] ?? '')),
        ]
        : room_utilization_pick_prepared_by($assignments, $dominantProgram);

    return [
        'prepared_by' => $preparedBy,
        'attested_by' => room_utilization_pick_attested_by($assignments),
        'dominant_program' => $dominantProgram,
    ];
}

function room_utilization_apply_merge_display(mysqli $conn, array $rows): array
{
    $offeringIds = [];
    foreach ($rows as $row) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId > 0) {
            $offeringIds[] = $offeringId;
        }
    }

    $offeringIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (empty($offeringIds)) {
        return $rows;
    }

    $mergeContext = synk_schedule_merge_load_display_context($conn, $offeringIds);
    $relatedOfferingIds = $offeringIds;

    foreach ($mergeContext as $mergeInfo) {
        $relatedOfferingIds = array_merge(
            $relatedOfferingIds,
            (array)($mergeInfo['group_offering_ids'] ?? []),
            (array)($mergeInfo['member_offering_ids'] ?? []),
            (array)($mergeInfo['effective_owner_by_type'] ?? [])
        );

        foreach ((array)($mergeInfo['owned_member_ids_by_scope'] ?? []) as $scopeOfferingIds) {
            $relatedOfferingIds = array_merge($relatedOfferingIds, (array)$scopeOfferingIds);
        }
    }

    $sectionRowsByOffering = synk_schedule_merge_load_section_rows_by_offering(
        $conn,
        synk_schedule_merge_normalize_offering_ids($relatedOfferingIds)
    );
    $programRowsByOffering = room_utilization_load_program_rows_by_offering(
        $conn,
        synk_schedule_merge_normalize_offering_ids($relatedOfferingIds)
    );

    foreach ($rows as &$row) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId <= 0) {
            continue;
        }

        $scheduleType = room_utilization_schedule_type($row['schedule_type'] ?? 'LEC');
        $fallbackSection = trim((string)($row['section_name'] ?? ''));
        $defaultSection = room_utilization_default_section_label($offeringId, $sectionRowsByOffering, $fallbackSection);
        $scopeDisplay = synk_schedule_merge_scope_display_context(
            (array)($mergeContext[$offeringId] ?? []),
            $scheduleType,
            $offeringId,
            $sectionRowsByOffering
        );
        $mergedSection = trim((string)($scopeDisplay['group_label'] ?? ''));
        $programScope = room_utilization_programs_for_scope($scopeDisplay, $offeringId, $programRowsByOffering);
        $programLabel = room_utilization_program_label_from_programs($programScope);

        if (empty($programScope)) {
            $programLabel = room_utilization_program_label_for_scope($scopeDisplay, $offeringId, $sectionRowsByOffering);
        }

        $row['schedule_type'] = $scheduleType;
        $row['program_scope'] = $programScope;
        $row['program_ids'] = array_values(array_filter(array_map(static function (array $program): int {
            return (int)($program['program_id'] ?? 0);
        }, $programScope)));
        if ($programLabel !== '') {
            $row['program_code'] = $programLabel;
        }

        if (!empty($scopeDisplay['is_merged']) && $mergedSection !== '') {
            $row['section_name'] = $mergedSection;
            $row['program_major'] = '';
            continue;
        }

        if ($defaultSection !== '') {
            $row['section_name'] = $defaultSection;
        }
    }
    unset($row);

    return $rows;
}

if (isset($_POST['load_room_options'])) {
    $ay = trim((string)($_POST['ay'] ?? ''));
    $semester = mapSemester($_POST['semester'] ?? '');

    if ($ay === '' || $semester <= 0) {
        echo json_encode([
            'status' => 'ok',
            'rooms' => []
        ]);
        exit;
    }

    $rooms = array_values(array_map(function ($room) {
        return [
            'room_id' => (int)$room['room_id'],
            'label' => $room['room_label']
        ];
    }, load_accessible_rooms_for_term($conn, $college_id, $ay, $semester)));

    echo json_encode([
        'status' => 'ok',
        'rooms' => $rooms
    ]);
    exit;
}

/* ==========================================================
   SINGLE ROOM SCHEDULE
========================================================== */
if (isset($_POST['load_room_schedule'])) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('po', 'sec', 'sc', 'ps', 'pys', 'ph');
    $classScheduleHasType = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type');

    $ay       = trim($_POST['ay']);
    $semester = mapSemester($_POST['semester']);
    $room_id  = (int)$_POST['room_id'];

    if (!$ay || !$semester || !$room_id) {
        echo json_encode([]);
        exit;
    }

    $accessibleRooms = load_accessible_rooms_for_term($conn, $college_id, $ay, $semester);
    if (!isset($accessibleRooms[$room_id])) {
        echo json_encode([]);
        exit;
    }

    $sql = "
        SELECT
            po.offering_id,
            " . ($classScheduleHasType ? 'cs.schedule_type' : "'LEC' AS schedule_type") . ",
            cs.time_start,
            cs.time_end,
            cs.days_json,
            sm.sub_code AS subject_code,
            COALESCE(p.program_id, 0) AS program_id,
            COALESCE(p.program_code, '') AS program_code,
            COALESCE(p.program_name, '') AS program_name,
            COALESCE(p.major, '') AS program_major,
            sec.full_section AS section_name,
            f.faculty_id,
            COALESCE(
                CONCAT(f.last_name, ', ', f.first_name),
                'TBA'
            ) AS faculty_name,
            r.capacity AS room_capacity
        FROM tbl_class_schedule cs
        INNER JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        INNER JOIN tbl_prospectus_offering po
            ON po.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        LEFT JOIN tbl_program p
            ON p.program_id = po.program_id
        INNER JOIN tbl_academic_years ay
            ON ay.ay_id = po.ay_id
        LEFT JOIN tbl_faculty_workload_sched fws
            ON fws.schedule_id = cs.schedule_id
        LEFT JOIN tbl_faculty f
            ON f.faculty_id = fws.faculty_id
        WHERE cs.room_id = ?
        AND ay.ay = ?
        AND po.semester = ?
        ORDER BY cs.time_start
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isi", $room_id, $ay, $semester);
    $stmt->execute();

    $res = $stmt->get_result();
    $data = [];

    while ($row = $res->fetch_assoc()) {
        $row['days_raw'] = json_decode($row['days_json'], true) ?: [];
        $data[] = $row;
    }

    $data = room_utilization_apply_merge_display($conn, $data);
    $signatories = room_utilization_build_print_signatories($conn, $college_id, $ay, $semester, $data);

    echo json_encode([
        'status' => 'ok',
        'rows' => $data,
        'signatories' => $signatories,
    ]);
    exit;
}

/* ==========================================================
   ALL ROOMS OVERVIEW
========================================================== */
if (isset($_POST['load_all_rooms'])) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('po', 'sec', 'sc', 'ps', 'pys', 'ph');
    $classScheduleHasType = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type');

    $ay       = trim($_POST['ay']);
    $semester = mapSemester($_POST['semester']);

    if (!$ay || !$semester) {
        echo json_encode([]);
        exit;
    }

    $rooms = load_accessible_rooms_for_term($conn, $college_id, $ay, $semester);

    if (empty($rooms)) {
        echo json_encode([]);
        exit;
    }

    $roomIds = array_keys($rooms);
    $roomIdList = implode(',', array_map('intval', $roomIds));

    $schedSql = "
        SELECT
            po.offering_id,
            " . ($classScheduleHasType ? 'cs.schedule_type' : "'LEC' AS schedule_type") . ",
            cs.room_id,
            cs.time_start,
            cs.time_end,
            cs.days_json,
            sm.sub_code AS subject_code,
            COALESCE(p.program_id, 0) AS program_id,
            COALESCE(p.program_code, '') AS program_code,
            COALESCE(p.program_name, '') AS program_name,
            COALESCE(p.major, '') AS program_major,
            sec.full_section AS section_name,
            f.faculty_id,
            COALESCE(
                CONCAT(f.last_name, ', ', f.first_name),
                'TBA'
            ) AS faculty_name
        FROM tbl_class_schedule cs
        INNER JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        INNER JOIN tbl_prospectus_offering po
            ON po.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        LEFT JOIN tbl_program p
            ON p.program_id = po.program_id
        INNER JOIN tbl_academic_years ay
            ON ay.ay_id = po.ay_id
        LEFT JOIN tbl_faculty_workload_sched fws
            ON fws.schedule_id = cs.schedule_id
        LEFT JOIN tbl_faculty f
            ON f.faculty_id = fws.faculty_id
        WHERE cs.room_id IN ({$roomIdList})
          AND r.status = 'active'
          AND ay.ay = ?
          AND po.semester = ?
        ORDER BY r.room_code, cs.time_start
    ";

    $schedStmt = $conn->prepare($schedSql);
    $schedStmt->bind_param("si", $ay, $semester);
    $schedStmt->execute();
    $schedRes = $schedStmt->get_result();
    $scheduleRows = [];

    while ($row = $schedRes->fetch_assoc()) {
        $scheduleRows[] = $row;
    }
    $schedStmt->close();

    foreach (room_utilization_apply_merge_display($conn, $scheduleRows) as $row) {
        $roomId = (int)$row['room_id'];
        if (!isset($rooms[$roomId])) {
            continue;
        }

        $days = json_decode($row['days_json'], true) ?: [];
        $dayKey = normalize_day_key($days);
        if ($dayKey === '') {
            continue;
        }

        if (!isset($rooms[$roomId]['groups'][$dayKey])) {
            $rooms[$roomId]['groups'][$dayKey] = [];
        }

        $rooms[$roomId]['groups'][$dayKey][] = [
            "time_start"   => $row['time_start'],
            "time_end"     => $row['time_end'],
            "days_json"    => $row['days_json'],
            "subject_code" => $row['subject_code'],
            "program_id"   => (int)($row['program_id'] ?? 0),
            "program_code" => $row['program_code'],
            "program_name" => $row['program_name'],
            "program_major" => $row['program_major'],
            "program_scope" => $row['program_scope'] ?? [],
            "program_ids" => $row['program_ids'] ?? [],
            "section_name" => $row['section_name'],
            "faculty_id"   => (int)($row['faculty_id'] ?? 0),
            "faculty_name" => $row['faculty_name']
        ];
    }

    echo json_encode(array_values($rooms));
    exit;
}

if (isset($_POST['load_faculty_workload'])) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $classScheduleHasGroupId = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_group_id');
    $classScheduleHasType = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type');
    $facultyHasDesignationId = synk_table_has_column($conn, 'tbl_faculty', 'designation_id');
    $designationTableExists = synk_table_exists($conn, 'tbl_designation');
    $designationHasStatus = $designationTableExists && synk_table_has_column($conn, 'tbl_designation', 'status');
    $assignmentHasAyId = synk_table_has_column($conn, 'tbl_college_faculty', 'ay_id');
    $assignmentHasSemester = synk_table_has_column($conn, 'tbl_college_faculty', 'semester');

    $facultyId = (int)($_POST['faculty_id'] ?? 0);
    $ay = trim((string)($_POST['ay'] ?? ''));
    $semester = mapSemester($_POST['semester'] ?? '');

    if ($facultyId <= 0 || $ay === '' || $semester <= 0) {
        echo json_encode([
            'rows' => [],
            'meta' => []
        ]);
        exit;
    }

    $selectParts = [
        'fw.workload_id',
        'cs.schedule_id',
        'o.offering_id',
        $classScheduleHasGroupId ? 'cs.schedule_group_id AS group_id' : 'NULL AS group_id',
        $classScheduleHasType ? 'cs.schedule_type AS type' : "'LEC' AS type",
        'sm.sub_code',
        'sm.sub_description AS subject_description',
        'sec.section_name',
        'sec.full_section',
        'cs.days_json',
        'cs.time_start',
        'cs.time_end',
        "COALESCE(r.room_code, '') AS room_code",
        "COALESCE(col.college_code, '') AS college_code",
        'ps.lec_units',
        'ps.lab_units',
        'ps.total_units',
        "COALESCE(CONCAT(f.last_name, ', ', f.first_name), 'TBA') AS faculty_name"
    ];

    $orderParts = [
        'sec.section_name',
        'sm.sub_code',
        $classScheduleHasGroupId ? 'COALESCE(cs.schedule_group_id, cs.schedule_id)' : 'cs.schedule_id'
    ];

    if ($classScheduleHasType) {
        $orderParts[] = "FIELD(cs.schedule_type, 'LEC', 'LAB')";
    }

    $orderParts[] = 'cs.time_start';

    $sql = "
        SELECT
            " . implode(",\n            ", $selectParts) . "
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        LEFT JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        LEFT JOIN tbl_program p
            ON p.program_id = o.program_id
        LEFT JOIN tbl_college col
            ON col.college_id = p.college_id
        LEFT JOIN tbl_faculty f
            ON f.faculty_id = fw.faculty_id
        INNER JOIN tbl_academic_years ay
            ON ay.ay_id = fw.ay_id
        WHERE fw.faculty_id = ?
          AND ay.ay = ?
          AND fw.semester = ?
        ORDER BY " . implode(",\n                 ", $orderParts) . "
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isi", $facultyId, $ay, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    $rawRows = [];
    $preparations = [];
    $facultyName = '';
    $offeringIds = [];

    while ($row = $res->fetch_assoc()) {
        $facultyName = $facultyName !== '' ? $facultyName : (string)($row['faculty_name'] ?? '');
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId > 0) {
            $offeringIds[$offeringId] = true;
        }

        $type = strtoupper(trim((string)($row['type'] ?? 'LEC')));
        $lecUnits = (float)($row['lec_units'] ?? 0);
        $labValue = (float)($row['lab_units'] ?? 0);
        $totalUnits = (float)($row['total_units'] ?? 0);

        $subCode = trim((string)($row['sub_code'] ?? ''));
        if ($subCode !== '') {
            $preparations[$subCode] = true;
        }

        $daysArr = json_decode((string)($row['days_json'] ?? '[]'), true);
        if (!is_array($daysArr)) {
            $daysArr = [];
        }

        $rawRows[] = [
            'workload_id' => (int)($row['workload_id'] ?? 0),
            'schedule_id' => (int)($row['schedule_id'] ?? 0),
            'offering_id' => (int)($row['offering_id'] ?? 0),
            'group_id' => (int)($row['group_id'] ?? 0),
            'type' => $type,
            'sub_code' => $subCode,
            'desc' => (string)($row['subject_description'] ?? ''),
            'section' => (string)($row['section_name'] ?? ''),
            'full_section' => (string)($row['full_section'] ?? ''),
            'days_arr' => $daysArr,
            'time_start' => (string)($row['time_start'] ?? ''),
            'time_end' => (string)($row['time_end'] ?? ''),
            'room' => (string)($row['room_code'] ?? ''),
            'college_code' => (string)($row['college_code'] ?? ''),
            'lec_units' => $lecUnits,
            'lab_units' => $labValue,
            'total_units' => $totalUnits
        ];
    }

    $stmt->close();

    $contextTotals = [];
    if (!empty($offeringIds)) {
        $offeringIdList = implode(',', array_map('intval', array_keys($offeringIds)));
        $contextSelectParts = [
            'cs.schedule_id',
            'cs.offering_id',
            $classScheduleHasGroupId ? 'cs.schedule_group_id AS group_id' : 'NULL AS group_id',
            $classScheduleHasType ? 'cs.schedule_type AS type' : "'LEC' AS type"
        ];
        $contextSql = "
            SELECT
                " . implode(",\n                ", $contextSelectParts) . "
            FROM tbl_class_schedule cs
            WHERE cs.offering_id IN ({$offeringIdList})
        ";

        $contextRes = $conn->query($contextSql);
        if ($contextRes instanceof mysqli_result) {
            while ($contextRow = $contextRes->fetch_assoc()) {
                $contextKey = room_workload_context_key_from_values(
                    (int)($contextRow['group_id'] ?? 0),
                    (int)($contextRow['schedule_id'] ?? 0),
                    (int)($contextRow['offering_id'] ?? 0)
                );

                if (!isset($contextTotals[$contextKey])) {
                    $contextTotals[$contextKey] = [
                        'total_count' => 0,
                        'lec_count' => 0,
                        'lab_count' => 0
                    ];
                }

                $contextTotals[$contextKey]['total_count']++;
                if (strtoupper(trim((string)($contextRow['type'] ?? 'LEC'))) === 'LAB') {
                    $contextTotals[$contextKey]['lab_count']++;
                    continue;
                }

                $contextTotals[$contextKey]['lec_count']++;
            }
            $contextRes->free();
        }
    }

    $ownedContextRows = [];
    foreach ($rawRows as $rawRow) {
        $contextKey = room_workload_context_key_from_values(
            (int)($rawRow['group_id'] ?? 0),
            (int)($rawRow['schedule_id'] ?? 0),
            (int)($rawRow['offering_id'] ?? 0)
        );

        if (!isset($ownedContextRows[$contextKey])) {
            $ownedContextRows[$contextKey] = [];
        }

        $ownedContextRows[$contextKey][] = [
            'schedule_type' => (string)($rawRow['type'] ?? 'LEC'),
            'days' => $rawRow['days_arr'] ?? [],
            'time_start' => (string)($rawRow['time_start'] ?? ''),
            'time_end' => (string)($rawRow['time_end'] ?? ''),
            'lec_units' => (float)($rawRow['lec_units'] ?? 0),
            'lab_units' => (float)($rawRow['lab_units'] ?? 0),
            'total_units' => (float)($rawRow['total_units'] ?? 0)
        ];
    }

    $ownedContextMetrics = [];
    foreach ($ownedContextRows as $contextKey => $contextRows) {
        $ownedContextMetrics[$contextKey] = synk_schedule_sum_display_metrics(
            $contextRows,
            $contextTotals[$contextKey] ?? []
        );
    }

    foreach ($rawRows as $rawRow) {
        $lecUnits = (float)($rawRow['lec_units'] ?? 0);
        $labValue = (float)($rawRow['lab_units'] ?? 0);
        $totalUnits = (float)($rawRow['total_units'] ?? 0);
        $subjectUnits = synk_subject_units_total($lecUnits, $labValue, $totalUnits);
        $contextKey = room_workload_context_key_from_values(
            (int)($rawRow['group_id'] ?? 0),
            (int)($rawRow['schedule_id'] ?? 0),
            (int)($rawRow['offering_id'] ?? 0)
        );
        $metrics = $ownedContextMetrics[$contextKey] ?? [
            'units' => 0.0,
            'lec' => 0.0,
            'lab' => 0.0,
            'lab_hours' => 0.0,
            'faculty_load' => 0.0
        ];

        $fullSection = trim((string)($rawRow['full_section'] ?? ''));
        if ($fullSection === '') {
            $fullSection = trim((string)($rawRow['section'] ?? ''));
        }

        $rows[] = [
            'workload_id' => (int)($rawRow['workload_id'] ?? 0),
            'offering_id' => (int)($rawRow['offering_id'] ?? 0),
            'group_id' => (int)($rawRow['group_id'] ?? 0),
            'type' => (string)($rawRow['type'] ?? 'LEC'),
            'sub_code' => (string)($rawRow['sub_code'] ?? ''),
            'desc' => (string)($rawRow['desc'] ?? ''),
            'course' => $fullSection,
            'section' => (string)($rawRow['section'] ?? ''),
            'days' => implode(", ", $rawRow['days_arr'] ?? []),
            'time' => synk_schedule_time_label($rawRow['time_start'] ?? '', $rawRow['time_end'] ?? ''),
            'room' => (string)($rawRow['room'] ?? ''),
            'college_code' => (string)($rawRow['college_code'] ?? ''),
            'subject_units' => round($subjectUnits, 2),
            'lec_units' => round($lecUnits, 2),
            'lab_units' => round($labValue, 2),
            'units' => round((float)($metrics['units'] ?? 0), 2),
            'lec' => round((float)($metrics['lec'] ?? 0), 2),
            'lab' => round((float)($metrics['lab'] ?? 0), 2),
            'lab_hours' => round((float)($metrics['lab_hours'] ?? 0), 2),
            'faculty_load' => round((float)($metrics['faculty_load'] ?? 0), 2),
            'student_count' => 0
        ];
    }

    $studentCountMap = synk_fetch_offering_enrollee_count_map($conn, array_keys($offeringIds));
    foreach ($rows as &$workloadRow) {
        $workloadRow['student_count'] = synk_offering_enrollee_count_for_map(
            $studentCountMap,
            (int)($workloadRow['offering_id'] ?? 0)
        );
    }
    unset($workloadRow);

    $designationName = '';
    $designationUnits = 0.0;
    $designationCollegeName = '';

    if ($facultyHasDesignationId && $designationTableExists) {
        $designationWhere = [
            'cf.faculty_id = ?',
            "cf.status = 'active'"
        ];
        $designationTypes = 'i';
        $designationParams = [$facultyId];

        if ($assignmentHasAyId) {
            $designationWhere[] = 'cf.ay_id = ay.ay_id';
        }

        if ($assignmentHasSemester) {
            $designationWhere[] = 'cf.semester = ?';
            $designationTypes .= 'i';
            $designationParams[] = $semester;
        }

        $designationSql = "
            SELECT
                d.designation_name,
                d.designation_units,
                col.college_name,
                cf.college_id
            FROM tbl_college_faculty cf
            INNER JOIN tbl_faculty f
                ON f.faculty_id = cf.faculty_id
" . synk_faculty_designation_join_sql($conn, room_utilization_fetch_ay_id($conn, $ay), $semester) . "
               " . ($designationHasStatus ? "AND d.status = 'active'" : '') . "
            LEFT JOIN tbl_college col
                ON col.college_id = cf.college_id
            INNER JOIN tbl_academic_years ay
                ON ay.ay = ?
            WHERE " . implode("\n              AND ", $designationWhere) . "
            ORDER BY CASE WHEN cf.college_id = ? THEN 0 ELSE 1 END,
                     cf.college_faculty_id DESC
            LIMIT 1
        ";

        $designationTypes = 's' . $designationTypes . 'i';
        array_unshift($designationParams, $ay);
        $designationParams[] = $college_id;

        $designationStmt = $conn->prepare($designationSql);
        synk_bind_dynamic_params($designationStmt, $designationTypes, $designationParams);
        $designationStmt->execute();
        $designationRes = $designationStmt->get_result();
        $designationRow = $designationRes->fetch_assoc();
        $designationStmt->close();

        if (is_array($designationRow)) {
            $designationName = trim((string)($designationRow['designation_name'] ?? ''));
            $designationUnits = (float)($designationRow['designation_units'] ?? 0);
            $designationCollegeName = trim((string)($designationRow['college_name'] ?? ''));
        }
    }

    echo json_encode([
        'rows' => $rows,
        'meta' => [
            'faculty_name' => $facultyName,
            'designation_name' => $designationName,
            'designation_label' => workload_title_case($designationName),
            'designation_units' => round($designationUnits, 2),
            'designation_college_name' => $designationCollegeName,
            'total_preparations' => count($preparations)
        ]
    ]);
    exit;
}

echo json_encode([]);
