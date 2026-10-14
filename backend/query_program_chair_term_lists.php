<?php
if (!defined('SYNK_PROGRAM_CHAIR_API')) define('SYNK_PROGRAM_CHAIR_API', true);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/program_chair_faculty_management_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new DomainException('Use a POST request.',405);
    foreach (['action','kind','college_id','source_ay_id','source_semester','csrf_token','preview_token','selected_ids','ay_id','semester'] as $field) {
        if (isset($_POST[$field]) && !is_string($_POST[$field])) throw new DomainException('Invalid list request.',400);
    }
    $scope = synk_chair_faculty_require_scope($conn,$_POST);
    if (empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null) || !hash_equals((string)$_SESSION['csrf_token'],$_POST['csrf_token'])) throw new DomainException('Reload the page before trying again.',403);
    if (($_POST['kind'] ?? '') !== 'faculty') throw new DomainException('Choose a valid faculty list.',400);
    $target = synk_scheduler_list_current_term($conn);
    $expected = synk_dean_term_from_ids($conn,$_POST['ay_id'] ?? null,$_POST['semester'] ?? null);
    if ($expected['ay_id'] !== $target['ay_id'] || $expected['semester'] !== $target['semester']) throw new DomainException('Switch to the current academic term before copying faculty.',409);
    $action = $_POST['action'] ?? '';
    if ($action === 'options') $data = ['target'=>$target,'terms'=>synk_scheduler_list_source_terms($conn,$scope,'faculty',$target)];
    elseif ($action === 'preview') {
        $source = synk_scheduler_list_source($conn,$scope,'faculty',synk_scheduler_list_id($_POST['source_ay_id'] ?? null),synk_scheduler_list_id($_POST['source_semester'] ?? null),$target);
        $data = synk_chair_faculty_stage_copy($conn,$scope,$source,$target);
    } elseif ($action === 'copy') $data = synk_chair_faculty_copy($conn,$scope,$_POST);
    else throw new DomainException('Choose a valid copy action.',400);
    echo json_encode(['status'=>'ok']+$data,JSON_UNESCAPED_UNICODE);
} catch (DomainException $exception) {
    $status = in_array($exception->getCode(),[400,401,403,404,405,409,422],true) ? $exception->getCode() : 403;
    http_response_code($status); echo json_encode(['status'=>'error','message'=>$exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('Program Chair faculty list copy: '.$exception->getMessage());
    http_response_code(500); echo json_encode(['status'=>'error','message'=>'The faculty list could not be copied. Please try again.']);
}
