<?php
session_start();
ob_start();

include '../backend/db.php';
require_once '../backend/academic_term_helper.php';
require_once '../backend/scheduler_access_helper.php';

synk_scheduler_bootstrap_session_scope($conn);

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'scheduler') {
    header('Location: ../index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = (string)$_SESSION['csrf_token'];

$myCollege = (int)($_SESSION['college_id'] ?? 0);
if ($myCollege <= 0) {
    echo 'Scheduler error: missing college assignment.';
    exit;
}

$collegeLabel = trim((string)($_SESSION['college_name'] ?? '')) ?: 'College not assigned';
$currentTerm = synk_fetch_current_academic_term($conn);
$currentTermText = (string)($currentTerm['term_text'] ?? 'Current academic term');

if ($myCollege > 0) {
    $collegeStmt = $conn->prepare("
        SELECT college_name, college_code
        FROM tbl_college
        WHERE college_id = ?
        LIMIT 1
    ");

    if ($collegeStmt instanceof mysqli_stmt) {
        $collegeStmt->bind_param('i', $myCollege);
        $collegeStmt->execute();
        $collegeResult = $collegeStmt->get_result();
        $collegeRow = ($collegeResult instanceof mysqli_result) ? $collegeResult->fetch_assoc() : null;

        if (is_array($collegeRow)) {
            $collegeCode = trim((string)($collegeRow['college_code'] ?? ''));
            $collegeName = trim((string)($collegeRow['college_name'] ?? ''));
            $collegeLabel = trim($collegeCode . ' - ' . $collegeName, ' -');
        }

        $collegeStmt->close();
    }
}
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

    <title>Faculty Assignment | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" type="text/css" href="custom_css.css" />
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
      .select2-container .select2-selection--single {
        height: 38px !important;
        padding: 4px 10px;
      }

      .select2-container--default .select2-selection--single .select2-selection__arrow {
        height: 36px !important;
      }

      .modal.show {
        z-index: 99999 !important;
      }

      .select2-container--open {
        z-index: 100000 !important;
      }

      .swal2-container {
        z-index: 1000000 !important;
      }

      .swal2-timer-progress-bar {
        background: linear-gradient(90deg, #28a745, #20c997) !important;
      }

      .faculty-list-card {
        border: 1px solid #e5ecf6;
        box-shadow: 0 18px 40px rgba(31, 45, 61, 0.06);
      }

      .faculty-toolbar {
        padding: 1rem 1.1rem;
        margin-bottom: 1.25rem;
        border: 1px solid #e8eef5;
        border-radius: 1rem;
        background: linear-gradient(180deg, #fbfcfe 0%, #f5f8fc 100%);
      }

      .faculty-search-shell .input-group-text {
        border-right: 0;
        background: #fff;
      }

      .faculty-search-shell .form-control {
        border-left: 0;
      }

      .faculty-search-shell .form-control:focus {
        box-shadow: none;
      }

      .faculty-term-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.6rem;
        padding: 0.55rem 0.8rem;
        border-radius: 999px;
        background: #f5f8ff;
        border: 1px solid #dfe7fb;
        color: #4b6282;
        font-weight: 600;
      }

      .faculty-term-label {
        font-size: 0.72rem;
        letter-spacing: 0.06em;
        text-transform: uppercase;
      }

      .faculty-results-summary {
        font-weight: 600;
        color: #344257;
      }

      .faculty-term-note {
        color: #7b8aa0;
      }

      .faculty-table thead th {
        font-size: 0.76rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #667a93;
        border-bottom: 1px solid #dbe5f1;
        white-space: normal;
        line-height: 1.35;
      }

      .faculty-table {
        width: 100%;
        table-layout: fixed;
      }

      .faculty-table tbody td {
        padding-top: 0.85rem;
        padding-bottom: 0.85rem;
        color: #4f6078;
        border-color: #edf2f7;
        vertical-align: middle;
        overflow-wrap: anywhere;
      }

      .faculty-index {
        color: #8a99ae;
        font-weight: 700;
      }

      .faculty-name {
        font-weight: 700;
        color: #2f3f57;
        white-space: normal;
        word-break: break-word;
      }

      .faculty-designation-pill {
        display: inline-block;
        max-width: 100%;
        padding: 0.3rem 0.65rem;
        border: 1px solid transparent;
        border-radius: 0.9rem;
        font-size: 0.76rem;
        font-weight: 700;
        line-height: 1.35;
        white-space: normal;
        word-break: break-word;
        overflow-wrap: anywhere;
      }

      .faculty-classification-pill {
        display: inline-block;
        max-width: 100%;
        padding: 0.3rem 0.65rem;
        border: 1px solid #b7e2ee;
        border-radius: 0.9rem;
        background: #e9f7fb;
        color: #166578;
        font-size: 0.76rem;
        font-weight: 700;
        line-height: 1.35;
        white-space: normal;
        word-break: break-word;
        overflow-wrap: anywhere;
      }

      .faculty-assigned-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 88px;
        padding: 0.35rem 0.7rem;
        border-radius: 999px;
        font-size: 0.76rem;
        font-weight: 700;
        line-height: 1;
        white-space: nowrap;
      }

      .faculty-assigned-pill.is-active {
        background: #e8f7ef;
        color: #146c43;
      }

      .faculty-assigned-pill.is-empty {
        background: #f2f5f9;
        color: #73839a;
      }

      .faculty-action-cell .btn {
        min-width: 0;
      }

      .faculty-action-group {
        display: inline-flex;
        align-items: center;
        justify-content: flex-end;
        flex-wrap: nowrap;
        white-space: nowrap;
        max-width: 100%;
      }

      .faculty-action-group > .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.25rem;
        padding: 0.38rem 0.48rem;
        border-radius: 0 !important;
        margin-left: 0 !important;
        font-size: 0.72rem;
        line-height: 1;
        white-space: nowrap;
      }

      .faculty-action-group > .btn:first-child {
        border-top-left-radius: 0.45rem !important;
        border-bottom-left-radius: 0.45rem !important;
      }

      .faculty-action-group > .btn:last-child {
        border-top-right-radius: 0.45rem !important;
        border-bottom-right-radius: 0.45rem !important;
      }

      .faculty-action-group > .btn + .btn {
        margin-left: -1px !important;
      }

      .faculty-action-label {
        margin-left: 0;
        line-height: 1;
      }

      .faculty-action-icon {
        font-size: 0.95rem;
      }

      .faculty-mobile-list {
        display: grid;
        gap: 1rem;
      }

      .faculty-mobile-card {
        border: 1px solid #e2eaf3;
        border-radius: 1rem;
        box-shadow: 0 10px 24px rgba(31, 45, 61, 0.05);
      }

      .faculty-mobile-card .card-body {
        padding: 1rem;
      }

      .faculty-mobile-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.85rem;
      }

      .faculty-mobile-index {
        display: inline-flex;
        margin-bottom: 0.5rem;
        color: #8898ae;
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
      }

      .faculty-mobile-name {
        margin-bottom: 0.45rem;
        font-weight: 700;
        color: #2f3f57;
      }

      .faculty-mobile-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 0.75rem;
        margin-top: 1rem;
      }

      .faculty-mobile-meta {
        padding: 0.8rem 0.9rem;
        border-radius: 0.85rem;
        background: #f7f9fc;
        border: 1px solid #e6edf5;
      }

      .faculty-mobile-meta-label {
        display: block;
        margin-bottom: 0.35rem;
        font-size: 0.74rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #8392a8;
        font-weight: 700;
      }

      .faculty-mobile-meta-value {
        display: block;
        color: #344257;
      }

      .faculty-mobile-actions {
        margin-top: 1rem;
      }

      .faculty-mobile-actions .faculty-action-group {
        display: flex;
        width: 100%;
      }

      .faculty-mobile-actions .faculty-action-group .btn {
        flex: 1 1 0;
      }

      @media (min-width: 768px) {
        .faculty-action-label {
          font-size: 0.74rem;
        }
      }

      .faculty-page-note {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        margin-top: 0.85rem;
        padding: 0.6rem 0.85rem;
        border-radius: 0.85rem;
        background: #f5f8ff;
        border: 1px solid #dce5fb;
        color: #4b6282;
        font-size: 0.92rem;
        font-weight: 600;
      }

      .faculty-designation-note {
        color: #6f7f96;
      }

      .faculty-empty-state,
      .faculty-loading-state {
        padding: 1rem;
        border-radius: 0.9rem;
        text-align: center;
      }

      .faculty-empty-state {
        border: 1px dashed #d6e0ec;
        background: #fafcff;
        color: #70829a;
      }

      .faculty-loading-state {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.55rem;
        color: #60748f;
      }

      .faculty-scroll-sentinel {
        width: 100%;
        height: 1px;
      }

      @media (max-width: 767.98px) {
        .faculty-toolbar {
          padding: 0.9rem;
        }

        .faculty-term-chip {
          width: 100%;
          justify-content: center;
        }
      }
    </style>
