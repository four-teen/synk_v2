<?php
session_start();
include 'db.php';
require_once __DIR__ . '/scheduler_request_guard.php';
synk_scheduler_guard_request($conn, 'auto_schedule_draft.php');
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schedule_block_helper.php';
require_once __DIR__ . '/academic_schedule_policy_helper.php';
require_once __DIR__ . '/schedule_merge_helper.php';
require_once __DIR__ . '/schedule_tba_helper.php';

header('Content-Type: application/json');

function respond($status, $message = '', $extra = []) {
    echo json_encode(array_merge([
        'status' => $status,
        'message' => $message
    ], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond('error', 'Invalid request method.');
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'scheduler') {
    respond('error', 'Unauthorized access.');
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    respond('error', 'CSRF validation failed.');
}

$college_id = (int)($_SESSION['college_id'] ?? 0);
if ($college_id <= 0) {
    respond('error', 'Missing college context.');
}

$previewRequested = isset($_POST['preview_auto_schedule_draft']);
$applyRequested = isset($_POST['apply_auto_schedule_draft']);
if ($applyRequested) {
    try { synk_schedule_acquire_write_lock($conn); }
    catch (Throwable $e) { respond('error', $e->getMessage()); }
}

if (!$previewRequested && !$applyRequested) {
    respond('error', 'Invalid request.');
}

$program_id = (int)($_POST['program_id'] ?? 0);
$ay_id = (int)($_POST['ay_id'] ?? 0);
$semester = (int)($_POST['semester'] ?? 0);

if ($program_id <= 0 || $ay_id <= 0 || !in_array($semester, [1, 2, 3], true)) {
    respond('error', 'Missing or invalid scheduling filters.');
}

$schedulePolicy = synk_fetch_effective_schedule_policy($conn, $college_id);

function day_order(): array
{
    return ['M', 'T', 'W', 'Th', 'F', 'S'];
}

function normalize_day_token($day): string
{
    $token = strtoupper(trim((string)$day));
    if ($token === 'TH') {
        $token = 'Th';
    }

    return in_array($token, day_order(), true) ? $token : '';
}

function normalize_days_array($days): array
{
    if (is_string($days) && trim($days) !== '') {
        $decoded = json_decode($days, true);
        $days = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($days)) {
        return [];
    }

    $seen = [];
    foreach ($days as $day) {
        $normalized = normalize_day_token($day);
        if ($normalized !== '') {
            $seen[$normalized] = true;
        }
    }

    $result = [];
    foreach (day_order() as $day) {
        if (isset($seen[$day])) {
            $result[] = $day;
        }
    }

    return $result;
}

function days_overlap($left, $right): bool
{
    return is_array($left) && is_array($right) && count(array_intersect($left, $right)) > 0;
}

function days_to_label($days): string
{
    return implode('', normalize_days_array($days));
}

function normalize_time_input($value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    if (!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $value)) {
        return null;
    }

    return strlen($value) === 5 ? $value . ':00' : $value;
}

function time_to_minutes($time): ?int
{
    $normalized = normalize_time_input($time);
    if ($normalized === null) {
        return null;
    }

    $parts = explode(':', $normalized);
    return ((int)$parts[0] * 60) + (int)$parts[1];
}

function minutes_to_time(int $minutes): string
{
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return sprintf('%02d:%02d:00', $hours, $mins);
}

function minutes_to_label(int $minutes): string
{
    return date('g:i A', strtotime(minutes_to_time($minutes)));
}

function max_time_string(string $left, string $right): string
{
    return strcmp($left, $right) >= 0 ? $left : $right;
}

function min_time_string(string $left, string $right): string
{
    return strcmp($left, $right) <= 0 ? $left : $right;
}

function format_hours_label(int $minutes): string
{
    $hours = $minutes / 60;
    return floor($hours) == $hours
        ? number_format($hours, 0) . ' hour(s)'
        : number_format($hours, 1) . ' hour(s)';
}

function synk_schedule_minutes_overlap(string $startA, string $endA, string $startB, string $endB): bool
{
    return ($startA < $endB) && ($endA > $startB);
}

function room_access_table_exists(mysqli $conn): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    $q = $conn->query("SHOW TABLES LIKE 'tbl_room_college_access'");
    $exists = $q && $q->num_rows > 0;
    return $exists;
}

function section_curriculum_table_exists(mysqli $conn): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    $q = $conn->query("SHOW TABLES LIKE 'tbl_section_curriculum'");
    $exists = $q && $q->num_rows > 0;
    return $exists;
}

function normalize_room_type($type): string
{
    $value = strtolower(trim((string)$type));
    return in_array($value, ['lecture', 'laboratory', 'lec_lab'], true) ? $value : 'lecture';
}

function room_type_allows_schedule($roomType, string $scheduleType): bool
{
    $roomType = normalize_room_type($roomType);
    if ($scheduleType === 'LAB') {
        return in_array($roomType, ['laboratory', 'lec_lab'], true);
    }

    return in_array($roomType, ['lecture', 'lec_lab'], true);
}

function build_room_label(array $row): string
{
    $code = trim((string)($row['room_code'] ?? ''));
    $name = trim((string)($row['room_name'] ?? ''));
    $label = $code !== '' ? $code : $name;

    if ($code !== '' && $name !== '' && strcasecmp($code, $name) !== 0) {
        $label = $code . ' - ' . $name;
    } elseif ($label === '') {
        $label = 'Room';
    }

    $accessType = strtolower(trim((string)($row['access_type'] ?? 'owner')));
    if ($accessType === 'shared') {
        $ownerCode = trim((string)($row['owner_code'] ?? ''));
        $label .= $ownerCode !== '' ? " (Shared from {$ownerCode})" : ' (Shared)';
    }

    return $label;
}

