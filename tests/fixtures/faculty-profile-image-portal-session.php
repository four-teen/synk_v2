<?php
// A short-lived loopback route signs in only an isolated, unusable-password QA account.
if (!defined('SYNK_PROFILE_IMAGE_PORTAL_QA') || !defined('SYNK_PROFILE_IMAGE_PORTAL_USER')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(SYNK_PROFILE_IMAGE_PORTAL_QA, $_GET['qa'])) {
    http_response_code(404);
    exit;
}
session_start();
$_SESSION = ['user_id' => SYNK_PROFILE_IMAGE_PORTAL_USER, 'role' => 'professor'];
session_write_close();
header('X-Synk-Image-QA-PHP: ' . PHP_VERSION);
header('X-Synk-Image-QA-Fileinfo: ' . (class_exists('finfo') ? 'enabled' : 'disabled'));
require __DIR__ . '/../../professor/manage-profile.php';
