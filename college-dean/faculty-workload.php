<?php
session_start();
ob_start();
require_once '../backend/db.php';
require_once '../backend/college_dean_workload_helper.php';
synk_college_dean_require_login($conn);
$deanScope = synk_college_dean_dashboard_require_scope($conn, [], true);
$deanTerm = synk_fetch_current_academic_term($conn);
$collegeDeanPortalContext = synk_college_dean_portal_context($conn);
$collegeDeanPortalDisplayName = (string)($collegeDeanPortalContext['account_name'] ?? 'College Dean');
$collegeDeanPortalDisplayEmail = (string)($collegeDeanPortalContext['email'] ?? '');
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$deanWorkloadConfig = ['csrf_token'=>(string)$_SESSION['csrf_token'], 'ay_id'=>$deanTerm['ay_id'],
    'semester'=>$deanTerm['semester'], 'college_id'=>$deanScope['college_id'], 'campus_id'=>$deanScope['campus_id']];
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Faculty Workload | College Dean | Synk</title>
    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="custom_css.css" />
    <link rel="stylesheet" href="faculty-workload.css?v=<?php echo (int)filemtime(__DIR__ . '/faculty-workload.css'); ?>" />
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
            <main class="container-xxl flex-grow-1 container-p-y" id="deanWorkload">
              <div class="card dean-page-card mb-4">
                <div class="card-body p-4">
                  <div class="dean-workload-heading">
                    <div>
                      <p class="card-section-title mb-1">Academic Operations</p>
                      <h4 class="dean-page-title mb-2">Faculty Workload</h4>
                      <p class="dean-page-copy mb-3">Review assigned classes and manage their student lists.</p>
                      <label for="deanFaculty" class="form-label">Faculty</label>
                      <div class="d-flex flex-wrap gap-2 align-items-center">
                        <select id="deanFaculty" class="form-select dean-faculty-select" disabled><option value="">Loading faculty…</option></select>
                        <button type="button" id="deanRefresh" class="btn btn-outline-primary" disabled><i class="bx bx-refresh me-1" aria-hidden="true"></i>Refresh</button>
                      </div>
                      <a href="reports.php" class="small d-inline-flex align-items-center mt-2"><i class="bx bx-bar-chart-alt-2 me-1" aria-hidden="true"></i>Faculty Student Summary</a>
                    </div>
                    <div class="dean-faculty-photo-panel">
                      <span class="badge bg-label-primary"><?php echo htmlspecialchars($deanTerm['term_text'], ENT_QUOTES, 'UTF-8'); ?></span>
                      <img id="deanFacultyPhoto" class="dean-faculty-photo" src="faculty-placeholder.svg" alt="Faculty profile placeholder" width="128" height="128" />
                    </div>
                  </div>
                  <div id="deanNotice" class="alert mt-3 mb-0 d-none" role="status"></div>
                </div>
              </div>
              <div class="row row-cols-2 row-cols-lg-5 g-3 mb-4" id="deanSummary" hidden>
                <div class="col"><div class="card dean-page-card"><div class="card-body"><span class="dean-page-copy">Assigned Classes</span><h4 class="mt-2 mb-0" id="deanClassCount">0</h4></div></div></div>
                <div class="col"><div class="card dean-page-card"><div class="card-body"><span class="dean-page-copy">Preparations</span><h4 class="mt-2 mb-0" id="deanPreparationCount">0</h4></div></div></div>
                <div class="col"><div class="card dean-page-card"><div class="card-body"><span class="dean-page-copy">Faculty Load</span><h4 class="mt-2 mb-0" id="deanLoadCount">0</h4></div></div></div>
                <div class="col"><div class="card dean-page-card"><div class="card-body"><span class="dean-page-copy">Classes with Student Lists</span><h4 class="mt-2 mb-0" id="deanRosterCount">0</h4></div></div></div>
                <div class="col"><div class="card dean-page-card"><div class="card-body"><span class="dean-page-copy">Classes Without Student Lists</span><h4 class="mt-2 mb-0" id="deanMissingCount">0</h4></div></div></div>
              </div>
              <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3" id="deanClassFilters" hidden>
                <div><label for="deanRosterFilter" class="form-label">Student Lists</label><select id="deanRosterFilter" class="form-select"><option value="all">All classes</option><option value="with">With student lists</option><option value="missing">Without student lists</option></select></div>
                <p id="deanClassVisibility" class="small text-muted mb-1" role="status"></p>
              </div>
              <div id="deanClasses" aria-live="polite"><div class="dean-empty-state"><i class="bx bx-user" aria-hidden="true"></i><h5>Select a faculty member</h5><p class="mb-0">Their assigned classes for the current academic term will appear here.</p></div></div>
            </main>
            <?php include '../footer.php'; ?>
            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>
      <div class="layout-overlay layout-menu-toggle"></div>
    </div>
    <div class="modal fade" id="deanRosterModal" tabindex="-1" aria-labelledby="deanRosterTitle" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><div><h5 class="modal-title" id="deanRosterTitle">Class Students</h5><p class="small text-muted mb-0 mt-1" id="deanRosterSubtitle"></p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <div class="d-flex flex-wrap gap-2 justify-content-between mb-3"><span class="badge bg-label-primary align-self-center" id="deanStudentTotal">0 students</span><input type="search" id="deanStudentSearch" class="form-control dean-roster-search" placeholder="Search name or student ID" aria-label="Search class students" /></div>
          <div id="deanRosterContent"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
      </div></div>
    </div>
    <div class="modal fade" id="deanImportModal" tabindex="-1" aria-labelledby="deanImportTitle" aria-hidden="true" data-bs-backdrop="static">
      <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><div><h5 class="modal-title" id="deanImportTitle">Import Class Students</h5><p class="small text-muted mb-0 mt-1" id="deanImportSubtitle"></p></div><button type="button" id="deanImportClose" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <form id="deanImportForm">
            <label for="deanCsvFile" class="form-label">Exam Permits CSV</label>
            <div class="d-flex flex-wrap gap-2"><input type="file" id="deanCsvFile" class="form-control dean-csv-input" accept=".csv,text/csv" required /><button type="submit" id="deanPreviewButton" class="btn btn-primary"><i class="bx bx-search-alt me-1" aria-hidden="true"></i>Preview Import</button></div>
            <p class="small text-muted mt-2 mb-0">Use the subject CSV exported from the old system. Maximum 2 MB and 2,000 students.</p>
            <p class="small text-muted mt-1 mb-0">Students are matched by ID. One student record can belong to multiple classes.</p>
          </form>
          <div id="deanImportNotice" class="alert mt-3 mb-0 d-none" role="status"></div>
          <div id="deanImportPreview" class="mt-3" hidden></div>
        </div>
        <div class="modal-footer"><button type="button" id="deanImportCancel" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" id="deanConfirmButton" class="btn btn-primary" disabled hidden>Confirm Import</button></div>
      </div></div>
    </div>
    <script id="deanWorkloadConfig" type="application/json"><?php echo json_encode($deanWorkloadConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="faculty-workload.js?v=<?php echo (int)filemtime(__DIR__ . '/faculty-workload.js'); ?>"></script>
  </body>
</html>
