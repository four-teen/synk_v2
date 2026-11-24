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
    if (strpos($action,'exemption_')===0) {
        require_once __DIR__.'/management_exemptions_helper.php';
        synk_management_require_permission($conn,$scope,in_array($action,['exemption_data','exemption_list_get'],true)?'exemptions':'manage_exemptions');
        $period=synk_management_view_term($conn,$_POST,$scope);
        if ($action==='exemption_data') synk_management_response(['status'=>'ok']+synk_management_exemption_data($conn,$scope,$period,$_POST));
        if ($action==='exemption_search') synk_management_response(['status'=>'ok','students'=>synk_management_exemption_search($conn,$scope,$period,(string)($_POST['search'] ?? ''))]);
        if ($action==='exemption_list_get') synk_management_response(['status'=>'ok','list'=>synk_management_exemption_list_get($conn,$scope,$period,synk_management_input_id($_POST['list_id'] ?? ''))]);
        synk_management_response(['status'=>'ok']+synk_management_exemption_write($conn,$scope,$action,$_POST));
    }
    if(strpos($action,'officer_')===0){
        require_once __DIR__.'/management_officers_helper.php';
        synk_management_require_permission($conn,$scope,'officers');
        if($action==='officer_search')synk_management_response(['status'=>'ok','students'=>synk_management_officer_search($conn,$scope,(string)($_POST['search'] ?? ''))]);
        synk_management_response(['status'=>'ok']+synk_management_officer_write($conn,$scope,$action,$_POST));
    }
    if (in_array($action,['attendance_past_preview','attendance_past_save'],true)) {
        require_once __DIR__.'/management_past_attendance_helper.php';
        require_once __DIR__.'/management_attendance_workbook_helper.php';
        synk_management_require_attendance_import($conn,$scope);
        synk_management_input_text($_POST,'title',255);
        $workbook=synk_management_read_attendance_upload($_FILES['workbook'] ?? null,(string)($_POST['template'] ?? ''));
        synk_management_response(['status'=>'ok']+synk_management_record_past_attendance($conn,$scope,$action,$_POST,$workbook));
    }
    $kind=(string)($_POST['kind'] ?? '');
    $permission='setup';
    if(in_array($action,['payment_preview','payment_post'],true))$permission='payments';
    elseif($action==='attendance_scan')$permission='attendance';
    elseif(in_array($action,['attendance_get','attendance_roster'],true))$permission='events';
    elseif(in_array($action,['attendance_open','attendance_close','attendance_reopen','attendance_correct'],true))$permission='manage_events';
    elseif($action==='student_detail')$permission='student_records';
    elseif($action==='student_violation_apply')$permission='manage_violations';
    elseif(in_array($kind,['contributions','events','violations','flag_raising'],true) && in_array($action,['get','save','delete'],true))$permission=$action==='get'?$kind:'manage_'.($kind==='flag_raising'?'events':$kind);
    synk_management_require_permission($conn,$scope,$permission,isset($_POST['student_id'])?(int)$_POST['student_id']:null);
    if($action==='student_violation_apply'){
        require_once __DIR__.'/management_student_violations_helper.php';
        synk_management_response(['status'=>'ok']+synk_management_student_violation_apply($conn,$scope,$_POST));
    }
    if (in_array($action,['payment_preview','payment_post'],true)) {
        require_once __DIR__.'/management_payment_helper.php';
        $result=$action==='payment_preview' ? synk_management_payment_preview($conn,$scope,$_POST) : synk_management_payment_post($conn,$scope,$_POST);
        synk_management_response(['status'=>'ok']+$result);
    }
    if (strpos($action,'attendance_')===0) {
        require_once __DIR__.'/management_attendance_helper.php';
        if (in_array($action,['attendance_get','attendance_roster'],true)) {
            $event=synk_management_catalog_get($conn,$scope,'events',synk_management_input_id($_POST['event_id'] ?? null));
            if (!synk_management_catalog_in_period($event,synk_management_view_term($conn,$_POST,$scope))) throw new OutOfBoundsException('This event is outside the selected period.');
            if ($action==='attendance_roster') {
                require_once __DIR__.'/management_attendance_review_helper.php';
                synk_management_response(['status'=>'ok','roster'=>synk_management_closed_attendance_roster($conn,$event,$_POST)]);
            }
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
        $view=synk_management_view_term($conn,$_POST,$scope);
        if (($kind==='events' && synk_management_is_flag_raising($record))
            || !($kind==='flag_raising' ? synk_management_same_academic_semester($record,$view) : synk_management_catalog_in_period($record,$view))) {
            throw new OutOfBoundsException('This record is unavailable in the selected Management period.');
        }
        synk_management_response(['status'=>'ok','record'=>$record]);
    }
    $result = synk_management_catalog_write($conn,$scope,$kind,$action,$_POST);
    synk_management_response(['status'=>'ok'] + $result);
} catch (OutOfBoundsException $error) { synk_management_response(['status'=>'error','message'=>$error->getMessage()],404); }
catch (SynkManagementConflict $error) { synk_management_response(['status'=>'error','message'=>$error->getMessage()],409); }
catch (SynkManagementPermissionDenied $error) { synk_management_response(['status'=>'error','message'=>$error->getMessage()],403); }
catch (DomainException $error) { synk_management_response(['status'=>'error','message'=>$error->getMessage()],422); }
catch (Throwable $error) {
    error_log('Management request: ' . $error->getMessage());
    synk_management_response(['status'=>'error','message'=>'Unable to complete the request. Please try again.'],503);
}
