<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../account-access.php';
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

function hris_test_sign_in_rejected(mysqli $conn, array $google, array $settings, string $reason): void
{
    try {
        synk_hris_complete_login($conn, $google, $settings);
        throw new RuntimeException('An unauthorized Google sign-in was accepted.');
    } catch (DomainException $error) {
        check_hris($error->getMessage() === $reason, 'Google sign-in should be rejected as ' . $reason);
    }
}

check_hris(synk_hris_employee_accounts_ready($conn), 'Apply employee-accounts.sql before testing account approvals.');
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
    $original = $conn->query("SELECT * FROM tbl_useraccount WHERE user_id = {$userId}")->fetch_assoc();
    hris_test_sign_in_rejected($conn, $google, $settings, 'hris_access_denied');

    synk_hris_write_account($conn, null, null, $userId, $email, SYNK_HRIS_ADMIN, null);
    synk_hris_complete_login($conn, $google, $settings);
    $approval = synk_hris_employee_account($conn, 'email', $email);
    check_hris((int)$approval['synk_user_id'] === $userId && $approval['google_sub'] === $google['sub']
        && $_SESSION['hris_identity']['account_id'] === (int)$approval['account_id'], 'A verified Google identity must bind in Kawani.');
    check_hris($_SESSION['user_id'] === 17 && $_SESSION['role'] === 'professor', 'The Kawani session must preserve the Synk role and identity.');
    check_hris($conn->query("SELECT * FROM tbl_useraccount WHERE user_id = {$userId}")->fetch_assoc() === $original, 'Kawani must preserve every field of the Synk account.');
    hris_test_sign_in_rejected($conn, array_replace($google, ['sub' => 'different-subject']), $settings, 'google_identity_mismatch');
    synk_hris_write_account($conn, $approval, null, $userId, $email, null, $userId);
    hris_test_sign_in_rejected($conn, $google, $settings, 'hris_access_denied');

    $employeeEmail = 'hris-employee-test-' . bin2hex(random_bytes(6)) . '@' . $settings['allowed_domain'];
    $employeeGoogle = ['email' => $employeeEmail, 'sub' => 'hris-test-' . bin2hex(random_bytes(14)), 'email_verified' => true];
    $conn->query("INSERT INTO tbl_faculty (first_name, last_name, middle_name, ext_name, status) VALUES ('Kawani', 'Test Employee', '', '', 'inactive')");
    $facultyId = (int)$conn->insert_id;
    synk_hris_record_employee_email($conn, $facultyId, $employeeEmail, $userId);
    hris_test_sign_in_rejected($conn, $employeeGoogle, $settings, 'hris_access_denied');
    $recorded = synk_hris_employee_account($conn, 'faculty_id', $facultyId);
    synk_hris_write_account($conn, $recorded, $facultyId, null, $employeeEmail, SYNK_HRIS_USER, $userId);
    hris_test_sign_in_rejected($conn, array_replace($employeeGoogle, ['email_verified' => false]), $settings, 'hris_access_denied');
    synk_hris_complete_login($conn, $employeeGoogle, $settings);
    $employee = synk_hris_employee_account($conn, 'faculty_id', $facultyId);
    check_hris($employee['synk_user_id'] === null && $employee['role_code'] === SYNK_HRIS_USER
        && $employee['google_sub'] === $employeeGoogle['sub'], 'Approved employees can sign in to Kawani without a shared account or faculty login link.');
    check_hris(synk_hris_matching_synk_account($conn, $employeeEmail) === null, 'A Kawani-only sign-in must not create a Synk account.');
    synk_hris_write_account($conn, $employee, $facultyId, null, $employeeEmail, 'conflict', $userId);
    hris_test_sign_in_rejected($conn, $employeeGoogle, $settings, 'hris_access_denied');
} finally {
    $conn->rollback();
    $_SESSION = [];
    session_destroy();
}
echo "HRIS HTTP, OAuth routing and role checks passed. Test account changes rolled back.\n";
