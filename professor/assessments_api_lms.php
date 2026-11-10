<?php
ini_set('display_errors', '0');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST'); throw new DomainException('Use the assessment workspace to make this request.', 405);
    }
    if (($_SESSION['role'] ?? '') !== 'professor' || empty($_SESSION['user_id'])) throw new DomainException('Sign in as a professor to continue.', 401);
    $raw = file_get_contents('php://input', false, null, 0, 1048577);
    if ($raw === false || strlen($raw) > 1048576) throw new DomainException('The assessment request is too large.', 413);
    $input = json_decode($raw, true);
    if (!is_array($input) || json_last_error() !== JSON_ERROR_NONE) throw new DomainException('Enter a valid assessment request.', 400);
    require_once __DIR__ . '/../backend/db.php';
    require_once __DIR__ . '/../backend/assessment_api_helper_lms.php';
    $data = synk_assessment_request_lms($conn, $input);
    echo json_encode(['ok'=>true] + $data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
} catch (Throwable $error) {
    $known = $error instanceof DomainException && in_array($error->getCode(), [400,401,403,404,405,409,413,422,503], true);
    http_response_code($known ? $error->getCode() : 503);
    if (!$known) error_log('LMS assessment request: ' . $error->getMessage());
    echo json_encode(['ok'=>false, 'message'=>$known ? $error->getMessage() : 'The assessment could not be saved. Please try again.']);
}