function load_accessible_rooms(mysqli $conn, int $collegeId, int $ayId, int $semester): array
{
    if (!room_access_table_exists($conn)) {
        return [];
    }

    $sql = "
        SELECT DISTINCT
            r.room_id,
            r.room_code,
            r.room_name,
            LOWER(COALESCE(r.room_type, 'lecture')) AS room_type,
            acc.access_type,
            owner.college_code AS owner_code
        FROM tbl_room_college_access acc
        INNER JOIN tbl_rooms r ON r.room_id = acc.room_id
        INNER JOIN tbl_college owner ON owner.college_id = r.college_id
        WHERE acc.college_id = ?
          AND acc.ay_id = ?
          AND acc.semester = ?
          AND r.status = 'active'
        ORDER BY r.room_name ASC, r.room_code ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('iii', $collegeId, $ayId, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $rooms = [];
    while ($row = $res->fetch_assoc()) {
        $row['room_id'] = (int)$row['room_id'];
        $row['room_type'] = normalize_room_type($row['room_type'] ?? 'lecture');
        $row['access_type'] = strtolower((string)($row['access_type'] ?? 'owner'));
        $row['label'] = build_room_label($row);
        $rooms[] = $row;
    }

    $stmt->close();
    return $rooms;
}

function load_target_offerings(mysqli $conn, int $programId, int $ayId, int $semester, int $collegeId): array
{
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $sql = "
        SELECT
            o.offering_id,
            o.section_id,
            o.ay_id,
            o.semester,
            o.status AS offering_status,
            sec.section_name,
            sec.year_level,
            sm.sub_code,
            sm.sub_description,
            ps.lec_units,
            ps.lab_units,
            ps.total_units
        FROM tbl_prospectus_offering o
        {$liveOfferingJoins}
        INNER JOIN tbl_program p ON p.program_id = o.program_id
        INNER JOIN tbl_subject_masterlist sm ON sm.sub_id = ps.sub_id
        WHERE o.program_id = ?
          AND o.ay_id = ?
          AND o.semester = ?
          AND p.college_id = ?
        ORDER BY sec.year_level ASC, sec.section_name ASC, sm.sub_code ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('iiii', $programId, $ayId, $semester, $collegeId);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = [
            'offering_id' => (int)$row['offering_id'],
            'section_id' => (int)$row['section_id'],
            'ay_id' => (int)$row['ay_id'],
            'semester' => (int)$row['semester'],
            'offering_status' => strtolower(trim((string)($row['offering_status'] ?? 'pending'))),
            'section_name' => (string)$row['section_name'],
            'year_level' => (int)($row['year_level'] ?? 0),
            'sub_code' => (string)$row['sub_code'],
            'sub_description' => (string)$row['sub_description'],
            'lec_units' => (float)$row['lec_units'],
            'lab_units' => (float)$row['lab_units'],
            'total_units' => (float)$row['total_units']
        ];
    }

    $stmt->close();

    $mergeContext = synk_schedule_merge_load_display_context($conn, array_map(static function (array $row): int {
        return (int)$row['offering_id'];
    }, $rows));

    $filtered = [];
    foreach ($rows as $row) {
        $offeringId = (int)$row['offering_id'];
        $mergeInfo = $mergeContext[$offeringId] ?? null;
        if (is_array($mergeInfo) && !empty($mergeInfo['is_merged_member'])) {
            continue;
        }

        if (is_array($mergeInfo) && (int)($mergeInfo['group_size'] ?? 1) > 1) {
            $groupLabel = trim((string)($mergeInfo['group_course_label'] ?? ''));
            if ($groupLabel !== '') {
                $row['section_name'] = $groupLabel;
            }
        }

        $filtered[] = $row;
    }

    return $filtered;
}

function load_existing_schedule_rows_by_offering(mysqli $conn, array $offeringIds): array
{
    $result = [];
    if (empty($offeringIds)) {
        return $result;
    }

    $safeIds = array_map('intval', array_values(array_unique($offeringIds)));
    $sql = "
        SELECT
            cs.offering_id,
            cs.schedule_id,
            cs.schedule_type,
            cs.schedule_group_id,
            cs.room_id,
            cs.days_json,
            cs.time_start,
            cs.time_end
        FROM tbl_class_schedule cs
        WHERE cs.offering_id IN (" . implode(',', $safeIds) . ")
          AND cs.schedule_type IN ('LEC', 'LAB')
        ORDER BY cs.offering_id ASC, FIELD(cs.schedule_type, 'LEC', 'LAB'), cs.schedule_id ASC
    ";

    $query = $conn->query($sql);
    if (!($query instanceof mysqli_result)) {
        return $result;
    }

    while ($row = $query->fetch_assoc()) {
        $offeringId = (int)$row['offering_id'];
        if (!isset($result[$offeringId])) {
            $result[$offeringId] = [];
        }

        $result[$offeringId][] = [
            'schedule_id' => (int)$row['schedule_id'],
            'schedule_type' => synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC')),
            'schedule_group_id' => (int)($row['schedule_group_id'] ?? 0),
            'room_id' => (int)$row['room_id'],
            'days' => normalize_days_array(json_decode((string)($row['days_json'] ?? ''), true)),
            'time_start' => (string)$row['time_start'],
            'time_end' => (string)$row['time_end']
        ];
    }

    return $result;
}

function offering_schedule_status(array $offering, array $rows): array
{
    $required = synk_required_minutes_by_type(
        (float)$offering['lec_units'],
        (float)$offering['lab_units'],
        (float)$offering['total_units']
    );
    $scheduled = synk_sum_scheduled_minutes_by_type($rows);
    $requiredTypes = ((float)$offering['lab_units'] > 0) ? ['LEC', 'LAB'] : ['LEC'];

    $hasAny = false;
    $isComplete = true;
    foreach ($requiredTypes as $type) {
        $requiredMinutes = (int)($required[$type] ?? 0);
        $scheduledMinutes = (int)($scheduled[$type] ?? 0);

        if ($scheduledMinutes > 0) {
            $hasAny = true;
        }

        if ($requiredMinutes > 0 && $scheduledMinutes < $requiredMinutes) {
            $isComplete = false;
        }
    }

    if (!$hasAny) {
        return [
            'status_key' => 'not_scheduled',
            'status_label' => 'Not Scheduled',
            'required_minutes' => $required,
            'scheduled_minutes' => $scheduled
        ];
    }

    if ($isComplete) {
        return [
            'status_key' => 'scheduled',
            'status_label' => 'Scheduled',
            'required_minutes' => $required,
            'scheduled_minutes' => $scheduled
        ];
    }

    return [
        'status_key' => 'incomplete',
        'status_label' => 'Incomplete',
        'required_minutes' => $required,
        'scheduled_minutes' => $scheduled
    ];
}

