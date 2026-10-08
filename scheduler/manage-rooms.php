<?php
session_start();
include '../backend/db.php';
require_once '../backend/academic_term_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'scheduler') {
    header("Location: ../index.php");
    exit;
}

$college_id = (int)($_SESSION['college_id'] ?? 0);
$college_name = $_SESSION['college_name'] ?? '';
$campus_id = 0;

$campusQ = $conn->prepare("SELECT campus_id FROM tbl_college WHERE college_id = ? LIMIT 1");
$campusQ->bind_param("i", $college_id);
$campusQ->execute();
$campusRow = $campusQ->get_result()->fetch_assoc();
if ($campusRow) {
    $campus_id = (int)$campusRow['campus_id'];
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
$currentTerm = synk_fetch_current_academic_term($conn);
$default_ay_id = (int)$currentTerm['ay_id'];
$default_semester = (int)$currentTerm['semester'];

$ayOptions = [];
$ayQ = $conn->query("SELECT ay_id, ay FROM tbl_academic_years ORDER BY ay DESC");
while ($ay = $ayQ->fetch_assoc()) {
    $ayOptions[] = $ay;
}

$collegeOptions = [];
$cQ = $conn->prepare("SELECT college_id, college_name FROM tbl_college WHERE status='active' AND campus_id = ? ORDER BY college_name");
$cQ->bind_param("i", $campus_id);
$cQ->execute();
$cRes = $cQ->get_result();
while ($c = $cRes->fetch_assoc()) {
    $collegeOptions[] = $c;
}
?>

<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed">
<head>
    <meta charset="utf-8"/>
    <title>Room Management | Synk Scheduler</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />

    <link rel="stylesheet" type="text/css" href="custom_css.css">
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
        #roomTable td {
            padding-top: 0.5rem !important;
            padding-bottom: 0.5rem !important;
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

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="fw-bold">
            <i class="bx bx-building-house me-2"></i>
            Room Management <small class="text-muted">(<?= htmlspecialchars($college_name) ?>)</small>
        </h4>

        <div class="d-flex gap-2">
            <button class="btn btn-outline-primary" id="btnOpenCopyRooms">
                <i class="bx bx-copy-alt"></i> Copy Previous Term
            </button>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRoomModal" id="btnOpenAddRoom">
                <i class="bx bx-plus"></i> Add Room
            </button>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h5 class="m-0">Academic Context</h5>
            <small class="text-muted">Room access and sharing are controlled by Academic Year + Semester.</small>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Academic Year</label>
                    <select id="ay_id" class="form-select">
                        <option value="">Select...</option>
                        <?php foreach ($ayOptions as $ay): ?>
                            <option value="<?= (int)$ay['ay_id'] ?>"<?= (int)$ay['ay_id'] === $default_ay_id ? ' selected' : '' ?>><?= htmlspecialchars($ay['ay']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Semester</label>
                    <select id="semester" class="form-select">
                        <option value="">Select...</option>
                        <option value="1"<?= $default_semester === 1 ? ' selected' : '' ?>>First Semester</option>
                        <option value="2"<?= $default_semester === 2 ? ' selected' : '' ?>>Second Semester</option>
                        <option value="3"<?= $default_semester === 3 ? ' selected' : '' ?>>Midyear</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h5 class="m-0">Room List</h5>
            <small class="text-muted">Rooms accessible to <?= htmlspecialchars($college_name) ?> in selected AY + Semester.</small>
        </div>

        <div class="table-responsive p-3">
            <table class="table table-hover" id="roomTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Room Code</th>
                        <th>Room Name</th>
                        <th>Owner Code</th>
                        <th>Access</th>
                        <th>Shared With</th>
                        <th>Type</th>
                        <th>Capacity</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

</div>
<?php include '../footer.php'; ?>
</div>
</div>

</div>

<div class="modal fade" id="copyRoomsModal">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Copy Rooms From Previous Term</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <div class="alert alert-info mb-3">
          This copies your owned rooms into the selected target term. Sharing will start blank so you can set fresh sharing for the new Academic Year and Semester, and you can remove any copied room that does not apply.
        </div>

        <div class="mb-3">
          <label class="form-label">Target Term</label>
          <div class="form-control bg-light" id="copy_target_term_text">Select Academic Year and Semester first.</div>
        </div>

        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Source Academic Year</label>
            <select id="copy_source_ay_id" class="form-select">
              <option value="">Select...</option>
              <?php foreach ($ayOptions as $ay): ?>
                <option value="<?= (int)$ay['ay_id'] ?>"><?= htmlspecialchars($ay['ay']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Source Semester</label>
            <select id="copy_source_semester" class="form-select">
              <option value="">Select...</option>
              <option value="1">First Semester</option>
              <option value="2">Second Semester</option>
              <option value="3">Midyear</option>
            </select>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="btnCopyRooms">Copy Rooms</button>
      </div>

    </div>
  </div>
</div>

<div class="modal fade" id="addRoomModal">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Add Room</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <form id="addRoomForm">
          <div class="mb-3">
            <label class="form-label">Room Code <span class="text-danger">*</span></label>
            <input type="text" name="room_code" class="form-control" required placeholder="e.g., CCS-101">
          </div>

          <div class="mb-3">
            <label class="form-label">Room Name</label>
            <input type="text" name="room_name" class="form-control" placeholder="e.g., Computer Laboratory 1">
          </div>

          <div class="mb-3">
            <label class="form-label">Room Type</label>
            <select name="room_type" class="form-select" required>
                <option value="lecture">Lecture Room</option>
                <option value="laboratory">Laboratory Room</option>
                <option value="lec_lab">Lecture-Laboratory Room</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Capacity</label>
            <input type="number" name="capacity" class="form-control" value="0">
          </div>

          <div class="mb-3">
            <label class="form-label">Shared With Colleges (this term)</label>
            <select name="shared_colleges[]" id="add_shared_colleges" class="form-select" multiple>
              <?php foreach ($collegeOptions as $c): ?>
                <?php if ((int)$c['college_id'] === $college_id) continue; ?>
                <option value="<?= (int)$c['college_id'] ?>"><?= htmlspecialchars($c['college_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel/Close</button>
        <button class="btn btn-primary" id="btnSaveRoom">Save</button>
      </div>

    </div>
  </div>
</div>

<div class="modal fade" id="editRoomModal">
  <div class="modal-dialog modal-md modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Edit Room</h5>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        <form id="editRoomForm">
          <input type="hidden" id="edit_room_id" name="room_id">

          <div class="mb-3">
            <label class="form-label">Room Code <span class="text-danger">*</span></label>
            <input type="text" id="edit_room_code" name="room_code" class="form-control" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Room Name</label>
            <input type="text" id="edit_room_name" name="room_name" class="form-control">
          </div>

          <div class="mb-3">
            <label class="form-label">Room Type</label>
            <select id="edit_room_type" name="room_type" class="form-select" required>
                <option value="lecture">Lecture Room</option>
                <option value="laboratory">Laboratory Room</option>
                <option value="lec_lab">Lecture-Laboratory Room</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Capacity</label>
            <input type="number" id="edit_room_capacity" name="capacity" class="form-control">
          </div>

          <div class="mb-3">
            <label class="form-label">Shared With Colleges (this term)</label>
            <select name="shared_colleges[]" id="edit_shared_colleges" class="form-select" multiple>
              <?php foreach ($collegeOptions as $c): ?>
                <?php if ((int)$c['college_id'] === $college_id) continue; ?>
                <option value="<?= (int)$c['college_id'] ?>"><?= htmlspecialchars($c['college_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>
      </div>

      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" id="btnUpdateRoom">Update Room</button>
      </div>

    </div>
  </div>
</div>

<script src="../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../assets/vendor/libs/popper/popper.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="../assets/vendor/js/menu.js"></script>
<script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>
<script src="../assets/js/main.js"></script>
<script src="../assets/js/dashboards-analytics.js"></script>

<script>
let roomTable;
const CSRF_TOKEN = <?= json_encode($csrf_token) ?>;

function getTermOrWarn() {
    const ay_id = $("#ay_id").val();
    const semester = $("#semester").val();
    if (!ay_id || !semester) {
        Swal.fire("Missing Filters", "Select Academic Year and Semester first.", "warning");
        return null;
    }
    return { ay_id, semester };
}

function getSemesterLabel(semester) {
    switch (String(semester || '')) {
        case '1':
            return 'First Semester';
        case '2':
            return 'Second Semester';
        case '3':
            return 'Midyear';
        default:
            return 'Select Semester';
    }
}

function getTargetTermText() {
    const ayText = $("#ay_id option:selected").text() || 'Select Academic Year';
    return `${ayText} - ${getSemesterLabel($("#semester").val())}`;
}

function getSuggestedPreviousAyId() {
    const currentAyId = $("#ay_id").val();
    let matchedCurrent = false;
    let suggestedAyId = '';

    $("#copy_source_ay_id option").each(function () {
        const value = $(this).val();
        if (!value) return;

        if (matchedCurrent) {
            suggestedAyId = value;
            return false;
        }

        if (value === currentAyId) {
            matchedCurrent = true;
        }
    });

    return suggestedAyId;
}

function seedCopyRoomsModal() {
    $("#copy_target_term_text").text(getTargetTermText());
    $("#copy_source_semester").val($("#semester").val() || '');
    $("#copy_source_ay_id").val(getSuggestedPreviousAyId());
}

$(document).ready(function () {
    $('#add_shared_colleges').select2({
        width: '100%',
        placeholder: "Select colleges...",
        dropdownParent: $('#addRoomModal')
    });

    $('#edit_shared_colleges').select2({
        width: '100%',
        placeholder: "Select colleges...",
        dropdownParent: $('#editRoomModal')
    });

    roomTable = $('#roomTable').DataTable({
        paging: true,
        searching: true,
        ordering: true,
        lengthChange: true,
        pageLength: 10,
        responsive: true,
        columnDefs: [
            { orderable: false, targets: -1 },
            { className: "text-end", targets: -1 }
        ]
    });

    $("#ay_id, #semester").on("change", function () {
        if ($("#ay_id").val() && $("#semester").val()) {
            loadRooms();
        } else {
            roomTable.clear().draw();
        }
    });

    $("#btnOpenAddRoom").on("click", function () {
        const term = getTermOrWarn();
        if (!term) {
            $("#addRoomModal").modal("hide");
            return;
        }
    });

    $("#btnOpenCopyRooms").on("click", function () {
        const term = getTermOrWarn();
        if (!term) return;

        seedCopyRoomsModal();
        $("#copyRoomsModal").modal("show");
    });

    if ($("#ay_id").val() && $("#semester").val()) {
        loadRooms();
    }
});

function loadRooms() {
    const term = getTermOrWarn();
    if (!term) return;

    $.post('../backend/query_rooms.php', {
        load_rooms: 1,
        ay_id: term.ay_id,
        semester: term.semester,
        csrf_token: CSRF_TOKEN
    }, function (data) {
        const raw = (data || '').toString().trim();

        if (raw === 'schema_missing') {
            roomTable.clear().draw();
            Swal.fire('Setup Required', 'Run backend/sql/phase3_room_term_sharing.sql first.', 'warning');
            return;
        }

        if (raw === 'missing_term') {
            roomTable.clear().draw();
            return;
        }

        roomTable.clear();
        if (raw !== '') {
            const $tmp = $('<tbody>').html(raw);
            const $validRows = $tmp.find('tr').filter(function () {
                return $(this).children('td,th').length === 9;
            });
            if ($validRows.length > 0) {
                roomTable.rows.add($validRows);
            }
        }
        roomTable.draw();
    });
}

$("#btnSaveRoom").click(function () {
    const term = getTermOrWarn();
    if (!term) return;

    $.post('../backend/query_rooms.php',
        $("#addRoomForm").serialize() +
        `&save_room=1&ay_id=${encodeURIComponent(term.ay_id)}&semester=${encodeURIComponent(term.semester)}&csrf_token=${encodeURIComponent(CSRF_TOKEN)}`,
        function(res) {
            if (res === "duplicate") {
                Swal.fire({
                    icon: "warning",
                    title: "Duplicate!",
                    text: "Room code already exists.",
                    target: '#addRoomModal',
                    backdrop: false
                });
                return;
            }

            if (res === "success") {
                Swal.fire({
                    icon: "success",
                    title: "Success",
                    text: "Room added successfully",
                    timer: 1200,
                    showConfirmButton: false,
                    target: '#addRoomModal',
                    backdrop: false
                });

                $("#addRoomForm")[0].reset();
                $("#add_shared_colleges").val(null).trigger("change");
                loadRooms();
            }
        }
    );
});

$("#btnCopyRooms").click(function () {
    const targetTerm = getTermOrWarn();
    if (!targetTerm) return;

    const sourceAyId = $("#copy_source_ay_id").val();
    const sourceSemester = $("#copy_source_semester").val();

    if (!sourceAyId || !sourceSemester) {
        Swal.fire({
            icon: "warning",
            title: "Missing Source Term",
            text: "Select the source Academic Year and Semester to copy from.",
            target: '#copyRoomsModal',
            backdrop: false
        });
        return;
    }

    $.ajax({
        url: '../backend/query_rooms.php',
        type: 'POST',
        dataType: 'json',
        data: {
            copy_rooms_from_term: 1,
            source_ay_id: sourceAyId,
            source_semester: sourceSemester,
            ay_id: targetTerm.ay_id,
            semester: targetTerm.semester,
            csrf_token: CSRF_TOKEN
        },
        success: function (res) {
            if (!res || !res.status) {
                Swal.fire("Error", "Unable to copy rooms for the selected term.", "error");
                return;
            }

            if (res.status === 'same_term') {
                Swal.fire("Invalid Source", res.message || "Choose a different source term.", "warning");
                return;
            }

            if (res.status === 'invalid_term') {
                Swal.fire("Missing Term", res.message || "Select both source and target terms.", "warning");
                return;
            }

            if (res.status === 'source_empty') {
                Swal.fire("No Rooms Found", res.message || "No rooms were found in the selected source term.", "info");
                return;
            }

            if (res.status === 'schema_missing') {
                Swal.fire("Setup Required", res.message || "Run the room term-sharing setup first.", "warning");
                return;
            }

            if (res.status === 'success') {
                const copiedCount = Number(res.copied_count || 0);
                const skippedCount = Number(res.skipped_count || 0);
                let summary = copiedCount === 1
                    ? '1 room was copied into the selected term.'
                    : `${copiedCount} rooms were copied into the selected term.`;

                if (copiedCount === 0) {
                    summary = 'No new rooms were copied because they already exist in the selected term.';
                }

                if (skippedCount > 0) {
                    summary += ` ${skippedCount} room(s) were skipped because they already exist in the target term.`;
                }

                summary += ' Sharing remains blank for this term.';

                Swal.fire({
                    icon: copiedCount > 0 ? "success" : "info",
                    title: copiedCount > 0 ? "Rooms Copied" : "Nothing New To Copy",
                    text: summary
                });

                $("#copyRoomsModal").modal("hide");
                loadRooms();
                return;
            }

            Swal.fire("Error", res.message || "Unable to copy rooms for the selected term.", "error");
        },
        error: function () {
            Swal.fire("Server Error", "Unable to connect to the server.", "error");
        }
    });
});

$(document).on("click", ".btnEdit", function () {
    $("#edit_room_id").val($(this).data("id"));
    $("#edit_room_code").val($(this).data("code"));
    $("#edit_room_name").val($(this).data("name"));
    $("#edit_room_type").val($(this).data("type"));
    $("#edit_room_capacity").val($(this).data("capacity"));

    const sharedRaw = ($(this).data("shared-colleges") || "").toString();
    const sharedArr = sharedRaw ? sharedRaw.split(",") : [];
    $("#edit_shared_colleges").val(sharedArr).trigger("change");

    $("#editRoomModal").modal("show");
});

$("#btnUpdateRoom").click(function () {
    const term = getTermOrWarn();
    if (!term) return;

    $.post('../backend/query_rooms.php',
        $("#editRoomForm").serialize() +
        `&update_room=1&ay_id=${encodeURIComponent(term.ay_id)}&semester=${encodeURIComponent(term.semester)}&csrf_token=${encodeURIComponent(CSRF_TOKEN)}`,
        function(res) {
            if (res === "duplicate") {
                Swal.fire("Duplicate!", "Room code already exists.", "warning");
                return;
            }

            if (res === "success") {
                Swal.fire({
                    icon: "success",
                    title: "Updated!",
                    text: "Room updated successfully."
                });
                $("#editRoomModal").modal("hide");
                loadRooms();
            }
        }
    );
});

$(document).on("click", ".btnDelete", function () {
    const term = getTermOrWarn();
    if (!term) return;

    let id = $(this).data("id");

    Swal.fire({
        title: "Are you sure?",
        text: "Room access for the selected AY and Semester will be removed.",
        icon: "warning",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        cancelButtonColor: "#aaa",
        confirmButtonText: "Delete",
        target: document.body
    }).then((result) => {
        if (result.isConfirmed) {
            $.post('../backend/query_rooms.php',
                {
                    delete_room: 1,
                    room_id: id,
                    ay_id: term.ay_id,
                    semester: term.semester,
                    csrf_token: CSRF_TOKEN
                },
                function(res) {
                    if (res === "success") {
                        Swal.fire("Removed!", "Room access removed for selected term.", "success");
                        loadRooms();
                    }
                }
            );
        }
    });
});
</script>

</body>
</html>
