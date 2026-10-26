<?php
// Fixture records and role changes are rolled back after the actual request handler exits.
if (PHP_SAPI !== 'cli' && !defined('SYNK_ACCOUNT_SWITCH_QA_HTTP')) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__, 2);
require_once $root . '/backend/db.php';
require_once $root . '/backend/account_switch_helper.php';

foreach (['tbl_useraccount', 'tbl_useraccount_roles', 'tbl_user_college_access', 'tbl_useraccount_registrar_scopes'] as $table) {
    $engine = $conn->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetch_assoc();
    if (strtolower((string)($engine['ENGINE'] ?? '')) !== 'innodb') throw new RuntimeException('Transactional table required: ' . $table);
}
$scenario = PHP_SAPI === 'cli' ? ($argv[1] ?? 'switch') : (string)($_GET['scenario'] ?? '');
$conn->begin_transaction();
register_shutdown_function(static function () use ($conn): void { $conn->rollback(); });
$colleges = $conn->query("SELECT college_id, campus_id FROM tbl_college WHERE status='active' ORDER BY college_id LIMIT 2")->fetch_all(MYSQLI_ASSOC);
if (!$colleges) throw new RuntimeException('An active college is required.');
$collegeId = (int)$colleges[0]['college_id'];
$campusId = (int)$colleges[0]['campus_id'];
$deanCollegeId = (int)($colleges[1]['college_id'] ?? $collegeId);
$tag = 'SwitchQA' . bin2hex(random_bytes(8));
$email = strtolower($tag) . '@sksu.edu.ph';
$conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_id,college_dean_college_id) VALUES ('{$tag}','{$email}','unusable-test-password','admin','active',{$collegeId},{$deanCollegeId})");
$userId = (int)$conn->insert_id;
$conn->query("INSERT INTO tbl_management_organizations (college_id,organization_code,organization_name) VALUES ($deanCollegeId,'$tag','$tag')");
$managementOrg=(int)$conn->insert_id;
$conn->query('UPDATE tbl_useraccount SET management_college_id='.$deanCollegeId.',management_organization_id='.$managementOrg.' WHERE user_id='.$userId);
$roles = synk_supported_module_roles();
foreach ($roles as $role) {
    $primary = $role === 'admin' ? 1 : 0;
    $conn->query("INSERT INTO tbl_useraccount_roles (user_id,role,is_primary,status) VALUES ({$userId},'{$role}',{$primary},'active')");
}
$conn->query("INSERT INTO tbl_user_college_access (user_id,college_id,is_default,status) VALUES ({$userId},{$collegeId},1,'active')");
$conn->query("INSERT INTO tbl_useraccount_registrar_scopes (user_id,campus_id,is_default,status) VALUES ({$userId},{$campusId},1,'active')");
session_save_path($root . '/temp');
session_start();
$originalSessionId = session_id();
$_SESSION = [
    'user_id' => $userId, 'username' => $tag, 'email' => $email, 'role' => 'admin', 'auth_method'=>'google',
    // Deliberately stale data must be cleared and refreshed from the database.
    'available_roles' => ['admin'], 'college_id' => 2147483647, 'campus_id' => 2147483647,
    'scheduler_college_access' => [['college_id' => 2147483647]],
    'registrar_campus_access' => [['campus_id' => 2147483647]],
    'csrf_token' => 'old-workspace-token', 'workspace_preference' => 'old-workspace',
    'user_avatar_url' => 'https://example.invalid/qa-avatar.png',
    'account_switch_token' => 'switch-qa-token',
];
$_POST = ['account_switch_token' => 'switch-qa-token'];
$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$handler = 'switch-account.php';