</head>

<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">

    <?php include 'sidebar.php'; ?>

    <div class="layout-page">
      <?php include 'navbar.php'; ?>

      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">

          <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
            <div class="mb-3 mb-md-0">
              <h4 class="fw-bold mb-1">
                <i class="bx bx-group me-2"></i> Faculty Assignment per College
              </h4>
              <p class="mb-0 text-muted">
                Review assigned faculty, update designation, and monitor current-term workload coverage for this college.
              </p>
              <div class="faculty-page-note">
                <i class="bx bx-shield-quarter"></i>
                <span>Designation and employment classification can be edited from this page. Manage the prepared-by signatory from Report Signatories.</span>
              </div>
            </div>

            <div class="d-flex flex-column align-items-stretch align-items-md-end gap-2">
              <div class="fw-bold" style="font-size: 16px;">
                <?= htmlspecialchars($collegeLabel, ENT_QUOTES, 'UTF-8') ?>
              </div>

              <input type="hidden" id="college_id" value="<?= $myCollege ?>">

              <div class="d-flex flex-column flex-sm-row gap-2">
                <a class="btn btn-outline-primary" href="manage-report-signatories.php">
                  <i class="bx bx-pen"></i> Report Signatories
                </a>
                <button class="btn btn-primary" id="btnAddFaculty">
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
                  <span class="faculty-term-label">Current Term</span>
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
                        placeholder="Search by faculty name, designation, classification, or status"
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
                      <th style="width:21%;">Faculty Name</th>
                      <th style="width:20%;">Designation</th>
                      <th style="width:15%;">Employment Classification</th>
                      <th style="width:10%;">Assigned</th>
                      <th style="width:9%;">Status</th>
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

        <?php include '../footer.php'; ?>

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
              <option value="">Select Designation...</option>
            </select>
            <div class="form-text faculty-designation-note">
              This page only allows designation updates for faculty assigned to your current college scope.
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

<script src="../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../assets/js/main.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;
const facultyListState = {
  page: 1,
  pageSize: 20,
  total: 0,
  loadedCount: 0,
  hasMore: true,
  loading: false,
  search: "",
  debounceTimer: null,
  currentRequest: null,
  observer: null,
  requestToken: 0,
  termText: $("#facultyTermText").text().trim()
};

const addFacultyModalElement = document.getElementById("addFacultyModal");
const designationModalElement = document.getElementById("designationModal");
const classificationModalElement = document.getElementById("classificationModal");
const addFacultyModal = new bootstrap.Modal(addFacultyModalElement, {
  backdrop: "static",
  keyboard: false
});
const designationModal = new bootstrap.Modal(designationModalElement, {
  backdrop: "static",
  keyboard: false
});
const classificationModal = new bootstrap.Modal(classificationModalElement, {
  backdrop: "static",
  keyboard: false
});

