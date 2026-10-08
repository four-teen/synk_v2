<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'professor') {
    http_response_code(401);
    echo json_encode(['error' => 'Please sign in as a professor.']);
    exit;
}
$userId = (int)$_SESSION['user_id'];
session_write_close();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Use GET for education suggestions.']);
    exit;
}

require_once __DIR__ . '/../backend/faculty_profile_helper.php';
$type = $_GET['type'] ?? '';
$query = $_GET['q'] ?? '';
$page = $_GET['page'] ?? '1';
if (!is_string($type) || !isset(synk_faculty_education_types()[$type]) || !is_string($query)
    || !preg_match('//u', $query) || preg_match_all('/./us', $query) > 500
    || !is_string($page) || !ctype_digit($page) || (int)$page < 1 || (int)$page > 10000) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid education search.']);
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    require __DIR__ . '/../backend/db.php';
    if (!synk_faculty_profile_account($conn, $userId)) {
        http_response_code(403);
        echo json_encode(['error' => 'Your account is unavailable.']);
        exit;
    }
    echo json_encode(synk_faculty_education_search($conn, $type, $query, (int)$page), JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('Education suggestions failed: ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Education suggestions are temporarily unavailable.']);
}
