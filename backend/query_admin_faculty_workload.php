<?php
require_once __DIR__ . '/schedule_tba_helper.php';
session_start();

include 'db.php';
require_once __DIR__.'/scheduler_request_guard.php';
synk_scheduler_guard_request($conn, 'query_admin_faculty_workload.php');
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/offering_enrollee_helper.php';
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/schedule_block_helper.php';
require_once __DIR__ . '/schedule_merge_helper.php';
require_once __DIR__ . '/faculty_need_helper.php';
require_once __DIR__ . '/scheduler_access_helper.php';

header('Content-Type: application/json');

$sessionRole = (string)($_SESSION['role'] ?? '');
if ($sessionRole === 'scheduler') {
    synk_scheduler_bootstrap_session_scope($conn);
}

if (!isset($_SESSION['user_id']) || !in_array($sessionRole, ['admin', 'scheduler'], true)) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'Administrator or scheduler session required.',
        'rows' => [],
        'external_rows' => [],
        'meta' => [],
    ]);
    exit;
}

$assigneeType = strtolower(trim((string)($_POST['assignee_type'] ?? 'faculty')));
$isFacultyNeed = $assigneeType === 'faculty_need';
$assigneeType = $isFacultyNeed ? 'faculty_need' : 'faculty';
$facultyId = (int)($_POST['faculty_id'] ?? 0);
$facultyNeedId = (int)($_POST['faculty_need_id'] ?? 0);
$scopeCollegeId = (int)($_POST['college_id'] ?? 0);
$scopeCampusId = (int)($_POST['campus_id'] ?? 0);
$includeExternal = (int)($_POST['include_external'] ?? 0) === 1;
$currentTerm = (($_SESSION['role'] ?? '') === 'scheduler' ? synk_scheduler_request_term($conn,$_POST) : synk_fetch_current_academic_term($conn));
$ayId = (int)($currentTerm['ay_id'] ?? 0);
$semester = (int)($currentTerm['semester'] ?? 0);
$targetId = $isFacultyNeed ? $facultyNeedId : $facultyId;
$facultyNeed = null;
$workloadTable = $isFacultyNeed ? synk_faculty_need_workload_table_name() : 'tbl_faculty_workload_sched';
$workloadIdSelect = $isFacultyNeed ? 'fw.need_workload_id AS workload_id' : 'fw.workload_id';
$workloadTargetColumn = $isFacultyNeed ? 'fw.faculty_need_id' : 'fw.faculty_id';
$workloadSourceReady = $ayId > 0 && $semester > 0 && synk_table_exists($conn, $workloadTable);

if ($sessionRole === 'scheduler') {
    $schedulerCampusId = (int)($_SESSION['campus_id'] ?? 0);

    if ($schedulerCampusId <= 0) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Scheduler campus assignment required.',
            'rows' => [],
            'external_rows' => [],
            'meta' => [],
        ]);
        exit;
    }

    if ($scopeCampusId > 0 && $scopeCampusId !== $schedulerCampusId) {
        http_response_code(403);
        echo json_encode([
            'status' => 'error',
            'message' => 'Requested campus is outside the scheduler assignment.',
            'rows' => [],
            'external_rows' => [],
            'meta' => [],
        ]);
        exit;
    }

    $scopeCampusId = $schedulerCampusId;

    if ($scopeCollegeId > 0 && synk_table_exists($conn, 'tbl_college')) {
        $collegeScopeStmt = $conn->prepare("
            SELECT campus_id
            FROM tbl_college
            WHERE college_id = ?
            LIMIT 1
        ");

        if ($collegeScopeStmt instanceof mysqli_stmt) {
            $collegeScopeStmt->bind_param('i', $scopeCollegeId);
            $collegeScopeStmt->execute();
            $collegeScopeResult = $collegeScopeStmt->get_result();
            $collegeScopeRow = $collegeScopeResult instanceof mysqli_result
                ? $collegeScopeResult->fetch_assoc()
                : null;
            $collegeScopeStmt->close();

            if ((int)($collegeScopeRow['campus_id'] ?? 0) !== $schedulerCampusId) {
                http_response_code(403);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Requested college is outside the scheduler campus assignment.',
                    'rows' => [],
                    'external_rows' => [],
                    'meta' => [],
                ]);
                exit;
            }
        }
    }
}