$(document).ready(function () {
  $("#faculty_id").select2({
    dropdownParent: $("#addFacultyModal .modal-body"),
    width: "100%",
    placeholder: "Select Faculty...",
    allowClear: true
  });

  $("#designation_id").select2({
    dropdownParent: $("#designationModal .modal-body"),
    width: "100%",
    placeholder: "Select Designation...",
    allowClear: true
  });

  loadFacultyDropdown();
  initializeFacultyScroll();
  resetFacultyList();

  $("#facultySearch").on("input", function () {
    const nextSearch = $.trim($(this).val());

    clearTimeout(facultyListState.debounceTimer);
    facultyListState.debounceTimer = window.setTimeout(function () {
      if (nextSearch === facultyListState.search) {
        return;
      }

      facultyListState.search = nextSearch;
      resetFacultyList();
    }, 250);
  });

  $("#addFacultyModal").on("keydown", function (e) {
    if (e.key !== "Enter") {
      return;
    }

    if ($(".select2-container--open").length) {
      return;
    }

    e.preventDefault();
    $("#addFacultyForm").trigger("submit");
  });

  $("#designationModal").on("keydown", function (e) {
    if (e.key !== "Enter") {
      return;
    }

    if ($(".select2-container--open").length) {
      return;
    }

    e.preventDefault();
    $("#designationForm").trigger("submit");
  });

  $("#designationModal").on("hidden.bs.modal", function () {
    resetDesignationForm();
  });

  $("#classificationModal").on("keydown", function (e) {
    if (e.key !== "Enter") {
      return;
    }

    e.preventDefault();
    $("#classificationForm").trigger("submit");
  });

  $("#classificationModal").on("hidden.bs.modal", function () {
    resetClassificationForm();
  });

  $("#btnAddFaculty").on("click", function () {
    const collegeId = Number($("#college_id").val() || 0);

    if (!collegeId) {
      Swal.fire("No College Selected", "Please select a college first.", "warning");
      return;
    }

    $("#faculty_id").val(null).trigger("change");
    $("#add_employment_classification").val("");
    addFacultyModal.show();
  });

  $("#addFacultyForm").on("submit", function (e) {
    e.preventDefault();

    const collegeId = Number($("#college_id").val() || 0);
    const facultyId = Number($("#faculty_id").val() || 0);
    const employmentClassification = String($("#add_employment_classification").val() || "").trim();

    if (!collegeId || !facultyId || employmentClassification === "") {
      Swal.fire({
        icon: "warning",
        title: "Missing Data",
        text: "Please select faculty and employment classification to assign."
      });
      return;
    }

    $.ajax({
      url: "../backend/query_college_faculty.php",
      type: "POST",
      dataType: "json",
      data: buildRequestData({
        save_assignment: 1,
        college_id: collegeId,
        faculty_id: facultyId,
        employment_classification: employmentClassification
      })
    }).done(function (res) {
      if (res.status === "inserted" || res.status === "reactivated") {
        const viewportState = captureFacultyViewportState();

        Swal.fire({
          icon: "success",
          title: res.status === "reactivated" ? "Assignment Restored" : "Faculty Assigned",
          text: res.status === "reactivated"
            ? "The faculty assignment has been reactivated for this college."
            : "Faculty has been successfully assigned to this college.",
          timer: 1300,
          timerProgressBar: true,
          showConfirmButton: false
        });

        $("#addFacultyForm")[0].reset();
        $("#faculty_id").val(null).trigger("change");
        $("#add_employment_classification").val("");
        hideModalAndRefresh(addFacultyModalElement, addFacultyModal, viewportState);
        return;
      }

      if (res.status === "duplicate") {
        Swal.fire("Already Assigned", "This faculty is already assigned to this college.", "info");
        return;
      }

      if (res.status === "invalid_classification") {
        Swal.fire("Invalid Classification", "Please select a valid employment classification.", "warning");
        return;
      }

      if (res.status === "schema_update_required") {
        Swal.fire("Schema Update Required", "Employment classification support is not ready in this database yet.", "warning");
        return;
      }

      Swal.fire("Error", "Something went wrong while saving the assignment.", "error");
    }).fail(function (xhr) {
      Swal.fire("Server Error", extractErrorMessage(xhr, "Unable to connect."), "error");
    });
  });

  $(document).on("click", ".btn-edit-designation", function () {
    openDesignationModal($(this));
  });

  $(document).on("click", ".btn-edit-classification", function () {
    openClassificationModal($(this));
  });

  $("#designationForm").on("submit", function (e) {
    e.preventDefault();

    const collegeId = Number($("#designation_college_id").val() || $("#college_id").val() || 0);
    const facultyId = Number($("#designation_faculty_id").val() || 0);
    const designationId = Number($("#designation_id").val() || 0);

    if (!collegeId || !facultyId || !designationId) {
      Swal.fire({
        icon: "warning",
        title: "Missing Data",
        text: "Please choose a designation before saving."
      });
      return;
    }

    $.ajax({
      url: "../backend/query_college_faculty.php",
      type: "POST",
      dataType: "json",
      data: buildRequestData({
        update_faculty_designation: 1,
        college_id: collegeId,
        faculty_id: facultyId,
        designation_id: designationId
      })
    }).done(function (res) {
      if (res && res.status === "updated") {
        Swal.fire({
          icon: "success",
          title: "Designation Updated",
          text: "Faculty designation has been updated successfully.",
          timer: 1300,
          timerProgressBar: true,
          showConfirmButton: false
        });
        applyFacultyDesignationUpdate(facultyId, res);
        designationModal.hide();
        return;
      }

      if (res && res.status === "invalid_designation") {
        Swal.fire("Invalid Designation", "Please select an active designation.", "warning");
        return;
      }

      if (res && res.status === "schema_update_required") {
        Swal.fire("Schema Update Required", "Designation support is not ready in this database yet.", "warning");
        return;
      }

      Swal.fire("Error", "Unable to update the faculty designation.", "error");
    }).fail(function (xhr) {
      Swal.fire("Error", extractErrorMessage(xhr, "Unable to update the faculty designation."), "error");
    });
  });

  $("#classificationForm").on("submit", function (e) {
    e.preventDefault();

    const collegeId = Number($("#classification_college_id").val() || $("#college_id").val() || 0);
    const facultyId = Number($("#classification_faculty_id").val() || 0);
    const employmentClassification = String($("#employment_classification").val() || "").trim();

    if (!collegeId || !facultyId || employmentClassification === "") {
      Swal.fire({
        icon: "warning",
        title: "Missing Data",
        text: "Please choose an employment classification before saving."
      });
      return;
    }

    $.ajax({
      url: "../backend/query_college_faculty.php",
      type: "POST",
      dataType: "json",
      data: buildRequestData({
        update_employment_classification: 1,
        college_id: collegeId,
        faculty_id: facultyId,
        employment_classification: employmentClassification
      })
    }).done(function (res) {
      if (res && res.status === "updated") {
        Swal.fire({
          icon: "success",
          title: "Classification Updated",
          text: "Faculty employment classification has been updated successfully.",
          timer: 1300,
          timerProgressBar: true,
          showConfirmButton: false
        });
        applyFacultyClassificationUpdate(facultyId, res);
        classificationModal.hide();
        return;
      }

      if (res && res.status === "invalid_classification") {
        Swal.fire("Invalid Classification", "Please select a valid employment classification.", "warning");
        return;
      }

      if (res && res.status === "schema_update_required") {
        Swal.fire("Schema Update Required", "Employment classification support is not ready in this database yet.", "warning");
        return;
      }

      Swal.fire("Error", "Unable to update the employment classification.", "error");
    }).fail(function (xhr) {
      Swal.fire("Error", extractErrorMessage(xhr, "Unable to update the employment classification."), "error");
    });
  });

  $(document).on("click", ".btn-remove-assignment", function () {
    const collegeFacultyId = Number($(this).data("id") || 0);

    if (!collegeFacultyId) {
      return;
    }

    Swal.fire({
      title: "Remove Assignment?",
      text: "This will mark the faculty as inactive for this college.",
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: "Yes, remove",
      cancelButtonText: "Cancel"
    }).then((result) => {
      if (!result.isConfirmed) {
        return;
      }

      $.ajax({
        url: "../backend/query_college_faculty.php",
        type: "POST",
        dataType: "json",
        data: buildRequestData({
          remove_assignment: 1,
          college_faculty_id: collegeFacultyId
        })
      }).done(function (res) {
        if (res.status === "removed") {
          Swal.fire({
            icon: "success",
            title: "Removed",
            text: "Faculty assignment updated successfully.",
            timer: 1300,
            showConfirmButton: false
          });
          refreshFacultyData({ preserveScroll: true });
          return;
        }

        Swal.fire("Error", "Unable to remove assignment.", "error");
      }).fail(function (xhr) {
        Swal.fire("Error", extractErrorMessage(xhr, "Unable to connect to the server."), "error");
      });
    });
  });

  $(document).on("click", ".btn-reactivate-assignment", function () {
    const collegeId = Number($("#college_id").val() || 0);
    const facultyId = Number($(this).data("facultyId") || 0);

    if (!collegeId || !facultyId) {
      return;
    }

    $.ajax({
      url: "../backend/query_college_faculty.php",
      type: "POST",
      dataType: "json",
      data: buildRequestData({
        save_assignment: 1,
        college_id: collegeId,
        faculty_id: facultyId
      })
    }).done(function (res) {
      if (res.status === "reactivated" || res.status === "inserted") {
        Swal.fire({
          icon: "success",
          title: "Assignment Restored",
          text: "Faculty assignment has been set back to active.",
          timer: 1300,
          showConfirmButton: false
        });
        refreshFacultyData({ preserveScroll: true });
        return;
      }

      if (res.status === "duplicate") {
        refreshFacultyData({ preserveScroll: true });
        return;
      }

      Swal.fire("Error", "Unable to reactivate this assignment.", "error");
    }).fail(function (xhr) {
      Swal.fire("Error", extractErrorMessage(xhr, "Unable to connect to the server."), "error");
    });
  });
});

