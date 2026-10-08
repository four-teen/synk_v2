<?php
session_start();
ob_start();
include '../backend/db.php';
require_once '../backend/academic_term_helper.php';
require_once '../backend/academic_schedule_policy_helper.php';
require_once '../backend/faculty_need_helper.php';
require_once '../backend/offering_scope_helper.php';
require_once '../backend/schema_helper.php';
require_once '../backend/schedule_block_helper.php';
require_once '../backend/signatory_settings_helper.php';

function campus_dashboard_day_token(string $day): string
{
    $token = strtoupper(trim($day));
    return $token === 'TH' ? 'Th' : $token;
}

function campus_dashboard_day_label(string $day): string
{
    $labels = [
        'M' => 'Mon',
        'T' => 'Tue',
        'W' => 'Wed',
        'Th' => 'Thu',
        'F' => 'Fri',
        'S' => 'Sat',
    ];

    return $labels[$day] ?? $day;
}

function campus_dashboard_decode_days(string $daysJson): array
{
    $decoded = json_decode($daysJson, true);
    if (!is_array($decoded)) {
        return [];
    }

    $unique = [];
    foreach ($decoded as $day) {
        $token = campus_dashboard_day_token((string)$day);
        if ($token !== '') {
            $unique[$token] = true;
        }
    }

    return array_keys($unique);
}

function campus_dashboard_time_to_minutes(string $time): int
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

function campus_dashboard_minutes_to_label(int $minutes): string
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

function campus_dashboard_overlap_hours(int $startMinutes, int $endMinutes, int $windowStartMinutes, int $windowEndMinutes): float
{
    $effectiveStart = max($startMinutes, $windowStartMinutes);
    $effectiveEnd = min($endMinutes, $windowEndMinutes);

    if ($effectiveEnd <= $effectiveStart) {
        return 0.0;
    }

    return ($effectiveEnd - $effectiveStart) / 60;
}

function campus_dashboard_title_case(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = strtolower($value);
    $value = preg_replace_callback('/(^|[\s,\/-])([a-z])/', static function ($matches) {
        return $matches[1] . strtoupper($matches[2]);
    }, $value);

    return (string)$value;
}

function campus_dashboard_format_faculty_name(array $row): string
{
    $fullName = trim((string)($row['last_name'] ?? ''));
    $firstName = trim((string)($row['first_name'] ?? ''));
    $middleName = trim((string)($row['middle_name'] ?? ''));
    $extName = trim((string)($row['ext_name'] ?? ''));

    if ($firstName !== '') {
        $fullName .= ($fullName !== '' ? ', ' : '') . $firstName;
    }

    if ($middleName !== '') {
        $fullName .= ' ' . strtoupper(substr($middleName, 0, 1)) . '.';
    }

    if ($extName !== '') {
        $fullName .= ', ' . $extName;
    }

    return trim($fullName, " ,");
}

function campus_dashboard_context_key(int $groupId, int $scheduleId, int $offeringId): string
{
    if ($groupId > 0) {
        return 'group:' . $groupId;
    }

    if ($scheduleId > 0) {
        return 'schedule:' . $scheduleId;
    }

    return 'offering:' . $offeringId;
}

function campus_dashboard_unique_labels(array $labels): array
{
    $bucket = [];
    foreach ($labels as $label) {
        $label = trim((string)$label);
        if ($label !== '') {
            $bucket[$label] = true;
        }
    }

    $labels = array_keys($bucket);
    natcasesort($labels);
    return array_values($labels);
}

