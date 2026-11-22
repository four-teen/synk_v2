<?php
$adminViews = [
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
$hasManagementMenu = ($account['role_code'] ?? '') === SYNK_HRIS_ADMIN;
$views = $hasManagementMenu ? $adminViews : [
    'workspace' => [
        'title' => 'HR Workspace', 'description' => 'Your Kawani access', 'mobile_label' => 'Workspace',
        'icon' => 'bx-home-circle', 'tone' => 'green', 'url' => 'workspace.php',
    ],
];
