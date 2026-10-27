<?php
session_start();
require __DIR__.'/../backend/db.php';
require __DIR__.'/../backend/management_payment_helper.php';
$scope=synk_management_require_scope($conn);
try { $receipt=synk_management_payment_receipt($conn,synk_management_input_id($_GET['payment_id'] ?? null),null,$scope); }
catch (OutOfBoundsException|DomainException $error) { http_response_code(404);exit('Receipt unavailable.'); }
header('Cache-Control: no-store, private');
require __DIR__.'/payment-receipt-view.php';
