<?php
$adminViews = [
    'dashboard' => [
        'title' => 'Dashboard', 'description' => 'Faculty records at a glance', 'mobile_label' => 'Home',
        'icon' => 'bx-home-circle', 'tone' => 'green', 'url' => './',
    ],
    'faculty' => [
        'title' => 'Faculty Profiles', 'description' => 'Employee IDs and profile details', 'mobile_label' => 'Faculty',
        'icon' => 'bx-id-card', 'tone' => 'blue', 'url' => './?view=faculty',
    ],
    'pds' => [
        'title' => 'PDS Tracker', 'description' => 'Saved sections and follow-up', 'mobile_label' => 'PDS Tracker',
        'icon' => 'bx-file', 'tone' => 'amber', 'url' => './?view=pds',
    ],
    'accounts' => [
        'title' => 'Account Management', 'description' => 'Manage employee Kawani access', 'mobile_label' => 'Accounts',
        'icon' => 'bx-user-check', 'tone' => 'blue', 'url' => 'accounts.php',
    ],
];
$views = (($account['role_code'] ?? '') === SYNK_HRIS_USER) ? [
    'workspace' => [
        'title' => 'HR Workspace', 'description' => 'Your Kawani access', 'mobile_label' => 'Workspace',
        'icon' => 'bx-home-circle', 'tone' => 'green', 'url' => 'workspace.php',
    ],
] : $adminViews;
