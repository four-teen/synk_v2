<?php
session_start();
include 'db.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schedule_merge_helper.php';
require_once __DIR__ . '/scheduler_access_helper.php';
require_once __DIR__ . '/schema_helper.php';

header('Content-Type: application/json');

function empty_dashboard_heatmap_payload(): array
{
    return [
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

function empty_dashboard_chart_payload(string $scope = 'college', string $scopeLabel = ''): array
{
    return [
        'scope' => $scope,
        'scope_label' => $scopeLabel,
        'program_progress' => [],
        'weekly_pressure' => [],
        'schedule_heatmap' => empty_dashboard_heatmap_payload()
    ];
}

function dashboard_day_order(): array
{
    return ['M', 'T', 'W', 'Th', 'F', 'S'];
}

function dashboard_day_label(string $token): string
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

function normalize_dashboard_day_token($day): string
{
    $token = strtoupper(trim((string)$day));

    if ($token === 'TH') {
        return 'Th';
    }

    return in_array($token, ['M', 'T', 'W', 'F', 'S'], true) ? $token : '';
}

function dashboard_heatmap_day_order(): array
{
    return ['M', 'T', 'W', 'Th', 'F'];
}

function dashboard_decode_days(string $daysJson): array
{
    $decoded = json_decode($daysJson, true);
    if (!is_array($decoded)) {
        return [];
    }

    $unique = [];
    foreach ($decoded as $day) {
        $token = normalize_dashboard_day_token($day);
        if ($token !== '') {
            $unique[$token] = true;
        }
    }

    return array_keys($unique);
}

function dashboard_time_to_minutes(string $time): int
{
    $time = trim($time);
    if ($time === '') {
        return 0;
    }

    $parts = explode(':', $time);
    if (count($parts) < 2) {
        return 0;
    }

    $hours = (int)$parts[0];
    $minutes = (int)$parts[1];
    return ($hours * 60) + $minutes;
}

function dashboard_minutes_to_label(int $minutes): string
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

function dashboard_slot_label(int $startMinutes, int $endMinutes): string
{
    $startLabel = dashboard_minutes_to_label($startMinutes);
    $endLabel = dashboard_minutes_to_label($endMinutes);
    $startSuffix = substr($startLabel, -2);
    $endSuffix = substr($endLabel, -2);

    if ($startSuffix === $endSuffix) {
        $startCore = preg_replace('/\s+[AP]M$/', '', $startLabel);
        return $startCore . '-' . $endLabel;
    }

    return $startLabel . '-' . $endLabel;
}

function dashboard_room_label(array $row, string $scope, int $collegeId): string
{
    $roomId = (int)($row['room_id'] ?? 0);
    $roomCode = trim((string)($row['room_code'] ?? ''));
    $roomName = trim((string)($row['room_name'] ?? ''));
    $ownerCode = trim((string)($row['owner_code'] ?? ''));
    $ownerCollegeId = (int)($row['owner_college_id'] ?? 0);

    if ($roomCode !== '' && $roomName !== '' && strcasecmp($roomCode, $roomName) !== 0) {
        $label = $roomCode . ' - ' . $roomName;
    } elseif ($roomCode !== '') {
        $label = $roomCode;
    } elseif ($roomName !== '') {
        $label = $roomName;
    } else {
        $label = 'Room ' . ($roomId > 0 ? $roomId : '');
    }

    if ($ownerCode !== '' && ($scope === 'campus' || ($collegeId > 0 && $ownerCollegeId > 0 && $ownerCollegeId !== $collegeId))) {
        $label .= ' (' . $ownerCode . ')';
    }

    return trim($label);
}

function dashboard_overlap_hours(int $startMinutes, int $endMinutes, int $windowStartMinutes, int $windowEndMinutes): float
{
    $effectiveStart = max($startMinutes, $windowStartMinutes);
    $effectiveEnd = min($endMinutes, $windowEndMinutes);

    if ($effectiveEnd <= $effectiveStart) {
        return 0.0;
    }

    return ($effectiveEnd - $effectiveStart) / 60;
}

if (!isset($_SESSION['college_id'])) {
    echo json_encode(empty_dashboard_chart_payload());
    exit;
}

synk_scheduler_bootstrap_session_scope($conn);

$collegeId = (int)($_SESSION['college_id'] ?? 0);
$campusId = (int)($_SESSION['campus_id'] ?? 0);
$scope = strtolower(trim((string)($_POST['scope'] ?? 'college')));
$requestedRoomId = (int)($_POST['room_id'] ?? 0);
if ($scope !== 'campus' || $campusId <= 0) {
    $scope = 'college';
}

$scopeLabel = $scope === 'campus'
    ? (string)($_SESSION['campus_name'] ?? '')
    : (string)($_SESSION['college_name'] ?? '');

$currentTerm = synk_fetch_current_academic_term($conn);
$currentAyId = (int)($currentTerm['ay_id'] ?? 0);
$currentSemester = (int)($currentTerm['semester'] ?? 0);

if ($collegeId <= 0 || $currentAyId <= 0 || $currentSemester <= 0) {
    echo json_encode(empty_dashboard_chart_payload($scope, $scopeLabel));
    exit;
}

$filterValue = $scope === 'campus' ? $campusId : $collegeId;
$filterSql = $scope === 'campus' ? 'c.campus_id = ?' : 'p.college_id = ?';
$liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
$scheduledOfferingJoin = synk_schedule_merge_scheduled_offering_join_sql($conn, 'sched', 'o');

$programProgress = [];

if ($scope === 'campus') {
    $progressSql = "
        SELECT
            c.college_id,
            COALESCE(
                NULLIF(TRIM(c.college_code), ''),
                NULLIF(TRIM(c.college_name), ''),
                CONCAT('College ', c.college_id)
            ) AS scope_label,
            COUNT(DISTINCT o.offering_id) AS total_offerings,
            COUNT(DISTINCT sched.offering_id) AS scheduled_offerings
        FROM tbl_prospectus_offering o
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        INNER JOIN tbl_college c
            ON c.college_id = p.college_id
        {$scheduledOfferingJoin}
        WHERE {$filterSql}
          AND c.status = 'active'
          AND p.status = 'active'
          AND o.ay_id = ?
          AND o.semester = ?
        GROUP BY c.college_id, c.college_code, c.college_name
        HAVING COUNT(DISTINCT o.offering_id) > 0
        ORDER BY total_offerings DESC, scope_label ASC
    ";
} else {
    $progressSql = "
        SELECT
            p.program_id,
            COALESCE(
                NULLIF(TRIM(p.program_code), ''),
                NULLIF(TRIM(p.program_name), ''),
                CONCAT('Program ', p.program_id)
            ) AS scope_label,
            COUNT(DISTINCT o.offering_id) AS total_offerings,
            COUNT(DISTINCT sched.offering_id) AS scheduled_offerings
        FROM tbl_prospectus_offering o
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        INNER JOIN tbl_college c
            ON c.college_id = p.college_id
        {$scheduledOfferingJoin}
        WHERE {$filterSql}
          AND c.status = 'active'
          AND p.status = 'active'
          AND o.ay_id = ?
          AND o.semester = ?
        GROUP BY p.program_id, p.program_code, p.program_name
        HAVING COUNT(DISTINCT o.offering_id) > 0
        ORDER BY total_offerings DESC, scope_label ASC
    ";
}

$progressStmt = $conn->prepare($progressSql);
if ($progressStmt) {
    $progressStmt->bind_param("iii", $filterValue, $currentAyId, $currentSemester);
    $progressStmt->execute();
    $progressRes = $progressStmt->get_result();

    while ($row = $progressRes->fetch_assoc()) {
        $total = (int)($row['total_offerings'] ?? 0);
        $scheduled = (int)($row['scheduled_offerings'] ?? 0);

        $programProgress[] = [
            'program' => $row['scope_label'],
            'total' => $total,
            'scheduled' => $scheduled,
            'pending' => max(0, $total - $scheduled)
        ];
    }

    $progressStmt->close();
}

$weeklyDaySets = [];
foreach (dashboard_day_order() as $token) {
    $weeklyDaySets[$token] = [
        'meetings' => [],
        'rooms' => [],
        'faculty' => []
    ];
}

$weeklySql = "
    SELECT
        cs.schedule_id,
        cs.room_id,
        cs.days_json,
        fw.faculty_id
    FROM tbl_class_schedule cs
    INNER JOIN tbl_prospectus_offering o
        ON o.offering_id = cs.offering_id
    {$liveOfferingJoins}
    INNER JOIN tbl_program p
        ON p.program_id = o.program_id
    INNER JOIN tbl_college c
        ON c.college_id = p.college_id
    LEFT JOIN tbl_faculty_workload_sched fw
        ON fw.schedule_id = cs.schedule_id
       AND fw.ay_id = ?
       AND fw.semester = ?
    WHERE {$filterSql}
      AND c.status = 'active'
      AND p.status = 'active'
      AND o.ay_id = ?
      AND o.semester = ?
";

$weeklyStmt = $conn->prepare($weeklySql);
if ($weeklyStmt) {
    $weeklyStmt->bind_param("iiiii", $currentAyId, $currentSemester, $filterValue, $currentAyId, $currentSemester);
    $weeklyStmt->execute();
    $weeklyRes = $weeklyStmt->get_result();

    while ($row = $weeklyRes->fetch_assoc()) {
        $days = json_decode((string)($row['days_json'] ?? ''), true);

        if (!is_array($days) || empty($days)) {
            continue;
        }

        $scheduleId = (int)($row['schedule_id'] ?? 0);
        $roomId = (int)($row['room_id'] ?? 0);
        $facultyId = (int)($row['faculty_id'] ?? 0);

        foreach ($days as $day) {
            $token = normalize_dashboard_day_token($day);

            if ($token === '' || !isset($weeklyDaySets[$token]) || $scheduleId <= 0) {
                continue;
            }

            $weeklyDaySets[$token]['meetings'][$scheduleId] = true;

            if ($roomId > 0) {
                $weeklyDaySets[$token]['rooms'][$roomId] = true;
            }

            if ($facultyId > 0) {
                $weeklyDaySets[$token]['faculty'][$facultyId] = true;
            }
        }
    }

    $weeklyStmt->close();
}

$weeklyPressure = [];
foreach (dashboard_day_order() as $token) {
    $weeklyPressure[] = [
        'day' => dashboard_day_label($token),
        'meetings' => count($weeklyDaySets[$token]['meetings']),
        'rooms' => count($weeklyDaySets[$token]['rooms']),
        'faculty' => count($weeklyDaySets[$token]['faculty'])
    ];
}

$scheduleHeatmap = empty_dashboard_heatmap_payload();
$heatmapSlotLabels = [];
$heatmapDays = dashboard_heatmap_day_order();
$dayWindowStartMinutes = 7 * 60;
$dayWindowEndMinutes = 18 * 60;
$slotDefinitions = [];

for ($slotMinutes = $dayWindowStartMinutes; $slotMinutes < $dayWindowEndMinutes; $slotMinutes += 30) {
    $slotLabel = dashboard_slot_label($slotMinutes, $slotMinutes + 30);
    $slotDefinitions[] = [
        'label' => $slotLabel,
        'start' => $slotMinutes,
        'end' => $slotMinutes + 30
    ];
    $heatmapSlotLabels[] = $slotLabel;
}

$heatmapMatrix = [];
foreach ($heatmapDays as $dayKey) {
    $heatmapMatrix[$dayKey] = [];
    foreach ($heatmapSlotLabels as $slotLabel) {
        $heatmapMatrix[$dayKey][$slotLabel] = [];
    }
}

$roomPool = [];
$roomOptions = [];
$selectedRoomLabel = '';

if ($scope === 'campus') {
    $roomPoolSql = "
        SELECT DISTINCT
            r.room_id,
            r.room_code,
            r.room_name,
            r.college_id AS owner_college_id,
            owner.college_code AS owner_code
        FROM tbl_rooms r
        INNER JOIN tbl_college c
            ON c.college_id = r.college_id
        LEFT JOIN tbl_college owner
            ON owner.college_id = r.college_id
        WHERE c.campus_id = ?
          AND c.status = 'active'
          AND r.status = 'active'
        ORDER BY r.room_name ASC, r.room_code ASC, r.room_id ASC
    ";

    $roomPoolStmt = $conn->prepare($roomPoolSql);
    if ($roomPoolStmt instanceof mysqli_stmt) {
        $roomPoolStmt->bind_param('i', $filterValue);
    }
} elseif (synk_table_exists($conn, 'tbl_room_college_access')) {
    $roomPoolSql = "
        SELECT DISTINCT
            r.room_id,
            r.room_code,
            r.room_name,
            r.college_id AS owner_college_id,
            owner.college_code AS owner_code
        FROM tbl_room_college_access acc
        INNER JOIN tbl_rooms r
            ON r.room_id = acc.room_id
        LEFT JOIN tbl_college owner
            ON owner.college_id = r.college_id
        WHERE acc.college_id = ?
          AND acc.ay_id = ?
          AND acc.semester = ?
          AND r.status = 'active'
        ORDER BY r.room_name ASC, r.room_code ASC, r.room_id ASC
    ";

    $roomPoolStmt = $conn->prepare($roomPoolSql);
    if ($roomPoolStmt instanceof mysqli_stmt) {
        $roomPoolStmt->bind_param('iii', $filterValue, $currentAyId, $currentSemester);
    }
} else {
    $roomPoolSql = "
        SELECT DISTINCT
            r.room_id,
            r.room_code,
            r.room_name,
            r.college_id AS owner_college_id,
            owner.college_code AS owner_code
        FROM tbl_rooms r
        LEFT JOIN tbl_college owner
            ON owner.college_id = r.college_id
        WHERE r.college_id = ?
          AND r.status = 'active'
        ORDER BY r.room_name ASC, r.room_code ASC, r.room_id ASC
    ";

    $roomPoolStmt = $conn->prepare($roomPoolSql);
    if ($roomPoolStmt instanceof mysqli_stmt) {
        $roomPoolStmt->bind_param('i', $filterValue);
    }
}

if (isset($roomPoolStmt) && $roomPoolStmt instanceof mysqli_stmt) {
    $roomPoolStmt->execute();
    $roomPoolRes = $roomPoolStmt->get_result();

    while ($roomRow = $roomPoolRes->fetch_assoc()) {
        $roomId = (int)($roomRow['room_id'] ?? 0);
        if ($roomId > 0) {
            $roomPool[$roomId] = true;
            $roomLabel = dashboard_room_label($roomRow, $scope, $collegeId);
            $roomOptions[] = [
                'room_id' => $roomId,
                'label' => $roomLabel
            ];

            if ($roomId === $requestedRoomId) {
                $selectedRoomLabel = $roomLabel;
            }
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

$scheduleHeatmap['room_options'] = $roomOptions;
$scheduleHeatmap['selected_room_id'] = $selectedRoomId;
$scheduleHeatmap['selected_room_label'] = $selectedRoomId > 0 ? $selectedRoomLabel : '';
$scheduleHeatmap['view_mode'] = $selectedRoomId > 0 ? 'room' : 'pool';
$scheduleHeatmap['total_rooms'] = count($heatmapRoomIds);

if (!empty($heatmapRoomIds)) {
    $roomIdList = implode(',', array_map('intval', array_keys($heatmapRoomIds)));
    $heatmapSql = "
        SELECT
            cs.room_id,
            cs.time_start,
            cs.time_end,
            cs.days_json
        FROM tbl_class_schedule cs
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        WHERE o.ay_id = ?
          AND o.semester = ?
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
                dashboard_decode_days((string)($heatmapRow['days_json'] ?? '')),
                $heatmapDays
            ));
            $startMinutes = dashboard_time_to_minutes((string)($heatmapRow['time_start'] ?? ''));
            $endMinutes = dashboard_time_to_minutes((string)($heatmapRow['time_end'] ?? ''));
            $meetingHours = dashboard_overlap_hours(
                $startMinutes,
                $endMinutes,
                $dayWindowStartMinutes,
                $dayWindowEndMinutes
            );

            if (count($meetingDays) === 0 || $meetingHours <= 0) {
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

    foreach ($heatmapDays as $dayKey) {
        $seriesData = [];
        $dayOccupiedSlots = 0;

        foreach ($heatmapSlotLabels as $slotLabel) {
            $occupiedRooms = count($heatmapMatrix[$dayKey][$slotLabel]);
            $occupancyPercent = $scheduleHeatmap['total_rooms'] > 0
                ? round(($occupiedRooms / $scheduleHeatmap['total_rooms']) * 100, 1)
                : 0.0;

            $seriesData[] = [
                'x' => $slotLabel,
                'y' => $occupancyPercent
            ];

            if ($occupiedRooms > 0) {
                $scheduleHeatmap['occupied_slot_count']++;
                $dayOccupiedSlots++;
            }

            if ($occupancyPercent > $scheduleHeatmap['peak_percent']) {
                $scheduleHeatmap['peak_percent'] = $occupancyPercent;
                $scheduleHeatmap['peak_occupied_rooms'] = $occupiedRooms;
                $scheduleHeatmap['peak_label'] = dashboard_day_label($dayKey) . ' - ' . $slotLabel;
            }
        }

        if ($dayOccupiedSlots > $scheduleHeatmap['busiest_day_slots']) {
            $scheduleHeatmap['busiest_day_slots'] = $dayOccupiedSlots;
            $scheduleHeatmap['busiest_day_label'] = dashboard_day_label($dayKey);
        }

        $scheduleHeatmap['series'][] = [
            'name' => dashboard_day_label($dayKey),
            'data' => $seriesData
        ];
    }
}

if (empty($scheduleHeatmap['series'])) {
    foreach ($heatmapDays as $dayKey) {
        $seriesData = [];

        foreach ($heatmapSlotLabels as $slotLabel) {
            $seriesData[] = [
                'x' => $slotLabel,
                'y' => 0
            ];
        }

        $scheduleHeatmap['series'][] = [
            'name' => dashboard_day_label($dayKey),
            'data' => $seriesData
        ];
    }
}

echo json_encode([
    'scope' => $scope,
    'scope_label' => $scopeLabel,
    'program_progress' => $programProgress,
    'weekly_pressure' => $weeklyPressure,
    'schedule_heatmap' => $scheduleHeatmap
]);
exit;
?>
