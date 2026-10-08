<?php
session_start();
ob_start();

include '../backend/db.php';
require_once '../backend/academic_term_helper.php';
require_once '../backend/offering_scope_helper.php';
require_once '../backend/scheduler_access_helper.php';
require_once '../backend/schema_helper.php';
require_once '../backend/schedule_block_helper.php';

synk_scheduler_bootstrap_session_scope($conn);

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'scheduler') {
    header("Location: ../index.php");
    exit;
}

$collegeId = (int)($_SESSION['college_id'] ?? 0);
if ($collegeId <= 0) {
    echo "Scheduler error: missing college assignment.";
    exit;
}

date_default_timezone_set('Asia/Manila');

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function activity_day_order(): array
{
    return ['M', 'T', 'W', 'Th', 'F', 'S'];
}

function activity_day_labels(): array
{
    return [
        'M' => 'Monday',
        'T' => 'Tuesday',
        'W' => 'Wednesday',
        'Th' => 'Thursday',
        'F' => 'Friday',
        'S' => 'Saturday',
    ];
}

function activity_normalize_day_token($day): string
{
    $token = strtoupper(trim((string)$day));

    if ($token === 'TH') {
        return 'Th';
    }

    return in_array($token, activity_day_order(), true) ? $token : '';
}

function activity_default_day_token(): string
{
    $map = [
        'Mon' => 'M',
        'Tue' => 'T',
        'Wed' => 'W',
        'Thu' => 'Th',
        'Fri' => 'F',
        'Sat' => 'S',
    ];

    return $map[(string)date('D')] ?? 'M';
}

function activity_decode_days(string $daysJson): array
{
    $decoded = json_decode($daysJson, true);
    if (!is_array($decoded)) {
        return [];
    }

    $unique = [];
    foreach ($decoded as $day) {
        $token = activity_normalize_day_token($day);
        if ($token !== '') {
            $unique[$token] = true;
        }
    }

    $ordered = [];
    foreach (activity_day_order() as $token) {
        if (isset($unique[$token])) {
            $ordered[] = $token;
        }
    }

    return $ordered;
}

function activity_day_compact_label(array $days): string
{
    return empty($days) ? 'TBA' : implode('/', $days);
}

function activity_time_to_minutes(string $time): int
{
    $time = trim($time);
    if ($time === '') {
        return 0;
    }

    $parts = explode(':', $time);
    if (count($parts) < 2) {
        return 0;
    }

    return ((int)$parts[0] * 60) + (int)$parts[1];
}

function activity_minutes_to_input(int $minutes): string
{
    $hours = max(0, min(23, intdiv($minutes, 60)));
    $mins = max(0, min(59, $minutes % 60));
    return sprintf('%02d:%02d', $hours, $mins);
}

function activity_minutes_to_label(int $minutes): string
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

function activity_slot_header_label(int $minutes): string
{
    return (string)preg_replace('/\s+(AM|PM)$/', '', activity_minutes_to_label($minutes));
}

function activity_format_time_range(string $start, string $end): string
{
    $startMinutes = activity_time_to_minutes($start);
    $endMinutes = activity_time_to_minutes($end);

    if ($startMinutes <= 0 || $endMinutes <= 0 || $endMinutes <= $startMinutes) {
        return 'Time not set';
    }

    return activity_minutes_to_label($startMinutes) . ' - ' . activity_minutes_to_label($endMinutes);
}

function activity_overlap(int $leftStart, int $leftEnd, int $rightStart, int $rightEnd): bool
{
    return $leftStart < $rightEnd && $leftEnd > $rightStart;
}

function activity_duration_label(int $minutes): string
{
    if ($minutes <= 0) {
        return '0h';
    }

    $hours = intdiv($minutes, 60);
    $mins = $minutes % 60;

    if ($hours > 0 && $mins > 0) {
        return $hours . 'h ' . $mins . 'm';
    }

    if ($hours > 0) {
        return $hours . 'h';
    }

    return $mins . 'm';
}

function activity_schedule_type_label(string $type): string
{
    return strtoupper(trim($type)) === 'LAB' ? 'Laboratory' : 'Lecture';
}

function activity_year_level_label($yearLevel): string
{
    $year = (int)$yearLevel;
    $map = [
        1 => '1st Year',
        2 => '2nd Year',
        3 => '3rd Year',
        4 => '4th Year',
        5 => '5th Year',
        6 => '6th Year',
    ];

    return $map[$year] ?? ($year > 0 ? $year . 'th Year' : 'Year not set');
}

function activity_parse_csv_ints(string $csv): array
{
    $values = [];

    foreach (explode(',', $csv) as $piece) {
        $value = (int)trim($piece);
        if ($value > 0) {
            $values[$value] = $value;
        }
    }

    return array_values($values);
}

function activity_program_label(string $programCode, string $programName): string
{
    $programCode = trim($programCode);
    $programName = trim($programName);

    if ($programCode !== '') {
        return $programCode;
    }

    return $programName !== '' ? $programName : 'Program';
}

function activity_default_window(): array
{
    $earliest = (7 * 60) + 30;
    $latestStart = 16 * 60;
    $duration = 90;
    $currentMinutes = ((int)date('H') * 60) + (int)date('i');
    $rounded = (int)(floor($currentMinutes / 30) * 30);

    if ($rounded < $earliest) {
        $rounded = $earliest;
    }

    if ($rounded > $latestStart) {
        $rounded = $latestStart;
    }

    return [
        'start' => activity_minutes_to_input($rounded),
        'end' => activity_minutes_to_input($rounded + $duration),
    ];
}

function activity_heat_class(int $count): string
{
    if ($count <= 0) {
        return 'heat-free';
    }

    if ($count === 1) {
        return 'heat-busy';
    }

    if ($count === 2) {
        return 'heat-heavy';
    }

    return 'heat-conflict';
}

function activity_heat_state_label(int $count): string
{
    if ($count <= 0) {
        return 'Free';
    }

    if ($count === 1) {
        return 'Scheduled';
    }

    if ($count === 2) {
        return 'Overlap x2';
    }

    return 'Conflict';
}

function activity_health_summary(float $weeklyHours, int $overlapSlots): array
{
    if ($overlapSlots > 0) {
        return ['label' => 'Conflict Risk', 'class' => 'bg-label-danger'];
    }

    if ($weeklyHours >= 27) {
        return ['label' => 'Heavy Week', 'class' => 'bg-label-warning'];
    }

    if ($weeklyHours > 0) {
        return ['label' => 'Balanced Load', 'class' => 'bg-label-success'];
    }

    return ['label' => 'No Active Load', 'class' => 'bg-label-secondary'];
}

$currentTerm = synk_fetch_current_academic_term($conn);
$semesterOptions = [
    1 => 'First Semester',
    2 => 'Second Semester',
    3 => 'Midyear',
];

