<?php

function synk_hris_employee_accounts(mysqli $conn, string $domain): array
{
    $result = $conn->query("SELECT l.faculty_id, l.user_id, u.email, u.status AS account_status,
        CONCAT_WS(' ', NULLIF(TRIM(f.first_name), ''), NULLIF(TRIM(f.middle_name), ''),
            NULLIF(TRIM(f.last_name), ''), NULLIF(TRIM(f.ext_name), '')) AS faculty_name,
        CASE WHEN r.role_count = 1 THEN r.role_code WHEN r.role_count > 1 THEN 'conflict' ELSE '' END AS role_state
        FROM tbl_useraccount_faculty_links l
        JOIN tbl_faculty f ON f.faculty_id = l.faculty_id
        JOIN tbl_useraccount u ON u.user_id = l.user_id
        LEFT JOIN (
            SELECT user_id, COUNT(*) AS role_count, MIN(role_code) AS role_code
            FROM tbl_hr_user_roles
            WHERE role_code IN ('hris_admin', 'hris_user') AND status = 'active'
            GROUP BY user_id
        ) r ON r.user_id = u.user_id
        WHERE u.status = 'active' OR r.role_count > 0
        ORDER BY f.last_name, f.first_name, l.faculty_id");

    $accounts = [];
    while ($row = $result->fetch_assoc()) {
        $row['email'] = synk_normalize_email((string)$row['email']);
        $row['eligible'] = $row['account_status'] === 'active'
            && filter_var($row['email'], FILTER_VALIDATE_EMAIL) !== false
            && synk_is_allowed_email_domain($row['email'], $domain);
        if (!$row['eligible'] && $row['role_state'] === '') continue;
        $row['faculty_name'] = trim((string)$row['faculty_name']) ?: 'Name not recorded';
        $row['faculty_id'] = (int)$row['faculty_id'];
        $row['user_id'] = (int)$row['user_id'];
        $accounts[] = $row;
    }
    $result->close();
    return $accounts;
}

function synk_hris_employee_id_map(mysqli $conn): array
{
    require_once __DIR__ . '/../backend/schema_helper.php';
    $ids = [];

    if (synk_table_exists($conn, 'tbl_faculty_profiles')
        && synk_table_has_column($conn, 'tbl_faculty_profiles', 'faculty_id_number')) {
        $result = $conn->query("SELECT l.faculty_id, TRIM(p.faculty_id_number) AS employee_id
            FROM tbl_faculty_profiles p JOIN tbl_useraccount_faculty_links l ON l.user_id = p.user_id
            WHERE TRIM(p.faculty_id_number) <> ''");
        while ($row = $result->fetch_assoc()) {
            $ids[(int)$row['faculty_id']] = trim((string)$row['employee_id']);
        }
        $result->close();
    }

    if (synk_table_exists($conn, 'tbl_hr_faculty_profiles')
        && synk_table_exists($conn, 'tbl_hr_faculty_pds_contact')
        && synk_table_has_column($conn, 'tbl_hr_faculty_pds_contact', 'agency_employee_number')) {
        $result = $conn->query("SELECT COALESCE(h.faculty_id, l.faculty_id) AS faculty_id,
                TRIM(c.agency_employee_number) AS employee_id
            FROM tbl_hr_faculty_profiles h
            JOIN tbl_hr_faculty_pds_contact c ON c.hr_profile_id = h.hr_profile_id
            LEFT JOIN tbl_useraccount_faculty_links l ON l.user_id = h.account_user_id
            WHERE COALESCE(h.faculty_id, l.faculty_id) IS NOT NULL AND TRIM(c.agency_employee_number) <> ''
            ORDER BY h.hr_profile_id ASC");
        while ($row = $result->fetch_assoc()) {
            $facultyId = (int)$row['faculty_id'];
            $ids[$facultyId] = trim((string)$row['employee_id']);
        }
        $result->close();
    }

    return $ids;
}

function synk_hris_save_role(mysqli $conn, int $userId, string $role): void
{
    if (!isset(synk_hris_role_labels()[$role])) {
        throw new InvalidArgumentException('Choose Administrator or User.');
    }

    $stmt = $conn->prepare("UPDATE tbl_hr_user_roles SET status = 'inactive' WHERE user_id = ?
        AND role_code IN ('hris_admin', 'hris_user') AND role_code <> ? AND status = 'active'");
    $stmt->bind_param('is', $userId, $role);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO tbl_hr_user_roles (user_id, role_code, status) VALUES (?, ?, 'active')
        ON DUPLICATE KEY UPDATE granted_at = IF(status = 'inactive', CURRENT_TIMESTAMP, granted_at), status = 'active'");
    $stmt->bind_param('is', $userId, $role);
    $stmt->execute();
    $stmt->close();
}

function synk_hris_locked_role_state(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare("SELECT role_code FROM tbl_hr_user_roles
        WHERE user_id = ? AND role_code IN ('hris_admin', 'hris_user') AND status = 'active'
        ORDER BY role_code FOR UPDATE");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $roles = [];
    while ($row = $result->fetch_assoc()) {
        $roles[] = (string)$row['role_code'];
    }
    $stmt->close();
    return $roles;
}

// Caller owns the transaction. The existing Synk employee link determines the role owner.
function synk_hris_prepare_employee_role_change(mysqli $conn, array $actor, int $facultyId, int $userId,
    string $previousRole, string $domain, bool $requiresEligibleAccount = true): array
{
    $actorId = (int)($actor['user_id'] ?? 0);
    if ($actorId === $userId) {
        throw new DomainException('For your security, another administrator must change your role.');
    }
    if ($facultyId <= 0 || $userId <= 0
        || !in_array($previousRole, ['', SYNK_HRIS_ADMIN, SYNK_HRIS_USER, 'conflict'], true)) {
        throw new DomainException('Select an eligible faculty member.');
    }

    // Lock account rows in the same order so simultaneous administrator updates do not deadlock on them.
    $ids = [$actorId, $userId];
    sort($ids, SORT_NUMERIC);
    $stmt = $conn->prepare('SELECT status, email, google_sub FROM tbl_useraccount WHERE user_id = ? FOR UPDATE');
    $locked = [];
    foreach ($ids as $id) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $locked[$id] = $stmt->get_result()->fetch_assoc() ?: [];
    }
    $stmt->close();

    $roles = [];
    foreach ($ids as $id) {
        $roles[$id] = synk_hris_locked_role_state($conn, $id);
    }
    $actorRoleState = count($roles[$actorId]) === 1 ? $roles[$actorId][0] : (count($roles[$actorId]) > 1 ? 'conflict' : '');
    $targetRoleState = count($roles[$userId]) === 1 ? $roles[$userId][0] : (count($roles[$userId]) > 1 ? 'conflict' : '');

    if ($actorRoleState !== SYNK_HRIS_ADMIN || ($locked[$actorId]['status'] ?? '') !== 'active'
        || !hash_equals((string)($locked[$actorId]['google_sub'] ?? ''), (string)($actor['google_sub'] ?? ''))
        || !synk_is_allowed_email_domain((string)($locked[$actorId]['email'] ?? ''), $domain)) {
        throw new DomainException('Your Administrator access changed. Reopen Account Management.');
    }

    $stmt = $conn->prepare('SELECT user_id FROM tbl_useraccount_faculty_links WHERE faculty_id = ? FOR UPDATE');
    $stmt->bind_param('i', $facultyId);
    $stmt->execute();
    $linkedUserId = (int)($stmt->get_result()->fetch_assoc()['user_id'] ?? 0);
    $stmt->close();
    if ($linkedUserId !== $userId || !$locked[$userId]) {
        throw new DomainException('The employee’s linked university account changed or is unavailable. Select the employee again.');
    }
    $targetEmail = synk_normalize_email((string)$locked[$userId]['email']);
    if ($requiresEligibleAccount && ($locked[$userId]['status'] !== 'active'
        || !filter_var($targetEmail, FILTER_VALIDATE_EMAIL) || !synk_is_allowed_email_domain($targetEmail, $domain))) {
        throw new DomainException('An active university account is required to assign a Kawani role.');
    }
    if ($targetRoleState !== $previousRole) {
        throw new DomainException('The employee’s Kawani role changed. Review the current assignment and try again.');
    }

    return $roles[$userId];
}

function synk_hris_protect_last_admin(mysqli $conn, int $targetUserId, array $targetRoles, string $domain): void
{
    if (count($targetRoles) !== 1 || $targetRoles[0] !== SYNK_HRIS_ADMIN) return;

    $stmt = $conn->prepare("SELECT u.email FROM tbl_hr_user_roles r
        JOIN tbl_useraccount u ON u.user_id = r.user_id
        WHERE r.user_id <> ? AND r.role_code = 'hris_admin' AND r.status = 'active' AND u.status = 'active'
            AND (SELECT COUNT(*) FROM tbl_hr_user_roles role_check
                WHERE role_check.user_id = r.user_id
                    AND role_check.role_code IN ('hris_admin', 'hris_user') AND role_check.status = 'active') = 1
        ORDER BY r.user_id FOR UPDATE");
    $stmt->bind_param('i', $targetUserId);
    $stmt->execute();
    $result = $stmt->get_result();
    $hasBackupAdmin = false;
    while ($row = $result->fetch_assoc()) {
        $email = synk_normalize_email((string)$row['email']);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) && synk_is_allowed_email_domain($email, $domain)) {
            $hasBackupAdmin = true;
            break;
        }
    }
    $stmt->close();
    if (!$hasBackupAdmin) {
        throw new DomainException('At least one Administrator must remain assigned to Kawani. Assign another Administrator before removing or changing this role.');
    }
}

function synk_hris_assign_employee_role(mysqli $conn, array $actor, int $facultyId, int $userId, string $role,
    string $previousRole, string $domain): void
{
    if (!isset(synk_hris_role_labels()[$role])) {
        throw new DomainException('Choose Administrator or User.');
    }
    $targetRoles = synk_hris_prepare_employee_role_change($conn, $actor, $facultyId, $userId, $previousRole, $domain);
    if ($role !== SYNK_HRIS_ADMIN) synk_hris_protect_last_admin($conn, $userId, $targetRoles, $domain);

    synk_hris_save_role($conn, $userId, $role);
}

function synk_hris_remove_employee_role(mysqli $conn, array $actor, int $facultyId, int $userId,
    string $previousRole, string $domain): void
{
    $targetRoles = synk_hris_prepare_employee_role_change($conn, $actor, $facultyId, $userId, $previousRole, $domain, false);
    if (!$targetRoles) {
        throw new DomainException('This faculty member does not have an active Kawani role.');
    }
    synk_hris_protect_last_admin($conn, $userId, $targetRoles, $domain);

    $stmt = $conn->prepare("UPDATE tbl_hr_user_roles SET status = 'inactive'
        WHERE user_id = ? AND role_code IN ('hris_admin', 'hris_user') AND status = 'active'");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}
