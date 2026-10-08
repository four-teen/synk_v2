<?php
session_start();
include 'db.php';
require_once __DIR__ . '/faculty_need_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/schedule_block_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'scheduler') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.', 'faculty' => []]);
    exit;
}

$collegeId = (int)($_SESSION['college_id'] ?? 0);
$ayId = (int)($_POST['ay_id'] ?? 0);
$semester = (int)($_POST['semester'] ?? 0);
$facultyIds = $_POST['faculty_ids'] ?? [];
$facultyNeedIds = $_POST['faculty_need_ids'] ?? [];

if (!is_array($facultyIds)) {
    $facultyIds = [$facultyIds];
}

if (!is_array($facultyNeedIds)) {
    $facultyNeedIds = [$facultyNeedIds];
}

$facultyIds = array_values(array_unique(array_filter(array_map('intval', $facultyIds), static function ($value) {
    return $value > 0;
})));

$facultyNeedIds = array_values(array_unique(array_filter(array_map('intval', $facultyNeedIds), static function ($value) {
    return $value > 0;
})));

if ($collegeId <= 0 || $ayId <= 0 || $semester <= 0 || (empty($facultyIds) && empty($facultyNeedIds))) {
    echo json_encode(['status' => 'ok', 'faculty' => [], 'faculty_needs' => []]);
    exit;
}

function workload_overview_title_case(string $value): string
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

function workload_overview_context_key(int $groupId, int $scheduleId, int $offeringId): string
{
    if ($groupId > 0) {
        return 'group:' . $groupId;
    }

    if ($scheduleId > 0) {
        return 'schedule:' . $scheduleId;
    }

    return 'offering:' . $offeringId;
}

$liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
$classScheduleHasGroupId = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_group_id');
$classScheduleHasType = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type');
$facultyHasDesignationId = synk_table_has_column($conn, 'tbl_faculty', 'designation_id');
$facultyHasEmploymentClassification = synk_table_has_column($conn, 'tbl_faculty', 'employment_classification');
$designationTableExists = synk_table_exists($conn, 'tbl_designation');
$designationHasStatus = $designationTableExists && synk_table_has_column($conn, 'tbl_designation', 'status');
$facultyIdList = !empty($facultyIds) ? implode(',', array_map('intval', $facultyIds)) : '';

$summaries = [];
foreach ($facultyIds as $facultyId) {
    $summaries[$facultyId] = [
        'faculty_id' => $facultyId,
        'designation_name' => '',
        'designation_label' => '',
        'designation_units' => 0.0,
        'employment_classification' => '',
        'workload_load' => 0.0,
        'total_load' => 0.0,
        'total_preparations' => 0
    ];
}

if (!empty($facultyIds) && $facultyHasDesignationId && $designationTableExists) {
    $designationSql = "
        SELECT
            f.faculty_id,
            d.designation_name,
            d.designation_units
        FROM tbl_faculty f
        LEFT JOIN tbl_designation d
            ON d.designation_id = f.designation_id
           " . ($designationHasStatus ? "AND d.status = 'active'" : '') . "
        WHERE f.faculty_id IN ({$facultyIdList})
    ";

    $designationRes = $conn->query($designationSql);
    if ($designationRes instanceof mysqli_result) {
        while ($designationRow = $designationRes->fetch_assoc()) {
            $facultyId = (int)($designationRow['faculty_id'] ?? 0);
            if (!isset($summaries[$facultyId])) {
                continue;
            }

            $designationName = trim((string)($designationRow['designation_name'] ?? ''));
            $designationUnits = (float)($designationRow['designation_units'] ?? 0);
            $summaries[$facultyId]['designation_name'] = $designationName;
            $summaries[$facultyId]['designation_label'] = workload_overview_title_case($designationName);
            $summaries[$facultyId]['designation_units'] = round($designationUnits, 2);
        }
        $designationRes->free();
    }
}

