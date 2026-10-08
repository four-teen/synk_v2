<?php
session_start();
include 'db.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/schema_helper.php';

header('Content-Type: application/json');

function admin_heatmap_empty_payload(): array
{
    return [
        'scope_kind' => 'campus',
        'series' => [],
        'peak_label' => 'No occupied room slots',
        'peak_occupied_rooms' => 0,
        'peak_percent' => 0.0,
        'total_rooms' => 0,
        'room_options' => [],
        'selected_room_id' => 0,
        'selected_room_label' => '',
        'view_mode' => 'pool',
        'occupied_slot_count' => 0,
        'busiest_day_label' => '',
        'busiest_day_slots' => 0
    ];
}

function admin_heatmap_respond(string $status, string $message = '', array $payload = []): void
{
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'schedule_heatmap' => array_merge(admin_heatmap_empty_payload(), $payload)
    ]);
    exit;
}

function admin_heatmap_day_order(): array
{
    return ['M', 'T', 'W', 'Th', 'F'];
}

function admin_heatmap_day_label(string $token): string
{
    $labels = [
        'M' => 'Mon',
        'T' => 'Tue',
        'W' => 'Wed',
        'Th' => 'Thu',
        'F' => 'Fri',
        'S' => 'Sat'
    ];

    return $labels[$token] ?? $token;
}

function admin_heatmap_normalize_day_token($day): string
{
    $token = strtoupper(trim((string)$day));
    if ($token === 'TH') {
        return 'Th';
    }

    return in_array($token, ['M', 'T', 'W', 'F', 'S'], true) ? $token : '';
}

function admin_heatmap_decode_days(string $daysJson): array
{
    $decoded = json_decode($daysJson, true);
    if (!is_array($decoded)) {
        return [];
    }

    $unique = [];
    foreach ($decoded as $day) {
        $token = admin_heatmap_normalize_day_token($day);
        if ($token !== '') {
            $unique[$token] = true;
        }
    }

    return array_keys($unique);
}

function admin_heatmap_time_to_minutes(string $time): int
{
    $time = trim($time);
    if ($time === '') {
        return 0;
    }

    $parts = explode(':', $time);
    if (count($parts) < 2) {
        return 0;
    }

    return (((int)$parts[0]) * 60) + ((int)$parts[1]);
}

function admin_heatmap_minutes_to_label(int $minutes): string
{
    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;
    $suffix = $hours >= 12 ? 'PM' : 'AM';
    $displayHour = $hours % 12;
    if ($displayHour === 0) {
        $displayHour = 12;
    }

    return sprintf('%d:%02d %s', $displayHour, $mins, $suffix);
}

function admin_heatmap_slot_label(int $startMinutes, int $endMinutes): string
{
    $startLabel = admin_heatmap_minutes_to_label($startMinutes);
    $endLabel = admin_heatmap_minutes_to_label($endMinutes);
    $startSuffix = substr($startLabel, -2);
    $endSuffix = substr($endLabel, -2);

    if ($startSuffix === $endSuffix) {
        $startCore = preg_replace('/\s+[AP]M$/', '', $startLabel);
        return $startCore . '-' . $endLabel;
    }

    return $startLabel . '-' . $endLabel;
}

function admin_heatmap_room_label(array $row, string $scopeKind, int $selectedCollegeId): string
{
    $roomId = (int)($row['room_id'] ?? 0);
    $roomCode = trim((string)($row['room_code'] ?? ''));
    $roomName = trim((string)($row['room_name'] ?? ''));
    $ownerCode = trim((string)($row['owner_code'] ?? ''));
    $ownerCollegeId = (int)($row['owner_college_id'] ?? 0);
    $campusCode = trim((string)($row['campus_code'] ?? ''));

    if ($roomCode !== '' && $roomName !== '' && strcasecmp($roomCode, $roomName) !== 0) {
        $label = $roomCode . ' - ' . $roomName;
    } elseif ($roomCode !== '') {
        $label = $roomCode;
    } elseif ($roomName !== '') {
        $label = $roomName;
    } else {
        $label = 'Room ' . ($roomId > 0 ? $roomId : '');
    }

    if ($scopeKind === 'college' && $ownerCode !== '' && $ownerCollegeId > 0 && $ownerCollegeId !== $selectedCollegeId) {
        return trim($label . ' (Shared from ' . $ownerCode . ')');
    }

    $suffixParts = [];
    if ($scopeKind !== 'college' && $ownerCode !== '') {
        $suffixParts[] = $ownerCode;
    }
    if ($scopeKind === 'university' && $campusCode !== '') {
        $suffixParts[] = $campusCode;
    }

    if (!empty($suffixParts)) {
        $label .= ' (' . implode(' - ', $suffixParts) . ')';
    }

    return trim($label);
}