$defaultWindow = activity_default_window();
$selectedAyId = (int)($_GET['ay_id'] ?? ($currentTerm['ay_id'] ?? 0));
$selectedSemester = (int)($_GET['semester'] ?? ($currentTerm['semester'] ?? 0));
$selectedDay = activity_normalize_day_token($_GET['day'] ?? activity_default_day_token());
$selectedTimeStart = trim((string)($_GET['time_start'] ?? $defaultWindow['start']));
$selectedTimeEnd = trim((string)($_GET['time_end'] ?? $defaultWindow['end']));
$selectedFacultyId = (int)($_GET['faculty_id'] ?? 0);

if ($selectedDay === '') {
    $selectedDay = activity_default_day_token();
}

$selectedStartMinutes = activity_time_to_minutes($selectedTimeStart);
$selectedEndMinutes = activity_time_to_minutes($selectedTimeEnd);

if ($selectedStartMinutes <= 0 || $selectedEndMinutes <= 0 || $selectedEndMinutes <= $selectedStartMinutes) {
    $selectedTimeStart = $defaultWindow['start'];
    $selectedTimeEnd = $defaultWindow['end'];
    $selectedStartMinutes = activity_time_to_minutes($selectedTimeStart);
    $selectedEndMinutes = activity_time_to_minutes($selectedTimeEnd);
}

$academicYears = [];
$academicYearMap = [];
$academicYearRes = $conn->query("SELECT ay_id, ay FROM tbl_academic_years ORDER BY ay DESC");
if ($academicYearRes instanceof mysqli_result) {
    while ($row = $academicYearRes->fetch_assoc()) {
        $ayId = (int)($row['ay_id'] ?? 0);
        if ($ayId <= 0) {
            continue;
        }

        $label = trim((string)($row['ay'] ?? ''));
        $academicYears[] = [
            'ay_id' => $ayId,
            'label' => $label,
        ];
        $academicYearMap[$ayId] = $label;
    }
    $academicYearRes->close();
}

if ($selectedAyId <= 0 && !empty($academicYears)) {
    $selectedAyId = (int)$academicYears[0]['ay_id'];
}

if (!isset($semesterOptions[$selectedSemester])) {
    $selectedSemester = (int)($currentTerm['semester'] ?? 1);
}

$facultyOptions = [];
$assignmentHasAyId = synk_table_has_column($conn, 'tbl_college_faculty', 'ay_id');
$assignmentHasSemester = synk_table_has_column($conn, 'tbl_college_faculty', 'semester');

$facultySql = "
    SELECT DISTINCT
        f.faculty_id,
        TRIM(
            CONCAT(
                COALESCE(f.last_name, ''),
                ', ',
                COALESCE(f.first_name, ''),
                CASE
                    WHEN TRIM(COALESCE(f.ext_name, '')) <> '' THEN CONCAT(' ', TRIM(f.ext_name))
                    ELSE ''
                END
            )
        ) AS faculty_name
    FROM tbl_college_faculty cf
    INNER JOIN tbl_faculty f
        ON f.faculty_id = cf.faculty_id
    WHERE cf.college_id = ?
      AND cf.status = 'active'
      AND f.status = 'active'
";

$facultyTypes = 'i';
$facultyParams = [$collegeId];

if ($assignmentHasAyId) {
    $facultySql .= " AND cf.ay_id = ?";
    $facultyTypes .= 'i';
    $facultyParams[] = $selectedAyId;
}

if ($assignmentHasSemester) {
    $facultySql .= " AND cf.semester = ?";
    $facultyTypes .= 'i';
    $facultyParams[] = $selectedSemester;
}

$facultySql .= " ORDER BY f.last_name ASC, f.first_name ASC";

$facultyStmt = $conn->prepare($facultySql);
if ($facultyStmt instanceof mysqli_stmt) {
    synk_bind_dynamic_params($facultyStmt, $facultyTypes, $facultyParams);
    $facultyStmt->execute();
    $facultyResult = $facultyStmt->get_result();

    while ($row = $facultyResult ? $facultyResult->fetch_assoc() : null) {
        $facultyId = (int)($row['faculty_id'] ?? 0);
        if ($facultyId <= 0) {
            continue;
        }

        $facultyOptions[$facultyId] = trim((string)($row['faculty_name'] ?? 'Faculty'));
    }

    $facultyStmt->close();
}

$selectedFacultyName = $selectedFacultyId > 0
    ? (string)($facultyOptions[$selectedFacultyId] ?? 'Selected Faculty')
    : '';

$scheduleRows = [];
$windowRows = [];
$facultyTermRows = [];
$facultyWindowRows = [];
$facultyDayRows = [];
$windowFacultySet = [];
$windowRoomSet = [];
$windowSubjectSet = [];
$windowProgramSet = [];
$windowRoomUsage = [];
$windowTbaFacultyCount = 0;
$windowTbaRoomCount = 0;
$windowLectureCount = 0;
$windowLabCount = 0;

$classScheduleHasType = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type');
$scheduleTypeSql = $classScheduleHasType ? 'cs.schedule_type' : "'LEC'";
$scheduleTypeGroupSql = $classScheduleHasType ? ', cs.schedule_type' : '';
$liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');

$scheduleSql = "
    SELECT
        cs.schedule_id,
        cs.offering_id,
        {$scheduleTypeSql} AS schedule_type,
        o.year_level,
        sec.section_name,
        p.program_code,
        p.program_name,
        sm.sub_code,
        sm.sub_description,
        ps.lec_units,
        ps.lab_units,
        cs.days_json,
        cs.time_start,
        cs.time_end,
        cs.room_id,
        r.room_name,
        r.room_code,
        GROUP_CONCAT(DISTINCT f.faculty_id SEPARATOR ',') AS faculty_ids,
        GROUP_CONCAT(
            DISTINCT TRIM(
                CONCAT(
                    COALESCE(f.last_name, ''),
                    ', ',
                    COALESCE(f.first_name, ''),
                    CASE
                        WHEN TRIM(COALESCE(f.ext_name, '')) <> '' THEN CONCAT(' ', TRIM(f.ext_name))
                        ELSE ''
                    END
                )
            )
            SEPARATOR ' | '
        ) AS faculty_names
    FROM tbl_class_schedule cs
    INNER JOIN tbl_prospectus_offering o
        ON o.offering_id = cs.offering_id
    {$liveOfferingJoins}
    INNER JOIN tbl_program p
        ON p.program_id = o.program_id
    INNER JOIN tbl_subject_masterlist sm
        ON sm.sub_id = ps.sub_id
    LEFT JOIN tbl_rooms r
        ON r.room_id = cs.room_id
    LEFT JOIN tbl_faculty_workload_sched fws
        ON fws.schedule_id = cs.schedule_id
       AND fws.ay_id = o.ay_id
       AND fws.semester = o.semester
    LEFT JOIN tbl_faculty f
        ON f.faculty_id = fws.faculty_id
    WHERE o.ay_id = ?
      AND o.semester = ?
      AND p.college_id = ?
      AND p.status = 'active'
    GROUP BY
        cs.schedule_id,
        cs.offering_id
        {$scheduleTypeGroupSql},
        o.year_level,
        sec.section_name,
        p.program_code,
        p.program_name,
        sm.sub_code,
        sm.sub_description,
        ps.lec_units,
        ps.lab_units,
        cs.days_json,
        cs.time_start,
        cs.time_end,
        cs.room_id,
        r.room_name,
        r.room_code
    ORDER BY
        cs.time_start ASC,
        FIELD({$scheduleTypeSql}, 'LEC', 'LAB'),
        sec.section_name ASC,
        sm.sub_code ASC
