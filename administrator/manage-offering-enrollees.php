<?php
session_start();
ob_start();

include '../backend/db.php';
require_once '../backend/academic_term_helper.php';
require_once '../backend/offering_enrollee_helper.php';

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['csrf_token'];
$currentTerm = synk_fetch_current_academic_term($conn);
$defaultAyId = (int)($currentTerm['ay_id'] ?? 0);
$defaultSemester = (int)($currentTerm['semester'] ?? 1);
$defaultSectionCapacity = synk_default_section_enrollee_count();

function synk_admin_enrollee_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function synk_admin_enrollee_title_case(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s+/', ' ', strtolower($value));
    return (string)preg_replace_callback('/(^|[\s\/-])([a-z])/', static function ($matches) {
        return $matches[1] . strtoupper($matches[2]);
    }, $value);
}

function synk_admin_enrollee_program_label(array $program): string
{
    $code = strtoupper(trim((string)($program['program_code'] ?? '')));
    $name = synk_admin_enrollee_title_case((string)($program['program_name'] ?? ''));
    $major = synk_admin_enrollee_title_case((string)($program['major'] ?? ''));

    $label = $code;
    if ($name !== '') {
        $label .= ($label !== '' ? ' - ' : '') . $name;
    }

    if ($major !== '') {
        $label .= ' (Major in ' . $major . ')';
    }

    return trim($label);
}

