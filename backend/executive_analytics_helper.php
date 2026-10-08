<?php

require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schedule_block_helper.php';
require_once __DIR__ . '/schema_helper.php';

function synk_exec_analytics_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function synk_exec_analytics_access_table_name(): string
{
    return 'tbl_executive_access_codes';
}

function synk_exec_analytics_session_key(): string
{
    return 'executive_analytics_auth';
}

function synk_exec_analytics_bootstrap(mysqli $conn): void
{
    synk_exec_analytics_ensure_access_table($conn);
    synk_exec_analytics_seed_default_access_codes($conn);
}

function synk_exec_analytics_ensure_access_table(mysqli $conn): bool
{
    $tableName = synk_exec_analytics_access_table_name();

    $sql = "
        CREATE TABLE IF NOT EXISTS `{$tableName}` (
            `access_code_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `role_key` VARCHAR(50) NOT NULL,
            `role_label` VARCHAR(120) NOT NULL,
            `access_code_hash` VARCHAR(255) NOT NULL,
            `display_order` INT UNSIGNED NOT NULL DEFAULT 0,
            `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            `last_login_at` DATETIME NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`access_code_id`),
            UNIQUE KEY `uniq_exec_role_key` (`role_key`),
            KEY `idx_exec_status_order` (`status`, `display_order`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";

    if (!$conn->query($sql)) {
        return false;
    }

    return synk_table_exists($conn, $tableName);
}

function synk_exec_analytics_default_access_seeds(): array
{
    // These defaults make the module usable immediately; replace them for production rollout.
    return [
        [
            'role_key' => 'vp_academics',
            'role_label' => 'Vice President for Academics',
            'access_code' => 'VPAA-2026-SYNK',
            'display_order' => 10,
        ],
        [
            'role_key' => 'president',
            'role_label' => 'President',
            'access_code' => 'PRES-2026-SYNK',
            'display_order' => 20,
        ],
    ];
}

function synk_exec_analytics_seed_default_access_codes(mysqli $conn): void
{
    $tableName = synk_exec_analytics_access_table_name();

    if (!synk_table_exists($conn, $tableName)) {
        return;
    }

    $result = $conn->query("SELECT COUNT(*) AS total_rows FROM `{$tableName}`");
    $rowCount = 0;

    if ($result instanceof mysqli_result) {
        $row = $result->fetch_assoc();
        $rowCount = (int)($row['total_rows'] ?? 0);
        $result->close();
    }

    if ($rowCount > 0) {
        return;
    }

    $stmt = $conn->prepare("
        INSERT INTO `{$tableName}` (
            role_key,
            role_label,
            access_code_hash,
            display_order,
            status
        ) VALUES (?, ?, ?, ?, 'active')
    ");

    if (!$stmt) {
        return;
    }

    foreach (synk_exec_analytics_default_access_seeds() as $seed) {
        $roleKey = (string)$seed['role_key'];
        $roleLabel = (string)$seed['role_label'];
        $accessCodeHash = password_hash((string)$seed['access_code'], PASSWORD_DEFAULT);
        $displayOrder = (int)$seed['display_order'];
        $stmt->bind_param('sssi', $roleKey, $roleLabel, $accessCodeHash, $displayOrder);
        $stmt->execute();
    }

    $stmt->close();
}

function synk_exec_analytics_access_rows(mysqli $conn): array
{
    synk_exec_analytics_bootstrap($conn);

    $tableName = synk_exec_analytics_access_table_name();
    $rows = [];
    $result = $conn->query("
        SELECT access_code_id, role_key, role_label, display_order, status, last_login_at, created_at, updated_at
        FROM `{$tableName}`
        ORDER BY display_order ASC, role_label ASC, access_code_id ASC
    ");

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $row['access_code_id'] = (int)($row['access_code_id'] ?? 0);
            $row['display_order'] = (int)($row['display_order'] ?? 0);
            $rows[] = $row;
        }
        $result->close();
    }

    return $rows;
}

function synk_exec_analytics_active_user(): ?array
{
    $session = $_SESSION[synk_exec_analytics_session_key()] ?? null;
    return is_array($session) ? $session : null;
}

function synk_exec_analytics_store_session(array $row, bool $regenerate = true): void
{
    if ($regenerate) {
        session_regenerate_id(true);
    }

    $_SESSION[synk_exec_analytics_session_key()] = [
        'access_code_id' => (int)($row['access_code_id'] ?? 0),
        'role_key' => (string)($row['role_key'] ?? ''),
        'role_label' => (string)($row['role_label'] ?? 'Executive Analytics'),
        'logged_in_at' => date('c'),
    ];
}

function synk_exec_analytics_logout(): void
{
    unset($_SESSION[synk_exec_analytics_session_key()]);
}

function synk_exec_analytics_find_access_by_code(mysqli $conn, string $accessCode): ?array
{
    synk_exec_analytics_bootstrap($conn);

    $safeCode = trim($accessCode);
    if ($safeCode === '') {
        return null;
    }

    $tableName = synk_exec_analytics_access_table_name();
    $stmt = $conn->prepare("
        SELECT access_code_id, role_key, role_label, access_code_hash, display_order, status
        FROM `{$tableName}`
        WHERE status = 'active'
        ORDER BY display_order ASC, access_code_id ASC
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $matchedRow = null;

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $hash = (string)($row['access_code_hash'] ?? '');
            if ($hash !== '' && password_verify($safeCode, $hash)) {
                $row['access_code_id'] = (int)($row['access_code_id'] ?? 0);
                $row['display_order'] = (int)($row['display_order'] ?? 0);
                $matchedRow = $row;
                break;
            }
        }
        $result->close();
    }

    $stmt->close();
    return $matchedRow;
}

