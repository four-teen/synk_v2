<?php

require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/offering_enrollee_helper.php';
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/schedule_block_helper.php';

function synk_workload_simulation_title_case(string $value): string
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

function synk_workload_simulation_context_key_from_values(int $groupId, int $offeringId): string
{
    if ($groupId > 0) {
        return 'group:' . $groupId;
    }

    return 'offering:' . $offeringId;
}

function synk_workload_simulation_partner_label(int $groupId, int $offeringId, int $contextTotalCount): string
{
    if ($contextTotalCount <= 1) {
        return '';
    }

    if ($groupId > 0) {
        return 'PAIR G' . $groupId;
    }

    if ($offeringId > 0) {
        return 'PAIR O' . $offeringId;
    }

    return 'PAIR';
}

function synk_workload_simulation_normalize_days($daysJson): array
{
    $days = json_decode((string)$daysJson, true);
    return is_array($days) ? synk_normalize_schedule_days($days) : [];
}

function synk_workload_simulation_time_range_label(?string $timeStart, ?string $timeEnd): string
{
    $timeStart = trim((string)$timeStart);
    $timeEnd = trim((string)$timeEnd);

    if ($timeStart === '' || $timeEnd === '') {
        return '';
    }

    return date('g:iA', strtotime($timeStart)) . '-' . date('g:iA', strtotime($timeEnd));
}

function synk_workload_simulation_resolve_context_group_id(array $scheduledRows): int
{
    $groupId = 0;

    foreach ($scheduledRows as $row) {
        $candidate = (int)($row['group_id'] ?? 0);
        if ($candidate <= 0) {
            continue;
        }

        if ($groupId === 0) {
            $groupId = $candidate;
            continue;
        }

        if ($groupId !== $candidate) {
            return 0;
        }
    }

    return $groupId;
}