function admin_faculty_workload_emit_empty_response(
    array $currentTerm,
    int $ayId,
    int $semester,
    string $assigneeType,
    string $assigneeLabel = '',
    bool $analyticsReady = false
): void {
    echo json_encode([
        'status' => 'ok',
        'rows' => [],
        'external_rows' => [],
        'meta' => [
            'ay_id' => $ayId,
            'semester' => $semester,
            'term_text' => (string)($currentTerm['term_text'] ?? 'Current academic term'),
            'row_count' => 0,
            'total_load' => 0,
            'total_preparations' => 0,
            'assignee_type' => $assigneeType === 'faculty_need' ? 'faculty_need' : 'faculty',
            'assignee_label' => $assigneeLabel,
            'analytics_ready' => $analyticsReady,
        ],
    ]);
    exit;
}

if ($targetId <= 0 || $ayId <= 0 || $semester <= 0) {
    admin_faculty_workload_emit_empty_response($currentTerm, $ayId, $semester, $assigneeType, '', $workloadSourceReady);
}

if ($isFacultyNeed) {
    if ($scopeCollegeId <= 0 || !synk_table_exists($conn, synk_faculty_need_table_name())) {
        admin_faculty_workload_emit_empty_response($currentTerm, $ayId, $semester, $assigneeType, '', false);
    }

    $facultyNeed = synk_faculty_need_find($conn, $scopeCollegeId, $ayId, $semester, $facultyNeedId);
    if (!is_array($facultyNeed)) {
        admin_faculty_workload_emit_empty_response($currentTerm, $ayId, $semester, $assigneeType, '', false);
    }
}

if (!$workloadSourceReady) {
    admin_faculty_workload_emit_empty_response(
        $currentTerm,
        $ayId,
        $semester,
        $assigneeType,
        $isFacultyNeed ? (string)($facultyNeed['need_label'] ?? '') : '',
        false
    );
}

function admin_faculty_workload_context_key(int $groupId, int $scheduleId, int $offeringId): string
{
    if ($groupId > 0) {
        return 'group:' . $groupId;
    }

    if ($scheduleId > 0) {
        return 'schedule:' . $scheduleId;
    }

    return 'offering:' . $offeringId;
}

function admin_faculty_workload_scope_type_label(string $scope): string
{
    return strtoupper(trim($scope)) === 'LAB' ? 'Merged laboratory' : 'Merged lecture';
}

function admin_faculty_workload_merge_scope_note(array $scopeDisplay): string
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

    $scopeLabel = admin_faculty_workload_scope_type_label((string)($scopeDisplay['scope'] ?? 'LEC'));
    return $targetLabel !== '' ? ($scopeLabel . ' with ' . $targetLabel) : $scopeLabel;
}

function admin_faculty_workload_program_rows_by_offering(mysqli $conn, array $offeringIds): array
{
    $normalizedIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (empty($normalizedIds)) {
        return [];
    }

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
        WHERE po.offering_id IN (" . implode(',', array_map('intval', $normalizedIds)) . ")
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
                'program_code' => strtoupper(trim((string)($row['program_code'] ?? ''))),
                'program_name' => trim((string)($row['program_name'] ?? '')),
                'major' => trim((string)($row['major'] ?? '')),
            ];
        }
        $result->free();
    }

    return $rows;
}

function admin_faculty_workload_programs_for_scope(array $scopeDisplay, int $offeringId, array $programRowsByOffering): array
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

function admin_faculty_workload_program_label(array $programs): string
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

function admin_faculty_workload_time_label(string $start, string $end): string
{
    $start = trim($start);
    $end = trim($end);
    if ($start === '' || $end === '') {
        return 'TBA';
    }

    $startStamp = strtotime($start);
    $endStamp = strtotime($end);
    if ($startStamp === false || $endStamp === false) {
        return trim($start . ' - ' . $end);
    }

    return date('g:iA', $startStamp) . '-' . date('g:iA', $endStamp);
}

$liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
$classScheduleHasGroupId = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_group_id');
$classScheduleHasType = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type');
$splitExternalRows = false;

