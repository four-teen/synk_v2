<?php 
    session_start();
    ob_start();
    include '../backend/db.php';
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

    <title>Subject Masterlist | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="custom_css.css">

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
        #subjectTable td {
            padding-top: 0.6rem !important;
            padding-bottom: 0.6rem !important;
        }
        #subjectTable td:last-child {
            white-space: nowrap !important;
        }
        /* FORCE SweetAlert ABOVE Bootstrap Modal */
        .swal2-container {
            z-index: 20000 !important;
        }

    </style>
</head>

<body>
<!-- Layout wrapper -->
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">

    <!-- Sidebar -->
    <?php include 'sidebar.php'; ?>
    <!-- /Sidebar -->

    <!-- Layout container -->
    <div class="layout-page">

      <!-- Navbar -->
      <?php include 'navbar.php'; ?>
      <!-- / Navbar -->

      <!-- Content wrapper -->
      <div class="content-wrapper">

        <div class="container-xxl flex-grow-1 container-p-y">

          <!-- Title + Add Button -->
          <div class="d-flex justify-content-between align-items-center mb-4">
              <h4 class="fw-bold"><i class="bx bx-book me-2"></i> Subject Masterlist</h4>
              <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
                  <i class="bx bx-plus"></i> Add Subject
              </button>
          </div>

          <!-- Card: Subject List -->
          <div class="card">
            <div class="card-header">
                <h5 class="m-0">Subject List</h5>
                <small class="text-muted">All subjects across the university</small>
            </div>

            <div class="card-datatable table-responsive p-3">
              <table class="table table-hover align-middle" id="subjectTable">
                <thead>
                  <tr>
                    <th style="width: 60px;">#</th>
                    <th style="width: 150px;">Subject Code</th>
                    <th>Subject Description</th>
                    <th>Used In Program(s)</th>
                    <th style="width: 120px;">Status</th>
                    <th class="text-end" style="width: 120px;">Actions</th>
                  </tr>
                </thead>

                <tbody></tbody>
              </table>
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

<!-- ========================= -->
<!-- ADD SUBJECT MODAL -->
<!-- ========================= -->
<div class="modal fade" id="addSubjectModal" tabindex="-1">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
          <h5 class="modal-title">Add New Subject</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
          <form id="addSubjectForm">

              <div class="mb-3">
                  <label class="form-label">Subject Code <span class="text-danger">*</span></label>
                  <input type="text" name="sub_code" class="form-control" required placeholder="e.g., ENG 1">
              </div>

              <div class="mb-3">
                  <label class="form-label">Subject Description <span class="text-danger">*</span></label>
                  <input type="text" name="sub_description" class="form-control" required placeholder="e.g., Basic English">
              </div>

          </form>
      </div>

      <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" id="btnSaveSubject">Save</button>
      </div>

    </div>
  </div>
</div>

<!-- ========================= -->
<!-- EDIT SUBJECT MODAL -->
<!-- ========================= -->
<div class="modal fade" id="editSubjectModal" tabindex="-1">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
          <h5 class="modal-title">Edit Subject</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
          <form id="editSubjectForm">

              <input type="hidden" name="sub_id" id="edit_sub_id">

              <div class="mb-3">
                  <label class="form-label">Subject Code</label>
                  <input type="text" name="sub_code" id="edit_sub_code" class="form-control" required>
              </div>

              <div class="mb-3">
                  <label class="form-label">Subject Description</label>
                  <input type="text" name="sub_description" id="edit_sub_description" class="form-control" required>
              </div>

              <div class="mb-3">
                  <label class="form-label">Status</label>
                  <select name="status" id="edit_sub_status" class="form-select">
                      <option value="active">Active</option>
                      <option value="inactive">Inactive</option>
                  </select>
              </div>

          </form>
      </div>

      <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-primary" id="btnUpdateSubject">Update</button>
      </div>

    </div>
  </div>
</div>


<!-- Core JS -->
<script src="../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.0/sweetalert2.all.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>


<script src="../assets/js/main.js"></script>

<script>

// INITIAL LOAD
loadSubjects();

