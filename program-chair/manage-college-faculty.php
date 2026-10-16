<?php
session_start();
define('SYNK_PROGRAM_CHAIR_VIEW', true);
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/program_chair_faculty_management_helper.php';
synk_program_chair_require_login($conn);
$scope = synk_chair_faculty_require_scope($conn);
$currentTerm = synk_dean_view_term($conn);
$canManage = !empty($currentTerm['is_current']);
$collegeDeanPortalContext = synk_college_dean_portal_context($conn);
$collegeDeanPortalDisplayName = $collegeDeanPortalContext['account_name'];
$collegeDeanPortalDisplayEmail = $collegeDeanPortalContext['email'];
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf_token = (string)$_SESSION['csrf_token'];
$myCollege = (int)$scope['college_id'];
$collegeLabel = trim($scope['college_code'] . ' - ' . $scope['college_name'], ' -');
$currentTermText = (string)$currentTerm['term_text'];
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

    <title>College Faculty | Program Chair | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" type="text/css" href="../college-dean/custom_css.css" />
    <link href="vendor/select2/select2.min.css" rel="stylesheet" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <link rel="stylesheet" href="manage-college-faculty.css?v=<?= filemtime(__DIR__ . '/manage-college-faculty.css') ?>">
</head>

<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="layout-page">
      <?php include __DIR__ . '/navbar.php'; ?>

      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y" id="chair-college-faculty" data-can-assign="<?= $canManage ? '1' : '0' ?>">
          <?php if (!$canManage): ?><div class="alert alert-info">This term is for viewing only. Use the term settings to select the current term before managing faculty.</div><?php endif; ?>

          <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
            <div class="mb-3 mb-md-0">
              <h4 class="fw-bold mb-1">
                <i class="bx bx-group me-2"></i> Faculty Assignment per College
              </h4>
              <p class="mb-0 text-muted">
                Review assigned faculty, update login access and designation, and monitor workload coverage for this college.
              </p>
              <div class="faculty-page-note">
                <i class="bx bx-shield-quarter"></i>
                <span>Manage Faculty and Scheduler login access, designation, and employment classification for your assigned college.</span>
              </div>
            </div>

            <div class="d-flex flex-column align-items-stretch align-items-md-end gap-2">
              <div class="fw-bold" style="font-size: 16px;">
                <?= htmlspecialchars($collegeLabel, ENT_QUOTES, 'UTF-8') ?>
              </div>

              <input type="hidden" id="college_id" value="<?= $myCollege ?>">

              <div class="d-flex flex-column flex-sm-row gap-2">
                <button type="button" class="btn btn-outline-primary" id="btnCopyPreviousFaculty"<?= $canManage ? '' : ' disabled' ?>><i class="bx bx-copy-alt"></i> Copy Previous List</button>
                <button class="btn btn-primary" id="btnAddFaculty"<?= $canManage ? '' : ' disabled' ?>>
                  <i class="bx bx-plus"></i> Add Faculty to College
                </button>
              </div>
            </div>
          </div>

          <div class="card faculty-list-card">
            <div class="card-header border-0 pb-0">
              <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <div>
                  <h5 class="m-0">Assigned Faculty</h5>
                  <small class="text-muted">Search and review faculty assignments for this college.</small>
                </div>
                <div class="faculty-term-chip">
                  <span class="faculty-term-label"><?= $canManage ? 'Current Term' : 'Viewing Term' ?></span>
                  <span id="facultyTermText"><?= htmlspecialchars($currentTermText, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
              </div>
            </div>

            <div class="card-body p-3 p-lg-4">
              <div class="faculty-toolbar">
                <div class="row g-3 align-items-center">
                  <div class="col-lg-6">
                    <label for="facultySearch" class="form-label fw-semibold">Search</label>
                    <div class="input-group faculty-search-shell">
                      <span class="input-group-text"><i class="bx bx-search"></i></span>
                      <input
                        type="search"
                        id="facultySearch"
                        class="form-control"
                        placeholder="Search by name, designation, or classification"
                        autocomplete="off"
                      >
                    </div>
                  </div>
                  <div class="col-lg-6">
                    <div class="faculty-results-summary" id="facultyResultsSummary">
                      Loading faculty assignments...
                    </div>
                    <small class="faculty-term-note" id="facultyTermNote">
                      Assigned count is scoped to <?= htmlspecialchars($currentTermText, ENT_QUOTES, 'UTF-8') ?>.
                    </small>
                  </div>
                </div>
              </div>

              <div id="facultyEmptyState" class="faculty-empty-state d-none">
                No faculty assignments found for this college.
              </div>

              <div class="table-responsive d-none d-md-block">
                <table class="table table-hover align-middle faculty-table mb-0" id="collegeFacultyTable">
                  <thead>
                    <tr>
                      <th style="width:5%;">#</th>
                      <th style="width:25%;">Faculty Name</th>
                      <th style="width:20%;">Designation</th>
                      <th style="width:15%;">Employment Classification</th>
                      <th style="width:15%;">Assigned</th>
                      <th class="text-end" style="width:20%;">Actions</th>
                    </tr>
                  </thead>
                  <tbody id="collegeFacultyTableBody"></tbody>
                </table>
              </div>

              <div class="faculty-mobile-list d-md-none" id="collegeFacultyMobileList"></div>

              <div id="facultyLoadingState" class="faculty-loading-state d-none">
                <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                <span>Loading faculty assignments...</span>
              </div>

              <div id="facultyScrollSentinel" class="faculty-scroll-sentinel"></div>
            </div>
          </div>

        </div>

        <?php include __DIR__ . '/../footer.php'; ?>

        <div class="content-backdrop fade"></div>
      </div>
    </div>
  </div>

  <div class="layout-overlay layout-menu-toggle"></div>
</div>

<div class="modal fade" id="addFacultyModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Add Faculty to College</h5>
      </div>

      <div class="modal-body">
        <form id="addFacultyForm">
          <div class="mb-3">
            <label class="form-label">Faculty</label>
            <select id="faculty_id" class="form-select" style="width: 100%;">
              <option value="">Select Faculty...</option>
            </select>
          </div>

          <div class="mb-0">
            <label class="form-label">Employment Classification</label>
            <select id="add_employment_classification" class="form-select">
              <option value="">Select Classification...</option>
              <option value="permanent">Permanent</option>
              <option value="temporary">Temporary</option>
              <option value="contract_of_service">Contract of Service</option>
              <option value="part_time">Part Time</option>
            </select>
          </div>

          <button type="submit" hidden></button>
        </form>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          Cancel
        </button>
        <button type="submit" class="btn btn-primary" form="addFacultyForm">
          Save
        </button>
      </div>

    </div>
  </div>
</div>

<div class="modal fade" id="designationModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="designationModalTitle">Update Designation</h5>
      </div>

      <div class="modal-body">
        <form id="designationForm">
          <input type="hidden" id="designation_faculty_id">
          <input type="hidden" id="designation_college_id" value="<?= $myCollege ?>">

          <div class="mb-3">
            <label class="form-label">Faculty</label>
            <input type="text" id="designation_faculty_name" class="form-control" readonly>
          </div>

          <div class="mb-0">
            <label class="form-label">Designation</label>
            <select id="designation_id" class="form-select" style="width: 100%;">
              <option value="">No designation</option>
            </select>
            <div class="form-text faculty-designation-note" id="designationTermNote">
              Choose No designation to remove the appointment for <?= htmlspecialchars($currentTerm['term_text'], ENT_QUOTES, 'UTF-8') ?>. Previous semesters keep their saved designations.
            </div>
          </div>

          <button type="submit" hidden></button>
        </form>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          Cancel
        </button>
        <button type="submit" class="btn btn-primary" form="designationForm">
          Save Designation
        </button>
      </div>

    </div>
  </div>
</div>

<div class="modal fade" id="classificationModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title" id="classificationModalTitle">Update Employment Classification</h5>
      </div>

      <div class="modal-body">
        <form id="classificationForm">
          <input type="hidden" id="classification_faculty_id">
          <input type="hidden" id="classification_college_id" value="<?= $myCollege ?>">

          <div class="mb-3">
            <label class="form-label">Faculty</label>
            <input type="text" id="classification_faculty_name" class="form-control" readonly>
          </div>

          <div class="mb-0">
            <label class="form-label">Employment Classification</label>
            <select id="employment_classification" class="form-select">
              <option value="">Select Classification...</option>
              <option value="permanent">Permanent</option>
              <option value="temporary">Temporary</option>
              <option value="contract_of_service">Contract of Service</option>
              <option value="part_time">Part Time</option>
            </select>
          </div>

          <button type="submit" hidden></button>
        </form>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          Cancel
        </button>
        <button type="submit" class="btn btn-primary" form="classificationForm">
          Save Classification
        </button>
      </div>

    </div>
  </div>
</div>

<div class="modal fade" id="facultyLoginModal" tabindex="-1" aria-labelledby="facultyLoginTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form id="facultyLoginForm">
      <div class="modal-header"><h5 class="modal-title" id="facultyLoginTitle">Faculty Login Access</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <h6 id="facultyLoginName" class="mb-2"></h6>
        <p class="text-muted small">Assign Faculty, Scheduler, or both roles. Scheduler access is limited to your assigned college.</p>
        <div id="facultyLoginNotice" class="alert alert-info" role="status" aria-live="polite" hidden></div>
        <fieldset id="facultyLoginFields" disabled>
          <div class="mb-3" id="facultyLoginEmailField" hidden><label for="facultyLoginEmail" class="form-label">Institutional Email for New Login</label><input type="email" class="form-control" id="facultyLoginEmail" maxlength="150" required autocomplete="off" disabled><div class="form-text" id="facultyLoginEmailNote"></div></div>
          <div class="mb-3"><label for="facultyLoginStatus" class="form-label">Login Status</label><select class="form-select" id="facultyLoginStatus"><option value="active">Active</option><option value="inactive">Inactive</option></select><div class="form-text" id="facultyLoginStatusNote">Inactive accounts cannot sign in.</div></div>
          <div class="mb-3"><span class="form-label d-block">Assigned Roles</span>
            <label class="faculty-login-role mb-2" for="facultyLoginProfessor"><input type="checkbox" class="form-check-input" id="facultyLoginProfessor" value="professor"><span><strong>Faculty</strong><small class="text-muted d-block">Faculty workspace and teaching assignments</small></span></label>
            <label class="faculty-login-role" for="facultyLoginScheduler"><input type="checkbox" class="form-check-input" id="facultyLoginScheduler" value="scheduler"><span><strong>Scheduler</strong><small class="text-muted d-block"><?= htmlspecialchars($collegeLabel, ENT_QUOTES, 'UTF-8') ?></small></span></label>
          </div>
          <div class="mb-0" id="facultyLoginPrimaryField"><label for="facultyLoginPrimary" class="form-label">Default Workspace</label><select class="form-select" id="facultyLoginPrimary" required></select></div>
        </fieldset>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="facultyLoginSave" disabled>Save Login Access</button></div>
    </form>
  </div></div>
</div>

<script src="../assets/vendor/libs/jquery/jquery.js"></script>
<?php $termCopyKind = 'faculty'; $termCopyCollegeId = $myCollege; include __DIR__ . '/term-list-copy-modal.php'; ?>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script>
<script src="../assets/js/sweetalert2.all.min.js"></script>
<script src="../assets/js/main.js"></script>
<script src="vendor/select2/select2.min.js"></script>

<script id="chairFacultyConfig" type="application/json"><?= json_encode(['csrf_token'=>$csrf_token,'ay_id'=>(int)$currentTerm['ay_id'],'semester'=>(int)$currentTerm['semester'],'can_manage'=>!empty($currentTerm['is_current'])], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="manage-college-faculty.js?v=<?= filemtime(__DIR__ . '/manage-college-faculty.js') ?>"></script>
<script src="faculty-login-access.js?v=<?= filemtime(__DIR__ . '/faculty-login-access.js') ?>"></script>

</body>
</html>
