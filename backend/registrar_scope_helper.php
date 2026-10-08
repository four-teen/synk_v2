<?php

require_once __DIR__ . '/schema_helper.php';

function synk_registrar_scope_table_name(): string
{
    return 'tbl_useraccount_registrar_scopes';
}

function synk_registrar_scope_table_exists(mysqli $conn): bool
{
    static $cache = null;

    if (is_bool($cache)) {
        return $cache;
    }

    $result = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string(synk_registrar_scope_table_name()) . "'");
    $cache = $result instanceof mysqli_result && $result->num_rows > 0;

    if ($result instanceof mysqli_result) {
        $result->close();
    }

    return $cache;
}

function synk_registrar_scope_option_label(array $row): string
{
    $campusCode = trim((string)($row['campus_code'] ?? ''));
    $campusName = trim((string)($row['campus_name'] ?? ''));

    if ($campusCode !== '' && $campusName !== '') {
        return $campusCode . ' - ' . $campusName;
    }

    return $campusName !== '' ? $campusName : ($campusCode !== '' ? $campusCode : 'Campus');
}

function synk_registrar_role_values($roleOrRoles): array
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

function synk_registrar_roles_include_registrar($roleOrRoles): bool
{
    return in_array('registrar', synk_registrar_role_values($roleOrRoles), true);
}

function synk_fetch_registrar_scope_rows(mysqli $conn, int $userId): array
{
    if ($userId <= 0 || !synk_registrar_scope_table_exists($conn) || !synk_table_exists($conn, 'tbl_campus')) {
        return [];
    }

    $tableName = synk_registrar_scope_table_name();
    $stmt = $conn->prepare("
        SELECT
            rs.campus_id,
            rs.is_default,
            COALESCE(c.campus_code, '') AS campus_code,
            COALESCE(c.campus_name, '') AS campus_name
        FROM `{$tableName}` rs
        INNER JOIN tbl_campus c
            ON c.campus_id = rs.campus_id
        WHERE rs.user_id = ?
          AND rs.status = 'active'
        ORDER BY rs.is_default DESC, c.campus_name ASC, c.campus_code ASC, c.campus_id ASC
    ");

    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $row['campus_id'] = (int)($row['campus_id'] ?? 0);
            $row['is_default'] = !empty($row['is_default']);
            $row['display_label'] = synk_registrar_scope_option_label($row);
            $rows[] = $row;
        }

        $result->close();
    }

    $stmt->close();
    return $rows;
}

function synk_fetch_registrar_scope_rows_bulk(mysqli $conn, array $userIds): array
{
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static function (int $value): bool {
        return $value > 0;
    })));

    if (empty($userIds) || !synk_registrar_scope_table_exists($conn) || !synk_table_exists($conn, 'tbl_campus')) {
        return [];
    }

    $rowsByUser = [];
    $tableName = synk_registrar_scope_table_name();
    $userIdList = implode(', ', $userIds);
    $result = $conn->query("
        SELECT
            rs.user_id,
            rs.campus_id,
            rs.is_default,
            COALESCE(c.campus_code, '') AS campus_code,
            COALESCE(c.campus_name, '') AS campus_name
        FROM `{$tableName}` rs
        INNER JOIN tbl_campus c
            ON c.campus_id = rs.campus_id
        WHERE rs.user_id IN ({$userIdList})
          AND rs.status = 'active'
        ORDER BY rs.user_id ASC, rs.is_default DESC, c.campus_name ASC, c.campus_code ASC, c.campus_id ASC
    ");

    if (!($result instanceof mysqli_result)) {
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $userId = (int)($row['user_id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        $row['campus_id'] = (int)($row['campus_id'] ?? 0);
        $row['is_default'] = !empty($row['is_default']);
        $row['display_label'] = synk_registrar_scope_option_label($row);

        if (!isset($rowsByUser[$userId])) {
            $rowsByUser[$userId] = [];
        }

        $rowsByUser[$userId][] = $row;
    }

    $result->close();
    return $rowsByUser;
}

function synk_registrar_default_campus_id(array $scopeRows): int
{
    foreach ($scopeRows as $row) {
        if (!empty($row['is_default'])) {
            return (int)($row['campus_id'] ?? 0);
        }
    }

    return isset($scopeRows[0]['campus_id']) ? (int)$scopeRows[0]['campus_id'] : 0;
}

function synk_registrar_scope_row_for_campus(array $scopeRows, int $campusId): ?array
{
    foreach ($scopeRows as $row) {
        if ((int)($row['campus_id'] ?? 0) === $campusId) {
            return $row;
        }
    }

    return null;
}

function synk_registrar_store_session_scope(array $row, array $scopeRows, int $requestedCampusId = 0): void
{
    $_SESSION['user_id'] = $row['user_id'];
    $_SESSION['username'] = $row['username'];
    $_SESSION['email'] = $row['email'];
    $_SESSION['role'] = 'registrar';

    $activeRow = null;
    if ($requestedCampusId > 0) {
        $activeRow = synk_registrar_scope_row_for_campus($scopeRows, $requestedCampusId);
    }

    if (!$activeRow) {
        $activeRow = synk_registrar_scope_row_for_campus($scopeRows, synk_registrar_default_campus_id($scopeRows));
    }

    if (!$activeRow && !empty($scopeRows)) {
        $activeRow = $scopeRows[0];
    }

    $_SESSION['registrar_campus_access'] = array_values(array_map(static function (array $item): array {
        return [
            'campus_id' => (int)($item['campus_id'] ?? 0),
            'campus_code' => (string)($item['campus_code'] ?? ''),
            'campus_name' => (string)($item['campus_name'] ?? ''),
            'display_label' => (string)($item['display_label'] ?? ''),
            'is_default' => !empty($item['is_default']),
        ];
    }, $scopeRows));

    $_SESSION['default_campus_id'] = synk_registrar_default_campus_id($scopeRows);

    if ($activeRow) {
        $_SESSION['campus_id'] = (int)($activeRow['campus_id'] ?? 0);
        $_SESSION['campus_name'] = (string)($activeRow['campus_name'] ?? '');
    } else {
        $_SESSION['campus_id'] = 0;
        $_SESSION['campus_name'] = '';
    }

    $_SESSION['college_id'] = null;
    $_SESSION['college_name'] = null;
}

function synk_registrar_account_has_access(mysqli $conn, array $row): bool
{
    $userId = (int)($row['user_id'] ?? 0);
    return !empty(synk_fetch_registrar_scope_rows($conn, $userId));
}

function synk_persist_registrar_scope(mysqli $conn, int $userId, $roleOrRoles, ?int $campusId): ?string
{
    if ($userId <= 0) {
        return 'missing';
    }

    $hasRegistrarRole = synk_registrar_roles_include_registrar($roleOrRoles);
    $safeCampusId = $campusId !== null ? (int)$campusId : 0;

    if ($hasRegistrarRole && $safeCampusId <= 0) {
        return 'need_registrar_campus';
    }

    if (!synk_registrar_scope_table_exists($conn)) {
        return 'schema_error';
    }

    $tableName = synk_registrar_scope_table_name();
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

    if (!$hasRegistrarRole) {
        return null;
    }

    $insertStmt = $conn->prepare("
        INSERT INTO `{$tableName}` (user_id, campus_id, is_default, status)
        VALUES (?, ?, 1, 'active')
    ");

    if (!$insertStmt) {
        return 'save_failed';
    }

    $insertStmt->bind_param('ii', $userId, $safeCampusId);
    if (!$insertStmt->execute()) {
        $insertStmt->close();
        return 'save_failed';
    }

    $insertStmt->close();
    return null;
}