";

$scheduleStmt = $conn->prepare($scheduleSql);
if ($scheduleStmt instanceof mysqli_stmt) {
    $scheduleStmt->bind_param('iii', $selectedAyId, $selectedSemester, $collegeId);
    $scheduleStmt->execute();
    $scheduleResult = $scheduleStmt->get_result();

    while ($row = $scheduleResult ? $scheduleResult->fetch_assoc() : null) {
        $days = activity_decode_days((string)($row['days_json'] ?? ''));
        $startTime = substr((string)($row['time_start'] ?? ''), 0, 5);
        $endTime = substr((string)($row['time_end'] ?? ''), 0, 5);
        $startMinutes = activity_time_to_minutes($startTime);
        $endMinutes = activity_time_to_minutes($endTime);
        $facultyIds = activity_parse_csv_ints((string)($row['faculty_ids'] ?? ''));
        $facultyNames = trim((string)($row['faculty_names'] ?? ''));
        $type = synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC'));
        $programLabel = activity_program_label((string)($row['program_code'] ?? ''), (string)($row['program_name'] ?? ''));
        $roomName = trim((string)($row['room_name'] ?? ''));
        $roomCode = trim((string)($row['room_code'] ?? ''));
        $roomLabel = $roomName !== '' ? $roomName : ($roomCode !== '' ? $roomCode : 'TBA');

        $preparedRow = [
            'schedule_id' => (int)($row['schedule_id'] ?? 0),
            'offering_id' => (int)($row['offering_id'] ?? 0),
            'schedule_type' => $type,
            'schedule_type_label' => activity_schedule_type_label($type),
            'year_level' => (int)($row['year_level'] ?? 0),
            'year_level_label' => activity_year_level_label($row['year_level'] ?? 0),
            'section_name' => trim((string)($row['section_name'] ?? '')),
            'program_label' => $programLabel,
            'subject_code' => trim((string)($row['sub_code'] ?? '')),
            'subject_description' => trim((string)($row['sub_description'] ?? '')),
            'lec_units' => (int)($row['lec_units'] ?? 0),
            'lab_units' => (int)($row['lab_units'] ?? 0),
            'days' => $days,
            'days_label' => activity_day_compact_label($days),
            'time_start' => $startTime,
            'time_end' => $endTime,
            'time_range' => activity_format_time_range($startTime, $endTime),
            'start_minutes' => $startMinutes,
            'end_minutes' => $endMinutes,
            'duration_minutes' => max(0, $endMinutes - $startMinutes),
            'room_id' => (int)($row['room_id'] ?? 0),
            'room_label' => $roomLabel,
            'faculty_ids' => $facultyIds,
            'faculty_names' => $facultyNames !== '' ? $facultyNames : 'TBA',
            'has_faculty' => !empty($facultyIds),
            'matches_selected_faculty' => $selectedFacultyId > 0 && in_array($selectedFacultyId, $facultyIds, true),
        ];

        $scheduleRows[] = $preparedRow;

        if ($preparedRow['matches_selected_faculty']) {
            $facultyTermRows[] = $preparedRow;
        }

        if ($selectedFacultyId > 0 && in_array($selectedDay, $preparedRow['days'], true) && $preparedRow['matches_selected_faculty']) {
            $facultyDayRows[] = $preparedRow;
        }

        if (
            in_array($selectedDay, $preparedRow['days'], true) &&
            $preparedRow['start_minutes'] > 0 &&
            $preparedRow['end_minutes'] > 0 &&
            activity_overlap($selectedStartMinutes, $selectedEndMinutes, $preparedRow['start_minutes'], $preparedRow['end_minutes'])
        ) {
            $windowRows[] = $preparedRow;
            $windowSubjectSet[$preparedRow['subject_code']] = true;
            $windowProgramSet[$preparedRow['program_label']] = true;

            if ($preparedRow['schedule_type'] === 'LAB') {
                $windowLabCount++;
            } else {
                $windowLectureCount++;
            }

            if ($preparedRow['room_label'] !== 'TBA') {
                $windowRoomSet[$preparedRow['room_label']] = true;
                $windowRoomUsage[$preparedRow['room_label']] = (int)($windowRoomUsage[$preparedRow['room_label']] ?? 0) + 1;
            } else {
                $windowTbaRoomCount++;
            }

            if ($preparedRow['has_faculty']) {
                foreach ($preparedRow['faculty_ids'] as $facultyId) {
                    $windowFacultySet[$facultyId] = true;
                }
            } else {
                $windowTbaFacultyCount++;
            }

            if ($preparedRow['matches_selected_faculty']) {
                $facultyWindowRows[] = $preparedRow;
            }
        }
    }

    $scheduleStmt->close();
}

usort($windowRows, static function (array $left, array $right): int {
    if ($left['start_minutes'] === $right['start_minutes']) {
        if ($left['schedule_type'] === $right['schedule_type']) {
            return strcmp($left['subject_code'], $right['subject_code']);
        }

        return ($left['schedule_type'] === 'LAB' ? 1 : 0) <=> ($right['schedule_type'] === 'LAB' ? 1 : 0);
    }

    return $left['start_minutes'] <=> $right['start_minutes'];
});

usort($facultyDayRows, static function (array $left, array $right): int {
    if ($left['start_minutes'] === $right['start_minutes']) {
        return strcmp($left['subject_code'], $right['subject_code']);
    }

    return $left['start_minutes'] <=> $right['start_minutes'];
});

arsort($windowRoomUsage);
$topRooms = array_slice($windowRoomUsage, 0, 3, true);
$mostUsedRoomLabel = 'No room activity';
$mostUsedRoomCount = 0;

if (!empty($windowRoomUsage)) {
    reset($windowRoomUsage);
    $mostUsedRoomLabel = (string)key($windowRoomUsage);
    $mostUsedRoomCount = (int)current($windowRoomUsage);
}