function buildRequestData(payload) {
  return Object.assign({
    csrf_token: CSRF_TOKEN
  }, payload);
}

function extractErrorMessage(xhr, fallbackMessage) {
  const response = xhr && xhr.responseJSON ? xhr.responseJSON : null;

  if (response && typeof response.message === "string" && response.message.trim() !== "") {
    return response.message;
  }

  if (response && response.status === "unauthorized") {
    return "Your scheduler session has expired. Please sign in again.";
  }

  if (response && response.status === "forbidden") {
    return "You no longer have access to manage this college.";
  }

  return fallbackMessage;
}

function resetDesignationForm() {
  $("#designationForm")[0].reset();
  $("#designation_faculty_id").val("");
  $("#designation_faculty_name").val("");
  $("#designationModalTitle").text("Update Designation");
  $("#designation_id")
    .prop("disabled", false)
    .empty()
    .append('<option value=""></option>')
    .val(null)
    .trigger("change");
}

function resetClassificationForm() {
  $("#classificationForm")[0].reset();
  $("#classification_faculty_id").val("");
  $("#classification_faculty_name").val("");
  $("#classificationModalTitle").text("Update Employment Classification");
  $("#employment_classification").val("");
}

function openDesignationModal($button) {
  const facultyId = Number($button.data("facultyId") || 0);
  const facultyName = String($button.data("facultyName") || "").trim();
  const designationId = Number($button.data("designationId") || 0);
  const collegeId = Number($("#college_id").val() || 0);

  if (!facultyId || !collegeId) {
    Swal.fire("Missing Data", "Unable to load the faculty designation form.", "warning");
    return;
  }

  $("#designation_faculty_id").val(facultyId);
  $("#designation_college_id").val(collegeId);
  $("#designation_faculty_name").val(facultyName);
  $("#designationModalTitle").text(designationId > 0 ? "Update Designation" : "Add Designation");
  $("#designation_id")
    .prop("disabled", true)
    .empty()
    .append('<option value=""></option>')
    .val(null)
    .trigger("change");

  designationModal.show();

  loadDesignationOptions(designationId).fail(function (xhr) {
    designationModal.hide();
    Swal.fire("Error", extractErrorMessage(xhr, "Unable to load designation options."), "error");
  });
}