function campus_dashboard_format_load_value(float $value): string
{
    if (abs($value - round($value)) < 0.01) {
        return number_format($value, 0);
    }

    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

function campus_dashboard_report_signatory_slot(array $settings, string $slotCode, string $fallbackLabel): array
{
    $row = is_array($settings[$slotCode] ?? null) ? $settings[$slotCode] : [];

    return [
        'slot_code' => $slotCode,
        'label' => trim((string)($row['label'] ?? $fallbackLabel)) ?: $fallbackLabel,
        'signatory_name' => trim((string)($row['signatory_name'] ?? '')),
        'signatory_title' => trim((string)($row['signatory_title'] ?? '')),
    ];
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

if ($_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = (string)$_SESSION['csrf_token'];

$currentTerm = synk_fetch_current_academic_term($conn);
$currentAyId = (int)($currentTerm['ay_id'] ?? 0);
$currentSem = (int)($currentTerm['semester'] ?? 0);
$academicTermText = trim((string)($currentTerm['term_text'] ?? 'Current academic term'));
$academicTermTextEscaped = htmlspecialchars($academicTermText, ENT_QUOTES, 'UTF-8');

// -----------------------------------------
// GET CAMPUS ID
// -----------------------------------------
$campusIdParam = $_GET['campus_id'] ?? null;

if ($campusIdParam === null) {
    // No campus id – go back to main dashboard
    header("Location: index.php");
    exit;
}

$isUniversitySummary = ($campusIdParam === 'all');

// -----------------------------------------
// OPTIONAL: GET COLLEGE ID (FILTER MODE)
// -----------------------------------------
$collegeIdParam = $_GET['college_id'] ?? null;
$selectedCollegeId = null;
$selectedCollegeName = null;
$selectedCollegeSchedulePolicy = [
    'source_label' => 'Scheduling policy',
    'window_label' => ''
];

if (!$isUniversitySummary && $collegeIdParam !== null && $collegeIdParam !== '') {
    $selectedCollegeId = (int)$collegeIdParam;
}


// -----------------------------------------
// LOAD CAMPUS INFO
// -----------------------------------------
$campusName = "University Summary";
$campusCode = "SKSU";

if (!$isUniversitySummary) {
    $campusId = (int)$campusIdParam;

    $stmt = $conn->prepare("
        SELECT campus_id, campus_code, campus_name
        FROM tbl_campus
        WHERE campus_id = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->bind_param("i", $campusId);
    $stmt->execute();
    $resCampus = $stmt->get_result();

    if ($resCampus->num_rows === 0) {
        // Invalid campus – redirect back
        header("Location: index.php");
        exit;
    }

    $campusRow  = $resCampus->fetch_assoc();
    $campusName = $campusRow['campus_name'];
    $campusCode = $campusRow['campus_code'];
    $stmt->close();
}

// -----------------------------------------
// VALIDATE SELECTED COLLEGE UNDER THIS CAMPUS
// -----------------------------------------
if (!$isUniversitySummary && $selectedCollegeId) {

    $stmt = $conn->prepare("
        SELECT college_id, college_name
        FROM tbl_college
        WHERE college_id = ?
          AND campus_id  = ?
          AND status     = 'active'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $selectedCollegeId, $campusId);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res->num_rows === 0) {
        // invalid college for this campus → reset filter safely
        $selectedCollegeId = null;
        $selectedCollegeName = null;
    } else {
        $row = $res->fetch_assoc();
        $selectedCollegeName = $row['college_name'];
        $selectedCollegeSchedulePolicy = synk_fetch_effective_schedule_policy($conn, $selectedCollegeId);
    }
    $stmt->close();
}



// -----------------------------------------
// LOAD COLLEGES UNDER SELECTED CAMPUS
// -----------------------------------------
$colleges = [];

if (!$isUniversitySummary) {
    $stmt = $conn->prepare("
        SELECT college_id, college_code, college_name
        FROM tbl_college
        WHERE campus_id = ?
          AND status = 'active'
        ORDER BY college_name ASC
    ");
    $stmt->bind_param("i", $campusId);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $colleges[] = $row;
    }
    $stmt->close();
}


// -----------------------------------------
// ROOMS & ROOM-BY-COLLEGE DATA
// -----------------------------------------
$rooms          = [];
$roomCount      = 0;
$collegeLabels  = [];
$collegeRoomCnt = [];

if ($isUniversitySummary) {
    // All campuses – aggregate per campus
    $roomQuery = "
        SELECT 
            c.campus_name AS label,
            COUNT(r.room_id) AS room_count
        FROM tbl_rooms r
        INNER JOIN tbl_college col ON col.college_id = r.college_id
        INNER JOIN tbl_campus c ON c.campus_id = col.campus_id
        GROUP BY c.campus_id
        ORDER BY c.campus_name ASC
    ";

    $rs = $conn->query($roomQuery);
    while ($row = $rs->fetch_assoc()) {
        $collegeLabels[]  = $row['label'];
        $collegeRoomCnt[] = (int)$row['room_count'];
        $roomCount       += (int)$row['room_count'];
    }

    // Load list of rooms (all campuses) – simple list
    $roomListQuery = "
        SELECT 
            r.room_id,
            r.room_name,
            r.room_code,
            col.college_name,
            c.campus_name
        FROM tbl_rooms r
        INNER JOIN tbl_college col ON col.college_id = r.college_id
        INNER JOIN tbl_campus c   ON c.campus_id = col.campus_id
        ORDER BY c.campus_name, col.college_name, r.room_name
    ";
} else {
    // Single campus – chart per college (or rooms for a selected college)
    if ($selectedCollegeId) {

        // Chart becomes: one line entry for the selected college only
        $stmt = $conn->prepare("
            SELECT 
                col.college_name AS label,
                COUNT(r.room_id) AS room_count
            FROM tbl_rooms r
            INNER JOIN tbl_college col ON col.college_id = r.college_id
            WHERE col.college_id = ?
            GROUP BY col.college_id
            ORDER BY col.college_name ASC
        ");
        $stmt->bind_param("i", $selectedCollegeId);

    } else {

        $stmt = $conn->prepare("
            SELECT 
                col.college_name AS label,
                COUNT(r.room_id) AS room_count
            FROM tbl_rooms r
            INNER JOIN tbl_college col ON col.college_id = r.college_id
            WHERE col.campus_id = ?
            GROUP BY col.college_id
            ORDER BY col.college_name ASC
        ");
        $stmt->bind_param("i", $campusId);
    }

    $stmt->execute();
    $rs = $stmt->get_result();

    $collegeLabels  = [];
    $collegeRoomCnt = [];
    $roomCount      = 0;

    while ($row = $rs->fetch_assoc()) {
        $collegeLabels[]  = $row['label'];
        $collegeRoomCnt[] = (int)$row['room_count'];
        $roomCount       += (int)$row['room_count'];
    }
    $stmt->close();

    if ($selectedCollegeId) {
        $roomListQuery = "
            SELECT 
                r.room_id,
                r.room_name,
                r.room_code,
                col.college_name
            FROM tbl_rooms r
            INNER JOIN tbl_college col ON col.college_id = r.college_id
            WHERE col.college_id = {$selectedCollegeId}
            ORDER BY col.college_name, r.room_name
        ";
    } else {
        $roomListQuery = "
            SELECT 
                r.room_id,
                r.room_name,
                r.room_code,
                col.college_name
            FROM tbl_rooms r
            INNER JOIN tbl_college col ON col.college_id = r.college_id
            WHERE col.campus_id = {$campusId}
            ORDER BY col.college_name, r.room_name
        ";
    }

}

// Execute the room list query (for table)
$rsRooms = $conn->query($roomListQuery);
while ($row = $rsRooms->fetch_assoc()) {
    $rooms[] = $row;
}

// -----------------------------------------
// CURRENT TERM ANALYTICS
// -----------------------------------------

$analyticsReady = ($currentAyId > 0 && $currentSem > 0);
$heatmapSlotLabels = [];
$heatmapSeries = [];
$heatmapPeakLabel = 'No occupied room slots';
$scheduleSummary = [
    'scheduled_classes' => 0,
    'assigned_classes' => 0,
    'unassigned_classes' => 0,
];
$utilizationRows = [];
$utilizationChartLabels = [];
$utilizationChartUsed = [];
$utilizationChartAvailable = [];
$utilizationMetricLabel = $isUniversitySummary ? 'Campus' : ($selectedCollegeId ? 'Room' : 'College');
$utilizationHeading = $isUniversitySummary
    ? 'Room Utilization by Campus'
    : ($selectedCollegeId ? 'Accessible Room Utilization' : 'Room Utilization by College');
$heatmapSubtext = $isUniversitySummary
    ? 'Each cell shows the percentage of rooms occupied across all campuses in the current-term weekday window.'
    : ($selectedCollegeId
        ? 'Each cell shows the percentage of this college\'s accessible rooms occupied in the current-term weekday window.'
        : 'Each cell shows the percentage of campus rooms occupied in the current-term weekday window.');
$utilizationSubtext = $isUniversitySummary
    ? 'Compares scheduled room-hours against the university\'s 40-hour weekly baseline for each campus room pool.'
    : ($selectedCollegeId
        ? 'Compares scheduled room-hours against the university\'s 40-hour weekly baseline for rooms this college can use.'
        : 'Compares scheduled room-hours against the university\'s 40-hour weekly baseline for each college room pool.');
$resourceCapacityNote = 'Utilization compares actual scheduled room-hours against the university standard of 40 room-hours per week per room. The heatmap above still shows real 30-minute activity from Monday to Friday, 7:00 AM to 6:00 PM, so utilization may exceed 100% when rooms run beyond the standard weekly load.';
$assignmentCoveragePercent = 0.0;
$highestUtilizationLabel = 'No room data';
$highestUtilizationPercent = 0.0;
$heatmapPeakOccupiedRooms = 0;
$heatmapPeakPercent = 0.0;
$heatmapTotalRooms = 0;

if ($analyticsReady) {
    $daysOrder = ['M', 'T', 'W', 'Th', 'F'];
    $dayWindowStartMinutes = 7 * 60;
    $dayWindowEndMinutes = 18 * 60;
    $slotDefinitions = [];
    for ($slotMinutes = $dayWindowStartMinutes; $slotMinutes < $dayWindowEndMinutes; $slotMinutes += 60) {
        $slotLabel = campus_dashboard_minutes_to_label($slotMinutes);
        $slotDefinitions[] = [
            'label' => $slotLabel,
            'start' => $slotMinutes,
            'end' => $slotMinutes + 60,
        ];
        $heatmapSlotLabels[] = $slotLabel;
    }

    $heatmapMatrix = [];
    foreach ($daysOrder as $dayKey) {
        $heatmapMatrix[$dayKey] = [];
        foreach ($heatmapSlotLabels as $slotLabel) {
            $heatmapMatrix[$dayKey][$slotLabel] = [];
        }
    }

    $scheduleScopeSql = '';
    $scheduleScopeTypes = '';
    $scheduleScopeParams = [];

    if ($selectedCollegeId) {
        $scheduleScopeSql = ' AND col.college_id = ?';
        $scheduleScopeTypes = 'i';
        $scheduleScopeParams[] = $selectedCollegeId;
    } elseif (!$isUniversitySummary) {
        $scheduleScopeSql = ' AND camp.campus_id = ?';
        $scheduleScopeTypes = 'i';
        $scheduleScopeParams[] = $campusId;
    }

    $assignmentSourceSql = [
        "
            SELECT DISTINCT schedule_id, 1 AS has_assignment
            FROM tbl_faculty_workload_sched
            WHERE ay_id = ?
              AND semester = ?
        "
    ];
    $assignmentSourceTypes = 'ii';
    $assignmentSourceParams = [$currentAyId, $currentSem];

    if (synk_table_exists($conn, 'tbl_faculty_need_workload_sched')) {
        $assignmentSourceSql[] = "
            SELECT DISTINCT schedule_id, 1 AS has_assignment
            FROM tbl_faculty_need_workload_sched
            WHERE ay_id = ?
              AND semester = ?
        ";
        $assignmentSourceTypes .= 'ii';
        $assignmentSourceParams[] = $currentAyId;
        $assignmentSourceParams[] = $currentSem;
    }

    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('po', 'sec', 'sc', 'ps', 'pys', 'ph');
    $scheduleSql = "
        SELECT
            cs.schedule_id,
            cs.days_json,
            cs.time_start,
            cs.time_end,
            COALESCE(fa.has_assignment, 0) AS has_assignment
        FROM tbl_class_schedule cs
        INNER JOIN tbl_prospectus_offering po
            ON po.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = sec.program_id
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        INNER JOIN tbl_campus camp
            ON camp.campus_id = col.campus_id
        LEFT JOIN (
            " . implode("\n            UNION\n", $assignmentSourceSql) . "
        ) fa
            ON fa.schedule_id = cs.schedule_id
        WHERE po.ay_id = ?
          AND po.semester = ?
          {$scheduleScopeSql}
        ORDER BY cs.time_start ASC, cs.schedule_id ASC
    ";

    $stmt = $conn->prepare($scheduleSql);
    $scheduleParams = array_merge($assignmentSourceParams, [$currentAyId, $currentSem]);
    foreach ($scheduleScopeParams as $scopeParam) {
        $scheduleParams[] = $scopeParam;
    }
    $scheduleTypes = $assignmentSourceTypes . 'ii' . $scheduleScopeTypes;

    if ($stmt && synk_bind_dynamic_params($stmt, $scheduleTypes, $scheduleParams)) {
        $stmt->execute();
        $scheduleResult = $stmt->get_result();

        while ($scheduleRow = $scheduleResult->fetch_assoc()) {
            $scheduleSummary['scheduled_classes']++;
            if ((int)$scheduleRow['has_assignment'] === 1) {
                $scheduleSummary['assigned_classes']++;
            } else {
                $scheduleSummary['unassigned_classes']++;
            }
        }

        $stmt->close();
    } elseif ($stmt) {
        $stmt->close();
    }
    unset($stmt);

    $roomCapacityPerWeek = 40.0;
    $roomPool = [];
    $roomPoolResult = null;

    if ($selectedCollegeId) {
        if (synk_table_exists($conn, 'tbl_room_college_access')) {
            $roomPoolSql = "
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
                WHERE acc.college_id = ?
                  AND acc.ay_id = ?
                  AND acc.semester = ?
                  AND r.status = 'active'
                ORDER BY r.room_name ASC, r.room_code ASC
            ";

            $stmt = $conn->prepare($roomPoolSql);
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('iii', $selectedCollegeId, $currentAyId, $currentSem);
            }
        } else {
            $roomPoolSql = "
                SELECT
                    r.room_id,
                    r.room_code,
                    r.room_name
                FROM tbl_rooms r
                WHERE r.college_id = ?
                  AND r.status = 'active'
                ORDER BY r.room_name ASC, r.room_code ASC
            ";

            $stmt = $conn->prepare($roomPoolSql);
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $selectedCollegeId);
            }
        }

        if ($stmt instanceof mysqli_stmt) {
            $stmt->execute();
            $roomPoolResult = $stmt->get_result();

            while ($roomRow = $roomPoolResult->fetch_assoc()) {
                $roomId = (int)$roomRow['room_id'];
                $roomCode = trim((string)($roomRow['room_code'] ?? ''));
                $roomName = trim((string)($roomRow['room_name'] ?? ''));
                $roomLabel = $roomName !== '' ? $roomName : $roomCode;
                if ($roomCode !== '' && $roomName !== '' && strcasecmp($roomCode, $roomName) !== 0) {
                    $roomLabel = $roomCode . ' - ' . $roomName;
                }

                if (strtolower(trim((string)($roomRow['access_type'] ?? 'owner'))) === 'shared') {
                    $ownerCode = trim((string)($roomRow['owner_code'] ?? ''));
                    if ($ownerCode !== '') {
                        $roomLabel .= ' (Shared from ' . $ownerCode . ')';
                    }
                }

                $roomPool[$roomId] = [
                    'category_key' => (string)$roomId,
                    'category_label' => $roomLabel,
                    'used_hours' => 0.0,
                ];
            }

            $stmt->close();
        }
        unset($stmt);
    } else {
        if ($isUniversitySummary) {
            $roomPoolSql = "
                SELECT
                    r.room_id,
                    camp.campus_id AS category_key,
                    camp.campus_name AS category_label
                FROM tbl_rooms r
                INNER JOIN tbl_college col
                    ON col.college_id = r.college_id
                INNER JOIN tbl_campus camp
                    ON camp.campus_id = col.campus_id
                WHERE r.status = 'active'
                ORDER BY camp.campus_name ASC, r.room_name ASC, r.room_code ASC
            ";

            $roomPoolResult = $conn->query($roomPoolSql);
        } else {
            $stmt = $conn->prepare("
                SELECT
                    r.room_id,
                    col.college_id AS category_key,
                    col.college_name AS category_label
                FROM tbl_rooms r
                INNER JOIN tbl_college col
                    ON col.college_id = r.college_id
                WHERE col.campus_id = ?
                  AND r.status = 'active'
                ORDER BY col.college_name ASC, r.room_name ASC, r.room_code ASC
            ");
            if ($stmt instanceof mysqli_stmt) {
                $stmt->bind_param('i', $campusId);
                $stmt->execute();
                $roomPoolResult = $stmt->get_result();
            }
        }

        if ($roomPoolResult instanceof mysqli_result) {
            while ($roomRow = $roomPoolResult->fetch_assoc()) {
                $roomId = (int)$roomRow['room_id'];
                $categoryKey = (string)$roomRow['category_key'];
                $roomPool[$roomId] = [
                    'category_key' => $categoryKey,
                    'category_label' => (string)$roomRow['category_label'],
                    'used_hours' => 0.0,
                ];
            }
        }

        if (isset($stmt) && $stmt instanceof mysqli_stmt) {
            $stmt->close();
            unset($stmt);
        }
    }

    if (!empty($roomPool)) {
        $roomIds = array_keys($roomPool);
        $roomIdList = implode(',', array_map('intval', $roomIds));
        $roomUsageSql = "
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

        $stmt = $conn->prepare($roomUsageSql);
        if ($stmt instanceof mysqli_stmt) {
            $stmt->bind_param('ii', $currentAyId, $currentSem);
            $stmt->execute();
            $roomUsageResult = $stmt->get_result();
            $heatmapTotalRooms = count($roomPool);

            while ($usageRow = $roomUsageResult->fetch_assoc()) {
                $roomId = (int)$usageRow['room_id'];
                if (!isset($roomPool[$roomId])) {
                    continue;
                }

                $meetingDays = array_values(array_intersect(
                    campus_dashboard_decode_days((string)($usageRow['days_json'] ?? '')),
                    $daysOrder
                ));
                $startMinutes = campus_dashboard_time_to_minutes((string)($usageRow['time_start'] ?? ''));
                $endMinutes = campus_dashboard_time_to_minutes((string)($usageRow['time_end'] ?? ''));
                $meetingHours = campus_dashboard_overlap_hours(
                    $startMinutes,
                    $endMinutes,
                    $dayWindowStartMinutes,
                    $dayWindowEndMinutes
                );

                if (count($meetingDays) === 0 || $meetingHours <= 0) {
                    continue;
                }

                $roomPool[$roomId]['used_hours'] += ($meetingHours * count($meetingDays));

                foreach ($meetingDays as $dayKey) {
                    foreach ($slotDefinitions as $slotDefinition) {
                        if ($slotDefinition['start'] < $endMinutes && $slotDefinition['end'] > $startMinutes) {
                            $heatmapMatrix[$dayKey][$slotDefinition['label']][$roomId] = true;
                        }
                    }
                }
            }

            $stmt->close();
        }
        unset($stmt);

        foreach ($daysOrder as $dayKey) {
            $seriesData = [];
            foreach ($heatmapSlotLabels as $slotLabel) {
                $occupiedRooms = count($heatmapMatrix[$dayKey][$slotLabel]);
                $occupancyPercent = $heatmapTotalRooms > 0
                    ? round(($occupiedRooms / $heatmapTotalRooms) * 100, 1)
                    : 0.0;

                $seriesData[] = [
                    'x' => $slotLabel,
                    'y' => $occupancyPercent,
                ];

                if ($occupancyPercent > $heatmapPeakPercent) {
                    $heatmapPeakPercent = $occupancyPercent;
                    $heatmapPeakOccupiedRooms = $occupiedRooms;
                    $heatmapPeakLabel = campus_dashboard_day_label($dayKey) . ' - ' . $slotLabel;
                }
            }

            $heatmapSeries[] = [
                'name' => campus_dashboard_day_label($dayKey),
                'data' => $seriesData,
            ];
        }

        $utilizationCategoryIndex = [];
        foreach ($roomPool as $roomMeta) {
            $categoryKey = $roomMeta['category_key'];
            if (!isset($utilizationCategoryIndex[$categoryKey])) {
                $utilizationCategoryIndex[$categoryKey] = [
                    'label' => $roomMeta['category_label'],
                    'room_count' => 0,
                    'used_hours' => 0.0,
                ];
            }

            $utilizationCategoryIndex[$categoryKey]['room_count']++;
            $utilizationCategoryIndex[$categoryKey]['used_hours'] += $roomMeta['used_hours'];
        }

        foreach ($utilizationCategoryIndex as $categoryMeta) {
            $capacityHours = $categoryMeta['room_count'] * $roomCapacityPerWeek;
            $usedHours = round($categoryMeta['used_hours'], 1);
            $availableHours = round(max($capacityHours - $categoryMeta['used_hours'], 0), 1);
            $utilizationPercent = $capacityHours > 0
                ? round(($categoryMeta['used_hours'] / $capacityHours) * 100, 1)
                : 0.0;

            $utilizationRows[] = [
                'label' => $categoryMeta['label'],
                'room_count' => (int)$categoryMeta['room_count'],
                'used_hours' => $usedHours,
                'available_hours' => $availableHours,
                'capacity_hours' => round($capacityHours, 1),
                'utilization_percent' => $utilizationPercent,
            ];
        }

        usort($utilizationRows, static function (array $a, array $b): int {
            if ($a['utilization_percent'] === $b['utilization_percent']) {
                return strcasecmp($a['label'], $b['label']);
            }

            return $b['utilization_percent'] <=> $a['utilization_percent'];
        });

        foreach ($utilizationRows as $utilizationRow) {
            $utilizationChartLabels[] = $utilizationRow['label'];
            $utilizationChartUsed[] = $utilizationRow['used_hours'];
            $utilizationChartAvailable[] = $utilizationRow['available_hours'];
        }

        if (!empty($utilizationRows)) {
            $highestUtilizationLabel = (string)$utilizationRows[0]['label'];
            $highestUtilizationPercent = (float)$utilizationRows[0]['utilization_percent'];
        }
    }

    if (empty($heatmapSeries)) {
        foreach ($daysOrder as $dayKey) {
            $seriesData = [];
            foreach ($heatmapSlotLabels as $slotLabel) {
                $seriesData[] = [
                    'x' => $slotLabel,
                    'y' => 0,
                ];
            }

            $heatmapSeries[] = [
                'name' => campus_dashboard_day_label($dayKey),
                'data' => $seriesData,
            ];
        }
    }

    if ($scheduleSummary['scheduled_classes'] > 0) {
        $assignmentCoveragePercent = round(
            ($scheduleSummary['assigned_classes'] / $scheduleSummary['scheduled_classes']) * 100,
            1
        );
    }
}


// -----------------------------------------
// CAMPUS METRICS (FACULTY, PROGRAMS, SECTIONS, ROOMS)
// -----------------------------------------

$facultyCount = 0;
$programCount = 0;
$sectionCount = 0;

if ($isUniversitySummary) {

    // ALL FACULTY
    $facultyCount = $conn->query("
        SELECT COUNT(*) AS cnt
        FROM tbl_faculty
        WHERE status='active'
    ")->fetch_assoc()['cnt'];

    // ALL PROGRAMS
    $programCount = $conn->query("
        SELECT COUNT(*) AS cnt
        FROM tbl_program
        WHERE status='active'
    ")->fetch_assoc()['cnt'];

    // ALL SECTIONS
    $sectionCount = $conn->query("
        SELECT COUNT(*) AS cnt
        FROM tbl_sections
        WHERE status='active'
    ")->fetch_assoc()['cnt'];

} else {

    // ----------------------------
    // FACULTY count (campus or college)
    // ----------------------------
    if ($selectedCollegeId) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM tbl_faculty f
            INNER JOIN tbl_college_faculty cf ON cf.faculty_id = f.faculty_id
            WHERE cf.college_id = ?
              AND f.status = 'active'
              AND cf.status = 'active'
        ");
        $stmt->bind_param("i", $selectedCollegeId);
        $stmt->execute();
        $facultyCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();
    } else {
        $facultyCount = (int)$conn->query("
            SELECT COUNT(*) AS cnt
            FROM tbl_faculty f
            INNER JOIN tbl_college_faculty cf ON cf.faculty_id = f.faculty_id
            INNER JOIN tbl_college c ON c.college_id = cf.college_id
            WHERE c.campus_id = {$campusId}
              AND f.status = 'active'
              AND cf.status = 'active'
        ")->fetch_assoc()['cnt'];
    }

    // ----------------------------
    // PROGRAM count (campus or college)
    // ----------------------------
    if ($selectedCollegeId) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM tbl_program p
            WHERE p.college_id = ?
              AND p.status = 'active'
        ");
        $stmt->bind_param("i", $selectedCollegeId);
        $stmt->execute();
        $programCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();
    } else {
        $programCount = (int)$conn->query("
            SELECT COUNT(*) AS cnt
            FROM tbl_program p
            INNER JOIN tbl_college c ON c.college_id = p.college_id
            WHERE c.campus_id = {$campusId}
              AND p.status = 'active'
        ")->fetch_assoc()['cnt'];
    }

    // ----------------------------
    // SECTION count (campus or college)
    // ----------------------------
    if ($selectedCollegeId) {
        $stmt = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM tbl_sections s
            INNER JOIN tbl_program p ON p.program_id = s.program_id
            WHERE p.college_id = ?
              AND s.status = 'active'
        ");
        $stmt->bind_param("i", $selectedCollegeId);
        $stmt->execute();
        $sectionCount = (int)$stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();
    } else {
        $sectionCount = (int)$conn->query("
            SELECT COUNT(*) AS cnt
            FROM tbl_sections s
            INNER JOIN tbl_program p ON p.program_id = s.program_id
            INNER JOIN tbl_college c ON c.college_id = p.college_id
            WHERE c.campus_id = {$campusId}
              AND s.status = 'active'
        ")->fetch_assoc()['cnt'];
    }
}


$facultyDirectoryScopeLabel = $selectedCollegeId
    ? (string)($selectedCollegeName ?? 'Selected College')
    : ($isUniversitySummary ? 'All Campuses' : $campusName . ' Campus');
$facultyDirectoryScopeLabelEscaped = htmlspecialchars($facultyDirectoryScopeLabel, ENT_QUOTES, 'UTF-8');
$facultyDirectory = [
    'rows' => [],
    'total' => 0,
    'faculty_with_workload' => 0,
    'faculty_with_designation' => 0,
    'inactive_total' => 0,
    'assigned_classes_total' => 0,
    'average_total_load' => 0.0,
    'scope_label' => $facultyDirectoryScopeLabel,
    'term_text' => $academicTermText,
    'workload_ready' => $analyticsReady,
    'note' => $analyticsReady
        ? 'Directory details follow the active academic term and current campus or college filter.'
        : 'Faculty master data is available, but workload figures will populate once the academic term is configured.',
];

if (
    synk_table_exists($conn, 'tbl_faculty')
    && synk_table_exists($conn, 'tbl_college_faculty')
    && synk_table_exists($conn, 'tbl_college')
) {
    $assignmentHasAyId = synk_table_has_column($conn, 'tbl_college_faculty', 'ay_id');
    $assignmentHasSemester = synk_table_has_column($conn, 'tbl_college_faculty', 'semester');
    $facultyHasMiddleName = synk_table_has_column($conn, 'tbl_faculty', 'middle_name');
    $facultyHasExtName = synk_table_has_column($conn, 'tbl_faculty', 'ext_name');
    $facultyHasDesignationId = synk_table_has_column($conn, 'tbl_faculty', 'designation_id');
    $facultyHasStatus = synk_table_has_column($conn, 'tbl_faculty', 'status');
    $facultyDesignationTextColumn = null;
    foreach (['designation', 'designation_name'] as $candidate) {
        if (synk_table_has_column($conn, 'tbl_faculty', $candidate)) {
            $facultyDesignationTextColumn = $candidate;
            break;
        }
    }

    $designationTableExists = synk_table_exists($conn, 'tbl_designation');
    $designationHasName = $designationTableExists && synk_table_has_column($conn, 'tbl_designation', 'designation_name');
    $designationHasUnits = $designationTableExists && synk_table_has_column($conn, 'tbl_designation', 'designation_units');
    $designationHasStatus = $designationTableExists && synk_table_has_column($conn, 'tbl_designation', 'status');
    $classScheduleHasGroupId = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_group_id');
    $classScheduleHasType = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type');

    $designationJoinSql = '';
    $designationNameExpr = "''";
    $designationUnitsExpr = '0';
    $facultyDesignationExpr = $facultyDesignationTextColumn !== null
        ? "NULLIF(TRIM(f.`{$facultyDesignationTextColumn}`), '')"
        : 'NULL';

    if ($facultyHasDesignationId) {
        $designationSelectId = 'f.designation_id';
    } else {
        $designationSelectId = '0';
    }

    if ($facultyHasDesignationId && $designationTableExists && $designationHasName) {
        $designationJoinSql = "
            LEFT JOIN tbl_designation d
                ON d.designation_id = f.designation_id
               " . ($designationHasStatus ? "AND d.status = 'active'" : '') . "
        ";
        $designationNameExpr = "COALESCE(NULLIF(TRIM(d.designation_name), ''), {$facultyDesignationExpr}, '')";
        $designationUnitsExpr = $designationHasUnits ? 'COALESCE(d.designation_units, 0)' : '0';
    } elseif ($facultyDesignationExpr !== 'NULL') {
        $designationNameExpr = "COALESCE({$facultyDesignationExpr}, '')";
    }

    $facultyDirectoryWhere = ["LOWER(TRIM(COALESCE(cf.status, 'active'))) = 'active'"];
    $facultyDirectoryTypes = '';
    $facultyDirectoryParams = [];

    if ($assignmentHasAyId && $assignmentHasSemester && $currentAyId > 0 && $currentSem > 0) {
        $facultyDirectoryWhere[] = '((cf.ay_id = ? AND cf.semester = ?) OR (cf.ay_id IS NULL AND cf.semester IS NULL))';
        $facultyDirectoryTypes .= 'ii';
        $facultyDirectoryParams[] = $currentAyId;
        $facultyDirectoryParams[] = $currentSem;
    }

    if ($selectedCollegeId) {
        $facultyDirectoryWhere[] = 'col.college_id = ?';
        $facultyDirectoryTypes .= 'i';
        $facultyDirectoryParams[] = $selectedCollegeId;
    } elseif (!$isUniversitySummary) {
        $facultyDirectoryWhere[] = 'camp.campus_id = ?';
        $facultyDirectoryTypes .= 'i';
        $facultyDirectoryParams[] = $campusId;
    }

    $facultyDirectoryGroupBy = ['f.faculty_id', 'f.last_name', 'f.first_name'];
    if ($facultyHasMiddleName) {
        $facultyDirectoryGroupBy[] = 'f.middle_name';
    }
    if ($facultyHasExtName) {
        $facultyDirectoryGroupBy[] = 'f.ext_name';
    }
    if ($facultyHasStatus) {
        $facultyDirectoryGroupBy[] = 'f.status';
    }
    $facultyDirectoryGroupBy[] = $designationSelectId;
    $facultyDirectoryGroupBy[] = $designationNameExpr;
    $facultyDirectoryGroupBy[] = $designationUnitsExpr;

    $facultyDirectorySql = "
        SELECT
            f.faculty_id,
            f.last_name,
            f.first_name,
            " . ($facultyHasMiddleName ? 'f.middle_name' : 'NULL AS middle_name') . ",
            " . ($facultyHasExtName ? 'f.ext_name' : 'NULL AS ext_name') . ",
            " . ($facultyHasStatus ? "LOWER(TRIM(COALESCE(f.status, 'active'))) AS faculty_status" : "'active' AS faculty_status") . ",
            {$designationSelectId} AS designation_id,
            {$designationNameExpr} AS designation_name,
            {$designationUnitsExpr} AS designation_units,
            COUNT(DISTINCT cf.college_faculty_id) AS assignment_rows,
            COUNT(DISTINCT col.college_id) AS college_count,
            GROUP_CONCAT(DISTINCT col.college_name ORDER BY col.college_name SEPARATOR '||') AS college_names,
            GROUP_CONCAT(DISTINCT COALESCE(NULLIF(TRIM(col.college_code), ''), col.college_name) ORDER BY col.college_name SEPARATOR '||') AS college_codes,
            GROUP_CONCAT(DISTINCT camp.campus_name ORDER BY camp.campus_name SEPARATOR '||') AS campus_names
        FROM tbl_college_faculty cf
        INNER JOIN tbl_faculty f
            ON f.faculty_id = cf.faculty_id
        INNER JOIN tbl_college col
            ON col.college_id = cf.college_id
        INNER JOIN tbl_campus camp
            ON camp.campus_id = col.campus_id
        {$designationJoinSql}
        WHERE " . implode("\n          AND ", $facultyDirectoryWhere) . "
        GROUP BY " . implode(",\n            ", $facultyDirectoryGroupBy) . "
        ORDER BY
            CASE WHEN " . ($facultyHasStatus ? "LOWER(TRIM(COALESCE(f.status, 'active')))" : "'active'") . " = 'inactive' THEN 1 ELSE 0 END,
            f.last_name ASC,
            f.first_name ASC,
            f.faculty_id ASC
    ";

    $facultyDirectoryStmt = $conn->prepare($facultyDirectorySql);
    $facultyDirectoryRowsById = [];

    if ($facultyDirectoryStmt && synk_bind_dynamic_params($facultyDirectoryStmt, $facultyDirectoryTypes, $facultyDirectoryParams)) {
        $facultyDirectoryStmt->execute();
        $facultyDirectoryResult = $facultyDirectoryStmt->get_result();

        if ($facultyDirectoryResult instanceof mysqli_result) {
            while ($facultyRow = $facultyDirectoryResult->fetch_assoc()) {
                $facultyId = (int)($facultyRow['faculty_id'] ?? 0);
                if ($facultyId <= 0) {
                    continue;
                }

                $status = strtolower(trim((string)($facultyRow['faculty_status'] ?? 'active')));
                if ($status !== 'inactive') {
                    $status = 'active';
                }

                $designationName = trim((string)($facultyRow['designation_name'] ?? ''));
                $collegeNames = campus_dashboard_unique_labels(explode('||', (string)($facultyRow['college_names'] ?? '')));
                $campusNames = campus_dashboard_unique_labels(explode('||', (string)($facultyRow['campus_names'] ?? '')));

                $facultyDirectoryRowsById[$facultyId] = [
                    'faculty_id' => $facultyId,
                    'full_name' => campus_dashboard_format_faculty_name($facultyRow),
                    'last_name' => trim((string)($facultyRow['last_name'] ?? '')),
                    'first_name' => trim((string)($facultyRow['first_name'] ?? '')),
                    'middle_name' => trim((string)($facultyRow['middle_name'] ?? '')),
                    'ext_name' => trim((string)($facultyRow['ext_name'] ?? '')),
                    'status' => $status,
                    'status_label' => $status === 'inactive' ? 'Inactive' : 'Active',
                    'designation_id' => (int)($facultyRow['designation_id'] ?? 0),
                    'designation_name' => $designationName,
                    'designation_label' => campus_dashboard_title_case($designationName),
                    'designation_units' => round((float)($facultyRow['designation_units'] ?? 0), 2),
                    'assignment_rows' => (int)($facultyRow['assignment_rows'] ?? 0),
                    'college_count' => (int)($facultyRow['college_count'] ?? count($collegeNames)),
                    'college_labels' => $collegeNames,
                    'college_label' => implode(', ', $collegeNames),
                    'campus_labels' => $campusNames,
                    'campus_label' => implode(', ', $campusNames),
                    'assigned_count' => 0,
                    'program_count' => 0,
                    'program_labels' => [],
                    'section_count' => 0,
                    'section_labels' => [],
                    'subject_count' => 0,
                    'subject_labels' => [],
                    'workload_load' => 0.0,
                    'total_load' => round((float)($facultyRow['designation_units'] ?? 0), 2),
                    'total_preparations' => 0,
                    'class_assignment_labels' => [],
                ];
            }
        }

        $facultyDirectoryStmt->close();
    } elseif ($facultyDirectoryStmt) {
        $facultyDirectoryStmt->close();
    }

    if (!empty($facultyDirectoryRowsById) && $analyticsReady && synk_table_exists($conn, 'tbl_faculty_workload_sched')) {
        $facultyIds = array_keys($facultyDirectoryRowsById);
        $facultyIdList = implode(',', array_map('intval', $facultyIds));
        $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
        $workloadScopeSql = '';
        $workloadScopeTypes = '';
        $workloadScopeParams = [];

        if ($selectedCollegeId) {
            $workloadScopeSql = ' AND p.college_id = ?';
            $workloadScopeTypes = 'i';
            $workloadScopeParams[] = $selectedCollegeId;
        } elseif (!$isUniversitySummary) {
            $workloadScopeSql = ' AND camp.campus_id = ?';
            $workloadScopeTypes = 'i';
            $workloadScopeParams[] = $campusId;
        }

        $workloadSql = "
            SELECT
                fw.faculty_id,
                cs.schedule_id,
                o.offering_id,
                " . ($classScheduleHasGroupId ? 'cs.schedule_group_id AS group_id' : 'NULL AS group_id') . ",
                " . ($classScheduleHasType ? 'cs.schedule_type AS schedule_type' : "'LEC' AS schedule_type") . ",
                sm.sub_code,
                sm.sub_description,
                COALESCE(NULLIF(TRIM(p.program_code), ''), p.program_name, CONCAT('Program ', p.program_id)) AS program_label,
                sec.section_name,
                col.college_name,
                camp.campus_name,
                ps.lec_units,
                ps.lab_units,
                ps.total_units
            FROM tbl_faculty_workload_sched fw
            INNER JOIN tbl_class_schedule cs
                ON cs.schedule_id = fw.schedule_id
            INNER JOIN tbl_prospectus_offering o
                ON o.offering_id = cs.offering_id
            {$liveOfferingJoins}
            INNER JOIN tbl_program p
                ON p.program_id = o.program_id
            INNER JOIN tbl_college col
                ON col.college_id = p.college_id
            INNER JOIN tbl_campus camp
                ON camp.campus_id = col.campus_id
            INNER JOIN tbl_subject_masterlist sm
                ON sm.sub_id = ps.sub_id
            WHERE fw.ay_id = ?
              AND fw.semester = ?
              AND o.ay_id = ?
              AND o.semester = ?
              AND fw.faculty_id IN ({$facultyIdList})
              {$workloadScopeSql}
            ORDER BY
                fw.faculty_id ASC,
                sec.section_name ASC,
                sm.sub_code ASC,
                cs.schedule_id ASC
        ";

        $workloadStmt = $conn->prepare($workloadSql);
        $workloadParams = array_merge([$currentAyId, $currentSem, $currentAyId, $currentSem], $workloadScopeParams);
        $workloadTypes = 'iiii' . $workloadScopeTypes;
        $rowsByFacultyContext = [];
        $preparationMap = [];
        $scheduleMap = [];
        $programMap = [];
        $sectionMap = [];
        $subjectMap = [];
        $assignmentMap = [];
        $offeringIds = [];

        if ($workloadStmt && synk_bind_dynamic_params($workloadStmt, $workloadTypes, $workloadParams)) {
            $workloadStmt->execute();
            $workloadResult = $workloadStmt->get_result();

            if ($workloadResult instanceof mysqli_result) {
                while ($workloadRow = $workloadResult->fetch_assoc()) {
                    $facultyId = (int)($workloadRow['faculty_id'] ?? 0);
                    if (!isset($facultyDirectoryRowsById[$facultyId])) {
                        continue;
                    }

                    $scheduleId = (int)($workloadRow['schedule_id'] ?? 0);
                    $offeringId = (int)($workloadRow['offering_id'] ?? 0);
                    $contextKey = campus_dashboard_context_key(
                        (int)($workloadRow['group_id'] ?? 0),
                        $scheduleId,
                        $offeringId
                    );

                    $rowsByFacultyContext[$facultyId][$contextKey][] = [
                        'schedule_type' => (string)($workloadRow['schedule_type'] ?? 'LEC'),
                        'lec_units' => (float)($workloadRow['lec_units'] ?? 0),
                        'lab_units' => (float)($workloadRow['lab_units'] ?? 0),
                        'total_units' => (float)($workloadRow['total_units'] ?? 0),
                    ];

                    if ($scheduleId > 0) {
                        $scheduleMap[$facultyId][$scheduleId] = true;
                    }

                    if ($offeringId > 0) {
                        $offeringIds[$offeringId] = true;
                    }

                    $subCode = trim((string)($workloadRow['sub_code'] ?? ''));
                    $subDescription = trim((string)($workloadRow['sub_description'] ?? ''));
                    $subjectLabel = $subCode;
                    if ($subDescription !== '') {
                        $subjectLabel = $subjectLabel !== '' ? $subjectLabel . ' - ' . $subDescription : $subDescription;
                    }
                    if ($subjectLabel !== '') {
                        $subjectMap[$facultyId][$subjectLabel] = true;
                    }
                    if ($subCode !== '') {
                        $preparationMap[$facultyId][$subCode] = true;
                    }

                    $programLabel = trim((string)($workloadRow['program_label'] ?? ''));
                    if ($programLabel !== '') {
                        $programMap[$facultyId][$programLabel] = true;
                    }

                    $sectionName = trim((string)($workloadRow['section_name'] ?? ''));
                    if ($sectionName !== '') {
                        $sectionMap[$facultyId][$sectionName] = true;
                    }

                    if (!isset($assignmentMap[$facultyId][$contextKey])) {
                        $assignmentMap[$facultyId][$contextKey] = [
                            'subject_label' => $subjectLabel !== '' ? $subjectLabel : 'Unlabeled Subject',
                            'program_labels' => [],
                            'section_labels' => [],
                            'college_labels' => [],
                            'type_map' => [],
                        ];
                    }

                    if ($programLabel !== '') {
                        $assignmentMap[$facultyId][$contextKey]['program_labels'][$programLabel] = true;
                    }
                    if ($sectionName !== '') {
                        $assignmentMap[$facultyId][$contextKey]['section_labels'][$sectionName] = true;
                    }

                    $collegeLabel = trim((string)($workloadRow['college_name'] ?? ''));
                    if ($collegeLabel !== '') {
                        $assignmentMap[$facultyId][$contextKey]['college_labels'][$collegeLabel] = true;
                    }

                    $scheduleType = strtoupper(trim((string)($workloadRow['schedule_type'] ?? 'LEC')));
                    if ($scheduleType === '') {
                        $scheduleType = 'LEC';
                    }
                    $assignmentMap[$facultyId][$contextKey]['type_map'][$scheduleType] = true;
                }
            }

            $workloadStmt->close();
        } elseif ($workloadStmt) {
            $workloadStmt->close();
        }

        $contextTotals = [];
        if (!empty($offeringIds)) {
            $offeringIdList = implode(',', array_map('intval', array_keys($offeringIds)));
            $contextSql = "
                SELECT
                    cs.schedule_id,
                    cs.offering_id,
                    " . ($classScheduleHasGroupId ? 'cs.schedule_group_id AS group_id' : 'NULL AS group_id') . ",
                    " . ($classScheduleHasType ? 'cs.schedule_type AS schedule_type' : "'LEC' AS schedule_type") . "
                FROM tbl_class_schedule cs
                WHERE cs.offering_id IN ({$offeringIdList})
            ";
            $contextResult = $conn->query($contextSql);

            if ($contextResult instanceof mysqli_result) {
                while ($contextRow = $contextResult->fetch_assoc()) {
                    $contextKey = campus_dashboard_context_key(
                        (int)($contextRow['group_id'] ?? 0),
                        (int)($contextRow['schedule_id'] ?? 0),
                        (int)($contextRow['offering_id'] ?? 0)
                    );

                    if (!isset($contextTotals[$contextKey])) {
                        $contextTotals[$contextKey] = [
                            'total_count' => 0,
                            'lec_count' => 0,
                            'lab_count' => 0,
                        ];
                    }

                    $contextTotals[$contextKey]['total_count']++;
                    if (strtoupper(trim((string)($contextRow['schedule_type'] ?? 'LEC'))) === 'LAB') {
                        $contextTotals[$contextKey]['lab_count']++;
                    } else {
                        $contextTotals[$contextKey]['lec_count']++;
                    }
                }

                $contextResult->free();
            }
        }

        foreach ($facultyDirectoryRowsById as $facultyId => &$facultyDirectoryRow) {
            $totalLoad = 0.0;
            foreach (($rowsByFacultyContext[$facultyId] ?? []) as $contextKey => $contextRows) {
                $metrics = synk_schedule_sum_display_metrics($contextRows, $contextTotals[$contextKey] ?? []);
                $totalLoad += (float)($metrics['faculty_load'] ?? 0);
            }

            $classAssignments = [];
            foreach (($assignmentMap[$facultyId] ?? []) as $assignment) {
                $programLabels = campus_dashboard_unique_labels(array_keys($assignment['program_labels'] ?? []));
                $sectionLabels = campus_dashboard_unique_labels(array_keys($assignment['section_labels'] ?? []));
                $collegeLabels = campus_dashboard_unique_labels(array_keys($assignment['college_labels'] ?? []));
                $typeLabels = campus_dashboard_unique_labels(array_keys($assignment['type_map'] ?? []));

                usort($typeLabels, static function ($left, $right) {
                    $order = ['LEC' => 0, 'LAB' => 1];
                    return ($order[$left] ?? 99) <=> ($order[$right] ?? 99);
                });

                $metaParts = [];
                if (!empty($programLabels)) {
                    $metaParts[] = implode(', ', $programLabels);
                }
                if (!empty($sectionLabels)) {
                    $metaParts[] = implode(', ', $sectionLabels);
                }
                if (!empty($collegeLabels) && (!$selectedCollegeId || count($collegeLabels) > 1)) {
                    $metaParts[] = implode(', ', $collegeLabels);
                }
                if (!empty($typeLabels)) {
                    $metaParts[] = implode('/', $typeLabels);
                }

                $assignmentLabel = $assignment['subject_label'];
                if (!empty($metaParts)) {
                    $assignmentLabel .= ' | ' . implode(' | ', $metaParts);
                }
                $classAssignments[] = $assignmentLabel;
            }

            $programLabels = campus_dashboard_unique_labels(array_keys($programMap[$facultyId] ?? []));
            $sectionLabels = campus_dashboard_unique_labels(array_keys($sectionMap[$facultyId] ?? []));
            $subjectLabels = campus_dashboard_unique_labels(array_keys($subjectMap[$facultyId] ?? []));

            $facultyDirectoryRow['assigned_count'] = count($scheduleMap[$facultyId] ?? []);
            $facultyDirectoryRow['program_labels'] = $programLabels;
            $facultyDirectoryRow['program_count'] = count($programLabels);
            $facultyDirectoryRow['section_labels'] = $sectionLabels;
            $facultyDirectoryRow['section_count'] = count($sectionLabels);
            $facultyDirectoryRow['subject_labels'] = $subjectLabels;
            $facultyDirectoryRow['subject_count'] = count($subjectLabels);
            $facultyDirectoryRow['workload_load'] = round($totalLoad, 2);
            $facultyDirectoryRow['total_preparations'] = count($preparationMap[$facultyId] ?? []);
            $facultyDirectoryRow['total_load'] = round($facultyDirectoryRow['workload_load'] + (float)$facultyDirectoryRow['designation_units'], 2);
            $facultyDirectoryRow['class_assignment_labels'] = campus_dashboard_unique_labels($classAssignments);
        }
        unset($facultyDirectoryRow);
    }

    $facultyDirectoryRows = array_values($facultyDirectoryRowsById);
    usort($facultyDirectoryRows, static function (array $left, array $right): int {
        $statusOrderLeft = (($left['status'] ?? 'active') === 'inactive') ? 1 : 0;
        $statusOrderRight = (($right['status'] ?? 'active') === 'inactive') ? 1 : 0;
        if ($statusOrderLeft !== $statusOrderRight) {
            return $statusOrderLeft <=> $statusOrderRight;
        }

        $assignedCompare = ((int)($right['assigned_count'] ?? 0)) <=> ((int)($left['assigned_count'] ?? 0));
        if ($assignedCompare !== 0) {
            return $assignedCompare;
        }

        $loadCompare = ((float)($right['total_load'] ?? 0)) <=> ((float)($left['total_load'] ?? 0));
        if ($loadCompare !== 0) {
            return $loadCompare;
        }

        return strnatcasecmp((string)($left['full_name'] ?? ''), (string)($right['full_name'] ?? ''));
    });

    $totalLoadAccumulator = 0.0;
    foreach ($facultyDirectoryRows as $facultyDirectoryRow) {
        $facultyDirectory['assigned_classes_total'] += (int)($facultyDirectoryRow['assigned_count'] ?? 0);
        $totalLoadAccumulator += (float)($facultyDirectoryRow['total_load'] ?? 0);

        if ((int)($facultyDirectoryRow['assigned_count'] ?? 0) > 0) {
            $facultyDirectory['faculty_with_workload']++;
        }
        if (trim((string)($facultyDirectoryRow['designation_name'] ?? '')) !== '') {
            $facultyDirectory['faculty_with_designation']++;
        }
        if ((string)($facultyDirectoryRow['status'] ?? 'active') === 'inactive') {
            $facultyDirectory['inactive_total']++;
        }
    }

    $facultyDirectory['rows'] = $facultyDirectoryRows;
    $facultyDirectory['total'] = count($facultyDirectoryRows);
    if ($facultyDirectory['total'] > 0) {
        $facultyDirectory['average_total_load'] = round($totalLoadAccumulator / $facultyDirectory['total'], 2);
    }
}

$facultyDirectoryJson = json_encode($facultyDirectory['rows'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($facultyDirectoryJson === false) {
    $facultyDirectoryJson = '[]';
}

$globalSignatorySettings = synk_fetch_signatory_settings($conn, 'global', 0);
$collegeSignatorySettings = $selectedCollegeId
    ? synk_fetch_signatory_settings($conn, 'college', (int)$selectedCollegeId)
    : [];
$consolidatedReportSignatories = [
    'prepared_by' => campus_dashboard_report_signatory_slot($collegeSignatorySettings, 'prepared_by', 'Prepared by'),
    'checked_by_left' => campus_dashboard_report_signatory_slot($globalSignatorySettings, 'checked_by_left', 'Checked by'),
    'checked_by_right' => campus_dashboard_report_signatory_slot($globalSignatorySettings, 'checked_by_right', 'Checked by'),
    'recommending_approval' => campus_dashboard_report_signatory_slot($globalSignatorySettings, 'recommending_approval', 'Recommending Approval'),
    'approved_by' => campus_dashboard_report_signatory_slot($globalSignatorySettings, 'approved_by', 'Approved by'),
];

$consolidatedReportScope = [
    'view_type' => $selectedCollegeId ? 'college' : ($isUniversitySummary ? 'university' : 'campus'),
    'campus_name' => $isUniversitySummary ? 'All Campuses' : $campusName,
    'college_name' => $selectedCollegeId ? (string)$selectedCollegeName : 'All Colleges',
    'scope_label' => $facultyDirectoryScopeLabel,
];

$consolidatedReportAssigneeMap = [];
foreach ($facultyDirectory['rows'] as $facultyDirectoryRow) {
    $facultyId = (int)($facultyDirectoryRow['faculty_id'] ?? 0);
    if ($facultyId <= 0) {
        continue;
    }

    $consolidatedReportAssigneeMap['faculty:' . $facultyId] = [
        'assignee_type' => 'faculty',
        'faculty_id' => $facultyId,
        'faculty_need_id' => 0,
        'college_id' => 0,
        'full_name' => (string)($facultyDirectoryRow['full_name'] ?? ''),
        'status' => (string)($facultyDirectoryRow['status'] ?? 'active'),
        'designation_name' => (string)($facultyDirectoryRow['designation_name'] ?? ''),
        'designation_label' => (string)($facultyDirectoryRow['designation_label'] ?? ''),
        'designation_units' => round((float)($facultyDirectoryRow['designation_units'] ?? 0), 2),
        'total_preparations' => (int)($facultyDirectoryRow['total_preparations'] ?? 0),
        'college_label' => (string)($facultyDirectoryRow['college_label'] ?? ''),
        'campus_label' => (string)($facultyDirectoryRow['campus_label'] ?? ''),
    ];
}

if (
    $analyticsReady
    && $currentAyId > 0
    && $currentSem > 0
    && synk_table_exists($conn, 'tbl_faculty_workload_sched')
    && synk_table_exists($conn, 'tbl_faculty')
    && synk_table_exists($conn, 'tbl_class_schedule')
    && synk_table_exists($conn, 'tbl_prospectus_offering')
    && synk_table_exists($conn, 'tbl_program')
    && synk_table_exists($conn, 'tbl_college')
) {
    $reportFacultyHasMiddleName = synk_table_has_column($conn, 'tbl_faculty', 'middle_name');
    $reportFacultyHasExtName = synk_table_has_column($conn, 'tbl_faculty', 'ext_name');
    $reportFacultyHasStatus = synk_table_has_column($conn, 'tbl_faculty', 'status');
    $reportFacultyHasDesignationId = synk_table_has_column($conn, 'tbl_faculty', 'designation_id');
    $reportFacultyDesignationTextColumn = null;
    foreach (['designation', 'designation_name'] as $candidate) {
        if (synk_table_has_column($conn, 'tbl_faculty', $candidate)) {
            $reportFacultyDesignationTextColumn = $candidate;
            break;
        }
    }

    $reportDesignationTableExists = synk_table_exists($conn, 'tbl_designation');
    $reportDesignationHasName = $reportDesignationTableExists && synk_table_has_column($conn, 'tbl_designation', 'designation_name');
    $reportDesignationHasUnits = $reportDesignationTableExists && synk_table_has_column($conn, 'tbl_designation', 'designation_units');
    $reportDesignationHasStatus = $reportDesignationTableExists && synk_table_has_column($conn, 'tbl_designation', 'status');
    $reportDesignationJoinSql = '';
    $reportDesignationSelectId = $reportFacultyHasDesignationId ? 'f.designation_id' : '0';
    $reportFacultyDesignationExpr = $reportFacultyDesignationTextColumn !== null
        ? "NULLIF(TRIM(f.`{$reportFacultyDesignationTextColumn}`), '')"
        : 'NULL';
    $reportDesignationNameExpr = "''";
    $reportDesignationUnitsExpr = '0';

    if ($reportFacultyHasDesignationId && $reportDesignationTableExists && $reportDesignationHasName) {
        $reportDesignationJoinSql = "
            LEFT JOIN tbl_designation d
                ON d.designation_id = f.designation_id
               " . ($reportDesignationHasStatus ? "AND d.status = 'active'" : '') . "
        ";
        $reportDesignationNameExpr = "COALESCE(NULLIF(TRIM(d.designation_name), ''), {$reportFacultyDesignationExpr}, '')";
        $reportDesignationUnitsExpr = $reportDesignationHasUnits ? 'COALESCE(d.designation_units, 0)' : '0';
    } elseif ($reportFacultyDesignationExpr !== 'NULL') {
        $reportDesignationNameExpr = "COALESCE({$reportFacultyDesignationExpr}, '')";
    }

    $reportWorkloadWhere = [
        'fw.ay_id = ?',
        'fw.semester = ?',
    ];
    $reportWorkloadTypes = 'ii';
    $reportWorkloadParams = [$currentAyId, $currentSem];

    if ($selectedCollegeId) {
        $reportWorkloadWhere[] = 'p.college_id = ?';
        $reportWorkloadTypes .= 'i';
        $reportWorkloadParams[] = (int)$selectedCollegeId;
    } elseif (!$isUniversitySummary) {
        $reportWorkloadWhere[] = 'camp.campus_id = ?';
        $reportWorkloadTypes .= 'i';
        $reportWorkloadParams[] = (int)$campusId;
    }

    $reportFacultyGroupBy = ['f.faculty_id', 'f.last_name', 'f.first_name'];
    if ($reportFacultyHasMiddleName) {
        $reportFacultyGroupBy[] = 'f.middle_name';
    }
    if ($reportFacultyHasExtName) {
        $reportFacultyGroupBy[] = 'f.ext_name';
    }
    if ($reportFacultyHasStatus) {
        $reportFacultyGroupBy[] = 'f.status';
    }
    $reportFacultyGroupBy[] = $reportDesignationSelectId;
    $reportFacultyGroupBy[] = $reportDesignationNameExpr;
    $reportFacultyGroupBy[] = $reportDesignationUnitsExpr;

    $reportLiveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $reportFacultySql = "
        SELECT
            f.faculty_id,
            f.last_name,
            f.first_name,
            " . ($reportFacultyHasMiddleName ? 'f.middle_name' : 'NULL AS middle_name') . ",
            " . ($reportFacultyHasExtName ? 'f.ext_name' : 'NULL AS ext_name') . ",
            " . ($reportFacultyHasStatus ? "LOWER(TRIM(COALESCE(f.status, 'active'))) AS faculty_status" : "'active' AS faculty_status") . ",
            {$reportDesignationSelectId} AS designation_id,
            {$reportDesignationNameExpr} AS designation_name,
            {$reportDesignationUnitsExpr} AS designation_units,
            GROUP_CONCAT(DISTINCT col.college_name ORDER BY col.college_name SEPARATOR '||') AS college_names,
            GROUP_CONCAT(DISTINCT camp.campus_name ORDER BY camp.campus_name SEPARATOR '||') AS campus_names
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_faculty f
            ON f.faculty_id = fw.faculty_id
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        {$reportLiveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        INNER JOIN tbl_campus camp
            ON camp.campus_id = col.campus_id
        {$reportDesignationJoinSql}
        WHERE " . implode("\n          AND ", $reportWorkloadWhere) . "
        GROUP BY " . implode(",\n            ", $reportFacultyGroupBy) . "
        ORDER BY f.last_name ASC, f.first_name ASC, f.faculty_id ASC
    ";

    $reportFacultyStmt = $conn->prepare($reportFacultySql);
    if ($reportFacultyStmt && synk_bind_dynamic_params($reportFacultyStmt, $reportWorkloadTypes, $reportWorkloadParams)) {
        $reportFacultyStmt->execute();
        $reportFacultyResult = $reportFacultyStmt->get_result();

        if ($reportFacultyResult instanceof mysqli_result) {
            while ($reportFacultyRow = $reportFacultyResult->fetch_assoc()) {
                $facultyId = (int)($reportFacultyRow['faculty_id'] ?? 0);
                if ($facultyId <= 0 || isset($consolidatedReportAssigneeMap['faculty:' . $facultyId])) {
                    continue;
                }

                $status = strtolower(trim((string)($reportFacultyRow['faculty_status'] ?? 'active')));
                if ($status !== 'inactive') {
                    $status = 'active';
                }

                $designationName = trim((string)($reportFacultyRow['designation_name'] ?? ''));
                $collegeNames = campus_dashboard_unique_labels(explode('||', (string)($reportFacultyRow['college_names'] ?? '')));
                $campusNames = campus_dashboard_unique_labels(explode('||', (string)($reportFacultyRow['campus_names'] ?? '')));

                $consolidatedReportAssigneeMap['faculty:' . $facultyId] = [
                    'assignee_type' => 'faculty',
                    'faculty_id' => $facultyId,
                    'faculty_need_id' => 0,
                    'college_id' => 0,
                    'full_name' => campus_dashboard_format_faculty_name($reportFacultyRow),
                    'status' => $status,
                    'designation_name' => $designationName,
                    'designation_label' => campus_dashboard_title_case($designationName),
                    'designation_units' => round((float)($reportFacultyRow['designation_units'] ?? 0), 2),
                    'total_preparations' => 0,
                    'college_label' => implode(', ', $collegeNames),
                    'campus_label' => implode(', ', $campusNames),
                ];
            }
        }

        $reportFacultyStmt->close();
    } elseif ($reportFacultyStmt) {
        $reportFacultyStmt->close();
    }
}

if ($currentAyId > 0 && $currentSem > 0 && synk_table_exists($conn, 'tbl_college')) {
    synk_faculty_need_ensure_tables($conn);

    $needWhere = [
        'fn.ay_id = ?',
        'fn.semester = ?',
        "fn.status = 'active'",
    ];
    $needTypes = 'ii';
    $needParams = [$currentAyId, $currentSem];

    if ($selectedCollegeId) {
        $needWhere[] = 'fn.college_id = ?';
        $needTypes .= 'i';
        $needParams[] = (int)$selectedCollegeId;
    } elseif (!$isUniversitySummary) {
        $needWhere[] = 'camp.campus_id = ?';
        $needTypes .= 'i';
        $needParams[] = (int)$campusId;
    }

    $needSql = "
        SELECT
            fn.faculty_need_id,
            fn.college_id,
            fn.need_label,
            COALESCE(col.college_name, '') AS college_name,
            COALESCE(camp.campus_name, '') AS campus_name
        FROM `" . synk_faculty_need_table_name() . "` fn
        INNER JOIN tbl_college col
            ON col.college_id = fn.college_id
        LEFT JOIN tbl_campus camp
            ON camp.campus_id = col.campus_id
        WHERE " . implode("\n          AND ", $needWhere) . "
        ORDER BY camp.campus_name ASC, col.college_name ASC, fn.faculty_need_id ASC
    ";

    $needStmt = $conn->prepare($needSql);
    if ($needStmt && synk_bind_dynamic_params($needStmt, $needTypes, $needParams)) {
        $needStmt->execute();
        $needResult = $needStmt->get_result();

        if ($needResult instanceof mysqli_result) {
            while ($needRow = $needResult->fetch_assoc()) {
                $facultyNeedId = (int)($needRow['faculty_need_id'] ?? 0);
                $collegeId = (int)($needRow['college_id'] ?? 0);
                $needLabel = trim((string)($needRow['need_label'] ?? ''));
                if ($facultyNeedId <= 0 || $collegeId <= 0 || $needLabel === '') {
                    continue;
                }

                $consolidatedReportAssigneeMap['need:' . $facultyNeedId] = [
                    'assignee_type' => 'faculty_need',
                    'faculty_id' => 0,
                    'faculty_need_id' => $facultyNeedId,
                    'college_id' => $collegeId,
                    'full_name' => $needLabel,
                    'status' => 'active',
                    'designation_name' => '',
                    'designation_label' => '',
                    'designation_units' => 0,
                    'total_preparations' => 0,
                    'college_label' => trim((string)($needRow['college_name'] ?? '')),
                    'campus_label' => trim((string)($needRow['campus_name'] ?? '')),
                ];
            }
        }

        $needStmt->close();
    } elseif ($needStmt) {
        $needStmt->close();
    }
}

$consolidatedReportAssignees = array_values($consolidatedReportAssigneeMap);
usort($consolidatedReportAssignees, static function (array $left, array $right): int {
    $nameCompare = strnatcasecmp((string)($left['full_name'] ?? ''), (string)($right['full_name'] ?? ''));
    if ($nameCompare !== 0) {
        return $nameCompare;
    }

    return strnatcasecmp((string)($left['assignee_type'] ?? ''), (string)($right['assignee_type'] ?? ''));
});

$consolidatedReportAssigneesJson = json_encode($consolidatedReportAssignees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($consolidatedReportAssigneesJson === false) {
    $consolidatedReportAssigneesJson = '[]';
}




?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../assets/"
  data-template="vertical-menu-template-free"
>
<head>
  <meta charset="utf-8" />
  <meta
    name="viewport"
    content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
  />

  <title>Campus Dashboard | Synk</title>

  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
  <link rel="stylesheet" href="../assets/vendor/css/core.css" />
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
  <link rel="stylesheet" href="../assets/css/demo.css" />
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
  <link rel="stylesheet" href="../assets/vendor/libs/apex-charts/apex-charts.css" />

  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>

  <style>
    .kpi-box {
      padding: 10px;
    }
    .kpi-icon {
      width: 48px;
      height: 48px;
      border-radius: 10px;
      display: flex;
      justify-content: center;
      align-items: center;
      font-size: 22px;
      color: #fff;
      margin: auto;
    }
    .kpi-value {
      font-size: 1.7rem;
      font-weight: 700;
    }

    .card-section-title {
      font-size: 0.9rem;
      font-weight: 600;
      letter-spacing: 0.05em;
      text-transform: uppercase;
      color: #888;
    }

    .table-sm td,
    .table-sm th {
      padding-top: 0.4rem !important;
      padding-bottom: 0.4rem !important;
    }

    .resource-stat-card {
      border: 1px solid #e7e7ef;
      border-radius: 14px;
      padding: 1rem;
      background: linear-gradient(180deg, #fff 0%, #fbfbff 100%);
      height: 100%;
    }

    .resource-stat {
      padding: 0.75rem 0;
      border-bottom: 1px solid #eef0f6;
    }

    .resource-stat:last-child {
      border-bottom: 0;
      padding-bottom: 0;
    }

    .resource-stat-label {
      font-size: 0.78rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: #8592a3;
      margin-bottom: 0.25rem;
    }

    .resource-stat-value {
      font-size: 1.45rem;
      line-height: 1.2;
      font-weight: 700;
      color: #566a7f;
    }

    .resource-stat-note {
      font-size: 0.85rem;
      color: #8592a3;
    }

    .resource-note {
      font-size: 0.82rem;
      color: #8592a3;
    }

    .chart-shell {
      min-height: 340px;
    }

    .chart-fallback {
      min-height: 340px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #8592a3;
      text-align: center;
    }

    .chart-loader {
      min-height: 340px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #8592a3;
      text-align: center;
    }

    .loader-inline {
      display: inline-flex;
      align-items: center;
      gap: 0.65rem;
      font-weight: 600;
    }

    .chart-insight {
      min-height: 40px;
      margin-top: 0.75rem;
      font-size: 0.84rem;
      color: #8592a3;
    }

    .heatmap-toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: flex-end;
      justify-content: space-between;
      gap: 0.85rem;
    }

    .heatmap-filter-control {
      min-width: 240px;
      max-width: 100%;
    }

    .heatmap-filter-label {
      display: block;
      margin-bottom: 0.35rem;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: #8592a3;
    }

    .heatmap-room-filter {
      min-width: 240px;
    }

    .matrix-table th,
    .matrix-table td {
      min-width: 88px;
      vertical-align: middle;
    }

    .matrix-room {
      background: #f8f9fa;
      white-space: normal;
      word-break: break-word;
      line-height: 1.15;
    }

    .matrix-cell {
      border-radius: 6px;
      padding: 4px;
      text-align: center;
      font-size: 0.76rem;
      min-height: 34px;
    }

    .matrix-vacant {
      background: #eef2f6;
      color: #8b97a8;
    }

    .matrix-occupied {
      background: #f6f9fc;
      color: #17324d;
      border: 1px solid #d8e4f1;
      font-weight: 600;
    }

    .matrix-entry {
      border-radius: 5px;
      color: #fff;
      padding: 3px 5px;
      margin-bottom: 3px;
      line-height: 1.08;
      box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.14);
    }

    .matrix-entry:last-child {
      margin-bottom: 0;
    }

    .matrix-conflict .matrix-entry {
      color: #fff;
    }

    .matrix-entry strong {
      display: block;
      font-size: 0.68rem;
      letter-spacing: 0.01em;
    }

    .matrix-entry small {
      display: block;
      color: rgba(255, 255, 255, 0.92);
      font-size: 0.58rem;
    }

    .matrix-conflict {
      background: #fff5f5;
      border: 1px solid #ffc9c9;
    }

    .matrix-day {
      background: #f8f9fa;
      font-weight: 700;
      white-space: nowrap;
      min-width: 52px;
      width: 52px;
      max-width: 52px;
      letter-spacing: 0.02em;
    }

    .sub-0 { background: #2563eb; }
    .sub-1 { background: #059669; }
    .sub-2 { background: #d97706; }
    .sub-3 { background: #7c3aed; }
    .sub-4 { background: #0f766e; }
    .sub-5 { background: #dc2626; }
    .sub-6 { background: #db2777; }
    .sub-7 { background: #4f46e5; }
    .sub-8 { background: #0891b2; }
    .sub-9 { background: #65a30d; }
    .sub-10 { background: #b45309; }
    .sub-11 { background: #475569; }

    #matrixModal {
      --matrix-room-col-width: 118px;
      --matrix-day-col-width: 50px;
      --matrix-slot-col-width: 58px;
      z-index: 1090;
    }

    #matrixModal .matrix-table {
      font-size: 0.68rem;
    }

    #matrixModal .matrix-room-col {
      width: var(--matrix-room-col-width);
    }

    #matrixModal .matrix-day-col {
      width: var(--matrix-day-col-width);
    }

    #matrixModal .matrix-slot-col {
      width: var(--matrix-slot-col-width);
    }

    #matrixModal .matrix-table th {
      font-size: 0.62rem;
      line-height: 1.05;
      white-space: nowrap;
    }

    #matrixModal .matrix-table th,
    #matrixModal .matrix-table td {
      padding: 4px !important;
      min-width: var(--matrix-slot-col-width);
    }

    #matrixModal .matrix-room {
      min-width: var(--matrix-room-col-width);
      width: var(--matrix-room-col-width);
      max-width: var(--matrix-room-col-width);
      font-size: 0.69rem;
      padding: 7px 6px !important;
    }

    #matrixModal .matrix-day {
      min-width: var(--matrix-day-col-width);
      width: var(--matrix-day-col-width);
      max-width: var(--matrix-day-col-width);
      font-size: 0.64rem;
      padding: 4px 3px !important;
    }

    #matrixModal .matrix-cell {
      padding: 3px;
      min-height: 28px;
      font-size: 0.64rem;
      line-height: 1.02;
    }

    #matrixModal .matrix-cell strong {
      font-size: 0.64rem;
    }

    #matrixModal .matrix-cell small {
      font-size: 0.56rem;
      opacity: 0.94;
    }

    #matrixModal .matrix-time-slot {
      display: grid;
      gap: 1px;
      justify-items: center;
      line-height: 1.02;
      font-variant-numeric: tabular-nums;
    }

    #matrixModal .matrix-time-slot span {
      display: block;
    }

    #matrixModal .matrix-meta-note {
      line-height: 1.35;
    }

    #matrixModal .matrix-table thead th {
      position: sticky;
      top: 0;
      z-index: 6;
      background: #ffffff;
    }

    #matrixModal .matrix-room {
      position: sticky;
      left: 0;
      z-index: 5;
      box-shadow: 2px 0 4px rgba(0, 0, 0, 0.05);
    }

    #matrixModal .matrix-day {
      position: sticky;
      left: var(--matrix-room-col-width);
      z-index: 4;
      box-shadow: 2px 0 4px rgba(0, 0, 0, 0.04);
    }

    #matrixModal .matrix-table thead .matrix-room {
      z-index: 9;
    }

    #matrixModal .matrix-table thead .matrix-day {
      z-index: 8;
    }

    #matrixModal .modal-body {
      padding: 0.5rem;
      overflow: hidden;
    }

    #matrixModal .matrix-shell {
      height: calc(95vh - 92px);
      display: flex;
      flex-direction: column;
      min-height: 0;
    }

    #matrixModal .matrix-scroll-wrap {
      flex: 1 1 auto;
      min-height: 0;
      overflow: auto;
      border: 1px solid #d6e0f0;
      border-radius: 14px;
      background: #fff;
    }

    #matrixModal .matrix-table {
      width: max-content;
      min-width: 100%;
    }

    #matrixModal .modal-dialog {
      max-width: min(99vw, 2200px) !important;
      width: min(99vw, 2200px);
      height: 95vh;
      margin: 0.75rem auto;
    }

    #matrixModal .modal-content {
      height: 100%;
    }

    .faculty-directory-note {
      font-size: 0.83rem;
      color: #8592a3;
      line-height: 1.45;
    }

    .faculty-directory-stats {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.75rem;
      margin-bottom: 1rem;
    }

    .faculty-directory-stat {
      border: 1px solid #e7e7ef;
      border-radius: 12px;
      background: linear-gradient(180deg, #ffffff 0%, #f9faff 100%);
      padding: 0.85rem 0.95rem;
      min-width: 0;
    }

    .faculty-directory-stat-label {
      display: block;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      color: #8592a3;
      letter-spacing: 0.04em;
      line-height: 1.3;
    }

    .faculty-directory-stat-value {
      display: block;
      margin-top: 0.35rem;
      color: #435971;
      font-size: 1.18rem;
      font-weight: 700;
      line-height: 1.2;
    }

    .faculty-directory-toolbar {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      margin-bottom: 0.95rem;
    }

    .faculty-directory-toolbar .input-group-text,
    .faculty-directory-toolbar .btn,
    .faculty-directory-toolbar .form-control {
      font-size: 0.9rem;
    }

    .faculty-directory-summary {
      color: #8592a3;
      font-size: 0.83rem;
      font-weight: 600;
      line-height: 1.4;
    }

    .faculty-directory-list {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
      max-height: 420px;
      overflow-y: auto;
      padding-right: 0.15rem;
    }

    .faculty-directory-item {
      width: 100%;
      border: 1px solid #e7e7ef;
      border-radius: 14px;
      background: #fff;
      padding: 0.9rem 0.95rem;
      text-align: left;
      transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
    }

    .faculty-directory-item:hover,
    .faculty-directory-item:focus {
      border-color: rgba(105, 108, 255, 0.35);
      box-shadow: 0 10px 22px rgba(67, 89, 113, 0.08);
      transform: translateY(-1px);
    }

    .faculty-directory-item-top {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 0.75rem;
      min-width: 0;
    }

    .faculty-directory-item-name {
      color: #435971;
      font-size: 0.98rem;
      font-weight: 700;
      line-height: 1.25;
      word-break: break-word;
    }

    .faculty-directory-item-subtext {
      margin-top: 0.25rem;
      color: #8592a3;
      font-size: 0.82rem;
      line-height: 1.45;
      word-break: break-word;
    }

    .faculty-directory-item-metrics {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      margin-top: 0.8rem;
    }

    .faculty-directory-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.35rem;
      border-radius: 999px;
      border: 1px solid #dfe4f1;
      background: #f7f8fd;
      color: #566a7f;
      font-size: 0.76rem;
      font-weight: 700;
      line-height: 1.1;
      padding: 0.38rem 0.65rem;
      min-height: 32px;
    }

    .faculty-directory-chip.is-accent {
      border-color: rgba(105, 108, 255, 0.18);
      background: rgba(105, 108, 255, 0.09);
      color: #5b61f2;
    }

    .faculty-directory-chip.is-success {
      border-color: rgba(113, 221, 55, 0.25);
      background: rgba(113, 221, 55, 0.12);
      color: #4c9d22;
    }

    .faculty-directory-chip.is-warning {
      border-color: rgba(255, 171, 0, 0.28);
      background: rgba(255, 171, 0, 0.12);
      color: #b87800;
    }

    .faculty-directory-empty {
      border: 1px dashed rgba(67, 89, 113, 0.22);
      border-radius: 14px;
      background: rgba(245, 246, 255, 0.7);
      color: #8592a3;
      text-align: center;
      padding: 1.35rem 1rem;
      line-height: 1.5;
    }

    .faculty-directory-empty i {
      font-size: 1.25rem;
      margin-bottom: 0.35rem;
      display: block;
    }

    .faculty-detail-subtitle {
      color: #8592a3;
      font-size: 0.86rem;
      line-height: 1.45;
      margin-top: 0.25rem;
    }

    .faculty-detail-section-title {
      font-size: 0.8rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: #8592a3;
      margin-bottom: 0.75rem;
    }

    .faculty-detail-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 0.85rem;
      margin-bottom: 1rem;
    }

    .faculty-detail-panel {
      border: 1px solid #e7e7ef;
      border-radius: 14px;
      background: #fff;
      padding: 0.95rem;
      min-width: 0;
    }

    .faculty-detail-field {
      display: grid;
      gap: 0.2rem;
      margin-bottom: 0.7rem;
    }

    .faculty-detail-field:last-child {
      margin-bottom: 0;
    }

    .faculty-detail-label {
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: #8592a3;
      line-height: 1.35;
    }

    .faculty-detail-value {
      color: #435971;
      font-size: 0.95rem;
      font-weight: 600;
      line-height: 1.45;
      word-break: break-word;
    }

    .faculty-detail-metrics {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 0.75rem;
      margin-bottom: 1rem;
    }

    .faculty-detail-metric {
      border: 1px solid #e7e7ef;
      border-radius: 12px;
      background: linear-gradient(180deg, #ffffff 0%, #fafbff 100%);
      padding: 0.85rem 0.9rem;
      min-width: 0;
    }

    .faculty-detail-metric-label {
      display: block;
      color: #8592a3;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      line-height: 1.35;
    }

    .faculty-detail-metric-value {
      display: block;
      margin-top: 0.32rem;
      color: #435971;
      font-size: 1.06rem;
      font-weight: 700;
      line-height: 1.2;
    }

    .faculty-detail-badge-list {
      display: flex;
      flex-wrap: wrap;
      gap: 0.5rem;
      margin-bottom: 0.95rem;
    }

    .faculty-detail-badge {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 0.42rem 0.75rem;
      border: 1px solid #dfe4f1;
      background: #f7f8fd;
      color: #566a7f;
      font-size: 0.78rem;
      font-weight: 700;
      line-height: 1.15;
      max-width: 100%;
      word-break: break-word;
    }

    .faculty-detail-assignment-list {
      list-style: none;
      margin: 0;
      padding: 0;
      display: grid;
      gap: 0.55rem;
    }

    .faculty-detail-assignment-list li {
      border: 1px solid #e7e7ef;
      border-radius: 12px;
      padding: 0.8rem 0.9rem;
      background: #fff;
      color: #566a7f;
      line-height: 1.45;
      word-break: break-word;
    }

    .faculty-workload-summary {
      color: #8592a3;
      font-size: 0.84rem;
      font-weight: 600;
      line-height: 1.45;
      margin-bottom: 0.85rem;
    }

    .faculty-workload-table-wrap {
      border: 1px solid #e7e7ef;
      border-radius: 14px;
      overflow: auto;
      background: #fff;
    }

    .faculty-workload-table {
      width: 100%;
      min-width: 920px;
      margin-bottom: 0;
    }

    .faculty-workload-table thead th {
      position: sticky;
      top: 0;
      z-index: 1;
      background: #f8f9fd;
      color: #8592a3;
      font-size: 0.72rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      border-bottom: 1px solid #e7e7ef;
      white-space: nowrap;
    }

    .faculty-workload-table td {
      color: #566a7f;
      font-size: 0.88rem;
      line-height: 1.45;
      vertical-align: top;
      border-color: #eef1f6;
    }

    .faculty-workload-code {
      font-weight: 700;
      color: #435971;
      white-space: nowrap;
    }

    .faculty-workload-desc {
      min-width: 260px;
    }

    .faculty-workload-course {
      min-width: 160px;
    }

    .faculty-workload-note {
      display: block;
      margin-top: 0.25rem;
      color: #8592a3;
      font-size: 0.76rem;
      line-height: 1.45;
    }

    .faculty-workload-inline-meta {
      display: block;
      margin-top: 0.28rem;
      color: #8592a3;
      font-size: 0.76rem;
      line-height: 1.45;
    }

    .faculty-workload-type {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 0.18rem 0.52rem;
      background: rgba(105, 108, 255, 0.08);
      color: #5b61f2;
      font-size: 0.72rem;
      font-weight: 700;
      line-height: 1.1;
    }

    .faculty-workload-loader {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.7rem;
      min-height: 130px;
      color: #8592a3;
      font-weight: 600;
      text-align: center;
    }

    .faculty-directory-workload-alert .alert {
      background: #eaf8ff;
      border-color: #cbeaf8;
      color: #1a7da8;
      margin-bottom: 0.9rem;
    }

    .workload-card {
      border: 1px solid #dbe5f1;
      box-shadow: 0 2px 8px rgba(18, 38, 63, 0.05);
      border-radius: 16px;
    }

    .workload-card .card-header {
      padding-bottom: 0.95rem;
      border-bottom-color: #e7edf5;
    }

    .workload-table thead th {
      font-size: 0.72rem;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #5f728b;
      border-bottom: 1px solid #dbe4ef;
      border-top: 1px solid #dbe4ef;
      box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.95), inset 0 -1px 0 rgba(204, 216, 229, 0.9);
      white-space: nowrap;
      background: #f8fbfe;
      vertical-align: middle;
    }

    .workload-table thead th.course-head { width: 7.5%; }
    .workload-table thead th.day-head { width: 5.5%; }
    .workload-table thead th.time-head { width: 7.5%; }
    .workload-table thead th.room-head { width: 6.5%; }
    .workload-table thead th.unit-head { width: 4.5%; }
    .workload-table thead th.hours-group-head { width: 9%; }
    .workload-table thead th.load-head { width: 6%; }
    .workload-table thead th.students-head { width: 7.5%; }
    .workload-table thead th.hours-subhead {
      width: 4.5%;
      white-space: nowrap;
      word-break: keep-all;
      overflow-wrap: normal;
    }

    .workload-table tbody td {
      color: #5c6f88;
      border-color: #e7edf5;
      vertical-align: middle;
    }

    .workload-table tfoot th,
    .workload-table tfoot td {
      color: #5f728b;
      border-top: 2px solid #d7e1ec;
      background: #f9fbfd;
      vertical-align: middle;
    }

    .workload-code {
      font-weight: 700;
      color: #5b6f86;
      white-space: nowrap;
    }

    .workload-desc {
      color: #5f728b;
    }

    .workload-days,
    .workload-room {
      white-space: nowrap;
    }

    .workload-time {
      white-space: normal;
      line-height: 1.08;
      min-width: 88px;
    }

    .time-line {
      display: block;
      white-space: nowrap;
    }

    .merged-metric {
      vertical-align: middle !important;
      background: #fbfcfe;
      font-weight: 600;
    }

    .workload-summary-row th,
    .workload-summary-row td {
      background: #f9fbfd;
      font-size: inherit;
      border-top: 1px solid #d7e1ec;
      border-bottom: 1px solid #d7e1ec;
    }

    .workload-summary-label {
      color: #52657d;
      font-weight: 700;
      white-space: nowrap;
    }

    .workload-summary-value {
      color: #4f6279;
      font-weight: 600;
    }

    .summary-separator th,
    .summary-separator td {
      border-top: 2px solid #b9c8d9 !important;
    }

    .workload-total-row th,
    .workload-total-row td {
      border-top: 2px solid #b7c6d8 !important;
      font-size: inherit;
      background: #f6f9fc;
    }

    .load-status-inline {
      display: inline-block;
      margin-left: 0.45rem;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 0.76rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      vertical-align: middle;
    }

    .load-status-inline.underload {
      background: #fff3cd;
      color: #7a5a00;
    }

    .load-status-inline.overload {
      background: #fde8ea;
      color: #a61c2d;
    }

    .workload-total-load-screen {
      padding-left: 0.75rem !important;
      padding-right: 1rem !important;
    }

    .total-load-screen-inner {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      align-items: center;
      width: 100%;
      gap: 0.5rem;
    }

    .total-load-screen-inner .total-load-value {
      grid-column: 1;
      justify-self: center;
      font-weight: 700;
    }

    .total-load-screen-inner .load-status-inline {
      grid-column: 2 / 4;
      justify-self: start;
      margin-left: 0;
    }

    .paired-anchor {
      background: #fbfcfe;
    }

    .paired-row td {
      background-image: linear-gradient(to right, rgba(88, 116, 255, 0.04), rgba(88, 116, 255, 0));
    }

    .schedule-partner-note {
      display: block;
      margin-top: 0.2rem;
      font-size: 0.72rem;
      color: #6f7f96;
    }

    .print-type-suffix {
      display: none;
    }

    .workload-merge-note {
      display: block;
      margin-top: 0.28rem;
      font-size: 0.72rem;
      font-weight: 600;
      color: #556b85;
      line-height: 1.25;
    }

    .type-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 42px;
      padding: 2px 8px;
      border-radius: 6px;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .type-pill.lec {
      background: #e8e9ff;
      color: #5d68f4;
    }

    .type-pill.lab {
      background: #fff0cf;
      color: #c98900;
    }

    @media (min-width: 1200px) {
      #matrixModal {
        --matrix-room-col-width: 104px;
        --matrix-day-col-width: 46px;
        --matrix-slot-col-width: 50px;
      }

      #matrixModal .matrix-table {
        width: 100%;
        table-layout: fixed;
      }
    }

    @media (min-width: 1600px) {
      #matrixModal {
        --matrix-room-col-width: 100px;
        --matrix-day-col-width: 44px;
        --matrix-slot-col-width: 48px;
      }
    }

    @media (max-width: 992px) {
      #matrixModal {
        --matrix-room-col-width: 126px;
        --matrix-day-col-width: 52px;
        --matrix-slot-col-width: 66px;
      }

      #matrixModal .modal-dialog {
        max-width: 100vw !important;
        width: 100vw;
        height: 100vh;
        margin: 0;
      }

      #matrixModal .matrix-shell {
        height: calc(100vh - 92px);
      }

      #matrixModal .matrix-scroll-wrap {
        border-radius: 0;
        border-left: 0;
        border-right: 0;
        border-bottom: 0;
      }

      .faculty-directory-stats,
      .faculty-detail-grid,
      .faculty-detail-metrics {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 575.98px) {
      .faculty-directory-item-top {
        flex-direction: column;
      }
    }
  </style>
  <style id="consolidatedReportStyleTag">
    .consolidated-report-modal .modal-dialog {
      max-width: min(96vw, 1800px);
    }

    .consolidated-report-modal .modal-content {
      border: 0;
      overflow: hidden;
    }

    .consolidated-report-modal .modal-header,
    .consolidated-report-modal .modal-footer {
      background: #ffffff;
    }

    .consolidated-report-modal .modal-body {
      background: #eef3f9;
      padding: 1rem;
    }

    .consolidated-report-toolbar-note {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      font-size: 0.84rem;
      color: #66768a;
    }

    .consolidated-report-toolbar-note i {
      color: #5d68f4;
      font-size: 1rem;
    }

    .consolidated-report-preview-root {
      display: flex;
      flex-direction: column;
      gap: 1.25rem;
      align-items: stretch;
      overflow-x: auto;
    }

    .consolidated-report-loading {
      min-height: 50vh;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .consolidated-report-loading-card,
    .consolidated-report-empty-state {
      width: min(100%, 560px);
      margin: 0 auto;
      background: #fff;
      border: 1px solid #dbe5f1;
      border-radius: 1rem;
      box-shadow: 0 20px 45px rgba(22, 40, 75, 0.08);
      padding: 1.5rem;
      text-align: center;
    }

    .consolidated-report-loading-card .spinner-border,
    .consolidated-report-empty-state i {
      width: 2.1rem;
      height: 2.1rem;
      margin-bottom: 0.75rem;
    }

    .consolidated-report-empty-state i {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
      color: #5d68f4;
    }

    .consolidated-report-loading-title,
    .consolidated-report-empty-title {
      font-size: 1.08rem;
      font-weight: 700;
      color: #304257;
      margin-bottom: 0.35rem;
    }

    .consolidated-report-loading-copy,
    .consolidated-report-empty-copy {
      font-size: 0.92rem;
      color: #6a7d94;
      margin-bottom: 0.9rem;
    }

    .consolidated-report-progress {
      display: flex;
      flex-direction: column;
      gap: 0.45rem;
      align-items: stretch;
    }

    .consolidated-report-progress-meta {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      font-size: 0.82rem;
      color: #61748b;
    }

    .consolidated-report-progress-track {
      width: 100%;
      height: 0.55rem;
      background: #e6edf7;
      border-radius: 999px;
      overflow: hidden;
    }

    .consolidated-report-progress-bar {
      width: 0;
      height: 100%;
      background: linear-gradient(90deg, #198754 0%, #5d68f4 100%);
      transition: width 0.2s ease;
    }

    .consolidated-report-page {
      --consolidated-page-width: 1344px;
      --consolidated-page-height: 816px;
      position: relative;
      width: var(--consolidated-page-width);
      height: var(--consolidated-page-height);
      margin: 0 auto;
      background: #fff;
      box-shadow: 0 22px 50px rgba(25, 42, 70, 0.16);
      overflow: hidden;
      flex: 0 0 auto;
    }

    .consolidated-report-page.is-measure {
      --consolidated-page-width: 1344px;
      --consolidated-page-height: 816px;
      box-shadow: none;
      margin: 0;
    }

    .consolidated-report-page-background {
      position: absolute;
      inset: 0;
      background-image: var(--consolidated-report-bg);
      background-repeat: no-repeat;
      background-position: center;
      background-size: 100% 100%;
    }

    .consolidated-report-page-inner {
      position: absolute;
      inset: 188px 48px 68px 74px;
      display: flex;
      flex-direction: column;
      min-height: 0;
    }

    .consolidated-report-page-header {
      text-align: center;
      padding: 0 0 0.32rem;
    }

    .consolidated-report-title {
      font-family: "Arial Narrow", Arial, sans-serif;
      font-size: 1.14rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: #0f3140;
      margin-bottom: 0.14rem;
    }

    .consolidated-report-subtitle,
    .consolidated-report-term,
    .consolidated-report-page-count {
      font-family: Arial, sans-serif;
      color: #10212f;
      line-height: 1.15;
    }

    .consolidated-report-subtitle {
      font-size: 0.78rem;
      font-weight: 700;
      text-transform: uppercase;
    }

    .consolidated-report-term {
      margin-top: 0.12rem;
      font-size: 0.72rem;
      font-weight: 600;
      letter-spacing: 0.02em;
    }

    .consolidated-report-page-count {
      margin-top: 0.12rem;
      font-size: 0.68rem;
      font-weight: 600;
      color: #4b5f70;
    }

    .consolidated-report-table-wrap {
      flex: 1 1 auto;
      min-height: 0;
      padding-inline: 24px;
      box-sizing: border-box;
    }

    .consolidated-report-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      font-family: Arial, sans-serif;
      font-size: 9.25px;
      color: #000;
      background: #fff;
    }

    .consolidated-report-table thead th,
    .consolidated-report-table tbody td {
      border: 1px solid #000;
      padding: 0.18rem 0.22rem;
      vertical-align: top;
      box-sizing: border-box;
      overflow-wrap: anywhere;
      word-break: normal;
    }

    .consolidated-report-table thead th {
      background: #fff;
      color: #000;
      font-size: 8.4px;
      font-weight: 700;
      line-height: 1.15;
      text-align: center;
      text-transform: uppercase;
    }

    .consolidated-report-table thead .is-lower {
      text-transform: none;
    }

    .consolidated-report-table tbody td {
      line-height: 1.18;
    }

    .consolidated-cell-index,
    .consolidated-cell-name,
    .consolidated-cell-prep,
    .consolidated-cell-students,
    .consolidated-cell-units,
    .consolidated-cell-hours,
    .consolidated-cell-load,
    .consolidated-cell-designation,
    .consolidated-cell-total,
    .consolidated-cell-remark {
      text-align: center;
      vertical-align: top !important;
    }

    .consolidated-cell-index,
    .consolidated-cell-name,
    .consolidated-cell-prep,
    .consolidated-cell-designation,
    .consolidated-cell-total,
    .consolidated-cell-remark {
      padding-top: 0.28rem !important;
    }

    .consolidated-cell-index {
      font-weight: 700;
    }

    .consolidated-cell-name {
      font-weight: 700;
      text-transform: uppercase;
      line-height: 1.12;
    }

    .consolidated-cell-prep,
    .consolidated-cell-code,
    .consolidated-cell-load,
    .consolidated-cell-total,
    .consolidated-cell-remark {
      font-weight: 700;
    }

    .consolidated-campus-term {
      font-size: 9px !important;
      font-weight: 700 !important;
      text-transform: none !important;
      padding: 0.28rem 0.2rem !important;
    }

    .consolidated-campus-term-line {
      display: block;
      line-height: 1.2;
    }

    .consolidated-campus-term-line + .consolidated-campus-term-line {
      margin-top: 0.08rem;
    }

    .consolidated-course-title,
    .consolidated-designation-title {
      font-weight: 700;
      color: #000;
    }

    .consolidated-schedule-line {
      display: block;
      white-space: pre-line;
    }

    .consolidated-schedule-line + .consolidated-schedule-line {
      margin-top: 0.12rem;
    }

    .consolidated-remark-text {
      display: inline-block;
      font-size: 9px;
      font-weight: 700;
      letter-spacing: 0.03em;
      text-transform: uppercase;
      color: #000;
    }

    .consolidated-faculty-total-row td {
      border-top-width: 1px;
      font-weight: 700;
      vertical-align: middle !important;
    }

    .consolidated-faculty-block,
    .consolidated-report-table tr {
      page-break-inside: avoid;
      break-inside: avoid;
    }

    .consolidated-cell-remark-total {
      text-align: center;
      vertical-align: middle !important;
    }

    .consolidated-signatory-page .consolidated-report-page-inner {
      inset: 208px 90px 88px 100px;
    }

    .consolidated-signatory-wrap {
      margin-top: auto;
      padding-top: 1rem;
      font-family: Arial, sans-serif;
      color: #182b3d;
    }

    .consolidated-signatory-top {
      display: grid;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 1.6rem;
    }

    .consolidated-signatory-bottom {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: 2rem;
      margin-top: 2.5rem;
      max-width: 31%;
    }

    .consolidated-signatory-label {
      margin-bottom: 1.5rem;
      font-size: 0.92rem;
      font-weight: 700;
    }

    .consolidated-signatory-name {
      min-height: 1.2rem;
      font-size: 0.86rem;
      font-weight: 800;
      text-transform: uppercase;
      border-bottom: 1px solid #455a6f;
      padding-bottom: 0.08rem;
    }

    .consolidated-signatory-title {
      min-height: 1.15rem;
      margin-top: 0.28rem;
      font-size: 0.82rem;
      color: #50657c;
    }

    #consolidatedReportMeasureRoot {
      position: absolute;
      left: -20000px;
      top: 0;
      visibility: hidden;
      pointer-events: none;
      width: 0;
      height: 0;
      overflow: hidden;
    }

    @media (max-width: 1199.98px) {
      .consolidated-report-preview-root {
        align-items: flex-start;
      }
    }

    @media (max-width: 767.98px) {
      .consolidated-report-modal .modal-body {
        padding: 0.65rem;
      }

      .consolidated-signatory-top {
        grid-template-columns: 1fr;
        gap: 1.2rem;
      }

      .consolidated-signatory-bottom {
        max-width: 100%;
      }
    }

    @media print {
      html,
      body {
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }

      body.consolidated-report-print-body .consolidated-report-preview-root {
        gap: 0 !important;
      }

      body.consolidated-report-print-body .consolidated-report-page {
        --consolidated-page-width: 14in;
        --consolidated-page-height: 8.5in;
        width: 14in !important;
        height: 8.5in !important;
        margin: 0 !important;
        box-shadow: none !important;
        page-break-after: always;
        break-after: page;
      }

      body.consolidated-report-print-body .consolidated-report-page:last-child {
        page-break-after: auto;
        break-after: auto;
      }
    }
  </style>
