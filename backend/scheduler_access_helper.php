<?php

function synk_scheduler_access_table_exists(mysqli $conn): bool
{
    $result = $conn->query("SHOW TABLES LIKE 'tbl_user_college_access'");
    $exists = $result instanceof mysqli_result && $result->num_rows > 0;

    if ($result instanceof mysqli_result) {
        $result->close();
    }

    return $exists;
}

function synk_ensure_scheduler_access_table(mysqli $conn): bool
{
    if (synk_scheduler_access_table_exists($conn)) {
        return true;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS tbl_user_college_access (
            access_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT(10) UNSIGNED NOT NULL,
            college_id INT(10) UNSIGNED NOT NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (access_id),
            UNIQUE KEY uk_user_college (user_id, college_id),
            KEY idx_user_status_default (user_id, status, is_default),
            KEY idx_college_status (college_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    if (!$conn->query($sql)) {
        return false;
    }

    return synk_scheduler_access_table_exists($conn);
}

function synk_scheduler_access_option_label(array $row): string
{
    $collegeCode = trim((string)($row['college_code'] ?? ''));
    $collegeName = trim((string)($row['college_name'] ?? ''));
    $campusName = trim((string)($row['campus_name'] ?? ''));

    $label = $collegeName;
    if ($collegeCode !== '' && $collegeName !== '') {
        $label = $collegeCode . ' - ' . $collegeName;
    } elseif ($collegeCode !== '') {
        $label = $collegeCode;
    }

    if ($campusName !== '') {
        $label .= ' (' . $campusName . ')';
    }

    return $label !== '' ? $label : 'College';
}

function synk_fetch_scheduler_access_rows(mysqli $conn, int $userId): array
{
    if ($userId <= 0 || !synk_ensure_scheduler_access_table($conn)) {
        return [];
    }

    $sql = "
        SELECT
            access.college_id,
            access.is_default,
            c.college_code,
            c.college_name,
            c.campus_id,
            cp.campus_name
        FROM tbl_user_college_access access
        INNER JOIN tbl_college c
            ON c.college_id = access.college_id
        LEFT JOIN tbl_campus cp
            ON cp.campus_id = c.campus_id
        WHERE access.user_id = ?
          AND access.status = 'active'
          AND c.status = 'active'
        ORDER BY access.is_default DESC, c.college_name ASC, c.college_code ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    while ($row = $result ? $result->fetch_assoc() : null) {
        $row['college_id'] = (int)($row['college_id'] ?? 0);
        $row['campus_id'] = (int)($row['campus_id'] ?? 0);
        $row['is_default'] = (int)($row['is_default'] ?? 0) === 1;
        $row['display_label'] = synk_scheduler_access_option_label($row);
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}

function synk_build_scheduler_access_fallback(mysqli $conn, int $collegeId): array
{
    if ($collegeId <= 0) {
        return [];
    }

    $sql = "
        SELECT
            c.college_id,
            c.college_code,
            c.college_name,
            c.campus_id,
            cp.campus_name
        FROM tbl_college c
        LEFT JOIN tbl_campus cp
            ON cp.campus_id = c.campus_id
        WHERE c.college_id = ?
          AND c.status = 'active'
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $collegeId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return [];
    }

    $row['college_id'] = (int)($row['college_id'] ?? 0);
    $row['campus_id'] = (int)($row['campus_id'] ?? 0);
    $row['is_default'] = true;
    $row['display_label'] = synk_scheduler_access_option_label($row);

    return [$row];
}

function synk_fetch_scheduler_access_rows_bulk(mysqli $conn, array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), function ($value) {
        return $value > 0;
    })));

    if (empty($userIds) || !synk_ensure_scheduler_access_table($conn)) {
        return [];
    }

    $rowsByUser = [];
    $userIdList = implode(', ', $userIds);
    $sql = "
        SELECT
            access.user_id,
            access.college_id,
            access.is_default,
            c.college_code,
            c.college_name,
            c.campus_id,
            cp.campus_name
        FROM tbl_user_college_access access
        INNER JOIN tbl_college c
            ON c.college_id = access.college_id
        LEFT JOIN tbl_campus cp
            ON cp.campus_id = c.campus_id
        WHERE access.user_id IN ({$userIdList})
          AND access.status = 'active'
          AND c.status = 'active'
        ORDER BY access.user_id ASC, access.is_default DESC, c.college_name ASC, c.college_code ASC
    ";

    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $userId = (int)($row['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        $row['college_id'] = (int)($row['college_id'] ?? 0);
        $row['campus_id'] = (int)($row['campus_id'] ?? 0);
        $row['is_default'] = (int)($row['is_default'] ?? 0) === 1;
        $row['display_label'] = synk_scheduler_access_option_label($row);

        if (!isset($rowsByUser[$userId])) {
            $rowsByUser[$userId] = [];
        }

        $rowsByUser[$userId][] = $row;
    }

    $result->close();

    return $rowsByUser;
}

