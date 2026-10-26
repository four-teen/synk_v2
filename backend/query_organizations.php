<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/db.php';
require_once __DIR__.'/management_organization_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
function synk_organization_response(array $data, int $status = 200): void { http_response_code($status); echo json_encode($data); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { header('Allow: POST'); synk_organization_response(['status'=>'error','message'=>'Use a POST request.'],405); }
$account = !empty($_SESSION['user_id']) ? synk_find_useraccount_by_id($conn,(int)$_SESSION['user_id']) : null;
if (($_SESSION['role'] ?? '')!=='admin' || !$account || ($account['status'] ?? '')!=='active' || !synk_useraccount_has_active_role($conn,$account,'admin')) synk_organization_response(['status'=>'error','message'=>'Administrator access is required.'],403);
foreach ($_POST as $value) if (!is_scalar($value)) synk_organization_response(['status'=>'error','message'=>'Invalid request.'],400);
if (empty($_SESSION['csrf_token']) || !is_string($_POST['csrf_token'] ?? null) || !hash_equals($_SESSION['csrf_token'],$_POST['csrf_token'])) synk_organization_response(['status'=>'error','message'=>'Your session changed. Reload the page.'],403);
try {
    $action = (string)($_POST['action'] ?? '');
    if ($action==='get') synk_organization_response(['status'=>'ok','record'=>synk_organization_get($conn,synk_management_input_id($_POST['organization_id'] ?? ''))]);
    synk_organization_response(['status'=>'ok']+synk_organization_write($conn,$action,$_POST));
} catch (OutOfBoundsException $e) { synk_organization_response(['status'=>'error','message'=>$e->getMessage()],404); }
catch (SynkManagementConflict $e) { synk_organization_response(['status'=>'error','message'=>$e->getMessage()],409); }
catch (DomainException $e) { synk_organization_response(['status'=>'error','message'=>$e->getMessage()],422); }
catch (Throwable $e) { error_log('Organizations: '.$e->getMessage()); synk_organization_response(['status'=>'error','message'=>'Unable to complete the request. Please try again.'],503); }
