<?php
require_once __DIR__ . '/../backend/auth_google.php';
require_once __DIR__ . '/employee-account-data.php';
require_once __DIR__ . '/permissions.php';

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

function synk_hris_legacy_account(mysqli $conn, $identity): ?array
{
    $filter = is_int($identity) ? 'u.user_id = ?' : 'LOWER(TRIM(u.email)) = ?';
    $type = is_int($identity) ? 'i' : 's';
    $stmt = $conn->prepare("SELECT u.user_id, u.username, u.email, u.google_sub, r.role_code
        FROM tbl_useraccount u JOIN tbl_hr_user_roles r ON r.user_id = u.user_id
        WHERE {$filter} AND u.status = 'active' AND r.role_code IN ('hris_admin', 'hris_user') AND r.status = 'active' LIMIT 2");
    try {
        $stmt->bind_param($type, $identity);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows === 1 ? $result->fetch_assoc() + ['account_id' => 0, 'revision' => 0] : null;
    } finally {
        $stmt->close();
    }
}

function synk_hris_account_access(mysqli $conn, ?array $approval, string $domain): ?array
{
    if (!$approval || !isset(synk_hris_role_labels()[$approval['role_code'] ?? ''])
        || !synk_is_allowed_email_domain($approval['email'], $domain)) return null;

    $facultyId = (int)$approval['faculty_id'];
    $employee = synk_hris_employee_identity($conn, $facultyId);
    if ($facultyId && !$employee) return null;
    try {
        $shared = synk_hris_matching_synk_account($conn, $approval['email'], $facultyId);
    } catch (DomainException $error) {
        return null;
    }
    $sharedId = (int)($shared['user_id'] ?? 0);
    if ($approval['synk_user_id'] !== null && (int)$approval['synk_user_id'] !== $sharedId) return null;

    $name = $employee ? trim(implode(' ', array_filter(array_map('trim', [
        (string)$employee['first_name'], (string)$employee['middle_name'], (string)$employee['last_name'], (string)$employee['ext_name'],
    ]), 'strlen'))) : '';
    return $approval + [
        'user_id' => (int)$approval['synk_user_id'],
        'matching_synk_id' => $sharedId,
        'username' => $name ?: (string)($shared['username'] ?? $approval['email']),
        'synk_google_sub' => (string)($shared['google_sub'] ?? ''),
    ];
}

function synk_hris_actor_id(array $account): int
{
    // Signed audit columns retain positive Synk IDs and use negative IDs for Kawani-only actors.
    return (int)$account['user_id'] ?: -(int)$account['account_id'];
}

// Called only after the shared callback verifies the Google response and HRIS OAuth state.
function synk_hris_complete_login(mysqli $conn, array $google, array $settings): void
{
    $email = synk_normalize_email((string)($google['email'] ?? ''));
    $subject = trim((string)($google['sub'] ?? ''));
    $domain = (string)$settings['allowed_domain'];
    if (!preg_match('/\A[!-~]{1,64}\z/', $subject) || ($google['email_verified'] ?? false) !== true
        || !filter_var($email, FILTER_VALIDATE_EMAIL) || !synk_is_allowed_email_domain($email, $domain)) {
        throw new DomainException('hris_access_denied');
    }

    if (!synk_hris_employee_accounts_ready($conn)) {
        $legacy = synk_hris_legacy_account($conn, $email);
        if (!$legacy) throw new DomainException('hris_access_denied');
        if (trim((string)$legacy['google_sub']) === '') throw new DomainException('hris_unavailable');
        if (!hash_equals((string)$legacy['google_sub'], $subject)) throw new DomainException('google_identity_mismatch');
        $identity = ['user_id' => (int)$legacy['user_id']];
    } else {
        $approval = synk_hris_employee_account($conn, 'email', $email);
        $account = synk_hris_account_access($conn, $approval, $domain);
        if (!$account) throw new DomainException('hris_access_denied');
        foreach ([$account['google_sub'], $account['synk_google_sub']] as $bound) {
            if ((string)$bound !== '' && !hash_equals((string)$bound, $subject)) {
                throw new DomainException('google_identity_mismatch');
            }
        }

        // Bind only while the approved address and role still belong to this account revision.
        $accountId = (int)$account['account_id'];
        $revision = (int)$account['revision'];
        $sharedId = $account['matching_synk_id'] ?: null;
        $stmt = $conn->prepare('UPDATE tbl_hr_employee_accounts SET google_sub = ?, synk_user_id = ?
            WHERE account_id = ? AND revision = ? AND (google_sub IS NULL OR google_sub = ?)
                AND (synk_user_id IS NULL OR synk_user_id = ?)');
        try {
            $stmt->bind_param('siiisi', $subject, $sharedId, $accountId, $revision, $subject, $sharedId);
            $stmt->execute();
        } catch (mysqli_sql_exception $error) {
            if ((int)$error->getCode() === 1062) throw new DomainException('google_identity_mismatch');
            throw $error;
        } finally {
            $stmt->close();
        }
        $current = synk_hris_account_access($conn, synk_hris_employee_account($conn, 'account_id', $accountId), $domain);
        if (!$current || (int)$current['revision'] !== $revision) throw new DomainException('hris_access_denied');
        if (!hash_equals((string)$current['google_sub'], $subject)
            || ($current['synk_google_sub'] !== '' && !hash_equals($current['synk_google_sub'], $subject))) {
            throw new DomainException('google_identity_mismatch');
        }
        $identity = ['account_id' => $accountId, 'revision' => $revision];
    }

    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Could not refresh the HRIS session.');
    }
    unset($_SESSION['hris_csrf']);
    $_SESSION['hris_identity'] = $identity + ['google_sub' => $subject, 'signed_in_at' => time()];
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
        $domain = (string)synk_auth_settings()['allowed_domain'];
        if (synk_hris_employee_accounts_ready($conn)) {
            $upgrading = !isset($identity['account_id']);
            $approval = $upgrading
                ? synk_hris_employee_account($conn, 'legacy_user_id', (int)($identity['user_id'] ?? 0))
                : synk_hris_employee_account($conn, 'account_id', (int)$identity['account_id']);
            $account = synk_hris_account_access($conn, $approval, $domain);
            if ($account && (int)$account['revision'] !== ($upgrading ? 1 : (int)($identity['revision'] ?? 0))) $account = null;
        } else {
            $account = synk_hris_legacy_account($conn, (int)($identity['user_id'] ?? 0));
        }
    } catch (Throwable $error) {
        error_log('HRIS access check failed: ' . get_class($error));
        synk_auth_status_redirect('hris_unavailable', 'login.php');
    }
    $activeRole = $identity['active_role'] ?? ($account['role_code'] ?? null);
    if (!$account || !in_array($activeRole, synk_hris_assigned_roles($account), true)
        || (string)$account['google_sub'] === '' || !hash_equals((string)$account['google_sub'], (string)($identity['google_sub'] ?? ''))
        || (!empty($account['synk_google_sub']) && !hash_equals($account['synk_google_sub'], $account['google_sub']))
        || !synk_is_allowed_email_domain($account['email'], $domain)) {
        unset($_SESSION['hris_identity']);
        synk_auth_status_redirect('hris_access_denied', 'login.php');
    }
    if ((int)$account['account_id'] > 0 && !isset($identity['account_id'])) {
        $_SESSION['hris_identity'] = ['account_id' => (int)$account['account_id'], 'revision' => (int)$account['revision']] + $identity;
    }
    $account['active_role'] = $activeRole;
    return [$conn, $account];
}

function synk_hris_set_session_role(string $role): void
{
    $_SESSION['hris_identity']['active_role'] = $role;
    unset($_SESSION['hris_csrf'], $_SESSION['hris_account_flash'], $_SESSION['hris_employee_delete_review']);
}

function synk_hris_require_permission(string $permission, bool $json = false): array
{
    [$conn, $account] = synk_hris_require_account();
    if (!synk_hris_can($account, $permission)) {
        $message = 'This operation is not assigned to your Kawani account.';
        http_response_code(403);
        header('Content-Type: ' . ($json ? 'application/json' : 'text/plain') . '; charset=utf-8');
        exit($json ? json_encode(['message' => $message]) : $message);
    }
    return [$conn, $account];
}