function openClassificationModal($button) {
  const facultyId = Number($button.data("facultyId") || 0);
  const facultyName = String($button.data("facultyName") || "").trim();
  const employmentClassification = String($button.data("employmentClassification") || "").trim();
  const collegeId = Number($("#college_id").val() || 0);

  if (!facultyId || !collegeId) {
    Swal.fire("Missing Data", "Unable to load the faculty classification form.", "warning");
    return;
  }

  $("#classification_faculty_id").val(facultyId);
  $("#classification_college_id").val(collegeId);
  $("#classification_faculty_name").val(facultyName);
  $("#classificationModalTitle").text(employmentClassification !== "" ? "Update Employment Classification" : "Add Employment Classification");
  $("#employment_classification").val(employmentClassification);

  classificationModal.show();
}

function loadDesignationOptions(selectedId) {
  return $.ajax({
    url: "../backend/query_college_faculty.php",
    type: "POST",
    dataType: "json",
    data: buildRequestData({
      load_designation_options: 1
    })
  }).done(function (res) {
    const $designationSelect = $("#designation_id");
    $designationSelect.empty().append('<option value=""></option>');

    if (res && res.status === "success" && Array.isArray(res.data)) {
      $.each(res.data, function (index, item) {
        $designationSelect.append(
          $("<option>", { value: item.id, text: item.text })
        );
      });
    }

    if (selectedId > 0) {
      $designationSelect.val(String(selectedId));
    } else {
      $designationSelect.val(null);
    }

    $designationSelect.prop("disabled", false).trigger("change");
  }).always(function () {
    $("#designation_id").prop("disabled", false);
  });
}

function initializeFacultyScroll() {
  const sentinel = document.getElementById("facultyScrollSentinel");

  if (!sentinel || typeof IntersectionObserver !== "function") {
    return;
  }

  facultyListState.observer = new IntersectionObserver(function (entries) {
    const entry = entries[0];
    if (!entry || !entry.isIntersecting) {
      return;
    }

    if (facultyListState.loading || !facultyListState.hasMore) {
      return;
    }

    loadCollegeFaculty();
  }, {
    root: null,
    rootMargin: "220px 0px",
    threshold: 0
  });

  facultyListState.observer.observe(sentinel);
}

function refreshFacultyData(options) {
  const settings = Object.assign({
    preserveScroll: false,
    viewportState: null
  }, options || {});

  loadFacultyDropdown();

  if (!settings.preserveScroll) {
    resetFacultyList();
    return;
  }

  reloadFacultyListPreservingViewport(settings.viewportState || captureFacultyViewportState());
}

function hideModalAndRefresh(modalElement, modalInstance, viewportState) {
  $(modalElement).one("hidden.bs.modal", function () {
    if (document.activeElement && typeof document.activeElement.blur === "function") {
      document.activeElement.blur();
    }

    refreshFacultyData({
      preserveScroll: true,
      viewportState: viewportState
    });
  });

  modalInstance.hide();
}

function resetFacultyList(options) {
  const settings = Object.assign({
    deferLoad: false
  }, options || {});

  if (facultyListState.currentRequest) {
    facultyListState.currentRequest.abort();
    facultyListState.currentRequest = null;
  }

  facultyListState.page = 1;
  facultyListState.total = 0;
  facultyListState.loadedCount = 0;
  facultyListState.hasMore = true;
  facultyListState.loading = false;
  facultyListState.requestToken += 1;

  $("#collegeFacultyTableBody").empty();
  $("#collegeFacultyMobileList").empty();
  $("#facultyEmptyState").addClass("d-none");

  toggleFacultyLoading(false);
  updateFacultyResultsSummary();

  if (settings.deferLoad) {
    return null;
  }

  return loadCollegeFaculty();
}

function loadFacultyDropdown() {
  const collegeId = Number($("#college_id").val() || 0);

  if (!collegeId) {
    return;
  }

  $.ajax({
    url: "../backend/query_college_faculty.php",
    type: "POST",
    dataType: "json",
    data: buildRequestData({
      load_faculty_dropdown: 1,
      college_id: collegeId
    })
  }).done(function (data) {
      const $facultySelect = $("#faculty_id");
      $facultySelect.empty().append('<option value=""></option>');

      $.each(data, function (index, item) {
        $facultySelect.append(
          $("<option>", { value: item.id, text: item.text })
        );
      });
    }).fail(function () {
      $("#faculty_id").empty().append('<option value=""></option>');
    });
}

function loadCollegeFaculty() {
  const collegeId = Number($("#college_id").val() || 0);

  if (!collegeId || facultyListState.loading || !facultyListState.hasMore) {
    return null;
  }

  facultyListState.loading = true;
  toggleFacultyLoading(true);
  updateFacultyResultsSummary();

  const requestToken = facultyListState.requestToken;

  facultyListState.currentRequest = $.ajax({
    url: "../backend/query_college_faculty.php",
    type: "POST",
    dataType: "json",
    data: buildRequestData({
      load_college_faculty: 1,
      college_id: collegeId,
      page: facultyListState.page,
      page_size: facultyListState.pageSize,
      search: facultyListState.search
    })
  }).done(function (res) {
    if (requestToken !== facultyListState.requestToken) {
      return;
    }

    if (!res || res.status !== "success") {
      facultyListState.hasMore = false;
      updateFacultyResultsSummary("Unable to load faculty assignments.");
      return;
    }

    const rows = Array.isArray(res.data) ? res.data : [];
    const pagination = res.pagination || {};
    const startIndex = facultyListState.loadedCount + 1;

    facultyListState.total = Number(pagination.total || 0);
    facultyListState.hasMore = Boolean(pagination.has_more);

    if (res.term && res.term.term_text) {
      facultyListState.termText = String(res.term.term_text);
      $("#facultyTermText").text(facultyListState.termText);
      $("#facultyTermNote").text("Assigned count is scoped to " + facultyListState.termText + ".");
    }

    if (rows.length > 0) {
      appendFacultyRows(rows, startIndex);
      facultyListState.loadedCount += rows.length;
      facultyListState.page += 1;
    } else if (facultyListState.page === 1) {
      facultyListState.hasMore = false;
    }

    const showEmptyState = facultyListState.total === 0;
    $("#facultyEmptyState")
      .toggleClass("d-none", !showEmptyState)
      .text(facultyListState.search
        ? "No faculty assignments matched your search."
        : "No faculty assignments found for this college.");

    updateFacultyResultsSummary();
  }).fail(function (xhr, statusText) {
    if (statusText === "abort") {
      return;
    }

    facultyListState.hasMore = false;
    updateFacultyResultsSummary(extractErrorMessage(xhr, "Unable to load faculty assignments."));
  }).always(function () {
    if (requestToken === facultyListState.requestToken) {
      facultyListState.loading = false;
      facultyListState.currentRequest = null;
      toggleFacultyLoading(false);
    }
  });

  return facultyListState.currentRequest;
}

