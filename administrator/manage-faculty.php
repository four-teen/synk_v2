<?php 
    session_start();
    ob_start();
    include '../backend/db.php';
    require_once '../backend/faculty_directory_helper.php';
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

    <title>Faculty Masterlist | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
      #facultyTable td {
        padding-top: 0.5rem !important;
        padding-bottom: 0.5rem !important;
      }
      #facultyTable td:last-child {
        white-space: nowrap !important;
      }

      .faculty-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
      }

      .faculty-search-group {
        width: 100%;
        max-width: 34rem;
      }

      .faculty-result-summary {
        color: #8592a3;
        font-size: 0.875rem;
        font-weight: 600;
        white-space: nowrap;
      }

      .faculty-mobile-list {
        display: grid;
        gap: 1rem;
      }

      .faculty-mobile-card {
        border: 1px solid rgba(105, 108, 255, 0.12);
        border-radius: 1rem;
        box-shadow: 0 10px 24px rgba(67, 89, 113, 0.08);
      }

      .faculty-mobile-card .card-body {
        padding: 1rem;
      }

      .faculty-mobile-top {
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
      }

      .faculty-mobile-index {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 2rem;
        height: 2rem;
        padding: 0 0.65rem;
        border-radius: 999px;
        background: rgba(105, 108, 255, 0.12);
        color: #696cff;
        font-size: 0.8rem;
        font-weight: 700;
      }

      .faculty-mobile-name {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
        color: #435971;
      }

      .faculty-mobile-designation {
        margin-top: 0.2rem;
      }

      .faculty-mobile-meta {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid rgba(67, 89, 113, 0.08);
      }

      .faculty-mobile-meta-label {
        display: block;
        margin-bottom: 0.25rem;
        color: #8592a3;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
      }

      .faculty-mobile-meta-value {
        color: #435971;
        font-size: 0.92rem;
        font-weight: 600;
      }

      .faculty-mobile-actions {
        display: flex;
        gap: 0.75rem;
        margin-top: 1rem;
      }

      .faculty-mobile-actions .btn {
        flex: 1 1 0;
      }

      .faculty-designation-pill {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        font-size: 0.75rem;
        padding: 0.3rem 0.65rem;
        border: 1px solid transparent;
        font-weight: 700;
        letter-spacing: 0.02em;
      }

      .faculty-classification-pill {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        font-size: 0.75rem;
        padding: 0.3rem 0.65rem;
        border: 1px solid #b7e2ee;
        background: #e9f7fb;
        color: #166578;
        font-weight: 700;
        letter-spacing: 0.02em;
      }

      .faculty-empty-state {
        border: 1px dashed rgba(67, 89, 113, 0.2);
        border-radius: 1rem;
        padding: 1.25rem;
        text-align: center;
        color: #8592a3;
        background: rgba(245, 246, 255, 0.7);
      }

      .faculty-load-hint {
        margin-top: 1rem;
        text-align: center;
        color: #8592a3;
        font-size: 0.85rem;
        font-weight: 600;
      }

      @media (min-width: 992px) {
        #facultyMobileList {
          display: none;
        }
      }

      @media (max-width: 767.98px) {
        .faculty-mobile-top {
          flex-direction: column;
        }

        .faculty-mobile-meta {
          grid-template-columns: 1fr;
        }

        .faculty-mobile-actions {
          flex-direction: column;
        }
      }

      @media (max-width: 991.98px) {
        .faculty-toolbar {
          flex-direction: column;
          align-items: stretch;
        }

        .faculty-search-group {
          max-width: 100%;
        }

        .faculty-result-summary {
          white-space: normal;
        }

        .faculty-table-wrap {
          display: none;
        }
      }

    </style>
</head>