if ($scenario === 'anonymous') $_SESSION = [];
if ($scenario === 'student') { $_SESSION['user_id'] = 'student:qa'; $_SESSION['role'] = 'student'; }
if ($scenario === 'bad_token') $_POST['account_switch_token'] = 'invalid';
if ($scenario === 'missing_token') $_POST = [];
if ($scenario === 'array_token') $_POST['account_switch_token'] = ['invalid'];
if ($scenario === 'get') $_SERVER['REQUEST_METHOD'] = 'GET';
if ($scenario === 'inactive') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$userId}");
if ($scenario === 'missing') $conn->query("DELETE FROM tbl_useraccount WHERE user_id={$userId}");
if ($scenario === 'single' || $scenario === 'menu_single') $conn->query("DELETE FROM tbl_useraccount_roles WHERE user_id={$userId} AND role <> 'admin'");
if ($scenario === 'revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId} AND role='professor'");
if ($scenario === 'all_revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId}");
if ($scenario === 'scope_missing' || $scenario === 'menu_scope_missing') {
    $conn->query("DELETE FROM tbl_useraccount_roles WHERE user_id={$userId} AND role NOT IN ('admin','registrar','college_dean')");
    $conn->query("DELETE FROM tbl_useraccount_registrar_scopes WHERE user_id={$userId}");
    $conn->query("UPDATE tbl_useraccount SET college_dean_college_id=NULL WHERE user_id={$userId}");
}
if (strpos($scenario, 'menu_') === 0) {
    $handler = '';
    $role = substr($scenario, 5);
    if (in_array($role, $roles, true)) $_SESSION['role'] = $role;
    if ($role === 'student') { $_SESSION['user_id'] = 'student:qa'; $_SESSION['role'] = 'student'; }
    if ($role === 'inactive') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$userId}");
}
if ($scenario === 'chooser' || $scenario === 'normal_chooser' || $scenario === 'logout' || strpos($scenario, 'select_') === 0) {
    $account = synk_find_useraccount_by_id($conn, $userId);
    $context = synk_account_switch_context($conn);
    $_SESSION = [];
    synk_store_pending_role_login($account, $context['role_rows'], [
        'auth_method'=>'google',
        'avatar_url' => 'https://example.invalid/qa-avatar.png',
        'is_account_switch' => $scenario !== 'normal_chooser',
        'current_role' => $scenario !== 'normal_chooser' ? 'admin' : '',
    ]);
    $_POST = [];
    $handler = $scenario === 'logout' ? 'logout.php' : 'index.php';
    if (strpos($scenario, 'select_') === 0) {
        $selectedRole = substr($scenario, 7);
        $_POST = ['complete_role_login' => 1, 'selected_role' => $selectedRole,
            'role_selection_token' => synk_get_pending_role_login()['selection_token']];
        if ($selectedRole === 'bad_token') $_POST['role_selection_token'] = 'invalid';
        if ($selectedRole === 'missing_token') unset($_POST['role_selection_token']);
        if ($selectedRole === 'array_token') $_POST['role_selection_token'] = ['invalid'];
        if ($selectedRole === 'revoked') {
            $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId} AND role='professor'");
            $_POST['selected_role'] = 'professor';
        }
        if ($selectedRole === 'all_revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId}");
        if ($selectedRole === 'inactive') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$userId}");
        if ($selectedRole === 'missing') $conn->query("DELETE FROM tbl_useraccount WHERE user_id={$userId}");
    }
}
session_write_close();
ob_start();
register_shutdown_function(static function () use ($userId, $collegeId, $deanCollegeId, $campusId, $originalSessionId): void {
    $body = '';
    while (ob_get_level() > 0) $body = ob_get_clean() . $body;
    $redirect = '';
    foreach (headers_list() as $header) {
        if (stripos($header, 'Location:') === 0) $redirect = trim(substr($header, 9));
    }
    $response = json_decode($body, true);
    $result = ['response' => is_array($response) ? $response : trim($body),
        'http_status' => http_response_code() ?: 200, 'redirect' => $redirect,
        'session' => $_SESSION, 'session_rotated' => session_id() !== $originalSessionId,
        'session_active' => session_status() === PHP_SESSION_ACTIVE,
        'user_id' => $userId, 'college_id' => $collegeId, 'dean_college_id' => $deanCollegeId, 'campus_id' => $campusId];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    header_remove('Location');
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result);
});
if ($handler === '') {
    session_start();
    $navbarPaths = ['admin' => 'administrator/navbar.php', 'scheduler' => 'scheduler/navbar.php',
        'professor' => 'professor/navbar.php', 'program_chair' => 'program-chair/navbar.php',
        'college_dean' => 'college-dean/navbar.php', 'registrar' => 'registrar/navbar.php', 'DI' => 'di/navbar.php'];
    $navbarPath = $navbarPaths[$_SESSION['role'] ?? ''] ?? null;
    if ($scenario === 'menu_nested') $navbarPath = 'administrator/students/navbar.php';
    if ($scenario === 'menu_toolbar' || ($_SESSION['role'] ?? '') === 'VPAA') $navbarPath = 'backend/leadership_dashboard_view.php';
    if ($navbarPath === 'di/navbar.php' || $navbarPath === 'backend/leadership_dashboard_view.php') {
        require_once $root . '/backend/leadership_portal_helper.php';
        $moduleRole = $navbarPath === 'di/navbar.php' ? 'DI' : 'VPAA';
        $moduleIcon = 'bx-briefcase-alt-2';
        $moduleTerm = synk_fetch_current_academic_term($conn);
        $moduleAccount = synk_find_useraccount_by_id($conn, $userId);
        $moduleAccount['primary_role'] = 'admin';
        $moduleAccount['role_rows'] = synk_fetch_useraccount_role_rows($conn, $userId, 'admin');
    }
    if ($navbarPath !== null) {
        if (in_array($navbarPath, ['college-dean/navbar.php', 'program-chair/navbar.php'], true)) require_once $root . '/backend/college_dean_term_helper.php';
        chdir(dirname($root . '/' . $navbarPath));
        require $root . '/' . $navbarPath;
    } else {
        synk_render_account_switch($conn);
    }
} else {
    chdir($root);
    require $root . '/' . $handler;
}
