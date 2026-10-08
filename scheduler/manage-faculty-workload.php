<?php
session_start();
ob_start();
include '../backend/db.php';
require_once '../backend/academic_term_helper.php';
require_once '../backend/offering_scope_helper.php';
require_once '../backend/schema_helper.php';
require_once '../backend/schedule_block_helper.php';
require_once '../backend/schedule_merge_helper.php';
require_once '../backend/signatory_settings_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'scheduler') {
    header("Location: ../index.php");
    exit;
}

$collegeId = (int)($_SESSION['college_id'] ?? 0);
$currentTerm = synk_fetch_current_academic_term($conn);

$program_id = (string)($_GET['program_id'] ?? '');
$ay_id = (string)($_GET['ay_id'] ?? ($currentTerm['ay_id'] ?? ''));
$semester = (string)($_GET['semester'] ?? ($currentTerm['semester'] ?? ''));
$doPrint = isset($_GET['print']) && $_GET['print'] === '1';
$exportMode = strtolower(trim((string)($_GET['export'] ?? '')));
$reportScope = strtolower(trim((string)($_GET['scope'] ?? 'program')));
$reportScope = in_array($reportScope, ['program', 'college'], true) ? $reportScope : 'program';
$isCollegeScope = $reportScope === 'college';
$collegeName = trim((string)($_SESSION['college_name'] ?? ''));

function semesterLabel($sem) {
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

function normalizeCampusLabel($campusName) {
    $label = strtoupper(trim((string)$campusName));
    $label = preg_replace('/\s+CAMPUS$/i', '', $label ?? '');
    $label = trim((string)$label);

    return $label !== '' ? $label . ' CAMPUS' : 'CAMPUS';
}

function synk_title_case_display($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/', ' ', $value);
    $value = ucwords(strtolower($value));

    $smallWords = ['And', 'Of', 'In', 'On', 'To', 'For', 'The', 'A', 'An', 'At', 'By', 'From'];
    $parts = explode(' ', $value);

    foreach ($parts as $index => $part) {
        if ($index === 0) {
            continue;
        }

        if (in_array($part, $smallWords, true)) {
            $parts[$index] = strtolower($part);
        }
    }

    return implode(' ', $parts);
}

function formatProgramDisplayLabel($programCode, $programName, $major = '') {
    $code = strtoupper(trim((string)$programCode));
    $name = synk_title_case_display($programName);
    $major = synk_title_case_display($major);

    $label = trim($code !== '' && $name !== ''
        ? $code . ' - ' . $name
        : ($code !== '' ? $code : $name));

    if ($major !== '') {
        $label = trim($label !== '' ? $label . ' major in ' . $major : $major);
    }

    return $label;
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function reportSignatoryUppercase(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_strtoupper')) {
        return mb_strtoupper($value, 'UTF-8');
    }

    return strtoupper($value);
}

function normalizeReportSignatorySlot(array $settings, string $slotCode, string $fallbackLabel): array
{
    $row = is_array($settings[$slotCode] ?? null) ? $settings[$slotCode] : [];

    return [
        'slot_code' => $slotCode,
        'label' => trim((string)($row['label'] ?? $fallbackLabel)) ?: $fallbackLabel,
        'faculty_id' => (int)($row['faculty_id'] ?? 0),
        'signatory_name' => trim((string)($row['signatory_name'] ?? '')),
        'signatory_title' => trim((string)($row['signatory_title'] ?? '')),
    ];
}

function reportSignatoryHasContent(array $slot): bool
{
    return (int)($slot['faculty_id'] ?? 0) > 0
        || trim((string)($slot['signatory_name'] ?? '')) !== ''
        || trim((string)($slot['signatory_title'] ?? '')) !== '';
}

function excelXmlEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function buildRoomDisplayLabel($roomCode, $roomName): string {
    $roomCode = trim((string)$roomCode);
    $roomName = trim((string)$roomName);

    if ($roomCode !== '' && $roomName !== '') {
        return strcasecmp($roomCode, $roomName) === 0
            ? $roomCode
            : $roomCode . ' - ' . $roomName;
    }

    if ($roomCode !== '') {
        return $roomCode;
    }

    if ($roomName !== '') {
        return $roomName;
    }

    return '-';
}

function buildScheduleDisplayParts(array $row): array {
    $decodedDays = json_decode((string)($row['days_json'] ?? ''), true);
    $days = (is_array($decodedDays) && !empty($decodedDays)) ? implode('', $decodedDays) : '-';
    $time = (!empty($row['time_start']) && !empty($row['time_end']))
        ? date("h:i A", strtotime((string)$row['time_start'])) . ' - ' . date("h:i A", strtotime((string)$row['time_end']))
        : '-';
    $roomCode = trim((string)($row['room_code'] ?? ''));
    $roomName = trim((string)($row['room_name'] ?? ''));
    $schedule = ($days === '-' && $time === '-') ? '-' : trim($days . ' ' . $time);

    return [
        'days' => $days,
        'time' => $time,
        'room' => buildRoomDisplayLabel($roomCode, $roomName),
        'schedule' => $schedule
    ];
}

function buildBaseSectionDisplayLabel(array $row): string {
    $fullSection = trim((string)($row['full_section'] ?? ''));
    $programCode = strtoupper(trim((string)($row['program_code'] ?? '')));
    $sectionName = trim((string)($row['section_name'] ?? ''));
    $major = strtoupper(trim((string)($row['major'] ?? '')));
    $baseLabel = '';

    if ($fullSection !== '') {
        $baseLabel = $fullSection;
    } elseif ($programCode !== '' && $sectionName !== '') {
        if (stripos($sectionName, $programCode . ' ') === 0) {
            $baseLabel = $sectionName;
        } else {
            $baseLabel = $programCode . ' ' . $sectionName;
        }
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

function buildSectionDisplayLabel(array $row): string {
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

function buildCourseReportRowSignature(array $row): string
{
    $display = buildScheduleDisplayParts($row);
    $sectionLabel = buildSectionDisplayLabel($row);

    return implode('|', [
        strtoupper(trim((string)($row['sub_code'] ?? ''))),
        strtoupper(trim($sectionLabel)),
        strtoupper(trim((string)($display['schedule'] ?? '-'))),
        strtoupper(trim((string)($display['room'] ?? '-')))
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

function buildSubjectHourUnitDisplay(array $row): array {
    $lecHours = round(max(0.0, (float)($row['lec_units'] ?? 0)), 2);
    $labHours = round(
        synk_lab_contact_hours(
            $lecHours,
            max(0.0, (float)($row['lab_units'] ?? 0)),
            (float)($row['total_units'] ?? 0)
        ),
        2
    );

    return [
        'lec_hours' => $lecHours,
        'lab_hours' => $labHours,
        'total_units' => round(synk_subject_units_total($lecHours, $labHours, 0.0), 2)
    ];
}

function formatReportNumber($value): string {
    $formatted = number_format((float)$value, 2, '.', '');
    return rtrim(rtrim($formatted, '0'), '.');
}

function buildSubjectDescriptionLabel(array $row, string $description): string {
    $description = trim($description);
    $hoursUnits = buildSubjectHourUnitDisplay($row);
    $suffix = formatReportNumber($hoursUnits['total_units']) . ' units (' .
        formatReportNumber($hoursUnits['lec_hours']) . ' lec, ' .
        formatReportNumber($hoursUnits['lab_hours']) . ' lab)';

    return $description !== '' ? $description . ', ' . $suffix : $suffix;
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

function loadReportScheduleRowsByOffering(mysqli $conn, array $offeringIds): array {
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
            'room_name' => (string)($row['room_name'] ?? '')
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
                (int)($left['schedule_id'] ?? 0)
            ] <=> [
                (string)($right['time_start'] ?? ''),
                (string)($right['time_end'] ?? ''),
                $rightOrder,
                (int)($right['schedule_id'] ?? 0)
            ];
        });
    }

    return $rowsByOffering;
}

function facultyReportSavedScheduleTablesExist(mysqli $conn): bool
{
    foreach ([
        'tbl_program_schedule_set',
        'tbl_program_schedule_set_row'
    ] as $tableName) {
        if (!synk_table_exists($conn, $tableName)) {
            return false;
        }
    }

    return true;
}

function facultyReportSavedScheduleScopeLabel(int $programId, string $programCode = '', string $programName = ''): string
{
    if ($programId <= 0) {
        return 'College term';
    }

    $scopeParts = array_filter([
        strtoupper(trim($programCode)),
        synk_title_case_display($programName)
    ], static function ($value): bool {
        return trim((string)$value) !== '';
    });

    return !empty($scopeParts)
        ? 'Legacy program set: ' . implode(' - ', $scopeParts)
        : 'Legacy program set';
}

function facultyReportLoadSavedScheduleWorkspaceState(mysqli $conn, int $collegeId, int $ayId, int $semester): ?array
{
    if (!facultyReportSavedScheduleTablesExist($conn) || !synk_table_exists($conn, 'tbl_program_schedule_live_workspace_state')) {
        return null;
    }

    $sql = "
        SELECT
            ws.last_loaded_schedule_set_id,
            s.schedule_set_id,
            s.program_id,
            s.set_name,
            COALESCE(NULLIF(TRIM(p.program_code), ''), '') AS program_code,
            COALESCE(NULLIF(TRIM(p.program_name), ''), '') AS program_name
        FROM tbl_program_schedule_live_workspace_state ws
        LEFT JOIN tbl_program_schedule_set s
            ON s.schedule_set_id = ws.last_loaded_schedule_set_id
           AND s.college_id = ws.college_id
           AND s.ay_id = ws.ay_id
           AND s.semester = ws.semester
        LEFT JOIN tbl_program p
            ON p.program_id = s.program_id
        WHERE ws.college_id = ?
          AND ws.ay_id = ?
          AND ws.semester = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!($stmt instanceof mysqli_stmt)) {
        return null;
    }

    $stmt->bind_param("iii", $collegeId, $ayId, $semester);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $scheduleSetId = (int)($row['schedule_set_id'] ?? $row['last_loaded_schedule_set_id'] ?? 0);
    if ($scheduleSetId <= 0) {
        return null;
    }

    return [
        'schedule_set_id' => $scheduleSetId,
        'set_name' => trim((string)($row['set_name'] ?? '')),
        'scope_label' => facultyReportSavedScheduleScopeLabel(
            (int)($row['program_id'] ?? 0),
            (string)($row['program_code'] ?? ''),
            (string)($row['program_name'] ?? '')
        )
    ];
}

function facultyReportLoadSavedScheduleSetsForCollegeTerm(mysqli $conn, int $collegeId, int $ayId, int $semester): array
{
    if (!facultyReportSavedScheduleTablesExist($conn)) {
        return [];
    }

    $sql = "
        SELECT
            s.schedule_set_id,
            s.program_id,
            s.set_name,
            COALESCE(NULLIF(TRIM(p.program_code), ''), '') AS program_code,
            COALESCE(NULLIF(TRIM(p.program_name), ''), '') AS program_name
        FROM tbl_program_schedule_set s
        LEFT JOIN tbl_program p
            ON p.program_id = s.program_id
        WHERE s.college_id = ?
          AND s.ay_id = ?
          AND s.semester = ?
        ORDER BY
            CASE WHEN s.program_id = 0 THEN 0 ELSE 1 END ASC,
            s.date_updated DESC,
            s.schedule_set_id DESC
    ";

    $stmt = $conn->prepare($sql);
    if (!($stmt instanceof mysqli_stmt)) {
        return [];
    }

    $stmt->bind_param("iii", $collegeId, $ayId, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $sets = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $sets[] = [
            'schedule_set_id' => (int)($row['schedule_set_id'] ?? 0),
            'program_id' => (int)($row['program_id'] ?? 0),
            'set_name' => trim((string)($row['set_name'] ?? '')),
            'scope_label' => facultyReportSavedScheduleScopeLabel(
                (int)($row['program_id'] ?? 0),
                (string)($row['program_code'] ?? ''),
                (string)($row['program_name'] ?? '')
            )
        ];
    }

    $stmt->close();
    return $sets;
}

function facultyReportLoadSavedScheduleSetRowsForCollege(mysqli $conn, int $scheduleSetId, int $collegeId): array
{
    $sql = "
        SELECT
            r.program_id,
            r.offering_id,
            r.schedule_type,
            r.room_id,
            r.days_json,
            r.time_start,
            r.time_end
        FROM tbl_program_schedule_set_row r
        INNER JOIN tbl_program_schedule_set s
            ON s.schedule_set_id = r.schedule_set_id
        WHERE r.schedule_set_id = ?
          AND s.college_id = ?
        ORDER BY
            r.program_id ASC,
            r.offering_id ASC,
            r.block_order ASC,
            FIELD(r.schedule_type, 'LEC', 'LAB'),
            r.schedule_set_row_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!($stmt instanceof mysqli_stmt)) {
        return [];
    }

    $stmt->bind_param("ii", $scheduleSetId, $collegeId);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function facultyReportNormalizeSignatureDays($daysJson): string
{
    $days = json_decode((string)$daysJson, true);
    if (!is_array($days)) {
        return '';
    }

    $normalized = [];
    foreach ($days as $day) {
        $value = trim((string)$day);
        if ($value !== '') {
            $normalized[$value] = true;
        }
    }

    return implode(',', array_keys($normalized));
}

function facultyReportSignatureFromSetRows(array $rows): string
{
    $items = [];

    foreach ($rows as $row) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId <= 0) {
            continue;
        }

        $items[] = [
            'program_id' => (int)($row['program_id'] ?? 0),
            'offering_id' => $offeringId,
            'schedule_type' => strtoupper(trim((string)($row['schedule_type'] ?? 'LEC'))) === 'LAB' ? 'LAB' : 'LEC',
            'room_id' => (int)($row['room_id'] ?? 0),
            'days' => facultyReportNormalizeSignatureDays((string)($row['days_json'] ?? '[]')),
            'time_start' => (string)($row['time_start'] ?? ''),
            'time_end' => (string)($row['time_end'] ?? '')
        ];
    }

    usort($items, static function (array $left, array $right): int {
        return [
            $left['program_id'],
            $left['offering_id'],
            $left['schedule_type'],
            $left['room_id'],
            $left['days'],
            $left['time_start'],
            $left['time_end']
        ] <=> [
            $right['program_id'],
            $right['offering_id'],
            $right['schedule_type'],
            $right['room_id'],
            $right['days'],
            $right['time_start'],
            $right['time_end']
        ];
    });

    return empty($items) ? '' : sha1(json_encode($items));
}

function facultyReportLoadLiveScheduleSnapshotRowsForCollegeTerm(mysqli $conn, int $ayId, int $semester, int $collegeId): array
{
    $sql = "
        SELECT
            o.program_id,
            cs.offering_id,
            cs.schedule_type,
            cs.room_id,
            cs.days_json,
            cs.time_start,
            cs.time_end
        FROM tbl_class_schedule cs
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        WHERE o.ay_id = ?
          AND o.semester = ?
          AND p.college_id = ?
          AND cs.schedule_type IN ('LEC', 'LAB')
        ORDER BY
            o.program_id ASC,
            cs.offering_id ASC,
            FIELD(cs.schedule_type, 'LEC', 'LAB'),
            cs.time_start ASC,
            cs.schedule_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!($stmt instanceof mysqli_stmt)) {
        return [];
    }

    $stmt->bind_param("iii", $ayId, $semester, $collegeId);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $rows[] = $row;
    }

    $stmt->close();
    return $rows;
}