function synk_fetch_college_access_fallback_rows_bulk(mysqli $conn, array $collegeIds): array
{
    $collegeIds = array_values(array_unique(array_filter(array_map('intval', $collegeIds), function ($value) {
        return $value > 0;
    })));

    if (empty($collegeIds)) {
        return [];
    }

    $collegeIdList = implode(', ', $collegeIds);
    $sql = "
        SELECT
            c.college_id,
            c.college_code,
            c.college_name,
            c.campus_id,
            cp.campus_name
        FROM tbl_college c
        LEFT JOIN tbl_campus cp
            ON cp.campus_id = c.campus_id
        WHERE c.college_id IN ({$collegeIdList})
          AND c.status = 'active'
    ";

    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    $rowsByCollege = [];
    while ($row = $result->fetch_assoc()) {
        $collegeId = (int)($row['college_id'] ?? 0);
        if ($collegeId <= 0) {
            continue;
        }

        $row['college_id'] = $collegeId;
        $row['campus_id'] = (int)($row['campus_id'] ?? 0);
        $row['is_default'] = true;
        $row['display_label'] = synk_scheduler_access_option_label($row);
        $rowsByCollege[$collegeId] = $row;
    }

    $result->close();

    return $rowsByCollege;
}

function synk_resolve_scheduler_access_rows_bulk(mysqli $conn, array $userFallbackCollegeMap): array
{
    $normalizedFallbacks = [];
    foreach ($userFallbackCollegeMap as $userId => $fallbackCollegeId) {
        $safeUserId = (int)$userId;
        if ($safeUserId <= 0) {
            continue;
        }

        $normalizedFallbacks[$safeUserId] = (int)$fallbackCollegeId;
    }

    if (empty($normalizedFallbacks)) {
        return [];
    }

    $rowsByUser = synk_fetch_scheduler_access_rows_bulk($conn, array_keys($normalizedFallbacks));
    $fallbackCollegeIds = [];

    foreach ($normalizedFallbacks as $userId => $fallbackCollegeId) {
        if (!isset($rowsByUser[$userId]) || empty($rowsByUser[$userId])) {
            if ($fallbackCollegeId > 0) {
                $fallbackCollegeIds[] = $fallbackCollegeId;
            }
        }
    }

    $fallbackRowsByCollege = synk_fetch_college_access_fallback_rows_bulk($conn, $fallbackCollegeIds);

    foreach ($normalizedFallbacks as $userId => $fallbackCollegeId) {
        if (isset($rowsByUser[$userId]) && !empty($rowsByUser[$userId])) {
            continue;
        }

        if ($fallbackCollegeId > 0 && isset($fallbackRowsByCollege[$fallbackCollegeId])) {
            $rowsByUser[$userId] = [$fallbackRowsByCollege[$fallbackCollegeId]];
            continue;
        }

        $rowsByUser[$userId] = [];
    }

    return $rowsByUser;
}

function synk_resolve_scheduler_access_rows(mysqli $conn, int $userId, int $fallbackCollegeId = 0): array
{
    $rows = synk_fetch_scheduler_access_rows($conn, $userId);
    if (!empty($rows)) {
        return $rows;
    }

    return synk_build_scheduler_access_fallback($conn, $fallbackCollegeId);
}

function synk_scheduler_default_college_id(array $accessRows): int
{
    foreach ($accessRows as $row) {
        if (!empty($row['is_default'])) {
            return (int)($row['college_id'] ?? 0);
        }
    }

    return isset($accessRows[0]['college_id']) ? (int)$accessRows[0]['college_id'] : 0;
}

