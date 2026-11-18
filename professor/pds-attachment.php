<?php
session_start();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '')!=='professor') { http_response_code(401); exit('Sign in to your professor account.'); }
require_once __DIR__.'/../backend/hr_faculty_pds_helper.php';
$slot=$_GET['slot'] ?? '';
if (!is_string($slot) || !in_array($slot,synk_hr_pds_attachment_slots(),true)) { http_response_code(404); exit('Attachment unavailable.'); }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
try {
    $userId=(int)$_SESSION['user_id'];
    $statement=$conn->prepare("SELECT a.user_id,l.faculty_id FROM tbl_useraccount a LEFT JOIN tbl_useraccount_faculty_links l ON l.user_id=a.user_id WHERE a.user_id=? AND a.status='active' AND a.role='professor'");
    $statement->bind_param('i',$userId); $statement->execute(); $actor=$statement->get_result()->fetch_assoc(); $statement->close();
    if (!$actor) { http_response_code(403); exit('Account unavailable.'); }
    $profile=synk_hr_pds_profile($conn,$userId,(int)($actor['faculty_id'] ?? 0));
    if (!$profile) { http_response_code(404); exit('Attachment unavailable.'); }
    $profileId=(int)$profile['hr_profile_id'];
    $statement=$conn->prepare('SELECT original_name,mime_type,byte_size,content FROM tbl_hr_faculty_pds_attachments WHERE hr_profile_id=? AND slot=?');
    $statement->bind_param('is',$profileId,$slot); $statement->execute(); $file=$statement->get_result()->fetch_assoc(); $statement->close();
    if (!$file) { http_response_code(404); exit('Attachment unavailable.'); }
    $fallback=preg_replace('/[^A-Za-z0-9._-]/','_',$file['original_name']);
    header('Content-Type: '.$file['mime_type']);
    header('Content-Length: '.strlen($file['content']));
    header('Content-Disposition: attachment; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode($file['original_name']));
    session_write_close(); echo $file['content'];
} catch (Throwable $error) {
    error_log('HR PDS attachment read failed ('.get_class($error).', code '.$error->getCode().').');
    http_response_code(503); echo 'The attachment is temporarily unavailable.';
}
