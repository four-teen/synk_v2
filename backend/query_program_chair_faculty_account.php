<?php
if (!defined('SYNK_PROGRAM_CHAIR_API')) define('SYNK_PROGRAM_CHAIR_API',true);
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/program_chair_faculty_management_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function synk_chair_account_response(array $data,int $status=200): void
{
    http_response_code($status); echo json_encode($data); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') synk_chair_account_response(['status'=>'error','message'=>'Use a POST request.'],405);
try { $scope=synk_chair_faculty_require_scope($conn,$_POST); }
catch (DomainException $exception) { synk_chair_account_response(['status'=>'error','message'=>$exception->getMessage()],403); }
if (empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null) || !hash_equals((string)$_SESSION['csrf_token'],$_POST['csrf_token'])) synk_chair_account_response(['status'=>'error','message'=>'Reload this page before managing login access.'],403);
try {
    foreach ($_POST as $field=>$value) if (!is_string($value)) throw new DomainException('Invalid login access request.',400);
    $action=$_POST['action'] ?? '';
    if ($action==='load') {
        $term=synk_dean_read_term($conn,$_POST);
        $target=synk_chair_account_target($conn,$scope,$term,synk_scheduler_list_id($_POST['faculty_id'] ?? null));
        synk_chair_account_response(['status'=>'ok','account'=>synk_chair_account_public($conn,$target,$term)]);
    }
    if ($action==='save') synk_chair_account_response(synk_chair_account_save($conn,$_POST));
    throw new DomainException('Choose a valid login access action.',400);
} catch (DomainException $exception) {
    $status=in_array($exception->getCode(),[400,401,403,404,409,422],true)?$exception->getCode():422;
    synk_chair_account_response(['status'=>'error','message'=>$exception->getMessage()],$status);
} catch (Throwable $exception) {
    error_log('Program Chair faculty login access: '.$exception->getMessage());
    synk_chair_account_response(['status'=>'error','message'=>'Login access could not be saved. Please try again.'],500);
}
