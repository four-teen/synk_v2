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

    <title>Campus Management | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap"
      rel="stylesheet"
    />

    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
      /* Better spacing for table rows */
      #campusTable td {
          padding-top: 0.65rem !important;
          padding-bottom: 0.65rem !important;
      }

      /* Fix spacing below card */
      .card-datatable {
          padding-bottom: 20px !important;
      }

      /* Prevent stretching action buttons */
      #campusTable th:last-child,
      #campusTable td:last-child {
          white-space: nowrap !important;
      }
    </style>

    
  </head>

  <body>
    <!-- Layout wrapper -->
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">

        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>
        <!-- / Sidebar -->

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
                <h4 class="fw-bold"><i class="bx bx-buildings me-2"></i> Campus Management</h4>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCampusModal">
                  <i class="bx bx-plus"></i> Add Campus
                </button>
              </div>

              <!-- Card: Campus List -->
              <div class="card">
                <div class="card-header">
                  <h5 class="m-0">Campus List</h5>
                  <small class="text-muted">All campuses under the university</small>
                </div>

              <div class="card-datatable table-responsive p-3">
                <table class="table table-hover align-middle" id="campusTable">
                  <thead>
                    <tr>
                      <th style="width: 60px;">#</th>
                      <th style="width: 150px;">Campus Code</th>
                      <th>Campus Name</th>
                      <th style="width: 120px;">Status</th>
                      <th class="text-end text-nowrap" style="width: 120px;">Actions</th>
                    </tr>
                  </thead>
                  <tbody></tbody>
                </table>
              </div>
              </div>

            </div>
            <!-- / Container -->

            <!-- Footer -->
            <?php include '../footer.php'; ?>
            <!-- / Footer -->

            <div class="content-backdrop fade"></div>
          </div>
          <!-- Content wrapper -->

        </div>
        <!-- / Layout container -->

      </div>

      <div class="layout-overlay layout-menu-toggle"></div>

    </div>
    <!-- / Layout wrapper -->

    <!-- ADD CAMPUS MODAL -->
    <div class="modal fade" id="addCampusModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">

          <div class="modal-header">
            <h5 class="modal-title">Add New Campus</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">
            <form id="addCampusForm">

              <div class="mb-3">
                <label class="form-label">Campus Code <span class="text-danger">*</span></label>
                <input type="text" name="campus_code" class="form-control" placeholder="e.g., ISU" required>
              </div>

              <div class="mb-3">
                <label class="form-label">Campus Name <span class="text-danger">*</span></label>
                <input type="text" name="campus_name" class="form-control" placeholder="e.g., Isulan Campus" required>
              </div>

            </form>
          </div>

          <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" id="btnSaveCampus">Save</button>
          </div>

        </div>
      </div>
    </div>

    <!-- EDIT CAMPUS MODAL -->
    <div class="modal fade" id="editCampusModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">

          <div class="modal-header">
            <h5 class="modal-title">Edit Campus</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">
            <form id="editCampusForm">
                <input type="hidden" name="campus_id" id="edit_campus_id">

                <div class="mb-3">
                  <label class="form-label">Campus Code</label>
                  <input type="text" name="campus_code" id="edit_campus_code" class="form-control" required>
                </div>

                <div class="mb-3">
                  <label class="form-label">Campus Name</label>
                  <input type="text" name="campus_name" id="edit_campus_name" class="form-control" required>
                </div>

                <div class="mb-3">
                  <label class="form-label">Status</label>
                  <select name="status" id="edit_campus_status" class="form-select">
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>
            </form>
          </div>

          <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" id="btnUpdateCampus">Update</button>
          </div>

        </div>
      </div>
    </div>


    <!-- Core JS -->
    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Main JS -->
    <script src="../assets/js/main.js"></script>

    <!-- Page JS -->
    <script>

      // ------------------------
      // Load campuses initially
      // ------------------------
      loadCampuses();  
        
      // Load campuses
      function loadCampuses() {
        $.post('../backend/query_campus.php', { load_campuses: 1 }, function(data) {
          $('#campusTable tbody').html(data);
        });
      }

      // Save campus
      $("#btnSaveCampus").click(function () {
        $.post('../backend/query_campus.php', $("#addCampusForm").serialize() + "&save_campus=1", function(res) {
          Swal.fire("Success!", "Campus added successfully!", "success");
          $("#addCampusModal").modal("hide");
          loadCampuses();
        });
      });


      // ------------------------
      // OPEN EDIT MODAL
      // ------------------------
      $(document).on("click", ".btnEdit", function () {
          let id     = $(this).data("id");
          let code   = $(this).data("code");
          let name   = $(this).data("name");
          let status = $(this).data("status");

          $("#edit_campus_id").val(id);
          $("#edit_campus_code").val(code);
          $("#edit_campus_name").val(name);
          $("#edit_campus_status").val(status);

          $("#editCampusModal").modal("show");
      });


      // ------------------------
      // UPDATE CAMPUS
      // ------------------------
      $("#btnUpdateCampus").click(function () {
          $.post("../backend/query_campus.php", $("#editCampusForm").serialize() + "&update_campus=1", function (res) {
              Swal.fire("Updated!", "Campus updated successfully.", "success");
              $("#editCampusModal").modal("hide");
              loadCampuses();
          });
      });


      // ------------------------
      // DELETE CAMPUS
      // ------------------------
      $(document).on("click", ".btnDelete", function () {
          let id = $(this).data("id");

          Swal.fire({
              title: "Are you sure?",
              text: "This campus will be permanently deleted.",
              icon: "warning",
              showCancelButton: true,
              confirmButtonColor: "#d33",
              cancelButtonColor: "#aaa",
              confirmButtonText: "Delete"
          }).then((result) => {

              if (result.isConfirmed) {
                  $.post("../backend/query_campus.php", { delete_campus: 1, campus_id: id }, function (res) {
                      Swal.fire("Deleted!", "Campus removed successfully.", "success");
                      loadCampuses();
                  });
              }

          });

      });




    </script>

  </body>
</html>
