<?php
require_once __DIR__ . '/auth_useraccount.php';
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/student_login_activity_helper.php';

function synk_student_login_csrf(): string
{
    if (!isset($_SESSION['student_login_csrf'])) $_SESSION['student_login_csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['student_login_csrf'];
}

function synk_student_login_check_csrf($token): bool
{
    return is_string($token) && isset($_SESSION['student_login_csrf']) && hash_equals($_SESSION['student_login_csrf'], $token);
}

function synk_student_login_issue_labels(): array
{
    return [
        'student_email_invalid' => 'Email format needs checking',
        'email_domain_denied' => 'Email is outside the SKSU domain',
        'student_email_mismatch' => 'Entered email differs from the Google account',
        'student_directory_access_denied' => 'Email was not found in the Student Directory',
        'student_email_ambiguous' => 'Email is assigned to more than one student',
        'student_session_changed' => 'Student email or directory record changed',
        'google_cancelled' => 'Student cancelled Google sign-in',
        'google_unavailable' => 'Google sign-in is unavailable',
    ];
}

function synk_student_login_issue_label(string $code): string
{
    return synk_student_login_issue_labels()[$code] ?? 'Google sign-in could not be completed';
}

function synk_student_login_record_issue(mysqli $conn, string $submitted, string $code, string $verifiedEmail = '', string $googleName = ''): void
{
    synk_student_login_activity_record($conn,$submitted,'failure',$code,$verifiedEmail!=='');
    $submitted = synk_normalize_email($submitted);
    $submitted = function_exists('mb_substr') ? mb_substr($submitted, 0, 255, 'UTF-8') : substr($submitted, 0, 255);
    if ($submitted === '') return;
    $verifiedEmail = substr(synk_normalize_email($verifiedEmail), 0, 255);
    $code = substr($code, 0, 64);
    $verified = $verifiedEmail !== '' ? 1 : 0;
    $name = function_exists('mb_substr') ? mb_substr($googleName, 0, 255, 'UTF-8') : substr($googleName, 0, 255);
    if (!$verified) $name = '';
    $key = hash('sha256', $submitted . "\n" . $verifiedEmail . "\n" . $code);
    $stmt = $conn->prepare("INSERT INTO tbl_student_login_issues
        (issue_key,submitted_email,google_email,google_name,failure_code,identity_verified)
        VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE attempt_count=attempt_count+1,last_seen=CURRENT_TIMESTAMP,
        google_name=VALUES(google_name),review_status='open',reviewed_by=NULL,reviewed_at=NULL");
    if (!$stmt) throw new RuntimeException('Unable to record the student sign-in issue.');
    $stmt->bind_param('sssssi', $key, $submitted, $verifiedEmail, $name, $code, $verified);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to record the student sign-in issue.'); }
    $stmt->close();
    $_SESSION['student_login_issue_saved'] = true;
}

// A Google identity is accepted only after the shared callback validates its
// signature, issuer, audience, expiration, nonce and verified UserInfo email.
function synk_student_login_find_directory(mysqli $conn, string $email): array
{
    $email = synk_normalize_email($email);
    if ($email === '' || !synk_table_exists($conn, 'tbl_student_management')) return [];
    $stmt = $conn->prepare('SELECT student_id,email_address,first_name,last_name,middle_name,suffix_name
        FROM tbl_student_management WHERE LOWER(TRIM(email_address))=? ORDER BY student_id LIMIT 2');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function synk_student_login_verified_identity(mysqli $conn, string $submitted, array $identity, string $allowedDomain): array
{
    $email = synk_normalize_email((string)($identity['email'] ?? ''));
    $name = (string)($identity['name'] ?? '');
    if (($identity['email_verified'] ?? false) !== true || trim((string)($identity['sub'] ?? '')) === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        synk_student_login_activity_record($conn,$submitted,'failure','google_profile_invalid');
        throw new DomainException('google_profile_invalid');
    }
    $failure = '';
    if (!synk_is_allowed_email_domain($email, $allowedDomain)) $failure = 'email_domain_denied';
    elseif (!hash_equals(synk_normalize_email($submitted), $email)) $failure = 'student_email_mismatch';
    $rows = $failure === '' ? synk_student_login_find_directory($conn, $email) : [];
    if ($failure === '' && !$rows) $failure = 'student_directory_access_denied';
    elseif ($failure === '' && count($rows) !== 1) $failure = 'student_email_ambiguous';
    if ($failure !== '') {
        synk_student_login_record_issue($conn, $submitted, $failure, $email, $name);
        throw new DomainException($failure);
    }
    return $rows[0];
}

function synk_student_login_complete(mysqli $conn, array $student, string $email, string $name, string $googleSub): void
{
    synk_complete_student_login($email,$name,$googleSub);
    $_SESSION['student_id']=(int)$student['student_id'];
    synk_student_login_activity_record($conn,$email,'success','login_success',true,(int)$student['student_id']);
}

function synk_student_login_session_record(mysqli $conn): ?array
{
    if (($_SESSION['role'] ?? '') !== 'student' || !isset($_SESSION['student_id'], $_SESSION['student_google_sub'])) return null;
    $rows = synk_student_login_find_directory($conn, (string)($_SESSION['email'] ?? ''));
    return count($rows) === 1 && (int)$rows[0]['student_id'] === (int)$_SESSION['student_id'] ? $rows[0] : null;
}

function synk_student_login_require_admin(mysqli $conn): array
{
    if (($_SESSION['role'] ?? '') !== 'admin' || !isset($_SESSION['user_id'])) throw new DomainException('Administrator sign-in is required.', 403);
    $user = synk_find_useraccount_by_id($conn, (int)$_SESSION['user_id']);
    if (!$user || $user['status'] !== 'active' || !synk_useraccount_has_active_role($conn, $user, 'admin')) throw new DomainException('Administrator access is no longer available.', 403);
    return $user;
}

function synk_student_login_review_issue(mysqli $conn, int $issueId, string $note): void
{
    $user = synk_student_login_require_admin($conn);
    $length = function_exists('mb_strlen') ? mb_strlen($note, 'UTF-8') : strlen($note);
    if ($issueId <= 0 || $length > 1000) throw new DomainException('Check the issue and keep the note under 1,000 characters.', 422);
    $stmt = $conn->prepare("UPDATE tbl_student_login_issues SET review_status='reviewed',review_note=?,reviewed_by=?,reviewed_at=CURRENT_TIMESTAMP WHERE issue_id=?");
    $stmt->bind_param('sii', $note, $user['user_id'], $issueId);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to save the review.'); }
    $stmt->close();
}
