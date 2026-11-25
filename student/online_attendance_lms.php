<?php
session_start();
ob_start();
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/student_portal_helper.php';
synk_student_require_login($conn);
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); http_response_code(405); exit; }
$student = synk_student_fetch_directory_record_by_student_id($conn,(int)$_SESSION['student_id']);
if (!$student) { http_response_code(404); exit('Student record unavailable.'); }
$studentPortalDisplayName = synk_student_directory_display_name($student);
$studentPortalDisplayEmail = $student['email_address'];
$studentPortalViewTerm = synk_fetch_current_academic_term($conn);
if (empty($_SESSION['student_online_attendance_csrf'])) $_SESSION['student_online_attendance_csrf'] = bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="robots" content="noindex,nofollow"><title>Online Attendance | Synk Student</title>
  <link rel="icon" href="../assets/img/favicon/synk-icon.png">
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
  <link rel="stylesheet" href="../assets/vendor/css/core.css"><link rel="stylesheet" href="../assets/vendor/css/theme-default.css">
  <link rel="stylesheet" href="../assets/css/demo.css"><link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
  <link rel="stylesheet" href="custom_css.css?v=<?= (int)filemtime(__DIR__.'/custom_css.css') ?>">
  <link rel="stylesheet" href="online_attendance_lms.css?v=<?= (int)filemtime(__DIR__.'/online_attendance_lms.css') ?>">
  <script src="../assets/vendor/js/helpers.js"></script><script src="../assets/js/config.js"></script>
</head>
<body class="student-portal">
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
  <?php require __DIR__.'/sidebar.php'; ?>
  <div class="layout-page">
    <?php require __DIR__.'/navbar.php'; ?>
    <main class="content-wrapper" id="main-content">
      <div class="container-xxl flex-grow-1 container-p-y student-online-attendance" id="studentOnlineAttendance">
        <header class="student-oa-heading">
          <div><h1>Online Attendance</h1><p><i class="bx bx-time-five" aria-hidden="true"></i> Philippine time (UTC+8)</p></div>
          <div class="student-oa-actions">
            <a class="btn btn-outline-secondary" href="assessments_lms.php"><i class="bx bx-arrow-back me-1" aria-hidden="true"></i>My subjects</a>
            <button type="button" class="btn btn-outline-primary" id="studentOaRefresh"><i class="bx bx-refresh me-1" aria-hidden="true"></i>Refresh</button>
          </div>
        </header>
        <p id="studentOaNotice" class="student-oa-notice" role="status">Loading attendance…</p>
        <div id="studentOaError" class="alert alert-danger" role="alert" hidden></div>
        <div class="student-oa-filter mb-3" id="studentOaFilter" hidden>
          <label class="form-label" for="studentOaSubject">Subject</label>
          <select class="form-select" id="studentOaSubject"><option value="">All subjects</option></select>
        </div>
        <div id="studentOaList"></div>
      </div>
      <?php require __DIR__.'/../footer.php'; ?>
    </main>
  </div>
</div><div class="layout-overlay layout-menu-toggle"></div></div>
<script id="studentOnlineAttendanceConfig" type="application/json"><?= json_encode([
    'endpoint'=>'online_attendance_api_lms.php','csrf_token'=>$_SESSION['student_online_attendance_csrf'],
],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="../assets/vendor/libs/jquery/jquery.js"></script><script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script><script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script>
<script src="../assets/js/online_attendance_lms.js?v=<?= (int)filemtime(__DIR__.'/../assets/js/online_attendance_lms.js') ?>" defer></script>
<script src="online_attendance_lms.js?v=<?= (int)filemtime(__DIR__.'/online_attendance_lms.js') ?>" defer></script>
</body></html>