function synk_exec_analytics_attempt_login(mysqli $conn, string $accessCode): array
{
    $matchedRow = synk_exec_analytics_find_access_by_code($conn, $accessCode);
    if (!$matchedRow) {
        return [
            'status' => 'invalid',
            'message' => 'The access code is not recognized.',
        ];
    }

    $tableName = synk_exec_analytics_access_table_name();
    $stmt = $conn->prepare("
        UPDATE `{$tableName}`
        SET last_login_at = NOW()
        WHERE access_code_id = ?
    ");

    if ($stmt) {
        $accessCodeId = (int)$matchedRow['access_code_id'];
        $stmt->bind_param('i', $accessCodeId);
        $stmt->execute();
        $stmt->close();
    }

    synk_exec_analytics_store_session($matchedRow, true);

    return [
        'status' => 'success',
        'message' => 'Executive analytics access granted.',
        'viewer' => [
            'access_code_id' => (int)$matchedRow['access_code_id'],
            'role_key' => (string)$matchedRow['role_key'],
            'role_label' => (string)$matchedRow['role_label'],
        ],
    ];
}

function synk_exec_analytics_require_login(mysqli $conn): array
{
    synk_exec_analytics_bootstrap($conn);

    $viewer = synk_exec_analytics_active_user();
    if (!$viewer || (int)($viewer['access_code_id'] ?? 0) <= 0) {
        header('Location: login.php');
        exit;
    }

    $tableName = synk_exec_analytics_access_table_name();
    $stmt = $conn->prepare("
        SELECT access_code_id, role_key, role_label, display_order, status, last_login_at
        FROM `{$tableName}`
        WHERE access_code_id = ?
          AND status = 'active'
        LIMIT 1
    ");

    if (!$stmt) {
        header('Location: login.php');
        exit;
    }

    $accessCodeId = (int)$viewer['access_code_id'];
    $stmt->bind_param('i', $accessCodeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $liveRow = $result instanceof mysqli_result ? $result->fetch_assoc() : null;

    if ($result instanceof mysqli_result) {
        $result->close();
    }
    $stmt->close();

    if (!is_array($liveRow)) {
        synk_exec_analytics_logout();
        header('Location: login.php');
        exit;
    }

    $liveRow['access_code_id'] = (int)($liveRow['access_code_id'] ?? 0);
    $liveRow['display_order'] = (int)($liveRow['display_order'] ?? 0);
    synk_exec_analytics_store_session($liveRow, false);

    return synk_exec_analytics_active_user() ?? [];
}

function synk_exec_analytics_fetch_active_campuses(mysqli $conn): array
{
    $rows = [];
    $result = $conn->query("
        SELECT campus_id, campus_code, campus_name
        FROM tbl_campus
        WHERE status = 'active'
        ORDER BY campus_name ASC, campus_code ASC, campus_id ASC
    ");

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $row['campus_id'] = (int)($row['campus_id'] ?? 0);
            $rows[] = $row;
        }
        $result->close();
    }

    return $rows;
}

function synk_exec_analytics_scope_condition(int $campusId, string $qualifiedCampusColumn): string
{
    return $campusId > 0 ? " AND {$qualifiedCampusColumn} = {$campusId}" : '';
}

function synk_exec_analytics_query_rows(mysqli $conn, string $sql): array
{
    $rows = [];
    $result = $conn->query($sql);

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->close();
    }

    return $rows;
}

function synk_exec_analytics_fetch_campus_rows(mysqli $conn, int $ayId, int $semester): array
{
    $campuses = synk_exec_analytics_fetch_active_campuses($conn);
    $rowsByCampus = [];

    foreach ($campuses as $campus) {
        $campusId = (int)($campus['campus_id'] ?? 0);
        $rowsByCampus[$campusId] = [
            'campus_id' => $campusId,
            'campus_code' => (string)($campus['campus_code'] ?? ''),
            'campus_name' => (string)($campus['campus_name'] ?? ''),
            'colleges' => 0,
            'programs' => 0,
            'sections' => 0,
            'offerings' => 0,
            'scheduled_offerings' => 0,
            'schedules' => 0,
            'faculty' => 0,
            'rooms' => 0,
            'projected_enrollees' => 0,
            'students' => 0,
            'workflows' => 0,
            'latest_activity_at' => '',
            'schedule_coverage' => 0.0,
            'activity_score' => 0,
        ];
    }

    foreach (synk_exec_analytics_query_rows($conn, "
        SELECT campus_id, COUNT(*) AS metric_total
        FROM tbl_college
        WHERE status = 'active'
        GROUP BY campus_id
    ") as $row) {
        $campusId = (int)($row['campus_id'] ?? 0);
        if (isset($rowsByCampus[$campusId])) {
            $rowsByCampus[$campusId]['colleges'] = (int)($row['metric_total'] ?? 0);
        }
    }

    foreach (synk_exec_analytics_query_rows($conn, "
        SELECT col.campus_id, COUNT(*) AS metric_total
        FROM tbl_program p
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        WHERE p.status = 'active'
          AND col.status = 'active'
        GROUP BY col.campus_id
    ") as $row) {
        $campusId = (int)($row['campus_id'] ?? 0);
        if (isset($rowsByCampus[$campusId])) {
            $rowsByCampus[$campusId]['programs'] = (int)($row['metric_total'] ?? 0);
        }
    }

    foreach (synk_exec_analytics_query_rows($conn, "
        SELECT col.campus_id, COUNT(DISTINCT s.section_id) AS metric_total
        FROM tbl_sections s
        INNER JOIN tbl_program p
            ON p.program_id = s.program_id
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        WHERE s.ay_id = {$ayId}
          AND s.semester = {$semester}
          AND s.status = 'active'
          AND p.status = 'active'
          AND col.status = 'active'
        GROUP BY col.campus_id
    ") as $row) {
        $campusId = (int)($row['campus_id'] ?? 0);
        if (isset($rowsByCampus[$campusId])) {
            $rowsByCampus[$campusId]['sections'] = (int)($row['metric_total'] ?? 0);
        }
    }

    foreach (synk_exec_analytics_query_rows($conn, "
        SELECT col.campus_id, COUNT(DISTINCT po.offering_id) AS metric_total
        FROM tbl_prospectus_offering po
        INNER JOIN tbl_program p
            ON p.program_id = po.program_id
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        WHERE po.ay_id = {$ayId}
          AND po.semester = {$semester}
          AND po.status IN ('pending', 'active', 'locked')
          AND p.status = 'active'
          AND col.status = 'active'
        GROUP BY col.campus_id
    ") as $row) {
        $campusId = (int)($row['campus_id'] ?? 0);
        if (isset($rowsByCampus[$campusId])) {
            $rowsByCampus[$campusId]['offerings'] = (int)($row['metric_total'] ?? 0);
        }
    }

    foreach (synk_exec_analytics_query_rows($conn, "
        SELECT
            col.campus_id,
            COUNT(DISTINCT po.offering_id) AS scheduled_offerings,
            COUNT(DISTINCT cs.schedule_id) AS schedules
        FROM tbl_prospectus_offering po
        INNER JOIN tbl_program p
            ON p.program_id = po.program_id
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        INNER JOIN tbl_class_schedule cs
            ON cs.offering_id = po.offering_id
        WHERE po.ay_id = {$ayId}
          AND po.semester = {$semester}
          AND po.status IN ('pending', 'active', 'locked')
          AND p.status = 'active'
          AND col.status = 'active'
        GROUP BY col.campus_id
    ") as $row) {
        $campusId = (int)($row['campus_id'] ?? 0);
        if (isset($rowsByCampus[$campusId])) {
            $rowsByCampus[$campusId]['scheduled_offerings'] = (int)($row['scheduled_offerings'] ?? 0);
            $rowsByCampus[$campusId]['schedules'] = (int)($row['schedules'] ?? 0);
        }
    }

    foreach (synk_exec_analytics_query_rows($conn, "
        SELECT col.campus_id, COUNT(DISTINCT fws.faculty_id) AS metric_total
        FROM tbl_faculty_workload_sched fws
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fws.schedule_id
        INNER JOIN tbl_prospectus_offering po
            ON po.offering_id = cs.offering_id
        INNER JOIN tbl_program p
            ON p.program_id = po.program_id
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        WHERE fws.ay_id = {$ayId}
          AND fws.semester = {$semester}
          AND p.status = 'active'
          AND col.status = 'active'
        GROUP BY col.campus_id
    ") as $row) {
        $campusId = (int)($row['campus_id'] ?? 0);
        if (isset($rowsByCampus[$campusId])) {
            $rowsByCampus[$campusId]['faculty'] = (int)($row['metric_total'] ?? 0);
        }
    }

    foreach (synk_exec_analytics_query_rows($conn, "
        SELECT col.campus_id, COUNT(DISTINCT r.room_id) AS metric_total
        FROM tbl_rooms r
        INNER JOIN tbl_college col
            ON col.college_id = r.college_id
        WHERE r.status = 'active'
          AND col.status = 'active'
          AND (r.ay_id IS NULL OR r.ay_id = {$ayId})
          AND (r.semester IS NULL OR r.semester = {$semester})
        GROUP BY col.campus_id
    ") as $row) {
        $campusId = (int)($row['campus_id'] ?? 0);
        if (isset($rowsByCampus[$campusId])) {
            $rowsByCampus[$campusId]['rooms'] = (int)($row['metric_total'] ?? 0);
        }
    }

    if (synk_table_exists($conn, 'tbl_offering_enrollee_counts')) {
        foreach (synk_exec_analytics_query_rows($conn, "
            SELECT col.campus_id, COALESCE(SUM(oec.total_enrollees), 0) AS metric_total
            FROM tbl_offering_enrollee_counts oec
            INNER JOIN tbl_prospectus_offering po
                ON po.offering_id = oec.offering_id
            INNER JOIN tbl_program p
                ON p.program_id = po.program_id
            INNER JOIN tbl_college col
                ON col.college_id = p.college_id
            WHERE po.ay_id = {$ayId}
              AND po.semester = {$semester}
              AND po.status IN ('pending', 'active', 'locked')
              AND p.status = 'active'
              AND col.status = 'active'
            GROUP BY col.campus_id
        ") as $row) {
            $campusId = (int)($row['campus_id'] ?? 0);
            if (isset($rowsByCampus[$campusId])) {
                $rowsByCampus[$campusId]['projected_enrollees'] = (int)($row['metric_total'] ?? 0);
            }
        }
    }

    if (synk_table_exists($conn, 'tbl_student_management')) {
        foreach (synk_exec_analytics_query_rows($conn, "
            SELECT col.campus_id, COUNT(DISTINCT sm.student_id) AS metric_total
            FROM tbl_student_management sm
            INNER JOIN tbl_program p
                ON p.program_id = sm.program_id
            INNER JOIN tbl_college col
                ON col.college_id = p.college_id
            WHERE sm.ay_id = {$ayId}
              AND sm.semester = {$semester}
              AND p.status = 'active'
              AND col.status = 'active'
            GROUP BY col.campus_id
        ") as $row) {
            $campusId = (int)($row['campus_id'] ?? 0);
            if (isset($rowsByCampus[$campusId])) {
                $rowsByCampus[$campusId]['students'] = (int)($row['metric_total'] ?? 0);
            }
        }
    }

    if (synk_table_exists($conn, 'tbl_enrollment_headers')) {
        foreach (synk_exec_analytics_query_rows($conn, "
            SELECT campus_id, COUNT(DISTINCT enrollment_id) AS workflows, MAX(updated_at) AS latest_activity_at
            FROM tbl_enrollment_headers
            WHERE ay_id = {$ayId}
              AND semester = {$semester}
            GROUP BY campus_id
        ") as $row) {
            $campusId = (int)($row['campus_id'] ?? 0);
            if (isset($rowsByCampus[$campusId])) {
                $rowsByCampus[$campusId]['workflows'] = (int)($row['workflows'] ?? 0);
                $rowsByCampus[$campusId]['latest_activity_at'] = (string)($row['latest_activity_at'] ?? '');
            }
        }
    }

    foreach ($rowsByCampus as &$row) {
        $offerings = (int)($row['offerings'] ?? 0);
        $scheduledOfferings = (int)($row['scheduled_offerings'] ?? 0);
        $row['schedule_coverage'] = $offerings > 0
            ? round(($scheduledOfferings / $offerings) * 100, 1)
            : 0.0;
        $row['activity_score'] =
            ((int)$row['sections'] * 2) +
            ((int)$row['offerings'] * 3) +
            ((int)$row['schedules'] * 4) +
            ((int)$row['faculty'] * 4) +
            ((int)$row['workflows'] * 6) +
            (int)round(((int)$row['projected_enrollees']) / 20) +
            ((int)$row['students'] * 2);
    }
    unset($row);

    usort($rowsByCampus, static function (array $left, array $right): int {
        $scoreCompare = ((int)($right['activity_score'] ?? 0)) <=> ((int)($left['activity_score'] ?? 0));
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }

        return strcmp((string)($left['campus_name'] ?? ''), (string)($right['campus_name'] ?? ''));
    });

    return array_values($rowsByCampus);
}

function synk_exec_analytics_fetch_college_rows(mysqli $conn, int $ayId, int $semester, int $campusId = 0): array
{
    $scopeSql = synk_exec_analytics_scope_condition($campusId, 'col.campus_id');
    $rowsByCollege = [];

    foreach (synk_exec_analytics_query_rows($conn, "
        SELECT
            col.college_id,
            col.college_code,
            col.college_name,
            col.campus_id,
            COALESCE(cam.campus_code, '') AS campus_code,
            COALESCE(cam.campus_name, '') AS campus_name
        FROM tbl_college col
        LEFT JOIN tbl_campus cam
            ON cam.campus_id = col.campus_id
        WHERE col.status = 'active'
        {$scopeSql}
        ORDER BY cam.campus_name ASC, col.college_name ASC
    ") as $row) {
        $collegeId = (int)($row['college_id'] ?? 0);
        $rowsByCollege[$collegeId] = [
            'college_id' => $collegeId,
            'college_code' => (string)($row['college_code'] ?? ''),
            'college_name' => (string)($row['college_name'] ?? ''),
            'campus_id' => (int)($row['campus_id'] ?? 0),
            'campus_code' => (string)($row['campus_code'] ?? ''),
            'campus_name' => (string)($row['campus_name'] ?? ''),
            'programs' => 0,
            'sections' => 0,
            'offerings' => 0,
            'scheduled_offerings' => 0,
            'schedules' => 0,
            'faculty' => 0,
            'rooms' => 0,
            'projected_enrollees' => 0,
            'students' => 0,
            'workflows' => 0,
            'schedule_coverage' => 0.0,
            'activity_score' => 0,
        ];
    }

    foreach (synk_exec_analytics_query_rows($conn, "
        SELECT p.college_id, COUNT(*) AS metric_total
        FROM tbl_program p
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        WHERE p.status = 'active'
          AND col.status = 'active'
          {$scopeSql}
        GROUP BY p.college_id
    ") as $row) {
        $collegeId = (int)($row['college_id'] ?? 0);
        if (isset($rowsByCollege[$collegeId])) {
            $rowsByCollege[$collegeId]['programs'] = (int)($row['metric_total'] ?? 0);
        }
    }

    foreach (synk_exec_analytics_query_rows($conn, "
        SELECT p.college_id, COUNT(DISTINCT s.section_id) AS metric_total
        FROM tbl_sections s
        INNER JOIN tbl_program p
            ON p.program_id = s.program_id
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        WHERE s.ay_id = {$ayId}
          AND s.semester = {$semester}
          AND s.status = 'active'
          AND p.status = 'active'
          AND col.status = 'active'
          {$scopeSql}
        GROUP BY p.college_id
    ") as $row) {
        $collegeId = (int)($row['college_id'] ?? 0);
        if (isset($rowsByCollege[$collegeId])) {
            $rowsByCollege[$collegeId]['sections'] = (int)($row['metric_total'] ?? 0);
        }
    }

    foreach (synk_exec_analytics_query_rows($conn, "
        SELECT p.college_id, COUNT(DISTINCT po.offering_id) AS metric_total
        FROM tbl_prospectus_offering po
        INNER JOIN tbl_program p
            ON p.program_id = po.program_id
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        WHERE po.ay_id = {$ayId}
          AND po.semester = {$semester}
          AND po.status IN ('pending', 'active', 'locked')
          AND p.status = 'active'
          AND col.status = 'active'
          {$scopeSql}
        GROUP BY p.college_id
    ") as $row) {
        $collegeId = (int)($row['college_id'] ?? 0);
        if (isset($rowsByCollege[$collegeId])) {
            $rowsByCollege[$collegeId]['offerings'] = (int)($row['metric_total'] ?? 0);
        }
    }

    foreach (synk_exec_analytics_query_rows($conn, "
        SELECT
            p.college_id,
            COUNT(DISTINCT po.offering_id) AS scheduled_offerings,
            COUNT(DISTINCT cs.schedule_id) AS schedules
        FROM tbl_prospectus_offering po
        INNER JOIN tbl_program p
            ON p.program_id = po.program_id
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        INNER JOIN tbl_class_schedule cs
            ON cs.offering_id = po.offering_id
        WHERE po.ay_id = {$ayId}
          AND po.semester = {$semester}
          AND po.status IN ('pending', 'active', 'locked')
          AND p.status = 'active'
          AND col.status = 'active'
          {$scopeSql}
        GROUP BY p.college_id
    ") as $row) {
        $collegeId = (int)($row['college_id'] ?? 0);
        if (isset($rowsByCollege[$collegeId])) {
            $rowsByCollege[$collegeId]['scheduled_offerings'] = (int)($row['scheduled_offerings'] ?? 0);
            $rowsByCollege[$collegeId]['schedules'] = (int)($row['schedules'] ?? 0);
        }
    }

    foreach (synk_exec_analytics_query_rows($conn, "
        SELECT p.college_id, COUNT(DISTINCT fws.faculty_id) AS metric_total
        FROM tbl_faculty_workload_sched fws
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fws.schedule_id
        INNER JOIN tbl_prospectus_offering po
            ON po.offering_id = cs.offering_id
        INNER JOIN tbl_program p
            ON p.program_id = po.program_id
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        WHERE fws.ay_id = {$ayId}
          AND fws.semester = {$semester}
          AND p.status = 'active'
          AND col.status = 'active'
          {$scopeSql}
        GROUP BY p.college_id
    ") as $row) {
        $collegeId = (int)($row['college_id'] ?? 0);
        if (isset($rowsByCollege[$collegeId])) {
            $rowsByCollege[$collegeId]['faculty'] = (int)($row['metric_total'] ?? 0);
        }
    }

    foreach (synk_exec_analytics_query_rows($conn, "
        SELECT r.college_id, COUNT(DISTINCT r.room_id) AS metric_total
        FROM tbl_rooms r
        INNER JOIN tbl_college col
            ON col.college_id = r.college_id
        WHERE r.status = 'active'
          AND col.status = 'active'
          AND (r.ay_id IS NULL OR r.ay_id = {$ayId})
          AND (r.semester IS NULL OR r.semester = {$semester})
          {$scopeSql}
        GROUP BY r.college_id
    ") as $row) {
        $collegeId = (int)($row['college_id'] ?? 0);
        if (isset($rowsByCollege[$collegeId])) {
            $rowsByCollege[$collegeId]['rooms'] = (int)($row['metric_total'] ?? 0);
        }
    }

    if (synk_table_exists($conn, 'tbl_offering_enrollee_counts')) {
        foreach (synk_exec_analytics_query_rows($conn, "
            SELECT p.college_id, COALESCE(SUM(oec.total_enrollees), 0) AS metric_total
            FROM tbl_offering_enrollee_counts oec
            INNER JOIN tbl_prospectus_offering po
                ON po.offering_id = oec.offering_id
            INNER JOIN tbl_program p
                ON p.program_id = po.program_id
            INNER JOIN tbl_college col
                ON col.college_id = p.college_id
            WHERE po.ay_id = {$ayId}
              AND po.semester = {$semester}
              AND po.status IN ('pending', 'active', 'locked')
              AND p.status = 'active'
              AND col.status = 'active'
              {$scopeSql}
            GROUP BY p.college_id
        ") as $row) {
            $collegeId = (int)($row['college_id'] ?? 0);
            if (isset($rowsByCollege[$collegeId])) {
                $rowsByCollege[$collegeId]['projected_enrollees'] = (int)($row['metric_total'] ?? 0);
            }
        }
    }

    if (synk_table_exists($conn, 'tbl_student_management')) {
        foreach (synk_exec_analytics_query_rows($conn, "
            SELECT p.college_id, COUNT(DISTINCT sm.student_id) AS metric_total
            FROM tbl_student_management sm
            INNER JOIN tbl_program p
                ON p.program_id = sm.program_id
            INNER JOIN tbl_college col
                ON col.college_id = p.college_id
            WHERE sm.ay_id = {$ayId}
              AND sm.semester = {$semester}
              AND p.status = 'active'
              AND col.status = 'active'
              {$scopeSql}
            GROUP BY p.college_id
        ") as $row) {
            $collegeId = (int)($row['college_id'] ?? 0);
            if (isset($rowsByCollege[$collegeId])) {
                $rowsByCollege[$collegeId]['students'] = (int)($row['metric_total'] ?? 0);
            }
        }
    }

    if (synk_table_exists($conn, 'tbl_enrollment_headers')) {
        foreach (synk_exec_analytics_query_rows($conn, "
            SELECT college_id, COUNT(DISTINCT enrollment_id) AS metric_total
            FROM tbl_enrollment_headers
            WHERE ay_id = {$ayId}
              AND semester = {$semester}
              " . ($campusId > 0 ? "AND campus_id = {$campusId}" : '') . "
            GROUP BY college_id
        ") as $row) {
            $collegeId = (int)($row['college_id'] ?? 0);
            if (isset($rowsByCollege[$collegeId])) {
                $rowsByCollege[$collegeId]['workflows'] = (int)($row['metric_total'] ?? 0);
            }
        }
    }

    foreach ($rowsByCollege as &$row) {
        $offerings = (int)($row['offerings'] ?? 0);
        $scheduledOfferings = (int)($row['scheduled_offerings'] ?? 0);
        $row['schedule_coverage'] = $offerings > 0
            ? round(($scheduledOfferings / $offerings) * 100, 1)
            : 0.0;
        $row['activity_score'] =
            ((int)$row['programs']) +
            ((int)$row['sections'] * 2) +
            ((int)$row['offerings'] * 3) +
            ((int)$row['schedules'] * 4) +
            ((int)$row['faculty'] * 4) +
            ((int)$row['workflows'] * 6) +
            (int)round(((int)$row['projected_enrollees']) / 20) +
            ((int)$row['students'] * 2);
    }
    unset($row);

    usort($rowsByCollege, static function (array $left, array $right): int {
        $scoreCompare = ((int)($right['activity_score'] ?? 0)) <=> ((int)($left['activity_score'] ?? 0));
        if ($scoreCompare !== 0) {
            return $scoreCompare;
        }

        $campusCompare = strcmp((string)($left['campus_name'] ?? ''), (string)($right['campus_name'] ?? ''));
        if ($campusCompare !== 0) {
            return $campusCompare;
        }

        return strcmp((string)($left['college_name'] ?? ''), (string)($right['college_name'] ?? ''));
    });

    return array_values($rowsByCollege);
}

function synk_exec_analytics_fetch_workflow_status_rows(mysqli $conn, int $ayId, int $semester, int $campusId = 0): array
{
    if (!synk_table_exists($conn, 'tbl_enrollment_headers')) {
        return [];
    }

    $scopeSql = synk_exec_analytics_scope_condition($campusId, 'campus_id');
    $rows = synk_exec_analytics_query_rows($conn, "
        SELECT workflow_status, COUNT(*) AS total_rows
        FROM tbl_enrollment_headers
        WHERE ay_id = {$ayId}
          AND semester = {$semester}
          {$scopeSql}
        GROUP BY workflow_status
        ORDER BY total_rows DESC, workflow_status ASC
    ");

    foreach ($rows as &$row) {
        $row['workflow_status'] = (string)($row['workflow_status'] ?? 'unknown');
        $row['total_rows'] = (int)($row['total_rows'] ?? 0);
    }
    unset($row);

    return $rows;
}

function synk_exec_analytics_fetch_latest_enrollment_rows(mysqli $conn, int $ayId, int $semester, int $campusId = 0, int $limit = 8): array
{
    if (!synk_table_exists($conn, 'tbl_enrollment_headers')) {
        return [];
    }

    $safeLimit = max(1, min(25, $limit));
    $scopeSql = synk_exec_analytics_scope_condition($campusId, 'eh.campus_id');

    $rows = synk_exec_analytics_query_rows($conn, "
        SELECT
            eh.enrollment_reference,
            eh.workflow_status,
            eh.created_at,
            eh.updated_at,
            COALESCE(cam.campus_name, '') AS campus_name,
            COALESCE(cam.campus_code, '') AS campus_code,
            COALESCE(col.college_name, '') AS college_name,
            COALESCE(p.program_name, '') AS program_name,
            COALESCE(p.program_code, '') AS program_code,
            TRIM(CONCAT_WS(', ', NULLIF(TRIM(eh.last_name), ''), NULLIF(TRIM(CONCAT_WS(' ', eh.first_name, eh.middle_name)), ''))) AS student_name
        FROM tbl_enrollment_headers eh
        LEFT JOIN tbl_campus cam
            ON cam.campus_id = eh.campus_id
        LEFT JOIN tbl_college col
            ON col.college_id = eh.college_id
        LEFT JOIN tbl_program p
            ON p.program_id = eh.program_id
        WHERE eh.ay_id = {$ayId}
          AND eh.semester = {$semester}
          {$scopeSql}
        ORDER BY COALESCE(eh.updated_at, eh.created_at) DESC, eh.enrollment_id DESC
        LIMIT {$safeLimit}
    ");

    return array_map(static function (array $row): array {
        $studentName = trim((string)($row['student_name'] ?? ''));
        if ($studentName === '') {
            $studentName = 'Enrollment draft';
        }

        return [
            'enrollment_reference' => (string)($row['enrollment_reference'] ?? ''),
            'workflow_status' => (string)($row['workflow_status'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'updated_at' => (string)($row['updated_at'] ?? ''),
            'campus_name' => (string)($row['campus_name'] ?? ''),
            'campus_code' => (string)($row['campus_code'] ?? ''),
            'college_name' => (string)($row['college_name'] ?? ''),
            'program_name' => (string)($row['program_name'] ?? ''),
            'program_code' => (string)($row['program_code'] ?? ''),
            'student_name' => $studentName,
        ];
    }, $rows);
}

function synk_exec_analytics_fetch_source_table_rows(mysqli $conn): array
{
    synk_exec_analytics_bootstrap($conn);

    $catalog = [
        [
            'table_name' => synk_exec_analytics_access_table_name(),
            'label' => 'Executive Access Codes',
            'description' => 'Separate code-only entry points for the Vice President for Academics and the President.',
            'is_new' => true,
        ],
        [
            'table_name' => 'tbl_campus',
            'label' => 'Campus Directory',
            'description' => 'Active campus records that drive the sidebar and institutional grouping.',
            'is_new' => false,
        ],
        [
            'table_name' => 'tbl_college',
            'label' => 'College Structure',
            'description' => 'College ownership under each campus.',
            'is_new' => false,
        ],
        [
            'table_name' => 'tbl_program',
            'label' => 'Program Catalog',
            'description' => 'Academic programs used for course, section, and student grouping.',
            'is_new' => false,
        ],
        [
            'table_name' => 'tbl_sections',
            'label' => 'Section Inventory',
            'description' => 'Active term sections by program and year level.',
            'is_new' => false,
        ],
        [
            'table_name' => 'tbl_prospectus_offering',
            'label' => 'Offering Engine',
            'description' => 'Generated offerings that power schedule and enrollee analytics.',
            'is_new' => false,
        ],
        [
            'table_name' => 'tbl_class_schedule',
            'label' => 'Schedule Matrix',
            'description' => 'Live lecture and laboratory schedule assignments.',
            'is_new' => false,
        ],
        [
            'table_name' => 'tbl_faculty_workload_sched',
            'label' => 'Faculty Load Footprint',
            'description' => 'Assigned faculty load records for the active term.',
            'is_new' => false,
        ],
        [
            'table_name' => 'tbl_offering_enrollee_counts',
            'label' => 'Projected Enrollees',
            'description' => 'Offering-level enrollee counts used in demand projections.',
            'is_new' => false,
        ],
        [
            'table_name' => 'tbl_enrollment_headers',
            'label' => 'Enrollment Workflow',
            'description' => 'Draft-to-approval workflow records for latest executive monitoring.',
            'is_new' => false,
        ],
        [
            'table_name' => 'tbl_student_management',
            'label' => 'Student Records Snapshot',
            'description' => 'Imported student management records available for analytics.',
            'is_new' => false,
        ],
    ];

    foreach ($catalog as &$table) {
        $tableName = (string)($table['table_name'] ?? '');
        $table['exists'] = synk_table_exists($conn, $tableName);
        $table['row_count'] = 0;

        if ($table['exists']) {
            $result = $conn->query("SELECT COUNT(*) AS total_rows FROM `{$tableName}`");
            if ($result instanceof mysqli_result) {
                $row = $result->fetch_assoc();
                $table['row_count'] = (int)($row['total_rows'] ?? 0);
                $result->close();
            }
        }
    }
    unset($table);

    return $catalog;
}

function synk_exec_analytics_find_campus_row(array $campusRows, int $campusId): ?array
{
    foreach ($campusRows as $row) {
        if ((int)($row['campus_id'] ?? 0) === $campusId) {
            return $row;
        }
    }

    return null;
}

function synk_exec_analytics_scope_summary(array $campusRows, int $campusId = 0): array
{
    if ($campusId > 0) {
        $campusRow = synk_exec_analytics_find_campus_row($campusRows, $campusId);
        if (is_array($campusRow)) {
            $campusRow['campus_count'] = 1;
            return $campusRow;
        }
    }

    $summary = [
        'campus_id' => 0,
        'campus_code' => 'ALL',
        'campus_name' => 'All Campuses',
        'campus_count' => count($campusRows),
        'colleges' => 0,
        'programs' => 0,
        'sections' => 0,
        'offerings' => 0,
        'scheduled_offerings' => 0,
        'schedules' => 0,
        'faculty' => 0,
        'rooms' => 0,
        'projected_enrollees' => 0,
        'students' => 0,
        'workflows' => 0,
        'latest_activity_at' => '',
        'schedule_coverage' => 0.0,
        'activity_score' => 0,
    ];

    foreach ($campusRows as $row) {
        $summary['colleges'] += (int)($row['colleges'] ?? 0);
        $summary['programs'] += (int)($row['programs'] ?? 0);
        $summary['sections'] += (int)($row['sections'] ?? 0);
        $summary['offerings'] += (int)($row['offerings'] ?? 0);
        $summary['scheduled_offerings'] += (int)($row['scheduled_offerings'] ?? 0);
        $summary['schedules'] += (int)($row['schedules'] ?? 0);
        $summary['faculty'] += (int)($row['faculty'] ?? 0);
        $summary['rooms'] += (int)($row['rooms'] ?? 0);
        $summary['projected_enrollees'] += (int)($row['projected_enrollees'] ?? 0);
        $summary['students'] += (int)($row['students'] ?? 0);
        $summary['workflows'] += (int)($row['workflows'] ?? 0);
        $summary['activity_score'] += (int)($row['activity_score'] ?? 0);

        $latest = (string)($row['latest_activity_at'] ?? '');
        if ($latest !== '' && ($summary['latest_activity_at'] === '' || strtotime($latest) > strtotime($summary['latest_activity_at']))) {
            $summary['latest_activity_at'] = $latest;
        }
    }

    $summary['schedule_coverage'] = $summary['offerings'] > 0
        ? round(($summary['scheduled_offerings'] / $summary['offerings']) * 100, 1)
        : 0.0;

    return $summary;
}

function synk_exec_analytics_normalize_faculty_classification(string $value): string
{
    $normalized = strtolower(trim($value));
    $normalized = preg_replace('/[\s-]+/', '_', $normalized) ?? $normalized;

    if ($normalized === 'cos' || $normalized === 'contract_service' || $normalized === 'contract_of_services') {
        return 'contract_of_service';
    }

    if ($normalized === 'parttime') {
        return 'part_time';
    }

    return $normalized;
}

function synk_exec_analytics_faculty_load_status(float $loadValue, int $preparationCount): string
{
    $normalLoadUnits = $preparationCount >= 2 ? 18.0 : 21.0;
    $tolerance = 0.0001;

    if ($loadValue > $normalLoadUnits + $tolerance) {
        return 'overload';
    }

    if ($loadValue >= $normalLoadUnits - $tolerance) {
        return 'normal';
    }

    return 'underload';
}

function synk_exec_analytics_faculty_health_summary(mysqli $conn, int $ayId, int $semester, int $campusId = 0): array
{
    $summary = [
        'overload_count' => 0,
        'underload_count' => 0,
        'normal_count' => 0,
        'cos_count' => 0,
        'faculty_total' => 0,
        'status_ready' => false,
    ];

    if (
        $ayId <= 0
        || $semester <= 0
        || !synk_table_exists($conn, 'tbl_faculty')
        || !synk_table_exists($conn, 'tbl_college_faculty')
        || !synk_table_exists($conn, 'tbl_college')
        || !synk_table_exists($conn, 'tbl_faculty_workload_sched')
        || !synk_table_exists($conn, 'tbl_class_schedule')
        || !synk_table_exists($conn, 'tbl_prospectus_offering')
        || !synk_table_exists($conn, 'tbl_section_curriculum')
        || !synk_table_exists($conn, 'tbl_prospectus_header')
        || !synk_table_exists($conn, 'tbl_prospectus_subjects')
        || !synk_table_exists($conn, 'tbl_prospectus_year_sem')
        || !synk_table_exists($conn, 'tbl_sections')
        || !synk_table_exists($conn, 'tbl_subject_masterlist')
    ) {
        return $summary;
    }

    $facultyHasStatus = synk_table_has_column($conn, 'tbl_faculty', 'status');
    $facultyHasDesignationId = synk_table_has_column($conn, 'tbl_faculty', 'designation_id');
    $facultyHasEmploymentClassification = synk_table_has_column($conn, 'tbl_faculty', 'employment_classification');
    $designationTableExists = synk_table_exists($conn, 'tbl_designation');
    $designationHasUnits = $designationTableExists && synk_table_has_column($conn, 'tbl_designation', 'designation_units');
    $designationHasStatus = $designationTableExists && synk_table_has_column($conn, 'tbl_designation', 'status');
    $classScheduleHasGroupId = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_group_id');
    $classScheduleHasType = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_type');

    $designationJoinSql = '';
    $designationUnitsExpr = '0';

    if ($facultyHasDesignationId && $designationTableExists && $designationHasUnits) {
        $designationJoinSql = "
            LEFT JOIN tbl_designation d
                ON d.designation_id = f.designation_id
               " . ($designationHasStatus ? "AND d.status = 'active'" : '') . "
        ";
        $designationUnitsExpr = 'COALESCE(d.designation_units, 0)';
    }

    $assignmentWhere = [
        "LOWER(TRIM(COALESCE(cf.status, 'active'))) = 'active'",
        'cf.ay_id = ?',
        'cf.semester = ?',
    ];
    $assignmentTypes = 'ii';
    $assignmentParams = [$ayId, $semester];

    if ($facultyHasStatus) {
        $assignmentWhere[] = "LOWER(TRIM(COALESCE(f.status, 'active'))) = 'active'";
    }

    if ($campusId > 0) {
        $assignmentWhere[] = 'col.campus_id = ?';
        $assignmentTypes .= 'i';
        $assignmentParams[] = $campusId;
    }

    $assignmentSql = "
        SELECT DISTINCT
            f.faculty_id,
            {$designationUnitsExpr} AS designation_units,
            " . ($facultyHasEmploymentClassification ? "COALESCE(f.employment_classification, '')" : "''") . " AS employment_classification
        FROM tbl_college_faculty cf
        INNER JOIN tbl_faculty f
            ON f.faculty_id = cf.faculty_id
        INNER JOIN tbl_college col
            ON col.college_id = cf.college_id
        {$designationJoinSql}
        WHERE " . implode("\n          AND ", $assignmentWhere) . "
        ORDER BY f.faculty_id ASC
    ";

    $assignmentStmt = $conn->prepare($assignmentSql);
    if (!($assignmentStmt instanceof mysqli_stmt) || !synk_bind_dynamic_params($assignmentStmt, $assignmentTypes, $assignmentParams)) {
        if ($assignmentStmt instanceof mysqli_stmt) {
            $assignmentStmt->close();
        }
        return $summary;
    }

    $assignmentStmt->execute();
    $assignmentResult = $assignmentStmt->get_result();
    $facultyRowsById = [];

    if ($assignmentResult instanceof mysqli_result) {
        while ($row = $assignmentResult->fetch_assoc()) {
            $facultyId = (int)($row['faculty_id'] ?? 0);
            if ($facultyId <= 0) {
                continue;
            }

            $facultyRowsById[$facultyId] = [
                'faculty_id' => $facultyId,
                'designation_units' => round((float)($row['designation_units'] ?? 0), 2),
                'employment_classification' => synk_exec_analytics_normalize_faculty_classification((string)($row['employment_classification'] ?? '')),
            ];
        }
        $assignmentResult->close();
    }
    $assignmentStmt->close();

    if (empty($facultyRowsById)) {
        $summary['status_ready'] = true;
        return $summary;
    }

    $summary['faculty_total'] = count($facultyRowsById);
    foreach ($facultyRowsById as $facultyRow) {
        if ((string)($facultyRow['employment_classification'] ?? '') === 'contract_of_service') {
            $summary['cos_count']++;
        }
    }

    $facultyIdList = implode(',', array_map('intval', array_keys($facultyRowsById)));
    $liveOfferingJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $workloadSql = "
        SELECT
            fw.faculty_id,
            cs.schedule_id,
            o.offering_id,
            " . ($classScheduleHasGroupId ? 'cs.schedule_group_id AS group_id' : 'NULL AS group_id') . ",
            " . ($classScheduleHasType ? 'cs.schedule_type AS schedule_type' : "'LEC' AS schedule_type") . ",
            COALESCE(sm.sub_code, '') AS sub_code,
            ps.lec_units,
            ps.lab_units,
            ps.total_units
        FROM tbl_faculty_workload_sched fw
        INNER JOIN tbl_class_schedule cs
            ON cs.schedule_id = fw.schedule_id
        INNER JOIN tbl_prospectus_offering o
            ON o.offering_id = cs.offering_id
        {$liveOfferingJoins}
        INNER JOIN tbl_subject_masterlist sm
            ON sm.sub_id = ps.sub_id
        WHERE fw.ay_id = ?
          AND fw.semester = ?
          AND o.ay_id = ?
          AND o.semester = ?
          AND fw.faculty_id IN ({$facultyIdList})
        ORDER BY fw.faculty_id ASC, cs.schedule_id ASC
    ";

    $workloadStmt = $conn->prepare($workloadSql);
    $rowsByFacultyContext = [];
    $preparationMap = [];
    $offeringIds = [];

    if ($workloadStmt instanceof mysqli_stmt) {
        $workloadStmt->bind_param('iiii', $ayId, $semester, $ayId, $semester);
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

                $contextKey = ((int)($workloadRow['group_id'] ?? 0)) > 0
                    ? 'group:' . (int)$workloadRow['group_id']
                    : ($scheduleId > 0 ? 'schedule:' . $scheduleId : 'offering:' . $offeringId);

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
            $workloadResult->close();
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
                $contextKey = ((int)($contextRow['group_id'] ?? 0)) > 0
                    ? 'group:' . (int)$contextRow['group_id']
                    : (((int)($contextRow['schedule_id'] ?? 0)) > 0
                        ? 'schedule:' . (int)$contextRow['schedule_id']
                        : 'offering:' . (int)($contextRow['offering_id'] ?? 0));

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
            $contextResult->close();
        }
    }

    foreach ($facultyRowsById as $facultyId => $facultyRow) {
        $workloadLoad = 0.0;
        foreach ((array)($rowsByFacultyContext[$facultyId] ?? []) as $contextKey => $contextRows) {
            $metrics = synk_schedule_sum_display_metrics($contextRows, $contextTotals[$contextKey] ?? []);
            $workloadLoad += (float)($metrics['faculty_load'] ?? 0);
        }

        $totalPreparations = count($preparationMap[$facultyId] ?? []);
        $totalLoad = round($workloadLoad + (float)($facultyRow['designation_units'] ?? 0), 2);
        $statusKey = synk_exec_analytics_faculty_load_status($totalLoad, $totalPreparations);

        if ($statusKey === 'overload') {
            $summary['overload_count']++;
        } elseif ($statusKey === 'underload') {
            $summary['underload_count']++;
        } else {
            $summary['normal_count']++;
        }
    }

    $summary['status_ready'] = true;
    return $summary;
}

function synk_exec_analytics_status_palette(string $status): array
{
    $normalized = strtolower(trim($status));

    $map = [
        'draft' => ['label' => 'Draft', 'class' => 'status-draft'],
        'submitted' => ['label' => 'Submitted', 'class' => 'status-submitted'],
        'reviewed' => ['label' => 'Reviewed', 'class' => 'status-reviewed'],
        'approved' => ['label' => 'Approved', 'class' => 'status-approved'],
        'returned' => ['label' => 'Returned', 'class' => 'status-returned'],
        'posted' => ['label' => 'Posted', 'class' => 'status-posted'],
    ];

    return $map[$normalized] ?? [
        'label' => $normalized !== '' ? ucwords(str_replace('_', ' ', $normalized)) : 'Unknown',
        'class' => 'status-generic',
    ];
}

function synk_exec_analytics_number(int $value): string
{
    return number_format($value);
}

function synk_exec_analytics_percent(float $value): string
{
    return number_format($value, 1) . '%';
}
