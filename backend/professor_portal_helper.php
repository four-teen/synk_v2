<?php

require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schedule_merge_helper.php';
require_once __DIR__ . '/schedule_block_helper.php';
require_once __DIR__ . '/offering_enrollee_helper.php';

function synk_professor_faculty_link_table_name(): string
{
    return 'tbl_useraccount_faculty_links';
}

function synk_professor_faculty_link_table_exists(mysqli $conn): bool
{
    static $cache = null;

    if ($cache === true) {
        return true;
    }

    $tableName = synk_professor_faculty_link_table_name();
    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($tableName) . "'");
    $exists = $result instanceof mysqli_result && $result->num_rows > 0;

    if ($result instanceof mysqli_result) {
        $result->close();
    }

    if ($exists) {
        $cache = true;
    }

    return $exists;
}

function synk_professor_ensure_faculty_link_table(mysqli $conn): bool
{
    if (synk_professor_faculty_link_table_exists($conn)) {
        return true;
    }

    $tableName = synk_professor_faculty_link_table_name();
    $sql = "
        CREATE TABLE IF NOT EXISTS `{$tableName}` (
            `account_faculty_link_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `faculty_id` INT UNSIGNED NOT NULL,
            `date_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`account_faculty_link_id`),
            UNIQUE KEY `uniq_account_faculty_user` (`user_id`),
            UNIQUE KEY `uniq_account_faculty_faculty` (`faculty_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    if (!$conn->query($sql)) {
        return false;
    }

    return synk_professor_faculty_link_table_exists($conn);
}

function synk_professor_full_name_from_row(array $row): string
{
    $firstName = trim((string)($row['first_name'] ?? ''));
    $middleName = trim((string)($row['middle_name'] ?? ''));
    $lastName = trim((string)($row['last_name'] ?? ''));
    $extName = trim((string)($row['ext_name'] ?? ''));

    $fullName = trim(implode(' ', array_filter([
        $firstName,
        $middleName,
        $lastName,
        $extName,
    ], static function ($value): bool {
        return trim((string)$value) !== '';
    })));

    if ($fullName !== '') {
        return preg_replace('/\s+/', ' ', $fullName) ?: $fullName;
    }

    return 'Professor';
}

function synk_professor_fetch_faculty_link(mysqli $conn, int $userId): ?array
{
    if ($userId <= 0 || !synk_professor_faculty_link_table_exists($conn)) {
        return null;
    }

    $tableName = synk_professor_faculty_link_table_name();
    $stmt = $conn->prepare("
        SELECT
            link.user_id,
            link.faculty_id,
            f.first_name,
            COALESCE(f.middle_name, '') AS middle_name,
            f.last_name,
            COALESCE(f.ext_name, '') AS ext_name,
            COALESCE(f.status, 'active') AS faculty_status
        FROM `{$tableName}` link
        INNER JOIN tbl_faculty f
            ON f.faculty_id = link.faculty_id
        WHERE link.user_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;

    if ($result instanceof mysqli_result) {
        $result->close();
    }
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $row['faculty_id'] = (int)($row['faculty_id'] ?? 0);
    $row['faculty_name'] = synk_professor_full_name_from_row($row);
    $row['faculty_status'] = strtolower(trim((string)($row['faculty_status'] ?? 'active')));

    return $row;
}

function synk_professor_fetch_faculty_links_bulk(mysqli $conn, array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static function (int $value): bool {
        return $value > 0;
    })));

    if (empty($userIds) || !synk_professor_faculty_link_table_exists($conn)) {
        return [];
    }

    $tableName = synk_professor_faculty_link_table_name();
    $userIdList = implode(', ', $userIds);
    $result = $conn->query("
        SELECT
            link.user_id,
            link.faculty_id,
            f.first_name,
            COALESCE(f.middle_name, '') AS middle_name,
            f.last_name,
            COALESCE(f.ext_name, '') AS ext_name,
            COALESCE(f.status, 'active') AS faculty_status
        FROM `{$tableName}` link
        INNER JOIN tbl_faculty f
            ON f.faculty_id = link.faculty_id
        WHERE link.user_id IN ({$userIdList})
    ");

    if (!($result instanceof mysqli_result)) {
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $userId = (int)($row['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        $row['faculty_id'] = (int)($row['faculty_id'] ?? 0);
        $row['faculty_name'] = synk_professor_full_name_from_row($row);
        $row['faculty_status'] = strtolower(trim((string)($row['faculty_status'] ?? 'active')));
        $rows[$userId] = $row;
    }

    $result->close();
    return $rows;
}

function synk_professor_role_selected($roles): bool
{
    if (!is_array($roles)) {
        if ($roles === null || $roles === '') {
            $roles = [];
        } else {
            $roles = [$roles];
        }
    }

    foreach ($roles as $role) {
        if (strtolower(trim((string)$role)) === 'professor') {
            return true;
        }
    }

    return false;
}

function synk_professor_fetch_faculty_record_by_id(mysqli $conn, int $facultyId, bool $activeOnly = true): ?array
{
    if ($facultyId <= 0 || !synk_table_exists($conn, 'tbl_faculty')) {
        return null;
    }

    $hasStatusColumn = synk_table_has_column($conn, 'tbl_faculty', 'status');
    $sql = "
        SELECT
            faculty_id,
            first_name,
            COALESCE(middle_name, '') AS middle_name,
            last_name,
            COALESCE(ext_name, '') AS ext_name,
            COALESCE(status, 'active') AS status
        FROM tbl_faculty
        WHERE faculty_id = ?
    ";

    if ($activeOnly && $hasStatusColumn) {
        $sql .= " AND status = 'active'";
    }

    $sql .= " LIMIT 1";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $facultyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;

    if ($result instanceof mysqli_result) {
        $result->close();
    }
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $row['faculty_id'] = (int)($row['faculty_id'] ?? 0);
    $row['faculty_name'] = synk_professor_full_name_from_row($row);
    $row['status'] = strtolower(trim((string)($row['status'] ?? 'active')));

    return $row;
}

function synk_professor_validate_faculty_id(mysqli $conn, int $facultyId): bool
{
    return synk_professor_fetch_faculty_record_by_id($conn, $facultyId, true) !== null;
}

function synk_persist_professor_faculty_link(mysqli $conn, int $userId, $roles, ?int $facultyId): ?string
{
    if ($userId <= 0) {
        return 'missing';
    }

    $hasProfessorRole = synk_professor_role_selected($roles);

    if (!$hasProfessorRole) {
        if (!synk_professor_faculty_link_table_exists($conn)) {
            return null;
        }

        $deleteStmt = $conn->prepare("DELETE FROM `" . synk_professor_faculty_link_table_name() . "` WHERE user_id = ?");
        if (!$deleteStmt) {
            return 'save_failed';
        }

        $deleteStmt->bind_param('i', $userId);
        if (!$deleteStmt->execute()) {
            $deleteStmt->close();
            return 'save_failed';
        }
        $deleteStmt->close();

        return null;
    }

    $safeFacultyId = (int)($facultyId ?? 0);
    if ($safeFacultyId <= 0) {
        return 'need_faculty';
    }

    if (!synk_professor_validate_faculty_id($conn, $safeFacultyId)) {
        return 'invalid_faculty';
    }

    if (!synk_professor_ensure_faculty_link_table($conn)) {
        return 'save_failed';
    }

    $tableName = synk_professor_faculty_link_table_name();
    $existingStmt = $conn->prepare("
        SELECT user_id
        FROM `{$tableName}`
        WHERE faculty_id = ?
          AND user_id <> ?
        LIMIT 1
    ");

    if (!$existingStmt) {
        return 'save_failed';
    }

    $existingStmt->bind_param('ii', $safeFacultyId, $userId);
    $existingStmt->execute();
    $existingResult = $existingStmt->get_result();
    $isInUse = $existingResult instanceof mysqli_result && $existingResult->num_rows > 0;

    if ($existingResult instanceof mysqli_result) {
        $existingResult->close();
    }
    $existingStmt->close();

    if ($isInUse) {
        return 'faculty_in_use';
    }

    $deleteStmt = $conn->prepare("DELETE FROM `{$tableName}` WHERE user_id = ?");
    if (!$deleteStmt) {
        return 'save_failed';
    }

    $deleteStmt->bind_param('i', $userId);
    if (!$deleteStmt->execute()) {
        $deleteStmt->close();
        return 'save_failed';
    }
    $deleteStmt->close();

    $insertStmt = $conn->prepare("
        INSERT INTO `{$tableName}` (user_id, faculty_id)
        VALUES (?, ?)
    ");

    if (!$insertStmt) {
        return 'save_failed';
    }

    $insertStmt->bind_param('ii', $userId, $safeFacultyId);
    if (!$insertStmt->execute()) {
        $insertStmt->close();
        return 'save_failed';
    }
    $insertStmt->close();

    return null;
}

function synk_professor_require_login(?mysqli $conn = null): void
{
    if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'professor') {
        header('Location: ../index.php');
        exit;
    }
}

function synk_professor_resolve_portal_context(mysqli $conn): array
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $email = trim((string)($_SESSION['email'] ?? ''));
    $accountName = trim((string)($_SESSION['username'] ?? 'Professor'));
    $facultyLink = synk_professor_fetch_faculty_link($conn, $userId);
    $facultyName = trim((string)($facultyLink['faculty_name'] ?? ''));
    $facultyStatus = strtolower(trim((string)($facultyLink['faculty_status'] ?? '')));

    if ($facultyName === '') {
        $facultyName = $accountName !== '' ? $accountName : 'Professor';
    }

    return [
        'user_id' => $userId,
        'email' => $email,
        'account_name' => $accountName,
        'faculty_link' => $facultyLink,
        'faculty_id' => (int)($facultyLink['faculty_id'] ?? 0),
        'faculty_name' => $facultyName,
        'faculty_status' => $facultyStatus,
        'faculty_is_linked' => $facultyLink !== null,
        'faculty_is_active' => $facultyStatus === '' || $facultyStatus === 'active',
    ];
}

function synk_professor_enrollment_table_name(): string
{
    return 'tbl_student_management_enrolled_subjects';
}

function synk_professor_workload_identity_sql(string $alias = 'es'): string
{
    return "
        CONCAT_WS(
            '|',
            CASE
                WHEN {$alias}.offering_id > 0 THEN CONCAT('off:', {$alias}.offering_id)
                ELSE 'off:0'
            END,
            COALESCE(NULLIF(TRIM({$alias}.subject_code), ''), CONCAT('sub:', {$alias}.subject_id)),
            COALESCE(NULLIF(TRIM({$alias}.descriptive_title), ''), ''),
            COALESCE(NULLIF(TRIM({$alias}.section_text), ''), CONCAT('section:', {$alias}.section_id)),
            COALESCE(NULLIF(TRIM({$alias}.schedule_text), ''), ''),
            COALESCE(NULLIF(TRIM({$alias}.room_text), ''), CONCAT('room:', {$alias}.room_id)),
            COALESCE(CAST({$alias}.program_id AS CHAR), '0')
        )
    ";
}

// Workload ownership comes from the assignment table. A schedule must still
// belong to that assignment's exact term and an active, valid class offering.
function synk_professor_workload_assignment_source_sql(): string
{
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('po', 'sec', 'sc', 'ps', 'pys', 'ph');
    return "FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_faculty assigned_faculty
            ON assigned_faculty.faculty_id = fw.faculty_id AND assigned_faculty.status = 'active'
        INNER JOIN tbl_class_schedule cs ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_prospectus_offering po
            ON po.offering_id = cs.offering_id
           AND po.ay_id = fw.ay_id AND po.semester = fw.semester
        {$liveOfferingJoins}
        INNER JOIN tbl_subject_masterlist sm ON sm.sub_id = ps.sub_id";
}

function synk_professor_fetch_assigned_term_rows(mysqli $conn, int $facultyId): array
{
    if ($facultyId <= 0 || !synk_table_exists($conn, 'tbl_faculty_workload_sched')
        || !synk_table_exists($conn, 'tbl_class_schedule') || !synk_table_exists($conn, 'tbl_prospectus_offering')) {
        return [];
    }
    $source = synk_professor_workload_assignment_source_sql();
    $stmt = $conn->prepare("SELECT fw.ay_id, fw.semester, ay.ay AS academic_year_label,
            MAX(fw.schedule_id) AS latest_schedule_id
        {$source}
        INNER JOIN tbl_academic_years ay ON ay.ay_id = fw.ay_id
        WHERE fw.faculty_id = ? AND fw.ay_id > 0 AND fw.semester BETWEEN 1 AND 3
        GROUP BY fw.ay_id, fw.semester, ay.ay
        ORDER BY ay.ay DESC, fw.semester DESC");
    $stmt->bind_param('i', $facultyId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function synk_professor_fetch_workload_term_options(mysqli $conn, int $facultyId): array
{
    if (
        $facultyId <= 0
        || !synk_table_exists($conn, 'tbl_faculty_workload_sched')
        || !synk_table_exists($conn, 'tbl_class_schedule')
        || !synk_table_exists($conn, 'tbl_prospectus_offering')
    ) {
        return [];
    }

    $rows = [];
    foreach (synk_professor_fetch_assigned_term_rows($conn, $facultyId) as $row) {
        $ayId = (int)($row['ay_id'] ?? 0);
        $semester = (int)($row['semester'] ?? 0);
        $workloadRows = ($ayId > 0 && $semester > 0)
            ? synk_professor_fetch_subject_rows_by_academic_year($conn, $facultyId, $ayId, $semester)
            : [];
        if (!$workloadRows) {
            continue;
        }
        $studentCount = 0;

        foreach ($workloadRows as $workloadRow) {
            $studentCount += max(0, (int)($workloadRow['student_count'] ?? 0));
        }

        $academicYearLabel = (string)($row['academic_year_label'] ?? '');
        $semesterLabel = function_exists('synk_semester_label')
            ? synk_semester_label($semester)
            : '';

        $rows[] = [
            'term_key' => $ayId . '-' . $semester,
            'ay_id' => $ayId,
            'semester' => $semester,
            'academic_year_label' => $academicYearLabel,
            'semester_label' => $semesterLabel,
            'term_label' => trim($academicYearLabel . ($semesterLabel !== '' ? ' - ' . $semesterLabel : '')),
            'workload_count' => count($workloadRows),
            'student_count' => $studentCount,
        ];
    }

    return $rows;
}

function synk_professor_fetch_workload_rows(mysqli $conn, int $facultyId, int $ayId, int $semester): array
{
    return synk_professor_fetch_subject_rows_by_academic_year($conn, $facultyId, $ayId, $semester);
}

function synk_professor_fetch_subject_year_options(mysqli $conn, int $facultyId): array
{
    if ($facultyId <= 0 || !synk_table_exists($conn, 'tbl_faculty_workload_sched') || !synk_table_exists($conn, 'tbl_class_schedule')) {
        return [];
    }

    $source = synk_professor_workload_assignment_source_sql();
    $stmt = $conn->prepare("
        SELECT
            fw.ay_id,
            COALESCE(ay.ay, '') AS academic_year_label,
            COUNT(DISTINCT cs.offering_id) AS subject_count
        {$source}
        LEFT JOIN tbl_academic_years ay
            ON ay.ay_id = fw.ay_id
        WHERE fw.faculty_id = ?
        GROUP BY fw.ay_id, ay.ay
        ORDER BY fw.ay_id DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $facultyId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = [
                'ay_id' => (int)($row['ay_id'] ?? 0),
                'academic_year_label' => (string)($row['academic_year_label'] ?? ''),
                'subject_count' => (int)($row['subject_count'] ?? 0),
            ];
        }

        $result->close();
    }
    $stmt->close();

    return $rows;
}

function synk_professor_format_days($daysJson): string
{
    $decoded = json_decode((string)$daysJson, true);
    if (!is_array($decoded) || empty($decoded)) {
        return '';
    }

    return implode('/', synk_normalize_schedule_days($decoded));
}

function synk_professor_format_time_range(string $timeStart, string $timeEnd): string
{
    $safeStart = trim($timeStart);
    $safeEnd = trim($timeEnd);

    if ($safeStart === '' || $safeEnd === '') {
        return '';
    }

    $startFormatted = date('g:i A', strtotime($safeStart));
    $endFormatted = date('g:i A', strtotime($safeEnd));

    return $startFormatted . ' - ' . $endFormatted;
}

function synk_professor_program_label(array $row): string
{
    $programCode = trim((string)($row['program_code'] ?? ''));
    $programName = trim((string)($row['program_name'] ?? ''));
    $major = trim((string)($row['program_major'] ?? ''));

    $label = $programCode;
    if ($programCode !== '' && $programName !== '') {
        $label .= ' - ' . $programName;
    } elseif ($programName !== '') {
        $label = $programName;
    }

    if ($major !== '') {
        $label .= ' major in ' . $major;
    }

    return trim($label);
}

function synk_professor_meeting_details(array $row): array
{
    $dayLabels = ['M' => 'Mon', 'T' => 'Tue', 'W' => 'Wed', 'Th' => 'Thu', 'F' => 'Fri', 'S' => 'Sat'];
    $days = synk_normalize_schedule_days(json_decode((string)($row['days_json'] ?? ''), true));
    $type = strtoupper(trim((string)($row['schedule_type'] ?? 'LEC')));
    $pendingStatus = (string)($row['schedule_status'] ?? 'scheduled');
    return [
        'type' => $type,
        'type_label' => $type === 'LAB' ? 'Lab' : ($type === 'LEC' ? 'Lecture' : ($type !== '' ? $type : 'Class')),
        'days' => $pendingStatus === 'tba' ? 'TBA' : implode(', ', array_map(static function (string $day) use ($dayLabels): string { return $dayLabels[$day] ?? $day; }, $days)),
        'time' => $pendingStatus === 'tba' ? 'TBA' : synk_professor_format_time_range((string)($row['time_start'] ?? ''), (string)($row['time_end'] ?? '')),
        'room' => $pendingStatus !== 'scheduled' && trim((string)($row['room_name'] ?? '')) === '' ? 'TBA' : trim((string)($row['room_name'] ?? '')),
    ];
}

function synk_professor_fetch_subject_rows_by_academic_year(mysqli $conn, int $facultyId, int $ayId, int $semester = 0): array
{
    if (
        $facultyId <= 0
        || $ayId <= 0
        || !synk_table_exists($conn, 'tbl_faculty_workload_sched')
        || !synk_table_exists($conn, 'tbl_class_schedule')
        || !synk_table_exists($conn, 'tbl_prospectus_offering')
    ) {
        return [];
    }

    $semesterWhereSql = $semester > 0 ? " AND fw.semester = ?" : "";
    $assignmentSource = synk_professor_workload_assignment_source_sql();
    $classScheduleHasType = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type');
    $sectionHasYearLevel = synk_table_has_column($conn, 'tbl_sections', 'year_level');
    $offeringHasYearLevel = synk_table_has_column($conn, 'tbl_prospectus_offering', 'year_level');
    $scheduleTypeSelect = $classScheduleHasType
        ? "COALESCE(cs.schedule_type, 'LEC') AS schedule_type"
        : "'LEC' AS schedule_type";
    $yearLevelSelect = '0 AS year_level';

    if ($sectionHasYearLevel && $offeringHasYearLevel) {
        $yearLevelSelect = 'COALESCE(sec.year_level, po.year_level, 0) AS year_level';
    } elseif ($sectionHasYearLevel) {
        $yearLevelSelect = 'COALESCE(sec.year_level, 0) AS year_level';
    } elseif ($offeringHasYearLevel) {
        $yearLevelSelect = 'COALESCE(po.year_level, 0) AS year_level';
    }

    $stmt = $conn->prepare("
        SELECT
            fw.faculty_id,
            fw.schedule_id,
            fw.ay_id,
            COALESCE(ay.ay, '') AS academic_year_label,
            fw.semester,
            po.offering_id,
            {$yearLevelSelect},
            COALESCE(NULLIF(sec.full_section, ''), CONCAT_WS(' ', NULLIF(TRIM(p.program_code), ''), NULLIF(TRIM(sec.section_name), ''))) AS section_display,
            sm.sub_code AS subject_code,
            sm.sub_description AS descriptive_title,
            {$scheduleTypeSelect},
            cs.schedule_status,
            cs.days_json,
            cs.time_start,
            cs.time_end,
            CASE
                WHEN NULLIF(TRIM(r.room_code), '') IS NOT NULL AND NULLIF(TRIM(r.room_name), '') IS NOT NULL THEN TRIM(CONCAT(r.room_code, ' - ', r.room_name))
                WHEN NULLIF(TRIM(r.room_code), '') IS NOT NULL THEN TRIM(r.room_code)
                WHEN NULLIF(TRIM(r.room_name), '') IS NOT NULL THEN TRIM(r.room_name)
                ELSE ''
            END AS room_name,
            COALESCE(p.program_code, '') AS program_code,
            COALESCE(p.program_name, '') AS program_name,
            COALESCE(p.major, '') AS program_major,
            COALESCE(c.college_name, '') AS college_name,
            COALESCE(ca.campus_name, '') AS campus_name
        {$assignmentSource}
        LEFT JOIN tbl_program p
            ON p.program_id = po.program_id
        LEFT JOIN tbl_college c
            ON c.college_id = p.college_id
        LEFT JOIN tbl_campus ca
            ON ca.campus_id = c.campus_id
        LEFT JOIN tbl_rooms r
            ON r.room_id = cs.room_id
        LEFT JOIN tbl_academic_years ay
            ON ay.ay_id = fw.ay_id
        WHERE fw.faculty_id = ?
          AND fw.ay_id = ?
          {$semesterWhereSql}
        ORDER BY
            fw.semester ASC,
            sm.sub_code ASC,
            po.offering_id ASC,
            cs.time_start ASC,
            cs.schedule_id ASC
    ");

    if (!$stmt) {
        return [];
    }

    if ($semester > 0) {
        $stmt->bind_param('iii', $facultyId, $ayId, $semester);
    } else {
        $stmt->bind_param('ii', $facultyId, $ayId);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $rawRows = [];
    $offeringIds = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rawRows[] = $row;
            $offeringIds[] = (int)($row['offering_id'] ?? 0);
        }

        $result->close();
    }
    $stmt->close();

    if (empty($rawRows)) {
        return [];
    }

    $mergeContext = synk_schedule_merge_load_display_context($conn, $offeringIds);
    $studentLookupIds = $offeringIds;
    foreach ($mergeContext as $mergeInfo) {
        foreach ((array)($mergeInfo['group_offering_ids'] ?? []) as $groupOfferingId) {
            $studentLookupIds[] = (int)$groupOfferingId;
        }
    }
    $studentCountMap = synk_fetch_offering_enrollee_count_map($conn, $studentLookupIds);

    $subjectsByOffering = [];

    foreach ($rawRows as $row) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        $mergeInfo = $mergeContext[$offeringId] ?? null;
        $ownerOfferingId = (int)($mergeInfo['owner_offering_id'] ?? $offeringId);
        $groupOfferingIds = !empty($mergeInfo['group_offering_ids'])
            ? array_values(array_unique(array_map('intval', (array)$mergeInfo['group_offering_ids'])))
            : [$offeringId];

        if (!isset($subjectsByOffering[$ownerOfferingId])) {
            $studentCount = 0;
            foreach ($groupOfferingIds as $groupOfferingId) {
                $studentCount += synk_offering_enrollee_count_for_map($studentCountMap, $groupOfferingId);
            }

            $subjectsByOffering[$ownerOfferingId] = [
                'faculty_id' => (int)$row['faculty_id'],
                'schedule_ids' => [],
                'offering_id' => $ownerOfferingId,
                'ay_id' => (int)($row['ay_id'] ?? 0),
                'academic_year_label' => (string)($row['academic_year_label'] ?? ''),
                'semester' => (int)($row['semester'] ?? 0),
                'semester_label' => function_exists('synk_semester_label') ? synk_semester_label((int)($row['semester'] ?? 0)) : '',
                'year_level' => (int)($row['year_level'] ?? 0),
                'section_display' => trim((string)($mergeInfo['group_course_label'] ?? (string)($row['section_display'] ?? ''))),
                'subject_code' => (string)($row['subject_code'] ?? ''),
                'descriptive_title' => (string)($row['descriptive_title'] ?? ''),
                'program_label' => synk_professor_program_label($row),
                'college_name' => (string)($row['college_name'] ?? ''),
                'campus_name' => (string)($row['campus_name'] ?? ''),
                'student_count' => $studentCount,
                'schedule_lines' => [],
                'room_values' => [],
                'meetings' => [],
            ];
        }

        $scheduleType = strtoupper(trim((string)($row['schedule_type'] ?? 'LEC')));
        $subjectsByOffering[$ownerOfferingId]['schedule_ids'][(int)$row['schedule_id']] = (int)$row['schedule_id'];
        $meeting = synk_professor_meeting_details($row);
        $meetingKey = json_encode($meeting);
        $subjectsByOffering[$ownerOfferingId]['meetings'][$meetingKey] = $meeting;
        $isTba = ($row['schedule_status'] ?? 'scheduled') === 'tba';
        $days = $isTba ? 'TBA' : synk_professor_format_days((string)($row['days_json'] ?? ''));
        $timeRange = $isTba ? 'TBA' : synk_professor_format_time_range((string)($row['time_start'] ?? ''), (string)($row['time_end'] ?? ''));
        $scheduleLine = trim(implode(' ', array_filter([
            $days,
            $timeRange,
            $scheduleType !== '' ? '(' . $scheduleType . ')' : '',
        ], static function ($value): bool {
            return trim((string)$value) !== '';
        })));

        if ($scheduleLine !== '') {
            $subjectsByOffering[$ownerOfferingId]['schedule_lines'][$scheduleLine] = $scheduleLine;
        }

        $roomName = trim((string)($row['room_name'] ?? ''));
        if ($roomName === '' && ($row['schedule_status'] ?? 'scheduled') !== 'scheduled') { $roomName = 'TBA'; }
        if ($roomName !== '') {
            $subjectsByOffering[$ownerOfferingId]['room_values'][$roomName] = $roomName;
        }
    }

    $rows = [];
    foreach ($subjectsByOffering as $subject) {
        $subject['schedule_text'] = !empty($subject['schedule_lines'])
            ? implode(' | ', array_values($subject['schedule_lines']))
            : 'Schedule not available';
        $subject['room_name'] = !empty($subject['room_values'])
            ? implode(' | ', array_values($subject['room_values']))
            : 'Room not assigned';

        unset($subject['schedule_lines'], $subject['room_values']);
        $subject['meetings'] = array_values($subject['meetings']);
        $subject['schedule_ids'] = array_values($subject['schedule_ids']);
        $rows[] = $subject;
    }

    usort($rows, static function (array $left, array $right): int {
        $semesterCompare = ((int)($left['semester'] ?? 0)) <=> ((int)($right['semester'] ?? 0));
        if ($semesterCompare !== 0) {
            return $semesterCompare;
        }

        $codeCompare = strnatcasecmp((string)($left['subject_code'] ?? ''), (string)($right['subject_code'] ?? ''));
        if ($codeCompare !== 0) {
            return $codeCompare;
        }

        return strnatcasecmp((string)($left['section_display'] ?? ''), (string)($right['section_display'] ?? ''));
    });

    return $rows;
}

function synk_professor_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function synk_professor_group_subject_rows_by_semester(array $rows): array
{
    $grouped = [];

    foreach ($rows as $row) {
        $semester = max(0, (int)($row['semester'] ?? 0));
        if (!isset($grouped[$semester])) {
            $grouped[$semester] = [
                'semester' => $semester,
                'semester_label' => function_exists('synk_semester_label') ? synk_semester_label($semester) : '',
                'subjects' => [],
            ];
        }

        $grouped[$semester]['subjects'][] = $row;
    }

    ksort($grouped);
    return array_values($grouped);
}
