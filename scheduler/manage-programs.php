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

    <title>Program Management | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <style>
      #programTable td {
          padding-top: 0.65rem !important;
          padding-bottom: 0.65rem !important;
      }
      #programTable th:last-child,
      #programTable td:last-child {
          white-space: nowrap !important;
      }
      .card-datatable {
          padding-bottom: 20px !important;
      }
    </style>

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
            <div class="container-xxl flex-grow-1 container-p-y">

              <!-- Title + Add Button -->
              <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="fw-bold"><i class="bx bx-book-alt me-2"></i> Program Management</h4>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProgramModal">
                  <i class="bx bx-plus"></i> Add Program
                </button>
              </div>

              <!-- Program List Card -->
              <div class="card">
                <div class="card-header">
                  <h5 class="m-0">Program List</h5>
                  <small class="text-muted">Programs under each college</small>
                </div>

                <div class="card-datatable table-responsive p-3">
                  <table class="table table-hover align-middle" id="programTable">
                    <thead>
                      <tr>
                        <th>#</th>
                        <th>College</th>
                        <th>Program Code</th>
                        <th>Program Name</th>
                        <th>Major</th>
                        <th>Status</th>
                        <th class="text-end" style="width:120px;">Actions</th>
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


    <!-- ADD PROGRAM MODAL -->
    <div class="modal fade" id="addProgramModal" tabindex="-1">
      <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">

          <div class="modal-header">
            <h5 class="modal-title">Add New Program</h5>
            <button class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">
            <form id="addProgramForm">

              <div class="mb-3">
                <label class="form-label">Select College <span class="text-danger">*</span></label>
                <select name="college_id" class="form-select" required>
                  <option value="">Select College</option>
                  <?php
                    $colleges = $conn->query("SELECT college_id, college_name FROM tbl_college WHERE status='active'");
                    while ($c = $colleges->fetch_assoc()) {
                      echo "<option value='{$c['college_id']}'>{$c['college_name']}</option>";
                    }
                  ?>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label">Program Code <span class="text-danger">*</span></label>
                <input type="text" name="program_code" class="form-control" placeholder="e.g., BSIT" required>
              </div>

              <div class="mb-3">
                <label class="form-label">Program Name <span class="text-danger">*</span></label>
                <input type="text" name="program_name" class="form-control" placeholder="Bachelor of Science in Information Technology" required>
              </div>

              <div class="mb-3">
                <label class="form-label">Major (Optional)</label>
                <input type="text" name="major" class="form-control" placeholder="e.g., Network Technology">
              </div>

            </form>
          </div>

          <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" id="btnSaveProgram">Save</button>
          </div>

        </div>
      </div>
    </div>


    <!-- EDIT PROGRAM MODAL -->
    <div class="modal fade" id="editProgramModal" tabindex="-1">
      <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">

          <div class="modal-header">
            <h5 class="modal-title">Edit Program</h5>
            <button class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">
            <form id="editProgramForm">

              <input type="hidden" name="program_id" id="edit_program_id">

              <div class="mb-3">
                <label class="form-label">Select College</label>
                <select name="college_id" id="edit_college_id" class="form-select">
                  <?php
                    $colleges = $conn->query("SELECT college_id, college_name FROM tbl_college WHERE status='active'");
                    while ($c = $colleges->fetch_assoc()) {
                      echo "<option value='{$c['college_id']}'>{$c['college_name']}</option>";
                    }
                  ?>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label">Program Code</label>
                <input type="text" name="program_code" id="edit_program_code" class="form-control">
              </div>

              <div class="mb-3">
                <label class="form-label">Program Name</label>
                <input type="text" name="program_name" id="edit_program_name" class="form-control">
              </div>

              <div class="mb-3">
                <label class="form-label">Major</label>
                <input type="text" name="major" id="edit_major" class="form-control">
              </div>

              <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" id="edit_program_status" class="form-select">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>

            </form>
          </div>

          <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" id="btnUpdateProgram">Update</button>
          </div>

        </div>
      </div>
    </div>


    <!-- JS Files -->
    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
      loadPrograms();

      function loadPrograms() {
        $.post("../backend/query_program.php", { load_programs: 1 }, function(data) {
          $("#programTable tbody").html(data);
        });
      }

      $("#btnSaveProgram").click(function () {
        $.post("../backend/query_program.php",
            $("#addProgramForm").serialize() + "&save_program=1",
            function() {
              Swal.fire("Success!", "Program added successfully!", "success");
              $("#addProgramModal").modal("hide");
              loadPrograms();
            }
        );
      });

      $(document).on("click", ".btnEdit", function() {
        $("#edit_program_id").val($(this).data("id"));
        $("#edit_college_id").val($(this).data("college"));
        $("#edit_program_code").val($(this).data("code"));
        $("#edit_program_name").val($(this).data("name"));
        $("#edit_major").val($(this).data("major"));
        $("#edit_program_status").val($(this).data("status"));
        $("#editProgramModal").modal("show");
      });

      $("#btnUpdateProgram").click(function() {
        $.post("../backend/query_program.php",
            $("#editProgramForm").serialize() + "&update_program=1",
            function() {
              Swal.fire("Updated!", "Program updated successfully!", "success");
              $("#editProgramModal").modal("hide");
              loadPrograms();
            }
        );
      });

      $(document).on("click", ".btnDelete", function () {
        let id = $(this).data("id");

        Swal.fire({
          title: "Delete this program?",
          text: "This cannot be undone.",
          icon: "warning",
          showCancelButton: true,
          confirmButtonColor: "#d33",
          cancelButtonColor: "#aaa",
          confirmButtonText: "Delete"
        }).then((result)=>{
          if(result.isConfirmed){
            $.post("../backend/query_program.php",
                { delete_program: 1, program_id: id },
                function() {
                  Swal.fire("Deleted!", "Program removed successfully.", "success");
                  loadPrograms();
                }
            );
          }
        });
      });
    </script>

  </body>
</html>
