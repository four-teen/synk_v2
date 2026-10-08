<?php
session_start();
include 'db.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/offering_enrollee_helper.php';
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/schedule_block_helper.php';
require_once __DIR__ . '/schedule_tba_helper.php';
require_once __DIR__ . '/schedule_merge_helper.php';
require_once __DIR__ . '/program_chair_signatory_helper.php';
require_once __DIR__ . '/faculty_need_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['rows' => [], 'meta' => []]);
    exit;
}

$assigneeType = strtolower(trim((string)($_POST['assignee_type'] ?? 'faculty')));
$faculty_id = (int)($_POST['faculty_id'] ?? 0);
$faculty_need_id = (int)($_POST['faculty_need_id'] ?? 0);
$ay_id = (int)($_POST['ay_id'] ?? 0);
$semester = (int)($_POST['semester'] ?? 0);
$college_id = (int)($_SESSION['college_id'] ?? 0);
$isFacultyNeed = $assigneeType === 'faculty_need';
$targetId = $isFacultyNeed ? $faculty_need_id : $faculty_id;
$facultyNeed = null;

if ($targetId <= 0 || $ay_id <= 0 || $semester <= 0) {
    echo json_encode(['rows' => [], 'meta' => []]);
    exit;
}

if ($isFacultyNeed) {
    $facultyNeed = synk_faculty_need_find($conn, $college_id, $ay_id, $semester, $faculty_need_id);
    if (!is_array($facultyNeed)) {
        echo json_encode(['rows' => [], 'meta' => []]);
        exit;
    }
}

function workload_title_case(string $value): string
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

function workload_context_key_from_values(int $groupId, int $scheduleId, int $offeringId): string
{
    if ($groupId > 0) {
        return 'group:' . $groupId;
    }

    if ($scheduleId > 0) {
        return 'schedule:' . $scheduleId;
    }

    return 'offering:' . $offeringId;
}

function workload_scope_type_label(string $scope): string
{
    return strtoupper(trim($scope)) === 'LAB' ? 'Merged laboratory' : 'Merged lecture';
}

function workload_merge_scope_note(array $scopeDisplay): string
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

    $scopeLabel = workload_scope_type_label((string)($scopeDisplay['scope'] ?? 'LEC'));
    return $targetLabel !== '' ? ($scopeLabel . ' with ' . $targetLabel) : $scopeLabel;
}

function workload_load_program_rows_by_offering(mysqli $conn, array $offeringIds): array
{
    $normalizedIds = synk_schedule_merge_normalize_offering_ids($offeringIds);
    if (empty($normalizedIds)) {
        return [];
    }

    $idList = implode(',', array_map('intval', $normalizedIds));
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
        WHERE po.offering_id IN ({$idList})
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
    }

    return $rows;
}

function workload_programs_for_scope(array $scopeDisplay, int $offeringId, array $programRowsByOffering): array
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

function workload_program_label_from_programs(array $programs): string
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

function workload_faculty_print_name(array $row): string
{
    $lastName = trim((string)($row['last_name'] ?? ''));
    $firstName = trim((string)($row['first_name'] ?? ''));
    $middleName = trim((string)($row['middle_name'] ?? ''));
    $extName = trim((string)($row['ext_name'] ?? ''));

    $name = trim($lastName . ', ' . $firstName, ' ,');

    if ($middleName !== '') {
        $name .= ' ' . strtoupper(substr($middleName, 0, 1)) . '.';
    }

    if ($extName !== '') {
        $name .= ', ' . $extName;
    }

    return trim($name, ' ,');
}

function workload_designation_is_dean(string $designationName): bool
{
    return preg_match('/\bDEAN\b/i', $designationName) === 1;
}

function workload_dean_score(array $assignment): int
{
    $designationName = strtoupper(trim((string)($assignment['designation_name'] ?? '')));
    if ($designationName === 'DEAN') {
        return 120;
    }

    if (strpos($designationName, 'DEAN (') === 0) {
        return 110;
    }

    if (strpos($designationName, 'COLLEGE DEAN') !== false) {
        return 100;
    }

    if (strpos($designationName, 'DEAN') === 0) {
        return 90;
    }

    return workload_designation_is_dean($designationName) ? 70 : 0;
}