if ($includeExternal && $scopeCollegeId > 0 && $scopeCampusId <= 0 && synk_table_exists($conn, 'tbl_college')) {
    $campusLookupStmt = $conn->prepare("
        SELECT campus_id
        FROM tbl_college
        WHERE college_id = ?
        LIMIT 1
    ");

    if ($campusLookupStmt instanceof mysqli_stmt) {
        $campusLookupStmt->bind_param('i', $scopeCollegeId);
        $campusLookupStmt->execute();
        $campusLookupResult = $campusLookupStmt->get_result();
        $campusLookupRow = $campusLookupResult instanceof mysqli_result ? $campusLookupResult->fetch_assoc() : null;
        $scopeCampusId = (int)($campusLookupRow['campus_id'] ?? 0);
        $campusLookupStmt->close();
    }
}

$selectParts = [
    $workloadIdSelect,
    'cs.schedule_id',
    'o.offering_id',
    $classScheduleHasGroupId ? 'cs.schedule_group_id AS group_id' : 'NULL AS group_id',
    $classScheduleHasType ? 'cs.schedule_type AS type' : "'LEC' AS type",
    'p.program_id',
    "COALESCE(p.program_code, '') AS program_code",
    "COALESCE(p.program_name, '') AS program_name",
    "COALESCE(p.major, '') AS program_major",
    'p.college_id AS schedule_college_id',
    "COALESCE(c.college_name, '') AS schedule_college_name",
    "COALESCE(cp.campus_name, '') AS schedule_campus_name",
    'sm.sub_code',
    'sm.sub_description AS `desc`',
    'sec.section_name AS section',
    'sec.full_section AS full_section',
    'cs.days_json',
    'cs.time_start',
    'cs.time_end',
    "COALESCE(NULLIF(TRIM(r.room_code), ''), NULLIF(TRIM(r.room_name), ''), '') AS room",
    'ps.lec_units',
    'ps.lab_units',
    'ps.total_units'
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

$scopeSql = '';
$scopeTypes = '';
$scopeParams = [];
if ($includeExternal && $scopeCollegeId > 0 && $scopeCampusId > 0) {
    $scopeSql .= ' AND cp.campus_id = ?';
    $scopeTypes .= 'i';
    $scopeParams[] = $scopeCampusId;
    $splitExternalRows = true;
} elseif ($scopeCollegeId > 0) {
    $scopeSql .= ' AND p.college_id = ?';
    $scopeTypes .= 'i';
    $scopeParams[] = $scopeCollegeId;
} elseif ($scopeCampusId > 0) {
    $scopeSql .= ' AND cp.campus_id = ?';
    $scopeTypes .= 'i';
    $scopeParams[] = $scopeCampusId;
}

$sql = "
    SELECT
        " . implode(",\n        ", $selectParts) . "
    FROM `{$workloadTable}` fw
    INNER JOIN tbl_class_schedule cs
        ON cs.schedule_id = fw.schedule_id
    INNER JOIN tbl_prospectus_offering o
        ON o.offering_id = cs.offering_id
    {$liveOfferingJoins}
    INNER JOIN tbl_program p
        ON p.program_id = o.program_id
    LEFT JOIN tbl_college c
        ON c.college_id = p.college_id
    LEFT JOIN tbl_campus cp
        ON cp.campus_id = c.campus_id
    INNER JOIN tbl_subject_masterlist sm
        ON sm.sub_id = ps.sub_id
    LEFT JOIN tbl_rooms r
        ON r.room_id = cs.room_id
    WHERE {$workloadTargetColumn} = ?
      AND fw.ay_id = ?
      AND fw.semester = ?
      {$scopeSql}
    ORDER BY
        " . implode(",\n        ", $orderParts) . "
";

$stmt = $conn->prepare($sql);
if (!($stmt instanceof mysqli_stmt)) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unable to prepare workload query.',
        'rows' => [],
        'external_rows' => [],
        'meta' => [],
    ]);
    exit;
}

$types = 'iii' . $scopeTypes;
$params = array_merge([$targetId, $ayId, $semester], $scopeParams);
synk_bind_dynamic_params($stmt, $types, $params);
$stmt->execute();
$result = $stmt->get_result();

$rawRows = [];
$offeringIds = [];
$preparations = [];

