<?php
// Included only by a short-lived, randomly protected loopback test route.
if (!defined('SYNK_DI_HTTP_QA') || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(SYNK_DI_HTTP_QA, $_GET['qa'])) {
    http_response_code(404);
    exit;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../../backend/faculty_directory_helper.php';
require_once __DIR__ . '/../../backend/faculty_profile_helper.php';
synk_faculty_directory_ensure_schema($conn);
synk_faculty_record_profiles_ensure($conn);
session_start();
ob_start();
$conn->begin_transaction();
$qaEmail = 'di-http-' . bin2hex(random_bytes(8)) . '@sksu.edu.ph';
$qaRole = ($_GET['scenario'] ?? '') === 'unauthorized' ? 'scheduler' : 'DI';
$stmt = $conn->prepare("INSERT INTO tbl_useraccount (username, email, password, role, status) VALUES ('DI HTTP QA', ?, 'unusable-test-password', ?, 'active')");
$stmt->bind_param('ss', $qaEmail, $qaRole);
$stmt->execute();
$qaActorId = (int)$conn->insert_id;
$stmt->close();
$conn->query("INSERT INTO tbl_faculty (first_name, last_name, status) VALUES ('DI Directory HTTP', 'QA Faculty', 'active')");
$qaFacultyId = (int)$conn->insert_id;
// The professor list includes saved profiles at 25% completion or higher.
// This unassigned faculty has a profile, even without a login account.
$qaProfile = synk_faculty_profile_defaults(['username'=>'DI Directory HTTP QA Faculty'], null);
$qaProfile['gender'] = 'Male';
$qaProfile['date_of_birth'] = '1990-01-01';
$qaProfile['civil_status'] = 'Single';
$qaProfile['employment_classification'] = 'Permanent';
foreach (['bachelor','master','doctoral'] as $level) $qaProfile[$level . '_status'] = 'Not applicable';
synk_faculty_record_profile_save($conn, $qaFacultyId, $qaProfile, 0, $qaActorId);
$_SESSION = ['user_id' => $qaActorId, 'role' => 'DI', 'di_faculty_csrf' => SYNK_DI_HTTP_QA];
$_GET['faculty_id'] = ($_GET['scenario'] ?? '') === 'bad_id' ? '1 OR 1=1' : (string)$qaFacultyId;
register_shutdown_function(static function () use ($conn, $qaFacultyId) {
    $row = $conn->query('SELECT status, record_revision FROM tbl_faculty WHERE faculty_id = ' . $qaFacultyId)->fetch_assoc();
    header('X-QA-Faculty-Status: ' . ($row['status'] ?? 'missing'));
    header('X-QA-Faculty-Revision: ' . ($row['record_revision'] ?? 'missing'));
    $conn->rollback();
    if (session_status() === PHP_SESSION_ACTIVE) session_abort();
});
if (($_GET['scenario'] ?? '') === 'list') {
    $_GET['q'] = 'DI Directory HTTP';
    $_GET['semester'] = '3';
    $_GET['ay_id'] = '999999';
    require __DIR__ . '/../../di/professors.php';
} else {
    require __DIR__ . '/../../di/faculty-profile.php';
}
