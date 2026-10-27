<?php
session_start();ob_start();
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/student_dashboard_helper.php';
synk_student_require_login($conn);
$student=synk_student_fetch_directory_record_by_student_id($conn,(int)$_SESSION['student_id']);
if (!$student) { http_response_code(404);exit('Student record unavailable.'); }
header('Cache-Control: no-store, private');
$studentPortalDisplayName=synk_student_directory_display_name($student);
$studentPortalDisplayEmail=$student['email_address'];$studentPortalPreviewMode=false;
if (empty($_SESSION['management_student_qr_csrf'])) $_SESSION['management_student_qr_csrf']=bin2hex(random_bytes(32));
?>
<!DOCTYPE html><html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>My QR | Synk Student</title>
<link rel="icon" href="../assets/img/favicon/synk-icon.png"><link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css"><link rel="stylesheet" href="../assets/vendor/css/core.css"><link rel="stylesheet" href="../assets/vendor/css/theme-default.css"><link rel="stylesheet" href="../assets/css/demo.css"><link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css"><link rel="stylesheet" href="custom_css.css"><link rel="stylesheet" href="attendance-qr.css?v=<?= (int)filemtime(__DIR__.'/attendance-qr.css') ?>">
<script src="../assets/vendor/js/helpers.js"></script><script src="../assets/js/config.js"></script></head>
<body class="student-portal"><div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php require __DIR__.'/sidebar.php'; ?><div class="layout-page"><?php require __DIR__.'/navbar.php'; ?><main class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">
<section class="card student-attendance-card" aria-labelledby="student-qr-title"><span class="badge bg-label-primary mb-3">EVENT ATTENDANCE</span><h1 id="student-qr-title">My QR</h1><p class="text-muted mb-0">Show this code to Management to record your attendance.</p>
<div id="student-attendance-qr" class="student-attendance-qr" role="img" aria-label="Your student attendance QR code"><span>Loading QR…</span></div>
<h2><?= synk_student_h($studentPortalDisplayName) ?></h2><strong class="text-primary student-attendance-id"><?= synk_student_h($student['student_number']) ?></strong>
<a class="btn btn-primary student-qr-save" id="student-qr-save" hidden><i class="bx bx-download me-2" aria-hidden="true"></i>Save QR to phone</a>
<p class="small text-muted mt-2 mb-0" id="student-qr-save-help">Saves a PNG image with your full name and student ID for future use.</p>
<p class="text-muted mt-3 mb-0">Keep the whole QR visible and increase your screen brightness when scanning. Wait for Management to confirm your name and attendance. Keep your QR private.</p>
<div class="alert alert-danger mt-3" id="student-qr-error" role="alert" hidden></div><button class="btn btn-outline-primary mt-3" id="student-qr-retry" type="button" hidden>Try again</button></section>
</div><?php require __DIR__.'/../footer.php'; ?></main></div></div><div class="layout-overlay layout-menu-toggle"></div></div>
<script type="application/json" id="student-attendance-config"><?= json_encode(['csrf_token'=>$_SESSION['management_student_qr_csrf'],'endpoint'=>'../backend/query_management_student_qr.php','full_name'=>$studentPortalDisplayName,'student_number'=>(string)$student['student_number']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="../assets/vendor/libs/jquery/jquery.js"></script><script src="../assets/vendor/js/bootstrap.js"></script><script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script><script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script><script src="../assets/vendor/libs/management-qr/qrcode.js"></script><script src="attendance-qr.js?v=<?= (int)filemtime(__DIR__.'/attendance-qr.js') ?>" defer></script></body></html>
