<?php

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!defined('SYNK_PROGRAM_CHAIR_API')) define('SYNK_PROGRAM_CHAIR_API', true);
require_once __DIR__.'/db.php';
require_once __DIR__.'/college_dean_dashboard_helper.php';
require_once __DIR__.'/evaluation_report_forms.php';

header('Cache-Control: no-store, private');
header('Vary: Cookie');
if (!synk_college_dean_dashboard_scope($conn)) {
    http_response_code(403);
    exit;
}
session_write_close();

$form = $_GET['form'] ?? null;
$forms = synk_sef_report_forms();
if (!is_string($form) || !isset($forms[$form])) {
    http_response_code(404);
    exit;
}

$filename = strtoupper($forms[$form]['title']).'.docx';
$path = __DIR__.'/templates/evaluation-reports/'.$filename;
if (!is_readable($path)) {
    http_response_code(404);
    exit;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Content-Length: '.filesize($path));
header('X-Content-Type-Options: nosniff');
readfile($path);
