<?php

require_once __DIR__ . '/faculty-data.php';

function synk_hris_can_manage_assignment(array $actor, array $target): bool
{
    return synk_hris_active_role($actor) === SYNK_HRIS_ADMIN
        || (int)$actor['account_id'] === (int)$target['account_id']
        || (synk_hris_assigned_roles($target) === [SYNK_HRIS_USER]
            && !array_diff(synk_hris_user_permissions($target), synk_hris_grantable_permissions($actor)));
}

function synk_hris_account_directory(mysqli $conn, array $actor): array
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
        $missingFaculty = $facultyId && !isset($employees[$facultyId]);
        unset($employees[$facultyId]);
        if (!synk_hris_can_manage_assignment($actor, $row)) continue;
        $assigned[$id] = $row + $employee;
        if ($missingFaculty || ($row['synk_user_id'] !== null
            && ($row['synk_status'] !== 'active' || synk_normalize_email((string)$row['synk_email']) !== $row['email']))) {
            $assigned[$id]['sign_in_status'] = 'Unavailable for sign-in';
        } elseif ($row['role_code'] !== 'conflict' && $row['google_sub'] === null) {
            $assigned[$id]['sign_in_status'] = 'Awaiting Google sign-in';
        }
        if ($facultyId) {
            $byFaculty[$facultyId] = $id;
        }
    }
    $result->close();
    uasort($assigned, static function (array $left, array $right): int {
        return strnatcasecmp($left['employee_name'], $right['employee_name']) ?: strcmp($left['email'], $right['email']);
    });
    return [$employees, $assigned, $byFaculty];
}

// The caller verifies the assignment and owns the transaction.
function synk_hris_write_account(mysqli $conn, ?array $existing, ?int $facultyId, ?int $synkUserId,
    string $email, ?string $role, ?int $actorId, array $permissions = [], bool $alsoUser = false): void
{
    $permissionsReady = synk_hris_account_permissions_ready($conn);
    $secondaryRoleReady = synk_hris_secondary_role_ready($conn);
    $grantsUser = $role === SYNK_HRIS_USER || $alsoUser;
    if (($grantsUser && !$permissionsReady) || ($alsoUser && !$secondaryRoleReady)) {
        throw new DomainException('Install the Kawani account update before assigning roles.');
    }
    if ($alsoUser && $role !== SYNK_HRIS_ADMIN) throw new InvalidArgumentException('Administrator must be the primary role for a dual assignment.');

    $sql = ($existing ? 'UPDATE tbl_hr_employee_accounts' : 'INSERT INTO tbl_hr_employee_accounts')
        . ' SET faculty_id = ?, synk_user_id = ?, email = ?, role_code = ?, updated_by = ?';
    $types = 'iissi';
    $params = [$facultyId, $synkUserId, $email, $role, $actorId];
    if ($permissionsReady) {
        $sql .= ', user_permissions = ?';
        $types .= 's';
        $params[] = $grantsUser ? json_encode($permissions) : null;
    }
    if ($secondaryRoleReady) {
        $sql .= ', secondary_role_code = ?';
        $types .= 's';
        $params[] = $alsoUser ? SYNK_HRIS_USER : null;
    }
    if ($existing) {
        $sql .= ', google_sub = ?, revision = revision + 1, updated_at = CURRENT_TIMESTAMP WHERE account_id = ?';
        $types .= 'si';
        $params[] = $email === $existing['email'] ? $existing['google_sub'] : null;
        $params[] = (int)$existing['account_id'];
    }

    $stmt = $conn->prepare($sql);
    try {
        synk_bind_dynamic_params($stmt, $types, $params);
        $stmt->execute();
    } catch (mysqli_sql_exception $error) {
        if ((int)$error->getCode() === 1062) throw new DomainException('This employee or email already has a Kawani account. Review the existing assignment.');
        throw $error;
    } finally {
        $stmt->close();
    }
}

function synk_hris_other_verified_administrator(mysqli $conn, array $lockedAdministrators, int $accountId, string $domain): bool
{
    foreach ($lockedAdministrators as $candidate) {
        if ((int)$candidate['account_id'] === $accountId || empty($candidate['google_sub'])) continue;
        $verified = synk_hris_account_access($conn, $candidate, $domain);
        if ($verified && ($verified['synk_google_sub'] === '' || hash_equals($verified['synk_google_sub'], $verified['google_sub']))) {
            return true;
        }
    }
    return false;
}