$nextFacultyClass = null;
if ($selectedFacultyId > 0) {
    foreach ($facultyDayRows as $row) {
        if ($row['start_minutes'] >= $selectedEndMinutes) {
            $nextFacultyClass = $row;
            break;
        }
    }
}

$heatmapSlots = [];
for ($slotMinutes = (7 * 60) + 30; $slotMinutes < (17 * 60) + 30; $slotMinutes += 30) {
    $heatmapSlots[] = [
        'label' => activity_minutes_to_label($slotMinutes),
        'start' => $slotMinutes,
        'end' => $slotMinutes + 30,
    ];
}

$heatmapDays = activity_day_order();
$heatmapMatrix = [];
$facultyDayTotals = [];
foreach ($heatmapDays as $dayToken) {
    $heatmapMatrix[$dayToken] = [];
    $facultyDayTotals[$dayToken] = 0;

    foreach ($heatmapSlots as $slot) {
        $heatmapMatrix[$dayToken][$slot['label']] = 0;
    }
}

$facultyWeeklyMinutes = 0;
if ($selectedFacultyId > 0) {
    foreach ($facultyTermRows as $row) {
        $meetingMinutes = max(0, (int)$row['duration_minutes']);
        if ($meetingMinutes <= 0 || empty($row['days'])) {
            continue;
        }

        foreach ($row['days'] as $dayToken) {
            if (!isset($heatmapMatrix[$dayToken])) {
                continue;
            }

            $facultyWeeklyMinutes += $meetingMinutes;
            $facultyDayTotals[$dayToken] += $meetingMinutes;

            foreach ($heatmapSlots as $slot) {
                if (activity_overlap($slot['start'], $slot['end'], (int)$row['start_minutes'], (int)$row['end_minutes'])) {
                    $heatmapMatrix[$dayToken][$slot['label']]++;
                }
            }
        }
    }
}

$heatOverlapSlots = 0;
foreach ($heatmapMatrix as $dayCells) {
    foreach ($dayCells as $count) {
        if ($count > 1) {
            $heatOverlapSlots++;
        }
    }
}

$busiestDayToken = 'M';
$busiestDayMinutes = 0;
foreach ($facultyDayTotals as $dayToken => $minutes) {
    if ($minutes > $busiestDayMinutes) {
        $busiestDayMinutes = $minutes;
        $busiestDayToken = $dayToken;
    }
}

$weeklyHours = round($facultyWeeklyMinutes / 60, 1);
$selectedDayLoadHours = round(($facultyDayTotals[$selectedDay] ?? 0) / 60, 1);
$healthSummary = activity_health_summary($weeklyHours, $heatOverlapSlots);
$dayLabels = activity_day_labels();