function facultyReportSignatureFromLiveRows(array $rows): string
{
    $items = [];

    foreach ($rows as $row) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId <= 0) {
            continue;
        }

        $items[] = [
            'program_id' => (int)($row['program_id'] ?? 0),
            'offering_id' => $offeringId,
            'schedule_type' => strtoupper(trim((string)($row['schedule_type'] ?? 'LEC'))) === 'LAB' ? 'LAB' : 'LEC',
            'room_id' => (int)($row['room_id'] ?? 0),
            'days' => facultyReportNormalizeSignatureDays((string)($row['days_json'] ?? '[]')),
            'time_start' => (string)($row['time_start'] ?? ''),
            'time_end' => (string)($row['time_end'] ?? '')
        ];
    }

    usort($items, static function (array $left, array $right): int {
        return [
            $left['program_id'],
            $left['offering_id'],
            $left['schedule_type'],
            $left['room_id'],
            $left['days'],
            $left['time_start'],
            $left['time_end']
        ] <=> [
            $right['program_id'],
            $right['offering_id'],
            $right['schedule_type'],
            $right['room_id'],
            $right['days'],
            $right['time_start'],
            $right['time_end']
        ];
    });

    return empty($items) ? '' : sha1(json_encode($items));
}

function facultyReportResolveWorkspaceStatus(mysqli $conn, int $collegeId, int $ayId, int $semester): array
{
    $status = [
        'label' => 'Current live workspace (not saved as a set)',
        'scope_label' => '',
        'state_key' => 'live',
        'set_count' => 0
    ];

    if ($collegeId <= 0 || $ayId <= 0 || $semester <= 0 || !facultyReportSavedScheduleTablesExist($conn)) {
        return $status;
    }

    $sets = facultyReportLoadSavedScheduleSetsForCollegeTerm($conn, $collegeId, $ayId, $semester);
    $status['set_count'] = count($sets);
    if (empty($sets)) {
        $status['label'] = 'Current live workspace (no saved set yet)';
        return $status;
    }

    $workspaceState = facultyReportLoadSavedScheduleWorkspaceState($conn, $collegeId, $ayId, $semester);
    $liveSignature = facultyReportSignatureFromLiveRows(
        facultyReportLoadLiveScheduleSnapshotRowsForCollegeTerm($conn, $ayId, $semester, $collegeId)
    );

    $activeSet = null;
    foreach ($sets as $set) {
        $scheduleSetId = (int)($set['schedule_set_id'] ?? 0);
        if ($scheduleSetId <= 0) {
            continue;
        }

        $setSignature = facultyReportSignatureFromSetRows(
            facultyReportLoadSavedScheduleSetRowsForCollege($conn, $scheduleSetId, $collegeId)
        );

        if ($setSignature === '' || $setSignature !== $liveSignature) {
            continue;
        }

        $activeSet = $set;
        break;
    }

    if (is_array($activeSet)) {
        $status['label'] = 'Active set: ' . trim((string)($activeSet['set_name'] ?? 'Saved set'));
        $status['scope_label'] = trim((string)($activeSet['scope_label'] ?? ''));
        $status['state_key'] = 'active_set';
        return $status;
    }

    if (is_array($workspaceState) && trim((string)($workspaceState['set_name'] ?? '')) !== '') {
        $status['label'] = 'Loaded from: ' . trim((string)$workspaceState['set_name']) . ' (modified in live)';
        $status['scope_label'] = trim((string)($workspaceState['scope_label'] ?? ''));
        $status['state_key'] = 'loaded_modified';
        return $status;
    }

    $status['label'] = 'Current live workspace (not matching a saved set)';
    return $status;
}

function excelCellXml($value, $styleId = 'Body', $mergeAcross = 0) {
    $mergeAttr = $mergeAcross > 0 ? ' ss:MergeAcross="' . (int)$mergeAcross . '"' : '';
    return '<Cell ss:StyleID="' . excelXmlEscape($styleId) . '"' . $mergeAttr . '><Data ss:Type="String">' . excelXmlEscape($value) . '</Data></Cell>';
}

