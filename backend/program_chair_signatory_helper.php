<?php

require_once __DIR__ . '/schema_helper.php';

function synk_program_chair_signatory_table_name(): string
{
    return 'tbl_program_chair_signatories';
}

function synk_program_chair_signatory_ensure_table(mysqli $conn): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $tableName = synk_program_chair_signatory_table_name();

    if (!synk_table_exists($conn, $tableName)) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS `{$tableName}` (
                `program_chair_signatory_id` INT NOT NULL AUTO_INCREMENT,
                `college_id` INT(10) UNSIGNED NOT NULL,
                `program_id` INT(10) UNSIGNED NOT NULL,
                `faculty_id` INT(10) UNSIGNED NOT NULL,
                `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
                `updated_by` INT NULL DEFAULT NULL,
                `date_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `date_updated` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`program_chair_signatory_id`),
                UNIQUE KEY `uniq_program_chair_scope` (`college_id`, `program_id`),
                KEY `idx_program_chair_college_status` (`college_id`, `status`),
                KEY `idx_program_chair_faculty` (`faculty_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $ensured = true;
        return;
    }

    $columns = synk_describe_columns($conn, $tableName);
    $clauses = [];

    if (!isset($columns['college_id'])) {
        $clauses[] = "ADD COLUMN college_id INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER program_chair_signatory_id";
    }

    if (!isset($columns['program_id'])) {
        $clauses[] = "ADD COLUMN program_id INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER college_id";
    }

    if (!isset($columns['faculty_id'])) {
        $clauses[] = "ADD COLUMN faculty_id INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER program_id";
    }

    if (!isset($columns['status'])) {
        $clauses[] = "ADD COLUMN status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER faculty_id";
    }

    if (!isset($columns['updated_by'])) {
        $clauses[] = "ADD COLUMN updated_by INT NULL DEFAULT NULL AFTER status";
    }

    if (!isset($columns['date_created'])) {
        $clauses[] = "ADD COLUMN date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER updated_by";
    }

    if (!isset($columns['date_updated'])) {
        $clauses[] = "ADD COLUMN date_updated DATETIME NULL DEFAULT NULL AFTER date_created";
    }

    if (!empty($clauses)) {
        $conn->query("ALTER TABLE `{$tableName}` " . implode(', ', $clauses));
    }

    if (!synk_table_has_index($conn, $tableName, 'uniq_program_chair_scope')) {
        $conn->query("ALTER TABLE `{$tableName}` ADD UNIQUE INDEX `uniq_program_chair_scope` (`college_id`, `program_id`)");
    }

    if (!synk_table_has_index($conn, $tableName, 'idx_program_chair_college_status')) {
        $conn->query("ALTER TABLE `{$tableName}` ADD INDEX `idx_program_chair_college_status` (`college_id`, `status`)");
    }

    if (!synk_table_has_index($conn, $tableName, 'idx_program_chair_faculty')) {
        $conn->query("ALTER TABLE `{$tableName}` ADD INDEX `idx_program_chair_faculty` (`faculty_id`)");
    }

    $ensured = true;
}

function synk_program_chair_signatory_name(array $row): string
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

function synk_program_chair_signatory_program_label(array $row): string
{
    $programCode = trim((string)($row['program_code'] ?? ''));
    $programName = trim((string)($row['program_name'] ?? ''));
    $major = trim((string)($row['major'] ?? ''));

    $label = $programCode;
    if ($programName !== '') {
        $label = $label !== '' ? $label . ' - ' . $programName : $programName;
    }

    if ($major !== '') {
        $label .= ' (' . $major . ')';
    }

    return trim($label);
}

function synk_program_chair_signatory_fetch_program_options(mysqli $conn, int $collegeId): array
{
    if ($collegeId <= 0) {
        return [];
    }

    synk_program_chair_signatory_ensure_table($conn);

    $stmt = $conn->prepare("
        SELECT
            program_id,
            program_code,
            program_name,
            COALESCE(major, '') AS major
        FROM tbl_program
        WHERE college_id = ?
          AND status = 'active'
        ORDER BY program_code ASC, program_name ASC, major ASC
    ");

    if (!($stmt instanceof mysqli_stmt)) {
        return [];
    }

    $stmt->bind_param('i', $collegeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $programs = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $row['program_id'] = (int)($row['program_id'] ?? 0);
            $row['label'] = synk_program_chair_signatory_program_label($row);
            $programs[] = $row;
        }
    }

    $stmt->close();

    return $programs;
}

function synk_program_chair_signatory_college_faculty_term_where(
    mysqli $conn,
    int $ayId,
    int $semester,
    string &$types,
    array &$params
): string {
    $assignmentHasAyId = synk_table_has_column($conn, 'tbl_college_faculty', 'ay_id');
    $assignmentHasSemester = synk_table_has_column($conn, 'tbl_college_faculty', 'semester');

    if ($assignmentHasAyId && $assignmentHasSemester && $ayId > 0 && $semester > 0) {
        $types .= 'ii';
        $params[] = $ayId;
        $params[] = $semester;

        return ' AND ((cf.ay_id = ? AND cf.semester = ?) OR (cf.ay_id IS NULL AND cf.semester IS NULL))';
    }

    return '';
}

function synk_program_chair_signatory_fetch_faculty_options(
    mysqli $conn,
    int $collegeId,
    int $ayId = 0,
    int $semester = 0
): array {
    if ($collegeId <= 0) {
        return [];
    }

    synk_program_chair_signatory_ensure_table($conn);

    $facultyHasMiddleName = synk_table_has_column($conn, 'tbl_faculty', 'middle_name');
    $facultyHasExtName = synk_table_has_column($conn, 'tbl_faculty', 'ext_name');
    $facultyHasDesignationId = synk_table_has_column($conn, 'tbl_faculty', 'designation_id');
    $designationExists = synk_table_exists($conn, 'tbl_designation');
    $designationHasStatus = $designationExists && synk_table_has_column($conn, 'tbl_designation', 'status');

    $types = 'i';
    $params = [$collegeId];
    $termWhere = synk_program_chair_signatory_college_faculty_term_where($conn, $ayId, $semester, $types, $params);

    $designationJoin = '';
    $designationSelect = "'' AS designation_name";
    if ($facultyHasDesignationId && $designationExists) {
        $designationJoin = "
        LEFT JOIN tbl_designation d
            ON d.designation_id = f.designation_id
           " . ($designationHasStatus ? "AND d.status = 'active'" : '');
        $designationSelect = "COALESCE(d.designation_name, '') AS designation_name";
    }

    $sql = "
        SELECT DISTINCT
            f.faculty_id,
            f.last_name,
            f.first_name,
            " . ($facultyHasMiddleName ? 'f.middle_name' : "'' AS middle_name") . ",
            " . ($facultyHasExtName ? 'f.ext_name' : "'' AS ext_name") . ",
            {$designationSelect}
        FROM tbl_college_faculty cf
        INNER JOIN tbl_faculty f
            ON f.faculty_id = cf.faculty_id
        {$designationJoin}
        WHERE cf.college_id = ?
          AND cf.status = 'active'
          AND f.status = 'active'
          {$termWhere}
        ORDER BY f.last_name ASC, f.first_name ASC, f.faculty_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!($stmt instanceof mysqli_stmt)) {
        return [];
    }

    synk_bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $facultyRows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $facultyRows[] = [
                'faculty_id' => (int)($row['faculty_id'] ?? 0),
                'full_name' => synk_program_chair_signatory_name($row),
                'designation_name' => trim((string)($row['designation_name'] ?? '')),
            ];
        }
    }

    $stmt->close();

    return $facultyRows;
}

function synk_program_chair_signatory_fetch_assignments(mysqli $conn, int $collegeId): array
{
    if ($collegeId <= 0) {
        return [];
    }

    synk_program_chair_signatory_ensure_table($conn);

    $facultyHasMiddleName = synk_table_has_column($conn, 'tbl_faculty', 'middle_name');
    $facultyHasExtName = synk_table_has_column($conn, 'tbl_faculty', 'ext_name');
    $facultyHasDesignationId = synk_table_has_column($conn, 'tbl_faculty', 'designation_id');
    $designationExists = synk_table_exists($conn, 'tbl_designation');
    $designationHasStatus = $designationExists && synk_table_has_column($conn, 'tbl_designation', 'status');

    $designationJoin = '';
    $designationSelect = "'' AS designation_name";
    if ($facultyHasDesignationId && $designationExists) {
        $designationJoin = "
        LEFT JOIN tbl_designation d
            ON d.designation_id = f.designation_id
           " . ($designationHasStatus ? "AND d.status = 'active'" : '');
        $designationSelect = "COALESCE(d.designation_name, '') AS designation_name";
    }

    $stmt = $conn->prepare("
        SELECT
            pcs.program_chair_signatory_id,
            pcs.program_id,
            pcs.faculty_id,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS major,
            f.last_name,
            f.first_name,
            " . ($facultyHasMiddleName ? 'f.middle_name' : "'' AS middle_name") . ",
            " . ($facultyHasExtName ? 'f.ext_name' : "'' AS ext_name") . ",
            {$designationSelect},
            pcs.date_updated,
            pcs.date_created
        FROM `" . synk_program_chair_signatory_table_name() . "` pcs
        INNER JOIN tbl_program p
            ON p.program_id = pcs.program_id
           AND p.college_id = pcs.college_id
        INNER JOIN tbl_faculty f
            ON f.faculty_id = pcs.faculty_id
        {$designationJoin}
        WHERE pcs.college_id = ?
          AND pcs.status = 'active'
        ORDER BY p.program_code ASC, p.program_name ASC, p.major ASC
    ");

    if (!($stmt instanceof mysqli_stmt)) {
        return [];
    }

    $stmt->bind_param('i', $collegeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $assignments = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $programId = (int)($row['program_id'] ?? 0);
            $assignments[$programId] = [
                'program_chair_signatory_id' => (int)($row['program_chair_signatory_id'] ?? 0),
                'program_id' => $programId,
                'faculty_id' => (int)($row['faculty_id'] ?? 0),
                'program_label' => synk_program_chair_signatory_program_label($row),
                'faculty_name' => synk_program_chair_signatory_name($row),
                'designation_name' => trim((string)($row['designation_name'] ?? '')),
            ];
        }
    }

    $stmt->close();

    return $assignments;
}

function synk_program_chair_signatory_program_belongs_to_college(mysqli $conn, int $collegeId, int $programId): bool
{
    if ($collegeId <= 0 || $programId <= 0) {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT program_id
        FROM tbl_program
        WHERE college_id = ?
          AND program_id = ?
          AND status = 'active'
        LIMIT 1
    ");

    if (!($stmt instanceof mysqli_stmt)) {
        return false;
    }

    $stmt->bind_param('ii', $collegeId, $programId);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = ($result instanceof mysqli_result) && $result->num_rows > 0;
    $stmt->close();

    return $exists;
}

function synk_program_chair_signatory_faculty_is_assigned(
    mysqli $conn,
    int $collegeId,
    int $facultyId,
    int $ayId = 0,
    int $semester = 0
): bool {
    if ($collegeId <= 0 || $facultyId <= 0) {
        return false;
    }

    $types = 'ii';
    $params = [$collegeId, $facultyId];
    $termWhere = synk_program_chair_signatory_college_faculty_term_where($conn, $ayId, $semester, $types, $params);

    $stmt = $conn->prepare("
        SELECT cf.college_faculty_id
        FROM tbl_college_faculty cf
        INNER JOIN tbl_faculty f
            ON f.faculty_id = cf.faculty_id
        WHERE cf.college_id = ?
          AND cf.faculty_id = ?
          AND cf.status = 'active'
          AND f.status = 'active'
          {$termWhere}
        LIMIT 1
    ");

    if (!($stmt instanceof mysqli_stmt)) {
        return false;
    }

    synk_bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = ($result instanceof mysqli_result) && $result->num_rows > 0;
    $stmt->close();

    return $exists;
}

function synk_program_chair_signatory_save(
    mysqli $conn,
    int $collegeId,
    int $programId,
    int $facultyId,
    int $userId = 0
): bool {
    if ($collegeId <= 0 || $programId <= 0 || $facultyId <= 0) {
        return false;
    }

    synk_program_chair_signatory_ensure_table($conn);
    $tableName = synk_program_chair_signatory_table_name();

    $stmt = $conn->prepare("
        INSERT INTO `{$tableName}` (
            college_id,
            program_id,
            faculty_id,
            status,
            updated_by,
            date_updated
        ) VALUES (?, ?, ?, 'active', ?, NOW())
        ON DUPLICATE KEY UPDATE
            faculty_id = VALUES(faculty_id),
            status = 'active',
            updated_by = VALUES(updated_by),
            date_updated = NOW()
    ");

    if (!($stmt instanceof mysqli_stmt)) {
        return false;
    }

    $stmt->bind_param('iiii', $collegeId, $programId, $facultyId, $userId);
    $saved = $stmt->execute();
    $stmt->close();

    return $saved;
}

function synk_program_chair_signatory_remove(
    mysqli $conn,
    int $collegeId,
    int $programId,
    int $userId = 0
): bool {
    if ($collegeId <= 0 || $programId <= 0) {
        return false;
    }

    synk_program_chair_signatory_ensure_table($conn);
    $tableName = synk_program_chair_signatory_table_name();

    $stmt = $conn->prepare("
        UPDATE `{$tableName}`
        SET status = 'inactive',
            updated_by = ?,
            date_updated = NOW()
        WHERE college_id = ?
          AND program_id = ?
        LIMIT 1
    ");

    if (!($stmt instanceof mysqli_stmt)) {
        return false;
    }

    $stmt->bind_param('iii', $userId, $collegeId, $programId);
    $saved = $stmt->execute();
    $stmt->close();

    return $saved;
}

function synk_program_chair_signatory_fetch_for_program(
    mysqli $conn,
    int $collegeId,
    int $programId,
    int $ayId = 0,
    int $semester = 0
): ?array {
    if ($collegeId <= 0 || $programId <= 0) {
        return null;
    }

    synk_program_chair_signatory_ensure_table($conn);

    $facultyHasMiddleName = synk_table_has_column($conn, 'tbl_faculty', 'middle_name');
    $facultyHasExtName = synk_table_has_column($conn, 'tbl_faculty', 'ext_name');
    $facultyHasDesignationId = synk_table_has_column($conn, 'tbl_faculty', 'designation_id');
    $designationExists = synk_table_exists($conn, 'tbl_designation');
    $designationHasStatus = $designationExists && synk_table_has_column($conn, 'tbl_designation', 'status');

    $types = 'ii';
    $params = [$collegeId, $programId];
    $termWhere = synk_program_chair_signatory_college_faculty_term_where($conn, $ayId, $semester, $types, $params);

    $designationJoin = '';
    $designationSelect = "'' AS designation_name";
    if ($facultyHasDesignationId && $designationExists) {
        $designationJoin = "
        LEFT JOIN tbl_designation d
            ON d.designation_id = f.designation_id
           " . ($designationHasStatus ? "AND d.status = 'active'" : '');
        $designationSelect = "COALESCE(d.designation_name, '') AS designation_name";
    }

    $stmt = $conn->prepare("
        SELECT
            pcs.program_id,
            pcs.faculty_id,
            p.program_code,
            p.program_name,
            COALESCE(p.major, '') AS major,
            f.last_name,
            f.first_name,
            " . ($facultyHasMiddleName ? 'f.middle_name' : "'' AS middle_name") . ",
            " . ($facultyHasExtName ? 'f.ext_name' : "'' AS ext_name") . ",
            {$designationSelect}
        FROM `" . synk_program_chair_signatory_table_name() . "` pcs
        INNER JOIN tbl_program p
            ON p.program_id = pcs.program_id
           AND p.college_id = pcs.college_id
        INNER JOIN tbl_faculty f
            ON f.faculty_id = pcs.faculty_id
        INNER JOIN tbl_college_faculty cf
            ON cf.college_id = pcs.college_id
           AND cf.faculty_id = pcs.faculty_id
           AND cf.status = 'active'
        {$designationJoin}
        WHERE pcs.college_id = ?
          AND pcs.program_id = ?
          AND pcs.status = 'active'
          AND p.status = 'active'
          AND f.status = 'active'
          {$termWhere}
        ORDER BY pcs.date_updated DESC, pcs.program_chair_signatory_id DESC
        LIMIT 1
    ");

    if (!($stmt instanceof mysqli_stmt)) {
        return null;
    }

    synk_bind_dynamic_params($stmt, $types, $params);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = ($result instanceof mysqli_result) ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    return [
        'program_id' => (int)($row['program_id'] ?? 0),
        'program_code' => strtoupper(trim((string)($row['program_code'] ?? ''))),
        'program_label' => synk_program_chair_signatory_program_label($row),
        'faculty_id' => (int)($row['faculty_id'] ?? 0),
        'name' => synk_program_chair_signatory_name($row),
        'designation' => trim((string)($row['designation_name'] ?? '')) ?: 'Program Chairman',
    ];
}
