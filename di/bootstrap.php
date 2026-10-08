<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/leadership_portal_helper.php';
require_once __DIR__ . '/../backend/faculty_directory_helper.php';
$moduleRole = 'DI';
$moduleAccount = synk_leadership_portal_account($conn, $moduleRole);
$moduleTerm = synk_fetch_current_academic_term($conn);
header('Cache-Control: no-store, private');
synk_faculty_directory_ensure_schema($conn);
