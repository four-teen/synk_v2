<?php
if (!defined('SYNK_PROGRAM_CHAIR_API')) define('SYNK_PROGRAM_CHAIR_API', true);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/program_chair_faculty_helper.php';
require_once __DIR__ . '/program_chair_faculty_management_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function synk_chair_faculty_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') synk_chair_faculty_response(['status'=>'error','message'=>'Use a POST request.'], 405);
try { $scope = synk_chair_faculty_require_scope($conn, $_POST); }
catch (DomainException $exception) { synk_chair_faculty_response(['status'=>'error','message'=>$exception->getMessage()], 403); }
if (!is_string($_POST['csrf_token'] ?? null) || empty($_SESSION['csrf_token'])
    || !hash_equals((string)$_SESSION['csrf_token'], $_POST['csrf_token'])) {
    synk_chair_faculty_response(['status'=>'error','message'=>'Reload the page and try again. Your session token has changed.'], 403);
}
try {
    foreach (['faculty_id','college_faculty_id','designation_id','employment_classification','ay_id','semester','search','page','page_size','action'] as $field) {
        if (isset($_POST[$field]) && !is_scalar($_POST[$field])) throw new DomainException('Invalid faculty request.', 400);
    }
    if (isset($_POST['load_college_faculty'])) {
        $term = synk_dean_read_term($conn, $_POST);
        synk_chair_faculty_response(synk_chair_faculty_management_list($conn,$scope,$term,$_POST));
    }
    if (isset($_POST['load_faculty_dropdown'])) {
        $term = synk_dean_read_term($conn, $_POST);
        $list = synk_chair_faculty_list($conn,$scope,$term);
        synk_chair_faculty_response(array_map(static function (array $row): array { return ['id'=>$row['faculty_id'],'text'=>$row['faculty_name']]; },$list['available']));
    }
    if (isset($_POST['load_designation_options'])) {
        $rows = synk_chair_faculty_query($conn,"SELECT designation_id AS id,designation_name AS text FROM tbl_designation WHERE status='active' ORDER BY designation_name");
        synk_chair_faculty_response(['status'=>'success','data'=>$rows]);
    }
    if (isset($_POST['save_assignment'])) synk_chair_faculty_response(synk_chair_faculty_assign($conn,$_POST));
    if (isset($_POST['update_faculty_designation'])) synk_chair_faculty_response(synk_chair_faculty_update($conn,$_POST,'designation'));
    if (isset($_POST['update_employment_classification'])) synk_chair_faculty_response(synk_chair_faculty_update($conn,$_POST,'classification'));
    if (isset($_POST['remove_assignment'])) synk_chair_faculty_response(synk_chair_faculty_remove($conn,$_POST));
    if (($_POST['action'] ?? '') === 'list') {
        $term = synk_dean_read_term($conn, $_POST);
        synk_chair_faculty_response(['status'=>'ok','scope'=>$scope,'term'=>$term] + synk_chair_faculty_list($conn, $scope, $term));
    }
    if (($_POST['action'] ?? '') === 'assign') synk_chair_faculty_response(synk_chair_faculty_assign($conn, $_POST));
    synk_chair_faculty_response(['status'=>'error','message'=>'Unknown faculty action.'], 400);
} catch (DomainException $exception) {
    $status = in_array($exception->getCode(),[400,401,403,404,409,422],true) ? $exception->getCode() : 422;
    synk_chair_faculty_response(['status'=>'error','message'=>$exception->getMessage()], $status);
} catch (Throwable $exception) {
    error_log('Program Chair college faculty: ' . $exception->getMessage());
    synk_chair_faculty_response(['status'=>'error','message'=>'Unable to complete this faculty request. Please try again.'], 500);
}
