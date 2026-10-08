<?php

require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/program_chair_helper.php';

function synk_enrollment_headers_table_name(): string
{
    return 'tbl_enrollment_headers';
}

function synk_enrollment_subjects_table_name(): string
{
    return 'tbl_enrollment_subjects';
}

function synk_enrollment_workflow_logs_table_name(): string
{
    return 'tbl_enrollment_workflow_logs';
}

function synk_enrollment_headers_table_exists(mysqli $conn): bool
{
    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string(synk_enrollment_headers_table_name()) . "'");
    $exists = $result instanceof mysqli_result && $result->num_rows > 0;

    if ($result instanceof mysqli_result) {
        $result->close();
    }

    return $exists;
}

function synk_enrollment_subjects_table_exists(mysqli $conn): bool
{
    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string(synk_enrollment_subjects_table_name()) . "'");
    $exists = $result instanceof mysqli_result && $result->num_rows > 0;

    if ($result instanceof mysqli_result) {
        $result->close();
    }

    return $exists;
}

function synk_enrollment_workflow_logs_table_exists(mysqli $conn): bool
{
    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string(synk_enrollment_workflow_logs_table_name()) . "'");
    $exists = $result instanceof mysqli_result && $result->num_rows > 0;

    if ($result instanceof mysqli_result) {
        $result->close();
    }

    return $exists;
}

function synk_enrollment_draft_tables_ready(mysqli $conn): bool
{
    return synk_enrollment_headers_table_exists($conn)
        && synk_enrollment_subjects_table_exists($conn)
        && synk_enrollment_workflow_logs_table_exists($conn);
}

function synk_enrollment_status_label(string $status): string
{
    $safeStatus = strtolower(trim($status));
    if ($safeStatus === 'draft') {
        return 'Draft';
    }
    if ($safeStatus === 'submitted') {
        return 'Submitted to Registrar';
    }
    if ($safeStatus === 'returned') {
        return 'Returned to Program Chair';
    }
    if ($safeStatus === 'approved') {
        return 'Approved';
    }
    if ($safeStatus === 'posted') {
        return 'Posted';
    }
    if ($safeStatus === 'cancelled') {
        return 'Cancelled';
    }

    return strtoupper($safeStatus);
}

function synk_enrollment_generate_reference(): string
{
    try {
        $randomSuffix = strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    } catch (Throwable $e) {
        $randomSuffix = strtoupper(substr(md5((string)microtime(true)), 0, 6));
    }

    return 'ENR-' . date('Ymd-His') . '-' . $randomSuffix;
}

function synk_enrollment_normalize_text($value): ?string
{
    $safeValue = trim((string)$value);
    return $safeValue !== '' ? $safeValue : null;
}

