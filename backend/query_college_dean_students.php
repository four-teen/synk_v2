<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/college_dean_students_helper.php';
require_once __DIR__ . '/college_dean_student_email_helper.php';
require_once __DIR__ . '/college_dean_student_management_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function synk_dean_students_response(array $payload, int $status=200): void
{
    http_response_code($status);
    echo json_encode($payload,defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') synk_dean_students_response(['status'=>'error','message'=>'Use a POST request.'],405);
foreach (['action','csrf_token','college_id','campus_id','program_id','section_id','term','search','sort','page','page_size','student_id','preview_token','selected_ids',
    'student_number','first_name','last_name','middle_name','suffix_name','email_address','version','record_term','record_program_id','year_level'] as $field) {
    if (isset($_POST[$field]) && !is_scalar($_POST[$field])) synk_dean_students_response(['status'=>'error','message'=>'Invalid request.'],400);
}
$scope=synk_college_dean_dashboard_require_scope($conn,$_POST);
if (empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null)
    || !hash_equals((string)$_SESSION['csrf_token'],$_POST['csrf_token'])) {
    synk_dean_students_response(['status'=>'error','message'=>'Your session token has changed. Reload the page and try again.'],403);
}
$action=(string)($_POST['action'] ?? '');
try {
    if (!in_array($action,['bootstrap','list','detail','email_preview','email_save','form_options','edit_load','student_save','email_propose','selection','delete_preview','delete_save'],true)) throw new DomainException('Unknown student directory action.');
    if (!synk_dean_students_ready($conn)) throw new DomainException('Imported student lists are not available yet.');
    if ($action === 'bootstrap') synk_dean_students_response(['status'=>'ok','options'=>synk_dean_students_options($conn,$scope),
        'current_term'=>synk_fetch_current_academic_term($conn)]);
    if ($action==='form_options') synk_dean_students_response(['status'=>'ok']+synk_dean_student_manage_options($conn,$scope));
    if ($action==='student_save') synk_dean_students_response(['status'=>'ok']+synk_dean_student_manage_save($conn,$scope,$_POST));
    if ($action==='email_propose') {
        $catalog=synk_student_email_catalog($conn);
        $record=synk_dean_student_manage_names($_POST)+['student_number'=>(string)($_POST['student_number'] ?? '')];
        synk_dean_students_response(['status'=>'ok','candidate'=>synk_student_email_check($record,$catalog['owners'])]);
    }
    if ($action==='delete_save') synk_dean_students_response(['status'=>'ok']+synk_dean_students_delete_save($conn,$scope,(string)($_POST['preview_token'] ?? '')));
    $filters=synk_dean_students_filters($_POST,$conn);
    if ($action==='selection') synk_dean_students_response(['status'=>'ok','student_ids'=>synk_dean_students_selection($conn,$scope,$filters)]);
    if ($action==='delete_preview') synk_dean_students_response(['status'=>'ok']+synk_dean_students_delete_preview($conn,$scope,$filters,synk_dean_students_selected_ids($_POST['selected_ids'] ?? '')));
    if ($action === 'email_preview') synk_dean_students_response(['status'=>'ok']+synk_dean_student_email_preview($conn,$scope,$filters));
    if ($action === 'email_save') {
        $selected=json_decode((string)($_POST['selected_ids'] ?? ''),true);
        if (!is_array($selected) || array_values($selected)!==$selected) throw new DomainException('Select valid proposed emails.');
        synk_dean_students_response(['status'=>'ok']+synk_dean_student_email_save($conn,$scope,(string)($_POST['preview_token'] ?? ''),$selected));
    }
    if ($action === 'list') synk_dean_students_response(['status'=>'ok']+synk_dean_students_list($conn,$scope,$filters));
    $studentId=filter_var($_POST['student_id'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if (!$studentId) throw new DomainException('Select a valid student.');
    if ($action==='edit_load') synk_dean_students_response(['status'=>'ok','student'=>synk_dean_student_manage_record($conn,$scope,$studentId)]);
    $detail=synk_dean_students_detail($conn,$scope,$studentId,$filters);
    if (!$detail) synk_dean_students_response(['status'=>'error','message'=>'This student has no imported class list in your assigned college.'],404);
    synk_dean_students_response(['status'=>'ok']+$detail);
} catch (DomainException $e) {
    synk_dean_students_response(['status'=>'error','message'=>$e->getMessage()],422);
} catch (Throwable $e) {
    error_log('College Dean students: ' . $e->getMessage());
    synk_dean_students_response(['status'=>'error','message'=>'Unable to complete this student request. Please try again.'],500);
}
