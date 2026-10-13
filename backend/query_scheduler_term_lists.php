<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/scheduler_term_list_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new DomainException('Use a POST request.', 405);
    foreach (['action', 'kind', 'college_id', 'source_ay_id', 'source_semester', 'csrf_token', 'preview_token', 'selected_ids'] as $field) {
        if (isset($_POST[$field]) && !is_string($_POST[$field])) throw new DomainException('Invalid request.', 400);
    }
    $scope = synk_scheduler_list_scope($conn, synk_scheduler_list_id($_POST['college_id'] ?? null));
    if (!is_string($_POST['csrf_token'] ?? null) || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'], $_POST['csrf_token'])) throw new DomainException('Reload the page before trying again.', 403);
    $kind = $_POST['kind'] ?? '';
    if (!in_array($kind, ['faculty', 'rooms'], true)) throw new DomainException('Choose a valid list.', 400);
    $target = synk_scheduler_list_current_term($conn);
    $action = $_POST['action'] ?? '';
    if ($action === 'options') $data = ['target' => $target, 'terms' => synk_scheduler_list_source_terms($conn, $scope, $kind, $target)];
    elseif ($action === 'preview') {
        $source = synk_scheduler_list_source($conn, $scope, $kind, synk_scheduler_list_id($_POST['source_ay_id'] ?? null), synk_scheduler_list_id($_POST['source_semester'] ?? null), $target);
        $data = synk_scheduler_list_stage_preview($conn, $scope, $kind, $source, $target);
    } elseif ($action === 'copy') $data = synk_scheduler_list_copy($conn, $scope, $kind, $_POST['preview_token'] ?? '', synk_scheduler_list_selected_ids($_POST['selected_ids'] ?? ''));
    else throw new DomainException('Choose a valid list action.', 400);
    echo json_encode(['status' => 'ok'] + $data, JSON_UNESCAPED_UNICODE);
} catch (DomainException $exception) {
    $status = in_array($exception->getCode(), [400, 401, 403, 404, 405, 409, 422], true) ? $exception->getCode() : 422;
    http_response_code($status);
    echo json_encode(['status' => 'error', 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('Scheduler list copy: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'The list could not be copied. Please try again.']);
}
