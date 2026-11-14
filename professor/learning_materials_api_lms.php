<?php
ini_set('display_errors','0');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); throw new DomainException('Use the Learning materials workspace.',405); }
    if (($_SESSION['role'] ?? '') !== 'professor' || empty($_SESSION['user_id'])) throw new DomainException('Sign in again to continue.',401);
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0)>0) throw new DomainException('The upload is too large. Choose smaller files and try again.',413);
    require_once __DIR__.'/../backend/db.php';
    require_once __DIR__.'/../backend/learning_material_api_helper_lms.php';
    $input = $_POST;
    if (!is_string($input['context'] ?? null) || strlen($input['context'])>4096) throw new DomainException('Open Learning materials from your class workspace.',403);
    $input['context'] = json_decode($input['context'],true);
    echo json_encode(['ok'=>true]+synk_material_request_lms($conn,$input,$_FILES),JSON_HEX_TAG);
} catch (Throwable $error) {
    $known = $error instanceof DomainException && in_array($error->getCode(),[400,401,403,404,405,409,413,422,503],true);
    http_response_code($known ? $error->getCode() : 503);
    if (!$known) error_log('LMS learning materials: '.$error->getMessage());
    echo json_encode(['ok'=>false,'message'=>$known ? $error->getMessage() : 'Learning materials could not be updated. Please try again.']);
}
