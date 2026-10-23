<?php
// Compatibility response for an Academic Settings page opened before free term switching.
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/db.php';
require_once __DIR__.'/scheduler_request_guard.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new DomainException('Use a POST request.',405);
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') throw new DomainException('Administrator access is required.',403);
    synk_scheduler_require_csrf($_POST);
    $actor=synk_find_useraccount_by_id($conn,(int)$_SESSION['user_id']);
    if (!$actor || $actor['status']!=='active' || !synk_useraccount_has_active_role($conn,$actor,'admin')) throw new DomainException('Administrator access is no longer available.',403);
    throw new DomainException('Planning permissions are no longer needed. Schedulers can work in any existing academic year and semester. Reload Academic Settings.',410);
} catch (DomainException $error) {
    http_response_code($error->getCode() ?: 422); echo json_encode(['status'=>'error','message'=>$error->getMessage()]);
} catch (Throwable $error) {
    error_log('Legacy scheduler planning settings: '.$error->getMessage());
    http_response_code(500); echo json_encode(['status'=>'error','message'=>'Unable to verify your session. Reload Academic Settings.']);
}
