<?php
require_once __DIR__ . '/../backend/auth_google.php';

const SYNK_HRIS_ADMIN = 'hris_admin';
const SYNK_HRIS_USER = 'hris_user';

function synk_hris_role_labels(): array
{
    return [SYNK_HRIS_ADMIN => 'Administrator', SYNK_HRIS_USER => 'User'];
}

function synk_hris_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function synk_hris_headers(string $styleNonce = ''): void
{
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    $chartStyles = $styleNonce === '' ? '' : "style-src 'self' 'nonce-{$styleNonce}'; ";
    header("Content-Security-Policy: default-src 'self'; {$chartStyles}object-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'self' https://accounts.google.com");
}

function synk_hris_csrf(): string
{
    if (empty($_SESSION['hris_csrf'])) {
        $_SESSION['hris_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['hris_csrf'];
}

function synk_hris_valid_post(): bool
{
    $token = $_POST['csrf_token'] ?? null;
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && is_string($token)
        && !empty($_SESSION['hris_csrf']) && hash_equals($_SESSION['hris_csrf'], $token);
}

function synk_hris_start_google(array $settings): void
{
    if (!synk_hris_valid_post()) {
        synk_auth_status_redirect('login_request_invalid', 'login.php');
    }
    if (!synk_google_login_enabled($settings) || !synk_google_auth_ready($settings)) {
        synk_auth_status_redirect('google_unavailable', 'login.php');
    }

    // Use the same registered callback as staff sign-in, even when no override is configured.
    if (trim((string)$settings['google_redirect_uri']) === '') {
        $base = preg_replace('#/hris/[^/]+$#', '', synk_auth_base_url());
        $settings['google_redirect_uri'] = $base . '/backend/auth_google_callback.php';
    }
    $state = bin2hex(random_bytes(32));
    $nonce = bin2hex(random_bytes(32));
    $_SESSION['hris_google_oauth_state'] = $state;
    $_SESSION['hris_google_oauth_nonce'] = $nonce;
    $_SESSION['hris_google_oauth_started_at'] = time();
    header('Location: ' . synk_google_auth_url($settings, $state, $nonce), true, 303);
    exit;
}

function synk_hris_find_account(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare("SELECT u.user_id, u.username, u.email, u.google_sub, r.role_code
        FROM tbl_useraccount u JOIN tbl_hr_user_roles r ON r.user_id = u.user_id
        WHERE u.user_id = ? AND u.status = 'active' AND r.role_code IN ('hris_admin', 'hris_user') AND r.status = 'active'
        ORDER BY r.role_code LIMIT 2");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    // An account with conflicting active HRIS roles must not receive the more privileged role by accident.
    $account = $result->num_rows === 1 ? $result->fetch_assoc() : null;
    $stmt->close();
    return $account ?: null;
}

function synk_hris_find_admin(mysqli $conn, int $userId): ?array
{
    $account = synk_hris_find_account($conn, $userId);
    return $account && $account['role_code'] === SYNK_HRIS_ADMIN ? $account : null;
}

// Called only after the shared callback verifies the Google response and HRIS OAuth state.
function synk_hris_complete_login(mysqli $conn, array $google, array $settings): void
{
    $email = synk_normalize_email((string)($google['email'] ?? ''));
    $subject = trim((string)($google['sub'] ?? ''));
    if ($subject === '' || strlen($subject) > 64 || ($google['email_verified'] ?? false) !== true
        || !synk_is_allowed_email_domain($email, (string)$settings['allowed_domain'])) {
        throw new DomainException('hris_access_denied');
    }

    $stmt = $conn->prepare("SELECT u.user_id, u.google_sub
        FROM tbl_useraccount u
        WHERE LOWER(u.email) = ? AND u.status = 'active' AND EXISTS (
            SELECT 1 FROM tbl_hr_user_roles r WHERE r.user_id = u.user_id
            AND r.role_code IN ('hris_admin', 'hris_user') AND r.status = 'active'
        ) AND (SELECT COUNT(*) FROM tbl_hr_user_roles r WHERE r.user_id = u.user_id
            AND r.role_code IN ('hris_admin', 'hris_user') AND r.status = 'active') = 1
        LIMIT 2");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $account = $result->num_rows === 1 ? $result->fetch_assoc() : null;
    $stmt->close();
    if (!$account) {
        throw new DomainException('hris_access_denied');
    }

    $userId = (int)$account['user_id'];
    $boundSubject = trim((string)$account['google_sub']);
    if ($boundSubject === '') {
        // Bind once, without changing the account's original Synk role or sign-in preferences.
        $stmt = $conn->prepare("UPDATE tbl_useraccount SET google_sub = ?, google_email_verified = 1
            WHERE user_id = ? AND LOWER(email) = ? AND status = 'active'
            AND (google_sub IS NULL OR google_sub = '')");
        $stmt->bind_param('sis', $subject, $userId, $email);
        $stmt->execute();
        $stmt->close();
    }
    $current = synk_hris_find_account($conn, $userId);
    if (!$current || synk_normalize_email($current['email']) !== $email) {
        throw new DomainException('hris_access_denied');
    }
    if (!hash_equals((string)$current['google_sub'], $subject)) {
        throw new DomainException('google_identity_mismatch');
    }

    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Could not refresh the HRIS session.');
    }
    unset($_SESSION['hris_csrf']);
    $_SESSION['hris_identity'] = ['user_id' => $userId, 'google_sub' => $subject, 'signed_in_at' => time()];
}

function synk_hris_require_account(): array
{
    $identity = $_SESSION['hris_identity'] ?? null;
    if (!is_array($identity) || (int)($identity['signed_in_at'] ?? 0) < time() - 28800) {
        unset($_SESSION['hris_identity']);
        synk_auth_status_redirect('sign_in_required', 'login.php');
    }

    try {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        require __DIR__ . '/../backend/db.php';
        $account = synk_hris_find_account($conn, (int)$identity['user_id']);
    } catch (Throwable $error) {
        error_log('HRIS access check failed: ' . get_class($error));
        synk_auth_status_redirect('hris_unavailable', 'login.php');
    }
    $settings = synk_auth_settings();
    if (!$account || (string)$account['google_sub'] === '' || !hash_equals((string)$account['google_sub'], (string)($identity['google_sub'] ?? ''))
        || !synk_is_allowed_email_domain($account['email'], (string)$settings['allowed_domain'])) {
        unset($_SESSION['hris_identity']);
        synk_auth_status_redirect('hris_access_denied', 'login.php');
    }
    return [$conn, $account];
}

function synk_hris_require_admin(): array
{
    [$conn, $account] = synk_hris_require_account();
    if ($account['role_code'] !== SYNK_HRIS_ADMIN) {
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            header('Location: workspace.php', true, 302);
            exit;
        }
        http_response_code(403);
        exit('Kawani Administrator access is required.');
    }
    return [$conn, $account];
}
