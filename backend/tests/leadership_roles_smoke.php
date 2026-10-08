<?php
// Integration checks use a disposable account inside a rolled-back transaction.
// Run after the schema update: php backend/tests/leadership_roles_smoke.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$sessionDirectory = dirname(__DIR__, 2) . '/temp';
if (!is_dir($sessionDirectory)) {
    mkdir($sessionDirectory, 0700, true);
}
session_save_path($sessionDirectory);
set_error_handler(static function (int $severity, string $message, string $file, int $line): void {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
session_write_close();
$_POST = [];
$_GET = [];
require_once __DIR__ . '/../query_accounts.php';
require_once __DIR__ . '/../leadership_portal_helper.php';

function leadership_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach (['tbl_useraccount', 'tbl_useraccount_roles'] as $table) {
    $engine = $conn->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetch_assoc();
    leadership_check(strtolower((string)($engine['ENGINE'] ?? '')) === 'innodb', 'A transactional table is required: ' . $table);
}

leadership_check(synk_normalize_supported_roles(['ADMIN', ' vpaa ', 'VPAA', 'di', 'DI', 'unknown']) === ['admin', 'VPAA', 'DI'], 'Canonical role codes or deduplication failed.');
foreach (['admin' => 'administrator/', 'scheduler' => 'scheduler/', 'professor' => 'professor/', 'program_chair' => 'program-chair/', 'registrar' => 'registrar/', 'VPAA' => 'vpaa/', 'DI' => 'di/'] as $role => $path) {
    leadership_check(synk_role_redirect_path($role) === $path, 'Incorrect module route: ' . $role);
}

$beforeCount = (int)$conn->query('SELECT COUNT(*) AS total FROM tbl_useraccount')->fetch_assoc()['total'];
$testUserId = 0;
$conn->begin_transaction();
try {
    $baseInput = ['username' => 'Leadership Role Test', 'email' => 'role-check-' . bin2hex(random_bytes(8)) . '@' . $allowedDomain, 'status' => 'active'];
    foreach (['VPAA', 'DI'] as $role) {
        $validation = query_accounts_validate_payload($baseInput + ['roles' => [strtolower($role)], 'primary_role' => strtolower($role)], $allowedDomain);
        leadership_check(!isset($validation['error']) && $validation['payload']['roles'] === [$role] && $validation['payload']['primary_role'] === $role, 'Standalone role validation failed: ' . $role);
    }
    $badPrimary = query_accounts_validate_payload($baseInput + ['roles' => ['VPAA'], 'primary_role' => 'admin'], $allowedDomain);
    leadership_check(($badPrimary['error'] ?? '') === 'invalid_primary_role', 'Unassigned default role was accepted.');

    $validation = query_accounts_validate_payload($baseInput + ['roles' => ['admin', 'VPAA', 'DI'], 'primary_role' => 'VPAA'], $allowedDomain);
    leadership_check(!isset($validation['error']), 'Combined role validation failed.');
    $payload = $validation['payload'];
    [$sql, $types, $values] = query_accounts_build_insert($conn, $payload);
    $stmt = $conn->prepare($sql);
    synk_stmt_bind_params($stmt, $types, $values);
    leadership_check($stmt->execute(), 'Account creation failed.');
    $testUserId = (int)$conn->insert_id;
    $stmt->close();
    leadership_check(synk_persist_useraccount_roles($conn, $testUserId, $payload['roles'], $payload['primary_role']) === null, 'Role save failed.');

    $account = synk_find_useraccount_by_id($conn, $testUserId);
    leadership_check($account['role'] === 'VPAA', 'Primary role was not stored as VPAA.');
    $roleRows = synk_fetch_useraccount_role_rows($conn, $testUserId, $account['role']);
    leadership_check(synk_useraccount_primary_role($roleRows) === 'VPAA', 'Default role did not round-trip.');
    $bulkRows = synk_fetch_useraccount_role_rows_bulk($conn, [$testUserId => $account['role']]);
    leadership_check($bulkRows[$testUserId] === $roleRows, 'Account list roles differ from individual account roles.');
    $display = query_accounts_payload_from_row($account, $roleRows, []);
    leadership_check($display['primary_role'] === 'VPAA' && in_array('DI', $display['roles'], true), 'Account display payload lost a role.');

    $payload['user_id'] = $testUserId;
    $payload['primary_role'] = 'DI';
    [$sql, $types, $values] = query_accounts_build_update($conn, $payload);
    $stmt = $conn->prepare($sql);
    synk_stmt_bind_params($stmt, $types, $values);
    leadership_check($stmt->execute(), 'Account update failed.');
    $stmt->close();
    leadership_check(synk_persist_useraccount_roles($conn, $testUserId, $payload['roles'], 'di') === null, 'Default role update failed.');
    $account = synk_find_useraccount_by_id($conn, $testUserId);
    $roleRows = synk_fetch_useraccount_role_rows($conn, $testUserId, $account['role']);
    leadership_check($account['role'] === 'DI' && synk_useraccount_primary_role($roleRows) === 'DI', 'DI default did not persist.');
    $loginRoles = synk_filter_loginable_role_rows($conn, $account, $roleRows);
    leadership_check(count($loginRoles) === 3, 'New roles were filtered out at sign-in.');
    synk_store_pending_role_login($account, $loginRoles);
    leadership_check(synk_get_pending_role_login()['primary_role'] === 'DI', 'Role chooser default lost its code.');

    foreach (['VPAA', 'DI'] as $role) {
        leadership_check(synk_complete_user_login($account, $conn, strtolower($role), $loginRoles) === $role, 'Login did not select ' . $role);
        leadership_check($_SESSION['role'] === $role, 'Session role code is incorrect.');
        leadership_check(synk_leadership_portal_account($conn, $role)['user_id'] === $testUserId, 'Assigned account could not enter its module.');
    }
    leadership_check(synk_persist_useraccount_roles($conn, $testUserId, ['DI'], 'DI') === null, 'Role removal failed.');
    $roleRows = synk_fetch_useraccount_role_rows($conn, $testUserId, 'DI');
    leadership_check(array_column($roleRows, 'role') === ['DI'], 'Removed roles remained assigned.');
    $conn->query("UPDATE tbl_useraccount_roles SET status = 'inactive' WHERE user_id = " . $testUserId);
    leadership_check(!synk_useraccount_has_active_role($conn, $account, 'DI'), 'An inactive assignment still grants module access.');
    $inactiveRows = synk_fetch_useraccount_role_rows($conn, $testUserId, 'DI');
    leadership_check(synk_filter_loginable_role_rows($conn, $account, $inactiveRows) === [], 'An inactive assignment still permits sign-in via the legacy fallback.');
} finally {
    $conn->rollback();
    synk_reset_authenticated_session_context();
    synk_clear_pending_role_login();
    session_destroy();
}

leadership_check((int)$conn->query('SELECT COUNT(*) AS total FROM tbl_useraccount')->fetch_assoc()['total'] === $beforeCount, 'The test account was not rolled back.');
leadership_check($conn->query('SELECT user_id FROM tbl_useraccount WHERE user_id = ' . $testUserId)->num_rows === 0, 'A test account remains.');
echo "PASS: standalone and combined roles, creation, update, display, default role, sign-in, module access, and role removal. Test account rolled back.\n";
