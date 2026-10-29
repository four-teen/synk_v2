<?php
session_start();ob_start();
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/student_dashboard_helper.php';
synk_student_require_login($conn);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET') { http_response_code(405);header('Allow: GET');exit('Use GET.'); }
$student=synk_student_fetch_directory_record_by_student_id($conn,(int)$_SESSION['student_id']);
if (!$student) { http_response_code(404);exit('Student record unavailable.'); }
$studentPortalDisplayName=synk_student_directory_display_name($student);
$studentPortalDisplayEmail=(string)$student['email_address'];$studentPortalPreviewMode=false;
$financialAccount=null;$financialError='';$reading=false;
try {
    require_once __DIR__.'/../backend/management_student_account_helper.php';
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);$reading=true;
    // Only the Management financial term is selectable. Academic scope is server-owned.
    $financialViewTerm=synk_management_student_financial_view_term($conn,(int)$_SESSION['student_id'],$_GET);
    $financialAccount=synk_management_student_account($conn,(int)$_SESSION['student_id'],$financialViewTerm);
    $conn->commit();$reading=false;
} catch (DomainException $error) {
    if ($reading) $conn->rollback();
    http_response_code(400);$financialError=$error->getMessage();
} catch (Throwable $error) {
    if ($reading) $conn->rollback();
    error_log('Student finances: '.$error->getMessage());http_response_code(503);
    $financialError='Your financial information is temporarily unavailable. Please try again later.';
}
?>
<!DOCTYPE html><html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title>My Finances | Synk Student</title>
<link rel="icon" href="../assets/img/favicon/synk-icon.png"><link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css"><link rel="stylesheet" href="../assets/vendor/css/core.css"><link rel="stylesheet" href="../assets/vendor/css/theme-default.css"><link rel="stylesheet" href="../assets/css/demo.css"><link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css"><link rel="stylesheet" href="custom_css.css?v=<?= (int)filemtime(__DIR__.'/custom_css.css') ?>"><link rel="stylesheet" href="../management/student-financial-page.css?v=<?= (int)filemtime(__DIR__.'/../management/student-financial-page.css') ?>">
<script src="../assets/vendor/js/helpers.js"></script><script src="../assets/js/config.js"></script></head>
<body class="student-portal student-finances"><div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php require __DIR__.'/sidebar.php'; ?><div class="layout-page"><?php require __DIR__.'/navbar.php'; ?><main class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y">
<?php if ($financialError): ?><h1 class="fs-3">My Finances</h1><div class="alert alert-danger" role="alert"><?= synk_student_h($financialError) ?></div><?php else: require __DIR__.'/../management/student-financial-page.php';endif; ?>
</div><?php require __DIR__.'/../footer.php'; ?></main></div></div><div class="layout-overlay layout-menu-toggle"></div></div>
<script src="../assets/vendor/libs/jquery/jquery.js"></script><script src="../assets/vendor/js/bootstrap.js"></script><script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script><script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script></body></html>
