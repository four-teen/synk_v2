<?php

function synk_hris_known_roles(array $roles): array
{
    return array_values(array_intersect(array_keys(synk_hris_role_labels()), array_filter($roles, 'is_string')));
}

function synk_hris_assigned_roles(array $account): array
{
    $primary = $account['role_code'] ?? null;
    if ($primary === SYNK_HRIS_ADMIN && ($account['secondary_role_code'] ?? null) === SYNK_HRIS_USER
        && ($account['user_permissions'] ?? null) !== null) {
        return [SYNK_HRIS_ADMIN, SYNK_HRIS_USER];
    }
    return isset(synk_hris_role_labels()[$primary ?? '']) ? [$primary] : [];
}

function synk_hris_active_role(array $account): ?string
{
    $role = $account['active_role'] ?? $account['role_code'] ?? null;
    return in_array($role, synk_hris_assigned_roles($account), true) ? $role : null;
}

function synk_hris_permission_options(): array
{
    return [
        'dashboard' => ['Dashboard', 'View HR totals and charts.'],
        'employee_profiles' => ['Employee Profiles', 'Find employees and review their profile and PDS.'],
        'employee_manage' => ['Edit employee records', 'Add employees and update HR status, personnel details, and work experience.'],
        'employee_delete' => ['Delete employees', 'Review and delete eligible employee records.'],
        'pds_tracker' => ['PDS Tracker', 'Check which PDS sections employees have completed.'],
        'position_catalog' => ['Position Catalog', 'Add, edit, and remove non-teaching positions.'],
        'accounts' => ['Account Management', 'Assign and manage eligible User accounts.'],
    ];
}

function synk_hris_known_permissions(array $permissions): array
{
    return array_values(array_intersect(array_keys(synk_hris_permission_options()), array_filter($permissions, 'is_string')));
}

function synk_hris_account_permissions(array $account): array
{
    $role = synk_hris_active_role($account);
    if ($role === SYNK_HRIS_ADMIN) return array_keys(synk_hris_permission_options());
    return $role === SYNK_HRIS_USER ? synk_hris_user_permissions($account) : [];
}

function synk_hris_user_permissions(array $account): array
{
    if (!in_array(SYNK_HRIS_USER, synk_hris_assigned_roles($account), true)) return [];
    $saved = json_decode((string)($account['user_permissions'] ?? ''));
    $permissions = is_array($saved) ? synk_hris_known_permissions($saved) : [];
    if (!in_array('employee_profiles', $permissions, true)) {
        $permissions = array_values(array_diff($permissions, ['employee_manage', 'employee_delete']));
    }
    return $permissions;
}

function synk_hris_can(array $account, string $permission): bool
{
    return in_array($permission, synk_hris_account_permissions($account), true);
}

function synk_hris_grantable_permissions(array $account): array
{
    $permissions = synk_hris_account_permissions($account);
    if (synk_hris_active_role($account) === SYNK_HRIS_ADMIN) return $permissions;
    return array_values(array_diff($permissions, ['accounts']));
}

function synk_hris_validate_user_permissions(array $requested): array
{
    $permissions = synk_hris_known_permissions($requested);
    if (count($requested) !== count($permissions)) {
        throw new DomainException('Choose from the available User permissions.');
    }
    if (!in_array('employee_profiles', $permissions, true)
        && array_intersect($permissions, ['employee_manage', 'employee_delete'])) {
        throw new DomainException('Select Employee Profiles to allow editing or deleting employee records.');
    }
    return $permissions;
}
