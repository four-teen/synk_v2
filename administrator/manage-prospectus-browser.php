<?php
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
ob_start();
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/program_chair_curriculum_helper.php';
$collegeDeanPortalContext = synk_chair_curriculum_prepare_view($conn);
$curriculumIsChair = $collegeDeanPortalContext!==null;

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
$canManageCurriculum = $isAdmin || $curriculumIsChair;
require_once __DIR__ . '/../backend/prospectus_browser_helper.php';
try {
    $browserFilters = synk_prospectus_browser_filters($conn, $role, (int)$collegeId);
} catch (DomainException $exception) {
    http_response_code(403);
    exit(htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
}
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Prospectus Browser | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet"
      href="../program-chair/vendor/select2/select2.min.css" />

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
    .chair-prospectus-table .prospectus-program-details {
        display: flex;
        flex-direction: column;
        gap: 1px;
        line-height: 1.15;
        white-space: nowrap;
    }
    .chair-prospectus-table .prospectus-cmo,
    .chair-prospectus-table .prospectus-effectivity {
        margin: 0;
        font-size: 0.8125rem;
    }
    .chair-prospectus-table .prospectus-cmo {
        text-transform: lowercase;
        font-style: italic;
    }
    .chair-prospectus-table > tbody > tr > td {
        padding-top: 0.35rem;
        padding-bottom: 0.35rem;
        vertical-align: middle;
    }
</style>    
</head>

<body>
<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">

<?php include $curriculumIsChair ? __DIR__ . '/../program-chair/sidebar.php' : __DIR__ . '/sidebar.php'; ?>

<div class="layout-page">
<?php include $curriculumIsChair ? __DIR__ . '/../program-chair/navbar.php' : __DIR__ . '/navbar.php'; ?>

<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

<!-- TITLE -->
<h4 class="fw-bold mb-4">
    <i class="bx bx-search-alt me-2"></i> Prospectus Browser
</h4>
<?php if ($curriculumIsChair): ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <p class="mb-0 text-muted">View and manage curriculum for <?= htmlspecialchars((string)($_SESSION['college_name'] ?? 'your assigned college'),ENT_QUOTES,'UTF-8') ?>.</p>
    <a href="manage-prospectus.php" class="btn btn-primary btn-sm"><i class="bx bx-plus"></i> Create Curriculum</a>
</div>
<?php endif; ?>

<!-- FILTER -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <?php if ($isAdmin): ?>
            <div class="col-md-4">
                <label for="filterCampus" class="form-label">Campus</label>
                <select id="filterCampus" class="form-select"><option value="">Select Campus</option></select>
            </div>
            <div class="col-md-8">
                <label for="filterCollege" class="form-label">College</label>
                <select id="filterCollege" class="form-select" disabled><option value="">Select a campus first</option></select>
            </div>
            <?php endif; ?>
            <div class="col-12">
                <label for="filterProgram" class="form-label">Program <span class="text-muted text-lowercase">(optional)</span></label>
                <select id="filterProgram" class="form-select" disabled><option value="">All Programs</option></select>
            </div>
        </div>
    </div>
</div>

<!-- TABLE -->
<div class="card">
    <div class="card-body">
        <p id="browserResultsSummary" class="text-muted small mb-3" aria-live="polite"></p>
        <div class="table-responsive">
            <table class="table table-bordered table-sm<?= $curriculumIsChair ? ' chair-prospectus-table' : '' ?>">
                <thead class="table-light">
                    <tr>
                        <th>Program / Major</th>
                        <?php if (!$curriculumIsChair): ?>
                        <th>CMO</th>
                        <th>Effectivity SY</th>
                        <?php endif; ?>
                        <th class="text-center">Year / Sem</th>
                        <th class="text-center">Subjects</th>
                        <th class="text-center">Total Units</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody id="prospectusTable">
                    <tr>
                        <td colspan="<?= $curriculumIsChair ? 5 : 7 ?>" class="text-center text-muted">
                            <?= $isAdmin ? 'Select a campus and college to view prospectuses' : 'Loading college prospectuses...' ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($canManageCurriculum): ?>
<div class="modal fade" id="editCmoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= $curriculumIsChair ? 'Edit Prospectus Details' : 'Edit Prospectus Header' ?></h5>
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

<?php if (!$curriculumIsChair): ?>
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

<?php endif; ?>
<div class="modal fade" id="transferProspectusBrowserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= $curriculumIsChair ? 'Move Prospectus to Another Program' : 'Transfer Prospectus To Program' ?></h5>
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
                        <label class="form-label"><?= $curriculumIsChair ? 'Destination Program' : 'Target Program' ?></label>
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
                <button type="button" class="btn btn-danger" id="btnConfirmBrowserTransfer"><?= $curriculumIsChair ? 'Move Prospectus' : 'Transfer Prospectus' ?></button>
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
    <script src="../assets/js/sweetalert2.all.min.js"></script>
    <script src="../program-chair/vendor/select2/select2.min.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>
    <script src="../assets/js/main.js"></script>

<script>
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
const CAN_MANAGE_CURRICULUM = <?php echo $canManageCurriculum ? 'true' : 'false'; ?>;
const BROWSER_IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const BROWSER_IS_CHAIR = <?= $curriculumIsChair ? 'true' : 'false' ?>;
const PROSPECTUS_TABLE_COLUMNS = BROWSER_IS_CHAIR ? 5 : 7;
let browserFilters = <?= json_encode($browserFilters, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let copyTargetPrograms = [];
let browserRequest = null;
let browserRequestVersion = 0;

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
    return $("#filterProgram option:selected").attr("data-program-label") || "Selected Program";
}

function currentBrowserSelection() {
    if (BROWSER_IS_ADMIN) {
        return { campus_id: $("#filterCampus").val() || "", college_id: $("#filterCollege").val() || "", program_id: $("#filterProgram").val() || "" };
    }
    const college = browserFilters.colleges[0];
    return { campus_id: college ? String(college.campus_id) : "", college_id: college ? String(college.college_id) : "", program_id: $("#filterProgram").val() || "" };
}

function inactiveLabel(item) {
    return item.status && item.status !== "active" ? " (inactive)" : "";
}

function populateCollegeFilter(campusId, selectedCollege = "") {
    const select = $("#filterCollege").empty();
    const colleges = browserFilters.colleges.filter(item => String(item.campus_id) === String(campusId));
    select.append(new Option(!campusId ? "Select a campus first" : colleges.length ? "Select College" : "No colleges in this campus", ""));
    colleges.forEach(item => select.append(new Option(`${item.college_name} (${item.college_code})${inactiveLabel(item)}`, String(item.college_id))));
    select.val(String(selectedCollege)).prop("disabled", !campusId || !colleges.length).trigger("change.select2");
}

function populateProgramFilter(collegeId, selectedProgram = "") {
    const select = $("#filterProgram").empty();
    select.append(new Option(collegeId ? "All Programs" : "Select a college first", ""));
    browserFilters.programs.filter(item => String(item.college_id) === String(collegeId)).forEach(program => {
        const total = Number(program.curriculum_count) || 0;
        const baseLabel = buildProgramLabel(program);
        const label = `${baseLabel}${inactiveLabel(program)} — ${total} ${total === 1 ? "curriculum" : "curricula"}`;
        select.append($(new Option(label, String(program.program_id))).attr("data-program-label", baseLabel));
    });
    select.val(select.find("option").toArray().some(option => option.value === String(selectedProgram)) ? String(selectedProgram) : "")
        .prop("disabled", !collegeId).trigger("change.select2");
}

function disposeProspectusActionMenus() {
    if (!BROWSER_IS_CHAIR) return;
    $("#prospectusTable .chair-prospectus-more").each(function () {
        const dropdown = bootstrap.Dropdown.getInstance(this);
        if (dropdown) dropdown.dispose();
    });
}

function renderEmptyState(message) {
    disposeProspectusActionMenus();
    $("#prospectusTable").html(`
        <tr>
            <td colspan="${PROSPECTUS_TABLE_COLUMNS}" class="text-center text-muted">
                ${escapeHtml(message)}
            </td>
        </tr>
    `);
}

function buildActionButtons(row) {
    if (BROWSER_IS_CHAIR) {
        const prospectusId = Number(row.prospectus_id) || 0;
        const menuId = `prospectusMore-${prospectusId}`;
        const attributes = `data-prospectus-id="${prospectusId}"
            data-program-id="${Number(row.program_id) || 0}"
            data-program-label="${escapeHtml(buildProgramLabel(row))}"
            data-cmo="${escapeHtml(row.cmo_no || '')}"
            data-effective-sy="${escapeHtml(row.effective_sy || '')}"`;
        return `<div class="d-flex justify-content-center align-items-center gap-1 flex-nowrap">
            <a href="view-prospectus.php?pid=${prospectusId}" class="btn btn-sm btn-primary">View</a>
            <a href="manage-prospectus.php?pid=${prospectusId}" class="btn btn-sm btn-outline-success">Manage</a>
            <div class="dropdown">
                <button type="button" id="${menuId}" class="btn btn-sm btn-outline-secondary dropdown-toggle chair-prospectus-more"
                    data-bs-toggle="dropdown" aria-expanded="false" aria-label="${escapeHtml('More actions for ' + (row.cmo_no || 'prospectus') + ' (' + (row.effective_sy || '') + ')')}">More</button>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="${menuId}">
                    <li><button type="button" class="dropdown-item btnEditBrowserCmo" ${attributes}><i class="bx bx-edit-alt me-2" aria-hidden="true"></i>Edit Details</button></li>
                    <li><button type="button" class="dropdown-item btnTransferBrowserProspectus" ${attributes}><i class="bx bx-transfer me-2" aria-hidden="true"></i>Move to Another Program</button></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><button type="button" class="dropdown-item text-danger btnDeleteBrowserProspectus" ${attributes}><i class="bx bx-trash me-2" aria-hidden="true"></i>Delete Prospectus</button></li>
                </ul>
            </div>
        </div>`;
    }
    const buttons = [
        `<a href="view-prospectus.php?pid=${Number(row.prospectus_id) || 0}" class="btn btn-sm btn-primary">Open</a>`
    ];

    if (CAN_MANAGE_CURRICULUM) {
        buttons.push(`<a href="manage-prospectus.php?pid=${Number(row.prospectus_id) || 0}" class="btn btn-sm btn-outline-success">Edit Subjects</a>`);
        buttons.push(
            `<button type="button"
                     class="btn btn-sm btn-outline-secondary btnEditBrowserCmo"
                     data-prospectus-id="${Number(row.prospectus_id) || 0}"
                     data-program-id="${Number(row.program_id) || 0}"
                     data-program-label="${escapeHtml(buildProgramLabel(row))}"
                     data-cmo="${escapeHtml(row.cmo_no || "")}"
                     data-effective-sy="${escapeHtml(row.effective_sy || "")}">
                Edit CMO
             </button>`
        );
        buttons.push(
            `<button type="button"
                     class="btn btn-sm btn-outline-primary btnCopyBrowserProspectus"
                     data-prospectus-id="${Number(row.prospectus_id) || 0}"
                     data-program-id="${Number(row.program_id) || 0}"
                     data-program-label="${escapeHtml(buildProgramLabel(row))}"
                     data-cmo="${escapeHtml(row.cmo_no || "")}"
                     data-effective-sy="${escapeHtml(row.effective_sy || "")}">
                Copy
             </button>`
        );
        buttons.push(
            `<button type="button"
                     class="btn btn-sm btn-outline-danger btnTransferBrowserProspectus"
                     data-prospectus-id="${Number(row.prospectus_id) || 0}"
                     data-program-id="${Number(row.program_id) || 0}"
                     data-program-label="${escapeHtml(buildProgramLabel(row))}"
                     data-cmo="${escapeHtml(row.cmo_no || "")}"
                     data-effective-sy="${escapeHtml(row.effective_sy || "")}">
                Transfer
             </button>`
        );
        buttons.push(
            `<button type="button"
                     class="btn btn-sm btn-danger btnDeleteBrowserProspectus"
                     data-prospectus-id="${Number(row.prospectus_id) || 0}"
                     data-program-label="${escapeHtml(buildProgramLabel(row))}"
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
    disposeProspectusActionMenus();
    tbody.empty();

    if (!Array.isArray(rows) || rows.length === 0) {
        renderEmptyState($("#filterProgram").val() ? "No prospectus found for this program" : "No prospectuses found for this college");
        return;
    }

    rows.forEach(function (row) {
        const programCells = BROWSER_IS_CHAIR
            ? `<td><div class="prospectus-program-details">
                <span class="prospectus-program-name">${escapeHtml(buildProgramLabel(row))}</span>
                <em class="prospectus-cmo text-muted">${escapeHtml(row.cmo_no || '—')}</em>
                <span class="prospectus-effectivity text-muted">Effectivity SY: ${escapeHtml(row.effective_sy || '—')}</span>
              </div></td>`
            : `<td>${escapeHtml(buildProgramLabel(row))}</td>
               <td>${escapeHtml(row.cmo_no || "")}</td>
               <td>${escapeHtml(row.effective_sy || "")}</td>`;
        tbody.append(`
            <tr>
                ${programCells}
                <td class="text-center">${escapeHtml(row.yearsem_count || 0)}</td>
                <td class="text-center">${escapeHtml(row.subject_count || 0)}</td>
                <td class="text-center">${escapeHtml(formatUnits(row.total_units))}</td>
                <td class="text-center">${buildActionButtons(row)}</td>
            </tr>
        `);
    });
    if (BROWSER_IS_CHAIR) {
        $("#prospectusTable .chair-prospectus-more").each(function () {
            // Position against the viewport so the responsive table does not clip the menu.
            new bootstrap.Dropdown(this, { popperConfig: defaults => ({ ...defaults, strategy: "fixed" }) });
        });
    }
}

function loadBrowserProspectuses() {
    const selection = currentBrowserSelection();
    const version = ++browserRequestVersion;
    if (browserRequest) browserRequest.abort();
    $("#browserResultsSummary").text("");
    if (!selection.campus_id || !selection.college_id) {
        renderEmptyState("Select a campus and college to view prospectuses");
        return;
    }
    renderEmptyState("Loading prospectuses...");
    // Chair scope is derived on the server; campus/college browsing exists only for administrators.
    const filters = BROWSER_IS_ADMIN ? selection : { program_id: selection.program_id };
    browserRequest = $.post("../backend/query_prospectus_browser.php", { load_browser_prospectuses: 1, ...filters }, function (rows) {
        if (version !== browserRequestVersion) return;
        if (!Array.isArray(rows)) { renderEmptyState("Failed to load prospectuses."); return; }
        const counts = new Map();
        rows.forEach(row => counts.set(String(row.program_id), (counts.get(String(row.program_id)) || 0) + 1));
        browserFilters.programs.forEach(program => {
            if (String(program.college_id) === String(selection.college_id)
                && (!selection.program_id || String(program.program_id) === String(selection.program_id))) {
                program.curriculum_count = counts.get(String(program.program_id)) || 0;
            }
        });
        populateProgramFilter(selection.college_id, selection.program_id);
        renderProspectusRows(rows);
        const programs = new Set(rows.map(row => String(row.program_id))).size;
        $("#browserResultsSummary").text(`${rows.length} ${rows.length === 1 ? "curriculum" : "curricula"} across ${programs} ${programs === 1 ? "program" : "programs"}`);
    }, "json").fail(function (xhr, status) {
        if (status !== "abort" && version === browserRequestVersion) {
            renderEmptyState(xhr.responseJSON && xhr.responseJSON.error || "Failed to load prospectuses. Please try again.");
        }
    });
}

function refreshBrowserResults(targetProgramId = "") {
    return $.post("../backend/query_prospectus_browser.php", { load_browser_filters: 1 }, function (filters) {
        if (!filters || !Array.isArray(filters.programs) || !Array.isArray(filters.colleges) || !Array.isArray(filters.campuses)) return;
        browserFilters = filters;
        let selection = currentBrowserSelection();
        if (targetProgramId) {
            const target = browserFilters.programs.find(item => String(item.program_id) === String(targetProgramId));
            if (target) selection = { campus_id: target.campus_id, college_id: target.college_id, program_id: target.program_id };
        }
        if (BROWSER_IS_ADMIN) {
            $("#filterCampus").val(String(selection.campus_id)).trigger("change.select2");
            populateCollegeFilter(selection.campus_id, selection.college_id);
        }
        populateProgramFilter(selection.college_id, selection.program_id);
        loadBrowserProspectuses();
    }, "json").fail(function () {
        Swal.fire("Refresh Needed", "Your change was saved, but the browser could not refresh. Reload this page to see the latest records.", "info");
    });
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

$("#filterCampus, #filterCollege, #filterProgram").select2({ width: "100%" });
if (BROWSER_IS_ADMIN) {
    browserFilters.campuses.forEach(item => $("#filterCampus").append(new Option(`${item.campus_name} (${item.campus_code})${inactiveLabel(item)}`, String(item.campus_id))));
    $("#filterCampus").trigger("change.select2").on("change", function () {
        populateCollegeFilter($(this).val());
        populateProgramFilter("");
        loadBrowserProspectuses();
    });
    $("#filterCollege").on("change", function () {
        populateProgramFilter($(this).val());
        loadBrowserProspectuses();
    });
} else {
    populateProgramFilter(currentBrowserSelection().college_id);
    loadBrowserProspectuses();
}
$("#filterProgram").on("change", loadBrowserProspectuses);

$(document).on("click", ".btnEditBrowserCmo", function () {
    const button = $(this);

    $("#edit_cmo_prospectus_id").val(button.data("prospectusId"));
    $("#edit_cmo_program_label").val(button.data("programLabel") || getSelectedProgramLabel());
    $("#edit_cmo_no").val(button.data("cmo") || "");
    $("#edit_effective_sy").val(button.data("effectiveSy") || "");

    $("#editCmoModal").modal("show");
});

<?php if (!$curriculumIsChair): ?>
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

<?php endif; ?>
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
                Swal.fire(BROWSER_IS_CHAIR ? "No Destination Program" : "No Target Program", BROWSER_IS_CHAIR ? "No other active program is available for this move." : "No other active program is available for transfer.", "info");
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
        confirmButtonText: BROWSER_IS_CHAIR ? "Delete Prospectus" : "Delete CMO",
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

            refreshBrowserResults();

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
        refreshBrowserResults();

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

<?php if (!$curriculumIsChair): ?>
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

        $("#copyProspectusBrowserModal").modal("hide");
        refreshBrowserResults(targetProgramId);

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

<?php endif; ?>
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
            Swal.fire("Error", parts[1] || (BROWSER_IS_CHAIR ? "Failed to move prospectus." : "Failed to transfer prospectus."), "error");
            return;
        }

        $("#transferProspectusBrowserModal").modal("hide");
        refreshBrowserResults(targetProgramId);

        Swal.fire({
            icon: "success",
            title: BROWSER_IS_CHAIR ? "Prospectus Moved" : "Prospectus Transferred",
            text: BROWSER_IS_CHAIR ? "Prospectus moved successfully." : (parts[1] || "Prospectus moved successfully."),
            timer: 1400,
            showConfirmButton: false
        });
    }).fail(function () {
        Swal.fire("Error", BROWSER_IS_CHAIR ? "Failed to move prospectus." : "Failed to transfer prospectus.", "error");
    });
});
</script>

</body>
</html>
