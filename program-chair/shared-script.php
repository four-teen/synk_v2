<?php
require_once __DIR__ . '/../backend/program_chair_portal_view.php';
$file = $_GET['file'] ?? '';
$files = ['faculty-workload.js', 'faculty-profile.js', 'faculty-profile-navigation.js', 'reports.js',
    'students.js', 'student-emails.js', 'student-management.js', 'view-term-settings.js', 'term-list-copy.js'];
if (!is_string($file) || !in_array($file, $files, true)) { http_response_code(404); exit; }
header('Content-Type: application/javascript; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
$directory = $file === 'term-list-copy.js' ? 'scheduler' : 'college-dean';
$source = file_get_contents(__DIR__ . '/../' . $directory . '/' . $file);
echo str_replace('../backend/query_scheduler_term_lists.php', '../backend/query_program_chair_term_lists.php', synk_program_chair_portal_endpoint_urls($source));