if (!empty($facultyIds) && $facultyHasEmploymentClassification) {
    $classificationSql = "
        SELECT
            faculty_id,
            COALESCE(NULLIF(TRIM(employment_classification), ''), '') AS employment_classification
        FROM tbl_faculty
        WHERE faculty_id IN ({$facultyIdList})
    ";

    $classificationRes = $conn->query($classificationSql);
    if ($classificationRes instanceof mysqli_result) {
        while ($classificationRow = $classificationRes->fetch_assoc()) {
            $facultyId = (int)($classificationRow['faculty_id'] ?? 0);
            if (!isset($summaries[$facultyId])) {
                continue;
            }

            $summaries[$facultyId]['employment_classification'] = strtolower(
                trim((string)($classificationRow['employment_classification'] ?? ''))
            );
        }
        $classificationRes->free();
    }
}

$rowsByFacultyContext = [];
$preparationMap = [];
$offeringIds = [];

if (!empty($facultyIds)) {
    $selectParts = [
        'fw.faculty_id',
        'cs.schedule_id',
        'o.offering_id',
        $classScheduleHasGroupId ? 'cs.schedule_group_id AS group_id' : 'NULL AS group_id',
        $classScheduleHasType ? 'cs.schedule_type AS schedule_type' : "'LEC' AS schedule_type",
        'sm.sub_code',
        'ps.lec_units',
        'ps.lab_units',
        'ps.total_units'
    ];

    $orderParts = [
        'fw.faculty_id',
        'sec.section_name',
        'sm.sub_code',
        $classScheduleHasGroupId ? 'COALESCE(cs.schedule_group_id, cs.schedule_id)' : 'cs.schedule_id'
    ];

    if ($classScheduleHasType) {
        $orderParts[] = "FIELD(cs.schedule_type, 'LEC', 'LAB')";
    }

    $rawSql = "
        SELECT
            " . implode(",\n            ", $selectParts) . "
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        WHERE fw.ay_id = ?
          AND fw.semester = ?
          AND fw.faculty_id IN ({$facultyIdList})
        ORDER BY " . implode(",\n                 ", $orderParts) . "
    ";

    $rawStmt = $conn->prepare($rawSql);
    if ($rawStmt instanceof mysqli_stmt) {
        $rawStmt->bind_param("ii", $ayId, $semester);
        $rawStmt->execute();
        $rawRes = $rawStmt->get_result();

        while ($row = $rawRes->fetch_assoc()) {
            $facultyId = (int)($row['faculty_id'] ?? 0);
            if (!isset($summaries[$facultyId])) {
                continue;
            }

            $offeringId = (int)($row['offering_id'] ?? 0);
            $contextKey = workload_overview_context_key(
                (int)($row['group_id'] ?? 0),
                (int)($row['schedule_id'] ?? 0),
                $offeringId
            );

            if (!isset($rowsByFacultyContext[$facultyId])) {
                $rowsByFacultyContext[$facultyId] = [];
            }

            if (!isset($rowsByFacultyContext[$facultyId][$contextKey])) {
                $rowsByFacultyContext[$facultyId][$contextKey] = [];
            }

            $rowsByFacultyContext[$facultyId][$contextKey][] = [
                'schedule_type' => (string)($row['schedule_type'] ?? 'LEC'),
                'lec_units' => (float)($row['lec_units'] ?? 0),
                'lab_units' => (float)($row['lab_units'] ?? 0),
                'total_units' => (float)($row['total_units'] ?? 0)
            ];

            if ($offeringId > 0) {
                $offeringIds[$offeringId] = true;
            }

            $preparationKey = trim((string)($row['sub_code'] ?? ''));
            if ($preparationKey !== '') {
                if (!isset($preparationMap[$facultyId])) {
                    $preparationMap[$facultyId] = [];
                }
                $preparationMap[$facultyId][$preparationKey] = true;
            }
        }

        $rawStmt->close();
    }
}

$facultyNeedSummaries = [];
$rowsByFacultyNeedContext = [];
$facultyNeedPreparationMap = [];