function load_workload_faculty_map(mysqli $conn, array $offeringIds, int $ayId, int $semester): array
{
    $map = [];
    if (empty($offeringIds)) {
        return $map;
    }

    $safeIds = array_map('intval', array_values(array_unique($offeringIds)));
    $sql = "
        SELECT
            cs.offering_id,
            fw.faculty_id,
            CONCAT(
                f.last_name,
                ', ',
                f.first_name,
                CASE
                    WHEN COALESCE(f.ext_name, '') <> '' THEN CONCAT(' ', f.ext_name)
                    ELSE ''
                END
            ) AS faculty_name
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_faculty f
            ON f.faculty_id = fw.faculty_id
        WHERE cs.offering_id IN (" . implode(',', $safeIds) . ")
          AND fw.ay_id = ?
          AND fw.semester = ?
        GROUP BY cs.offering_id, fw.faculty_id, faculty_name
        ORDER BY faculty_name ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $map;
    }

    $stmt->bind_param('ii', $ayId, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $offeringId = (int)$row['offering_id'];
        if (!isset($map[$offeringId])) {
            $map[$offeringId] = [];
        }

        $map[$offeringId][] = (string)$row['faculty_name'];
    }

    $stmt->close();
    return $map;
}

function load_term_schedule_snapshot_by_day(mysqli $conn, int $ayId, int $semester, array $excludeOfferingIds = []): array
{
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $sql = "
        SELECT
            cs.offering_id,
            cs.room_id,
            cs.schedule_type,
            cs.days_json,
            cs.time_start,
            cs.time_end,
            o.section_id
        FROM tbl_class_schedule cs
        INNER JOIN tbl_prospectus_offering o ON o.offering_id = cs.offering_id
        {$liveOfferingJoins}
        WHERE o.ay_id = ?
          AND o.semester = ?
    ";

    if (!empty($excludeOfferingIds)) {
        $safeIds = array_map('intval', array_values(array_unique($excludeOfferingIds)));
        $sql .= " AND cs.offering_id NOT IN (" . implode(',', $safeIds) . ")";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('ii', $ayId, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $byDay = [];
    foreach (day_order() as $day) {
        $byDay[$day] = [];
    }

    while ($row = $res->fetch_assoc()) {
        $days = normalize_days_array(json_decode((string)$row['days_json'], true));
        if (empty($days)) {
            continue;
        }

        $item = [
            'offering_id' => (int)$row['offering_id'],
            'room_id' => (int)$row['room_id'],
            'schedule_type' => synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC')),
            'time_start' => (string)$row['time_start'],
            'time_end' => (string)$row['time_end'],
            'section_id' => (int)$row['section_id'],
            'days' => $days
        ];

        foreach ($days as $day) {
            $byDay[$day][] = $item;
        }
    }

    $stmt->close();
    return $byDay;
}

function add_block_to_occupancy(array &$occupancyByDay, array $block, array $offering): void
{
    $item = [
        'offering_id' => (int)$offering['offering_id'],
        'room_id' => (int)$block['room_id'],
        'schedule_type' => (string)$block['schedule_type'],
        'time_start' => (string)$block['time_start'],
        'time_end' => (string)$block['time_end'],
        'section_id' => (int)$offering['section_id'],
        'days' => $block['days']
    ];

    foreach ($block['days'] as $day) {
        if (!isset($occupancyByDay[$day])) {
            $occupancyByDay[$day] = [];
        }
        $occupancyByDay[$day][] = $item;
    }
}

function candidate_has_conflict(array $candidate, array $occupancyByDay, int $sectionId): bool
{
    foreach ($candidate['days'] as $day) {
        foreach ($occupancyByDay[$day] ?? [] as $item) {
            if (!synk_schedule_minutes_overlap(
                $candidate['time_start'],
                $candidate['time_end'],
                $item['time_start'],
                $item['time_end']
            )) {
                continue;
            }

            if ((int)$item['section_id'] === $sectionId) {
                return true;
            }

            if ((int)$item['room_id'] === (int)$candidate['room_id']) {
                return true;
            }
        }
    }

    return false;
}

function room_fit_score(array $room, string $scheduleType): int
{
    $roomType = normalize_room_type($room['room_type'] ?? 'lecture');
    $score = 0;

    if ($scheduleType === 'LAB') {
        $score += $roomType === 'laboratory' ? 10 : 5;
    } else {
        $score += $roomType === 'lecture' ? 8 : 4;
    }

    $score += strtolower((string)($room['access_type'] ?? 'owner')) === 'owner' ? 3 : 1;
    return $score;
}

function time_score(array $candidate): int
{
    $start = time_to_minutes($candidate['time_start']);
    $end = time_to_minutes($candidate['time_end']);
    if ($start === null || $end === null) {
        return 0;
    }

    $score = 0;
    if ($start >= 450 && $start < 720) {
        $score += 3;
    }
    if ($end <= 1020) {
        $score += 2;
    }
    if ($start < 780 && $end > 720) {
        $score -= 6;
    }

    return $score;
}

function add_pattern(array &$patterns, array $days, int $meetingMinutes, int $weight, string $label): void
{
    $days = normalize_days_array($days);
    if (empty($days) || $meetingMinutes <= 0 || ($meetingMinutes % 30) !== 0) {
        return;
    }

    $key = implode('-', $days) . '|' . $meetingMinutes;
    if (isset($patterns[$key])) {
        if ($weight > $patterns[$key]['weight']) {
            $patterns[$key]['weight'] = $weight;
            $patterns[$key]['label'] = $label;
        }
        return;
    }

    $patterns[$key] = [
        'days' => $days,
        'meeting_minutes' => $meetingMinutes,
        'weight' => $weight,
        'label' => $label
    ];
}

function build_lecture_patterns(int $weeklyMinutes): array
{
    $patterns = [];
    $definitions = [
        [['M', 'W', 'F'], 36, 'MWF lecture formula'],
        [['M', 'W'], 34, 'MW lecture rebalance'],
        [['T', 'Th'], 30, 'TTh lecture fallback']
    ];

    foreach ($definitions as $definition) {
        $days = $definition[0];
        $meetingCount = count($days);
        if ($meetingCount <= 0 || ($weeklyMinutes % $meetingCount) !== 0) {
            continue;
        }

        $meetingMinutes = (int)($weeklyMinutes / $meetingCount);
        add_pattern($patterns, $days, $meetingMinutes, (int)$definition[1], (string)$definition[2]);
    }

    if ($weeklyMinutes <= 120 && ($weeklyMinutes % 30) === 0) {
        add_pattern($patterns, ['M'], $weeklyMinutes, 16, 'Monday lecture block');
        add_pattern($patterns, ['W'], $weeklyMinutes, 16, 'Wednesday lecture block');
        add_pattern($patterns, ['T'], $weeklyMinutes, 15, 'Tuesday lecture block');
        add_pattern($patterns, ['Th'], $weeklyMinutes, 15, 'Thursday lecture block');
        add_pattern($patterns, ['F'], $weeklyMinutes, 14, 'Friday lecture block');
    }

    return array_values($patterns);
}

function build_lab_patterns(int $weeklyMinutes): array
{
    $patterns = [];
    if (($weeklyMinutes % 2) !== 0) {
        return [];
    }

    $meetingMinutes = (int)($weeklyMinutes / 2);
    if (($meetingMinutes % 30) !== 0 || $meetingMinutes <= 0) {
        return [];
    }

    add_pattern(
        $patterns,
        ['T', 'Th'],
        $meetingMinutes,
        $meetingMinutes === 90 ? 38 : 32,
        $meetingMinutes === 90 ? 'TTh laboratory formula' : 'TTh laboratory split'
    );

    return array_values($patterns);
}

function build_component_patterns(string $scheduleType, int $weeklyMinutes): array
{
    if ($weeklyMinutes <= 0) {
        return [];
    }

    if ($scheduleType === 'LAB') {
        return build_lab_patterns($weeklyMinutes);
    }

    return build_lecture_patterns($weeklyMinutes);
}

function resolve_component_window(string $scheduleType, array $days, array $policy): ?array
{
    $usesTuesdayThursday = count(array_intersect($days, ['T', 'Th'])) > 0;
    $baseStart = ($scheduleType === 'LAB' || $usesTuesdayThursday) ? '07:30:00' : '08:00:00';
    $baseEnd = ($scheduleType === 'LAB' || $usesTuesdayThursday) ? '17:30:00' : '17:00:00';

    $start = max_time_string((string)($policy['day_start'] ?? '07:30:00'), $baseStart);
    $end = min_time_string((string)($policy['day_end'] ?? '17:30:00'), $baseEnd);

    if ($end <= $start) {
        return null;
    }

    return [
        'start' => $start,
        'end' => $end
    ];
}

function build_component_failure_reason(string $scheduleType, int $weeklyMinutes, array $diagnostics): string
{
    $typeLabel = $scheduleType === 'LAB' ? 'laboratory' : 'lecture';

    if ((int)($diagnostics['compatible_room_count'] ?? 0) <= 0) {
        return "No {$typeLabel}-compatible room is available for this term.";
    }

    if ((int)($diagnostics['pattern_count'] ?? 0) <= 0) {
        return "The {$typeLabel} load of " . format_hours_label($weeklyMinutes) . " does not match the supported auto-draft distribution.";
    }

    if ((int)($diagnostics['policy_pattern_count'] ?? 0) <= 0) {
        return "The active scheduling policy blocks the standard {$typeLabel} day distribution.";
    }

    if ((int)($diagnostics['slot_checks'] ?? 0) <= 0) {
        return "The active scheduling window does not have enough room to place the {$typeLabel} block.";
    }

    return "No conflict-free {$typeLabel} slot is available in the accessible rooms for this section.";
}

function generate_component_candidates(
    string $scheduleType,
    int $weeklyMinutes,
    array $offering,
    array $occupancyByDay,
    array $rooms,
    array $policy
): array {
    $compatibleRooms = array_values(array_filter($rooms, function (array $room) use ($scheduleType): bool {
        return room_type_allows_schedule($room['room_type'] ?? 'lecture', $scheduleType);
    }));

    $patterns = build_component_patterns($scheduleType, $weeklyMinutes);
    $policyPatterns = [];
    foreach ($patterns as $pattern) {
        if (!empty(synk_schedule_policy_disallowed_days($pattern['days'], $policy))) {
            continue;
        }
        $policyPatterns[] = $pattern;
    }

    $diagnostics = [
        'compatible_room_count' => count($compatibleRooms),
        'pattern_count' => count($patterns),
        'policy_pattern_count' => count($policyPatterns),
        'slot_checks' => 0
    ];

    $candidates = [];
    foreach ($policyPatterns as $pattern) {
        $meetingMinutes = (int)($pattern['meeting_minutes'] ?? 0);
        if ($meetingMinutes <= 0) {
            continue;
        }

        $window = resolve_component_window($scheduleType, $pattern['days'], $policy);
        if ($window === null) {
            continue;
        }

        $windowStart = time_to_minutes($window['start']);
        $windowEnd = time_to_minutes($window['end']);
        if ($windowStart === null || $windowEnd === null || ($windowStart + $meetingMinutes) > $windowEnd) {
            continue;
        }

        foreach ($compatibleRooms as $room) {
            for ($start = $windowStart; ($start + $meetingMinutes) <= $windowEnd; $start += 30) {
                $diagnostics['slot_checks']++;

                $candidate = [
                    'schedule_type' => $scheduleType,
                    'room_id' => (int)$room['room_id'],
                    'room_label' => (string)$room['label'],
                    'days' => $pattern['days'],
                    'days_label' => days_to_label($pattern['days']),
                    'time_start' => minutes_to_time($start),
                    'time_end' => minutes_to_time($start + $meetingMinutes),
                    'time_label' => minutes_to_label($start) . ' - ' . minutes_to_label($start + $meetingMinutes),
                    'pattern_label' => (string)$pattern['label']
                ];

                if (synk_schedule_policy_blocked_time_overlap($candidate['time_start'], $candidate['time_end'], $policy) !== null) {
                    continue;
                }

                if (candidate_has_conflict($candidate, $occupancyByDay, (int)$offering['section_id'])) {
                    continue;
                }

                $candidate['score'] = (int)$pattern['weight']
                    + room_fit_score($room, $scheduleType)
                    + time_score($candidate);
                $candidates[] = $candidate;
            }
        }
    }

    usort($candidates, function (array $left, array $right): int {
        if ((int)$left['score'] === (int)$right['score']) {
            if ((string)$left['time_start'] === (string)$right['time_start']) {
                return strcmp((string)$left['room_label'], (string)$right['room_label']);
            }

            return strcmp((string)$left['time_start'], (string)$right['time_start']);
        }

        return (int)$right['score'] <=> (int)$left['score'];
    });

    $selected = [];
    $seen = [];
    foreach ($candidates as $candidate) {
        $signature = implode('-', $candidate['days']) . '|' . $candidate['time_start'] . '|' . $candidate['room_id'];
        if (isset($seen[$signature])) {
            continue;
        }

        $seen[$signature] = true;
        $selected[] = $candidate;

        if (count($selected) >= 16) {
            break;
        }
    }

    return [
        'candidates' => $selected,
        'diagnostics' => $diagnostics,
        'reason' => empty($selected)
            ? build_component_failure_reason($scheduleType, $weeklyMinutes, $diagnostics)
            : ''
    ];
}

function choose_offering_draft(array $offering, array $rooms, array &$occupancyByDay, array $policy): array
{
    $required = synk_required_minutes_by_type(
        (float)$offering['lec_units'],
        (float)$offering['lab_units'],
        (float)$offering['total_units']
    );
    $lectureMinutes = (int)($required['LEC'] ?? 0);
    $labMinutes = (int)($required['LAB'] ?? 0);

    $selectedBlocks = [];

    if ($labMinutes > 0) {
        $labResult = generate_component_candidates('LAB', $labMinutes, $offering, $occupancyByDay, $rooms, $policy);
        if (empty($labResult['candidates'])) {
            return [
                'ok' => false,
                'reason' => $labResult['reason']
            ];
        }

        $bestCombo = null;
        $lectureFailure = '';
        foreach ($labResult['candidates'] as $labCandidate) {
            $localOccupancy = $occupancyByDay;
            add_block_to_occupancy($localOccupancy, $labCandidate, $offering);

            if ($lectureMinutes <= 0) {
                $bestCombo = [
                    'score' => (int)$labCandidate['score'],
                    'blocks' => [$labCandidate]
                ];
                break;
            }

            $lectureResult = generate_component_candidates('LEC', $lectureMinutes, $offering, $localOccupancy, $rooms, $policy);
            if (empty($lectureResult['candidates'])) {
                if ($lectureFailure === '') {
                    $lectureFailure = $lectureResult['reason'];
                }
                continue;
            }

            $lectureCandidate = $lectureResult['candidates'][0];
            $comboScore = (int)$labCandidate['score'] + (int)$lectureCandidate['score'];

            if ($bestCombo === null || $comboScore > (int)$bestCombo['score']) {
                $bestCombo = [
                    'score' => $comboScore,
                    'blocks' => [$lectureCandidate, $labCandidate]
                ];
            }
        }

        if ($bestCombo === null) {
            return [
                'ok' => false,
                'reason' => $lectureFailure !== ''
                    ? $lectureFailure
                    : 'No lecture slot fit beside the required laboratory placement.'
            ];
        }

        $selectedBlocks = $bestCombo['blocks'];
    } else {
        $lectureResult = generate_component_candidates('LEC', $lectureMinutes, $offering, $occupancyByDay, $rooms, $policy);
        if (empty($lectureResult['candidates'])) {
            return [
                'ok' => false,
                'reason' => $lectureResult['reason']
            ];
        }

        $selectedBlocks = [$lectureResult['candidates'][0]];
    }

    usort($selectedBlocks, function (array $left, array $right): int {
        $leftOrder = $left['schedule_type'] === 'LAB' ? 1 : 0;
        $rightOrder = $right['schedule_type'] === 'LAB' ? 1 : 0;
        if ($leftOrder !== $rightOrder) {
            return $leftOrder <=> $rightOrder;
        }

        return strcmp((string)$left['time_start'], (string)$right['time_start']);
    });

    foreach ($selectedBlocks as $block) {
        add_block_to_occupancy($occupancyByDay, $block, $offering);
    }

    return [
        'ok' => true,
        'blocks' => $selectedBlocks
    ];
}

function build_preview_item(array $offering, array $statusInfo, array $blocks): array
{
    $previewBlocks = [];
    foreach ($blocks as $block) {
        $previewBlocks[] = [
            'type' => (string)$block['schedule_type'],
            'type_label' => $block['schedule_type'] === 'LAB' ? 'Laboratory' : 'Lecture',
            'room_id' => (int)$block['room_id'],
            'room_label' => (string)$block['room_label'],
            'days' => $block['days'],
            'days_label' => (string)$block['days_label'],
            'time_start' => (string)$block['time_start'],
            'time_end' => (string)$block['time_end'],
            'time_label' => (string)$block['time_label'],
            'pattern_label' => (string)$block['pattern_label']
        ];
    }

    return [
        'offering_id' => (int)$offering['offering_id'],
        'section_name' => (string)$offering['section_name'],
        'year_level' => (int)$offering['year_level'],
        'sub_code' => (string)$offering['sub_code'],
        'sub_description' => (string)$offering['sub_description'],
        'lec_units' => (float)$offering['lec_units'],
        'lab_units' => (float)$offering['lab_units'],
        'total_units' => (float)$offering['total_units'],
        'current_status_key' => (string)$statusInfo['status_key'],
        'current_status_label' => (string)$statusInfo['status_label'],
        'blocks' => $previewBlocks
    ];
}

function load_offering_map_for_apply(mysqli $conn, int $programId, int $ayId, int $semester, int $collegeId): array
{
    $rows = load_target_offerings($conn, $programId, $ayId, $semester, $collegeId);
    $map = [];
    foreach ($rows as $row) {
        $map[(int)$row['offering_id']] = $row;
    }

    return $map;
}

function validate_room_map_for_block(array $roomMap, int $roomId, string $scheduleType): ?string
{
    if (!isset($roomMap[$roomId])) {
        return 'Selected room is not available for this term.';
    }

    $room = $roomMap[$roomId];
    if (!room_type_allows_schedule($room['room_type'] ?? 'lecture', $scheduleType)) {
        $label = trim((string)($room['label'] ?? 'Selected room'));
        $typeLabel = $scheduleType === 'LAB' ? 'laboratory' : 'lecture';
        return "{$label} is not compatible with {$typeLabel} scheduling.";
    }

    return null;
}

function normalize_apply_blocks($blocksRaw): array
{
    if (is_string($blocksRaw) && trim($blocksRaw) !== '') {
        $decoded = json_decode($blocksRaw, true);
        $blocksRaw = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($blocksRaw)) {
        return [];
    }

    $blocks = [];
    foreach ($blocksRaw as $raw) {
        if (!is_array($raw)) {
            continue;
        }

        $type = strtoupper(trim((string)($raw['type'] ?? '')));
        if (!in_array($type, ['LEC', 'LAB'], true)) {
            continue;
        }

        $timeStart = normalize_time_input($raw['time_start'] ?? '');
        $timeEnd = normalize_time_input($raw['time_end'] ?? '');
        $days = normalize_days_array($raw['days'] ?? []);

        if ($timeStart === null || $timeEnd === null || empty($days)) {
            continue;
        }

        $blocks[] = [
            'schedule_type' => $type,
            'room_id' => (int)($raw['room_id'] ?? 0),
            'days' => $days,
            'time_start' => $timeStart,
            'time_end' => $timeEnd
        ];
    }

    return $blocks;
}

function validate_apply_blocks(
    array $offering,
    array $blocks,
    array $roomMap,
    array $policy,
    array $occupancyByDay
): array {
    $allowedTypes = ((float)$offering['lab_units'] > 0) ? ['LEC', 'LAB'] : ['LEC'];
    $requiredMinutes = synk_required_minutes_by_type(
        (float)$offering['lec_units'],
        (float)$offering['lab_units'],
        (float)$offering['total_units']
    );

    if (count($blocks) !== count($allowedTypes)) {
        return [
            'ok' => false,
            'reason' => 'The draft does not match the required lecture/laboratory block count for this subject.'
        ];
    }

    $seenTypes = [];
    foreach ($blocks as $block) {
        if (!in_array($block['schedule_type'], $allowedTypes, true)) {
            return [
                'ok' => false,
                'reason' => 'The draft includes an unsupported schedule type for this subject.'
            ];
        }

        if (isset($seenTypes[$block['schedule_type']])) {
            return [
                'ok' => false,
                'reason' => 'The draft contains duplicate lecture or laboratory blocks.'
            ];
        }

        $seenTypes[$block['schedule_type']] = true;

        if ($block['room_id'] <= 0) {
            return [
                'ok' => false,
                'reason' => 'A draft block is missing its room assignment.'
            ];
        }

        if ($block['time_end'] <= $block['time_start']) {
            return [
                'ok' => false,
                'reason' => 'A draft block ends earlier than it starts.'
            ];
        }

        $roomError = validate_room_map_for_block($roomMap, (int)$block['room_id'], (string)$block['schedule_type']);
        if ($roomError !== null) {
            return [
                'ok' => false,
                'reason' => $roomError
            ];
        }

        $disallowedDays = synk_schedule_policy_disallowed_days($block['days'], $policy);
        if (!empty($disallowedDays)) {
            return [
                'ok' => false,
                'reason' => 'The draft uses blocked day(s): ' . implode(', ', $disallowedDays) . '.'
            ];
        }

        if (!synk_schedule_policy_is_within_window($block['time_start'], $block['time_end'], $policy)) {
            return [
                'ok' => false,
                'reason' => 'The draft falls outside the supported scheduling window.'
            ];
        }

        if (synk_schedule_policy_blocked_time_overlap($block['time_start'], $block['time_end'], $policy) !== null) {
            return [
                'ok' => false,
                'reason' => 'The draft overlaps a blocked time range from Academic Settings.'
            ];
        }

        $weeklyMinutes = synk_schedule_weekly_minutes($block['days'], $block['time_start'], $block['time_end']);
        $requiredForType = (int)($requiredMinutes[$block['schedule_type']] ?? 0);
        if ($weeklyMinutes !== $requiredForType) {
            return [
                'ok' => false,
                'reason' => 'The draft no longer matches the required weekly ' .
                    strtolower($block['schedule_type'] === 'LAB' ? 'laboratory' : 'lecture') .
                    ' hours for this subject.'
            ];
        }
    }

    for ($i = 0; $i < count($blocks); $i++) {
        for ($j = $i + 1; $j < count($blocks); $j++) {
            if (
                days_overlap($blocks[$i]['days'], $blocks[$j]['days']) &&
                synk_schedule_minutes_overlap(
                    $blocks[$i]['time_start'],
                    $blocks[$i]['time_end'],
                    $blocks[$j]['time_start'],
                    $blocks[$j]['time_end']
                )
            ) {
                return [
                    'ok' => false,
                    'reason' => 'The lecture and laboratory draft blocks overlap.'
                ];
            }
        }
    }

    foreach ($blocks as $block) {
        if (candidate_has_conflict($block, $occupancyByDay, (int)$offering['section_id'])) {
            $typeLabel = $block['schedule_type'] === 'LAB' ? 'laboratory' : 'lecture';
            return [
                'ok' => false,
                'reason' => "The {$typeLabel} draft now conflicts with another section or room assignment."
            ];
        }
    }

    return [
        'ok' => true,
        'blocks' => $blocks
    ];
}

function replace_offering_schedule(mysqli $conn, array $offering, array $blocks, int $userId): bool
{
    $groupId = count($blocks) > 1 ? random_int(100000, 2147483647) : null;
    $offeringId = (int)$offering['offering_id'];

    $deleteStmt = $conn->prepare("
        DELETE FROM tbl_class_schedule
        WHERE offering_id = ?
    ");
    if (!$deleteStmt) {
        return false;
    }

    $deleteStmt->bind_param('i', $offeringId);
    $deleteOk = $deleteStmt->execute();
    $deleteStmt->close();
    if (!$deleteOk) {
        return false;
    }

    foreach ($blocks as $block) {
        $daysJson = json_encode($block['days']);
        if ($groupId === null) {
            $stmt = $conn->prepare("
                INSERT INTO tbl_class_schedule
                (offering_id, schedule_type, room_id, days_json, time_start, time_end, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) {
                return false;
            }

            $stmt->bind_param(
                'isisssi',
                $offeringId,
                $block['schedule_type'],
                $block['room_id'],
                $daysJson,
                $block['time_start'],
                $block['time_end'],
                $userId
            );
        } else {
            $stmt = $conn->prepare("
                INSERT INTO tbl_class_schedule
                (offering_id, schedule_type, schedule_group_id, room_id, days_json, time_start, time_end, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt) {
                return false;
            }

            $stmt->bind_param(
                'isiisssi',
                $offeringId,
                $block['schedule_type'],
                $groupId,
                $block['room_id'],
                $daysJson,
                $block['time_start'],
                $block['time_end'],
                $userId
            );
        }

        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            return false;
        }
    }

    $updateStmt = $conn->prepare("
        UPDATE tbl_prospectus_offering
        SET status = 'active'
        WHERE offering_id = ?
          AND (status IS NULL OR status != 'locked')
    ");
    if (!$updateStmt) {
        return false;
    }

    $updateStmt->bind_param('i', $offeringId);
    $updateOk = $updateStmt->execute();
    $updateStmt->close();

    return $updateOk;
}

if ($previewRequested) {
    if (!room_access_table_exists($conn)) {
        respond('error', 'Room access table is missing.');
    }

    if (!section_curriculum_table_exists($conn)) {
        respond('error', 'Create tbl_section_curriculum first before running auto draft.');
    }

    $rooms = load_accessible_rooms($conn, $college_id, $ay_id, $semester);
    $offerings = load_target_offerings($conn, $program_id, $ay_id, $semester, $college_id);
    $offeringIds = array_map(function (array $offering): int {
        return (int)$offering['offering_id'];
    }, $offerings);
    $existingRowsByOffering = load_existing_schedule_rows_by_offering($conn, $offeringIds);
    $workloadMap = load_workload_faculty_map($conn, $offeringIds, $ay_id, $semester);

    $targets = [];
    $skippedComplete = 0;
    foreach ($offerings as $offering) {
        $rows = $existingRowsByOffering[(int)$offering['offering_id']] ?? [];
        $statusInfo = offering_schedule_status($offering, $rows);
        $offering['current_status_key'] = $statusInfo['status_key'];
        $offering['current_status_label'] = $statusInfo['status_label'];

        if ($statusInfo['status_key'] === 'scheduled') {
            $skippedComplete++;
            continue;
        }

        $targets[] = $offering;
    }

    usort($targets, function (array $left, array $right): int {
        $leftLab = (float)$left['lab_units'] > 0 ? 1 : 0;
        $rightLab = (float)$right['lab_units'] > 0 ? 1 : 0;
        if ($leftLab !== $rightLab) {
            return $rightLab <=> $leftLab;
        }

        $leftMinutes = array_sum(synk_required_minutes_by_type(
            (float)$left['lec_units'],
            (float)$left['lab_units'],
            (float)$left['total_units']
        ));
        $rightMinutes = array_sum(synk_required_minutes_by_type(
            (float)$right['lec_units'],
            (float)$right['lab_units'],
            (float)$right['total_units']
        ));
        if ($leftMinutes !== $rightMinutes) {
            return $rightMinutes <=> $leftMinutes;
        }

        if ((int)$left['year_level'] !== (int)$right['year_level']) {
            return (int)$left['year_level'] <=> (int)$right['year_level'];
        }

        $sectionCompare = strcmp((string)$left['section_name'], (string)$right['section_name']);
        if ($sectionCompare !== 0) {
            return $sectionCompare;
        }

        return strcmp((string)$left['sub_code'], (string)$right['sub_code']);
    });

    $targetIds = array_map(function (array $offering): int {
        return (int)$offering['offering_id'];
    }, $targets);
    $occupancyByDay = load_term_schedule_snapshot_by_day($conn, $ay_id, $semester, $targetIds);

    $ready = [];
    $manual = [];
    foreach ($targets as $offering) {
        $rows = $existingRowsByOffering[(int)$offering['offering_id']] ?? [];
        $statusInfo = offering_schedule_status($offering, $rows);
        $facultyNames = $workloadMap[(int)$offering['offering_id']] ?? [];

        if ($offering['offering_status'] === 'locked') {
            $manual[] = [
                'offering_id' => (int)$offering['offering_id'],
                'section_name' => (string)$offering['section_name'],
                'year_level' => (int)$offering['year_level'],
                'sub_code' => (string)$offering['sub_code'],
                'sub_description' => (string)$offering['sub_description'],
                'current_status_key' => (string)$statusInfo['status_key'],
                'current_status_label' => (string)$statusInfo['status_label'],
                'reason' => 'This offering is locked and cannot be changed automatically.'
            ];
            continue;
        }

        if (!empty($facultyNames)) {
            $manual[] = [
                'offering_id' => (int)$offering['offering_id'],
                'section_name' => (string)$offering['section_name'],
                'year_level' => (int)$offering['year_level'],
                'sub_code' => (string)$offering['sub_code'],
                'sub_description' => (string)$offering['sub_description'],
                'current_status_key' => (string)$statusInfo['status_key'],
                'current_status_label' => (string)$statusInfo['status_label'],
                'reason' => 'This offering is already assigned in Faculty Workload to ' . implode(', ', $facultyNames) . '.'
            ];
            continue;
        }

        if (empty($rooms)) {
            $manual[] = [
                'offering_id' => (int)$offering['offering_id'],
                'section_name' => (string)$offering['section_name'],
                'year_level' => (int)$offering['year_level'],
                'sub_code' => (string)$offering['sub_code'],
                'sub_description' => (string)$offering['sub_description'],
                'current_status_key' => (string)$statusInfo['status_key'],
                'current_status_label' => (string)$statusInfo['status_label'],
                'reason' => 'No accessible rooms are available for this term.'
            ];
            continue;
        }

        $draftResult = choose_offering_draft($offering, $rooms, $occupancyByDay, $schedulePolicy);
        if (!$draftResult['ok']) {
            $manual[] = [
                'offering_id' => (int)$offering['offering_id'],
                'section_name' => (string)$offering['section_name'],
                'year_level' => (int)$offering['year_level'],
                'sub_code' => (string)$offering['sub_code'],
                'sub_description' => (string)$offering['sub_description'],
                'current_status_key' => (string)$statusInfo['status_key'],
                'current_status_label' => (string)$statusInfo['status_label'],
                'reason' => (string)$draftResult['reason']
            ];
            continue;
        }

        $ready[] = build_preview_item($offering, $statusInfo, $draftResult['blocks']);
    }

    respond('ok', 'Auto-draft preview generated.', [
        'summary' => [
            'target_count' => count($targets),
            'ready_count' => count($ready),
            'manual_count' => count($manual),
            'skipped_complete_count' => $skippedComplete
        ],
        'rules' => [
            'Lecture hours follow the prospectus load, preferring MWF then MW then TTh when the policy blocks the standard spread.',
            'Laboratory hours use a Tuesday/Thursday split so standard 3-hour labs become 1.5 hours on Tuesday and 1.5 hours on Thursday.',
            'All placements respect the effective ' . ($schedulePolicy['source_label'] ?? 'scheduling policy') . ' window of ' . ($schedulePolicy['window_label'] ?? '') . '.'
        ],
        'ready' => $ready,
        'manual' => $manual
    ]);
}

$draftItems = json_decode((string)($_POST['draft_items_json'] ?? ''), true);
if (!is_array($draftItems)) {
    respond('error', 'Invalid draft payload.');
}

$applyOrder = [];
foreach ($draftItems as $item) {
    if (!is_array($item)) {
        continue;
    }

    $offeringId = (int)($item['offering_id'] ?? 0);
    if ($offeringId <= 0 || !isset($item['blocks'])) {
        continue;
    }

    $applyOrder[] = [
        'offering_id' => $offeringId,
        'blocks' => $item['blocks']
    ];
}

if (empty($applyOrder)) {
    respond('error', 'There are no draft schedules to apply.');
}

if (!room_access_table_exists($conn)) {
    respond('error', 'Room access table is missing.');
}

if (!section_curriculum_table_exists($conn)) {
    respond('error', 'Create tbl_section_curriculum first before applying auto draft.');
}

$offeringMap = load_offering_map_for_apply($conn, $program_id, $ay_id, $semester, $college_id);
$rooms = load_accessible_rooms($conn, $college_id, $ay_id, $semester);
$roomMap = [];
foreach ($rooms as $room) {
    $roomMap[(int)$room['room_id']] = $room;
}

$applyIds = array_map(function (array $item): int {
    return (int)$item['offering_id'];
}, $applyOrder);
$workloadMap = load_workload_faculty_map($conn, $applyIds, $ay_id, $semester);
$occupancyByDay = load_term_schedule_snapshot_by_day($conn, $ay_id, $semester, $applyIds);

$applied = [];
$failed = [];
foreach ($applyOrder as $item) {
    $offeringId = (int)$item['offering_id'];
    if (!isset($offeringMap[$offeringId])) {
        $failed[] = [
            'offering_id' => $offeringId,
            'reason' => 'This offering is no longer available in the selected program term.'
        ];
        continue;
    }

    $offering = $offeringMap[$offeringId];

    if ($offering['offering_status'] === 'locked') {
        $failed[] = [
            'offering_id' => $offeringId,
            'sub_code' => (string)$offering['sub_code'],
            'section_name' => (string)$offering['section_name'],
            'reason' => 'This offering is locked and cannot be changed automatically.'
        ];
        continue;
    }

    $facultyNames = $workloadMap[$offeringId] ?? [];
    if (!empty($facultyNames)) {
        $failed[] = [
            'offering_id' => $offeringId,
            'sub_code' => (string)$offering['sub_code'],
            'section_name' => (string)$offering['section_name'],
            'reason' => 'This offering is already assigned in Faculty Workload to ' . implode(', ', $facultyNames) . '.'
        ];
        continue;
    }

    // Keep newly supported pending assignments intact when applying an automatic draft.
    if (synk_table_exists($conn,'tbl_faculty_need_workload_sched')) {
        $pendingNeed=$conn->query("SELECT 1 FROM tbl_class_schedule cs JOIN tbl_faculty_need_workload_sched nw ON nw.schedule_id=cs.schedule_id
            WHERE cs.offering_id={$offeringId} AND cs.schedule_status<>'scheduled' AND nw.ay_id={$ay_id} AND nw.semester={$semester} LIMIT 1");
        if ($pendingNeed && $pendingNeed->num_rows) {
            $failed[]=['offering_id'=>$offeringId,'sub_code'=>(string)$offering['sub_code'],'section_name'=>(string)$offering['section_name'],
                'reason'=>'This TBA offering is assigned to a faculty need. Arrange its existing blocks in Class Scheduling.'];
            continue;
        }
    }

    $blocks = normalize_apply_blocks($item['blocks']);
    $validation = validate_apply_blocks($offering, $blocks, $roomMap, $schedulePolicy, $occupancyByDay);
    if (!$validation['ok']) {
        $failed[] = [
            'offering_id' => $offeringId,
            'sub_code' => (string)$offering['sub_code'],
            'section_name' => (string)$offering['section_name'],
            'reason' => (string)$validation['reason']
        ];
        continue;
    }

    $conn->begin_transaction();
    try {
        if (!replace_offering_schedule($conn, $offering, $validation['blocks'], (int)$_SESSION['user_id'])) {
            throw new RuntimeException('Failed to save draft blocks.');
        }

        $conn->commit();

        foreach ($validation['blocks'] as $block) {
            add_block_to_occupancy($occupancyByDay, $block, $offering);
        }

        $applied[] = [
            'offering_id' => $offeringId,
            'sub_code' => (string)$offering['sub_code'],
            'section_name' => (string)$offering['section_name']
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        $failed[] = [
            'offering_id' => $offeringId,
            'sub_code' => (string)$offering['sub_code'],
            'section_name' => (string)$offering['section_name'],
            'reason' => 'Failed to save the generated draft for this offering.'
        ];
    }
}

$status = 'ok';
if (!empty($failed) && !empty($applied)) {
    $status = 'partial';
} elseif (!empty($failed) && empty($applied)) {
    $status = 'error';
}

respond($status, 'Auto-draft apply finished.', [
    'applied_count' => count($applied),
    'failed_count' => count($failed),
    'applied' => $applied,
    'failed' => $failed
]);
