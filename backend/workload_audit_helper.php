<?php

require_once __DIR__ . '/schema_helper.php';

function synk_workload_audit_table_name(): string
{
    return 'tbl_scheduler_workload_audit_logs';
}

function synk_workload_audit_ensure_table(mysqli $conn): bool
{
    $tableName = synk_workload_audit_table_name();

    return (bool)$conn->query("
        CREATE TABLE IF NOT EXISTS `{$tableName}` (
            `audit_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `action_type` VARCHAR(80) NOT NULL,
            `action_label` VARCHAR(160) NOT NULL,
            `actor_user_id` INT UNSIGNED NULL DEFAULT NULL,
            `actor_username` VARCHAR(150) NULL DEFAULT NULL,
            `actor_role` VARCHAR(50) NULL DEFAULT NULL,
            `college_id` INT UNSIGNED NULL DEFAULT NULL,
            `college_name` VARCHAR(255) NULL DEFAULT NULL,
            `ay_id` INT UNSIGNED NULL DEFAULT NULL,
            `ay_label` VARCHAR(80) NULL DEFAULT NULL,
            `semester` TINYINT NULL DEFAULT NULL,
            `assignee_type` VARCHAR(40) NULL DEFAULT NULL,
            `faculty_id` INT UNSIGNED NULL DEFAULT NULL,
            `faculty_need_id` INT UNSIGNED NULL DEFAULT NULL,
            `workload_id` INT UNSIGNED NULL DEFAULT NULL,
            `need_workload_id` INT UNSIGNED NULL DEFAULT NULL,
            `schedule_id` INT UNSIGNED NULL DEFAULT NULL,
            `entity_type` VARCHAR(80) NULL DEFAULT NULL,
            `entity_id` INT UNSIGNED NULL DEFAULT NULL,
            `affected_count` INT UNSIGNED NOT NULL DEFAULT 1,
            `subject_code` VARCHAR(80) NULL DEFAULT NULL,
            `section_label` VARCHAR(160) NULL DEFAULT NULL,
            `schedule_type` VARCHAR(20) NULL DEFAULT NULL,
            `details_json` LONGTEXT NULL DEFAULT NULL,
            `ip_address` VARCHAR(45) NULL DEFAULT NULL,
            `user_agent` VARCHAR(255) NULL DEFAULT NULL,
            `date_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`audit_id`),
            KEY `idx_workload_audit_actor_date` (`actor_user_id`, `date_created`),
            KEY `idx_workload_audit_college_term_date` (`college_id`, `ay_id`, `semester`, `date_created`),
            KEY `idx_workload_audit_action_date` (`action_type`, `date_created`),
            KEY `idx_workload_audit_schedule` (`schedule_id`),
            KEY `idx_workload_audit_workload` (`workload_id`, `need_workload_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
}

function synk_workload_audit_trim($value, int $limit): ?string
{
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }

    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, $limit, 'UTF-8');
    }

    return substr($text, 0, $limit);
}

function synk_workload_audit_nullable_int($value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }

    $number = (int)$value;
    return $number > 0 ? $number : null;
}

function synk_workload_audit_action_label(string $actionType): string
{
    $labels = [
        'workload_add' => 'Added workload',
        'workload_delete' => 'Removed workload',
        'workload_bulk_delete' => 'Removed all college workload',
        'faculty_need_create' => 'Created faculty need',
        'faculty_need_delete' => 'Deleted faculty need',
        'faculty_need_workload_delete' => 'Removed faculty need workload',
    ];

    return $labels[$actionType] ?? ucwords(str_replace('_', ' ', $actionType));
}

function synk_workload_audit_actor_context(): array
{
    $forwardedFor = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    $ipAddress = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    if ($forwardedFor !== '') {
        $parts = array_filter(array_map('trim', explode(',', $forwardedFor)));
        if (!empty($parts)) {
            $ipAddress = reset($parts);
        }
    }

    return [
        'actor_user_id' => synk_workload_audit_nullable_int($_SESSION['user_id'] ?? null),
        'actor_username' => synk_workload_audit_trim($_SESSION['username'] ?? '', 150),
        'actor_role' => synk_workload_audit_trim($_SESSION['role'] ?? '', 50),
        'college_id' => synk_workload_audit_nullable_int($_SESSION['college_id'] ?? null),
        'college_name' => synk_workload_audit_trim($_SESSION['college_name'] ?? '', 255),
        'ip_address' => synk_workload_audit_trim($ipAddress, 45),
        'user_agent' => synk_workload_audit_trim($_SERVER['HTTP_USER_AGENT'] ?? '', 255),
    ];
}

function synk_workload_audit_json(array $details): ?string
{
    if (empty($details)) {
        return null;
    }

    $json = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return is_string($json) ? $json : null;
}

function synk_workload_audit_record(mysqli $conn, string $actionType, array $entry = []): bool
{
    if (!synk_workload_audit_ensure_table($conn)) {
        error_log('Unable to ensure scheduler workload audit table.');
        return false;
    }

    $actor = synk_workload_audit_actor_context();
    $details = is_array($entry['details'] ?? null) ? $entry['details'] : [];

    $row = [
        'action_type' => synk_workload_audit_trim($actionType, 80) ?: 'workload_action',
        'action_label' => synk_workload_audit_trim($entry['action_label'] ?? synk_workload_audit_action_label($actionType), 160) ?: 'Workload action',
        'actor_user_id' => synk_workload_audit_nullable_int($entry['actor_user_id'] ?? $actor['actor_user_id']),
        'actor_username' => synk_workload_audit_trim($entry['actor_username'] ?? $actor['actor_username'], 150),
        'actor_role' => synk_workload_audit_trim($entry['actor_role'] ?? $actor['actor_role'], 50),
        'college_id' => synk_workload_audit_nullable_int($entry['college_id'] ?? $actor['college_id']),
        'college_name' => synk_workload_audit_trim($entry['college_name'] ?? $actor['college_name'], 255),
        'ay_id' => synk_workload_audit_nullable_int($entry['ay_id'] ?? null),
        'ay_label' => synk_workload_audit_trim($entry['ay_label'] ?? '', 80),
        'semester' => synk_workload_audit_nullable_int($entry['semester'] ?? null),
        'assignee_type' => synk_workload_audit_trim($entry['assignee_type'] ?? '', 40),
        'faculty_id' => synk_workload_audit_nullable_int($entry['faculty_id'] ?? null),
        'faculty_need_id' => synk_workload_audit_nullable_int($entry['faculty_need_id'] ?? null),
        'workload_id' => synk_workload_audit_nullable_int($entry['workload_id'] ?? null),
        'need_workload_id' => synk_workload_audit_nullable_int($entry['need_workload_id'] ?? null),
        'schedule_id' => synk_workload_audit_nullable_int($entry['schedule_id'] ?? null),
        'entity_type' => synk_workload_audit_trim($entry['entity_type'] ?? '', 80),
        'entity_id' => synk_workload_audit_nullable_int($entry['entity_id'] ?? null),
        'affected_count' => max(1, (int)($entry['affected_count'] ?? 1)),
        'subject_code' => synk_workload_audit_trim($entry['subject_code'] ?? '', 80),
        'section_label' => synk_workload_audit_trim($entry['section_label'] ?? '', 160),
        'schedule_type' => synk_workload_audit_trim($entry['schedule_type'] ?? '', 20),
        'details_json' => synk_workload_audit_json($details),
        'ip_address' => synk_workload_audit_trim($entry['ip_address'] ?? $actor['ip_address'], 45),
        'user_agent' => synk_workload_audit_trim($entry['user_agent'] ?? $actor['user_agent'], 255),
    ];

    $columns = [
        'action_type' => 's',
        'action_label' => 's',
        'actor_user_id' => 'i',
        'actor_username' => 's',
        'actor_role' => 's',
        'college_id' => 'i',
        'college_name' => 's',
        'ay_id' => 'i',
        'ay_label' => 's',
        'semester' => 'i',
        'assignee_type' => 's',
        'faculty_id' => 'i',
        'faculty_need_id' => 'i',
        'workload_id' => 'i',
        'need_workload_id' => 'i',
        'schedule_id' => 'i',
        'entity_type' => 's',
        'entity_id' => 'i',
        'affected_count' => 'i',
        'subject_code' => 's',
        'section_label' => 's',
        'schedule_type' => 's',
        'details_json' => 's',
        'ip_address' => 's',
        'user_agent' => 's',
    ];

    $tableName = synk_workload_audit_table_name();
    $fieldList = '`' . implode('`, `', array_keys($columns)) . '`';
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $stmt = $conn->prepare("INSERT INTO `{$tableName}` ({$fieldList}) VALUES ({$placeholders})");

    if (!($stmt instanceof mysqli_stmt)) {
        error_log('Unable to prepare scheduler workload audit insert: ' . $conn->error);
        return false;
    }

    $types = implode('', array_values($columns));
    $params = [];
    foreach (array_keys($columns) as $column) {
        $params[] = $row[$column];
    }

    if (!synk_bind_dynamic_params($stmt, $types, $params)) {
        $stmt->close();
        return false;
    }

    $ok = $stmt->execute();
    if (!$ok) {
        error_log('Unable to insert scheduler workload audit row: ' . $stmt->error);
    }

    $stmt->close();
    return $ok;
}

function synk_workload_audit_normalize_assignee_type(string $assigneeType): string
{
    return strtolower(trim($assigneeType)) === 'faculty_need' ? 'faculty_need' : 'faculty';
}

function synk_workload_audit_offering_metadata_join_sql(
    string $offeringAlias = 'o',
    string $sectionAlias = 'sec',
    string $sectionCurriculumAlias = 'sc',
    string $subjectAlias = 'ps',
    string $yearSemAlias = 'pys',
    string $headerAlias = 'ph'
): string {
    return "
        LEFT JOIN tbl_section_curriculum {$sectionCurriculumAlias}
            ON {$sectionCurriculumAlias}.section_id = {$offeringAlias}.section_id
           AND {$sectionCurriculumAlias}.prospectus_id = {$offeringAlias}.prospectus_id
        LEFT JOIN tbl_prospectus_header {$headerAlias}
            ON {$headerAlias}.prospectus_id = {$offeringAlias}.prospectus_id
           AND {$headerAlias}.program_id = {$offeringAlias}.program_id
        LEFT JOIN tbl_prospectus_subjects {$subjectAlias}
            ON {$subjectAlias}.ps_id = {$offeringAlias}.ps_id
        LEFT JOIN tbl_prospectus_year_sem {$yearSemAlias}
            ON {$yearSemAlias}.pys_id = {$subjectAlias}.pys_id
           AND {$yearSemAlias}.prospectus_id = {$offeringAlias}.prospectus_id
           AND {$yearSemAlias}.year_level = {$offeringAlias}.year_level
           AND {$yearSemAlias}.semester = {$offeringAlias}.semester
        LEFT JOIN tbl_sections {$sectionAlias}
            ON {$sectionAlias}.section_id = {$offeringAlias}.section_id
           AND {$sectionAlias}.program_id = {$offeringAlias}.program_id
           AND {$sectionAlias}.year_level = {$offeringAlias}.year_level
           AND {$sectionAlias}.ay_id = {$offeringAlias}.ay_id
           AND {$sectionAlias}.semester = {$offeringAlias}.semester
    ";
}

function synk_workload_audit_format_faculty_name(array $row): string
{
    $lastName = trim((string)($row['faculty_last_name'] ?? ''));
    $firstName = trim((string)($row['faculty_first_name'] ?? ''));
    $extName = trim((string)($row['faculty_ext_name'] ?? ''));

    $name = trim($lastName . ($lastName !== '' && $firstName !== '' ? ', ' : '') . $firstName);
    if ($extName !== '') {
        $name = trim($name . ' ' . $extName);
    }

    return $name;
}

function synk_workload_audit_row_details(array $row, array $extra = []): array
{
    $facultyName = synk_workload_audit_format_faculty_name($row);
    $needLabel = trim((string)($row['need_label'] ?? ''));
    $collegeCode = trim((string)($row['college_code'] ?? ''));
    $collegeName = trim((string)($row['college_name'] ?? ''));
    $programCode = trim((string)($row['program_code'] ?? ''));
    $programName = trim((string)($row['program_name'] ?? ''));
    $roomCode = trim((string)($row['room_code'] ?? ''));
    $roomName = trim((string)($row['room_name'] ?? ''));

    return array_merge([
        'assignee_type' => (string)($row['assignee_type'] ?? ''),
        'faculty_id' => synk_workload_audit_nullable_int($row['faculty_id'] ?? null),
        'faculty_name' => $facultyName,
        'faculty_need_id' => synk_workload_audit_nullable_int($row['faculty_need_id'] ?? null),
        'need_label' => $needLabel,
        'workload_id' => synk_workload_audit_nullable_int($row['workload_id'] ?? null),
        'need_workload_id' => synk_workload_audit_nullable_int($row['need_workload_id'] ?? null),
        'schedule_id' => synk_workload_audit_nullable_int($row['schedule_id'] ?? null),
        'college_id' => synk_workload_audit_nullable_int($row['college_id'] ?? null),
        'college_label' => trim($collegeCode . ' - ' . $collegeName, ' -'),
        'ay_id' => synk_workload_audit_nullable_int($row['ay_id'] ?? null),
        'ay_label' => trim((string)($row['ay_label'] ?? '')),
        'semester' => synk_workload_audit_nullable_int($row['semester'] ?? null),
        'program_label' => trim($programCode . ' - ' . $programName, ' -'),
        'section_label' => trim((string)($row['section_name'] ?? '')),
        'subject_code' => trim((string)($row['sub_code'] ?? '')),
        'subject_description' => trim((string)($row['sub_description'] ?? '')),
        'schedule_type' => trim((string)($row['schedule_type'] ?? '')),
        'days_json' => (string)($row['days_json'] ?? ''),
        'time_start' => (string)($row['time_start'] ?? ''),
        'time_end' => (string)($row['time_end'] ?? ''),
        'room_label' => trim($roomCode . ' - ' . $roomName, ' -'),
    ], $extra);
}

function synk_workload_audit_record_workload_event(
    mysqli $conn,
    string $actionType,
    array $row,
    array $extraDetails = []
): bool {
    $assigneeType = synk_workload_audit_normalize_assignee_type((string)($row['assignee_type'] ?? 'faculty'));
    $workloadId = synk_workload_audit_nullable_int($row['workload_id'] ?? null);
    $needWorkloadId = synk_workload_audit_nullable_int($row['need_workload_id'] ?? null);
    $entityId = $assigneeType === 'faculty_need' ? $needWorkloadId : $workloadId;

    return synk_workload_audit_record($conn, $actionType, [
        'college_id' => synk_workload_audit_nullable_int($row['college_id'] ?? null),
        'college_name' => $row['college_name'] ?? '',
        'ay_id' => synk_workload_audit_nullable_int($row['ay_id'] ?? null),
        'ay_label' => $row['ay_label'] ?? '',
        'semester' => synk_workload_audit_nullable_int($row['semester'] ?? null),
        'assignee_type' => $assigneeType,
        'faculty_id' => synk_workload_audit_nullable_int($row['faculty_id'] ?? null),
        'faculty_need_id' => synk_workload_audit_nullable_int($row['faculty_need_id'] ?? null),
        'workload_id' => $workloadId,
        'need_workload_id' => $needWorkloadId,
        'schedule_id' => synk_workload_audit_nullable_int($row['schedule_id'] ?? null),
        'entity_type' => $assigneeType === 'faculty_need' ? 'faculty_need_workload' : 'faculty_workload',
        'entity_id' => $entityId,
        'subject_code' => $row['sub_code'] ?? '',
        'section_label' => $row['section_name'] ?? '',
        'schedule_type' => $row['schedule_type'] ?? '',
        'details' => synk_workload_audit_row_details($row, $extraDetails),
    ]);
}

function synk_workload_audit_fetch_workload_rows(mysqli $conn, string $assigneeType, array $ids): array
{
    $assigneeType = synk_workload_audit_normalize_assignee_type($assigneeType);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
        return $id > 0;
    })));

    if (empty($ids)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $joinSql = synk_workload_audit_offering_metadata_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $scheduleTypeSql = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type') ? 'cs.schedule_type' : "'LEC'";

    if ($assigneeType === 'faculty_need') {
        $sql = "
            SELECT
                'faculty_need' AS assignee_type,
                NULL AS workload_id,
                fw.need_workload_id,
                fw.faculty_need_id,
                NULL AS faculty_id,
                fw.schedule_id,
                fw.ay_id,
                fw.semester,
                fn.need_label,
                c.college_id,
                c.college_code,
                c.college_name,
                ay.ay AS ay_label,
                {$scheduleTypeSql} AS schedule_type,
                cs.days_json,
                cs.time_start,
                cs.time_end,
                r.room_code,
                r.room_name,
                p.program_code,
                p.program_name,
                sec.section_name,
                sm.sub_code,
                sm.sub_description,
                NULL AS faculty_last_name,
                NULL AS faculty_first_name,
                NULL AS faculty_ext_name
            FROM tbl_faculty_need_workload_sched fw
            LEFT JOIN tbl_faculty_need fn
                ON fn.faculty_need_id = fw.faculty_need_id
            INNER JOIN tbl_class_schedule cs
                ON cs.schedule_id = fw.schedule_id
            INNER JOIN tbl_prospectus_offering o
                ON o.offering_id = cs.offering_id
            {$joinSql}
            LEFT JOIN tbl_program p
                ON p.program_id = o.program_id
            LEFT JOIN tbl_college c
                ON c.college_id = p.college_id
            LEFT JOIN tbl_subject_masterlist sm
                ON sm.sub_id = ps.sub_id
            LEFT JOIN tbl_academic_years ay
                ON ay.ay_id = fw.ay_id
            LEFT JOIN tbl_rooms r
                ON r.room_id = cs.room_id
            WHERE fw.need_workload_id IN ({$placeholders})
            ORDER BY fw.need_workload_id ASC
        ";
    } else {
        $sql = "
            SELECT
                'faculty' AS assignee_type,
                fw.workload_id,
                NULL AS need_workload_id,
                NULL AS faculty_need_id,
                fw.faculty_id,
                fw.schedule_id,
                fw.ay_id,
                fw.semester,
                NULL AS need_label,
                c.college_id,
                c.college_code,
                c.college_name,
                ay.ay AS ay_label,
                {$scheduleTypeSql} AS schedule_type,
                cs.days_json,
                cs.time_start,
                cs.time_end,
                r.room_code,
                r.room_name,
                p.program_code,
                p.program_name,
                sec.section_name,
                sm.sub_code,
                sm.sub_description,
                f.last_name AS faculty_last_name,
                f.first_name AS faculty_first_name,
                f.ext_name AS faculty_ext_name
            FROM tbl_faculty_workload_sched fw
            LEFT JOIN tbl_faculty f
                ON f.faculty_id = fw.faculty_id
            INNER JOIN tbl_class_schedule cs
                ON cs.schedule_id = fw.schedule_id
            INNER JOIN tbl_prospectus_offering o
                ON o.offering_id = cs.offering_id
            {$joinSql}
            LEFT JOIN tbl_program p
                ON p.program_id = o.program_id
            LEFT JOIN tbl_college c
                ON c.college_id = p.college_id
            LEFT JOIN tbl_subject_masterlist sm
                ON sm.sub_id = ps.sub_id
            LEFT JOIN tbl_academic_years ay
                ON ay.ay_id = fw.ay_id
            LEFT JOIN tbl_rooms r
                ON r.room_id = cs.room_id
            WHERE fw.workload_id IN ({$placeholders})
            ORDER BY fw.workload_id ASC
        ";
    }

    $stmt = $conn->prepare($sql);
    if (!($stmt instanceof mysqli_stmt)) {
        error_log('Unable to prepare workload audit fetch rows: ' . $conn->error);
        return [];
    }

    $params = $ids;
    synk_bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $stmt->close();
    return $rows;
}

function synk_workload_audit_fetch_college_workload_rows(mysqli $conn, int $collegeId, int $ayId, int $semester): array
{
    if ($collegeId <= 0 || $ayId <= 0 || $semester <= 0) {
        return [];
    }

    $joinSql = synk_workload_audit_offering_metadata_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $scheduleTypeSql = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type') ? 'cs.schedule_type' : "'LEC'";
    $sql = "
        SELECT
            'faculty' AS assignee_type,
            fw.workload_id,
            NULL AS need_workload_id,
            NULL AS faculty_need_id,
            fw.faculty_id,
            fw.schedule_id,
            fw.ay_id,
            fw.semester,
            NULL AS need_label,
            c.college_id,
            c.college_code,
            c.college_name,
            ay.ay AS ay_label,
            {$scheduleTypeSql} AS schedule_type,
            cs.days_json,
            cs.time_start,
            cs.time_end,
            r.room_code,
            r.room_name,
            p.program_code,
            p.program_name,
            sec.section_name,
            sm.sub_code,
            sm.sub_description,
            f.last_name AS faculty_last_name,
            f.first_name AS faculty_first_name,
            f.ext_name AS faculty_ext_name
        FROM tbl_faculty_workload_sched fw
        LEFT JOIN tbl_faculty f
            ON f.faculty_id = fw.faculty_id
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        {$joinSql}
        LEFT JOIN tbl_program p
            ON p.program_id = o.program_id
        LEFT JOIN tbl_college c
            ON c.college_id = p.college_id
        LEFT JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        LEFT JOIN tbl_academic_years ay
            ON ay.ay_id = fw.ay_id
        LEFT JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        WHERE fw.ay_id = ?
          AND fw.semester = ?
          AND p.college_id = ?
        ORDER BY fw.workload_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!($stmt instanceof mysqli_stmt)) {
        error_log('Unable to prepare workload audit college fetch: ' . $conn->error);
        return [];
    }

    $stmt->bind_param('iii', $ayId, $semester, $collegeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $stmt->close();
    return $rows;
}

function synk_workload_audit_record_bulk_delete(
    mysqli $conn,
    int $collegeId,
    int $ayId,
    int $semester,
    array $rows,
    int $deletedCount
): bool {
    if ($deletedCount <= 0) {
        return true;
    }

    $workloadIds = [];
    $scheduleIds = [];
    $facultyIds = [];
    $ayLabel = '';
    $collegeName = '';

    foreach ($rows as $row) {
        $workloadId = (int)($row['workload_id'] ?? 0);
        $scheduleId = (int)($row['schedule_id'] ?? 0);
        $facultyId = (int)($row['faculty_id'] ?? 0);

        if ($workloadId > 0) {
            $workloadIds[] = $workloadId;
        }

        if ($scheduleId > 0) {
            $scheduleIds[] = $scheduleId;
        }

        if ($facultyId > 0) {
            $facultyIds[] = $facultyId;
        }

        if ($ayLabel === '') {
            $ayLabel = trim((string)($row['ay_label'] ?? ''));
        }

        if ($collegeName === '') {
            $collegeName = trim((string)($row['college_name'] ?? ''));
        }
    }

    return synk_workload_audit_record($conn, 'workload_bulk_delete', [
        'college_id' => $collegeId,
        'college_name' => $collegeName,
        'ay_id' => $ayId,
        'ay_label' => $ayLabel,
        'semester' => $semester,
        'entity_type' => 'college_workload',
        'entity_id' => $collegeId,
        'affected_count' => $deletedCount,
        'details' => [
            'college_id' => $collegeId,
            'ay_id' => $ayId,
            'ay_label' => $ayLabel,
            'semester' => $semester,
            'deleted_count' => $deletedCount,
            'workload_ids' => array_values(array_unique($workloadIds)),
            'schedule_ids' => array_values(array_unique($scheduleIds)),
            'faculty_ids' => array_values(array_unique($facultyIds)),
        ],
    ]);
}

function synk_workload_audit_fetch_logs(mysqli $conn, int $collegeId, array $filters = [], int $limit = 250): array
{
    if (!synk_workload_audit_ensure_table($conn)) {
        return [];
    }

    $limit = max(25, min(500, $limit));
    $tableName = synk_workload_audit_table_name();
    $where = [];
    $types = '';
    $params = [];

    if ($collegeId > 0) {
        $where[] = 'l.college_id = ?';
        $types .= 'i';
        $params[] = $collegeId;
    } else {
        $filterCollegeId = (int)($filters['college_id'] ?? 0);
        if ($filterCollegeId > 0) {
            $where[] = 'l.college_id = ?';
            $types .= 'i';
            $params[] = $filterCollegeId;
        }
    }

    $actionType = trim((string)($filters['action_type'] ?? ''));
    if ($actionType !== '') {
        $where[] = 'l.action_type = ?';
        $types .= 's';
        $params[] = $actionType;
    }

    $actorUserId = (int)($filters['actor_user_id'] ?? 0);
    if ($actorUserId > 0) {
        $where[] = 'l.actor_user_id = ?';
        $types .= 'i';
        $params[] = $actorUserId;
    }

    $ayId = (int)($filters['ay_id'] ?? 0);
    if ($ayId > 0) {
        $where[] = 'l.ay_id = ?';
        $types .= 'i';
        $params[] = $ayId;
    }

    $semester = (int)($filters['semester'] ?? 0);
    if ($semester > 0) {
        $where[] = 'l.semester = ?';
        $types .= 'i';
        $params[] = $semester;
    }

    $dateFrom = trim((string)($filters['date_from'] ?? ''));
    if ($dateFrom !== '') {
        $where[] = 'l.date_created >= ?';
        $types .= 's';
        $params[] = $dateFrom . ' 00:00:00';
    }

    $dateTo = trim((string)($filters['date_to'] ?? ''));
    if ($dateTo !== '') {
        $where[] = 'l.date_created <= ?';
        $types .= 's';
        $params[] = $dateTo . ' 23:59:59';
    }

    $search = trim((string)($filters['search'] ?? ''));
    if ($search !== '') {
        $where[] = "(
            l.actor_username LIKE ?
            OR l.subject_code LIKE ?
            OR l.section_label LIKE ?
            OR l.action_label LIKE ?
            OR l.details_json LIKE ?
        )";
        $types .= 'sssss';
        $term = '%' . $search . '%';
        array_push($params, $term, $term, $term, $term, $term);
    }

    if (empty($where)) {
        $where[] = '1 = 1';
    }

    $sql = "
        SELECT
            l.*,
            COALESCE(NULLIF(u.username, ''), l.actor_username, 'Unknown user') AS actor_display_name
        FROM `{$tableName}` l
        LEFT JOIN tbl_useraccount u
            ON u.user_id = l.actor_user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY l.date_created DESC, l.audit_id DESC
        LIMIT {$limit}
    ";

    $stmt = $conn->prepare($sql);
    if (!($stmt instanceof mysqli_stmt)) {
        return [];
    }

    synk_bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $stmt->close();
    return $rows;
}

function synk_workload_audit_fetch_users(mysqli $conn, int $collegeId): array
{
    if (!synk_workload_audit_ensure_table($conn)) {
        return [];
    }

    $tableName = synk_workload_audit_table_name();
    $whereSql = $collegeId > 0 ? 'WHERE l.college_id = ?' : '';
    $stmt = $conn->prepare("
        SELECT
            l.actor_user_id,
            COALESCE(NULLIF(u.username, ''), l.actor_username, 'Unknown user') AS actor_display_name,
            COUNT(*) AS transaction_count
        FROM `{$tableName}` l
        LEFT JOIN tbl_useraccount u
            ON u.user_id = l.actor_user_id
        {$whereSql}
        GROUP BY l.actor_user_id, u.username, l.actor_username
        ORDER BY actor_display_name ASC
    ");

    if (!($stmt instanceof mysqli_stmt)) {
        return [];
    }

    if ($collegeId > 0) {
        $stmt->bind_param('i', $collegeId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'actor_user_id' => (int)($row['actor_user_id'] ?? 0),
                'actor_display_name' => trim((string)($row['actor_display_name'] ?? 'Unknown user')),
                'transaction_count' => (int)($row['transaction_count'] ?? 0),
            ];
        }
    }

    $stmt->close();
    return $rows;
}

function synk_workload_audit_fetch_action_counts(mysqli $conn, int $collegeId): array
{
    if (!synk_workload_audit_ensure_table($conn)) {
        return [];
    }

    $tableName = synk_workload_audit_table_name();
    $whereSql = $collegeId > 0 ? 'WHERE college_id = ?' : '';
    $stmt = $conn->prepare("
        SELECT action_type, action_label, COUNT(*) AS transaction_count
        FROM `{$tableName}`
        {$whereSql}
        GROUP BY action_type, action_label
        ORDER BY action_label ASC
    ");

    if (!($stmt instanceof mysqli_stmt)) {
        return [];
    }

    if ($collegeId > 0) {
        $stmt->bind_param('i', $collegeId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'action_type' => (string)($row['action_type'] ?? ''),
                'action_label' => (string)($row['action_label'] ?? ''),
                'transaction_count' => (int)($row['transaction_count'] ?? 0),
            ];
        }
    }

    $stmt->close();
    return $rows;
}
