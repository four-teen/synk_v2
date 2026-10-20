<?php
session_start();

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth_google.php';

$settings = synk_auth_settings();
$incomingState = is_string($_GET['state'] ?? null) ? trim($_GET['state']) : '';
$studentState = (string)($_SESSION['student_google_oauth_state'] ?? '');
$mainState = (string)($_SESSION['google_oauth_state'] ?? '');
$matchesStudent = $incomingState !== '' && $studentState !== '' && hash_equals($studentState, $incomingState);
$matchesMain = $incomingState !== '' && $mainState !== '' && hash_equals($mainState, $incomingState);
$isStudentPortal = $matchesStudent || (!$matchesMain && ($_SESSION['google_oauth_portal'] ?? '') === 'student');
$redirectTarget = $isStudentPortal ? '../student/login.php' : '../index.php';
$stateSessionKey = $isStudentPortal ? 'student_google_oauth_state' : 'google_oauth_state';
$nonceSessionKey = $isStudentPortal ? 'student_google_oauth_nonce' : 'google_oauth_nonce';
$storedState = (string)($_SESSION[$stateSessionKey] ?? '');
$storedNonce = (string)($_SESSION[$nonceSessionKey] ?? '');
$submittedStudentEmail = $isStudentPortal ? (string)($_SESSION['student_google_oauth_email'] ?? '') : '';
$studentStartedAt = (int)($_SESSION['student_google_oauth_started_at'] ?? 0);
$verifiedStudentEmail = '';
$verifiedStudentName = '';
if ($isStudentPortal) require_once __DIR__ . '/student_login_helper.php';
$redirectStatus = static function (string $status, bool $record = true) use ($conn, $isStudentPortal, $redirectTarget,
    $matchesStudent, $submittedStudentEmail, &$verifiedStudentEmail, &$verifiedStudentName): void {
    if ($isStudentPortal && $record) {
        if ($matchesStudent && $submittedStudentEmail !== '') {
            try { synk_student_login_record_issue($conn, $submittedStudentEmail, $status, $verifiedStudentEmail, $verifiedStudentName); }
            catch (Throwable $e) { error_log('Student sign-in diagnostics could not be saved.'); $status = 'student_portal_unavailable'; }
        } else synk_student_login_activity_record($conn,'','failure',$status);
    }
    synk_auth_status_redirect($status, $redirectTarget);
};

if (!synk_google_login_enabled($settings) || !synk_google_auth_ready($settings)) $redirectStatus('google_unavailable');
if ($incomingState === '' || $storedState === '' || !hash_equals($storedState, $incomingState)) {
    // An unbound callback cannot be attributed to the student in a pending flow.
    if ($isStudentPortal) synk_student_login_activity_record($conn,'','failure','google_state_invalid');
    $redirectStatus('google_state_invalid', false);
}
// Consume only the matching flow. A Student and staff sign-in may be open in separate tabs.
unset($_SESSION[$stateSessionKey], $_SESSION[$nonceSessionKey]);
if ($isStudentPortal) unset($_SESSION['student_google_oauth_email'], $_SESSION['student_google_oauth_started_at']);
if (($_SESSION['google_oauth_portal'] ?? '') === ($isStudentPortal ? 'student' : 'main')) unset($_SESSION['google_oauth_portal']);
if ($isStudentPortal && ($submittedStudentEmail === '' || $studentStartedAt < time() - 900)) $redirectStatus('google_state_invalid');

if (!empty($_GET['error'])) {
    $redirectStatus('google_cancelled');
}

$code = is_string($_GET['code'] ?? null) ? trim($_GET['code']) : '';
if ($code === '') {
    $redirectStatus('google_code_missing');
}

$tokenResponse = synk_google_exchange_code($settings, $code);
if (!$tokenResponse['ok']) {
    $redirectStatus('google_token_failed');
}

$tokenJson = $tokenResponse['json'];
$accessToken = trim((string)($tokenJson['access_token'] ?? ''));
if ($accessToken === '') {
    $redirectStatus('google_token_missing');
}

$idToken = trim((string)($tokenJson['id_token'] ?? ''));
if ($idToken === '') {
    $redirectStatus('google_id_token_missing');
}