</head>

<body>
  <div class="layout-wrapper layout-content-navbar">
    <div class="layout-container">

      <!-- Sidebar -->
      <?php include 'sidebar.php'; ?>
      <!-- /Sidebar -->

      <div class="layout-page">

        <!-- Navbar -->
        <?php include 'navbar.php'; ?>
        <!-- /Navbar -->

        <div class="content-wrapper">
          <div class="container-xxl flex-grow-1 container-p-y">

            <!-- BREADCRUMB / TITLE -->
            <div class="d-flex justify-content-between align-items-center mb-3">
              <div>
                <small class="text-muted d-block">Campus Dashboard</small>
                <h4 class="fw-bold mb-0">
                  <?= htmlspecialchars($campusName); ?>
                  <?php if (!$isUniversitySummary): ?>
                    <span class="text-muted"> (<?= htmlspecialchars($campusCode); ?>)</span>
                  <?php endif; ?>
                </h4>
              </div>
              <div>
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                  <i class="bx bx-arrow-back"></i> Back to Institutional View
                </a>
              </div>
            </div>

            <div class="row">
              <!-- LEFT COLUMN -->
              <div class="col-lg-8 mb-4 order-0">

                <!-- CAMPUS HERO CARD -->
                <div class="card shadow-sm mb-4">
                  <div class="row align-items-center g-0">
                    <div class="col-sm-8 px-4 py-4">
                      <p class="card-section-title mb-1">
                        <?= $isUniversitySummary ? 'System-wide Overview' : 'Campus Overview'; ?>
                      </p>
                      <h4 class="text-primary fw-bold mb-2">
                        <?= $isUniversitySummary ? 'SKSU University Summary' : $campusName . ' Campus'; ?>
                      </h4>
                      <p class="mb-3 text-muted">
                        Welcome back, <strong><?= $_SESSION['username'] ?? 'Administrator'; ?></strong>!
                        This page shows an executive snapshot of
                        <?= $isUniversitySummary ? 'all SKSU campuses combined' : 'academic operations and resources for this campus'; ?>.
                      </p>
                      <div class="d-flex flex-wrap gap-2">
                        <a href="#" class="btn btn-sm btn-primary">
                          <i class="bx bx-file"></i>
                          <?= $isUniversitySummary ? 'Download University Report' : 'Download Campus Report'; ?>
                        </a>
                        <a href="../scheduler/" class="btn btn-sm btn-outline-primary">
                          <i class="bx bx-calendar"></i> View Room Utilization
                        </a>
                      </div>
                    </div>
                    <div class="col-sm-4 text-center">
                      <img
                        src="../assets/img/illustrations/man-with-laptop-light.png"
                        height="130"
                        alt="Overview"
                        class="img-fluid p-2"
                        data-app-dark-img="illustrations/man-with-laptop-dark.png"
                        data-app-light-img="illustrations/man-with-laptop-light.png"
                      />
                    </div>
                  </div>
                </div>