function workload_fetch_college_dean_signatory(mysqli $conn, int $collegeId, int $ayId, int $semester): array
{
    if (
        $collegeId <= 0
        || !synk_table_exists($conn, 'tbl_college_faculty')
        || !synk_table_exists($conn, 'tbl_faculty')
        || !synk_table_exists($conn, 'tbl_designation')
        || !synk_table_has_column($conn, 'tbl_faculty', 'designation_id')
    ) {
        return ['name' => '', 'designation' => ''];
    }

    $assignmentHasAyId = synk_table_has_column($conn, 'tbl_college_faculty', 'ay_id');
    $assignmentHasSemester = synk_table_has_column($conn, 'tbl_college_faculty', 'semester');
    $designationHasStatus = synk_table_has_column($conn, 'tbl_designation', 'status');
    $facultyHasMiddleName = synk_table_has_column($conn, 'tbl_faculty', 'middle_name');
    $facultyHasExtName = synk_table_has_column($conn, 'tbl_faculty', 'ext_name');

    $whereParts = [
        'cf.college_id = ?',
        "LOWER(TRIM(cf.status)) = 'active'",
        "LOWER(TRIM(f.status)) = 'active'",
        "NULLIF(TRIM(d.designation_name), '') IS NOT NULL",
    ];
    $types = 'i';
    $params = [$collegeId];

    if ($designationHasStatus) {
        $whereParts[] = "LOWER(TRIM(d.status)) = 'active'";
    }

    if ($assignmentHasAyId && $assignmentHasSemester && $ayId > 0 && $semester > 0) {
        $whereParts[] = '((cf.ay_id = ? AND cf.semester = ?) OR (cf.ay_id IS NULL AND cf.semester IS NULL))';
        $types .= 'ii';
        $params[] = $ayId;
        $params[] = $semester;
    }

    $stmt = $conn->prepare("
        SELECT
            cf.college_faculty_id,
            f.faculty_id,
            f.last_name,
            f.first_name,
            " . ($facultyHasMiddleName ? 'f.middle_name' : "'' AS middle_name") . ",
            " . ($facultyHasExtName ? 'f.ext_name' : "'' AS ext_name") . ",
            d.designation_name
        FROM tbl_college_faculty cf
        INNER JOIN tbl_faculty f
            ON f.faculty_id = cf.faculty_id
        INNER JOIN tbl_designation d
            ON d.designation_id = f.designation_id
        WHERE " . implode("\n          AND ", $whereParts) . "
        ORDER BY f.last_name ASC, f.first_name ASC, cf.college_faculty_id ASC
    ");

    if (!($stmt instanceof mysqli_stmt)) {
        return ['name' => '', 'designation' => ''];
    }

    synk_bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $candidates = [];
    $order = 0;

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $designationName = trim((string)($row['designation_name'] ?? ''));
            if (!workload_designation_is_dean($designationName)) {
                continue;
            }

            $assignment = [
                'name' => workload_faculty_print_name($row),
                'designation_name' => $designationName,
            ];
            $candidates[] = [
                'assignment' => $assignment,
                'score' => workload_dean_score($assignment),
                'order' => $order++,
            ];
        }
    }

    $stmt->close();

    if (empty($candidates)) {
        return ['name' => '', 'designation' => ''];
    }

    usort($candidates, static function (array $left, array $right): int {
        $scoreCompare = ((int)$right['score']) <=> ((int)$left['score']);
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }

        return ((int)$left['order']) <=> ((int)$right['order']);
    });

    $selected = (array)$candidates[0]['assignment'];

    return [
        'name' => (string)($selected['name'] ?? ''),
        'designation' => (string)($selected['designation_name'] ?? ''),
    ];
}

