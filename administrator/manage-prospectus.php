<?php 
    session_start();
    ob_start();
    include '../backend/db.php';

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    $csrfToken = (string)$_SESSION['csrf_token'];
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

    <title>Prospectus Builder | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet"
      href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />

    <link rel="stylesheet" type="text/css" href="custom_css.css">
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
      .prospectus-header-label {
        font-size: 0.85rem;
        text-transform: uppercase;
        color: #888;
        letter-spacing: 0.04em;
      }
      .accordion-button span.badge {
        font-size: 0.75rem;
      }
      .table-prospectus td {
        padding-top: 0.4rem !important;
        padding-bottom: 0.4rem !important;
      }

      .swal2-container {
          z-index: 20000 !important;
      }

      /* Smaller and cleaner SweetAlert */
      .swal2-popup {
          font-size: 0.85rem !important;
          padding: 1.2rem !important;
          border-radius: 12px !important;
      }

      /* Green loader bar */
      .swal2-timer-progress-bar {
          background: #28a745 !important; /* Bootstrap success green */
      }

      /* Ensure alert stays on top of modal */
      .swal2-container {
          z-index: 20000 !important;
      }

      .accordion-header {
          background: #FFF7E6 !important;     /* light beige */
          border: 1px solid #FFE4C2 !important;
          border-radius: 6px;
          padding: 6px 12px;
          margin-bottom: 6px;
      }

    /* ======================================================
       SELECT2 HEIGHT & ALIGNMENT FIX (GLOBAL)
       Matches Bootstrap .form-select height
    ====================================================== */

    /* Main single select */
    .select2-container--default .select2-selection--single {
        height: calc(2.25rem + 2px);      /* Bootstrap form-select height */
        padding: 0.375rem 0.75rem;
        border: 1px solid #d9dee3;
        border-radius: 0.375rem;
        display: flex;
        align-items: center;
    }

    /* Selected text alignment */
    .select2-container--default 
    .select2-selection--single 
    .select2-selection__rendered {
        line-height: normal;
        padding-left: 0;
        padding-right: 0;
        color: #566a7f;
    }

    /* Dropdown arrow alignment */
    .select2-container--default 
    .select2-selection--single 
    .select2-selection__arrow {
        height: 100%;
        top: 0;
    }

    /* Focus state (matches Sneat) */
    .select2-container--default.select2-container--focus 
    .select2-selection--single {
        border-color: #696cff;
        box-shadow: 0 0 0 0.15rem rgba(105,108,255,.25);
    }

    /* ======================================================
       MULTI-SELECT (PREREQUISITES)
    ====================================================== */

    .select2-container--default .select2-selection--multiple {
        min-height: calc(2.25rem + 2px);
        padding: 0.25rem 0.5rem;
        border: 1px solid #d9dee3;
        border-radius: 0.375rem;
    }

    /* Chips (selected items) */
    .select2-container--default 
    .select2-selection--multiple 
    .select2-selection__choice {
        margin-top: 0.25rem;
        background-color: #e7e7ff;
        border: none;
        color: #696cff;
        font-size: 0.75rem;
        border-radius: 0.25rem;
    }

    /* ======================================================
       DISABLED STATE
    ====================================================== */

    .select2-container--default 
    .select2-selection--single.select2-selection--disabled {
        background-color: #f5f5f9;
        cursor: not-allowed;
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

          <!-- TITLE -->
          <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">
              <i class="bx bx-spreadsheet me-2"></i> Prospectus Builder
            </h4>
          </div>

          <!-- ===================== -->
          <!-- PROSPECTUS HEADER     -->
          <!-- ===================== -->
          <div class="card mb-4">
            <div class="card-header">
              <h5 class="mb-0">Prospectus Information</h5>
              <small class="text-muted">Select program and define CMO + Effectivity</small>
            </div>
            <div class="card-body">

              <form id="prospectusHeaderForm" class="row g-3 align-items-end">
                <input type="hidden" id="prospectus_id" name="prospectus_id">

                <!-- Program -->
                <div class="col-md-5">
                  <label class="form-label prospectus-header-label">Program</label>
                  <select class="form-select" name="program_id" id="program_id" required>
                    <option value="">Select Program</option>

                    <?php
                      $role = $_SESSION['role'];
                      $myCollege = $_SESSION['college_id'];

                      // ADMIN → can see ALL programs
                      if ($role === 'admin') {

$prog = $conn->query("
  SELECT 
    p.program_id, 
    p.program_code, 
    p.program_name, 
    p.major,
    ca.campus_code
  FROM tbl_program p
  INNER JOIN tbl_college c ON p.college_id = c.college_id
  INNER JOIN tbl_campus ca ON ca.campus_id = c.campus_id
  WHERE p.status='active'
  ORDER BY ca.campus_code, p.program_name, p.major
");

                      } 
                      // SCHEDULER → can see ONLY programs under *their* college
                      else if ($role === 'scheduler') {

                      $prog = $conn->query("
                        SELECT 
                          p.program_id, 
                          p.program_code, 
                          p.program_name, 
                          p.major,
                          c.college_name
                        FROM tbl_program p
                        LEFT JOIN tbl_college c ON p.college_id = c.college_id
                        WHERE p.status='active'
                          AND p.college_id = '$myCollege'
                        ORDER BY p.program_name, p.major
                      ");
                      }

                      while ($r = $prog->fetch_assoc()) {

                          $programName = ucwords(strtolower($r['program_name']));
                          $programCode = strtoupper($r['program_code']);
                          $major       = trim($r['major']);

                          // Build readable label
                          if ($major !== '') {
                              $baseLabel = $programName . ' major in ' . $major . ' (' . $programCode . ')';
                          } else {
                              $baseLabel = $programName . ' (' . $programCode . ')';
                          }

                          // ADMIN includes CAMPUS CODE
                          if ($role === 'admin') {
                              $label = $r['campus_code'] . ' - ' . $baseLabel;
                          } else {
                              $label = $baseLabel;
                          }

                          echo "<option value='{$r['program_id']}'>" . htmlspecialchars($label) . "</option>";
                      }

                    ?>

                  </select>
                </div>


                <!-- CMO No -->
                <div class="col-md-3">
                  <label class="form-label prospectus-header-label">CMO Number</label>
                  <input type="text" class="form-control" name="cmo_no" id="cmo_no"
                         placeholder="e.g., CMO 25, Series of 2015" required>
                </div>

                <!-- Effectivity SY -->
                <div class="col-md-2">
                  <label class="form-label prospectus-header-label">Effectivity SY</label>
                  <input type="text" class="form-control" name="effective_sy" id="effective_sy"
                         placeholder="e.g., 2018-2019" required>
                </div>

<div class="col-md-2 text-end">
  <button type="button" id="btnSaveHeader" class="btn btn-success w-100 mb-1">
    <i class="bx bx-save"></i> Save New
  </button>

  <button type="button" id="btnLoadHeader" class="btn btn-primary w-100 mb-1">
    <i class="bx bx-search"></i> Load Existing
  </button>

  <!-- ADMIN ONLY -->
  <?php if ($_SESSION['role'] === 'admin'): ?>
  <button type="button" id="btnCopyProspectus" class="btn btn-warning w-100">
    <i class="bx bx-copy"></i> Copy Prospectus
  </button>
  <?php endif; ?>
</div>

              </form>

            </div>
          </div>

          <!-- ===================== -->
          <!-- YEAR / SEM SECTIONS   -->
          <!-- ===================== -->
          <div class="card" id="yearSemCard" style="display:none;">
            <div class="card-header d-flex justify-content-between align-items-center">
              <div>
                <h5 class="mb-0">Year & Semester Structure</h5>
                <small class="text-muted">Manage subjects per year and semester</small>
              </div>
              <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#yearSemModal">
                <i class="bx bx-plus"></i> Add Year / Semester
              </button>
            </div>

            <div class="card-body">
              <div class="accordion" id="yearSemAccordion">
                <!-- Dynamic items via AJAX -->
              </div>
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

<!-- ====================================== -->
<!-- MODAL: ADD YEAR / SEMESTER             -->
<!-- ====================================== -->
<div class="modal fade" id="yearSemModal" tabindex="-1">
  <div class="modal-dialog modal-sm modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add Year & Semester</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="yearSemForm">
          <input type="hidden" name="prospectus_id" id="ys_prospectus_id">

          <div class="mb-3">
            <label class="form-label">Year Level</label>
            <select name="year_level" class="form-select" required>
              <option value="">Select</option>
              <option value="1">First Year</option>
              <option value="2">Second Year</option>
              <option value="3">Third Year</option>
              <option value="4">Fourth Year</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Semester</label>
            <select name="semester" class="form-select" required>
              <option value="">Select</option>
              <option value="1">First Semester</option>
              <option value="2">Second Semester</option>
              <option value="3">Summer</option>
            </select>
          </div>

        </form>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="btnSaveYearSem">Save</button>
      </div>
    </div>
  </div>
</div>

<!-- ====================================== -->
<!-- MODAL: ADD SUBJECT TO YEAR/SEM         -->
<!-- ====================================== -->
<div class="modal fade" id="subjectModal" tabindex="-1">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add Subject to Prospectus</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <form id="prospectusSubjectForm">
          <input type="hidden" name="pys_id" id="ps_pys_id">

          <!-- MAIN SUBJECT -->
          <div class="mb-3">
            <label class="form-label">Subject</label>
            <select name="sub_id" id="ps_sub_id" class="form-select" required>
              <option value="">Select Subject</option>
              <?php
                $sub = $conn->query("
                  SELECT sub_id, sub_code, sub_description
                  FROM tbl_subject_masterlist
                  WHERE status='active'
                  ORDER BY sub_code
                ");
                while ($s = $sub->fetch_assoc()) {
                  $label = $s['sub_code'] . ' - ' . $s['sub_description'];
                  echo "<option value='{$s['sub_id']}'>" . htmlspecialchars($label) . "</option>";
                }
              ?>
            </select>
          </div>

          <!-- UNITS -->
          <div class="row">
              <div class="col-md-3 mb-3">
                  <label class="form-label">Lec Hours</label>
                  <input type="number" name="lec_units" id="ps_lec_units"
                         class="form-control calc-units" min="0" step="1" value="0">
              </div>

              <div class="col-md-3 mb-3">
                  <label class="form-label">Lab Hours</label>
                  <input type="number" name="lab_units" id="ps_lab_units"
                         class="form-control calc-units" min="0" step="1" value="0">
              </div>

              <div class="col-md-3 mb-3">
                  <label class="form-label">Total Units</label>
                  <input type="number" name="total_units" id="ps_total_units"
                         class="form-control" min="0" step="0.01" value="0" readonly>
                  <small class="text-muted">Auto: Lec Hours + (Lab Hours / 3)</small>
              </div>

              <div class="col-md-3 mb-3">
                  <label class="form-label">Sort Order</label>
                  <input type="number" name="sort_order" id="ps_sort_order"
                         class="form-control" min="1" value="1">
              </div>
          </div>


          <!-- PRE-REQUISITE SUBJECTS -->
          <div class="mb-3">
            <label class="form-label">Pre-requisite Subjects</label>
            <select name="prerequisites[]" 
                    id="ps_prerequisites" 
                    class="form-select js-prereq-multiple" 
                    multiple="multiple">
              <!-- options will be populated by AJAX (loadPrerequisiteOptions) -->
            </select>
          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="btnSaveProspectusSubject">Save</button>
      </div>
    </div>
  </div>
</div>



<div class="modal fade" id="loadProspectusModal" tabindex="-1">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Load Prospectus</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <label class="form-label">Select Prospectus</label>
        <select id="existingProspectusList" class="form-select">
          <option value="">Loading...</option>
        </select>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="btnLoadSelectedProspectus">Load</button>
      </div>
    </div>
  </div>
</div>


<!-- ====================================== -->
<!-- MODAL: EDIT SUBJECT (NEW / SEPARATE)   -->
<!-- ====================================== -->
<div class="modal fade" id="editSubjectModal" tabindex="-1">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Edit Prospectus Subject</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <form id="editProspectusSubjectForm">
          <input type="hidden" name="ps_id" id="edit_ps_id">

          <!-- SUBJECT (editable) -->
          <div class="mb-3">
            <label class="form-label">Subject</label>
            <select name="sub_id" id="edit_sub_id" class="form-select" required>
              <option value="">Select Subject</option>
              <?php
                $sub = $conn->query("
                  SELECT sub_id, sub_code, sub_description
                  FROM tbl_subject_masterlist
                  WHERE status='active'
                  ORDER BY sub_code
                ");
                while ($s = $sub->fetch_assoc()) {
                  $label = $s['sub_code'] . ' - ' . $s['sub_description'];
                  echo "<option value='{$s['sub_id']}'>" . htmlspecialchars($label) . "</option>";
                }
              ?>
            </select>
          </div>

          <!-- UNITS -->
          <div class="row">
            <div class="col-md-3 mb-3">
              <label class="form-label">Lec Hours</label>
              <input type="number" name="lec_units" id="edit_lec_units" class="form-control" min="0" step="1" value="0">
            </div>

            <div class="col-md-3 mb-3">
              <label class="form-label">Lab Hours</label>
              <input type="number" name="lab_units" id="edit_lab_units" class="form-control" min="0" step="1" value="0">
            </div>

            <div class="col-md-3 mb-3">
              <label class="form-label">Total Units</label>
              <input type="number" name="total_units" id="edit_total_units" class="form-control" min="0" step="0.01" value="0" readonly>
              <small class="text-muted">Auto: Lec Hours + (Lab Hours / 3)</small>
            </div>

            <div class="col-md-3 mb-3">
              <label class="form-label">Sort Order</label>
              <input type="number" name="sort_order" id="edit_sort_order" class="form-control" min="1" value="1">
            </div>
          </div>

          <!-- PREREQUISITES -->
          <div class="mb-3">
            <label class="form-label">Pre-requisite Subjects</label>
            <select name="prerequisites[]" id="edit_prerequisites" class="form-select" multiple="multiple">
              <!-- will be populated via AJAX (same pattern) -->
            </select>
          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="btnUpdateProspectusSubject">Update</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="copyProspectusModal" tabindex="-1">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Copy Prospectus</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <form id="copyProspectusForm">

          <div class="mb-3">
            <label class="form-label">Source Prospectus</label>
            <select name="source_prospectus_id" id="copy_source_prospectus" class="form-select" required>
              <option value="">Select Prospectus</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Target Program</label>
            <select name="target_program_id" id="copy_target_program" class="form-select" required>
              <option value="">Select Program</option>
              <!-- reuse same program list query -->
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">CMO Number</label>
            <input type="text" name="cmo_no" id="copy_cmo_no" class="form-control" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Effectivity SY</label>
            <input type="text" name="effective_sy" id="copy_effective_sy" class="form-control" required>
          </div>

        </form>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-warning" id="btnConfirmCopyProspectus">
          <i class="bx bx-copy"></i> Copy
        </button>
      </div>

    </div>
  </div>
</div>


<!-- JS -->
    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>    
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="../assets/js/dashboards-analytics.js"></script>


<script>
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

$.ajaxPrefilter(function (options) {
    if (String(options.type || "GET").toUpperCase() !== "POST") {
        return;
    }

    if (typeof options.data === "string") {
        if (options.data.indexOf("csrf_token=") === -1) {
            const tokenPair = "csrf_token=" + encodeURIComponent(CSRF_TOKEN);
            options.data = options.data ? (options.data + "&" + tokenPair) : tokenPair;
        }
        return;
    }

    if (Array.isArray(options.data)) {
        const hasToken = options.data.some(function (item) {
            return item && item.name === "csrf_token";
        });

        if (!hasToken) {
            options.data.push({ name: "csrf_token", value: CSRF_TOKEN });
        }
        return;
    }

    if ($.isPlainObject(options.data)) {
        if (!Object.prototype.hasOwnProperty.call(options.data, "csrf_token")) {
            options.data.csrf_token = CSRF_TOKEN;
        }
        return;
    }

    options.data = { csrf_token: CSRF_TOKEN };
});

const LAB_HOURS_PER_UNIT = 3;

function roundProspectusUnits(value) {
    return Math.round((Number(value) + Number.EPSILON) * 100) / 100;
}

function formatProspectusUnits(value) {
    const rounded = roundProspectusUnits(Math.max(0, Number(value) || 0));
    return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(2).replace(/\.?0+$/, "");
}

function syncProspectusTotalUnits(lecSelector, labSelector, totalSelector) {
    const lecHours = Math.max(0, Number($(lecSelector).val()) || 0);
    const labHours = Math.max(0, Number($(labSelector).val()) || 0);
    const totalUnits = roundProspectusUnits(lecHours + (labHours / LAB_HOURS_PER_UNIT));

    $(totalSelector).val(formatProspectusUnits(totalUnits));
    return totalUnits;
}

function normalizeProspectusHours(lecValue, labValue, totalUnitsValue) {
    const lecHours = Math.max(0, Number(lecValue) || 0);
    const rawLabValue = Math.max(0, Number(labValue) || 0);
    const storedTotalUnits = Math.max(0, Number(totalUnitsValue) || 0);
    const legacyLabCredits =
        rawLabValue > 0 && Math.abs((lecHours + rawLabValue) - storedTotalUnits) < 0.0001;

    return {
        lecHours,
        labHours: legacyLabCredits ? (rawLabValue * LAB_HOURS_PER_UNIT) : rawLabValue
    };
}

$(document).on("input change", "#ps_lec_units, #ps_lab_units", function () {
    syncProspectusTotalUnits("#ps_lec_units", "#ps_lab_units", "#ps_total_units");
});

$(document).on("input change", "#edit_lec_units, #edit_lab_units", function () {
    syncProspectusTotalUnits("#edit_lec_units", "#edit_lab_units", "#edit_total_units");
});

// ===============================
// EDIT SUBJECTS
// ===============================

$("#btnUpdateProspectusSubject").click(function () {

  let prereqIds = $("#edit_prerequisites").val() || [];
  let prereqJson = prereqIds.length ? JSON.stringify(prereqIds) : null;

  let prereqText = $("#edit_prerequisites option:selected")
    .map(function(){ return $(this).text().split(" - ")[0].trim(); })
    .get()
    .filter(v => v !== "")
    .join(", ");

  let payload = $("#editProspectusSubjectForm").serializeArray();

  // remove prerequisites[] array entries
  payload = payload.filter(x => x.name !== "prerequisites[]");

  // add both formats like your add flow
  payload.push({ name: "prerequisites", value: prereqText });
  payload.push({ name: "prerequisite_sub_ids", value: prereqJson });
  payload.push({ name: "update_prospectus_subject", value: 1 });

  $.post("../backend/query_prospectus.php", payload, function(res){

    let parts = res.split("|");
    if (parts[0] !== "OK") {
      Swal.fire("Error", parts[1] || "Update failed", "error");
      return;
    }

    Swal.fire({
      icon: "success",
      title: "Updated!",
      text: parts[2] || "Subject updated successfully.",
      timer: 1200,
      timerProgressBar: true,
      showConfirmButton: false,
      heightAuto: false
    });

    $("#editSubjectModal").modal("hide");
    loadYearSem($("#prospectus_id").val());
  });

});


$(document).on("click", ".btnEditSubject", function () {

  let ps_id = $(this).data("ps");
  let prospectus_id = $("#prospectus_id").val();

  $("#edit_ps_id").val(ps_id);

  // load options first, then load row and select
  loadPrerequisiteOptionsEdit(prospectus_id).then(() => {

    // init select2 multi (avoid duplicates)
    if ($('#edit_prerequisites').hasClass("select2-hidden-accessible")) {
      $('#edit_prerequisites').select2('destroy');
    }

    $('#edit_prerequisites').select2({
      placeholder: "Select prerequisite subjects",
      allowClear: true,
      width: "100%",
      dropdownParent: $('#editSubjectModal')
    });

    $.post("../backend/query_prospectus.php",
      { load_subject_for_edit: 1, ps_id: ps_id },
      function(resp){

        let item = JSON.parse(resp);
        const normalized = normalizeProspectusHours(item.lec_units, item.lab_units, item.total_units);

        $("#edit_sub_id").val(item.sub_id).trigger("change");
        $("#edit_lec_units").val(formatProspectusUnits(normalized.lecHours));
        $("#edit_lab_units").val(formatProspectusUnits(normalized.labHours));
        syncProspectusTotalUnits("#edit_lec_units", "#edit_lab_units", "#edit_total_units");
        $("#edit_sort_order").val(item.sort_order);

        // prerequisites selected IDs (JSON)
        let prereqIds = [];
        try {
          prereqIds = item.prerequisite_sub_ids ? JSON.parse(item.prerequisite_sub_ids) : [];
        } catch(e) { prereqIds = []; }

        $("#edit_prerequisites").val(prereqIds).trigger("change");

        $("#editSubjectModal").modal("show");
      }
    );
  });

});


function loadPrerequisiteOptionsEdit(prospectus_id) {
  return $.post("../backend/query_prospectus.php",
    { get_all_subjects: 1, prospectus_id },
    function(res){
      let data = JSON.parse(res);
      let select = $("#edit_prerequisites");
      select.empty();

      data.forEach(sub => {
        select.append(`<option value="${sub.sub_id}">${sub.sub_code} - ${sub.sub_description}</option>`);
      });
    }
  );
}

$('#edit_sub_id').select2({
  placeholder: "Select Subject",
  allowClear: true,
  width: "100%",
  dropdownParent: $('#editSubjectModal')
});


// ===============================
// END EDIT SUBJECTS
// ===============================

let lastOpenedPys = null;

$(document).on("shown.bs.collapse", ".accordion-collapse", function () {
    lastOpenedPys = $(this).attr("id"); // example: collapse12
});

function loadPrerequisiteOptions(prospectus_id) {
    return $.post(
        "../backend/query_prospectus.php",
        { get_all_subjects: 1, prospectus_id },
        function(res) {
            let data = JSON.parse(res);
            let select = $("#ps_prerequisites");

            select.empty();

            data.forEach(sub => {
                select.append(
                    `<option value="${sub.sub_id}">
                        ${sub.sub_code} - ${sub.sub_description}
                    </option>`
                );
            });
        }
    );
}

// -----------------------------
// DELETE YEAR SEMESTER
// -----------------------------
$(document).on("click", ".btnDeleteYearSem", function () {
    let pys_id = $(this).data("pys");

    Swal.fire({
        title: "Delete Year & Semester?",
        html: "<small>This will permanently remove this year/semester <br> and all subjects under it.</small>",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonColor: "#aaa",
        confirmButtonText: "Yes, delete it",
    }).then((result) => {
        if (result.isConfirmed) {
            $.post("../backend/query_prospectus.php", {
                delete_year_sem: 1,
                pys_id: pys_id
            }, function (res) {

                let parts = res.split("|");
                let status = parts[0];
                let msg = parts[1];

                if (status === "OK") {
                    Swal.fire({
                        icon: "success",
                        title: msg,
                        timer: 1200,
                        showConfirmButton: false,
                    });

                    loadYearSem($("#prospectus_id").val());
                } else {
                    Swal.fire("Error", msg, "error");
                }
            });
        }
    });
});


// -----------------------------
// GLOBAL FUNCTION (must be here)
// -----------------------------
// function loadYearSem(prospectus_id) {
//     console.log("Loading Year/Sem for ID:", prospectus_id);

//     $.post(
//         "../backend/query_prospectus.php",
//         { load_year_sem: 1, prospectus_id: prospectus_id },
//         function (html) {
//             $("#yearSemAccordion").html(html);
//         }
//     );
// }
function loadYearSem(prospectus_id) {
    $.post(
        "../backend/query_prospectus.php",
        { load_year_sem: 1, prospectus_id: prospectus_id },
        function (html) {
            $("#yearSemAccordion").html(html);

            // Re-open the previously opened section
            if (lastOpenedPys) {
                $("#" + lastOpenedPys).collapse("show");
            }
        }
    );
}

// -----------------------------
// DOCUMENT READY
// -----------------------------
$(document).ready(function () {


/* ======================================================
   GLOBAL SELECT2 (UI ONLY)
   Applies to all visible page selects (non-modal)
====================================================== */
$('select.form-select').each(function () {

    // Skip selects that are inside modals
    if ($(this).closest('.modal').length > 0) return;

    // Prevent double initialization
    if ($(this).hasClass('select2-hidden-accessible')) return;

    $(this).select2({
        width: '100%',
        allowClear: true,
        placeholder: $(this).find('option:first').text() || 'Select option'
    });
});

$('#yearSemModal').on('shown.bs.modal', function () {

    $(this).find('select').each(function () {

        if ($(this).hasClass('select2-hidden-accessible')) return;

        $(this).select2({
            width: '100%',
            dropdownParent: $('#yearSemModal'),
            placeholder: 'Select option',
            allowClear: true
        });
    });
});


// Init Select2 for existing prospectus list
if ($('#existingProspectusList').hasClass('select2-hidden-accessible')) {
    $('#existingProspectusList').select2('destroy');
}

$('#existingProspectusList').select2({
    width: '100%',
    allowClear: true,
    placeholder: 'Select Prospectus',
    dropdownParent: $('#loadProspectusModal')
});






// -------------------------------------------
// OPEN COPY PROSPECTUS MODAL (ADMIN)
// -------------------------------------------
$("#btnCopyProspectus").click(function () {

    $("#copyProspectusModal").modal("show");

    // Load prospectus list (same as load existing)
    $.post("../backend/query_prospectus.php",
        { load_prospectus_list: 1 },
        function (res) {

            $("#copy_source_prospectus")
                .empty()
                .append('<option value="">Select Prospectus</option>');

                res.forEach(item => {

                    // ------------------------------------------
                    // BUILD PROGRAM LABEL (WITH OPTIONAL MAJOR)
                    // ------------------------------------------
let campus = item.campus_code || 'N/A';

let programLabel = item.program_name;

if (item.major && item.major.trim() !== "") {
    programLabel += ` major in ${item.major}`;
}

programLabel += ` (${item.program_code})`;

let label = `${campus} – ${programLabel} — ${item.cmo_no} — ${item.effective_sy}`;

                    $("#copy_source_prospectus").append(
                        `<option value="${item.prospectus_id}"
                                 data-cmo="${item.cmo_no}"
                                 data-sy="${item.effective_sy}">
                            ${label}
                         </option>`
                    );
                });
// ------------------------------------------
// INIT SELECT2 FOR SOURCE PROSPECTUS
// ------------------------------------------
if ($('#copy_source_prospectus').hasClass('select2-hidden-accessible')) {
    $('#copy_source_prospectus').select2('destroy');
}

$('#copy_source_prospectus').select2({
    placeholder: 'Select Prospectus',
    allowClear: true,
    width: '100%',
    dropdownParent: $('#copyProspectusModal')
});


        },
        "json"
    );

    // Load target program list (reuse same pattern as header)
    $.post("../backend/query_prospectus_copy.php",
        { load_programs: 1 },
        function (res) {

            $("#copy_target_program")
                .empty()
                .append('<option value="">Select Program</option>');

res.forEach(p => {

    // ------------------------------------------
    // BUILD TARGET PROGRAM LABEL (WITH MAJOR)
    // ------------------------------------------
let programLabel = `${p.campus_code} – ${p.program_name}`;

if (p.major && p.major.trim() !== "") {
    programLabel += ` major in ${p.major}`;
}

programLabel += ` (${p.program_code})`;

    $("#copy_target_program").append(
        `<option value="${p.program_id}">
            ${programLabel}
         </option>`
    );
});
// ------------------------------------------
// INIT SELECT2 FOR TARGET PROGRAM
// ------------------------------------------
if ($('#copy_target_program').hasClass("select2-hidden-accessible")) {
    $('#copy_target_program').select2('destroy');
}

$('#copy_target_program').select2({
    placeholder: "Select Program",
    allowClear: true,
    width: "100%",
    dropdownParent: $('#copyProspectusModal')
});

        },
        "json"
    );

});

// -------------------------------------------
// AUTO-FILL CMO + EFFECTIVITY FROM SOURCE
// -------------------------------------------
$("#copy_source_prospectus").on("change", function () {

    let opt = $(this).find("option:selected");

    $("#copy_cmo_no").val(opt.data("cmo") || "");
    $("#copy_effective_sy").val(opt.data("sy") || "");

});

// -------------------------------------------
// CONFIRM COPY PROSPECTUS
// -------------------------------------------
$("#btnConfirmCopyProspectus").click(function () {

    let formData = $("#copyProspectusForm").serialize();

    $.post("../backend/query_prospectus_copy.php",
        formData + "&copy_prospectus=1",
        function (res) {

            let parts = res.split("|");

            if (parts[0] !== "OK") {
                Swal.fire("Error", parts[1], "error");
                return;
            }

            let newId = parts[1];

            Swal.fire({
                icon: "success",
                title: "Prospectus Copied!",
                text: "New prospectus created successfully.",
                timer: 1200,
                showConfirmButton: false
            });

            $("#copyProspectusModal").modal("hide");

            // AUTO-LOAD NEW PROSPECTUS
            $("#prospectus_id").val(newId);
            $("#ys_prospectus_id").val(newId);
            $("#yearSemCard").show();

            loadYearSem(newId);
        }
    );

});



// Initialize SUBJECT dropdown as single-select Select2
$('#ps_sub_id').select2({
    placeholder: "Select Subject",
    allowClear: true,
    width: "100%",
    dropdownParent: $('#subjectModal')
});

    // Make globally accessible
    window.loadYearSem = loadYearSem;

    // -------------------------------------------
    // OPEN MODAL + LOAD PROSPECTUS LIST
    // -------------------------------------------
    $("#btnLoadHeader").click(function () {
        $("#loadProspectusModal").modal("show");

        $.ajax({
            url: "../backend/query_prospectus.php",
            type: "POST",
            data: { load_prospectus_list: 1 },
            dataType: "json",
            success: function (res) {
                $("#existingProspectusList").empty()
                    .append(`<option value="">Select Prospectus</option>`);

/* ======================================================
   BUILD LOAD PROSPECTUS LABEL (WITH CAMPUS CODE)
====================================================== */
res.forEach(item => {

    let programLabel = item.program_name;

    if (item.major && item.major.trim() !== '') {
        programLabel += ` major in ${item.major}`;
    }

    programLabel += ` (${item.program_code})`;

    let campus = item.campus_code || 'N/A';

    let finalLabel = `${campus} – ${programLabel} — ${item.cmo_no} — ${item.effective_sy}`;

    $("#existingProspectusList").append(`
        <option value="${item.prospectus_id}">
            ${finalLabel}
        </option>
    `);
});


            }
        });
    });

    // -------------------------------------------
    // LOAD SELECTED PROSPECTUS HEADER
    // -------------------------------------------
    $("#btnLoadSelectedProspectus").click(function () {

        let pid = $("#existingProspectusList").val();
        if (!pid) return;

        $.post("../backend/query_prospectus.php",
            { load_header: 1, prospectus_id: pid },
            function (res) {

                let parts = res.split("|");

                if (parts[0] !== "OK") {
                    Swal.fire("Error", parts[1], "error");
                    return;
                }

                // Populate fields
                $("#prospectus_id").val(pid);
                $("#ys_prospectus_id").val(pid);
                $("#program_id").val(parts[1]);
                $("#cmo_no").val(parts[2]);
                $("#effective_sy").val(parts[3]);

                Swal.fire({
                    icon: "success",
                    title: "Loaded!",
                    text: "Prospectus loaded successfully.",
                    timer: 1000,
                    timerProgressBar: true,
                    showConfirmButton: false,
                    heightAuto: false
                });


                $("#loadProspectusModal").modal("hide");
                $("#yearSemCard").show();

                loadYearSem(pid);
            }
        );
    });

    // -------------------------------------------
    // SAVE NEW PROSPECTUS HEADER
    // -------------------------------------------
    $("#btnSaveHeader").click(function () {

        $.post(
            "../backend/query_prospectus.php",
            $("#prospectusHeaderForm").serialize() + "&save_header=1",
            function (res) {

                let parts = res.split("|");
                let status = parts[0];
                let id = parts[1];
                let msg = parts[2];

                Swal.fire({
                    icon: status === "OK" ? "success" : "error",
                    title: msg
                });

                if (status === "OK") {
                    $("#prospectus_id").val(id);
                    $("#ys_prospectus_id").val(id);
                    $("#yearSemCard").show();
                    loadYearSem(id);
                }
            }
        );
    });

    // -------------------------------------------
    // SAVE NEW YEAR / SEM ENTRY
    // -------------------------------------------
    $("#btnSaveYearSem").click(function () {

        $.post(
            "../backend/query_prospectus.php",
            $("#yearSemForm").serialize() + "&save_year_sem=1",
            function (res) {

                let parts = res.split("|");
                let status = parts[0];
                let msg = parts[2];

                if (status === "ERROR") {
                    Swal.fire("Error", msg, "error");
                    return;
                }

                if (status === "EXISTS") {
                    Swal.fire("Notice", msg, "info");
                    $("#yearSemModal").modal("hide");
                    return;
                }

                Swal.fire("Success", msg, "success");
                $("#yearSemModal").modal("hide");

                loadYearSem($("#prospectus_id").val());
            }
        );
    });

// -------------------------------------------
// OPEN ADD SUBJECT MODAL
// -------------------------------------------
$(document).on("click", ".btnAddSubject", function () {

    let pys = $(this).data("pys");
    console.log("PYS SENT:", pys);   // ← ADD THIS
    // $("#ps_pys_id").val(pys);
    $('#subjectModal').find('#ps_pys_id').val(pys);
    $("#ps_lec_units").val("0");
    $("#ps_lab_units").val("0");
    syncProspectusTotalUnits("#ps_lec_units", "#ps_lab_units", "#ps_total_units");

    // Load prerequisite options (AJAX)
    loadPrerequisiteOptions($("#prospectus_id").val()).then(() => {

    // Destroy previous Select2 to avoid duplicate initialization
    if ($('#ps_prerequisites').hasClass("select2-hidden-accessible")) {
        $('#ps_prerequisites').select2('destroy');
    }

    // Initialize Select2 normally
    $('#ps_prerequisites').select2({
        placeholder: "Select prerequisite subjects",
        allowClear: true,
        width: "100%",
        dropdownParent: $('#subjectModal')
    });

        });

        $("#subjectModal").modal("show");
    });

// Destroy old Select2 (avoid duplicate instances)
if ($('#ps_sub_id').hasClass("select2-hidden-accessible")) {
    $('#ps_sub_id').select2('destroy');
}

// Reinitialize as single select
$('#ps_sub_id').select2({
    placeholder: "Select Subject",
    allowClear: true,
    width: "100%",
    dropdownParent: $('#subjectModal')
});

// Clear previous value
$('#ps_sub_id').val('').trigger('change');



    // -------------------------------------------
    // SAVE SUBJECT
    // -------------------------------------------
$("#btnSaveProspectusSubject").click(function () {

    // Get all form values manually
    let form = $('#subjectModal').find('#prospectusSubjectForm');
    let formData = form.serializeArray();

// Get selected prerequisite IDs
let prereqIds = $("#ps_prerequisites").val() || [];   // array of sub_id

// JSON version (for DB logic)
let prereqJson = prereqIds.length ? JSON.stringify(prereqIds) : null;

// Human-readable string (for display)
let prereqText = $("#ps_prerequisites option:selected")
    .map(function () {
        // get only sub_code AND clean spaces
        return $(this)
            .text()
            .split(" - ")[0]
            .trim();   // ✅ REMOVE leading/trailing spaces
    })
    .get()
    .filter(v => v !== "")   // ✅ safety: remove empty
    .join(", ");             // ✅ consistent spacing


// Remove old prerequisites[] entry
formData = formData.filter(item => item.name !== "prerequisites[]");

// Add display string
formData.push({ name: "prerequisites", value: prereqText });

// Add JSON ID list
formData.push({ name: "prerequisite_sub_ids", value: prereqJson });

// Save flag
formData.push({ name: "save_prospectus_subject", value: 1 });

    $.post("../backend/query_prospectus.php", formData, function (res) {

        console.log("Subject Save Response:", res);

        let parts = res.split("|");
        let status = parts[0];
        let msg = parts[2];

        if (status === "ERROR") {
            Swal.fire("Error", msg, "error");
            return;
        }

        if (status === "EXISTS") {
            Swal.fire("Warning", msg, "warning");
            return;
        }

      if (status === "OK") {
          Swal.fire({
              icon: "success",
              title: "Saved Successfully!",
              text: msg,
              timer: 1200,
              timerProgressBar: true,
              showConfirmButton: false,
              heightAuto: false,
          });

          // Reload year/semester subject list
          loadYearSem($("#prospectus_id").val());

          // Clear subject & prerequisites
          $("#ps_sub_id").val("").trigger("change");
          $("#ps_prerequisites").val([]).trigger("change");
          $("#ps_lec_units").val("0");
          $("#ps_lab_units").val("0");
          syncProspectusTotalUnits("#ps_lec_units", "#ps_lab_units", "#ps_total_units");

          // ✅ Increment sort order automatically
          let currentSort = parseInt($("#ps_sort_order").val()) || 1;
          $("#ps_sort_order").val(currentSort + 1);

          // ✅ Focus back to subject dropdown
          setTimeout(() => {
              $("#ps_sub_id").select2("open"); // or .focus() if not select2
          }, 200);
      }

    });

});

    // -------------------------------------------
    // DELETE SUBJECT
    // -------------------------------------------
    $(document).on("click", ".btnDeleteSubject", function () {

        let ps_id = $(this).data("ps");

        Swal.fire({
            title: "Remove subject?",
            text: "This will permanently remove the subject.",
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            cancelButtonColor: "#aaa",
            confirmButtonText: "Yes, remove it"
        }).then((result) => {

            if (result.isConfirmed) {

                $.post(
                    "../backend/query_prospectus.php",
                    { delete_prospectus_subject: 1, ps_id: ps_id },
                    function (res) {

                        let parts = res.split("|");
                        let status = parts[0];
                        let msg = parts[1];

                        if (status === "OK") {
                            Swal.fire("Removed", msg, "success");
                            loadYearSem($("#prospectus_id").val());
                        } else {
                            Swal.fire("Error", msg, "error");
                        }
                    }
                );
            }
        });
    });



});
</script>



</body>
</html>