function synk_scheduler_access_row_for_college(array $accessRows, int $collegeId): ?array
{
    foreach ($accessRows as $row) {
        if ((int)($row['college_id'] ?? 0) === $collegeId) {
            return $row;
        }
    }

    return null;
}

function synk_scheduler_store_session_scope(array $row, array $accessRows, int $requestedCollegeId = 0): void
{
    $_SESSION['user_id'] = $row['user_id'];
    $_SESSION['username'] = $row['username'];
    $_SESSION['email'] = $row['email'];
    $_SESSION['role'] = $row['role'];

    if ((string)($row['role'] ?? '') !== 'scheduler') {
        $_SESSION['college_id'] = $row['college_id'] ?? null;
        $_SESSION['college_name'] = $row['college_name'] ?? null;
        unset(
            $_SESSION['scheduler_college_access'],
            $_SESSION['campus_id'],
            $_SESSION['campus_name'],
            $_SESSION['default_college_id']
        );
        return;
    }

    $activeRow = null;
    if ($requestedCollegeId > 0) {
        $activeRow = synk_scheduler_access_row_for_college($accessRows, $requestedCollegeId);
    }

    if (!$activeRow) {
        $defaultCollegeId = synk_scheduler_default_college_id($accessRows);
        $activeRow = synk_scheduler_access_row_for_college($accessRows, $defaultCollegeId);
    }

    if (!$activeRow && !empty($accessRows)) {
        $activeRow = $accessRows[0];
    }

    $_SESSION['scheduler_college_access'] = array_values(array_map(function ($item) {
        return [
            'college_id' => (int)($item['college_id'] ?? 0),
            'college_code' => (string)($item['college_code'] ?? ''),
            'college_name' => (string)($item['college_name'] ?? ''),
            'campus_id' => (int)($item['campus_id'] ?? 0),
            'campus_name' => (string)($item['campus_name'] ?? ''),
            'display_label' => (string)($item['display_label'] ?? ''),
            'is_default' => !empty($item['is_default'])
        ];
    }, $accessRows));

    $_SESSION['default_college_id'] = synk_scheduler_default_college_id($accessRows);

    if ($activeRow) {
        $_SESSION['college_id'] = (int)($activeRow['college_id'] ?? 0);
        $_SESSION['college_name'] = (string)($activeRow['college_name'] ?? '');
        $_SESSION['campus_id'] = (int)($activeRow['campus_id'] ?? 0);
        $_SESSION['campus_name'] = (string)($activeRow['campus_name'] ?? '');
    } else {
        $_SESSION['college_id'] = null;
        $_SESSION['college_name'] = null;
        $_SESSION['campus_id'] = 0;
        $_SESSION['campus_name'] = '';
    }
}

function synk_scheduler_account_has_access(mysqli $conn, array $row): bool
{
    $userId = (int)($row['user_id'] ?? 0);
    $fallbackCollegeId = (int)($row['college_id'] ?? 0);
    return !empty(synk_resolve_scheduler_access_rows($conn, $userId, $fallbackCollegeId));
}

function synk_scheduler_bootstrap_session_scope(mysqli $conn): void
{
    if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'scheduler') {
        return;
    }

    $userRow = [
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'username' => (string)($_SESSION['username'] ?? ''),
        'email' => (string)($_SESSION['email'] ?? ''),
        'role' => 'scheduler',
        'college_id' => (int)($_SESSION['college_id'] ?? 0),
        'college_name' => (string)($_SESSION['college_name'] ?? '')
    ];

    $accessRows = synk_resolve_scheduler_access_rows($conn, $userRow['user_id'], (int)$userRow['college_id']);
    synk_scheduler_store_session_scope($userRow, $accessRows, (int)($_SESSION['college_id'] ?? 0));
}

function synk_parse_scheduler_college_ids($input): array
{
    if (!is_array($input)) {
        if ($input === null || $input === '') {
            return [];
        }
        $input = [$input];
    }

    $ids = [];
    foreach ($input as $value) {
        $collegeId = (int)$value;
        if ($collegeId > 0) {
            $ids[$collegeId] = $collegeId;
        }
    }

    return array_values($ids);
}