$campuses = [];
$campusResult = $conn->query("
    SELECT campus_id, campus_code, campus_name
    FROM tbl_campus
    WHERE status = 'active'
    ORDER BY campus_name ASC, campus_code ASC
");

while ($campusResult instanceof mysqli_result && ($row = $campusResult->fetch_assoc())) {
    $campuses[] = [
        'campus_id' => (int)$row['campus_id'],
        'campus_code' => (string)($row['campus_code'] ?? ''),
        'campus_name' => (string)($row['campus_name'] ?? ''),
        'label' => trim((string)($row['campus_code'] ?? '')) !== ''
            ? strtoupper(trim((string)$row['campus_code'])) . ' - ' . synk_admin_enrollee_title_case((string)($row['campus_name'] ?? ''))
            : synk_admin_enrollee_title_case((string)($row['campus_name'] ?? '')),
    ];
}

$colleges = [];
$collegeResult = $conn->query("
    SELECT college_id, campus_id, college_code, college_name
    FROM tbl_college
    WHERE status = 'active'
    ORDER BY college_name ASC, college_code ASC
");

while ($collegeResult instanceof mysqli_result && ($row = $collegeResult->fetch_assoc())) {
    $colleges[] = [
        'college_id' => (int)$row['college_id'],
        'campus_id' => (int)$row['campus_id'],
        'college_code' => (string)($row['college_code'] ?? ''),
        'college_name' => (string)($row['college_name'] ?? ''),
        'label' => trim((string)($row['college_code'] ?? '')) !== ''
            ? strtoupper(trim((string)$row['college_code'])) . ' - ' . synk_admin_enrollee_title_case((string)($row['college_name'] ?? ''))
            : synk_admin_enrollee_title_case((string)($row['college_name'] ?? '')),
    ];
}

$programs = [];
$programResult = $conn->query("
    SELECT program_id, college_id, program_code, program_name, COALESCE(major, '') AS major
    FROM tbl_program
    WHERE status = 'active'
    ORDER BY program_code ASC, program_name ASC, major ASC
");

while ($programResult instanceof mysqli_result && ($row = $programResult->fetch_assoc())) {
    $programs[] = [
        'program_id' => (int)$row['program_id'],
        'college_id' => (int)$row['college_id'],
        'program_code' => (string)($row['program_code'] ?? ''),
        'program_name' => (string)($row['program_name'] ?? ''),
        'major' => (string)($row['major'] ?? ''),
        'label' => synk_admin_enrollee_program_label($row),
    ];
}

$academicYears = [];
$ayResult = $conn->query("
    SELECT ay_id, ay
    FROM tbl_academic_years
    ORDER BY ay_id DESC
");

while ($ayResult instanceof mysqli_result && ($row = $ayResult->fetch_assoc())) {
    $academicYears[] = [
        'ay_id' => (int)$row['ay_id'],
        'ay' => (string)($row['ay'] ?? ''),
    ];
}

$defaultCampusId = (int)($campuses[0]['campus_id'] ?? 0);
$defaultCollegeId = 0;
foreach ($colleges as $college) {
    if ((int)$college['campus_id'] === $defaultCampusId) {
        $defaultCollegeId = (int)$college['college_id'];
        break;
    }
}

$pageData = [
    'csrfToken' => $csrfToken,
    'campuses' => $campuses,
    'colleges' => $colleges,
    'programs' => $programs,
    'defaultCampusId' => $defaultCampusId,
    'defaultCollegeId' => $defaultCollegeId,
    'defaultAyId' => $defaultAyId,
    'defaultSemester' => $defaultSemester,
];
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <title>Offering Enrollees | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
        .oe-toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: end;
        }

        .oe-toolbar-field {
            min-width: 180px;
        }

        .oe-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 1rem;
        }

        .oe-summary-card {
            border: 1px solid rgba(67, 89, 113, 0.12);
            border-radius: 1rem;
            padding: 1rem 1.1rem;
            background: linear-gradient(180deg, rgba(245, 247, 255, 0.92), rgba(255, 255, 255, 0.98));
        }

        .oe-summary-label {
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            color: #7b8ba4;
            margin-bottom: 0.35rem;
        }

        .oe-summary-value {
            font-size: 1.45rem;
            font-weight: 700;
            color: #435971;
            line-height: 1.1;
        }

        .oe-summary-subtext {
            margin-top: 0.3rem;
            color: #8592a3;
            font-size: 0.83rem;
        }

        .oe-table-wrap {
            max-height: 68vh;
            overflow: auto;
        }

        .oe-table thead th {
            position: sticky;
            top: 0;
            z-index: 2;
            background: #fff;
            font-size: 0.74rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #5f728b;
            white-space: nowrap;
        }

        .oe-table td,
        .oe-table th {
            vertical-align: middle;
        }

        .oe-program {
            color: #566a7f;
            font-size: 0.83rem;
        }

        .oe-course-code {
            font-weight: 700;
            color: #435971;
            white-space: nowrap;
        }

        .oe-course-desc {
            color: #566a7f;
        }

        .oe-input {
            min-width: 120px;
            max-width: 140px;
            text-align: right;
        }

        .oe-status-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.7rem;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 600;
        }

        .oe-status-chip.ready {
            background: rgba(113, 221, 55, 0.12);
            color: #477a10;
        }

        .oe-status-chip.missing {
            background: rgba(255, 62, 29, 0.12);
            color: #b42318;
        }

        .oe-empty-row td {
            padding-top: 2rem;
            padding-bottom: 2rem;
            color: #8592a3;
        }

        .oe-loader {
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            color: #6f7f95;
            font-weight: 600;
        }

        .oe-loader .spinner-border {
            width: 1rem;
            height: 1rem;
        }

        @media (max-width: 991.98px) {
            .oe-summary-grid {
                grid-template-columns: 1fr;
            }
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
          <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
            <div>
              <h4 class="fw-bold mb-1"><i class="bx bx-group me-2"></i> Offering Enrollees</h4>
              <p class="text-muted mb-0">Set temporary section-based dummy enrollee counts for generated offerings per college, program, academic year, and semester.</p>
            </div>
            <div id="storageStatusChip" class="oe-status-chip missing">
              <i class="bx bx-error-circle"></i>
              <span>Storage table required</span>
            </div>
          </div>

          <div class="card mb-4">
            <div class="card-body">
              <div class="alert alert-info mb-0">
                Counts are managed per section. Unsaved sections default to <?= synk_admin_enrollee_h($defaultSectionCapacity); ?> students, editing one offering updates the same headcount across that section, and saved values are still written per generated offering for workload, room, and reporting pages.
              </div>
            </div>
          </div>

          <div class="card mb-4">
            <div class="card-header">
              <h5 class="m-0">Scope Filters</h5>
              <small class="text-muted">Load generated offerings first, then adjust the shared section headcount for each distinct section.</small>
            </div>
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-3">
                  <label class="form-label">Campus</label>
                  <select id="oeCampus" class="form-select"></select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">College</label>
                  <select id="oeCollege" class="form-select"></select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Program</label>
                  <select id="oeProgram" class="form-select"></select>
                </div>
                <div class="col-md-2">
                  <label class="form-label">A.Y.</label>
                  <select id="oeAy" class="form-select">
                    <?php foreach ($academicYears as $academicYear): ?>
                      <option value="<?= (int)$academicYear['ay_id']; ?>"<?= (int)$academicYear['ay_id'] === $defaultAyId ? ' selected' : ''; ?>>
                        <?= synk_admin_enrollee_h($academicYear['ay']); ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-1">
                  <label class="form-label">Semester</label>
                  <select id="oeSemester" class="form-select">
                    <option value="1"<?= $defaultSemester === 1 ? ' selected' : ''; ?>>1st</option>
                    <option value="2"<?= $defaultSemester === 2 ? ' selected' : ''; ?>>2nd</option>
                    <option value="3"<?= $defaultSemester === 3 ? ' selected' : ''; ?>>Mid</option>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <div class="card mb-4">
            <div class="card-header d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
              <div>
                <h5 class="m-0">Bulk Enrollee Tools</h5>
                <small class="text-muted">Use the current program filter to apply one shared section headcount across all loaded sections quickly.</small>
              </div>
              <div class="oe-toolbar">
                <div class="oe-toolbar-field">
                  <label class="form-label mb-1">Section Headcount</label>
                  <input type="number" min="0" step="1" id="oeBulkCount" class="form-control" placeholder="e.g. <?= (int)$defaultSectionCapacity; ?>">
                </div>
                <button id="oeApplyBulk" type="button" class="btn btn-outline-primary">
                  <i class="bx bx-spreadsheet me-1"></i> Apply to Loaded Sections
                </button>
                <button id="oeSaveAll" type="button" class="btn btn-primary">
                  <i class="bx bx-save me-1"></i> Save All
                </button>
              </div>
            </div>
          </div>

          <div class="oe-summary-grid mb-4">
            <div class="oe-summary-card">
              <div class="oe-summary-label">Loaded Offerings</div>
              <div class="oe-summary-value" id="oeSummaryOfferings">0</div>
              <div class="oe-summary-subtext">Generated subject offerings in the selected scope</div>
            </div>
            <div class="oe-summary-card">
              <div class="oe-summary-label">Distinct Sections</div>
              <div class="oe-summary-value" id="oeSummarySections">0</div>
              <div class="oe-summary-subtext">Unique sections represented by the loaded offerings</div>
            </div>
            <div class="oe-summary-card">
              <div class="oe-summary-label">Programs In Scope</div>
              <div class="oe-summary-value" id="oeSummaryPrograms">0</div>
              <div class="oe-summary-subtext">Affected programs under the current filter</div>
            </div>
            <div class="oe-summary-card">
              <div class="oe-summary-label">Estimated Students</div>
              <div class="oe-summary-value" id="oeSummaryEnrollees">0</div>
              <div class="oe-summary-subtext">Distinct-section headcount, not offering rows multiplied</div>
            </div>
          </div>

          <div class="card">
            <div class="card-header d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-2">
              <div>
                <h5 class="m-0">Generated Offerings</h5>
                <small class="text-muted">Only offerings already generated in <code>tbl_prospectus_offering</code> are listed here. Section headcount repeats per subject row, but the student total counts each section once.</small>
              </div>
              <small class="text-muted" id="oeScopeLabel">Choose a scope to load offerings.</small>
            </div>
            <div class="card-body p-0">
              <div id="oeTableWrap" class="oe-table-wrap">
                <div class="p-4 text-center text-muted">Loading offering scope...</div>
              </div>
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

<script src="../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../assets/vendor/libs/popper/popper.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script>
<script src="../assets/js/main.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const pageData = <?= json_encode($pageData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

$(function () {
    const campuses = Array.isArray(pageData.campuses) ? pageData.campuses : [];
    const colleges = Array.isArray(pageData.colleges) ? pageData.colleges : [];
    const programs = Array.isArray(pageData.programs) ? pageData.programs : [];
    const csrfToken = String(pageData.csrfToken || "");

    const $campus = $("#oeCampus");
    const $college = $("#oeCollege");
    const $program = $("#oeProgram");
    const $ay = $("#oeAy");
    const $semester = $("#oeSemester");
    const $tableWrap = $("#oeTableWrap");
    const $scopeLabel = $("#oeScopeLabel");
    const $saveButton = $("#oeSaveAll");
    const $applyBulkButton = $("#oeApplyBulk");
    const $bulkCount = $("#oeBulkCount");
    const $storageChip = $("#storageStatusChip");

    let loadedRows = [];
    let activeRequest = null;
    let storageReady = false;

    function escapeHtml(value) {
        return $("<div>").text(value == null ? "" : String(value)).html();
    }

    function toNumber(value) {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function formatNumber(value) {
        return String(Math.max(0, Math.round(toNumber(value))));
    }

    function normalizeEnrolleeValue(value) {
        return Math.max(0, Math.round(toNumber(value)));
    }

    function semesterLabel(value) {
        if (Number(value) === 1) return "1st Semester";
        if (Number(value) === 2) return "2nd Semester";
        if (Number(value) === 3) return "Midyear";
        return "";
    }

    function getSelectedCampusId() {
        return Number($campus.val()) || 0;
    }

    function getSelectedCollegeId() {
        return Number($college.val()) || 0;
    }

    function getSelectedProgramId() {
        return Number($program.val()) || 0;
    }

    function getCurrentScope() {
        const collegeId = getSelectedCollegeId();
        const ayId = Number($ay.val()) || 0;
        const semester = Number($semester.val()) || 0;

        if (!collegeId || !ayId || !semester) {
            return null;
        }

        return {
            campus_id: getSelectedCampusId(),
            college_id: collegeId,
            program_id: getSelectedProgramId(),
            ay_id: ayId,
            semester: semester
        };
    }

    function getCollegeOptions(campusId) {
        return colleges.filter(function (collegeItem) {
            return Number(collegeItem.campus_id) === Number(campusId);
        });
    }

    function getProgramOptions(collegeId) {
        return programs.filter(function (programItem) {
            return Number(programItem.college_id) === Number(collegeId);
        });
    }

    function updateStorageChip(isReady) {
        storageReady = !!isReady;
        $storageChip
            .toggleClass("ready", storageReady)
            .toggleClass("missing", !storageReady)
            .html(storageReady
                ? '<i class="bx bx-check-circle"></i><span>Storage ready</span>'
                : '<i class="bx bx-error-circle"></i><span>Storage table required</span>');

        $saveButton.prop("disabled", !storageReady || loadedRows.length === 0);
    }

    function renderCampusOptions() {
        const options = campuses.map(function (campusItem) {
            return `<option value="${escapeHtml(campusItem.campus_id)}">${escapeHtml(campusItem.label)}</option>`;
        }).join("");

        $campus.html(options);
        if (pageData.defaultCampusId) {
            $campus.val(String(pageData.defaultCampusId));
        }
    }

    function renderCollegeOptions() {
        const campusId = getSelectedCampusId();
        const collegeOptions = getCollegeOptions(campusId);
        const currentCollegeId = getSelectedCollegeId();

        const options = collegeOptions.map(function (collegeItem) {
            return `<option value="${escapeHtml(collegeItem.college_id)}">${escapeHtml(collegeItem.label)}</option>`;
        }).join("");

        $college.html(options);

        const keepCurrent = collegeOptions.some(function (collegeItem) {
            return Number(collegeItem.college_id) === Number(currentCollegeId);
        });

        if (keepCurrent) {
            $college.val(String(currentCollegeId));
        } else if (pageData.defaultCollegeId && collegeOptions.some(function (collegeItem) {
            return Number(collegeItem.college_id) === Number(pageData.defaultCollegeId);
        })) {
            $college.val(String(pageData.defaultCollegeId));
        } else {
            $college.val(collegeOptions.length ? String(collegeOptions[0].college_id) : "");
        }
    }

    function renderProgramOptions() {
        const collegeId = getSelectedCollegeId();
        const programOptions = getProgramOptions(collegeId);
        const currentProgramId = getSelectedProgramId();

        let options = '<option value="0">All Programs</option>';
        options += programOptions.map(function (programItem) {
            return `<option value="${escapeHtml(programItem.program_id)}">${escapeHtml(programItem.label)}</option>`;
        }).join("");

        $program.html(options);

        const keepCurrent = programOptions.some(function (programItem) {
            return Number(programItem.program_id) === Number(currentProgramId);
        });

        $program.val(keepCurrent ? String(currentProgramId) : "0");
    }

    function setSummary(summary) {
        $("#oeSummaryOfferings").text(formatNumber(summary && summary.total_offerings));
        $("#oeSummarySections").text(formatNumber(summary && summary.total_sections));
        $("#oeSummaryPrograms").text(formatNumber(summary && summary.programs_in_scope));
        $("#oeSummaryEnrollees").text(formatNumber(summary && summary.total_dummy_enrollees));
    }

    function setTableLoading(message) {
        loadedRows = [];
        setSummary({ total_offerings: 0, total_sections: 0, programs_in_scope: 0, total_dummy_enrollees: 0 });
        $saveButton.prop("disabled", true);
        $applyBulkButton.prop("disabled", true);
        $tableWrap.html(`
            <div class="p-4 text-center">
                <div class="oe-loader">
                    <div class="spinner-border text-primary" role="status"></div>
                    <span>${escapeHtml(message || "Loading offerings...")}</span>
                </div>
            </div>
        `);
    }

    function updateScopeLabel() {
        const collegeText = $college.find("option:selected").text().trim();
        const programText = $program.find("option:selected").text().trim();
        const ayText = $ay.find("option:selected").text().trim();
        const semesterText = semesterLabel($semester.val());

        if (!collegeText || !ayText || !semesterText) {
            $scopeLabel.text("Choose a scope to load offerings.");
            return;
        }

        const label = programText && programText !== "All Programs"
            ? `${collegeText} | ${programText} | ${semesterText}, AY ${ayText}`
            : `${collegeText} | ${semesterText}, AY ${ayText}`;

        $scopeLabel.text(label);
    }

    function renderTableRows(rows) {
        if (!rows.length) {
            $tableWrap.html(`
                <table class="table table-hover mb-0 oe-table">
                    <thead>
                        <tr>
                            <th>Program</th>
                            <th>Course</th>
                            <th>Course No.</th>
                            <th>Course Description</th>
                            <th class="text-end">Section Headcount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="oe-empty-row">
                            <td colspan="5" class="text-center">No generated offerings found for the selected scope.</td>
                        </tr>
                    </tbody>
                </table>
            `);
            return;
        }

        const rowsHtml = rows.map(function (row) {
            const sectionKey = getSectionKey(row);
            return `
                <tr data-offering-id="${escapeHtml(row.offering_id)}" data-section-key="${escapeHtml(sectionKey)}">
                    <td><div class="oe-program">${escapeHtml(row.program_label || "")}</div></td>
                    <td>${escapeHtml(row.course || row.section_name || "")}</td>
                    <td class="oe-course-code">${escapeHtml(row.sub_code || "")}</td>
                    <td class="oe-course-desc">${escapeHtml(row.sub_description || "")}</td>
                    <td class="text-end">
                        <input
                            type="number"
                            min="0"
                            step="1"
                            class="form-control oe-input ms-auto enrollee-input"
                            data-offering-id="${escapeHtml(row.offering_id)}"
                            data-section-key="${escapeHtml(sectionKey)}"
                            value="${escapeHtml(formatNumber(row.total_enrollees || 0))}"
                        >
                    </td>
                </tr>
            `;
        }).join("");

        $tableWrap.html(`
            <table class="table table-hover mb-0 oe-table">
                <thead>
                    <tr>
                        <th>Program</th>
                        <th>Course</th>
                        <th>Course No.</th>
                        <th>Course Description</th>
                        <th class="text-end">Section Headcount</th>
                    </tr>
                </thead>
                <tbody>${rowsHtml}</tbody>
            </table>
        `);
    }

    function getSectionKey(row) {
        const rawKey = String(row && row.section_key || "").trim();
        if (rawKey !== "") {
            return rawKey;
        }

        const sectionId = Number(row && row.section_id) || 0;
        if (sectionId > 0) {
            return `section:${sectionId}`;
        }

        return `offering:${Number(row && row.offering_id) || 0}`;
    }

    function collectSectionMetrics(rows) {
        const sectionCountMap = {};

        (Array.isArray(rows) ? rows : []).forEach(function (row) {
            const sectionKey = getSectionKey(row);
            const count = normalizeEnrolleeValue(row && row.total_enrollees);

            if (!Object.prototype.hasOwnProperty.call(sectionCountMap, sectionKey)) {
                sectionCountMap[sectionKey] = count;
                return;
            }

            sectionCountMap[sectionKey] = Math.max(sectionCountMap[sectionKey], count);
        });

        return {
            total_sections: Object.keys(sectionCountMap).length,
            total_dummy_enrollees: Object.values(sectionCountMap).reduce(function (sum, count) {
                return sum + normalizeEnrolleeValue(count);
            }, 0)
        };
    }

    function hydrateRows(rows) {
        loadedRows = Array.isArray(rows) ? rows.map(function (row) {
            return Object.assign({}, row, {
                offering_id: Number(row.offering_id) || 0,
                program_id: Number(row.program_id) || 0,
                section_id: Number(row.section_id) || 0,
                section_key: String(row.section_key || "").trim(),
                total_enrollees: normalizeEnrolleeValue(row.total_enrollees)
            });
        }) : [];

        renderTableRows(loadedRows);
        $applyBulkButton.prop("disabled", loadedRows.length === 0);
        $saveButton.prop("disabled", !storageReady || loadedRows.length === 0);
    }

    function syncRowDataFromInputs() {
        const sectionMap = {};
        $tableWrap.find(".enrollee-input").each(function () {
            const sectionKey = String($(this).data("section-key") || "").trim();
            if (sectionKey === "") {
                return;
            }

            sectionMap[sectionKey] = normalizeEnrolleeValue($(this).val());
        });

        loadedRows = loadedRows.map(function (row) {
            const sectionKey = getSectionKey(row);
            if (Object.prototype.hasOwnProperty.call(sectionMap, sectionKey)) {
                row.total_enrollees = sectionMap[sectionKey];
            }
            return row;
        });
    }

    function applySectionCount(sectionKey, value) {
        const normalizedValue = normalizeEnrolleeValue(value);

        $tableWrap.find(".enrollee-input").each(function () {
            if (String($(this).data("section-key") || "").trim() === sectionKey) {
                $(this).val(String(normalizedValue));
            }
        });

        loadedRows = loadedRows.map(function (row) {
            if (getSectionKey(row) === sectionKey) {
                row.total_enrollees = normalizedValue;
            }
            return row;
        });
    }

    function refreshSummaryFromRows(serverSummary) {
        if (!loadedRows.length) {
            setSummary(serverSummary || {});
            return;
        }

        const sectionMetrics = collectSectionMetrics(loadedRows);
        setSummary({
            total_offerings: loadedRows.length,
            total_sections: sectionMetrics.total_sections,
            programs_in_scope: new Set(loadedRows.map(function (row) {
                return Number(row.program_id) || 0;
            })).size,
            total_dummy_enrollees: sectionMetrics.total_dummy_enrollees
        });
    }

    function loadOfferings() {
        updateScopeLabel();

        const scope = getCurrentScope();
        if (!scope) {
            setTableLoading("Select a complete scope first.");
            return;
        }

        if (activeRequest && activeRequest.readyState !== 4) {
            activeRequest.abort();
        }

        setTableLoading("Loading generated offerings...");

        activeRequest = $.ajax({
            url: "../backend/query_admin_offering_enrollees.php",
            type: "POST",
            dataType: "json",
            data: Object.assign({
                action: "load_offerings"
            }, scope)
        }).done(function (response) {
            if (!response || response.status !== "success") {
                updateStorageChip(false);
                $tableWrap.html(`<div class="p-4 text-center text-danger">${escapeHtml(response && response.message ? response.message : "Failed to load offerings.")}</div>`);
                return;
            }

            updateStorageChip(!!response.storage_ready);
            hydrateRows(response.rows || []);
            refreshSummaryFromRows(response.summary || {});
        }).fail(function (xhr, status) {
            if (status === "abort") {
                return;
            }

            updateStorageChip(false);
            $tableWrap.html('<div class="p-4 text-center text-danger">Failed to load offerings.</div>');
        });
    }

    function collectEntries() {
        syncRowDataFromInputs();
        return loadedRows.map(function (row) {
            return {
                offering_id: Number(row.offering_id) || 0,
                total_enrollees: Math.max(0, Math.round(toNumber(row.total_enrollees)))
            };
        });
    }

    function applyBulkCount() {
        if (!loadedRows.length) {
            return;
        }

        const bulkValue = normalizeEnrolleeValue($bulkCount.val());
        $tableWrap.find(".enrollee-input").val(String(bulkValue));
        loadedRows = loadedRows.map(function (row) {
            row.total_enrollees = bulkValue;
            return row;
        });
        refreshSummaryFromRows();
    }

    function saveAll() {
        const scope = getCurrentScope();
        if (!scope) {
            Swal.fire("Missing Scope", "Select a valid college, A.Y., and semester first.", "warning");
            return;
        }

        if (!storageReady) {
            Swal.fire("Storage Required", "Create the enrollee table first before saving dummy counts.", "warning");
            return;
        }

        const entries = collectEntries();
        $saveButton.prop("disabled", true);

        $.ajax({
            url: "../backend/query_admin_offering_enrollees.php",
            type: "POST",
            dataType: "json",
            data: Object.assign({
                action: "save_counts",
                csrf_token: csrfToken,
                entries_json: JSON.stringify(entries)
            }, scope)
        }).done(function (response) {
            if (!response || response.status !== "success") {
                Swal.fire("Save Failed", response && response.message ? response.message : "Please try again later.", "error");
                return;
            }

            updateStorageChip(!!response.storage_ready);
            hydrateRows(response.rows || []);
            refreshSummaryFromRows(response.summary || {});

            Swal.fire("Saved", response.message || "Dummy enrollee counts were saved.", "success");
        }).fail(function () {
            Swal.fire("Save Failed", "Could not save dummy enrollee counts right now.", "error");
        }).always(function () {
            $saveButton.prop("disabled", !storageReady || loadedRows.length === 0);
        });
    }

    renderCampusOptions();
    renderCollegeOptions();
    renderProgramOptions();
    updateScopeLabel();
    loadOfferings();

    $campus.on("change", function () {
        renderCollegeOptions();
        renderProgramOptions();
        loadOfferings();
    });

    $college.on("change", function () {
        renderProgramOptions();
        loadOfferings();
    });

    $program.on("change", loadOfferings);
    $ay.on("change", loadOfferings);
    $semester.on("change", loadOfferings);
    $applyBulkButton.on("click", applyBulkCount);
    $saveButton.on("click", saveAll);

    $tableWrap.on("input", ".enrollee-input", function () {
        const sectionKey = String($(this).data("section-key") || "").trim();
        applySectionCount(sectionKey, $(this).val());
        refreshSummaryFromRows();
    });
});
</script>
</body>
</html>