<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">

    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>
    <!-- /Sidebar -->

    <!-- Layout page -->
    <div class="layout-page">

      <!-- Navbar -->
      <?php include 'navbar.php'; ?>
      <!-- /Navbar -->

      <!-- Content wrapper -->
      <div class="content-wrapper">

        <div class="container-xxl flex-grow-1 container-p-y">

          <!-- Title + Add Button -->
          <div class="d-flex justify-content-between align-items-center mb-4">
            <h4 class="fw-bold mb-0">
              <i class="bx bx-id-card me-2"></i> Faculty Masterlist
            </h4>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFacultyModal">
              <i class="bx bx-plus"></i> Add Faculty
            </button>
          </div>

          <!-- Card: Faculty List -->
          <div class="card">
            <div class="card-header">
              <h5 class="m-0">Faculty List</h5>
              <small class="text-muted">University-wide faculty registry</small>
            </div>

            <div class="card-datatable p-3">
              <div class="faculty-toolbar">
                <div class="input-group faculty-search-group">
                  <span class="input-group-text"><i class="bx bx-search"></i></span>
                  <input
                    type="search"
                    id="facultySearchInput"
                    class="form-control"
                    placeholder="Search by faculty name, designation, classification, or status"
                    autocomplete="off"
                  >
                  <button class="btn btn-outline-secondary" type="button" id="btnClearFacultySearch">Clear</button>
                </div>
                <div class="faculty-result-summary" id="facultyResultSummary">Loading faculty...</div>
              </div>

              <div class="faculty-table-wrap">
                <div class="table-responsive">
                  <table class="table table-hover align-middle" id="facultyTable">
                    <thead>
                      <tr>
                        <th style="width:60px;">#</th>
                        <th>Name</th>
                        <th>Designation</th>
                        <th>Employment Classification</th>
                        <th style="width:120px;">Status</th>
                        <th class="text-end" style="width:120px;">Actions</th>
                      </tr>
                    </thead>
                    <tbody></tbody>
                  </table>
                </div>
              </div>

              <div class="faculty-mobile-list" id="facultyMobileList"></div>
              <div class="faculty-load-hint d-none" id="facultyLoadHint"></div>
            </div>
          </div>

        </div>

        <!-- Footer -->
        <?php include '../footer.php'; ?>
        <!-- /Footer -->

        <div class="content-backdrop fade"></div>
      </div>
      <!-- /Content wrapper -->

    </div>
    <!-- /Layout page -->

  </div>

  <div class="layout-overlay layout-menu-toggle"></div>
</div>