$idTokenClaims = synk_google_decode_id_token($idToken);
$idTokenStatus = synk_google_validate_id_token_claims($settings, $idToken, $idTokenClaims, $storedNonce);
if ($idTokenStatus !== null) {
    $redirectStatus($idTokenStatus);
}

$userInfoResponse = synk_google_fetch_userinfo($accessToken);
if (!$userInfoResponse['ok']) {
    $redirectStatus('google_profile_failed');
}

$userInfo = $userInfoResponse['json'];
$googleSub = trim((string)($userInfo['sub'] ?? ''));
$email = synk_normalize_email((string)($userInfo['email'] ?? ''));
$displayName = trim((string)($userInfo['name'] ?? ''));
$pictureUrl = trim((string)($userInfo['picture'] ?? ''));
$emailVerified = !empty($userInfo['email_verified']);
$idTokenEmail = synk_normalize_email((string)($idTokenClaims['email'] ?? ''));
$idTokenSub = trim((string)($idTokenClaims['sub'] ?? ''));

if ($googleSub === '' || $email === '' || !$emailVerified) {
    $redirectStatus('google_profile_invalid');
}

if (($idTokenEmail !== '' && !hash_equals($idTokenEmail, $email))
    || ($idTokenSub !== '' && !hash_equals($idTokenSub, $googleSub))) {
    $redirectStatus('google_email_mismatch');
}

if ($isStudentPortal) { $verifiedStudentEmail = $email; $verifiedStudentName = $displayName; }

if (!synk_is_allowed_email_domain($email, (string)$settings['allowed_domain'])) {
    $redirectStatus('email_domain_denied');
}

if ($isStudentPortal) {
    try {
        $directoryStudent = synk_student_login_verified_identity($conn, $submittedStudentEmail, $userInfo, (string)$settings['allowed_domain']);
    } catch (DomainException $e) { $redirectStatus($e->getMessage(), false); }
    catch (Throwable $e) { $redirectStatus('student_portal_unavailable'); }
    synk_student_login_complete($conn,$directoryStudent,$email,$displayName,$googleSub);
    unset($_SESSION['student_login_last_email'], $_SESSION['student_login_csrf']);
    if ($pictureUrl !== '') $_SESSION['user_avatar_url'] = $pictureUrl;
    header('Location: ../student/');
    exit;
}

$user = synk_find_useraccount_by_email($conn, $email);
if (!$user) {
    $redirectStatus('account_not_allowed');
}

if (($user['status'] ?? '') !== 'active') {
    $redirectStatus('account_inactive');
}

$configuredRoleRows = synk_fetch_useraccount_role_rows(
    $conn,
    (int)($user['user_id'] ?? 0),
    (string)($user['role'] ?? '')
);
$loginableRoleRows = synk_filter_loginable_role_rows($conn, $user, $configuredRoleRows);

if (empty($loginableRoleRows)) {
    $status = 'role_not_supported';

    foreach ($configuredRoleRows as $roleRow) {
        if ((string)($roleRow['role'] ?? '') === 'scheduler') {
            $status = 'account_incomplete';
            break;
        }
    }

    $redirectStatus($status);
}

$existingGoogleSub = trim((string)($user['google_sub'] ?? ''));
if ($existingGoogleSub !== '' && !hash_equals($existingGoogleSub, $googleSub)) {
    $redirectStatus('google_identity_mismatch');
}

synk_record_google_login($conn, (int)$user['user_id'], $googleSub, $emailVerified, $displayName);

if (count($loginableRoleRows) > 1) {
    synk_store_pending_role_login($user, $loginableRoleRows, ['avatar_url' => $pictureUrl]);
    $redirectStatus('choose_role');
}

$activeRole = synk_complete_user_login($user, $conn, null, $loginableRoleRows);

if ($pictureUrl !== '') {
    $_SESSION['user_avatar_url'] = $pictureUrl;
}

$redirectPath = synk_role_redirect_path($activeRole);
if ($redirectPath === null) {
    $redirectStatus('role_not_supported');
}

header('Location: ../' . $redirectPath);
exit;