function synk_hris_manage_employee_account(mysqli $conn, array $actor, string $action, int $facultyId, int $accountId,
    int $revision, string $email, array $requestedRoles, array $permissions, string $domain): ?array
{
    $roles = [];
    if ($action !== 'remove') {
        $email = synk_hris_official_email($email, $domain);
        $roles = synk_hris_known_roles($requestedRoles);
        if (!$roles || count($roles) !== count($requestedRoles)) throw new DomainException('Select Administrator, User, or both.');
        $permissions = in_array(SYNK_HRIS_USER, $roles, true) ? synk_hris_validate_user_permissions($permissions) : [];
    }
    // Lock administrators in the same order before changes so concurrent demotions cannot remove all access.
    $conn->begin_transaction();
    try {
        if ($action === 'assign') synk_hris_lock_employee_record($conn, $facultyId);
        $result = $conn->query("SELECT * FROM tbl_hr_employee_accounts WHERE role_code = 'hris_admin' ORDER BY account_id FOR UPDATE");
        $lockedAdministrators = $result->fetch_all(MYSQLI_ASSOC);
        $result->close();
        $actorAccountId = (int)$actor['account_id'];
        $currentActor = synk_hris_employee_account($conn, 'account_id', $actorAccountId, true);
        $activeRole = synk_hris_active_role($actor);
        if ($currentActor) $currentActor['active_role'] = $activeRole;
        if (!$currentActor || $activeRole === null || (int)$currentActor['revision'] !== (int)$actor['revision']
            || !synk_hris_can($currentActor, 'accounts')) {
            throw new DomainException('Your Kawani account access changed. Sign in again.', 403);
        }

        if ($action === 'assign') {
            $target = synk_hris_employee_account($conn, 'faculty_id', $facultyId, true);
            if (($target['role_code'] ?? null) !== null) throw new DomainException('This employee already has a role. Open their assigned account.');
            $emailAccount = synk_hris_employee_account($conn, 'email', $email, true);
            if (!$target && $emailAccount && $emailAccount['faculty_id'] === null && $emailAccount['role_code'] === null) {
                $target = $emailAccount;
            }
        } else {
            $target = synk_hris_employee_account($conn, 'account_id', $accountId, true);
            if (!$target || $target['role_code'] === null || (int)$target['revision'] !== $revision) {
                throw new DomainException('This assignment changed. Reopen the employee’s account and try again.');
            }
            $facultyId = (int)$target['faculty_id'];
        }
        $isOwnAccount = $target && (int)$target['account_id'] === $actorAccountId;
        if ($isOwnAccount && ($action !== 'edit' || $email !== $target['email'])) {
            throw new DomainException('Another administrator manages changes to your sign-in email or account removal.', 403);
        }
        if ($action !== 'assign' && !synk_hris_can_manage_assignment($currentActor, $target)) {
            throw new DomainException('This Kawani account is outside your assignment access.', 403);
        }
        if ($action !== 'remove' && $currentActor['active_role'] !== SYNK_HRIS_ADMIN) {
            $allowedRoles = $isOwnAccount ? synk_hris_assigned_roles($currentActor) : [SYNK_HRIS_USER];
            $allowedPermissions = $isOwnAccount ? synk_hris_user_permissions($currentActor) : synk_hris_grantable_permissions($currentActor);
            if ($roles !== $allowedRoles || array_diff($permissions, $allowedPermissions)) {
                throw new DomainException('This Kawani role or permission is outside your assignment access.', 403);
            }
        }
        if (($target['role_code'] ?? null) === SYNK_HRIS_ADMIN && !in_array(SYNK_HRIS_ADMIN, $roles, true)
            && !synk_hris_other_verified_administrator($conn, $lockedAdministrators, (int)$target['account_id'], $domain)) {
            throw new DomainException('Kawani needs one verified Administrator. Keep Administrator selected until another Administrator signs in.');
        }
        if ($action === 'remove') {
            synk_hris_write_account($conn, $target, $facultyId ?: null, $target['synk_user_id'] === null ? null : (int)$target['synk_user_id'],
                $target['email'], null, synk_hris_actor_id($actor));
        } else {
            if (($action === 'assign' && !$facultyId) || ($facultyId && !synk_hris_employee_identity($conn, $facultyId))) {
                throw new DomainException('Select an employee record that is available in Synk.');
            }
            $shared = $isOwnAccount ? null : synk_hris_matching_synk_account($conn, $email, $facultyId);
            $synkUserId = $isOwnAccount ? $target['synk_user_id'] : ($shared['user_id'] ?? null);
            synk_hris_write_account($conn, $target, $facultyId ?: null, $synkUserId === null ? null : (int)$synkUserId,
                $email, $roles[0], synk_hris_actor_id($actor), $permissions, count($roles) === 2);
        }
        $saved = null;
        if ($isOwnAccount) {
            $saved = synk_hris_employee_account($conn, 'account_id', $actorAccountId);
            if (!$saved) throw new RuntimeException('The updated Kawani account could not be read.');
            $saved['active_role'] = in_array($currentActor['active_role'], $roles, true) ? $currentActor['active_role'] : $roles[0];
        }
        $conn->commit();
        return $saved;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
