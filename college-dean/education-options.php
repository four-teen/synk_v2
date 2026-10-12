<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/college_dean_profile_helper.php';
synk_college_dean_dashboard_require_scope($conn);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405); header('Allow: GET'); echo json_encode(['error'=>'Use GET for education suggestions.']); exit;
}
$type = $_GET['type'] ?? '';
$query = $_GET['q'] ?? '';
$page = $_GET['page'] ?? '1';
if (!is_string($type) || !isset(synk_faculty_education_types()[$type]) || !is_string($query)
    || !preg_match('//u', $query) || preg_match_all('/./us', $query) > 500
    || !is_string($page) || !ctype_digit($page) || (int)$page < 1 || (int)$page > 10000) {
    http_response_code(400); echo json_encode(['error'=>'Invalid education search.']); exit;
}
session_write_close();
try {
    echo json_encode(synk_faculty_education_search($conn, $type, $query, (int)$page), JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('Dean education suggestions: ' . $exception->getMessage());
    http_response_code(503); echo json_encode(['error'=>'Education suggestions are temporarily unavailable.']);
}
