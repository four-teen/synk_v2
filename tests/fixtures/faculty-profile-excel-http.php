<?php
// Only a short-lived, token-protected loopback route may execute this fixture.
if (!defined('SYNK_EXCEL_HTTP_QA') || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(SYNK_EXCEL_HTTP_QA, $_GET['qa'])) {
    http_response_code(404);
    exit;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ob_start();
require_once __DIR__ . '/../../backend/db.php';
session_start();
$conn->begin_transaction();
register_shutdown_function(static function () use ($conn): void {
    $conn->rollback();
    if (session_status() === PHP_SESSION_ACTIVE) session_abort();
});
$tag = 'ExcelQA' . substr(SYNK_EXCEL_HTTP_QA, 0, 12);
$email = strtolower($tag) . '-actor@sksu.edu.ph';
$role = ($_GET['scenario'] ?? '') === 'unauthorized' ? 'scheduler' : 'DI';
$stmt = $conn->prepare("INSERT INTO tbl_useraccount (username, email, password, role, status) VALUES (?, ?, 'unusable-test-password', ?, 'active')");
$stmt->bind_param('sss', $tag, $email, $role);
$stmt->execute();
$actorId = (int)$conn->insert_id;
$stmt->close();
$_SESSION = ['user_id' => $actorId, 'role' => 'DI'];
$location = $conn->query("SELECT c.college_id, c.college_name, cp.campus_id, cp.campus_name FROM tbl_college c
    JOIN tbl_campus cp ON cp.campus_id = c.campus_id WHERE c.status = 'active' AND cp.status = 'active'
    ORDER BY c.college_id LIMIT 1")->fetch_assoc();
if (!$location) throw new RuntimeException('HTTP export checks require an existing active college.');
for ($i = 0; $i < 28; $i++) {
    $first = $tag;
    $last = 'Professor ' . str_pad((string)$i, 2, '0', STR_PAD_LEFT);
    $name = $first . ' ' . $last;
    $status = $i === 0 ? 'resigned' : 'active';
    $stmt = $conn->prepare('INSERT INTO tbl_faculty (first_name, last_name, status) VALUES (?, ?, ?)');
    $stmt->bind_param('sss', $first, $last, $status);
    $stmt->execute();
    $facultyId = (int)$conn->insert_id;
    $stmt->close();
    $email = strtolower($tag) . '-' . $i . '@sksu.edu.ph';
    // Legacy account storage predates the professor role; profiles link by user ID.
    $stmt = $conn->prepare("INSERT INTO tbl_useraccount (username, email, password, role, status) VALUES (?, ?, 'unusable-test-password', 'admin', 'active')");
    $stmt->bind_param('ss', $name, $email);
    $stmt->execute();
    $userId = (int)$conn->insert_id;
    $stmt->close();
    $conn->query("INSERT INTO tbl_useraccount_faculty_links (user_id, faculty_id) VALUES ({$userId}, {$facultyId})");
    if ($i === 27) {
        $stmt = $conn->prepare("INSERT INTO tbl_faculty_profiles (user_id, full_name, eligibility) VALUES (?, ?, '')");
        $stmt->bind_param('is', $userId, $name);
    } else {
        $stmt = $conn->prepare("INSERT INTO tbl_faculty_profiles (user_id, full_name, gender, date_of_birth, civil_status,
            campus_name, college_name, employment_classification, eligibility, service_start_date, length_of_service,
            bachelor_status, master_status, doctoral_status) VALUES (?, ?, 'Male', '1990-01-01', 'Single', ?, ?,
            'Permanent', '', '2020-01-01', '6 years', 'Not applicable', 'Not applicable', 'Not applicable')");
        $stmt->bind_param('isss', $userId, $name, $location['campus_name'], $location['college_name']);
    }
    $stmt->execute();
    $stmt->close();
}
$_GET['q'] = $tag;
$_GET['export'] = 'excel';
if (($_GET['scenario'] ?? '') === 'list') {
    unset($_GET['export']);
} elseif (($_GET['scenario'] ?? '') === 'empty') {
    $_GET['q'] = $tag . ' missing';
} elseif (($_GET['scenario'] ?? '') === 'status') {
    $_GET['status'] = 'resigned';
} elseif (($_GET['scenario'] ?? '') === 'search') {
    $_GET['q'] = $tag . ' Professor 26';
} elseif (($_GET['scenario'] ?? '') === 'campus') {
    $_GET['campus_id'] = (string)$location['campus_id'];
    $_GET['college_id'] = (string)$location['college_id'];
} elseif (($_GET['scenario'] ?? '') === 'connection') {
    $_GET['connection'] = 'no_account';
}
$_GET['page'] = '2';
ob_clean();
require __DIR__ . '/../../di/professors.php';