function admin_heatmap_fill_empty_series(array $slotLabels, array $days): array
{
    $series = [];
    foreach ($days as $dayKey) {
        $seriesData = [];
        foreach ($slotLabels as $slotLabel) {
            $seriesData[] = [
                'x' => $slotLabel,
                'y' => 0
            ];
        }

        $series[] = [
            'name' => admin_heatmap_day_label($dayKey),
            'data' => $seriesData
        ];
    }

    return $series;
}

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'admin') {
    admin_heatmap_respond('error', 'Unauthorized access.');
}

$csrfToken = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)) {
    admin_heatmap_respond('error', 'CSRF validation failed.');
}

$currentTerm = synk_fetch_current_academic_term($conn);
$currentAyId = (int)($currentTerm['ay_id'] ?? 0);
$currentSemester = (int)($currentTerm['semester'] ?? 0);
if ($currentAyId <= 0 || $currentSemester <= 0) {
    admin_heatmap_respond('ok');
}

$campusIdRaw = trim((string)($_POST['campus_id'] ?? ''));
$selectedCollegeId = (int)($_POST['college_id'] ?? 0);
$requestedRoomId = (int)($_POST['room_id'] ?? 0);
$isUniversitySummary = strtolower($campusIdRaw) === 'all';
$campusId = $isUniversitySummary ? 0 : (int)$campusIdRaw;

if (!$isUniversitySummary && $campusId <= 0) {
    admin_heatmap_respond('error', 'Missing campus context.');
}

