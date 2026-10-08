<?php
session_start();
include 'db.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/schedule_block_helper.php';
require_once __DIR__ . '/schedule_tba_helper.php';
require_once __DIR__ . '/schedule_merge_helper.php';
require_once __DIR__ . '/faculty_need_helper.php';

header('Content-Type: application/json');

/* =====================================================
   SECURITY
===================================================== */
if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

/* =====================================================
   COLLEGE CONTEXT (SCHEDULER SCOPE)
===================================================== */
if (!isset($_SESSION['college_id'])) {
    echo json_encode([]);
    exit;
}

$college_id = (int)$_SESSION['college_id'];

/* =====================================================
   INPUTS
===================================================== */
$faculty_id = (int)($_POST['faculty_id'] ?? 0);
$ay_id = (int)($_POST['ay_id'] ?? 0);
$semester = (int)($_POST['semester_num'] ?? 0);
$ay_raw = trim($_POST['ay'] ?? '');
$semester_raw = trim($_POST['semester'] ?? '');

/* =====================================================
   SEMESTER MAP (UI -> DB)
===================================================== */
$semester_map = [
    '1st' => 1,
    '2nd' => 2,
    'Midyear' => 3
];

if ($semester <= 0) {
    if ($semester_raw === '' || !isset($semester_map[$semester_raw])) {
        echo json_encode([]);
        exit;
    }

    $semester = $semester_map[$semester_raw];
}