if (!empty($facultyNeedIds)) {
    synk_faculty_need_ensure_tables($conn);
    $facultyNeedIdList = implode(',', array_map('intval', $facultyNeedIds));

    $validNeedSql = "
        SELECT faculty_need_id
        FROM `" . synk_faculty_need_table_name() . "`
        WHERE college_id = ?
          AND ay_id = ?
          AND semester = ?
          AND status = 'active'
          AND faculty_need_id IN ({$facultyNeedIdList})
    ";

    $validNeedStmt = $conn->prepare($validNeedSql);
    if ($validNeedStmt instanceof mysqli_stmt) {
        $validNeedStmt->bind_param('iii', $collegeId, $ayId, $semester);
        $validNeedStmt->execute();
        $validNeedRes = $validNeedStmt->get_result();

        if ($validNeedRes instanceof mysqli_result) {
            while ($needRow = $validNeedRes->fetch_assoc()) {
                $facultyNeedId = (int)($needRow['faculty_need_id'] ?? 0);
                if ($facultyNeedId <= 0) {
                    continue;
                }

                $facultyNeedSummaries[$facultyNeedId] = [
                    'faculty_need_id' => $facultyNeedId,
                    'workload_load' => 0.0,
                    'total_load' => 0.0,
                    'total_preparations' => 0
                ];
            }
        }

        $validNeedStmt->close();
    }

    if (!empty($facultyNeedSummaries)) {
        $validFacultyNeedIdList = implode(',', array_map('intval', array_keys($facultyNeedSummaries)));
        $needSelectParts = [
            'fw.faculty_need_id',
            'cs.schedule_id',
            'o.offering_id',
            $classScheduleHasGroupId ? 'cs.schedule_group_id AS group_id' : 'NULL AS group_id',
            $classScheduleHasType ? 'cs.schedule_type AS schedule_type' : "'LEC' AS schedule_type",
            'sm.sub_code',
            'ps.lec_units',
            'ps.lab_units',
            'ps.total_units'
        ];

        $needOrderParts = [
            'fw.faculty_need_id',
            'sec.section_name',
            'sm.sub_code',
            $classScheduleHasGroupId ? 'COALESCE(cs.schedule_group_id, cs.schedule_id)' : 'cs.schedule_id'
        ];

        if ($classScheduleHasType) {
            $needOrderParts[] = "FIELD(cs.schedule_type, 'LEC', 'LAB')";
        }

        $needRawSql = "
            SELECT
                " . implode(",\n                ", $needSelectParts) . "
            FROM `" . synk_faculty_need_workload_table_name() . "` fw
            INNER JOIN tbl_class_schedule cs
                ON cs.schedule_id = fw.schedule_id
            INNER JOIN tbl_prospectus_offering o
                ON o.offering_id = cs.offering_id
            {$liveOfferingJoins}
            INNER JOIN tbl_program p
                ON p.program_id = o.program_id
            INNER JOIN tbl_subject_masterlist sm
                ON sm.sub_id = ps.sub_id
            WHERE fw.ay_id = ?
              AND fw.semester = ?
              AND fw.faculty_need_id IN ({$validFacultyNeedIdList})
            ORDER BY " . implode(",\n                     ", $needOrderParts) . "
        ";

        $needRawStmt = $conn->prepare($needRawSql);
        if ($needRawStmt instanceof mysqli_stmt) {
            $needRawStmt->bind_param('ii', $ayId, $semester);
            $needRawStmt->execute();
            $needRawRes = $needRawStmt->get_result();

            while ($needRow = $needRawRes->fetch_assoc()) {
                $facultyNeedId = (int)($needRow['faculty_need_id'] ?? 0);
                if (!isset($facultyNeedSummaries[$facultyNeedId])) {
                    continue;
                }

                $offeringId = (int)($needRow['offering_id'] ?? 0);
                $contextKey = workload_overview_context_key(
                    (int)($needRow['group_id'] ?? 0),
                    (int)($needRow['schedule_id'] ?? 0),
                    $offeringId
                );

                if (!isset($rowsByFacultyNeedContext[$facultyNeedId])) {
                    $rowsByFacultyNeedContext[$facultyNeedId] = [];
                }

                if (!isset($rowsByFacultyNeedContext[$facultyNeedId][$contextKey])) {
                    $rowsByFacultyNeedContext[$facultyNeedId][$contextKey] = [];
                }

                $rowsByFacultyNeedContext[$facultyNeedId][$contextKey][] = [
                    'schedule_type' => (string)($needRow['schedule_type'] ?? 'LEC'),
                    'lec_units' => (float)($needRow['lec_units'] ?? 0),
                    'lab_units' => (float)($needRow['lab_units'] ?? 0),
                    'total_units' => (float)($needRow['total_units'] ?? 0)
                ];

                if ($offeringId > 0) {
                    $offeringIds[$offeringId] = true;
                }

                $preparationKey = trim((string)($needRow['sub_code'] ?? ''));
                if ($preparationKey !== '') {
                    if (!isset($facultyNeedPreparationMap[$facultyNeedId])) {
                        $facultyNeedPreparationMap[$facultyNeedId] = [];
                    }
                    $facultyNeedPreparationMap[$facultyNeedId][$preparationKey] = true;
                }
            }

            $needRawStmt->close();
        }
    }
}

