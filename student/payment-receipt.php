<?php
session_start();
require __DIR__.'/../backend/db.php';
require __DIR__.'/../backend/student_dashboard_helper.php';
synk_student_require_login($conn);
require __DIR__.'/../backend/management_payment_helper.php';
try { $receipt=synk_management_payment_receipt($conn,synk_management_input_id($_GET['payment_id'] ?? null),(int)$_SESSION['student_id']); }
catch (OutOfBoundsException|DomainException $error) { http_response_code(404);exit('Receipt unavailable.'); }
header('Cache-Control: no-store, private');
require __DIR__.'/../management/payment-receipt-view.php';
