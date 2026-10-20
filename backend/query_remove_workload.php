<?php
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/db.php';
require_once __DIR__.'/workload_student_guard_helper.php';
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
function synk_remove_workload_response(array $payload,int $status=200): void
{
    http_response_code($status); echo json_encode($payload); exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') synk_remove_workload_response(['status'=>'error','message'=>'Use a POST request.'],405);
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '')!=='scheduler' || empty($_SESSION['college_id'])) synk_remove_workload_response(['status'=>'error','message'=>'Scheduler access and a college assignment are required.'],403);
if (empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null) || !hash_equals((string)$_SESSION['csrf_token'],$_POST['csrf_token'])) synk_remove_workload_response(['status'=>'error','message'=>'Your session changed. Reload the page before removing workload.'],403);
try {
    $type=$_POST['assignee_type'] ?? 'faculty';
    if (!is_string($type) || !in_array($type,['faculty','faculty_need'],true)) throw new DomainException('Choose a valid workload type.');
    $result=synk_workload_remove_guarded($conn,(int)$_SESSION['college_id'],$type,synk_workload_removal_ids($_POST));
    synk_remove_workload_response(['status'=>'ok','message'=>'Workload removed.']+$result);
} catch (SynkWorkloadStudentsException $error) { synk_remove_workload_response(['status'=>'blocked','code'=>'active_students','message'=>$error->getMessage(),'classes'=>$error->classes],409); }
catch (DomainException $error) { synk_remove_workload_response(['status'=>'error','message'=>$error->getMessage()],422); }
catch (Throwable $error) { error_log('Remove workload: '.$error->getMessage()); synk_remove_workload_response(['status'=>'error','message'=>'Unable to remove workload. Refresh the list before trying again.'],500); }