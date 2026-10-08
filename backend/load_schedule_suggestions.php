<?php
session_start();
include 'db.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schedule_block_helper.php';
require_once __DIR__ . '/academic_schedule_policy_helper.php';

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
$offering_id = (int)($_POST['offering_id'] ?? 0);
$target_type = strtoupper(trim((string)($_POST['target_type'] ?? '')));
$target_key = trim((string)($_POST['target_key'] ?? ''));

if ($college_id <= 0 || $offering_id <= 0) {
    respond('error', 'Missing scheduling context.');
}

if ($target_type !== '' && !in_array($target_type, ['LEC', 'LAB'], true)) {
    respond('error', 'Unsupported suggestion target.');
}

$schedulePolicy = synk_fetch_effective_schedule_policy($conn, $college_id);

function day_order() {
    return ['M', 'T', 'W', 'Th', 'F', 'S'];
}

function normalize_day_token($day) {
    $token = strtoupper(trim((string)$day));
    if ($token === 'TH') {
        return 'Th';
    }
    return in_array($token, day_order(), true) ? $token : '';
}

function normalize_days_array($days) {
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

function days_overlap($a, $b) {
    return is_array($a) && is_array($b) && count(array_intersect($a, $b)) > 0;
}

function time_to_minutes($time) {
    $time = trim((string)$time);
    if ($time === '') {
        return null;
    }

    $parts = explode(':', $time);
    if (count($parts) < 2) {
        return null;
    }

    return ((int)$parts[0] * 60) + (int)$parts[1];
}

function time_overlap($startA, $endA, $startB, $endB) {
    $startA = is_numeric($startA) ? (int)$startA : time_to_minutes($startA);
    $endA = is_numeric($endA) ? (int)$endA : time_to_minutes($endA);
    $startB = is_numeric($startB) ? (int)$startB : time_to_minutes($startB);
    $endB = is_numeric($endB) ? (int)$endB : time_to_minutes($endB);

    if ($startA === null || $endA === null || $startB === null || $endB === null) {
        return false;
    }

    return ($startA < $endB) && ($endA > $startB);
}

function minutes_to_time($minutes) {
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return sprintf('%02d:%02d:00', $hours, $mins);
}

function minutes_to_input_time($minutes) {
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return sprintf('%02d:%02d', $hours, $mins);
}

function minutes_to_label($minutes) {
    return date('h:i A', strtotime(minutes_to_time($minutes)));
}

function days_to_label($days) {
    return implode('', normalize_days_array($days));
}

function ceil_to_slot($minutes, $slot = 30) {
    $minutes = (int)ceil((float)$minutes);
    return (int)(ceil(max($slot, $minutes) / $slot) * $slot);
}

function room_access_table_exists($conn) {
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    $q = $conn->query("SHOW TABLES LIKE 'tbl_room_college_access'");
    $exists = $q && $q->num_rows > 0;
    return $exists;
}

function normalize_room_type($type) {
    $value = strtolower(trim((string)$type));
    return in_array($value, ['lecture', 'laboratory', 'lec_lab'], true) ? $value : 'lecture';
}

function room_type_allows_schedule($roomType, $scheduleType) {
    $roomType = normalize_room_type($roomType);
    if ($scheduleType === 'LAB') {
        return in_array($roomType, ['laboratory', 'lec_lab'], true);
    }
    return in_array($roomType, ['lecture', 'lec_lab'], true);
}

function build_room_label(array $row) {
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

function load_offering_context($conn, $offeringId, $collegeId) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $sql = "
        SELECT
            o.offering_id,
            o.section_id,
            o.ay_id,
            o.semester,
            sec.section_name,
            sm.sub_code,
            sm.sub_description,
            ps.lec_units,
            ps.lab_units,
            ps.total_units
        FROM tbl_prospectus_offering o
        {$liveOfferingJoins}
        INNER JOIN tbl_program p ON p.program_id = o.program_id
        INNER JOIN tbl_subject_masterlist sm ON sm.sub_id = ps.sub_id
        WHERE o.offering_id = ?
          AND p.college_id = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ii', $offeringId, $collegeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function load_accessible_rooms($conn, $collegeId, $ayId, $semester) {
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

function load_term_schedule_snapshot_by_day($conn, $ayId, $semester, $excludeOfferingId) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $sql = "
        SELECT
            cs.schedule_id,
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
          AND cs.offering_id <> ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('iii', $ayId, $semester, $excludeOfferingId);
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
            'schedule_id' => (int)$row['schedule_id'],
            'offering_id' => (int)$row['offering_id'],
            'room_id' => (int)$row['room_id'],
            'schedule_type' => strtoupper(trim((string)($row['schedule_type'] ?? 'LEC'))),
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

function load_workload_faculties_for_offering_suggestions($conn, $offering_id, $ay_id, $semester) {
    $sql = "
        SELECT
            fw.faculty_id,
            CONCAT(
                f.last_name,
                ', ',
                f.first_name,
                CASE
                    WHEN COALESCE(f.ext_name, '') <> '' THEN CONCAT(' ', f.ext_name)
                    ELSE ''
                END
            ) AS faculty_name,
            COUNT(*) AS assigned_rows
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_faculty f
            ON f.faculty_id = fw.faculty_id
        WHERE cs.offering_id = ?
          AND fw.ay_id = ?
          AND fw.semester = ?
        GROUP BY fw.faculty_id, faculty_name
        ORDER BY faculty_name
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("iii", $offering_id, $ay_id, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $faculties = [];
    while ($row = $res->fetch_assoc()) {
        $faculties[] = [
            'faculty_id' => (int)($row['faculty_id'] ?? 0),
            'faculty_name' => (string)($row['faculty_name'] ?? ''),
            'assigned_rows' => (int)($row['assigned_rows'] ?? 0)
        ];
    }

    $stmt->close();
    return $faculties;
}

function load_other_faculty_workload_rows_suggestions($conn, $faculty_id, $ay_id, $semester, $offering_id) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $sql = "
        SELECT
            cs.schedule_id,
            cs.schedule_type,
            cs.days_json,
            cs.time_start,
            cs.time_end,
            sm.sub_code,
            sec.section_name
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        WHERE fw.faculty_id = ?
          AND fw.ay_id = ?
          AND fw.semester = ?
          AND cs.offering_id <> ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("iiii", $faculty_id, $ay_id, $semester, $offering_id);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $row['days'] = normalize_days_array(json_decode((string)($row['days_json'] ?? '[]'), true));
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function load_faculty_snapshot_by_day($conn, array $context, $offeringId) {
    $faculties = load_workload_faculties_for_offering_suggestions(
        $conn,
        (int)$offeringId,
        (int)($context['ay_id'] ?? 0),
        (int)($context['semester'] ?? 0)
    );

    $snapshotByDay = [];
    foreach (day_order() as $day) {
        $snapshotByDay[$day] = [];
    }

    if (empty($faculties)) {
        return [
            'has_assignments' => false,
            'faculty_names' => [],
            'by_day' => $snapshotByDay
        ];
    }

    $facultyNames = [];
    foreach ($faculties as $faculty) {
        $facultyName = trim((string)($faculty['faculty_name'] ?? ''));
        if ($facultyName !== '') {
            $facultyNames[] = $facultyName;
        }

        $rows = load_other_faculty_workload_rows_suggestions(
            $conn,
            (int)($faculty['faculty_id'] ?? 0),
            (int)($context['ay_id'] ?? 0),
            (int)($context['semester'] ?? 0),
            (int)$offeringId
        );

        foreach ($rows as $row) {
            $days = normalize_days_array($row['days'] ?? []);
            if (empty($days)) {
                continue;
            }

            $item = [
                'schedule_id' => (int)($row['schedule_id'] ?? 0),
                'schedule_type' => strtoupper(trim((string)($row['schedule_type'] ?? 'LEC'))),
                'time_start' => (string)($row['time_start'] ?? ''),
                'time_end' => (string)($row['time_end'] ?? ''),
                'sub_code' => (string)($row['sub_code'] ?? ''),
                'section_name' => (string)($row['section_name'] ?? ''),
                'faculty_name' => $facultyName,
                'days' => $days
            ];

            foreach ($days as $day) {
                $snapshotByDay[$day][] = $item;
            }
        }
    }

    return [
        'has_assignments' => true,
        'faculty_names' => array_values(array_unique(array_filter($facultyNames))),
        'by_day' => $snapshotByDay
    ];
}

function normalize_draft_schedules($draftsRaw) {
    if (is_string($draftsRaw) && trim($draftsRaw) !== '') {
        $decoded = json_decode($draftsRaw, true);
        $draftsRaw = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($draftsRaw)) {
        return [];
    }

    $normalized = [];
    $rawList = $draftsRaw;

    $isAssoc = array_keys($draftsRaw) !== range(0, count($draftsRaw) - 1);
    if ($isAssoc && (isset($draftsRaw['LEC']) || isset($draftsRaw['LAB']))) {
        $rawList = [];
        foreach (['LEC', 'LAB'] as $legacyType) {
            if (isset($draftsRaw[$legacyType]) && is_array($draftsRaw[$legacyType])) {
                $rawList[] = array_merge($draftsRaw[$legacyType], ['type' => $legacyType]);
            }
        }
    }

    foreach ($rawList as $index => $raw) {
        if (!is_array($raw)) {
            continue;
        }

        $type = strtoupper(trim((string)($raw['type'] ?? '')));
        if (!in_array($type, ['LEC', 'LAB'], true)) {
            continue;
        }

        $days = [];
        if (isset($raw['days']) && is_array($raw['days'])) {
            $days = normalize_days_array($raw['days']);
        } elseif (!empty($raw['days_json'])) {
            $days = normalize_days_array(json_decode((string)$raw['days_json'], true));
        }

        $normalized[] = [
            'block_key' => trim((string)($raw['block_key'] ?? $raw['schedule_id'] ?? "{$type}-{$index}")),
            'schedule_id' => (int)($raw['schedule_id'] ?? 0),
            'type' => $type,
            'room_id' => (int)($raw['room_id'] ?? 0),
            'time_start' => trim((string)($raw['time_start'] ?? '')),
            'time_end' => trim((string)($raw['time_end'] ?? '')),
            'days' => $days
        ];
    }

    return $normalized;
}

function is_complete_draft($draft) {
    return is_array($draft)
        && !empty($draft['type'])
        && !empty($draft['time_start'])
        && !empty($draft['time_end'])
        && !empty($draft['days'])
        && is_array($draft['days'])
        && $draft['time_end'] > $draft['time_start'];
}

function desired_weekly_minutes($targetType, $targetDraft, $allDrafts, $context) {
    if (is_complete_draft($targetDraft)) {
        return synk_schedule_weekly_minutes($targetDraft['days'], $targetDraft['time_start'], $targetDraft['time_end']);
    }

    $required = synk_required_minutes_by_type(
        (float)$context['lec_units'],
        (float)$context['lab_units'],
        (float)$context['total_units']
    );

    $scheduledSameType = 0;
    foreach ($allDrafts as $draft) {
        if (!is_complete_draft($draft)) {
            continue;
        }
        if (($draft['block_key'] ?? '') === ($targetDraft['block_key'] ?? '')) {
            continue;
        }
        if (($draft['type'] ?? '') !== $targetType) {
            continue;
        }

        $scheduledSameType += synk_schedule_weekly_minutes($draft['days'], $draft['time_start'], $draft['time_end']);
    }

    $remaining = max(60, (int)($required[$targetType] ?? 0) - $scheduledSameType);
    return ceil_to_slot($remaining, 30);
}

function collect_target_draft(array $drafts, string $targetType, string $targetKey = ''): array {
    foreach ($drafts as $draft) {
        if (($draft['block_key'] ?? '') === $targetKey && ($draft['type'] ?? '') === $targetType) {
            return $draft;
        }
    }

    foreach ($drafts as $draft) {
        if (($draft['type'] ?? '') === $targetType) {
            return $draft;
        }
    }

    return [
        'block_key' => $targetKey !== '' ? $targetKey : $targetType,
        'type' => $targetType
    ];
}

function add_pattern(&$patterns, $days, $weeklyMinutes, $weight, $label) {
    $days = normalize_days_array($days);
    $meetingCount = count($days);
    if ($meetingCount <= 0) {
        return;
    }

    $perMeetingMinutes = ceil_to_slot($weeklyMinutes / $meetingCount, 30);
    if ($perMeetingMinutes <= 0) {
        return;
    }

    $key = implode('-', $days) . '|' . $perMeetingMinutes;
    if (isset($patterns[$key])) {
        if ($weight > $patterns[$key]['weight']) {
            $patterns[$key]['weight'] = $weight;
            $patterns[$key]['label'] = $label;
        }
        return;
    }

    $patterns[$key] = [
        'days' => $days,
        'meeting_minutes' => $perMeetingMinutes,
        'weight' => $weight,
        'label' => $label
    ];
}

function build_schedule_patterns($scheduleType, $weeklyMinutes) {
    $scheduleType = strtoupper(trim((string)$scheduleType));
    $weeklyMinutes = max(60, ceil_to_slot($weeklyMinutes, 30));
    $patterns = [];

    if ($scheduleType === 'LAB') {
        add_pattern($patterns, ['T', 'Th'], $weeklyMinutes, 28, 'TTh lab split');
        add_pattern($patterns, ['M', 'W'], $weeklyMinutes, 26, 'MW lab split');
        add_pattern($patterns, ['F', 'S'], $weeklyMinutes, 18, 'FS lab split');
        add_pattern($patterns, ['S'], $weeklyMinutes, 14, 'Saturday lab block');
        add_pattern($patterns, ['F'], $weeklyMinutes, 12, 'Friday lab block');
        add_pattern($patterns, ['T'], $weeklyMinutes, 10, 'Tuesday lab block');
        add_pattern($patterns, ['Th'], $weeklyMinutes, 10, 'Thursday lab block');
        return array_values($patterns);
    }

    add_pattern($patterns, ['M', 'W'], $weeklyMinutes, 30, 'MW lecture split');
    add_pattern($patterns, ['T', 'Th'], $weeklyMinutes, 30, 'TTh lecture split');
    add_pattern($patterns, ['M', 'W', 'F'], $weeklyMinutes, 24, 'MWF lecture spread');
    add_pattern($patterns, ['T', 'Th', 'S'], $weeklyMinutes, 18, 'TThS lecture spread');
    add_pattern($patterns, ['M'], $weeklyMinutes, 14, 'Monday lecture block');
    add_pattern($patterns, ['T'], $weeklyMinutes, 14, 'Tuesday lecture block');
    add_pattern($patterns, ['W'], $weeklyMinutes, 14, 'Wednesday lecture block');
    add_pattern($patterns, ['Th'], $weeklyMinutes, 14, 'Thursday lecture block');
    add_pattern($patterns, ['F'], $weeklyMinutes, 12, 'Friday lecture block');
    add_pattern($patterns, ['S'], $weeklyMinutes, 8, 'Saturday lecture block');

    return array_values($patterns);
}

function build_preferred_patterns($scheduleType, $weeklyMinutes, array $targetDraft) {
    $patterns = [];
    $preferredDays = normalize_days_array($targetDraft['days'] ?? []);
    if (!empty($preferredDays)) {
        add_pattern($patterns, $preferredDays, $weeklyMinutes, 40, 'Selected day pattern');
    }

    return array_values($patterns);
}

function analyze_candidate_conflicts($candidate, $snapshotByDay, $facultyByDay, $context, $drafts, $targetKey) {
    $analysis = [
        'draft_conflict' => false,
        'section_conflicts' => [],
        'room_conflicts' => [],
        'faculty_conflicts' => []
    ];

    foreach ($candidate['days'] as $day) {
        foreach ($snapshotByDay[$day] ?? [] as $item) {
            if (!time_overlap($candidate['time_start'], $candidate['time_end'], $item['time_start'], $item['time_end'])) {
                continue;
            }

            if ((int)$item['section_id'] === (int)$context['section_id']) {
                $key = 'section|' . (int)($item['schedule_id'] ?? 0) . '|' . $day;
                $analysis['section_conflicts'][$key] = $item;
            }

            if ((int)$item['room_id'] === (int)$candidate['room_id']) {
                $key = 'room|' . (int)($item['schedule_id'] ?? 0) . '|' . $day;
                $analysis['room_conflicts'][$key] = $item;
            }
        }

        foreach ($facultyByDay[$day] ?? [] as $item) {
            if (!time_overlap($candidate['time_start'], $candidate['time_end'], $item['time_start'], $item['time_end'])) {
                continue;
            }

            $key = 'faculty|' . (int)($item['schedule_id'] ?? 0) . '|' . (string)($item['faculty_name'] ?? '') . '|' . $day;
            $analysis['faculty_conflicts'][$key] = $item;
        }
    }

    foreach ($drafts as $draft) {
        if (!is_complete_draft($draft)) {
            continue;
        }

        if (($draft['block_key'] ?? '') === $targetKey) {
            continue;
        }

        if (
            days_overlap($candidate['days'], $draft['days']) &&
            time_overlap($candidate['time_start'], $candidate['time_end'], $draft['time_start'], $draft['time_end'])
        ) {
            $analysis['draft_conflict'] = true;
            break;
        }
    }

    $analysis['section_conflicts'] = array_values($analysis['section_conflicts']);
    $analysis['room_conflicts'] = array_values($analysis['room_conflicts']);
    $analysis['faculty_conflicts'] = array_values($analysis['faculty_conflicts']);
    $analysis['section_count'] = count($analysis['section_conflicts']);
    $analysis['room_count'] = count($analysis['room_conflicts']);
    $analysis['faculty_count'] = count($analysis['faculty_conflicts']);
    $analysis['total_live_conflicts'] =
        $analysis['section_count'] +
        $analysis['room_count'] +
        $analysis['faculty_count'];

    return $analysis;
}

function room_fit_score($room, $scheduleType) {
    $roomType = normalize_room_type($room['room_type'] ?? 'lecture');
    $score = 0;

    if ($scheduleType === 'LAB') {
        $score += $roomType === 'laboratory' ? 8 : 4;
    } else {
        $score += $roomType === 'lecture' ? 7 : 4;
    }

    $score += strtolower((string)($room['access_type'] ?? 'owner')) === 'owner' ? 3 : 1;
    return $score;
}

function companion_spacing_score($candidate, $drafts, $targetKey) {
    $score = 0;

    foreach ($drafts as $draft) {
        if (!is_complete_draft($draft) || ($draft['block_key'] ?? '') === $targetKey) {
            continue;
        }

        if (!days_overlap($candidate['days'], $draft['days'])) {
            $score += 2;
            continue;
        }

        if (time_overlap($candidate['time_start'], $candidate['time_end'], $draft['time_start'], $draft['time_end'])) {
            return -20;
        }

        $candidateStart = time_to_minutes($candidate['time_start']);
        $draftEnd = time_to_minutes($draft['time_end']);
        if ($candidateStart !== null && $draftEnd !== null) {
            $gap = abs($candidateStart - $draftEnd);
            if ($gap <= 60) {
                $score += 3;
            }
        }
    }

    return $score;
}

function selected_days_score($candidate, array $targetDraft) {
    $preferredDays = normalize_days_array($targetDraft['days'] ?? []);
    if (empty($preferredDays)) {
        return 0;
    }

    $candidateDays = normalize_days_array($candidate['days'] ?? []);
    if ($candidateDays === $preferredDays) {
        return 12;
    }

    $overlap = count(array_intersect($candidateDays, $preferredDays));
    if ($overlap > 0) {
        return 4 + $overlap;
    }

    return -2;
}

function selected_time_score($candidate, array $targetDraft) {
    $preferredStart = trim((string)($targetDraft['time_start'] ?? ''));
    $preferredEnd = trim((string)($targetDraft['time_end'] ?? ''));
    if ($preferredStart === '' || $preferredEnd === '') {
        return 0;
    }

    $candidateStart = time_to_minutes($candidate['time_start']);
    $candidateEnd = time_to_minutes($candidate['time_end']);
    $targetStartMinutes = time_to_minutes($preferredStart);
    $targetEndMinutes = time_to_minutes($preferredEnd);

    if ($candidateStart === null || $candidateEnd === null || $targetStartMinutes === null || $targetEndMinutes === null) {
        return 0;
    }

    if ($candidateStart === $targetStartMinutes && $candidateEnd === $targetEndMinutes) {
        return 12;
    }

    $totalDiff = abs($candidateStart - $targetStartMinutes) + abs($candidateEnd - $targetEndMinutes);
    if ($totalDiff <= 60) {
        return 8;
    }
    if ($totalDiff <= 120) {
        return 4;
    }
    if ($totalDiff <= 180) {
        return 1;
    }

    return -2;
}

function selected_room_score($candidate, array $targetDraft) {
    $preferredRoomId = (int)($targetDraft['room_id'] ?? 0);
    if ($preferredRoomId <= 0) {
        return 0;
    }

    return $preferredRoomId === (int)($candidate['room_id'] ?? 0) ? 8 : -1;
}

function time_score($candidate) {
    $start = time_to_minutes($candidate['time_start']);
    $end = time_to_minutes($candidate['time_end']);
    if ($start === null || $end === null) {
        return 0;
    }

    $score = 0;
    if ($start >= 450 && $start < 720) {
        $score += 2;
    }
    if ($end <= 1020) {
        $score += 2;
    }
    if (($start < 780) && ($end > 720)) {
        $score -= 6;
    }
    return $score;
}

function fit_bucket($score, array $analysis) {
    if ((int)($analysis['total_live_conflicts'] ?? 0) > 1) {
        return ['label' => 'Conflict Risk', 'class' => 'review'];
    }

    if ((int)($analysis['total_live_conflicts'] ?? 0) === 1) {
        return ['label' => 'Needs Review', 'class' => 'review'];
    }

    if ($score >= 44) {
        return ['label' => 'Best Fit', 'class' => 'best'];
    }
    if ($score >= 34) {
        return ['label' => 'Strong Fit', 'class' => 'strong'];
    }
    return ['label' => 'Valid Slot', 'class' => 'valid'];
}

function build_reasons($candidate, $room, $scheduleType, array $targetDraft, array $analysis, bool $hasFacultyAssignments) {
    $reasons = [];

    if (normalize_days_array($candidate['days'] ?? []) === normalize_days_array($targetDraft['days'] ?? [])) {
        $reasons[] = 'Matches selected days';
    }

    if (
        trim((string)($targetDraft['time_start'] ?? '')) !== '' &&
        trim((string)($targetDraft['time_end'] ?? '')) !== '' &&
        trim((string)($candidate['time_start'] ?? '')) === trim((string)($targetDraft['time_start'] ?? '')) &&
        trim((string)($candidate['time_end'] ?? '')) === trim((string)($targetDraft['time_end'] ?? ''))
    ) {
        $reasons[] = 'Matches selected time';
    }

    if ((int)($targetDraft['room_id'] ?? 0) > 0 && (int)($targetDraft['room_id'] ?? 0) === (int)($candidate['room_id'] ?? 0)) {
        $reasons[] = 'Uses selected room';
    }

    $reasons[] = (int)($analysis['room_count'] ?? 0) === 0
        ? 'No room conflict'
        : 'Room overlaps ' . (int)($analysis['room_count'] ?? 0) . ' scheduled class(es)';

    $reasons[] = (int)($analysis['section_count'] ?? 0) === 0
        ? 'No section conflict'
        : 'Section overlaps ' . (int)($analysis['section_count'] ?? 0) . ' scheduled class(es)';

    if ($hasFacultyAssignments) {
        $reasons[] = (int)($analysis['faculty_count'] ?? 0) === 0
            ? 'No faculty workload conflict'
            : 'Faculty workload overlaps ' . (int)($analysis['faculty_count'] ?? 0) . ' assigned class(es)';
    }

    $reasons[] = $scheduleType === 'LAB' ? 'Laboratory-compatible room' : 'Lecture-compatible room';
    $reasons[] = (($room['access_type'] ?? 'owner') === 'shared') ? 'Shared room access' : 'Owned room access';

    $deduped = [];
    $seen = [];
    foreach ($reasons as $reason) {
        $key = trim((string)$reason);
        if ($key === '' || isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $deduped[] = $key;
    }

    return array_slice($deduped, 0, 5);
}

function target_draft_has_any_filter(array $targetDraft): bool {
    return !empty(normalize_days_array($targetDraft['days'] ?? []))
        || trim((string)($targetDraft['time_start'] ?? '')) !== ''
        || trim((string)($targetDraft['time_end'] ?? '')) !== ''
        || (int)($targetDraft['room_id'] ?? 0) > 0;
}

function filter_candidate_rooms_for_target(array $rooms, string $targetType, array $targetDraft): array {
    $filtered = array_values(array_filter($rooms, static function (array $room) use ($targetType): bool {
        return room_type_allows_schedule($room['room_type'] ?? 'lecture', $targetType);
    }));

    $selectedRoomId = (int)($targetDraft['room_id'] ?? 0);
    if ($selectedRoomId <= 0) {
        return $filtered;
    }

    return array_values(array_filter($filtered, static function (array $room) use ($selectedRoomId): bool {
        return (int)($room['room_id'] ?? 0) === $selectedRoomId;
    }));
}

function build_filtered_patterns_for_target(string $targetType, int $desiredMinutes, array $targetDraft, array $schedulePolicy): array {
    $selectedDays = normalize_days_array($targetDraft['days'] ?? []);
    if (!empty($selectedDays)) {
        if (!empty(array_intersect($selectedDays, $schedulePolicy['blocked_days'] ?? []))) {
            return [];
        }

        $meetingMinutes = max(30, ceil_to_slot($desiredMinutes / max(1, count($selectedDays)), 30));
        $selectedStart = trim((string)($targetDraft['time_start'] ?? ''));
        $selectedEnd = trim((string)($targetDraft['time_end'] ?? ''));
        if ($selectedStart !== '' && $selectedEnd !== '') {
            $selectedStartMinutes = time_to_minutes($selectedStart);
            $selectedEndMinutes = time_to_minutes($selectedEnd);
            if ($selectedStartMinutes !== null && $selectedEndMinutes !== null && $selectedEndMinutes > $selectedStartMinutes) {
                $meetingMinutes = $selectedEndMinutes - $selectedStartMinutes;
            }
        }

        return [[
            'days' => $selectedDays,
            'meeting_minutes' => $meetingMinutes,
            'weight' => 60,
            'label' => 'Selected days'
        ]];
    }

    return array_values(array_filter(
        build_schedule_patterns($targetType, $desiredMinutes),
        static function (array $pattern) use ($schedulePolicy): bool {
            return empty(array_intersect($pattern['days'] ?? [], $schedulePolicy['blocked_days'] ?? []));
        }
    ));
}

function build_time_windows_for_pattern(array $pattern, array $targetDraft, array $schedulePolicy): array {
    $meetingMinutes = (int)($pattern['meeting_minutes'] ?? 0);
    $dayStart = time_to_minutes((string)($schedulePolicy['day_start'] ?? '07:30:00'));
    $dayEnd = time_to_minutes((string)($schedulePolicy['day_end'] ?? '17:30:00'));
    if ($meetingMinutes <= 0 || $dayStart === null || $dayEnd === null) {
        return [];
    }

    $selectedStart = trim((string)($targetDraft['time_start'] ?? ''));
    $selectedEnd = trim((string)($targetDraft['time_end'] ?? ''));
    $selectedStartMinutes = $selectedStart !== '' ? time_to_minutes($selectedStart) : null;
    $selectedEndMinutes = $selectedEnd !== '' ? time_to_minutes($selectedEnd) : null;

    if ($selectedStartMinutes !== null && $selectedEndMinutes !== null && $selectedEndMinutes > $selectedStartMinutes) {
        if ($selectedStartMinutes < $dayStart || $selectedEndMinutes > $dayEnd) {
            return [];
        }

        return [[
            'start' => $selectedStartMinutes,
            'end' => $selectedEndMinutes,
            'label' => 'Selected time'
        ]];
    }

    if ($selectedStartMinutes !== null) {
        $end = $selectedStartMinutes + $meetingMinutes;
        if ($selectedStartMinutes < $dayStart || $end > $dayEnd) {
            return [];
        }

        return [[
            'start' => $selectedStartMinutes,
            'end' => $end,
            'label' => 'Starts at selected time'
        ]];
    }

    if ($selectedEndMinutes !== null) {
        $start = $selectedEndMinutes - $meetingMinutes;
        if ($start < $dayStart || $selectedEndMinutes > $dayEnd) {
            return [];
        }

        return [[
            'start' => $start,
            'end' => $selectedEndMinutes,
            'label' => 'Ends at selected time'
        ]];
    }

    $windows = [];
    for ($start = $dayStart; ($start + $meetingMinutes) <= $dayEnd; $start += 30) {
        $windows[] = [
            'start' => $start,
            'end' => $start + $meetingMinutes,
            'label' => (string)($pattern['label'] ?? 'Suggested slot')
        ];
    }

    return $windows;
}

function room_has_conflict_in_snapshot(int $roomId, array $days, string $timeStart, string $timeEnd, array $snapshotByDay): bool {
    foreach ($days as $day) {
        foreach ($snapshotByDay[$day] ?? [] as $item) {
            if ((int)($item['room_id'] ?? 0) !== $roomId) {
                continue;
            }

            if (time_overlap($timeStart, $timeEnd, (string)($item['time_start'] ?? ''), (string)($item['time_end'] ?? ''))) {
                return true;
            }
        }
    }

    return false;
}

function build_availability_for_target(
    array $context,
    array $rooms,
    array $schedulePolicy,
    array $snapshotByDay,
    array $facultySnapshot,
    array $drafts,
    string $targetType,
    string $targetKey
) {
    $targetDraft = collect_target_draft($drafts, $targetType, $targetKey);
    $desiredMinutes = desired_weekly_minutes($targetType, $targetDraft, $drafts, $context);

    if (!target_draft_has_any_filter($targetDraft)) {
        return [
            'mode' => 'availability_finder',
            'desired_minutes' => $desiredMinutes,
            'target_draft' => $targetDraft,
            'selected_time_key' => '',
            'time_slots' => [],
            'message' => 'Choose at least one day, time, or room first.'
        ];
    }

    $patterns = build_filtered_patterns_for_target($targetType, $desiredMinutes, $targetDraft, $schedulePolicy);
    $candidateRooms = filter_candidate_rooms_for_target($rooms, $targetType, $targetDraft);
    $facultyByDay = $facultySnapshot['by_day'] ?? [];
    $hasFacultyAssignments = !empty($facultySnapshot['has_assignments']);

    if (empty($patterns) || empty($candidateRooms)) {
        return [
            'mode' => 'availability_finder',
            'desired_minutes' => $desiredMinutes,
            'target_draft' => $targetDraft,
            'selected_time_key' => '',
            'time_slots' => [],
            'message' => 'No available entries matched the current filters.'
        ];
    }

    $slotMap = [];
    foreach ($patterns as $pattern) {
        foreach (build_time_windows_for_pattern($pattern, $targetDraft, $schedulePolicy) as $window) {
            $days = normalize_days_array($pattern['days'] ?? []);
            $timeStart = minutes_to_time((int)$window['start']);
            $timeEnd = minutes_to_time((int)$window['end']);

            if (empty($days) || synk_schedule_policy_blocked_time_overlap($timeStart, $timeEnd, $schedulePolicy) !== null) {
                continue;
            }

            $slotAnalysis = analyze_candidate_conflicts([
                'room_id' => 0,
                'days' => $days,
                'time_start' => $timeStart,
                'time_end' => $timeEnd
            ], $snapshotByDay, $facultyByDay, $context, $drafts, $targetKey);

            if (!empty($slotAnalysis['draft_conflict']) || (int)($slotAnalysis['section_count'] ?? 0) > 0 || (int)($slotAnalysis['faculty_count'] ?? 0) > 0) {
                continue;
            }

            $availableRooms = [];
            foreach ($candidateRooms as $room) {
                $roomId = (int)($room['room_id'] ?? 0);
                if ($roomId <= 0 || room_has_conflict_in_snapshot($roomId, $days, $timeStart, $timeEnd, $snapshotByDay)) {
                    continue;
                }

                $candidate = [
                    'schedule_type' => $targetType,
                    'room_id' => $roomId,
                    'room_label' => (string)($room['label'] ?? ''),
                    'days' => $days,
                    'time_start' => $timeStart,
                    'time_end' => $timeEnd
                ];
                $roomAnalysis = [
                    'room_count' => 0,
                    'section_count' => 0,
                    'faculty_count' => 0
                ];

                $availableRooms[] = [
                    'room_id' => $roomId,
                    'room_label' => (string)($room['label'] ?? ''),
                    'fit_label' => 'Available',
                    'fit_class' => 'best',
                    'reasons' => build_reasons($candidate, $room, $targetType, $targetDraft, $roomAnalysis, $hasFacultyAssignments)
                ];
            }

            if (empty($availableRooms)) {
                continue;
            }

            usort($availableRooms, static function (array $left, array $right): int {
                return strcmp((string)($left['room_label'] ?? ''), (string)($right['room_label'] ?? ''));
            });

            $timeKey = implode('-', $days) . '|' . $timeStart . '|' . $timeEnd;
            $slotMap[$timeKey] = [
                'time_key' => $timeKey,
                'days' => $days,
                'days_label' => days_to_label($days),
                'time_start' => minutes_to_input_time((int)$window['start']),
                'time_end' => minutes_to_input_time((int)$window['end']),
                'time_label' => minutes_to_label((int)$window['start']) . ' - ' . minutes_to_label((int)$window['end']),
                'pattern_label' => (string)($window['label'] ?? $pattern['label'] ?? 'Available slot'),
                'room_count' => count($availableRooms),
                'rooms' => $availableRooms,
                'sort_weight' => (int)($pattern['weight'] ?? 0)
            ];
        }
    }

    $timeSlots = array_values($slotMap);
    usort($timeSlots, static function (array $left, array $right): int {
        if ((int)($left['room_count'] ?? 0) !== (int)($right['room_count'] ?? 0)) {
            return ((int)($right['room_count'] ?? 0) <=> (int)($left['room_count'] ?? 0));
        }

        if ((int)($left['sort_weight'] ?? 0) !== (int)($right['sort_weight'] ?? 0)) {
            return ((int)($right['sort_weight'] ?? 0) <=> (int)($left['sort_weight'] ?? 0));
        }

        if ((string)($left['days_label'] ?? '') !== (string)($right['days_label'] ?? '')) {
            return strcmp((string)($left['days_label'] ?? ''), (string)($right['days_label'] ?? ''));
        }

        return strcmp((string)($left['time_start'] ?? ''), (string)($right['time_start'] ?? ''));
    });

    $timeSlots = array_slice($timeSlots, 0, 24);
    $selectedTimeKey = (string)($timeSlots[0]['time_key'] ?? '');

    return [
        'mode' => 'availability_finder',
        'desired_minutes' => $desiredMinutes,
        'target_draft' => $targetDraft,
        'selected_time_key' => $selectedTimeKey,
        'time_slots' => $timeSlots,
        'message' => empty($timeSlots)
            ? 'No available entries matched the current day, time, or room filters.'
            : 'Available entries loaded for the current filters.'
    ];
}

function build_suggestions_for_target(
    $conn,
    array $context,
    array $rooms,
    array $schedulePolicy,
    array $snapshotByDay,
    array $facultySnapshot,
    array $drafts,
    string $targetType,
    string $targetKey
) {
    $targetDraft = collect_target_draft($drafts, $targetType, $targetKey);
    $desiredMinutes = desired_weekly_minutes($targetType, $targetDraft, $drafts, $context);
    $strictFilters = trim($targetKey) !== '';

    if ($strictFilters) {
        if (!target_draft_has_any_filter($targetDraft)) {
            return [
                'desired_minutes' => $desiredMinutes,
                'target_draft' => $targetDraft,
                'suggestions' => [],
                'has_conflict_free' => false,
                'message' => 'Choose at least one day, time, or room first.'
            ];
        }

        $patterns = build_filtered_patterns_for_target($targetType, $desiredMinutes, $targetDraft, $schedulePolicy);
        $candidateRooms = filter_candidate_rooms_for_target($rooms, $targetType, $targetDraft);
        $facultyByDay = $facultySnapshot['by_day'] ?? [];
        $hasFacultyAssignments = !empty($facultySnapshot['has_assignments']);
        $candidates = [];

        foreach ($patterns as $pattern) {
            foreach ($candidateRooms as $room) {
                foreach (build_time_windows_for_pattern($pattern, $targetDraft, $schedulePolicy) as $window) {
                    $candidate = [
                        'schedule_type' => $targetType,
                        'room_id' => (int)$room['room_id'],
                        'room_label' => (string)$room['label'],
                        'days' => $pattern['days'],
                        'days_label' => days_to_label($pattern['days']),
                        'time_start' => minutes_to_time((int)$window['start']),
                        'time_end' => minutes_to_time((int)$window['end']),
                        'time_start_input' => minutes_to_input_time((int)$window['start']),
                        'time_end_input' => minutes_to_input_time((int)$window['end']),
                        'time_label' => minutes_to_label((int)$window['start']) . ' - ' . minutes_to_label((int)$window['end']),
                        'pattern_label' => (string)($window['label'] ?? $pattern['label'] ?? 'Suggested slot')
                    ];

                    if (synk_schedule_policy_blocked_time_overlap($candidate['time_start'], $candidate['time_end'], $schedulePolicy) !== null) {
                        continue;
                    }

                    $analysis = analyze_candidate_conflicts($candidate, $snapshotByDay, $facultyByDay, $context, $drafts, $targetKey);
                    if (!empty($analysis['draft_conflict']) || (int)($analysis['total_live_conflicts'] ?? 0) > 0) {
                        continue;
                    }

                    $score = 20
                        + (int)($pattern['weight'] ?? 0)
                        + room_fit_score($room, $targetType)
                        + time_score($candidate)
                        + selected_days_score($candidate, $targetDraft)
                        + selected_time_score($candidate, $targetDraft)
                        + selected_room_score($candidate, $targetDraft);

                    $fit = fit_bucket($score, $analysis);
                    $candidate['fit_label'] = $fit['label'];
                    $candidate['fit_class'] = $fit['class'];
                    $candidate['score'] = $score;
                    $candidate['is_conflict_free'] = true;
                    $candidate['conflict_count'] = 0;
                    $candidate['section_conflict_count'] = 0;
                    $candidate['room_conflict_count'] = 0;
                    $candidate['faculty_conflict_count'] = 0;
                    $candidate['reasons'] = build_reasons($candidate, $room, $targetType, $targetDraft, $analysis, $hasFacultyAssignments);
                    $candidates[] = $candidate;
                }
            }
        }

        usort($candidates, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                if ($a['time_start'] === $b['time_start']) {
                    return strcmp($a['room_label'], $b['room_label']);
                }
                return strcmp($a['time_start'], $b['time_start']);
            }

            return $b['score'] <=> $a['score'];
        });

        $selected = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            $signature = implode('-', $candidate['days']) . '|' . $candidate['time_start'] . '|' . $candidate['room_id'];
            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $selected[] = [
                'schedule_type' => $candidate['schedule_type'],
                'room_id' => $candidate['room_id'],
                'room_label' => $candidate['room_label'],
                'days' => $candidate['days'],
                'days_label' => $candidate['days_label'],
                'time_start' => $candidate['time_start_input'],
                'time_end' => $candidate['time_end_input'],
                'time_label' => $candidate['time_label'],
                'pattern_label' => $candidate['pattern_label'],
                'fit_label' => $candidate['fit_label'],
                'fit_class' => $candidate['fit_class'],
                'is_conflict_free' => true,
                'conflict_count' => 0,
                'section_conflict_count' => 0,
                'room_conflict_count' => 0,
                'faculty_conflict_count' => 0,
                'reasons' => $candidate['reasons']
            ];

            if (count($selected) >= 12) {
                break;
            }
        }

        return [
            'desired_minutes' => $desiredMinutes,
            'target_draft' => $targetDraft,
            'suggestions' => $selected,
            'has_conflict_free' => !empty($selected),
            'message' => !empty($selected)
                ? 'Available suggestions loaded for the selected filters.'
                : 'No available suggestions matched the current day, time, or room filters.'
        ];
    }

    $patternMap = [];

    foreach (build_preferred_patterns($targetType, $desiredMinutes, $targetDraft) as $pattern) {
        $key = implode('-', $pattern['days']) . '|' . (int)($pattern['meeting_minutes'] ?? 0);
        if (!isset($patternMap[$key]) || (int)($pattern['weight'] ?? 0) > (int)($patternMap[$key]['weight'] ?? 0)) {
            $patternMap[$key] = $pattern;
        }
    }

    foreach (build_schedule_patterns($targetType, $desiredMinutes) as $pattern) {
        $key = implode('-', $pattern['days']) . '|' . (int)($pattern['meeting_minutes'] ?? 0);
        if (!isset($patternMap[$key]) || (int)($pattern['weight'] ?? 0) > (int)($patternMap[$key]['weight'] ?? 0)) {
            $patternMap[$key] = $pattern;
        }
    }

    $patterns = array_values(array_filter($patternMap, static function (array $pattern) use ($schedulePolicy): bool {
        return empty(array_intersect($pattern['days'] ?? [], $schedulePolicy['blocked_days'] ?? []));
    }));

    $candidates = [];
    $dayStart = time_to_minutes((string)($schedulePolicy['day_start'] ?? '07:30:00'));
    $dayEnd = time_to_minutes((string)($schedulePolicy['day_end'] ?? '17:30:00'));
    $facultyByDay = $facultySnapshot['by_day'] ?? [];
    $hasFacultyAssignments = !empty($facultySnapshot['has_assignments']);

    foreach ($patterns as $pattern) {
        $meetingMinutes = (int)($pattern['meeting_minutes'] ?? 0);
        if ($meetingMinutes <= 0 || $dayStart === null || $dayEnd === null || ($dayStart + $meetingMinutes) > $dayEnd) {
            continue;
        }

        foreach ($rooms as $room) {
            if (!room_type_allows_schedule($room['room_type'] ?? 'lecture', $targetType)) {
                continue;
            }

            for ($start = $dayStart; ($start + $meetingMinutes) <= $dayEnd; $start += 30) {
                $end = $start + $meetingMinutes;
                $candidate = [
                    'schedule_type' => $targetType,
                    'room_id' => (int)$room['room_id'],
                    'room_label' => (string)$room['label'],
                    'days' => $pattern['days'],
                    'days_label' => days_to_label($pattern['days']),
                    'time_start' => minutes_to_time($start),
                    'time_end' => minutes_to_time($end),
                    'time_start_input' => minutes_to_input_time($start),
                    'time_end_input' => minutes_to_input_time($end),
                    'time_label' => minutes_to_label($start) . ' - ' . minutes_to_label($end),
                    'pattern_label' => $pattern['label']
                ];

                if (synk_schedule_policy_blocked_time_overlap($candidate['time_start'], $candidate['time_end'], $schedulePolicy) !== null) {
                    continue;
                }

                $analysis = analyze_candidate_conflicts($candidate, $snapshotByDay, $facultyByDay, $context, $drafts, $targetKey);
                if (!empty($analysis['draft_conflict'])) {
                    continue;
                }

                $score = 20
                    + (int)($pattern['weight'] ?? 0)
                    + room_fit_score($room, $targetType)
                    + companion_spacing_score($candidate, $drafts, $targetKey)
                    + time_score($candidate)
                    + selected_days_score($candidate, $targetDraft)
                    + selected_time_score($candidate, $targetDraft)
                    + selected_room_score($candidate, $targetDraft)
                    - ((int)($analysis['section_count'] ?? 0) * 40)
                    - ((int)($analysis['room_count'] ?? 0) * 35)
                    - ((int)($analysis['faculty_count'] ?? 0) * 45);

                $fit = fit_bucket($score, $analysis);
                $candidate['fit_label'] = $fit['label'];
                $candidate['fit_class'] = $fit['class'];
                $candidate['score'] = $score;
                $candidate['is_conflict_free'] = (int)($analysis['total_live_conflicts'] ?? 0) === 0;
                $candidate['conflict_count'] = (int)($analysis['total_live_conflicts'] ?? 0);
                $candidate['section_conflict_count'] = (int)($analysis['section_count'] ?? 0);
                $candidate['room_conflict_count'] = (int)($analysis['room_count'] ?? 0);
                $candidate['faculty_conflict_count'] = (int)($analysis['faculty_count'] ?? 0);
                $candidate['reasons'] = build_reasons($candidate, $room, $targetType, $targetDraft, $analysis, $hasFacultyAssignments);
                $candidates[] = $candidate;
            }
        }
    }

    usort($candidates, function ($a, $b) {
        if ((int)$a['conflict_count'] !== (int)$b['conflict_count']) {
            return ((int)$a['conflict_count'] <=> (int)$b['conflict_count']);
        }

        if ($a['score'] === $b['score']) {
            if ($a['time_start'] === $b['time_start']) {
                return strcmp($a['room_label'], $b['room_label']);
            }
            return strcmp($a['time_start'], $b['time_start']);
        }

        return $b['score'] <=> $a['score'];
    });

    $selected = [];
    $seen = [];
    foreach ($candidates as $candidate) {
        $signature = implode('-', $candidate['days']) . '|' . $candidate['time_start'] . '|' . $candidate['room_id'];
        if (isset($seen[$signature])) {
            continue;
        }

        $seen[$signature] = true;
        $selected[] = [
            'schedule_type' => $candidate['schedule_type'],
            'room_id' => $candidate['room_id'],
            'room_label' => $candidate['room_label'],
            'days' => $candidate['days'],
            'days_label' => $candidate['days_label'],
            'time_start' => $candidate['time_start_input'],
            'time_end' => $candidate['time_end_input'],
            'time_label' => $candidate['time_label'],
            'pattern_label' => $candidate['pattern_label'],
            'fit_label' => $candidate['fit_label'],
            'fit_class' => $candidate['fit_class'],
            'is_conflict_free' => $candidate['is_conflict_free'],
            'conflict_count' => $candidate['conflict_count'],
            'section_conflict_count' => $candidate['section_conflict_count'],
            'room_conflict_count' => $candidate['room_conflict_count'],
            'faculty_conflict_count' => $candidate['faculty_conflict_count'],
            'reasons' => $candidate['reasons']
        ];

        if (count($selected) >= 8) {
            break;
        }
    }

    return [
        'desired_minutes' => $desiredMinutes,
        'target_draft' => $targetDraft,
        'suggestions' => $selected,
        'has_conflict_free' => count(array_filter($selected, static function (array $candidate): bool {
            return !empty($candidate['is_conflict_free']);
        })) > 0
    ];
}

$context = load_offering_context($conn, $offering_id, $college_id);
if (!$context) {
    respond('error', 'Offering is out of sync. Re-run Generate Offerings first.');
}

$allowedTypes = ((float)$context['lab_units'] > 0) ? ['LEC', 'LAB'] : ['LEC'];
if ($target_type !== '' && !in_array($target_type, $allowedTypes, true)) {
    respond('error', 'This subject does not allow the selected schedule type.');
}

if (!room_access_table_exists($conn)) {
    respond('error', 'Room access table is missing.');
}

$ay_id = (int)$context['ay_id'];
$semester = (int)$context['semester'];
$rooms = load_accessible_rooms($conn, $college_id, $ay_id, $semester);

if (empty($rooms)) {
    if ($target_type === '') {
        $suggestionsByType = [];
        $desiredMinutesByType = [];
        foreach ($allowedTypes as $allowedType) {
            $suggestionsByType[$allowedType] = [];
            $desiredMinutesByType[$allowedType] = 0;
        }

        respond('ok', 'No accessible rooms found for this term.', [
            'desired_minutes' => $desiredMinutesByType,
            'suggestions' => $suggestionsByType
        ]);
    }

    respond('ok', 'No accessible rooms found for this term.', [
        'target_type' => $target_type,
        'desired_minutes' => 0,
        'suggestions' => []
    ]);
}

$drafts = normalize_draft_schedules($_POST['drafts_json'] ?? '');
$snapshotByDay = load_term_schedule_snapshot_by_day($conn, $ay_id, $semester, $offering_id);
$facultySnapshot = load_faculty_snapshot_by_day($conn, $context, $offering_id);

if ($target_key !== '') {
    $result = build_availability_for_target(
        $context,
        $rooms,
        $schedulePolicy,
        $snapshotByDay,
        $facultySnapshot,
        $drafts,
        $target_type,
        $target_key
    );

    respond('ok', (string)($result['message'] ?? 'Availability loaded.'), [
        'mode' => 'availability_finder',
        'target_type' => $target_type,
        'target_key' => $target_key,
        'desired_minutes' => (int)($result['desired_minutes'] ?? 0),
        'selected_time_key' => (string)($result['selected_time_key'] ?? ''),
        'time_slots' => $result['time_slots'] ?? []
    ]);
}

if ($target_type === '') {
    $suggestionsByType = [];
    $desiredMinutesByType = [];

    foreach ($allowedTypes as $allowedType) {
        $result = build_suggestions_for_target(
            $conn,
            $context,
            $rooms,
            $schedulePolicy,
            $snapshotByDay,
            $facultySnapshot,
            $drafts,
            $allowedType,
            ''
        );

        $suggestionsByType[$allowedType] = $result['suggestions'];
        $desiredMinutesByType[$allowedType] = (int)$result['desired_minutes'];
    }

    respond('ok', 'Suggestions loaded.', [
        'desired_minutes' => $desiredMinutesByType,
        'suggestions' => $suggestionsByType
    ]);
}

$result = build_suggestions_for_target(
    $conn,
    $context,
    $rooms,
    $schedulePolicy,
    $snapshotByDay,
    $facultySnapshot,
    $drafts,
    $target_type,
    $target_key
);

$message = trim((string)($result['message'] ?? ''));
if ($message === '') {
    $message = !empty($result['has_conflict_free'])
        ? 'Suggestions loaded.'
        : 'No conflict-free slots were found. Showing the closest available options to review.';
}

respond('ok', $message, [
    'target_type' => $target_type,
    'target_key' => $target_key,
    'desired_minutes' => (int)$result['desired_minutes'],
    'has_conflict_free' => !empty($result['has_conflict_free']),
    'suggestions' => $result['suggestions']
]);
