<?php
// Exercise the real endpoint's authenticated JSON response with no real user or writes.
if (!defined('SYNK_HR_PDS_QA') || !in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(SYNK_HR_PDS_QA,$_GET['qa'])) { http_response_code(404); exit; }
session_save_path(dirname(__DIR__,2).'/temp');
session_id('hrpdsresponseqa'.bin2hex(random_bytes(12)));
session_start();
// The deliberately nonexistent account cannot pass persistence authorization.
$_SESSION=['user_id'=>2147483647,'role'=>'professor','faculty_profile_csrf'=>SYNK_HR_PDS_QA];
session_write_close();
register_shutdown_function(static function (): void {
    if (session_status()!==PHP_SESSION_ACTIVE) session_start();
    $_SESSION=[]; session_destroy();
});
require __DIR__.'/../../professor/pds-save.php';