function xlsxColumnNameFromIndex(int $index): string
{
    $name = '';

    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = (int)floor($index / 26);
    }

    return $name;
}

function xlsxInlineCellXml(int $rowNumber, int $columnIndex, string $value, int $styleIndex = 0): string
{
    $cellRef = xlsxColumnNameFromIndex($columnIndex) . $rowNumber;
    return '<c r="' . $cellRef . '" t="inlineStr" s="' . $styleIndex . '"><is><t xml:space="preserve">' . excelXmlEscape($value) . '</t></is></c>';
}

function buildFacultyWorkloadXlsxBinary(
    array $courses,
    string $programLabel,
    string $campusLabel,
    string $semester,
    string $ayLabel,
    string $groupTitle = 'Program Offerings',
    string $sourceLabel = '',
    string $mergeLabel = ''
): ?string
{
    if (!class_exists('ZipArchive')) {
        return null;
    }

    $rowsXml = [];
    $mergeRefs = [];
    $rowNumber = 1;

    $appendMergedRow = static function (string $value, int $styleIndex) use (&$rowsXml, &$mergeRefs, &$rowNumber): void {
        $rowsXml[] = '<row r="' . $rowNumber . '">' . xlsxInlineCellXml($rowNumber, 1, $value, $styleIndex) . '</row>';
        $mergeRefs[] = 'A' . $rowNumber . ':D' . $rowNumber;
        $rowNumber++;
    };

    $appendMergedRow('SULTAN KUDARAT STATE UNIVERSITY', 1);
    $appendMergedRow('ALPHABETICAL LIST OF COURSES', 2);

    if ($programLabel !== '') {
        $appendMergedRow($programLabel, 3);
    }

    $appendMergedRow($campusLabel, 3);
    $appendMergedRow(semesterLabel($semester) . ', AY ' . ($ayLabel !== '' ? $ayLabel : '-'), 3);
    if ($sourceLabel !== '') {
        $appendMergedRow('Source: ' . $sourceLabel, 3);
    }
    if ($mergeLabel !== '') {
        $appendMergedRow('Merge handling: ' . $mergeLabel, 3);
    }
    $rowsXml[] = '<row r="' . $rowNumber . '"/>';
    $rowNumber++;
    $appendMergedRow($groupTitle !== '' ? $groupTitle : 'Program Offerings', 4);

    $rowsXml[] = '<row r="' . $rowNumber . '">' .
        xlsxInlineCellXml($rowNumber, 1, 'Course Code', 5) .
        xlsxInlineCellXml($rowNumber, 2, 'Section', 5) .
        xlsxInlineCellXml($rowNumber, 3, 'Class Schedule', 5) .
        xlsxInlineCellXml($rowNumber, 4, 'Room', 5) .
    '</row>';
    $rowNumber++;

    if (!empty($courses)) {
        foreach ($courses as $code => $data) {
            $firstRow = (array)(($data['rows'][0] ?? []));
            $descriptionLabel = buildSubjectDescriptionLabel($firstRow, (string)($data['desc'] ?? ''));
            $rowsXml[] = '<row r="' . $rowNumber . '">' .
                xlsxInlineCellXml($rowNumber, 1, (string)$code, 6) .
                xlsxInlineCellXml($rowNumber, 2, $descriptionLabel, 7) .
            '</row>';
            $mergeRefs[] = 'B' . $rowNumber . ':D' . $rowNumber;
            $rowNumber++;

            foreach (($data['rows'] ?? []) as $row) {
                $display = buildScheduleDisplayParts((array)$row);
                $sectionLabel = buildSectionDisplayLabel((array)$row);
                $rowsXml[] = '<row r="' . $rowNumber . '">' .
                    xlsxInlineCellXml($rowNumber, 1, '', 0) .
                    xlsxInlineCellXml($rowNumber, 2, $sectionLabel, 0) .
                    xlsxInlineCellXml($rowNumber, 3, $display['schedule'], 0) .
                    xlsxInlineCellXml($rowNumber, 4, $display['room'], 0) .
                '</row>';
                $rowNumber++;
            }
        }
    } else {
        $rowsXml[] = '<row r="' . $rowNumber . '">' . xlsxInlineCellXml($rowNumber, 1, 'No workload rows found for this filter.', 8) . '</row>';
        $mergeRefs[] = 'A' . $rowNumber . ':D' . $rowNumber;
    }

    $mergeCellsXml = '';
    if (!empty($mergeRefs)) {
        $mergeCellsXml = '<mergeCells count="' . count($mergeRefs) . '">';
        foreach ($mergeRefs as $mergeRef) {
            $mergeCellsXml .= '<mergeCell ref="' . $mergeRef . '"/>';
        }
        $mergeCellsXml .= '</mergeCells>';
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<sheetViews><sheetView workbookViewId="0"/></sheetViews>' .
            '<sheetFormatPr defaultRowHeight="15"/>' .
            '<cols>' .
                '<col min="1" max="1" width="18" customWidth="1"/>' .
                '<col min="2" max="2" width="18" customWidth="1"/>' .
                '<col min="3" max="3" width="32" customWidth="1"/>' .
                '<col min="4" max="4" width="22" customWidth="1"/>' .
            '</cols>' .
            '<sheetData>' . implode('', $rowsXml) . '</sheetData>' .
            $mergeCellsXml .
        '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
            '<fonts count="6">' .
                '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>' .
                '<font><b/><sz val="16"/><name val="Calibri"/><family val="2"/></font>' .
                '<font><b/><sz val="12"/><name val="Calibri"/><family val="2"/></font>' .
                '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font>' .
                '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/><color rgb="FF334760"/></font>' .
                '<font><i/><sz val="11"/><name val="Calibri"/><family val="2"/></font>' .
            '</fonts>' .
            '<fills count="4">' .
                '<fill><patternFill patternType="none"/></fill>' .
                '<fill><patternFill patternType="gray125"/></fill>' .
                '<fill><patternFill patternType="solid"><fgColor rgb="FFEAF2FB"/><bgColor indexed="64"/></patternFill></fill>' .
                '<fill><patternFill patternType="solid"><fgColor rgb="FFF7F7F7"/><bgColor indexed="64"/></patternFill></fill>' .
            '</fills>' .
            '<borders count="2">' .
                '<border><left/><right/><top/><bottom/><diagonal/></border>' .
                '<border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border>' .
            '</borders>' .
            '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' .
            '<cellXfs count="9">' .
                '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>' .
                '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' .
                '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' .
                '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' .
                '<xf numFmtId="0" fontId="4" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>' .
                '<xf numFmtId="0" fontId="3" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' .
                '<xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>' .
                '<xf numFmtId="0" fontId="3" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>' .
                '<xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' .
            '</cellXfs>' .
            '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>' .
        '</styleSheet>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' .
            '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' .
            '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>' .
        '</Types>';

    $rootRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>' .
            '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>' .
        '</Relationships>';

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets>' .
                '<sheet name="Faculty Workload" sheetId="1" r:id="rId1"/>' .
            '</sheets>' .
        '</workbook>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>' .
        '</Relationships>';

    $timestamp = gmdate('Y-m-d\TH:i:s\Z');
    $coreXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
            '<dc:title>Faculty Workload Report</dc:title>' .
            '<dc:creator>Synk</dc:creator>' .
            '<cp:lastModifiedBy>Synk</cp:lastModifiedBy>' .
            '<dcterms:created xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:created>' .
            '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:modified>' .
        '</cp:coreProperties>';

    $appXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">' .
            '<Application>Synk</Application>' .
        '</Properties>';

    $tempPath = tempnam(sys_get_temp_dir(), 'synk_xlsx_');
    if ($tempPath === false) {
        return null;
    }

    $zip = new ZipArchive();
    if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tempPath);
        return null;
    }

    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $rootRelsXml);
    $zip->addFromString('docProps/core.xml', $coreXml);
    $zip->addFromString('docProps/app.xml', $appXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();

    $binary = file_get_contents($tempPath);
    @unlink($tempPath);

    return $binary === false ? null : $binary;
}

