<?php
ini_set('display_errors', '0');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        throw new DomainException('This request method is not supported.', 405);
    }
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'professor') {
        throw new DomainException('Sign in using your professor account to view students.', 401);
    }
    require_once __DIR__ . '/../backend/db.php';
    require_once __DIR__ . '/../backend/auth_useraccount.php';
    require_once __DIR__ . '/../backend/professor_workload_students_helper.php';
    $account = synk_find_useraccount_by_id($conn, (int)$_SESSION['user_id']);
    if (!$account || $account['status'] !== 'active' || !synk_useraccount_has_active_role($conn, $account, 'professor')) {
        throw new DomainException('Professor access is no longer available.', 403);
    }
    $portal = synk_professor_resolve_portal_context($conn);
    $ayId = synk_professor_workload_query_integer($_GET['ay_id'] ?? null);
    $semester = synk_professor_workload_query_integer($_GET['semester'] ?? null);
    $offeringId = synk_professor_workload_query_integer($_GET['offering_id'] ?? null);
    if (!$ayId || !$offeringId || !in_array($semester, [1, 2, 3], true)) throw new DomainException('Choose a valid class and academic term.', 422);
    $data = synk_professor_workload_students($conn, (int)$portal['faculty_id'], $ayId, $semester, $offeringId);
    echo json_encode(['ok' => true] + $data, JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    $known = $error instanceof DomainException && in_array($error->getCode(), [401, 403, 404, 405, 422, 503], true);
    http_response_code($known ? $error->getCode() : 503);
    if (!$known) error_log('Professor workload students: ' . $error->getMessage());
    echo json_encode(['ok' => false, 'message' => $known ? $error->getMessage() : 'The student list could not be loaded. Please try again.']);
}
