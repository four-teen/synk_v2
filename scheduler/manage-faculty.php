<?php 
    session_start();
    ob_start();
    include '../backend/db.php';
require_once __DIR__ . '/term-context.php';
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
    <link rel="stylesheet" type="text/css" href="custom_css.css">
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

            <div class="card-datatable table-responsive p-3">
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
        <button class="btn btn-primary" id="btnSaveFaculty">Save</button>
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
        <button class="btn btn-primary" id="btnUpdateFaculty">Update</button>
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

// INITIAL LOAD
loadFaculty();

// ------------------------------------
// LOAD FACULTY LIST
// ------------------------------------
function loadFaculty() {
  $.post("../backend/query_faculty.php", { load_faculty: 1 }, function(data) {
    $("#facultyTable tbody").html(data);
  });
}

// ------------------------------------
// SAVE FACULTY (KEEP MODAL OPEN)
// ------------------------------------
$("#btnSaveFaculty").click(function () {

  $.post(
    "../backend/query_faculty.php",
    $("#addFacultyForm").serialize() + "&save_faculty=1",
    function(res) {
      res = $.trim(res);

      if (res === "missing") {
        Swal.fire("Missing Data", "Please fill out required fields.", "warning");
        return;
      }

      if (res === "invalid_classification") {
        Swal.fire("Invalid Classification", "Please choose a valid employment classification or leave it blank.", "warning");
        return;
      }

      if (res === "schema_update_required") {
        Swal.fire("Update Needed", "Please check the faculty table structure.", "warning");
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

      // DO NOT CLOSE MODAL
      // $("#addFacultyModal").modal("hide"); remove this

      // RESET FORM FOR NEXT ENTRY
      $("#addFacultyForm")[0].reset();
      $("#add_employment_classification").val("");

      // SET FOCUS BACK TO FIRST NAME OR LAST NAME
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

  $("#editFacultyModal").modal("show");
});

// ------------------------------------
// UPDATE FACULTY
// ------------------------------------
$("#btnUpdateFaculty").click(function () {

  $.post(
    "../backend/query_faculty.php",
    $("#editFacultyForm").serialize() + "&update_faculty=1",
    function(res) {
      res = $.trim(res);

      if (res === "missing") {
        Swal.fire("Missing Data", "Please fill out required fields.", "warning");
        return;
      }

      if (res === "invalid_classification") {
        Swal.fire("Invalid Classification", "Please choose a valid employment classification or leave it blank.", "warning");
        return;
      }

      if (res === "schema_update_required") {
        Swal.fire("Update Needed", "Please check the faculty table structure.", "warning");
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
      loadFaculty();
    }
  );

});

// ------------------------------------
// DELETE FACULTY
// ------------------------------------
$(document).on("click", ".btnDeleteFaculty", function () {
  let id = $(this).data("id");

  Swal.fire({
    title: "Are you sure?",
    text: "This faculty will be permanently deleted.",
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#aaa",
    confirmButtonText: "Delete"
  }).then((result) => {
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