function buildFacultyWorkloadExcelWorkbook(
    array $courses,
    string $programLabel,
    string $campusLabel,
    string $semester,
    string $ayLabel,
    string $groupTitle = 'Program Offerings',
    string $sourceLabel = '',
    string $mergeLabel = ''
): string
{
    $rows = [];
    $rows[] = '<Row ss:Height="24">' . excelCellXml('SULTAN KUDARAT STATE UNIVERSITY', 'Title', 3) . '</Row>';
    $rows[] = '<Row>' . excelCellXml('ALPHABETICAL LIST OF COURSES', 'Subtitle', 3) . '</Row>';

    if ($programLabel !== '') {
        $rows[] = '<Row>' . excelCellXml($programLabel, 'Meta', 3) . '</Row>';
    }

    $rows[] = '<Row>' . excelCellXml($campusLabel, 'Meta', 3) . '</Row>';
    $rows[] = '<Row>' . excelCellXml(semesterLabel($semester) . ', AY ' . ($ayLabel !== '' ? $ayLabel : '-'), 'Meta', 3) . '</Row>';
    if ($sourceLabel !== '') {
        $rows[] = '<Row>' . excelCellXml('Source: ' . $sourceLabel, 'Meta', 3) . '</Row>';
    }
    if ($mergeLabel !== '') {
        $rows[] = '<Row>' . excelCellXml('Merge handling: ' . $mergeLabel, 'Meta', 3) . '</Row>';
    }
    $rows[] = '<Row/>';
    $rows[] = '<Row>' . excelCellXml($groupTitle !== '' ? $groupTitle : 'Program Offerings', 'GroupTitle', 3) . '</Row>';
    $rows[] = '<Row>' .
        excelCellXml('Course Code', 'Header') .
        excelCellXml('Section', 'Header') .
        excelCellXml('Class Schedule', 'Header') .
        excelCellXml('Room', 'Header') .
    '</Row>';

    if (!empty($courses)) {
        foreach ($courses as $code => $data) {
            $firstRow = (array)(($data['rows'][0] ?? []));
            $descriptionLabel = buildSubjectDescriptionLabel($firstRow, (string)($data['desc'] ?? ''));
            $rows[] = '<Row>' .
                excelCellXml($code, 'CourseCode') .
                excelCellXml($descriptionLabel, 'CourseDesc', 2) .
            '</Row>';

            foreach (($data['rows'] ?? []) as $row) {
                $display = buildScheduleDisplayParts((array)$row);
                $sectionLabel = buildSectionDisplayLabel((array)$row);
                $rows[] = '<Row>' .
                    excelCellXml('', 'Body') .
                    excelCellXml($sectionLabel, 'Body') .
                    excelCellXml($display['schedule'], 'Body') .
                    excelCellXml($display['room'], 'Body') .
                '</Row>';
            }
        }
    } else {
        $rows[] = '<Row>' . excelCellXml('No workload rows found for this filter.', 'EmptyState', 3) . '</Row>';
    }

    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
        '<?mso-application progid="Excel.Sheet"?>' . "\n" .
        '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" ' .
        'xmlns:o="urn:schemas-microsoft-com:office:office" ' .
        'xmlns:x="urn:schemas-microsoft-com:office:excel" ' .
        'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" ' .
        'xmlns:html="http://www.w3.org/TR/REC-html40">' .
            '<Styles>' .
                '<Style ss:ID="Default" ss:Name="Normal">' .
                    '<Alignment ss:Vertical="Center"/>' .
                    '<Borders/>' .
                    '<Font ss:FontName="Calibri" ss:Size="11" ss:Color="#000000"/>' .
                    '<Interior/>' .
                    '<NumberFormat/>' .
                    '<Protection/>' .
                '</Style>' .
                '<Style ss:ID="Title">' .
                    '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' .
                    '<Font ss:FontName="Calibri" ss:Size="15" ss:Bold="1"/>' .
                '</Style>' .
                '<Style ss:ID="Subtitle">' .
                    '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' .
                    '<Font ss:FontName="Calibri" ss:Size="12" ss:Bold="1"/>' .
                '</Style>' .
                '<Style ss:ID="Meta">' .
                    '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' .
                    '<Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1"/>' .
                '</Style>' .
                '<Style ss:ID="GroupTitle">' .
                    '<Alignment ss:Vertical="Center"/>' .
                    '<Borders>' .
                        '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                    '</Borders>' .
                    '<Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1"/>' .
                    '<Interior ss:Color="#EAF2FB" ss:Pattern="Solid"/>' .
                '</Style>' .
                '<Style ss:ID="Header">' .
                    '<Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' .
                    '<Borders>' .
                        '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                    '</Borders>' .
                    '<Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1"/>' .
                    '<Interior ss:Color="#F7F7F7" ss:Pattern="Solid"/>' .
                '</Style>' .
                '<Style ss:ID="CourseCode">' .
                    '<Alignment ss:Vertical="Center"/>' .
                    '<Borders>' .
                        '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                    '</Borders>' .
                    '<Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1"/>' .
                '</Style>' .
                '<Style ss:ID="CourseDesc">' .
                    '<Alignment ss:Vertical="Center" ss:WrapText="1"/>' .
                    '<Borders>' .
                        '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                    '</Borders>' .
                    '<Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1"/>' .
                '</Style>' .
                '<Style ss:ID="Body">' .
                    '<Alignment ss:Vertical="Center" ss:WrapText="1"/>' .
                    '<Borders>' .
                        '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                    '</Borders>' .
                '</Style>' .
                '<Style ss:ID="EmptyState">' .
                    '<Alignment ss:Horizontal="Center" ss:Vertical="Center"/>' .
                    '<Borders>' .
                        '<Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                        '<Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>' .
                    '</Borders>' .
                    '<Font ss:FontName="Calibri" ss:Size="11" ss:Italic="1"/>' .
                '</Style>' .
            '</Styles>' .
            '<Worksheet ss:Name="Faculty Workload">' .
                '<Table>' .
                    '<Column ss:AutoFitWidth="0" ss:Width="120"/>' .
                    '<Column ss:AutoFitWidth="0" ss:Width="110"/>' .
                    '<Column ss:AutoFitWidth="0" ss:Width="210"/>' .
                    '<Column ss:AutoFitWidth="0" ss:Width="150"/>' .
                    implode('', $rows) .
                '</Table>' .
                '<WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">' .
                    '<PageSetup>' .
                        '<Layout x:Orientation="Portrait"/>' .
                    '</PageSetup>' .
                    '<ProtectObjects>False</ProtectObjects>' .
                    '<ProtectScenarios>False</ProtectScenarios>' .
                '</WorksheetOptions>' .
            '</Worksheet>' .
        '</Workbook>';
}

function buildReportFilename(string $programLabel, string $ayLabel, string $semester, string $extension = 'xlsx'): string
{
    $parts = [
        'faculty_workload_report',
        $programLabel !== '' ? $programLabel : 'program',
        $ayLabel !== '' ? $ayLabel : 'ay',
        semesterLabel($semester)
    ];

    $filename = implode('_', $parts);
    $filename = preg_replace('/[^A-Za-z0-9]+/', '_', strtoupper($filename));
    $filename = trim((string)$filename, '_');

    $extension = preg_replace('/[^A-Za-z0-9]/', '', strtolower($extension));
    $extension = $extension !== '' ? $extension : 'xlsx';

    return ($filename !== '' ? $filename : 'FACULTY_WORKLOAD_REPORT') . '.' . $extension;
}