<!-- COLLEGES UNDER THIS CAMPUS -->
<div class="card shadow-sm mb-4">
  <div class="card-header">
    <p class="card-section-title mb-1">Colleges</p>
    <h5 class="m-0">
      Academic Units Under
      <?= $isUniversitySummary ? 'All Campuses' : htmlspecialchars($campusName) . ' Campus'; ?>
    </h5>
  </div>

  <div class="card-body">
    <!--
      GUI PURPOSE:
      - Introduce colleges as the PRIMARY decision layer
      - This is GUI-only (no backend filtering yet)
      - Clicking a college will later refine KPIs, rooms, programs, and faculty
    -->

    <!-- RESET OPTION -->
    <div class="mb-3">
<a
  href="campus_dashboard.php?campus_id=<?= urlencode($campusIdParam); ?>"
  class="badge <?= !$selectedCollegeId ? 'bg-secondary text-white' : 'bg-label-secondary'; ?>"
  style="text-decoration:none;"
>
  All Colleges
</a>

    </div>

    <!-- COLLEGE BADGES (TEMPORARY GUI DATA) -->
<div class="d-flex flex-wrap gap-2">

  <?php if ($isUniversitySummary): ?>
    <span class="badge bg-secondary">
      Colleges are shown per campus
    </span>

  <?php elseif (count($colleges) === 0): ?>
    <span class="badge bg-secondary">
      No colleges recorded for this campus
    </span>

  <?php else: ?>
    <?php foreach ($colleges as $col): ?>
      <?php
      $isActive = ($selectedCollegeId && (int)$selectedCollegeId === (int)$col['college_id']);
      ?>

      <a
        href="campus_dashboard.php?campus_id=<?= urlencode($campusIdParam); ?>&college_id=<?= (int)$col['college_id']; ?>"
        class="badge <?= $isActive ? 'bg-primary text-white' : 'bg-label-primary'; ?>"
        style="cursor:pointer; text-decoration:none;"
        title="College Code: <?= htmlspecialchars($col['college_code']); ?>"
      >
        <i class="bx bx-buildings me-1"></i>
        <?= htmlspecialchars($col['college_name']); ?>
      </a>

    <?php endforeach; ?>
  <?php endif; ?>

</div>


    <small class="text-muted d-block mt-3">
      Select a college to focus dashboard metrics, rooms, programs, and faculty assignments.
    </small>
  </div>
