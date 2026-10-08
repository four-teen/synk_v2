<?php
session_start();
ob_start();

include '../backend/db.php';
require_once '../backend/program_chair_helper.php';

synk_program_chair_require_login($conn);

$programChairPortalContext = synk_program_chair_portal_context($conn);
$programChairPortalDisplayName = (string)($programChairPortalContext['account_name'] ?? 'Program Chair');
$programChairPortalDisplayEmail = (string)($programChairPortalContext['email'] ?? '');
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <title>Program Chair Students | Synk</title>
    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" type="text/css" href="custom_css.css" />
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
  </head>
  <body>
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <?php include 'sidebar.php'; ?>
        <div class="layout-page">
          <?php include 'navbar.php'; ?>
          <div class="content-wrapper">
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="card" style="border:1px solid #dce5f1;border-radius:22px;box-shadow:0 18px 38px rgba(67,89,113,.08);">
                <div class="card-body p-4">
                  <span class="badge bg-label-secondary text-uppercase">Students</span>
                  <h4 class="mt-3 mb-2">Student encoding and advising preparation</h4>
                  <p class="text-muted mb-3">This space is reserved for Program Chair student-side work such as first-year encoding, advising preparation, and verification follow-up before registrar approval.</p>
                  <div class="alert alert-warning mb-0">We can build this next around the enrollment header design so draft student records stay separate from final posted enrollment data.</div>
                </div>
              </div>
            </div>
            <?php include '../footer.php'; ?>
            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>
      <div class="layout-overlay layout-menu-toggle"></div>
    </div>
    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
  </body>
</html>