while ($row = $result->fetch_assoc()) {
    $daysArr = json_decode((string)($row['days_json'] ?? '[]'), true);
    if (!is_array($daysArr)) {
        $daysArr = [];
    }

    $rawRows[] = [
        'workload_id' => (int)($row['workload_id'] ?? 0),
        'schedule_id' => (int)($row['schedule_id'] ?? 0),
        'offering_id' => (int)($row['offering_id'] ?? 0),
        'group_id' => (int)($row['group_id'] ?? 0),
        'program_id' => (int)($row['program_id'] ?? 0),
        'program_code' => strtoupper(trim((string)($row['program_code'] ?? ''))),
        'program_name' => trim((string)($row['program_name'] ?? '')),
        'program_major' => trim((string)($row['program_major'] ?? '')),
        'schedule_college_id' => (int)($row['schedule_college_id'] ?? 0),
        'schedule_college_name' => trim((string)($row['schedule_college_name'] ?? '')),
        'schedule_campus_name' => trim((string)($row['schedule_campus_name'] ?? '')),
        'sub_code' => (string)($row['sub_code'] ?? ''),
        'desc' => (string)($row['desc'] ?? ''),
        'section' => (string)($row['section'] ?? ''),
        'full_section' => (string)($row['full_section'] ?? ''),
        'type' => strtoupper(trim((string)($row['type'] ?? 'LEC'))),
        'days_arr' => array_values($daysArr),
        'time_start' => (string)($row['time_start'] ?? ''),
        'time_end' => (string)($row['time_end'] ?? ''),
        'room' => (string)($row['room'] ?? ''),
        'lec_units' => (float)($row['lec_units'] ?? 0),
        'lab_units' => (float)($row['lab_units'] ?? 0),
        'total_units' => (float)($row['total_units'] ?? 0),
    ];

    $offeringId = (int)($row['offering_id'] ?? 0);
    if ($offeringId > 0) {
        $offeringIds[$offeringId] = true;
    }

    $preparationKey = trim((string)($row['sub_code'] ?? ''));
    if ($preparationKey !== '') {
        $preparations[$preparationKey] = true;
    }
}

$stmt->close();

$rows = [];
$contextTotals = [];
$totalLoad = 0.0;

