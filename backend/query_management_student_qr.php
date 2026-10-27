<?php
session_start();
require_once __DIR__.'/db.php';
require_once __DIR__.'/student_portal_helper.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') { http_response_code(405);header('Allow: POST');echo json_encode(['status'=>'error','message'=>'Use POST.']);exit; }
if (($_SESSION['role'] ?? '')!=='student' || empty($_SESSION['student_id'])) { http_response_code(401);echo json_encode(['status'=>'error','message'=>'Sign in to your student account.']);exit; }
synk_student_require_login($conn);
if (!is_string($_POST['csrf_token'] ?? null) || empty($_SESSION['management_student_qr_csrf']) || !hash_equals($_SESSION['management_student_qr_csrf'],$_POST['csrf_token'])) { http_response_code(403);echo json_encode(['status'=>'error','message'=>'Reload the page before showing your QR.']);exit; }
try {
    require_once __DIR__.'/management_attendance_helper.php';
    // The identity always comes from the authenticated session; no client ID.
    $code=synk_management_student_qr($conn,(int)$_SESSION['student_id']);
    echo json_encode(['status'=>'ok','qr_code'=>$code]);
} catch (Throwable $error) { error_log('Student attendance QR: '.$error->getMessage());http_response_code(503);echo json_encode(['status'=>'error','message'=>'Your attendance QR is temporarily unavailable.']); }
