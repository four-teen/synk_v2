<?php
session_start();
ob_start();
include '../backend/db.php';
require_once '../backend/academic_term_helper.php';
require_once '../backend/offering_scope_helper.php';
require_once '../backend/schedule_block_helper.php';
require_once '../backend/schedule_merge_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: ../index.php");
    exit;
}

$currentTerm = synk_fetch_current_academic_term($conn);
$campus_id = (string)($_GET['campus_id'] ?? '');
$ay_id = (string)($_GET['ay_id'] ?? ($currentTerm['ay_id'] ?? ''));
$semester = (string)($_GET['semester'] ?? ($currentTerm['semester'] ?? ''));
$doPrint = isset($_GET['print']) && $_GET['print'] === '1';
$exportMode = strtolower(trim((string)($_GET['export'] ?? '')));

function semesterLabel($sem): string
{
    switch ((string)$sem) {
        case '1':
            return 'FIRST SEMESTER';
        case '2':
            return 'SECOND SEMESTER';
        case '3':
            return 'MIDYEAR';
        default:
            return 'SEMESTER';
    }
}

function synk_admin_title_case_display($value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/', ' ', $value);
    $value = ucwords(strtolower($value));
    return (string)$value;
}

function normalizeCampusLabel($campusName): string
{
    $label = strtoupper(trim((string)$campusName));
    $label = preg_replace('/\s+CAMPUS$/i', '', $label ?? '');
    $label = trim((string)$label);
    return $label !== '' ? $label . ' CAMPUS' : 'CAMPUS';
}