function reloadFacultyListPreservingViewport(viewportState) {
  const safeViewportState = viewportState || captureFacultyViewportState();
  const targetLoadedCount = Math.max(
    facultyListState.pageSize,
    Number(safeViewportState.targetLoadedCount || facultyListState.pageSize)
  );
  const targetPageCount = Math.max(1, Math.ceil(targetLoadedCount / facultyListState.pageSize));

  resetFacultyList({ deferLoad: true });

  const requestToken = facultyListState.requestToken;

  function finalizeRefresh() {
    if (requestToken !== facultyListState.requestToken) {
      return;
    }

    restoreFacultyViewport(safeViewportState);
  }

  function loadNextPage(pageNumber) {
    if (requestToken !== facultyListState.requestToken) {
      return;
    }

    const request = loadCollegeFaculty();
    if (!request || typeof request.done !== "function") {
      finalizeRefresh();
      return;
    }

    request.done(function (res) {
      if (requestToken !== facultyListState.requestToken) {
        return;
      }

      const rows = res && Array.isArray(res.data) ? res.data : [];
      const shouldContinue = rows.length > 0 && facultyListState.hasMore && pageNumber < targetPageCount;

      if (shouldContinue) {
        loadNextPage(pageNumber + 1);
        return;
      }

      finalizeRefresh();
    }).fail(function (xhr, statusText) {
      if (statusText === "abort") {
        return;
      }

      finalizeRefresh();
    });
  }

  loadNextPage(1);
}

function captureFacultyViewportState() {
  const items = getFacultyViewportItems();
  const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 0;
  const scrollTarget = getPrimaryScrollTarget();
  let anchorElement = null;
  let anchorIndex = 0;

  items.some(function (item, index) {
    const rect = item.getBoundingClientRect();
    const isVisible = rect.bottom > 0 && rect.top < viewportHeight;

    if (!isVisible) {
      return false;
    }

    anchorElement = item;
    anchorIndex = index + 1;
    return true;
  });

  const targetLoadedCount = anchorIndex > 0
    ? Math.max(
      facultyListState.pageSize,
      Math.min(facultyListState.loadedCount, anchorIndex + facultyListState.pageSize)
    )
    : Math.max(
      facultyListState.pageSize,
      Math.min(facultyListState.loadedCount || 0, facultyListState.pageSize)
    );

  return {
    scrollTarget: scrollTarget,
    scrollTop: getScrollTargetTop(scrollTarget),
    targetLoadedCount: targetLoadedCount,
    anchorAssignmentId: anchorElement ? String(anchorElement.getAttribute("data-assignment-id") || "") : "",
    anchorOffset: anchorElement ? anchorElement.getBoundingClientRect().top : 0
  };
}

function restoreFacultyViewport(viewportState) {
  const scrollTarget = (viewportState && viewportState.scrollTarget) || getPrimaryScrollTarget();
  const scrollTop = Math.max(0, Number(viewportState && viewportState.scrollTop ? viewportState.scrollTop : 0));

  window.requestAnimationFrame(function () {
    window.requestAnimationFrame(function () {
      if (viewportState && viewportState.anchorAssignmentId) {
        const anchorItems = getFacultyViewportItems();
        let anchorElement = null;

        anchorItems.some(function (item) {
          if (String(item.getAttribute("data-assignment-id") || "") !== String(viewportState.anchorAssignmentId)) {
            return false;
          }

          anchorElement = item;
          return true;
        });

        if (anchorElement) {
          const nextScrollTop = getScrollTargetTop(scrollTarget)
            + anchorElement.getBoundingClientRect().top
            - Number(viewportState.anchorOffset || 0);

          restoreScrollTarget(scrollTarget, nextScrollTop);
          return;
        }
      }

      restoreScrollTarget(scrollTarget, scrollTop);
    });
  });
}

function getFacultyViewportItems() {
  const isMobileViewport = window.matchMedia("(max-width: 767.98px)").matches;
  const selector = isMobileViewport
    ? "#collegeFacultyMobileList .faculty-mobile-card"
    : "#collegeFacultyTableBody tr";

  return Array.prototype.slice.call(document.querySelectorAll(selector));
}

function getPrimaryScrollTarget() {
  const candidates = [];
  const seen = [];

  function pushCandidate(candidate) {
    if (!candidate || seen.indexOf(candidate) !== -1) {
      return;
    }

    seen.push(candidate);
    candidates.push(candidate);
  }

  pushCandidate(window);
  pushCandidate(document.scrollingElement);
  pushCandidate(document.documentElement);
  pushCandidate(document.body);
  pushCandidate(document.querySelector(".layout-page"));
  pushCandidate(document.querySelector(".content-wrapper"));
  pushCandidate(document.querySelector(".layout-wrapper"));
  pushCandidate(document.querySelector(".layout-content-navbar"));

  let activeTarget = window;
  let bestScore = -1;

  candidates.forEach(function (candidate) {
    const scrollTop = getScrollTargetTop(candidate);
    const maxScrollTop = getScrollTargetMaxTop(candidate);

    if (maxScrollTop <= 0) {
      return;
    }

    const score = (scrollTop > 0 ? 1000000 : 0) + scrollTop + maxScrollTop;
    if (score > bestScore) {
      bestScore = score;
      activeTarget = candidate;
    }
  });

  return activeTarget;
}

