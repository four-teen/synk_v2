<?php
session_start();
ob_start();
include '../backend/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$role = $_SESSION['role'];
$collegeId = $_SESSION['college_id'] ?? 0;
$csrfToken = (string)$_SESSION['csrf_token'];
$isAdmin = ($role === 'admin');
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed">
<head>
    <meta charset="utf-8" />
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
<h4 class="fw-bold mb-4">
    <i class="bx bx-search-alt me-2"></i> Prospectus Browser
</h4>

<!-- FILTER -->
<div class="card mb-4">
    <div class="card-body">
        <label class="form-label">Select Program</label>
        <select id="filterProgram" class="form-select">
            <option value="">Select Program</option>

            <?php
            if ($role === 'admin') {
                /* ============================================================
                   LOAD PROGRAMS WITH AT LEAST ONE ENCODED PROSPECTUS SUBJECT
                   (ADMIN VIEW – ALL COLLEGES)
                ============================================================ */

                    $prog = $conn->query("
                        SELECT DISTINCT
                            p.program_id,
                            p.program_name,
                            p.program_code,
                            p.major,
                            ca.campus_code
                        FROM tbl_program p
                        LEFT JOIN tbl_college c
                            ON c.college_id = p.college_id
                        LEFT JOIN tbl_campus ca
                            ON ca.campus_id = c.campus_id
                        WHERE p.status = 'active'
                          AND EXISTS (
                              SELECT 1
                              FROM tbl_prospectus_header h
                              JOIN tbl_prospectus_year_sem ys
                                ON ys.prospectus_id = h.prospectus_id
                              JOIN tbl_prospectus_subjects ps
                                ON ps.pys_id = ys.pys_id
                              WHERE h.program_id = p.program_id
                          )
                        ORDER BY ca.campus_code, p.program_name, p.major
                    ");
            } else {
                /* ============================================================
                   LOAD PROGRAMS WITH AT LEAST ONE ENCODED PROSPECTUS SUBJECT
                   (COLLEGE-SCOPED VIEW)
                ============================================================ */

                $prog = $conn->query("
                    SELECT DISTINCT p.program_id, p.program_name, p.program_code, p.major
                    FROM tbl_program p
                    WHERE p.status = 'active'
                      AND p.college_id = '$collegeId'
                      AND EXISTS (
                          SELECT 1
                          FROM tbl_prospectus_header h
                          JOIN tbl_prospectus_year_sem ys
                            ON ys.prospectus_id = h.prospectus_id
                          JOIN tbl_prospectus_subjects ps
                            ON ps.pys_id = ys.pys_id
                          WHERE h.program_id = p.program_id
                      )
                    ORDER BY p.program_name, p.major
                ");
            }

            while ($p = $prog->fetch_assoc()) {
                /* ============================================================
                   BUILD PROGRAM LABEL (ADMIN – WITH CAMPUS CODE)
                ============================================================ */

                $programName = ucwords(strtolower($p['program_name']));
                $programCode = strtoupper($p['program_code']);
                $major       = trim($p['major']);
                $campus      = $p['campus_code'] ?? '?';

                if ($major !== '') {
                    $baseLabel = $programName . ' major in ' . $major . ' (' . $programCode . ')';
                } else {
                    $baseLabel = $programName . ' (' . $programCode . ')';
                }

                $finalLabel = $campus . ' – ' . $baseLabel;

                echo "<option value='{$p['program_id']}'>" . htmlspecialchars($finalLabel) . "</option>";

            }
            ?>
        </select>
    </div>
</div>

<!-- TABLE -->
<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered table-sm">
                <thead class="table-light">
                    <tr>
                        <th>CMO</th>
                        <th>Effectivity SY</th>
                        <th class="text-center">Year / Sem</th>
                        <th class="text-center">Subjects</th>
                        <th class="text-center">Total Units</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody id="prospectusTable">
                    <tr>
                        <td colspan="6" class="text-center text-muted">
                            Select a program to view prospectus
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="editCmoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Prospectus Header</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="editCmoForm">
                    <input type="hidden" name="prospectus_id" id="edit_cmo_prospectus_id">

                    <div class="mb-3">
                        <label class="form-label">Program</label>
                        <input type="text" id="edit_cmo_program_label" class="form-control" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">CMO Number</label>
                        <input type="text" name="cmo_no" id="edit_cmo_no" class="form-control" required>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Effectivity SY</label>
                        <input type="text" name="effective_sy" id="edit_effective_sy" class="form-control" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSaveCmoEdit">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="copyProspectusBrowserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Copy Prospectus To Program</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="copyProspectusBrowserForm">
                    <input type="hidden" name="source_prospectus_id" id="browser_copy_source_prospectus_id">

                    <div class="mb-3">
                        <label class="form-label">Source Prospectus</label>
                        <input type="text" id="browser_copy_source_label" class="form-control" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Target Program</label>
                        <select name="target_program_id" id="browser_copy_target_program" class="form-select" required>
                            <option value="">Select Program</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">CMO Number</label>
                        <input type="text" name="cmo_no" id="browser_copy_cmo_no" class="form-control" required>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Effectivity SY</label>
                        <input type="text" name="effective_sy" id="browser_copy_effective_sy" class="form-control" required>
                    </div>
                </form>
                <small class="text-muted d-block mt-2">
                    This copies the selected prospectus header, year/semester structure, and subjects into the target program.
                </small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnConfirmBrowserCopy">Copy Prospectus</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="transferProspectusBrowserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Transfer Prospectus To Program</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="transferProspectusBrowserForm">
                    <input type="hidden" name="prospectus_id" id="browser_transfer_prospectus_id">

                    <div class="mb-3">
                        <label class="form-label">Source Prospectus</label>
                        <input type="text" id="browser_transfer_source_label" class="form-control" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Target Program</label>
                        <select name="target_program_id" id="browser_transfer_target_program" class="form-select" required>
                            <option value="">Select Program</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">CMO Number</label>
                        <input type="text" id="browser_transfer_cmo_no" class="form-control" readonly>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Effectivity SY</label>
                        <input type="text" id="browser_transfer_effective_sy" class="form-control" readonly>
                    </div>
                </form>
                <small class="text-muted d-block mt-2">
                    This moves the existing prospectus to the selected program. It will no longer stay under the current source program.
                </small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="btnConfirmBrowserTransfer">Transfer Prospectus</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

</div>
<?php include '../footer.php'; ?>
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
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
const IS_ADMIN = <?php echo $isAdmin ? 'true' : 'false'; ?>;
let copyTargetPrograms = [];

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

function escapeHtml(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function formatUnits(value) {
    const numeric = Number(value) || 0;
    return Number.isInteger(numeric) ? String(numeric) : numeric.toFixed(2).replace(/\.?0+$/, "");
}

function buildProgramLabel(program) {
    let label = `${program.campus_code || "N/A"} - ${program.program_name}`;

    if (program.major && String(program.major).trim() !== "") {
        label += ` major in ${program.major}`;
    }

    label += ` (${program.program_code})`;
    return label;
}

function getSelectedProgramLabel() {
    return $("#filterProgram option:selected").text() || "Selected Program";
}

function renderEmptyState(message) {
    $("#prospectusTable").html(`
        <tr>
            <td colspan="6" class="text-center text-muted">
                ${escapeHtml(message)}
            </td>
        </tr>
    `);
}

function buildActionButtons(row) {
    const buttons = [
        `<a href="view-prospectus.php?pid=${Number(row.prospectus_id) || 0}" class="btn btn-sm btn-primary">Open</a>`
    ];

    if (IS_ADMIN) {
        buttons.push(
            `<button type="button"
                     class="btn btn-sm btn-outline-secondary btnEditBrowserCmo"
                     data-prospectus-id="${Number(row.prospectus_id) || 0}"
                     data-program-id="${Number($("#filterProgram").val()) || 0}"
                     data-program-label="${escapeHtml(getSelectedProgramLabel())}"
                     data-cmo="${escapeHtml(row.cmo_no || "")}"
                     data-effective-sy="${escapeHtml(row.effective_sy || "")}">
                Edit CMO
             </button>`
        );
        buttons.push(
            `<button type="button"
                     class="btn btn-sm btn-outline-primary btnCopyBrowserProspectus"
                     data-prospectus-id="${Number(row.prospectus_id) || 0}"
                     data-program-id="${Number($("#filterProgram").val()) || 0}"
                     data-program-label="${escapeHtml(getSelectedProgramLabel())}"
                     data-cmo="${escapeHtml(row.cmo_no || "")}"
                     data-effective-sy="${escapeHtml(row.effective_sy || "")}">
                Copy
             </button>`
        );
        buttons.push(
            `<button type="button"
                     class="btn btn-sm btn-outline-danger btnTransferBrowserProspectus"
                     data-prospectus-id="${Number(row.prospectus_id) || 0}"
                     data-program-id="${Number($("#filterProgram").val()) || 0}"
                     data-program-label="${escapeHtml(getSelectedProgramLabel())}"
                     data-cmo="${escapeHtml(row.cmo_no || "")}"
                     data-effective-sy="${escapeHtml(row.effective_sy || "")}">
                Transfer
             </button>`
        );
        buttons.push(
            `<button type="button"
                     class="btn btn-sm btn-danger btnDeleteBrowserProspectus"
                     data-prospectus-id="${Number(row.prospectus_id) || 0}"
                     data-program-label="${escapeHtml(getSelectedProgramLabel())}"
                     data-cmo="${escapeHtml(row.cmo_no || "")}"
                     data-effective-sy="${escapeHtml(row.effective_sy || "")}">
                Delete
             </button>`
        );
    }

    return `<div class="d-flex justify-content-center gap-1 flex-wrap">${buttons.join("")}</div>`;
}

function renderProspectusRows(rows) {
    const tbody = $("#prospectusTable");
    tbody.empty();

    if (!Array.isArray(rows) || rows.length === 0) {
        renderEmptyState("No prospectus found for this program");
        return;
    }

    rows.forEach(function (row) {
        tbody.append(`
            <tr>
                <td>${escapeHtml(row.cmo_no || "")}</td>
                <td>${escapeHtml(row.effective_sy || "")}</td>
                <td class="text-center">${escapeHtml(row.yearsem_count || 0)}</td>
                <td class="text-center">${escapeHtml(row.subject_count || 0)}</td>
                <td class="text-center">${escapeHtml(formatUnits(row.total_units))}</td>
                <td class="text-center">${buildActionButtons(row)}</td>
            </tr>
        `);
    });
}

function loadProspectusByProgram(programId) {
    if (!programId) {
        renderEmptyState("Select a program to view prospectus");
        return;
    }

    $.post(
        "../backend/query_prospectus_browser.php",
        { load_prospectus_by_program: 1, program_id: programId },
        function (res) {
            renderProspectusRows(res);
        },
        "json"
    ).fail(function () {
        renderEmptyState("Failed to load prospectus records.");
    });
}

function ensureFilterProgramOption(programId) {
    if (!programId || $("#filterProgram option[value='" + programId + "']").length > 0) {
        return;
    }

    const match = copyTargetPrograms.find(function (program) {
        return String(program.program_id) === String(programId);
    });

    if (!match) {
        return;
    }

    $("#filterProgram").append(
        `<option value="${escapeHtml(match.program_id)}">${escapeHtml(match.browser_label)}</option>`
    );
}

function populateTargetProgramSelect(selector, programs, dropdownParentSelector) {
    const select = $(selector);
    select.empty().append('<option value="">Select Program</option>');

    programs.forEach(function (program) {
        select.append(
            `<option value="${escapeHtml(program.program_id)}">${escapeHtml(program.browser_label)}</option>`
        );
    });

    if (select.hasClass("select2-hidden-accessible")) {
        select.select2("destroy");
    }

    select.select2({
        width: "100%",
        allowClear: true,
        placeholder: "Select Program",
        dropdownParent: $(dropdownParentSelector)
    });
}

function loadCopyTargetPrograms() {
    return $.post(
        "../backend/query_prospectus_copy.php",
        { load_programs: 1 },
        function (res) {
            copyTargetPrograms = (Array.isArray(res) ? res : []).map(function (program) {
                program.browser_label = buildProgramLabel(program);
                return program;
            });
        },
        "json"
    );
}

$("#filterProgram").select2({ width: "100%" });

$("#filterProgram").on("change", function () {
    loadProspectusByProgram($(this).val());
});

$(document).on("click", ".btnEditBrowserCmo", function () {
    const button = $(this);

    $("#edit_cmo_prospectus_id").val(button.data("prospectusId"));
    $("#edit_cmo_program_label").val(button.data("programLabel") || getSelectedProgramLabel());
    $("#edit_cmo_no").val(button.data("cmo") || "");
    $("#edit_effective_sy").val(button.data("effectiveSy") || "");

    $("#editCmoModal").modal("show");
});

$(document).on("click", ".btnCopyBrowserProspectus", function () {
    const button = $(this);
    const sourceLabel = `${button.data("programLabel") || getSelectedProgramLabel()} - ${button.data("cmo") || ""} - ${button.data("effectiveSy") || ""}`;

    $("#browser_copy_source_prospectus_id").val(button.data("prospectusId"));
    $("#browser_copy_source_label").val(sourceLabel);
    $("#browser_copy_cmo_no").val(button.data("cmo") || "");
    $("#browser_copy_effective_sy").val(button.data("effectiveSy") || "");

    loadCopyTargetPrograms()
        .done(function () {
            populateTargetProgramSelect("#browser_copy_target_program", copyTargetPrograms, "#copyProspectusBrowserModal");
            $("#browser_copy_target_program").val("").trigger("change");
            $("#copyProspectusBrowserModal").modal("show");
        })
        .fail(function () {
            Swal.fire("Error", "Failed to load target programs.", "error");
        });
});

$(document).on("click", ".btnTransferBrowserProspectus", function () {
    const button = $(this);
    const sourceProgramId = String(button.data("programId") || "");
    const sourceLabel = `${button.data("programLabel") || getSelectedProgramLabel()} - ${button.data("cmo") || ""} - ${button.data("effectiveSy") || ""}`;

    $("#browser_transfer_prospectus_id").val(button.data("prospectusId"));
    $("#browser_transfer_source_label").val(sourceLabel);
    $("#browser_transfer_cmo_no").val(button.data("cmo") || "");
    $("#browser_transfer_effective_sy").val(button.data("effectiveSy") || "");

    loadCopyTargetPrograms()
        .done(function () {
            const transferTargets = copyTargetPrograms.filter(function (program) {
                return String(program.program_id) !== sourceProgramId;
            });

            if (transferTargets.length === 0) {
                Swal.fire("No Target Program", "No other active program is available for transfer.", "info");
                return;
            }

            populateTargetProgramSelect("#browser_transfer_target_program", transferTargets, "#transferProspectusBrowserModal");
            $("#browser_transfer_target_program").val("").trigger("change");
            $("#transferProspectusBrowserModal").modal("show");
        })
        .fail(function () {
            Swal.fire("Error", "Failed to load target programs.", "error");
        });
});

$(document).on("click", ".btnDeleteBrowserProspectus", function () {
    const button = $(this);
    const prospectusId = Number(button.data("prospectusId")) || 0;
    const cmo = String(button.data("cmo") || "").trim();
    const effectiveSy = String(button.data("effectiveSy") || "").trim();
    const programLabel = String(button.data("programLabel") || getSelectedProgramLabel()).trim();
    const summaryLabel = [cmo, effectiveSy].filter(Boolean).join(" • ");

    if (!prospectusId) {
        Swal.fire("Error", "Invalid prospectus reference.", "error");
        return;
    }

    Swal.fire({
        icon: "warning",
        title: "Delete Prospectus?",
        html: `
            <div class="text-start">
                <div><strong>Program:</strong> ${escapeHtml(programLabel || "Selected Program")}</div>
                <div><strong>CMO:</strong> ${escapeHtml(summaryLabel || "Selected prospectus")}</div>
                <div class="mt-2 text-danger">This permanently removes the CMO, its year/semester groups, subjects, generated offerings, schedules, and related workload records.</div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonColor: "#dc3545",
        confirmButtonText: "Delete CMO",
        cancelButtonText: "Cancel",
        focusCancel: true
    }).then(function (result) {
        if (!result.isConfirmed) {
            return;
        }

        $.post("../backend/query_prospectus_browser.php", {
            delete_prospectus: 1,
            prospectus_id: prospectusId
        }, function (res) {
            const parts = String(res || "").split("|");

            if (parts[0] !== "OK") {
                Swal.fire("Error", parts[1] || "Failed to delete prospectus.", "error");
                return;
            }

            loadProspectusByProgram($("#filterProgram").val());

            Swal.fire({
                icon: "success",
                title: "Prospectus Deleted",
                text: parts[1] || "Prospectus removed successfully.",
                timer: 1400,
                showConfirmButton: false
            });
        }).fail(function () {
            Swal.fire("Error", "Failed to delete prospectus.", "error");
        });
    });
});

$("#btnSaveCmoEdit").on("click", function () {
    const form = $("#editCmoForm")[0];
    if (form && !form.reportValidity()) {
        return;
    }

    const formData = $("#editCmoForm").serialize() + "&update_prospectus_header=1";

    $.post("../backend/query_prospectus_browser.php", formData, function (res) {
        const parts = String(res || "").split("|");

        if (parts[0] !== "OK") {
            Swal.fire("Error", parts[1] || "Failed to update prospectus header.", "error");
            return;
        }

        $("#editCmoModal").modal("hide");
        loadProspectusByProgram($("#filterProgram").val());

        Swal.fire({
            icon: "success",
            title: "Updated",
            text: parts[1] || "Prospectus header updated successfully.",
            timer: 1200,
            showConfirmButton: false
        });
    }).fail(function () {
        Swal.fire("Error", "Failed to update prospectus header.", "error");
    });
});

$("#btnConfirmBrowserCopy").on("click", function () {
    const form = $("#copyProspectusBrowserForm")[0];
    if (form && !form.reportValidity()) {
        return;
    }

    const targetProgramId = $("#browser_copy_target_program").val();
    const formData = $("#copyProspectusBrowserForm").serialize() + "&copy_prospectus=1";

    $.post("../backend/query_prospectus_copy.php", formData, function (res) {
        const parts = String(res || "").split("|");

        if (parts[0] !== "OK") {
            Swal.fire("Error", parts[1] || "Failed to copy prospectus.", "error");
            return;
        }

        ensureFilterProgramOption(targetProgramId);
        $("#copyProspectusBrowserModal").modal("hide");
        $("#filterProgram").val(String(targetProgramId)).trigger("change");

        Swal.fire({
            icon: "success",
            title: "Prospectus Copied",
            text: parts[2] || "Prospectus copied successfully.",
            timer: 1300,
            showConfirmButton: false
        });
    }).fail(function () {
        Swal.fire("Error", "Failed to copy prospectus.", "error");
    });
});

$("#btnConfirmBrowserTransfer").on("click", function () {
    const form = $("#transferProspectusBrowserForm")[0];
    if (form && !form.reportValidity()) {
        return;
    }

    const targetProgramId = $("#browser_transfer_target_program").val();
    const formData = $("#transferProspectusBrowserForm").serialize() + "&transfer_prospectus=1";

    $.post("../backend/query_prospectus_browser.php", formData, function (res) {
        const parts = String(res || "").split("|");

        if (parts[0] !== "OK") {
            Swal.fire("Error", parts[1] || "Failed to transfer prospectus.", "error");
            return;
        }

        ensureFilterProgramOption(targetProgramId);
        $("#transferProspectusBrowserModal").modal("hide");
        $("#filterProgram").val(String(targetProgramId)).trigger("change");

        Swal.fire({
            icon: "success",
            title: "Prospectus Transferred",
            text: parts[1] || "Prospectus moved successfully.",
            timer: 1400,
            showConfirmButton: false
        });
    }).fail(function () {
        Swal.fire("Error", "Failed to transfer prospectus.", "error");
    });
});
</script>

</body>
</html>
