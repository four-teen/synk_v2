<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD']!=='POST') {
    header('Allow: POST'); http_response_code(405);
    echo json_encode(['ok'=>false,'message'=>'Use the section Save button.']); exit;
}
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '')!=='professor') {
    http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Sign in to your professor account before saving.']); exit;
}
$multipart=stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''),'multipart/form-data')===0;
if (!$multipart && stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''),'application/json')!==0) {
    http_response_code(415); echo json_encode(['ok'=>false,'message'=>'Send a JSON or multipart section request.']); exit;
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0)>($multipart?7340032:2097152)) {
    http_response_code(413); echo json_encode(['ok'=>false,'message'=>'Save up to 6 MB of attachments at a time. Your entries have not been saved.']); exit;
}
$input=$multipart?($_POST['payload'] ?? null):file_get_contents('php://input',false,null,0,2097153);
if (!is_string($input) || strlen($input)>2097152 || ($multipart && array_diff(array_keys($_POST),['payload']))) {
    http_response_code(413); echo json_encode(['ok'=>false,'message'=>'This section is too large to save.']); exit;
}
$payload=json_decode($input,true,16);
if (json_last_error()!==JSON_ERROR_NONE || !is_array($payload)) {
    http_response_code(400); echo json_encode(['ok'=>false,'message'=>'The section could not be read. Reload before saving.']); exit;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/hr_faculty_pds_controller.php';
$response=synk_hr_pds_handle_save($conn,$_SESSION,$payload,$multipart?$_FILES:[]);
http_response_code($response['status']);
// The workstation's web runtime is PHP 7.1; the substitution flag starts at 7.2.
// PDS input is already validated as UTF-8 before it reaches persistence.
$jsonOptions = JSON_UNESCAPED_UNICODE;
if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) $jsonOptions |= constant('JSON_INVALID_UTF8_SUBSTITUTE');
echo json_encode($response['body'],$jsonOptions);
