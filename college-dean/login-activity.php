<?php
session_start();
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/college_dean_login_report_helper.php';
synk_college_dean_require_login($conn);
$scope=synk_college_dean_dashboard_require_scope($conn,[],true);
if (($_SESSION['role'] ?? '')!=='college_dean') { http_response_code(403); exit('College Dean access is required.'); }
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
header('Cache-Control: no-store, private');
$defaults=synk_dean_login_report_defaults();
$config=$defaults+['today'=>$defaults['date_to'],'csrf_token'=>(string)$_SESSION['csrf_token'],'college_id'=>$scope['college_id'],'campus_id'=>$scope['campus_id']];
$collegeDeanPortalContext=synk_college_dean_portal_context($conn);
$collegeDeanPortalDisplayName=(string)($collegeDeanPortalContext['account_name'] ?? 'College Dean');
$collegeDeanPortalDisplayEmail=(string)($collegeDeanPortalContext['email'] ?? '');
$h=static function($value):string{return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');};
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Student Login Activity | Synk</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png">
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
  <link rel="stylesheet" href="../assets/vendor/css/core.css">
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css">
  <link rel="stylesheet" href="../assets/css/demo.css">
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
  <link rel="stylesheet" href="custom_css.css?v=<?= (int)filemtime(__DIR__.'/custom_css.css') ?>">
  <link rel="stylesheet" href="login-activity.css?v=<?= (int)filemtime(__DIR__.'/login-activity.css') ?>">
  <script src="../assets/vendor/js/helpers.js"></script><script src="../assets/js/config.js"></script>
</head>
<body>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
  <?php include __DIR__.'/sidebar.php'; ?>
  <div class="layout-page">
    <?php include __DIR__.'/navbar.php'; ?>
    <div class="content-wrapper">
      <main class="container-xxl flex-grow-1 container-p-y dean-login-report" id="deanLoginReport">
        <section class="card mb-4" aria-labelledby="deanLoginReportTitle"><div class="card-body p-4">
          <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
            <div><p class="card-section-title mb-1">College activity</p><h1 id="deanLoginReportTitle">Student Login Activity</h1><p class="text-muted mb-0">Trace student sign-ins across <?= $h($scope['college_name']) ?>.</p></div>
            <a href="students.php" class="btn btn-outline-secondary"><i class="bx bx-user-pin me-1" aria-hidden="true"></i>Student Directory</a>
          </div>
          <p class="small text-muted">The default range is today and the previous four days. Dates and times use Philippine Time. Login activity is independent of the academic term selected in the navigation bar.</p>
          <form id="deanLoginFilters" class="row g-3">
            <div class="col-6 col-lg-2"><label for="deanLoginFrom" class="form-label">From</label><input id="deanLoginFrom" type="date" class="form-control" value="<?= $h($defaults['date_from']) ?>" max="<?= $h($defaults['date_to']) ?>" required></div>
            <div class="col-6 col-lg-2"><label for="deanLoginTo" class="form-label">Through</label><input id="deanLoginTo" type="date" class="form-control" value="<?= $h($defaults['date_to']) ?>" max="<?= $h($defaults['date_to']) ?>" required></div>
            <div class="col-12 col-sm-5 col-lg-3"><label for="deanLoginProgram" class="form-label">Program</label><select id="deanLoginProgram" class="form-select"><option value="0">All college programs</option></select></div>
            <div class="col-12 col-sm-7 col-lg-5"><label for="deanLoginSearch" class="form-label">Search activity</label><input type="search" id="deanLoginSearch" class="form-control" maxlength="150" placeholder="Student ID, name, email or IP address"></div>
            <div class="col-12 d-flex flex-wrap align-items-center gap-2">
              <button type="submit" class="btn btn-primary" id="deanLoginApply"><i class="bx bx-search me-1" aria-hidden="true"></i>Apply Filters</button>
              <button type="button" class="btn btn-outline-secondary" id="deanLoginReset">Reset to 5 days</button>
              <button type="button" class="btn btn-outline-primary" id="deanLoginRefresh"><i class="bx bx-refresh me-1" aria-hidden="true"></i>Refresh</button>
              <div class="dean-login-shortcuts" role="group" aria-label="Quick date ranges"><span class="small text-muted">Quick range:</span><button type="button" class="btn btn-sm btn-outline-secondary" data-login-days="1">Today</button><button type="button" class="btn btn-sm btn-outline-secondary" data-login-days="5">5 days</button><button type="button" class="btn btn-sm btn-outline-secondary" data-login-days="7">7 days</button><button type="button" class="btn btn-sm btn-outline-secondary" data-login-days="30">30 days</button></div>
            </div>
          </form>
          <p class="small text-muted mt-2 mb-0">Both dates are included. Choose up to 366 days at a time.</p>
          <div id="deanLoginTrace" class="dean-login-trace mt-3" hidden><span id="deanLoginTraceLabel"></span><button type="button" class="btn btn-sm btn-outline-primary" id="deanLoginClearTrace">Show all students</button></div>
          <div id="deanLoginNotice" class="alert alert-danger mt-3 mb-0 d-none" role="alert"></div>
        </div></section>
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-3 small text-muted" role="status"><span id="deanLoginRange">Loading activity...</span><span id="deanLoginUpdated"></span></div>
        <div id="deanLoginSummary" class="dean-login-kpis mb-4" aria-live="polite"></div>
        <div class="dean-login-insights mb-4">
          <section class="card" aria-labelledby="deanLoginDailyTitle"><div class="card-body p-4">
            <div class="d-flex flex-wrap justify-content-between gap-2 mb-2"><h2 id="deanLoginDailyTitle">Daily sign-in activity</h2><div class="dean-login-legend small"><span><i class="dean-login-dot is-success" aria-hidden="true"></i>Successful</span><span><i class="dean-login-dot is-failure" aria-hidden="true"></i>Failed</span></div></div>
            <p class="small text-muted">Compare completed logins and failed attempts for each day.</p>
            <div class="dean-login-daily-scroll" id="deanLoginDaily" tabindex="0" role="region" aria-label="Daily sign-in statistics"></div>
            <h3 class="mt-4">Common failure reasons</h3><div id="deanLoginReasons" class="dean-login-reasons"></div>
          </div></section>
          <section class="card" aria-labelledby="deanLoginAttentionTitle"><div class="card-body p-4">
            <h2 id="deanLoginAttentionTitle">Students with failed attempts</h2><p class="small text-muted">Up to five students with the most failures in this range. Trace a student to review their attempts.</p>
            <div id="deanLoginAttention"></div>
            <p class="small text-muted mt-3 mb-0">A failed attempt using a student's email does not prove that the student made it. Browser and device details are reported by the connecting device.</p>
          </div></section>
        </div>
        <section class="card" aria-labelledby="deanLoginHistoryTitle"><div class="card-body p-4">
          <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
            <div><h2 id="deanLoginHistoryTitle">Login history</h2><p class="small text-muted mb-0">Latest attempts first. Viewing this page does not add logins.</p></div>
            <div class="dean-login-table-controls"><div><label class="form-label" for="deanLoginOutcome">History outcome</label><select id="deanLoginOutcome" class="form-select form-select-sm"><option value="all">All attempts</option><option value="success">Successful logins</option><option value="failure">Failed attempts</option></select></div><div><label for="deanLoginPageSize" class="form-label">Per page</label><select id="deanLoginPageSize" class="form-select form-select-sm"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select></div></div>
          </div>
          <p class="small text-muted">Statistics above include both outcomes for the selected dates, program and search. The outcome filter applies to history only.</p>
          <div class="table-responsive" tabindex="0" role="region" aria-label="Student login history"><table class="table dean-login-history"><caption class="visually-hidden">Student sign-in attempts, newest first, in Philippine Time</caption><thead><tr><th scope="col">Date and time</th><th scope="col">Student</th><th scope="col">Result</th><th scope="col">Reason</th><th scope="col">Email used</th><th scope="col">Device and browser</th><th scope="col">IP address</th></tr></thead><tbody id="deanLoginRows"><tr><td colspan="7" class="text-muted text-center py-4">Loading login activity...</td></tr></tbody></table></div>
          <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3"><span id="deanLoginPageInfo" class="small text-muted" role="status"></span><nav aria-label="Login history pages"><div class="d-flex gap-2"><button type="button" id="deanLoginPrevious" class="btn btn-sm btn-outline-primary" disabled>Previous</button><button type="button" id="deanLoginNext" class="btn btn-sm btn-outline-primary" disabled>Next</button></div></nav></div>
          <p class="small text-muted mt-3 mb-0">Includes attempts linked to students in your college directory. History begins when tracking was enabled. Refresh to include new arrivals.</p>
        </div></section>
      </main>
      <?php include dirname(__DIR__).'/footer.php'; ?>
    </div>
  </div>
</div><div class="layout-overlay layout-menu-toggle"></div></div>
<script id="deanLoginReportConfig" type="application/json"><?= json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="../assets/vendor/libs/jquery/jquery.js"></script><script src="../assets/vendor/libs/popper/popper.js"></script><script src="../assets/vendor/js/bootstrap.js"></script><script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script><script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script>
<script src="login-activity.js?v=<?= (int)filemtime(__DIR__.'/login-activity.js') ?>"></script>
</body></html>
