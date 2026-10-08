<?php

require_once __DIR__ . '/auth_useraccount.php';
require_once __DIR__ . '/academic_term_helper.php';

function synk_leadership_portal_account(mysqli $conn, string $expectedRole): array
{
    if (!in_array($expectedRole, ['VPAA', 'DI'], true)) {
        throw new InvalidArgumentException('Unknown leadership module.');
    }

    if (!isset($_SESSION['user_id']) || synk_normalize_role_code((string)($_SESSION['role'] ?? '')) !== $expectedRole) {
        header('Location: ../index.php');
        exit;
    }

    $account = synk_find_useraccount_by_id($conn, (int)$_SESSION['user_id']);
    $roleRows = $account ? synk_fetch_useraccount_role_rows($conn, (int)$account['user_id'], (string)$account['role']) : [];
    $roles = array_column($roleRows, 'role');
    if (!$account || ($account['status'] ?? '') !== 'active' || !in_array($expectedRole, $roles, true)
        || !synk_useraccount_has_active_role($conn, $account, $expectedRole)) {
        synk_clear_pending_role_login();
        synk_reset_authenticated_session_context();
        header('Location: ../index.php?auth_status=account_not_allowed');
        exit;
    }

    $_SESSION['role'] = $expectedRole;
    $_SESSION['available_roles'] = array_column(synk_filter_loginable_role_rows($conn, $account, $roleRows), 'role');
    $_SESSION['primary_role'] = synk_useraccount_primary_role($roleRows, (string)$account['role']);

    $account['role_rows'] = $roleRows;
    $account['primary_role'] = $_SESSION['primary_role'];
    return $account;
}

function synk_leadership_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
