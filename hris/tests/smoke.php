<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../auth.php';
session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__ . '/../../backend/db.php';

function check_hris(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function hris_test_request(string $url, $cookies, ?array $post = null): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 10, CURLOPT_SHARE => $cookies,
        CURLOPT_COOKIEFILE => '']);
    if ($post !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $response = curl_exec($curl);
    check_hris(is_string($response), 'Could not reach local Synk.');
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    preg_match('/^Location:\s*(.+)\r?$/mi', substr($response, 0, $headerSize), $headers);
    return [$status, trim($headers[1] ?? ''), substr($response, $headerSize)];
}

$base = rtrim($argv[1] ?? 'http://localhost/synk', '/');
$cookies = curl_share_init();
curl_share_setopt($cookies, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
[$status, $location] = hris_test_request($base . '/hris/', $cookies);
check_hris($status === 302 && $location === 'login.php?auth_status=sign_in_required', 'Dashboard must require HRIS authentication.');
[$status, , $page] = hris_test_request($base . '/hris/' . $location, $cookies);
preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $page, $token);
check_hris($status === 200 && isset($token[1]) && strpos($page, 'kawan<span>i</span>') !== false, 'Login must render the Kawani wordmark and sign-in form.');
check_hris(strpos($page, 'Back to synQ') === false, 'HRIS login must omit the Synk return link.');
check_hris(strpos($page, 'Human Resource Information System') !== false
    && strpos($page, 'HRIS sign-in</h2>') === false && strpos($page, 'Sign in to open the HRIS workspace.') === false,
    'The redirected login displays the system name without redundant sign-in text.');
[$status, $location] = hris_test_request($base . '/hris/login.php', $cookies, ['csrf_token' => 'wrong']);
check_hris($status === 302 && strpos($location, 'login_request_invalid') !== false, 'Login must reject an invalid form token.');
[$status] = hris_test_request($base . '/hris/logout.php', $cookies);
check_hris($status === 403, 'Sign-out must reject a GET request.');

$settings = synk_auth_settings();
if (synk_google_login_enabled($settings) && synk_google_auth_ready($settings)) {
    [$status, $location] = hris_test_request($base . '/hris/login.php', $cookies, ['csrf_token' => $token[1]]);
    parse_str((string)parse_url($location, PHP_URL_QUERY), $google);
    check_hris($status === 303 && parse_url($location, PHP_URL_HOST) === 'accounts.google.com'
        && strlen($google['state'] ?? '') === 64 && strlen($google['nonce'] ?? '') === 64, 'Start an HRIS Google sign-in with state and nonce.');
    check_hris(($google['redirect_uri'] ?? '') === ($settings['google_redirect_uri'] ?: $base . '/backend/auth_google_callback.php'), 'Use the same callback URI as Synk.');

    [$status, $location] = hris_test_request($base . '/backend/auth_google_start.php', $cookies);
    parse_str((string)parse_url($location, PHP_URL_QUERY), $mainGoogle);
    check_hris($status === 302 && isset($mainGoogle['state']) && $mainGoogle['state'] !== $google['state'], 'HRIS and staff sign-in must use different state values.');
    $callback = $base . '/backend/auth_google_callback.php';
    [$status, $location] = hris_test_request($callback . '?state=' . rawurlencode($google['state']) . '&error=access_denied', $cookies);
    check_hris($status === 302 && $location === '../hris/login.php?auth_status=google_cancelled', 'HRIS cancellation must return to its own login.');
    [$status, $location] = hris_test_request($callback . '?state=' . rawurlencode($mainGoogle['state']) . '&error=access_denied', $cookies);
    check_hris($status === 302 && $location === '../index.php?auth_status=google_cancelled', 'Processing the HRIS callback must preserve the staff login attempt.');
    [$status, $location] = hris_test_request($callback . '?state=' . rawurlencode($google['state']) . '&error=access_denied', $cookies);
    check_hris($status === 302 && strpos($location, 'google_state_invalid') !== false, 'The callback must consume its state once.');
}
[$status, $location] = hris_test_request($base . '/hris/logout.php', $cookies, ['csrf_token' => $token[1]]);
check_hris($status === 303 && $location === 'login.php', 'An HRIS sign-out with a valid form token must return to its login.');
curl_share_close($cookies);

$_SESSION = ['user_id' => 17, 'role' => 'professor', 'auth_method' => 'google'];
$email = 'hris-test-' . bin2hex(random_bytes(6)) . '@' . $settings['allowed_domain'];
$google = ['email' => $email, 'sub' => 'hris-test-' . bin2hex(random_bytes(14)), 'email_verified' => true];
$conn->begin_transaction();
try {
    $stmt = $conn->prepare("INSERT INTO tbl_useraccount (username, email, password, role, status)
        VALUES ('HRIS QA', ?, 'unusable-test-password', 'admin', 'active')");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $userId = (int)$conn->insert_id;
    $stmt->close();
    check_hris(synk_hris_find_admin($conn, $userId) === null, 'Synk Administrator alone must never grant HRIS access.');
    $conn->query("INSERT INTO tbl_hr_user_roles (user_id, role_code) VALUES ({$userId}, 'hris_admin')");
    synk_hris_complete_login($conn, $google, $settings);
    check_hris($_SESSION['hris_identity']['user_id'] === $userId && $_SESSION['user_id'] === 17 && $_SESSION['role'] === 'professor', 'The HRIS session must preserve the existing Synk role and identity.');
    check_hris(synk_hris_find_admin($conn, $userId)['google_sub'] === $google['sub'], 'A verified Google identity must bind to the existing account.');
    try {
        synk_hris_complete_login($conn, array_replace($google, ['sub' => 'different-subject']), $settings);
        throw new RuntimeException('Changing the Google subject must not be accepted.');
    } catch (DomainException $error) {
        check_hris($error->getMessage() === 'google_identity_mismatch', 'Reject a mismatched Google subject.');
    }
    $conn->query("UPDATE tbl_hr_user_roles SET status = 'inactive' WHERE user_id = {$userId}");
    check_hris(synk_hris_find_admin($conn, $userId) === null, 'Revocation must take effect when the workspace is opened again.');
    $conn->query("INSERT INTO tbl_hr_user_roles (user_id, role_code, status) VALUES ({$userId}, 'hris_user', 'active')");
    check_hris(synk_hris_find_account($conn, $userId)['role_code'] === SYNK_HRIS_USER
        && synk_hris_find_admin($conn, $userId) === null, 'A User role grants HRIS sign-in without administrator access.');
    synk_hris_complete_login($conn, $google, $settings);
    check_hris($_SESSION['hris_identity']['user_id'] === $userId, 'A linked HRIS User account can complete verified Google sign-in.');

    $conn->query("UPDATE tbl_hr_user_roles SET status = 'active' WHERE user_id = {$userId} AND role_code = 'hris_admin'");
    check_hris(synk_hris_find_account($conn, $userId) === null, 'Conflicting active HRIS roles must fail closed.');
    try {
        synk_hris_complete_login($conn, $google, $settings);
        throw new RuntimeException('Conflicting active HRIS roles must not allow sign-in.');
    } catch (DomainException $error) {
        check_hris($error->getMessage() === 'hris_access_denied', 'Conflicting roles must be rejected at login.');
    }
} finally {
    $conn->rollback();
    $_SESSION = [];
    session_destroy();
}
echo "HRIS HTTP, OAuth routing and role checks passed. Test account changes rolled back.\n";