function synk_fetch_workload_simulation_catalog(mysqli $conn, int $collegeId, int $ayId, int $semester): array
{
    if ($collegeId <= 0 || $ayId <= 0 || $semester <= 0) {
        return [];
    }

    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $classScheduleHasGroupId = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_group_id');
    $classScheduleHasType = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type');

    $selectParts = [
        'o.offering_id',
        'sm.sub_code',
        'sm.sub_description',
        'sec.section_name',
        'sec.full_section',
        'ps.lec_units',
        'ps.lab_units',
        'ps.total_units',
        'cs.schedule_id',
        $classScheduleHasGroupId ? 'cs.schedule_group_id AS group_id' : 'NULL AS group_id',
        $classScheduleHasType ? 'cs.schedule_type AS schedule_type' : "'LEC' AS schedule_type",
        'cs.days_json',
        'cs.time_start',
        'cs.time_end',
        'r.room_code'
    ];

    $sql = "
        SELECT
            " . implode(",\n            ", $selectParts) . "
        FROM tbl_prospectus_offering o
        " . $liveOfferingJoins . "
        INNER JOIN tbl_program p
            ON p.program_id = o.program_id
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        LEFT JOIN tbl_class_schedule cs
            ON cs.offering_id = o.offering_id
        LEFT JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        WHERE o.ay_id = ?
          AND o.semester = ?
          AND p.college_id = ?
        ORDER BY
            sec.section_name,
            sm.sub_code,
            " . ($classScheduleHasType ? "FIELD(cs.schedule_type, 'LEC', 'LAB')," : '') . "
            cs.time_start,
            cs.schedule_id
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iii', $ayId, $semester, $collegeId);
    $stmt->execute();
    $res = $stmt->get_result();

    $offerings = [];

    while ($row = $res->fetch_assoc()) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($offeringId <= 0) {
            continue;
        }

        if (!isset($offerings[$offeringId])) {
            $offerings[$offeringId] = [
                'offering_id' => $offeringId,
                'sub_code' => (string)($row['sub_code'] ?? ''),
                'sub_description' => (string)($row['sub_description'] ?? ''),
                'section_name' => (string)($row['section_name'] ?? ''),
                'full_section' => (string)($row['full_section'] ?? ''),
                'lec_units' => (float)($row['lec_units'] ?? 0),
                'lab_units' => (float)($row['lab_units'] ?? 0),
                'total_units' => (float)($row['total_units'] ?? 0),
                'scheduled_rows' => []
            ];
        }

        $scheduleId = (int)($row['schedule_id'] ?? 0);
        if ($scheduleId <= 0) {
            continue;
        }

        $offerings[$offeringId]['scheduled_rows'][] = [
            'schedule_id' => $scheduleId,
            'group_id' => (int)($row['group_id'] ?? 0),
            'schedule_type' => synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC')),
            'days_arr' => synk_workload_simulation_normalize_days($row['days_json'] ?? '[]'),
            'time_start' => (string)($row['time_start'] ?? ''),
            'time_end' => (string)($row['time_end'] ?? ''),
            'room_code' => (string)($row['room_code'] ?? '')
        ];
    }

    $stmt->close();

    $studentCountMap = synk_fetch_offering_enrollee_count_map($conn, array_keys($offerings));
    $rows = [];

    foreach ($offerings as $offering) {
        $offeringId = (int)$offering['offering_id'];
        $lecUnits = (float)$offering['lec_units'];
        $labUnits = (float)$offering['lab_units'];
        $totalUnits = (float)$offering['total_units'];
        $subjectUnits = synk_subject_units_total($lecUnits, $labUnits, $totalUnits);
        $labHoursTotal = max(0.0, $labUnits);
        $scheduledRows = $offering['scheduled_rows'];
        $scheduledLecCount = 0;
        $scheduledLabCount = 0;

        foreach ($scheduledRows as $scheduledRow) {
            if (($scheduledRow['schedule_type'] ?? 'LEC') === 'LAB') {
                $scheduledLabCount++;
            } else {
                $scheduledLecCount++;
            }
        }

        $hasLectureComponent = $lecUnits > 0;
        $hasLabComponent = $labHoursTotal > 0;
        $needsVirtualLec = $hasLectureComponent && $scheduledLecCount === 0;
        $needsVirtualLab = $hasLabComponent && $scheduledLabCount === 0;
        $contextGroupId = synk_workload_simulation_resolve_context_group_id($scheduledRows);
        $contextKey = synk_workload_simulation_context_key_from_values($contextGroupId, $offeringId);
        $contextTotals = [
            'total_count' => count($scheduledRows) + ($needsVirtualLec ? 1 : 0) + ($needsVirtualLab ? 1 : 0),
            'lec_count' => $scheduledLecCount + ($needsVirtualLec ? 1 : 0),
            'lab_count' => $scheduledLabCount + ($needsVirtualLab ? 1 : 0)
        ];

        if ($contextTotals['total_count'] === 0) {
            $contextTotals = [
                'total_count' => 1,
                'lec_count' => 1,
                'lab_count' => 0
            ];
            $needsVirtualLec = true;
        }

        foreach ($scheduledRows as $scheduledRow) {
            $type = (string)($scheduledRow['schedule_type'] ?? 'LEC');
            $metrics = synk_schedule_row_display_metrics(
                $type,
                $lecUnits,
                $labUnits,
                $totalUnits,
                $contextTotals
            );

            $rows[] = [
                'sim_key' => 'schedule:' . (int)$scheduledRow['schedule_id'],
                'context_key' => $contextKey,
                'partner_label' => synk_workload_simulation_partner_label($contextGroupId, $offeringId, (int)$contextTotals['total_count']),
                'offering_id' => $offeringId,
                'schedule_id' => (int)$scheduledRow['schedule_id'],
                'group_id' => $contextGroupId,
                'schedule_type' => $type,
                'source_kind' => 'scheduled',
                'has_schedule' => true,
                'subject_code' => (string)$offering['sub_code'],
                'subject_description' => (string)$offering['sub_description'],
                'section_name' => (string)$offering['section_name'],
                'course' => trim((string)$offering['full_section']) !== ''
                    ? (string)$offering['full_section']
                    : (string)$offering['section_name'],
                'days' => implode(', ', $scheduledRow['days_arr']),
                'days_arr' => array_values($scheduledRow['days_arr']),
                'time' => synk_workload_simulation_time_range_label($scheduledRow['time_start'], $scheduledRow['time_end']),
                'time_start' => (string)$scheduledRow['time_start'],
                'time_end' => (string)$scheduledRow['time_end'],
                'room_code' => (string)$scheduledRow['room_code'],
                'subject_units' => round($subjectUnits, 2),
                'lec_units' => round($lecUnits, 2),
                'lab_units' => round($labUnits, 2),
                'lab_hours_total' => round($labHoursTotal, 2),
                'context_total_count' => (int)$contextTotals['total_count'],
                'context_lec_count' => (int)$contextTotals['lec_count'],
                'context_lab_count' => (int)$contextTotals['lab_count'],
                'units' => round((float)($metrics['units'] ?? 0), 2),
                'lec' => round((float)($metrics['lec'] ?? 0), 2),
                'lab' => round((float)($metrics['lab'] ?? 0), 2),
                'lab_hours' => round((float)($metrics['lab_hours'] ?? 0), 2),
                'faculty_load' => round((float)($metrics['faculty_load'] ?? 0), 2),
                'student_count' => synk_offering_enrollee_count_for_map($studentCountMap, $offeringId)
            ];
        }

        if ($needsVirtualLec) {
            $metrics = synk_schedule_component_display_totals(
                'LEC',
                $lecUnits,
                $labUnits,
                $totalUnits
            );

            $rows[] = [
                'sim_key' => 'virtual:' . $offeringId . ':LEC',
                'context_key' => $contextKey,
                'partner_label' => synk_workload_simulation_partner_label($contextGroupId, $offeringId, (int)$contextTotals['total_count']),
                'offering_id' => $offeringId,
                'schedule_id' => 0,
                'group_id' => $contextGroupId,
                'schedule_type' => 'LEC',
                'source_kind' => 'virtual',
                'has_schedule' => false,
                'subject_code' => (string)$offering['sub_code'],
                'subject_description' => (string)$offering['sub_description'],
                'section_name' => (string)$offering['section_name'],
                'course' => trim((string)$offering['full_section']) !== ''
                    ? (string)$offering['full_section']
                    : (string)$offering['section_name'],
                'days' => '',
                'days_arr' => [],
                'time' => '',
                'time_start' => '',
                'time_end' => '',
                'room_code' => '',
                'subject_units' => round($subjectUnits, 2),
                'lec_units' => round($lecUnits, 2),
                'lab_units' => round($labUnits, 2),
                'lab_hours_total' => round($labHoursTotal, 2),
                'context_total_count' => (int)$contextTotals['total_count'],
                'context_lec_count' => (int)$contextTotals['lec_count'],
                'context_lab_count' => (int)$contextTotals['lab_count'],
                'units' => round((float)($metrics['units'] ?? 0), 2),
                'lec' => round((float)($metrics['lec'] ?? 0), 2),
                'lab' => round((float)($metrics['lab'] ?? 0), 2),
                'lab_hours' => round((float)($metrics['lab_hours'] ?? 0), 2),
                'faculty_load' => round((float)($metrics['faculty_load'] ?? 0), 2),
                'student_count' => synk_offering_enrollee_count_for_map($studentCountMap, $offeringId)
            ];
        }

        if ($needsVirtualLab) {
            $metrics = synk_schedule_component_display_totals(
                'LAB',
                $lecUnits,
                $labUnits,
                $totalUnits
            );

            $rows[] = [
                'sim_key' => 'virtual:' . $offeringId . ':LAB',
                'context_key' => $contextKey,
                'partner_label' => synk_workload_simulation_partner_label($contextGroupId, $offeringId, (int)$contextTotals['total_count']),
                'offering_id' => $offeringId,
                'schedule_id' => 0,
                'group_id' => $contextGroupId,
                'schedule_type' => 'LAB',
                'source_kind' => 'virtual',
                'has_schedule' => false,
                'subject_code' => (string)$offering['sub_code'],
                'subject_description' => (string)$offering['sub_description'],
                'section_name' => (string)$offering['section_name'],
                'course' => trim((string)$offering['full_section']) !== ''
                    ? (string)$offering['full_section']
                    : (string)$offering['section_name'],
                'days' => '',
                'days_arr' => [],
                'time' => '',
                'time_start' => '',
                'time_end' => '',
                'room_code' => '',
                'subject_units' => round($subjectUnits, 2),
                'lec_units' => round($lecUnits, 2),
                'lab_units' => round($labUnits, 2),
                'lab_hours_total' => round($labHoursTotal, 2),
                'context_total_count' => (int)$contextTotals['total_count'],
                'context_lec_count' => (int)$contextTotals['lec_count'],
                'context_lab_count' => (int)$contextTotals['lab_count'],
                'units' => round((float)($metrics['units'] ?? 0), 2),
                'lec' => round((float)($metrics['lec'] ?? 0), 2),
                'lab' => round((float)($metrics['lab'] ?? 0), 2),
                'lab_hours' => round((float)($metrics['lab_hours'] ?? 0), 2),
                'faculty_load' => round((float)($metrics['faculty_load'] ?? 0), 2),
                'student_count' => synk_offering_enrollee_count_for_map($studentCountMap, $offeringId)
            ];
        }
    }

    return $rows;
}