<!-- ========================= -->
<!-- ADD FACULTY MODAL         -->
<!-- ========================= -->
<div class="modal fade" id="addFacultyModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Add New Faculty</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <form id="addFacultyForm">
          <div class="row g-3">

            <div class="col-lg-3 col-md-6">
              <label class="form-label">Last Name <span class="text-danger">*</span></label>
              <input type="text" name="last_name" class="form-control" required>
            </div>

            <div class="col-lg-3 col-md-6">
              <label class="form-label">First Name <span class="text-danger">*</span></label>
              <input type="text" name="first_name" class="form-control" required>
            </div>

            <div class="col-lg-3 col-md-6">
              <label class="form-label">Middle Name</label>
              <input type="text" name="middle_name" class="form-control">
            </div>

            <div class="col-lg-3 col-md-6">
              <label class="form-label">Extension</label>
              <input type="text" name="ext_name" class="form-control" placeholder="Jr., III">
            </div>

            <div class="col-12">
              <label class="form-label">Designation</label>
              <select name="designation_id" id="add_designation_id" class="form-select">
                <option value="">No Designation</option>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Employment Classification</label>
              <select name="employment_classification" id="add_employment_classification" class="form-select">
                <option value="">No Classification</option>
                <option value="permanent">Permanent</option>
                <option value="temporary">Temporary</option>
                <option value="contract_of_service">Contract of Service</option>
                <option value="part_time">Part Time</option>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <?php foreach (synk_faculty_record_statuses() as $value => $label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach; ?>
              </select>
            </div>

          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="btnSaveFaculty" type="button">Save</button>
      </div>

    </div>
  </div>
</div>

<!-- ========================= -->
<!-- EDIT FACULTY MODAL        -->
<!-- ========================= -->
<div class="modal fade" id="editFacultyModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Edit Faculty</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <form id="editFacultyForm">
          <input type="hidden" name="faculty_id" id="edit_faculty_id">

          <div class="row g-3">

            <div class="col-lg-3 col-md-6">
              <label class="form-label">Last Name</label>
              <input type="text" name="last_name" id="edit_last_name" class="form-control" required>
            </div>

            <div class="col-lg-3 col-md-6">
              <label class="form-label">First Name</label>
              <input type="text" name="first_name" id="edit_first_name" class="form-control" required>
            </div>

            <div class="col-lg-3 col-md-6">
              <label class="form-label">Middle Name</label>
              <input type="text" name="middle_name" id="edit_middle_name" class="form-control">
            </div>

            <div class="col-lg-3 col-md-6">
              <label class="form-label">Extension</label>
              <input type="text" name="ext_name" id="edit_ext_name" class="form-control">
            </div>

            <div class="col-12">
              <label class="form-label">Designation</label>
              <select name="designation_id" id="edit_designation_id" class="form-select">
                <option value="">No Designation</option>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Employment Classification</label>
              <select name="employment_classification" id="edit_employment_classification" class="form-select">
                <option value="">No Classification</option>
                <option value="permanent">Permanent</option>
                <option value="temporary">Temporary</option>
                <option value="contract_of_service">Contract of Service</option>
                <option value="part_time">Part Time</option>
              </select>
            </div>

            <div class="col-12">
              <label class="form-label">Status</label>
              <select name="status" id="edit_status" class="form-select">
                <?php foreach (synk_faculty_record_statuses() as $value => $label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach; ?>
              </select>
            </div>

          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="btnUpdateFaculty" type="button">Update</button>
      </div>

    </div>
  </div>
</div>


<!-- Core JS -->
<script src="../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="../assets/js/main.js"></script>


<script>
var facultyState = {
  allRecords: [],
  filteredRecords: [],
  visibleCount: 0,
  isAppending: false,
  desktopBatchSize: 40,
  mobileBatchSize: 12,
  lastScreenMode: ""
};

function escapeHtml(value) {
  return $("<div>").text(value == null ? "" : value).html();
}

function normalizeText(value) {
  return $.trim(String(value == null ? "" : value)).toLowerCase();
}

function isSmallScreen() {
  return $(window).width() < 992;
}

function getScreenMode() {
  return isSmallScreen() ? "mobile" : "desktop";
}

function getFacultyBatchSize() {
  return isSmallScreen() ? facultyState.mobileBatchSize : facultyState.desktopBatchSize;
}

function buildFacultyDataAttributes(item) {
  return (
    ' data-id="' + escapeHtml(item.id) + '"' +
    ' data-lname="' + escapeHtml(item.lname) + '"' +
    ' data-fname="' + escapeHtml(item.fname) + '"' +
    ' data-mname="' + escapeHtml(item.mname) + '"' +
    ' data-ext="' + escapeHtml(item.ext) + '"' +
    ' data-status="' + escapeHtml(item.status) + '"' +
    ' data-designation="' + escapeHtml(item.designationId) + '"' +
    ' data-designation-name="' + escapeHtml(item.designationName) + '"' +
    ' data-designation-style="' + escapeHtml(item.designationStyle) + '"' +
    ' data-employment-classification="' + escapeHtml(item.employmentClassification) + '"' +
    ' data-employment-classification-label="' + escapeHtml(item.employmentClassificationLabel) + '"'
  );
}

function buildFacultyDesignationHtml(item) {
  var styleAttr = "";

  if (item.designationName === "") {
    return '<span class="text-muted">Not Set</span>';
  }

  if (item.designationStyle !== "") {
    styleAttr = ' style="' + escapeHtml(item.designationStyle) + '"';
  }

  return '<span class="faculty-designation-pill"' + styleAttr + '>' + escapeHtml(item.designationName) + '</span>';
}

function buildFacultyClassificationHtml(item) {
  if (item.employmentClassificationLabel === "") {
    return '<span class="text-muted">Not Set</span>';
  }

  return '<span class="faculty-classification-pill">' + escapeHtml(item.employmentClassificationLabel) + '</span>';
}

function buildFacultyActionButtonsHtml(item, isCard) {
  var editClass = isCard
    ? "btn btn-outline-warning btnEditFaculty"
    : "btn btn-sm btn-warning btnEditFaculty";
  var deleteClass = isCard
    ? "btn btn-outline-danger btnDeleteFaculty"
    : "btn btn-sm btn-danger btnDeleteFaculty";
  var editLabel = isCard
    ? '<i class="bx bx-edit me-1"></i>Edit'
    : '<i class="bx bx-edit"></i>';
  var deleteLabel = isCard
    ? '<i class="bx bx-trash me-1"></i>Delete'
    : '<i class="bx bx-trash"></i>';

  return (
    '<button class="' + editClass + '"' + buildFacultyDataAttributes(item) + ' type="button">' + editLabel + '</button>' +
    ' <button class="' + deleteClass + '" data-id="' + escapeHtml(item.id) + '" type="button">' + deleteLabel + '</button>'
  );
}

function buildFacultyRowHtml(item, displayIndex) {
  return (
    "<tr>" +
      "<td>" + displayIndex + ".</td>" +
      "<td>" + escapeHtml(item.fullName) + "</td>" +
      "<td>" + buildFacultyDesignationHtml(item) + "</td>" +
      "<td>" + buildFacultyClassificationHtml(item) + "</td>" +
      "<td>" + item.statusHtml + "</td>" +
      '<td class="text-end">' + buildFacultyActionButtonsHtml(item, false) + "</td>" +
    "</tr>"
  );
}

function buildFacultyCardHtml(item, displayIndex) {
  return (
    '<div class="card faculty-mobile-card">' +
      '<div class="card-body">' +
        '<div class="faculty-mobile-top">' +
          '<div>' +
            '<span class="faculty-mobile-index">#' + displayIndex + '</span>' +
            '<h6 class="faculty-mobile-name mt-3">' + escapeHtml(item.fullName) + '</h6>' +
            '<div class="faculty-mobile-designation">' + buildFacultyDesignationHtml(item) + '</div>' +
          '</div>' +
          '<div>' + item.statusHtml + '</div>' +
        '</div>' +
        '<div class="faculty-mobile-meta">' +
          '<div>' +
            '<span class="faculty-mobile-meta-label">Name</span>' +
            '<span class="faculty-mobile-meta-value">' + escapeHtml(item.fullName) + '</span>' +
          '</div>' +
          '<div>' +
            '<span class="faculty-mobile-meta-label">Status</span>' +
            '<span class="faculty-mobile-meta-value">' + escapeHtml(item.statusText) + '</span>' +
          '</div>' +
          '<div>' +
            '<span class="faculty-mobile-meta-label">Classification</span>' +
            '<span class="faculty-mobile-meta-value">' + escapeHtml(item.employmentClassificationLabel || "Not Set") + '</span>' +
          '</div>' +
        '</div>' +
        '<div class="faculty-mobile-actions">' + buildFacultyActionButtonsHtml(item, true) + '</div>' +
      '</div>' +
    '</div>'
  );
}

function parseFacultyPayload(payload) {
  var records = [];
  var $table = $("<table><tbody></tbody></table>");

  $table.find("tbody").html(payload);

  $table.find("tbody tr").each(function() {
    var $row = $(this);
    var $cells = $row.find("td");
    var $editButton = $row.find(".btnEditFaculty").first();
    var record = null;

    if ($cells.length < 6 || $editButton.length === 0) {
      return;
    }

    record = {
      id: String($editButton.attr("data-id") || ""),
      lname: String($editButton.attr("data-lname") || ""),
      fname: String($editButton.attr("data-fname") || ""),
      mname: String($editButton.attr("data-mname") || ""),
      ext: String($editButton.attr("data-ext") || ""),
      status: String($editButton.attr("data-status") || ""),
      designationId: String($editButton.attr("data-designation") || ""),
      designationName: $.trim(String($editButton.attr("data-designation-name") || $cells.eq(2).text() || "")),
      designationStyle: String($editButton.attr("data-designation-style") || ""),
      employmentClassification: String($editButton.attr("data-employment-classification") || ""),
      employmentClassificationLabel: $.trim(String($editButton.attr("data-employment-classification-label") || "")),
      fullName: $.trim($cells.eq(1).text()),
      statusHtml: $.trim($cells.eq(4).html() || ""),
      statusText: $.trim($cells.eq(4).text() || "")
    };

    if (record.statusHtml === "") {
      record.statusHtml = '<span class="badge bg-secondary">' + escapeHtml(record.statusText || "N/A") + '</span>';
    }

    record.searchText = normalizeText(
      [
        record.fullName,
        record.lname,
        record.fname,
        record.mname,
        record.ext,
        record.designationName,
        record.employmentClassificationLabel,
        record.statusText
      ].join(" ")
    );

    records.push(record);
  });

  return records;
}

function updateFacultySummary() {
  var loaded = facultyState.visibleCount;
  var filtered = facultyState.filteredRecords.length;
  var total = facultyState.allRecords.length;
  var summaryText = "";

  if (filtered === 0) {
    summaryText = total === 0
      ? "No faculty records available"
      : "No faculty matched your search";
  } else if (filtered === total) {
    summaryText = "Showing " + loaded + " of " + total + " faculty";
  } else {
    summaryText = "Showing " + loaded + " of " + filtered + " matching faculty (" + total + " total)";
  }

  $("#facultyResultSummary").text(summaryText);
}

function updateFacultyLoadHint() {
  var $hint = $("#facultyLoadHint");
  var filtered = facultyState.filteredRecords.length;

  if (filtered === 0) {
    $hint.addClass("d-none").text("");
    return;
  }

  if (facultyState.visibleCount < filtered) {
    $hint.removeClass("d-none").text("Scroll down to load more faculty.");
    return;
  }

  if (filtered > getFacultyBatchSize()) {
    $hint.removeClass("d-none").text("All faculty currently loaded.");
    return;
  }

  $hint.addClass("d-none").text("");
}

function showFacultyLoadMessage(message) {
  var safeMessage = escapeHtml(message || "No faculty records found.");

  $("#facultyTable tbody").html(
    '<tr><td colspan="6" class="text-center text-muted py-3">' + safeMessage + "</td></tr>"
  );
  $("#facultyMobileList").html('<div class="faculty-empty-state">' + safeMessage + '</div>');
  $("#facultyLoadHint").addClass("d-none").text("");
  $("#facultyResultSummary").text(message || "No faculty records found.");
}

function clearFacultyRenderTargets() {
  $("#facultyTable tbody").empty();
  $("#facultyMobileList").empty();
}

function filterFacultyRecords(searchTerm) {
  var normalizedTerm = normalizeText(searchTerm);
  var filtered = [];
  var i = 0;

  if (normalizedTerm === "") {
    facultyState.filteredRecords = facultyState.allRecords.slice(0);
    return;
  }

  for (i = 0; i < facultyState.allRecords.length; i++) {
    if (facultyState.allRecords[i].searchText.indexOf(normalizedTerm) !== -1) {
      filtered.push(facultyState.allRecords[i]);
    }
  }

  facultyState.filteredRecords = filtered;
}

function renderNextFacultyBatch() {
  var filtered = facultyState.filteredRecords;
  var start = facultyState.visibleCount;
  var end = 0;
  var i = 0;
  var tableHtml = "";
  var cardHtml = "";
  var smallScreen = isSmallScreen();

  if (facultyState.isAppending || start >= filtered.length) {
    updateFacultySummary();
    updateFacultyLoadHint();
    return;
  }

  facultyState.isAppending = true;
  end = Math.min(start + getFacultyBatchSize(), filtered.length);

  for (i = start; i < end; i++) {
    if (smallScreen) {
      cardHtml += buildFacultyCardHtml(filtered[i], i + 1);
    } else {
      tableHtml += buildFacultyRowHtml(filtered[i], i + 1);
    }
  }

  if (smallScreen) {
    $("#facultyMobileList").append(cardHtml);
  } else {
    $("#facultyTable tbody").append(tableHtml);
  }

  facultyState.visibleCount = end;
  facultyState.isAppending = false;

  updateFacultySummary();
  updateFacultyLoadHint();
  window.setTimeout(ensureFacultyViewportFilled, 0);
}

function resetFacultyRender() {
  clearFacultyRenderTargets();
  facultyState.visibleCount = 0;

  if (facultyState.filteredRecords.length === 0) {
    showFacultyLoadMessage(
      facultyState.allRecords.length === 0
        ? "No faculty records found."
        : "No faculty matched your search."
    );
    return;
  }

  renderNextFacultyBatch();
}

function ensureFacultyViewportFilled() {
  var guard = 0;

  while (
    facultyState.visibleCount < facultyState.filteredRecords.length &&
    $(document).height() <= ($(window).height() + 120) &&
    guard < 10
  ) {
    renderNextFacultyBatch();
    guard++;
  }
}

function maybeLoadMoreFaculty() {
  var remainingHeight = 0;

  if (
    facultyState.isAppending ||
    facultyState.visibleCount >= facultyState.filteredRecords.length ||
    facultyState.filteredRecords.length === 0
  ) {
    return;
  }

  remainingHeight = $(document).height() - ($(window).scrollTop() + $(window).height());
  if (remainingHeight < 220) {
    renderNextFacultyBatch();
  }
}

function applyFacultySearch() {
  filterFacultyRecords($("#facultySearchInput").val());
  resetFacultyRender();
}

function loadDesignationOptions($selectElement, selectedId) {
  selectedId = selectedId || "";

  $.ajax({
    url: "../backend/query_designation.php",
    type: "POST",
    data: { load_designation_options: 1 },
    cache: false,
    success: function(optionsHtml) {
      if (typeof optionsHtml === "string" && optionsHtml.indexOf("<option") !== -1) {
        $selectElement.html(optionsHtml);
      } else {
        $selectElement.html('<option value="">No Designation</option>');
      }

      if (selectedId !== "") {
        $selectElement.val(String(selectedId));
      } else {
        $selectElement.val("");
      }
    },
    error: function() {
      $selectElement.html('<option value="">No Designation</option>');
      $selectElement.val("");
    }
  });
}

function loadFaculty() {
  showFacultyLoadMessage("Loading faculty...");

  $.ajax({
    url: "../backend/query_faculty.php",
    type: "POST",
    data: { load_faculty: 1 },
    cache: false,
    success: function(data) {
      var payload = $.trim(data);

      if (payload && payload.indexOf("<tr") !== -1) {
        facultyState.allRecords = parseFacultyPayload(payload);
        filterFacultyRecords($("#facultySearchInput").val());
        resetFacultyRender();
      } else if (payload !== "") {
        showFacultyLoadMessage("Unable to load faculty list. Please check the faculty table structure.");
      } else {
        showFacultyLoadMessage("No faculty records found.");
      }
    },
    error: function() {
      showFacultyLoadMessage("Failed to load faculty list. Please refresh the page.");
    }
  });
}

facultyState.lastScreenMode = getScreenMode();
loadDesignationOptions($("#add_designation_id"));
loadFaculty();

$("#facultySearchInput").on("input", function() {
  applyFacultySearch();
});

$("#btnClearFacultySearch").on("click", function() {
  $("#facultySearchInput").val("");
  applyFacultySearch();
  $("#facultySearchInput").focus();
});

$(window).on("scroll", function() {
  maybeLoadMoreFaculty();
});

$(window).on("resize", function() {
  var currentMode = getScreenMode();

  if (currentMode !== facultyState.lastScreenMode) {
    facultyState.lastScreenMode = currentMode;
    resetFacultyRender();
  } else {
    maybeLoadMoreFaculty();
  }
});

// ------------------------------------
// SAVE FACULTY (KEEP MODAL OPEN)
// ------------------------------------
$("#btnSaveFaculty").click(function (e) {
  e.preventDefault();

  $.post(
    "../backend/query_faculty.php",
    $("#addFacultyForm").serialize() + "&save_faculty=1",
    function(res) {
      res = $.trim(res);

      if (res === "missing") {
        Swal.fire("Missing Data", "Please fill out required fields.", "warning");
        return;
      }

      if (res === "invalid_designation") {
        Swal.fire("Invalid Designation", "Please choose a valid active designation or leave it blank.", "warning");
        return;
      }

      if (res === "invalid_classification") {
        Swal.fire("Invalid Classification", "Please choose a valid employment classification or leave it blank.", "warning");
        return;
      }

      if (res === "schema_update_required") {
        Swal.fire("Update Needed", "Please check the faculty, designation, and employment classification table structure.", "warning");
        return;
      }

      if (res !== "success") {
        Swal.fire("Save Failed", "Could not add faculty. Please refresh and try again.", "error");
        return;
      }

      // SUCCESS ALERT
      Swal.fire({
        icon: "success",
        title: "Saved!",
        text: "Faculty added successfully.",
        timer: 1200,
        showConfirmButton: false
      });

      // RESET FORM FOR NEXT ENTRY
      $("#addFacultyModal").modal("hide");
      $("#addFacultyForm")[0].reset();

      // SET FOCUS BACK TO FIRST NAME OR LAST NAME
      loadDesignationOptions($("#add_designation_id"));
      $("#add_designation_id").val("");
      $("#add_employment_classification").val("");
      $("input[name='last_name']").focus();

      // REFRESH TABLE
      loadFaculty();
    }
  );
});


// allow Enter key to trigger save in add modal
$("#addFacultyForm input").on("keypress", function(e) {
  if (e.which === 13) {
    e.preventDefault();
    $("#btnSaveFaculty").click();
  }
});

// ------------------------------------
// OPEN EDIT MODAL
// ------------------------------------
  $(document).on("click", ".btnEditFaculty", function () {

  $("#edit_faculty_id").val($(this).data("id"));
  $("#edit_last_name").val($(this).data("lname"));
  $("#edit_first_name").val($(this).data("fname"));
  $("#edit_middle_name").val($(this).data("mname"));
  $("#edit_ext_name").val($(this).data("ext"));
  $("#edit_status").val($(this).data("status"));
  $("#edit_employment_classification").val($(this).data("employmentClassification") || "");
  loadDesignationOptions($("#edit_designation_id"), String($(this).data("designation") || ""));

  $("#editFacultyModal").modal("show");
});

// ------------------------------------
// UPDATE FACULTY
// ------------------------------------
$("#btnUpdateFaculty").click(function (e) {
  e.preventDefault();

  $.post(
    "../backend/query_faculty.php",
    $("#editFacultyForm").serialize() + "&update_faculty=1",
    function(res) {
      res = $.trim(res);

      if (res === "missing") {
        Swal.fire("Missing Data", "Please fill out required fields.", "warning");
        return;
      }

      if (res === "invalid_designation") {
        Swal.fire("Invalid Designation", "Please choose a valid active designation or leave it blank.", "warning");
        return;
      }

      if (res === "invalid_classification") {
        Swal.fire("Invalid Classification", "Please choose a valid employment classification or leave it blank.", "warning");
        return;
      }

      if (res === "schema_update_required") {
        Swal.fire("Update Needed", "Please check the faculty, designation, and employment classification table structure.", "warning");
        return;
      }

      if (res !== "success") {
        Swal.fire("Update Failed", "Could not update faculty. Please refresh and try again.", "error");
        return;
      }

      Swal.fire({
        icon: "success",
        title: "Updated!",
        text: "Faculty record updated.",
        timer: 1200,
        showConfirmButton: false
      });

      $("#editFacultyModal").modal("hide");

      // REFRESH TABLE
      loadFaculty();
    }
  );

});

// ------------------------------------
// DELETE FACULTY
// ------------------------------------
$(document).on("click", ".btnDeleteFaculty", function () {
  var id = $(this).data("id");

  Swal.fire({
    title: "Are you sure?",
    text: "This faculty will be permanently deleted.",
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#aaa",
    confirmButtonText: "Delete"
  }).then(function(result) {
    if (result.isConfirmed) {
      $.post(
        "../backend/query_faculty.php",
        { delete_faculty: 1, faculty_id: id },
        function(res) {
          Swal.fire("Deleted!", "Faculty removed successfully.", "success");
          loadFaculty();
        }
      );
    }
  });

});

</script>

</body>
</html>
