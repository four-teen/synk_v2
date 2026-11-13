<?php
// Included only by the two portal-specific routes.
if (!isset($announcementStudentRoute)) { http_response_code(404); exit; }
ini_set('display_errors','0');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); throw new DomainException('Use the announcement workspace.',405); }
    if (($_SESSION['role'] ?? '') !== ($announcementStudentRoute ? 'student' : 'professor') || empty($_SESSION['user_id'])) throw new DomainException('Sign in again to continue.',401);
    $raw = file_get_contents('php://input',false,null,0,100001);
    if ($raw === false || strlen($raw)>100000) throw new DomainException('The announcement request is too large.',413);
    $input = json_decode($raw,true);
    if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) throw new DomainException('Enter a valid announcement request.',400);
    require_once __DIR__.'/db.php';
    require_once __DIR__.'/announcement_api_helper_lms.php';
    echo json_encode(['ok'=>true]+synk_announcement_request_lms($conn,$input,$announcementStudentRoute),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG);
} catch (Throwable $error) {
    $known = $error instanceof DomainException && in_array($error->getCode(),[400,401,403,404,405,409,413,422,503],true);
    http_response_code($known ? $error->getCode() : 503);
    if (!$known) error_log('LMS announcement request: '.$error->getMessage());
    echo json_encode(['ok'=>false,'message'=>$known ? $error->getMessage() : 'Announcements could not be updated. Please try again.']);
}