function synk_scheduler_role_values($roleOrRoles): array
{
    if (!is_array($roleOrRoles)) {
        if ($roleOrRoles === null || $roleOrRoles === '') {
            $roleOrRoles = [];
        } else {
            $roleOrRoles = [$roleOrRoles];
        }
    }

    $roles = [];
    foreach ($roleOrRoles as $role) {
        $safeRole = strtolower(trim((string)$role));
        if ($safeRole === '') {
            continue;
        }

        $roles[$safeRole] = $safeRole;
    }

    return array_values($roles);
}

function synk_scheduler_roles_include_scheduler($roleOrRoles): bool
{
    return in_array('scheduler', synk_scheduler_role_values($roleOrRoles), true);
}

function synk_scheduler_normalize_access_payload($roleOrRoles, array $collegeIds, ?int $defaultCollegeId): array
{
    if (!synk_scheduler_roles_include_scheduler($roleOrRoles)) {
        return [
            'college_ids' => [],
            'default_college_id' => null
        ];
    }

    if (empty($collegeIds)) {
        return [
            'college_ids' => [],
            'default_college_id' => null
        ];
    }

    $safeDefault = $defaultCollegeId !== null ? (int)$defaultCollegeId : 0;
    if ($safeDefault <= 0 || !in_array($safeDefault, $collegeIds, true)) {
        $safeDefault = (int)$collegeIds[0];
    }

    return [
        'college_ids' => $collegeIds,
        'default_college_id' => $safeDefault
    ];
}

function synk_persist_scheduler_college_access(mysqli $conn, int $userId, $roleOrRoles, array $collegeIds, ?int $defaultCollegeId): ?string
{
    if ($userId <= 0) {
        return 'missing';
    }

    $normalized = synk_scheduler_normalize_access_payload($roleOrRoles, $collegeIds, $defaultCollegeId);
    $collegeIds = $normalized['college_ids'];
    $defaultCollegeId = $normalized['default_college_id'];
    $hasSchedulerRole = synk_scheduler_roles_include_scheduler($roleOrRoles);

    if ($hasSchedulerRole && empty($collegeIds)) {
        return 'need_college';
    }

    if (!synk_ensure_scheduler_access_table($conn)) {
        return 'schema_error';
    }

    $deleteStmt = $conn->prepare("DELETE FROM tbl_user_college_access WHERE user_id = ?");
    if (!$deleteStmt) {
        return 'save_failed';
    }

    $deleteStmt->bind_param("i", $userId);
    if (!$deleteStmt->execute()) {
        $deleteStmt->close();
        return 'save_failed';
    }
    $deleteStmt->close();

    if ($hasSchedulerRole) {
        $insertStmt = $conn->prepare("
            INSERT INTO tbl_user_college_access (user_id, college_id, is_default, status)
            VALUES (?, ?, ?, 'active')
        ");

        if (!$insertStmt) {
            return 'save_failed';
        }

        foreach ($collegeIds as $collegeId) {
            $isDefault = $collegeId === $defaultCollegeId ? 1 : 0;
            $insertStmt->bind_param("iii", $userId, $collegeId, $isDefault);
            if (!$insertStmt->execute()) {
                $insertStmt->close();
                return 'save_failed';
            }
        }

        $insertStmt->close();
    }

    if ($hasSchedulerRole && $defaultCollegeId !== null) {
        $updateStmt = $conn->prepare("UPDATE tbl_useraccount SET college_id = ? WHERE user_id = ?");
        if (!$updateStmt) {
            return 'save_failed';
        }

        $updateStmt->bind_param("ii", $defaultCollegeId, $userId);
        if (!$updateStmt->execute()) {
            $updateStmt->close();
            return 'save_failed';
        }
        $updateStmt->close();
    } else {
        $updateStmt = $conn->prepare("UPDATE tbl_useraccount SET college_id = NULL WHERE user_id = ?");
        if (!$updateStmt) {
            return 'save_failed';
        }

        $updateStmt->bind_param("i", $userId);
        if (!$updateStmt->execute()) {
            $updateStmt->close();
            return 'save_failed';
        }
        $updateStmt->close();
    }

    return null;
}