</div>


                <!-- KPI ROW -->
                <div class="card shadow-sm mb-4">
                  <div class="card-header d-flex justify-content-between align-items-center pb-2">
                    <div>
                      <p class="card-section-title mb-1">Key Indicators</p>
                      <h5 class="m-0">Academic Operations Overview</h5>
                    </div>
                    <span class="badge bg-label-primary">KPI Summary</span>
                  </div>
                  <div class="card-body px-4 py-3">
                    <div class="row gy-4">

                      <!-- Total Faculty (placeholder for now) -->
                      <div class="col-md-3 col-6">
                        <div class="kpi-box text-center">
                          <div class="kpi-icon bg-primary">
                            <i class="bx bx-user-voice"></i>
                          </div>
                          <h3 class="kpi-value text-info mt-2"><?= $facultyCount; ?></h3>
                          <small class="text-muted">
                            <?= $isUniversitySummary ? 'Faculty (All Campuses)' : 'Campus Faculty'; ?>
                          </small>
                        </div>
                      </div>

                      <!-- Programs Offered (placeholder) -->
                      <div class="col-md-3 col-6">
                        <div class="kpi-box text-center">
                          <div class="kpi-icon bg-info">
                            <i class="bx bx-book-open"></i>
                          </div>
                          <h3 class="kpi-value text-info mt-2"><?= (int)$programCount; ?></h3>
                          <small class="text-muted">Programs Offered</small>
                        </div>
                      </div>

                      <!-- Sections (placeholder) -->
                      <div class="col-md-3 col-6">
                        <div class="kpi-box text-center">
                          <div class="kpi-icon bg-success">
                            <i class="bx bx-group"></i>
                          </div>
                          <h3 class="kpi-value text-success mt-2"><?= (int)$sectionCount; ?></h3>
                          <small class="text-muted">Active Sections</small>
                        </div>
                      </div>

                      <!-- Rooms (real count) -->
                      <div class="col-md-3 col-6">
                        <div class="kpi-box text-center">
                          <div class="kpi-icon bg-warning">
                            <i class="bx bx-building-house"></i>
                          </div>
                          <h3 class="kpi-value text-warning mt-2">
                            <?= $roomCount; ?>
                          </h3>
                          <small class="text-muted">Total Rooms</small>
                        </div>
                      </div>

                    </div>
                  </div>
                </div>

                <!-- SCHEDULING HEALTH ANALYTICS -->
                <div class="card shadow-sm mb-4">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                      <p class="card-section-title mb-1">Scheduling Health</p>
                      <h5 class="m-0">Weekly Schedule Heatmap &amp; Room Utilization</h5>
                    </div>
                    <div class="d-flex flex-column flex-md-row align-items-md-center gap-2">
                      <?php if ($selectedCollegeId && $analyticsReady): ?>
                        <button
                          type="button"
                          class="btn btn-sm btn-outline-primary"
                          id="openMatrixModal"
                        >
                          <i class="bx bx-grid-alt me-1"></i>
                          View Schedule Matrix
                        </button>
                      <?php elseif (!$selectedCollegeId): ?>
                        <span class="text-muted small">Select a college to open the scheduler matrix.</span>
                      <?php endif; ?>
                      <span class="badge bg-label-info"><?= $academicTermTextEscaped ?></span>
                    </div>
                  </div>
                  <div class="card-body">
                    <?php if (!$analyticsReady): ?>
                      <div class="alert alert-warning mb-0">
                        Configure the current academic term first to view scheduling heatmaps and room utilization.
                      </div>
                    <?php else: ?>
                      <div class="row g-4 align-items-stretch">
                        <div class="col-xl-8">
                          <div class="d-flex justify-content-between align-items-start flex-column flex-md-row gap-2 mb-3">
                            <div>
                              <h6 class="fw-bold mb-1" id="scheduleHeatmapTitle">Weekly Schedule Heatmap</h6>
                              <small class="text-muted" id="scheduleHeatmapSubtitle"><?= htmlspecialchars($heatmapSubtext); ?></small>
                            </div>
                            <span class="badge bg-label-primary" id="scheduleHeatmapBadge">
                              Room Heat
                            </span>
                          </div>
                          <div class="heatmap-toolbar mb-3">
                            <div class="small text-muted" id="scheduleHeatmapFilterSummary">
                              Filter by room to inspect one classroom schedule.
                            </div>
                            <div class="heatmap-filter-control">
                              <label class="heatmap-filter-label" for="scheduleHeatmapRoomFilter">Room View</label>
                              <select class="form-select form-select-sm heatmap-room-filter" id="scheduleHeatmapRoomFilter" aria-label="Select room heatmap view" disabled>
                                <option value="all">All rooms</option>
                              </select>
                            </div>
                          </div>
                          <div id="scheduleHeatmapChart" class="chart-shell"></div>
                          <p class="chart-insight mb-0" id="scheduleHeatmapInsight">
                            This heat map highlights the busiest 30-minute room intervals for scheduling.
                          </p>
                        </div>

                        <div class="col-xl-4">
                          <div class="resource-stat-card">
                            <h6 class="fw-bold mb-1">Scheduling Snapshot</h6>
                            <p class="resource-note mb-0">Use these figures to spot teaching pressure before opening deeper reports.</p>

                            <div class="resource-stat">
                              <div class="resource-stat-label">Scheduled Classes</div>
                              <div class="resource-stat-value"><?= (int)$scheduleSummary['scheduled_classes']; ?></div>
                              <div class="resource-stat-note">Classes included in this current-term scheduling view.</div>
                            </div>

                            <div class="resource-stat">
                              <div class="resource-stat-label">Faculty Coverage</div>
                              <div class="resource-stat-value"><?= number_format($assignmentCoveragePercent, 1); ?>%</div>
                              <div class="resource-stat-note"><?= (int)$scheduleSummary['assigned_classes']; ?> assigned, <?= (int)$scheduleSummary['unassigned_classes']; ?> unassigned.</div>
                            </div>

                            <div class="resource-stat">
                              <div class="resource-stat-label">Top <?= htmlspecialchars($utilizationMetricLabel); ?></div>
                              <div class="resource-stat-value"><?= htmlspecialchars($highestUtilizationLabel); ?></div>
                              <div class="resource-stat-note"><?= number_format($highestUtilizationPercent, 1); ?>% of the 40-hour weekly baseline in the busiest item for this view.</div>
                            </div>

                            <div class="resource-stat">
                              <div class="resource-stat-label">Interpretation</div>
                              <div class="resource-stat-note">
                                Darker heatmap cells mean a higher percentage of rooms are occupied in that 30-minute block. Higher utilization shows where space is getting tight first.
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>

                      <hr class="my-4" />

                      <div class="d-flex justify-content-between align-items-start flex-column flex-md-row gap-2 mb-3">
                        <div>
                          <h6 class="fw-bold mb-1"><?= htmlspecialchars($utilizationHeading); ?></h6>
                          <small class="text-muted"><?= htmlspecialchars($utilizationSubtext); ?></small>
                        </div>
                        <small class="resource-note"><?= htmlspecialchars($resourceCapacityNote); ?></small>
                      </div>

                      <div id="roomUtilizationChart" class="chart-shell"></div>
                      <p class="chart-insight mb-0" id="roomUtilizationInsight">
                        This chart shows scheduled room-hours against the university's 40-hour weekly baseline so over-capacity room pools stand out immediately.
                      </p>

                      <div class="table-responsive mt-4">
                        <table class="table table-sm table-hover align-middle mb-0">
                          <thead>
                            <tr>
                              <th><?= htmlspecialchars($utilizationMetricLabel); ?></th>
                              <th class="text-center">Rooms</th>
                              <th class="text-end">Used Hours</th>
                              <th class="text-end">Capacity</th>
                              <th class="text-end">Utilization</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php if (empty($utilizationRows)): ?>
                              <tr>
                                <td colspan="5" class="text-center text-muted">
                                  No current-term room utilization data is available for this view.
                                </td>
                              </tr>
                            <?php else: ?>
                              <?php foreach ($utilizationRows as $row): ?>
                                <tr>
                                  <td class="fw-semibold"><?= htmlspecialchars($row['label']); ?></td>
                                  <td class="text-center"><?= (int)$row['room_count']; ?></td>
                                  <td class="text-end"><?= number_format((float)$row['used_hours'], 1); ?></td>
                                  <td class="text-end"><?= number_format((float)$row['capacity_hours'], 1); ?></td>
                                  <td class="text-end">
                                    <span class="badge <?= $row['utilization_percent'] >= 100 ? 'bg-label-danger' : ($row['utilization_percent'] >= 80 ? 'bg-label-warning' : 'bg-label-success'); ?>">
                                      <?= number_format((float)$row['utilization_percent'], 1); ?>%
                                    </span>
                                  </td>
                                </tr>
                              <?php endforeach; ?>
                            <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>

                  </div>
                </div>

                <!-- PROGRAMS / PROSPECTUS PLACEHOLDER -->
                <div class="card shadow-sm mb-4">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                      <p class="card-section-title mb-1">Programs & Prospectus</p>
                      <h5 class="m-0">Program Offerings Overview</h5>
                    </div>
                    <a href="manage-prospectus.php" class="btn btn-sm btn-outline-primary">
                      Manage Prospectus
                    </a>
                  </div>
                  <div class="card-body">
                    <p class="text-muted mb-2">
                      List all programs and prospectus versions for
                      <?= $isUniversitySummary ? 'the entire university.' : 'this campus.'; ?>
                    </p>
                    <div class="alert alert-info mb-0">
                      <i class="bx bx-info-circle me-1"></i>
                      <!-- Placeholder only for now. Hook this card to your existing Prospectus Builder queries when ready. -->
                    </div>
                  </div>
                </div>

              </div>
              <!-- /LEFT COLUMN -->

              <!-- RIGHT COLUMN -->
              <div class="col-lg-4 order-1 order-lg-0 mb-4">

                <!-- CAMPUS QUICK FACTS -->
                <div class="card shadow-sm mb-4">
                  <div class="card-header">
                    <p class="card-section-title mb-1">Quick Facts</p>
                    <h5 class="m-0">
                      <?= $isUniversitySummary ? 'University Snapshot' : 'Campus Snapshot'; ?>
                    </h5>
                  </div>
                  <div class="card-body">
                    <ul class="list-unstyled mb-0">
                      <li class="mb-2 d-flex justify-content-between">
                        <span class="text-muted">Campus Code</span>
                        <strong><?= htmlspecialchars($campusCode); ?></strong>
                      </li>
                      <li class="mb-2 d-flex justify-content-between">
                        <span class="text-muted">Total Rooms</span>
                        <strong><?= $roomCount; ?></strong>
                      </li>
                       <li class="mb-2 d-flex justify-content-between">
                         <span class="text-muted">Faculty</span>
                         <strong><?= (int)$facultyCount; ?></strong>
                       </li>
                       <li class="mb-2 d-flex justify-content-between">
                         <span class="text-muted">Programs</span>
                         <strong><?= (int)$programCount; ?></strong>
                       </li>
                    </ul>
                  </div>
                </div>

                <div class="card shadow-sm mb-4">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                      <p class="card-section-title mb-1">People</p>
                      <h5 class="m-0">Faculty Directory</h5>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                      <button type="button" class="btn btn-sm btn-outline-primary" id="btnOpenCampusConsolidatedReport">
                        <i class="bx bx-file me-1"></i> Consolidated Workload
                      </button>
                      <a href="manage-faculty.php" class="btn btn-sm btn-outline-primary">
                        Manage Faculty
                      </a>
                    </div>
                  </div>
                  <div class="card-body">
                    <p class="faculty-directory-note mb-3">
                      <?= htmlspecialchars($facultyDirectory['note'], ENT_QUOTES, 'UTF-8'); ?><br>
                      <span class="fw-semibold"><?= $facultyDirectoryScopeLabelEscaped; ?></span> |
                      <?= $academicTermTextEscaped; ?>
                    </p>

                    <?php if ($facultyDirectory['total'] > 0): ?>
                      <div class="faculty-directory-stats">
                        <div class="faculty-directory-stat">
                          <span class="faculty-directory-stat-label">Faculty</span>
                          <span class="faculty-directory-stat-value"><?= (int)$facultyDirectory['total']; ?></span>
                        </div>
                        <div class="faculty-directory-stat">
                          <span class="faculty-directory-stat-label">With Workload</span>
                          <span class="faculty-directory-stat-value"><?= (int)$facultyDirectory['faculty_with_workload']; ?></span>
                        </div>
                        <div class="faculty-directory-stat">
                          <span class="faculty-directory-stat-label">Assigned Classes</span>
                          <span class="faculty-directory-stat-value"><?= (int)$facultyDirectory['assigned_classes_total']; ?></span>
                        </div>
                        <div class="faculty-directory-stat">
                          <span class="faculty-directory-stat-label">Avg Total Load</span>
                          <span class="faculty-directory-stat-value"><?= htmlspecialchars(campus_dashboard_format_load_value((float)$facultyDirectory['average_total_load']), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                      </div>

                      <div class="faculty-directory-toolbar">
                        <div class="input-group input-group-sm">
                          <span class="input-group-text"><i class="bx bx-search"></i></span>
                          <input
                            type="search"
                            id="facultyDirectorySearch"
                            class="form-control"
                            placeholder="Search faculty, college, program, or subject"
                            autocomplete="off"
                          >
                          <button class="btn btn-outline-secondary" type="button" id="facultyDirectoryClearSearch">
                            Clear
                          </button>
                        </div>
                        <div class="faculty-directory-summary" id="facultyDirectorySummaryText">
                          Loading faculty directory...
                        </div>
                      </div>

                      <div class="faculty-directory-list" id="facultyDirectoryList"></div>
                    <?php else: ?>
                      <div class="faculty-directory-empty">
                        <i class="bx bx-user-x"></i>
                        No faculty assignments are available for the current campus and college settings.
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <!-- NAVIGATION TO OTHER CAMPUSES -->
                <div class="card shadow-sm">
                  <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="m-0">Switch Campus</h5>
                    <span class="badge bg-label-primary">Navigation</span>
                  </div>
                  <div class="card-body">
                    <div class="list-group list-group-flush">
                      <a href="campus_dashboard.php?campus_id=all" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="bx bx-globe me-2"></i>University Summary</span>
                      </a>
                      <?php
                      // Reuse campus list for quick switcher
                      $campSql = $conn->query("SELECT campus_id, campus_name FROM tbl_campus WHERE status='active' ORDER BY campus_name ASC");
                      while ($cRow = $campSql->fetch_assoc()):
                      ?>
                        <a
                          href="campus_dashboard.php?campus_id=<?= $cRow['campus_id']; ?>"
                          class="list-group-item list-group-item-action d-flex justify-content-between align-items-center
                          <?= (!$isUniversitySummary && (int)$campusId === (int)$cRow['campus_id']) ? 'active' : ''; ?>"
                        >
                          <span>
                            <i class="bx bx-buildings me-2"></i><?= htmlspecialchars($cRow['campus_name']); ?>
                          </span>
                          <?php if (!$isUniversitySummary && (int)$campusId === (int)$cRow['campus_id']): ?>
                            <i class="bx bx-check-circle"></i>
                          <?php endif; ?>
                        </a>
                      <?php endwhile; ?>
                    </div>
                  </div>
                </div>

              </div>
              <!-- /RIGHT COLUMN -->
            </div>

          </div>

          <!-- Footer -->
          <?php include '../footer.php'; ?>
          <!-- /Footer -->

          <div class="content-backdrop fade"></div>
        </div>
      </div>
    </div>

    <div class="layout-overlay layout-menu-toggle"></div>
  </div>

  <div class="modal fade" id="facultyDirectoryDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h5 class="modal-title mb-0" id="facultyDirectoryDetailTitle">Faculty Details</h5>
            <div class="faculty-detail-subtitle" id="facultyDirectoryDetailSubtitle">
              Current settings overview
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body" id="facultyDirectoryDetailBody">
          <div class="faculty-directory-empty">
            <i class="bx bx-user"></i>
            Select a faculty member to view current-setting details.
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade consolidated-report-modal" id="campusConsolidatedReportModal" tabindex="-1" aria-labelledby="campusConsolidatedReportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-lg-down modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <div>
            <h5 class="modal-title" id="campusConsolidatedReportModalLabel">Consolidated Faculty Workload</h5>
            <small class="text-muted">Preview first, then print the legal landscape report for the current academic term.</small>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="consolidatedReportPreviewRoot" class="consolidated-report-preview-root">
            <div class="consolidated-report-loading">
              <div class="consolidated-report-loading-card">
                <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
                <div class="consolidated-report-loading-title">Preparing consolidated faculty workload</div>
                <div class="consolidated-report-loading-copy">Open the report to generate the campus preview.</div>
                <div class="consolidated-report-progress">
                  <div class="consolidated-report-progress-meta">
                    <span id="consolidatedReportProgressLabel">Waiting to start</span>
                    <span id="consolidatedReportProgressValue">0%</span>
                  </div>
                  <div class="consolidated-report-progress-track">
                    <div id="consolidatedReportProgressBar" class="consolidated-report-progress-bar"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div id="consolidatedReportMeasureRoot" aria-hidden="true"></div>
        </div>
        <div class="modal-footer justify-content-between">
          <div class="consolidated-report-toolbar-note">
            <i class="bx bx-info-circle"></i>
            <span>Rows follow the current campus dashboard scope and current academic term.</span>
          </div>
          <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
            <button type="button" class="btn btn-outline-primary" id="btnRefreshConsolidatedReport">
              <i class="bx bx-refresh me-1"></i> Refresh Preview
            </button>
            <button type="button" class="btn btn-outline-success" id="btnExportConsolidatedExcel" disabled>
              <i class="bx bx-spreadsheet me-1"></i> Export Excel
            </button>
            <button type="button" class="btn btn-primary" id="btnPrintConsolidatedReport" disabled>
              <i class="bx bx-printer me-1"></i> Print Report
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="matrixModal" tabindex="-1" data-bs-backdrop="false" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-lg-down modal-xxl modal-dialog-scrollable">
      <div class="modal-content">

        <div class="modal-header">
          <div>
            <h5 class="modal-title mb-0" id="matrixModalTitle">
              <i class="bx bx-building me-1"></i> Room-Time Matrix
            </h5>
            <div class="small text-muted mt-1" id="matrixModalNote">
              <?php if ($selectedCollegeId): ?>
                Viewing <?= htmlspecialchars((string)$selectedCollegeName, ENT_QUOTES, 'UTF-8'); ?> |
              <?php endif; ?>
              Follows <?= htmlspecialchars((string)($selectedCollegeSchedulePolicy['source_label'] ?? 'Scheduling policy'), ENT_QUOTES, 'UTF-8'); ?>:
              <?= htmlspecialchars((string)($selectedCollegeSchedulePolicy['window_label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> | 12-hour time labels
            </div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div id="matrixContainer">
            <div class="text-center text-muted py-5">
              Loading schedule matrix...
            </div>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- Core JS -->
  <script src="../assets/vendor/libs/jquery/jquery.js"></script>
  <script src="../assets/vendor/libs/popper/popper.js"></script>
  <script src="../assets/vendor/js/bootstrap.js"></script>
  <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
  <script src="../assets/vendor/js/menu.js"></script>

  <!-- Vendors JS -->
  <script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>

  <!-- Main JS -->
  <script src="../assets/js/main.js"></script>

  <script>
    document.addEventListener("DOMContentLoaded", function () {
      var utilizationLabels = <?= json_encode($utilizationChartLabels); ?>;
      var utilizationUsed = <?= json_encode($utilizationChartUsed, JSON_NUMERIC_CHECK); ?>;
      var utilizationAvailable = <?= json_encode($utilizationChartAvailable, JSON_NUMERIC_CHECK); ?>;
      var analyticsReady = <?= $analyticsReady ? 'true' : 'false'; ?>;
      var campusIdParam = <?= json_encode((string)$campusIdParam); ?>;
      var dashboardCampusId = <?= $isUniversitySummary ? 0 : (int)$campusId; ?>;
      var currentAyId = <?= (int)$currentAyId; ?>;
      var currentSem = <?= (int)$currentSem; ?>;
      var currentAyLabel = <?= json_encode((string)($currentTerm['ay_label'] ?? '')); ?>;
      var selectedCollegeId = <?= (int)$selectedCollegeId; ?>;
      var selectedCollegeName = <?= json_encode(htmlspecialchars((string)($selectedCollegeName ?? ''), ENT_QUOTES, 'UTF-8')); ?>;
      var selectedCollegePolicySource = <?= json_encode((string)($selectedCollegeSchedulePolicy['source_label'] ?? 'Scheduling policy')); ?>;
      var selectedCollegePolicyWindow = <?= json_encode((string)($selectedCollegeSchedulePolicy['window_label'] ?? '')); ?>;
      var academicTermText = <?= json_encode(htmlspecialchars($academicTermText, ENT_QUOTES, 'UTF-8')); ?>;
      var csrfToken = <?= json_encode($csrfToken); ?>;
      var dashboardViewType = <?= json_encode($selectedCollegeId ? 'college' : ($isUniversitySummary ? 'university' : 'campus')); ?>;
      var utilizationMetricLabel = <?= json_encode((string)$utilizationMetricLabel); ?>;
      var highestUtilizationLabel = <?= json_encode((string)$highestUtilizationLabel); ?>;
      var highestUtilizationPercent = <?= json_encode((float)$highestUtilizationPercent, JSON_NUMERIC_CHECK); ?>;
      var facultyDirectoryData = <?= $facultyDirectoryJson; ?>;
      var consolidatedReportAssignees = <?= $consolidatedReportAssigneesJson; ?>;
      var consolidatedReportScope = <?= json_encode($consolidatedReportScope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      var consolidatedReportSignatories = <?= json_encode($consolidatedReportSignatories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
      var facultyDirectoryScopeLabel = <?= json_encode((string)$facultyDirectory['scope_label']); ?>;
      var facultyDirectorySearchInput = document.getElementById("facultyDirectorySearch");
      var facultyDirectoryClearButton = document.getElementById("facultyDirectoryClearSearch");
      var facultyDirectoryList = document.getElementById("facultyDirectoryList");
      var facultyDirectorySummaryText = document.getElementById("facultyDirectorySummaryText");
      var facultyDirectoryDetailModalElement = document.getElementById("facultyDirectoryDetailModal");
      var facultyDirectoryDetailTitle = document.getElementById("facultyDirectoryDetailTitle");
      var facultyDirectoryDetailSubtitle = document.getElementById("facultyDirectoryDetailSubtitle");
      var facultyDirectoryDetailBody = document.getElementById("facultyDirectoryDetailBody");
      var facultyDirectoryDetailModal = facultyDirectoryDetailModalElement && typeof bootstrap !== "undefined"
        ? new bootstrap.Modal(facultyDirectoryDetailModalElement)
        : null;
      var consolidatedReportModalElement = document.getElementById("campusConsolidatedReportModal");
      var consolidatedReportModal = consolidatedReportModalElement && typeof bootstrap !== "undefined"
        ? new bootstrap.Modal(consolidatedReportModalElement)
        : null;
      var consolidatedReportBackgroundUrl = new URL(
        "../assets/img/print/consolidated-faculty-workload-template.png",
        window.location.href
      ).href;
      var consolidatedReportGeneratedHtml = "";
      var consolidatedReportRecords = [];
      var consolidatedReportIsBuilding = false;
      var facultyDirectoryWorkloadRequest = null;
      var facultyDirectoryWorkloadCache = new Map();
      var facultyDirectoryActiveFacultyId = 0;
      var hasApexCharts = typeof ApexCharts !== "undefined";
      var scheduleHeatmapChart = null;
      var scheduleHeatmapRequest = null;
      var roomUtilizationChart = null;
      var scheduleHeatmapRoomSelection = "all";
      var syncingScheduleHeatmapRoomFilter = false;
      var scheduleHeatmapRoomFilter = document.getElementById("scheduleHeatmapRoomFilter");
      var scheduleHeatmapFilterSummary = document.getElementById("scheduleHeatmapFilterSummary");
      var scheduleHeatmapTitle = document.getElementById("scheduleHeatmapTitle");
      var scheduleHeatmapSubtitle = document.getElementById("scheduleHeatmapSubtitle");
      var scheduleHeatmapBadge = document.getElementById("scheduleHeatmapBadge");

      function escapeHtml(value) {
        return String(value || "")
          .replace(/&/g, "&amp;")
          .replace(/</g, "&lt;")
          .replace(/>/g, "&gt;")
          .replace(/"/g, "&quot;")
          .replace(/'/g, "&#039;");
      }

      function buildInlineLoader(message) {
        return (
          '<div class="loader-inline">' +
            '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>' +
            '<span>' + escapeHtml(message) + "</span>" +
          "</div>"
        );
      }

      function setChartFallback(containerId, message) {
        var chartContainer = document.getElementById(containerId);
        if (!chartContainer) {
          return;
        }

        chartContainer.innerHTML =
          '<div class="chart-fallback"><p class="mb-0">' + escapeHtml(message) + "</p></div>";
      }

      function setChartLoading(containerId, message) {
        var chartContainer = document.getElementById(containerId);
        if (!chartContainer) {
          return;
        }

        chartContainer.innerHTML =
          '<div class="chart-loader">' + buildInlineLoader(message) + "</div>";
      }

      function setInsight(elementId, message) {
        var element = document.getElementById(elementId);
        if (element) {
          element.textContent = message;
        }
      }

      function formatLoadValue(value) {
        var numericValue = Number(value) || 0;
        if (Math.abs(numericValue - Math.round(numericValue)) < 0.01) {
          return String(Math.round(numericValue));
        }

        return numericValue.toFixed(2).replace(/\.00$/, "").replace(/(\.\d*[1-9])0+$/, "$1");
      }

      function toNumber(value) {
        var numericValue = Number(value);
        return Number.isFinite(numericValue) ? numericValue : 0;
      }

      function formatNumber(value) {
        var numericValue = toNumber(value);
        return Number.isInteger(numericValue) ? String(numericValue) : String(parseFloat(numericValue.toFixed(2)));
      }

      function formatStudentCount(value) {
        var numericValue = Math.round(toNumber(value));
        return numericValue > 0 ? String(numericValue) : "";
      }

      function getNormalLoadUnits(preparationCount) {
        var prepCount = Math.max(0, Math.floor(toNumber(preparationCount)));
        return prepCount >= 2 ? 18 : 21;
      }

      function getLoadStatus(loadValue, preparationCount) {
        var numericLoad = toNumber(loadValue);
        var normalLoadUnits = getNormalLoadUnits(preparationCount);
        var tolerance = 0.0001;

        if (numericLoad > normalLoadUnits + tolerance) {
          return { label: "Overload", className: "overload" };
        }

        if (numericLoad >= normalLoadUnits - tolerance) {
          return { label: "", className: "normal" };
        }

        return { label: "Underload", className: "underload" };
      }

      function facultyDirectoryEmptyMarkup(message) {
        return (
          '<div class="faculty-directory-empty">' +
            '<i class="bx bx-user"></i>' +
            escapeHtml(message) +
          "</div>"
        );
      }

      function facultyDirectorySearchBlob(item) {
        var parts = [
          item && item.full_name,
          item && item.status_label,
          item && item.designation_label,
          item && item.designation_name,
          item && item.college_label,
          item && item.campus_label
        ];

        if (item && Array.isArray(item.program_labels)) {
          parts.push(item.program_labels.join(" "));
        }
        if (item && Array.isArray(item.section_labels)) {
          parts.push(item.section_labels.join(" "));
        }
        if (item && Array.isArray(item.subject_labels)) {
          parts.push(item.subject_labels.join(" "));
        }
        if (item && Array.isArray(item.class_assignment_labels)) {
          parts.push(item.class_assignment_labels.join(" "));
        }

        return parts.join(" ").toLowerCase();
      }

      function renderFacultyDirectorySummary(rows, keyword) {
        if (!facultyDirectorySummaryText) {
          return;
        }

        var total = Array.isArray(rows) ? rows.length : 0;
        if (total === 0) {
          facultyDirectorySummaryText.textContent = keyword
            ? "No faculty matched your search in the current settings."
            : "No faculty assignments are available in the current settings.";
          return;
        }

        var assignedFaculty = rows.filter(function (row) {
          return Number(row && row.assigned_count) > 0;
        }).length;
        var assignedClasses = rows.reduce(function (sum, row) {
          return sum + (Number(row && row.assigned_count) || 0);
        }, 0);

        facultyDirectorySummaryText.textContent =
          "Showing " + total + " faculty in " + facultyDirectoryScopeLabel +
          " | " + assignedFaculty + " with workload | " + assignedClasses + " assigned classes";
      }

      function buildFacultyDirectoryItemMarkup(item) {
        var statusClass = String(item && item.status || "").toLowerCase() === "inactive"
          ? "bg-label-danger"
          : "bg-label-success";
        var designationLabel = String(item && (item.designation_label || item.designation_name) || "").trim();
        var contextLineParts = [];

        if (designationLabel) {
          contextLineParts.push(designationLabel);
        }
        if (item && item.college_label) {
          contextLineParts.push(String(item.college_label));
        }
        if (!contextLineParts.length) {
          contextLineParts.push("Current scope: " + facultyDirectoryScopeLabel);
        }

        return (
          '<button type="button" class="faculty-directory-item btn-view-faculty-detail" data-faculty-id="' + escapeHtml(item.faculty_id) + '">' +
            '<div class="faculty-directory-item-top">' +
              '<div class="min-w-0 flex-grow-1">' +
                '<div class="faculty-directory-item-name">' + escapeHtml(item.full_name || "Unnamed Faculty") + "</div>" +
                '<div class="faculty-directory-item-subtext">' + escapeHtml(contextLineParts.join(" | ")) + "</div>" +
              "</div>" +
              '<span class="badge ' + statusClass + '">' + escapeHtml(item.status_label || "Active") + "</span>" +
            "</div>" +
            '<div class="faculty-directory-item-metrics">' +
              '<span class="faculty-directory-chip is-accent"><i class="bx bx-briefcase-alt-2"></i>' + escapeHtml(String(Number(item.assigned_count) || 0)) + " class" + ((Number(item.assigned_count) || 0) === 1 ? "" : "es") + "</span>" +
              '<span class="faculty-directory-chip is-success"><i class="bx bx-line-chart"></i>' + escapeHtml(formatLoadValue(item.total_load)) + " total load</span>" +
              '<span class="faculty-directory-chip"><i class="bx bx-book-content"></i>' + escapeHtml(String(Number(item.total_preparations) || 0)) + " prep" + ((Number(item.total_preparations) || 0) === 1 ? "" : "s") + "</span>" +
              '<span class="faculty-directory-chip is-warning"><i class="bx bx-layer"></i>' + escapeHtml(String(Number(item.program_count) || 0)) + " program" + ((Number(item.program_count) || 0) === 1 ? "" : "s") + "</span>" +
            "</div>" +
          "</button>"
        );
      }

      function renderFacultyDirectoryList() {
        if (!facultyDirectoryList) {
          return;
        }

        var keyword = facultyDirectorySearchInput
          ? String(facultyDirectorySearchInput.value || "").trim().toLowerCase()
          : "";

        var rows = (Array.isArray(facultyDirectoryData) ? facultyDirectoryData : []).filter(function (item) {
          if (keyword === "") {
            return true;
          }

          return facultyDirectorySearchBlob(item).indexOf(keyword) !== -1;
        });

        renderFacultyDirectorySummary(rows, keyword);

        if (!rows.length) {
          facultyDirectoryList.innerHTML = facultyDirectoryEmptyMarkup("No faculty matched the current search.");
          return;
        }

        facultyDirectoryList.innerHTML = rows.map(buildFacultyDirectoryItemMarkup).join("");
      }

      function buildFacultyDetailField(label, value) {
        return (
          '<div class="faculty-detail-field">' +
            '<span class="faculty-detail-label">' + escapeHtml(label) + "</span>" +
            '<span class="faculty-detail-value">' + escapeHtml(value || "--") + "</span>" +
          "</div>"
        );
      }

      function buildFacultyDetailBadges(labels, fallback) {
        if (!Array.isArray(labels) || labels.length === 0) {
          return '<div class="faculty-directory-empty">' + escapeHtml(fallback) + "</div>";
        }

        return '<div class="faculty-detail-badge-list">' + labels.map(function (label) {
          return '<span class="faculty-detail-badge">' + escapeHtml(label) + "</span>";
        }).join("") + "</div>";
      }

      function buildFacultyWorkloadLoader(message) {
        return (
          '<div class="faculty-workload-loader">' +
            '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>' +
            '<span>' + escapeHtml(message) + "</span>" +
          "</div>"
        );
      }

      function formatCompactTime(value) {
        var raw = String(value == null ? "" : value).trim();
        var parts;

        if (raw === "") {
          return "";
        }

        parts = raw.split("-");
        if (parts.length !== 2) {
          return escapeHtml(raw);
        }

        return (
          '<span class="time-line">' + escapeHtml(parts[0].trim()) + "</span>" +
          '<span class="time-line">' + escapeHtml(parts[1].trim()) + "</span>"
        );
      }

      function getWorkloadGroupKey(row) {
        var groupId = Number(row && row.group_id) || 0;
        if (groupId > 0) {
          return "group:" + groupId;
        }

        var offeringId = Number(row && row.offering_id) || 0;
        if (offeringId > 0) {
          return "offering:" + offeringId;
        }

        return "workload:" + (Number(row && row.workload_id) || 0);
      }

      function calculateWorkloadMetricTotals(rowsData) {
        var sourceRows = Array.isArray(rowsData) ? rowsData : [];
        var countedGroups = new Set();
        var preparationSet = new Set();
        var totals = {
          unit: 0,
          lab: 0,
          lec: 0,
          load: 0,
          preparations: 0
        };

        sourceRows.forEach(function (row) {
          var groupKey = getWorkloadGroupKey(row);
          var preparationKey = String(row && row.sub_code || "").trim();

          if (!countedGroups.has(groupKey)) {
            countedGroups.add(groupKey);
            totals.unit += toNumber(row && row.units);
            totals.lab += toNumber(row && row.lab);
            totals.lec += toNumber(row && row.lec);
            totals.load += toNumber(row && row.faculty_load);
          }

          if (preparationKey !== "") {
            preparationSet.add(preparationKey);
          }
        });

        totals.preparations = preparationSet.size;
        return totals;
      }

      function formatDesignationDisplay(meta, item) {
        var sourceMeta = meta || {};
        var designationName = String(sourceMeta.designation_name || item && item.designation_name || "").trim();
        var designationLabel = String(sourceMeta.designation_label || item && (item.designation_label || item.designation_name) || "").trim();
        var collegeLabel = String(selectedCollegeName || item && item.college_label || "").trim();

        if (!designationLabel) {
          return "";
        }

        if (designationName.toUpperCase() === "DEAN" && collegeLabel !== "") {
          return designationLabel + ", " + collegeLabel;
        }

        return designationLabel;
      }

      function buildWorkloadDescription(row, isPaired) {
        var description = escapeHtml(row && row.desc || "");
        var mergeNote = String(row && row.merge_note || "").trim();
        var normalizedType = String(row && (row.type || row.schedule_type) || "").toUpperCase();
        var typeLabel = normalizedType === "LAB" ? "Lab" : (normalizedType === "LEC" ? "Lec" : "");
        var noteParts = [];
        var html = description;
        var typeValue;

        if (isPaired) {
          typeValue = normalizedType === "LAB" ? "LAB" : "LEC";
          noteParts.push('<span class="type-pill ' + typeValue.toLowerCase() + '">' + escapeHtml(typeValue) + "</span>");
        }

        if (typeLabel) {
          html += '<span class="print-type-suffix"> (' + escapeHtml(typeLabel) + ")</span>";
        }

        if (noteParts.length > 0) {
          html += '<span class="schedule-partner-note">' + noteParts.join("") + "</span>";
        }

        if (mergeNote) {
          html += '<span class="workload-merge-note">' + escapeHtml(mergeNote) + "</span>";
        }

        return html;
      }

      function buildFacultyWorkloadPanelMarkup(payload, item) {
        var response = payload || {};
        var rows = Array.isArray(response.rows) ? response.rows : [];
        var meta = response.meta || {};
        var totals;
        var preparationCount;
        var designationUnits;
        var designationText;
        var grandTotalUnits;
        var grandTotalLoad;
        var loadStatus;
        var tableBody = "";
        var i;
        var row;
        var groupKey;
        var groupRows;
        var candidateRow;
        var displayUnits;
        var displayLabUnits;
        var displayLecUnits;
        var mergedStudents;
        var j;

        if (!rows.length) {
          return (
            '<div class="card workload-card">' +
              '<div class="card-header">' +
                '<h5 class="m-0">Current Faculty Workload</h5>' +
                '<small class="text-muted">Classes already assigned for this term</small>' +
              "</div>" +
              '<div class="card-body">' +
                '<div class="faculty-directory-workload-alert">' +
                  '<div class="alert mb-0">' +
                    '<strong>Faculty Selected:</strong> ' + escapeHtml(item && item.full_name || "Selected Faculty") +
                    ' | <span class="fw-summary-label">Term:</span> ' + escapeHtml(String(meta.term_text || academicTermText || "Current academic term")) +
                  "</div>" +
                "</div>" +
                facultyDirectoryEmptyMarkup("No workload assigned yet.") +
              "</div>" +
            "</div>"
          );
        }

        totals = calculateWorkloadMetricTotals(rows);
        preparationCount = Math.max(Number(meta.total_preparations) || 0, totals.preparations);
        designationUnits = toNumber(meta.designation_units || item && item.designation_units);
        designationText = formatDesignationDisplay(meta, item);
        grandTotalUnits = totals.unit + designationUnits;
        grandTotalLoad = totals.load + designationUnits;
        loadStatus = getLoadStatus(grandTotalLoad, preparationCount);

        for (i = 0; i < rows.length; i += 1) {
          row = rows[i];
          groupKey = getWorkloadGroupKey(row);
          groupRows = [row];

          while ((i + groupRows.length) < rows.length) {
            candidateRow = rows[i + groupRows.length];
            if (getWorkloadGroupKey(candidateRow) !== groupKey) {
              break;
            }
            groupRows.push(candidateRow);
          }

          displayUnits = toNumber(row && row.units);
          displayLabUnits = toNumber(row && row.lab);
          displayLecUnits = toNumber(row && row.lec);

          if (groupRows.length > 1) {
            mergedStudents = groupRows.reduce(function (maxValue, groupRow) {
              return Math.max(maxValue, toNumber(groupRow && groupRow.student_count));
            }, 0);

            for (j = 0; j < groupRows.length; j += 1) {
              row = groupRows[j];
              tableBody +=
                '<tr class="' + (j === 0 ? 'paired-row paired-anchor' : 'paired-row') + '">' +
                  '<td class="workload-code">' + escapeHtml(String(row && row.sub_code || '')) + '</td>' +
                  '<td class="workload-desc">' + buildWorkloadDescription(row, true) + '</td>' +
                  '<td>' + escapeHtml(String(row && (row.course || row.section) || '')) + '</td>' +
                  '<td class="workload-days">' + escapeHtml(String(row && row.days || '')) + '</td>' +
                  '<td class="workload-time">' + formatCompactTime(String(row && row.time || '')) + '</td>' +
                  '<td class="workload-room">' + escapeHtml(String(row && row.room || '')) + '</td>' +
                  (j === 0
                    ? '<td class="text-center merged-metric" rowspan="' + groupRows.length + '">' + escapeHtml(formatNumber(displayUnits)) + '</td>' +
                      '<td class="text-center merged-metric" rowspan="' + groupRows.length + '">' + escapeHtml(formatNumber(displayLabUnits)) + '</td>' +
                      '<td class="text-center merged-metric" rowspan="' + groupRows.length + '">' + escapeHtml(formatNumber(displayLecUnits)) + '</td>' +
                      '<td class="text-center merged-metric" rowspan="' + groupRows.length + '">' + escapeHtml(formatNumber(row && row.faculty_load)) + '</td>' +
                      '<td class="text-center merged-metric" rowspan="' + groupRows.length + '">' + escapeHtml(formatStudentCount(mergedStudents)) + '</td>'
                    : '') +
                '</tr>';
            }

            i += groupRows.length - 1;
            continue;
          }

          tableBody +=
            '<tr>' +
              '<td class="workload-code">' + escapeHtml(String(row && row.sub_code || '')) + '</td>' +
              '<td class="workload-desc">' + buildWorkloadDescription(row, false) + '</td>' +
              '<td>' + escapeHtml(String(row && (row.course || row.section) || '')) + '</td>' +
              '<td class="workload-days">' + escapeHtml(String(row && row.days || '')) + '</td>' +
              '<td class="workload-time">' + formatCompactTime(String(row && row.time || '')) + '</td>' +
              '<td class="workload-room">' + escapeHtml(String(row && row.room || '')) + '</td>' +
              '<td class="text-center">' + escapeHtml(formatNumber(displayUnits)) + '</td>' +
              '<td class="text-center">' + escapeHtml(formatNumber(displayLabUnits)) + '</td>' +
              '<td class="text-center">' + escapeHtml(formatNumber(displayLecUnits)) + '</td>' +
              '<td class="text-center fw-semibold">' + escapeHtml(formatNumber(row && row.faculty_load)) + '</td>' +
              '<td class="text-center">' + escapeHtml(formatStudentCount(row && row.student_count)) + '</td>' +
            '</tr>';
        }

        if (tableBody === "") {
          tableBody = '<tr><td colspan="11" class="text-center text-muted">No workload assigned yet.</td></tr>';
        }

        return (
          '<div class="card workload-card">' +
            '<div class="card-header">' +
              '<h5 class="m-0">Current Faculty Workload</h5>' +
              '<small class="text-muted">Classes already assigned for this term</small>' +
            '</div>' +
            '<div class="card-body">' +
              '<div class="faculty-directory-workload-alert">' +
                '<div class="alert mb-0">' +
                  '<strong>Faculty Selected:</strong> ' + escapeHtml(item && item.full_name || 'Selected Faculty') +
                  ' | <span class="fw-summary-label">Term:</span> ' + escapeHtml(String(meta.term_text || academicTermText || 'Current academic term')) +
                '</div>' +
              '</div>' +
              '<div class="table-responsive">' +
                '<table class="table table-hover table-sm mb-0 workload-table">' +
                  '<thead class="table-light">' +
                    '<tr>' +
                      '<th rowspan="2">Course No.</th>' +
                      '<th rowspan="2">Course Description</th>' +
                      '<th rowspan="2" class="course-head">Course</th>' +
                      '<th rowspan="2" class="day-head">Day</th>' +
                      '<th rowspan="2" class="time-head">Time</th>' +
                      '<th rowspan="2" class="room-head">Room</th>' +
                      '<th rowspan="2" class="text-center unit-head">Unit</th>' +
                      '<th colspan="2" class="text-center hours-group-head">No. of Hours</th>' +
                      '<th rowspan="2" class="text-center load-head">Load</th>' +
                      '<th rowspan="2" class="text-center students-head"># of<br>Students</th>' +
                    '</tr>' +
                    '<tr>' +
                      '<th class="text-center hours-subhead">Lab</th>' +
                      '<th class="text-center hours-subhead">Lec</th>' +
                    '</tr>' +
                  '</thead>' +
                  '<tbody>' + tableBody + '</tbody>' +
                  '<tfoot class="table-light">' +
                    '<tr class="workload-summary-row">' +
                      '<th colspan="2" class="text-start workload-summary-label">Designation:</th>' +
                      '<td colspan="4" class="workload-summary-value">' + escapeHtml(designationText) + '</td>' +
                      '<td class="text-center fw-semibold">' + (designationUnits > 0 ? escapeHtml(formatNumber(designationUnits)) : '') + '</td>' +
                      '<td></td>' +
                      '<td></td>' +
                      '<td class="text-center fw-semibold">' + (designationUnits > 0 ? escapeHtml(formatNumber(designationUnits)) : '') + '</td>' +
                      '<td></td>' +
                    '</tr>' +
                    '<tr class="workload-summary-row summary-separator">' +
                      '<th colspan="2" class="text-start workload-summary-label">No. of Prep:</th>' +
                      '<td colspan="4" class="workload-summary-value">' + escapeHtml(String(preparationCount)) + '</td>' +
                      '<td></td>' +
                      '<td></td>' +
                      '<td></td>' +
                      '<td></td>' +
                      '<td></td>' +
                    '</tr>' +
                    '<tr class="workload-summary-row workload-total-row">' +
                      '<th colspan="6" class="text-end fw-semibold total-label">Total Load</th>' +
                      '<th class="text-center">' + escapeHtml(formatNumber(grandTotalUnits)) + '</th>' +
                      '<th class="text-center">' + escapeHtml(formatNumber(totals.lab)) + '</th>' +
                      '<th class="text-center">' + escapeHtml(formatNumber(totals.lec)) + '</th>' +
                      '<th class="text-center fw-semibold workload-total-load-screen">' +
                        '<span class="total-load-screen-inner">' +
                          '<span class="total-load-value">' + escapeHtml(formatNumber(grandTotalLoad)) + '</span>' +
                          (loadStatus.label ? '<span class="load-status-inline ' + escapeHtml(loadStatus.className) + '">' + escapeHtml(loadStatus.label) + '</span>' : '') +
                        '</span>' +
                      '</th>' +
                      '<th class="text-center"></th>' +
                    '</tr>' +
                  '</tfoot>' +
                '</table>' +
              '</div>' +
            '</div>' +
          '</div>'
        );
      }

      function uppercaseDisplayText(value) {
        return String(value == null ? "" : value).trim().toUpperCase();
      }

      function semesterUiLabel(value) {
        var semester = Number(value) || 0;
        if (semester === 1) {
          return "1st";
        }
        if (semester === 2) {
          return "2nd";
        }
        if (semester === 3) {
          return "Midyear";
        }

        return "Current";
      }

      function getConsolidatedTermContext() {
        return {
          ayId: Number(currentAyId) || 0,
          ayText: String(currentAyLabel || "").trim(),
          semesterNum: Number(currentSem) || 0,
          semesterUi: semesterUiLabel(currentSem),
          termText: String(academicTermText || "Current academic term").trim()
        };
      }

      function getConsolidatedReportTermLabel(termContext) {
        if (termContext && termContext.ayText) {
          return String(termContext.semesterUi || "Current") + " Semester AY " + termContext.ayText;
        }

        return String(termContext && termContext.termText || academicTermText || "Current academic term");
      }

      function getConsolidatedCampusHeaderLines(termContext) {
        var scope = consolidatedReportScope || {};
        var campusSource = String(scope.campus_name || "Campus").trim();
        var campusLabel = campusSource.replace(/\s+campus$/i, "").trim().toUpperCase() || "CAMPUS";
        var semesterLabel = String(termContext && termContext.semesterUi || "").trim() || "Semester";
        var ayLabel = String(termContext && termContext.ayText || "").trim();

        return [
          campusLabel,
          "Campus",
          semesterLabel,
          ayLabel ? "Semester AY " + ayLabel : "Semester AY"
        ];
      }

      function normalizeWorkloadResponse(payload) {
        if (Array.isArray(payload)) {
          return {
            rows: payload,
            external_rows: [],
            meta: {}
          };
        }

        if (!payload || !Array.isArray(payload.rows)) {
          return null;
        }

        return {
          rows: payload.rows,
          external_rows: Array.isArray(payload.external_rows) ? payload.external_rows : [],
          meta: payload.meta || {}
        };
      }

      function getConsolidatedRemarkMeta(loadStatus) {
        var statusClass = String(loadStatus && loadStatus.className || "normal").trim() || "normal";
        if (statusClass === "overload") {
          return { code: "OL", className: "is-overload" };
        }
        if (statusClass === "underload") {
          return { code: "UL", className: "is-underload" };
        }

        return { code: "NL", className: "is-normal" };
      }

      function formatConsolidatedRemarkVariance(value) {
        var n = Math.abs(toNumber(value));
        return n > 0.0001 ? n.toFixed(2) : "";
      }

      function setConsolidatedReportPrintEnabled(enabled) {
        var button = document.getElementById("btnPrintConsolidatedReport");
        var excelButton = document.getElementById("btnExportConsolidatedExcel");
        if (button) {
          button.disabled = !enabled;
        }
        if (excelButton) {
          excelButton.disabled = !enabled;
        }
      }

      function setConsolidatedReportProgress(percent, label) {
        var safePercent = Math.max(0, Math.min(100, Math.round(Number(percent) || 0)));
        var labelElement = document.getElementById("consolidatedReportProgressLabel");
        var valueElement = document.getElementById("consolidatedReportProgressValue");
        var barElement = document.getElementById("consolidatedReportProgressBar");

        if (labelElement) {
          labelElement.textContent = String(label || "Preparing preview...");
        }
        if (valueElement) {
          valueElement.textContent = safePercent + "%";
        }
        if (barElement) {
          barElement.style.width = safePercent + "%";
        }
      }

      function showConsolidatedReportLoading(label, percent) {
        var previewRoot = document.getElementById("consolidatedReportPreviewRoot");
        var safePercent = Math.max(0, Math.min(100, Math.round(Number(percent) || 0)));
        consolidatedReportGeneratedHtml = "";
        consolidatedReportRecords = [];
        setConsolidatedReportPrintEnabled(false);

        if (!previewRoot) {
          return;
        }

        previewRoot.innerHTML =
          '<div class="consolidated-report-loading">' +
            '<div class="consolidated-report-loading-card">' +
              '<div class="spinner-border text-primary" role="status" aria-hidden="true"></div>' +
              '<div class="consolidated-report-loading-title">Preparing consolidated faculty workload</div>' +
              '<div class="consolidated-report-loading-copy">The preview is built from actual scheduler workload in the current dashboard scope.</div>' +
              '<div class="consolidated-report-progress">' +
                '<div class="consolidated-report-progress-meta">' +
                  '<span id="consolidatedReportProgressLabel">' + escapeHtml(label || "Preparing preview...") + "</span>" +
                  '<span id="consolidatedReportProgressValue">' + escapeHtml(String(safePercent)) + "%</span>" +
                "</div>" +
                '<div class="consolidated-report-progress-track">' +
                  '<div id="consolidatedReportProgressBar" class="consolidated-report-progress-bar" style="width:' + safePercent + '%;"></div>' +
                "</div>" +
              "</div>" +
            "</div>" +
          "</div>";
      }

      function setConsolidatedReportEmptyState(title, message) {
        var previewRoot = document.getElementById("consolidatedReportPreviewRoot");
        consolidatedReportGeneratedHtml = "";
        consolidatedReportRecords = [];
        setConsolidatedReportPrintEnabled(false);

        if (previewRoot) {
          previewRoot.innerHTML =
            '<div class="consolidated-report-empty-state">' +
              '<i class="bx bx-file-blank"></i>' +
              '<div class="consolidated-report-empty-title">' + escapeHtml(title) + "</div>" +
              '<div class="consolidated-report-empty-copy">' + escapeHtml(message) + "</div>" +
            "</div>";
        }
      }

      function fetchConsolidatedAssigneeWorkloadPayload(option) {
        var assigneeType = String(option && option.assignee_type || "faculty").trim() === "faculty_need"
          ? "faculty_need"
          : "faculty";
        var facultyId = assigneeType === "faculty" ? (Number(option && option.faculty_id) || 0) : 0;
        var facultyNeedId = assigneeType === "faculty_need" ? (Number(option && option.faculty_need_id) || 0) : 0;
        var scopeCollegeId = assigneeType === "faculty_need"
          ? (Number(option && option.college_id) || 0)
          : (Number(selectedCollegeId) || 0);

        return new Promise(function (resolve, reject) {
          $.ajax({
            url: "../backend/query_admin_faculty_workload.php",
            type: "POST",
            dataType: "json",
            data: {
              assignee_type: assigneeType,
              faculty_id: facultyId,
              faculty_need_id: facultyNeedId,
              college_id: scopeCollegeId,
              campus_id: Number(dashboardCampusId) || 0
            }
          }).done(resolve).fail(reject);
        });
      }

      function buildConsolidatedScheduleLine(row, includeTypeTag) {
        var dayLabel = String(row && row.days || "").trim();
        var timeLabel = String(row && row.time || "").trim();
        var typeLabel = String(row && (row.type || row.schedule_type) || "").trim().toUpperCase();
        var parts = [];
        var line;

        if (dayLabel) {
          parts.push(dayLabel);
        }
        if (timeLabel) {
          parts.push(timeLabel);
        }

        line = parts.join(" ").trim() || "-";
        if (includeTypeTag && typeLabel) {
          line += " (" + (typeLabel === "LAB" ? "Lab" : "Lec") + ")";
        }

        return line;
      }

      function groupWorkloadRowsForConsolidatedReport(rowsData) {
        var groups = [];
        var orderedRows = Array.isArray(rowsData) ? rowsData : [];
        var groupedRows = new Map();

        orderedRows.forEach(function (row) {
          var groupKey = getWorkloadGroupKey(row);
          if (!groupedRows.has(groupKey)) {
            groupedRows.set(groupKey, []);
          }
          groupedRows.get(groupKey).push(row);
        });

        groupedRows.forEach(function (groupRows) {
          var firstRow = groupRows[0] || {};
          var typeMap = new Map();
          var scheduleLines = [];
          var uniqueTypes;

          groupRows.forEach(function (groupRow) {
            var typeLabel = String(groupRow && (groupRow.type || groupRow.schedule_type) || "").trim().toUpperCase();
            if (typeLabel) {
              typeMap.set(typeLabel, true);
            }
          });
          uniqueTypes = Array.from(typeMap.keys());

          groupRows.forEach(function (groupRow) {
            var line = buildConsolidatedScheduleLine(groupRow, uniqueTypes.length > 1);
            if (line && scheduleLines.indexOf(line) === -1) {
              scheduleLines.push(line);
            }
          });

          groups.push({
            sub_code: String(firstRow && firstRow.sub_code || "").trim(),
            desc: String(firstRow && firstRow.desc || "").trim(),
            course: String(firstRow && (firstRow.course || firstRow.section) || "").trim(),
            student_count: groupRows.reduce(function (maxValue, groupRow) {
              return Math.max(maxValue, Math.round(toNumber(groupRow && groupRow.student_count)));
            }, 0),
            units: toNumber(firstRow && firstRow.units),
            lec: toNumber(firstRow && firstRow.lec),
            lab: toNumber(firstRow && firstRow.lab),
            faculty_load: toNumber(firstRow && firstRow.faculty_load),
            schedule_lines: scheduleLines.length > 0 ? scheduleLines : ["-"],
            type_labels: uniqueTypes
          });
        });

        return groups;
      }

      function buildConsolidatedFacultyRecord(option, payload) {
        var sourcePayload = normalizeWorkloadResponse(payload);
        var rows;
        var entries;
        var meta;
        var totals;
        var designationUnits;
        var totalPreparations;
        var teachingLoad;
        var totalWorkload;
        var normalLoadUnits;
        var loadStatus;
        var remarkMeta;
        var overloadExcess;

        if (!sourcePayload) {
          return null;
        }

        rows = Array.isArray(sourcePayload.rows) ? sourcePayload.rows : [];
        entries = groupWorkloadRowsForConsolidatedReport(rows);
        meta = sourcePayload.meta || {};
        totals = calculateWorkloadMetricTotals(rows);
        designationUnits = Math.max(toNumber(meta.designation_units), toNumber(option && option.designation_units));
        totalPreparations = Math.max(
          Number(meta.total_preparations) || 0,
          Number(option && option.total_preparations) || 0,
          totals.preparations
        );
        teachingLoad = totals.load;
        totalWorkload = teachingLoad + designationUnits;
        normalLoadUnits = getNormalLoadUnits(totalPreparations);
        loadStatus = getLoadStatus(totalWorkload, totalPreparations);
        remarkMeta = getConsolidatedRemarkMeta(loadStatus);
        overloadExcess = totalWorkload - normalLoadUnits;

        if (String(option && option.status || "").toLowerCase() === "inactive" && entries.length === 0 && teachingLoad <= 0.0001) {
          return null;
        }

        if (entries.length === 0 && totalWorkload <= 0.0001) {
          return null;
        }

        return {
          assignee_type: String(option && option.assignee_type || "faculty").trim() === "faculty_need" ? "faculty_need" : "faculty",
          faculty_id: Number(option && option.faculty_id) || 0,
          faculty_need_id: Number(option && option.faculty_need_id) || 0,
          full_name: String(option && option.full_name || "").trim(),
          display_name: uppercaseDisplayText(option && option.full_name || ""),
          total_preparations: totalPreparations,
          designation_text: formatDesignationDisplay(meta, option),
          designation_units: designationUnits,
          teaching_load: teachingLoad,
          total_workload: totalWorkload,
          normal_load_units: normalLoadUnits,
          remark_code: remarkMeta.code,
          remark_class: remarkMeta.className,
          remark_total: overloadExcess > 0.0001 ? overloadExcess : 0,
          entries: entries.length > 0 ? entries : [{
            sub_code: "",
            desc: "",
            course: "",
            student_count: 0,
            units: 0,
            lec: 0,
            lab: 0,
            faculty_load: 0,
            schedule_lines: ["-"],
            type_labels: []
          }]
        };
      }

      function renderConsolidatedReportTableHead(termContext) {
        var campusLines = getConsolidatedCampusHeaderLines(termContext).map(function (line) {
          return '<span class="consolidated-campus-term-line">' + escapeHtml(line) + "</span>";
        }).join("");

        return (
          "<thead>" +
            "<tr>" +
              '<th rowspan="3" colspan="2" class="consolidated-campus-term">' + campusLines + "</th>" +
              '<th rowspan="3">No. of<br>Preparations</th>' +
              '<th colspan="9">Actual Teaching Loads</th>' +
              '<th rowspan="3">Designation &amp; Load<br>Displacement</th>' +
              '<th rowspan="3">Total<br>Workload</th>' +
              '<th rowspan="3">Remarks<br>(OL/UL)</th>' +
            "</tr>" +
            "<tr>" +
              '<th rowspan="2">Course<br>Code</th>' +
              '<th rowspan="2">Course Title</th>' +
              '<th rowspan="2">Day &amp; Time</th>' +
              '<th rowspan="2">Course,<br>Year &amp; Section</th>' +
              '<th rowspan="2">No. of<br>Students</th>' +
              '<th rowspan="2">No. of<br>Units</th>' +
              '<th colspan="2">No of Hours</th>' +
              '<th rowspan="2">Teaching<br>Load</th>' +
            "</tr>" +
            "<tr>" +
              '<th class="is-lower">Lec</th>' +
              '<th class="is-lower">Lab</th>' +
            "</tr>" +
          "</thead>"
        );
      }

      function renderConsolidatedReportColGroup() {
        var columnWidths = [
          3.1, 11.4, 4.4, 6.8, 15.6,
          10.9, 9.1, 4.8, 4.4, 4,
          4, 4.8, 8.3, 4.3, 4.1
        ];

        return "<colgroup>" + columnWidths.map(function (width) {
          return '<col style="width:' + width + '%">';
        }).join("") + "</colgroup>";
      }

      function renderConsolidatedFacultyBlock(record, options) {
        var opts = options || {};
        var rows = Array.isArray(opts.entries)
          ? opts.entries
          : (Array.isArray(record && record.entries) ? record.entries : []);
        var showTotalRow = opts.showTotalRow !== false;
        var contentRowspan = Math.max(rows.length, 1);
        var blockRowspan = contentRowspan + (showTotalRow ? 1 : 0);
        var designationHtml = record.designation_text
          ? '<div class="consolidated-designation-title">' + escapeHtml(record.designation_text) + "</div>"
          : (record.designation_units > 0 ? '<div class="consolidated-designation-title">Load Displacement</div>' : "&nbsp;");
        var remarkTotalText = record.remark_code === "OL" ? formatConsolidatedRemarkVariance(record.remark_total) : "";
        var blockClasses = ["consolidated-faculty-block"];

        if (opts.continuation) {
          blockClasses.push("is-continuation");
        }

        return (
          '<tbody class="' + blockClasses.join(" ") + '">' +
            rows.map(function (entry, index) {
              var scheduleHtml = (Array.isArray(entry && entry.schedule_lines) ? entry.schedule_lines : ["-"]).map(function (line) {
                return '<span class="consolidated-schedule-line">' + escapeHtml(line) + "</span>";
              }).join("");

              return (
                "<tr>" +
                  (index === 0
                    ? '<td rowspan="' + blockRowspan + '" class="consolidated-cell-index">' + escapeHtml(String(record.sequence || "")) + "</td>" +
                      '<td rowspan="' + blockRowspan + '" class="consolidated-cell-name">' + escapeHtml(record.display_name || record.full_name || "") + "</td>" +
                      '<td rowspan="' + blockRowspan + '" class="consolidated-cell-prep">' + escapeHtml(String(record.total_preparations || 0)) + "</td>"
                    : "") +
                  '<td class="consolidated-cell-code">' + escapeHtml(entry && entry.sub_code || "") + "</td>" +
                  '<td class="consolidated-cell-title"><span class="consolidated-course-title">' + escapeHtml(entry && entry.desc || "") + "</span></td>" +
                  '<td class="consolidated-cell-schedule">' + scheduleHtml + "</td>" +
                  '<td class="consolidated-cell-course">' + escapeHtml(entry && entry.course || "") + "</td>" +
                  '<td class="consolidated-cell-students">' + escapeHtml(formatStudentCount(entry && entry.student_count || 0)) + "</td>" +
                  '<td class="consolidated-cell-units">' + escapeHtml(formatNumber(entry && entry.units || 0)) + "</td>" +
                  '<td class="consolidated-cell-hours">' + escapeHtml(formatNumber(entry && entry.lec || 0)) + "</td>" +
                  '<td class="consolidated-cell-hours">' + escapeHtml(formatNumber(entry && entry.lab || 0)) + "</td>" +
                  '<td class="consolidated-cell-load">' + escapeHtml(formatNumber(entry && entry.faculty_load || 0)) + "</td>" +
                  (index === 0
                    ? '<td rowspan="' + contentRowspan + '" class="consolidated-cell-designation">' + designationHtml + "</td>" +
                      '<td rowspan="' + contentRowspan + '" class="consolidated-cell-total">' + escapeHtml(formatNumber(record.total_workload || 0)) + "</td>" +
                      '<td rowspan="' + contentRowspan + '" class="consolidated-cell-remark"><span class="consolidated-remark-text">' + escapeHtml(record.remark_code || "NL") + "</span></td>"
                    : "") +
                "</tr>"
              );
            }).join("") +
            (showTotalRow
              ? '<tr class="consolidated-faculty-total-row">' +
                  '<td class="consolidated-cell-code">&nbsp;</td>' +
                  '<td class="consolidated-cell-title">&nbsp;</td>' +
                  '<td class="consolidated-cell-schedule">&nbsp;</td>' +
                  '<td class="consolidated-cell-course">&nbsp;</td>' +
                  '<td class="consolidated-cell-students">&nbsp;</td>' +
                  '<td class="consolidated-cell-units">&nbsp;</td>' +
                  '<td class="consolidated-cell-hours">&nbsp;</td>' +
                  '<td class="consolidated-cell-hours">&nbsp;</td>' +
                  '<td class="consolidated-cell-load">' + escapeHtml(formatNumber(record.teaching_load || 0)) + "</td>" +
                  '<td class="consolidated-cell-designation">' + (record.designation_units > 0 ? escapeHtml(formatNumber(record.designation_units)) : "&nbsp;") + "</td>" +
                  '<td class="consolidated-cell-total">&nbsp;</td>' +
                  '<td class="consolidated-cell-remark-total">' + (remarkTotalText ? escapeHtml(remarkTotalText) : "&nbsp;") + "</td>" +
                "</tr>"
              : "") +
          "</tbody>"
        );
      }

      function renderConsolidatedSignatoryBlock(label, signatory) {
        var name = uppercaseDisplayText(signatory && signatory.signatory_name || "");
        var title = String(signatory && signatory.signatory_title || "").trim();

        return (
          '<div class="consolidated-signatory-block">' +
            '<div class="consolidated-signatory-label">' + escapeHtml(label) + "</div>" +
            '<div class="consolidated-signatory-name">' + (name ? escapeHtml(name) : "&nbsp;") + "</div>" +
            '<div class="consolidated-signatory-title">' + (title ? escapeHtml(title) : "&nbsp;") + "</div>" +
          "</div>"
        );
      }

      function createConsolidatedReportElement(templateHtml) {
        var template = document.createElement("template");
        template.innerHTML = String(templateHtml || "").trim();
        return template.content.firstElementChild;
      }

      function createConsolidatedReportPage(termContext, options) {
        var opts = options || {};
        var page = document.createElement("section");
        var scope = consolidatedReportScope || {};
        var campusLabel = uppercaseDisplayText(scope.campus_name || "Campus");
        var collegeLabel = uppercaseDisplayText(scope.college_name || "All Colleges");
        var titleLabel = opts.signatoryPage ? "CONSOLIDATED FACULTY WORKLOAD SIGNATORIES" : "CONSOLIDATED FACULTY WORKLOAD";
        var bodyHtml;

        page.className = ("consolidated-report-page " + (opts.measure ? "is-measure " : "") + (opts.signatoryPage ? "consolidated-signatory-page" : "")).trim();
        page.style.setProperty("--consolidated-report-bg", 'url("' + consolidatedReportBackgroundUrl + '")');

        bodyHtml = opts.signatoryPage
          ? '<div class="consolidated-signatory-wrap">' +
              '<div class="consolidated-signatory-top">' +
                renderConsolidatedSignatoryBlock("Prepared by:", consolidatedReportSignatories && consolidatedReportSignatories.prepared_by || {}) +
                renderConsolidatedSignatoryBlock("Checked by:", consolidatedReportSignatories && consolidatedReportSignatories.checked_by_left || {}) +
                renderConsolidatedSignatoryBlock("Checked by:", consolidatedReportSignatories && consolidatedReportSignatories.checked_by_right || {}) +
              "</div>" +
              '<div class="consolidated-signatory-bottom">' +
                renderConsolidatedSignatoryBlock("Recommending Approval:", consolidatedReportSignatories && consolidatedReportSignatories.recommending_approval || {}) +
                renderConsolidatedSignatoryBlock("Approved by:", consolidatedReportSignatories && consolidatedReportSignatories.approved_by || {}) +
              "</div>" +
            "</div>"
          : '<div class="consolidated-report-table-wrap">' +
              '<table class="consolidated-report-table">' +
                renderConsolidatedReportColGroup() +
                renderConsolidatedReportTableHead(termContext) +
              "</table>" +
            "</div>";

        page.innerHTML =
          '<div class="consolidated-report-page-background"></div>' +
          '<div class="consolidated-report-page-inner">' +
            '<div class="consolidated-report-page-header">' +
              '<div class="consolidated-report-title">' + escapeHtml(titleLabel) + "</div>" +
              '<div class="consolidated-report-subtitle">' + escapeHtml(campusLabel) + "</div>" +
              '<div class="consolidated-report-subtitle">' + escapeHtml(collegeLabel) + "</div>" +
              '<div class="consolidated-report-term">' + escapeHtml(getConsolidatedReportTermLabel(termContext)) + "</div>" +
              '<div class="consolidated-report-page-count">Page <span class="consolidated-page-number">1</span> of <span class="consolidated-page-total">1</span></div>' +
            "</div>" +
            bodyHtml +
          "</div>";

        return {
          page: page,
          inner: page.querySelector(".consolidated-report-page-inner"),
          table: page.querySelector(".consolidated-report-table")
        };
      }

      function updateConsolidatedReportPageMeta(pageElements) {
        var pages = Array.isArray(pageElements) ? pageElements : [];
        var total = pages.length;
        pages.forEach(function (page, index) {
          $(page).find(".consolidated-page-number").text(index + 1);
          $(page).find(".consolidated-page-total").text(total);
        });
      }

      function createConsolidatedMeasurePageBundle(measureRoot, generatedPages, termContext) {
        var pageBundle = createConsolidatedReportPage(termContext, { measure: true });
        measureRoot.appendChild(pageBundle.page);
        generatedPages.push(pageBundle.page);
        return pageBundle;
      }

      function consolidatedReportPageOverflows(pageBundle) {
        if (!pageBundle || !pageBundle.inner) {
          return false;
        }

        return pageBundle.inner.scrollHeight > pageBundle.inner.clientHeight + 1;
      }

      function pageHasConsolidatedReportRows(pageBundle) {
        return Boolean(pageBundle && pageBundle.table && pageBundle.table.querySelector("tbody"));
      }

      function createConsolidatedFacultyChunkElement(record, entries, options) {
        return createConsolidatedReportElement(renderConsolidatedFacultyBlock(record, {
          entries: entries,
          continuation: Boolean(options && options.continuation),
          showTotalRow: !(options && options.showTotalRow === false)
        }));
      }

      function consolidatedFacultyChunkFits(pageBundle, record, entries, options) {
        var blockElement = createConsolidatedFacultyChunkElement(record, entries, options);
        var fits;
        if (!(blockElement instanceof HTMLElement) || !pageBundle || !pageBundle.table) {
          return false;
        }

        pageBundle.table.appendChild(blockElement);
        fits = !consolidatedReportPageOverflows(pageBundle);
        pageBundle.table.removeChild(blockElement);
        return fits;
      }

      function getConsolidatedFittableEntryCount(pageBundle, record, entries, startIndex) {
        var safeEntries = Array.isArray(entries) ? entries : [];
        var remainingCount = safeEntries.length - startIndex;
        var bestCount = 0;
        var count;

        for (count = 1; count <= remainingCount; count += 1) {
          var isFinalChunk = (startIndex + count) >= safeEntries.length;
          var chunkEntries = safeEntries.slice(startIndex, startIndex + count);
          var fits = consolidatedFacultyChunkFits(pageBundle, record, chunkEntries, {
            continuation: startIndex > 0,
            showTotalRow: isFinalChunk
          });

          if (!fits) {
            break;
          }

          bestCount = count;
        }

        return bestCount;
      }

      function appendConsolidatedFacultyChunk(pageBundle, record, entries, options) {
        var blockElement = createConsolidatedFacultyChunkElement(record, entries, options);
        if (!(blockElement instanceof HTMLElement) || !pageBundle || !pageBundle.table) {
          return false;
        }

        pageBundle.table.appendChild(blockElement);
        return true;
      }

      function buildConsolidatedPreviewHtml(records, termContext) {
        var measureRoot = document.getElementById("consolidatedReportMeasureRoot");
        var generatedPages = [];
        var pageBundle;
        var signatoryBundle;
        var printablePages;

        if (!measureRoot) {
          return "";
        }

        measureRoot.innerHTML = "";
        pageBundle = createConsolidatedMeasurePageBundle(measureRoot, generatedPages, termContext);

        records.forEach(function (record) {
          var entries = Array.isArray(record && record.entries) && record.entries.length > 0 ? record.entries : [];
          var startIndex = 0;

          while (startIndex < entries.length) {
            var fitCount = getConsolidatedFittableEntryCount(pageBundle, record, entries, startIndex);
            var chunkEntries;
            var isFinalChunk;

            if (fitCount <= 0 && pageHasConsolidatedReportRows(pageBundle)) {
              pageBundle = createConsolidatedMeasurePageBundle(measureRoot, generatedPages, termContext);
              fitCount = getConsolidatedFittableEntryCount(pageBundle, record, entries, startIndex);
            }

            if (fitCount <= 0) {
              fitCount = 1;
            }

            chunkEntries = entries.slice(startIndex, startIndex + fitCount);
            isFinalChunk = (startIndex + fitCount) >= entries.length;

            if (!appendConsolidatedFacultyChunk(pageBundle, record, chunkEntries, {
              continuation: startIndex > 0,
              showTotalRow: isFinalChunk
            })) {
              break;
            }

            startIndex += fitCount;
          }
        });

        signatoryBundle = createConsolidatedReportPage(termContext, { measure: true, signatoryPage: true });
        measureRoot.appendChild(signatoryBundle.page);
        generatedPages.push(signatoryBundle.page);

        printablePages = generatedPages.map(function (page) {
          return page.cloneNode(true);
        });
        updateConsolidatedReportPageMeta(printablePages);
        measureRoot.innerHTML = "";

        return printablePages.map(function (page) {
          return page.outerHTML;
        }).join("");
      }

      async function openConsolidatedReportPreview() {
        var termContext = getConsolidatedTermContext();
        var assignees = (Array.isArray(consolidatedReportAssignees) ? consolidatedReportAssignees : []).slice();
        var records = [];
        var index;

        if (consolidatedReportIsBuilding) {
          return;
        }

        if (!termContext.ayId || !termContext.semesterNum) {
          if (consolidatedReportModal) {
            consolidatedReportModal.show();
          }
          setConsolidatedReportEmptyState("Missing academic term", "Configure the current academic year and semester before generating the consolidated workload.");
          return;
        }

        if (consolidatedReportModal) {
          consolidatedReportModal.show();
        }

        if (!assignees.length) {
          setConsolidatedReportEmptyState("No faculty available", "No faculty or faculty need is available in the current dashboard scope.");
          return;
        }

        consolidatedReportIsBuilding = true;
        showConsolidatedReportLoading("Loading faculty workload...", 5);
        $("#btnRefreshConsolidatedReport").prop("disabled", true);

        try {
          assignees.sort(function (left, right) {
            return String(left && left.full_name || "").localeCompare(String(right && right.full_name || ""));
          });

          for (index = 0; index < assignees.length; index += 1) {
            var option = assignees[index];
            var percent = Math.round(((index + 1) / assignees.length) * 86);
            var payload;
            var record;

            setConsolidatedReportProgress(percent, "Loading " + String(option && option.full_name || "assignee") + " (" + (index + 1) + " of " + assignees.length + ")...");

            try {
              payload = await fetchConsolidatedAssigneeWorkloadPayload(option);
              record = buildConsolidatedFacultyRecord(option, payload);
              if (record) {
                records.push(record);
              }
            } catch (facultyError) {
              console.error("Failed to load consolidated workload", option, facultyError);
            }
          }

          records.sort(function (left, right) {
            return String(left && left.full_name || "").localeCompare(String(right && right.full_name || ""));
          });
          records.forEach(function (record, recordIndex) {
            record.sequence = recordIndex + 1;
          });

          if (!records.length) {
            setConsolidatedReportEmptyState("No report rows available", "There is no assigned scheduler workload yet for the current dashboard scope and term.");
            return;
          }

          setConsolidatedReportProgress(95, "Building legal landscape preview...");
          consolidatedReportRecords = records;
          consolidatedReportGeneratedHtml = buildConsolidatedPreviewHtml(records, termContext);

          if (!consolidatedReportGeneratedHtml) {
            consolidatedReportRecords = [];
            setConsolidatedReportEmptyState("Preview unavailable", "The consolidated faculty workload preview could not be generated right now.");
            return;
          }

          $("#consolidatedReportPreviewRoot").html(consolidatedReportGeneratedHtml);
          setConsolidatedReportProgress(100, "Preview ready");
          setConsolidatedReportPrintEnabled(true);
        } catch (reportError) {
          console.error("Failed to build consolidated workload preview", reportError);
          setConsolidatedReportEmptyState("Preview unavailable", "The consolidated faculty workload preview could not be generated right now.");
        } finally {
          consolidatedReportIsBuilding = false;
          $("#btnRefreshConsolidatedReport").prop("disabled", false);
        }
      }

      function consolidatedExcelFilename() {
        var scope = consolidatedReportScope || {};
        var parts = [
          "consolidated-faculty-workload",
          scope.campus_name || "campus",
          scope.college_name || "all-colleges",
          currentAyLabel || "current-ay",
          semesterUiLabel(currentSem) || "semester"
        ];

        return parts.join("-")
          .replace(/[^A-Za-z0-9]+/g, "-")
          .replace(/^-+|-+$/g, "")
          .toLowerCase() + ".xls";
      }

      function renderConsolidatedExcelSignatoryRows() {
        var signatories = consolidatedReportSignatories || {};
        var rows = [
          ["Prepared by:", signatories.prepared_by || {}],
          ["Checked by:", signatories.checked_by_left || {}],
          ["Checked by:", signatories.checked_by_right || {}],
          ["Recommending Approval:", signatories.recommending_approval || {}],
          ["Approved by:", signatories.approved_by || {}]
        ];

        return (
          '<table class="consolidated-excel-signatories">' +
            '<tr><th colspan="3">Signatories</th></tr>' +
            rows.map(function (row) {
              var label = row[0];
              var signatory = row[1] || {};
              return (
                "<tr>" +
                  '<td class="signatory-label">' + escapeHtml(label) + "</td>" +
                  '<td class="signatory-name">' + escapeHtml(uppercaseDisplayText(signatory.signatory_name || "")) + "</td>" +
                  '<td>' + escapeHtml(signatory.signatory_title || "") + "</td>" +
                "</tr>"
              );
            }).join("") +
          "</table>"
        );
      }

      function buildConsolidatedExcelWorkbookHtml(records, termContext) {
        var scope = consolidatedReportScope || {};
        var reportRows = (Array.isArray(records) ? records : []).map(function (record) {
          return renderConsolidatedFacultyBlock(record, {
            entries: Array.isArray(record && record.entries) ? record.entries : [],
            showTotalRow: true
          });
        }).join("");

        return (
          '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">' +
          "<head>" +
            '<meta charset="utf-8">' +
            "<style>" +
              "table{border-collapse:collapse;font-family:Arial,sans-serif;font-size:10pt;}" +
              ".consolidated-report-table th,.consolidated-report-table td{border:1px solid #000;padding:4px;vertical-align:top;mso-number-format:\\@;}" +
              ".consolidated-report-table th{text-align:center;font-weight:bold;background:#f2f2f2;}" +
              ".consolidated-cell-index,.consolidated-cell-name,.consolidated-cell-prep,.consolidated-cell-students,.consolidated-cell-units,.consolidated-cell-hours,.consolidated-cell-load,.consolidated-cell-designation,.consolidated-cell-total,.consolidated-cell-remark,.consolidated-cell-remark-total{text-align:center;}" +
              ".consolidated-cell-name,.consolidated-cell-code,.consolidated-cell-load,.consolidated-cell-total,.consolidated-cell-remark,.consolidated-course-title,.consolidated-designation-title{font-weight:bold;}" +
              ".consolidated-report-title-row th{font-size:13pt;text-align:center;border:0;background:#fff;}" +
              ".consolidated-report-subtitle-row th{font-size:11pt;text-align:center;border:0;background:#fff;}" +
              ".consolidated-faculty-total-row td{font-weight:bold;background:#f8f8f8;}" +
              ".consolidated-schedule-line{display:block;}" +
              ".consolidated-excel-signatories{margin-top:18px;}" +
              ".consolidated-excel-signatories th,.consolidated-excel-signatories td{border:1px solid #000;padding:6px;}" +
              ".signatory-label{font-weight:bold;}" +
              ".signatory-name{font-weight:bold;text-transform:uppercase;}" +
            "</style>" +
          "</head>" +
          "<body>" +
            '<table class="consolidated-report-table">' +
              '<tr class="consolidated-report-title-row"><th colspan="15">CONSOLIDATED FACULTY WORKLOAD</th></tr>' +
              '<tr class="consolidated-report-subtitle-row"><th colspan="15">' + escapeHtml(uppercaseDisplayText(scope.campus_name || "Campus")) + "</th></tr>" +
              '<tr class="consolidated-report-subtitle-row"><th colspan="15">' + escapeHtml(uppercaseDisplayText(scope.college_name || "All Colleges")) + "</th></tr>" +
              '<tr class="consolidated-report-subtitle-row"><th colspan="15">' + escapeHtml(getConsolidatedReportTermLabel(termContext)) + "</th></tr>" +
              renderConsolidatedReportTableHead(termContext) +
              reportRows +
            "</table>" +
            renderConsolidatedExcelSignatoryRows() +
          "</body>" +
          "</html>"
        );
      }

      function exportConsolidatedReportExcel() {
        var records = Array.isArray(consolidatedReportRecords) ? consolidatedReportRecords : [];
        var termContext = getConsolidatedTermContext();
        var workbookHtml;
        var blob;
        var url;
        var link;

        if (!records.length) {
          return;
        }

        workbookHtml = buildConsolidatedExcelWorkbookHtml(records, termContext);
        blob = new Blob(["\ufeff", workbookHtml], {
          type: "application/vnd.ms-excel;charset=utf-8;"
        });
        url = URL.createObjectURL(blob);
        link = document.createElement("a");
        link.href = url;
        link.download = consolidatedExcelFilename();
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(function () {
          URL.revokeObjectURL(url);
        }, 1000);
      }

      function printConsolidatedReportPreview() {
        var reportStyleTag;
        var frameId;
        var existingFrame;
        var printFrame;
        var printDocument;
        var printTriggered = false;

        if (!consolidatedReportGeneratedHtml) {
          return;
        }

        reportStyleTag = document.getElementById("consolidatedReportStyleTag");
        frameId = "consolidatedReportPrintFrame";
        existingFrame = document.getElementById(frameId);
        if (existingFrame) {
          existingFrame.remove();
        }

        printFrame = document.createElement("iframe");
        printFrame.id = frameId;
        printFrame.style.position = "fixed";
        printFrame.style.right = "0";
        printFrame.style.bottom = "0";
        printFrame.style.width = "0";
        printFrame.style.height = "0";
        printFrame.style.border = "0";
        printFrame.setAttribute("aria-hidden", "true");
        document.body.appendChild(printFrame);

        printDocument = printFrame.contentWindow && printFrame.contentWindow.document;
        if (!printDocument || !printFrame.contentWindow) {
          printFrame.remove();
          return;
        }

        printDocument.open();
        printDocument.write(
          '<!DOCTYPE html>' +
          '<html lang="en">' +
          "<head>" +
            '<meta charset="utf-8">' +
            '<meta name="viewport" content="width=device-width, initial-scale=1.0">' +
            "<title>Consolidated Faculty Workload</title>" +
            "<style>@media print {@page { size: legal landscape; margin: 0; }}</style>" +
            (reportStyleTag ? reportStyleTag.outerHTML : "") +
          "</head>" +
          '<body class="consolidated-report-print-body">' +
            '<div class="consolidated-report-preview-root">' + consolidatedReportGeneratedHtml + "</div>" +
          "</body>" +
          "</html>"
        );
        printDocument.close();

        function triggerPrint() {
          if (printTriggered) {
            return;
          }

          printTriggered = true;
          setTimeout(function () {
            try {
              printFrame.contentWindow.focus();
              printFrame.contentWindow.print();
            } finally {
              setTimeout(function () {
                printFrame.remove();
              }, 1500);
            }
          }, 350);
        }

        printFrame.addEventListener("load", triggerPrint, { once: true });
        setTimeout(triggerPrint, 700);
      }

      function loadFacultyDirectoryWorkload(item) {
        var workloadPanel = document.getElementById("facultyDirectoryWorkloadPanel");
        var requestKey;

        if (!workloadPanel || !item) {
          return;
        }

        requestKey = [
          Number(item.faculty_id) || 0,
          Number(selectedCollegeId) || 0,
          Number(dashboardCampusId) || 0,
          Number(currentAyId) || 0,
          Number(currentSem) || 0
        ].join(":");

        if (facultyDirectoryWorkloadCache.has(requestKey)) {
          workloadPanel.innerHTML = buildFacultyWorkloadPanelMarkup(facultyDirectoryWorkloadCache.get(requestKey), item);
          return;
        }

        workloadPanel.innerHTML = buildFacultyWorkloadLoader("Loading current scheduler workload...");

        if (facultyDirectoryWorkloadRequest && facultyDirectoryWorkloadRequest.readyState !== 4) {
          facultyDirectoryWorkloadRequest.abort();
        }

        facultyDirectoryWorkloadRequest = $.ajax({
          url: "../backend/query_admin_faculty_workload.php",
          type: "POST",
          dataType: "json",
          data: {
            faculty_id: Number(item.faculty_id) || 0,
            college_id: Number(selectedCollegeId) || 0,
            campus_id: Number(dashboardCampusId) || 0
          }
        }).done(function (response) {
          facultyDirectoryWorkloadRequest = null;

          if (facultyDirectoryActiveFacultyId !== Number(item.faculty_id) || !document.getElementById("facultyDirectoryWorkloadPanel")) {
            return;
          }

          if (!response || response.status !== "ok") {
            workloadPanel.innerHTML = facultyDirectoryEmptyMarkup(
              response && response.message
                ? String(response.message)
                : "Unable to load scheduler workload for this faculty."
            );
            return;
          }

          facultyDirectoryWorkloadCache.set(requestKey, response);
          if (facultyDirectoryDetailSubtitle) {
            facultyDirectoryDetailSubtitle.textContent =
              facultyDirectoryScopeLabel + " | " + String((response.meta || {}).term_text || academicTermText || "Current academic term");
          }
          workloadPanel.innerHTML = buildFacultyWorkloadPanelMarkup(response, item);
        }).fail(function (xhr, statusText) {
          facultyDirectoryWorkloadRequest = null;
          if (statusText === "abort") {
            return;
          }

          if (facultyDirectoryActiveFacultyId !== Number(item.faculty_id) || !document.getElementById("facultyDirectoryWorkloadPanel")) {
            return;
          }

          workloadPanel.innerHTML = facultyDirectoryEmptyMarkup("Unable to load scheduler workload for this faculty.");
        });
      }

      function openFacultyDirectoryDetail(facultyId) {
        var item = (Array.isArray(facultyDirectoryData) ? facultyDirectoryData : []).find(function (row) {
          return Number(row && row.faculty_id) === Number(facultyId);
        });

        if (!item || !facultyDirectoryDetailBody || !facultyDirectoryDetailTitle || !facultyDirectoryDetailSubtitle) {
          return;
        }

        facultyDirectoryActiveFacultyId = Number(item.faculty_id) || 0;

        facultyDirectoryDetailTitle.textContent = item.full_name || "Faculty Details";
        facultyDirectoryDetailSubtitle.textContent =
          facultyDirectoryScopeLabel + " | " + (academicTermText || "Current academic term");

        facultyDirectoryDetailBody.innerHTML =
          '<div id="facultyDirectoryWorkloadPanel">' + buildFacultyWorkloadLoader("Loading current scheduler workload...") + '</div>';

        if (facultyDirectoryDetailModal) {
          facultyDirectoryDetailModal.show();
        }

        loadFacultyDirectoryWorkload(item);
      }

      function scheduleHeatmapAllRoomsLabel() {
        if (dashboardViewType === "university") {
          return "All active rooms";
        }

        return dashboardViewType === "campus" ? "All campus rooms" : "All accessible rooms";
      }

      function scheduleHeatmapAggregateSubtitle() {
        if (dashboardViewType === "university") {
          return "Each cell shows the percentage of active university rooms occupied during each 30-minute weekday block from 7:00 AM to 6:00 PM across all campuses.";
        }

        if (dashboardViewType === "campus") {
          return "Each cell shows the percentage of active campus rooms occupied during each 30-minute weekday block from 7:00 AM to 6:00 PM.";
        }

        return "Each cell shows the percentage of active rooms accessible to the selected college that are occupied during each 30-minute weekday block from 7:00 AM to 6:00 PM.";
      }

      function scheduleHeatmapTitleText() {
        if (dashboardViewType === "university") {
          return "University Room Occupancy Heatmap";
        }

        return dashboardViewType === "campus"
          ? "Campus Room Occupancy Heatmap"
          : "Accessible Room Occupancy Heatmap";
      }

      function scheduleHeatmapBadgeText(isSingleRoomView) {
        if (isSingleRoomView) {
          return "Room View";
        }

        if (dashboardViewType === "university") {
          return "University Heat";
        }

        return dashboardViewType === "campus" ? "Campus Heat" : "Room Heat";
      }

      function scheduleHeatmapPoolLabel() {
        if (dashboardViewType === "university") {
          return "university room pool";
        }

        return dashboardViewType === "campus" ? "campus room pool" : "accessible room pool";
      }

      function syncScheduleHeatmapFilter(payload) {
        var roomOptions = payload && Array.isArray(payload.room_options) ? payload.room_options : [];
        var selectedRoomId = Number(payload && payload.selected_room_id) || 0;
        var selectedRoomLabel = payload && payload.selected_room_label ? payload.selected_room_label : "the selected room";

        if (scheduleHeatmapTitle) {
          scheduleHeatmapTitle.textContent = scheduleHeatmapTitleText();
        }

        if (scheduleHeatmapRoomFilter) {
          var optionMarkup = roomOptions.map(function (room) {
            var roomId = Number(room && room.room_id) || 0;
            var roomLabel = room && room.label ? room.label : ("Room " + roomId);
            return '<option value="' + roomId + '">' + escapeHtml(roomLabel) + "</option>";
          }).join("");
          var nextValue = roomOptions.some(function (room) {
            return String(Number(room && room.room_id) || 0) === String(selectedRoomId);
          }) ? String(selectedRoomId) : "all";

          syncingScheduleHeatmapRoomFilter = true;
          scheduleHeatmapRoomFilter.innerHTML =
            '<option value="all">' + escapeHtml(scheduleHeatmapAllRoomsLabel()) + "</option>" + optionMarkup;
          scheduleHeatmapRoomFilter.value = nextValue;
          scheduleHeatmapRoomFilter.disabled = roomOptions.length === 0;
          syncingScheduleHeatmapRoomFilter = false;
          scheduleHeatmapRoomSelection = nextValue;
        }

        if (scheduleHeatmapFilterSummary) {
          if (roomOptions.length === 0) {
            scheduleHeatmapFilterSummary.textContent = "No active rooms are available for this heatmap.";
          } else if (selectedRoomId > 0) {
            scheduleHeatmapFilterSummary.textContent = "Viewing classroom heatmap for " + selectedRoomLabel + ".";
          } else {
            scheduleHeatmapFilterSummary.textContent = "Filter by room to inspect one classroom schedule.";
          }
        }

        if (scheduleHeatmapSubtitle) {
          scheduleHeatmapSubtitle.textContent = selectedRoomId > 0
            ? "Each cell shows whether " + selectedRoomLabel + " is occupied during each 30-minute weekday block from 7:00 AM to 6:00 PM."
            : scheduleHeatmapAggregateSubtitle();
        }
      }

      function setScheduleHeatmapFilterLoadingState() {
        if (scheduleHeatmapRoomFilter) {
          scheduleHeatmapRoomFilter.disabled = true;
        }

        if (scheduleHeatmapFilterSummary) {
          scheduleHeatmapFilterSummary.textContent = "Loading room options for the heatmap.";
        }

        if (scheduleHeatmapTitle) {
          scheduleHeatmapTitle.textContent = scheduleHeatmapTitleText();
        }

        if (scheduleHeatmapSubtitle) {
          scheduleHeatmapSubtitle.textContent = scheduleHeatmapAggregateSubtitle();
        }

        if (scheduleHeatmapBadge) {
          scheduleHeatmapBadge.textContent = scheduleHeatmapBadgeText(false);
        }
      }

      function scheduleHeatmapInsightLoadingText() {
        if (scheduleHeatmapRoomSelection !== "all") {
          return "Loading the classroom heat map for the selected room.";
        }

        if (dashboardViewType === "university") {
          return "Loading which 30-minute weekday blocks from 7:00 AM to 6:00 PM have the highest room occupancy across the university.";
        }

        return dashboardViewType === "campus"
          ? "Loading which 30-minute weekday blocks from 7:00 AM to 6:00 PM have the highest room occupancy across the current campus."
          : "Loading which 30-minute weekday blocks from 7:00 AM to 6:00 PM are busiest across rooms accessible to the selected college.";
      }

      function buildScheduleHeatmapRequestData() {
        return {
          campus_id: campusIdParam,
          college_id: selectedCollegeId,
          room_id: scheduleHeatmapRoomSelection === "all" ? "" : scheduleHeatmapRoomSelection,
          csrf_token: csrfToken
        };
      }

      function renderScheduleHeatmap(heatmap) {
        var payload = heatmap && typeof heatmap === "object" ? heatmap : {};
        var series = Array.isArray(payload.series) ? payload.series : [];
        var totalRooms = Number(payload.total_rooms) || 0;
        var peakPercent = Number(payload.peak_percent) || 0;
        var peakOccupiedRooms = Number(payload.peak_occupied_rooms) || 0;
        var peakLabel = payload.peak_label || "No occupied room slots";
        var occupiedSlotCount = Number(payload.occupied_slot_count) || 0;
        var busiestDayLabel = payload.busiest_day_label || "";
        var busiestDaySlots = Number(payload.busiest_day_slots) || 0;
        var selectedRoomLabel = payload.selected_room_label || "Selected room";
        var isSingleRoomView = payload.view_mode === "room";
        var poolLabel = scheduleHeatmapPoolLabel();
        var chartContainer = document.querySelector("#scheduleHeatmapChart");

        if (!chartContainer) {
          return;
        }

        syncScheduleHeatmapFilter(payload);

        if (scheduleHeatmapBadge) {
          scheduleHeatmapBadge.textContent = scheduleHeatmapBadgeText(isSingleRoomView);
        }

        if (!series.length) {
          setChartFallback("scheduleHeatmapChart", "No room occupancy heat map is available.");
          setInsight("scheduleHeatmapInsight", "Room occupancy could not be measured for the current scheduling scope.");
          return;
        }

        if (!hasApexCharts) {
          setChartFallback("scheduleHeatmapChart", "ApexCharts failed to load.");
          setInsight("scheduleHeatmapInsight", "Room occupancy data is available but the chart library could not be loaded.");
          return;
        }

        if (scheduleHeatmapChart) {
          scheduleHeatmapChart.destroy();
        }

        chartContainer.innerHTML = "";

        scheduleHeatmapChart = new ApexCharts(chartContainer, {
          series: series,
          chart: {
            type: "heatmap",
            height: 340,
            toolbar: { show: false }
          },
          dataLabels: {
            enabled: false
          },
          stroke: {
            width: 1,
            colors: ["#ffffff"]
          },
          plotOptions: {
            heatmap: {
              shadeIntensity: 0.6,
              enableShades: false,
              colorScale: {
                ranges: isSingleRoomView
                  ? [
                      { from: 0, to: 0, name: "Available", color: "#edf1f7" },
                      { from: 0.1, to: 100, name: "Occupied", color: "#1f6fd1" }
                    ]
                  : [
                      { from: 0, to: 0, name: "Idle", color: "#edf1f7" },
                      { from: 0.1, to: 25, name: "Light", color: "#8fc2ff" },
                      { from: 25.1, to: 50, name: "Moderate", color: "#4b97f2" },
                      { from: 50.1, to: 75, name: "Busy", color: "#1f6fd1" },
                      { from: 75.1, to: 100, name: "Peak", color: "#114aa3" }
                    ]
              }
            }
          },
          xaxis: {
            labels: {
              rotate: -55,
              trim: false,
              hideOverlappingLabels: false,
              style: {
                fontSize: "9px",
                colors: "#697a8d"
              }
            }
          },
          yaxis: {
            labels: {
              style: {
                colors: "#697a8d"
              }
            }
          },
          legend: {
            position: "top",
            horizontalAlign: "left"
          },
          grid: {
            borderColor: "#eceef4",
            strokeDashArray: 4
          },
          tooltip: {
            y: {
              formatter: function (value) {
                return isSingleRoomView
                  ? (Number(value) > 0 ? "Occupied" : "Available")
                  : Number(value).toFixed(1) + "% occupied";
              }
            }
          }
        });

        scheduleHeatmapChart.render();

        if (totalRooms <= 0) {
          setInsight(
            "scheduleHeatmapInsight",
            "No active rooms are available in the current " + poolLabel + ", so occupancy cannot be measured yet."
          );
          return;
        }

        if (isSingleRoomView) {
          if (occupiedSlotCount <= 0) {
            setInsight(
              "scheduleHeatmapInsight",
              selectedRoomLabel + " has no scheduled occupancy between 7:00 AM and 6:00 PM on weekdays for the current term."
            );
            return;
          }

          setInsight(
            "scheduleHeatmapInsight",
            selectedRoomLabel +
              " is occupied during " +
              occupiedSlotCount +
              " weekday 30-minute block" +
              (occupiedSlotCount === 1 ? "" : "s") +
              (busiestDaySlots > 0
                ? ". " + busiestDayLabel + " is the busiest day with " + busiestDaySlots + " occupied block" + (busiestDaySlots === 1 ? "" : "s") + "."
                : ".")
          );
          return;
        }

        if (peakPercent <= 0) {
          setInsight(
            "scheduleHeatmapInsight",
            "No occupied room slots are mapped between 7:00 AM and 6:00 PM across the " + poolLabel + " of " + totalRooms + " room" + (totalRooms === 1 ? "" : "s") + "."
          );
          return;
        }

        setInsight(
          "scheduleHeatmapInsight",
          "Peak room pressure hits " +
            peakLabel +
            " with " +
            peakOccupiedRooms +
            "/" +
            totalRooms +
            " rooms occupied (" +
            peakPercent.toFixed(1) +
            "%) in the current " +
            poolLabel +
            "."
        );
      }

      function loadScheduleHeatmap() {
        if (scheduleHeatmapRequest && scheduleHeatmapRequest.readyState !== 4) {
          scheduleHeatmapRequest.abort();
        }

        if (scheduleHeatmapChart) {
          scheduleHeatmapChart.destroy();
          scheduleHeatmapChart = null;
        }

        setScheduleHeatmapFilterLoadingState();
        setChartLoading("scheduleHeatmapChart", "Loading room occupancy heat map...");
        setInsight("scheduleHeatmapInsight", scheduleHeatmapInsightLoadingText());

        scheduleHeatmapRequest = $.ajax({
          url: "../backend/dashboard_admin_schedule_heatmap.php",
          type: "POST",
          dataType: "json",
          data: buildScheduleHeatmapRequestData(),
          success: function (data) {
            scheduleHeatmapRequest = null;

            if (!data || data.status !== "ok") {
              if (scheduleHeatmapRoomFilter) {
                scheduleHeatmapRoomFilter.disabled = scheduleHeatmapRoomFilter.options.length <= 1;
              }
              if (scheduleHeatmapFilterSummary) {
                scheduleHeatmapFilterSummary.textContent = "Unable to refresh room options for the heatmap.";
              }
              setChartFallback("scheduleHeatmapChart", (data && data.message) ? data.message : "Unable to load room occupancy heat map.");
              setInsight("scheduleHeatmapInsight", "Room occupancy data could not be loaded.");
              return;
            }

            renderScheduleHeatmap((data || {}).schedule_heatmap || {});
          },
          error: function (xhr, statusText) {
            if (statusText === "abort") {
              return;
            }

            scheduleHeatmapRequest = null;
            if (scheduleHeatmapRoomFilter) {
              scheduleHeatmapRoomFilter.disabled = scheduleHeatmapRoomFilter.options.length <= 1;
            }
            if (scheduleHeatmapFilterSummary) {
              scheduleHeatmapFilterSummary.textContent = "Unable to refresh room options for the heatmap.";
            }

            setChartFallback("scheduleHeatmapChart", "Unable to load room occupancy heat map.");
            setInsight("scheduleHeatmapInsight", "Room occupancy data could not be loaded.");
            console.error("Admin heatmap error:", xhr.responseText);
          }
        });
      }

      function renderRoomUtilizationChart() {
        if (!utilizationLabels.length) {
          setChartFallback("roomUtilizationChart", "No room utilization data is available.");
          setInsight("roomUtilizationInsight", "No current-term room utilization data is available for this view.");
          return;
        }

        if (!hasApexCharts) {
          setChartFallback("roomUtilizationChart", "ApexCharts failed to load.");
          setInsight("roomUtilizationInsight", "Room utilization data is available but the chart library could not be loaded.");
          return;
        }

        var utilizationElement = document.querySelector("#roomUtilizationChart");
        if (!utilizationElement) {
          return;
        }

        if (roomUtilizationChart) {
          roomUtilizationChart.destroy();
        }

        utilizationElement.innerHTML = "";

        roomUtilizationChart = new ApexCharts(utilizationElement, {
          series: [
            {
              name: "Scheduled Room-Hours",
              data: utilizationUsed
            },
            {
              name: "Remaining Baseline Capacity",
              data: utilizationAvailable
            }
          ],
          chart: {
            type: "bar",
            height: Math.max(300, utilizationLabels.length * 56),
            stacked: true,
            toolbar: { show: false }
          },
          colors: ["#ffab00", "#ebeef5"],
          plotOptions: {
            bar: {
              horizontal: true,
              borderRadius: 6,
              barHeight: "58%"
            }
          },
          dataLabels: {
            enabled: false
          },
          stroke: {
            width: 1,
            colors: ["#ffffff"]
          },
          xaxis: {
            categories: utilizationLabels,
            title: {
              text: "40-hour baseline room-hours",
              style: { color: "#777" }
            },
            labels: {
              style: { colors: "#777" }
            }
          },
          yaxis: {
            labels: {
              style: { colors: "#566a7f" }
            }
          },
          legend: {
            position: "top",
            horizontalAlign: "left"
          },
          grid: {
            borderColor: "#eceef4",
            strokeDashArray: 4
          },
          tooltip: {
            shared: true,
            intersect: false,
            y: {
              formatter: function (value) {
                return Number(value).toFixed(1) + " hours";
              }
            }
          }
        });

        roomUtilizationChart.render();

        if ((Number(highestUtilizationPercent) || 0) <= 0 || !highestUtilizationLabel || highestUtilizationLabel === "No room data") {
          setInsight(
            "roomUtilizationInsight",
            "No room usage has been recorded in the current reporting window for this " + utilizationMetricLabel.toLowerCase() + " view yet."
          );
          return;
        }

        setInsight(
          "roomUtilizationInsight",
          highestUtilizationLabel +
            " is currently the busiest " +
            utilizationMetricLabel.toLowerCase() +
            " at " +
            Number(highestUtilizationPercent).toFixed(1) +
            "% of the university's 40-hour weekly baseline."
        );
      }

      renderFacultyDirectoryList();

      if (facultyDirectorySearchInput) {
        facultyDirectorySearchInput.addEventListener("input", renderFacultyDirectoryList);
      }

      if (facultyDirectoryClearButton) {
        facultyDirectoryClearButton.addEventListener("click", function () {
          if (facultyDirectorySearchInput) {
            facultyDirectorySearchInput.value = "";
          }
          renderFacultyDirectoryList();
        });
      }

      if (facultyDirectoryList) {
        facultyDirectoryList.addEventListener("click", function (event) {
          var trigger = event.target.closest(".btn-view-faculty-detail");
          if (!trigger) {
            return;
          }

          openFacultyDirectoryDetail(trigger.getAttribute("data-faculty-id"));
        });
      }

      if (facultyDirectoryDetailModalElement) {
        facultyDirectoryDetailModalElement.addEventListener("hidden.bs.modal", function () {
          facultyDirectoryActiveFacultyId = 0;
          if (facultyDirectoryWorkloadRequest && facultyDirectoryWorkloadRequest.readyState !== 4) {
            facultyDirectoryWorkloadRequest.abort();
          }
          facultyDirectoryWorkloadRequest = null;
        });
      }

      $("#btnOpenCampusConsolidatedReport, #btnRefreshConsolidatedReport").on("click", function () {
        openConsolidatedReportPreview();
      });

      $("#btnPrintConsolidatedReport").on("click", function () {
        printConsolidatedReportPreview();
      });

      $("#btnExportConsolidatedExcel").on("click", function () {
        exportConsolidatedReportExcel();
      });

      if (!analyticsReady) {
        return;
      }

      if (!hasApexCharts) {
        console.error("ApexCharts library failed to load.");
      }

      renderRoomUtilizationChart();
      loadScheduleHeatmap();

      if (scheduleHeatmapRoomFilter) {
        scheduleHeatmapRoomFilter.addEventListener("change", function () {
          if (syncingScheduleHeatmapRoomFilter) {
            return;
          }

          scheduleHeatmapRoomSelection = scheduleHeatmapRoomFilter.value || "all";
          loadScheduleHeatmap();
        });
      }

      var openMatrixButton = document.getElementById("openMatrixModal");
      var matrixContainer = document.getElementById("matrixContainer");
      var matrixModalElement = document.getElementById("matrixModal");
      var matrixModalTitle = document.getElementById("matrixModalTitle");
      var matrixModalNote = document.getElementById("matrixModalNote");

      if (openMatrixButton && matrixContainer && matrixModalElement && selectedCollegeId > 0) {
        var matrixModal = new bootstrap.Modal(matrixModalElement);

        openMatrixButton.addEventListener("click", function () {
          if (matrixModalTitle) {
            matrixModalTitle.innerHTML = '<i class="bx bx-building me-1"></i> Room-Time Matrix';
          }

          if (matrixModalNote) {
            var noteParts = [];
            if (selectedCollegeName) {
              noteParts.push("Viewing " + selectedCollegeName);
            }
            if (selectedCollegePolicySource || selectedCollegePolicyWindow) {
              noteParts.push("Follows " + (selectedCollegePolicySource || "Scheduling policy") + (selectedCollegePolicyWindow ? ": " + selectedCollegePolicyWindow : ""));
            } else if (academicTermText) {
              noteParts.push(academicTermText);
            }
            noteParts.push("12-hour time labels");
            matrixModalNote.textContent = noteParts.join(" | ");
          }

          matrixContainer.innerHTML = `
            <div class="text-center text-muted py-5">
              Loading schedule matrix...
            </div>
          `;

          matrixModal.show();

          $.post(
            "../backend/load_room_time_matrix.php",
            {
              college_id: selectedCollegeId,
              ay_id: currentAyId,
              semester: currentSem,
              csrf_token: csrfToken
            }
          ).done(function (html) {
            matrixContainer.innerHTML = html;
          }).fail(function (xhr) {
            matrixContainer.innerHTML =
              "<div class='text-danger text-center py-4'>Failed to load schedule matrix.</div>";
            console.error(xhr.responseText);
          });
        });
      }
    });
  </script>

</body>
</html>
