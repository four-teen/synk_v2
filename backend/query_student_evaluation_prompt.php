<?php
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/db.php';
require_once __DIR__.'/student_evaluation_prompt_helper.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');
$respond=static function(array $data,int $code=200):void{http_response_code($code);echo json_encode($data);exit;};
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { header('Allow: POST');$respond(['status'=>'error'],405); }
if (($_SESSION['role'] ?? '')!=='student' || empty($_SESSION['student_id'])) $respond(['status'=>'signed_out'],401);
if (!is_string($_POST['csrf_token'] ?? null) || empty($_SESSION['student_evaluation_prompt_csrf']) || !hash_equals($_SESSION['student_evaluation_prompt_csrf'],$_POST['csrf_token'])) $respond(['status'=>'error'],403);
try {
    if (!synk_student_login_session_record($conn)) $respond(['status'=>'signed_out'],401);
    // A background evaluation check must never keep an idle student signed in.
    synk_student_presence_check($conn,false);
    $respond(['status'=>'ok','evaluation'=>synk_student_evaluation_prompt_state($conn,(int)$_SESSION['student_id'])]);
} catch (DomainException $error) { $respond(['status'=>'signed_out'],401); }
catch (Throwable $error) { error_log('Student evaluation prompt: '.$error->getMessage());$respond(['status'=>'error'],503); }
