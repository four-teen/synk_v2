<?php

require_once __DIR__ . '/program_chair_helper.php';
require_once __DIR__ . '/enrollment_draft_helper.php';

function synk_college_dean_enrollment_enabled(): bool
{
    return false;
}

function synk_college_dean_store_session_scope(array $account): void
{
    $_SESSION['college_id'] = (int)($account['college_dean_college_id'] ?? 0);
    $_SESSION['college_name'] = (string)($account['college_dean_college_name'] ?? '');
}

function synk_college_dean_require_login(mysqli $conn): void
{
    if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'college_dean') {
        $redirectPath = synk_role_redirect_path((string)($_SESSION['role'] ?? ''));
        header('Location: ../' . ($redirectPath ?? 'index.php'));
        exit;
    }

    $account = synk_find_useraccount_by_id($conn, (int)$_SESSION['user_id']);
    if (!$account || !synk_college_dean_account_has_access($conn, $account)) {
        synk_clear_pending_role_login();
        synk_reset_authenticated_session_context();
        header('Location: ../index.php?auth_status=account_not_allowed');
        exit;
    }

    // Refresh the assignment on every request, including after an administrator edits it.
    synk_college_dean_store_session_scope($account);
    $_SESSION['username'] = (string)$account['username'];
    $_SESSION['email'] = (string)$account['email'];
    $roleRows = synk_fetch_useraccount_role_rows($conn, (int)$account['user_id'], (string)$account['role']);
    $_SESSION['available_roles'] = array_column(synk_filter_loginable_role_rows($conn, $account, $roleRows), 'role');
    $_SESSION['primary_role'] = synk_useraccount_primary_role($roleRows, (string)$account['role']);
}

function synk_college_dean_portal_context(mysqli $conn): array
{
    // Programs, terms, and college details use the same proven queries as Program Chair.
    $context = synk_program_chair_portal_context($conn);
    $context['account_name'] = trim((string)($_SESSION['username'] ?? '')) ?: 'College Dean';
    foreach ($context['role_badges'] as &$badge) {
        $badge['is_active'] = synk_normalize_role_code((string)$badge['role']) === 'college_dean';
    }
    unset($badge);
    return $context;
}

function synk_college_dean_fetch_draft_rows(mysqli $conn, int $userId, int $collegeId): array
{
    $rows = synk_enrollment_fetch_program_chair_draft_rows($conn, $userId, $collegeId);
    foreach ($rows as &$row) {
        if (($row['workflow_status'] ?? '') === 'returned') {
            $row['status_label'] = 'Returned to College Dean';
        }
    }
    unset($row);
    return $rows;
}

function synk_college_dean_fetch_draft_detail(mysqli $conn, int $enrollmentId, int $userId, int $collegeId, bool $editableOnly = false): ?array
{
    $detail = synk_enrollment_fetch_program_chair_draft_detail($conn, $enrollmentId, $userId, $collegeId, $editableOnly);
    if ($detail && ($detail['workflow_status'] ?? '') === 'returned') {
        $detail['status_label'] = 'Returned to College Dean';
    }
    return $detail;
}
