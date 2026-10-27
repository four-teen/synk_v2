<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/management_catalog_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
function synk_management_response(array $data, int $code = 200): void
{
    http_response_code($code); echo json_encode($data); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST'); synk_management_response(['status'=>'error','message'=>'Use a POST request.'],405);
}
foreach ($_POST as $value) if (!is_scalar($value)) synk_management_response(['status'=>'error','message'=>'Invalid request.'],400);
$scope = synk_management_require_scope($conn,true);
if (empty($_SESSION['management_csrf_token']) || !is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['management_csrf_token'],$_POST['csrf_token'])) {
    synk_management_response(['status'=>'error','message'=>'Your session token changed. Reload the page and try again.'],403);
}
try {
    $action = (string)($_POST['action'] ?? '');
    if (in_array($action,['payment_preview','payment_post'],true)) {
        require_once __DIR__.'/management_payment_helper.php';
        $result=$action==='payment_preview' ? synk_management_payment_preview($conn,$scope,$_POST) : synk_management_payment_post($conn,$scope,$_POST);
        synk_management_response(['status'=>'ok']+$result);
    }
    if (strpos($action,'attendance_')===0) {
        require_once __DIR__.'/management_attendance_helper.php';
        if ($action==='attendance_get') {
            $event=synk_management_catalog_get($conn,$scope,'events',synk_management_input_id($_POST['event_id'] ?? null));
            if (!synk_management_catalog_in_period($event,synk_management_view_term($conn,$_POST,$scope))) throw new OutOfBoundsException('This event is outside the selected period.');
            synk_management_response(['status'=>'ok','attendance'=>synk_management_attendance_summary($conn,$scope,(int)$event['event_id'])]);
        }
        synk_management_response(['status'=>'ok']+synk_management_attendance_write($conn,$scope,$action,$_POST));
    }
    if ($action === 'save_management_term') {
        synk_management_response(['status'=>'ok'] + synk_management_save_current_exam_term($conn,$scope,$_POST));
    }
    if ($action === 'student_detail') {
        require_once __DIR__ . '/management_students_helper.php';
        $detail = synk_management_students_detail($conn,$scope,$_POST);
        synk_management_response(['status'=>'ok'] + $detail);
    }
    if (($_POST['kind'] ?? '') === 'violations') {
        require_once __DIR__ . '/management_violations_helper.php';
        if (!synk_table_exists($conn,'tbl_management_violations')) throw new RuntimeException('Violation storage is unavailable.');
        if ($action === 'get') synk_management_response(['status'=>'ok','record'=>synk_management_violation_get($conn,$scope,synk_management_input_id($_POST['record_id'] ?? ''))]);
        synk_management_response(['status'=>'ok'] + synk_management_violation_write($conn,$scope,$action,$_POST));
    }
    if (!synk_management_catalog_ready($conn)) throw new RuntimeException('Management catalog storage is unavailable.');
    $kind = (string)($_POST['kind'] ?? ''); synk_management_catalog_kind($kind);
    if ($action === 'get') {
        $record = synk_management_catalog_get($conn,$scope,$kind,synk_management_input_id($_POST['record_id'] ?? ''));
        if (!synk_management_catalog_in_period($record,synk_management_view_term($conn,$_POST,$scope))) {
            throw new OutOfBoundsException('This record is unavailable in the selected Management period.');
        }
        synk_management_response(['status'=>'ok','record'=>$record]);
    }
    $result = synk_management_catalog_write($conn,$scope,$kind,$action,$_POST);
    synk_management_response(['status'=>'ok'] + $result);
} catch (OutOfBoundsException $error) { synk_management_response(['status'=>'error','message'=>$error->getMessage()],404); }
catch (SynkManagementConflict $error) { synk_management_response(['status'=>'error','message'=>$error->getMessage()],409); }
catch (DomainException $error) { synk_management_response(['status'=>'error','message'=>$error->getMessage()],422); }
catch (Throwable $error) {
    error_log('Management request: ' . $error->getMessage());
    synk_management_response(['status'=>'error','message'=>'Unable to complete the request. Please try again.'],503);
}