function synk_enrollment_fetch_section_context(mysqli $conn, int $sectionId, int $collegeId, int $ayId, int $semester): ?array
{
    if (
        $sectionId <= 0
        || $collegeId <= 0
        || $ayId <= 0
        || $semester <= 0
        || !synk_table_exists($conn, 'tbl_sections')
        || !synk_table_exists($conn, 'tbl_program')
        || !synk_table_exists($conn, 'tbl_college')
    ) {
        return null;
    }

    $stmt = $conn->prepare("
        SELECT
            s.section_id,
            s.program_id,
            s.year_level,
            s.ay_id,
            s.semester,
            COALESCE(s.section_name, '') AS section_name,
            COALESCE(s.full_section, '') AS full_section,
            p.college_id,
            COALESCE(p.program_code, '') AS program_code,
            COALESCE(p.program_name, '') AS program_name,
            COALESCE(p.major, '') AS major,
            COALESCE(c.college_code, '') AS college_code,
            COALESCE(c.college_name, '') AS college_name,
            COALESCE(c.campus_id, 0) AS campus_id,
            COALESCE(cp.campus_name, '') AS campus_name
        FROM tbl_sections s
        INNER JOIN tbl_program p
            ON p.program_id = s.program_id
        INNER JOIN tbl_college c
            ON c.college_id = p.college_id
        LEFT JOIN tbl_campus cp
            ON cp.campus_id = c.campus_id
        WHERE s.section_id = ?
          AND p.college_id = ?
          AND s.ay_id = ?
          AND s.semester = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('iiii', $sectionId, $collegeId, $ayId, $semester);
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

    $row['section_id'] = (int)($row['section_id'] ?? 0);
    $row['program_id'] = (int)($row['program_id'] ?? 0);
    $row['year_level'] = (int)($row['year_level'] ?? 0);
    $row['ay_id'] = (int)($row['ay_id'] ?? 0);
    $row['semester'] = (int)($row['semester'] ?? 0);
    $row['college_id'] = (int)($row['college_id'] ?? 0);
    $row['campus_id'] = (int)($row['campus_id'] ?? 0);
    $row['section_display'] = trim((string)($row['full_section'] ?? '')) !== ''
        ? trim((string)$row['full_section'])
        : trim(strtoupper(trim((string)($row['program_code'] ?? ''))) . ' ' . trim((string)($row['section_name'] ?? '')));

    return $row;
}

function synk_enrollment_insert_workflow_log(mysqli $conn, int $enrollmentId, string $actionType, string $fromStatus, string $toStatus, int $actorUserId, string $actorRole, ?string $remarks = null): void
{
    if ($enrollmentId <= 0 || !synk_enrollment_workflow_logs_table_exists($conn)) {
        return;
    }

    $tableName = synk_enrollment_workflow_logs_table_name();
    $stmt = $conn->prepare("
        INSERT INTO `{$tableName}` (
            enrollment_id,
            action_type,
            from_status,
            to_status,
            acted_by_user_id,
            acted_by_role,
            remarks
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        return;
    }

    $safeRemarks = synk_enrollment_normalize_text($remarks);
    $stmt->bind_param('isssiss', $enrollmentId, $actionType, $fromStatus, $toStatus, $actorUserId, $actorRole, $safeRemarks);
    $stmt->execute();
    $stmt->close();
}

function synk_enrollment_fetch_subject_rows(mysqli $conn, int $enrollmentId): array
{
    if ($enrollmentId <= 0 || !synk_enrollment_subjects_table_exists($conn)) {
        return [];
    }

    $tableName = synk_enrollment_subjects_table_name();
    $stmt = $conn->prepare("
        SELECT
            enrollment_subject_id,
            offering_id,
            COALESCE(subject_id, 0) AS subject_id,
            COALESCE(subject_code, '') AS subject_code,
            COALESCE(descriptive_title, '') AS descriptive_title,
            COALESCE(units, 0) AS units,
            COALESCE(section_text, '') AS section_text,
            COALESCE(schedule_text, '') AS schedule_text,
            COALESCE(room_text, '') AS room_text,
            COALESCE(faculty_text, '') AS faculty_text,
            COALESCE(sort_order, 0) AS sort_order
        FROM `{$tableName}`
        WHERE enrollment_id = ?
        ORDER BY sort_order ASC, enrollment_subject_id ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $row['enrollment_subject_id'] = (int)($row['enrollment_subject_id'] ?? 0);
            $row['offering_id'] = (int)($row['offering_id'] ?? 0);
            $row['subject_id'] = (int)($row['subject_id'] ?? 0);
            $row['units'] = round((float)($row['units'] ?? 0), 2);
            $row['sort_order'] = (int)($row['sort_order'] ?? 0);
            $rows[] = $row;
        }

        $result->close();
    }

    $stmt->close();
    return $rows;
}

function synk_enrollment_header_select_sql(string $whereSql): string
{
    $headersTable = synk_enrollment_headers_table_name();
    $subjectsTable = synk_enrollment_subjects_table_name();

    return "
        SELECT
            h.enrollment_id,
            h.enrollment_reference,
            h.workflow_status,
            h.student_record_mode,
            h.campus_id,
            h.college_id,
            h.program_id,
            h.section_id,
            h.ay_id,
            h.semester,
            h.year_level,
            h.enrollment_type,
            COALESCE(h.student_id, 0) AS student_id,
            COALESCE(h.student_number, '') AS student_number,
            COALESCE(h.last_name, '') AS last_name,
            COALESCE(h.first_name, '') AS first_name,
            COALESCE(h.middle_name, '') AS middle_name,
            COALESCE(h.suffix_name, '') AS suffix_name,
            COALESCE(h.sex, '') AS sex,
            h.birthdate,
            COALESCE(h.email_address, '') AS email_address,
            COALESCE(h.contact_number, '') AS contact_number,
            COALESCE(h.chair_notes, '') AS chair_notes,
            COALESCE(h.registrar_notes, '') AS registrar_notes,
            h.created_by,
            COALESCE(h.submitted_by, 0) AS submitted_by,
            COALESCE(h.reviewed_by, 0) AS reviewed_by,
            COALESCE(h.approved_by, 0) AS approved_by,
            h.created_at,
            h.updated_at,
            h.submitted_at,
            h.reviewed_at,
            h.approved_at,
            COALESCE(c.college_code, '') AS college_code,
            COALESCE(c.college_name, '') AS college_name,
            COALESCE(cp.campus_name, '') AS campus_name,
            COALESCE(p.program_code, '') AS program_code,
            COALESCE(p.program_name, '') AS program_name,
            COALESCE(p.major, '') AS major,
            COALESCE(s.section_name, '') AS section_name,
            COALESCE(s.full_section, '') AS full_section,
            COALESCE(creator.username, '') AS created_by_name,
            COALESCE(submitter.username, '') AS submitted_by_name,
            COALESCE(reviewer.username, '') AS reviewed_by_name,
            COUNT(es.enrollment_subject_id) AS subject_count,
            COALESCE(SUM(es.units), 0) AS total_units
        FROM `{$headersTable}` h
        LEFT JOIN tbl_college c
            ON c.college_id = h.college_id
        LEFT JOIN tbl_campus cp
            ON cp.campus_id = h.campus_id
        LEFT JOIN tbl_program p
            ON p.program_id = h.program_id
        LEFT JOIN tbl_sections s
            ON s.section_id = h.section_id
        LEFT JOIN tbl_useraccount creator
            ON creator.user_id = h.created_by
        LEFT JOIN tbl_useraccount submitter
            ON submitter.user_id = h.submitted_by
        LEFT JOIN tbl_useraccount reviewer
            ON reviewer.user_id = h.reviewed_by
        LEFT JOIN `{$subjectsTable}` es
            ON es.enrollment_id = h.enrollment_id
        {$whereSql}
        GROUP BY h.enrollment_id
    ";
}

function synk_enrollment_fetch_program_chair_draft_rows(mysqli $conn, int $userId, int $collegeId): array
{
    if ($userId <= 0 || $collegeId <= 0 || !synk_enrollment_draft_tables_ready($conn)) {
        return [];
    }

    $stmt = $conn->prepare(synk_enrollment_header_select_sql("
        WHERE h.created_by = ?
          AND h.college_id = ?
    ") . "
        ORDER BY
            CASE h.workflow_status
                WHEN 'draft' THEN 0
                WHEN 'returned' THEN 1
                WHEN 'submitted' THEN 2
                WHEN 'approved' THEN 3
                ELSE 4
            END ASC,
            h.updated_at DESC,
            h.enrollment_id DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('ii', $userId, $collegeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $row['enrollment_id'] = (int)($row['enrollment_id'] ?? 0);
            $row['subject_count'] = (int)($row['subject_count'] ?? 0);
            $row['total_units'] = round((float)($row['total_units'] ?? 0), 2);
            $row['status_label'] = synk_enrollment_status_label((string)($row['workflow_status'] ?? 'draft'));
            $rows[] = $row;
        }

        $result->close();
    }

    $stmt->close();
    return $rows;
}

function synk_enrollment_fetch_program_chair_draft_detail(mysqli $conn, int $enrollmentId, int $userId, int $collegeId, bool $editableOnly = false): ?array
{
    if ($enrollmentId <= 0 || $userId <= 0 || $collegeId <= 0 || !synk_enrollment_draft_tables_ready($conn)) {
        return null;
    }

    $whereSql = "
        WHERE h.enrollment_id = ?
          AND h.created_by = ?
          AND h.college_id = ?
    ";

    if ($editableOnly) {
        $whereSql .= " AND h.workflow_status IN ('draft', 'returned')";
    }

    $stmt = $conn->prepare(synk_enrollment_header_select_sql($whereSql) . " LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('iii', $enrollmentId, $userId, $collegeId);
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

    $row['enrollment_id'] = (int)($row['enrollment_id'] ?? 0);
    $row['subject_count'] = (int)($row['subject_count'] ?? 0);
    $row['total_units'] = round((float)($row['total_units'] ?? 0), 2);
    $row['status_label'] = synk_enrollment_status_label((string)($row['workflow_status'] ?? 'draft'));
    $row['subjects'] = synk_enrollment_fetch_subject_rows($conn, (int)$row['enrollment_id']);
    $row['workflow_logs'] = synk_enrollment_fetch_workflow_logs($conn, (int)$row['enrollment_id']);

    return $row;
}

function synk_enrollment_fetch_registrar_queue_rows(mysqli $conn, int $campusId): array
{
    if ($campusId <= 0 || !synk_enrollment_draft_tables_ready($conn)) {
        return [];
    }

    $stmt = $conn->prepare(synk_enrollment_header_select_sql("
        WHERE h.campus_id = ?
          AND h.workflow_status IN ('submitted', 'returned', 'approved')
    ") . "
        ORDER BY
            CASE h.workflow_status
                WHEN 'submitted' THEN 0
                WHEN 'returned' THEN 1
                WHEN 'approved' THEN 2
                ELSE 3
            END ASC,
            COALESCE(h.submitted_at, h.updated_at) DESC,
            h.enrollment_id DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $campusId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $row['enrollment_id'] = (int)($row['enrollment_id'] ?? 0);
            $row['subject_count'] = (int)($row['subject_count'] ?? 0);
            $row['total_units'] = round((float)($row['total_units'] ?? 0), 2);
            $row['status_label'] = synk_enrollment_status_label((string)($row['workflow_status'] ?? 'draft'));
            $rows[] = $row;
        }

        $result->close();
    }

    $stmt->close();
    return $rows;
}

function synk_enrollment_fetch_registrar_detail(mysqli $conn, int $enrollmentId, int $campusId): ?array
{
    if ($enrollmentId <= 0 || $campusId <= 0 || !synk_enrollment_draft_tables_ready($conn)) {
        return null;
    }

    $stmt = $conn->prepare(synk_enrollment_header_select_sql("
        WHERE h.enrollment_id = ?
          AND h.campus_id = ?
    ") . " LIMIT 1");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ii', $enrollmentId, $campusId);
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

    $row['enrollment_id'] = (int)($row['enrollment_id'] ?? 0);
    $row['subject_count'] = (int)($row['subject_count'] ?? 0);
    $row['total_units'] = round((float)($row['total_units'] ?? 0), 2);
    $row['status_label'] = synk_enrollment_status_label((string)($row['workflow_status'] ?? 'draft'));
    $row['subjects'] = synk_enrollment_fetch_subject_rows($conn, (int)$row['enrollment_id']);
    $row['workflow_logs'] = synk_enrollment_fetch_workflow_logs($conn, (int)$row['enrollment_id']);

    return $row;
}

function synk_enrollment_fetch_workflow_logs(mysqli $conn, int $enrollmentId): array
{
    if ($enrollmentId <= 0 || !synk_enrollment_workflow_logs_table_exists($conn)) {
        return [];
    }

    $tableName = synk_enrollment_workflow_logs_table_name();
    $stmt = $conn->prepare("
        SELECT
            l.workflow_log_id,
            COALESCE(l.action_type, '') AS action_type,
            COALESCE(l.from_status, '') AS from_status,
            COALESCE(l.to_status, '') AS to_status,
            COALESCE(l.acted_by_user_id, 0) AS acted_by_user_id,
            COALESCE(l.acted_by_role, '') AS acted_by_role,
            COALESCE(l.remarks, '') AS remarks,
            l.created_at,
            COALESCE(u.username, '') AS actor_name
        FROM `{$tableName}` l
        LEFT JOIN tbl_useraccount u
            ON u.user_id = l.acted_by_user_id
        WHERE l.enrollment_id = ?
        ORDER BY l.created_at DESC, l.workflow_log_id DESC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $enrollmentId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $row['workflow_log_id'] = (int)($row['workflow_log_id'] ?? 0);
            $row['acted_by_user_id'] = (int)($row['acted_by_user_id'] ?? 0);
            $rows[] = $row;
        }

        $result->close();
    }

    $stmt->close();
    return $rows;
}

function synk_enrollment_student_profile_complete(array $payload): bool
{
    $mode = strtolower(trim((string)($payload['student_record_mode'] ?? 'first_year')));
    $lastName = trim((string)($payload['last_name'] ?? ''));
    $firstName = trim((string)($payload['first_name'] ?? ''));
    $studentNumber = trim((string)($payload['student_number'] ?? ''));

    if ($mode === 'existing') {
        return $studentNumber !== '' || ($lastName !== '' && $firstName !== '');
    }

    return $lastName !== '' && $firstName !== '';
}

function synk_enrollment_normalize_subject_selection(array $inputRows, array $liveOfferingRows): array
{
    $liveMap = [];
    foreach ($liveOfferingRows as $liveRow) {
        $offeringId = (int)($liveRow['offering_id'] ?? 0);
        if ($offeringId <= 0) {
            continue;
        }

        $liveMap[$offeringId] = $liveRow;
    }

    $normalizedRows = [];
    $seenOfferingIds = [];

    foreach ($inputRows as $index => $inputRow) {
        $offeringId = (int)($inputRow['offering_id'] ?? 0);
        if ($offeringId <= 0 || isset($seenOfferingIds[$offeringId]) || !isset($liveMap[$offeringId])) {
            continue;
        }

        $seenOfferingIds[$offeringId] = true;
        $liveRow = $liveMap[$offeringId];
        $sectionText = trim((string)($liveRow['full_section'] ?? ''));
        if ($sectionText === '') {
            $sectionText = trim((string)($liveRow['section_name'] ?? ''));
        }

        $normalizedRows[] = [
            'offering_id' => $offeringId,
            'subject_id' => (int)($liveRow['subject_id'] ?? 0),
            'subject_code' => trim((string)($liveRow['subject_code'] ?? '')),
            'descriptive_title' => trim((string)($liveRow['subject_description'] ?? '')),
            'units' => round((float)($liveRow['total_units'] ?? 0), 2),
            'section_text' => $sectionText,
            'schedule_text' => trim((string)($liveRow['schedule_text'] ?? '')),
            'room_text' => trim((string)($liveRow['room_text'] ?? '')),
            'faculty_text' => trim((string)($liveRow['faculty_text'] ?? '')),
            'sort_order' => count($normalizedRows) + 1,
        ];
    }

    return $normalizedRows;
}

function synk_enrollment_persist_subject_rows(mysqli $conn, int $enrollmentId, array $subjectRows): ?string
{
    if ($enrollmentId <= 0 || !synk_enrollment_subjects_table_exists($conn)) {
        return 'schema_error';
    }

    $tableName = synk_enrollment_subjects_table_name();
    $deleteStmt = $conn->prepare("DELETE FROM `{$tableName}` WHERE enrollment_id = ?");
    if (!$deleteStmt) {
        return 'save_failed';
    }

    $deleteStmt->bind_param('i', $enrollmentId);
    if (!$deleteStmt->execute()) {
        $deleteStmt->close();
        return 'save_failed';
    }
    $deleteStmt->close();

    if (empty($subjectRows)) {
        return null;
    }

    $insertStmt = $conn->prepare("
        INSERT INTO `{$tableName}` (
            enrollment_id,
            offering_id,
            subject_id,
            subject_code,
            descriptive_title,
            units,
            section_text,
            schedule_text,
            room_text,
            faculty_text,
            sort_order
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$insertStmt) {
        return 'save_failed';
    }

    foreach ($subjectRows as $subjectRow) {
        $subjectId = (int)($subjectRow['subject_id'] ?? 0);
        $offeringId = (int)($subjectRow['offering_id'] ?? 0);
        $subjectCode = trim((string)($subjectRow['subject_code'] ?? ''));
        $descriptiveTitle = trim((string)($subjectRow['descriptive_title'] ?? ''));
        $units = round((float)($subjectRow['units'] ?? 0), 2);
        $sectionText = trim((string)($subjectRow['section_text'] ?? ''));
        $scheduleText = trim((string)($subjectRow['schedule_text'] ?? ''));
        $roomText = trim((string)($subjectRow['room_text'] ?? ''));
        $facultyText = trim((string)($subjectRow['faculty_text'] ?? ''));
        $sortOrder = (int)($subjectRow['sort_order'] ?? 0);

        $insertStmt->bind_param(
            'iiissdssssi',
            $enrollmentId,
            $offeringId,
            $subjectId,
            $subjectCode,
            $descriptiveTitle,
            $units,
            $sectionText,
            $scheduleText,
            $roomText,
            $facultyText,
            $sortOrder
        );

        if (!$insertStmt->execute()) {
            $insertStmt->close();
            return 'save_failed';
        }
    }

    $insertStmt->close();
    return null;
}

function synk_enrollment_save_program_chair_draft(
    mysqli $conn,
    int $userId,
    int $collegeId,
    array $sectionContext,
    array $headerPayload,
    array $subjectRows,
    int $enrollmentId = 0,
    string $actorRole = 'program_chair'
): array {
    if (
        $userId <= 0
        || $collegeId <= 0
        || empty($sectionContext)
        || !synk_enrollment_draft_tables_ready($conn)
    ) {
        return ['error' => 'schema_error'];
    }

    $safeEnrollmentId = max(0, $enrollmentId);
    $existingDetail = null;
    if ($safeEnrollmentId > 0) {
        $existingDetail = synk_enrollment_fetch_program_chair_draft_detail($conn, $safeEnrollmentId, $userId, $collegeId, true);
        if (!$existingDetail) {
            return ['error' => 'draft_not_found'];
        }
    }

    $headersTable = synk_enrollment_headers_table_name();
    $studentRecordMode = strtolower(trim((string)($headerPayload['student_record_mode'] ?? 'first_year')));
    if (!in_array($studentRecordMode, ['first_year', 'existing'], true)) {
        $studentRecordMode = 'first_year';
    }

    $enrollmentType = strtolower(trim((string)($headerPayload['enrollment_type'] ?? 'regular')));
    $allowedEnrollmentTypes = ['regular', 'irregular', 'first_year', 'transferee', 'returnee'];
    if (!in_array($enrollmentType, $allowedEnrollmentTypes, true)) {
        $enrollmentType = 'regular';
    }

    $studentId = max(0, (int)($headerPayload['student_id'] ?? 0));
    $studentNumber = synk_enrollment_normalize_text($headerPayload['student_number'] ?? null);
    $lastName = synk_enrollment_normalize_text($headerPayload['last_name'] ?? null);
    $firstName = synk_enrollment_normalize_text($headerPayload['first_name'] ?? null);
    $middleName = synk_enrollment_normalize_text($headerPayload['middle_name'] ?? null);
    $suffixName = synk_enrollment_normalize_text($headerPayload['suffix_name'] ?? null);
    $sex = synk_enrollment_normalize_text($headerPayload['sex'] ?? null);
    $birthdate = synk_enrollment_normalize_text($headerPayload['birthdate'] ?? null);
    $emailAddress = synk_enrollment_normalize_text($headerPayload['email_address'] ?? null);
    $contactNumber = synk_enrollment_normalize_text($headerPayload['contact_number'] ?? null);
    $verificationNote = synk_enrollment_normalize_text($headerPayload['verification_note'] ?? null);
    $chairNotes = synk_enrollment_normalize_text($headerPayload['chair_notes'] ?? null);

    $campusId = (int)($sectionContext['campus_id'] ?? 0);
    $sectionId = (int)($sectionContext['section_id'] ?? 0);
    $programId = (int)($sectionContext['program_id'] ?? 0);
    $ayId = (int)($sectionContext['ay_id'] ?? 0);
    $semester = (int)($sectionContext['semester'] ?? 0);
    $yearLevel = (int)($sectionContext['year_level'] ?? 0);

    if ($campusId <= 0 || $sectionId <= 0 || $programId <= 0 || $ayId <= 0 || $semester <= 0) {
        return ['error' => 'invalid_section'];
    }

    $conn->begin_transaction();

    try {
        if ($existingDetail) {
            $stmt = $conn->prepare("
                UPDATE `{$headersTable}`
                SET
                    campus_id = ?,
                    college_id = ?,
                    program_id = ?,
                    section_id = ?,
                    ay_id = ?,
                    semester = ?,
                    year_level = ?,
                    enrollment_type = ?,
                    student_record_mode = ?,
                    student_id = ?,
                    student_number = ?,
                    last_name = ?,
                    first_name = ?,
                    middle_name = ?,
                    suffix_name = ?,
                    sex = ?,
                    birthdate = ?,
                    email_address = ?,
                    contact_number = ?,
                    verification_note = ?,
                    chair_notes = ?
                WHERE enrollment_id = ?
            ");

            if (!$stmt) {
                throw new RuntimeException('save_failed');
            }

            $stmt->bind_param(
                'iiiiiiississsssssssssi',
                $campusId,
                $collegeId,
                $programId,
                $sectionId,
                $ayId,
                $semester,
                $yearLevel,
                $enrollmentType,
                $studentRecordMode,
                $studentId,
                $studentNumber,
                $lastName,
                $firstName,
                $middleName,
                $suffixName,
                $sex,
                $birthdate,
                $emailAddress,
                $contactNumber,
                $verificationNote,
                $chairNotes,
                $safeEnrollmentId
            );

            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('save_failed');
            }

            $stmt->close();
        } else {
            $enrollmentReference = synk_enrollment_generate_reference();
            $workflowStatus = 'draft';
            $stmt = $conn->prepare("
                INSERT INTO `{$headersTable}` (
                    enrollment_reference,
                    workflow_status,
                    campus_id,
                    college_id,
                    program_id,
                    section_id,
                    ay_id,
                    semester,
                    year_level,
                    enrollment_type,
                    student_record_mode,
                    student_id,
                    student_number,
                    last_name,
                    first_name,
                    middle_name,
                    suffix_name,
                    sex,
                    birthdate,
                    email_address,
                    contact_number,
                    verification_note,
                    chair_notes,
                    created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            if (!$stmt) {
                throw new RuntimeException('save_failed');
            }

            $stmt->bind_param(
                'ssiiiiiiississsssssssssi',
                $enrollmentReference,
                $workflowStatus,
                $campusId,
                $collegeId,
                $programId,
                $sectionId,
                $ayId,
                $semester,
                $yearLevel,
                $enrollmentType,
                $studentRecordMode,
                $studentId,
                $studentNumber,
                $lastName,
                $firstName,
                $middleName,
                $suffixName,
                $sex,
                $birthdate,
                $emailAddress,
                $contactNumber,
                $verificationNote,
                $chairNotes,
                $userId
            );

            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('save_failed');
            }

            $safeEnrollmentId = (int)$conn->insert_id;
            $stmt->close();
        }

        $subjectPersistError = synk_enrollment_persist_subject_rows($conn, $safeEnrollmentId, $subjectRows);
        if ($subjectPersistError !== null) {
            throw new RuntimeException($subjectPersistError);
        }

        if ($existingDetail) {
            synk_enrollment_insert_workflow_log(
                $conn,
                $safeEnrollmentId,
                'updated',
                (string)($existingDetail['workflow_status'] ?? 'draft'),
                (string)($existingDetail['workflow_status'] ?? 'draft'),
                $userId,
                $actorRole,
                'Draft details updated.'
            );
        } else {
            synk_enrollment_insert_workflow_log(
                $conn,
                $safeEnrollmentId,
                'created',
                '',
                'draft',
                $userId,
                $actorRole,
                'Enrollment draft created.'
            );
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['error' => $e->getMessage() !== '' ? $e->getMessage() : 'save_failed'];
    }

    $detail = synk_enrollment_fetch_program_chair_draft_detail($conn, $safeEnrollmentId, $userId, $collegeId, false);
    if (!$detail) {
        return ['error' => 'save_failed'];
    }

    return ['detail' => $detail];
}

function synk_enrollment_submit_program_chair_draft(mysqli $conn, int $enrollmentId, int $userId, int $collegeId, string $actorRole = 'program_chair'): array
{
    if ($enrollmentId <= 0 || $userId <= 0 || $collegeId <= 0 || !synk_enrollment_draft_tables_ready($conn)) {
        return ['error' => 'missing'];
    }

    $detail = synk_enrollment_fetch_program_chair_draft_detail($conn, $enrollmentId, $userId, $collegeId, true);
    if (!$detail) {
        return ['error' => 'draft_not_found'];
    }

    $allowedStatuses = ['draft', 'returned'];
    $currentStatus = strtolower(trim((string)($detail['workflow_status'] ?? 'draft')));
    if (!in_array($currentStatus, $allowedStatuses, true)) {
        return ['error' => 'submit_not_allowed'];
    }

    if ((int)($detail['program_id'] ?? 0) <= 0 || (int)($detail['section_id'] ?? 0) <= 0) {
        return ['error' => 'missing_setup'];
    }

    if (!synk_enrollment_student_profile_complete($detail)) {
        return ['error' => 'missing_student'];
    }

    if ((int)($detail['subject_count'] ?? 0) <= 0) {
        return ['error' => 'missing_subjects'];
    }

    $headersTable = synk_enrollment_headers_table_name();
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("
            UPDATE `{$headersTable}`
            SET
                workflow_status = 'submitted',
                submitted_by = ?,
                submitted_at = NOW()
            WHERE enrollment_id = ?
        ");

        if (!$stmt) {
            throw new RuntimeException('save_failed');
        }

        $stmt->bind_param('ii', $userId, $enrollmentId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('save_failed');
        }
        $stmt->close();

        synk_enrollment_insert_workflow_log(
            $conn,
            $enrollmentId,
            'submitted',
            $currentStatus,
            'submitted',
            $userId,
            $actorRole,
            'Draft submitted to registrar queue.'
        );

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['error' => $e->getMessage() !== '' ? $e->getMessage() : 'save_failed'];
    }

    $updatedDetail = synk_enrollment_fetch_program_chair_draft_detail($conn, $enrollmentId, $userId, $collegeId, false);
    if (!$updatedDetail) {
        return ['error' => 'save_failed'];
    }

    return ['detail' => $updatedDetail];
}
