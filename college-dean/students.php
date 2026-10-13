<?php
session_start();
ob_start();

require_once '../backend/db.php';
require_once '../backend/college_dean_students_helper.php';

synk_college_dean_require_login($conn);
$deanStudentsScope = synk_college_dean_dashboard_require_scope($conn, [], true);
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$deanStudentsViewTerm = synk_dean_view_term($conn);
$deanStudentsConfig = ['csrf_token'=>(string)$_SESSION['csrf_token'], 'college_id'=>$deanStudentsScope['college_id'], 'campus_id'=>$deanStudentsScope['campus_id'],
    'ay_id'=>$deanStudentsViewTerm['ay_id'], 'semester'=>$deanStudentsViewTerm['semester'], 'read_only'=>empty($deanStudentsViewTerm['is_current'])];

$collegeDeanPortalContext = synk_college_dean_portal_context($conn);
$collegeDeanPortalDisplayName = (string)($collegeDeanPortalContext['account_name'] ?? 'College Dean');
$collegeDeanPortalDisplayEmail = (string)($collegeDeanPortalContext['email'] ?? '');
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <title>College Dean Students | Synk</title>
    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" type="text/css" href="custom_css.css?v=<?= filemtime(__DIR__ . '/custom_css.css'); ?>" />
    <link rel="stylesheet" href="students.css?v=<?php echo (int)filemtime(__DIR__ . '/students.css'); ?>" />
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
            <main class="container-xxl flex-grow-1 container-p-y<?php echo $deanStudentsConfig['read_only'] ? ' dean-students-readonly' : ''; ?>" id="deanStudentDirectory">
              <div id="deanStudentViewOnlyNotice" class="alert alert-info" role="status"<?php echo !$deanStudentsConfig['read_only'] ? ' hidden' : ''; ?>>This view is for reviewing records. Choose the current term to add students, edit details, fill emails, or delete term records.</div>
              <div class="card dean-page-card mb-4">
                <div class="card-body p-4">
                  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                    <div><p class="card-section-title mb-1">Directory</p><h4 class="dean-page-title mb-2">Student Management</h4><p class="dean-page-copy mb-0">Manage your college's student profiles and imported class lists.</p></div>
                    <div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-primary" id="deanStudentAdd" disabled><i class="bx bx-user-plus me-1" aria-hidden="true"></i>Add Student</button><button type="button" class="btn btn-outline-primary" id="deanStudentEmailsOpen" disabled><i class="bx bx-envelope me-1" aria-hidden="true"></i>Fill Missing Emails</button><a href="faculty-workload.php" class="btn btn-outline-primary"><i class="bx bx-upload me-1" aria-hidden="true"></i>Import Students</a></div>
                  </div>
                  <form id="deanStudentsFilters" class="row g-3">
                    <div class="col-12 col-lg-3"><label class="form-label" for="deanStudentsSearch">Search students</label><input type="search" class="form-control" id="deanStudentsSearch" placeholder="Student ID or name" maxlength="150" /></div>
                    <div class="col-12 col-md-3 col-lg-3"><label class="form-label" for="deanStudentsTerm">Academic year / semester</label><select id="deanStudentsTerm" class="form-select" disabled><option value="all">Loading academic terms...</option></select></div>
                    <div class="col-12 col-md-3 col-lg-2"><label class="form-label" for="deanStudentsProgram">Program</label><select id="deanStudentsProgram" class="form-select" disabled><option value="0">All programs</option></select></div>
                    <div class="col-12 col-md-3 col-lg-2"><label class="form-label" for="deanStudentsSection">Class section</label><select id="deanStudentsSection" class="form-select" disabled><option value="0">All class sections</option></select></div>
                    <div class="col-12 col-md-3 col-lg-2"><label class="form-label" for="deanStudentsEmailStatus">Email status</label><select id="deanStudentsEmailStatus" class="form-select" disabled><option value="all">All emails</option><option value="missing">Missing email</option><option value="present">With email</option></select></div>
                    <div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit" id="deanStudentsSearchButton" disabled><i class="bx bx-search me-1" aria-hidden="true"></i>Search</button><button type="button" class="btn btn-outline-secondary" id="deanStudentsReset" disabled>Reset Filters</button><button type="button" class="btn btn-outline-primary" id="deanStudentsRefresh"><i class="bx bx-refresh me-1" aria-hidden="true"></i>Refresh</button></div>
                  </form>
                  <div id="deanStudentsNotice" class="alert mt-3 mb-0 d-none" role="status"></div>
                  <div id="deanStudentManagementNotice" class="alert mt-3 mb-0 d-none" role="status"></div>
                </div>
              </div>
              <div class="row g-3 mb-4" id="deanStudentsSummary" aria-live="polite">
                <div class="col-12 col-md-4"><div class="card dean-page-card"><div class="card-body"><span class="dean-page-copy">Unique Students</span><h4 id="deanUniqueStudents" class="mt-2 mb-0">&mdash;</h4></div></div></div>
                <div class="col-6 col-md-4"><div class="card dean-page-card"><div class="card-body"><span class="dean-page-copy">Imported Classes</span><h4 id="deanImportedClasses" class="mt-2 mb-0">&mdash;</h4></div></div></div>
                <div class="col-6 col-md-4"><div class="card dean-page-card"><div class="card-body"><span class="dean-page-copy">Programs</span><h4 id="deanImportedPrograms" class="mt-2 mb-0">&mdash;</h4></div></div></div>
              </div>
              <section class="card dean-page-card" aria-labelledby="deanStudentsListTitle">
                <div class="card-body p-4">
                  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                    <h5 class="mb-0" id="deanStudentsListTitle">Student Directory</h5>
                    <div class="d-flex gap-2"><label class="visually-hidden" for="deanStudentsSort">Sort students</label><select id="deanStudentsSort" class="form-select form-select-sm"><option value="name">Name A–Z</option><option value="id">Student ID A–Z</option><option value="newest">Recently imported</option></select><label class="visually-hidden" for="deanStudentsPageSize">Students per page</label><select id="deanStudentsPageSize" class="form-select form-select-sm"><option value="25">25 per page</option><option value="50">50 per page</option><option value="100">100 per page</option></select></div>
                  </div>
                  <p class="small text-muted">Class Sections list the groups where a student takes subjects for the selected academic term.</p>
                  <div class="dean-student-selection d-flex flex-wrap align-items-center gap-2 mb-3"><span id="deanStudentsSelectedCount" class="small fw-semibold" role="status">0 selected</span><button type="button" class="btn btn-sm btn-outline-primary" id="deanStudentsSelectResults" disabled>Select All Matching Students</button><button type="button" class="btn btn-sm btn-outline-secondary" id="deanStudentsClearSelection" disabled>Clear Selection</button><button type="button" class="btn btn-sm btn-outline-danger ms-sm-auto" id="deanStudentsDelete" disabled><i class="bx bx-trash me-1" aria-hidden="true"></i>Delete Selected Term Records</button><span id="deanStudentsDeleteHint" class="small text-muted w-100"></span></div>
                  <div class="table-responsive"><table class="table dean-students-table dean-student-directory-table"><colgroup><col class="dean-directory-select"><col class="dean-directory-name"><col class="dean-directory-program"><col class="dean-directory-sections"><col class="dean-directory-subjects"><col class="dean-directory-actions"></colgroup><thead><tr><th><input type="checkbox" class="form-check-input" id="deanStudentsSelectPage" aria-label="Select students on this page" disabled></th><th>Student Name</th><th>Programs</th><th>Class Sections</th><th class="text-center" aria-label="Number of subjects">Subjects</th><th class="text-end">Actions</th></tr></thead><tbody id="deanStudentsRows"><tr><td colspan="6" class="text-center text-muted py-5">Loading students...</td></tr></tbody></table></div>
                  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3"><span class="small text-muted" id="deanStudentsPageInfo" role="status"></span><nav aria-label="Student directory pages"><div class="d-flex gap-2"><button class="btn btn-sm btn-outline-primary" type="button" id="deanStudentsPrevious" disabled>Previous</button><button class="btn btn-sm btn-outline-primary" type="button" id="deanStudentsNext" disabled>Next</button></div></nav></div>
                </div>
              </section>
            </main>
            <?php include '../footer.php'; ?>
            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>
      <div class="layout-overlay layout-menu-toggle"></div>
    </div>
    <div class="modal fade" id="deanStudentEditorModal" tabindex="-1" aria-labelledby="deanStudentEditorTitle" aria-hidden="true" data-bs-backdrop="static">
      <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="deanStudentEditorTitle">Add Student</h5><button type="button" class="btn-close" id="deanStudentEditorClose" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div id="deanStudentEditorNotice" class="alert d-none" role="status"></div>
          <form id="deanStudentEditorForm"><fieldset id="deanStudentEditorFields">
            <input type="hidden" id="deanStudentEditId" value="0"><input type="hidden" id="deanStudentEditVersion">
            <p class="small text-muted" id="deanStudentEditorHelp">Add a unique student profile. You can import their class lists later.</p>
            <div class="row g-3">
              <div class="col-12"><label class="form-label" for="deanStudentNumber">Student ID <span class="text-danger">*</span></label><input class="form-control" id="deanStudentNumber" inputmode="numeric" pattern="[0-9]{1,32}" maxlength="32" required><div class="form-text">Keep leading zeros. The ID identifies one shared student profile.</div></div>
              <div class="col-sm-6"><label class="form-label" for="deanStudentFirstName">Complete first name <span class="text-danger">*</span></label><input class="form-control" id="deanStudentFirstName" maxlength="150" required><div class="form-text">Include all given names, for example Carol Jan.</div></div>
              <div class="col-sm-6"><label class="form-label" for="deanStudentLastName">Last name <span class="text-danger">*</span></label><input class="form-control" id="deanStudentLastName" maxlength="150" required></div>
              <div class="col-sm-8"><label class="form-label" for="deanStudentMiddleName">Middle name</label><input class="form-control" id="deanStudentMiddleName" maxlength="150"></div>
              <div class="col-sm-4"><label class="form-label" for="deanStudentSuffixName">Suffix</label><input class="form-control" id="deanStudentSuffixName" maxlength="50" placeholder="Jr., III"></div>
              <div class="col-12"><label class="form-label" for="deanStudentEmail">School email</label><div class="d-flex flex-wrap gap-2"><input type="email" class="form-control dean-student-email-input" id="deanStudentEmail" maxlength="255" placeholder="firstnamelastname@sksu.edu.ph"><button type="button" class="btn btn-outline-primary" id="deanStudentGenerateEmail">Suggest Email</button></div><div class="form-text">Enter the verified @sksu.edu.ph address. A school email is needed for student Google sign-in.</div></div>
            </div>
            <div class="row g-3 mt-1" id="deanStudentCreationFields">
              <div class="col-sm-6"><label class="form-label" for="deanStudentRecordProgram">Program <span class="text-danger">*</span></label><select class="form-select" id="deanStudentRecordProgram"></select></div>
              <div class="col-sm-6"><label class="form-label" for="deanStudentYearLevel">Year level <span class="text-danger">*</span></label><select class="form-select" id="deanStudentYearLevel"><option value="1">1st Year</option><option value="2">2nd Year</option><option value="3">3rd Year</option><option value="4">4th Year</option><option value="5">5th Year</option></select></div>
              <div class="col-sm-6"><label class="form-label" for="deanStudentRecordYear">Academic year <span class="text-danger">*</span></label><select class="form-select" id="deanStudentRecordYear"></select></div>
              <div class="col-sm-6"><label class="form-label" for="deanStudentRecordSemester">Semester <span class="text-danger">*</span></label><select class="form-select" id="deanStudentRecordSemester"><option value="1">1st Semester</option><option value="2">2nd Semester</option><option value="3">Midyear</option></select></div>
            </div>
            <p class="small text-muted mt-3 mb-0">Class sections remain attached to each imported subject. Adding or editing a profile does not assign its class subjects.</p>
          </fieldset></form>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" id="deanStudentEditorCancel" data-bs-dismiss="modal">Cancel</button><button type="submit" form="deanStudentEditorForm" class="btn btn-primary" id="deanStudentEditorSave">Save Student</button></div>
      </div></div>
    </div>
    <div class="modal fade" id="deanStudentDeleteModal" tabindex="-1" aria-labelledby="deanStudentDeleteTitle" aria-hidden="true" data-bs-backdrop="static">
      <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><div><h5 class="modal-title" id="deanStudentDeleteTitle">Delete Selected Term Records</h5><p class="small text-muted mb-0 mt-1" id="deanStudentDeleteTerm"></p></div><button type="button" class="btn-close" id="deanStudentDeleteClose" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><div id="deanStudentDeleteNotice" class="alert d-none" role="status"></div>
          <div class="alert alert-warning">This removes the selected students from the matching class lists and manual directory entries for this academic term. Shared student profiles, login emails, and records in other terms stay available.</div>
          <div id="deanStudentDeletePreview"></div>
          <div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="deanStudentDeleteAcknowledge" disabled><label class="form-check-label" for="deanStudentDeleteAcknowledge">I reviewed the academic term and selected students above.</label></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" id="deanStudentDeleteCancel" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" id="deanStudentDeleteConfirm" disabled>Delete Term Records</button></div>
      </div></div>
    </div>
    <div class="modal fade" id="deanStudentEmailsModal" tabindex="-1" aria-labelledby="deanStudentEmailsTitle" aria-hidden="true" data-bs-backdrop="static">
      <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><div><h5 class="modal-title" id="deanStudentEmailsTitle">Fill Missing Emails</h5><p class="small text-muted mt-1 mb-0">Students matching your current directory filters, across all result pages.</p></div><button type="button" class="btn-close" id="deanStudentEmailsClose" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
          <p class="small mb-3">Complete first name + last name <strong>@sksu.edu.ph</strong>, without spaces or accents. Middle names are excluded. Names such as Peña stay unchanged in the directory. Confirm that selected addresses match the students' actual school accounts.</p>
          <div id="deanStudentEmailsNotice" class="alert d-none" role="status"></div>
          <div id="deanStudentEmailsSummary" class="d-flex flex-wrap gap-2 mb-3" aria-live="polite"></div>
          <div id="deanStudentEmailsTools" class="d-flex flex-wrap gap-3 align-items-center mb-3" hidden>
            <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="deanStudentEmailsAll"><label class="form-check-label" for="deanStudentEmailsAll">Select all eligible emails</label></div>
            <label class="form-check mb-0"><input class="form-check-input" type="checkbox" id="deanStudentEmailsReviewOnly">Show only emails needing review</label>
          </div>
          <div id="deanStudentEmailsPreview" aria-live="polite"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" id="deanStudentEmailsCancel" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-outline-primary" id="deanStudentEmailsReload">Preview Again</button><button type="button" class="btn btn-primary" id="deanStudentEmailsSave" disabled>Save Selected (0)</button></div>
      </div></div>
    </div>
    <div class="modal fade" id="deanStudentDetailsModal" tabindex="-1" aria-labelledby="deanStudentDetailsTitle" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><div><h5 class="modal-title" id="deanStudentDetailsTitle">Student Details</h5><p class="small text-muted mt-1 mb-0" id="deanStudentDetailsTerm"></p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body" id="deanStudentDetailsContent"></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-primary" id="deanStudentDetailsEdit" disabled>Edit Student</button><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
      </div></div>
    </div>
    <script id="deanStudentsConfig" type="application/json"><?php echo json_encode($deanStudentsConfig, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?></script>
    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="students.js?v=<?php echo (int)filemtime(__DIR__ . '/students.js'); ?>"></script>
    <script src="student-emails.js?v=<?php echo (int)filemtime(__DIR__ . '/student-emails.js'); ?>"></script>
    <script src="student-management.js?v=<?php echo (int)filemtime(__DIR__ . '/student-management.js'); ?>"></script>
  </body>
</html>
