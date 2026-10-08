<?php
// Run: php tests/di-faculty-directory-http.php
// Checks the real Apache/PHP form. Each request rolls back every fixture write.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/faculty_directory_helper.php';
synk_faculty_directory_ensure_schema($conn);
$before = synk_faculty_directory_records($conn);
$checks = 0;
function di_http_check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$qaToken = bin2hex(random_bytes(24));
$root = realpath(__DIR__ . '/..');
$temporaryDirectory = $root . DIRECTORY_SEPARATOR . 'tmp';
if (!is_dir($temporaryDirectory)) mkdir($temporaryDirectory, 0775, true);
$routeName = 'di-directory-http-' . bin2hex(random_bytes(8)) . '.php';
$routePath = $temporaryDirectory . DIRECTORY_SEPARATOR . $routeName;
$fixturePath = __DIR__ . '/fixtures/di-faculty-review-http.php';
file_put_contents($routePath, '<?php define(\'SYNK_DI_HTTP_QA\', ' . var_export($qaToken, true) . '); require ' . var_export($fixturePath, true) . ';');
$url = 'http://localhost/synk/tmp/' . $routeName;
$request = static function (string $scenario, ?array $post = null, bool $authorizedRoute = true) use ($url, $qaToken): array {
    $headers = "Connection: close\r\n";
    if ($post !== null) $headers .= "Content-Type: application/x-www-form-urlencoded\r\n";
    $context = stream_context_create(['http' => ['method' => $post === null ? 'GET' : 'POST', 'header' => $headers,
        'content' => $post === null ? '' : http_build_query($post), 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15]]);
    $body = file_get_contents($url . '?scenario=' . rawurlencode($scenario) . '&qa=' . ($authorizedRoute ? $qaToken : 'invalid'), false, $context);
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $status);
    return ['status' => (int)($status[1] ?? 0), 'body' => (string)$body, 'headers' => implode("\n", $responseHeaders)];
};
$validPost = ['csrf_token' => $qaToken, 'revision' => '0', 'status' => 'resigned', 'campus_id' => '0', 'college_id' => '0', 'remarks' => 'QA resignation review'];
try {
    $guard = $request('get', null, false);
    di_http_check($guard['status'] === 404, 'The temporary test route rejects requests without its random token.');
    $get = $request('get');
    di_http_check($get['status'] === 200 && strpos($get['body'], 'Save faculty review') !== false, 'An active DI sees the actual faculty review form.');
    foreach (['Active', 'Inactive', 'Resigned', 'Retired', 'On leave', 'Other'] as $label) di_http_check(strpos($get['body'], '>' . $label . '</option>') !== false, 'Review form offers status: ' . $label);
    $csrf = $request('csrf', array_merge($validPost, ['csrf_token' => 'invalid']));
    di_http_check($csrf['status'] === 403 && strpos($csrf['headers'], 'X-QA-Faculty-Status: active') !== false, 'An invalid CSRF token cannot modify faculty status.');
    $unauthorized = $request('unauthorized', $validPost);
    di_http_check($unauthorized['status'] === 302 && strpos($unauthorized['headers'], 'auth_status=account_not_allowed') !== false
        && strpos($unauthorized['headers'], 'X-QA-Faculty-Status: active') !== false, 'A non-DI account cannot POST a faculty review.');
    $stale = $request('stale', array_merge($validPost, ['revision' => '999']));
    di_http_check($stale['status'] === 409 && strpos($stale['headers'], 'X-QA-Faculty-Status: active') !== false, 'An outdated form cannot overwrite the faculty record.');
    $other = $request('other', array_merge($validPost, ['status' => 'other', 'remarks' => '']));
    di_http_check($other['status'] === 200 && strpos($other['body'], 'Add remarks to explain the other status.') !== false
        && strpos($other['headers'], 'X-QA-Faculty-Status: active') !== false, 'Other status requires a review note before saving.');
    $save = $request('save', $validPost);
    di_http_check($save['status'] === 302 && strpos($save['headers'], 'Location: faculty-profile.php?faculty_id=') !== false, 'Saving uses a redirect to prevent duplicate form submissions.');
    di_http_check(strpos($save['headers'], 'X-QA-Faculty-Status: resigned') !== false && strpos($save['headers'], 'X-QA-Faculty-Revision: 1') !== false, 'The real PHP form saves resigned status on the retained faculty record.');
    $badId = $request('bad_id');
    di_http_check($badId['status'] === 404, 'Malformed faculty identifiers have a safe not-found response.');
    $list = $request('list');
    di_http_check($list['status'] === 200 && strpos($list['body'], 'DI Directory HTTP QA Faculty') !== false, 'The faculty list shows unassigned faculty despite forged semester and academic-year filters.');
} finally {
    if (is_file($routePath)) unlink($routePath);
}
di_http_check(synk_faculty_directory_records($conn) === $before, 'All HTTP fixture changes were rolled back; real records are unchanged.');
echo 'DI faculty directory HTTP: ', $checks, ' checks passed. Fixtures rolled back and test route removed.', PHP_EOL;
