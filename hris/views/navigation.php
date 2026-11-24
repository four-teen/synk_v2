<?php
$views = [
    'dashboard' => [
        'title' => 'Dashboard', 'description' => 'Employee records at a glance', 'mobile_label' => 'Home',
        'icon' => 'bx-home-circle', 'tone' => 'green', 'url' => './',
    ],
    'faculty' => [
        'title' => 'Employee Profiles', 'description' => 'Teaching and non-teaching personnel', 'mobile_label' => 'Employees',
        'icon' => 'bx-id-card', 'tone' => 'blue', 'url' => './?view=faculty',
    ],
    'pds' => [
        'title' => 'PDS Tracker', 'description' => 'Section progress and follow-up', 'mobile_label' => 'PDS Tracker',
        'icon' => 'bx-file', 'tone' => 'amber', 'url' => './?view=pds',
    ],
];
$permissions = array_fill_keys(synk_hris_account_permissions($account), true);
$viewPermissions = ['dashboard' => 'dashboard', 'faculty' => 'employee_profiles', 'pds' => 'pds_tracker'];
foreach ($viewPermissions as $key => $permission) {
    if (!isset($permissions[$permission])) unset($views[$key]);
}
$canManageAccounts = isset($permissions['accounts']);
$canManagePositions = isset($permissions['position_catalog']);
$canManageEmployees = isset($permissions['employee_manage']);
$canDeleteEmployees = isset($permissions['employee_delete']);
