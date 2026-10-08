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
$campus_name = $_SESSION['campus_name'] ?? '';
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
    <title>ISO-Class Program | Synk Scheduler</title>

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

    .iso-sheet-page {
        border: 1px solid #d9e2ec;
        border-radius: 12px;
        background: #fff;
        padding: 1.35rem 1.15rem 1.1rem;
        margin-bottom: 1.5rem;
        font-family: Arial, sans-serif;
        color: #000;
    }

    .iso-sheet-header {
        text-align: center;
        margin-bottom: 1rem;
    }

    .iso-sheet-title {
        font-size: 1.45rem;
        font-weight: 700;
        line-height: 1.1;
        text-transform: uppercase;
    }

    .iso-sheet-campus {
        margin-top: 0.15rem;
        font-size: 1.1rem;
        line-height: 1.1;
        text-decoration: underline;
    }

    .iso-sheet-term {
        margin-top: 0.15rem;
        font-size: 1rem;
        line-height: 1.1;
    }

    .iso-sheet-meta {
        margin-bottom: 0.75rem;
        font-size: 1rem;
        line-height: 1.25;
    }

    .iso-meta-line + .iso-meta-line {
        margin-top: 0.15rem;
    }

    .iso-meta-value {
        text-decoration: underline;
    }

    .iso-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    .iso-table th,
    .iso-table td {
        border: 1px solid #000;
        padding: 0.3rem 0.25rem;
        vertical-align: top;
        font-size: 0.78rem;
        color: #000;
    }

    .iso-table th {
        text-align: center;
        font-weight: 700;
    }

    .iso-time-col {
        width: 14%;
        text-align: center;
        vertical-align: middle !important;
        font-weight: 700;
    }

    .iso-section-col {
        width: 14.33%;
        text-align: center;
        vertical-align: middle !important;
    }

    .iso-section-label {
        font-style: italic;
        text-decoration: underline;
        font-weight: 500;
    }

    .iso-day-header {
        color: #1f3863;
        font-style: italic;
        font-weight: 700;
        font-size: 1rem;
    }

    .iso-subheader {
        line-height: 1.15;
        font-weight: 700;
    }

    .iso-slot-time {
        white-space: pre-line;
        line-height: 1.15;
        font-weight: 400;
        text-align: left;
        padding-left: 0.45rem !important;
    }

    .iso-cell {
        min-height: 52px;
    }

    .iso-entry + .iso-entry {
        margin-top: 0.45rem;
        padding-top: 0.35rem;
        border-top: 1px dashed rgba(0, 0, 0, 0.18);
    }

    .iso-entry-prefix {
        line-height: 1.15;
        font-size: 0.72rem;
    }

    .iso-entry-subject {
        line-height: 1.15;
        font-weight: 700;
        font-size: 0.74rem;
    }

    .iso-entry-room,
    .iso-entry-faculty {
        line-height: 1.15;
        font-size: 0.74rem;
    }

    .iso-special-title {
        text-align: center;
        font-weight: 700;
        font-size: 0.95rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
    }

    .iso-break-label {
        text-align: center;
        font-weight: 700;
        text-transform: uppercase;
    }

    .iso-footer {
        margin-top: 1.35rem;
        font-size: 0.95rem;
        page-break-inside: avoid;
    }

    .iso-footer-prepared {
        text-align: center;
        font-weight: 700;
        margin-bottom: 1rem;
    }

    .iso-footer-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 2rem;
        align-items: end;
    }

    .iso-footer-label {
        font-weight: 700;
        margin-bottom: 1.6rem;
    }

    .iso-signature-line {
        border-bottom: 1px solid #000;
        height: 1.7rem;
        margin-bottom: 0.35rem;
    }

    .iso-signature-caption {
        text-align: center;
    }

    .iso-empty-state {
        padding: 2.5rem 1rem;
        text-align: center;
        color: #73859b;
        font-weight: 500;
    }

    .iso-loader {
        position: fixed;
        inset: 0;
        background: rgba(255, 255, 255, 0.85);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 2000;
    }

    .iso-loader-box {
        text-align: center;
    }

    .iso-print-trigger {
        white-space: nowrap;
    }

    @media (max-width: 767.98px) {
        .iso-sheet-page {
            padding: 1rem 0.85rem;
        }

        #isoProgramCard .card-header {
            flex-direction: column;
            align-items: stretch !important;
        }

        .iso-print-trigger {
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
        .iso-no-print,
        #isoLoader {
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

        #isoProgramCard {
            display: block !important;
            border: 0 !important;
            box-shadow: none !important;
            margin: 0 !important;
        }

        #isoProgramCard .card-header {
            display: none !important;
        }

        #isoProgramCard .card-body {
            padding: 0 !important;
        }

        .iso-sheet-page {
            border: 0 !important;
            border-radius: 0 !important;
            padding: 0 !important;
            margin: 0 0 0.55in !important;
            box-shadow: none !important;
            page-break-after: always;
        }

        .iso-sheet-page:last-child {
            page-break-after: auto;
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

    <h4 class="fw-bold mb-3 iso-no-print">
        <i class="bx bx-file me-2"></i>
        ISO-Class Program
        <small class="text-muted">(<?= htmlspecialchars($college_name) ?>)</small>
    </h4>

    <div class="card mb-4 iso-no-print">
        <div class="card-header">
            <h5 class="m-0">Filter ISO-Class Program</h5>
            <small class="text-muted">Select academic year, semester, and program to view and print the ISO class program sheet.</small>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">A.Y.</label>
                    <select id="iso_ay" class="form-select select2-single">
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
                    <select id="iso_semester" class="form-select">
                        <option value="1st"<?= $defaultSemesterUi === '1st' ? ' selected' : '' ?>>1st Semester</option>
                        <option value="2nd"<?= $defaultSemesterUi === '2nd' ? ' selected' : '' ?>>2nd Semester</option>
                        <option value="Midyear"<?= $defaultSemesterUi === 'Midyear' ? ' selected' : '' ?>>Midyear</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Program</label>
                    <select id="iso_program_id" class="form-select select2-single">
                        <option value="">Select Program</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card" id="isoProgramCard" style="display:none;">
        <div class="card-header d-flex justify-content-between align-items-start gap-3">
            <div>
                <h5 class="m-0">ISO-Class Program</h5>
                <small class="text-muted">Program-based ISO print layout with section columns and signature footer.</small>
            </div>
            <button type="button" class="btn btn-outline-primary iso-print-trigger" id="btnPrintIsoProgram" disabled>
                <i class="bx bx-printer me-1"></i>Print ISO Sheet
            </button>
        </div>
        <div class="card-body">
            <div id="isoProgramWrapper"></div>
        </div>
    </div>

</div>

<?php include '../footer.php'; ?>

<div id="isoLoader" class="iso-loader d-none">
    <div class="iso-loader-box">
        <div class="spinner-border text-primary mb-2" role="status"></div>
        <div class="small fw-semibold" id="isoLoaderText">Loading ISO class program...</div>
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
    const ISO_COLUMN_COUNT = 6;
    const ISO_DAY_PATTERNS = {
        MW: "MW",
        TTH: "TTH",
        FRIDAY: "FRIDAY",
        SATURDAY: "SATURDAY"
    };
    const ISO_PRIMARY_SLOTS = [
        { key: "0730", start: 450, end: 540, label: "07:30am-\n09:00am" },
        { key: "0900", start: 540, end: 630, label: "09:00am-\n10:30am" },
        { key: "1030", start: 630, end: 720, label: "10:30am-\n12:00pm" },
        { break_row: true, key: "break", label: "12:00-1:00" },
        { key: "1300", start: 780, end: 870, label: "01:00pm-\n02:30pm" },
        { key: "1430", start: 870, end: 960, label: "02:30pm-\n04:00pm" },
        { key: "1600", start: 960, end: 1050, label: "04:00pm-\n05:30pm" }
    ];
    const ISO_DEFAULT_FRIDAY_ROWS = [
        { key: "friday_default", label: "07:30 AM -\n10:30 AM", start: 450 }
    ];
    const ISO_DEFAULT_SATURDAY_ROWS = [
        { key: "saturday_default", label: "07:30 AM -\n10:30 AM", start: 450 }
    ];
    const DEFAULT_CAMPUS_NAME = <?= json_encode($campus_name) ?>;
    const DEFAULT_COLLEGE_NAME = <?= json_encode($college_name) ?>;

    let loaderCount = 0;
    let programOptionsRequest = null;
    let isoProgramRequest = null;

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
        $("#isoLoaderText").text(message || "Loading ISO class program...");
        $("#isoLoader").removeClass("d-none");
    }

    function hideLoader() {
        loaderCount = Math.max(0, loaderCount - 1);
        if (loaderCount === 0) {
            $("#isoLoader").addClass("d-none");
        }
    }

    function setPrintState(enabled) {
        $("#btnPrintIsoProgram").prop("disabled", !enabled);
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

    function showIsoMessage(message) {
        $("#isoProgramCard").show();
        setPrintState(false);
        $("#isoProgramWrapper").html(
            `<div class="iso-empty-state">${escapeHtml(message)}</div>`
        );
    }

    function timeToMinutes(time) {
        const parts = String(time || "").split(":").map(Number);
        return ((parts[0] || 0) * 60) + (parts[1] || 0);
    }

    function minutesToDisplay(minutes) {
        const normalized = Math.max(0, Number(minutes) || 0);
        let hour = Math.floor(normalized / 60);
        const minute = normalized % 60;
        const suffix = hour >= 12 ? "PM" : "AM";
        hour = hour % 12 || 12;
        return `${String(hour).padStart(2, "0")}:${String(minute).padStart(2, "0")} ${suffix}`;
    }

    function timeRangeToDisplay(start, end) {
        return `${minutesToDisplay(timeToMinutes(start))} - ${minutesToDisplay(timeToMinutes(end))}`;
    }

    function normalizeDayToken(day) {
        const token = String(day || "").toUpperCase().trim();
        return token === "TH" ? "TH" : token;
    }

    function normalizeDayKey(daysArr) {
        const order = { M: 1, T: 2, W: 3, TH: 4, F: 5, S: 6 };
        const tokens = (Array.isArray(daysArr) ? daysArr : [])
            .map(normalizeDayToken)
            .filter(function (token) {
                return order[token];
            })
            .filter(function (token, index, array) {
                return array.indexOf(token) === index;
            });

        tokens.sort(function (left, right) {
            return (order[left] || 99) - (order[right] || 99);
        });

        return tokens.join("");
    }

    function formatDayLabel(dayKey) {
        const key = String(dayKey || "").toUpperCase().trim();
        if (key === "TH") {
            return "Th";
        }

        return key;
    }

    function getIsoGroup(row) {
        const dayKey = String(row.days_key || normalizeDayKey(row.days_raw)).toUpperCase();

        if (dayKey === "F") {
            return ISO_DAY_PATTERNS.FRIDAY;
        }

        if (dayKey === "S") {
            return ISO_DAY_PATTERNS.SATURDAY;
        }

        if (dayKey.indexOf("T") !== -1 || dayKey.indexOf("TH") !== -1) {
            return ISO_DAY_PATTERNS.TTH;
        }

        if (dayKey.indexOf("M") !== -1 || dayKey.indexOf("W") !== -1 || dayKey.indexOf("F") !== -1) {
            return ISO_DAY_PATTERNS.MW;
        }

        return ISO_DAY_PATTERNS.MW;
    }

    function chunkSections(sections, size) {
        const source = Array.isArray(sections) ? sections.slice() : [];
        const chunks = [];

        for (let index = 0; index < source.length; index += size) {
            chunks.push(source.slice(index, index + size));
        }

        return chunks;
    }

    function padSections(batch) {
        const output = Array.isArray(batch) ? batch.slice() : [];

        while (output.length < ISO_COLUMN_COUNT) {
            output.push({
                section_id: 0,
                full_section: ""
            });
        }

        return output;
    }

    function findPrimarySlot(startMinutes) {
        for (let index = 0; index < ISO_PRIMARY_SLOTS.length; index += 1) {
            const slot = ISO_PRIMARY_SLOTS[index];
            if (slot.break_row) {
                continue;
            }

            if (startMinutes >= slot.start && startMinutes < slot.end) {
                return slot;
            }
        }

        return null;
    }

    function buildEntry(row, expectedGroup, slot) {
        const dayLabel = formatDayLabel(row.days_key || normalizeDayKey(row.days_raw));
        const actualTime = timeRangeToDisplay(row.time_start, row.time_end);
        const slotLabel = slot && slot.start != null && slot.end != null
            ? `${minutesToDisplay(slot.start)} - ${minutesToDisplay(slot.end)}`
            : "";
        const expectedLabel = expectedGroup === ISO_DAY_PATTERNS.TTH ? "TTH" : "MW";
        const prefixParts = [];

        if (expectedGroup === ISO_DAY_PATTERNS.MW || expectedGroup === ISO_DAY_PATTERNS.TTH) {
            if (dayLabel && dayLabel !== expectedLabel) {
                prefixParts.push(dayLabel);
            }

            if (slotLabel && actualTime !== slotLabel) {
                prefixParts.push(actualTime);
            }
        }

        const subjectCode = String(row.subject_code || "").trim();
        const scheduleType = String(row.schedule_type || "").trim().toUpperCase();
        const subjectLabel = subjectCode + (scheduleType === "LAB" ? " LAB" : "");

        return {
            prefix: prefixParts.join(" "),
            subject: subjectLabel,
            room: String(row.room_label || "TBA").trim() || "TBA",
            faculty: String(row.faculty_name || "TBA").trim() || "TBA"
        };
    }

    function buildPrimaryRows(batchSections, rows, pattern) {
        const sectionIds = {};
        const cellMap = {};
        const relevantRows = Array.isArray(rows) ? rows : [];

        batchSections.forEach(function (section) {
            sectionIds[String(section.section_id)] = true;
        });

        ISO_PRIMARY_SLOTS.forEach(function (slot) {
            if (!slot.break_row) {
                cellMap[slot.key] = {};
            }
        });

        relevantRows.forEach(function (row) {
            const sectionId = String(row.section_id || "");
            if (!sectionIds[sectionId]) {
                return;
            }

            if (getIsoGroup(row) !== pattern) {
                return;
            }

            const slot = findPrimarySlot(timeToMinutes(String(row.time_start || "").substring(0, 5)));
            if (!slot) {
                return;
            }

            if (!cellMap[slot.key][sectionId]) {
                cellMap[slot.key][sectionId] = [];
            }

            cellMap[slot.key][sectionId].push(buildEntry(row, pattern, slot));
        });

        return ISO_PRIMARY_SLOTS.map(function (slot) {
            return $.extend({}, slot, {
                cells: cellMap[slot.key] || {}
            });
        });
    }

    function buildSpecialRows(batchSections, rows, pattern) {
        const sectionIds = {};
        const grouped = {};
        const relevantRows = Array.isArray(rows) ? rows : [];

        batchSections.forEach(function (section) {
            sectionIds[String(section.section_id)] = true;
        });

        relevantRows.forEach(function (row) {
            const sectionId = String(row.section_id || "");
            if (!sectionIds[sectionId]) {
                return;
            }

            if (getIsoGroup(row) !== pattern) {
                return;
            }

            const key = `${row.time_start || ""}|${row.time_end || ""}`;
            if (!grouped[key]) {
                grouped[key] = {
                    key: key,
                    label: timeRangeToDisplay(row.time_start, row.time_end).replace(" - ", " -\n"),
                    start: timeToMinutes(String(row.time_start || "").substring(0, 5)),
                    cells: {}
                };
            }

            if (!grouped[key].cells[sectionId]) {
                grouped[key].cells[sectionId] = [];
            }

            grouped[key].cells[sectionId].push(buildEntry(row, pattern, null));
        });

        const rowsOut = Object.keys(grouped).map(function (key) {
            return grouped[key];
        });

        rowsOut.sort(function (left, right) {
            return (left.start || 0) - (right.start || 0);
        });

        if (rowsOut.length) {
            return rowsOut;
        }

        return (pattern === ISO_DAY_PATTERNS.FRIDAY ? ISO_DEFAULT_FRIDAY_ROWS : ISO_DEFAULT_SATURDAY_ROWS).map(function (row) {
            return $.extend({}, row, {
                cells: {}
            });
        });
    }

    function renderEntries(entries) {
        const items = Array.isArray(entries) ? entries : [];
        if (!items.length) {
            return "";
        }

        return items.map(function (entry) {
            return `
                <div class="iso-entry">
                    ${entry.prefix ? `<div class="iso-entry-prefix">${escapeHtml(entry.prefix)}</div>` : ""}
                    ${entry.subject ? `<div class="iso-entry-subject">${escapeHtml(entry.subject)}</div>` : ""}
                    ${entry.room ? `<div class="iso-entry-room">${escapeHtml(entry.room)}</div>` : ""}
                    ${entry.faculty ? `<div class="iso-entry-faculty">${escapeHtml(entry.faculty)}</div>` : ""}
                </div>
            `;
        }).join("");
    }

    function renderPrimaryTable(batchSections, pattern, primaryRows, specialTitle, specialRows) {
        const sections = padSections(batchSections);
        const headerDayLabel = pattern === ISO_DAY_PATTERNS.TTH ? "TTH" : "MW";

        let html = `
            <table class="iso-table">
                <thead>
                    <tr>
                        <th class="iso-time-col"></th>
                        ${sections.map(function (section) {
                            return `<th class="iso-section-col"><span class="iso-section-label">${escapeHtml(section.full_section || "")}</span></th>`;
                        }).join("")}
                    </tr>
                    <tr>
                        <th class="iso-time-col">
                            <div>TIME</div>
                            <div class="iso-day-header">${escapeHtml(headerDayLabel)}</div>
                        </th>
                        ${sections.map(function () {
                            return `<th class="iso-subheader">Crs.Code<br>Room No.<br>Teacher</th>`;
                        }).join("")}
                    </tr>
                </thead>
                <tbody>
        `;

        primaryRows.forEach(function (row) {
            if (row.break_row) {
                html += `
                    <tr>
                        <td class="iso-time-col iso-slot-time">${escapeHtml(row.label)}</td>
                        <td class="iso-break-label" colspan="${ISO_COLUMN_COUNT}">Break Time</td>
                    </tr>
                `;
                return;
            }

            html += `
                <tr>
                    <td class="iso-time-col iso-slot-time">${escapeHtml(row.label)}</td>
                    ${sections.map(function (section) {
                        const entries = section.section_id ? (row.cells[String(section.section_id)] || []) : [];
                        return `<td class="iso-cell">${renderEntries(entries)}</td>`;
                    }).join("")}
                </tr>
            `;
        });

        html += `
                    <tr>
                        <td class="iso-time-col"></td>
                        <td class="iso-special-title" colspan="${ISO_COLUMN_COUNT}">${escapeHtml(specialTitle)}</td>
                    </tr>
        `;

        specialRows.forEach(function (row) {
            html += `
                <tr>
                    <td class="iso-time-col iso-slot-time">${escapeHtml(row.label)}</td>
                    ${sections.map(function (section) {
                        const entries = section.section_id ? (row.cells[String(section.section_id)] || []) : [];
                        return `<td class="iso-cell">${renderEntries(entries)}</td>`;
                    }).join("")}
                </tr>
            `;
        });

        html += `
                </tbody>
            </table>
        `;

        return html;
    }

    function renderSheetHeader(meta, ay, semester) {
        const campusDisplay = String(meta.campus_name || DEFAULT_CAMPUS_NAME || "Current Campus").trim() || "Current Campus";
        const collegeDisplay = String(meta.college_name || DEFAULT_COLLEGE_NAME).trim() || DEFAULT_COLLEGE_NAME;
        const programName = toDisplayCase(meta.program_name || "");
        const programMajor = toDisplayCase(meta.major || "");
        const programLabel = programMajor
            ? `${programName} (Major in ${programMajor})`
            : programName;

        return `
            <div class="iso-sheet-header">
                <div class="iso-sheet-title">Class Program</div>
                <div class="iso-sheet-campus">${escapeHtml(campusDisplay)}</div>
                <div class="iso-sheet-term">${escapeHtml(formatSemesterLabel(semester))} | AY ${escapeHtml(ay)}</div>
            </div>
            <div class="iso-sheet-meta">
                <div class="iso-meta-line">College: <span class="iso-meta-value">${escapeHtml(collegeDisplay)}</span></div>
                <div class="iso-meta-line">Program: <span class="iso-meta-value">${escapeHtml(programLabel || String(meta.program_code || "").trim())}</span></div>
            </div>
        `;
    }

    function renderSignatureFooter() {
        return `
            <div class="iso-footer">
                <div class="iso-footer-prepared">Prepared by:</div>
                <div class="iso-footer-grid">
                    <div>
                        <div class="iso-footer-label">Attested by:</div>
                        <div class="iso-signature-line"></div>
                        <div class="iso-signature-caption">Dean/Date</div>
                    </div>
                    <div>
                        <div class="iso-signature-line"></div>
                        <div class="iso-signature-caption">Program Chairman/Date</div>
                    </div>
                </div>
            </div>
        `;
    }

    function renderIsoProgram(meta, sections, rows) {
        const programSections = Array.isArray(sections) ? sections : [];
        const sectionBatches = chunkSections(programSections, ISO_COLUMN_COUNT);
        const allRows = Array.isArray(rows) ? rows : [];

        $("#isoProgramCard").show();
        setPrintState(Boolean($("#iso_program_id").val()));

        if (!sectionBatches.length) {
            $("#isoProgramWrapper").html(
                `<div class="iso-empty-state">No sections were found for the selected program and term.</div>`
            );
            return;
        }

        let html = "";

        sectionBatches.forEach(function (batch) {
            const mwRows = buildPrimaryRows(batch, allRows, ISO_DAY_PATTERNS.MW);
            const fridayRows = buildSpecialRows(batch, allRows, ISO_DAY_PATTERNS.FRIDAY);
            const tthRows = buildPrimaryRows(batch, allRows, ISO_DAY_PATTERNS.TTH);
            const saturdayRows = buildSpecialRows(batch, allRows, ISO_DAY_PATTERNS.SATURDAY);

            html += `
                <div class="iso-sheet-page">
                    ${renderSheetHeader(meta, $("#iso_ay").val(), $("#iso_semester").val())}
                    ${renderPrimaryTable(batch, ISO_DAY_PATTERNS.MW, mwRows, "Friday Class", fridayRows)}
                </div>
            `;

            html += `
                <div class="iso-sheet-page">
                    ${renderSheetHeader(meta, $("#iso_ay").val(), $("#iso_semester").val())}
                    ${renderPrimaryTable(batch, ISO_DAY_PATTERNS.TTH, tthRows, "Saturday Class", saturdayRows)}
                    ${renderSignatureFooter()}
                </div>
            `;
        });

        $("#isoProgramWrapper").html(html);
    }

    function setProgramOptions(programs, selectedProgramId) {
        const availablePrograms = Array.isArray(programs) ? programs : [];
        let html = '<option value="">Select Program</option>';

        if (!availablePrograms.length) {
            html = '<option value="">No programs available for this term</option>';
        } else {
            html += availablePrograms.map(function (program) {
                return `<option value="${escapeHtml(program.program_id)}">${escapeHtml(program.label)}</option>`;
            }).join("");
        }

        $("#iso_program_id").html(html);

        const targetProgramId = String(selectedProgramId || "");
        const hasSelectedProgram = availablePrograms.some(function (program) {
            return String(program.program_id) === targetProgramId;
        });

        $("#iso_program_id").val(hasSelectedProgram ? targetProgramId : "");

        if ($.fn.select2) {
            $("#iso_program_id").trigger("change.select2");
        }
    }

    function loadProgramOptions() {
        const ay = $("#iso_ay").val();
        const semester = $("#iso_semester").val();
        const currentProgramId = $("#iso_program_id").val();

        if (!ay || !semester) {
            setProgramOptions([], "");
            return createResolvedPromise();
        }

        if (programOptionsRequest) {
            programOptionsRequest.abort();
        }

        showLoader("Loading available programs...");

        programOptionsRequest = $.ajax({
            url: "../backend/query_iso_class_program.php",
            type: "POST",
            dataType: "json",
            data: {
                load_program_options: 1,
                ay: ay,
                semester: semester
            }
        }).done(function (response) {
            const programs = response && response.status === "ok" ? response.programs : [];
            setProgramOptions(programs, currentProgramId);
        }).fail(function (_, textStatus) {
            if (textStatus === "abort") {
                return;
            }

            setProgramOptions([], "");
        }).always(function () {
            programOptionsRequest = null;
            hideLoader();
        });

        return programOptionsRequest;
    }

    function loadIsoProgram() {
        const ay = $("#iso_ay").val();
        const semester = $("#iso_semester").val();
        const programId = $("#iso_program_id").val();

        if (!ay || !semester || !programId) {
            showIsoMessage("Select academic year, semester, and program to view the ISO class program.");
            return createResolvedPromise();
        }

        if (isoProgramRequest) {
            isoProgramRequest.abort();
        }

        showLoader("Loading ISO class program...");

        isoProgramRequest = $.ajax({
            url: "../backend/query_iso_class_program.php",
            type: "POST",
            dataType: "json",
            data: {
                load_iso_class_program: 1,
                ay: ay,
                semester: semester,
                program_id: programId
            }
        }).done(function (response) {
            if (!response || response.status !== "ok") {
                showIsoMessage(
                    response && response.message
                        ? response.message
                        : "Unable to load the selected ISO class program right now."
                );
                return;
            }

            renderIsoProgram(
                response.meta || {},
                Array.isArray(response.sections) ? response.sections : [],
                Array.isArray(response.rows) ? response.rows : []
            );
        }).fail(function (_, textStatus) {
            if (textStatus === "abort") {
                return;
            }

            showIsoMessage("Unable to load the selected ISO class program right now.");
        }).always(function () {
            isoProgramRequest = null;
            hideLoader();
        });

        return isoProgramRequest;
    }

    function reloadCurrentView() {
        const ay = $("#iso_ay").val();
        const semester = $("#iso_semester").val();
        const programId = $("#iso_program_id").val();

        if (!ay || !semester || !programId) {
            showIsoMessage("Select academic year, semester, and program to view the ISO class program.");
            return;
        }

        loadIsoProgram();
    }

    $("#iso_ay, #iso_semester").on("change", function () {
        loadProgramOptions().always(function () {
            reloadCurrentView();
        });
    });

    $("#iso_program_id").on("change", function () {
        reloadCurrentView();
    });

    $("#btnPrintIsoProgram").on("click", function () {
        if ($(this).prop("disabled")) {
            return;
        }

        window.print();
    });

    showIsoMessage("Select academic year, semester, and program to view the ISO class program.");

    setTimeout(function () {
        loadProgramOptions().always(function () {
            reloadCurrentView();
        });
    }, 200);
});
</script>

</body>
</html>
