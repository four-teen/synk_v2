<?php
ini_set('display_errors','0');
session_start();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: sandbox; default-src 'none'");
try {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '',['GET','HEAD'],true)) { header('Allow: GET, HEAD'); throw new DomainException('Use the material download link.',405); }
    require_once __DIR__.'/../backend/db.php';
    require_once __DIR__.'/../backend/learning_material_api_helper_lms.php';
    $context = synk_material_context_lms($conn,['context'=>$_GET]);
    $id = synk_material_id_lms($_GET['material_id'] ?? null);
    $lms = synk_database_connection_lms($conn);
    if (!synk_material_ready_lms($lms)) throw new DomainException('Learning materials are temporarily unavailable.',503);
    $file = synk_material_file_lms($lms,$context,$id,($_SERVER['REQUEST_METHOD'] === 'GET'));
    header('Content-Type: '.$file['mime_type']);
    header('Content-Length: '.(int)$file['size_bytes']);
    $extension = strtolower(pathinfo($file['original_name'],PATHINFO_EXTENSION));
    header('Content-Disposition: attachment; filename="material.'.$extension.'"; filename*=UTF-8\'\''.rawurlencode($file['original_name']));
    if ($_SERVER['REQUEST_METHOD'] === 'GET') echo $file['file_data'];
} catch (Throwable $error) {
    $known = $error instanceof DomainException && in_array($error->getCode(),[400,401,403,404,405,422,503],true);
    http_response_code($known ? $error->getCode() : 503);
    header('Content-Type: text/plain; charset=utf-8');
    if (!$known) error_log('LMS material download: '.$error->getMessage());
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') echo $known ? $error->getMessage() : 'This material could not be downloaded. Please try again.';
}
