<?php

require_once __DIR__ . '/auth_useraccount.php';

function synk_account_switch_context(mysqli $conn): ?array
{
    $role = synk_normalize_role_code((string)($_SESSION['role'] ?? ''));
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0 || !in_array($role, synk_supported_module_roles(), true)) {
        return null;
    }

    $account = synk_find_useraccount_by_id($conn, $userId);
    if (!$account || ($account['status'] ?? '') !== 'active') {
        return null;
    }

    $roleRows = synk_fetch_useraccount_role_rows($conn, $userId, (string)$account['role']);
    if (($_SESSION['auth_method'] ?? '') !== 'google') {
        $roleRows = array_values(array_filter($roleRows, static function (array $row): bool { return $row['role'] !== 'management'; }));
    }
    return [
        'account' => $account,
        'role_rows' => synk_filter_loginable_role_rows($conn, $account, $roleRows),
    ];
}

function synk_account_switch_token(): string
{
    if (empty($_SESSION['account_switch_token'])) {
        $_SESSION['account_switch_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['account_switch_token'];
}

function synk_render_account_switch(mysqli $conn, string $action = '../switch-account.php', bool $toolbar = false): void
{
    $context = synk_account_switch_context($conn);
    if (!$context || count($context['role_rows']) < 2) {
        return;
    }

    $action = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
    $token = htmlspecialchars(synk_account_switch_token(), ENT_QUOTES, 'UTF-8');
    $buttonClass = $toolbar ? 'btn btn-outline-primary btn-sm' : 'dropdown-item';

    if (!$toolbar) echo '<li>';
    echo '<form method="post" action="', $action, '" class="m-0">',
        '<input type="hidden" name="account_switch_token" value="', $token, '">',
        '<button type="submit" class="', $buttonClass, '">',
        '<i class="bx bx-transfer-alt me-2"></i>',
        '<span class="align-middle">Switch Account</span>',
        '</button></form>';
    if (!$toolbar) echo '</li>';
}
