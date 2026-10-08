<?php
session_start();
ob_start();
include '../backend/db.php';
require_once '../backend/academic_term_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'scheduler') {
    header("Location: ../index.php");
    exit;
}

$collegeId = (int)($_SESSION['college_id'] ?? 0);
$collegeName = $_SESSION['college_name'] ?? '';
$currentTerm = synk_fetch_current_academic_term($conn);
$defaultAyId = (int)($currentTerm['ay_id'] ?? 0);
$defaultAyLabel = (string)($currentTerm['ay_label'] ?? '');
$defaultSemesterUi = '';
$defaultSemesterNum = (int)($currentTerm['semester'] ?? 0);

if ($defaultAyId <= 0 || $defaultAyLabel === '') {
    $fallbackAyResult = mysqli_query($conn, "SELECT ay_id, ay FROM tbl_academic_years ORDER BY ay DESC LIMIT 1");
    $fallbackAyRow = $fallbackAyResult ? mysqli_fetch_assoc($fallbackAyResult) : null;
    if (is_array($fallbackAyRow)) {
        $defaultAyId = (int)($fallbackAyRow['ay_id'] ?? 0);
        $defaultAyLabel = (string)($fallbackAyRow['ay'] ?? '');
    }
}

if ($defaultSemesterNum === 1) {
    $defaultSemesterUi = '1st';
} elseif ($defaultSemesterNum === 2) {
    $defaultSemesterUi = '2nd';
} elseif ($defaultSemesterNum === 3) {
    $defaultSemesterUi = 'Midyear';
}

$facultyOptions = "";
$defaultFacultyId = 0;
$facultySql = "
    SELECT DISTINCT
        f.faculty_id,
        CONCAT(f.last_name, ', ', f.first_name, ' ', COALESCE(f.ext_name, '')) AS full_name
    FROM tbl_college_faculty cf
    INNER JOIN tbl_faculty f
        ON f.faculty_id = cf.faculty_id
    WHERE cf.college_id = ?
      AND cf.status = 'active'
      AND f.status = 'active'
";

$facultySql .= " ORDER BY f.last_name, f.first_name";

