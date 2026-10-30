<?php
ini_set('display_errors','0');
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { header('Allow: POST'); throw new DomainException('Use the faculty evaluation clearing controls.',405); }
    if (($_SESSION['role'] ?? '')!=='college_dean' || empty($_SESSION['user_id'])) throw new DomainException('College Dean sign-in is required.',403);
    if (!is_string($_POST['csrf_token'] ?? null) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],$_POST['csrf_token'])) throw new DomainException('Your session expired. Reload the page before clearing.',403);
    require_once __DIR__.'/../backend/db.php';
    require_once __DIR__.'/../backend/college_dean_evaluation_clear_helper.php';
    $scope=synk_college_dean_dashboard_scope($conn);
    if (!$scope) throw new DomainException('Your College Dean access is no longer available.',403);
    foreach (['college_id','campus_id'] as $field) if (isset($_POST[$field]) && (!is_scalar($_POST[$field]) || (string)$_POST[$field]!== (string)$scope[$field])) throw new DomainException('This request is outside your assigned college.',403);
    $action=$_POST['action'] ?? null;
    if ($action==='preview') {
        foreach (['faculty_id','ay_id','semester'] as $field) {
            $value=is_string($_POST[$field] ?? null) ? filter_var($_POST[$field],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]) : false;
            if ($value===false) throw new DomainException('Select one faculty and a valid academic term.',422);
            $ids[$field]=(int)$value;
        }
        $filters=synk_dean_evaluations_filters(['faculty_id'=>$ids['faculty_id'],'instrument_version'=>$_POST['instrument_version'] ?? 'all']);
        $result=synk_dean_evaluation_clear_preview($conn,$scope,$ids,$ids['faculty_id'],$filters['instrument_version']);
    } elseif ($action==='confirm') {
        $token=$_POST['preview_token'] ?? null;
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new DomainException('Review one faculty before clearing its evaluations.',422);
        $result=synk_dean_evaluation_clear_save($conn,$scope,$token);
        $_SESSION['dean_faculty_evaluation_clear_notice']=['college_id'=>$scope['college_id'],'ay_id'=>$result['target']['ay_id'],'semester'=>$result['target']['semester'],'message'=>$result['message']];
    } else throw new DomainException('Choose a valid per-faculty clearing action.',422);
    echo json_encode(['ok'=>true]+$result,JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    $known=$error instanceof DomainException;
    $status=$known && in_array($error->getCode(),[403,404,405,409,422,503],true)?$error->getCode():($known?422:500);
    http_response_code($status);
    if (!$known) error_log('Dean faculty evaluation clearing: '.$error->getMessage());
    echo json_encode(['ok'=>false,'message'=>$known?$error->getMessage():'The evaluations could not be cleared. Please review the latest records and try again.']);
}