function getScrollTargetTop(target) {
  if (!target || target === window) {
    return window.pageYOffset
      || document.documentElement.scrollTop
      || document.body.scrollTop
      || 0;
  }

  return Number(target.scrollTop || 0);
}

function getScrollTargetMaxTop(target) {
  if (!target || target === window) {
    const scrollingElement = document.scrollingElement || document.documentElement || document.body;
    if (!scrollingElement) {
      return 0;
    }

    return Math.max(0, Number(scrollingElement.scrollHeight || 0) - Number(window.innerHeight || 0));
  }

  return Math.max(0, Number(target.scrollHeight || 0) - Number(target.clientHeight || 0));
}

function setScrollTargetTop(target, top) {
  const safeTop = Math.max(0, Number(top || 0));

  if (!target || target === window) {
    window.scrollTo(0, safeTop);
    return;
  }

  target.scrollTop = safeTop;
}

function restoreScrollTarget(target, top) {
  const safeTop = Math.max(0, Number(top || 0));

  setScrollTargetTop(target, safeTop);

  window.setTimeout(function () {
    setScrollTargetTop(target, safeTop);
  }, 60);
}

function appendFacultyRows(rows, startIndex) {
  const tableRows = [];
  const mobileCards = [];

  rows.forEach(function (row, index) {
    const sequence = startIndex + index;
    const designationHtml = buildDesignationHtml(row);
    const classificationHtml = buildEmploymentClassificationHtml(row);
    const assignedHtml = buildAssignedHtml(row);
    const statusBadge = buildStatusBadge(row.status);
    const actionHtml = buildActionHtml(row);
    const safeName = escapeHtml(row.full_name || "Unnamed Faculty");
    const assignmentId = escapeHtml(String(row.college_faculty_id || 0));
    const facultyId = escapeHtml(String(row.faculty_id || 0));

    tableRows.push(
      "<tr data-assignment-id='" + assignmentId + "' data-faculty-id='" + facultyId + "'>" +
        "<td class='faculty-index'>" + sequence + "</td>" +
        "<td><div class='faculty-name'>" + safeName + "</div></td>" +
        "<td class='faculty-designation-slot'>" + designationHtml + "</td>" +
        "<td class='faculty-classification-slot'>" + classificationHtml + "</td>" +
        "<td>" + assignedHtml + "</td>" +
        "<td>" + statusBadge + "</td>" +
        "<td class='text-end faculty-action-cell faculty-actions-slot'>" + actionHtml + "</td>" +
      "</tr>"
    );

    mobileCards.push(
      "<div class='card faculty-mobile-card' data-assignment-id='" + assignmentId + "' data-faculty-id='" + facultyId + "'>" +
        "<div class='card-body'>" +
          "<div class='faculty-mobile-top'>" +
            "<div>" +
              "<span class='faculty-mobile-index'>#" + sequence + "</span>" +
              "<h6 class='faculty-mobile-name'>" + safeName + "</h6>" +
              "<div class='faculty-designation-slot'>" + designationHtml + "</div>" +
            "</div>" +
            "<div>" + statusBadge + "</div>" +
          "</div>" +
          "<div class='faculty-mobile-grid'>" +
            "<div class='faculty-mobile-meta'>" +
              "<span class='faculty-mobile-meta-label'>Classification</span>" +
              "<span class='faculty-mobile-meta-value faculty-classification-slot'>" + classificationHtml + "</span>" +
            "</div>" +
            "<div class='faculty-mobile-meta'>" +
              "<span class='faculty-mobile-meta-label'>Assigned</span>" +
              "<span class='faculty-mobile-meta-value'>" + assignedHtml + "</span>" +
            "</div>" +
            "<div class='faculty-mobile-meta'>" +
              "<span class='faculty-mobile-meta-label'>Academic Term</span>" +
              "<span class='faculty-mobile-meta-value'>" + escapeHtml(facultyListState.termText || "Current academic term") + "</span>" +
            "</div>" +
          "</div>" +
          "<div class='faculty-mobile-actions faculty-actions-slot'>" + actionHtml + "</div>" +
        "</div>" +
      "</div>"
    );
  });

  $("#collegeFacultyTableBody").append(tableRows.join(""));
  $("#collegeFacultyMobileList").append(mobileCards.join(""));
}

function buildDesignationHtml(row) {
  const designationName = String(row.designation_name || "").trim();

  if (designationName === "") {
    return "<span class='text-muted'>Not Set</span>";
  }

  const styleAttr = row.designation_style
    ? " style=\"" + escapeHtml(String(row.designation_style)) + "\""
    : "";

  return "<span class='faculty-designation-pill'" + styleAttr + ">" + escapeHtml(designationName) + "</span>";
}

function buildEmploymentClassificationHtml(row) {
  const classification = String(row.employment_classification || "").trim();
  const label = String(row.employment_classification_label || formatEmploymentClassification(classification)).trim();

  if (label === "") {
    return "<span class='text-muted'>Not Set</span>";
  }

  return "<span class='faculty-classification-pill'>" + escapeHtml(label) + "</span>";
}

function formatEmploymentClassification(value) {
  const labels = {
    permanent: "Permanent",
    temporary: "Temporary",
    contract_of_service: "Contract of Service",
    part_time: "Part Time"
  };

  return labels[String(value || "").trim()] || "";
}

function buildAssignedHtml(row) {
  const count = Number(row.assigned_count || 0);
  const label = count + " " + (count === 1 ? "class" : "classes");
  const stateClass = count > 0 ? "is-active" : "is-empty";

  return "<span class='faculty-assigned-pill " + stateClass + "'>" + escapeHtml(label) + "</span>";
}

function buildStatusBadge(status) {
  const normalizedStatus = String(status || "").toLowerCase() === "inactive" ? "inactive" : "active";
  const badgeClass = normalizedStatus === "active" ? "bg-success" : "bg-danger";
  const label = normalizedStatus === "active" ? "ACTIVE" : "INACTIVE";

  return "<span class='badge " + badgeClass + "'>" + label + "</span>";
}