$facultyStmt = $conn->prepare($facultySql);
if ($facultyStmt instanceof mysqli_stmt) {
    $facultyStmt->bind_param("i", $collegeId);
    $facultyStmt->execute();
    $facultyResult = $facultyStmt->get_result();

    while ($facultyResult && ($row = $facultyResult->fetch_assoc())) {
        $facultyId = (int)($row['faculty_id'] ?? 0);
        $facultyName = htmlspecialchars((string)($row['full_name'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($facultyId > 0 && $facultyName !== '') {
            if ($defaultFacultyId <= 0) {
                $defaultFacultyId = $facultyId;
            }
            $selected = ($facultyId === $defaultFacultyId) ? " selected" : "";
            $facultyOptions .= "<option value='{$facultyId}'{$selected}>{$facultyName}</option>";
        }
    }

    $facultyStmt->close();
}

$ayOptions = [];
$ayResult = mysqli_query($conn, "SELECT ay_id, ay FROM tbl_academic_years ORDER BY ay ASC");
while ($ayResult && ($row = mysqli_fetch_assoc($ayResult))) {
    $ayOptions[] = [
        'ay_id' => (int)($row['ay_id'] ?? 0),
        'ay' => (string)($row['ay'] ?? '')
    ];
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
    <title>Workload Simulations | Synk Scheduler</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
        .simulation-hero-card,
        .simulation-data-card {
            border: 1px solid #dbe5f1;
            box-shadow: 0 2px 8px rgba(18, 38, 63, 0.05);
        }

        .simulation-note {
            color: #6f7f95;
        }

        #simulationAlert .alert {
            background: linear-gradient(135deg, #eef7ff, #f9fbff);
            border-color: #cfe2ff;
            color: #1f5e8c;
        }

        .simulation-table thead th {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #5f728b;
            border-bottom: 1px solid #dbe4ef;
            white-space: nowrap;
        }

        .simulation-table tbody td,
        .simulation-table tfoot td,
        .simulation-table tfoot th {
            color: #5c6f88;
            border-color: #e7edf5;
            vertical-align: middle;
        }

        .simulation-table tfoot th,
        .simulation-table tfoot td {
            background: #f9fbfd;
            border-top: 2px solid #d7e1ec;
        }

        .simulation-code { font-weight: 700; color: #51657f; white-space: nowrap; }
        .simulation-desc { color: #5d7088; }
        .simulation-time { min-width: 88px; line-height: 1.08; }
        .time-line { display: block; white-space: nowrap; }
        .simulation-merged { background: #fbfcfe; font-weight: 600; }

        .type-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 44px;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .type-pill.lec { background: #e8e9ff; color: #5d68f4; }
        .type-pill.lab { background: #fff0cf; color: #c98900; }

        .partner-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 72px;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            background: #eef2f7;
            color: #52657d;
        }

        .pair-aware-badge {
            background: var(--pair-soft, #eef2f7);
            color: var(--pair-text, #52657d);
            box-shadow: inset 0 0 0 1px var(--pair-accent, #ccd7e6);
        }

        .pair-linked-row td {
            transition: background-color 0.18s ease, box-shadow 0.18s ease, color 0.18s ease;
        }

        .pair-linked-row td:first-child {
            box-shadow: inset 4px 0 0 var(--pair-accent, transparent);
        }

        .pair-linked-row .simulation-code {
            color: var(--pair-text, #51657f);
        }

        .pair-linked-row.pair-linked-active td {
            background: var(--pair-surface, #f8fbff);
        }

        .pair-linked-row.pair-linked-active td:first-child {
            box-shadow: inset 6px 0 0 var(--pair-accent, transparent);
        }

        .pair-linked-row.pair-linked-active .pair-aware-badge {
            background: var(--pair-accent, #dbe5f1);
            color: #fff;
            box-shadow: none;
        }

        .pair-linked-row.pair-linked-active .type-pill {
            box-shadow: 0 0 0 1px var(--pair-accent, transparent);
        }

        .table-loader-row td { padding-top: 1rem !important; padding-bottom: 1rem !important; }

        .table-loader {
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            color: #6f7f95;
            font-weight: 600;
        }

        .table-loader .spinner-border { width: 1rem; height: 1rem; }

        .load-status-inline {
            display: inline-block;
            margin-left: 0.45rem;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            vertical-align: middle;
        }

        .load-status-inline.underload { background: #fff3cd; color: #7a5a00; }
        .load-status-inline.overload { background: #fde8ea; color: #a61c2d; }

        .btn-outline-danger-soft { color: #b42318; border-color: #f0b4af; background: #fff7f6; }
        .btn-outline-danger-soft:hover { color: #fff; background: #b42318; border-color: #b42318; }

        .select2-container--default .select2-selection--single {
            height: 38px !important;
            padding: 6px 12px !important;
            display: flex;
            align-items: center;
            border: 1px solid #d9dee3;
            border-radius: 6px;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 42px !important;
            right: 10px !important;
        }

        .select2-selection__rendered { line-height: 42px !important; }
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
                    <div class="card simulation-hero-card mb-4">
                        <div class="card-body">
                            <h4 class="fw-bold mb-2">
                                <i class="bx bx-git-compare me-2"></i>
                                Workload Simulations
                                <small class="text-muted">(<?= htmlspecialchars($collegeName, ENT_QUOTES, 'UTF-8') ?>)</small>
                            </h4>
                            <p class="mb-0 simulation-note">
                                Build faculty load simulations from generated offerings and save them in the database for later review and printing.
                            </p>
                        </div>
                    </div>

                    <div class="card simulation-data-card mb-4">
                        <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                            <div>
                                <h5 class="m-0">Simulation Filters</h5>
                                <small class="text-muted">Pick a faculty, academic year, and semester to start simulating.</small>
                            </div>
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="printSimulationBtn">
                                <i class="bx bx-printer me-1"></i> Print All Simulations
                            </button>
                        </div>
                        <div class="card-body">
                            <div class="row g-3 align-items-end">
                                <div class="col-lg-6">
                                    <label class="form-label fw-semibold" for="simulationFaculty">Select Faculty</label>
                                    <select id="simulationFaculty" class="form-select select2-single">
                                        <option value="">Select Faculty</option>
                                        <?= $facultyOptions ?>
                                    </select>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label fw-semibold" for="simulationSemester">Semester</label>
                                    <select id="simulationSemester" class="form-select">
                                        <option value="1st"<?= $defaultSemesterUi === '1st' ? ' selected' : '' ?>>1st Semester</option>
                                        <option value="2nd"<?= $defaultSemesterUi === '2nd' ? ' selected' : '' ?>>2nd Semester</option>
                                        <option value="Midyear"<?= $defaultSemesterUi === 'Midyear' ? ' selected' : '' ?>>Midyear</option>
                                    </select>
                                </div>
                                <div class="col-lg-3 col-md-6">
                                    <label class="form-label fw-semibold" for="simulationAy">A.Y.</label>
                                    <select id="simulationAy" class="form-select select2-single">
                                        <option value="">Select A.Y.</option>
                                        <?php
                                        foreach ($ayOptions as $row) {
                                            $ayId = (int)($row['ay_id'] ?? 0);
                                            $ayValue = htmlspecialchars((string)($row['ay'] ?? ''), ENT_QUOTES, 'UTF-8');
                                            $selected = ($ayId === $defaultAyId || (string)($row['ay'] ?? '') === $defaultAyLabel) ? " selected" : "";
                                            echo "<option value='{$ayValue}' data-ay-id='{$ayId}'{$selected}>{$ayValue}</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-3" id="simulationAlert" style="display:none;">
                                <div class="alert mb-0">
                                    <strong>Faculty Selected:</strong> <span id="simulationFacultyName"></span>
                                    &nbsp;|&nbsp; <span class="fw-semibold">Term:</span>
                                    <span id="simulationTermText"></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card simulation-data-card mb-4" id="simulationWorkloadCard" style="display:none;">
                        <div class="card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                            <div>
                                <h5 class="m-0">Simulation Workload</h5>
                                <small class="text-muted">Saved simulated load for the selected faculty and term.</small>
                            </div>
                            <button type="button" class="btn btn-outline-danger-soft btn-sm" id="clearSimulationBtn">
                                <i class="bx bx-reset me-1"></i> Clear Simulation
                            </button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0 simulation-table">
                                <thead class="table-light">
                                    <tr>
                                        <th rowspan="2">Course No.</th>
                                        <th rowspan="2">Course Description</th>
                                        <th rowspan="2">Course</th>
                                        <th rowspan="2">Day</th>
                                        <th rowspan="2">Time</th>
                                        <th rowspan="2">Room</th>
                                        <th rowspan="2" class="text-center">Unit</th>
                                        <th colspan="2" class="text-center">No. of Hours</th>
                                        <th rowspan="2" class="text-center">Load</th>
                                        <th rowspan="2" class="text-center"># of Students</th>
                                        <th rowspan="2" class="text-end">Action</th>
                                    </tr>
                                    <tr>
                                        <th class="text-center">Lab Hrs</th>
                                        <th class="text-center">Lec Hrs</th>
                                    </tr>
                                </thead>
                                <tbody id="simulationWorkloadTbody"></tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="2" class="text-start">Designation:</th>
                                        <td colspan="4" id="simulationDesignationText"></td>
                                        <td></td><td></td><td></td>
                                        <td class="text-center fw-semibold" id="simulationDesignationLoad"></td>
                                        <td></td><td></td>
                                    </tr>
                                    <tr>
                                        <th colspan="2" class="text-start">No. of Prep:</th>
                                        <td colspan="4" id="simulationTotalPreparations">0</td>
                                        <td></td><td></td><td></td><td></td><td></td><td></td>
                                    </tr>
                                    <tr>
                                        <th colspan="6" class="text-end">Total Load</th>
                                        <th class="text-center" id="simulationTotalUnit">0</th>
                                        <th class="text-center" id="simulationTotalLab">0</th>
                                        <th class="text-center" id="simulationTotalLec">0</th>
                                        <th class="text-center fw-semibold" id="simulationTotalLoad">0</th>
                                        <th class="text-center" id="simulationTotalStudents"></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <div class="card simulation-data-card mb-4" id="simulationOfferingsCard" style="display:none;">
                        <div class="card-header">
                            <h5 class="m-0">Generated Offerings for Simulation</h5>
                            <small class="text-muted">Add offerings one by one to the selected faculty workload simulation.</small>
                        </div>
                        <div class="card-body py-2 border-bottom">
                            <div class="row g-2 align-items-center">
                                <div class="col-lg-8">
                                    <input type="text" id="simulationSearch" class="form-control" placeholder="Search by course, description, section, or type...">
                                </div>
                                <div class="col-lg-4">
                                    <select id="simulationFilter" class="form-select">
                                        <option value="all">Filter by: All</option>
                                        <option value="course">Course No.</option>
                                        <option value="desc">Description</option>
                                        <option value="section">Section</option>
                                        <option value="type">Type</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0 simulation-table">
                                <thead class="table-light">
                                    <tr>
                                        <th>Course No.</th>
                                        <th>Course Description</th>
                                        <th>Section</th>
                                        <th>Type</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="simulationOfferingsTbody"></tbody>
                            </table>
                        </div>
                        <div class="card-footer">
                            <small class="text-muted">
                                Scheduled rows keep their real day, time, and room. Missing components appear as virtual lecture/lab rows so you can still simulate the prospectus load before all schedules are finalized.
                            </small>
                        </div>
                    </div>
                </div>

                <?php include '../footer.php'; ?>
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
<script src="../assets/vendor/js/menu.js"></script>
<script src="../assets/js/main.js"></script>
<script>
let simulationMetaRequest = null;
let simulationSavedRequest = null;
let simulationOfferingsRequest = null;
let selectionRequestToken = 0;
let simulationMeta = {
    designation_name: "",
    designation_label: "",
    designation_units: 0,
    total_preparations: 0
};
let simulationCatalog = [];
let savedSimulationRows = [];
let hasSelect2 = false;

const SEMESTER_MAP = {
    "1st": 1,
    "2nd": 2,
    "Midyear": 3
};
const WORKLOAD_COLLEGE_NAME = <?= json_encode($collegeName) ?>;
const DEFAULT_AY_ID = <?= json_encode($defaultAyId) ?>;
const DEFAULT_AY_LABEL = <?= json_encode($defaultAyLabel) ?>;
const DEFAULT_SEMESTER_UI = <?= json_encode($defaultSemesterUi) ?>;
const DEFAULT_FACULTY_ID = <?= json_encode($defaultFacultyId) ?>;
const PAIR_COLOR_PALETTES = [
    { accent: "#4f7cff", soft: "#eaf0ff", surface: "#f7f9ff", text: "#2e56bd" },
    { accent: "#1fa6a2", soft: "#e4fbf8", surface: "#f2fffd", text: "#117270" },
    { accent: "#d27d2d", soft: "#fff3e5", surface: "#fffaf4", text: "#96510d" },
    { accent: "#b05df0", soft: "#f4e9ff", surface: "#fbf6ff", text: "#7a2dc0" },
    { accent: "#e25f8d", soft: "#ffe8f0", surface: "#fff7fa", text: "#af2b5d" },
    { accent: "#4f9b57", soft: "#e9f8eb", surface: "#f7fdf8", text: "#2f6e36" },
    { accent: "#2f84c9", soft: "#e7f4ff", surface: "#f5fbff", text: "#1d5f96" },
    { accent: "#8f6cf4", soft: "#eee9ff", surface: "#faf8ff", text: "#5b3fc0" }
];

function escapeHtml(value) {
    return String(value ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function toNumber(value) {
    const n = Number(value);
    return Number.isFinite(n) ? n : 0;
}

function formatNumber(value) {
    const n = toNumber(value);
    return Number.isInteger(n) ? String(n) : String(parseFloat(n.toFixed(2)));
}

function formatStudentCount(value) {
    const n = Math.round(toNumber(value));
    return n > 0 ? String(n) : "";
}

function formatCompactTime(value) {
    const raw = String(value ?? "").trim();
    if (raw === "") {
        return "<span class=\"text-muted\">-</span>";
    }

    const parts = raw.split("-");
    if (parts.length !== 2) {
        return escapeHtml(raw);
    }

    return `
        <span class="time-line">${escapeHtml(parts[0].trim())}</span>
        <span class="time-line">${escapeHtml(parts[1].trim())}</span>
    `;
}

function buildLoadingRow(colspan, message) {
    return `
        <tr class="table-loader-row">
            <td colspan="${colspan}" class="text-center text-muted">
                <div class="table-loader">
                    <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                    <span>${escapeHtml(message)}</span>
                </div>
            </td>
        </tr>
    `;
}

function ensureSimulationFilterDefaults() {
    const $faculty = $("#simulationFaculty");
    const $semester = $("#simulationSemester");
    const $ay = $("#simulationAy");

    const selectedFacultyId = Number($faculty.val()) || 0;
    if (!selectedFacultyId && DEFAULT_FACULTY_ID > 0) {
        $faculty.val(String(DEFAULT_FACULTY_ID));
    }

    if (!$semester.val() && DEFAULT_SEMESTER_UI) {
        $semester.val(DEFAULT_SEMESTER_UI);
    }

    const selectedAyId = Number($ay.find("option:selected").data("ay-id")) || 0;
    if ($ay.val() && selectedAyId > 0) {
        if (hasSelect2) {
            $faculty.trigger("change.select2");
            $ay.trigger("change.select2");
        }
        return;
    }

    let $fallbackOption = $();
    if (DEFAULT_AY_ID > 0) {
        $fallbackOption = $ay.find(`option[data-ay-id="${DEFAULT_AY_ID}"]`).first();
    }

    if (!$fallbackOption.length && DEFAULT_AY_LABEL) {
        $fallbackOption = $ay.find("option").filter(function () {
            return String($(this).val()) === DEFAULT_AY_LABEL;
        }).first();
    }

    if (!$fallbackOption.length) {
        $fallbackOption = $ay.find("option").filter(function () {
            return String($(this).val()).trim() !== "" && (Number($(this).data("ay-id")) || 0) > 0;
        }).last();
    }

    if ($fallbackOption.length) {
        $ay.val(String($fallbackOption.val()));
    }

    if (hasSelect2) {
        $faculty.trigger("change.select2");
        $ay.trigger("change.select2");
    }
}

function initSimulationSelects() {
    hasSelect2 = Boolean(window.jQuery && $.fn && typeof $.fn.select2 === "function");

    if (!hasSelect2) {
        return;
    }

    $("#simulationFaculty").select2({
        width: "100%",
        placeholder: "Select Faculty",
        allowClear: true
    });

    $("#simulationAy").select2({
        width: "100%",
        placeholder: "Select A.Y.",
        allowClear: true
    });
}

function hashText(value) {
    const input = String(value || "");
    let hash = 0;

    for (let index = 0; index < input.length; index += 1) {
        hash = ((hash << 5) - hash) + input.charCodeAt(index);
        hash |= 0;
    }

    return Math.abs(hash);
}

function escapeSelectorValue(value) {
    const raw = String(value || "");
    if (window.CSS && typeof window.CSS.escape === "function") {
        return window.CSS.escape(raw);
    }

    return raw.replace(/([ #;?%&,.+*~\\':"!^$[\]()=>|\/@])/g, "\\$1");
}

function getPairKey(row) {
    const partnerLabel = String(row?.partner_label || "").trim();
    if (partnerLabel === "") {
        return "";
    }

    return String(row?.context_key || row?.sim_key || partnerLabel).trim();
}

function getPairPalette(pairKey) {
    if (!pairKey) {
        return null;
    }

    return PAIR_COLOR_PALETTES[hashText(pairKey) % PAIR_COLOR_PALETTES.length];
}

function buildPairStyleVars(pairKey) {
    const palette = getPairPalette(pairKey);
    if (!palette) {
        return "";
    }

    return [
        `--pair-accent:${palette.accent}`,
        `--pair-soft:${palette.soft}`,
        `--pair-surface:${palette.surface}`,
        `--pair-text:${palette.text}`
    ].join(";");
}

function buildPairRowAttributes(row, extraClass = "") {
    const classes = [];
    const pairKey = getPairKey(row);
    const attrs = [];

    if (extraClass) {
        classes.push(extraClass);
    }

    if (pairKey !== "") {
        classes.push("pair-linked-row");
        attrs.push(`data-pair-key="${escapeHtml(pairKey)}"`);

        const pairStyle = buildPairStyleVars(pairKey);
        if (pairStyle !== "") {
            attrs.push(`style="${escapeHtml(pairStyle)}"`);
        }
    }

    if (classes.length) {
        attrs.unshift(`class="${classes.join(" ")}"`);
    }

    return attrs.length ? ` ${attrs.join(" ")}` : "";
}

function buildPairBadge(row) {
    const partnerLabel = String(row?.partner_label || "").trim();
    const pairKey = getPairKey(row);

    if (partnerLabel === "" || pairKey === "") {
        return "";
    }

    const badgeStyle = buildPairStyleVars(pairKey);
    const title = `${partnerLabel} • ${pairKey}`;

    return `
        <span
            class="partner-pill pair-aware-badge"
            data-pair-key="${escapeHtml(pairKey)}"
            style="${escapeHtml(badgeStyle)}"
            title="${escapeHtml(title)}"
        >${escapeHtml(partnerLabel)}</span>
    `;
}

function highlightPairRows(pairKey) {
    $(".pair-linked-row.pair-linked-active").removeClass("pair-linked-active");

    if (!pairKey) {
        return;
    }

    $(`.pair-linked-row[data-pair-key="${escapeSelectorValue(pairKey)}"]`).addClass("pair-linked-active");
}

function clearPairRows(pairKey) {
    if (!pairKey) {
        $(".pair-linked-row.pair-linked-active").removeClass("pair-linked-active");
        return;
    }

    $(`.pair-linked-row[data-pair-key="${escapeSelectorValue(pairKey)}"]`).removeClass("pair-linked-active");
}

function buildTypeBadge(type) {
    const normalized = String(type || "LEC").toUpperCase() === "LAB" ? "LAB" : "LEC";
    return `<span class="type-pill ${normalized.toLowerCase()}">${normalized}</span>`;
}

function buildWorkloadDescription(row) {
    return escapeHtml(row?.subject_description || row?.desc || "");
}

function normalizeWorkloadResponse(payload) {
    if (Array.isArray(payload)) {
        return {
            rows: payload,
            meta: {
                designation_name: "",
                designation_label: "",
                designation_units: 0,
                total_preparations: 0
            }
        };
    }

    if (!payload || !Array.isArray(payload.rows)) {
        return null;
    }

    return {
        rows: payload.rows,
        meta: payload.meta || {}
    };
}

function formatDesignationDisplay(meta) {
    const name = String(meta?.designation_name || meta?.designation_label || "").trim();
    const label = String(meta?.designation_label || name).trim();

    if (!label) {
        return "";
    }

    if (name.toUpperCase() === "DEAN" && String(WORKLOAD_COLLEGE_NAME || "").trim() !== "") {
        return `${label}, ${WORKLOAD_COLLEGE_NAME}`;
    }

    return label;
}

function getLoadStatus(loadValue) {
    const numericLoad = toNumber(loadValue);

    if (numericLoad > 21) {
        return { label: "Overload", className: "overload" };
    }

    if (numericLoad >= 18) {
        return { label: "", className: "normal" };
    }

    return { label: "Underload", className: "underload" };
}

function sumSimulationGroupMetrics(rows) {
    const totals = rows.reduce(function (carry, row) {
        carry.units += toNumber(row?.units);
        carry.lab += toNumber(row?.lab);
        carry.lec += toNumber(row?.lec);
        carry.faculty_load += toNumber(row?.faculty_load);
        return carry;
    }, {
        units: 0,
        lab: 0,
        lec: 0,
        faculty_load: 0
    });

    return {
        units: Number(totals.units.toFixed(2)),
        lab: Number(totals.lab.toFixed(2)),
        lec: Number(totals.lec.toFixed(2)),
        faculty_load: Number(totals.faculty_load.toFixed(2))
    };
}

function getSelectedContext() {
    const facultyId = $("#simulationFaculty").val();
    const ayText = $("#simulationAy").val();
    const semesterUi = $("#simulationSemester").val();
    const ayId = Number($("#simulationAy option:selected").data("ay-id")) || 0;
    const semesterNum = SEMESTER_MAP[semesterUi] || 0;

    if (!facultyId || !ayText || !semesterUi || !ayId || !semesterNum) {
        return null;
    }

    return {
        facultyId: Number(facultyId),
        facultyName: $("#simulationFaculty option:selected").text().trim(),
        ayId,
        ayText,
        semesterUi,
        semesterNum
    };
}

function abortPendingRequest(request) {
    if (request && request.readyState !== 4) {
        request.abort();
    }
}

function extractErrorMessage(xhr, fallback) {
    if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
        return xhr.responseJSON.message;
    }

    return fallback;
}

function hidePanels() {
    abortPendingRequest(simulationMetaRequest);
    abortPendingRequest(simulationSavedRequest);
    abortPendingRequest(simulationOfferingsRequest);
    $("#simulationAlert").hide();
    $("#simulationWorkloadCard").hide();
    $("#simulationOfferingsCard").hide();
    $("#simulationWorkloadTbody").html("");
    $("#simulationOfferingsTbody").html("");
}

function setSimulationLoadingState() {
    $("#simulationWorkloadCard").show();
    $("#simulationWorkloadTbody").html(buildLoadingRow(12, "Loading saved simulation..."));
}

function setOfferingsLoadingState() {
    $("#simulationOfferingsCard").show();
    $("#simulationOfferingsTbody").html(buildLoadingRow(5, "Loading generated offerings..."));
}

function refreshPanels() {
    const context = getSelectedContext();
    if (!context) {
        hidePanels();
        return;
    }

    const requestToken = ++selectionRequestToken;
    $("#simulationFacultyName").text(context.facultyName);
    $("#simulationTermText").text(`${context.semesterUi} A.Y. ${context.ayText}`);
    $("#simulationAlert").stop(true, true).slideDown();

    loadSimulationMeta(context, requestToken);
    loadSavedSimulation(context, requestToken);
    loadSimulationCatalog(context, requestToken);
}

function loadSimulationMeta(context, requestToken) {
    abortPendingRequest(simulationMetaRequest);

    simulationMetaRequest = $.ajax({
        url: "../backend/query_load_faculty_workload.php",
        type: "POST",
        dataType: "json",
        data: {
            faculty_id: context.facultyId,
            ay_id: context.ayId,
            semester: context.semesterNum
        }
    }).done(function (data) {
        if (requestToken !== selectionRequestToken) {
            return;
        }

        const payload = normalizeWorkloadResponse(data);
        simulationMeta = payload ? (payload.meta || {}) : {};
        renderSimulationWorkload();
    }).fail(function (xhr, status) {
        if (status === "abort" || requestToken !== selectionRequestToken) {
            return;
        }

        simulationMeta = {
            designation_name: "",
            designation_label: "",
            designation_units: 0,
            total_preparations: 0
        };
        renderSimulationWorkload();
    });
}

function loadSavedSimulation(context, requestToken) {
    abortPendingRequest(simulationSavedRequest);
    setSimulationLoadingState();

    simulationSavedRequest = $.ajax({
        url: "../backend/query_load_workload_simulation.php",
        type: "POST",
        dataType: "json",
        data: {
            faculty_id: context.facultyId,
            ay_id: context.ayId,
            semester: context.semesterNum
        }
    }).done(function (data) {
        if (requestToken !== selectionRequestToken) {
            return;
        }

        savedSimulationRows = Array.isArray(data?.rows) ? data.rows : [];
        renderSimulationPanels(context);
    }).fail(function (xhr, status) {
        if (status === "abort" || requestToken !== selectionRequestToken) {
            return;
        }

        savedSimulationRows = [];
        $("#simulationWorkloadCard").show();
        $("#simulationWorkloadTbody").html(`
            <tr>
                <td colspan="12" class="text-center text-danger">${escapeHtml(extractErrorMessage(xhr, "Failed to load saved simulation data."))}</td>
            </tr>
        `);
    });
}

function loadSimulationCatalog(context, requestToken) {
    abortPendingRequest(simulationOfferingsRequest);
    setOfferingsLoadingState();

    simulationOfferingsRequest = $.ajax({
        url: "../backend/query_workload_simulation_offerings.php",
        type: "POST",
        dataType: "json",
        data: {
            ay_id: context.ayId,
            semester: context.semesterNum
        }
    }).done(function (data) {
        if (requestToken !== selectionRequestToken) {
            return;
        }

        simulationCatalog = Array.isArray(data?.rows) ? data.rows : [];
        renderSimulationOfferings(context);
    }).fail(function (xhr, status) {
        if (status === "abort" || requestToken !== selectionRequestToken) {
            return;
        }

        simulationCatalog = [];
        $("#simulationOfferingsCard").show();
        $("#simulationOfferingsTbody").html(`
            <tr>
                <td colspan="5" class="text-center text-danger">${escapeHtml(extractErrorMessage(xhr, "Failed to load generated offerings."))}</td>
            </tr>
        `);
    });
}

function renderSimulationWorkload() {
    const grouped = new Map();
    const sortedRows = savedSimulationRows.slice().sort(function (a, b) {
        const left = `${a.section_name || ""}|${a.subject_code || ""}|${a.schedule_type || ""}|${a.time || ""}`;
        const right = `${b.section_name || ""}|${b.subject_code || ""}|${b.schedule_type || ""}|${b.time || ""}`;
        return left.localeCompare(right);
    });

    sortedRows.forEach(function (row) {
        const key = String(row.context_key || row.sim_key || "");
        if (!grouped.has(key)) {
            grouped.set(key, []);
        }
        grouped.get(key).push(row);
    });

    let totalUnit = 0;
    let totalLab = 0;
    let totalLec = 0;
    let totalLoad = 0;
    let rowsHtml = "";
    const prepSet = new Set();

    grouped.forEach(function (groupRows) {
        if (!groupRows.length) {
            return;
        }

        const first = groupRows[0];
        const metrics = sumSimulationGroupMetrics(groupRows);

        totalUnit += metrics.units;
        totalLab += metrics.lab;
        totalLec += metrics.lec;
        totalLoad += metrics.faculty_load;

        const prepKey = String(first.subject_code || "").trim();
        if (prepKey) {
            prepSet.add(prepKey);
        }

        groupRows.forEach(function (row, index) {
            rowsHtml += `
                <tr${buildPairRowAttributes(row)}>
                    <td class="simulation-code">${escapeHtml(row.subject_code)}</td>
                    <td class="simulation-desc">${buildWorkloadDescription(row, groupRows.length > 1)}</td>
                    <td>${escapeHtml(row.course || row.section_name)}</td>
                    <td>${escapeHtml(row.days || "") || '<span class="text-muted">-</span>'}</td>
                    <td class="simulation-time">${formatCompactTime(row.time)}</td>
                    <td>${escapeHtml(row.room_code || "") || '<span class="text-muted">-</span>'}</td>
                    ${index === 0 ? `
                        <td class="text-center simulation-merged" rowspan="${groupRows.length}">${formatNumber(metrics.units)}</td>
                        <td class="text-center simulation-merged" rowspan="${groupRows.length}">${formatNumber(metrics.lab)}</td>
                        <td class="text-center simulation-merged" rowspan="${groupRows.length}">${formatNumber(metrics.lec)}</td>
                        <td class="text-center simulation-merged" rowspan="${groupRows.length}">${formatNumber(metrics.faculty_load)}</td>
                    ` : ""}
                    <td class="text-center">${formatStudentCount(row.student_count)}</td>
                    <td class="text-end">
                        <button type="button" class="btn btn-sm btn-outline-danger remove-simulation-row" data-simulation-id="${escapeHtml(row.simulation_id)}">
                            <i class="bx bx-trash"></i>
                        </button>
                    </td>
                </tr>
            `;
        });
    });

    if (!rowsHtml) {
        rowsHtml = `
            <tr>
                <td colspan="12" class="text-center text-muted">
                    No simulated rows yet. Add generated offerings below to build the faculty workload simulation.
                </td>
            </tr>
        `;
    }

    const designationUnits = toNumber(simulationMeta.designation_units);
    const grandTotalLoad = totalLoad + designationUnits;
    const loadStatus = getLoadStatus(grandTotalLoad);

    $("#simulationWorkloadTbody").html(rowsHtml);
    $("#simulationDesignationText").text(formatDesignationDisplay(simulationMeta));
    $("#simulationDesignationLoad").text(designationUnits > 0 ? formatNumber(designationUnits) : "");
    $("#simulationTotalPreparations").text(formatNumber(prepSet.size));
    $("#simulationTotalUnit").text(formatNumber(totalUnit));
    $("#simulationTotalLab").text(formatNumber(totalLab));
    $("#simulationTotalLec").text(formatNumber(totalLec));
    $("#simulationTotalLoad").html(`
        <span>${escapeHtml(formatNumber(grandTotalLoad))}</span>
        ${loadStatus.label ? `<span class="load-status-inline ${escapeHtml(loadStatus.className)}">${escapeHtml(loadStatus.label)}</span>` : ""}
    `);
    $("#simulationTotalStudents").text("");
    $("#simulationWorkloadCard").show();
}

function renderSimulationOfferings(context) {
    const keyword = $("#simulationSearch").val().toLowerCase();
    const filter = $("#simulationFilter").val();
    const savedKeys = new Set(savedSimulationRows.map(function (row) {
        return String(row.sim_key || "");
    }));
    let rowsHtml = "";

    simulationCatalog.forEach(function (row) {
        const haystack = {
            all: [
                row.subject_code || "",
                row.subject_description || "",
                row.section_name || row.course || "",
                row.schedule_type || "",
                row.partner_label || ""
            ].join(" ").toLowerCase(),
            course: String(row.subject_code || "").toLowerCase(),
            desc: String(row.subject_description || "").toLowerCase(),
            section: String(row.section_name || row.course || "").toLowerCase(),
            type: String(row.schedule_type || "").toLowerCase()
        };
        const matches = keyword === "" || (haystack[filter] || haystack.all).includes(keyword);
        if (!matches) {
            return;
        }

        const alreadySaved = savedKeys.has(String(row.sim_key || ""));
        if (alreadySaved) {
            return;
        }

        rowsHtml += `
            <tr${buildPairRowAttributes(row)}>
                <td class="simulation-code">${escapeHtml(row.subject_code)}</td>
                <td class="simulation-desc">${buildWorkloadDescription(row)}</td>
                <td>${escapeHtml(row.course || row.section_name)}</td>
                <td class="text-center">${buildTypeBadge(row.schedule_type)}</td>
                <td class="text-end">
                    <button
                        type="button"
                        class="btn btn-sm btn-primary btnAddSimulation"
                        data-sim-key="${escapeHtml(row.sim_key)}"
                    >
                        Add to Workload
                    </button>
                </td>
            </tr>
        `;
    });

    if (!rowsHtml) {
        rowsHtml = `
            <tr>
                <td colspan="5" class="text-center text-muted">
                    ${simulationCatalog.length === 0 ? "No generated offerings found for this term." : "No offerings match your current search."}
                </td>
            </tr>
        `;
    }

    $("#simulationOfferingsTbody").html(rowsHtml);
    $("#simulationOfferingsCard").show();
}

function renderSimulationPanels(context = getSelectedContext()) {
    if (!context) {
        return;
    }

    renderSimulationWorkload();
    renderSimulationOfferings(context);
}

$(document).ready(function () {
    initSimulationSelects();

    ensureSimulationFilterDefaults();

    $("#simulationFaculty, #simulationAy").on("change select2:select select2:clear", function () {
        refreshPanels();
    });

    $("#simulationSemester").on("change", function () {
        refreshPanels();
    });

    $("#simulationSearch, #simulationFilter").on("keyup change", function () {
        const context = getSelectedContext();
        if (!context) {
            return;
        }

        renderSimulationOfferings(context);
    });

    $(document).on("mouseenter focusin", ".pair-linked-row[data-pair-key]", function () {
        highlightPairRows(String($(this).attr("data-pair-key") || ""));
    });

    $(document).on("mouseleave focusout", ".pair-linked-row[data-pair-key]", function (event) {
        const pairKey = String($(this).attr("data-pair-key") || "");
        const relatedTarget = event.relatedTarget;

        if (relatedTarget) {
            const $nextPairRow = $(relatedTarget).closest(".pair-linked-row[data-pair-key]");
            if ($nextPairRow.length && String($nextPairRow.attr("data-pair-key") || "") === pairKey) {
                return;
            }
        }

        clearPairRows(pairKey);
    });

    $("#printSimulationBtn").on("click", function () {
        const ayId = Number($("#simulationAy option:selected").data("ay-id")) || 0;
        const semesterUi = $("#simulationSemester").val();
        const semesterNum = SEMESTER_MAP[semesterUi] || 0;

        if (!ayId || !semesterNum) {
            Swal.fire("Missing Data", "Please select academic year and semester first.", "warning");
            return;
        }

        window.open(
            `print-workload-simulations.php?ay_id=${encodeURIComponent(ayId)}&semester=${encodeURIComponent(semesterNum)}`,
            "_blank"
        );
    });

    $(document).on("click", ".btnAddSimulation", function () {
        const context = getSelectedContext();
        if (!context) {
            Swal.fire("Missing Data", "Please select faculty, A.Y., and semester first.", "warning");
            return;
        }

        const simKey = String($(this).data("simKey") || "");
        if (!simKey) {
            Swal.fire("Missing Data", "Unable to identify the selected offering.", "warning");
            return;
        }

        $.post(
            "../backend/query_add_workload_simulation.php",
            {
                faculty_id: context.facultyId,
                ay_id: context.ayId,
                semester: context.semesterNum,
                sim_key: simKey
            },
            function (response) {
                if (!response || typeof response !== "object") {
                    Swal.fire("Error", "Invalid response from server.", "error");
                    return;
                }

                if (response.status !== "success") {
                    Swal.fire("Error", response.message || "Unable to save the simulation row.", "error");
                    return;
                }

                loadSavedSimulation(context, selectionRequestToken);
                loadSimulationCatalog(context, selectionRequestToken);
            },
            "json"
        ).fail(function (xhr) {
            Swal.fire("Error", extractErrorMessage(xhr, "Unable to save the simulation row."), "error");
        });
    });

    $(document).on("click", ".remove-simulation-row", function () {
        const context = getSelectedContext();
        const simulationId = Number($(this).data("simulationId")) || 0;
        if (!context || simulationId <= 0) {
            return;
        }

        $.post(
            "../backend/query_remove_workload_simulation.php",
            { simulation_id: simulationId },
            function (response) {
                if (!response || typeof response !== "object") {
                    Swal.fire("Error", "Invalid response from server.", "error");
                    return;
                }

                if (response.status !== "success") {
                    Swal.fire("Error", response.message || "Unable to remove the simulation row.", "error");
                    return;
                }

                loadSavedSimulation(context, selectionRequestToken);
                loadSimulationCatalog(context, selectionRequestToken);
            },
            "json"
        ).fail(function (xhr) {
            Swal.fire("Error", extractErrorMessage(xhr, "Unable to remove the simulation row."), "error");
        });
    });

    $("#clearSimulationBtn").on("click", function () {
        const context = getSelectedContext();
        if (!context) {
            return;
        }

        Swal.fire({
            title: "Clear this simulation?",
            text: "This removes all saved simulation rows for the selected faculty and term.",
            icon: "warning",
            showCancelButton: true,
            confirmButtonText: "Clear Simulation",
            confirmButtonColor: "#b42318"
        }).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }

            $.post(
                "../backend/query_clear_workload_simulation.php",
                {
                    faculty_id: context.facultyId,
                    ay_id: context.ayId,
                    semester: context.semesterNum
                },
                function (response) {
                    if (!response || typeof response !== "object") {
                        Swal.fire("Error", "Invalid response from server.", "error");
                        return;
                    }

                    if (response.status !== "success") {
                        Swal.fire("Error", response.message || "Unable to clear the simulation rows.", "error");
                        return;
                    }

                    loadSavedSimulation(context, selectionRequestToken);
                    loadSimulationCatalog(context, selectionRequestToken);
                },
                "json"
            ).fail(function (xhr) {
                Swal.fire("Error", extractErrorMessage(xhr, "Unable to clear the simulation rows."), "error");
            });
        });
    });

    refreshPanels();
});
</script>
</body>
</html>
