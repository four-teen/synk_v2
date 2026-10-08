<?php
// Run: php tests/professor-account-links-http.php
// Read-only checks through Apache, using its actual PHP version and a temporary
// token-protected loopback route. The route and test session are removed afterward.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/db.php';
$actor = $conn->query("SELECT user_id FROM tbl_useraccount WHERE role = 'admin' AND status = 'active' LIMIT 1")->fetch_assoc();
if (!$actor) throw new RuntimeException('An active administrator is required for the account page checks.');
$expectedCount = (int)$conn->query('SELECT COUNT(*) AS total FROM tbl_useraccount')->fetch_assoc()['total'];
$token = bin2hex(random_bytes(24));
$root = realpath(__DIR__ . '/..');
$directory = $root . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($directory)) mkdir($directory, 0775, true);
$routeName = 'professor-links-http-' . bin2hex(random_bytes(8)) . '.php';
$routePath = $directory . DIRECTORY_SEPARATOR . $routeName;
$route = <<<'PHP'
<?php
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(QA_TOKEN, $_GET['qa'])) {
    http_response_code(404);
    exit;
}
header('X-QA-PHP-Version: ' . PHP_VERSION);
session_start();
$_SESSION = ['user_id' => QA_ADMIN_ID, 'role' => 'admin'];
session_write_close();
register_shutdown_function(static function () {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
});
if (($_GET['action'] ?? '') === 'page') {
    require QA_ROOT . '/administrator/manage-accounts.php';
} else {
    $_POST = ['load_accounts' => 1, 'response_format' => 'json'];
    require QA_ROOT . '/backend/query_accounts.php';
}
PHP;
$route = str_replace(['QA_TOKEN', 'QA_ADMIN_ID', 'QA_ROOT'], [var_export($token, true), (string)(int)$actor['user_id'], var_export($root, true)], $route);
file_put_contents($routePath, $route);
$request = static function (string $action, bool $authorized = true) use ($routeName, $token): array {
    $context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15, 'header' => "Connection: close\r\n"]]);
    $url = 'http://localhost/synk/tmp/' . $routeName . '?action=' . rawurlencode($action) . '&qa=' . ($authorized ? $token : 'invalid');
    $body = file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $status);
    return ['status' => (int)($status[1] ?? 0), 'body' => (string)$body, 'headers' => implode("\n", $headers)];
};
$checks = 0;
$check = static function (bool $passed, string $message) use (&$checks): void {
    if (!$passed) throw new RuntimeException($message);
    $checks++;
};
try {
    $guard = $request('load', false);
    $check($guard['status'] === 404, 'The test route rejects invalid tokens.');
    $load = $request('load');
    $check($load['status'] === 200, 'The actual web server returns HTTP 200 for account loading.');
    $json = json_decode($load['body'], true);
    $check(is_array($json) && ($json['status'] ?? '') === 'ok', 'The actual web server returns clean account JSON: ' . substr($load['body'], 0, 1500));
    $check(count($json['accounts']) === $expectedCount, 'The response contains the complete account list.');
    $eligible = 0;
    foreach ($json['accounts'] as $account) {
        $check(array_key_exists('professor_eligible', $account) && array_key_exists('professor_link_reason', $account), 'Every account includes Professor assignment eligibility.');
        if ($account['professor_eligible']) $eligible++;
    }
    $page = $request('page');
    $check($page['status'] === 200 && strpos($page['body'], 'id="accountsTable"') !== false, 'Apache renders the actual manage accounts page.');
    $check(!preg_match('/Parse error|Fatal error|<b>Warning<\/b>|xdebug-error/i', $page['body']), 'The rendered page contains no PHP errors.');
    preg_match('/X-QA-PHP-Version: ([^\r\n]+)/i', $load['headers'], $version);
    echo 'Account loading HTTP: ', $checks, ' checks passed on PHP ', $version[1] ?? 'unknown', '; ', $expectedCount, ' accounts, ', $eligible, ' eligible.', PHP_EOL;
} finally {
    if (is_file($routePath)) unlink($routePath);
}
