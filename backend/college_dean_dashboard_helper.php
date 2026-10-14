<?php

require_once __DIR__ . '/college_dean_helper.php';

function synk_college_dean_dashboard_scope(mysqli $conn): ?array
{
    $role = (string)($_SESSION['role'] ?? '');
    if (empty($_SESSION['user_id']) || !in_array($role, ['college_dean', 'program_chair'], true)) {
        return null;
    }
    if ($role === 'program_chair' && !(defined('SYNK_PROGRAM_CHAIR_VIEW') || defined('SYNK_PROGRAM_CHAIR_API'))) {
        return null;
    }
    $account = synk_find_useraccount_by_id($conn, (int)$_SESSION['user_id']);
    if (!$account || !($role === 'program_chair'
        ? synk_program_chair_account_has_access($conn, $account)
        : synk_college_dean_account_has_access($conn, $account))) {
        return null;
    }
    $stmt = $conn->prepare("
        SELECT c.college_id, c.college_code, c.college_name,
               cp.campus_id, cp.campus_code, cp.campus_name
        FROM tbl_college c
        INNER JOIN tbl_campus cp ON cp.campus_id = c.campus_id
        WHERE c.college_id = ? AND c.status = 'active' AND cp.status = 'active'
        LIMIT 1
    ");
    $collegeId = (int)($role === 'program_chair' ? $account['college_id'] : $account['college_dean_college_id']);
    $stmt->bind_param('i', $collegeId);
    $stmt->execute();
    $scope = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$scope) return null;
    $scope['college_id'] = (int)$scope['college_id'];
    $scope['campus_id'] = (int)$scope['campus_id'];
    if ($role === 'program_chair') {
        $_SESSION['college_id'] = $collegeId;
        $_SESSION['college_name'] = (string)$scope['college_name'];
    } else {
        synk_college_dean_store_session_scope($account);
    }
    return $scope;
}

function synk_college_dean_dashboard_require_scope(mysqli $conn, array $input = [], bool $html = false): array
{
    $scope = synk_college_dean_dashboard_scope($conn);
    $message = $scope ? '' : 'College portal access and an active college assignment are required.';
    if ($scope) {
        foreach (['college_id', 'campus_id'] as $key) {
            if (isset($input[$key]) && trim((string)$input[$key]) !== ''
                && (string)$input[$key] !== (string)$scope[$key]) {
                $message = 'The requested view is outside your assigned college.';
                break;
            }
        }
    }
    if ($message !== '') {
        http_response_code(403);
        if ($html) {
            header('Content-Type: text/html; charset=utf-8');
            echo "<div class='text-danger text-center p-4'>" . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
        } else {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'message' => $message, 'rows' => [], 'external_rows' => [], 'meta' => []]);
        }
        exit;
    }
    return $scope;
}