$campusLabel = 'CAMPUS';
if ($collegeId > 0) {
    $campusStmt = $conn->prepare("
        SELECT tc.campus_name
             , col.college_name
        FROM tbl_college col
        INNER JOIN tbl_campus tc ON tc.campus_id = col.campus_id
        WHERE col.college_id = ?
        LIMIT 1
    ");

    if ($campusStmt instanceof mysqli_stmt) {
        $campusStmt->bind_param("i", $collegeId);
        $campusStmt->execute();
        $campusRes = $campusStmt->get_result();
        $campusRow = $campusRes ? $campusRes->fetch_assoc() : null;

        if (is_array($campusRow)) {
            $campusLabel = normalizeCampusLabel($campusRow['campus_name'] ?? '');
            if ($collegeName === '') {
                $collegeName = trim((string)($campusRow['college_name'] ?? ''));
            }
        }

        $campusStmt->close();
    }
}

$ayLabel = '';
if ($ay_id !== '') {
    $ayStmt = $conn->prepare("
        SELECT ay
        FROM tbl_academic_years
        WHERE ay_id = ?
        LIMIT 1
    ");

    if ($ayStmt instanceof mysqli_stmt) {
        $ayIdInt = (int)$ay_id;
        $ayStmt->bind_param("i", $ayIdInt);
        $ayStmt->execute();
        $ayRes = $ayStmt->get_result();
        $ayRow = $ayRes ? $ayRes->fetch_assoc() : null;

        if (is_array($ayRow)) {
            $ayLabel = (string)($ayRow['ay'] ?? '');
        }

        $ayStmt->close();
    }
}

$programOptions = [];
if ($collegeId > 0) {
    $programQuery = "
        SELECT
            p.program_id,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS major
        FROM tbl_program p
        WHERE p.college_id = ?
        ORDER BY p.program_code ASC, p.program_name ASC, p.major ASC
    ";
    $programStmt = $conn->prepare($programQuery);

    if ($programStmt instanceof mysqli_stmt) {
        $programStmt->bind_param("i", $collegeId);
        $programStmt->execute();
        $programRes = $programStmt->get_result();

        while ($programRes && ($row = $programRes->fetch_assoc())) {
            $programOptions[] = $row;
        }

        $programStmt->close();
    }
}

$collegeReportLabel = synk_title_case_display($collegeName !== '' ? $collegeName : 'College');
$selectedProgramLabel = '';
$selectedReportLabel = '';
if ($isCollegeScope) {
    $selectedReportLabel = $collegeReportLabel !== '' ? $collegeReportLabel : 'Selected college';
} elseif ($program_id !== '') {
    foreach ($programOptions as $option) {
        if ((string)($option['program_id'] ?? '') !== $program_id) {
            continue;
        }

        $selectedProgramLabel = formatProgramDisplayLabel(
            $option['program_code'] ?? '',
            $option['program_name'] ?? '',
            $option['major'] ?? ''
        );
        $selectedReportLabel = $selectedProgramLabel;
        break;
    }
}

$reportScopeLabel = $isCollegeScope ? 'College' : 'Program';
$groupTitle = $isCollegeScope ? 'College Offerings' : 'Program Offerings';
$selectedFilterLabel = $selectedReportLabel !== ''
    ? $selectedReportLabel
    : ($isCollegeScope ? 'Selected college' : 'Selected program');

$hasFilters = $ay_id !== '' && $semester !== '' && ($isCollegeScope || $program_id !== '');
$reportWorkspaceStatus = ['label' => 'Current live workspace (not saved as a set)', 'scope_label' => '', 'state_key' => 'live', 'set_count' => 0];
if ($collegeId > 0 && $ay_id !== '' && $semester !== '') {
    $reportWorkspaceStatus = facultyReportResolveWorkspaceStatus($conn, $collegeId, (int)$ay_id, (int)$semester);
}
$courses = [];
$courseRowSignatures = [];

if ($hasFilters && $collegeId > 0) {
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $whereClauses = [
        'o.ay_id = ?',
        'o.semester = ?',
        'p.college_id = ?'
    ];
    $bindTypes = 'iii';
    $bindParams = [
        (int)$ay_id,
        (int)$semester,
        $collegeId
    ];

    if (!$isCollegeScope) {
        $whereClauses[] = 'o.program_id = ?';
        $bindTypes .= 'i';
        $bindParams[] = (int)$program_id;
    }

    $sql = "
        SELECT
            sm.sub_code,
            sm.sub_description,
            p.program_code,
            COALESCE(p.major, '') AS major,
            sec.section_name,
            sec.full_section,
            ps.lec_units,
            ps.lab_units,
            ps.total_units,
            o.offering_id
        FROM tbl_prospectus_offering o
        {$liveOfferingJoins}
        INNER JOIN tbl_program p ON p.program_id = o.program_id
        INNER JOIN tbl_subject_masterlist sm ON sm.sub_id = ps.sub_id
        WHERE " . implode("
          AND ", $whereClauses) . "
        ORDER BY sm.sub_code, p.program_code, sec.section_name
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt instanceof mysqli_stmt) {
        synk_bind_dynamic_params($stmt, $bindTypes, $bindParams);
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
                        'rows' => []
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
                        'room_name' => ''
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

$reportQuery = $hasFilters
    ? http_build_query([
        'scope' => $reportScope,
        'ay_id' => $ay_id,
        'semester' => $semester,
        'program_id' => $isCollegeScope ? null : $program_id
    ])
    : '';
$totalCourseCount = count($courses);
$totalScheduleEntryCount = 0;
$mergedScheduleEntryCount = 0;
foreach ($courses as $courseGroup) {
    foreach ((array)($courseGroup['rows'] ?? []) as $courseRow) {
        $totalScheduleEntryCount++;
        if (trim((string)($courseRow['report_merge_tag'] ?? '')) !== '') {
            $mergedScheduleEntryCount++;
        }
    }
}
$reportSourceLabel = trim((string)($reportWorkspaceStatus['label'] ?? 'Current live workspace'));
$reportSourceScopeLabel = trim((string)($reportWorkspaceStatus['scope_label'] ?? ''));
if ($reportSourceScopeLabel !== '') {
    $reportSourceLabel .= ' (' . $reportSourceScopeLabel . ')';
}
$mergeStatusLabel = $mergedScheduleEntryCount > 0
    ? $mergedScheduleEntryCount . ' merged schedule ' . ($mergedScheduleEntryCount === 1 ? 'entry' : 'entries') . ' shown in this live report'
    : 'No merged schedule entries in this live report';
$globalSignatorySettings = synk_fetch_signatory_settings($conn, 'global', 0);
$collegeSignatorySettings = $collegeId > 0 ? synk_fetch_signatory_settings($conn, 'college', $collegeId) : [];
$preparedBySignatory = normalizeReportSignatorySlot($collegeSignatorySettings, 'prepared_by', 'Prepared by');
$checkedByLeftSignatory = normalizeReportSignatorySlot($globalSignatorySettings, 'checked_by_left', 'Checked by (Left)');
$checkedByRightSignatory = normalizeReportSignatorySlot($globalSignatorySettings, 'checked_by_right', 'Checked by (Right)');
$recommendingApprovalSignatory = normalizeReportSignatorySlot($globalSignatorySettings, 'recommending_approval', 'Recommending Approval');
$approvedBySignatory = normalizeReportSignatorySlot($globalSignatorySettings, 'approved_by', 'Approved by');
$hasPrintableSignatorySheet =
    reportSignatoryHasContent($preparedBySignatory)
    || reportSignatoryHasContent($checkedByLeftSignatory)
    || reportSignatoryHasContent($checkedByRightSignatory)
    || reportSignatoryHasContent($recommendingApprovalSignatory)
    || reportSignatoryHasContent($approvedBySignatory);
$printReportUrl = $reportQuery !== '' ? ('?' . $reportQuery . '&print=1') : '';
$excelReportUrl = $reportQuery !== '' ? ('?' . $reportQuery . '&export=excel') : '';

if ($exportMode === 'excel' && $hasFilters) {
    $xlsxBinary = buildFacultyWorkloadXlsxBinary(
        $courses,
        $selectedReportLabel,
        $campusLabel,
        $semester,
        $ayLabel,
        $groupTitle,
        $reportSourceLabel,
        $mergeStatusLabel
    );

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    if ($xlsxBinary !== null) {
        $filename = buildReportFilename($selectedReportLabel, $ayLabel, $semester, 'xlsx');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($xlsxBinary));
        echo $xlsxBinary;
        exit;
    }

    $filename = buildReportFilename($selectedReportLabel, $ayLabel, $semester, 'xml');
    $excelWorkbook = buildFacultyWorkloadExcelWorkbook(
        $courses,
        $selectedReportLabel,
        $campusLabel,
        $semester,
        $ayLabel,
        $groupTitle,
        $reportSourceLabel,
        $mergeStatusLabel
    );
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo $excelWorkbook;
    exit;
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

  <title>Faculty Workload Report | Synk</title>

  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
  <link rel="stylesheet" href="../assets/vendor/css/core.css" />
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
  <link rel="stylesheet" href="../assets/css/demo.css" />
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
  <link rel="stylesheet" type="text/css" href="custom_css.css" />

  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>

  <style>
    .report-page-title {
      color: #344760;
    }

    .report-filter-card {
      border: 1px solid #e5ecf6;
      border-radius: 1rem;
      box-shadow: 0 18px 40px rgba(31, 45, 61, 0.06);
      background: linear-gradient(180deg, #fcfdff 0%, #f6f9fd 100%);
    }

    .report-filter-note {
      color: #74849a;
      font-size: 0.85rem;
    }

    .report-filter-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding-top: 0.45rem;
      border-top: 1px solid #e2ebf6;
      margin-top: 0.35rem;
    }

    .report-filter-meta {
      display: flex;
      flex-wrap: wrap;
      gap: 0.7rem;
      min-width: 0;
    }

    .report-filter-chip {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.68rem 0.95rem;
      border: 1px solid #d8e4f2;
      border-radius: 999px;
      background: rgba(255, 255, 255, 0.9);
      color: #52637a;
      font-size: 0.82rem;
      line-height: 1.2;
      box-shadow: 0 10px 18px rgba(52, 71, 96, 0.04);
    }

    .report-filter-chip i {
      font-size: 1rem;
      color: #696cff;
    }

    .report-filter-actions {
      display: flex;
      justify-content: flex-end;
      flex: 0 0 auto;
    }

    .report-action-badges {
      display: flex;
      flex-wrap: wrap;
      justify-content: flex-end;
      gap: 0.7rem;
    }

    .report-action-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      white-space: nowrap;
      padding: 0.72rem 1rem;
      border: 1px solid #d8e4f2;
      border-radius: 999px;
      font-weight: 600;
      font-size: 0.84rem;
      line-height: 1;
      text-decoration: none;
      box-shadow: 0 10px 18px rgba(52, 71, 96, 0.05);
      transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
    }

    .report-action-badge i {
      font-size: 1rem;
    }

    .report-action-badge:hover,
    .report-action-badge:focus {
      transform: translateY(-1px);
      text-decoration: none;
      box-shadow: 0 12px 24px rgba(52, 71, 96, 0.1);
    }

    .report-action-badge.is-print {
      background: #f4f5ff;
      border-color: #cfd4ff;
      color: #5d63ff;
    }

    .report-action-badge.is-excel {
      background: #f1fff4;
      border-color: #bfe8c9;
      color: #39a85a;
    }

    .report-action-badge.is-disabled {
      background: #f5f7fb;
      border-color: #dbe3ef;
      color: #93a1b5;
      cursor: not-allowed;
      pointer-events: none;
    }

    .report-overview-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 1rem;
    }

    .report-overview-card {
      padding: 1rem 1.1rem;
      border: 1px solid #e5ecf6;
      border-radius: 1rem;
      background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
      box-shadow: 0 16px 30px rgba(31, 45, 61, 0.05);
    }

    .report-overview-label {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      font-size: 0.78rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: #7a8aa3;
      margin-bottom: 0.45rem;
    }

    .report-overview-label i {
      color: #696cff;
      font-size: 1rem;
    }

    .report-overview-value {
      color: #32445d;
      font-size: 1rem;
      font-weight: 700;
      line-height: 1.35;
    }

    .report-overview-value.is-metric {
      font-size: 1.5rem;
      line-height: 1;
    }

    @media (max-width: 991.98px) {
      .report-filter-toolbar {
        align-items: stretch;
        flex-direction: column;
      }

      .report-filter-actions,
      .report-action-badges {
        width: 100%;
      }

      .report-action-badges {
        justify-content: flex-start;
      }
    }

    @media (max-width: 575.98px) {
      .report-filter-chip {
        width: 100%;
        justify-content: flex-start;
      }

      .report-action-badge {
        width: 100%;
        justify-content: center;
      }

      .report-overview-grid {
        grid-template-columns: 1fr;
      }
    }

    .report-panel {
      position: relative;
      border: 1px solid #e5ecf6;
      border-radius: 1rem;
      background: #fff;
      box-shadow: 0 18px 40px rgba(31, 45, 61, 0.06);
      overflow: hidden;
    }

    .report-panel-body {
      padding: 1.25rem;
    }

    .report-loading-overlay {
      position: absolute;
      inset: 0;
      z-index: 6;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
      background: rgba(248, 251, 255, 0.9);
      backdrop-filter: blur(4px);
    }

    .report-loading-overlay.is-visible {
      display: flex;
    }

    .report-loading-card {
      width: min(420px, 100%);
      padding: 1.3rem 1.4rem;
      border: 1px solid #d7e3f1;
      border-radius: 1.1rem;
      background: #fff;
      box-shadow: 0 20px 50px rgba(31, 45, 61, 0.14);
    }

    .report-loading-topline {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      margin-bottom: 0.85rem;
    }

    .report-loading-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.45rem;
      padding: 0.38rem 0.72rem;
      border-radius: 999px;
      background: #eef4ff;
      color: #3f5fb4;
      font-size: 0.75rem;
      font-weight: 700;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .report-loading-percent {
      font-size: 1.6rem;
      font-weight: 800;
      color: #2d4260;
      line-height: 1;
    }

    .report-loading-title {
      font-size: 1rem;
      font-weight: 700;
      color: #2f435f;
      margin-bottom: 0.2rem;
    }

    .report-loading-stage {
      color: #73839a;
      font-size: 0.86rem;
      margin-bottom: 0.9rem;
    }

    .report-progress-track {
      height: 0.72rem;
      border-radius: 999px;
      background: #e8eef8;
      overflow: hidden;
      margin-bottom: 0.75rem;
    }

    .report-progress-fill {
      width: 0%;
      height: 100%;
      border-radius: inherit;
      background: linear-gradient(90deg, #696cff 0%, #51b0ff 50%, #20c997 100%);
      transition: width 0.15s ease;
    }

    .report-loading-caption {
      color: #8391a6;
      font-size: 0.76rem;
    }

    .report-empty-state {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      min-height: 320px;
      padding: 2rem 1.5rem;
      text-align: center;
      color: #708198;
      background: linear-gradient(180deg, #ffffff 0%, #f9fbfe 100%);
    }

    .report-empty-state i {
      font-size: 2.3rem;
      color: #9ab0d0;
      margin-bottom: 0.85rem;
    }

    .print-area {
      background: #fff;
    }

    .print-header {
      display: none;
      gap: 14px;
      align-items: center;
      border-bottom: 2px solid #000;
      padding-bottom: 6px;
      margin-bottom: 8px;
    }

    .print-title {
      flex: 1;
      text-align: center;
      line-height: 1.08;
    }

    .print-title > div {
      margin: 0;
    }

    .print-title .uni {
      font-weight: 800;
      font-size: 17px;
      line-height: 1.05;
    }

    .print-title .main {
      font-weight: 900;
      margin-top: 3px;
      line-height: 1.05;
    }

    .print-title .program-line,
    .print-title .campus-line,
    .print-title .term-line,
    .print-title .source-line,
    .print-title .merge-line {
      margin-top: 2px;
    }

    .print-title .program-line {
      font-weight: 700;
      font-size: 13px;
      line-height: 1.08;
      text-transform: uppercase;
    }

    .print-title .source-line,
    .print-title .merge-line {
      font-size: 11px;
      line-height: 1.12;
      font-weight: 600;
      text-transform: none;
    }

    .group-title {
      background: #eaf2fb;
      font-weight: 800;
      text-transform: uppercase;
      border: 1px solid #000;
      border-bottom: none;
      padding: 6px 8px;
      color: #334760;
    }

    .report-print-sheet {
      width: 100%;
    }

    .report-table-wrap {
      overflow-x: auto;
    }

    table.report-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 12px;
    }

    table.report-table th,
    table.report-table td {
      border: 1px solid #000;
      padding: 6px 8px;
    }

    table.report-table th {
      background: #f7f7f7;
      text-align: center;
    }

    table.report-table thead {
      display: table-header-group;
    }

    table.report-table tfoot {
      display: table-footer-group;
    }

    .course-code {
      font-weight: 800;
      width: 140px;
    }

    .course-desc {
      font-weight: 700;
    }

    .course-desc-meta {
      color: #d97b7b;
      font-weight: 700;
    }

    .indent-row td {
      border-top: none;
    }

    .report-signatory-sheet {
      clear: both;
      margin-top: 10mm;
      padding-top: 0;
      color: #000;
      page-break-inside: avoid;
      break-inside: avoid-page;
    }

    .report-signatory-top-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }

    .report-signatory-top-table td {
      vertical-align: top;
      padding: 0;
    }

    .report-signatory-top-table .report-signatory-head-cell {
      font-size: 14px;
      font-weight: 700;
      line-height: 1.2;
      padding-bottom: 1.7rem;
    }

    .report-signatory-top-table .report-signatory-entry-cell {
      width: 33.333%;
      box-sizing: border-box;
      padding-right: 12mm;
    }

    .report-signatory-top-table .report-signatory-entry-cell:last-child {
      padding-right: 0;
    }

    .report-signatory-bottom-stack {
      display: grid;
      grid-template-columns: minmax(0, 1fr);
      gap: 14mm 0;
      margin-top: 14mm;
      max-width: calc((100% - 24mm) / 3);
    }

    .report-signatory-block,
    .report-signatory-entry {
      min-width: 0;
      break-inside: avoid;
      page-break-inside: avoid;
    }

    .report-signatory-label {
      margin-bottom: 1.7rem;
      font-size: 13px;
      font-weight: 700;
      line-height: 1.2;
    }

    .report-signatory-name {
      min-height: 1.15rem;
      font-size: 12px;
      font-weight: 800;
      line-height: 1.15;
      letter-spacing: -0.01em;
      text-transform: uppercase;
      white-space: nowrap;
    }

    .report-signatory-title {
      min-height: 1.1rem;
      margin-top: 0.18rem;
      font-size: 12px;
      line-height: 1.25;
      word-break: break-word;
      overflow-wrap: anywhere;
    }

    @media screen and (max-width: 767.98px) {
      .report-signatory-top-table,
      .report-signatory-top-table tbody,
      .report-signatory-top-table tr,
      .report-signatory-top-table td {
        display: block;
        width: 100%;
      }

      .report-signatory-top-table .report-signatory-head-cell {
        padding-bottom: 0.75rem;
      }

      .report-signatory-top-table .report-signatory-entry-cell {
        padding-right: 0;
        padding-bottom: 1.3rem;
      }

      .report-signatory-bottom-stack {
        max-width: 100%;
        margin-top: 1.5rem;
      }
    }

    .print-preview-mode {
      background: #eef2f7;
    }

    .print-preview-mode #layout-menu,
    .print-preview-mode #layout-navbar,
    .print-preview-mode .layout-overlay,
    .print-preview-mode .content-backdrop,
    .print-preview-mode footer,
    .print-preview-mode .footer,
    .print-preview-mode .navbar,
    .print-preview-mode .layout-menu-toggle,
    .print-preview-mode .menu-toggle,
    .print-preview-mode .no-print {
      display: none !important;
    }

    .print-preview-mode .layout-wrapper,
    .print-preview-mode .layout-container,
    .print-preview-mode .layout-page,
    .print-preview-mode .content-wrapper {
      display: block !important;
      width: 100% !important;
      max-width: 100% !important;
      margin: 0 !important;
      padding: 0 !important;
      background: transparent !important;
    }

    .print-preview-mode .container-xxl {
      max-width: 210mm !important;
      width: 210mm !important;
      margin: 0 auto !important;
      padding: 10mm 9mm !important;
      background: #fff !important;
    }

    .print-preview-mode .report-panel {
      border: 0 !important;
      border-radius: 0 !important;
      box-shadow: none !important;
      overflow: visible !important;
      background: #fff !important;
    }

    .print-preview-mode .report-panel-body {
      padding: 0 !important;
    }

    .print-preview-mode .print-header {
      display: flex !important;
    }

    .print-preview-mode .report-table-wrap {
      overflow: visible !important;
    }

    .print-preview-mode .report-signatory-sheet {
      margin-top: 8mm !important;
      padding-top: 0 !important;
      page-break-before: auto;
      break-before: auto;
    }

    @media print {
      html,
      body {
        background: #fff !important;
      }

      body {
        margin: 0 !important;
        padding: 0 !important;
      }

      .no-print {
        display: none !important;
      }

      .layout-menu,
      .layout-navbar,
      .layout-overlay,
      .content-backdrop,
      footer,
      .footer,
      .navbar,
      .menu-toggle {
        display: none !important;
      }

      .layout-wrapper,
      .layout-container,
      .layout-page,
      .content-wrapper,
      .container-xxl {
        display: block !important;
        width: 100% !important;
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
      }

      .report-panel {
        border: 0;
        box-shadow: none;
        border-radius: 0;
        overflow: visible;
      }

      .report-panel-body {
        padding: 0;
      }

      .report-print-sheet,
      .print-area,
      .report-table-wrap {
        width: 100% !important;
        max-width: 100% !important;
      }

      .print-header {
        display: flex !important;
        page-break-after: avoid;
        break-after: avoid-page;
      }

      .report-table-wrap {
        overflow: visible;
      }

      .report-signatory-sheet {
        margin-top: 8mm;
        padding-top: 0;
        page-break-before: auto;
        break-before: auto;
      }

      .report-signatory-top-table {
        display: table;
        width: 100%;
        table-layout: fixed;
      }

      .report-signatory-top-table tbody {
        display: table-row-group;
      }

      .report-signatory-top-table tr {
        display: table-row;
      }

      .report-signatory-top-table td {
        display: table-cell;
        width: auto;
      }

      .group-title {
        page-break-after: avoid;
        break-after: avoid-page;
      }

      table.report-table {
        page-break-inside: auto;
      }

      table.report-table tr,
      table.report-table td,
      table.report-table th {
        page-break-inside: avoid;
        break-inside: avoid;
      }

      @page {
        size: A4 portrait;
        margin: 10mm 9mm 10mm 9mm;
      }
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
              <h4 class="fw-bold mb-2 report-page-title">Faculty Workload Report</h4>
              <p class="report-filter-note mb-0">
                The report updates automatically when you change the scope, program, academic year, or semester.
              </p>
            </div>

            <div class="card report-filter-card no-print mb-4">
              <div class="card-body">
                <form method="GET" class="row g-3 align-items-end" id="facultyWorkloadFilterForm" novalidate>
                  <div class="col-lg-2 col-md-3">
                    <label class="form-label" for="scopeSelect">Scope</label>
                    <select name="scope" id="scopeSelect" class="form-select">
                      <option value="program" <?= $reportScope === 'program' ? 'selected' : '' ?>>Per Program</option>
                      <option value="college" <?= $reportScope === 'college' ? 'selected' : '' ?>>Whole College</option>
                    </select>
                  </div>

                  <div class="col-lg-5 col-md-6">
                    <label class="form-label" for="programSelect">Program</label>
                    <select
                      name="program_id"
                      id="programSelect"
                      class="form-select"
                      <?= $isCollegeScope ? 'disabled' : 'required' ?>
                    >
                      <option value="">Select program...</option>
                      <?php foreach ($programOptions as $option): ?>
                        <?php
                        $optionProgramLabel = formatProgramDisplayLabel(
                            $option['program_code'] ?? '',
                            $option['program_name'] ?? '',
                            $option['major'] ?? ''
                        );
                        ?>
                        <option
                          value="<?= h($option['program_id']) ?>"
                          <?= $program_id === (string)$option['program_id'] ? 'selected' : '' ?>
                        >
                          <?= h($optionProgramLabel) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="col-lg-3 col-md-3">
                    <label class="form-label" for="aySelect">Academic Year</label>
                    <select name="ay_id" id="aySelect" class="form-select" required>
                      <option value="">Select year...</option>
                      <?php
                      $ayQ = $conn->query("SELECT ay_id, ay FROM tbl_academic_years ORDER BY ay DESC");
                      while ($ayRow = $ayQ ? $ayQ->fetch_assoc() : null):
                        if (!$ayRow) {
                            break;
                        }
                      ?>
                        <option value="<?= h($ayRow['ay_id']) ?>" <?= $ay_id === (string)$ayRow['ay_id'] ? 'selected' : '' ?>>
                          <?= h($ayRow['ay']) ?>
                        </option>
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

                  <div class="col-12">
                    <div class="report-filter-toolbar">
                      <div class="report-filter-meta">
                        <?php if ($hasFilters): ?>
                          <div class="report-filter-chip">
                            <i class="bx bx-collection"></i>
                            <span><?= h($selectedFilterLabel) ?></span>
                          </div>
                          <div class="report-filter-chip">
                            <i class="bx bx-calendar"></i>
                            <span>AY <?= h($ayLabel !== '' ? $ayLabel : '-') ?> · <?= h(ucwords(strtolower(semesterLabel($semester)))) ?></span>
                          </div>
                          <div class="report-filter-chip">
                            <i class="bx bx-buildings"></i>
                            <span><?= h($campusLabel) ?></span>
                          </div>
                          <div class="report-filter-chip">
                            <i class="bx bx-save"></i>
                            <span><?= h($reportSourceLabel) ?></span>
                          </div>
                          <div class="report-filter-chip">
                            <i class="bx bx-git-merge"></i>
                            <span><?= h($mergeStatusLabel) ?></span>
                          </div>
                        <?php else: ?>
                          <div class="report-filter-chip">
                            <i class="bx bx-info-circle"></i>
                            <span>Choose a program, or switch to whole college, to enable print and Excel export.</span>
                          </div>
                        <?php endif; ?>
                      </div>

                      <div class="report-filter-actions">
                        <?php if ($hasFilters): ?>
                          <div class="report-action-badges" aria-label="Report actions">
                            <a
                              class="report-action-badge is-print"
                              href="<?= h($printReportUrl) ?>"
                              target="_blank"
                              rel="noopener"
                            >
                              <i class="bx bx-printer"></i>
                              <span>Print View</span>
                            </a>
                            <a
                              class="report-action-badge is-excel"
                              href="<?= h($excelReportUrl) ?>"
                            >
                              <i class="bx bx-download"></i>
                              <span>Download Excel</span>
                            </a>
                          </div>
                        <?php else: ?>
                          <div class="report-action-badges" aria-label="Report actions disabled">
                            <span class="report-action-badge is-disabled">
                              <i class="bx bx-printer"></i>
                              <span>Print View</span>
                            </span>
                            <span class="report-action-badge is-disabled">
                              <i class="bx bx-download"></i>
                              <span>Download Excel</span>
                            </span>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </form>
              </div>
            </div>

            <?php if ($hasFilters): ?>
              <div class="report-overview-grid no-print mb-4">
                <div class="report-overview-card">
                  <div class="report-overview-label">
                    <i class="bx bx-book-content"></i>
                    <span><?= h($reportScopeLabel) ?></span>
                  </div>
                  <div class="report-overview-value"><?= h($selectedFilterLabel) ?></div>
                </div>
                <div class="report-overview-card">
                  <div class="report-overview-label">
                    <i class="bx bx-layer"></i>
                    <span>Course Codes</span>
                  </div>
                  <div class="report-overview-value is-metric"><?= h((string)$totalCourseCount) ?></div>
                </div>
                <div class="report-overview-card">
                  <div class="report-overview-label">
                    <i class="bx bx-spreadsheet"></i>
                    <span>Schedule Entries</span>
                  </div>
                  <div class="report-overview-value is-metric"><?= h((string)$totalScheduleEntryCount) ?></div>
                </div>
                <div class="report-overview-card">
                  <div class="report-overview-label">
                    <i class="bx bx-calendar-star"></i>
                    <span>Reporting Term</span>
                  </div>
                  <div class="report-overview-value"><?= h(ucwords(strtolower(semesterLabel($semester)))) ?>, AY <?= h($ayLabel !== '' ? $ayLabel : '-') ?></div>
                </div>
                <div class="report-overview-card">
                  <div class="report-overview-label">
                    <i class="bx bx-save"></i>
                    <span>Live Source</span>
                  </div>
                  <div class="report-overview-value"><?= h($reportSourceLabel) ?></div>
                </div>
                <div class="report-overview-card">
                  <div class="report-overview-label">
                    <i class="bx bx-git-merge"></i>
                    <span>Merge Handling</span>
                  </div>
                  <div class="report-overview-value"><?= h($mergeStatusLabel) ?></div>
                </div>
              </div>
            <?php endif; ?>

            <div class="report-panel" id="facultyReportPanel">
              <div id="reportLoadingOverlay" class="report-loading-overlay" aria-hidden="true">
                <div class="report-loading-card">
                  <div class="report-loading-topline">
                    <div class="report-loading-badge">
                      <span class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></span>
                      <span>Loading</span>
                    </div>
                    <div class="report-loading-percent" id="reportLoadingPercent">0%</div>
                  </div>
                  <div class="report-loading-title">Preparing faculty workload report</div>
                  <div class="report-loading-stage" id="reportLoadingStage">Checking selected filters...</div>
                  <div class="report-progress-track">
                    <div class="report-progress-fill" id="reportLoadingBar"></div>
                  </div>
                  <div class="report-loading-caption">The report updates automatically after every filter change.</div>
                </div>
              </div>

              <div class="report-panel-body">
                <?php if (!$hasFilters): ?>
                  <div class="report-empty-state">
                    <i class="bx bx-filter-alt"></i>
                    <h5 class="mb-2">Select report filters to load the report</h5>
                    <p class="mb-0">
                      Program scope loads the full selected program term. Whole college scope loads all programs in this college for the selected term.
                    </p>
                  </div>
                <?php else: ?>
                  <div class="print-area report-print-sheet">
                    <div class="print-header">
                      <div class="print-title">
                        <div class="uni">SULTAN KUDARAT STATE UNIVERSITY</div>
                        <div class="main">ALPHABETICAL LIST OF COURSES</div>
                        <?php if ($selectedReportLabel !== ''): ?>
                          <div class="program-line"><?= h($selectedReportLabel) ?></div>
                        <?php endif; ?>
                        <div class="campus-line"><?= h($campusLabel) ?></div>
                        <div class="term-line"><?= h(semesterLabel($semester)) ?>, AY <?= h($ayLabel) ?></div>
                        <div class="source-line">Source: <?= h($reportSourceLabel) ?></div>
                        <div class="merge-line">Merge handling: <?= h($mergeStatusLabel) ?></div>
                      </div>
                    </div>

                    <div class="group-title"><?= h($groupTitle) ?></div>

                    <?php if (!empty($courses)): ?>
                      <div class="report-table-wrap">
                        <table class="report-table">
                          <thead>
                            <tr>
                              <th>Course Code</th>
                              <th>Section</th>
                              <th>Class Schedule</th>
                              <th>Room</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($courses as $code => $data): ?>
                              <?php
                              $firstRow = (array)(($data['rows'][0] ?? []));
                              $descriptionLabel = buildSubjectDescriptionLabel($firstRow, (string)($data['desc'] ?? ''));
                              $summarySuffix = '';
                              $baseDescription = (string)($data['desc'] ?? '');
                              if ($baseDescription !== '' && strpos($descriptionLabel, $baseDescription . ', ') === 0) {
                                  $summarySuffix = substr($descriptionLabel, strlen($baseDescription));
                              }
                              ?>
                              <tr>
                                <td class="course-code"><?= h($code) ?></td>
                                <td colspan="3" class="course-desc">
                                  <?= h($baseDescription !== '' ? $baseDescription : $descriptionLabel) ?><?php if ($summarySuffix !== ''): ?><span class="course-desc-meta"><?= h($summarySuffix) ?></span><?php endif; ?>
                                </td>
                              </tr>
                              <?php foreach ($data['rows'] as $row): ?>
                                <?php
                                $display = buildScheduleDisplayParts((array)$row);
                                $sectionLabel = buildSectionDisplayLabel((array)$row);
                                ?>
                                <tr class="indent-row">
                                  <td></td>
                                  <td><?= h($sectionLabel) ?></td>
                                  <td><?= h($display['schedule']) ?></td>
                                  <td><?= h($display['room']) ?></td>
                                </tr>
                              <?php endforeach; ?>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php else: ?>
                      <div class="report-empty-state" style="min-height: 260px;">
                        <i class="bx bx-folder-open"></i>
                        <h5 class="mb-2">No workload rows found for this filter</h5>
                        <p class="mb-0">
                          Try another program, academic year, or semester to view available schedule data.
                        </p>
                      </div>
                    <?php endif; ?>

                    <?php if ($doPrint && $hasPrintableSignatorySheet): ?>
                      <div class="report-signatory-sheet">
                        <table class="report-signatory-top-table" aria-hidden="true">
                          <tbody>
                            <tr>
                              <td class="report-signatory-head-cell">Prepared by:</td>
                              <td class="report-signatory-head-cell" colspan="2">Checked by:</td>
                            </tr>
                            <tr>
                              <td class="report-signatory-entry-cell">
                                <div class="report-signatory-name<?= $preparedBySignatory['signatory_name'] === '' ? ' is-empty' : '' ?>">
                                  <?= $preparedBySignatory['signatory_name'] !== '' ? h(reportSignatoryUppercase($preparedBySignatory['signatory_name'])) : '&nbsp;' ?>
                                </div>
                                <div class="report-signatory-title<?= $preparedBySignatory['signatory_title'] === '' ? ' is-empty' : '' ?>">
                                  <?= $preparedBySignatory['signatory_title'] !== '' ? h($preparedBySignatory['signatory_title']) : '&nbsp;' ?>
                                </div>
                              </td>
                              <td class="report-signatory-entry-cell">
                                <div class="report-signatory-name<?= $checkedByLeftSignatory['signatory_name'] === '' ? ' is-empty' : '' ?>">
                                  <?= $checkedByLeftSignatory['signatory_name'] !== '' ? h(reportSignatoryUppercase($checkedByLeftSignatory['signatory_name'])) : '&nbsp;' ?>
                                </div>
                                <div class="report-signatory-title<?= $checkedByLeftSignatory['signatory_title'] === '' ? ' is-empty' : '' ?>">
                                  <?= $checkedByLeftSignatory['signatory_title'] !== '' ? h($checkedByLeftSignatory['signatory_title']) : '&nbsp;' ?>
                                </div>
                              </td>
                              <td class="report-signatory-entry-cell">
                                <div class="report-signatory-name<?= $checkedByRightSignatory['signatory_name'] === '' ? ' is-empty' : '' ?>">
                                  <?= $checkedByRightSignatory['signatory_name'] !== '' ? h(reportSignatoryUppercase($checkedByRightSignatory['signatory_name'])) : '&nbsp;' ?>
                                </div>
                                <div class="report-signatory-title<?= $checkedByRightSignatory['signatory_title'] === '' ? ' is-empty' : '' ?>">
                                  <?= $checkedByRightSignatory['signatory_title'] !== '' ? h($checkedByRightSignatory['signatory_title']) : '&nbsp;' ?>
                                </div>
                              </td>
                            </tr>
                          </tbody>
                        </table>

                        <div class="report-signatory-bottom-stack">
                          <div class="report-signatory-block">
                            <div class="report-signatory-label">Recommending Approval:</div>
                            <div class="report-signatory-name<?= $recommendingApprovalSignatory['signatory_name'] === '' ? ' is-empty' : '' ?>">
                              <?= $recommendingApprovalSignatory['signatory_name'] !== '' ? h(reportSignatoryUppercase($recommendingApprovalSignatory['signatory_name'])) : '&nbsp;' ?>
                            </div>
                            <div class="report-signatory-title<?= $recommendingApprovalSignatory['signatory_title'] === '' ? ' is-empty' : '' ?>">
                              <?= $recommendingApprovalSignatory['signatory_title'] !== '' ? h($recommendingApprovalSignatory['signatory_title']) : '&nbsp;' ?>
                            </div>
                          </div>

                          <div class="report-signatory-block">
                            <div class="report-signatory-label">Approved by:</div>
                            <div class="report-signatory-name<?= $approvedBySignatory['signatory_name'] === '' ? ' is-empty' : '' ?>">
                              <?= $approvedBySignatory['signatory_name'] !== '' ? h(reportSignatoryUppercase($approvedBySignatory['signatory_name'])) : '&nbsp;' ?>
                            </div>
                            <div class="report-signatory-title<?= $approvedBySignatory['signatory_title'] === '' ? ' is-empty' : '' ?>">
                              <?= $approvedBySignatory['signatory_title'] !== '' ? h($approvedBySignatory['signatory_title']) : '&nbsp;' ?>
                            </div>
                          </div>
                        </div>
                      </div>
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

  <script>
    document.addEventListener('DOMContentLoaded', function () {
      var form = document.getElementById('facultyWorkloadFilterForm');
      var overlay = document.getElementById('reportLoadingOverlay');
      var loadingPercent = document.getElementById('reportLoadingPercent');
      var loadingBar = document.getElementById('reportLoadingBar');
      var loadingStage = document.getElementById('reportLoadingStage');
      var progressTimer = null;
      var navigateTimer = null;
      var progressValue = 0;
      var isNavigating = false;

      function setLoaderProgress(nextValue) {
        progressValue = Math.max(0, Math.min(99, nextValue));

        if (loadingPercent) {
          loadingPercent.textContent = progressValue + '%';
        }

        if (loadingBar) {
          loadingBar.style.width = progressValue + '%';
        }

        if (!loadingStage) {
          return;
        }

        if (progressValue < 20) {
          loadingStage.textContent = 'Checking selected filters...';
        } else if (progressValue < 45) {
          loadingStage.textContent = 'Loading program offerings...';
        } else if (progressValue < 70) {
          loadingStage.textContent = 'Collecting section schedules and rooms...';
        } else if (progressValue < 90) {
          loadingStage.textContent = 'Building the printable report view...';
        } else {
          loadingStage.textContent = 'Finalizing report...';
        }
      }

      function stopLoaderProgress() {
        if (progressTimer) {
          window.clearInterval(progressTimer);
          progressTimer = null;
        }
      }

      function stopPendingNavigation() {
        if (navigateTimer) {
          window.clearTimeout(navigateTimer);
          navigateTimer = null;
        }
      }

      function hideLoader() {
        stopLoaderProgress();

        if (overlay) {
          overlay.classList.remove('is-visible');
          overlay.setAttribute('aria-hidden', 'true');
        }

        setLoaderProgress(0);
      }

      function showLoader() {
        if (!overlay) {
          return;
        }

        overlay.classList.add('is-visible');
        overlay.setAttribute('aria-hidden', 'false');
        setLoaderProgress(6);
        stopLoaderProgress();

        progressTimer = window.setInterval(function () {
          if (progressValue >= 94) {
            stopLoaderProgress();
            return;
          }

          var increment = progressValue < 35 ? 6 : (progressValue < 70 ? 4 : 1);
          setLoaderProgress(progressValue + increment);
        }, 140);
      }

      function formHasCompleteFilters() {
        if (!form) {
          return false;
        }

        var scope = form.elements['scope'] ? form.elements['scope'].value.trim() : 'program';
        var program = form.elements['program_id'] ? form.elements['program_id'].value.trim() : '';
        var ay = form.elements['ay_id'] ? form.elements['ay_id'].value.trim() : '';
        var sem = form.elements['semester'] ? form.elements['semester'].value.trim() : '';

        return ay !== '' && sem !== '' && (scope === 'college' || program !== '');
      }

      function syncScopeFieldState() {
        if (!form) {
          return;
        }

        var scope = form.elements['scope'] ? form.elements['scope'].value.trim() : 'program';
        var programField = form.elements['program_id'];

        if (!programField) {
          return;
        }

        var needsProgram = scope !== 'college';
        programField.disabled = !needsProgram;
        programField.required = needsProgram;
      }

      function navigateWithFilters() {
        if (!form || isNavigating || !formHasCompleteFilters()) {
          return;
        }

        var params = new URLSearchParams(new FormData(form));
        params.delete('print');
        params.delete('export');

        if (form.elements['scope'] && form.elements['scope'].value.trim() === 'college') {
          params.delete('program_id');
        }

        var nextSearch = params.toString();
        var currentSearch = window.location.search.replace(/^\?/, '');

        if (nextSearch === currentSearch) {
          return;
        }

        isNavigating = true;
        showLoader();
        window.location.assign(window.location.pathname + '?' + nextSearch);
      }

      if (form) {
        syncScopeFieldState();

        form.addEventListener('submit', function (event) {
          event.preventDefault();
          stopPendingNavigation();
          syncScopeFieldState();
          navigateWithFilters();
        });

        Array.prototype.forEach.call(form.querySelectorAll('select'), function (select) {
          select.addEventListener('change', function () {
            syncScopeFieldState();
            stopPendingNavigation();
            navigateTimer = window.setTimeout(function () {
              navigateWithFilters();
            }, 220);
          });
        });
      }

      window.addEventListener('pageshow', function () {
        isNavigating = false;
        stopPendingNavigation();
        hideLoader();
      });

      hideLoader();
    });
  </script>

  <?php if ($doPrint && $hasFilters): ?>
    <script>
      window.addEventListener('load', function () {
        window.print();
      });
    </script>
  <?php endif; ?>
</body>
</html>
