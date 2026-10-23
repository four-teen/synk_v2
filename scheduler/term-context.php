<?php
require_once __DIR__ . '/../backend/scheduler_term_helper.php';
require_once __DIR__ . '/../backend/scheduler_term_list_helper.php';
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'scheduler') { header('Location: ../index.php'); exit; }
if (!isset($conn) || !($conn instanceof mysqli)) { http_response_code(404); exit; }
try {
    synk_scheduler_bootstrap_session_scope($conn);
    synk_scheduler_list_scope($conn,(int)($_SESSION['college_id'] ?? 0));
} catch (DomainException $error) { http_response_code($error->getCode() ?: 403); exit(htmlspecialchars($error->getMessage(),ENT_QUOTES,'UTF-8')); }
synk_scheduler_page_term($conn);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && basename($_SERVER['PHP_SELF'] ?? '')==='manage-report-signatories.php') {
    require_once __DIR__.'/../backend/scheduler_request_guard.php';
    $_POST['save_signatory_settings']='1';
    synk_scheduler_guard_request($conn,'manage-report-signatories.php');
}