function workload_dominant_program_from_rows(array $rows): array
{
    $programs = [];
    $counts = [];
    $order = [];
    $nextOrder = 0;
    $countedSubjectPrograms = [];

    foreach ($rows as $rowIndex => $row) {
        $rowPrograms = [];
        $subjectCode = strtoupper(trim((string)($row['sub_code'] ?? '')));
        $courseLabel = strtoupper(trim((string)($row['course'] ?? ($row['section'] ?? ''))));
        $rowSubjectKey = $subjectCode !== '' ? ($subjectCode . '|' . $courseLabel) : ('row:' . (int)$rowIndex);

        if (isset($row['program_scope']) && is_array($row['program_scope'])) {
            $rowPrograms = $row['program_scope'];
        } else {
            $rowPrograms[] = [
                'program_id' => (int)($row['program_id'] ?? 0),
                'program_code' => trim((string)($row['program_code'] ?? '')),
                'program_name' => trim((string)($row['program_name'] ?? '')),
                'major' => trim((string)($row['program_major'] ?? '')),
            ];
        }

        $countedInRow = [];
        foreach ($rowPrograms as $program) {
            $programId = (int)($program['program_id'] ?? 0);
            $programCode = strtoupper(trim((string)($program['program_code'] ?? '')));

            if ($programId <= 0 && $programCode === '') {
                continue;
            }

            $programKey = $programId > 0 ? 'id:' . $programId : 'code:' . $programCode;
            if (isset($countedInRow[$programKey])) {
                continue;
            }

            $subjectProgramKey = $rowSubjectKey . '|' . $programKey;
            if (isset($countedSubjectPrograms[$subjectProgramKey])) {
                continue;
            }

            if (!isset($programs[$programKey])) {
                $programs[$programKey] = [
                    'program_id' => $programId,
                    'program_code' => $programCode,
                    'program_name' => trim((string)($program['program_name'] ?? '')),
                    'major' => trim((string)($program['major'] ?? '')),
                ];
                $counts[$programKey] = 0;
                $order[$programKey] = $nextOrder++;
            }

            $counts[$programKey]++;
            $countedInRow[$programKey] = true;
            $countedSubjectPrograms[$subjectProgramKey] = true;
        }
    }

    if (empty($programs)) {
        return [];
    }

    $keys = array_keys($programs);
    usort($keys, static function ($left, $right) use ($counts, $order): int {
        $countCompare = ($counts[$right] ?? 0) <=> ($counts[$left] ?? 0);
        if ($countCompare !== 0) {
            return $countCompare;
        }

        return ($order[$left] ?? 0) <=> ($order[$right] ?? 0);
    });

    $dominantKey = (string)$keys[0];
    $dominantProgram = $programs[$dominantKey];
    $dominantProgram['subject_count'] = (int)($counts[$dominantKey] ?? 0);

    return $dominantProgram;
}

function workload_build_print_signatories(
    mysqli $conn,
    int $collegeId,
    int $ayId,
    int $semester,
    array $rows
): array {
    $dominantProgram = workload_dominant_program_from_rows($rows);
    $preparedBy = [
        'name' => '',
        'designation' => 'Program Chairperson',
        'program_code' => (string)($dominantProgram['program_code'] ?? ''),
    ];

    if ((int)($dominantProgram['program_id'] ?? 0) > 0) {
        $programChair = synk_program_chair_signatory_fetch_for_program(
            $conn,
            $collegeId,
            (int)$dominantProgram['program_id'],
            $ayId,
            $semester
        );

        if (is_array($programChair)) {
            $preparedBy = [
                'name' => (string)($programChair['name'] ?? ''),
                'designation' => (string)($programChair['designation'] ?? 'Program Chairperson'),
                'program_code' => (string)($programChair['program_code'] ?? ($dominantProgram['program_code'] ?? '')),
            ];
        }
    }

    return [
        'prepared_by' => $preparedBy,
        'approved_by' => workload_fetch_college_dean_signatory($conn, $collegeId, $ayId, $semester),
        'dominant_program' => $dominantProgram,
    ];
}

$liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
$classScheduleHasGroupId = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_group_id');
$classScheduleHasType = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type');
$facultyHasDesignationId = synk_table_has_column($conn, 'tbl_faculty', 'designation_id');
$designationTableExists = synk_table_exists($conn, 'tbl_designation');
$designationHasStatus = $designationTableExists && synk_table_has_column($conn, 'tbl_designation', 'status');
$assignmentHasAyId = synk_table_has_column($conn, 'tbl_college_faculty', 'ay_id');
$assignmentHasSemester = synk_table_has_column($conn, 'tbl_college_faculty', 'semester');
$workloadIdSelect = $isFacultyNeed ? 'fw.need_workload_id AS workload_id' : 'fw.workload_id';
$workloadTable = $isFacultyNeed ? synk_faculty_need_workload_table_name() : 'tbl_faculty_workload_sched';
$workloadTargetColumn = $isFacultyNeed ? 'fw.faculty_need_id' : 'fw.faculty_id';

$selectParts = [
    $workloadIdSelect,
    'cs.schedule_id',
    'cs.schedule_status',
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
    'r.room_code AS room',
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

$sql = "
SELECT
    " . implode(",\n    ", $selectParts) . "
FROM `{$workloadTable}` fw
JOIN tbl_class_schedule cs       ON cs.schedule_id = fw.schedule_id
JOIN tbl_prospectus_offering o   ON o.offering_id = cs.offering_id
{$liveOfferingJoins}
JOIN tbl_program p               ON p.program_id = o.program_id
LEFT JOIN tbl_college c          ON c.college_id = p.college_id
LEFT JOIN tbl_campus cp          ON cp.campus_id = c.campus_id
JOIN tbl_subject_masterlist sm   ON sm.sub_id = ps.sub_id
LEFT JOIN tbl_rooms r            ON r.room_id = cs.room_id
WHERE
    {$workloadTargetColumn} = ?
AND fw.ay_id      = ?
AND fw.semester   = ?
ORDER BY
    " . implode(",\n    ", $orderParts) . "
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $targetId, $ay_id, $semester);
$stmt->execute();
$res = $stmt->get_result();

$rows = [];
$rawRows = [];
$preparations = [];
$offeringIds = [];

while ($row = $res->fetch_assoc()) {
    $days_arr = json_decode((string)$row['days_json'], true);
    if (!is_array($days_arr)) {
        $days_arr = [];
    }

    $rawRows[] = [
        'workload_id' => (int)$row['workload_id'],
        'schedule_id' => (int)($row['schedule_id'] ?? 0),
        'schedule_status' => synk_schedule_status($row),
        'offering_id' => (int)$row['offering_id'],
        'group_id' => (int)($row['group_id'] ?? 0),
        'program_id' => (int)($row['program_id'] ?? 0),
        'program_code' => strtoupper(trim((string)($row['program_code'] ?? ''))),
        'program_name' => trim((string)($row['program_name'] ?? '')),
        'program_major' => trim((string)($row['program_major'] ?? '')),
        'schedule_college_id' => (int)($row['schedule_college_id'] ?? 0),
        'schedule_college_name' => trim((string)($row['schedule_college_name'] ?? '')),
        'schedule_campus_name' => trim((string)($row['schedule_campus_name'] ?? '')),
        'sub_code' => (string)$row['sub_code'],
        'desc' => (string)$row['desc'],
        'section' => (string)$row['section'],
        'full_section' => (string)($row['full_section'] ?? ''),
        'type' => strtoupper(trim((string)($row['type'] ?? 'LEC'))),
        'days_arr' => $days_arr,
        'time_start' => (string)$row['time_start'],
        'time_end' => (string)$row['time_end'],
        'room' => (string)($row['room'] ?? ''),
        'lec_units' => (float)($row['lec_units'] ?? 0),
        'lab_units' => (float)($row['lab_units'] ?? 0),
        'total_units' => (float)($row['total_units'] ?? 0)
    ];

    $offeringIds[(int)$row['offering_id']] = true;

    $preparationKey = trim((string)$row['sub_code']);
    if ((int)($row['schedule_college_id'] ?? 0) === $college_id && $preparationKey !== '') {
        $preparations[$preparationKey] = true;
    }
}

