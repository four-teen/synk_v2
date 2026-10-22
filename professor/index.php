<?php
session_start();
ob_start();
header('Cache-Control: no-store, private, max-age=0');

include '../backend/db.php';
require_once '../backend/professor_portal_helper.php';
require_once '../backend/professor_dashboard_helper.php';

synk_professor_require_login($conn);

$professorPortalContext = synk_professor_resolve_portal_context($conn);
$facultyId = (int)($professorPortalContext['faculty_id'] ?? 0);
$facultyLink = is_array($professorPortalContext['faculty_link'] ?? null)
    ? $professorPortalContext['faculty_link']
    : null;
$facultyName = trim((string)($professorPortalContext['faculty_name'] ?? 'Professor'));
$accountName = trim((string)($professorPortalContext['account_name'] ?? 'Professor'));
$professorEmail = trim((string)($professorPortalContext['email'] ?? (string)($_SESSION['email'] ?? '')));
$facultyIsLinked = !empty($professorPortalContext['faculty_is_linked']);
$facultyIsActive = !array_key_exists('faculty_is_active', $professorPortalContext)
    || !empty($professorPortalContext['faculty_is_active']);
$currentTerm = synk_fetch_current_academic_term($conn);

$workloadTermOptions = $facultyId > 0
    ? synk_professor_fetch_assigned_term_rows($conn, $facultyId)
    : [];
$dashboardCurrentWorkload = $facultyId > 0 && $facultyIsActive && (int)$currentTerm['ay_id'] > 0 && (int)$currentTerm['semester'] > 0
    ? synk_professor_fetch_workload_rows($conn, $facultyId, (int)$currentTerm['ay_id'], (int)$currentTerm['semester']) : [];
$dashboardWorkloadChart = synk_professor_dashboard_workload_chart($dashboardCurrentWorkload, $currentTerm);
$teachingSummary = synk_professor_dashboard_term_summary($workloadTermOptions, $currentTerm, $dashboardCurrentWorkload);
$dashboardProfile = null;
try {
    $dashboardProfile = synk_professor_dashboard_profile($conn, (int)$_SESSION['user_id'], $facultyLink);
} catch (Throwable $exception) {
    error_log('Professor dashboard profile summary failed: ' . $exception->getMessage());
}
$profileCompletion = $dashboardProfile['completion'] ?? null;
$professorSidebarCompletion = $profileCompletion;
$dashboardActions = synk_professor_dashboard_actions($dashboardProfile, $facultyIsLinked, $facultyIsActive);
$facultyStatusLabel = 'Faculty linked';
if (!$facultyIsLinked) {
    $facultyStatusLabel = 'Needs faculty link';
} elseif (!$facultyIsActive) {
    $facultyStatusLabel = 'Inactive faculty record';
}

$workloadUrl = $teachingSummary['workload_url'];

$professorPortalDisplayName = $facultyName !== '' ? $facultyName : $accountName;
$professorPortalDisplayEmail = $professorEmail;
$professorPortalFacultyStatusLabel = $facultyStatusLabel;
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../assets/"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />

    <title>Professor Dashboard | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/apex-charts/apex-charts.css" />
    <link rel="stylesheet" type="text/css" href="custom_css.css" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <link rel="stylesheet" href="dashboard.css?v=<?php echo filemtime(__DIR__ . '/dashboard.css'); ?>" />
  </head>

  <body>
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <?php include 'sidebar.php'; ?>

        <div class="layout-page">
          <?php include 'navbar.php'; ?>

          <div class="content-wrapper">
            <?php include __DIR__ . '/../backend/professor_dashboard_view.php'; ?>

            <?php include '../footer.php'; ?>
          </div>
        </div>
      </div>
      <div class="layout-overlay layout-menu-toggle"></div>
    </div>

    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>
    <script src="dashboard-charts.js?v=<?php echo filemtime(__DIR__ . '/dashboard-charts.js'); ?>"></script>
    <script src="dashboard.js?v=<?php echo filemtime(__DIR__ . '/dashboard.js'); ?>"></script>
  </body>
</html>
