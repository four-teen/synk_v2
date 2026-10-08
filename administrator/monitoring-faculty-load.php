<?php
session_start();
ob_start();

include '../backend/db.php';
require_once '../backend/academic_term_helper.php';
require_once '../backend/offering_scope_helper.php';
require_once '../backend/schema_helper.php';
require_once '../backend/schedule_block_helper.php';
require_once '../backend/faculty_need_helper.php';

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit;
}

function admin_monitoring_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_monitoring_title_case(string $value): string
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

function admin_monitoring_format_faculty_name(array $row): string
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

function admin_monitoring_context_key(int $groupId, int $scheduleId, int $offeringId): string
{
    if ($groupId > 0) {
        return 'group:' . $groupId;
    }

    if ($scheduleId > 0) {
        return 'schedule:' . $scheduleId;
    }

    return 'offering:' . $offeringId;
}

function admin_monitoring_unique_label_values(array $labels): array
{
    $unique = [];

    foreach ($labels as $label) {
        $text = trim((string)$label);
        if ($text === '') {
            continue;
        }

        $unique[strtolower($text)] = $text;
    }

    $values = array_values($unique);
    natcasesort($values);

    return array_values($values);
}

function admin_monitoring_join_label_values(array $labels, string $fallback = ''): string
{
    $values = admin_monitoring_unique_label_values($labels);
    if (empty($values)) {
        return $fallback;
    }

    return implode(' | ', $values);
}

function admin_monitoring_apply_faculty_scope_summary(array &$row): void
{
    if ((string)($row['assignee_type'] ?? '') !== 'faculty') {
        return;
    }

    $campusValues = admin_monitoring_unique_label_values((array)($row['campus_labels_map'] ?? []));
    $collegeValues = admin_monitoring_unique_label_values((array)($row['college_labels_map'] ?? []));
    $scopeValues = admin_monitoring_unique_label_values((array)($row['scope_labels_map'] ?? []));

    $row['campus_label'] = !empty($campusValues) ? implode(' | ', $campusValues) : 'Unassigned campus';
    $row['college_label'] = !empty($collegeValues) ? implode(' | ', $collegeValues) : 'Unassigned college';
    $row['campus_college_label'] = !empty($scopeValues)
        ? implode(' | ', $scopeValues)
        : trim($row['campus_label'] . ' - ' . $row['college_label'], ' -');
    $row['scope_count'] = count($scopeValues);
}

function admin_monitoring_load_status(float $loadValue, int $preparationCount, bool $analyticsReady): array
{
    if (!$analyticsReady) {
        return [
            'key' => 'pending',
            'label' => 'Pending term',
            'badge_class' => 'is-pending',
        ];
    }

    $normalLoadUnits = $preparationCount >= 2 ? 18.0 : 21.0;
    $tolerance = 0.0001;

    if ($loadValue > $normalLoadUnits + $tolerance) {
        return [
            'key' => 'overload',
            'label' => 'Overload',
            'badge_class' => 'is-overload',
        ];
    }

    if ($loadValue >= $normalLoadUnits - $tolerance) {
        return [
            'key' => 'normal',
            'label' => 'Normal',
            'badge_class' => 'is-normal',
        ];
    }

    return [
        'key' => 'underload',
        'label' => 'Underload',
        'badge_class' => 'is-underload',
    ];
}

$currentTerm = synk_fetch_current_academic_term($conn);
$currentAyId = (int)($currentTerm['ay_id'] ?? 0);
$currentSem = (int)($currentTerm['semester'] ?? 0);
$academicTermText = trim((string)($currentTerm['term_text'] ?? 'Current academic term'));
$academicTermText = $academicTermText !== '' ? $academicTermText : 'Current academic term';
$classScheduleHasGroupId = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_group_id');
$classScheduleHasType = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type');
$facultyWorkloadSourceReady = $currentAyId > 0
    && $currentSem > 0
    && synk_table_exists($conn, 'tbl_faculty_workload_sched');
$facultyNeedWorkloadSourceReady = $currentAyId > 0
    && $currentSem > 0
    && synk_table_exists($conn, synk_faculty_need_workload_table_name());
$analyticsReady = $facultyWorkloadSourceReady || $facultyNeedWorkloadSourceReady;

$monitorRowsByKey = [];

