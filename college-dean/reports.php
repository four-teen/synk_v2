<?php
session_start();
ob_start();

require_once '../backend/db.php';
require_once '../backend/college_dean_faculty_students_helper.php';

synk_college_dean_require_login($conn);
$deanScope = synk_college_dean_dashboard_require_scope($conn, [], true);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$deanReportConfig = ['csrf_token'=>(string)$_SESSION['csrf_token'], 'college_id'=>$deanScope['college_id'], 'campus_id'=>$deanScope['campus_id']];

$collegeDeanPortalContext = synk_college_dean_portal_context($conn);
$collegeDeanPortalDisplayName = (string)($collegeDeanPortalContext['account_name'] ?? 'College Dean');
$collegeDeanPortalDisplayEmail = (string)($collegeDeanPortalContext['email'] ?? '');
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>College Dean Reports | Synk</title>
    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" type="text/css" href="custom_css.css" />
    <link rel="stylesheet" href="reports.css?v=<?php echo (int)filemtime(__DIR__ . '/reports.css'); ?>" />
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
            <main class="container-xxl flex-grow-1 container-p-y" id="deanFacultyReport">
              <div class="card dean-page-card mb-4">
                <div class="card-body p-4">
                  <div class="d-flex flex-wrap justify-content-between gap-3 mb-3">
                    <div><p class="card-section-title mb-1">Reports</p><h4 class="dean-page-title mb-2">Faculty Student Summary</h4><p class="dean-page-copy mb-0">Review imported student totals and classes still missing student lists.</p></div>
                    <a href="faculty-workload.php" class="btn btn-outline-primary align-self-start"><i class="bx bx-briefcase-alt-2 me-1" aria-hidden="true"></i>Faculty Workload</a>
                  </div>
                  <div class="row g-3 align-items-end">
                    <div class="col-sm-6 col-lg-3"><label for="deanReportTerm" class="form-label">Academic Term</label><select id="deanReportTerm" class="form-select" disabled><option value="">Loading terms…</option></select></div>
                    <div class="col-sm-6 col-lg-3"><label for="deanReportProgram" class="form-label">Program</label><select id="deanReportProgram" class="form-select" disabled><option value="0">All programs</option></select></div>
                    <div class="col-sm-6 col-lg-3"><label for="deanReportStatus" class="form-label">Student Lists</label><select id="deanReportStatus" class="form-select" disabled><option value="all">All faculty</option><option value="missing">With missing lists</option><option value="complete">All classes have lists</option><option value="unassigned">No assigned classes</option></select></div>
                    <div class="col-sm-6 col-lg-3"><label for="deanReportSearch" class="form-label">Faculty Name</label><input id="deanReportSearch" type="search" class="form-control" placeholder="Search faculty" maxlength="150" disabled /></div>
                  </div>
                  <div class="d-flex flex-wrap gap-2 mt-3"><button id="deanReportRefresh" type="button" class="btn btn-sm btn-outline-primary" disabled><i class="bx bx-refresh me-1" aria-hidden="true"></i>Refresh</button><button id="deanReportReset" type="button" class="btn btn-sm btn-outline-secondary" disabled>Reset Filters</button></div>
                  <div id="deanReportNotice" class="alert mt-3 mb-0 d-none" role="status"></div>
                </div>
              </div>
              <div class="row g-3 mb-4" id="deanReportSummary" hidden>
                <div class="col-6 col-xl-3"><div class="card dean-page-card h-100"><div class="card-body"><span class="dean-page-copy">Unique Students</span><h4 class="mt-2 mb-0" id="deanReportUnique">0</h4></div></div></div>
                <div class="col-6 col-xl-3"><div class="card dean-page-card h-100"><div class="card-body"><span class="dean-page-copy">Total Class Enrollments</span><h4 class="mt-2 mb-0" id="deanReportEnrollments">0</h4></div></div></div>
                <div class="col-6 col-xl-3"><div class="card dean-page-card h-100"><div class="card-body"><span class="dean-page-copy">Classes Without Student Lists</span><h4 class="mt-2 mb-0" id="deanReportMissing">0</h4></div></div></div>
                <div class="col-6 col-xl-3"><div class="card dean-page-card h-100"><div class="card-body"><span class="dean-page-copy">Faculty with Missing Lists</span><h4 class="mt-2 mb-0" id="deanReportFacultyMissing">0</h4></div></div></div>
              </div>
              <div class="card dean-page-card">
                <div class="card-body p-4 pb-3"><h5 class="mb-2">Student Lists by Faculty</h5><p class="small dean-page-copy mb-0">Unique Students counts each student once. Total Class Enrollments counts each student once per class. Lecture and lab share one roster; college totals also count shared students and classes once.</p></div>
                <div class="table-responsive"><table class="table dean-report-table mb-0"><thead><tr><th scope="col">Faculty</th><th scope="col">Assigned<br>Classes</th><th scope="col">With<br>Student Lists</th><th scope="col">Without<br>Student Lists</th><th scope="col">Unique<br>Students</th><th scope="col">Total Class<br>Enrollments</th><th scope="col">Details</th></tr></thead><tbody id="deanReportRows" aria-live="polite"><tr><td colspan="7" class="text-center py-4 text-muted">Loading faculty report…</td></tr></tbody></table></div>
                <div class="card-body py-3"><p id="deanReportCaption" class="small text-muted mb-0"></p></div>
              </div>
              <p class="small dean-page-copy mt-3 mb-0">Consolidated faculty workload reports are available on the <a href="index.php">dashboard</a>, where you can preview, print, or export them.</p>
            </main>
            <?php include '../footer.php'; ?>
            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>
      <div class="layout-overlay layout-menu-toggle"></div>
    </div>
    <div class="modal fade" id="deanFacultyClassesModal" tabindex="-1" aria-labelledby="deanFacultyClassesTitle" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><div><h5 class="modal-title" id="deanFacultyClassesTitle">Faculty Classes</h5><p id="deanFacultyClassesSubtitle" class="small text-muted mt-1 mb-0"></p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><p id="deanFacultyClassesTotals" class="small dean-page-copy mb-0"></p><select id="deanFacultyClassStatus" class="form-select dean-report-class-filter" aria-label="Filter faculty class student lists"><option value="all">All classes</option><option value="missing">Without student lists</option><option value="with">With student lists</option></select></div><div id="deanFacultyClassesNotice" class="alert alert-info small" hidden>Imports are available for the current academic term. This report shows a previous term.</div><div id="deanFacultyClassesContent"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
      </div></div>
    </div>
    <script id="deanReportConfig" type="application/json"><?php echo json_encode($deanReportConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="reports.js?v=<?php echo (int)filemtime(__DIR__ . '/reports.js'); ?>"></script>
  </body>
</html>
