<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/college_dean_faculty_students_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function synk_dean_faculty_students_response(array $payload, int $status=200): void
{
    http_response_code($status);
    echo json_encode($payload, defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') synk_dean_faculty_students_response(['status'=>'error','message'=>'Use a POST request.'],405);
foreach (['action','csrf_token','college_id','campus_id','term','program_id','faculty_id','status','search'] as $field) {
    if (isset($_POST[$field]) && !is_scalar($_POST[$field])) synk_dean_faculty_students_response(['status'=>'error','message'=>'Invalid request.'],400);
}
$scope = synk_college_dean_dashboard_require_scope($conn, $_POST);
if (empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null)
    || !hash_equals((string)$_SESSION['csrf_token'],$_POST['csrf_token'])) {
    synk_dean_faculty_students_response(['status'=>'error','message'=>'Your session token has changed. Reload the page and try again.'],403);
}
try {
    $action = (string)($_POST['action'] ?? '');
    if (!in_array($action,['bootstrap','list','detail'],true)) throw new DomainException('Unknown faculty report action.');
    if (!synk_dean_roster_ready($conn)) throw new DomainException('Imported student lists are not available yet.');
    $options = synk_dean_faculty_students_options($conn,$scope);
    if ($action === 'bootstrap') synk_dean_faculty_students_response(['status'=>'ok','options'=>$options]);
    $filters = synk_dean_faculty_students_filters($_POST,$options);
    if ($action === 'detail' && !$filters['faculty_id']) throw new DomainException('Select a faculty member.');
    // Search/status selects faculty in the overview; a detail request is checked
    // independently against the college, term and selected program.
    if ($action === 'detail') { $filters['search']=''; $filters['status']='all'; }
    $data = synk_dean_faculty_students_data($conn,$scope,$filters);
    if ($action === 'detail') {
        $detail = $data['details'][$filters['faculty_id']] ?? null;
        if (!$detail) synk_dean_faculty_students_response(['status'=>'error','message'=>'This faculty member has no assignment within the selected report scope.'],404);
        synk_dean_faculty_students_response(['status'=>'ok']+$detail);
    }
    unset($data['details']);
    synk_dean_faculty_students_response(['status'=>'ok']+$data);
} catch (DomainException $e) {
    synk_dean_faculty_students_response(['status'=>'error','message'=>$e->getMessage()],422);
} catch (Throwable $e) {
    error_log('College Dean faculty students: ' . $e->getMessage());
    synk_dean_faculty_students_response(['status'=>'error','message'=>'Unable to load the faculty student report. Please try again.'],500);
}
