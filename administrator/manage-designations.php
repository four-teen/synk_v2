<?php 
    session_start();
    ob_start();
    include '../backend/db.php';

    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        header('Location: ../index.php');
        exit;
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

    <title>Designation List | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
      #designationTable td {
        padding-top: 0.6rem !important;
        padding-bottom: 0.6rem !important;
      }
      #designationTable th:last-child,
      #designationTable td:last-child {
        white-space: nowrap !important;
      }
      .card-datatable {
        padding-bottom: 20px !important;
      }

      .designation-mobile-list {
        display: grid;
        gap: 1rem;
      }

      .designation-mobile-card {
        border: 1px solid rgba(105, 108, 255, 0.12);
        border-radius: 1rem;
        box-shadow: 0 10px 24px rgba(67, 89, 113, 0.08);
      }

      .designation-mobile-card .card-body {
        padding: 1rem;
      }

      .designation-mobile-top {
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
      }

      .designation-mobile-index {
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

      .designation-mobile-name {
        margin: 0;
        font-size: 1rem;
        font-weight: 700;
        color: #435971;
      }

      .designation-mobile-units {
        color: #566a7f;
        font-size: 0.9rem;
      }

      .designation-mobile-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px solid rgba(67, 89, 113, 0.08);
      }

      .designation-mobile-meta-label {
        display: block;
        margin-bottom: 0.25rem;
        color: #8592a3;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
      }

      .designation-mobile-meta-value {
        color: #435971;
        font-size: 0.92rem;
        font-weight: 600;
      }

      .designation-mobile-actions {
        display: flex;
        gap: 0.75rem;
        margin-top: 1rem;
      }

      .designation-mobile-actions .btn {
        flex: 1 1 0;
      }

      .designation-empty-state {
        border: 1px dashed rgba(67, 89, 113, 0.2);
        border-radius: 1rem;
        padding: 1.25rem;
        text-align: center;
        color: #8592a3;
        background: rgba(245, 246, 255, 0.7);
      }

      @media (max-width: 767.98px) {
        .designation-mobile-top {
          flex-direction: column;
        }

        .designation-mobile-grid {
          grid-template-columns: 1fr;
        }

        .designation-mobile-actions {
          flex-direction: column;
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
                <h4 class="fw-bold"><i class="bx bx-id-card me-2"></i> Designation List</h4>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDesignationModal">
                  <i class="bx bx-plus"></i> Add Designation
                </button>
              </div>

              <!-- Card: Designation List -->
              <div class="card">
                <div class="card-header">
                  <h5 class="m-0">Designations & Units</h5>
                  <small class="text-muted">Create and maintain designation mappings with corresponding units</small>
                </div>

                <div class="card-datatable p-3">
                  <div class="designation-table-wrap d-none d-lg-block">
                    <div class="table-responsive">
                      <table class="table table-hover align-middle" id="designationTable">
                        <thead>
                          <tr>
                            <th>#</th>
                            <th>Designation</th>
                            <th>Units</th>
                            <th>Status</th>
                            <th class="text-end" style="width:120px;">Actions</th>
                          </tr>
                        </thead>
                        <tbody></tbody>
                      </table>
                    </div>
                  </div>

                  <div class="designation-mobile-list d-lg-none" id="designationMobileList"></div>
                </div>
              </div>

            </div>

            <?php include '../footer.php'; ?>

            <div class="content-backdrop fade"></div>
          </div>
          <!-- /Content wrapper -->

        </div>
        <!-- /Layout page -->

      </div>

      <div class="layout-overlay layout-menu-toggle"></div>
    </div>

    <!-- Add Designation Modal -->
    <div class="modal fade" id="addDesignationModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">

          <div class="modal-header">
            <h5 class="modal-title">Add New Designation</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">
            <form id="addDesignationForm">
              <div class="mb-3">
                <label class="form-label">Designation <span class="text-danger">*</span></label>
                <input
                  type="text"
                  name="designation_name"
                  class="form-control"
                  placeholder="e.g., Director, Dean, Program Chair"
                  required
                >
              </div>

              <div class="mb-3">
                <label class="form-label">Units <span class="text-danger">*</span></label>
                <input
                  type="number"
                  name="designation_units"
                  class="form-control"
                  step="0.25"
                  min="0"
                  placeholder="e.g., 6"
                  required
                >
                <small class="text-muted">Decimal values are accepted (e.g., 6.25).</small>
              </div>
            </form>
          </div>

          <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" id="btnSaveDesignation">Save</button>
          </div>

        </div>
      </div>
    </div>

    <!-- Edit Designation Modal -->
    <div class="modal fade" id="editDesignationModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-md modal-dialog-centered">
        <div class="modal-content">

          <div class="modal-header">
            <h5 class="modal-title">Edit Designation</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>

          <div class="modal-body">
            <form id="editDesignationForm">
              <input type="hidden" name="designation_id" id="edit_designation_id">

              <div class="mb-3">
                <label class="form-label">Designation</label>
                <input
                  type="text"
                  name="designation_name"
                  id="edit_designation_name"
                  class="form-control"
                  required
                >
              </div>

              <div class="mb-3">
                <label class="form-label">Units</label>
                <input
                  type="number"
                  name="designation_units"
                  id="edit_designation_units"
                  class="form-control"
                  step="0.25"
                  min="0"
                  required
                >
              </div>

              <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" id="edit_designation_status" class="form-select">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                </select>
              </div>
            </form>
          </div>

          <div class="modal-footer">
            <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" id="btnUpdateDesignation">Update</button>
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
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

    <script>
      function escapeHtml(value) {
        return $("<div>").text(value).html();
      }

      function isSmallScreen() {
        return window.matchMedia("(max-width: 991.98px)").matches;
      }

      function renderDesignationMobileList() {
        const $mobileList = $("#designationMobileList");
        const rows = [];

        $("#designationTable tbody tr").each(function() {
          const $row = $(this);
          const $editBtn = $row.find(".btnEditDesignation").first();
          const designation = $editBtn.data("name");
          const units = $.trim($row.find("td").eq(2).text());
          const statusHtml = $row.find("td").eq(3).html();

          rows.push({
            id: $editBtn.data("id"),
            designation,
            units,
            statusHtml,
            status: $editBtn.data("status"),
            nameAttr: designation,
            unitsAttr: $.trim($editBtn.data("units"))
          });
        });

        if (rows.length === 0) {
          $mobileList.html(
            "<div class='designation-empty-state'>No designation records found.</div>"
          );
          return;
        }

        let html = "";
        rows.forEach(function(item, index) {
          const actionButtons = buildDesignationCardActions(item);
          html += `
            <div class="card designation-mobile-card">
              <div class="card-body">
                <div class="designation-mobile-top">
                  <div>
                    <span class="designation-mobile-index">#${index + 1}</span>
                    <h6 class="designation-mobile-name mt-3">${escapeHtml(item.designation)}</h6>
                    <div class="designation-mobile-units mt-1">Unit Capacity: ${escapeHtml(item.units)}</div>
                  </div>
                  <div>${item.statusHtml || ""}</div>
                </div>
                <div class="designation-mobile-grid">
                  <div>
                    <span class="designation-mobile-meta-label">Designation</span>
                    <span class="designation-mobile-meta-value">${escapeHtml(item.designation)}</span>
                  </div>
                  <div>
                    <span class="designation-mobile-meta-label">Units</span>
                    <span class="designation-mobile-meta-value">${escapeHtml(item.units)}</span>
                  </div>
                </div>
                <div class="designation-mobile-actions">
                  ${actionButtons}
                </div>
              </div>
            </div>
          `;
        });

        $mobileList.html(html);
      }

      function buildDesignationCardActions(item) {
        return `
          <button
            class="btn btn-outline-warning btnEditDesignation"
            data-id="${escapeHtml(item.id)}"
            data-name="${escapeHtml(item.nameAttr)}"
            data-units="${escapeHtml(item.unitsAttr)}"
            data-status="${escapeHtml(item.status)}"
            type="button"
          >
            <i class="bx bx-edit-alt me-1"></i>Edit
          </button>
          <button
            class="btn btn-outline-danger btnDeleteDesignation"
            data-id="${escapeHtml(item.id)}"
            type="button"
          >
            <i class="bx bx-trash me-1"></i>Delete
          </button>
        `;
      }

      function renderDesignationsView() {
        if (isSmallScreen()) {
          if (designationTable !== null) {
            designationTable.destroy();
            designationTable = null;
          }
          renderDesignationMobileList();
          return;
        }

        $("#designationMobileList").empty();

        if (designationTable !== null) {
          return;
        }

        designationTable = $("#designationTable").DataTable({
          pageLength: 10,
          ordering: true,
          responsive: true,
          autoWidth: false
        });
      }

      loadDesignations();

      let designationTable = null;

      function loadDesignations() {
        $.post("../backend/query_designation.php", { load_designations: 1 }, function(data) {
          if (designationTable !== null) {
            designationTable.destroy();
            designationTable = null;
          }

          $("#designationTable tbody").html(data);
          renderDesignationsView();
        });
      }

      $(window).on("resize", function() {
        renderDesignationsView();
      });

      $("#btnSaveDesignation").click(function () {
        $.post(
          "../backend/query_designation.php",
          $("#addDesignationForm").serialize() + "&save_designation=1",
          function(res) {
            res = res.trim();

            if (res === "unauthorized") {
              Swal.fire("Session Expired", "Please sign in again.", "warning").then(() => {
                window.location = "../index.php";
              });
              return;
            }

            if (res === "missing") {
              Swal.fire("Missing Data", "Please fill all required fields.", "warning");
              return;
            }

            if (res === "invalid_units") {
              Swal.fire("Invalid Units", "Units must be a valid number.", "warning");
              return;
            }

            if (res === "duplicate") {
              Swal.fire("Duplicate Entry", "That designation already exists.", "warning");
              return;
            }

            if (res !== "success") {
              Swal.fire("Save Failed", "Please try again later.", "error");
              return;
            }

            Swal.fire({
              icon: "success",
              title: "Saved!",
              text: "Designation added successfully.",
              timer: 1200,
              showConfirmButton: false
            });

            $("#addDesignationForm")[0].reset();
            $("#addDesignationModal").modal("hide");
            loadDesignations();
          }
        );
      });

      $(document).on("click", ".btnEditDesignation", function () {
        $("#edit_designation_id").val($(this).data("id"));
        $("#edit_designation_name").val($(this).data("name"));
        $("#edit_designation_units").val($(this).data("units"));
        $("#edit_designation_status").val($(this).data("status"));
        $("#editDesignationModal").modal("show");
      });

      $("#btnUpdateDesignation").click(function () {
        $.post(
          "../backend/query_designation.php",
          $("#editDesignationForm").serialize() + "&update_designation=1",
          function(res) {
            res = res.trim();

            if (res === "unauthorized") {
              Swal.fire("Session Expired", "Please sign in again.", "warning").then(() => {
                window.location = "../index.php";
              });
              return;
            }

            if (res === "missing") {
              Swal.fire("Missing Data", "Please fill all required fields.", "warning");
              return;
            }

            if (res === "invalid_status") {
              Swal.fire("Invalid Status", "Invalid status selected.", "warning");
              return;
            }

            if (res === "invalid_units") {
              Swal.fire("Invalid Units", "Units must be a valid number.", "warning");
              return;
            }

            if (res === "duplicate") {
              Swal.fire("Duplicate Entry", "That designation already exists.", "warning");
              return;
            }

            if (res !== "updated") {
              Swal.fire("Update Failed", "Please try again later.", "error");
              return;
            }

            Swal.fire({
              icon: "success",
              title: "Updated!",
              text: "Designation updated successfully.",
              timer: 1200,
              showConfirmButton: false
            });

            $("#editDesignationModal").modal("hide");
            loadDesignations();
          }
        );
      });

      $(document).on("click", ".btnDeleteDesignation", function () {
        let id = $(this).data("id");

        Swal.fire({
          title: "Delete this designation?",
          text: "This designation will be permanently removed.",
          icon: "warning",
          showCancelButton: true,
          confirmButtonColor: "#d33",
          cancelButtonColor: "#aaa",
          confirmButtonText: "Delete"
        }).then((result) => {
          if (!result.isConfirmed) {
            return;
          }

          $.post(
            "../backend/query_designation.php",
            { delete_designation: 1, designation_id: id },
            function(res) {
              res = res.trim();

              if (res === "unauthorized") {
                Swal.fire("Session Expired", "Please sign in again.", "warning").then(() => {
                  window.location = "../index.php";
                });
                return;
              }

              if (res === "missing") {
                Swal.fire("Missing ID", "Could not delete without a valid designation.", "warning");
                return;
              }

              if (res !== "deleted") {
                Swal.fire("Delete Failed", "Please try again later.", "error");
                return;
              }

              Swal.fire({
                icon: "success",
                title: "Deleted!",
                text: "Designation removed successfully.",
                timer: 1100,
                showConfirmButton: false
              });

              loadDesignations();
            }
          );
        });
      });
    </script>
  </body>
</html>