if (!empty($offeringIds)) {
    $mergeContext = synk_schedule_merge_load_display_context($conn, array_keys($offeringIds));
    $lookupIds = array_keys($offeringIds);

    foreach ($mergeContext as $info) {
        foreach ((array)($info['group_offering_ids'] ?? []) as $groupOfferingId) {
            $lookupIds[] = (int)$groupOfferingId;
        }

        $ownedMemberIdsByScope = (array)($info['owned_member_ids_by_scope'] ?? []);
        foreach (['LEC', 'LAB'] as $scope) {
            foreach ((array)($ownedMemberIdsByScope[$scope] ?? []) as $groupOfferingId) {
                $lookupIds[] = (int)$groupOfferingId;
            }
        }
    }

    $lookupIds = synk_schedule_merge_normalize_offering_ids($lookupIds);
    $sectionRowsByOffering = synk_schedule_merge_load_section_rows_by_offering($conn, $lookupIds);
    $programRowsByOffering = admin_faculty_workload_program_rows_by_offering($conn, $lookupIds);
    $studentCountMap = synk_fetch_offering_enrollee_count_map($conn, $lookupIds);

    $contextSql = "
        SELECT
            cs.schedule_id,
            cs.offering_id,
            " . ($classScheduleHasGroupId ? 'cs.schedule_group_id AS group_id' : 'NULL AS group_id') . ",
            " . ($classScheduleHasType ? 'cs.schedule_type AS type' : "'LEC' AS type") . "
        FROM tbl_class_schedule cs
        WHERE cs.offering_id IN (" . implode(',', array_map('intval', array_keys($offeringIds))) . ")
    ";

    $contextResult = $conn->query($contextSql);
    if ($contextResult instanceof mysqli_result) {
        while ($contextRow = $contextResult->fetch_assoc()) {
            $contextKey = admin_faculty_workload_context_key(
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
            if (strtoupper(trim((string)($contextRow['type'] ?? 'LEC'))) === 'LAB') {
                $contextTotals[$contextKey]['lab_count']++;
            } else {
                $contextTotals[$contextKey]['lec_count']++;
            }
        }
        $contextResult->free();
    }

    $ownedContextRows = [];
    foreach ($rawRows as $rawRow) {
        $contextKey = admin_faculty_workload_context_key(
            (int)($rawRow['group_id'] ?? 0),
            (int)($rawRow['schedule_id'] ?? 0),
            (int)($rawRow['offering_id'] ?? 0)
        );

        if (!isset($ownedContextRows[$contextKey])) {
            $ownedContextRows[$contextKey] = [];
        }

        $ownedContextRows[$contextKey][] = [
            'schedule_type' => (string)($rawRow['type'] ?? 'LEC'),
            'days' => (array)($rawRow['days_arr'] ?? []),
            'time_start' => (string)($rawRow['time_start'] ?? ''),
            'time_end' => (string)($rawRow['time_end'] ?? ''),
            'lec_units' => (float)($rawRow['lec_units'] ?? 0),
            'lab_units' => (float)($rawRow['lab_units'] ?? 0),
            'total_units' => (float)($rawRow['total_units'] ?? 0),
        ];
    }

    $ownedContextMetrics = [];
    foreach ($ownedContextRows as $contextKey => $contextRows) {
        $ownedContextMetrics[$contextKey] = synk_schedule_sum_display_metrics(
            $contextRows,
            $contextTotals[$contextKey] ?? []
        );
        $totalLoad += (float)($ownedContextMetrics[$contextKey]['faculty_load'] ?? 0);
    }

    foreach ($rawRows as $rawRow) {
        $offeringId = (int)($rawRow['offering_id'] ?? 0);
        $contextKey = admin_faculty_workload_context_key(
            (int)($rawRow['group_id'] ?? 0),
            (int)($rawRow['schedule_id'] ?? 0),
            $offeringId
        );

        $metrics = $ownedContextMetrics[$contextKey] ?? [
            'units' => 0.0,
            'lec' => 0.0,
            'lab' => 0.0,
            'lab_hours' => 0.0,
            'faculty_load' => 0.0,
        ];

        $mergeInfo = $mergeContext[$offeringId] ?? null;
        $scopeDisplay = synk_schedule_merge_scope_display_context(
            (array)$mergeInfo,
            (string)($rawRow['type'] ?? 'LEC'),
            $offeringId,
            $sectionRowsByOffering
        );

        $programScope = admin_faculty_workload_programs_for_scope($scopeDisplay, $offeringId, $programRowsByOffering);
        if (empty($programScope)) {
            $programScope = [[
                'program_id' => (int)($rawRow['program_id'] ?? 0),
                'program_code' => strtoupper(trim((string)($rawRow['program_code'] ?? ''))),
                'program_name' => trim((string)($rawRow['program_name'] ?? '')),
                'major' => trim((string)($rawRow['program_major'] ?? '')),
            ]];
        }

        $courseLabel = trim((string)($rawRow['full_section'] ?? ''));
        if ($courseLabel === '') {
            $courseLabel = trim((string)($rawRow['section'] ?? ''));
        }

        if (!empty($scopeDisplay['is_merged'])) {
            $groupLabel = trim((string)($scopeDisplay['group_label'] ?? ''));
            if ($groupLabel !== '') {
                $courseLabel = $groupLabel;
            }
        }

        if ($courseLabel === '' && isset($sectionRowsByOffering[$offeringId])) {
            $courseLabel = trim((string)($sectionRowsByOffering[$offeringId]['full_section'] ?? ''));
        }

        if ($courseLabel === '' && !empty($scopeDisplay['group_label'])) {
            $courseLabel = trim((string)($scopeDisplay['group_label']));
        }

        if ($courseLabel === '') {
            $courseLabel = 'Section ' . $offeringId;
        }

        $mergeNote = admin_faculty_workload_merge_scope_note($scopeDisplay);
        if ($mergeNote === '' && !empty($scopeDisplay['is_merged'])) {
            $mergeNote = ($scopeDisplay['mode'] ?? 'local') === 'full'
                ? 'Merged subject'
                : admin_faculty_workload_scope_type_label((string)($scopeDisplay['scope'] ?? 'LEC'));
        }

        $studentCount = 0;
        $studentOfferingIds = !empty($scopeDisplay['is_merged'])
            ? (array)($scopeDisplay['group_offering_ids'] ?? [])
            : [$offeringId];

        foreach ($studentOfferingIds as $studentOfferingId) {
            $studentCount += synk_offering_enrollee_count_for_map($studentCountMap, (int)$studentOfferingId);
        }

        if ($studentCount <= 0) {
            $studentCount = synk_offering_enrollee_count_for_map($studentCountMap, $offeringId);
        }

        $lecUnits = (float)($rawRow['lec_units'] ?? 0);
        $labUnits = (float)($rawRow['lab_units'] ?? 0);
        $totalUnits = (float)($rawRow['total_units'] ?? 0);

        $rows[] = [
            'workload_id' => (int)($rawRow['workload_id'] ?? 0),
            'schedule_id' => (int)($rawRow['schedule_id'] ?? 0),
            'offering_id' => $offeringId,
            'group_id' => (int)($rawRow['group_id'] ?? 0),
            'sub_code' => (string)($rawRow['sub_code'] ?? ''),
            'desc' => (string)($rawRow['desc'] ?? ''),
            'course' => $courseLabel,
            'section' => (string)($rawRow['section'] ?? ''),
            'type' => (string)($rawRow['type'] ?? 'LEC'),
            'merge_note' => $mergeNote,
            'is_scope_merged' => !empty($scopeDisplay['is_merged']),
            'merge_scope_mode' => (string)($scopeDisplay['mode'] ?? 'local'),
            'program_code' => admin_faculty_workload_program_label($programScope) ?: (string)($rawRow['program_code'] ?? ''),
            'schedule_college_id' => (int)($rawRow['schedule_college_id'] ?? 0),
            'schedule_college_name' => (string)($rawRow['schedule_college_name'] ?? ''),
            'schedule_campus_name' => (string)($rawRow['schedule_campus_name'] ?? ''),
            'days_arr' => array_values((array)($rawRow['days_arr'] ?? [])),
            'days' => empty($rawRow['days_arr']) ? 'TBA' : implode(', ', (array)$rawRow['days_arr']),
            'time_start' => (string)($rawRow['time_start'] ?? ''),
            'time_end' => (string)($rawRow['time_end'] ?? ''),
            'time' => admin_faculty_workload_time_label((string)($rawRow['time_start'] ?? ''), (string)($rawRow['time_end'] ?? '')),
            'room' => trim((string)($rawRow['room'] ?? '')) !== '' ? (string)$rawRow['room'] : 'TBA',
            'subject_units' => round(synk_subject_units_total($lecUnits, $labUnits, $totalUnits), 2),
            'units' => round((float)($metrics['units'] ?? 0), 2),
            'lec' => round((float)($metrics['lec'] ?? 0), 2),
            'lab' => round((float)($metrics['lab'] ?? 0), 2),
            'lab_hours' => round((float)($metrics['lab_hours'] ?? 0), 2),
            'faculty_load' => round((float)($metrics['faculty_load'] ?? 0), 2),
            'student_count' => $studentCount,
        ];
    }
}

$payloadRows = $rows;
$externalRows = [];

if ($splitExternalRows && $scopeCollegeId > 0) {
    $payloadRows = [];

    foreach ($rows as $row) {
        $isExternal = (int)($row['schedule_college_id'] ?? 0) !== $scopeCollegeId;
        $row['is_external'] = $isExternal;

        if ($isExternal) {
            $externalRows[] = $row;
            continue;
        }

        $payloadRows[] = $row;
    }
}

echo json_encode([
    'status' => 'ok',
    'rows' => $payloadRows,
    'external_rows' => $externalRows,
    'meta' => [
        'ay_id' => $ayId,
        'semester' => $semester,
        'term_text' => (string)($currentTerm['term_text'] ?? 'Current academic term'),
        'row_count' => count($rows),
        'total_load' => round($totalLoad, 2),
        'total_preparations' => count($preparations),
        'assignee_type' => $assigneeType,
        'assignee_label' => $isFacultyNeed ? (string)($facultyNeed['need_label'] ?? '') : '',
        'analytics_ready' => $workloadSourceReady,
    ],
]);
exit;
?>
