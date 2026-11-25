<?php
if (($page ?? '') !== 'payment_corrections') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/management_payment_correction_helper.php';

header('X-Content-Type-Options: nosniff');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method,['GET','POST'],true)) {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

$ready = synk_management_payments_ready($conn) && synk_management_payment_reversals_ready($conn);
$errorMessage = '';
$recordError = static function (Throwable $error) use (&$errorMessage): void {
    if ($error instanceof SynkManagementConflict) {
        http_response_code(409);
    } elseif ($error instanceof SynkManagementPermissionDenied) {
        http_response_code(403);
    } elseif ($error instanceof OutOfBoundsException) {
        http_response_code(404);
    } elseif ($error instanceof DomainException) {
        http_response_code(in_array($error->getCode(),[403,422],true) ? $error->getCode() : 422);
    } else {
        http_response_code(503);
        error_log('Management payment correction: '.$error->getMessage());
        $errorMessage = 'Payment corrections could not be loaded or saved. Reload the account to check its correction history.';
        return;
    }
    $errorMessage = $error->getMessage();
};

$csrf = $_SESSION['management_csrf_token'];
$notice = $_SESSION['management_correction_notice'] ?? '';
unset($_SESSION['management_correction_notice']);
$studentId = $studentResults = null;
$receipts = $history = $selected = [];
$search = $reason = $requestKey = $receiptVersion = '';
$correctionPage = 1;
try {
    $studentId = synk_management_input_id($_GET['student_id'] ?? null,true);
    $search = synk_management_input_text($_GET,'search',100,false,false);
    $correctionPage = synk_management_input_id($_GET['page'] ?? 1);
} catch (DomainException $error) {
    $recordError($error);
}

if ($method === 'POST' && $errorMessage === '') {
    try {
        if (!$ready) throw new RuntimeException('Payment correction storage is unavailable.');
        if (!is_string($_POST['csrf_token'] ?? null) || !hash_equals($csrf,$_POST['csrf_token'])) {
            throw new DomainException('This form expired. Reload the page and try again.',403);
        }
        if (!$studentId) throw new DomainException('Choose a student before deleting payments.',422);
        if (($_POST['action'] ?? '') !== 'delete') throw new DomainException('Choose a valid payment action.',422);
        $reason = is_string($_POST['reason'] ?? null) ? mb_substr($_POST['reason'],0,500,'UTF-8') : '';
        $selected = synk_management_correction_selection($_POST['selection'] ?? null);
        $result = synk_management_correction_post($conn,$scope,$term,$studentId,$selected,$_POST);
        $_SESSION['management_correction_notice'] = ($result['duplicate'] ? 'This deletion was already recorded.' : 'Selected payments deleted.')
            .' Reference '.$result['reference'].'.';
        $redirect = ['term'=>$term['key'],'exam_term'=>$term['exam_term'],'student_id'=>$studentId,'search'=>$search,'page'=>$correctionPage];
        header('Location: payment-corrections.php?'.http_build_query($redirect).'#correction-account',true,303);
        exit;
    } catch (Throwable $error) {
        if ($error instanceof SynkManagementConflict) $selected = [];
        $recordError($error);
    }
}

if ($ready) {
    try {
        if ($studentId) {
            $receipts = synk_management_correction_receipts($conn,$scope,$term,$studentId);
            $receiptVersion = synk_management_correction_version(synk_management_correction_context($scope,$term,$studentId),$receipts);
            $requestKey = bin2hex(random_bytes(32));
            $history = synk_management_correction_history($conn,$scope,$term,$studentId);
        } elseif ($search !== '') {
            $studentResults = synk_management_correction_search($conn,$scope,$term,$search,$correctionPage);
        }
    } catch (Throwable $error) {
        if ($errorMessage === '') $recordError($error);
    }
}
