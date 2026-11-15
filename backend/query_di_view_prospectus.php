<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
define('SYNK_DI_CURRICULUM_API', true);
if (empty($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    http_response_code(403); exit('ERROR|Director for Instruction access is required.');
}
try {
    require __DIR__ . '/query_view_prospectus.php';
} catch (Throwable $exception) {
    require_once __DIR__ . '/di_curriculum_helper.php';
    synk_di_curriculum_error($exception);
}