// ------------------------------------------------------
// LOAD SUBJECT LIST
// ------------------------------------------------------
function loadSubjects() {

    // If DataTable already exists → destroy it first
    if ($.fn.DataTable.isDataTable('#subjectTable')) {
        $('#subjectTable').DataTable().clear().destroy();
    }

    // Load the new rows
    $.post('../backend/query_subject_masterlist.php', { load_subjects: 1 }, function(data) {

        $('#subjectTable tbody').html(data);

        // Reinitialize DataTable AFTER rows are updated
        $('#subjectTable').DataTable({
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            ordering: true,
            responsive: true
        });

    });
}



// ------------------------------------------------------
// SAVE SUBJECT
// ------------------------------------------------------
// SAVE SUBJECT (KEEP MODAL OPEN + CLEAR INPUTS + FOCUS)
$("#btnSaveSubject").click(function () {

    $.post('../backend/query_subject_masterlist.php',
        $("#addSubjectForm").serialize() + "&save_subject=1",
        function(res) {

            if (res === "duplicate") {
                Swal.fire({
                    icon: "warning",
                    title: "Duplicate Subject",
                    text: "This subject already exists (same code and description).",

                    // 🔥 FORCE IN FRONT OF MODAL
                    target: document.getElementById('addSubjectModal'),

                    customClass: {
                        container: 'swal2-container'
                    }
                });
                return; // ⛔ STOP HERE
            }

            Swal.fire({
                icon: "success",
                title: "Saved!",
                text: "Subject added successfully.",
                timer: 1200,
                showConfirmButton: false,

                // 🔥 FORCE position above modal
                target: document.getElementById('addSubjectModal'),

                customClass: {
                    container: 'swal2-container'
                }
            });


            // Clear inputs
            $("#addSubjectForm")[0].reset();

            // Focus back to subject code
            $("input[name='sub_code']").focus();

            // Reload table
            loadSubjects();
        }
    );

});

// ------------------------------------------------------
// ENTER KEY TRIGGERS SAVE (FAST ENCODING MODE)
// ------------------------------------------------------
$("#addSubjectForm input").on("keypress", function (e) {
    if (e.which === 13) { // 13 = Enter key
        e.preventDefault(); // Stop form from submitting normally
        $("#btnSaveSubject").click(); // Trigger save
    }
});


// ------------------------------------------------------
// OPEN EDIT MODAL
// ------------------------------------------------------
$(document).on("click", ".btnEdit", function () {
    $("#edit_sub_id").val($(this).data("id"));
    $("#edit_sub_code").val($(this).data("code"));
    $("#edit_sub_description").val($(this).data("desc"));
    $("#edit_sub_status").val($(this).data("status"));
    $("#editSubjectModal").modal("show");
});

// ------------------------------------------------------
// UPDATE SUBJECT
// ------------------------------------------------------
$("#btnUpdateSubject").click(function () {
    $.post('../backend/query_subject_masterlist.php', $("#editSubjectForm").serialize() + "&update_subject=1", function(res) {
        Swal.fire("Updated!", "Subject updated successfully!", "success");
        $("#editSubjectModal").modal("hide");
        loadSubjects();
    });
});

// ------------------------------------------------------
// DELETE SUBJECT
// ------------------------------------------------------
$(document).on("click", ".btnDelete", function () {
    let id = $(this).data("id");

    Swal.fire({
        title: "Are you sure?",
        text: "This subject will be permanently deleted.",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonColor: "#aaa",
        confirmButtonText: "Delete"
    }).then((result) => {

        if (result.isConfirmed) {

            $.post(
                '../backend/query_subject_masterlist.php',
                { delete_subject: 1, sub_id: id },
                function (res) {

                    // 🚫 SUBJECT IS IN USE
                    if (res === "in_use") {
                        Swal.fire(
                            "Action Blocked",
                            "This subject is already used in a prospectus and cannot be deleted.",
                            "warning"
                        );
                        return;
                    }

                    // ✅ SUCCESS DELETE
                    Swal.fire(
                        "Deleted!",
                        "Subject removed successfully!",
                        "success"
                    );

                    loadSubjects();
                }
            );

        }


    });

});

</script>

</body>
</html>
