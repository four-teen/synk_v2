<?php
ini_set('display_errors','0');session_start();
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {header('Allow: POST');throw new DomainException('Use the class roster to make this request.',405);}
    if (!empty($_GET) || !empty($_SERVER['PATH_INFO'])) throw new DomainException('Use the class roster without URL selectors.',400);
    $raw=file_get_contents('php://input',false,null,0,16385);
    if ($raw===false || strlen($raw)>16384) throw new DomainException('The roster request is too large.',413);
    $input=json_decode($raw,true);if (!is_array($input) || json_last_error()!==JSON_ERROR_NONE) throw new DomainException('Enter a valid roster request.',400);
    require_once __DIR__.'/../backend/db.php';require_once __DIR__.'/../backend/class_students_api_helper_lms.php';
    echo json_encode(['ok'=>true]+synk_class_students_request_lms($conn,$input),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG);
} catch (Throwable $error) {
    $known=$error instanceof DomainException && in_array($error->getCode(),[400,401,403,404,405,409,413,422,503],true);
    http_response_code($known?$error->getCode():503);if (!$known) error_log('LMS class roster request: '.$error->getMessage());
    echo json_encode(['ok'=>false,'message'=>$known?$error->getMessage():'The class status could not be loaded or saved. Refresh the roster and try again.']);
}