function formatCampusOptionLabel($campusCode, $campusName): string
{
    $code = strtoupper(trim((string)$campusCode));
    $name = synk_admin_title_case_display($campusName);

    if ($code !== '' && $name !== '') {
        return $code . ' - ' . $name;
    }

    return $code !== '' ? $code : $name;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function buildRoomDisplayLabel($roomCode, $roomName): string
{
    $roomCode = trim((string)$roomCode);
    $roomName = trim((string)$roomName);

    if ($roomCode !== '' && $roomName !== '') {
        return strcasecmp($roomCode, $roomName) === 0 ? $roomCode : $roomCode . ' - ' . $roomName;
    }

    if ($roomCode !== '') {
        return $roomCode;
    }

    if ($roomName !== '') {
        return $roomName;
    }

    return '-';
}

function buildScheduleDisplayParts(array $row): array
{
    $decodedDays = json_decode((string)($row['days_json'] ?? ''), true);
    $days = (is_array($decodedDays) && !empty($decodedDays)) ? implode('', $decodedDays) : '-';
    $time = (!empty($row['time_start']) && !empty($row['time_end']))
        ? date('h:i A', strtotime((string)$row['time_start'])) . ' - ' . date('h:i A', strtotime((string)$row['time_end']))
        : '-';
    $schedule = ($days === '-' && $time === '-') ? '-' : trim($days . ' ' . $time);

    return [
        'schedule' => $schedule,
        'room' => buildRoomDisplayLabel(
            (string)($row['room_code'] ?? ''),
            (string)($row['room_name'] ?? $row['room_label'] ?? '')
        ),
    ];
}

function buildBaseSectionDisplayLabel(array $row): string
{
    $fullSection = trim((string)($row['full_section'] ?? ''));
    $programCode = strtoupper(trim((string)($row['program_code'] ?? '')));
    $sectionName = trim((string)($row['section_name'] ?? ''));
    $major = strtoupper(trim((string)($row['major'] ?? '')));
    $baseLabel = '';

    if ($fullSection !== '') {
        $baseLabel = $fullSection;
    } elseif ($programCode !== '' && $sectionName !== '') {
        $baseLabel = stripos($sectionName, $programCode . ' ') === 0
            ? $sectionName
            : $programCode . ' ' . $sectionName;
    } elseif ($sectionName !== '') {
        $baseLabel = $sectionName;
    }

    if ($baseLabel === '') {
        return '-';
    }

    if ($major !== '' && stripos($baseLabel, $major) === false) {
        return $baseLabel . ' - ' . $major;
    }

    return $baseLabel;
}

function buildSectionDisplayLabel(array $row): string
{
    $baseLabel = trim((string)($row['report_section_label'] ?? ''));
    if ($baseLabel === '') {
        $baseLabel = buildBaseSectionDisplayLabel($row);
    }

    if ($baseLabel === '') {
        $baseLabel = '-';
    }

    $mergeTag = trim((string)($row['report_merge_tag'] ?? ''));
    if ($mergeTag !== '' && stripos($baseLabel, '[' . $mergeTag . ']') === false) {
        return $baseLabel . ' [' . $mergeTag . ']';
    }

    return $baseLabel;
}

function buildMergedSectionDisplayLabel(array $mergeInfo, array $sectionRowsByOffering): string
{
    $groupLabel = trim((string)($mergeInfo['group_course_label'] ?? ''));
    $groupOfferingIds = synk_schedule_merge_normalize_offering_ids((array)($mergeInfo['group_offering_ids'] ?? []));

    if ($groupLabel === '' || empty($groupOfferingIds)) {
        return $groupLabel;
    }

    $hasMissingMajor = false;
    $rebuiltLabels = [];

    foreach ($groupOfferingIds as $groupOfferingId) {
        $sectionRow = $sectionRowsByOffering[$groupOfferingId] ?? null;
        if (!is_array($sectionRow)) {
            continue;
        }

        $major = strtoupper(trim((string)($sectionRow['major'] ?? '')));
        if ($major !== '' && stripos($groupLabel, $major) === false) {
            $hasMissingMajor = true;
        }

        $sectionLabel = buildBaseSectionDisplayLabel($sectionRow);
        if ($sectionLabel !== '' && $sectionLabel !== '-') {
            $rebuiltLabels[$sectionLabel] = true;
        }
    }

    if (!$hasMissingMajor || empty($rebuiltLabels)) {
        return $groupLabel;
    }

    $labelValues = array_keys($rebuiltLabels);
    natcasesort($labelValues);

    return implode('/', $labelValues);
}

function formatReportNumber($value): string
{
    $formatted = number_format((float)$value, 2, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

function buildSubjectDescriptionLabel(array $row, string $description): string
{
    $lecHours = round(max(0.0, (float)($row['lec_units'] ?? 0)), 2);
    $labHours = round(
        synk_lab_contact_hours(
            $lecHours,
            max(0.0, (float)($row['lab_units'] ?? 0)),
            (float)($row['total_units'] ?? 0)
        ),
        2
    );
    $totalUnits = round(synk_subject_units_total($lecHours, $labHours, 0.0), 2);

    $suffix = formatReportNumber($totalUnits) . ' units (' .
        formatReportNumber($lecHours) . ' lec, ' .
        formatReportNumber($labHours) . ' lab)';

    $description = trim($description);
    return $description !== '' ? $description . ', ' . $suffix : $suffix;
}

function buildCourseReportRowSignature(array $row): string
{
    $display = buildScheduleDisplayParts($row);
    $sectionLabel = buildSectionDisplayLabel($row);

    return implode('|', [
        strtoupper(trim((string)($row['sub_code'] ?? ''))),
        strtoupper(trim($sectionLabel)),
        strtoupper(trim((string)($display['schedule'] ?? '-'))),
        strtoupper(trim((string)($display['room'] ?? '-'))),
    ]);
}

function appendUniqueCourseReportRow(array &$courses, array &$rowSignaturesByCode, string $code, array $row): void
{
    if (!isset($rowSignaturesByCode[$code])) {
        $rowSignaturesByCode[$code] = [];
    }

    $signature = buildCourseReportRowSignature($row);
    if (isset($rowSignaturesByCode[$code][$signature])) {
        return;
    }

    $rowSignaturesByCode[$code][$signature] = true;
    $courses[$code]['rows'][] = $row;
}

function buildReportMergeTag(array $scopeDisplay): string
{
    if (empty($scopeDisplay['is_merged'])) {
        return '';
    }

    if (($scopeDisplay['mode'] ?? 'local') === 'full') {
        return 'Merged Subject';
    }

    return strtoupper(trim((string)($scopeDisplay['scope'] ?? 'LEC'))) === 'LAB'
        ? 'Merged LAB'
        : 'Merged LEC';
}

function loadReportScheduleRowsByOffering(mysqli $conn, array $offeringIds): array
{
    $rowsByOffering = [];
    $safeIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (empty($safeIds)) {
        return $rowsByOffering;
    }

    $sql = "
        SELECT
            cs.schedule_id,
            cs.offering_id,
            cs.schedule_type,
            cs.days_json,
            cs.time_start,
            cs.time_end,
            NULLIF(TRIM(r.room_code), '') AS room_code,
            NULLIF(TRIM(r.room_name), '') AS room_name
        FROM tbl_class_schedule cs
        LEFT JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        WHERE cs.offering_id IN (" . implode(',', array_map('intval', $safeIds)) . ")
        ORDER BY
            cs.offering_id ASC,
            FIELD(cs.schedule_type, 'LEC', 'LAB') ASC,
            cs.time_start ASC,
            cs.schedule_id ASC
    ";

    $result = $conn->query($sql);
    if (!($result instanceof mysqli_result)) {
        return $rowsByOffering;
    }

    while ($row = $result->fetch_assoc()) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId <= 0) {
            continue;
        }

        if (!isset($rowsByOffering[$offeringId])) {
            $rowsByOffering[$offeringId] = [];
        }

        $rowsByOffering[$offeringId][] = [
            'schedule_id' => (int)($row['schedule_id'] ?? 0),
            'schedule_type' => strtoupper(trim((string)($row['schedule_type'] ?? 'LEC'))) === 'LAB' ? 'LAB' : 'LEC',
            'days_json' => (string)($row['days_json'] ?? '[]'),
            'time_start' => (string)($row['time_start'] ?? ''),
            'time_end' => (string)($row['time_end'] ?? ''),
            'room_code' => (string)($row['room_code'] ?? ''),
            'room_name' => (string)($row['room_name'] ?? ''),
        ];
    }

    return $rowsByOffering;
}

function loadEffectiveReportScheduleRowsByOffering(mysqli $conn, array $offeringIds, array $effectiveOwnerMap = []): array
{
    $rowsByOffering = [];
    $safeIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (empty($safeIds)) {
        return $rowsByOffering;
    }

    if (empty($effectiveOwnerMap)) {
        $effectiveOwnerMap = synk_schedule_merge_load_effective_owner_map($conn, $safeIds);
    }

    $effectiveOwnerIds = synk_schedule_merge_collect_effective_owner_ids($effectiveOwnerMap);
    $scheduleRowsByOwner = loadReportScheduleRowsByOffering($conn, $effectiveOwnerIds);

    foreach ($safeIds as $offeringId) {
        $rowsByOffering[$offeringId] = [];

        foreach (synk_schedule_merge_schedule_types() as $type) {
            $effectiveOwnerId = (int)($effectiveOwnerMap[$offeringId][$type] ?? $offeringId);
            foreach ((array)($scheduleRowsByOwner[$effectiveOwnerId] ?? []) as $scheduleRow) {
                if (($scheduleRow['schedule_type'] ?? 'LEC') !== $type) {
                    continue;
                }

                $scheduleRow['effective_owner_id'] = $effectiveOwnerId;
                $rowsByOffering[$offeringId][] = $scheduleRow;
            }
        }

        if (count($rowsByOffering[$offeringId]) <= 1) {
            continue;
        }

        usort($rowsByOffering[$offeringId], static function (array $left, array $right): int {
            $leftOrder = ($left['schedule_type'] ?? 'LEC') === 'LAB' ? 1 : 0;
            $rightOrder = ($right['schedule_type'] ?? 'LEC') === 'LAB' ? 1 : 0;

            return [
                (string)($left['time_start'] ?? ''),
                (string)($left['time_end'] ?? ''),
                $leftOrder,
                (int)($left['schedule_id'] ?? 0),
            ] <=> [
                (string)($right['time_start'] ?? ''),
                (string)($right['time_end'] ?? ''),
                $rightOrder,
                (int)($right['schedule_id'] ?? 0),
            ];
        });
    }

    return $rowsByOffering;
}

function buildExcelHtmlDocument(array $courses, string $campusLabel, string $semester, string $ayLabel): string
{
    ob_start();
    ?>
    <html>
    <head>
      <meta charset="UTF-8">
      <style>
        body { font-family: Calibri, Arial, sans-serif; font-size: 11pt; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #000; padding: 8px; vertical-align: top; }
        th { background: #f2f2f2; font-weight: 700; text-transform: uppercase; }
        .center { text-align: center; }
        .title { font-size: 16pt; font-weight: 700; }
        .subtitle { font-size: 12pt; font-weight: 700; }
        .group { background: #eaf2fb; font-weight: 700; text-transform: uppercase; }
        .course-code { font-weight: 700; }
        .course-desc { font-weight: 700; }
      </style>
    </head>
    <body>
      <table>
        <tr><td colspan="4" class="center title">SULTAN KUDARAT STATE UNIVERSITY</td></tr>
        <tr><td colspan="4" class="center title">ALPHABETICAL LIST OF COURSES</td></tr>
        <tr><td colspan="4" class="center subtitle"><?php echo h($campusLabel); ?></td></tr>
        <tr><td colspan="4" class="center subtitle"><?php echo h(semesterLabel($semester)); ?>, AY <?php echo h($ayLabel); ?></td></tr>
        <tr><td colspan="4" class="group">Campus Offerings</td></tr>
        <tr>
          <th>Course Code</th>
          <th>Section</th>
          <th>Class Schedule</th>
          <th>Room</th>
        </tr>
        <?php if (!empty($courses)): ?>
          <?php foreach ($courses as $code => $data): ?>
            <?php
            $firstRow = (array)(($data['rows'][0] ?? []));
            $descriptionLabel = buildSubjectDescriptionLabel($firstRow, (string)($data['desc'] ?? ''));
            ?>
            <tr>
              <td class="course-code"><?php echo h($code); ?></td>
              <td colspan="3" class="course-desc"><?php echo h($descriptionLabel); ?></td>
            </tr>
            <?php foreach (($data['rows'] ?? []) as $row): ?>
              <?php $display = buildScheduleDisplayParts((array)$row); ?>
              <tr>
                <td></td>
                <td><?php echo h(buildSectionDisplayLabel((array)$row)); ?></td>
                <td><?php echo h($display['schedule']); ?></td>
                <td><?php echo h($display['room']); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="4" class="center">No schedule rows found for this filter.</td></tr>
        <?php endif; ?>
      </table>
    </body>
    </html>
    <?php
    return (string)ob_get_clean();
}

$campusOptions = [];
$campusQuery = $conn->query("
    SELECT campus_id, campus_code, campus_name
    FROM tbl_campus
    WHERE status = 'active'
    ORDER BY campus_name ASC, campus_code ASC
");
while ($campusQuery instanceof mysqli_result && ($campusRow = $campusQuery->fetch_assoc())) {
    $campusOptions[] = [
        'campus_id' => (int)($campusRow['campus_id'] ?? 0),
        'campus_name' => (string)($campusRow['campus_name'] ?? ''),
        'label' => formatCampusOptionLabel($campusRow['campus_code'] ?? '', $campusRow['campus_name'] ?? '')
    ];
}

$selectedCampus = null;
foreach ($campusOptions as $campusOption) {
    if ((string)$campusOption['campus_id'] === $campus_id) {
        $selectedCampus = $campusOption;
        break;
    }
}

$ayLabel = '';
if ($ay_id !== '') {
    $ayStmt = $conn->prepare("SELECT ay FROM tbl_academic_years WHERE ay_id = ? LIMIT 1");
    if ($ayStmt instanceof mysqli_stmt) {
        $ayInt = (int)$ay_id;
        $ayStmt->bind_param("i", $ayInt);
        $ayStmt->execute();
        $ayRes = $ayStmt->get_result();
        if ($ayRes instanceof mysqli_result && ($ayRow = $ayRes->fetch_assoc())) {
            $ayLabel = (string)($ayRow['ay'] ?? '');
        }
        $ayStmt->close();
    }
}

$selectedCampusLabel = $selectedCampus['label'] ?? '';
$campusPrintLabel = normalizeCampusLabel($selectedCampus['campus_name'] ?? '');
$hasFilters = $selectedCampus !== null && $ay_id !== '' && $semester !== '';
$courses = [];
$courseRowSignatures = [];

if ($hasFilters) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $sql = "
        SELECT
            o.offering_id,
            sm.sub_code,
            sm.sub_description,
            p.program_code,
            COALESCE(p.major, '') AS major,
            sec.section_name,
            sec.full_section,
            ps.lec_units,
            ps.lab_units,
            ps.total_units
        FROM tbl_prospectus_offering o
        {$liveOfferingJoins}
        INNER JOIN tbl_program p ON p.program_id = o.program_id
        INNER JOIN tbl_college col ON col.college_id = p.college_id
        INNER JOIN tbl_subject_masterlist sm ON sm.sub_id = ps.sub_id
        WHERE o.ay_id = ?
          AND o.semester = ?
          AND col.campus_id = ?
        ORDER BY sm.sub_code ASC, sec.full_section ASC, sec.section_name ASC, o.offering_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt instanceof mysqli_stmt) {
        $ayInt = (int)$ay_id;
        $semesterInt = (int)$semester;
        $campusInt = (int)$campus_id;
        $stmt->bind_param("iii", $ayInt, $semesterInt, $campusInt);
        $stmt->execute();
        $res = $stmt->get_result();

        $baseRows = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $baseRows[] = $row;
        }
        $stmt->close();

        if (!empty($baseRows)) {
            $offeringIds = array_map(static function (array $row): int {
                return (int)($row['offering_id'] ?? 0);
            }, $baseRows);
            $effectiveOwnerMap = synk_schedule_merge_load_effective_owner_map($conn, $offeringIds);
            $displayContextOfferingIds = $offeringIds;
            foreach ($effectiveOwnerMap as $types) {
                foreach ((array)$types as $effectiveOwnerId) {
                    $displayContextOfferingIds[] = (int)$effectiveOwnerId;
                }
            }

            $mergeContext = synk_schedule_merge_load_display_context(
                $conn,
                synk_schedule_merge_normalize_offering_ids($displayContextOfferingIds)
            );

            $groupSectionOfferingIds = $displayContextOfferingIds;
            foreach ($mergeContext as $mergeInfo) {
                foreach ((array)($mergeInfo['group_offering_ids'] ?? []) as $groupOfferingId) {
                    $groupSectionOfferingIds[] = (int)$groupOfferingId;
                }

                foreach ((array)($mergeInfo['owned_member_ids_by_scope'] ?? []) as $ownedMemberIds) {
                    foreach ((array)$ownedMemberIds as $groupOfferingId) {
                        $groupSectionOfferingIds[] = (int)$groupOfferingId;
                    }
                }

                foreach ((array)($mergeInfo['effective_owner_by_type'] ?? []) as $effectiveOwnerId) {
                    $groupSectionOfferingIds[] = (int)$effectiveOwnerId;
                }
            }

            $groupSectionRowsByOffering = synk_schedule_merge_load_section_rows_by_offering(
                $conn,
                synk_schedule_merge_normalize_offering_ids($groupSectionOfferingIds)
            );
            $scheduleRowsByOffering = loadEffectiveReportScheduleRowsByOffering($conn, $offeringIds, $effectiveOwnerMap);

            foreach ($baseRows as $row) {
                $offeringId = (int)($row['offering_id'] ?? 0);
                $mergeInfo = $mergeContext[$offeringId] ?? null;
                $groupSize = (int)($mergeInfo['group_size'] ?? 1);
                $groupLabel = trim((string)($mergeInfo['group_course_label'] ?? ''));
                $code = (string)($row['sub_code'] ?? '');

                if ($code === '') {
                    continue;
                }

                if ($groupSize > 1 && $groupLabel !== '') {
                    $row['full_section'] = buildMergedSectionDisplayLabel((array)$mergeInfo, $groupSectionRowsByOffering);
                    $row['major'] = '';
                }

                if (!isset($courses[$code])) {
                    $courses[$code] = [
                        'desc' => (string)($row['sub_description'] ?? ''),
                        'rows' => [],
                    ];
                    $courseRowSignatures[$code] = [];
                }

                $scheduleRows = $scheduleRowsByOffering[$offeringId] ?? [];
                if (empty($scheduleRows)) {
                    if ($groupSize > 1 && $groupLabel !== '') {
                        $row['report_section_label'] = buildMergedSectionDisplayLabel((array)$mergeInfo, $groupSectionRowsByOffering);
                        $row['major'] = '';
                    }

                    appendUniqueCourseReportRow($courses, $courseRowSignatures, $code, array_merge($row, [
                        'days_json' => '[]',
                        'time_start' => '',
                        'time_end' => '',
                        'room_code' => '',
                        'room_name' => '',
                    ]));
                    continue;
                }

                foreach ($scheduleRows as $scheduleRow) {
                    $scheduleType = strtoupper(trim((string)($scheduleRow['schedule_type'] ?? 'LEC'))) === 'LAB' ? 'LAB' : 'LEC';
                    $displayOfferingId = (int)($scheduleRow['effective_owner_id'] ?? ($effectiveOwnerMap[$offeringId][$scheduleType] ?? $offeringId));
                    $displayMergeInfo = $mergeContext[$displayOfferingId] ?? ($mergeContext[$offeringId] ?? null);
                    $scopeDisplay = synk_schedule_merge_scope_display_context(
                        (array)$displayMergeInfo,
                        $scheduleType,
                        $displayOfferingId,
                        $groupSectionRowsByOffering
                    );
                    $reportRow = array_merge($row, $scheduleRow);

                    $sectionLabel = trim((string)($scopeDisplay['group_label'] ?? ''));
                    if ($sectionLabel === '' && $groupSize > 1 && $groupLabel !== '') {
                        $sectionLabel = buildMergedSectionDisplayLabel((array)$mergeInfo, $groupSectionRowsByOffering);
                    }

                    if ($sectionLabel !== '') {
                        $reportRow['report_section_label'] = $sectionLabel;
                        $reportRow['major'] = '';
                    }

                    $mergeTag = buildReportMergeTag($scopeDisplay);
                    if ($mergeTag !== '') {
                        $reportRow['report_merge_tag'] = $mergeTag;
                    }

                    appendUniqueCourseReportRow($courses, $courseRowSignatures, $code, $reportRow);
                }
            }
        }
    }
}

$totalCourseCount = count($courses);
$totalScheduleEntryCount = 0;
foreach ($courses as $courseData) {
    $totalScheduleEntryCount += count($courseData['rows'] ?? []);
}

$queryParams = [
    'campus_id' => $campus_id,
    'ay_id' => $ay_id,
    'semester' => $semester
];
$printReportUrl = '?' . http_build_query(array_merge($queryParams, ['print' => '1']));
$excelReportUrl = '?' . http_build_query(array_merge($queryParams, ['export' => 'excel']));

if ($exportMode === 'excel' && $hasFilters) {
    $fileName = 'alphabetical_list_of_courses_' . preg_replace('/[^a-z0-9]+/i', '_', strtolower($campusPrintLabel . '_' . $ayLabel . '_' . semesterLabel($semester)));
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . trim($fileName, '_') . '.xls"');
    header('Cache-Control: max-age=0, no-store, no-cache, must-revalidate');
    header('Pragma: public');
    header('Expires: 0');
    echo buildExcelHtmlDocument($courses, $campusPrintLabel, $semester, $ayLabel);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8" />
  <title>Alphabetical List of Courses | Synk Administrator</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
  <link rel="stylesheet" href="../assets/vendor/css/core.css" />
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
  <link rel="stylesheet" href="../assets/css/demo.css" />
  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>
  <style>
    .report-shell, .report-filters, .report-panel { border: 1px solid #e3e9f1; border-radius: 1rem; box-shadow: 0 18px 36px rgba(34,59,99,.08); }
    .report-note { color: #6d7f95; }
    .report-chip, .report-action { display: inline-flex; align-items: center; gap: .45rem; border-radius: 999px; padding: .55rem .95rem; font-size: .85rem; font-weight: 600; text-decoration: none; }
    .report-chip { background: #f3f7fb; border: 1px solid #d7e3ef; color: #48627f; }
    .report-action.print { background: #eef6ff; border: 1px solid #bcd4f8; color: #2456a6; }
    .report-action.excel { background: #edf9f1; border: 1px solid #bfe4cb; color: #1e7a45; }
    .report-action.disabled { background: #f6f7f9; border: 1px solid #dce2ea; color: #8a97a8; cursor: not-allowed; }
    .metric-grid { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 1rem; }
    .metric-card { border: 1px solid #e1e8f0; border-radius: .9rem; padding: 1rem 1.1rem; background: linear-gradient(180deg,#fff 0%,#f9fbfd 100%); }
    .metric-label { color: #6c8199; font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
    .metric-value { margin-top: .8rem; color: #223b63; font-weight: 700; }
    .metric-value.big { font-size: 1.65rem; line-height: 1.1; }
    .report-body { padding: 1.5rem; }
    .report-empty { min-height: 280px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; color: #677b95; padding: 1.5rem; }
    .report-empty i { font-size: 3rem; color: #9bb0c7; margin-bottom: .75rem; }
    .print-sheet { max-width: 1020px; margin: 0 auto; }
    .print-header { text-align: center; margin-bottom: 1.25rem; }
    .print-header .uni { font-size: 1.05rem; font-weight: 800; color: #243b61; }
    .print-header .main { margin-top: .35rem; font-size: 1.4rem; font-weight: 800; color: #102946; }
    .print-header .campus-line, .print-header .term-line { margin-top: .3rem; font-size: .95rem; font-weight: 700; color: #3c5372; }
    .group-title { margin-bottom: .75rem; border: 1px solid #dbe5f0; border-radius: .75rem; background: #eef4fb; padding: .7rem .95rem; color: #2a4468; font-size: .95rem; font-weight: 800; text-transform: uppercase; }
    .table-wrap { overflow-x: auto; }
    table.report-table { width: 100%; border-collapse: collapse; background: #fff; }
    table.report-table th, table.report-table td { border: 1px solid #d6dee9; padding: .78rem .85rem; vertical-align: top; }
    table.report-table th { background: #f7f9fc; color: #304865; font-size: .78rem; font-weight: 800; text-transform: uppercase; }
    table.report-table td { color: #51677f; font-size: .93rem; line-height: 1.45; }
    .course-code { width: 15%; background: #fcfdff; color: #243b61; font-weight: 800; }
    .course-desc { background: #fcfdff; color: #233a5c; font-weight: 700; }
    .no-print .toolbar { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; justify-content: space-between; }
    @media (max-width: 991.98px) { .metric-grid { grid-template-columns: repeat(2, minmax(0,1fr)); } }
    @media (max-width: 767.98px) { .metric-grid { grid-template-columns: 1fr; } .report-body { padding: 1rem; } }
    @media print {
      body { background: #fff !important; }
      .no-print, .layout-menu, .layout-navbar, .layout-overlay, .content-backdrop, footer, .footer, .navbar, .menu-toggle { display: none !important; }
      .layout-wrapper, .layout-container, .layout-page, .content-wrapper, .container-xxl { display: block !important; width: 100% !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; background: #fff !important; }
      .report-panel { border: 0; box-shadow: none; border-radius: 0; }
      .report-body { padding: 0; }
      @page { size: A4 portrait; margin: 10mm 9mm 10mm 9mm; }
    }
  </style>
</head>
<body class="<?= $doPrint ? 'print-preview-mode' : '' ?>">
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">
    <?php include 'sidebar.php'; ?>
    <div class="layout-page">
      <?php include 'navbar.php'; ?>
      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">
          <div class="no-print mb-4">
            <h4 class="fw-bold mb-2">Alphabetical List of Courses</h4>
            <p class="report-note mb-0">Campus-scoped schedule report with merge-aware section display, print view, and Excel download.</p>
          </div>

          <div class="card report-filters no-print mb-4">
            <div class="card-body">
              <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-4 col-md-6">
                  <label class="form-label" for="campusSelect">Campus</label>
                  <select name="campus_id" id="campusSelect" class="form-select" required>
                    <option value="">Select campus...</option>
                    <?php foreach ($campusOptions as $campusOption): ?>
                      <option value="<?= h($campusOption['campus_id']) ?>" <?= $campus_id === (string)$campusOption['campus_id'] ? 'selected' : '' ?>><?= h($campusOption['label']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-lg-3 col-md-3">
                  <label class="form-label" for="aySelect">Academic Year</label>
                  <select name="ay_id" id="aySelect" class="form-select" required>
                    <option value="">Select year...</option>
                    <?php $ayQuery = $conn->query("SELECT ay_id, ay FROM tbl_academic_years ORDER BY ay DESC"); ?>
                    <?php while ($ayQuery instanceof mysqli_result && ($ayRow = $ayQuery->fetch_assoc())): ?>
                      <option value="<?= h($ayRow['ay_id']) ?>" <?= $ay_id === (string)$ayRow['ay_id'] ? 'selected' : '' ?>><?= h($ayRow['ay']) ?></option>
                    <?php endwhile; ?>
                  </select>
                </div>
                <div class="col-lg-2 col-md-3">
                  <label class="form-label" for="semesterSelect">Semester</label>
                  <select name="semester" id="semesterSelect" class="form-select" required>
                    <option value="">Select term...</option>
                    <option value="1" <?= $semester === '1' ? 'selected' : '' ?>>First</option>
                    <option value="2" <?= $semester === '2' ? 'selected' : '' ?>>Second</option>
                    <option value="3" <?= $semester === '3' ? 'selected' : '' ?>>Midyear</option>
                  </select>
                </div>
                <div class="col-lg-3 col-md-12 d-grid">
                  <button type="submit" class="btn btn-primary"><i class="bx bx-search-alt me-1"></i>Generate Report</button>
                </div>
                <div class="col-12 toolbar">
                  <div class="d-flex flex-wrap gap-2">
                    <?php if ($hasFilters): ?>
                      <span class="report-chip"><i class="bx bx-buildings"></i><span><?= h($selectedCampusLabel) ?></span></span>
                      <span class="report-chip"><i class="bx bx-calendar"></i><span>AY <?= h($ayLabel !== '' ? $ayLabel : '-') ?> · <?= h(ucwords(strtolower(semesterLabel($semester)))) ?></span></span>
                    <?php else: ?>
                      <span class="report-chip"><i class="bx bx-info-circle"></i><span>Choose a campus to enable print and Excel export.</span></span>
                    <?php endif; ?>
                  </div>
                  <div class="d-flex flex-wrap gap-2">
                    <?php if ($hasFilters): ?>
                      <a class="report-action print" href="<?= h($printReportUrl) ?>" target="_blank" rel="noopener"><i class="bx bx-printer"></i><span>Print View</span></a>
                      <a class="report-action excel" href="<?= h($excelReportUrl) ?>"><i class="bx bx-download"></i><span>Download Excel</span></a>
                    <?php else: ?>
                      <span class="report-action disabled"><i class="bx bx-printer"></i><span>Print View</span></span>
                      <span class="report-action disabled"><i class="bx bx-download"></i><span>Download Excel</span></span>
                    <?php endif; ?>
                  </div>
                </div>
              </form>
            </div>
          </div>

          <?php if ($hasFilters): ?>
            <div class="metric-grid no-print mb-4">
              <div class="metric-card"><div class="metric-label">Campus</div><div class="metric-value"><?= h($selectedCampusLabel) ?></div></div>
              <div class="metric-card"><div class="metric-label">Course Codes</div><div class="metric-value big"><?= h((string)$totalCourseCount) ?></div></div>
              <div class="metric-card"><div class="metric-label">Schedule Entries</div><div class="metric-value big"><?= h((string)$totalScheduleEntryCount) ?></div></div>
              <div class="metric-card"><div class="metric-label">Reporting Term</div><div class="metric-value"><?= h(ucwords(strtolower(semesterLabel($semester)))) ?>, AY <?= h($ayLabel !== '' ? $ayLabel : '-') ?></div></div>
            </div>
          <?php endif; ?>

          <div class="card report-panel">
            <div class="report-body">
              <?php if (!$hasFilters): ?>
                <div class="report-empty"><i class="bx bx-filter-alt"></i><h5 class="mb-2">Select a campus to load the report</h5><p class="mb-0">Choose the campus, academic year, and semester to generate the alphabetical list of courses.</p></div>
              <?php else: ?>
                <div class="print-sheet">
                  <div class="print-header">
                    <div>
                      <div class="uni">SULTAN KUDARAT STATE UNIVERSITY</div>
                      <div class="main">ALPHABETICAL LIST OF COURSES</div>
                      <div class="campus-line"><?= h($campusPrintLabel) ?></div>
                      <div class="term-line"><?= h(semesterLabel($semester)) ?>, AY <?= h($ayLabel) ?></div>
                    </div>
                  </div>
                  <div class="group-title">Campus Offerings</div>
                  <?php if (!empty($courses)): ?>
                    <div class="table-wrap">
                      <table class="report-table">
                        <thead>
                          <tr><th>Course Code</th><th>Section</th><th>Class Schedule</th><th>Room</th></tr>
                        </thead>
                        <tbody>
                          <?php foreach ($courses as $code => $data): ?>
                            <?php
                            $firstRow = (array)(($data['rows'][0] ?? []));
                            $descriptionLabel = buildSubjectDescriptionLabel($firstRow, (string)($data['desc'] ?? ''));
                            ?>
                            <tr><td class="course-code"><?= h($code) ?></td><td colspan="3" class="course-desc"><?= h($descriptionLabel) ?></td></tr>
                            <?php foreach (($data['rows'] ?? []) as $row): ?>
                              <?php $display = buildScheduleDisplayParts((array)$row); ?>
                              <tr>
                                <td></td>
                                <td><?= h(buildSectionDisplayLabel((array)$row)) ?></td>
                                <td><?= h($display['schedule']) ?></td>
                                <td><?= h($display['room']) ?></td>
                              </tr>
                            <?php endforeach; ?>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php else: ?>
                    <div class="report-empty"><i class="bx bx-folder-open"></i><h5 class="mb-2">No schedule rows found for this filter</h5><p class="mb-0">Try another campus, academic year, or semester to view available schedule data.</p></div>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php include '../footer.php'; ?>
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
<script src="../assets/vendor/js/menu.js"></script>
<script src="../assets/js/main.js"></script>
<?php if ($doPrint && $hasFilters): ?>
<script>
window.addEventListener('load', function () { window.print(); });
</script>
<?php endif; ?>
</body>
</html>