function synk_fetch_saved_workload_simulation_rows(
    mysqli $conn,
    int $collegeId,
    int $facultyId,
    int $ayId,
    int $semester
): array {
    if ($collegeId <= 0 || $facultyId <= 0 || $ayId <= 0 || $semester <= 0) {
        return [];
    }

    $stmt = $conn->prepare("
        SELECT
            simulation_id,
            simulation_key
        FROM tbl_faculty_workload_simulation
        WHERE college_id = ?
          AND faculty_id = ?
          AND ay_id = ?
          AND semester = ?
        ORDER BY created_at ASC, simulation_id ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('iiii', $collegeId, $facultyId, $ayId, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $savedRows = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $savedRows[] = [
            'simulation_id' => (int)($row['simulation_id'] ?? 0),
            'sim_key' => (string)($row['simulation_key'] ?? '')
        ];
    }

    $stmt->close();

    if (empty($savedRows)) {
        return [];
    }

    $catalogRows = synk_fetch_workload_simulation_catalog($conn, $collegeId, $ayId, $semester);
    $catalogMap = [];
    foreach ($catalogRows as $row) {
        $catalogMap[(string)($row['sim_key'] ?? '')] = $row;
    }

    $rows = [];
    foreach ($savedRows as $savedRow) {
        $simKey = (string)($savedRow['sim_key'] ?? '');
        if ($simKey === '' || !isset($catalogMap[$simKey])) {
            continue;
        }

        $rows[] = array_merge(
            ['simulation_id' => (int)$savedRow['simulation_id']],
            $catalogMap[$simKey]
        );
    }

    return $rows;
}

function synk_fetch_workload_simulation_designation_meta(
    mysqli $conn,
    int $collegeId,
    int $facultyId,
    int $ayId,
    int $semester
): array {
    $meta = [
        'designation_name' => '',
        'designation_label' => '',
        'designation_units' => 0.0,
        'total_preparations' => 0
    ];

    if ($collegeId <= 0 || $facultyId <= 0 || $ayId <= 0 || $semester <= 0) {
        return $meta;
    }

    $facultyHasDesignationId = synk_table_has_column($conn, 'tbl_faculty', 'designation_id');
    $designationTableExists = synk_table_exists($conn, 'tbl_designation');
    $designationHasStatus = $designationTableExists && synk_table_has_column($conn, 'tbl_designation', 'status');
    $assignmentHasAyId = synk_table_has_column($conn, 'tbl_college_faculty', 'ay_id');
    $assignmentHasSemester = synk_table_has_column($conn, 'tbl_college_faculty', 'semester');

    if (!$facultyHasDesignationId || !$designationTableExists) {
        return $meta;
    }

    $designationWhere = [
        'cf.college_id = ?',
        'cf.faculty_id = ?',
        "cf.status = 'active'"
    ];
    $designationTypes = 'ii';
    $designationParams = [$collegeId, $facultyId];

    if ($assignmentHasAyId) {
        $designationWhere[] = 'cf.ay_id = ?';
        $designationTypes .= 'i';
        $designationParams[] = $ayId;
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
    if (!$designationStmt) {
        return $meta;
    }

    synk_bind_dynamic_params($designationStmt, $designationTypes, $designationParams);
    $designationStmt->execute();
    $designationRes = $designationStmt->get_result();
    $designationRow = $designationRes ? $designationRes->fetch_assoc() : null;
    $designationStmt->close();

    if (is_array($designationRow)) {
        $meta['designation_name'] = trim((string)($designationRow['designation_name'] ?? ''));
        $meta['designation_units'] = round((float)($designationRow['designation_units'] ?? 0), 2);
        $meta['designation_label'] = synk_workload_simulation_title_case($meta['designation_name']);
    }

    return $meta;
}