$contextTotals = [];
if (!empty($offeringIds)) {
    $offeringIdList = implode(',', array_map('intval', array_keys($offeringIds)));
    $contextSelectParts = [
        'cs.schedule_id',
        'cs.offering_id',
        $classScheduleHasGroupId ? 'cs.schedule_group_id AS group_id' : 'NULL AS group_id',
        $classScheduleHasType ? 'cs.schedule_type AS schedule_type' : "'LEC' AS schedule_type"
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
            $contextKey = workload_overview_context_key(
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
            if (strtoupper(trim((string)($contextRow['schedule_type'] ?? 'LEC'))) === 'LAB') {
                $contextTotals[$contextKey]['lab_count']++;
                continue;
            }

            $contextTotals[$contextKey]['lec_count']++;
        }
        $contextRes->free();
    }
}

foreach ($summaries as $facultyId => &$summary) {
    $contextRows = $rowsByFacultyContext[$facultyId] ?? [];
    $totalLoad = 0.0;

    foreach ($contextRows as $contextKey => $rows) {
        $metrics = synk_schedule_sum_display_metrics($rows, $contextTotals[$contextKey] ?? []);
        $totalLoad += (float)($metrics['faculty_load'] ?? 0);
    }

    $summary['workload_load'] = round($totalLoad, 2);
    $summary['total_preparations'] = count($preparationMap[$facultyId] ?? []);
    $summary['total_load'] = round($summary['workload_load'] + (float)$summary['designation_units'], 2);
}
unset($summary);

foreach ($facultyNeedSummaries as $facultyNeedId => &$facultyNeedSummary) {
    $contextRows = $rowsByFacultyNeedContext[$facultyNeedId] ?? [];
    $totalLoad = 0.0;

    foreach ($contextRows as $contextKey => $rows) {
        $metrics = synk_schedule_sum_display_metrics($rows, $contextTotals[$contextKey] ?? []);
        $totalLoad += (float)($metrics['faculty_load'] ?? 0);
    }

    $facultyNeedSummary['workload_load'] = round($totalLoad, 2);
    $facultyNeedSummary['total_preparations'] = count($facultyNeedPreparationMap[$facultyNeedId] ?? []);
    $facultyNeedSummary['total_load'] = round($facultyNeedSummary['workload_load'], 2);
}
unset($facultyNeedSummary);

echo json_encode([
    'status' => 'ok',
    'faculty' => array_values($summaries),
    'faculty_needs' => array_values($facultyNeedSummaries)
]);
exit;
?>