function applyFacultyDesignationUpdate(facultyId, response) {
  const normalizedFacultyId = String(facultyId || "").trim();
  if (normalizedFacultyId === "") {
    return;
  }

  const row = {
    designation_id: Number(response && response.designation_id ? response.designation_id : 0),
    designation_name: String(response && response.designation_name ? response.designation_name : "").trim(),
    designation_style: String(response && response.designation_style ? response.designation_style : "").trim()
  };
  const designationHtml = buildDesignationHtml(row);
  const hasDesignation = row.designation_name !== "";
  const designationTitle = hasDesignation ? "Edit Designation" : "Add Designation";

  $("[data-faculty-id='" + escapeSelectorValue(normalizedFacultyId) + "']").each(function () {
    const $container = $(this);
    $container.find(".faculty-designation-slot").first().html(designationHtml);

    const $button = $container.find(".btn-edit-designation").first();
    if ($button.length) {
      $button
        .attr("title", designationTitle)
        .attr("aria-label", designationTitle)
        .attr("data-designation-id", String(row.designation_id || 0))
        .data("designationId", Number(row.designation_id || 0));
    }
  });
}

function applyFacultyClassificationUpdate(facultyId, response) {
  const normalizedFacultyId = String(facultyId || "").trim();
  if (normalizedFacultyId === "") {
    return;
  }

  const row = {
    employment_classification: String(response && response.employment_classification ? response.employment_classification : "").trim(),
    employment_classification_label: String(response && response.employment_classification_label ? response.employment_classification_label : "").trim()
  };
  const classificationHtml = buildEmploymentClassificationHtml(row);
  const hasClassification = row.employment_classification !== "";
  const classificationTitle = hasClassification ? "Edit Employment Classification" : "Add Employment Classification";

  $("[data-faculty-id='" + escapeSelectorValue(normalizedFacultyId) + "']").each(function () {
    const $container = $(this);
    $container.find(".faculty-classification-slot").first().html(classificationHtml);

    const $button = $container.find(".btn-edit-classification").first();
    if ($button.length) {
      $button
        .attr("title", classificationTitle)
        .attr("aria-label", classificationTitle)
        .attr("data-employment-classification", row.employment_classification)
        .data("employmentClassification", row.employment_classification);
    }
  });
}

function buildActionHtml(row) {
  const isActive = String(row.status || "").toLowerCase() !== "inactive";
  const hasDesignation = String(row.designation_name || "").trim() !== "";
  const designationTitle = hasDesignation ? "Edit Designation" : "Add Designation";
  const employmentClassification = String(row.employment_classification || "").trim();
  const classificationTitle = employmentClassification !== "" ? "Edit Employment Classification" : "Add Employment Classification";
  const designationButton = (
    "<button type='button' class='btn btn-sm btn-outline-primary btn-edit-designation' title='" +
    escapeHtml(designationTitle) +
    "' aria-label='" +
    escapeHtml(designationTitle) +
    "' data-faculty-id='" +
    escapeHtml(String(row.faculty_id || 0)) +
    "' data-faculty-name='" +
    escapeHtml(String(row.full_name || "Unnamed Faculty")) +
    "' data-designation-id='" +
    escapeHtml(String(row.designation_id || 0)) +
    "'>" +
    "<i class='bx bx-id-card faculty-action-icon'></i>" +
    "<span class='faculty-action-label'>Desig.</span>" +
    "</button>"
  );
  const classificationButton = (
    "<button type='button' class='btn btn-sm btn-outline-secondary btn-edit-classification' title='" +
    escapeHtml(classificationTitle) +
    "' aria-label='" +
    escapeHtml(classificationTitle) +
    "' data-faculty-id='" +
    escapeHtml(String(row.faculty_id || 0)) +
    "' data-faculty-name='" +
    escapeHtml(String(row.full_name || "Unnamed Faculty")) +
    "' data-employment-classification='" +
    escapeHtml(employmentClassification) +
    "'>" +
    "<i class='bx bx-briefcase faculty-action-icon'></i>" +
    "<span class='faculty-action-label'>Emp.</span>" +
    "</button>"
  );

  if (isActive) {
    return (
      "<div class='btn-group faculty-action-group' role='group' aria-label='Faculty row actions'>" +
        designationButton +
        classificationButton +
        "<button type='button' class='btn btn-sm btn-outline-danger btn-remove-assignment' title='Remove Assignment' aria-label='Remove Assignment' data-id='" +
        escapeHtml(String(row.college_faculty_id || 0)) +
        "'>" +
        "<i class='bx bx-trash faculty-action-icon'></i>" +
        "<span class='faculty-action-label'>Remove</span>" +
        "</button>" +
      "</div>"
    );
  }

  return (
    "<div class='btn-group faculty-action-group' role='group' aria-label='Faculty row actions'>" +
      "<button type='button' class='btn btn-sm btn-outline-success btn-reactivate-assignment' title='Reactivate Assignment' aria-label='Reactivate Assignment' data-faculty-id='" +
      escapeHtml(String(row.faculty_id || 0)) +
      "'>" +
      "<i class='bx bx-refresh faculty-action-icon'></i>" +
      "<span class='faculty-action-label'>Reactivate</span>" +
      "</button>" +
    "</div>"
  );
}

function toggleFacultyLoading(isLoading) {
  $("#facultyLoadingState").toggleClass("d-none", !isLoading);
}

function updateFacultyResultsSummary(overrideText) {
  if (overrideText) {
    $("#facultyResultsSummary").text(overrideText);
    return;
  }

  if (facultyListState.loading && facultyListState.loadedCount === 0) {
    $("#facultyResultsSummary").text("Loading faculty assignments...");
    return;
  }

  if (facultyListState.total === 0) {
    $("#facultyResultsSummary").text(
      facultyListState.search
        ? "No faculty assignments matched your search."
        : "No faculty assignments found."
    );
    return;
  }

  const noun = facultyListState.total === 1 ? "faculty assignment" : "faculty assignments";
  $("#facultyResultsSummary").text(
    "Showing " + facultyListState.loadedCount + " of " + facultyListState.total + " " + noun
  );
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/\"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

function escapeSelectorValue(value) {
  return String(value).replace(/'/g, "\\'");
}
</script>

</body>
</html>