/* =====================================================
   RESOLVE AY -> ay_id
===================================================== */
if ($ay_id <= 0) {
    if ($ay_raw === '') {
        echo json_encode([]);
        exit;
    }

    $ayStmt = $conn->prepare("
        SELECT ay_id
        FROM tbl_academic_years
        WHERE ay = ?
        LIMIT 1
    ");
    $ayStmt->bind_param("s", $ay_raw);
    $ayStmt->execute();
    $ayRes = $ayStmt->get_result();

    if ($ayRes->num_rows === 0) {
        $ayStmt->close();
        echo json_encode([]);
        exit;
    }

    $ay_id = (int)$ayRes->fetch_assoc()['ay_id'];
    $ayStmt->close();
}

function class_schedule_loader_context_key_from_values(int $groupId, int $scheduleId, int $offeringId): string
{
    if ($groupId > 0) {
        return 'group:' . $groupId;
    }

    if ($scheduleId > 0) {
        return 'schedule:' . $scheduleId;
    }

    return 'offering:' . $offeringId;
}

function class_schedule_loader_merge_note(array $scopeDisplay): string
{
    if (empty($scopeDisplay['is_merged'])) {
        return '';
    }

    $targetLabel = trim((string)($scopeDisplay['member_label'] ?? ''));
    if ($targetLabel === '') {
        $targetLabel = trim((string)($scopeDisplay['group_label'] ?? ''));
    }

    if (($scopeDisplay['mode'] ?? 'local') === 'full') {
        return $targetLabel !== '' ? ('Merged subject with ' . $targetLabel) : 'Merged subject';
    }

    $scopeLabel = strtoupper(trim((string)($scopeDisplay['scope'] ?? 'LEC'))) === 'LAB'
        ? 'Merged laboratory'
        : 'Merged lecture';

    return $targetLabel !== '' ? ($scopeLabel . ' with ' . $targetLabel) : $scopeLabel;
}

/* =====================================================
   MAIN QUERY
   PURPOSE:
   - Load scheduled class entries from the live synced offering set
   - Exclude schedule rows that already exist in faculty workload
===================================================== */
$liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
$classScheduleHasGroupId = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_group_id');
$classScheduleHasType = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type');
synk_faculty_need_ensure_tables($conn);

$selectParts = [
    'cs.schedule_id',
    'cs.schedule_status',
    'o.offering_id',
    $classScheduleHasGroupId ? 'cs.schedule_group_id' : 'NULL AS schedule_group_id',
    $classScheduleHasType ? 'cs.schedule_type' : "'LEC' AS schedule_type",
    'sm.sub_code',
    'sm.sub_description',
    "COALESCE(p.major, '') AS program_major",
    'sec.section_name',
    'sec.full_section',
    'cs.days_json',
    'cs.time_start',
    'cs.time_end',
    'r.room_code',
    'ps.total_units AS total_units',
    'ps.lec_units AS lec_units',
    'ps.lab_units AS lab_units'
];

$joinParts = [
    'FROM tbl_class_schedule cs',
    'JOIN tbl_prospectus_offering o',
    '    ON o.offering_id = cs.offering_id',
    trim($liveOfferingJoins),
    'JOIN tbl_program p',
    '    ON p.program_id = o.program_id',
    'JOIN tbl_subject_masterlist sm',
    '    ON sm.sub_id = ps.sub_id',
    'LEFT JOIN tbl_rooms r',
    '    ON r.room_id = cs.room_id',
    'LEFT JOIN tbl_faculty_workload_sched assigned_fw',
    '    ON assigned_fw.schedule_id = cs.schedule_id',
    '   AND assigned_fw.ay_id = ?',
    '   AND assigned_fw.semester = ?',
    'LEFT JOIN `' . synk_faculty_need_workload_table_name() . '` assigned_need',
    '    ON assigned_need.schedule_id = cs.schedule_id',
    '   AND assigned_need.ay_id = ?',
    '   AND assigned_need.semester = ?'
];

$sql = "
SELECT
    " . implode(",\n    ", $selectParts) . "
" . implode("\n", $joinParts) . "
WHERE
    o.ay_id = ?
AND o.semester = ?
AND p.college_id = ?
AND assigned_fw.schedule_id IS NULL
AND assigned_need.schedule_id IS NULL
ORDER BY
    sec.section_name,
    sm.sub_code,
    " . ($classScheduleHasType ? "FIELD(cs.schedule_type, 'LEC', 'LAB')," : '') . "
    cs.time_start
";

$stmt = $conn->prepare($sql);
$bindTypes = 'iiii';
$bindParams = [$ay_id, $semester, $ay_id, $semester];

$bindTypes .= 'iii';
$bindParams[] = $ay_id;
$bindParams[] = $semester;
$bindParams[] = $college_id;

synk_bind_dynamic_params($stmt, $bindTypes, $bindParams);
$stmt->execute();
$res = $stmt->get_result();

/* =====================================================
   FORMAT OUTPUT
===================================================== */
$rawRows = [];
$offeringIds = [];

while ($row = $res->fetch_assoc()) {
    $days_arr = json_decode((string)($row['days_json'] ?? '[]'), true);
    if (!is_array($days_arr)) {
        $days_arr = [];
    }

    $rawRows[] = [
        'schedule_id' => (int)($row['schedule_id'] ?? 0),
        'schedule_status' => synk_schedule_status($row),
        'offering_id' => (int)($row['offering_id'] ?? 0),
        'group_id' => (int)($row['schedule_group_id'] ?? 0),
        'schedule_type' => strtoupper(trim((string)($row['schedule_type'] ?? 'LEC'))),
        'subject_code' => (string)($row['sub_code'] ?? ''),
        'subject_description' => (string)($row['sub_description'] ?? ''),
        'program_major' => trim((string)($row['program_major'] ?? '')),
        'section_name' => (string)($row['section_name'] ?? ''),
        'full_section' => (string)($row['full_section'] ?? ''),
        'days_arr' => $days_arr,
        'time_start' => (string)($row['time_start'] ?? ''),
        'time_end' => (string)($row['time_end'] ?? ''),
        'room_code' => trim((string)($row['room_code'] ?? '')) !== '' ? (string)$row['room_code'] : 'TBA',
        'lec_units' => (float)($row['lec_units'] ?? 0),
        'lab_units' => (float)($row['lab_units'] ?? 0),
        'total_units' => (float)($row['total_units'] ?? 0)
    ];

    $offeringIds[(int)($row['offering_id'] ?? 0)] = true;
}

$mergeContext = synk_schedule_merge_load_display_context($conn, array_keys($offeringIds));
$mergeSectionLookupIds = array_keys($offeringIds);
foreach ($mergeContext as $info) {
    foreach ((array)($info['group_offering_ids'] ?? []) as $groupOfferingId) {
        $mergeSectionLookupIds[] = (int)$groupOfferingId;
    }

    $ownedMemberIdsByScope = (array)($info['owned_member_ids_by_scope'] ?? []);
    foreach (['LEC', 'LAB'] as $scope) {
        foreach ((array)($ownedMemberIdsByScope[$scope] ?? []) as $groupOfferingId) {
            $mergeSectionLookupIds[] = (int)$groupOfferingId;
        }
    }
}
$mergeSectionLookupIds = synk_schedule_merge_normalize_offering_ids($mergeSectionLookupIds);
$sectionRowsByOffering = synk_schedule_merge_load_section_rows_by_offering($conn, $mergeSectionLookupIds);

$data = [];
$contextTotals = [];
if (!empty($offeringIds)) {
    $offeringIdList = implode(',', array_map('intval', array_keys($offeringIds)));
    $contextSelectParts = [
        'cs.schedule_id',
    'cs.schedule_status',
        'cs.offering_id',
        $classScheduleHasGroupId ? 'cs.schedule_group_id AS group_id' : 'NULL AS group_id',
        $classScheduleHasType ? 'cs.schedule_type AS type' : "'LEC' AS type"
    ];
    $contextSql = "
        SELECT
            " . implode(",\n            ", $contextSelectParts) . "
        FROM tbl_class_schedule cs
        WHERE cs.offering_id IN ({$offeringIdList})
    ";

    $contextRes = $conn->query($contextSql);
    if ($contextRes instanceof mysqli_result) {
        while ($contextRow = $contextRes->fetch_assoc()) {
            $contextKey = class_schedule_loader_context_key_from_values(
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

foreach ($rawRows as $row) {
    $subjectUnits = synk_subject_units_total(
        (float)($row['lec_units'] ?? 0),
        (float)($row['lab_units'] ?? 0),
        (float)($row['total_units'] ?? 0)
    );
    $contextKey = class_schedule_loader_context_key_from_values(
        (int)($row['group_id'] ?? 0),
        (int)($row['schedule_id'] ?? 0),
        (int)($row['offering_id'] ?? 0)
    );
    $metrics = synk_schedule_row_display_metrics(
        (string)($row['schedule_type'] ?? 'LEC'),
        (float)($row['lec_units'] ?? 0),
        (float)($row['lab_units'] ?? 0),
        (float)($row['total_units'] ?? 0),
        $contextTotals[$contextKey] ?? []
    );
    $mergeInfo = $mergeContext[(int)($row['offering_id'] ?? 0)] ?? null;
    $scopeDisplay = synk_schedule_merge_scope_display_context(
        (array)$mergeInfo,
        (string)($row['schedule_type'] ?? 'LEC'),
        (int)($row['offering_id'] ?? 0),
        $sectionRowsByOffering
    );
    $sectionDisplay = trim((string)($row['full_section'] ?? ''));
    if ($sectionDisplay === '') {
        $sectionDisplay = trim((string)($row['section_name'] ?? ''));
    }
    $mergedCourseLabel = '';
    $mergeNote = class_schedule_loader_merge_note($scopeDisplay);

    if (!empty($scopeDisplay['is_merged'])) {
        $mergedCourseLabel = trim((string)($scopeDisplay['group_label'] ?? ''));
        if ($mergedCourseLabel !== '') {
            $sectionDisplay = $mergedCourseLabel;
        }
    }

    if ($sectionDisplay === '') {
        $sectionDisplay = trim((string)($row['section_name'] ?? ''));
    }

    if ($sectionDisplay === '' && isset($sectionRowsByOffering[(int)($row['offering_id'] ?? 0)])) {
        $sectionDisplay = trim((string)($sectionRowsByOffering[(int)($row['offering_id'] ?? 0)]['full_section'] ?? ''));
    }

    if ($sectionDisplay === '') {
        $sectionDisplay = 'Section ' . (int)($row['offering_id'] ?? 0);
    }

    if ($mergeNote === '' && !empty($scopeDisplay['is_merged'])) {
        $mergeNote = ($scopeDisplay['mode'] ?? 'local') === 'full'
            ? 'Merged subject'
            : (strtoupper(trim((string)($scopeDisplay['scope'] ?? 'LEC'))) === 'LAB' ? 'Merged laboratory' : 'Merged lecture');
    }

    $data[] = [
        'schedule_id' => (int)($row['schedule_id'] ?? 0),
        'schedule_status' => synk_schedule_status($row),
        'offering_id' => (int)($row['offering_id'] ?? 0),
        'group_id' => (int)($row['group_id'] ?? 0),
        'schedule_type' => (string)($row['schedule_type'] ?? 'LEC'),
        'subject_code' => (string)($row['subject_code'] ?? ''),
        'subject_description' => (string)($row['subject_description'] ?? ''),
        'program_major' => (string)($row['program_major'] ?? ''),
        'section_name' => (string)($row['section_name'] ?? ''),
        'section_label' => $sectionDisplay,
        'full_section' => (string)($row['full_section'] ?? ''),
        'merged_course_label' => $mergedCourseLabel,
        'merge_note' => $mergeNote,
        'is_scope_merged' => !empty($scopeDisplay['is_merged']),
        'merge_scope_mode' => (string)($scopeDisplay['mode'] ?? 'local'),
        'days_arr' => array_values((array)($row['days_arr'] ?? [])),
        'time_start' => (string)($row['time_start'] ?? ''),
        'time_end' => (string)($row['time_end'] ?? ''),
        'days' => empty($row['days_arr']) ? 'TBA' : implode(", ", $row['days_arr']),
        'time' => synk_schedule_time_label($row['time_start'] ?? '', $row['time_end'] ?? ''),
        'room_code' => trim((string)($row['room_code'] ?? '')) !== '' ? (string)$row['room_code'] : 'TBA',
        'lec_units' => round((float)($row['lec_units'] ?? 0), 2),
        'lab_units' => round((float)($row['lab_units'] ?? 0), 2),
        'subject_units' => round($subjectUnits, 2),
        'units' => round((float)($metrics['units'] ?? 0), 2),
        'hours_lec' => round((float)($metrics['lec'] ?? 0), 2),
        'hours_lab' => round((float)($metrics['lab'] ?? 0), 2),
        'faculty_load' => round((float)($metrics['faculty_load'] ?? 0), 2),
        'weekly_minutes' => 0,
        'coverage_ratio' => 0
    ];
}

echo json_encode($data);
exit;
