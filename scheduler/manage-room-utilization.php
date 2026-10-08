<?php
session_start();
ob_start();
include '../backend/db.php';
require_once '../backend/academic_term_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'scheduler') {
    header("Location: ../index.php");
    exit;
}

$college_name = $_SESSION['college_name'] ?? '';
$college_id = (int)($_SESSION['college_id'] ?? 0);
$campus_name = '';
$currentTerm = synk_fetch_current_academic_term($conn);
$defaultAyLabel = (string)$currentTerm['ay_label'];
$defaultSemesterUi = '';

if ($college_id > 0) {
    $campusStmt = $conn->prepare("
        SELECT cp.campus_name
        FROM tbl_college c
        LEFT JOIN tbl_campus cp ON cp.campus_id = c.campus_id
        WHERE c.college_id = ?
        LIMIT 1
    ");

    if ($campusStmt) {
        $campusStmt->bind_param("i", $college_id);
        $campusStmt->execute();
        $campusResult = $campusStmt->get_result();
        $campusRow = $campusResult ? $campusResult->fetch_assoc() : null;
        $campus_name = trim((string)($campusRow['campus_name'] ?? ''));
        $campusStmt->close();
    }
}

if ((int)$currentTerm['semester'] === 1) {
    $defaultSemesterUi = '1st';
} elseif ((int)$currentTerm['semester'] === 2) {
    $defaultSemesterUi = '2nd';
} elseif ((int)$currentTerm['semester'] === 3) {
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
    <title>Room Utilization | Synk Scheduler</title>

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

    .ru-room-label {
        font-size: 1.2rem;
        font-weight: 700;
        color: #2c415c;
    }

    .ru-term-label {
        font-size: 0.9rem;
        color: #6d7f95;
    }

    .ru-sheet {
        border: 1px solid #d9e2ec;
        border-radius: 12px;
        overflow: hidden;
        background: #fff;
    }

    .ru-sheet-table {
        width: 100%;
        margin-bottom: 0;
        table-layout: fixed;
    }

    .ru-sheet-table thead th {
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

    .ru-sheet-table tbody td {
        border-color: #d9e2ec;
        color: #576c85;
        vertical-align: top;
        background: #fff;
    }

    .ru-sheet-table th:nth-child(1),
    .ru-sheet-table td:nth-child(1) {
        width: 26%;
    }

    .ru-sheet-table th:nth-child(2),
    .ru-sheet-table td:nth-child(2) {
        width: 14%;
        text-align: center;
    }

    .ru-sheet-table th:nth-child(3),
    .ru-sheet-table td:nth-child(3) {
        width: 34%;
    }

    .ru-sheet-table th:nth-child(4),
    .ru-sheet-table td:nth-child(4) {
        width: 26%;
    }

    .ru-time-cell {
        white-space: nowrap;
        font-weight: 600;
        color: #334a63;
    }

    .ru-day-cell {
        white-space: nowrap;
        font-weight: 700;
        color: #304560;
    }

    .ru-sheet-group td {
        background: #f6f9fc;
        color: #2f455f;
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.1em;
    }

    .ru-subject-entry {
        color: #334a63;
        font-weight: 400;
        white-space: nowrap;
    }

    .ru-faculty-name {
        font-weight: 700;
        color: #2d4159;
        text-transform: uppercase;
    }

    .ru-empty-state {
        padding: 2.5rem 1rem;
        text-align: center;
        color: #73859b;
        font-weight: 500;
    }

    .ru-loader {
        position: fixed;
        inset: 0;
        background: rgba(255, 255, 255, 0.85);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 2000;
    }

    .ru-loader-box {
        text-align: center;
    }

    .ru-print-trigger {
        white-space: nowrap;
    }

    .ru-print-form-background,
    .ru-print-title,
    .ru-print-field,
    .ru-print-meta-line,
    .ru-print-signature-area,
    .ru-print-filler-row {
        display: none;
    }

    .ru-print-overlay {
        position: static;
    }

    .ru-print-table-area {
        position: static;
    }

    @page {
        size: A4 portrait;
        margin: 0;
    }

    @media print {
        html,
        body {
            width: 210mm !important;
            min-height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: visible !important;
            background: #fff !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        body * {
            visibility: hidden;
        }

        .layout-menu,
        .layout-navbar,
        footer,
        .ru-no-print,
        #ruLoader {
            display: none !important;
        }

        .layout-wrapper,
        .layout-container,
        .layout-page,
        .content-wrapper,
        .container-xxl {
            display: block !important;
            width: 100% !important;
            max-width: 100% !important;
            min-height: 0 !important;
            height: auto !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: visible !important;
            background: #fff !important;
        }

        .container-xxl > :not(#roomTimetableCard) {
            display: none !important;
        }

        #roomTimetableCard,
        #roomTimetableCard * {
            visibility: visible;
        }

        #roomTimetableCard {
            position: absolute;
            left: 0;
            top: 0;
            width: 210mm;
            min-height: 0;
            max-width: 210mm;
            display: block !important;
            border: 0 !important;
            box-shadow: none !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: visible !important;
            background: transparent !important;
        }

        #roomTimetableCard .card-header {
            display: none !important;
        }

        #roomTimetableCard .card-body {
            padding: 0 !important;
            background: transparent !important;
        }

        #roomTimetableWrapper {
            display: block !important;
            width: 210mm !important;
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
            overflow: visible !important;
        }

        .ru-print-sheet {
            display: block !important;
            position: relative;
            width: 210mm;
            height: 296mm;
            min-height: 296mm;
            box-sizing: border-box;
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
            isolation: isolate;
            overflow: hidden !important;
            page-break-after: auto !important;
            break-after: auto !important;
            page-break-inside: auto !important;
            break-inside: auto !important;
        }

        .ru-print-sheet + .ru-print-sheet {
            page-break-before: always !important;
            break-before: page !important;
        }

        .ru-print-form-background {
            display: block !important;
            position: absolute;
            inset: 0;
            width: 210mm;
            height: 296mm;
            object-fit: fill;
            z-index: 0;
        }

        .ru-print-overlay {
            position: relative;
            z-index: 1;
            width: 210mm;
            min-height: 296mm;
        }

        .ru-print-field {
            display: block !important;
            position: absolute;
            color: #000 !important;
            font-family: Arial, sans-serif;
            font-size: 9pt;
            line-height: 1.05;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ru-print-title {
            display: block !important;
            position: absolute;
            top: 43.6mm;
            left: 58mm;
            width: 112mm;
            z-index: 1;
            color: #000 !important;
            font-family: Arial, sans-serif;
            font-size: 14pt;
            font-weight: 700;
            line-height: 1;
            text-align: center;
            letter-spacing: 0.01em;
        }

        .ru-print-campus-field {
            top: 50.8mm;
            left: 80mm;
            width: 68mm;
            text-align: center;
        }

        .ru-print-term-field {
            top: 56.7mm;
            left: 75mm;
            width: 72mm;
            text-align: center;
        }

        .ru-print-meta-line {
            display: flex !important;
            align-items: flex-end;
            position: absolute;
            z-index: 1;
            color: #000 !important;
            font-family: Arial, sans-serif;
            font-size: 10pt;
            line-height: 1;
        }

        .ru-print-meta-label {
            flex: 0 0 auto;
            padding-right: 2mm;
        }

        .ru-print-meta-value {
            flex: 1 1 auto;
            min-height: 3.2mm;
            padding: 0 1.5mm 0.35mm;
            border-bottom: 0.45pt solid #000;
            font-size: 8.2pt;
            font-weight: 600;
            line-height: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .ru-print-college-line {
            top: 65.2mm;
            left: 24mm;
            width: 73mm;
        }

        .ru-print-program-line {
            top: 71.2mm;
            left: 24mm;
            width: 90mm;
        }

        .ru-print-room-line {
            top: 82.2mm;
            left: 24mm;
            width: 105mm;
        }

        .ru-print-table-area {
            position: absolute;
            top: 95.2mm;
            left: 25.3mm;
            width: 171.6mm;
            z-index: 1;
        }

        .ru-room-label,
        .ru-term-label {
            color: #000 !important;
        }

        .ru-sheet {
            border: 0 !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            background: transparent !important;
            overflow: visible !important;
        }

        .ru-sheet .table-responsive {
            overflow: visible !important;
        }

        .ru-sheet-table {
            width: 100% !important;
            border-collapse: collapse !important;
            table-layout: fixed !important;
            background: transparent !important;
            margin: 0 !important;
        }

        .ru-sheet-table th:nth-child(1),
        .ru-sheet-table td:nth-child(1) {
            width: 33.3% !important;
        }

        .ru-sheet-table th:nth-child(2),
        .ru-sheet-table td:nth-child(2) {
            width: 21.8% !important;
        }

        .ru-sheet-table th:nth-child(3),
        .ru-sheet-table td:nth-child(3) {
            width: 20.2% !important;
        }

        .ru-sheet-table th:nth-child(4),
        .ru-sheet-table td:nth-child(4) {
            width: 24.7% !important;
        }

        .ru-sheet-table thead th,
        .ru-sheet-table tbody td {
            border: 0.6pt solid #000 !important;
            background: transparent !important;
            color: #000 !important;
            box-shadow: none !important;
            font-family: Arial, sans-serif;
            font-size: 8.6pt !important;
            line-height: 1.08 !important;
            padding: 1.7mm 2.2mm !important;
            vertical-align: middle !important;
        }

        .ru-sheet-table thead th {
            height: 7.7mm !important;
            padding: 0 !important;
            color: #000 !important;
            font-size: 9.6pt !important;
            line-height: 1 !important;
            font-weight: 700 !important;
            text-align: center !important;
        }

        .ru-sheet-table tbody tr {
            height: 9.8mm !important;
        }

        .ru-sheet-group td {
            height: 9.8mm !important;
            padding: 1.6mm 2.2mm !important;
            color: #000 !important;
            background: transparent !important;
            font-size: 8.8pt !important;
            font-weight: 700 !important;
            letter-spacing: 0 !important;
            text-transform: uppercase !important;
        }

        .ru-time-cell,
        .ru-day-cell,
        .ru-subject-entry,
        .ru-faculty-name {
            color: #000 !important;
            font-family: Arial, sans-serif;
            font-size: 8.6pt !important;
            line-height: 1.08 !important;
            white-space: normal !important;
        }

        .ru-print-filler-row {
            display: table-row !important;
        }

        .ru-print-signature-area {
            display: block !important;
            position: absolute;
            inset: 0;
            z-index: 1;
            color: #000 !important;
            font-family: Arial, sans-serif;
            font-size: 8.6pt;
            line-height: 1.1;
        }

        .ru-print-signature-label {
            font-weight: 700;
        }

        .ru-print-prepared {
            position: absolute;
            top: 219mm;
            left: 126mm;
            width: 62mm;
            text-align: center;
        }

        .ru-print-attested {
            position: absolute;
            top: 236.3mm;
            left: 28mm;
            width: 80mm;
        }

        .ru-print-sign-line {
            border-bottom: 0.65pt solid #000;
            display: flex;
            align-items: flex-end;
            justify-content: center;
            height: 8mm;
            margin: 2mm 0 1mm;
            padding: 0 1mm 0.7mm;
        }

        .ru-print-prepared .ru-print-sign-line {
            width: 56mm;
            margin-left: auto;
            margin-right: auto;
        }

        .ru-print-attested .ru-print-sign-line {
            width: 64mm;
        }

        .ru-print-sign-caption {
            font-size: 8.3pt;
            text-align: center;
        }

        .ru-print-sign-name {
            display: block;
            width: 100%;
            color: #000 !important;
            font-size: 8.2pt;
            font-weight: 700;
            line-height: 1;
            overflow: hidden;
            text-align: center;
            text-overflow: ellipsis;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .ru-print-cc {
            position: absolute;
            top: 252.8mm;
            left: 28mm;
            font-size: 7.5pt;
            line-height: 1.15;
        }

        .ru-print-cc-title {
            margin-bottom: 1mm;
            font-weight: 700;
        }

        .ru-print-cc-list {
            margin-left: 18mm;
        }

        .ru-print-ack-box {
            position: absolute;
            top: 241.5mm;
            left: 130mm;
            width: 54mm;
            min-height: 25mm;
            border: 0.6pt dashed #000;
            padding: 1.7mm 2mm;
            font-size: 7.4pt;
            line-height: 1.18;
        }

        .ru-print-ack-title {
            font-weight: 500;
            text-transform: uppercase;
        }

        .ru-print-ack-row {
            margin-top: 2.1mm;
        }

        .ru-print-ack-sign {
            width: 36mm;
            margin: 0.5mm 0 0 auto;
            border-bottom: 0.55pt solid #000;
            height: 2.2mm;
        }

        .ru-print-ack-caption {
            margin-left: 15mm;
            font-size: 6.6pt;
            line-height: 1;
            text-align: center;
        }

        .ru-time-cell,
        .ru-day-cell,
        .ru-faculty-name {
            font-weight: 700 !important;
        }

        .ru-print-trigger {
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
        <i class="bx bx-grid-alt me-2"></i>
        Room Utilization
        <small class="text-muted">(<?= htmlspecialchars($college_name) ?>)</small>
    </h4>

    <div class="card mb-4 ru-no-print">
        <div class="card-header">
            <h5 class="m-0">Filter Room Schedule</h5>
            <small class="text-muted">Select academic year, semester, and room to view and print its utilization sheet.</small>
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">A.Y.</label>
                    <select id="ru_ay" class="form-select select2-single">
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
                    <select id="ru_semester" class="form-select">
                        <option value="1st"<?= $defaultSemesterUi === '1st' ? ' selected' : '' ?>>1st Semester</option>
                        <option value="2nd"<?= $defaultSemesterUi === '2nd' ? ' selected' : '' ?>>2nd Semester</option>
                        <option value="Midyear"<?= $defaultSemesterUi === 'Midyear' ? ' selected' : '' ?>>Midyear</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Room</label>
                    <select id="ru_room_id" class="form-select select2-single">
                        <option value="">Select Room</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="card" id="roomTimetableCard" style="display:none;">
        <div class="card-header d-flex justify-content-between align-items-start gap-3">
            <div>
                <div class="ru-room-label" id="ruRoomLabel">Room number/name:</div>
                <div class="ru-term-label" id="ruTermLabel"></div>
            </div>
            <button type="button" class="btn btn-outline-primary ru-print-trigger" id="btnPrintRoomUtilization" disabled>
                <i class="bx bx-printer me-1"></i>Print Utilization
            </button>
        </div>
        <div class="card-body">
            <div id="roomTimetableWrapper"></div>
        </div>
    </div>

</div>

<?php include '../footer.php'; ?>

<div id="ruLoader" class="ru-loader d-none">
    <div class="ru-loader-box">
        <div class="spinner-border text-primary mb-2" role="status"></div>
        <div class="small fw-semibold" id="ruLoaderText">Loading room utilization...</div>
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
    const RU_CAMPUS_NAME = <?= json_encode($campus_name) ?>;
    const RU_COLLEGE_NAME = <?= json_encode($college_name) ?>;
    const DAY_START_MINUTES = 7 * 60;
    const DAY_END_MINUTES = 18 * 60;
    const GROUP_ORDER = ["MWF", "TTH", "S", "OTHER"];
    const DAY_SORT = {
        M: 1,
        MW: 2,
        MWF: 3,
        WF: 4,
        W: 5,
        F: 6,
        T: 1,
        TTh: 2,
        Th: 3,
        S: 1
    };
    const PRINT_LAST_PAGE_BODY_ROWS = 11;
    const PRINT_CONTINUATION_PAGE_BODY_ROWS = 14;

    let loaderCount = 0;
    let roomOptionsRequest = null;
    let roomScheduleRequest = null;

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
        $("#ruLoaderText").text(message || "Loading room utilization...");
        $("#ruLoader").removeClass("d-none");
    }

    function hideLoader() {
        loaderCount = Math.max(0, loaderCount - 1);
        if (loaderCount === 0) {
            $("#ruLoader").addClass("d-none");
        }
    }

    function setPrintState(enabled) {
        $("#btnPrintRoomUtilization").prop("disabled", !enabled);
    }

    function formatSemesterLabel(semester) {
        if (!semester) {
            return "";
        }

        return semester === "Midyear" ? "Midyear" : semester + " Semester";
    }

    function formatPrintDate(value) {
        const source = value instanceof Date ? value : new Date();
        const month = String(source.getMonth() + 1).padStart(2, "0");
        const day = String(source.getDate()).padStart(2, "0");
        const year = String(source.getFullYear()).slice(-2);

        return `${month}-${day}-${year}`;
    }

    function updateHeader(roomText, ay, semester) {
        const title = roomText ? "Room number/name: " + roomText : "Room number/name:";
        const meta = [];

        if (ay) {
            meta.push("A.Y. " + ay);
        }

        if (semester) {
            meta.push(formatSemesterLabel(semester));
        }

        $("#ruRoomLabel").text(title);
        $("#ruTermLabel").text(meta.join(" | "));
    }

    function showRoomMessage(message) {
        const roomId = $("#ru_room_id").val();
        const roomText = roomId ? $("#ru_room_id option:selected").text() : "";
        $("#roomTimetableCard").show();
        updateHeader(roomText, $("#ru_ay").val(), $("#ru_semester").val());
        setPrintState(false);
        $("#roomTimetableWrapper").html(
            `<div class="ru-empty-state">${escapeHtml(message)}</div>`
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

    function normalizeDayKey(daysArr) {
        const order = { M: 1, T: 2, W: 3, TH: 4, F: 5, S: 6 };
        const cleaned = (daysArr || [])
            .map(function (day) {
                return String(day || "").toUpperCase().trim();
            })
            .filter(Boolean)
            .filter(function (day, index, array) {
                return array.indexOf(day) === index;
            })
            .sort(function (a, b) {
                return (order[a] || 99) - (order[b] || 99);
            });

        return cleaned.map(function (day) {
            return day === "TH" ? "Th" : day;
        }).join("");
    }

    function tokenizeDayKey(dayKey) {
        return String(dayKey || "").match(/Th|M|T|W|F|S/g) || [];
    }

    function getGroupKey(dayKey) {
        const tokens = tokenizeDayKey(dayKey);

        if (!tokens.length) {
            return "OTHER";
        }

        if (tokens.every(function (token) { return ["M", "W", "F"].indexOf(token) !== -1; })) {
            return "MWF";
        }

        if (tokens.every(function (token) { return ["T", "Th"].indexOf(token) !== -1; })) {
            return "TTH";
        }

        if (tokens.every(function (token) { return token === "S"; })) {
            return "S";
        }

        return "OTHER";
    }

    function buildTimeSegments(rows) {
        const segments = [];
        let cursor = DAY_START_MINUTES;

        if (!rows.length) {
            segments.push({
                isBlank: true,
                start: DAY_START_MINUTES,
                end: DAY_END_MINUTES
            });
            return segments;
        }

        rows.forEach(function (row) {
            let start = timeToMinutes(String(row.time_start || "").substring(0, 5));
            let end = timeToMinutes(String(row.time_end || "").substring(0, 5));

            start = Math.max(DAY_START_MINUTES, start);
            end = Math.min(DAY_END_MINUTES, end);

            if (end <= start) {
                return;
            }

            if (start > cursor) {
                segments.push({
                    isBlank: true,
                    start: cursor,
                    end: start
                });
            }

            segments.push({
                isBlank: false,
                start: start,
                end: end,
                row: row
            });

            cursor = Math.max(cursor, end);
        });

        if (cursor < DAY_END_MINUTES) {
            segments.push({
                isBlank: true,
                start: cursor,
                end: DAY_END_MINUTES
            });
        }

        return segments;
    }

    function buildSubjectCell(row) {
        const subjectCode = String(row.subject_code || "").trim();
        const programMajor = String(row.program_major || "").trim();
        const sectionName = String(row.section_name || "").trim();
        const tailParts = [];

        if (programMajor !== "") {
            tailParts.push(escapeHtml(programMajor));
        }

        if (sectionName !== "") {
            tailParts.push(escapeHtml(sectionName));
        }

        if (subjectCode !== "" && tailParts.length) {
            return `<div class="ru-subject-entry">${escapeHtml(subjectCode)} - ${tailParts.join(" ")}</div>`;
        }

        if (subjectCode !== "") {
            return `<div class="ru-subject-entry">${escapeHtml(subjectCode)}</div>`;
        }

        return tailParts.length ? `<div class="ru-subject-entry">${tailParts.join(" ")}</div>` : "";
    }

    function normalizeProgramCode(value) {
        return String(value || "")
            .trim()
            .toUpperCase()
            .replace(/\s+/g, "");
    }

    function addProgramCodesFromValue(target, value) {
        String(value || "")
            .split("/")
            .map(normalizeProgramCode)
            .filter(Boolean)
            .forEach(function (programCode) {
                target[programCode] = true;
            });
    }

    function buildProgramLabel(rows) {
        const programCodes = {};

        (Array.isArray(rows) ? rows : []).forEach(function (row) {
            addProgramCodesFromValue(programCodes, row.program_code);

            if (Object.keys(programCodes).length > 0) {
                return;
            }

            const sectionMatch = String(row.section_name || "").trim().match(/^([A-Za-z]{2,}[A-Za-z0-9]*)\b/);
            if (sectionMatch) {
                addProgramCodesFromValue(programCodes, sectionMatch[1]);
            }
        });

        return Object.keys(programCodes)
            .sort(function (left, right) {
                return left.localeCompare(right);
            })
            .join("/");
    }

    function buildSignatoryNameHtml(value, dateText) {
        const name = String(value || "").trim();
        const signDate = String(dateText || "").trim();
        const text = name !== "" && signDate !== ""
            ? `${name.toUpperCase()}/${signDate}`
            : name.toUpperCase();

        return text !== "" ? `<span class="ru-print-sign-name">${escapeHtml(text)}</span>` : "";
    }

    function normalizeRoomScheduleResponse(payload) {
        if (Array.isArray(payload)) {
            return {
                rows: payload,
                signatories: {}
            };
        }

        if (payload && Array.isArray(payload.rows)) {
            return {
                rows: payload.rows,
                signatories: payload.signatories && typeof payload.signatories === "object" ? payload.signatories : {}
            };
        }

        return {
            rows: [],
            signatories: {}
        };
    }

    function buildGroupedRows(data) {
        const groups = {
            MWF: [],
            TTH: [],
            S: [],
            OTHER: []
        };

        (Array.isArray(data) ? data : []).forEach(function (item) {
            const dayKey = normalizeDayKey(item.days_raw);
            const groupKey = getGroupKey(dayKey);
            const startMinutes = timeToMinutes(String(item.time_start || "").substring(0, 5));

            groups[groupKey].push($.extend({}, item, {
                _day_key: dayKey,
                _start_minutes: startMinutes
            }));
        });

        Object.keys(groups).forEach(function (groupKey) {
            groups[groupKey].sort(function (a, b) {
                if (a._start_minutes !== b._start_minutes) {
                    return a._start_minutes - b._start_minutes;
                }

                const dayA = DAY_SORT[a._day_key] || 99;
                const dayB = DAY_SORT[b._day_key] || 99;
                if (dayA !== dayB) {
                    return dayA - dayB;
                }

                return String(a.subject_code || "").localeCompare(String(b.subject_code || ""));
            });
        });

        return groups;
    }

    function setRoomOptions(rooms, selectedRoomId) {
        const availableRooms = Array.isArray(rooms) ? rooms : [];
        let html = '<option value="">Select Room</option>';

        if (!availableRooms.length) {
            html = '<option value="">No rooms available for this term</option>';
        } else {
            html += availableRooms.map(function (room) {
                return `<option value="${escapeHtml(room.room_id)}">${escapeHtml(room.label)}</option>`;
            }).join("");
        }

        $("#ru_room_id").html(html);

        const targetRoomId = String(selectedRoomId || "");
        const hasSelectedRoom = availableRooms.some(function (room) {
            return String(room.room_id) === targetRoomId;
        });

        $("#ru_room_id").val(hasSelectedRoom ? targetRoomId : "");

        if ($.fn.select2) {
            $("#ru_room_id").trigger("change.select2");
        }
    }

    function loadRoomOptions() {
        const ay = $("#ru_ay").val();
        const semester = $("#ru_semester").val();
        const currentRoomId = $("#ru_room_id").val();

        if (!ay || !semester) {
            setRoomOptions([], "");
            return createResolvedPromise();
        }

        if (roomOptionsRequest) {
            roomOptionsRequest.abort();
        }

        showLoader("Loading available rooms...");

        roomOptionsRequest = $.ajax({
            url: "../backend/query_room_utilization.php",
            type: "POST",
            dataType: "json",
            data: {
                load_room_options: 1,
                ay: ay,
                semester: semester
            }
        }).done(function (response) {
            const rooms = response && response.status === "ok" ? response.rooms : [];
            setRoomOptions(rooms, currentRoomId);
        }).fail(function (_, textStatus) {
            if (textStatus === "abort") {
                return;
            }

            setRoomOptions([], "");
        }).always(function () {
            roomOptionsRequest = null;
            hideLoader();
        });

        return roomOptionsRequest;
    }

    function buildReportRowItems(groups, visibleGroups) {
        const rowItems = [];

        visibleGroups.forEach(function (groupKey) {
            const rows = groups[groupKey] || [];
            const segments = buildTimeSegments(rows);
            const label = groupKey === "OTHER" ? "OTHER" : groupKey;

            rowItems.push({
                type: "group",
                html: `
                    <tr class="ru-sheet-group">
                        <td colspan="4">${escapeHtml(label)}</td>
                    </tr>
                `
            });

            segments.forEach(function (segment) {
                if (segment.isBlank) {
                    rowItems.push({
                        type: "blank",
                        html: `
                            <tr>
                                <td class="ru-time-cell">${escapeHtml(formatTimeRange(segment.start, segment.end))}</td>
                                <td></td>
                                <td></td>
                                <td></td>
                            </tr>
                        `
                    });
                    return;
                }

                const facultyName = String(segment.row.faculty_name || "TBA").trim() || "TBA";

                rowItems.push({
                    type: "schedule",
                    html: `
                        <tr>
                            <td class="ru-time-cell">${escapeHtml(formatTimeRange(segment.start, segment.end))}</td>
                            <td class="ru-day-cell">${escapeHtml(segment.row._day_key)}</td>
                            <td>${buildSubjectCell(segment.row)}</td>
                            <td class="ru-faculty-name">${escapeHtml(facultyName)}</td>
                        </tr>
                    `
                });
            });
        });

        return rowItems;
    }

    function splitPrintRowItems(rowItems) {
        const remaining = Array.isArray(rowItems) ? rowItems.slice() : [];
        const pages = [];

        if (remaining.length <= PRINT_LAST_PAGE_BODY_ROWS) {
            return [remaining];
        }

        while (remaining.length > PRINT_LAST_PAGE_BODY_ROWS) {
            let takeCount = Math.min(PRINT_CONTINUATION_PAGE_BODY_ROWS, remaining.length - 1);

            if (takeCount > 1 && remaining[takeCount - 1] && remaining[takeCount - 1].type === "group") {
                takeCount -= 1;
            }

            if (takeCount <= 0) {
                takeCount = Math.min(PRINT_CONTINUATION_PAGE_BODY_ROWS, remaining.length);
            }

            pages.push(remaining.splice(0, takeCount));
        }

        if (remaining.length) {
            pages.push(remaining);
        }

        return pages;
    }

    function buildPrintFillerRowHtml() {
        return `
            <tr class="ru-print-filler-row">
                <td>&nbsp;</td>
                <td></td>
                <td></td>
                <td></td>
            </tr>
        `;
    }

    function buildPrintTableHtml(rowItems, fillerTarget) {
        const rows = Array.isArray(rowItems) ? rowItems : [];
        let html = `
            <div class="ru-sheet">
                <div class="table-responsive">
                    <table class="table table-bordered ru-sheet-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Day</th>
                                <th>Subject</th>
                                <th>Faculty</th>
                            </tr>
                        </thead>
                        <tbody>
        `;

        rows.forEach(function (item) {
            html += item.html || "";
        });

        for (let rowCount = rows.length; rowCount < fillerTarget; rowCount += 1) {
            html += buildPrintFillerRowHtml();
        }

        html += `
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        return html;
    }

    function buildPrintSignatureAreaHtml(preparedNameHtml, attestedNameHtml) {
        return `
            <div class="ru-print-signature-area" aria-hidden="true">
                <div class="ru-print-prepared">
                    <div class="ru-print-signature-label">Prepared by:</div>
                    <div class="ru-print-sign-line">${preparedNameHtml}</div>
                    <div class="ru-print-sign-caption">Program Chairman</div>
                </div>
                <div class="ru-print-attested">
                    <div class="ru-print-signature-label">Attested by:</div>
                    <div class="ru-print-sign-line">${attestedNameHtml}</div>
                    <div>Dean</div>
                </div>
                <div class="ru-print-cc">
                    <div class="ru-print-cc-title">cc:</div>
                    <div class="ru-print-cc-list">
                        <div>1 - VP AA</div>
                        <div>1 - Registrar</div>
                        <div>1 - Dean</div>
                        <div>1 - Program Chairman</div>
                    </div>
                </div>
                <div class="ru-print-ack-box">
                    <div class="ru-print-ack-title">ACKNOWLEDGEMENT RECEIPT:</div>
                    <div class="ru-print-ack-row">Date:</div>
                    <div class="ru-print-ack-row">Time:</div>
                    <div class="ru-print-ack-row">By:</div>
                    <div class="ru-print-ack-sign"></div>
                    <div class="ru-print-ack-caption">
                        Name &amp; Signature of<br>Authorized Representative
                    </div>
                </div>
            </div>
        `;
    }

    function buildPrintSheetHtml(meta) {
        return `
            <div class="ru-print-sheet">
                <img src="../assets/img/print/classroom-utilization-template.png" alt="" class="ru-print-form-background" aria-hidden="true">
                <div class="ru-print-overlay">
                    <div class="ru-print-title">CLASSROOM UTILIZATION</div>
                    <div class="ru-print-field ru-print-campus-field">${escapeHtml(meta.campusName)}</div>
                    <div class="ru-print-field ru-print-term-field">${escapeHtml(meta.termText)}</div>
                    <div class="ru-print-meta-line ru-print-college-line">
                        <span class="ru-print-meta-label">College:</span>
                        <span class="ru-print-meta-value">${escapeHtml(meta.collegeName)}</span>
                    </div>
                    <div class="ru-print-meta-line ru-print-program-line">
                        <span class="ru-print-meta-label">Program:</span>
                        <span class="ru-print-meta-value">${escapeHtml(meta.programLabel)}</span>
                    </div>
                    <div class="ru-print-meta-line ru-print-room-line">
                        <span class="ru-print-meta-label">Room number/name:</span>
                        <span class="ru-print-meta-value">${escapeHtml(meta.roomText)}</span>
                    </div>
                    <div class="ru-print-table-area">
                        ${meta.tableHtml}
                    </div>
                    ${meta.signatureHtml}
                </div>
            </div>
        `;
    }

    function renderRoomReport(data, signatories) {
        const ay = $("#ru_ay").val();
        const semester = $("#ru_semester").val();
        const roomId = $("#ru_room_id").val();
        const roomText = roomId ? $("#ru_room_id option:selected").text() : "";
        const termText = semester ? `${formatSemesterLabel(semester)} | AY ${ay || ""}`.trim() : (ay ? `AY ${ay}` : "");
        const programLabel = buildProgramLabel(data);
        const printSignatories = signatories && typeof signatories === "object" ? signatories : {};
        const preparedBy = printSignatories.prepared_by && typeof printSignatories.prepared_by === "object"
            ? printSignatories.prepared_by
            : {};
        const attestedBy = printSignatories.attested_by && typeof printSignatories.attested_by === "object"
            ? printSignatories.attested_by
            : {};
        const printDate = formatPrintDate(new Date());
        const preparedNameHtml = buildSignatoryNameHtml(preparedBy.name, printDate);
        const attestedNameHtml = buildSignatoryNameHtml(attestedBy.name, printDate);
        const groups = buildGroupedRows(data);
        const visibleGroups = GROUP_ORDER.filter(function (groupKey) {
            return groupKey !== "OTHER" || groups.OTHER.length;
        });
        const rowItems = buildReportRowItems(groups, visibleGroups);
        const printPages = splitPrintRowItems(rowItems);

        $("#roomTimetableCard").show();
        updateHeader(roomText, ay, semester);
        setPrintState(Boolean(roomId));

        const html = printPages.map(function (pageRows, pageIndex) {
            const isLastPage = pageIndex === printPages.length - 1;
            const fillerTarget = isLastPage ? PRINT_LAST_PAGE_BODY_ROWS : PRINT_CONTINUATION_PAGE_BODY_ROWS;

            return buildPrintSheetHtml({
                campusName: RU_CAMPUS_NAME,
                termText: termText,
                collegeName: RU_COLLEGE_NAME,
                programLabel: programLabel,
                roomText: roomText,
                tableHtml: buildPrintTableHtml(pageRows, fillerTarget),
                signatureHtml: isLastPage ? buildPrintSignatureAreaHtml(preparedNameHtml, attestedNameHtml) : ""
            });
        }).join("");

        $("#roomTimetableWrapper").html(html);
    }

    function loadRoomSchedule() {
        const ay = $("#ru_ay").val();
        const semester = $("#ru_semester").val();
        const roomId = $("#ru_room_id").val();

        if (!ay || !semester || !roomId) {
            showRoomMessage("Select academic year, semester, and room to view the utilization sheet.");
            return createResolvedPromise();
        }

        if (roomScheduleRequest) {
            roomScheduleRequest.abort();
        }

        showLoader("Loading room schedule...");

        roomScheduleRequest = $.ajax({
            url: "../backend/query_room_utilization.php",
            type: "POST",
            dataType: "json",
            data: {
                load_room_schedule: 1,
                ay: ay,
                semester: semester,
                room_id: roomId
            }
        }).done(function (data) {
            const normalized = normalizeRoomScheduleResponse(data);
            renderRoomReport(normalized.rows, normalized.signatories);
        }).fail(function (_, textStatus) {
            if (textStatus === "abort") {
                return;
            }

            showRoomMessage("Unable to load the selected room schedule right now.");
        }).always(function () {
            roomScheduleRequest = null;
            hideLoader();
        });

        return roomScheduleRequest;
    }

    function reloadCurrentView() {
        const ay = $("#ru_ay").val();
        const semester = $("#ru_semester").val();
        const roomId = $("#ru_room_id").val();

        if (!ay || !semester || !roomId) {
            showRoomMessage("Select academic year, semester, and room to view the utilization sheet.");
            return;
        }

        loadRoomSchedule();
    }

    $("#ru_ay, #ru_semester").on("change", function () {
        loadRoomOptions().always(function () {
            reloadCurrentView();
        });
    });

    $("#ru_room_id").on("change", function () {
        reloadCurrentView();
    });

    $("#btnPrintRoomUtilization").on("click", function () {
        if ($(this).prop("disabled")) {
            return;
        }

        window.print();
    });

    showRoomMessage("Select academic year, semester, and room to view the utilization sheet.");

    setTimeout(function () {
        loadRoomOptions().always(function () {
            reloadCurrentView();
        });
    }, 200);
});
</script>

</body>
</html>
