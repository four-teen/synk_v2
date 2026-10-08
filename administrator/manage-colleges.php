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

    <title>College Management | Synk</title>

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

    <style>
      #collegeTable td {
          padding-top: 0.65rem !important;
          padding-bottom: 0.65rem !important;
      }
      #collegeTable th:last-child,
      #collegeTable td:last-child {
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
                <h4 class="fw-bold"><i class="bx bx-buildings me-2"></i> College Management</h4>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCollegeModal">
                  <i class="bx bx-plus"></i> Add College
                </button>
              </div>

              <!-- Card: College List -->
              <div class="card">
                <div class="card-header">
                  <h5 class="m-0">College List</h5>
                  <small class="text-muted">All colleges under each campus</small>
                </div>

                <div class="card-datatable table-responsive p-3">
                  <table class="table table-hover align-middle" id="collegeTable">
                    <thead>
                      <tr>
                        <th>#</th>
                        <th>Campus</th>
                        <th>College Code</th>
                        <th>College Name</th>
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

    <!-- ADD COLLEGE MODAL -->
    <div class="modal fade" id="addCollegeModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">
          
          <div class="modal-header">
            <h5 class="modal-title">Add New College</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">
            <form id="addCollegeForm">

              <div class="mb-3">
                <label class="form-label">Select Campus <span class="text-danger">*</span></label>
                <select name="campus_id" class="form-select" required>
                  <option value="">Select Campus</option>
                  <?php
                    $campus = $conn->query("SELECT * FROM tbl_campus WHERE status='active'");
                      while ($c = $campus->fetch_assoc()) {
                          // Use strtoupper() on the value you want to make uppercase
                          $uppercase_name = strtoupper($c['campus_name']);
                          
                          echo "<option value='{$c['campus_id']}'>{$uppercase_name}</option>";
                      }
                  ?>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label">College Code <span class="text-danger">*</span></label>
                <input type="text" name="college_code" class="form-control" placeholder="e.g., CCS" required>
              </div>

              <div class="mb-3">
                <label class="form-label">College Name <span class="text-danger">*</span></label>
                <input type="text" name="college_name" class="form-control" placeholder="e.g., College of Computer Studies" required>
              </div>

            </form>
          </div>

          <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" id="btnSaveCollege">Save</button>
          </div>

        </div>
      </div>
    </div>

    <!-- EDIT MODAL (Auto-filled via JS) -->
    <div class="modal fade" id="editCollegeModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">

          <div class="modal-header">
            <h5 class="modal-title">Edit College</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">
            <form id="editCollegeForm">

              <input type="hidden" name="college_id" id="edit_college_id">

              <div class="mb-3">
                <label class="form-label">Campus</label>
                <select name="campus_id" id="edit_campus_id" class="form-select">
                  <?php
                    $campus = $conn->query("SELECT * FROM tbl_campus WHERE status='active'");
                    while ($c = $campus->fetch_assoc()) {
                        echo "<option value='{$c['campus_id']}'>{$c['campus_name']}</option>";
                    }
                  ?>
                </select>
              </div>

              <div class="mb-3">
                <label class="form-label">College Code</label>
                <input type="text" name="college_code" id="edit_college_code" class="form-control">
              </div>

              <div class="mb-3">
                <label class="form-label">College Name</label>
                <input type="text" name="college_name" id="edit_college_name" class="form-control">
              </div>

              <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" id="edit_college_status" class="form-select">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>

            </form>
          </div>

          <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" id="btnUpdateCollege">Update</button>
          </div>

        </div>
      </div>
    </div>

    <!-- JS -->
    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>    
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="../assets/js/dashboards-analytics.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
      loadColleges();

      function loadColleges() {
        $.post("../backend/query_college.php", { load_colleges: 1 }, function(data) {
          $("#collegeTable tbody").html(data);
        });
      }

      $("#btnSaveCollege").click(function () {
        $.post("../backend/query_college.php", $("#addCollegeForm").serialize() + "&save_college=1", function(res){
          Swal.fire("Success!", "College added successfully.", "success");
          $("#addCollegeModal").modal("hide");
          loadColleges();
        });
      });

      $(document).on("click", ".btnEdit", function(){
        $("#edit_college_id").val($(this).data("id"));
        $("#edit_campus_id").val($(this).data("campus"));
        $("#edit_college_code").val($(this).data("code"));
        $("#edit_college_name").val($(this).data("name"));
        $("#edit_college_status").val($(this).data("status"));
        $("#editCollegeModal").modal("show");
      });

      $("#btnUpdateCollege").click(function(){
        $.post("../backend/query_college.php", $("#editCollegeForm").serialize() + "&update_college=1", function(){
          Swal.fire("Updated!", "College updated successfully.", "success");
          $("#editCollegeModal").modal("hide");
          loadColleges();
        });
      });

      $(document).on("click", ".btnDelete", function(){
        let id = $(this).data("id");

        Swal.fire({
          title: "Delete this College?",
          text: "All associated programs will also be affected.",
          icon: "warning",
          showCancelButton: true,
          confirmButtonColor: "#d33",
          cancelButtonColor: "#aaa",
          confirmButtonText: "Yes, delete it"
        }).then((result)=>{
          if(result.isConfirmed){
            $.post("../backend/query_college.php", { delete_college: 1, college_id: id }, function(){
              Swal.fire("Deleted!", "College removed successfully.", "success");
              loadColleges();
            });
          }
        });
      });
    </script>

  </body>
</html>