$pageTitle = 'Schedule Activity Monitor';
$windowLabel = activity_format_time_range($selectedTimeStart, $selectedTimeEnd);
$selectedAyLabel = $academicYearMap[$selectedAyId] ?? (string)($currentTerm['ay_label'] ?? 'Academic Year');
$selectedSemesterLabel = $semesterOptions[$selectedSemester] ?? 'Semester';
$collegeName = trim((string)($_SESSION['college_name'] ?? 'Assigned College'));
$campusName = trim((string)($_SESSION['campus_name'] ?? 'Current Campus'));
$schedulerName = trim((string)($_SESSION['username'] ?? 'Scheduler'));
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
    <title><?php echo h($pageTitle); ?> | Synk Scheduler</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" class="template-customizer-core-css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" />
    <link rel="stylesheet" type="text/css" href="custom_css.css" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
      .activity-hero {
        border: 1px solid #e4e9f3;
        border-radius: 1.2rem;
        background: linear-gradient(135deg, #ffffff 0%, #f6f9ff 65%, #eef7ff 100%);
      }

      .activity-hero-top {
        display: flex;
        justify-content: space-between;
        gap: 1.25rem;
        align-items: flex-start;
        flex-wrap: wrap;
      }

      .activity-eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.3rem 0.65rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #566b8f;
        background: rgba(105, 108, 255, 0.08);
      }

      .scope-chip-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.55rem;
        margin-top: 1rem;
      }

      .scope-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.5rem 0.8rem;
        border-radius: 999px;
        background: #fff;
        border: 1px solid #dde6f4;
        color: #4f6480;
        font-size: 0.82rem;
        font-weight: 600;
      }

      .activity-toolbar {
        min-width: 240px;
        display: grid;
        gap: 0.65rem;
      }

      .activity-toolbar-card {
        border-radius: 1rem;
        padding: 0.9rem 1rem;
        background: rgba(255, 255, 255, 0.88);
        border: 1px solid #e2e8f3;
      }

      .activity-toolbar-label {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #7a8ca5;
        font-weight: 700;
      }

      .activity-toolbar-value {
        font-size: 0.95rem;
        font-weight: 700;
        color: #243b5a;
      }

      .filter-card,
      .surface-card,
      .metric-card {
        border: 1px solid #e4e9f3;
        border-radius: 1rem;
        box-shadow: 0 10px 24px rgba(57, 76, 96, 0.05);
      }

      .filter-card .card-body,
      .surface-card .card-body {
        padding: 1.15rem 1.2rem;
      }

      .filter-hint {
        color: #7b8ba3;
        font-size: 0.84rem;
      }

      .metric-card {
        background: #fff;
        height: 100%;
      }

      .metric-card .card-body {
        padding: 1rem 1rem 0.95rem;
      }

      .metric-icon {
        width: 2.8rem;
        height: 2.8rem;
        border-radius: 0.9rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.35rem;
      }

      .metric-label {
        font-size: 0.77rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #7a8ca5;
        font-weight: 700;
      }

      .metric-value {
        font-size: 1.7rem;
        line-height: 1.1;
        font-weight: 700;
        color: #243b5a;
      }

      .metric-note {
        min-height: 2.35rem;
        color: #72839b;
        font-size: 0.84rem;
      }

      .window-table thead th {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        white-space: nowrap;
        color: #61748d;
        background: #f8fbff;
      }

      .window-table tbody td {
        vertical-align: top;
      }

      .window-table .time-col {
        min-width: 132px;
        white-space: nowrap;
      }

      .window-table .subject-col {
        min-width: 280px;
      }

      .window-table .section-col {
        min-width: 170px;
      }

      .window-table .faculty-col {
        min-width: 220px;
      }

      .subject-code {
        font-weight: 700;
        color: #243b5a;
      }

      .subject-meta,
      .section-meta,
      .faculty-meta,
      .time-meta {
        color: #7b8ba3;
        font-size: 0.82rem;
      }

      .activity-type-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        padding: 0.22rem 0.52rem;
        white-space: nowrap;
      }

      .type-lec {
        background: rgba(105, 108, 255, 0.12);
        color: #5d62f6;
      }

      .type-lab {
        background: rgba(113, 221, 55, 0.14);
        color: #5db71a;
      }

      .activity-match-row {
        background: rgba(105, 108, 255, 0.04);
      }
      .agenda-item,
      .presence-item,
      .insight-item {
        border: 1px solid #e6edf6;
        border-radius: 0.95rem;
        padding: 0.85rem 0.95rem;
        background: #fff;
      }

      .presence-item.is-active {
        border-color: #cfe1ff;
        background: linear-gradient(180deg, #ffffff 0%, #f4f8ff 100%);
      }

      .item-title {
        font-weight: 700;
        color: #243b5a;
      }

      .item-subtitle {
        color: #72839b;
        font-size: 0.84rem;
      }

      .agenda-strip {
        display: grid;
        gap: 0.75rem;
      }

      .agenda-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.28rem 0.6rem;
        border-radius: 999px;
        font-size: 0.74rem;
        font-weight: 700;
      }

      .agenda-pill.active {
        color: #0d6efd;
        background: rgba(13, 110, 253, 0.12);
      }

      .agenda-pill.upcoming {
        color: #ff8b00;
        background: rgba(255, 171, 0, 0.14);
      }

      .agenda-pill.done {
        color: #8592a3;
        background: rgba(133, 146, 163, 0.14);
      }

      .legend-row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.7rem;
      }

      .legend-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        color: #6a7d97;
        font-size: 0.8rem;
      }

      .legend-box {
        width: 0.9rem;
        height: 0.9rem;
        border-radius: 0.3rem;
        border: 1px solid rgba(86, 109, 134, 0.16);
      }

      .dataTables_wrapper .dataTables_length,
      .dataTables_wrapper .dataTables_filter {
        margin-bottom: 1rem;
      }

      .dataTables_wrapper .dataTables_filter {
        text-align: right;
      }

      .dataTables_wrapper .dataTables_filter input,
      .dataTables_wrapper .dataTables_length select {
        border-radius: 0.75rem;
        border-color: #d9e2ef;
        min-height: 2.6rem;
        box-shadow: none;
      }

      .dataTables_wrapper .dataTables_info,
      .dataTables_wrapper .dataTables_paginate {
        margin-top: 0.9rem;
        color: #6e829d;
        font-size: 0.84rem;
      }

      .dataTables_wrapper .dataTables_paginate .paginate_button {
        border-radius: 0.75rem !important;
      }

      .heatmap-wrap {
        overflow-x: auto;
      }

      .heatmap-table {
        min-width: 760px;
        border-collapse: separate;
        border-spacing: 0.35rem;
      }

      .heatmap-table th {
        font-size: 0.78rem;
        font-weight: 700;
        color: #60748d;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        text-align: center;
        white-space: nowrap;
      }

      .heatmap-table .heat-day-label {
        text-align: left;
        min-width: 132px;
        padding-right: 0.8rem;
        color: #3d516b;
        font-size: 0.82rem;
        white-space: nowrap;
      }

      .heatmap-table .heat-time-label {
        min-width: 78px;
        padding-bottom: 0.55rem;
      }

      .heat-cell {
        width: 78px;
        min-width: 78px;
        height: 48px;
        border-radius: 0.8rem;
        text-align: center;
        font-weight: 700;
        font-size: 0.84rem;
        color: #334760;
        padding: 0.2rem;
      }

      .heat-marker {
        width: 100%;
        height: 100%;
        border-radius: 0.72rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.8rem;
      }

      .heat-marker i {
        font-size: 1.05rem;
      }

      .heat-free {
        background: #f4f7fb;
        color: #9aa9bb;
      }

      .heat-busy {
        background: rgba(13, 110, 253, 0.12);
        color: #0d6efd;
      }

      .heat-heavy {
        background: rgba(255, 171, 0, 0.18);
        color: #d98200;
      }

      .heat-conflict {
        background: rgba(255, 62, 29, 0.16);
        color: #d6311f;
      }

      .empty-state {
        border: 1px dashed #d7e2f0;
        border-radius: 1rem;
        padding: 1.35rem 1.2rem;
        text-align: center;
        color: #7b8ba3;
        background: #fbfdff;
      }

      .top-room-list {
        display: grid;
        gap: 0.65rem;
      }

      .top-room-item {
        display: flex;
        justify-content: space-between;
        gap: 0.8rem;
        align-items: center;
      }

      @media (max-width: 991.98px) {
        .activity-toolbar {
          min-width: 100%;
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }

      @media (max-width: 767.98px) {
        .activity-hero-top {
          flex-direction: column;
        }

        .activity-toolbar {
          grid-template-columns: 1fr;
        }

        .window-table .subject-col,
        .window-table .faculty-col,
        .window-table .section-col {
          min-width: 220px;
        }

        .heat-cell {
          width: 64px;
          min-width: 64px;
        }

        .heatmap-table .heat-day-label {
          min-width: 104px;
        }
      }
    </style>
  </head>

  <body>
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <?php include 'sidebar.php'; ?>

        <div class="layout-page">
          <?php include 'navbar.php'; ?>

          <div class="content-wrapper">
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="card activity-hero mb-4">
                <div class="card-body p-4">
                  <div class="activity-hero-top">
                    <div>
                      <span class="activity-eyebrow">
                        <i class="bx bx-radar"></i>
                        Monitoring Workspace
                      </span>
                      <h4 class="mb-2 mt-3"><?php echo h($pageTitle); ?></h4>
                      <p class="mb-0 text-muted">
                        Track active class windows, verify where faculty should be, and review weekly teaching-load heat patterns in one workspace.
                      </p>

                      <div class="scope-chip-row">
                        <span class="scope-chip"><i class="bx bx-buildings"></i><?php echo h($collegeName); ?></span>
                        <span class="scope-chip"><i class="bx bx-map"></i><?php echo h($campusName); ?></span>
                        <span class="scope-chip"><i class="bx bx-calendar"></i><?php echo h($selectedSemesterLabel . ' | AY ' . $selectedAyLabel); ?></span>
                        <span class="scope-chip"><i class="bx bx-time-five"></i><?php echo h($dayLabels[$selectedDay] . ' | ' . $windowLabel); ?></span>
                      </div>
                    </div>

                    <div class="activity-toolbar">
                      <div class="activity-toolbar-card">
                        <div class="activity-toolbar-label">Best Menu Name</div>
                        <div class="activity-toolbar-value">Schedule Activity</div>
                      </div>
                      <div class="activity-toolbar-card">
                        <div class="activity-toolbar-label">Faculty Focus</div>
                        <div class="activity-toolbar-value"><?php echo h($selectedFacultyName !== '' ? $selectedFacultyName : 'All Faculty'); ?></div>
                      </div>
                      <div class="activity-toolbar-card">
                        <div class="activity-toolbar-label">Load Health</div>
                        <div class="d-flex align-items-center gap-2 mt-1">
                          <span class="badge <?php echo h($healthSummary['class']); ?>"><?php echo h($healthSummary['label']); ?></span>
                          <span class="text-muted small"><?php echo h($schedulerName); ?> monitor view</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <form method="get" class="card filter-card mb-4">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                    <div>
                      <h5 class="mb-1">Monitoring Filters</h5>
                      <p class="mb-0 filter-hint">Use the time window to scan all classes, then choose a faculty when you want location and heat-map details.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                      <button type="submit" class="btn btn-primary"><i class="bx bx-search-alt-2 me-1"></i> Apply Monitor</button>
                      <a href="manage-schedule-activity.php" class="btn btn-outline-secondary"><i class="bx bx-reset me-1"></i> Reset</a>
                    </div>
                  </div>

                  <div class="row g-3">
                    <div class="col-xl-2 col-md-4"><label class="form-label" for="ay_id">Academic Year</label><select class="form-select" id="ay_id" name="ay_id"><?php foreach ($academicYears as $yearOption): ?><option value="<?php echo (int)$yearOption['ay_id']; ?>" <?php echo $selectedAyId === (int)$yearOption['ay_id'] ? 'selected' : ''; ?>><?php echo h($yearOption['label']); ?></option><?php endforeach; ?></select></div>
                    <div class="col-xl-2 col-md-4"><label class="form-label" for="semester">Semester</label><select class="form-select" id="semester" name="semester"><?php foreach ($semesterOptions as $semesterValue => $semesterLabel): ?><option value="<?php echo (int)$semesterValue; ?>" <?php echo $selectedSemester === (int)$semesterValue ? 'selected' : ''; ?>><?php echo h($semesterLabel); ?></option><?php endforeach; ?></select></div>
                    <div class="col-xl-2 col-md-4"><label class="form-label" for="day">Day</label><select class="form-select" id="day" name="day"><?php foreach (activity_day_order() as $dayToken): ?><option value="<?php echo h($dayToken); ?>" <?php echo $selectedDay === $dayToken ? 'selected' : ''; ?>><?php echo h($dayLabels[$dayToken]); ?></option><?php endforeach; ?></select></div>
                    <div class="col-xl-2 col-md-4"><label class="form-label" for="time_start">Window Start</label><input type="time" class="form-control" id="time_start" name="time_start" value="<?php echo h($selectedTimeStart); ?>" step="1800" /></div>
                    <div class="col-xl-2 col-md-4"><label class="form-label" for="time_end">Window End</label><input type="time" class="form-control" id="time_end" name="time_end" value="<?php echo h($selectedTimeEnd); ?>" step="1800" /></div>
                    <div class="col-xl-2 col-md-4"><label class="form-label" for="faculty_id">Faculty Focus</label><select class="form-select" id="faculty_id" name="faculty_id"><option value="0">All faculty</option><?php foreach ($facultyOptions as $facultyId => $facultyLabel): ?><option value="<?php echo (int)$facultyId; ?>" <?php echo $selectedFacultyId === (int)$facultyId ? 'selected' : ''; ?>><?php echo h($facultyLabel); ?></option><?php endforeach; ?></select></div>
                  </div>
                </div>
              </form>

              <div class="row g-3 mb-4">
                <div class="col-xl-3 col-sm-6"><div class="card metric-card"><div class="card-body"><div class="d-flex justify-content-between gap-3"><div><div class="metric-label">Classes in Window</div><div class="metric-value"><?php echo count($windowRows); ?></div><div class="metric-note">Schedules overlapping <?php echo h($windowLabel); ?> on <?php echo h($dayLabels[$selectedDay]); ?>.</div></div><span class="metric-icon bg-label-primary text-primary"><i class="bx bx-time-five"></i></span></div></div></div></div>
                <div class="col-xl-3 col-sm-6"><div class="card metric-card"><div class="card-body"><div class="d-flex justify-content-between gap-3"><div><div class="metric-label">Faculty Active</div><div class="metric-value"><?php echo count($windowFacultySet); ?></div><div class="metric-note">Distinct faculty assigned inside the selected monitoring window.</div></div><span class="metric-icon bg-label-success text-success"><i class="bx bx-user-check"></i></span></div></div></div></div>
                <div class="col-xl-3 col-sm-6"><div class="card metric-card"><div class="card-body"><div class="d-flex justify-content-between gap-3"><div><div class="metric-label">Rooms in Use</div><div class="metric-value"><?php echo count($windowRoomSet); ?></div><div class="metric-note">Mapped rooms carrying classes in the current day-and-time range.</div></div><span class="metric-icon bg-label-info text-info"><i class="bx bx-building-house"></i></span></div></div></div></div>
                <div class="col-xl-3 col-sm-6"><div class="card metric-card"><div class="card-body"><div class="d-flex justify-content-between gap-3"><div><div class="metric-label"><?php echo $selectedFacultyId > 0 ? 'Faculty Matches' : 'Subjects Covered'; ?></div><div class="metric-value"><?php echo $selectedFacultyId > 0 ? count($facultyWindowRows) : count($windowSubjectSet); ?></div><div class="metric-note"><?php if ($selectedFacultyId > 0): ?>Classes tied to <?php echo h($selectedFacultyName); ?> in this monitoring window.<?php else: ?>Distinct subjects represented in the selected window.<?php endif; ?></div></div><span class="metric-icon bg-label-warning text-warning"><i class="bx bx-pulse"></i></span></div></div></div></div>
              </div>

              <div class="card surface-card mb-4">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                    <div>
                      <h5 class="mb-1">Faculty Whereabouts</h5>
                      <p class="mb-0 text-muted">Find where a faculty should be in the selected day-and-time window and keep the day agenda visible in one view.</p>
                    </div>
                    <?php if ($selectedFacultyId > 0): ?>
                      <span class="badge bg-label-primary"><?php echo h($selectedFacultyName); ?></span>
                    <?php endif; ?>
                  </div>

                  <div class="scope-chip-row mb-4">
                    <span class="scope-chip"><i class="bx bx-time-five"></i><?php echo h($dayLabels[$selectedDay] . ' | ' . $windowLabel); ?></span>
                    <span class="scope-chip"><i class="bx bx-building-house"></i><?php echo h('Top room: ' . $mostUsedRoomLabel); ?></span>
                    <span class="scope-chip"><i class="bx bx-user"></i><?php echo (int)$windowTbaFacultyCount; ?> faculty TBA</span>
                    <span class="scope-chip"><i class="bx bx-grid-alt"></i><?php echo (int)$windowLectureCount; ?> lecture | <?php echo (int)$windowLabCount; ?> lab</span>
                  </div>

                  <?php if ($selectedFacultyId <= 0): ?>
                    <div class="empty-state">
                      <div class="fw-semibold mb-1">Choose a faculty to activate this panel.</div>
                      <div>The page will then show current room, time, and the full day agenda for that instructor.</div>
                    </div>
                  <?php else: ?>
                    <?php if (!empty($facultyWindowRows)): ?>
                      <div class="agenda-strip mb-4">
                        <?php foreach ($facultyWindowRows as $row): ?>
                          <div class="presence-item is-active">
                            <div class="d-flex justify-content-between gap-2 align-items-start">
                              <div>
                                <div class="item-title"><?php echo h($row['subject_code'] . ' | ' . ($row['section_name'] !== '' ? $row['section_name'] : 'Section TBA')); ?></div>
                                <div class="item-subtitle"><?php echo h($row['subject_description']); ?></div>
                              </div>
                              <span class="agenda-pill active">Inside window</span>
                            </div>
                            <div class="row g-2 mt-2">
                              <div class="col-lg-3 col-sm-6"><span class="item-subtitle">Room</span><div class="fw-semibold"><?php echo h($row['room_label']); ?></div></div>
                              <div class="col-lg-3 col-sm-6"><span class="item-subtitle">Time</span><div class="fw-semibold"><?php echo h($row['time_range']); ?></div></div>
                              <div class="col-lg-3 col-sm-6"><span class="item-subtitle">Program</span><div class="fw-semibold"><?php echo h($row['program_label']); ?></div></div>
                              <div class="col-lg-3 col-sm-6"><span class="item-subtitle">Type</span><div class="fw-semibold"><?php echo h($row['schedule_type_label']); ?></div></div>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php elseif ($nextFacultyClass !== null): ?>
                      <div class="empty-state mb-4">
                        <div class="fw-semibold mb-1">No class for this faculty inside the selected window.</div>
                        <div>Next class on <?php echo h($dayLabels[$selectedDay]); ?> starts at <?php echo h($nextFacultyClass['time_range']); ?> in <?php echo h($nextFacultyClass['room_label']); ?>.</div>
                      </div>
                    <?php elseif (empty($facultyTermRows)): ?>
                      <div class="empty-state mb-4">
                        <div class="fw-semibold mb-1">No scheduled classes found for this faculty in the selected term.</div>
                        <div>Try switching academic year or semester if the load belongs to another term.</div>
                      </div>
                    <?php else: ?>
                      <div class="empty-state mb-4">
                        <div class="fw-semibold mb-1">No more matching classes on <?php echo h($dayLabels[$selectedDay]); ?>.</div>
                        <div>This faculty has schedule entries this term, but none overlap the current window.</div>
                      </div>
                    <?php endif; ?>

                    <?php if (!empty($facultyDayRows)): ?>
                      <h6 class="mb-3">Day Agenda</h6>
                      <div class="agenda-strip">
                        <?php foreach ($facultyDayRows as $row): ?>
                          <?php
                          $agendaStateClass = 'done';
                          $agendaStateLabel = 'Earlier';
                          if (activity_overlap($selectedStartMinutes, $selectedEndMinutes, (int)$row['start_minutes'], (int)$row['end_minutes'])) {
                              $agendaStateClass = 'active';
                              $agendaStateLabel = 'Current window';
                          } elseif ((int)$row['start_minutes'] >= $selectedEndMinutes) {
                              $agendaStateClass = 'upcoming';
                              $agendaStateLabel = 'Upcoming';
                          }
                          ?>
                          <div class="agenda-item">
                            <div class="d-flex justify-content-between gap-2 align-items-start">
                              <div>
                                <div class="item-title"><?php echo h($row['subject_code'] . ' | ' . ($row['section_name'] !== '' ? $row['section_name'] : 'Section TBA')); ?></div>
                                <div class="item-subtitle"><?php echo h($row['room_label'] . ' | ' . $row['time_range']); ?></div>
                              </div>
                              <span class="agenda-pill <?php echo h($agendaStateClass); ?>"><?php echo h($agendaStateLabel); ?></span>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>

              <div class="card surface-card mb-4">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                    <div>
                      <h5 class="mb-1">Classes in Selected Time Range</h5>
                      <p class="mb-0 text-muted">This list now uses DataTables so the scheduler can sort, search, and scan matching classes more comfortably.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                      <span class="badge bg-label-primary"><?php echo h($dayLabels[$selectedDay]); ?></span>
                      <span class="badge bg-label-info"><?php echo h($windowLabel); ?></span>
                      <span class="badge bg-label-secondary"><?php echo count($windowProgramSet); ?> program<?php echo count($windowProgramSet) === 1 ? '' : 's'; ?></span>
                    </div>
                  </div>

                  <?php if (empty($windowRows)): ?>
                    <div class="empty-state">
                      <div class="fw-semibold mb-1">No classes overlap this monitoring window.</div>
                      <div>Try widening the time range or switching to another day.</div>
                    </div>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table id="windowClassesTable" class="table align-middle window-table mb-0">
                        <thead>
                          <tr>
                            <th class="time-col">Time</th>
                            <th class="subject-col">Class Details</th>
                            <th class="section-col">Section & Program</th>
                            <th>Days</th>
                            <th>Room</th>
                            <th class="faculty-col">Faculty</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($windowRows as $row): ?>
                            <tr class="<?php echo $row['matches_selected_faculty'] ? 'activity-match-row' : ''; ?>">
                              <td class="time-col" data-order="<?php echo (int)$row['start_minutes']; ?>">
                                <div class="fw-semibold text-dark"><?php echo h($row['time_range']); ?></div>
                                <div class="time-meta"><?php echo h(activity_duration_label((int)$row['duration_minutes'])); ?></div>
                              </td>
                              <td class="subject-col">
                                <div class="d-flex align-items-start justify-content-between gap-2">
                                  <div>
                                    <div class="subject-code"><?php echo h($row['subject_code']); ?></div>
                                    <div class="text-dark"><?php echo h($row['subject_description']); ?></div>
                                    <div class="subject-meta mt-1"><?php echo (int)$row['lec_units']; ?> lec / <?php echo (int)$row['lab_units']; ?> lab units</div>
                                  </div>
                                  <span class="activity-type-badge <?php echo $row['schedule_type'] === 'LAB' ? 'type-lab' : 'type-lec'; ?>"><?php echo h($row['schedule_type_label']); ?></span>
                                </div>
                              </td>
                              <td class="section-col">
                                <div class="fw-semibold text-dark"><?php echo h($row['section_name'] !== '' ? $row['section_name'] : 'Section TBA'); ?></div>
                                <div class="section-meta"><?php echo h($row['program_label']); ?></div>
                                <div class="section-meta"><?php echo h($row['year_level_label']); ?></div>
                              </td>
                              <td><span class="badge bg-label-secondary"><?php echo h($row['days_label']); ?></span></td>
                              <td>
                                <div class="fw-semibold text-dark"><?php echo h($row['room_label']); ?></div>
                                <div class="section-meta"><?php echo $row['room_label'] === 'TBA' ? 'Room still unassigned' : 'Room mapped'; ?></div>
                              </td>
                              <td class="faculty-col">
                                <div class="fw-semibold text-dark"><?php echo h($row['faculty_names']); ?></div>
                                <div class="faculty-meta">
                                  <?php if ($row['matches_selected_faculty']): ?>
                                    Selected faculty appears in this class window.
                                  <?php elseif (!$row['has_faculty']): ?>
                                    Faculty still unassigned for this schedule.
                                  <?php else: ?>
                                    Assigned instructor for this schedule.
                                  <?php endif; ?>
                                </div>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="card surface-card">
                <div class="card-body">
                  <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap mb-3">
                    <div>
                      <h5 class="mb-1">Faculty Weekly Heat Map</h5>
                      <p class="mb-0 text-muted">Shows where the selected faculty carries classes across the week and flags possible overlap pressure.</p>
                    </div>
                    <?php if ($selectedFacultyId > 0): ?>
                      <div class="d-flex gap-2 flex-wrap">
                        <span class="badge bg-label-primary"><?php echo h($selectedFacultyName); ?></span>
                        <span class="badge bg-label-info"><?php echo h($weeklyHours . ' weekly hours'); ?></span>
                        <span class="badge bg-label-secondary"><?php echo h($dayLabels[$busiestDayToken] . ' peak'); ?></span>
                      </div>
                    <?php endif; ?>
                  </div>

                  <?php if ($selectedFacultyId <= 0): ?>
                    <div class="empty-state">
                      <div class="fw-semibold mb-1">Select a faculty to generate the heat map.</div>
                      <div>This section is designed for faculty load health and overlap monitoring.</div>
                    </div>
                  <?php elseif (empty($facultyTermRows)): ?>
                    <div class="empty-state">
                      <div class="fw-semibold mb-1">No heat-map data available for this faculty.</div>
                      <div>The selected faculty has no schedule rows in the chosen academic term.</div>
                    </div>
                  <?php else: ?>
                    <div class="row g-3 mb-3">
                      <div class="col-xl-3 col-sm-6"><div class="insight-item h-100"><div class="item-subtitle">Weekly Hours</div><div class="item-title"><?php echo h($weeklyHours . 'h'); ?></div><div class="item-subtitle">Total scheduled hours across all assigned teaching days.</div></div></div>
                      <div class="col-xl-3 col-sm-6"><div class="insight-item h-100"><div class="item-subtitle">Selected Day Load</div><div class="item-title"><?php echo h($selectedDayLoadHours . 'h'); ?></div><div class="item-subtitle"><?php echo h($dayLabels[$selectedDay]); ?> class hours for the chosen faculty.</div></div></div>
                      <div class="col-xl-3 col-sm-6"><div class="insight-item h-100"><div class="item-subtitle">Busiest Day</div><div class="item-title"><?php echo h($dayLabels[$busiestDayToken]); ?></div><div class="item-subtitle"><?php echo h(round($busiestDayMinutes / 60, 1) . 'h scheduled'); ?></div></div></div>
                      <div class="col-xl-3 col-sm-6"><div class="insight-item h-100"><div class="item-subtitle">Overlap Slots</div><div class="item-title"><?php echo (int)$heatOverlapSlots; ?></div><div class="item-subtitle">Half-hour cells with more than one class assignment.</div></div></div>
                    </div>

                    <div class="legend-row mb-3">
                      <span class="legend-chip"><span class="legend-box heat-free"></span>Free</span>
                      <span class="legend-chip"><span class="legend-box heat-busy"></span>Scheduled</span>
                      <span class="legend-chip"><span class="legend-box heat-heavy"></span>Overlap x2</span>
                      <span class="legend-chip"><span class="legend-box heat-conflict"></span>Conflict 3+</span>
                    </div>

                    <div class="heatmap-wrap">
                      <table class="heatmap-table w-100">
                        <thead>
                          <tr>
                            <th class="heat-day-label">Day \ Time</th>
                            <?php foreach ($heatmapSlots as $slot): ?>
                              <th class="heat-time-label" title="<?php echo h($slot['label']); ?>">
                                <?php echo h(activity_slot_header_label((int)$slot['start'])); ?>
                              </th>
                            <?php endforeach; ?>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($heatmapDays as $dayToken): ?>
                            <tr>
                              <th scope="row" class="heat-day-label"><?php echo h($dayLabels[$dayToken]); ?></th>
                              <?php foreach ($heatmapSlots as $slot): ?>
                                <?php
                                $count = (int)$heatmapMatrix[$dayToken][$slot['label']];
                                $stateLabel = activity_heat_state_label($count);
                                $cellTitle = $dayLabels[$dayToken] . ' | ' . $slot['label'] . ' | ' . $stateLabel;
                                ?>
                                <td class="heat-cell <?php echo h(activity_heat_class($count)); ?>" title="<?php echo h($cellTitle); ?>">
                                  <span class="heat-marker">
                                    <?php if ($count <= 0): ?>
                                      <span aria-hidden="true">&middot;</span>
                                    <?php elseif ($count === 1): ?>
                                      <i class="bx bx-check" aria-hidden="true"></i>
                                    <?php elseif ($count === 2): ?>
                                      <span>2x</span>
                                    <?php else: ?>
                                      <span>3+</span>
                                    <?php endif; ?>
                                  </span>
                                </td>
                              <?php endforeach; ?>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>

      <div class="layout-overlay layout-menu-toggle"></div>
    </div>

    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
      $(function () {
        const windowTable = $("#windowClassesTable");

        if (!windowTable.length || !$.fn.DataTable) {
          return;
        }

        windowTable.DataTable({
          autoWidth: false,
          scrollX: true,
          pageLength: 10,
          lengthMenu: [
            [10, 25, 50, -1],
            [10, 25, 50, "All"]
          ],
          order: [[0, "asc"], [1, "asc"]],
          language: {
            search: "",
            searchPlaceholder: "Search classes in this time range",
            lengthMenu: "Show _MENU_ classes",
            info: "Showing _START_ to _END_ of _TOTAL_ classes",
            zeroRecords: "No matching classes found in this view"
          },
          dom:
            "<'row align-items-center mb-3'<'col-md-6'l><'col-md-6'f>>" +
            "t" +
            "<'row align-items-center mt-3'<'col-md-5'i><'col-md-7'p>>"
        });
      });
    </script>
  </body>
</html>
