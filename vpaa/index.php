<?php
session_start();
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/leadership_portal_helper.php';

$moduleRole = 'VPAA';
$moduleIcon = 'bx-briefcase-alt-2';
$moduleAccount = synk_leadership_portal_account($conn, $moduleRole);
$moduleTerm = synk_fetch_current_academic_term($conn);
require __DIR__ . '/../backend/leadership_dashboard_view.php';
