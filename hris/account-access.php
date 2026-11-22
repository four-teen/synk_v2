<?php

require_once __DIR__ . '/faculty-data.php';

function synk_hris_account_directory(mysqli $conn): array
{
    $sources = synk_hris_faculty_sources($conn);
    $profiles = $sources['profiles'] ?? [];
    $pds = $sources['pds'] ?? [];
    $result = $conn->query("SELECT f.faculty_id, l.user_id, u.email,
        CONCAT_WS(' ', NULLIF(TRIM(f.first_name), ''), NULLIF(TRIM(f.middle_name), ''),
            NULLIF(TRIM(f.last_name), ''), NULLIF(TRIM(f.ext_name), '')) AS employee_name
        FROM tbl_faculty f LEFT JOIN tbl_useraccount_faculty_links l ON l.faculty_id = f.faculty_id
        LEFT JOIN tbl_useraccount u ON u.user_id = l.user_id
        ORDER BY f.last_name, f.first_name, f.faculty_id");
    $employees = [];
    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['faculty_id'];
        $owner = 'faculty:' . $id;
        $employees[$id] = [
            'faculty_id' => $id,
            'employee_name' => trim((string)$row['employee_name']) ?: 'Name not recorded',
            'employee_id' => trim((string)($pds[$owner]['employee_id'] ?? '')) ?: trim((string)($profiles[$owner]['faculty_id_number'] ?? '')),
            'email' => synk_normalize_email((string)$row['email']),
            'photo_user_id' => (int)$row['user_id'],
        ];
    }
    $result->close();

    $assigned = $byFaculty = [];
    $result = $conn->query('SELECT a.*, u.username, u.email AS synk_email, u.status AS synk_status
        FROM tbl_hr_employee_accounts a LEFT JOIN tbl_useraccount u ON u.user_id = a.synk_user_id ORDER BY a.email');
    while ($row = $result->fetch_assoc()) {
        $facultyId = (int)$row['faculty_id'];
        $id = (int)$row['account_id'];
        if ($row['role_code'] === null) {
            if (isset($employees[$facultyId])) $employees[$facultyId]['email'] = $row['email'];
            continue;
        }
        $employee = $employees[$facultyId] ?? [
            'employee_id' => '', 'employee_name' => trim((string)$row['username']) ?: $row['email'], 'photo_user_id' => (int)$row['synk_user_id'],
        ];
        $assigned[$id] = $row + $employee;
        if (($facultyId && !isset($employees[$facultyId])) || ($row['synk_user_id'] !== null
            && ($row['synk_status'] !== 'active' || synk_normalize_email((string)$row['synk_email']) !== $row['email']))) {
            $assigned[$id]['sign_in_status'] = 'Unavailable for sign-in';
        } elseif ($row['role_code'] !== 'conflict' && $row['google_sub'] === null) {
            $assigned[$id]['sign_in_status'] = 'Awaiting Google sign-in';
        }
        if ($facultyId) {
            $byFaculty[$facultyId] = $id;
            unset($employees[$facultyId]);
        }
    }
    $result->close();
    uasort($assigned, static function (array $left, array $right): int {
        return strnatcasecmp($left['employee_name'], $right['employee_name']) ?: strcmp($left['email'], $right['email']);
    });
    return [$employees, $assigned, $byFaculty];
}

// The caller verifies the administrator and owns the transaction.
function synk_hris_write_account(mysqli $conn, ?array $existing, ?int $facultyId, ?int $synkUserId,
    string $email, ?string $role, ?int $actorId): void
{
    if (!$existing) {
        $stmt = $conn->prepare('INSERT INTO tbl_hr_employee_accounts (faculty_id, synk_user_id, email, role_code, updated_by) VALUES (?, ?, ?, ?, ?)');
        $stmt->bind_param('iissi', $facultyId, $synkUserId, $email, $role, $actorId);
    } else {
        $subject = $email === $existing['email'] ? $existing['google_sub'] : null;
        $accountId = (int)$existing['account_id'];
        $stmt = $conn->prepare('UPDATE tbl_hr_employee_accounts
            SET faculty_id = ?, synk_user_id = ?, email = ?, google_sub = ?, role_code = ?,
                revision = revision + 1, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE account_id = ?');
        $stmt->bind_param('iisssii', $facultyId, $synkUserId, $email, $subject, $role, $actorId, $accountId);
    }
    try {
        $stmt->execute();
    } catch (mysqli_sql_exception $error) {
        if ((int)$error->getCode() === 1062) throw new DomainException('This employee or email already has a Kawani account. Review the existing assignment.');
        throw $error;
    } finally {
        $stmt->close();
    }
}

function synk_hris_manage_employee_account(mysqli $conn, array $actor, string $action, int $facultyId, int $accountId,
    int $revision, string $email, string $role, string $domain): void
{
    if ($action !== 'remove') {
        $email = synk_hris_official_email($email, $domain);
        if (!isset(synk_hris_role_labels()[$role])) throw new DomainException('Choose Administrator or User.');
    }
    // Lock administrators in the same order before changes so concurrent demotions cannot remove all access.
    $conn->begin_transaction();
    try {
        if ($action === 'assign') synk_hris_lock_employee_record($conn, $facultyId);
        $result = $conn->query("SELECT account_id, revision FROM tbl_hr_employee_accounts WHERE role_code = 'hris_admin' ORDER BY account_id FOR UPDATE");
        $administrators = array_column($result->fetch_all(MYSQLI_ASSOC), 'revision', 'account_id');
        $result->close();
        $actorAccountId = (int)$actor['account_id'];
        if ((int)($administrators[$actorAccountId] ?? 0) !== (int)$actor['revision'] || $actorAccountId <= 0) {
            throw new DomainException('Your Kawani administrator access changed. Sign in again.');
        }

        if ($action === 'assign') {
            $target = synk_hris_employee_account($conn, 'faculty_id', $facultyId, true);
            if (($target['role_code'] ?? null) !== null) throw new DomainException('This employee already has a role. Open their assigned account.');
            $emailAccount = synk_hris_employee_account($conn, 'email', $email, true);
            if (!$target && $emailAccount && $emailAccount['faculty_id'] === null) $target = $emailAccount;
        } else {
            $target = synk_hris_employee_account($conn, 'account_id', $accountId, true);
            if (!$target || $target['role_code'] === null || (int)$target['revision'] !== $revision) {
                throw new DomainException('This assignment changed. Reopen the employee’s account and try again.');
            }
            $facultyId = (int)$target['faculty_id'];
        }
        if ($target && (int)$target['account_id'] === $actorAccountId) {
            throw new DomainException('Another administrator must change your Kawani account.');
        }
        if ($action === 'remove') {
            synk_hris_write_account($conn, $target, $facultyId ?: null, $target['synk_user_id'] === null ? null : (int)$target['synk_user_id'],
                $target['email'], null, synk_hris_actor_id($actor));
        } else {
            if (($action === 'assign' && !$facultyId) || ($facultyId && !synk_hris_employee_identity($conn, $facultyId))) {
                throw new DomainException('Select an employee record that is available in Synk.');
            }
            $shared = synk_hris_matching_synk_account($conn, $email, $facultyId);
            synk_hris_write_account($conn, $target, $facultyId ?: null, $shared ? (int)$shared['user_id'] : null, $email, $role, synk_hris_actor_id($actor));
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
