<?php
// Run: php tests/college-dean-accounts.php [--http]
// Every real request runs in a transaction that is rolled back, including through Apache.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth_useraccount.php';
$snapshot = static function () use ($conn): array {
    $state = [];
    foreach (['tbl_useraccount', 'tbl_useraccount_roles', 'tbl_faculty', 'tbl_useraccount_faculty_links', 'tbl_user_college_access', 'tbl_useraccount_registrar_scopes', 'tbl_enrollment_headers', 'tbl_enrollment_subjects', 'tbl_enrollment_workflow_logs'] as $table) {
        $state[$table] = hash('sha256', json_encode($conn->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }
    return $state;
};
$before = $snapshot();
$http = in_array('--http', $argv, true);
$routePath = null;
$routeName = '';
$token = '';
$phpVersion = PHP_VERSION;
if ($http) {
    $token = bin2hex(random_bytes(24));
    $routeName = 'college-dean-qa-' . bin2hex(random_bytes(8)) . '.php';
    $routePath = __DIR__ . '/../tmp/' . $routeName;
    $route = <<<'PHP'
<?php
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(QA_TOKEN, $_GET['qa'])) { http_response_code(404); exit; }
header('X-QA-PHP-Version: ' . PHP_VERSION);
define('SYNK_COLLEGE_DEAN_QA_HTTP', true);
require QA_FIXTURE;
PHP;
    file_put_contents($routePath, str_replace(['QA_TOKEN','QA_FIXTURE'], [var_export($token, true), var_export(__DIR__ . '/fixtures/college-dean-request.php', true)], $route));
}
$request = static function (string $scenario) use ($http, $routeName, $token, &$phpVersion): array {
    if ($http) {
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20, 'header' => "Connection: close\r\n"]]);
        $body = file_get_contents('http://localhost/synk/tmp/' . $routeName . '?qa=' . $token . '&scenario=' . rawurlencode($scenario), false, $context);
        preg_match('/X-QA-PHP-Version: ([^\r\n]+)/i', implode("\n", $http_response_header ?? []), $version);
        $phpVersion = $version[1] ?? 'unknown';
    } else {
        $process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/college-dean-request.php', $scenario], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, __DIR__ . '/..');
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
    foreach (['save_standalone','save_multi','update_multi','update_remove'] as $scenario) {
        $r = $request($scenario);
        $check($r['response'] === 'success', $scenario . ' failed: ' . json_encode($r['response']));
        if ($scenario === 'save_standalone') {
            $check($r['roles'] === ['college_dean'] && $r['primary_role'] === 'college_dean', 'Standalone College Dean did not persist.');
        } elseif ($scenario === 'update_remove') {
            $check(!in_array('college_dean', $r['roles'], true) && count($r['roles']) === 7 && $r['college_dean_college_id'] === 0, 'Removing College Dean changed other roles or left its assignment.');
        } else {
            $check(count($r['roles']) === 8, 'The shared email did not retain all eight roles.');
        }
        if ($scenario !== 'save_standalone') $check($r['college_id'] === $r['chair_college'], 'College Dean overwrote the Program Chair assignment.');
        if ($scenario !== 'update_remove') $check($r['college_dean_college_id'] === $r['dean_college'], 'College Dean assignment did not persist.');
        if ($scenario === 'update_multi') $check($r['primary_role'] === 'program_chair', 'The existing Program Chair default cannot be selected.');
    }
    foreach (['missing_scope' => 'need_college_dean_college', 'invalid_scope' => 'invalid_college_dean_college', 'duplicate_email' => 'dup_email'] as $scenario => $error) {
        $r = $request($scenario);
        $check($r['response'] === $error && count($r['roles']) === 8, 'Invalid request changed the account: ' . $scenario);
    }
    foreach (['matrix_assign','matrix_missing','matrix_remove'] as $scenario) {
        $r = $request($scenario);
        $check($r['response']['status'] === 'ok', 'Role assignment handler failed.');
        if ($scenario === 'matrix_missing') {
            $check($r['response']['changed'] === 0 && $r['response']['skipped'][0]['reason'] === 'need_college_dean_college', 'Matrix assignment bypassed its required college.');
        } else {
            $check($r['response']['changed'] === 1 && in_array('college_dean', $r['roles'], true) === ($scenario === 'matrix_assign'), 'College Dean matrix assignment/removal failed.');
        }
    }
    $r = $request('load');
    $display = $r['response']['accounts'][0];
    $check($r['response']['status'] === 'ok' && count($display['roles']) === 8 && $display['primary_role'] === 'college_dean', 'Account display lost roles or its default.');
    $check($display['college_dean_college_label'] !== '' && (int)$display['college_dean_college_id'] === $r['dean_college'] && (int)$display['program_chair_college_id'] === $r['chair_college'], 'Account display did not preserve both college assignments.');
    $r = $request('login');
    $check($r['response']['status'] === 'success' && $r['response']['redirect'] === 'college-dean/' && $r['session_role'] === 'college_dean' && $r['session_college'] === $r['dean_college'], 'The real login chooser selected the wrong role, route, or college.');
    foreach (['index','programs','students','reports'] as $page) {
        $r = $request('portal_' . $page);
        $check(is_string($r['response']) && strpos($r['response'], 'College Dean') !== false && !preg_match('/Fatal error|Parse error|<b>Warning<\/b>|xdebug-error/i', $r['response']), 'College Dean page failed to render: ' . $page);
        $check($r['session_college'] === $r['dean_college'], 'Portal did not refresh its college assignment.');
        $check(strpos($r['response'], 'href="enrollment.php"') === false && strpos($r['response'], 'href="drafts.php"') === false, 'Removed Dean modules remain in navigation: ' . $page);
        $check(strpos($r['response'], 'href="custom_css.css"') !== false, 'Dean page does not load the shared dashboard theme: ' . $page);
    }
    foreach (['enrollment','drafts'] as $page) {
        $r = $request('portal_' . $page);
        $check($r['http_status'] === 302 && $r['response'] === '', 'Removed Dean page still renders: ' . $page);
        if ($http) $check($r['redirect'] === 'index.php', 'Removed Dean page did not redirect to the dashboard.');
    }
    $r = $request('prospectus');
    $check(isset($r['response']['header']) || isset($r['response']['program_name']), 'Assigned-college prospectus did not load: ' . json_encode($r['response']));
    $r = $request('prospectus_outside');
    $check($r['http_status'] === 403 && isset($r['response']['error']), 'College Dean accessed another college prospectus.');
    foreach (['offerings','offerings_outside','draft_save','draft_submit'] as $scenario) {
        $r = $request($scenario);
        $check($r['http_status'] === 403 && $r['response']['status'] === 'error' && $r['response']['code'] === 'feature_unavailable', 'Removed Dean workflow remains accessible: ' . $scenario);
        $check(!in_array('submitted', array_column($r['logs'], 'action_type'), true), 'Removed Dean workflow submitted a draft.');
    }
    foreach (['revoked','unauthorized'] as $scenario) {
        $r = $request($scenario);
        $check($r['http_status'] === 403 && $r['response']['status'] === 'error', 'College Dean authorization failed: ' . $scenario);
    }
    $check($snapshot() === $before, 'A fixture changed real account, faculty, scope, or enrollment data.');
    echo 'College Dean ', $http ? 'HTTP' : 'CLI', ': ', $checks, ' checks passed on PHP ', $phpVersion, '. All fixture records rolled back.', PHP_EOL;
} finally {
    if ($routePath !== null && is_file($routePath)) unlink($routePath);
}