if (
    synk_table_exists($conn, 'tbl_faculty')
    && synk_table_exists($conn, 'tbl_college_faculty')
    && synk_table_exists($conn, 'tbl_college')
    && synk_table_exists($conn, 'tbl_campus')
) {
    $assignmentHasAyId = synk_table_has_column($conn, 'tbl_college_faculty', 'ay_id');
    $assignmentHasSemester = synk_table_has_column($conn, 'tbl_college_faculty', 'semester');
    $facultyHasMiddleName = synk_table_has_column($conn, 'tbl_faculty', 'middle_name');
    $facultyHasExtName = synk_table_has_column($conn, 'tbl_faculty', 'ext_name');
    $facultyHasDesignationId = synk_table_has_column($conn, 'tbl_faculty', 'designation_id');
    $facultyHasStatus = synk_table_has_column($conn, 'tbl_faculty', 'status');
    $facultyDesignationTextColumn = null;

    foreach (['designation', 'designation_name'] as $candidateColumn) {
        if (synk_table_has_column($conn, 'tbl_faculty', $candidateColumn)) {
            $facultyDesignationTextColumn = $candidateColumn;
            break;
        }
    }

    $designationTableExists = synk_table_exists($conn, 'tbl_designation');
    $designationHasName = $designationTableExists && synk_table_has_column($conn, 'tbl_designation', 'designation_name');
    $designationHasUnits = $designationTableExists && synk_table_has_column($conn, 'tbl_designation', 'designation_units');
    $designationHasStatus = $designationTableExists && synk_table_has_column($conn, 'tbl_designation', 'status');

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

    $assignmentWhere = ["LOWER(TRIM(COALESCE(cf.status, 'active'))) = 'active'"];
    $assignmentTypes = '';
    $assignmentParams = [];

    if ($facultyHasStatus) {
        $assignmentWhere[] = "LOWER(TRIM(COALESCE(f.status, 'active'))) = 'active'";
    }

    if ($assignmentHasAyId && $assignmentHasSemester && $currentAyId > 0 && $currentSem > 0) {
        $assignmentWhere[] = '((cf.ay_id = ? AND cf.semester = ?) OR (cf.ay_id IS NULL AND cf.semester IS NULL))';
        $assignmentTypes .= 'ii';
        $assignmentParams[] = $currentAyId;
        $assignmentParams[] = $currentSem;
    }

    $assignmentSql = "
        SELECT DISTINCT
            f.faculty_id,
            f.last_name,
            f.first_name,
            " . ($facultyHasMiddleName ? 'f.middle_name' : 'NULL AS middle_name') . ",
            " . ($facultyHasExtName ? 'f.ext_name' : 'NULL AS ext_name') . ",
            camp.campus_id,
            camp.campus_name,
            col.college_id,
            col.college_name,
            {$designationSelectId} AS designation_id,
            {$designationNameExpr} AS designation_name,
            {$designationUnitsExpr} AS designation_units
        FROM tbl_college_faculty cf
        INNER JOIN tbl_faculty f
            ON f.faculty_id = cf.faculty_id
        INNER JOIN tbl_college col
            ON col.college_id = cf.college_id
        INNER JOIN tbl_campus camp
            ON camp.campus_id = col.campus_id
        {$designationJoinSql}
        WHERE " . implode("\n          AND ", $assignmentWhere) . "
        ORDER BY
            camp.campus_name ASC,
            col.college_name ASC,
            f.last_name ASC,
            f.first_name ASC,
            f.faculty_id ASC
    ";

    $assignmentStmt = $conn->prepare($assignmentSql);
    if ($assignmentStmt instanceof mysqli_stmt && synk_bind_dynamic_params($assignmentStmt, $assignmentTypes, $assignmentParams)) {
        $assignmentStmt->execute();
        $assignmentResult = $assignmentStmt->get_result();

        if ($assignmentResult instanceof mysqli_result) {
            while ($row = $assignmentResult->fetch_assoc()) {
                $facultyId = (int)($row['faculty_id'] ?? 0);
                $collegeId = (int)($row['college_id'] ?? 0);
                $campusId = (int)($row['campus_id'] ?? 0);
                if ($facultyId <= 0 || $collegeId <= 0 || $campusId <= 0) {
                    continue;
                }

                $designationName = trim((string)($row['designation_name'] ?? ''));
                $campusLabel = trim((string)($row['campus_name'] ?? ''));
                $collegeLabel = trim((string)($row['college_name'] ?? ''));
                $assignmentKey = 'faculty:' . $facultyId;

                if (!isset($monitorRowsByKey[$assignmentKey])) {
                    $monitorRowsByKey[$assignmentKey] = [
                        'assignment_key' => $assignmentKey,
                        'assignee_type' => 'faculty',
                        'assignee_type_label' => 'Faculty',
                        'faculty_id' => $facultyId,
                        'faculty_need_id' => 0,
                        'college_id' => 0,
                        'campus_id' => 0,
                        'full_name' => admin_monitoring_format_faculty_name($row),
                        'last_name' => trim((string)($row['last_name'] ?? '')),
                        'first_name' => trim((string)($row['first_name'] ?? '')),
                        'middle_name' => trim((string)($row['middle_name'] ?? '')),
                        'ext_name' => trim((string)($row['ext_name'] ?? '')),
                        'campus_label' => $campusLabel,
                        'college_label' => $collegeLabel,
                        'campus_college_label' => trim($campusLabel . ' - ' . $collegeLabel, ' -'),
                        'designation_id' => (int)($row['designation_id'] ?? 0),
                        'designation_name' => $designationName,
                        'designation_label' => admin_monitoring_title_case($designationName),
                        'designation_units' => round((float)($row['designation_units'] ?? 0), 2),
                        'need_notes' => '',
                        'workload_units' => 0.0,
                        'workload_load' => 0.0,
                        'total_units' => round((float)($row['designation_units'] ?? 0), 2),
                        'total_load' => round((float)($row['designation_units'] ?? 0), 2),
                        'total_preparations' => 0,
                        'status_ready' => $facultyWorkloadSourceReady,
                        'status_key' => 'pending',
                        'status_label' => 'Pending term',
                        'status_badge_class' => 'is-pending',
                        'campus_labels_map' => [],
                        'college_labels_map' => [],
                        'scope_labels_map' => [],
                        'scope_count' => 0,
                    ];
                }

                $monitorRowsByKey[$assignmentKey]['campus_labels_map'][$campusId] = $campusLabel;
                $monitorRowsByKey[$assignmentKey]['college_labels_map'][$collegeId] = $collegeLabel;
                $monitorRowsByKey[$assignmentKey]['scope_labels_map'][$campusId . ':' . $collegeId] = trim($campusLabel . ' - ' . $collegeLabel, ' -');
                admin_monitoring_apply_faculty_scope_summary($monitorRowsByKey[$assignmentKey]);
            }
        }

        $assignmentStmt->close();
    } elseif ($assignmentStmt instanceof mysqli_stmt) {
        $assignmentStmt->close();
    }

    if (!empty($monitorRowsByKey) && $facultyWorkloadSourceReady) {
        $facultyIds = [];
        foreach ($monitorRowsByKey as $row) {
            if ((string)($row['assignee_type'] ?? 'faculty') !== 'faculty') {
                continue;
            }

            $facultyIds[(int)$row['faculty_id']] = true;
        }

        if (!empty($facultyIds)) {
            $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
            $facultyIdList = implode(',', array_map('intval', array_keys($facultyIds)));

            $workloadSql = "
                SELECT
                    fw.faculty_id,
                    cs.schedule_id,
                    o.offering_id,
                    " . ($classScheduleHasGroupId ? 'cs.schedule_group_id AS group_id' : 'NULL AS group_id') . ",
                    " . ($classScheduleHasType ? 'cs.schedule_type AS schedule_type' : "'LEC' AS schedule_type") . ",
                    sm.sub_code,
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
                ORDER BY
                    fw.faculty_id ASC,
                    cs.schedule_id ASC
            ";

            $workloadStmt = $conn->prepare($workloadSql);
            $rowsByFacultyContext = [];
            $preparationMap = [];
            $offeringIds = [];

            if ($workloadStmt instanceof mysqli_stmt) {
                $workloadStmt->bind_param('iiii', $currentAyId, $currentSem, $currentAyId, $currentSem);
                $workloadStmt->execute();
                $workloadResult = $workloadStmt->get_result();

                if ($workloadResult instanceof mysqli_result) {
                    while ($workloadRow = $workloadResult->fetch_assoc()) {
                        $facultyId = (int)($workloadRow['faculty_id'] ?? 0);
                        $scheduleId = (int)($workloadRow['schedule_id'] ?? 0);
                        $offeringId = (int)($workloadRow['offering_id'] ?? 0);
                        if ($facultyId <= 0) {
                            continue;
                        }

                        $contextKey = admin_monitoring_context_key(
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

                        $subCode = trim((string)($workloadRow['sub_code'] ?? ''));
                        if ($subCode !== '') {
                            $preparationMap[$facultyId][$subCode] = true;
                        }

                        if ($offeringId > 0) {
                            $offeringIds[$offeringId] = true;
                        }
                    }
                }

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
                        $contextKey = admin_monitoring_context_key(
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

            $facultyTotalsById = [];
            foreach ($rowsByFacultyContext as $facultyId => $contextRowsByKey) {
                $workloadUnits = 0.0;
                $workloadLoad = 0.0;

                foreach ($contextRowsByKey as $contextKey => $contextRows) {
                    $metrics = synk_schedule_sum_display_metrics($contextRows, $contextTotals[$contextKey] ?? []);
                    $workloadUnits += (float)($metrics['units'] ?? 0);
                    $workloadLoad += (float)($metrics['faculty_load'] ?? 0);
                }

                $facultyTotalsById[$facultyId] = [
                    'workload_units' => round($workloadUnits, 2),
                    'workload_load' => round($workloadLoad, 2),
                    'total_preparations' => count($preparationMap[$facultyId] ?? []),
                ];
            }

            foreach ($monitorRowsByKey as $assignmentKey => $row) {
                if ((string)($row['assignee_type'] ?? 'faculty') !== 'faculty') {
                    continue;
                }

                $facultyTotals = $facultyTotalsById[(int)$row['faculty_id']] ?? [
                    'workload_units' => 0.0,
                    'workload_load' => 0.0,
                    'total_preparations' => 0,
                ];

                $monitorRowsByKey[$assignmentKey]['workload_units'] = round((float)$facultyTotals['workload_units'], 2);
                $monitorRowsByKey[$assignmentKey]['workload_load'] = round((float)$facultyTotals['workload_load'], 2);
                $monitorRowsByKey[$assignmentKey]['total_preparations'] = (int)$facultyTotals['total_preparations'];
                $monitorRowsByKey[$assignmentKey]['total_units'] = round(
                    (float)$facultyTotals['workload_units'] + (float)$row['designation_units'],
                    2
                );
                $monitorRowsByKey[$assignmentKey]['total_load'] = round(
                    (float)$facultyTotals['workload_load'] + (float)$row['designation_units'],
                    2
                );
            }
        }
    }
}

if (
    $currentAyId > 0
    && $currentSem > 0
    && synk_table_exists($conn, synk_faculty_need_table_name())
    && synk_table_exists($conn, 'tbl_college')
    && synk_table_exists($conn, 'tbl_campus')
) {
    $facultyNeedStatusColumnExists = synk_table_has_column($conn, synk_faculty_need_table_name(), 'status');
    $facultyNeedWhere = [
        'n.ay_id = ?',
        'n.semester = ?',
    ];

    if ($facultyNeedStatusColumnExists) {
        $facultyNeedWhere[] = "LOWER(TRIM(COALESCE(n.status, 'active'))) = 'active'";
    }

    $facultyNeedSql = "
        SELECT
            n.faculty_need_id,
            n.need_label,
            COALESCE(n.notes, '') AS notes,
            col.college_id,
            col.college_name,
            camp.campus_id,
            camp.campus_name
        FROM `" . synk_faculty_need_table_name() . "` n
        INNER JOIN tbl_college col
            ON col.college_id = n.college_id
        INNER JOIN tbl_campus camp
            ON camp.campus_id = col.campus_id
        WHERE " . implode("\n          AND ", $facultyNeedWhere) . "
        ORDER BY
            camp.campus_name ASC,
            col.college_name ASC,
            n.need_label ASC,
            n.faculty_need_id ASC
    ";

    $facultyNeedStmt = $conn->prepare($facultyNeedSql);
    if ($facultyNeedStmt instanceof mysqli_stmt) {
        $facultyNeedStmt->bind_param('ii', $currentAyId, $currentSem);
        $facultyNeedStmt->execute();
        $facultyNeedResult = $facultyNeedStmt->get_result();

        if ($facultyNeedResult instanceof mysqli_result) {
            while ($facultyNeedRow = $facultyNeedResult->fetch_assoc()) {
                $facultyNeedId = (int)($facultyNeedRow['faculty_need_id'] ?? 0);
                $collegeId = (int)($facultyNeedRow['college_id'] ?? 0);
                $campusId = (int)($facultyNeedRow['campus_id'] ?? 0);
                $needLabel = trim((string)($facultyNeedRow['need_label'] ?? ''));

                if ($facultyNeedId <= 0 || $collegeId <= 0 || $campusId <= 0 || $needLabel === '') {
                    continue;
                }

                $assignmentKey = 'need:' . $facultyNeedId;

                $monitorRowsByKey[$assignmentKey] = [
                    'assignment_key' => $assignmentKey,
                    'assignee_type' => 'faculty_need',
                    'assignee_type_label' => 'Faculty Need',
                    'faculty_id' => 0,
                    'faculty_need_id' => $facultyNeedId,
                    'college_id' => $collegeId,
                    'campus_id' => $campusId,
                    'full_name' => $needLabel,
                    'last_name' => '',
                    'first_name' => '',
                    'middle_name' => '',
                    'ext_name' => '',
                    'campus_label' => trim((string)($facultyNeedRow['campus_name'] ?? '')),
                    'college_label' => trim((string)($facultyNeedRow['college_name'] ?? '')),
                    'campus_college_label' => trim((string)($facultyNeedRow['campus_name'] ?? '')) . ' - ' . trim((string)($facultyNeedRow['college_name'] ?? '')),
                    'designation_id' => 0,
                    'designation_name' => '',
                    'designation_label' => '',
                    'designation_units' => 0.0,
                    'need_notes' => trim((string)($facultyNeedRow['notes'] ?? '')),
                    'workload_units' => 0.0,
                    'workload_load' => 0.0,
                    'total_units' => 0.0,
                    'total_load' => 0.0,
                    'total_preparations' => 0,
                    'status_ready' => $facultyNeedWorkloadSourceReady,
                    'status_key' => 'pending',
                    'status_label' => 'Pending term',
                    'status_badge_class' => 'is-pending',
                ];
            }
        }

        $facultyNeedStmt->close();
    }
}

if (!empty($monitorRowsByKey) && $facultyNeedWorkloadSourceReady) {
    $facultyNeedIds = [];
    $campusIds = [];

    foreach ($monitorRowsByKey as $row) {
        if ((string)($row['assignee_type'] ?? '') !== 'faculty_need') {
            continue;
        }

        $facultyNeedIds[(int)($row['faculty_need_id'] ?? 0)] = true;
        $campusIds[(int)($row['campus_id'] ?? 0)] = true;
    }

    $facultyNeedIds = array_filter(array_keys($facultyNeedIds), static function ($value) {
        return (int)$value > 0;
    });
    $campusIds = array_filter(array_keys($campusIds), static function ($value) {
        return (int)$value > 0;
    });

    if (!empty($facultyNeedIds) && !empty($campusIds)) {
        $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
        $facultyNeedIdList = implode(',', array_map('intval', $facultyNeedIds));
        $campusIdList = implode(',', array_map('intval', $campusIds));

        $workloadSql = "
            SELECT
                fw.faculty_need_id,
                cs.schedule_id,
                o.offering_id,
                " . ($classScheduleHasGroupId ? 'cs.schedule_group_id AS group_id' : 'NULL AS group_id') . ",
                " . ($classScheduleHasType ? 'cs.schedule_type AS schedule_type' : "'LEC' AS schedule_type") . ",
                camp.campus_id AS schedule_campus_id,
                sm.sub_code,
                ps.lec_units,
                ps.lab_units,
                ps.total_units
            FROM `" . synk_faculty_need_workload_table_name() . "` fw
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
              AND fw.faculty_need_id IN ({$facultyNeedIdList})
              AND camp.campus_id IN ({$campusIdList})
            ORDER BY
                fw.faculty_need_id ASC,
                camp.campus_id ASC,
                cs.schedule_id ASC
        ";

        $workloadStmt = $conn->prepare($workloadSql);
        $rowsByNeedCampusContext = [];
        $preparationMap = [];
        $offeringIds = [];

        if ($workloadStmt instanceof mysqli_stmt) {
            $workloadStmt->bind_param('iiii', $currentAyId, $currentSem, $currentAyId, $currentSem);
            $workloadStmt->execute();
            $workloadResult = $workloadStmt->get_result();

            if ($workloadResult instanceof mysqli_result) {
                while ($workloadRow = $workloadResult->fetch_assoc()) {
                    $facultyNeedId = (int)($workloadRow['faculty_need_id'] ?? 0);
                    $campusId = (int)($workloadRow['schedule_campus_id'] ?? 0);
                    $scheduleId = (int)($workloadRow['schedule_id'] ?? 0);
                    $offeringId = (int)($workloadRow['offering_id'] ?? 0);
                    if ($facultyNeedId <= 0 || $campusId <= 0) {
                        continue;
                    }

                    $contextKey = admin_monitoring_context_key(
                        (int)($workloadRow['group_id'] ?? 0),
                        $scheduleId,
                        $offeringId
                    );

                    $rowsByNeedCampusContext[$facultyNeedId][$campusId][$contextKey][] = [
                        'schedule_type' => (string)($workloadRow['schedule_type'] ?? 'LEC'),
                        'lec_units' => (float)($workloadRow['lec_units'] ?? 0),
                        'lab_units' => (float)($workloadRow['lab_units'] ?? 0),
                        'total_units' => (float)($workloadRow['total_units'] ?? 0),
                    ];

                    $subCode = trim((string)($workloadRow['sub_code'] ?? ''));
                    if ($subCode !== '') {
                        $preparationMap[$facultyNeedId][$campusId][$subCode] = true;
                    }

                    if ($offeringId > 0) {
                        $offeringIds[$offeringId] = true;
                    }
                }
            }

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
                    $contextKey = admin_monitoring_context_key(
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

        $needCampusTotalsByKey = [];
        foreach ($rowsByNeedCampusContext as $facultyNeedId => $campusContexts) {
            foreach ($campusContexts as $campusId => $contextRowsByKey) {
                $workloadUnits = 0.0;
                $workloadLoad = 0.0;

                foreach ($contextRowsByKey as $contextKey => $contextRows) {
                    $metrics = synk_schedule_sum_display_metrics($contextRows, $contextTotals[$contextKey] ?? []);
                    $workloadUnits += (float)($metrics['units'] ?? 0);
                    $workloadLoad += (float)($metrics['faculty_load'] ?? 0);
                }

                $needCampusTotalsByKey[$facultyNeedId . ':' . $campusId] = [
                    'workload_units' => round($workloadUnits, 2),
                    'workload_load' => round($workloadLoad, 2),
                    'total_preparations' => count($preparationMap[$facultyNeedId][$campusId] ?? []),
                ];
            }
        }

        foreach ($monitorRowsByKey as $assignmentKey => $row) {
            if ((string)($row['assignee_type'] ?? '') !== 'faculty_need') {
                continue;
            }

            $campusKey = (int)($row['faculty_need_id'] ?? 0) . ':' . (int)($row['campus_id'] ?? 0);
            $campusTotals = $needCampusTotalsByKey[$campusKey] ?? [
                'workload_units' => 0.0,
                'workload_load' => 0.0,
                'total_preparations' => 0,
            ];

            $monitorRowsByKey[$assignmentKey]['workload_units'] = round((float)$campusTotals['workload_units'], 2);
            $monitorRowsByKey[$assignmentKey]['workload_load'] = round((float)$campusTotals['workload_load'], 2);
            $monitorRowsByKey[$assignmentKey]['total_preparations'] = (int)$campusTotals['total_preparations'];
            $monitorRowsByKey[$assignmentKey]['total_units'] = round((float)$campusTotals['workload_units'], 2);
            $monitorRowsByKey[$assignmentKey]['total_load'] = round((float)$campusTotals['workload_load'], 2);
        }
    }
}

$monitorRows = array_values($monitorRowsByKey);
foreach ($monitorRows as &$monitorRow) {
    if ((string)($monitorRow['assignee_type'] ?? '') === 'faculty') {
        admin_monitoring_apply_faculty_scope_summary($monitorRow);
    }

    $status = admin_monitoring_load_status(
        (float)($monitorRow['total_load'] ?? 0),
        (int)($monitorRow['total_preparations'] ?? 0),
        (bool)($monitorRow['status_ready'] ?? $analyticsReady)
    );
    $monitorRow['status_key'] = (string)$status['key'];
    $monitorRow['status_label'] = (string)$status['label'];
    $monitorRow['status_badge_class'] = (string)$status['badge_class'];
    unset(
        $monitorRow['campus_labels_map'],
        $monitorRow['college_labels_map'],
        $monitorRow['scope_labels_map']
    );
}
unset($monitorRow);

usort($monitorRows, static function (array $left, array $right): int {
    $loadCompare = ((float)($right['total_load'] ?? 0)) <=> ((float)($left['total_load'] ?? 0));
    if ($loadCompare !== 0) {
        return $loadCompare;
    }

    $unitCompare = ((float)($right['total_units'] ?? 0)) <=> ((float)($left['total_units'] ?? 0));
    if ($unitCompare !== 0) {
        return $unitCompare;
    }

    $prepCompare = ((int)($right['total_preparations'] ?? 0)) <=> ((int)($left['total_preparations'] ?? 0));
    if ($prepCompare !== 0) {
        return $prepCompare;
    }

    $scopeCompare = strnatcasecmp((string)($left['campus_college_label'] ?? ''), (string)($right['campus_college_label'] ?? ''));
    if ($scopeCompare !== 0) {
        return $scopeCompare;
    }

    return strnatcasecmp((string)($left['full_name'] ?? ''), (string)($right['full_name'] ?? ''));
});

$pagePayload = [
    'rows' => $monitorRows,
    'termText' => $academicTermText,
    'analyticsReady' => $analyticsReady,
];
$pagePayloadJson = json_encode($pagePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($pagePayloadJson === false) {
    $pagePayloadJson = '{"rows":[],"termText":"Current academic term","analyticsReady":false}';
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

    <title>Faculty Load Monitoring | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
        .monitoring-hero {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .monitoring-subcopy {
            max-width: 56rem;
            color: #8592a3;
        }

        .monitoring-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        .monitoring-summary-card {
            border: 1px solid rgba(67, 89, 113, 0.12);
            border-radius: 1rem;
            padding: 1rem 1.1rem;
            background: linear-gradient(180deg, rgba(245, 247, 255, 0.92), rgba(255, 255, 255, 0.98));
            box-shadow: 0 10px 24px rgba(67, 89, 113, 0.06);
        }

        .monitoring-summary-label {
            display: block;
            margin-bottom: 0.35rem;
            color: #7b8ba4;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        .monitoring-summary-value {
            color: #435971;
            font-size: 1.5rem;
            font-weight: 700;
            line-height: 1.05;
        }

        .monitoring-summary-note {
            margin-top: 0.35rem;
            color: #8a97a8;
            font-size: 0.82rem;
        }

        .monitoring-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: end;
        }

        .monitoring-toolbar-field {
            min-width: 200px;
            flex: 1 1 220px;
        }

        .monitoring-toolbar-actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .monitoring-results-note {
            color: #8592a3;
            font-size: 0.88rem;
            font-weight: 600;
        }

        .monitoring-table-wrap {
            max-height: 72vh;
            overflow: auto;
        }

        .monitoring-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #fff;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #5f728b;
            white-space: nowrap;
            border-bottom: 1px solid rgba(67, 89, 113, 0.12);
        }

        .monitoring-table tbody td {
            vertical-align: middle;
            color: #566a7f;
        }

        .monitoring-table td.text-number {
            font-variant-numeric: tabular-nums;
            text-align: right;
            white-space: nowrap;
        }

        .monitoring-name {
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
        }

        .monitoring-name-text {
            font-weight: 700;
            color: #3f5170;
        }

        .assignee-chip {
            display: inline-flex;
            align-items: center;
            align-self: flex-start;
            padding: 0.24rem 0.58rem;
            border-radius: 999px;
            border: 1px solid rgba(67, 89, 113, 0.12);
            background: #f4f6fb;
            color: #5f728b;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .assignee-chip.is-faculty-need {
            background: #eef6ff;
            border-color: #cfe3ff;
            color: #1e5eb7;
        }

        .monitoring-name-note {
            color: #8592a3;
            font-size: 0.78rem;
            line-height: 1.4;
        }

        .monitoring-scope {
            display: flex;
            flex-direction: column;
            gap: 0.12rem;
        }

        .monitoring-campus {
            font-weight: 700;
            color: #435971;
        }

        .monitoring-college {
            color: #8592a3;
            font-size: 0.82rem;
        }

        .load-status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 102px;
            padding: 0.38rem 0.72rem;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .load-status-badge.is-overload {
            background: #fde8ea;
            border-color: #f5bcc3;
            color: #a61c2d;
        }

        .load-status-badge.is-normal {
            background: #e9f9ee;
            border-color: #b9e6c7;
            color: #146c43;
        }

        .load-status-badge.is-underload {
            background: #fff3cd;
            border-color: #f3da8d;
            color: #7a5a00;
        }

        .load-status-badge.is-pending {
            background: #eef1f6;
            border-color: #d9e0ea;
            color: #6b7d93;
        }

        .monitoring-empty {
            border: 1px dashed rgba(67, 89, 113, 0.2);
            border-radius: 1rem;
            padding: 1.75rem 1rem;
            text-align: center;
            color: #8592a3;
            background: rgba(245, 246, 255, 0.7);
        }

        .monitoring-empty i {
            display: block;
            font-size: 2rem;
            margin-bottom: 0.5rem;
            color: #9cabbd;
        }

        .faculty-monitoring-modal-subtitle {
            color: #8592a3;
            font-size: 0.88rem;
            margin-top: 0.18rem;
        }

        .faculty-directory-empty {
            border: 1px dashed rgba(67, 89, 113, 0.18);
            border-radius: 1rem;
            padding: 1.5rem 1rem;
            text-align: center;
            color: #8592a3;
            background: rgba(245, 246, 255, 0.72);
        }

        .faculty-directory-empty i {
            display: block;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            color: #9cabbd;
        }

        .faculty-workload-loader {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.7rem;
            min-height: 160px;
            color: #6f7f95;
            font-weight: 600;
        }

        .faculty-directory-workload-alert .alert {
            background: #eef6ff;
            border: 1px solid #d4e6fb;
            color: #355070;
        }

        .workload-card {
            border: 1px solid #dbe5f1;
            box-shadow: 0 2px 8px rgba(18, 38, 63, 0.05);
        }

        .workload-card .card-header {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
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
            background: #f6f9fc;
        }

        .workload-total-load-screen {
            padding-left: 0.75rem !important;
            padding-right: 1rem !important;
        }

        .total-load-screen-inner {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.45rem;
            flex-wrap: wrap;
        }

        .total-load-value {
            font-weight: 700;
        }

        .load-status-inline {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .load-status-inline.overload {
            background: #fde8ea;
            color: #a61c2d;
        }

        .load-status-inline.normal {
            background: #e9f9ee;
            color: #146c43;
        }

        .load-status-inline.underload {
            background: #fff3cd;
            color: #7a5a00;
        }

        .type-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 42px;
            margin-left: 0.35rem;
            padding: 0.08rem 0.42rem;
            border-radius: 999px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            vertical-align: middle;
        }

        .type-pill.lec {
            background: #eef6ff;
            color: #245f9c;
        }

        .type-pill.lab {
            background: #fff1e6;
            color: #a25818;
        }

        .schedule-partner-note,
        .workload-merge-note,
        .workload-source-note,
        .workload-external-note {
            display: block;
            margin-top: 0.22rem;
            font-size: 0.72rem;
        }

        .workload-merge-note {
            color: #7a6b40;
            font-weight: 600;
        }

        .workload-source-note {
            color: #536f88;
            font-weight: 600;
        }

        .external-workload-divider td {
            background: #fff7e6;
            color: #8a5a00;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }

        .external-workload-row td {
            background: #fffdf8;
            color: #5f6f83;
        }

        .external-workload-row .workload-code {
            border-left: 4px solid #f0b84e;
        }

        .workload-external-note {
            color: #9a6512;
            font-weight: 700;
        }

        .print-type-suffix {
            display: none;
        }

        @media (max-width: 991.98px) {
            .monitoring-hero {
                flex-direction: column;
            }

            .monitoring-toolbar-actions {
                width: 100%;
                justify-content: space-between;
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
            <div class="monitoring-hero">
                <div>
                    <h4 class="fw-bold mb-2">
                        <i class="bx bx-user-pin me-2"></i> Faculty Load Monitoring
                    </h4>
                    <p class="monitoring-subcopy mb-0">
                        Review active faculty workload across all campuses. Each faculty now appears once with fused totals across
                        all assigned colleges and campuses, while <strong>Show Workload</strong> marks every subject with its
                        source campus and college.
                    </p>
                </div>
                <div class="d-flex flex-column align-items-start align-items-lg-end gap-2">
                    <span class="badge bg-label-info px-3 py-2"><?= admin_monitoring_h($academicTermText); ?></span>
                    <span class="text-muted small">Current monitoring scope: all campuses</span>
                </div>
            </div>

            <?php if (!$analyticsReady): ?>
                <div class="alert alert-warning mb-4">
                    <i class="bx bx-info-circle me-1"></i>
                    Faculty assignment rows are available, but workload monitoring values will fully populate once an active academic
                    term and scheduler workload records are available.
                </div>
            <?php endif; ?>

            <div class="monitoring-summary-grid">
                <div class="monitoring-summary-card">
                    <span class="monitoring-summary-label">Assignments</span>
                    <div class="monitoring-summary-value" id="monitoringTotalCount">0</div>
                    <div class="monitoring-summary-note">Active faculty-college rows in the current list.</div>
                </div>
                <div class="monitoring-summary-card">
                    <span class="monitoring-summary-label">Overload</span>
                    <div class="monitoring-summary-value" id="monitoringOverloadCount">0</div>
                    <div class="monitoring-summary-note">Rows currently above the prep-based normal load.</div>
                </div>
                <div class="monitoring-summary-card">
                    <span class="monitoring-summary-label">Normal</span>
                    <div class="monitoring-summary-value" id="monitoringNormalCount">0</div>
                    <div class="monitoring-summary-note">Rows matching the expected normal load.</div>
                </div>
                <div class="monitoring-summary-card">
                    <span class="monitoring-summary-label">Underload</span>
                    <div class="monitoring-summary-value" id="monitoringUnderloadCount">0</div>
                    <div class="monitoring-summary-note">Rows currently below the prep-based normal load.</div>
                </div>
            </div>

            <div class="card">
                <div class="card-body border-bottom">
                    <div class="monitoring-toolbar">
                        <div class="monitoring-toolbar-field">
                            <label for="monitoringSearch" class="form-label mb-1">Search</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bx bx-search"></i></span>
                                <input
                                    type="search"
                                    id="monitoringSearch"
                                    class="form-control"
                                    placeholder="Search faculty need, faculty, campus, or college"
                                    autocomplete="off"
                                >
                            </div>
                        </div>

                        <div class="monitoring-toolbar-field">
                            <label for="monitoringSort" class="form-label mb-1">Sort By</label>
                            <select id="monitoringSort" class="form-select">
                                <option value="load_desc">Highest Load</option>
                                <option value="units_desc">Highest Units</option>
                                <option value="prep_desc">Highest Prep</option>
                                <option value="name_asc">Faculty Name A-Z</option>
                                <option value="scope_asc">Campus / College A-Z</option>
                            </select>
                        </div>

                        <div class="monitoring-toolbar-field">
                            <label for="monitoringStatus" class="form-label mb-1">Status</label>
                            <select id="monitoringStatus" class="form-select">
                                <option value="all">All Status</option>
                                <option value="overload">Overload</option>
                                <option value="normal">Normal</option>
                                <option value="underload">Underload</option>
                                <option value="pending">Pending term</option>
                            </select>
                        </div>

                        <div class="monitoring-toolbar-actions">
                            <button type="button" class="btn btn-outline-secondary" id="monitoringClearFilters">
                                Clear
                            </button>
                        </div>
                    </div>
                </div>

                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                        <div class="monitoring-results-note" id="monitoringResultsNote">
                            Loading faculty and faculty need monitoring list...
                        </div>
                        <div class="text-muted small">
                            Highest load, highest units, and highest prep sorting are available from the toolbar.
                        </div>
                    </div>

                    <div class="monitoring-table-wrap">
                        <table class="table table-hover monitoring-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Fullname</th>
                                    <th>Campus - College</th>
                                    <th class="text-end">Total Units</th>
                                    <th class="text-end">Total Load</th>
                                    <th class="text-end"># of Prep</th>
                                    <th>Status</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody id="monitoringTbody">
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">Loading faculty and faculty need monitoring list...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
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

<div class="modal fade" id="facultyMonitoringWorkloadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-0" id="facultyMonitoringWorkloadTitle">Faculty Workload</h5>
                    <div class="faculty-monitoring-modal-subtitle" id="facultyMonitoringWorkloadSubtitle">
                        Current monitoring scope
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="facultyMonitoringWorkloadBody">
                <div class="faculty-directory-empty">
                    <i class="bx bx-user"></i>
                    Select a faculty or faculty need row to view the detailed workload.
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../assets/vendor/libs/popper/popper.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script>
<script src="../assets/js/main.js"></script>

<script>
    (function () {
        const pagePayload = <?= $pagePayloadJson; ?>;
        const monitoringRows = Array.isArray(pagePayload.rows) ? pagePayload.rows : [];
        const analyticsReady = !!pagePayload.analyticsReady;
        const termText = String(pagePayload.termText || "Current academic term");
        const searchInput = document.getElementById("monitoringSearch");
        const sortSelect = document.getElementById("monitoringSort");
        const statusSelect = document.getElementById("monitoringStatus");
        const clearButton = document.getElementById("monitoringClearFilters");
        const tbody = document.getElementById("monitoringTbody");
        const resultsNote = document.getElementById("monitoringResultsNote");
        const totalCount = document.getElementById("monitoringTotalCount");
        const overloadCount = document.getElementById("monitoringOverloadCount");
        const normalCount = document.getElementById("monitoringNormalCount");
        const underloadCount = document.getElementById("monitoringUnderloadCount");
        const workloadModalElement = document.getElementById("facultyMonitoringWorkloadModal");
        const workloadModalTitle = document.getElementById("facultyMonitoringWorkloadTitle");
        const workloadModalSubtitle = document.getElementById("facultyMonitoringWorkloadSubtitle");
        const workloadModalBody = document.getElementById("facultyMonitoringWorkloadBody");
        const workloadModal = workloadModalElement && typeof bootstrap !== "undefined"
            ? new bootstrap.Modal(workloadModalElement)
            : null;

        const rowMap = new Map();
        monitoringRows.forEach(function (row) {
            rowMap.set(String(row.assignment_key || ""), row);
        });

        let activeAssignmentKey = "";
        let activeWorkloadRequest = null;
        const workloadCache = new Map();

        function escapeHtml(value) {
            return String(value || "")
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }

        function toNumber(value) {
            const numericValue = Number(value);
            return Number.isFinite(numericValue) ? numericValue : 0;
        }

        function formatNumber(value) {
            const numericValue = toNumber(value);
            return Number.isInteger(numericValue)
                ? String(numericValue)
                : String(parseFloat(numericValue.toFixed(2)));
        }

        function formatStudentCount(value) {
            const numericValue = Math.round(toNumber(value));
            return numericValue > 0 ? String(numericValue) : "";
        }

        function getNormalLoadUnits(preparationCount) {
            return Math.max(0, Math.floor(toNumber(preparationCount))) >= 2 ? 18 : 21;
        }

        function getLoadStatus(loadValue, preparationCount, analyticsFlag) {
            const isAnalyticsReady = typeof analyticsFlag === "undefined" ? analyticsReady : !!analyticsFlag;

            if (!isAnalyticsReady) {
                return { key: "pending", label: "Pending term", className: "pending" };
            }

            const numericLoad = toNumber(loadValue);
            const normalLoadUnits = getNormalLoadUnits(preparationCount);
            const tolerance = 0.0001;

            if (numericLoad > normalLoadUnits + tolerance) {
                return { key: "overload", label: "Overload", className: "overload" };
            }

            if (numericLoad >= normalLoadUnits - tolerance) {
                return { key: "normal", label: "Normal", className: "normal" };
            }

            return { key: "underload", label: "Underload", className: "underload" };
        }

        function getAssigneeType(row) {
            return String(row && row.assignee_type || "faculty").toLowerCase() === "faculty_need"
                ? "faculty_need"
                : "faculty";
        }

        function getSelectedEntryHeading(item, meta) {
            return getAssigneeType(meta || item) === "faculty_need"
                ? "Faculty Need Selected:"
                : "Faculty Selected:";
        }

        function getEntryAnalyticsReady(item, meta) {
            if (meta && Object.prototype.hasOwnProperty.call(meta, "analytics_ready")) {
                return !!meta.analytics_ready;
            }

            if (item && Object.prototype.hasOwnProperty.call(item, "status_ready")) {
                return !!item.status_ready;
            }

            return analyticsReady;
        }

        function getSearchBlob(row) {
            return [
                row && row.full_name,
                row && row.assignee_type_label,
                row && row.campus_label,
                row && row.college_label,
                row && row.campus_college_label,
                row && row.status_label,
                row && row.designation_label,
                row && row.need_notes
            ].join(" ").toLowerCase();
        }

        function sortRows(rows, sortValue) {
            const sorted = rows.slice();

            sorted.sort(function (left, right) {
                switch (sortValue) {
                    case "units_desc": {
                        const compare = toNumber(right.total_units) - toNumber(left.total_units);
                        if (compare !== 0) {
                            return compare;
                        }
                        break;
                    }

                    case "prep_desc": {
                        const compare = toNumber(right.total_preparations) - toNumber(left.total_preparations);
                        if (compare !== 0) {
                            return compare;
                        }
                        break;
                    }

                    case "name_asc": {
                        const compare = String(left.full_name || "").localeCompare(String(right.full_name || ""));
                        if (compare !== 0) {
                            return compare;
                        }
                        return String(left.campus_college_label || "").localeCompare(String(right.campus_college_label || ""));
                    }

                    case "scope_asc": {
                        const compare = String(left.campus_college_label || "").localeCompare(String(right.campus_college_label || ""));
                        if (compare !== 0) {
                            return compare;
                        }
                        return String(left.full_name || "").localeCompare(String(right.full_name || ""));
                    }

                    case "load_desc":
                    default: {
                        const compare = toNumber(right.total_load) - toNumber(left.total_load);
                        if (compare !== 0) {
                            return compare;
                        }
                        break;
                    }
                }

                const unitCompare = toNumber(right.total_units) - toNumber(left.total_units);
                if (unitCompare !== 0) {
                    return unitCompare;
                }

                const prepCompare = toNumber(right.total_preparations) - toNumber(left.total_preparations);
                if (prepCompare !== 0) {
                    return prepCompare;
                }

                return String(left.full_name || "").localeCompare(String(right.full_name || ""));
            });

            return sorted;
        }

        function getFilteredRows() {
            const keyword = searchInput ? String(searchInput.value || "").trim().toLowerCase() : "";
            const statusFilter = statusSelect ? String(statusSelect.value || "all") : "all";
            const sortValue = sortSelect ? String(sortSelect.value || "load_desc") : "load_desc";

            const filtered = monitoringRows.filter(function (row) {
                if (statusFilter !== "all" && String(row.status_key || "") !== statusFilter) {
                    return false;
                }

                if (!keyword) {
                    return true;
                }

                return getSearchBlob(row).indexOf(keyword) !== -1;
            });

            return sortRows(filtered, sortValue);
        }

        function updateSummary(rows) {
            const filteredRows = Array.isArray(rows) ? rows : [];
            const overloadRows = filteredRows.filter(function (row) { return String(row.status_key || "") === "overload"; }).length;
            const normalRows = filteredRows.filter(function (row) { return String(row.status_key || "") === "normal"; }).length;
            const underloadRows = filteredRows.filter(function (row) { return String(row.status_key || "") === "underload"; }).length;

            if (totalCount) {
                totalCount.textContent = String(filteredRows.length);
            }
            if (overloadCount) {
                overloadCount.textContent = String(overloadRows);
            }
            if (normalCount) {
                normalCount.textContent = String(normalRows);
            }
            if (underloadCount) {
                underloadCount.textContent = String(underloadRows);
            }
        }

        function renderTable() {
            const filteredRows = getFilteredRows();

            updateSummary(filteredRows);

            if (resultsNote) {
                resultsNote.textContent = "Showing " + filteredRows.length + " of " + monitoringRows.length + " monitoring entries for " + termText + ".";
            }

            if (!tbody) {
                return;
            }

            if (filteredRows.length === 0) {
                tbody.innerHTML = (
                            '<tr><td colspan="7">' +
                        '<div class="monitoring-empty">' +
                            '<i class="bx bx-user-x"></i>' +
                            'No faculty or faculty need entries matched the current filters.' +
                        '</div>' +
                    '</td></tr>'
                );
                return;
            }

            tbody.innerHTML = filteredRows.map(function (row) {
                const isFacultyNeed = getAssigneeType(row) === "faculty_need";
                const assigneeChip = isFacultyNeed
                    ? '<span class="assignee-chip is-faculty-need">' + escapeHtml(String(row.assignee_type_label || "Faculty Need")) + '</span>'
                    : '';
                const notesText = String(row && row.need_notes || "").trim();
                const notesMarkup = notesText !== ''
                    ? '<div class="monitoring-name-note">' + escapeHtml(notesText) + '</div>'
                    : '';

                return (
                    '<tr>' +
                        '<td>' +
                            '<div class="monitoring-name">' +
                                '<div class="monitoring-name-text">' + escapeHtml(row.full_name || "") + '</div>' +
                                assigneeChip +
                                notesMarkup +
                            '</div>' +
                        '</td>' +
                        '<td>' +
                            '<div class="monitoring-scope">' +
                                '<span class="monitoring-campus">' + escapeHtml(row.campus_label || "") + '</span>' +
                                '<span class="monitoring-college">' + escapeHtml(row.college_label || "") + '</span>' +
                            '</div>' +
                        '</td>' +
                        '<td class="text-number">' + escapeHtml(formatNumber(row.total_units)) + '</td>' +
                        '<td class="text-number">' + escapeHtml(formatNumber(row.total_load)) + '</td>' +
                        '<td class="text-number">' + escapeHtml(String(Math.max(0, Math.floor(toNumber(row.total_preparations))))) + '</td>' +
                        '<td><span class="load-status-badge ' + escapeHtml(row.status_badge_class || "is-pending") + '">' + escapeHtml(row.status_label || "Pending term") + '</span></td>' +
                        '<td class="text-end">' +
                            '<button type="button" class="btn btn-sm btn-outline-primary js-show-workload" data-assignment-key="' + escapeHtml(row.assignment_key || "") + '">' +
                                '<i class="bx bx-spreadsheet me-1"></i> Show Workload' +
                            '</button>' +
                        '</td>' +
                    '</tr>'
                );
            }).join("");
        }

        function facultyDirectoryEmptyMarkup(message) {
            return (
                '<div class="faculty-directory-empty">' +
                    '<i class="bx bx-user"></i>' +
                    escapeHtml(message) +
                '</div>'
            );
        }

        function buildFacultyWorkloadLoader(message) {
            return (
                '<div class="faculty-workload-loader">' +
                    '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>' +
                    '<span>' + escapeHtml(message) + '</span>' +
                '</div>'
            );
        }

        function getWorkloadGroupKey(row) {
            const groupId = Number(row && row.group_id) || 0;
            if (groupId > 0) {
                return "group:" + groupId;
            }

            const offeringId = Number(row && row.offering_id) || 0;
            if (offeringId > 0) {
                return "offering:" + offeringId;
            }

            return "workload:" + (Number(row && row.workload_id) || 0);
        }

        function calculateWorkloadMetricTotals(rowsData) {
            const sourceRows = Array.isArray(rowsData) ? rowsData : [];
            const countedGroups = new Set();
            const preparationSet = new Set();
            const totals = {
                unit: 0,
                lab: 0,
                lec: 0,
                load: 0,
                preparations: 0
            };

            sourceRows.forEach(function (row) {
                const groupKey = getWorkloadGroupKey(row);
                const preparationKey = String(row && row.sub_code || "").trim();

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

        function formatCompactTime(value) {
            const text = String(value || "").trim();
            if (text === "") {
                return "";
            }

            const parts = text.split("-");
            if (parts.length === 2) {
                return '<span class="time-line">' + escapeHtml(parts[0]) + '</span><span class="time-line">' + escapeHtml(parts[1]) + '</span>';
            }

            return escapeHtml(text);
        }

        function getWorkloadSourceLabel(row) {
            const campusName = String(row && row.schedule_campus_name || "").trim();
            const collegeName = String(row && row.schedule_college_name || "").trim();
            const parts = [];

            if (campusName) {
                parts.push(campusName);
            }

            if (collegeName && collegeName.toUpperCase() !== campusName.toUpperCase()) {
                parts.push(collegeName);
            }

            return parts.join(" - ");
        }

        function buildWorkloadDescription(row, isPaired) {
            const description = escapeHtml(row && row.desc || "");
            const mergeNote = String(row && row.merge_note || "").trim();
            const normalizedType = String(row && (row.type || row.schedule_type) || "").toUpperCase();
            const typeLabel = normalizedType === "LAB" ? "Lab" : (normalizedType === "LEC" ? "Lec" : "");
            const noteParts = [];
            const sourceLabel = getWorkloadSourceLabel(row);
            let html = description;

            if (isPaired) {
                const typeValue = normalizedType === "LAB" ? "LAB" : "LEC";
                noteParts.push('<span class="type-pill ' + typeValue.toLowerCase() + '">' + escapeHtml(typeValue) + '</span>');
            }

            if (typeLabel) {
                html += '<span class="print-type-suffix"> (' + escapeHtml(typeLabel) + ')</span>';
            }

            if (noteParts.length > 0) {
                html += '<span class="schedule-partner-note">' + noteParts.join("") + '</span>';
            }

            if (mergeNote) {
                html += '<span class="workload-merge-note">' + escapeHtml(mergeNote) + '</span>';
            }

            if (sourceLabel && !(row && row.is_external)) {
                html += '<span class="workload-source-note">Source: ' + escapeHtml(sourceLabel) + '</span>';
            }

            if (row && row.is_external) {
                html += '<span class="workload-external-note">View-only workload' + (sourceLabel ? ' from ' + escapeHtml(sourceLabel) : '') + '</span>';
            }

            return html;
        }

        function buildGroupedWorkloadRows(rowsData, rowClassName) {
            const sourceRows = Array.isArray(rowsData) ? rowsData : [];
            let rowsHtml = "";

            for (let i = 0; i < sourceRows.length; i += 1) {
                const currentRow = sourceRows[i];
                const groupKey = getWorkloadGroupKey(currentRow);
                const groupRows = [currentRow];

                while ((i + groupRows.length) < sourceRows.length) {
                    const candidateRow = sourceRows[i + groupRows.length];
                    if (getWorkloadGroupKey(candidateRow) !== groupKey) {
                        break;
                    }
                    groupRows.push(candidateRow);
                }

                const displayUnits = toNumber(currentRow && currentRow.units);
                const displayLabUnits = toNumber(currentRow && currentRow.lab);
                const displayLecUnits = toNumber(currentRow && currentRow.lec);
                const rowClassPrefix = rowClassName ? rowClassName + " " : "";

                if (groupRows.length > 1) {
                    const mergedStudents = groupRows.reduce(function (maxValue, groupRow) {
                        return Math.max(maxValue, toNumber(groupRow && groupRow.student_count));
                    }, 0);

                    groupRows.forEach(function (groupRow, groupIndex) {
                        rowsHtml += (
                            '<tr class="' + rowClassPrefix + (groupIndex === 0 ? 'paired-row paired-anchor' : 'paired-row') + '">' +
                                '<td class="workload-code">' + escapeHtml(String(groupRow && groupRow.sub_code || "")) + '</td>' +
                                '<td class="workload-desc">' + buildWorkloadDescription(groupRow, true) + '</td>' +
                                '<td>' + escapeHtml(String(groupRow && (groupRow.course || groupRow.section) || "")) + '</td>' +
                                '<td class="workload-days">' + escapeHtml(String(groupRow && groupRow.days || "")) + '</td>' +
                                '<td class="workload-time">' + formatCompactTime(String(groupRow && groupRow.time || "")) + '</td>' +
                                '<td class="workload-room">' + escapeHtml(String(groupRow && groupRow.room || "")) + '</td>' +
                                (groupIndex === 0
                                    ? '<td class="text-center merged-metric" rowspan="' + groupRows.length + '">' + escapeHtml(formatNumber(displayUnits)) + '</td>' +
                                      '<td class="text-center merged-metric" rowspan="' + groupRows.length + '">' + escapeHtml(formatNumber(displayLabUnits)) + '</td>' +
                                      '<td class="text-center merged-metric" rowspan="' + groupRows.length + '">' + escapeHtml(formatNumber(displayLecUnits)) + '</td>' +
                                      '<td class="text-center merged-metric" rowspan="' + groupRows.length + '">' + escapeHtml(formatNumber(groupRow && groupRow.faculty_load)) + '</td>' +
                                      '<td class="text-center merged-metric" rowspan="' + groupRows.length + '">' + escapeHtml(formatStudentCount(mergedStudents)) + '</td>'
                                    : '') +
                            '</tr>'
                        );
                    });

                    i += groupRows.length - 1;
                    continue;
                }

                rowsHtml += (
                    '<tr class="' + rowClassName + '">' +
                        '<td class="workload-code">' + escapeHtml(String(currentRow && currentRow.sub_code || "")) + '</td>' +
                        '<td class="workload-desc">' + buildWorkloadDescription(currentRow, false) + '</td>' +
                        '<td>' + escapeHtml(String(currentRow && (currentRow.course || currentRow.section) || "")) + '</td>' +
                        '<td class="workload-days">' + escapeHtml(String(currentRow && currentRow.days || "")) + '</td>' +
                        '<td class="workload-time">' + formatCompactTime(String(currentRow && currentRow.time || "")) + '</td>' +
                        '<td class="workload-room">' + escapeHtml(String(currentRow && currentRow.room || "")) + '</td>' +
                        '<td class="text-center">' + escapeHtml(formatNumber(displayUnits)) + '</td>' +
                        '<td class="text-center">' + escapeHtml(formatNumber(displayLabUnits)) + '</td>' +
                        '<td class="text-center">' + escapeHtml(formatNumber(displayLecUnits)) + '</td>' +
                        '<td class="text-center fw-semibold">' + escapeHtml(formatNumber(currentRow && currentRow.faculty_load)) + '</td>' +
                        '<td class="text-center">' + escapeHtml(formatStudentCount(currentRow && currentRow.student_count)) + '</td>' +
                    '</tr>'
                );
            }

            return rowsHtml;
        }

        function renderExternalWorkloadRows(externalRowsData) {
            const sourceRows = Array.isArray(externalRowsData) ? externalRowsData : [];
            if (sourceRows.length === 0) {
                return "";
            }

            return (
                '<tr class="external-workload-divider"><td colspan="11">Other college / campus workload - view only</td></tr>' +
                buildGroupedWorkloadRows(sourceRows, "external-workload-row")
            );
        }

        function buildFacultyWorkloadPanelMarkup(payload, item) {
            const response = payload || {};
            const rows = Array.isArray(response.rows) ? response.rows : [];
            const externalRows = (Array.isArray(response.external_rows) ? response.external_rows : []).map(function (row) {
                return Object.assign({}, row, { is_external: true });
            });
            const meta = response.meta || {};
            const allRows = rows.concat(externalRows);
            const designationUnits = toNumber(meta.designation_units || item && item.designation_units);
            const designationText = String(meta.designation_label || item && (item.designation_label || item.designation_name) || "").trim();
            const totals = calculateWorkloadMetricTotals(allRows);
            const preparationCount = Math.max(Number(meta.total_preparations) || 0, totals.preparations);
            const grandTotalUnits = totals.unit + designationUnits;
            const grandTotalLoad = totals.load + designationUnits;
            const assigneeType = getAssigneeType(meta || item);
            const selectionHeading = getSelectedEntryHeading(item, meta);
            const entryAnalyticsReady = getEntryAnalyticsReady(item, meta);
            const loadStatus = getLoadStatus(grandTotalLoad, preparationCount, entryAnalyticsReady);
            const summaryLabel = assigneeType === "faculty_need" ? "Faculty Need:" : "Designation:";
            const summaryValue = assigneeType === "faculty_need"
                ? String(meta.assignee_label || item && item.full_name || "").trim()
                : designationText;
            const emptyMessage = entryAnalyticsReady
                ? "No workload assigned yet."
                : "Workload source is pending for this term.";
            const emptyTableMessage = assigneeType === "faculty_need"
                ? "No workload assigned in the selected college."
                : "No workload assigned for this faculty.";
            let tableBody = "";

            if (rows.length > 0) {
                tableBody += buildGroupedWorkloadRows(rows, "");
            } else {
                tableBody += '<tr><td colspan="11" class="text-center text-muted">' + escapeHtml(emptyTableMessage) + '</td></tr>';
            }

            tableBody += renderExternalWorkloadRows(externalRows);

            if (allRows.length === 0) {
                return (
                    '<div class="card workload-card">' +
                        '<div class="card-header">' +
                            '<h5 class="m-0">Current Faculty Workload</h5>' +
                            '<small class="text-muted">Classes already assigned for this term</small>' +
                        '</div>' +
                        '<div class="card-body">' +
                            '<div class="faculty-directory-workload-alert">' +
                                '<div class="alert mb-0"><strong>' + escapeHtml(selectionHeading) + '</strong> ' + escapeHtml(item && item.full_name || "Selected Faculty") +
                                ' | <span class="fw-summary-label">Scope:</span> ' + escapeHtml(item && item.campus_college_label || "Selected college") +
                                ' | <span class="fw-summary-label">Term:</span> ' + escapeHtml(String(meta.term_text || termText)) + '</div>' +
                            '</div>' +
                            facultyDirectoryEmptyMarkup(emptyMessage) +
                        '</div>' +
                    '</div>'
                );
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
                                '<strong>' + escapeHtml(selectionHeading) + '</strong> ' + escapeHtml(item && item.full_name || "Selected Faculty") +
                                ' | <span class="fw-summary-label">Scope:</span> ' + escapeHtml(item && item.campus_college_label || "Selected college") +
                                ' | <span class="fw-summary-label">Term:</span> ' + escapeHtml(String(meta.term_text || termText)) +
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
                                        '<th colspan="2" class="text-start workload-summary-label">' + escapeHtml(summaryLabel) + '</th>' +
                                        '<td colspan="4" class="workload-summary-value">' + escapeHtml(summaryValue) + '</td>' +
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
                                                '<span class="load-status-inline ' + escapeHtml(loadStatus.className) + '">' + escapeHtml(loadStatus.label) + '</span>' +
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

        function loadFacultyWorkload(item) {
            const assigneeType = String(item && item.assignee_type || "faculty");
            const requestCollegeId = assigneeType === "faculty" ? 0 : (Number(item && item.college_id) || 0);
            const requestCampusId = assigneeType === "faculty" ? 0 : (Number(item && item.campus_id) || 0);
            const requestKey = [
                assigneeType,
                Number(item && item.faculty_id) || 0,
                Number(item && item.faculty_need_id) || 0,
                requestCollegeId,
                requestCampusId
            ].join(":");

            if (!workloadModalBody || !item) {
                return;
            }

            if (workloadCache.has(requestKey)) {
                workloadModalBody.innerHTML = buildFacultyWorkloadPanelMarkup(workloadCache.get(requestKey), item);
                return;
            }

            workloadModalBody.innerHTML = buildFacultyWorkloadLoader("Loading current scheduler workload...");

            if (activeWorkloadRequest && activeWorkloadRequest.readyState !== 4) {
                activeWorkloadRequest.abort();
            }

            activeWorkloadRequest = $.ajax({
                url: "../backend/query_admin_faculty_workload.php",
                type: "POST",
                dataType: "json",
                data: {
                    assignee_type: assigneeType,
                    faculty_id: Number(item.faculty_id) || 0,
                    faculty_need_id: Number(item.faculty_need_id) || 0,
                    college_id: requestCollegeId,
                    campus_id: requestCampusId,
                    include_external: 1
                }
            }).done(function (response) {
                activeWorkloadRequest = null;

                if (activeAssignmentKey !== String(item.assignment_key || "")) {
                    return;
                }

                if (!response || response.status !== "ok") {
                    workloadModalBody.innerHTML = facultyDirectoryEmptyMarkup(
                        response && response.message ? String(response.message) : "Unable to load scheduler workload for this entry."
                    );
                    return;
                }

                workloadCache.set(requestKey, response);
                workloadModalBody.innerHTML = buildFacultyWorkloadPanelMarkup(response, item);
            }).fail(function (xhr, statusText) {
                activeWorkloadRequest = null;
                if (statusText === "abort") {
                    return;
                }

                if (activeAssignmentKey !== String(item.assignment_key || "")) {
                    return;
                }

                workloadModalBody.innerHTML = facultyDirectoryEmptyMarkup("Unable to load scheduler workload for this entry.");
            });
        }

        function openWorkloadModal(assignmentKey) {
            const item = rowMap.get(String(assignmentKey || ""));
            if (!item || !workloadModalBody || !workloadModalTitle || !workloadModalSubtitle) {
                return;
            }

            activeAssignmentKey = String(item.assignment_key || "");
            workloadModalTitle.textContent = String(item.full_name || "Faculty Workload");
            workloadModalSubtitle.textContent = String(item.campus_college_label || "Selected college") + " | " + termText;
            workloadModalBody.innerHTML = buildFacultyWorkloadLoader("Loading current scheduler workload...");

            if (workloadModal) {
                workloadModal.show();
            }

            loadFacultyWorkload(item);
        }

        if (searchInput) {
            searchInput.addEventListener("input", renderTable);
        }

        if (sortSelect) {
            sortSelect.addEventListener("change", renderTable);
        }

        if (statusSelect) {
            statusSelect.addEventListener("change", renderTable);
        }

        if (clearButton) {
            clearButton.addEventListener("click", function () {
                if (searchInput) {
                    searchInput.value = "";
                }
                if (sortSelect) {
                    sortSelect.value = "load_desc";
                }
                if (statusSelect) {
                    statusSelect.value = "all";
                }
                renderTable();
            });
        }

        if (tbody) {
            tbody.addEventListener("click", function (event) {
                const trigger = event.target.closest(".js-show-workload");
                if (!trigger) {
                    return;
                }

                openWorkloadModal(String(trigger.getAttribute("data-assignment-key") || ""));
            });
        }

        if (workloadModalElement) {
            workloadModalElement.addEventListener("hidden.bs.modal", function () {
                activeAssignmentKey = "";
                if (activeWorkloadRequest && activeWorkloadRequest.readyState !== 4) {
                    activeWorkloadRequest.abort();
                }
                activeWorkloadRequest = null;
            });
        }

        renderTable();
    }());
</script>
</body>
</html>