$stmt->close();

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
$programRowsByOffering = workload_load_program_rows_by_offering($conn, $mergeSectionLookupIds);

$studentLookupIds = array_keys($offeringIds);
foreach ($mergeContext as $info) {
    foreach ((array)($info['group_offering_ids'] ?? []) as $groupOfferingId) {
        $studentLookupIds[] = (int)$groupOfferingId;
    }

    $ownedMemberIdsByScope = (array)($info['owned_member_ids_by_scope'] ?? []);
    foreach (['LEC', 'LAB'] as $scope) {
        foreach ((array)($ownedMemberIdsByScope[$scope] ?? []) as $groupOfferingId) {
            $studentLookupIds[] = (int)$groupOfferingId;
        }
    }
}
$studentLookupIds = synk_schedule_merge_normalize_offering_ids($studentLookupIds);
$importedStudentMap = synk_fetch_offering_imported_student_map($conn, $studentLookupIds, $ay_id, $semester);

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
            $contextKey = workload_context_key_from_values(
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

$ownedContextRows = [];
foreach ($rawRows as $rawRow) {
    $contextKey = workload_context_key_from_values(
        (int)($rawRow['group_id'] ?? 0),
        (int)($rawRow['schedule_id'] ?? 0),
        (int)($rawRow['offering_id'] ?? 0)
    );

    if (!isset($ownedContextRows[$contextKey])) {
        $ownedContextRows[$contextKey] = [];
    }

    $ownedContextRows[$contextKey][] = [
        'schedule_type' => (string)($rawRow['type'] ?? 'LEC'),
        'days' => $rawRow['days_arr'] ?? [],
        'time_start' => (string)($rawRow['time_start'] ?? ''),
        'time_end' => (string)($rawRow['time_end'] ?? ''),
        'lec_units' => (float)($rawRow['lec_units'] ?? 0),
        'lab_units' => (float)($rawRow['lab_units'] ?? 0),
        'total_units' => (float)($rawRow['total_units'] ?? 0)
    ];
}

$ownedContextMetrics = [];
foreach ($ownedContextRows as $contextKey => $contextRows) {
    $ownedContextMetrics[$contextKey] = synk_schedule_sum_display_metrics(
        $contextRows,
        $contextTotals[$contextKey] ?? []
    );
}

foreach ($rawRows as $rawRow) {
    $lecUnits = (float)($rawRow['lec_units'] ?? 0);
    $labValue = (float)($rawRow['lab_units'] ?? 0);
    $totalUnits = (float)($rawRow['total_units'] ?? 0);
    $subjectUnits = synk_subject_units_total($lecUnits, $labValue, $totalUnits);
    $contextKey = workload_context_key_from_values(
        (int)($rawRow['group_id'] ?? 0),
        (int)($rawRow['schedule_id'] ?? 0),
        (int)($rawRow['offering_id'] ?? 0)
    );
    $metrics = $ownedContextMetrics[$contextKey] ?? [
        'units' => 0.0,
        'lec' => 0.0,
        'lab' => 0.0,
        'lab_hours' => 0.0,
        'faculty_load' => 0.0
    ];
    $fullSection = trim((string)($rawRow['full_section'] ?? ''));
    if ($fullSection === '') {
        $fullSection = trim((string)($rawRow['section'] ?? ''));
    }
    $mergeInfo = $mergeContext[(int)($rawRow['offering_id'] ?? 0)] ?? null;
    $scopeDisplay = synk_schedule_merge_scope_display_context(
        (array)$mergeInfo,
        (string)($rawRow['type'] ?? 'LEC'),
        (int)($rawRow['offering_id'] ?? 0),
        $sectionRowsByOffering
    );
    $programScope = workload_programs_for_scope(
        $scopeDisplay,
        (int)($rawRow['offering_id'] ?? 0),
        $programRowsByOffering
    );
    if (empty($programScope)) {
        $programScope = [[
            'program_id' => (int)($rawRow['program_id'] ?? 0),
            'program_code' => strtoupper(trim((string)($rawRow['program_code'] ?? ''))),
            'program_name' => trim((string)($rawRow['program_name'] ?? '')),
            'major' => trim((string)($rawRow['program_major'] ?? '')),
        ]];
    }
    $programLabel = workload_program_label_from_programs($programScope);
    $courseLabel = $fullSection;
    $mergeNote = workload_merge_scope_note($scopeDisplay);
    $studentCount = 0;

    if (!empty($scopeDisplay['is_merged'])) {
        $groupLabel = trim((string)($scopeDisplay['group_label'] ?? ''));
        if ($groupLabel !== '') {
            $courseLabel = $groupLabel;
        }
    }

    $studentOfferingIds = !empty($scopeDisplay['is_merged'])
        ? (array)($scopeDisplay['group_offering_ids'] ?? [])
        : [(int)($rawRow['offering_id'] ?? 0)];
    $studentCount = synk_offering_imported_student_count($importedStudentMap, $studentOfferingIds);

    if ($studentCount <= 0) {
        $studentCount = synk_offering_imported_student_count($importedStudentMap, [(int)($rawRow['offering_id'] ?? 0)]);
    }

    if ($courseLabel === '') {
        $courseLabel = trim((string)($rawRow['section'] ?? ''));
    }

    if ($courseLabel === '' && isset($sectionRowsByOffering[(int)($rawRow['offering_id'] ?? 0)])) {
        $courseLabel = trim((string)($sectionRowsByOffering[(int)($rawRow['offering_id'] ?? 0)]['full_section'] ?? ''));
    }

    if ($courseLabel === '' && !empty($scopeDisplay['group_label'])) {
        $courseLabel = trim((string)$scopeDisplay['group_label']);
    }

    if ($courseLabel === '') {
        $courseLabel = 'Section ' . (int)($rawRow['offering_id'] ?? 0);
    }

    if ($mergeNote === '' && !empty($scopeDisplay['is_merged'])) {
        if (($scopeDisplay['mode'] ?? 'local') === 'full') {
            $mergeNote = 'Merged subject';
        } else {
            $mergeNote = workload_scope_type_label((string)($scopeDisplay['scope'] ?? 'LEC'));
        }
    }

    $rows[] = [
        'workload_id' => (int)$rawRow['workload_id'],
        'assignee_type' => $isFacultyNeed ? 'faculty_need' : 'faculty',
        'schedule_id' => (int)$rawRow['schedule_id'],
        'offering_id' => (int)$rawRow['offering_id'],
        'group_id' => (int)($rawRow['group_id'] ?? 0),
        'program_id' => (int)($rawRow['program_id'] ?? 0),
        'program_code' => $programLabel !== '' ? $programLabel : (string)($rawRow['program_code'] ?? ''),
        'program_name' => (string)($rawRow['program_name'] ?? ''),
        'program_major' => (string)($rawRow['program_major'] ?? ''),
        'schedule_college_id' => (int)($rawRow['schedule_college_id'] ?? 0),
        'schedule_college_name' => (string)($rawRow['schedule_college_name'] ?? ''),
        'schedule_campus_name' => (string)($rawRow['schedule_campus_name'] ?? ''),
        'program_ids' => array_values(array_filter(array_map(static function (array $program): int {
            return (int)($program['program_id'] ?? 0);
        }, $programScope))),
        'program_scope' => $programScope,
        'sub_code' => (string)$rawRow['sub_code'],
        'desc' => (string)$rawRow['desc'],
        'course' => $courseLabel,
        'section' => (string)$rawRow['section'],
        'type' => (string)$rawRow['type'],
        'merge_note' => $mergeNote,
        'is_scope_merged' => !empty($scopeDisplay['is_merged']),
        'merge_scope_mode' => (string)($scopeDisplay['mode'] ?? 'local'),
        'days_arr' => array_values((array)($rawRow['days_arr'] ?? [])),
        'time_start' => (string)($rawRow['time_start'] ?? ''),
        'time_end' => (string)($rawRow['time_end'] ?? ''),
        'schedule_status' => synk_schedule_status($rawRow),
        'days' => empty($rawRow['days_arr']) ? 'TBA' : implode(", ", $rawRow['days_arr']),
        'time' => synk_schedule_time_label($rawRow['time_start'], $rawRow['time_end']),
        'room' => trim((string)($rawRow['room'] ?? '')) !== '' ? (string)$rawRow['room'] : 'TBA',
        'lec_units' => round($lecUnits, 2),
        'lab_units' => round($labValue, 2),
        'subject_units' => round($subjectUnits, 2),
        'units' => round((float)($metrics['units'] ?? 0), 2),
        'lec' => round((float)($metrics['lec'] ?? 0), 2),
        'lab' => round((float)($metrics['lab'] ?? 0), 2),
        'lab_hours' => round((float)($metrics['lab_hours'] ?? 0), 2),
        'faculty_load' => round((float)($metrics['faculty_load'] ?? 0), 2),
        'student_count' => $studentCount
    ];
}

$designationName = '';
$designationUnits = 0.0;

if (!$isFacultyNeed && $college_id > 0 && $facultyHasDesignationId && $designationTableExists) {
    $designationWhere = [
        'cf.college_id = ?',
        'cf.faculty_id = ?',
        "cf.status = 'active'"
    ];
    $designationTypes = 'ii';
    $designationParams = [$college_id, $faculty_id];

    if ($assignmentHasAyId) {
        $designationWhere[] = 'cf.ay_id = ?';
        $designationTypes .= 'i';
        $designationParams[] = $ay_id;
    }

    if ($assignmentHasSemester) {
        $designationWhere[] = 'cf.semester = ?';
        $designationTypes .= 'i';
        $designationParams[] = $semester;
    }

    $designationSql = "
        SELECT
            d.designation_name,
            d.designation_units
        FROM tbl_college_faculty cf
        INNER JOIN tbl_faculty f
            ON f.faculty_id = cf.faculty_id
        LEFT JOIN tbl_designation d
            ON d.designation_id = f.designation_id
           " . ($designationHasStatus ? "AND d.status = 'active'" : '') . "
        WHERE " . implode("\n          AND ", $designationWhere) . "
        ORDER BY cf.college_faculty_id DESC
        LIMIT 1
    ";

    $designationStmt = $conn->prepare($designationSql);
    synk_bind_dynamic_params($designationStmt, $designationTypes, $designationParams);
    $designationStmt->execute();
    $designationRes = $designationStmt->get_result();
    $designationRow = $designationRes->fetch_assoc();
    $designationStmt->close();

    if (is_array($designationRow)) {
        $designationName = trim((string)($designationRow['designation_name'] ?? ''));
        $designationUnits = (float)($designationRow['designation_units'] ?? 0);
    }
}

$collegeRows = [];
$externalRows = [];

foreach ($rows as $row) {
    $isExternal = (int)($row['schedule_college_id'] ?? 0) !== $college_id;
    $row['is_external'] = $isExternal;

    if ($isExternal) {
        $externalRows[] = $row;
        continue;
    }

    $collegeRows[] = $row;
}

$printSignatories = workload_build_print_signatories($conn, $college_id, $ay_id, $semester, $collegeRows);

echo json_encode([
    'rows' => $collegeRows,
    'external_rows' => $externalRows,
    'signatories' => $printSignatories,
    'meta' => [
        'designation_name' => $designationName,
        'designation_label' => workload_title_case($designationName),
        'designation_units' => round($designationUnits, 2),
        'total_preparations' => count($preparations),
        'assignee_type' => $isFacultyNeed ? 'faculty_need' : 'faculty',
        'assignee_label' => $isFacultyNeed ? (string)($facultyNeed['need_label'] ?? '') : ''
    ]
]);
exit;