if (!$isUniversitySummary) {
    $campusStmt = $conn->prepare("
        SELECT campus_id
        FROM tbl_campus
        WHERE campus_id = ?
          AND status = 'active'
        LIMIT 1
    ");
    if (!$campusStmt) {
        admin_heatmap_respond('error', 'Unable to validate campus context.');
    }

    $campusStmt->bind_param('i', $campusId);
    $campusStmt->execute();
    $campusRow = $campusStmt->get_result()->fetch_assoc();
    $campusStmt->close();

    if (!$campusRow) {
        admin_heatmap_respond('error', 'Selected campus is not available.');
    }
}

if ($selectedCollegeId > 0) {
    if ($isUniversitySummary) {
        admin_heatmap_respond('error', 'College filtering requires a campus context.');
    }

    $collegeStmt = $conn->prepare("
        SELECT college_id
        FROM tbl_college
        WHERE college_id = ?
          AND campus_id = ?
          AND status = 'active'
        LIMIT 1
    ");
    if (!$collegeStmt) {
        admin_heatmap_respond('error', 'Unable to validate college context.');
    }

    $collegeStmt->bind_param('ii', $selectedCollegeId, $campusId);
    $collegeStmt->execute();
    $collegeRow = $collegeStmt->get_result()->fetch_assoc();
    $collegeStmt->close();

    if (!$collegeRow) {
        admin_heatmap_respond('error', 'Selected college is not available in this campus.');
    }
}

$scopeKind = $selectedCollegeId > 0 ? 'college' : ($isUniversitySummary ? 'university' : 'campus');
$days = admin_heatmap_day_order();
$dayWindowStartMinutes = 7 * 60;
$dayWindowEndMinutes = 18 * 60;
$slotDefinitions = [];
$slotLabels = [];

for ($slotMinutes = $dayWindowStartMinutes; $slotMinutes < $dayWindowEndMinutes; $slotMinutes += 30) {
    $slotLabel = admin_heatmap_slot_label($slotMinutes, $slotMinutes + 30);
    $slotDefinitions[] = [
        'label' => $slotLabel,
        'start' => $slotMinutes,
        'end' => $slotMinutes + 30
    ];
    $slotLabels[] = $slotLabel;
}

$heatmap = admin_heatmap_empty_payload();
$heatmap['scope_kind'] = $scopeKind;
$heatmapMatrix = [];
foreach ($days as $dayKey) {
    $heatmapMatrix[$dayKey] = [];
    foreach ($slotLabels as $slotLabel) {
        $heatmapMatrix[$dayKey][$slotLabel] = [];
    }
}

$roomPool = [];
$roomOptions = [];
$selectedRoomLabel = '';

if ($scopeKind === 'college') {
    if (synk_table_exists($conn, 'tbl_room_college_access')) {
        $roomPoolSql = "
            SELECT DISTINCT
                r.room_id,
                r.room_code,
                r.room_name,
                r.college_id AS owner_college_id,
                owner.college_code AS owner_code,
                camp.campus_code
            FROM tbl_room_college_access acc
            INNER JOIN tbl_rooms r
                ON r.room_id = acc.room_id
            LEFT JOIN tbl_college owner
                ON owner.college_id = r.college_id
            LEFT JOIN tbl_campus camp
                ON camp.campus_id = owner.campus_id
            WHERE acc.college_id = ?
              AND acc.ay_id = ?
              AND acc.semester = ?
              AND r.status = 'active'
            ORDER BY r.room_name ASC, r.room_code ASC, r.room_id ASC
        ";

        $roomPoolStmt = $conn->prepare($roomPoolSql);
        if ($roomPoolStmt instanceof mysqli_stmt) {
            $roomPoolStmt->bind_param('iii', $selectedCollegeId, $currentAyId, $currentSemester);
        }
    } else {
        $roomPoolSql = "
            SELECT
                r.room_id,
                r.room_code,
                r.room_name,
                r.college_id AS owner_college_id,
                owner.college_code AS owner_code,
                camp.campus_code
            FROM tbl_rooms r
            LEFT JOIN tbl_college owner
                ON owner.college_id = r.college_id
            LEFT JOIN tbl_campus camp
                ON camp.campus_id = owner.campus_id
            WHERE r.college_id = ?
              AND r.status = 'active'
            ORDER BY r.room_name ASC, r.room_code ASC, r.room_id ASC
        ";

        $roomPoolStmt = $conn->prepare($roomPoolSql);
        if ($roomPoolStmt instanceof mysqli_stmt) {
            $roomPoolStmt->bind_param('i', $selectedCollegeId);
        }
    }
} elseif ($scopeKind === 'campus') {
    $roomPoolSql = "
        SELECT
            r.room_id,
            r.room_code,
            r.room_name,
            col.college_id AS owner_college_id,
            col.college_code AS owner_code,
            camp.campus_code
        FROM tbl_rooms r
        INNER JOIN tbl_college col
            ON col.college_id = r.college_id
        INNER JOIN tbl_campus camp
            ON camp.campus_id = col.campus_id
        WHERE camp.campus_id = ?
          AND camp.status = 'active'
          AND col.status = 'active'
          AND r.status = 'active'
        ORDER BY r.room_name ASC, r.room_code ASC, r.room_id ASC
    ";

    $roomPoolStmt = $conn->prepare($roomPoolSql);
    if ($roomPoolStmt instanceof mysqli_stmt) {
        $roomPoolStmt->bind_param('i', $campusId);
    }
} else {
    $roomPoolSql = "
        SELECT
            r.room_id,
            r.room_code,
            r.room_name,
            col.college_id AS owner_college_id,
            col.college_code AS owner_code,
            camp.campus_code
        FROM tbl_rooms r
        INNER JOIN tbl_college col
            ON col.college_id = r.college_id
        INNER JOIN tbl_campus camp
            ON camp.campus_id = col.campus_id
        WHERE camp.status = 'active'
          AND col.status = 'active'
          AND r.status = 'active'
        ORDER BY camp.campus_name ASC, col.college_name ASC, r.room_name ASC, r.room_code ASC, r.room_id ASC
    ";

    $roomPoolStmt = $conn->prepare($roomPoolSql);
}

if (isset($roomPoolStmt) && $roomPoolStmt instanceof mysqli_stmt) {
    $roomPoolStmt->execute();
    $roomPoolRes = $roomPoolStmt->get_result();

    while ($roomRow = $roomPoolRes->fetch_assoc()) {
        $roomId = (int)($roomRow['room_id'] ?? 0);
        if ($roomId <= 0) {
            continue;
        }

        $roomPool[$roomId] = true;
        $roomLabel = admin_heatmap_room_label($roomRow, $scopeKind, $selectedCollegeId);
        $roomOptions[] = [
            'room_id' => $roomId,
            'label' => $roomLabel
        ];

        if ($roomId === $requestedRoomId) {
            $selectedRoomLabel = $roomLabel;
        }
    }

    $roomPoolStmt->close();
}
unset($roomPoolStmt);

usort($roomOptions, static function (array $left, array $right): int {
    return strcasecmp((string)($left['label'] ?? ''), (string)($right['label'] ?? ''));
});

$selectedRoomId = isset($roomPool[$requestedRoomId]) ? $requestedRoomId : 0;
$heatmapRoomIds = $selectedRoomId > 0 ? [$selectedRoomId => true] : $roomPool;

$heatmap['room_options'] = $roomOptions;
$heatmap['selected_room_id'] = $selectedRoomId;
$heatmap['selected_room_label'] = $selectedRoomId > 0 ? $selectedRoomLabel : '';
$heatmap['view_mode'] = $selectedRoomId > 0 ? 'room' : 'pool';
$heatmap['total_rooms'] = count($heatmapRoomIds);

if (!empty($heatmapRoomIds)) {
    $roomIdList = implode(',', array_map('intval', array_keys($heatmapRoomIds)));
    $heatmapSql = "
        SELECT
            cs.room_id,
            cs.time_start,
            cs.time_end,
            cs.days_json
        FROM tbl_class_schedule cs
        INNER JOIN tbl_prospectus_offering po
            ON po.offering_id = cs.offering_id
        WHERE po.ay_id = ?
          AND po.semester = ?
          AND cs.room_id IN ({$roomIdList})
    ";

    $heatmapStmt = $conn->prepare($heatmapSql);
    if ($heatmapStmt instanceof mysqli_stmt) {
        $heatmapStmt->bind_param('ii', $currentAyId, $currentSemester);
        $heatmapStmt->execute();
        $heatmapRes = $heatmapStmt->get_result();

        while ($heatmapRow = $heatmapRes->fetch_assoc()) {
            $roomId = (int)($heatmapRow['room_id'] ?? 0);
            if (!isset($heatmapRoomIds[$roomId])) {
                continue;
            }

            $meetingDays = array_values(array_intersect(
                admin_heatmap_decode_days((string)($heatmapRow['days_json'] ?? '')),
                $days
            ));
            $startMinutes = admin_heatmap_time_to_minutes((string)($heatmapRow['time_start'] ?? ''));
            $endMinutes = admin_heatmap_time_to_minutes((string)($heatmapRow['time_end'] ?? ''));

            if (count($meetingDays) === 0 || $endMinutes <= $startMinutes) {
                continue;
            }

            foreach ($meetingDays as $dayKey) {
                foreach ($slotDefinitions as $slotDefinition) {
                    if ($slotDefinition['start'] < $endMinutes && $slotDefinition['end'] > $startMinutes) {
                        $heatmapMatrix[$dayKey][$slotDefinition['label']][$roomId] = true;
                    }
                }
            }
        }

        $heatmapStmt->close();
    }
    unset($heatmapStmt);

    foreach ($days as $dayKey) {
        $seriesData = [];
        $dayOccupiedSlots = 0;

        foreach ($slotLabels as $slotLabel) {
            $occupiedRooms = count($heatmapMatrix[$dayKey][$slotLabel]);
            $occupancyPercent = $heatmap['total_rooms'] > 0
                ? round(($occupiedRooms / $heatmap['total_rooms']) * 100, 1)
                : 0.0;

            $seriesData[] = [
                'x' => $slotLabel,
                'y' => $occupancyPercent
            ];

            if ($occupiedRooms > 0) {
                $heatmap['occupied_slot_count']++;
                $dayOccupiedSlots++;
            }

            if ($occupancyPercent > $heatmap['peak_percent']) {
                $heatmap['peak_percent'] = $occupancyPercent;
                $heatmap['peak_occupied_rooms'] = $occupiedRooms;
                $heatmap['peak_label'] = admin_heatmap_day_label($dayKey) . ' - ' . $slotLabel;
            }
        }

        if ($dayOccupiedSlots > $heatmap['busiest_day_slots']) {
            $heatmap['busiest_day_slots'] = $dayOccupiedSlots;
            $heatmap['busiest_day_label'] = admin_heatmap_day_label($dayKey);
        }

        $heatmap['series'][] = [
            'name' => admin_heatmap_day_label($dayKey),
            'data' => $seriesData
        ];
    }
}

if (empty($heatmap['series'])) {
    $heatmap['series'] = admin_heatmap_fill_empty_series($slotLabels, $days);
}

admin_heatmap_respond('ok', '', $heatmap);
