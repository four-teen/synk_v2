<?php
// Run: php tests/account-switching.php [--http]
// The real switch/login/logout handlers run with isolated sessions and rolled-back fixtures.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth_useraccount.php';
$snapshot = static function () use ($conn): array {
    $state = [];
    foreach (['tbl_useraccount', 'tbl_useraccount_roles', 'tbl_user_college_access', 'tbl_useraccount_registrar_scopes'] as $table) {
        $state[$table] = hash('sha256', json_encode($conn->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }
    return $state;
};
$before = $snapshot();
$http = in_array('--http', $argv, true);
$routePath = null;
$routeName = 'account-switch-qa-' . bin2hex(random_bytes(8)) . '.php';
$token = bin2hex(random_bytes(24));
$phpVersion = PHP_VERSION;
if ($http) {
    $routePath = __DIR__ . '/../tmp/' . $routeName;
    $route = <<<'PHP'
<?php
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(QA_TOKEN, $_GET['qa'])) { http_response_code(404); exit; }
header('X-QA-PHP-Version: ' . PHP_VERSION);
define('SYNK_ACCOUNT_SWITCH_QA_HTTP', true);
require QA_FIXTURE;
PHP;
    file_put_contents($routePath, str_replace(['QA_TOKEN', 'QA_FIXTURE'], [var_export($token, true), var_export(__DIR__ . '/fixtures/account-switch-request.php', true)], $route));
}
$request = static function (string $scenario) use ($http, $routeName, $token, &$phpVersion): array {
    if ($http) {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20, 'header' => "Connection: close\r\n"]]);
        $body = file_get_contents('http://localhost/synk/tmp/' . $routeName . '?qa=' . $token . '&scenario=' . rawurlencode($scenario), false, $context);
        preg_match('/X-QA-PHP-Version: ([^\r\n]+)/i', implode("\n", $http_response_header ?? []), $version);
        $phpVersion = $version[1] ?? 'unknown';
    } else {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/account-switch-request.php', $scenario], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__ . '/..');
        $body = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || $errors !== '') throw new RuntimeException($scenario . ': ' . $errors . $body);
    }
    $result = json_decode((string)$body, true);
    if (!is_array($result) || isset($result['fixture_error'])) throw new RuntimeException($scenario . ': ' . substr((string)$body, 0, 1500));
    return $result;
};
$checks = 0;
$check = static function (bool $passed, string $message) use (&$checks): void {
    if (!$passed) throw new RuntimeException($message);
    $checks++;
};
try {
    $r = $request('switch');
    $pending = $r['session']['pending_role_login'] ?? [];
    $check($r['http_status'] === 302 || !$http, 'Switching must redirect.');
    if ($http) $check($r['redirect'] === 'index.php?auth_status=choose_role', 'Switching does not open the chooser.');
    $check(($pending['user_id'] ?? 0) === $r['user_id'] && count($pending['roles'] ?? []) === 8, 'Switching must use the same verified account and freshly loaded roles.');
    $check(!empty($pending['is_account_switch']) && $pending['current_role'] === 'admin', 'The chooser must know which account is current.');
    $check(strlen($pending['selection_token'] ?? '') === 64, 'Account selection must receive a fresh request token.');
    $check($pending['avatar_url'] === 'https://example.invalid/qa-avatar.png', 'Switching must retain the signed-in avatar.');
    $check(array_keys($r['session']) === ['pending_role_login'] && $r['session_rotated'], 'Switching must remove old workspace scope and tokens and rotate the session.');
    foreach (['bad_token', 'missing_token', 'array_token', 'get'] as $scenario) {
        $r = $request($scenario);
        $check($r['http_status'] === 403 && ($r['session']['role'] ?? '') === 'admin' && !$r['session_rotated'], 'An invalid request must leave the current session intact: ' . $scenario);
    }
    $r = $request('anonymous');
    $check($r['session'] === [] && (!$http || $r['redirect'] === 'index.php'), 'Anonymous visitors cannot switch accounts.');
    foreach (['inactive', 'missing', 'all_revoked', 'student'] as $scenario) {
        $r = $request($scenario);
        $check(!isset($r['session']['user_id'], $r['session']['pending_role_login']), 'Ineligible identities cannot enter account selection: ' . $scenario);
    }
    foreach (['single', 'scope_missing'] as $scenario) {
        $r = $request($scenario);
        $check(($r['session']['role'] ?? '') === 'admin' && !isset($r['session']['pending_role_login']), 'A single available account must retain its current session: ' . $scenario);
    }
    $r = $request('revoked');
    $check(!in_array('professor', array_column($r['session']['pending_role_login']['roles'], 'role'), true), 'A revoked role must be removed from the chooser.');
    foreach (synk_supported_module_roles() as $role) {
        $r = $request('menu_' . $role);
        $check(strpos($r['response'], 'Switch Account') !== false && strpos($r['response'], 'method="post"') !== false, 'Each staff role needs the account-switch menu: ' . $role);
    }
    foreach (['single', 'scope_missing', 'student', 'inactive'] as $scenario) {
        $r = $request('menu_' . $scenario);
        $check(strpos($r['response'], 'Switch Account') === false, 'The switch option must be hidden without multiple accessible accounts: ' . $scenario);
    }
    $r = $request('menu_nested');
    $check(strpos($r['response'], 'action="../../switch-account.php"') !== false, 'Nested administrator pages need the correct switch URL.');
    $r = $request('menu_toolbar');
    $check(strpos($r['response'], 'btn btn-outline-primary btn-sm') !== false && strpos($r['response'], '<li><form') === false, 'The VPAA toolbar needs a standalone switch button.');
    $r = $request('chooser');
    $check(strpos($r['response'], 'id="btn_choose_account"') !== false && strpos($r['response'], 'id="btn_google_login"') === false && strpos($r['response'], 'id="loginForm"') === false, 'Switching must render account selection without asking for credentials.');
    if (in_array('--save-chooser', $argv, true)) file_put_contents(__DIR__ . '/../.tmp/account-switch-chooser.html', $r['response']);
    $r = $request('normal_chooser');
    $check(strpos($r['response'], 'Centralized Academic Management Platform') !== false && empty($r['session']['pending_role_login']['is_account_switch']), 'The existing login chooser must retain its normal behavior.');
    foreach (synk_supported_module_roles() as $role) {
        $r = $request('select_' . $role);
        $check($r['response']['status'] === 'success' && $r['response']['redirect'] === synk_role_redirect_path($role) && ($r['session']['role'] ?? '') === $role && $r['session']['user_id'] === $r['user_id'], 'The assigned account must open without reauthentication: ' . $role);
        $check(!isset($r['session']['pending_role_login']) && $r['session_rotated'] && $r['session']['user_avatar_url'] === 'https://example.invalid/qa-avatar.png', 'Completing a switch must consume selection state, rotate the session, and preserve the avatar: ' . $role);
        if ($role === 'college_dean') $check((int)$r['session']['college_id'] === $r['dean_college_id'] && !isset($r['session']['scheduler_college_access']), 'College Dean must receive its own college scope.');
        if ($role === 'scheduler') $check((int)$r['session']['college_id'] === $r['college_id'] && count($r['session']['scheduler_college_access']) === 1, 'Scheduler must receive freshly resolved college access.');
        if ($role === 'registrar') $check((int)$r['session']['campus_id'] === $r['campus_id'] && count($r['session']['registrar_campus_access']) === 1, 'Registrar must receive freshly resolved campus access.');
    }
    foreach (['revoked' => 'invalid_primary_role', 'unassigned' => 'invalid_primary_role', 'all_revoked' => 'unsupported_role', 'inactive' => 'inactive', 'missing' => 'invalid',
        'bad_token' => 'role_selection_invalid', 'missing_token' => 'role_selection_invalid', 'array_token' => 'role_selection_invalid'] as $scenario => $status) {
        $r = $request('select_' . $scenario);
        $check($r['response']['status'] === $status && !isset($r['session']['user_id']), 'The server must recheck access when an account is selected: ' . $scenario);
    }
    $r = $request('logout');
    $check($r['session'] === [] && !$r['session_active'] && (!$http || $r['redirect'] === 'index.php'), 'Log Out must discard the remembered identity and account selection.');
    $check($snapshot() === $before, 'Account-switch checks must leave real accounts and assignments unchanged.');
    echo 'Account switching: ', $checks, ' checks passed on PHP ', $phpVersion, '.', PHP_EOL;
} finally {
    if ($routePath !== null && is_file($routePath)) unlink($routePath);
}
