<?php

require_once __DIR__ . '/../backend/schema_helper.php';

function synk_hris_employee_accounts_ready(mysqli $conn): bool
{
    return synk_table_exists($conn, 'tbl_hr_employee_accounts');
}

function synk_hris_account_permissions_ready(mysqli $conn): bool
{
    return synk_table_has_column($conn, 'tbl_hr_employee_accounts', 'user_permissions');
}

function synk_hris_secondary_role_ready(mysqli $conn): bool
{
    return synk_table_has_column($conn, 'tbl_hr_employee_accounts', 'secondary_role_code');
}

function synk_hris_official_email($value, string $domain, bool $optional = false): string
{
    $email = is_string($value) ? synk_normalize_email($value) : '';
    if ($optional && is_string($value) && $email === '') return '';
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !synk_is_allowed_email_domain($email, $domain)) {
        throw new DomainException('Enter a valid ' . $domain . ' Google email address.');
    }
    return $email;
}

function synk_hris_employee_account(mysqli $conn, string $column, $value, bool $lock = false): ?array
{
    if (!in_array($column, ['account_id', 'faculty_id', 'synk_user_id', 'legacy_user_id', 'email'], true)) {
        throw new InvalidArgumentException('Invalid Kawani account lookup.');
    }
    $stmt = $conn->prepare('SELECT * FROM tbl_hr_employee_accounts WHERE ' . $column . ' = ?' . ($lock ? ' FOR UPDATE' : ''));
    try {
        $stmt->bind_param($column === 'email' ? 's' : 'i', $value);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    } finally {
        $stmt->close();
    }
}

// Shared identities and faculty links can confirm ownership. Kawani writes its own approval and Google binding.
function synk_hris_matching_synk_account(mysqli $conn, string $email, int $facultyId = 0): ?array
{
    $stmt = $conn->prepare('SELECT user_id, username, email, status, google_sub FROM tbl_useraccount WHERE LOWER(TRIM(email)) = ? LIMIT 2');
    try {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally {
        $stmt->close();
    }
    if (count($matches) > 1) throw new DomainException('This email matches more than one Synk account. Verify the official address.');
    if (!$matches) return null;
    $shared = $matches[0];
    if ($shared['status'] !== 'active') throw new DomainException('The matching Synk account is inactive.');

    if ($facultyId > 0 && synk_table_exists($conn, 'tbl_useraccount_faculty_links')) {
        $userId = (int)$shared['user_id'];
        $stmt = $conn->prepare('SELECT faculty_id FROM tbl_useraccount_faculty_links WHERE user_id = ?');
        try {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $owner = $stmt->get_result()->fetch_assoc();
        } finally {
            $stmt->close();
        }
        if ($owner && (int)$owner['faculty_id'] !== $facultyId) {
            throw new DomainException('This email belongs to another employee in Synk. Verify the employee and official address.');
        }
    }
    return $shared;
}

function synk_hris_employee_identity(mysqli $conn, int $facultyId): ?array
{
    if (!$facultyId) return null;
    $stmt = $conn->prepare('SELECT first_name, middle_name, last_name, ext_name FROM tbl_faculty WHERE faculty_id = ?');
    try {
        $stmt->bind_param('i', $facultyId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    } finally {
        $stmt->close();
    }
}

function synk_hris_record_employee_email(mysqli $conn, int $facultyId, string $email, int $actorId): void
{
    if ($email === '') return;

    // Registration records the address. Opening Kawani still requires an explicit role assignment.
    $stmt = $conn->prepare('INSERT INTO tbl_hr_employee_accounts (faculty_id, email, updated_by) VALUES (?, ?, ?)');
    try {
        $stmt->bind_param('isi', $facultyId, $email, $actorId);
        $stmt->execute();
    } catch (mysqli_sql_exception $error) {
        if ((int)$error->getCode() === 1062) throw new DomainException('This official email is already recorded in Kawani.', 422);
        throw $error;
    } finally {
        $stmt->close();
    }
}
