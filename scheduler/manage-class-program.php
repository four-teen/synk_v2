<?php
session_start();
ob_start();
include '../backend/db.php';
require_once '../backend/academic_term_helper.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'scheduler') {
    header("Location: ../index.php");
    exit;
}

$college_name = $_SESSION['college_name'] ?? '';
$currentTerm = synk_fetch_current_academic_term($conn);
$defaultAyLabel = (string)($currentTerm['ay_label'] ?? '');
$defaultSemesterUi = '';

if ((int)($currentTerm['semester'] ?? 0) === 1) {
    $defaultSemesterUi = '1st';
} elseif ((int)($currentTerm['semester'] ?? 0) === 2) {
    $defaultSemesterUi = '2nd';
} elseif ((int)($currentTerm['semester'] ?? 0) === 3) {
    $defaultSemesterUi = 'Midyear';
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
    <title>Class Program | Synk Scheduler</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

<style>
    .select2-container--default .select2-selection--single {
        height: 45px !important;
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

    .select2-selection__rendered {
        line-height: 42px !important;
    }

    .cp-sheet-title {
        font-size: 1.35rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        color: #243a54;
        text-transform: uppercase;
    }

    .cp-term-label {
        margin-top: 0.15rem;
        font-size: 0.95rem;
        color: #667b92;
    }

    .cp-meta-list {
        margin-top: 1rem;
        display: grid;
        gap: 0.35rem;
    }

    .cp-meta-line {
        font-size: 0.95rem;
        color: #304560;
    }

    .cp-meta-key {
        font-weight: 700;
    }

    .cp-sheet {
        border: 1px solid #d9e2ec;
        border-radius: 12px;
        overflow: hidden;
        background: #fff;
    }

    .cp-sheet-table {
        width: 100%;
        margin-bottom: 0;
        table-layout: fixed;
    }

    .cp-sheet-table thead th {
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        text-align: center;
        color: #4d627a;
        background: #f9fbfd;
        border-color: #cfd8e3;
        vertical-align: middle;
    }

    .cp-sheet-table tbody td {
        border-color: #d9e2ec;
        color: #576c85;
        vertical-align: top;
        background: #fff;
        padding: 0.55rem 0.6rem;
    }

    .cp-sheet-table th:first-child,
    .cp-sheet-table td:first-child {
        width: 15%;
    }

    .cp-sheet-table th:not(:first-child),
    .cp-sheet-table td:not(:first-child) {
        width: 14.16%;
    }

    .cp-time-cell {
        white-space: nowrap;
        font-weight: 600;
        color: #334a63;
        font-size: 0.82rem;
        background: #fbfcfe;
    }

    .cp-empty-cell {
        background: #fff;
        min-height: 48px;
    }

    .cp-class-cell {
        background: #fbfdff !important;
    }

    .cp-class-block {
        min-height: 100%;
    }

    .cp-subject-code {
        font-size: 0.88rem;
        font-weight: 700;
        color: #253a53;
        line-height: 1.25;
    }

    .cp-subject-description {
        margin-top: 0.2rem;
        font-size: 0.72rem;
        line-height: 1.25;
        color: #60768f;
    }

    .cp-block-line {
        margin-top: 0.2rem;
        font-size: 0.72rem;
        line-height: 1.25;
        color: #3d546d;
    }

    .cp-block-chip {
        display: inline-block;
        margin-top: 0.35rem;
        padding: 0.16rem 0.42rem;
        border-radius: 999px;
        background: #eef4ff;
        color: #3d63dd;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.05em;
    }

    .cp-warning-banner {
        margin-bottom: 1rem;
        border: 1px solid #ffe3a6;
        border-radius: 10px;
        background: #fff8e6;
        color: #7a5a00;
        padding: 0.75rem 0.9rem;
        font-size: 0.84rem;
    }

    .cp-empty-state {
        padding: 2.5rem 1rem;
        text-align: center;
        color: #73859b;
        font-weight: 500;
    }

    .cp-loader {
        position: fixed;
        inset: 0;
        background: rgba(255, 255, 255, 0.85);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 2000;
    }

    .cp-loader-box {
        text-align: center;
    }

    .cp-print-trigger {
        white-space: nowrap;
    }

    @media (max-width: 767.98px) {
        #classProgramCard .card-header {
            flex-direction: column;
            align-items: stretch !important;
        }

        .cp-print-trigger {
            width: 100%;
        }
    }

    @media print {
        @page {
            margin: 0.45in;
        }

        body {
            background: #fff !important;
        }

        .layout-menu,
        .layout-navbar,
        footer,
        .cp-no-print,
        #cpLoader {
            display: none !important;
        }

        .layout-wrapper,
        .layout-container,
        .layout-page,
        .content-wrapper,
        .container-xxl {
            margin: 0 !important;
            padding: 0 !important;
            background: #fff !important;
        }

        #classProgramCard {
            display: block !important;
            border: 0 !important;
            box-shadow: none !important;
            margin: 0 !important;
        }

        #classProgramCard .card-header {
            border: 0 !important;
            padding: 0 0 12px !important;
        }

        #classProgramCard .card-body {
            padding: 0 !important;
        }

        .cp-sheet-title,
        .cp-term-label,
        .cp-meta-line {
            color: #000 !important;
        }

        .cp-sheet {
            border: 0 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
        }

        .cp-sheet-table thead th,
        .cp-sheet-table tbody td {
            color: #000 !important;
            border-color: #000 !important;
        }

        .cp-warning-banner {
            display: none !important;
        }

        .cp-print-trigger {
            display: none !important;
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

    <h4 class="fw-bold mb-3">
        <i class="bx bx-table me-2"></i>
        Class Program
        <small class="text-muted">(<?= htmlspecialchars($college_name) ?>)</small>
    </h4>

    <div class="card mb-4 cp-no-print">
        <div class="card-header">
            <h5 class="m-0">Filter Class Program</h5>
            <small class="text-muted">Select academic year, semester, and section to view and print the class program.</small>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">A.Y.</label>
                    <select id="cp_ay" class="form-select select2-single">
                        <option value="">Select A.Y.</option>
                        <?php
                        $ay = mysqli_query($conn, "SELECT ay FROM tbl_academic_years ORDER BY ay ASC");
                        while ($r = mysqli_fetch_assoc($ay)) {
                            $ayval = htmlspecialchars($r['ay']);
                            $selected = ($r['ay'] === $defaultAyLabel) ? " selected" : "";
                            echo "<option value='{$ayval}'{$selected}>{$ayval}</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Semester</label>
                    <select id="cp_semester" class="form-select">
                        <option value="1st"<?= $defaultSemesterUi === '1st' ? ' selected' : '' ?>>1st Semester</option>
                        <option value="2nd"<?= $defaultSemesterUi === '2nd' ? ' selected' : '' ?>>2nd Semester</option>
                        <option value="Midyear"<?= $defaultSemesterUi === 'Midyear' ? ' selected' : '' ?>>Midyear</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Section</label>
                    <select id="cp_section_id" class="form-select select2-single">
                        <option value="">Select Section</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card" id="classProgramCard" style="display:none;">
        <div class="card-header d-flex justify-content-between align-items-start gap-3">
            <div>
                <div class="cp-sheet-title">Class Program</div>
                <div class="cp-term-label" id="cpTermLabel"></div>
                <div class="cp-meta-list">
                    <div class="cp-meta-line"><span class="cp-meta-key">College:</span> <span id="cpCollegeValue">-</span></div>
                    <div class="cp-meta-line"><span class="cp-meta-key">Course:</span> <span id="cpCourseValue">-</span></div>
                    <div class="cp-meta-line"><span class="cp-meta-key">Section:</span> <span id="cpSectionValue">-</span></div>
                    <div class="cp-meta-line"><span class="cp-meta-key">Room/s:</span> <span id="cpRoomsValue">-</span></div>
                </div>
            </div>
            <button type="button" class="btn btn-outline-primary cp-print-trigger" id="btnPrintClassProgram" disabled>
                <i class="bx bx-printer me-1"></i>Print Program
            </button>
        </div>
        <div class="card-body">
            <div id="classProgramWrapper"></div>
        </div>
    </div>

</div>

<?php include '../footer.php'; ?>

<div id="cpLoader" class="cp-loader d-none">
    <div class="cp-loader-box">
        <div class="spinner-border text-primary mb-2" role="status"></div>
        <div class="small fw-semibold" id="cpLoaderText">Loading class program...</div>
    </div>
</div>

</div>
</div>
</div>

    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>

<script>
$(document).ready(function () {
    const DAY_START_MINUTES = 7 * 60;
    const DAY_END_MINUTES = 18 * 60;
    const SLOT_INTERVAL_MINUTES = 30;
    const DAY_COLUMNS = [
        { key: "M", label: "Mon" },
        { key: "T", label: "Tue" },
        { key: "W", label: "Wed" },
        { key: "TH", label: "Thu" },
        { key: "F", label: "Fri" },
        { key: "S", label: "Sat" }
    ];
    const DAY_ORDER = {
        M: 1,
        T: 2,
        W: 3,
        TH: 4,
        F: 5,
        S: 6
    };

    let loaderCount = 0;
    let sectionOptionsRequest = null;
    let sectionScheduleRequest = null;

    if ($.fn.select2) {
        $(".select2-single").select2({
            width: "100%"
        });
    }

    function escapeHtml(value) {
        return $("<div>").text(value == null ? "" : String(value)).html();
    }

    function createResolvedPromise() {
        const deferred = $.Deferred();
        deferred.resolve();
        return deferred.promise();
    }

    function showLoader(message) {
        loaderCount += 1;
        $("#cpLoaderText").text(message || "Loading class program...");
        $("#cpLoader").removeClass("d-none");
    }

    function hideLoader() {
        loaderCount = Math.max(0, loaderCount - 1);
        if (loaderCount === 0) {
            $("#cpLoader").addClass("d-none");
        }
    }

    function setPrintState(enabled) {
        $("#btnPrintClassProgram").prop("disabled", !enabled);
    }

    function formatSemesterLabel(semester) {
        if (!semester) {
            return "";
        }

        return semester === "Midyear" ? "Midyear" : semester + " Semester";
    }

    function toDisplayCase(value) {
        const normalized = String(value || "").trim().toLowerCase();
        if (!normalized) {
            return "";
        }

        return normalized.replace(/\b([a-z])/g, function (_, char) {
            return char.toUpperCase();
        });
    }

    function formatProgramDisplay(meta) {
        const code = String(meta.program_code || "").trim().toUpperCase();
        const programName = toDisplayCase(meta.program_name || "");
        const programMajor = toDisplayCase(meta.program_major || "");
        let label = [code, programName].filter(Boolean).join(" - ");

        if (programMajor) {
            label += (label ? " " : "") + `(Major in ${programMajor})`;
        }

        return label || "-";
    }

    function updateHeader(meta, ay, semester) {
        const payload = meta || {};
        const termParts = [];

        if (semester) {
            termParts.push(formatSemesterLabel(semester));
        }

        if (ay) {
            termParts.push("School Year " + ay);
        }

        $("#cpTermLabel").text(termParts.join(" | "));
        $("#cpCollegeValue").text(payload.college_name || "-");
        $("#cpCourseValue").text(formatProgramDisplay(payload));
        $("#cpSectionValue").text(payload.full_section || "-");
        $("#cpRoomsValue").text(payload.rooms_text || "-");
    }

    function showClassProgramMessage(message) {
        const sectionText = $("#cp_section_id").val() ? $("#cp_section_id option:selected").text() : "";

        $("#classProgramCard").show();
        updateHeader({ full_section: sectionText }, $("#cp_ay").val(), $("#cp_semester").val());
        setPrintState(false);
        $("#classProgramWrapper").html(
            `<div class="cp-empty-state">${escapeHtml(message)}</div>`
        );
    }

    function timeToMinutes(time) {
        const parts = String(time || "").split(":").map(Number);
        return ((parts[0] || 0) * 60) + (parts[1] || 0);
    }

    function minutesToAMPM(minutes) {
        const normalized = Math.max(0, Number(minutes) || 0);
        let hour = Math.floor(normalized / 60);
        const minute = normalized % 60;
        const period = hour >= 12 ? "PM" : "AM";
        hour = hour % 12 || 12;
        return `${hour}:${String(minute).padStart(2, "0")} ${period}`;
    }

    function formatTimeRange(startMinutes, endMinutes) {
        return `${minutesToAMPM(startMinutes)} - ${minutesToAMPM(endMinutes)}`;
    }

    function normalizeDayToken(day) {
        const token = String(day || "").toUpperCase().trim();
        return token === "TH" ? "TH" : token;
    }

    function normalizeDays(daysArr) {
        const tokens = (Array.isArray(daysArr) ? daysArr : [])
            .map(normalizeDayToken)
            .filter(function (token) {
                return DAY_ORDER[token];
            })
            .filter(function (token, index, array) {
                return array.indexOf(token) === index;
            });

        tokens.sort(function (left, right) {
            return (DAY_ORDER[left] || 99) - (DAY_ORDER[right] || 99);
        });

        return tokens;
    }

    function buildScheduleMatrix(rows) {
        const slots = [];
        const occupancy = {};
        const warnings = [];

        for (let minutes = DAY_START_MINUTES; minutes < DAY_END_MINUTES; minutes += SLOT_INTERVAL_MINUTES) {
            slots.push(minutes);
        }

        DAY_COLUMNS.forEach(function (day) {
            occupancy[day.key] = {};
        });

        (Array.isArray(rows) ? rows : []).forEach(function (row) {
            let start = timeToMinutes(String(row.time_start || "").substring(0, 5));
            let end = timeToMinutes(String(row.time_end || "").substring(0, 5));
            const days = normalizeDays(row.days_raw);

            start = Math.max(DAY_START_MINUTES, start);
            end = Math.min(DAY_END_MINUTES, end);

            if (!days.length || end <= start) {
                return;
            }

            let hasConflict = false;
            days.forEach(function (dayKey) {
                for (let cursor = start; cursor < end; cursor += SLOT_INTERVAL_MINUTES) {
                    if (occupancy[dayKey][cursor]) {
                        hasConflict = true;
                        return;
                    }
                }
            });

            if (hasConflict) {
                const subjectCode = String(row.subject_code || "Scheduled class").trim() || "Scheduled class";
                warnings.push(`${subjectCode} overlaps an existing cell and was skipped in the grid view.`);
                return;
            }

            const block = $.extend({}, row, {
                _slot_span: Math.max(1, Math.ceil((end - start) / SLOT_INTERVAL_MINUTES))
            });

            days.forEach(function (dayKey) {
                occupancy[dayKey][start] = {
                    type: "start",
                    block: block
                };

                for (let cursor = start + SLOT_INTERVAL_MINUTES; cursor < end; cursor += SLOT_INTERVAL_MINUTES) {
                    occupancy[dayKey][cursor] = {
                        type: "covered",
                        start: start
                    };
                }
            });
        });

        return {
            slots: slots,
            occupancy: occupancy,
            warnings: warnings.filter(function (warning, index, list) {
                return list.indexOf(warning) === index;
            })
        };
    }

    function buildClassCell(row) {
        const subjectCode = String(row.subject_code || "").trim();
        const subjectDescription = String(row.subject_description || "").trim();
        const facultyName = String(row.faculty_name || "TBA").trim() || "TBA";
        const roomLabel = String(row.room_label || "TBA").trim() || "TBA";
        const scheduleType = String(row.schedule_type || "").trim().toUpperCase();

        return `
            <div class="cp-class-block">
                ${subjectCode ? `<div class="cp-subject-code">${escapeHtml(subjectCode)}</div>` : ""}
                ${subjectDescription ? `<div class="cp-subject-description">${escapeHtml(subjectDescription)}</div>` : ""}
                <div class="cp-block-line">${escapeHtml(facultyName)}</div>
                <div class="cp-block-line">${escapeHtml(roomLabel)}</div>
                ${scheduleType === "LAB" ? `<div class="cp-block-chip">LAB</div>` : ""}
            </div>
        `;
    }

    function renderClassProgram(meta, rows) {
        const matrix = buildScheduleMatrix(rows);

        $("#classProgramCard").show();
        updateHeader(meta, $("#cp_ay").val(), $("#cp_semester").val());
        setPrintState(Boolean($("#cp_section_id").val()));

        let html = "";

        if (matrix.warnings.length) {
            html += `
                <div class="cp-warning-banner">
                    ${matrix.warnings.map(function (warning) {
                        return `<div>${escapeHtml(warning)}</div>`;
                    }).join("")}
                </div>
            `;
        }

        html += `
            <div class="cp-sheet">
                <div class="table-responsive">
                    <table class="table table-bordered cp-sheet-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                ${DAY_COLUMNS.map(function (day) {
                                    return `<th>${escapeHtml(day.label)}</th>`;
                                }).join("")}
                            </tr>
                        </thead>
                        <tbody>
        `;

        matrix.slots.forEach(function (slotStart) {
            const slotEnd = slotStart + SLOT_INTERVAL_MINUTES;

            html += `
                <tr>
                    <td class="cp-time-cell">${escapeHtml(formatTimeRange(slotStart, slotEnd))}</td>
            `;

            DAY_COLUMNS.forEach(function (day) {
                const entry = matrix.occupancy[day.key][slotStart];

                if (entry && entry.type === "covered") {
                    return;
                }

                if (entry && entry.type === "start") {
                    html += `<td rowspan="${entry.block._slot_span}" class="cp-class-cell">${buildClassCell(entry.block)}</td>`;
                    return;
                }

                html += '<td class="cp-empty-cell"></td>';
            });

            html += "</tr>";
        });

        html += `
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        $("#classProgramWrapper").html(html);
    }

    function setSectionOptions(sections, selectedSectionId) {
        const availableSections = Array.isArray(sections) ? sections : [];
        let html = '<option value="">Select Section</option>';

        if (!availableSections.length) {
            html = '<option value="">No sections available for this term</option>';
        } else {
            html += availableSections.map(function (section) {
                return `<option value="${escapeHtml(section.section_id)}">${escapeHtml(section.label)}</option>`;
            }).join("");
        }

        $("#cp_section_id").html(html);

        const targetSectionId = String(selectedSectionId || "");
        const hasSelectedSection = availableSections.some(function (section) {
            return String(section.section_id) === targetSectionId;
        });

        $("#cp_section_id").val(hasSelectedSection ? targetSectionId : "");

        if ($.fn.select2) {
            $("#cp_section_id").trigger("change.select2");
        }
    }

    function loadSectionOptions() {
        const ay = $("#cp_ay").val();
        const semester = $("#cp_semester").val();
        const currentSectionId = $("#cp_section_id").val();

        if (!ay || !semester) {
            setSectionOptions([], "");
            return createResolvedPromise();
        }

        if (sectionOptionsRequest) {
            sectionOptionsRequest.abort();
        }

        showLoader("Loading available sections...");

        sectionOptionsRequest = $.ajax({
            url: "../backend/query_class_program.php",
            type: "POST",
            dataType: "json",
            data: {
                load_section_options: 1,
                ay: ay,
                semester: semester
            }
        }).done(function (response) {
            const sections = response && response.status === "ok" ? response.sections : [];
            setSectionOptions(sections, currentSectionId);
        }).fail(function (_, textStatus) {
            if (textStatus === "abort") {
                return;
            }

            setSectionOptions([], "");
        }).always(function () {
            sectionOptionsRequest = null;
            hideLoader();
        });

        return sectionOptionsRequest;
    }

    function loadSectionSchedule() {
        const ay = $("#cp_ay").val();
        const semester = $("#cp_semester").val();
        const sectionId = $("#cp_section_id").val();

        if (!ay || !semester || !sectionId) {
            showClassProgramMessage("Select academic year, semester, and section to view the class program.");
            return createResolvedPromise();
        }

        if (sectionScheduleRequest) {
            sectionScheduleRequest.abort();
        }

        showLoader("Loading class program...");

        sectionScheduleRequest = $.ajax({
            url: "../backend/query_class_program.php",
            type: "POST",
            dataType: "json",
            data: {
                load_section_schedule: 1,
                ay: ay,
                semester: semester,
                section_id: sectionId
            }
        }).done(function (response) {
            if (!response || response.status !== "ok") {
                showClassProgramMessage(
                    response && response.message
                        ? response.message
                        : "Unable to load the selected class program right now."
                );
                return;
            }

            renderClassProgram(response.meta || {}, Array.isArray(response.rows) ? response.rows : []);
        }).fail(function (_, textStatus) {
            if (textStatus === "abort") {
                return;
            }

            showClassProgramMessage("Unable to load the selected class program right now.");
        }).always(function () {
            sectionScheduleRequest = null;
            hideLoader();
        });

        return sectionScheduleRequest;
    }

    function reloadCurrentView() {
        const ay = $("#cp_ay").val();
        const semester = $("#cp_semester").val();
        const sectionId = $("#cp_section_id").val();

        if (!ay || !semester || !sectionId) {
            showClassProgramMessage("Select academic year, semester, and section to view the class program.");
            return;
        }

        loadSectionSchedule();
    }

    $("#cp_ay, #cp_semester").on("change", function () {
        loadSectionOptions().always(function () {
            reloadCurrentView();
        });
    });

    $("#cp_section_id").on("change", function () {
        reloadCurrentView();
    });

    $("#btnPrintClassProgram").on("click", function () {
        if ($(this).prop("disabled")) {
            return;
        }

        window.print();
    });

    showClassProgramMessage("Select academic year, semester, and section to view the class program.");

    setTimeout(function () {
        loadSectionOptions().always(function () {
            reloadCurrentView();
        });
    }, 200);
});
</script>

</body>
</html>
