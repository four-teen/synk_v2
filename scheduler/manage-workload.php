<?php 
session_start();
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
ob_start();
include '../backend/db.php';
require_once '../backend/academic_term_helper.php';
require_once '../backend/schema_helper.php';
require_once '../backend/faculty_need_helper.php';
require_once '../backend/signatory_settings_helper.php';

/* =====================================================
   WORKLOAD COMPUTATION CONFIG
   NOTE:
   - 1 LAB unit = 3 contact hours
   - Each contact hour x LAB_LOAD_MULTIPLIER
   - This is intentionally hardcoded for now
   - Future: move to settings table
===================================================== */

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'scheduler') {
    header("Location: ../index.php");
    exit;
}

function workload_report_signatory_slot(array $settings, string $slotCode, string $fallbackLabel): array
{
    $row = is_array($settings[$slotCode] ?? null) ? $settings[$slotCode] : [];

    return [
        'slot_code' => $slotCode,
        'label' => trim((string)($row['label'] ?? $fallbackLabel)) ?: $fallbackLabel,
        'signatory_name' => trim((string)($row['signatory_name'] ?? '')),
        'signatory_title' => trim((string)($row['signatory_title'] ?? '')),
    ];
}

$college_id   = $_SESSION['college_id'];
$college_name = $_SESSION['college_name'] ?? '';
$campus_name  = '';
$currentTerm = synk_fetch_current_academic_term($conn);
$defaultAyId = (int)$currentTerm['ay_id'];
$defaultAyLabel = (string)$currentTerm['ay_label'];
$defaultSemesterUi = '';

if ((int)$college_id > 0) {
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

// LOAD FACULTY ASSIGNED TO THIS COLLEGE
$faculty_options = "";
$faculty_need_options = "";
$assignmentHasAyId = synk_table_has_column($conn, 'tbl_college_faculty', 'ay_id');
$assignmentHasSemester = synk_table_has_column($conn, 'tbl_college_faculty', 'semester');
$facultySql = "
    SELECT DISTINCT f.faculty_id,
           CONCAT(f.last_name, ', ', f.first_name, ' ', COALESCE(f.ext_name,'')) AS full_name
    FROM tbl_college_faculty cf
    JOIN tbl_faculty f ON cf.faculty_id = f.faculty_id
    WHERE cf.college_id = ?
      AND cf.status = 'active'
      AND f.status  = 'active'
";

$facultyTypes = 'i';
$facultyParams = [(int)$college_id];

if ($assignmentHasAyId) {
    $facultySql .= " AND cf.ay_id = ?";
    $facultyTypes .= 'i';
    $facultyParams[] = (int)$defaultAyId;
}

if ($assignmentHasSemester) {
    $facultySql .= " AND cf.semester = ?";
    $facultyTypes .= 'i';
    $facultyParams[] = (int)$currentTerm['semester'];
}

$facultySql .= " ORDER BY f.last_name, f.first_name";

$facultyStmt = $conn->prepare($facultySql);
if ($facultyStmt instanceof mysqli_stmt) {
    synk_bind_dynamic_params($facultyStmt, $facultyTypes, $facultyParams);
    $facultyStmt->execute();
    $facultyResult = $facultyStmt->get_result();

    while ($f = $facultyResult->fetch_assoc()) {
        $fid = (int)$f['faculty_id'];
        $fname = htmlspecialchars((string)$f['full_name'], ENT_QUOTES, 'UTF-8');
        $faculty_options .= "<option value='{$fid}' data-assignee-type='faculty'>{$fname}</option>";
    }

    $facultyStmt->close();
}

foreach (synk_faculty_need_fetch_options($conn, (int)$college_id, (int)$defaultAyId, (int)$currentTerm['semester']) as $need) {
    $needId = (int)($need['faculty_need_id'] ?? 0);
    $needLabel = htmlspecialchars((string)($need['need_label'] ?? ''), ENT_QUOTES, 'UTF-8');
    if ($needId > 0 && $needLabel !== '') {
        $faculty_need_options .= "<option value='need:{$needId}' data-assignee-type='faculty_need' data-faculty-need-id='{$needId}'>{$needLabel}</option>";
    }
}

$globalSignatorySettings = synk_fetch_signatory_settings($conn, 'global', 0);
$collegeSignatorySettings = (int)$college_id > 0 ? synk_fetch_signatory_settings($conn, 'college', (int)$college_id) : [];
$consolidatedReportSignatories = [
    'prepared_by' => workload_report_signatory_slot($collegeSignatorySettings, 'prepared_by', 'Prepared by'),
    'checked_by_left' => workload_report_signatory_slot($globalSignatorySettings, 'checked_by_left', 'Checked by'),
    'checked_by_right' => workload_report_signatory_slot($globalSignatorySettings, 'checked_by_right', 'Checked by'),
    'recommending_approval' => workload_report_signatory_slot($globalSignatorySettings, 'recommending_approval', 'Recommending Approval'),
    'approved_by' => workload_report_signatory_slot($globalSignatorySettings, 'approved_by', 'Approved by'),
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
    <title>Faculty Workload | Synk Scheduler</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css"/>

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
        .fw-summary-label {
            font-size: 0.85rem;
            color: #6c757d;
        }

        .table-sm td {
            padding-top: 0.5rem !important;
            padding-bottom: 0.5rem !important;
        }

        #facultyAlert .alert {
            background: #eaf8ff;
            border-color: #cbeaf8;
            color: #1a7da8;
        }

        .workload-card {
            border: 1px solid #dbe5f1;
            box-shadow: 0 2px 8px rgba(18, 38, 63, 0.05);
        }

        .workload-table thead th {
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #5f728b;
            border-bottom: 1px solid #dbe4ef;
            border-top: 1px solid #dbe4ef;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.95), inset 0 -1px 0 rgba(204, 216, 229, 0.9);
            white-space: nowrap;
        }

        .workload-table tbody td {
            color: #5c6f88;
            border-color: #e7edf5;
            vertical-align: middle;
        }

        .workload-table tbody .workload-group-start > td {
            border-top: 2px solid #d7e1ec;
        }

        .workload-table tbody .workload-group-end > td,
        .workload-table tbody .workload-group-start > td[rowspan] {
            border-bottom: 2px solid #d7e1ec;
        }

        .workload-table tfoot th,
        .workload-table tfoot td {
            color: #5f728b;
            border-top: 2px solid #d7e1ec;
            background: #f9fbfd;
            vertical-align: middle;
        }

        .workload-code {
            font-weight: 700;
            color: #5b6f86;
            white-space: nowrap;
        }

        .workload-desc {
            color: #5f728b;
        }

        .program-major-note {
            display: block;
            margin-top: 0.18rem;
            color: #7890ab;
            font-size: 0.72rem;
            font-weight: 600;
            line-height: 1.25;
        }

        .workload-days,
        .workload-room {
            white-space: nowrap;
        }

        .workload-time {
            white-space: normal;
            line-height: 1.08;
            min-width: 88px;
        }

        .time-line {
            display: block;
            white-space: nowrap;
        }

        .merged-metric {
            vertical-align: middle !important;
            background: #fbfcfe;
            font-weight: 600;
        }

        .workload-summary-row th,
        .workload-summary-row td {
            background: #f9fbfd;
            font-size: inherit;
            border-top: 1px solid #d7e1ec;
            border-bottom: 1px solid #d7e1ec;
        }

        .workload-summary-label {
            color: #52657d;
            font-weight: 700;
            white-space: nowrap;
        }

        .workload-summary-value {
            color: #4f6279;
            font-weight: 600;
        }

        .screen-only {
            white-space: nowrap;
        }

        .summary-separator th,
        .summary-separator td {
            border-top: 2px solid #b9c8d9 !important;
        }

        .workload-total-row th,
        .workload-total-row td {
            border-top: 2px solid #b7c6d8 !important;
            font-size: inherit;
            background: #f6f9fc;
        }

        .external-workload-divider td {
            background: #fff7e6;
            color: #8a5a00;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }

        .external-workload-row td {
            background: #fffdf8;
            color: #5f6f83;
        }

        .external-workload-row .workload-code {
            border-left: 4px solid #f0b84e;
        }

        .workload-external-note {
            display: block;
            margin-top: 0.28rem;
            font-size: 0.72rem;
            font-weight: 700;
            color: #9a6512;
        }

        .workload-external-note-print {
            display: none;
        }

        .external-workload-lock {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: 0.28rem 0.55rem;
            border-radius: 999px;
            border: 1px solid #f1d08e;
            background: #fff6dc;
            color: #8a5a00;
            font-size: 0.74rem;
            font-weight: 700;
            white-space: nowrap;
        }

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

        .total-load-value {
            font-size: inherit;
            font-weight: 700;
        }

        .print-total-value {
            display: none;
        }

        .print-only-table-cell {
            display: none;
        }

        .workload-total-load-screen {
            padding-left: 0.75rem !important;
            padding-right: 1rem !important;
        }

        .total-load-screen-inner {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            align-items: center;
            width: 100%;
            gap: 0.5rem;
        }

        .total-load-screen-inner .total-load-value {
            grid-column: 1;
            justify-self: center;
        }

        .total-load-screen-inner .load-status-inline {
            grid-column: 2 / 4;
            justify-self: start;
            margin-left: 0;
        }

        .load-status-inline.underload {
            background: #fff3cd;
            color: #7a5a00;
        }

        .load-status-inline.overload {
            background: #fde8ea;
            color: #a61c2d;
        }

        .print-sheet-header {
            display: none;
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: none;
            margin: 0 0 0.45rem;
            padding-top: 0.08rem;
            padding-bottom: 0.12rem;
        }

        .print-sheet-title {
            font-family: Arial, sans-serif;
            font-size: 1.16rem;
            font-weight: 700;
            letter-spacing: 0.02em;
            text-align: center;
            color: #000;
            line-height: 1.1;
        }

        .print-sheet-subtitle {
            font-family: Arial, sans-serif;
            font-size: 0.8rem;
            text-align: center;
            color: #000;
            margin-top: 0.08rem;
        }

        .print-sheet-subtitle-spaced {
            margin-bottom: 0.14rem;
        }

        .print-sheet-subtitle-line {
            display: flex;
            justify-content: center;
            align-items: flex-end;
            gap: 0.4rem;
        }

        .print-inline-label {
            white-space: nowrap;
        }

        .workload-start-panel {
            position: relative;
            overflow: hidden;
            border: 1px solid #e3ebf5;
            border-radius: 18px;
            background:
                radial-gradient(circle at 14% 22%, rgba(29, 145, 219, 0.13), transparent 26%),
                radial-gradient(circle at 86% 18%, rgba(34, 160, 107, 0.11), transparent 24%),
                linear-gradient(135deg, #ffffff 0%, #f7fbff 52%, #fffaf2 100%);
            box-shadow: 0 16px 38px rgba(55, 74, 96, 0.08);
        }

        .workload-start-panel::after {
            content: "";
            position: absolute;
            right: -4rem;
            bottom: -6rem;
            width: 18rem;
            height: 18rem;
            border-radius: 999px;
            border: 36px solid rgba(93, 104, 244, 0.055);
            pointer-events: none;
        }

        .workload-start-body {
            position: relative;
            z-index: 1;
            max-width: 820px;
            margin: 0 auto;
            padding: 3.8rem 1.5rem;
            text-align: center;
        }

        .workload-start-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 1.2rem;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #eef7ff;
            color: #1479b8;
            box-shadow: 0 12px 28px rgba(20, 121, 184, 0.14);
            font-size: 2rem;
        }

        .workload-start-title {
            margin: 0;
            color: #43546a;
            font-weight: 800;
            letter-spacing: 0.01em;
        }

        .workload-start-copy {
            max-width: 660px;
            margin: 0.65rem auto 0;
            color: #6f7f94;
            line-height: 1.6;
        }

        .workload-start-chips {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.55rem;
            margin: 1.35rem 0 1.55rem;
        }

        .workload-start-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.45rem 0.75rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.76);
            border: 1px solid rgba(126, 151, 180, 0.18);
            color: #52657d;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .workload-start-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.75rem;
        }

        .print-sheet-line {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem 1.25rem;
            margin-top: 0.22rem;
            width: 100%;
            font-family: Arial, sans-serif;
            font-size: 0.76rem;
            color: #000;
        }

        .print-field {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 0.35rem;
            align-items: end;
        }

        .print-field-label {
            white-space: nowrap;
        }

        .print-line-value {
            display: inline-block;
            min-width: 0;
            width: 100%;
            border-bottom: 1px solid #000;
            padding: 0 0.18rem 0.08rem;
            line-height: 1.2;
        }

        .print-line-value.is-filled {
            border-bottom-color: #000;
        }

        .print-line-value.is-blank {
            border-bottom-color: #000;
        }

        .print-line-value.no-underline {
            width: auto;
            border-bottom: none;
            padding: 0 0.18rem;
        }

        .print-line-value.no-underline.is-filled,
        .print-line-value.no-underline.is-blank {
            border-bottom: none;
        }

        .print-sheet-line.single {
            grid-template-columns: 1fr;
        }

        .print-only-block {
            display: none;
        }

        .print-form-background {
            display: none;
            max-width: 100%;
        }

        .print-workload-body {
            position: relative;
        }

        .print-workload-footer {
            margin-top: 0.75rem;
            font-family: Arial, sans-serif;
            color: #000;
        }

        .print-signatory-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.8rem 1.25rem;
            align-items: start;
        }

        .print-signatory {
            font-size: 0.8rem;
        }

        .print-signatory.full-width {
            grid-column: 1 / -1;
            width: 52%;
            margin: 0 auto;
        }

        .print-sign-label {
            margin-bottom: 0.18rem;
            font-weight: 400;
        }

        .print-sign-line {
            min-height: 1.1rem;
            border-bottom: 1px solid #bcc7d3;
            padding: 0 0.18rem 0.06rem;
            font-weight: 800;
            line-height: 1.15;
        }

        #printConforme,
        #printPreparedBy,
        #printApprovedBy {
            text-align: center;
        }

        .print-sign-line.is-filled {
            border-bottom-color: #bcc7d3;
        }

        .print-sign-line.short {
            width: 70%;
            margin: 0.4rem auto 0;
        }

        .print-sign-caption {
            margin-top: 0.08rem;
            text-align: center;
            font-size: 0.78rem;
            min-height: 0.9rem;
        }

        .print-sign-uppercase {
            text-transform: uppercase;
        }

        .print-meta-grid {
            display: grid;
            grid-template-columns: 1.35fr 1fr;
            gap: 0.9rem 1rem;
            margin-top: 0.7rem;
            align-items: start;
        }

        .print-note-block,
        .print-cc-block,
        .print-ack-box {
            font-size: 0.67rem;
            line-height: 1.2;
        }

        .print-note-title,
        .print-cc-title,
        .print-ack-title {
            font-weight: 700;
            margin-bottom: 0.12rem;
        }

        .print-note-line,
        .print-cc-line,
        .print-ack-line {
            margin: 0.06rem 0;
        }

        .print-note-line.indent {
            padding-left: 1.5rem;
        }

        .print-ack-box {
            border: 1px dashed #8b97a6;
            padding: 0.35rem 0.45rem;
            margin-top: 0.45rem;
        }

        .pair-note {
            display: block;
            margin-top: 0.2rem;
            font-size: 0.7rem;
            color: #7a8aa0;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .paired-anchor {
            background: #fbfcfe;
        }

        .paired-row td {
            background-image: linear-gradient(to right, rgba(88, 116, 255, 0.04), rgba(88, 116, 255, 0));
        }

        .schedule-partner-note {
            display: block;
            margin-top: 0.2rem;
            font-size: 0.72rem;
            color: #6f7f96;
        }

        .print-type-suffix {
            display: none;
        }

        .workload-merge-note,
        .scheduled-merge-note {
            display: block;
            margin-top: 0.28rem;
            font-size: 0.72rem;
            font-weight: 600;
            color: #556b85;
            line-height: 1.25;
        }

        .type-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 42px;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .type-pill.lec {
            background: #e8e9ff;
            color: #5d68f4;
        }

        .type-pill.lab {
            background: #fff0cf;
            color: #c98900;
        }

        #scheduledClassTbody tr.linked-schedule-row td {
            background-image: linear-gradient(to right, rgba(93, 104, 244, 0.045), rgba(93, 104, 244, 0));
        }

        #scheduledClassTbody tr.linked-schedule-row td:first-child {
            position: relative;
            padding-left: 1.35rem;
        }

        #scheduledClassTbody tr.linked-schedule-row td:first-child::before {
            content: "";
            position: absolute;
            left: 0.45rem;
            width: 4px;
            background: var(--schedule-pair-color, #5d68f4);
            box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.85);
        }

        #scheduledClassTbody tr.linked-schedule-start td:first-child::before {
            top: 22%;
            bottom: -1px;
            border-radius: 999px 999px 0 0;
        }

        #scheduledClassTbody tr.linked-schedule-middle td:first-child::before {
            top: -1px;
            bottom: -1px;
            border-radius: 0;
        }

        #scheduledClassTbody tr.linked-schedule-end td:first-child::before {
            top: -1px;
            bottom: 22%;
            border-radius: 0 0 999px 999px;
        }

        #scheduledClassTbody tr.schedule-conflict-disabled td {
            opacity: 0.58;
            background-image: linear-gradient(to right, rgba(220, 53, 69, 0.06), rgba(220, 53, 69, 0));
        }

        #scheduledClassTbody tr.schedule-conflict-disabled .chkSchedule {
            cursor: not-allowed;
        }

        .scheduled-pair-note {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            margin-top: 0.35rem;
            font-size: 0.72rem;
            font-weight: 600;
            color: #5f728b;
        }

        .scheduled-pair-note::before {
            content: "";
            width: 0.55rem;
            height: 0.55rem;
            border-radius: 999px;
            background: var(--schedule-pair-color, #5d68f4);
            box-shadow: 0 0 0 2px rgba(93, 104, 244, 0.12);
        }

        .floating-apply-bar {
            position: fixed;
            right: 1.25rem;
            bottom: 1rem;
            z-index: 1030;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            min-width: 320px;
            max-width: min(480px, calc(100vw - 2rem));
            padding: 0.85rem 1rem;
            border: 1px solid #d9e4f2;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.96);
            box-shadow: 0 18px 40px rgba(18, 38, 63, 0.16);
            backdrop-filter: blur(14px);
            opacity: 0;
            transform: translateY(18px);
            pointer-events: none;
            transition: opacity 0.22s ease, transform 0.22s ease;
        }

        .floating-apply-bar.is-visible {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }

        .scheduler-activity-indicator {
            position: fixed;
            right: 1.25rem;
            bottom: 6.2rem;
            z-index: 1032;
            min-width: 300px;
            max-width: min(92vw, 360px);
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 0.9rem 1rem;
            border: 1px solid rgba(93, 104, 244, 0.14);
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.97);
            box-shadow: 0 20px 40px rgba(18, 38, 63, 0.16);
            backdrop-filter: blur(14px);
            opacity: 0;
            transform: translateY(14px);
            pointer-events: none;
            transition: opacity 0.18s ease, transform 0.18s ease;
        }

        .scheduler-activity-indicator.is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        .scheduler-activity-spinner {
            width: 2rem;
            height: 2rem;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(93, 104, 244, 0.08);
            color: #5d68f4;
            flex: 0 0 auto;
        }

        .scheduler-activity-copy {
            min-width: 0;
        }

        .scheduler-activity-title {
            font-size: 0.92rem;
            font-weight: 700;
            color: #22354a;
            line-height: 1.2;
        }

        .scheduler-activity-meta {
            margin-top: 0.16rem;
            font-size: 0.78rem;
            color: #6b7d92;
            line-height: 1.28;
        }

        .faculty-browser-launcher {
            position: fixed;
            right: 0.9rem;
            top: 52%;
            transform: translateY(-50%);
            z-index: 1031;
            border: 0;
            border-radius: 22px;
            background: linear-gradient(180deg, #eef3ff 0%, #dfe8ff 100%);
            color: #4154d8;
            box-shadow: 0 20px 42px rgba(65, 84, 216, 0.2);
            padding: 0.95rem 0.65rem;
            display: inline-flex;
            flex-direction: column;
            align-items: center;
            gap: 0.55rem;
            transition: transform 0.2s ease, box-shadow 0.2s ease, right 0.2s ease;
        }

        .faculty-browser-launcher:hover,
        .faculty-browser-launcher:focus-visible {
            right: 1.1rem;
            transform: translateY(-50%) scale(1.01);
            box-shadow: 0 24px 48px rgba(65, 84, 216, 0.24);
        }

        .faculty-browser-launcher:focus-visible {
            outline: 3px solid rgba(65, 84, 216, 0.2);
            outline-offset: 2px;
        }

        .faculty-browser-launcher-icon {
            width: 2.2rem;
            height: 2.2rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.82);
            box-shadow: inset 0 0 0 1px rgba(91, 108, 255, 0.12);
            font-size: 1.15rem;
        }

        .faculty-browser-launcher-label {
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-size: 0.7rem;
            font-weight: 800;
        }

        #facultyBrowserDrawer {
            width: min(620px, calc(100vw - 1rem));
            border-left: 1px solid #dbe3f6;
            box-shadow: -24px 0 48px rgba(25, 40, 90, 0.14);
        }

        #facultyBrowserDrawer .offcanvas-header {
            padding: 1.2rem 1.2rem 1rem;
            background:
                radial-gradient(circle at top right, rgba(120, 196, 255, 0.18), transparent 44%),
                linear-gradient(180deg, #edf4ff 0%, #f8fbff 100%);
            border-bottom: 1px solid #dbe3f6;
        }

        .faculty-browser-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .faculty-browser-title-icon {
            width: 2.7rem;
            height: 2.7rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
            color: #4154d8;
            background: rgba(255, 255, 255, 0.9);
            box-shadow: inset 0 0 0 1px rgba(91, 108, 255, 0.12);
            font-size: 1.3rem;
        }

        .faculty-browser-title h5 {
            margin: 0;
            font-weight: 700;
            color: #22304c;
        }

        .faculty-browser-title p {
            margin: 0.2rem 0 0;
            color: #687796;
            font-size: 0.86rem;
        }

        #facultyBrowserDrawer .offcanvas-body {
            padding: 1.2rem;
            background:
                radial-gradient(circle at top left, rgba(227, 237, 255, 0.62), transparent 32%),
                linear-gradient(180deg, #fbfcff 0%, #f6f9ff 100%);
        }

        .faculty-browser-summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 0.75rem;
            margin-bottom: 0.7rem;
        }

        .faculty-browser-metric {
            border: 1px solid #dbe4f6;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.88);
            padding: 0.85rem;
            box-shadow: 0 12px 28px rgba(29, 43, 88, 0.05);
        }

        .faculty-browser-metric-label {
            display: block;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #7887a4;
        }

        .faculty-browser-metric-value {
            display: block;
            margin-top: 0.3rem;
            font-size: 1.35rem;
            font-weight: 800;
            line-height: 1;
            color: #22304c;
        }

        .faculty-browser-classification-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.4rem;
            margin: 0 0 1rem;
            color: #6f7f9b;
            font-size: 0.78rem;
        }

        .faculty-browser-classification-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            border: 1px solid #dde7f7;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.86);
            padding: 0.24rem 0.55rem;
            line-height: 1.2;
            box-shadow: 0 8px 18px rgba(29, 43, 88, 0.04);
            min-width: 0;
            white-space: nowrap;
        }

        .faculty-browser-classification-badge.is-permanent {
            border-color: #cdebd9;
            background: #eefaf3;
            color: #1f6d44;
        }

        .faculty-browser-classification-badge.is-cos {
            border-color: #d7e4ff;
            background: #f1f6ff;
            color: #315fa8;
        }

        .faculty-browser-classification-badge.is-part-time {
            border-color: #f7dfb1;
            background: #fff8e8;
            color: #815c07;
        }

        .faculty-browser-classification-badge.is-faculty-need {
            border-color: #ead6ff;
            background: #faf5ff;
            color: #6b3ba7;
        }

        .faculty-browser-classification-label {
            font-weight: 700;
        }

        .faculty-browser-classification-value {
            font-weight: 800;
        }

        .faculty-browser-note {
            margin-bottom: 1rem;
            color: #6d7c98;
            font-size: 0.84rem;
        }

        .faculty-browser-section {
            border: 1px solid #dfe7f7;
            border-radius: 20px;
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 14px 30px rgba(29, 43, 88, 0.05);
            padding: 1rem;
        }

        .faculty-browser-section + .faculty-browser-section {
            margin-top: 1rem;
        }

        .faculty-browser-section-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 0.75rem;
            margin-bottom: 0.9rem;
        }

        .faculty-browser-status-chip {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            border-radius: 999px;
            padding: 0.35rem 0.75rem;
            font-size: 0.77rem;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .faculty-browser-status-chip.is-overload {
            background: #fde8ea;
            color: #a61c2d;
        }

        .faculty-browser-status-chip.is-normal {
            background: #e7f5ef;
            color: #0f6a42;
        }

        .faculty-browser-status-chip.is-underload {
            background: #fff3cd;
            color: #7a5a00;
        }

        .faculty-browser-section-subtitle {
            margin-top: 0.2rem;
            color: #74839e;
            font-size: 0.8rem;
        }

        .faculty-browser-section-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 0.28rem 0.65rem;
            background: #f1f5ff;
            color: #51617f;
            font-size: 0.74rem;
            font-weight: 700;
        }

        .faculty-browser-list {
            display: grid;
            gap: 0.8rem;
        }

        .faculty-browser-card {
            width: 100%;
            border: 1px solid #e3eaf8;
            border-radius: 18px;
            background: linear-gradient(180deg, #ffffff 0%, #fcfdff 100%);
            padding: 0.9rem 0.95rem;
            display: flex;
            justify-content: space-between;
            gap: 0.9rem;
            align-items: flex-start;
            text-align: left;
            cursor: pointer;
            box-shadow: 0 8px 18px rgba(29, 43, 88, 0.04);
            transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        }

        .faculty-browser-card:hover,
        .faculty-browser-card:focus-visible {
            transform: translateY(-1px);
            box-shadow: 0 14px 28px rgba(29, 43, 88, 0.08);
        }

        .faculty-browser-card:focus-visible {
            outline: 3px solid rgba(65, 84, 216, 0.18);
            outline-offset: 2px;
        }

        .faculty-browser-card.is-current {
            border-color: #7d8bff;
            box-shadow: 0 16px 34px rgba(67, 86, 219, 0.12);
        }

        .faculty-browser-card.is-overload {
            border-color: rgba(166, 28, 45, 0.18);
        }

        .faculty-browser-card.is-normal {
            border-color: rgba(15, 106, 66, 0.18);
        }

        .faculty-browser-card.is-underload {
            border-color: rgba(122, 90, 0, 0.18);
        }

        .faculty-browser-name {
            font-size: 0.96rem;
            font-weight: 700;
            line-height: 1.25;
        }

        .faculty-browser-name.is-overload {
            color: #a61c2d;
        }

        .faculty-browser-name.is-normal {
            color: #0f6a42;
        }

        .faculty-browser-name.is-underload {
            color: #7a5a00;
        }

        .faculty-browser-subtext {
            margin-top: 0.28rem;
            color: #73829d;
            font-size: 0.81rem;
        }

        .faculty-browser-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 0.42rem;
            margin-top: 0.65rem;
        }

        .faculty-browser-tag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 0.24rem 0.55rem;
            font-size: 0.72rem;
            font-weight: 700;
            background: #eef3ff;
            color: #50617e;
        }

        .faculty-browser-tag.is-overload {
            background: #fde8ea;
            color: #a61c2d;
        }

        .faculty-browser-tag.is-normal {
            background: #e7f5ef;
            color: #0f6a42;
        }

        .faculty-browser-tag.is-underload {
            background: #fff3cd;
            color: #7a5a00;
        }

        .faculty-browser-load {
            min-width: 88px;
            text-align: right;
        }

        .faculty-browser-load-number {
            display: block;
            font-size: 1.35rem;
            line-height: 1;
            font-weight: 800;
            color: #22304c;
        }

        .faculty-browser-load-label {
            display: block;
            margin-top: 0.25rem;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #7b89a4;
        }

        .faculty-browser-empty {
            border: 1px dashed #cedaf4;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.82);
            color: #60708f;
            text-align: center;
            padding: 1.15rem;
            font-size: 0.9rem;
        }

        .faculty-browser-empty .spinner-border {
            width: 1rem;
            height: 1rem;
            margin-right: 0.5rem;
            vertical-align: text-bottom;
        }

        .floating-apply-meta {
            min-width: 0;
        }

        .floating-apply-label {
            display: block;
            font-size: 0.78rem;
            font-weight: 700;
            color: #4f6279;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .floating-apply-count {
            display: block;
            margin-top: 0.12rem;
            font-size: 0.82rem;
            color: #6b7e96;
            white-space: nowrap;
        }

        @media (max-width: 767.98px) {
            .floating-apply-bar {
                left: 1rem;
                right: 1rem;
                bottom: 0.85rem;
                min-width: 0;
                flex-wrap: wrap;
                gap: 0.75rem;
                padding: 0.8rem 0.9rem;
            }

            .floating-apply-bar .btn {
                width: 100%;
            }

            .scheduler-activity-indicator {
                left: 1rem;
                right: 1rem;
                bottom: 7.2rem;
                min-width: 0;
                max-width: none;
            }

            .faculty-browser-launcher {
                top: auto;
                bottom: 1rem;
                right: 1rem;
                transform: none;
                flex-direction: row;
                padding: 0.8rem 1rem;
                border-radius: 999px;
            }

            .faculty-browser-launcher:hover,
            .faculty-browser-launcher:focus-visible {
                right: 1rem;
                transform: translateY(-2px);
            }

            .faculty-browser-launcher-label {
                writing-mode: initial;
                transform: none;
                letter-spacing: 0.08em;
            }

            .faculty-browser-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .faculty-browser-classification-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 575.98px) {
            #facultyBrowserDrawer {
                width: 100vw;
            }

            .faculty-browser-summary {
                grid-template-columns: 1fr;
            }

            .faculty-browser-classification-summary {
                grid-template-columns: 1fr;
            }

            .faculty-browser-section-header,
            .faculty-browser-card {
                flex-direction: column;
            }

            .faculty-browser-load {
                min-width: 0;
                text-align: left;
            }
        }

        .btn-delete-workload {
            border-color: #ff5f4d;
            color: #ff5f4d;
            line-height: 1;
        }

        .btn-delete-workload:hover {
            background: #ff5f4d;
            color: #fff;
        }

        .table-loader-row td {
            padding-top: 1.1rem !important;
            padding-bottom: 1.1rem !important;
        }

        .table-loader {
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            color: #6f7f95;
            font-weight: 600;
        }

        .table-loader .spinner-border {
            width: 1rem;
            height: 1rem;
        }

        .total-label {
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .load-pill {
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 7px;
            white-space: nowrap;
            display: inline-block;
        }

        .load-normal {
            background-color: #e7f5ef;
            color: #0f5132;
        }

        .load-high {
            background-color: #fff3cd;
            color: #664d03;
        }

        .load-over {
            background-color: #f8d7da;
            color: #842029;
        }

        /* Increase Select2 height to match normal inputs */
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

        .select2-selection__rendered {
            line-height: 42px !important;
        }

        .faculty-need-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
        }

        /* PRINT */
        @page {
            size: A4 portrait;
            margin: 0;
        }

        @media print {
            html,
            body {
                width: 210mm !important;
                min-height: 297mm !important;
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
                background: #fff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            body * {
                visibility: hidden;
            }

            #printHeader {
                display: block !important;
            }

            #workloadCard,
            #workloadCard * {
                visibility: visible;
            }

            #workloadCard {
                position: absolute;
                left: 0;
                top: 0;
                width: 210mm;
                min-height: 297mm;
                max-width: 210mm;
                padding: 0;
                box-sizing: border-box;
                box-shadow: none;
                border: none;
                border-radius: 0 !important;
                background: transparent !important;
                margin: 0;
                overflow: hidden;
                isolation: isolate;
                page-break-after: avoid !important;
                break-after: avoid-page !important;
            }

            #workloadCard > .card-header {
                display: none !important;
            }

            .print-workload-body {
                position: relative;
                z-index: 1;
                min-height: 297mm;
                padding: 33mm 8mm 18mm 20mm;
                box-sizing: border-box;
            }

            .print-form-background {
                display: block !important;
                position: absolute;
                inset: 0;
                width: 210mm;
                height: 297mm;
                object-fit: fill;
                z-index: 0;
            }

            #workloadCard .table-responsive {
                overflow: visible !important;
                position: relative;
                z-index: 1;
            }

            .print-sheet-header {
                display: block !important;
                margin: 6.5mm 0 4.5mm;
            }

            .workload-table,
            .workload-table th,
            .workload-table td {
                border: 1.15px solid #000 !important;
                color: #000 !important;
                background: #fff !important;
                font-family: Arial, sans-serif;
                font-size: 7.6pt !important;
                table-layout: fixed;
                box-shadow: none !important;
            }

            .workload-table th,
            .workload-table td {
                padding: 3px 4px !important;
                line-height: 1.08;
                word-break: break-word;
            }

            .workload-table thead th {
                font-size: 6.15pt !important;
                line-height: 1.02 !important;
                letter-spacing: 0.01em !important;
                white-space: normal !important;
                word-break: normal !important;
                padding: 2px 3px !important;
            }

            .workload-table thead th.course-head { width: 7.5% !important; }
            .workload-table thead th.day-head { width: 5.5% !important; }
            .workload-table thead th.time-head { width: 7.5% !important; }
            .workload-table thead th.room-head { width: 6.5% !important; }
            .workload-table thead th.unit-head { width: 4.5% !important; }
            .workload-table thead th.hours-group-head { width: 9% !important; }
            .workload-table thead th.load-head { width: 6% !important; }
            .workload-table thead th.students-head { width: 7.5% !important; }
            .workload-table thead th.hours-subhead {
                width: 4.5% !important;
                white-space: nowrap !important;
                word-break: keep-all !important;
                overflow-wrap: normal !important;
            }

            .workload-table th:nth-child(1),
            .workload-table td:nth-child(1) { width: 10%; }
            .workload-table th:nth-child(2),
            .workload-table td:nth-child(2) { width: 19%; }
            .workload-table th:nth-child(3),
            .workload-table td:nth-child(3) { width: 8%; }
            .workload-table th:nth-child(4),
            .workload-table td:nth-child(4) { width: 6%; }
            .workload-table th:nth-child(5),
            .workload-table td:nth-child(5) { width: 8%; }
            .workload-table th:nth-child(6),
            .workload-table td:nth-child(6) { width: 7%; }
            .workload-table th:nth-child(7),
            .workload-table td:nth-child(7) { width: 4.5%; }
            .workload-table th:nth-child(8),
            .workload-table td:nth-child(8) { width: 4.5%; }
            .workload-table th:nth-child(9),
            .workload-table td:nth-child(9) { width: 4.5%; }
            .workload-table th:nth-child(10),
            .workload-table td:nth-child(10) { width: 5.5%; }
            .workload-table th:nth-child(11),
            .workload-table td:nth-child(11) { width: 7%; }

            .workload-time {
                min-width: 0;
            }

            .workload-table thead th.room-head,
            .workload-table td.workload-room {
                font-size: 5.9pt !important;
                line-height: 1 !important;
                white-space: normal !important;
                word-break: break-word !important;
                overflow-wrap: anywhere !important;
            }

            .workload-table {
                width: 100% !important;
                border-collapse: collapse !important;
                border: 1.35px solid #000 !important;
                page-break-inside: avoid !important;
                break-inside: avoid-page !important;
            }

            .btn,
            .badge,
            .select2-container,
            .screen-only {
                display: none !important;
            }

            .workload-table th.screen-only,
            .workload-table td.screen-only {
                width: 0 !important;
                min-width: 0 !important;
                max-width: 0 !important;
                padding: 0 !important;
                border: 0 !important;
            }

            .load-status-inline {
                display: none !important;
            }

            .screen-total-value {
                display: none !important;
            }

            .print-total-value {
                display: inline !important;
            }

            .print-only-table-cell {
                display: table-cell !important;
            }

            .schedule-partner-note,
            .workload-merge-note,
            .workload-desc .type-pill {
                display: none !important;
            }

            .external-workload-divider {
                display: none !important;
            }

            .workload-external-note {
                margin-top: 0.12rem;
                font-size: 6.4pt !important;
                font-weight: 700;
                color: #000 !important;
            }

            .workload-external-note-screen {
                display: none !important;
            }

            .workload-external-note-print {
                display: inline !important;
            }

            .external-workload-row td {
                background: #fff !important;
                color: #000 !important;
            }

            .external-workload-row .workload-code {
                border-left: 1.15px solid #000 !important;
            }

            .print-type-suffix {
                display: inline !important;
                font-weight: 600;
            }

            .print-sheet-line,
            .print-sheet-subtitle {
                font-size: 8pt !important;
            }

            .print-only-block {
                display: block !important;
            }

            .print-workload-footer {
                page-break-inside: avoid;
                break-inside: avoid-page;
                position: relative;
                z-index: 1;
            }

            .print-signatory,
            .print-sign-caption {
                font-size: 7.8pt !important;
            }

            .print-note-block,
            .print-cc-block,
            .print-ack-box {
                font-size: 6.7pt !important;
            }
        }

    </style>
    <style id="consolidatedReportStyleTag">
        .consolidated-report-modal .modal-dialog {
            max-width: min(96vw, 1800px);
        }

        .consolidated-report-modal .modal-content {
            border: 0;
            overflow: hidden;
        }

        .consolidated-report-modal .modal-header,
        .consolidated-report-modal .modal-footer {
            background: #ffffff;
        }

        .consolidated-report-modal .modal-body {
            background: #eef3f9;
            padding: 1rem;
        }

        .consolidated-report-toolbar-note {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.84rem;
            color: #66768a;
        }

        .consolidated-report-toolbar-note i {
            color: #5d68f4;
            font-size: 1rem;
        }

        .consolidated-report-preview-root {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            align-items: stretch;
            overflow-x: auto;
        }

        .consolidated-report-loading {
            min-height: 50vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .consolidated-report-loading-card,
        .consolidated-report-empty-state {
            width: min(100%, 560px);
            margin: 0 auto;
            background: #fff;
            border: 1px solid #dbe5f1;
            border-radius: 1rem;
            box-shadow: 0 20px 45px rgba(22, 40, 75, 0.08);
            padding: 1.5rem;
            text-align: center;
        }

        .consolidated-report-loading-card .spinner-border,
        .consolidated-report-empty-state i {
            width: 2.1rem;
            height: 2.1rem;
            margin-bottom: 0.75rem;
        }

        .consolidated-report-empty-state i {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #5d68f4;
        }

        .consolidated-report-loading-title,
        .consolidated-report-empty-title {
            font-size: 1.08rem;
            font-weight: 700;
            color: #304257;
            margin-bottom: 0.35rem;
        }

        .consolidated-report-loading-copy,
        .consolidated-report-empty-copy {
            font-size: 0.92rem;
            color: #6a7d94;
            margin-bottom: 0.9rem;
        }

        .consolidated-report-progress {
            display: flex;
            flex-direction: column;
            gap: 0.45rem;
            align-items: stretch;
        }

        .consolidated-report-progress-meta {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            font-size: 0.82rem;
            color: #61748b;
        }

        .consolidated-report-progress-track {
            width: 100%;
            height: 0.55rem;
            background: #e6edf7;
            border-radius: 999px;
            overflow: hidden;
        }

        .consolidated-report-progress-bar {
            width: 0;
            height: 100%;
            background: linear-gradient(90deg, #198754 0%, #5d68f4 100%);
            transition: width 0.2s ease;
        }

        .consolidated-report-page {
            --consolidated-page-width: 1344px;
            --consolidated-page-height: 816px;
            position: relative;
            width: var(--consolidated-page-width);
            height: var(--consolidated-page-height);
            margin: 0 auto;
            background: #fff;
            box-shadow: 0 22px 50px rgba(25, 42, 70, 0.16);
            overflow: hidden;
            flex: 0 0 auto;
        }

        .consolidated-report-page.is-measure {
            --consolidated-page-width: 1344px;
            --consolidated-page-height: 816px;
            box-shadow: none;
            margin: 0;
        }

        .consolidated-report-page-background {
            position: absolute;
            inset: 0;
            background-image: var(--consolidated-report-bg);
            background-repeat: no-repeat;
            background-position: center;
            background-size: 100% 100%;
        }

        .consolidated-report-page-inner {
            position: absolute;
            inset: 188px 48px 68px 74px;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .consolidated-report-page-header {
            text-align: center;
            padding: 0 0 0.32rem;
        }

        .consolidated-report-title {
            font-family: "Arial Narrow", Arial, sans-serif;
            font-size: 1.14rem;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            color: #0f3140;
            margin-bottom: 0.14rem;
        }

        .consolidated-report-subtitle,
        .consolidated-report-term,
        .consolidated-report-page-count {
            font-family: Arial, sans-serif;
            color: #10212f;
            line-height: 1.15;
        }

        .consolidated-report-subtitle {
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .consolidated-report-term {
            margin-top: 0.12rem;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .consolidated-report-page-count {
            margin-top: 0.12rem;
            font-size: 0.68rem;
            font-weight: 600;
            color: #4b5f70;
        }

        .consolidated-report-table-wrap {
            flex: 1 1 auto;
            min-height: 0;
            padding-inline: 24px;
            box-sizing: border-box;
        }

        .consolidated-report-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            font-family: Arial, sans-serif;
            font-size: 9.25px;
            color: #000;
            background: #fff;
        }

        .consolidated-report-table thead th,
        .consolidated-report-table tbody td {
            border: 1px solid #000;
            padding: 0.18rem 0.22rem;
            vertical-align: top;
            box-sizing: border-box;
            overflow-wrap: anywhere;
            word-break: normal;
        }

        .consolidated-report-table thead th {
            background: #fff;
            color: #000;
            font-size: 8.4px;
            font-weight: 700;
            line-height: 1.15;
            text-align: center;
            text-transform: uppercase;
        }

        .consolidated-report-table thead .is-lower {
            text-transform: none;
        }

        .consolidated-report-table tbody td {
            line-height: 1.18;
        }

        .consolidated-report-table tbody + tbody td {
            border-top-width: 1px;
            border-top-color: #000;
        }

        .consolidated-cell-index,
        .consolidated-cell-name,
        .consolidated-cell-prep,
        .consolidated-cell-students,
        .consolidated-cell-units,
        .consolidated-cell-hours,
        .consolidated-cell-load,
        .consolidated-cell-designation,
        .consolidated-cell-total,
        .consolidated-cell-remark {
            text-align: center;
            vertical-align: top !important;
        }

        .consolidated-cell-index,
        .consolidated-cell-name,
        .consolidated-cell-prep,
        .consolidated-cell-designation,
        .consolidated-cell-total,
        .consolidated-cell-remark {
            padding-top: 0.28rem !important;
        }

        .consolidated-cell-index {
            width: 3.1%;
            font-weight: 700;
        }

        .consolidated-cell-name {
            width: 12%;
            font-weight: 700;
            text-transform: uppercase;
            line-height: 1.12;
        }

        .consolidated-cell-prep {
            width: 4.5%;
            font-weight: 700;
        }

        .consolidated-cell-code {
            width: 7.2%;
            font-weight: 700;
        }

        .consolidated-cell-title {
            width: 15.5%;
        }

        .consolidated-cell-schedule {
            width: 11.5%;
        }

        .consolidated-cell-course {
            width: 9.6%;
        }

        .consolidated-cell-students {
            width: 5%;
        }

        .consolidated-cell-units {
            width: 4.7%;
        }

        .consolidated-cell-hours {
            width: 4.2%;
        }

        .consolidated-cell-load {
            width: 5%;
            font-weight: 700;
        }

        .consolidated-cell-designation {
            width: 8.8%;
        }

        .consolidated-cell-total {
            width: 4.5%;
            font-weight: 700;
        }

        .consolidated-cell-remark {
            width: 4.2%;
            font-weight: 700;
        }

        .consolidated-campus-term {
            font-size: 9px !important;
            font-weight: 700 !important;
            text-transform: none !important;
            padding: 0.28rem 0.2rem !important;
        }

        .consolidated-campus-term-line {
            display: block;
            line-height: 1.2;
        }

        .consolidated-campus-term-line + .consolidated-campus-term-line {
            margin-top: 0.08rem;
        }

        .consolidated-course-title {
            font-weight: 700;
            color: #000;
        }

        .consolidated-schedule-line {
            display: block;
            white-space: pre-line;
        }

        .consolidated-schedule-line + .consolidated-schedule-line {
            margin-top: 0.12rem;
        }

        .consolidated-designation-title {
            font-weight: 700;
            color: #000;
        }

        .consolidated-designation-units {
            margin-top: 0.12rem;
            font-size: 8px;
            color: #000;
        }

        .consolidated-remark-text {
            display: inline-block;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            color: #000;
        }

        .consolidated-faculty-total-row td {
            border-top-width: 1px;
            font-weight: 700;
            vertical-align: middle !important;
        }

        .consolidated-faculty-block,
        .consolidated-report-table tr {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .consolidated-faculty-total-row .consolidated-cell-load,
        .consolidated-faculty-total-row .consolidated-cell-designation,
        .consolidated-faculty-total-row .consolidated-cell-remark-total {
            font-size: 10px;
        }

        .consolidated-cell-remark-total {
            text-align: center;
            vertical-align: middle !important;
        }

        .consolidated-signatory-page .consolidated-report-page-inner {
            inset: 208px 90px 88px 100px;
        }

        .consolidated-signatory-wrap {
            margin-top: auto;
            padding-top: 1rem;
            font-family: Arial, sans-serif;
            color: #182b3d;
        }

        .consolidated-signatory-top {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1.6rem;
        }

        .consolidated-signatory-bottom {
            display: grid;
            grid-template-columns: minmax(0, 1fr);
            gap: 2rem;
            margin-top: 2.5rem;
            max-width: 31%;
        }

        .consolidated-signatory-block {
            min-width: 0;
        }

        .consolidated-signatory-label {
            margin-bottom: 1.5rem;
            font-size: 0.92rem;
            font-weight: 700;
        }

        .consolidated-signatory-name {
            min-height: 1.2rem;
            font-size: 0.86rem;
            font-weight: 800;
            text-transform: uppercase;
            border-bottom: 1px solid #455a6f;
            padding-bottom: 0.08rem;
        }

        .consolidated-signatory-title {
            min-height: 1.15rem;
            margin-top: 0.28rem;
            font-size: 0.82rem;
            color: #50657c;
        }

        #consolidatedReportMeasureRoot {
            position: absolute;
            left: -20000px;
            top: 0;
            visibility: hidden;
            pointer-events: none;
            width: 0;
            height: 0;
            overflow: hidden;
        }

        @media (max-width: 1199.98px) {
            .consolidated-report-preview-root {
                align-items: flex-start;
            }
        }

        @media (max-width: 767.98px) {
            .consolidated-report-modal .modal-body {
                padding: 0.65rem;
            }

            .consolidated-signatory-top {
                grid-template-columns: 1fr;
                gap: 1.2rem;
            }

            .consolidated-signatory-bottom {
                max-width: 100%;
            }
        }

        @media print {
            html,
            body {
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            body.consolidated-report-print-body .consolidated-report-preview-root {
                gap: 0 !important;
            }

            body.consolidated-report-print-body .consolidated-report-page {
                --consolidated-page-width: 14in;
                --consolidated-page-height: 8.5in;
                width: 14in !important;
                height: 8.5in !important;
                margin: 0 !important;
                box-shadow: none !important;
                page-break-after: always;
                break-after: page;
            }

            body.consolidated-report-print-body .consolidated-report-page:last-child {
                page-break-after: auto;
                break-after: auto;
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
        <i class="bx bx-task me-2"></i>
        Faculty Workload
        <small class="text-muted">(<?= htmlspecialchars($college_name) ?>)</small>
    </h4>

    <!-- SELECT FACULTY -->
    <div class="card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h5 class="m-0">Assign Workload</h5>
            <small class="text-muted">Assign scheduled lecture and laboratory entries to faculty.</small>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <button type="button" class="btn btn-outline-info btn-sm screen-only" data-bs-toggle="modal" data-bs-target="#workloadHelpModal">
                <i class="bx bx-help-circle me-1"></i> Help
            </button>
            <button type="button" class="btn btn-outline-primary btn-sm screen-only" id="btnOpenConsolidatedReport">
                <i class="bx bx-file me-1"></i> Consolidated Faculty Workload
            </button>
            <button type="button" class="btn btn-outline-danger btn-sm screen-only" id="btnClearCollegeWorkload">
                <i class="bx bx-trash me-1"></i> Remove All Assigned Workload
            </button>
        </div>
    </div>
        <div class="card-body">

            <div class="row g-3 align-items-end">
                <div class="col-md-6">
                    <div class="d-flex align-items-center justify-content-between gap-2">
                        <label class="form-label fw-semibold mb-0">Select Faculty / Faculty Need</label>
                        <div class="faculty-need-actions screen-only">
                            <button type="button" class="btn btn-link btn-sm px-0" id="btnAddFacultyNeed">
                                <i class="bx bx-plus-circle me-1"></i>Add Faculty Need
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm" id="btnDeleteFacultyNeed" style="display:none;">
                                <i class="bx bx-trash me-1"></i>Delete Selected Faculty Need
                            </button>
                        </div>
                    </div>
                    <select id="faculty_id" class="form-select select2-single">
                        <option value="">Select Faculty</option>
                        <optgroup label="Faculty">
                            <?= $faculty_options ?>
                        </optgroup>
                        <optgroup label="Faculty Needs" id="facultyNeedOptgroup">
                            <?= $faculty_need_options ?>
                        </optgroup>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Semester</label>
                    <select id="fw_semester" class="form-select">
                        <option value="1st"<?= $defaultSemesterUi === '1st' ? ' selected' : '' ?>>1st Semester</option>
                        <option value="2nd"<?= $defaultSemesterUi === '2nd' ? ' selected' : '' ?>>2nd Semester</option>
                        <option value="Midyear"<?= $defaultSemesterUi === 'Midyear' ? ' selected' : '' ?>>Midyear</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">A.Y.</label>
                    <select id="fw_ay" class="form-select select2-single">
                        <option value="">Select A.Y.</option>
                        <?php
                            $ay = mysqli_query($conn, "SELECT ay_id, ay FROM tbl_academic_years ORDER BY ay ASC");
                            while ($r = mysqli_fetch_assoc($ay)) {
                                $ayId  = (int)$r['ay_id'];
                                $ayval = htmlspecialchars($r['ay']);
                                $selected = ($ayId === $defaultAyId || $r['ay'] === $defaultAyLabel) ? " selected" : "";
                                echo "<option value='{$ayval}' data-ay-id='{$ayId}'{$selected}>{$ayval}</option>";
                            }
                        ?>
                    </select>
                </div>
            </div>

            <div class="mt-3" id="facultyAlert" style="display:none;">
                <div class="alert alert-info mb-0">
                    <strong id="selectedAssigneeLabel">Faculty Selected:</strong> <span id="facultyNameText"></span>
                    &nbsp;|&nbsp; <span class="fw-summary-label">Term:</span>
                    <span id="termSummary"></span>
                </div>
            </div>

        </div>
    </div>
<div class="card mt-4 workload-start-panel screen-only" id="workloadStartPanel">
    <div class="workload-start-body">
        <div class="workload-start-icon">
            <i class="bx bx-user-check"></i>
        </div>
        <h5 class="workload-start-title">Ready to Assign Faculty Workload</h5>
        <p class="workload-start-copy">
            Select a faculty to view current workload, check university-wide schedule conflicts, and assign available lecture/laboratory schedules.
        </p>
        <div class="workload-start-chips" aria-label="Workload rules">
            <span class="workload-start-chip"><i class="bx bx-globe"></i> University-wide load status</span>
            <span class="workload-start-chip"><i class="bx bx-lock-alt"></i> External workload is view-only</span>
            <span class="workload-start-chip"><i class="bx bx-printer"></i> Print remains college-scoped</span>
        </div>
        <div class="workload-start-actions">
            <button type="button" class="btn btn-primary" id="btnStartOpenFacultyOverview">
                <i class="bx bx-user-pin me-1"></i> Open Faculty Load Overview
            </button>
            <button type="button" class="btn btn-outline-primary" id="btnStartOpenConsolidatedReport">
                <i class="bx bx-file me-1"></i> Consolidated Report
            </button>
            <button type="button" class="btn btn-outline-secondary" id="btnStartChooseFaculty">
                <i class="bx bx-search me-1"></i> Choose Faculty
            </button>
        </div>
    </div>
</div>
<div class="card mt-4 workload-card" id="workloadCard" style="display:none;">
<div class="card-header d-flex justify-content-between align-items-center">
    <div>
        <h5 class="m-0">Current Faculty Workload</h5>
        <small class="text-muted">
            Classes already assigned for this term
        </small>
    </div>

    <!-- PRINT BUTTON (VISIBLE NOW) -->
    <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
        <i class="bx bx-printer me-1"></i> Print Workload
    </button>
</div>

<div class="print-workload-body">
    <img src="../assets/img/print/individual-faculty-workload-template.png" alt="" class="print-form-background" aria-hidden="true">

    <!-- PRINT HEADER (VISIBLE ONLY ON PRINT) -->
    <div id="printHeader" class="print-sheet-header">
        <div class="print-sheet-title">INDIVIDUAL FACULTY WORKLOAD</div>
        <div class="print-sheet-subtitle">
            <span id="printCampus" class="print-line-value no-underline <?= trim($campus_name) !== '' ? 'is-filled' : 'is-blank' ?>" style="max-width:58mm; min-width:36mm; text-align:center;"><?= htmlspecialchars($campus_name) ?></span>
        </div>
        <div class="print-sheet-subtitle print-sheet-subtitle-spaced">
            <span id="printSemesterAy" class="print-line-value no-underline is-blank" style="max-width:95mm; min-width:62mm; text-align:center;"></span>
        </div>
        <div class="print-sheet-line">
            <div class="print-field">
                <span class="print-field-label">Name:</span>
                <span id="printFacultyName" class="print-line-value is-blank"></span>
            </div>
            <div class="print-field">
                <span class="print-field-label">College:</span>
                <span id="printCollege" class="print-line-value <?= trim($college_name) !== '' ? 'is-filled' : 'is-blank' ?>"><?= htmlspecialchars($college_name) ?></span>
            </div>
        </div>
        <div class="print-sheet-line">
            <div class="print-field">
                <span class="print-field-label">Degree:</span>
                <span class="print-line-value is-blank"></span>
            </div>
            <div class="print-field">
                <span class="print-field-label">Major:</span>
                <span class="print-line-value is-blank"></span>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 workload-table">
            <thead class="table-light">
                <tr>
                    <th rowspan="2">Course No.</th>
                    <th rowspan="2">Course Description</th>
                    <th rowspan="2" class="course-head">Course</th>
                    <th rowspan="2" class="day-head">Day</th>
                    <th rowspan="2" class="time-head">Time</th>
                    <th rowspan="2" class="room-head">Room</th>
                    <th rowspan="2" class="text-center unit-head">Unit</th>
                    <th colspan="2" class="text-center hours-group-head">No. of Hours</th>
                    <th rowspan="2" class="text-center load-head">Load</th>
                    <th rowspan="2" class="text-center students-head"># of<br>Students</th>
                    <th rowspan="2" class="text-end screen-only">Action</th>
                </tr>
                <tr>
                    <th class="text-center hours-subhead">Lab</th>
                    <th class="text-center hours-subhead">Lec</th>
                </tr>
            </thead>

            <tbody id="workloadTbody"></tbody>
    <tfoot class="table-light">
        <tr class="workload-summary-row">
            <th colspan="2" class="text-start workload-summary-label">Designation:</th>
            <td colspan="4" class="workload-summary-value" id="designationText"></td>
            <td class="text-center fw-semibold" id="designationUNIT"></td>
            <td></td>
            <td></td>
            <td class="text-center fw-semibold" id="designationLOAD"></td>
            <td></td>
            <td class="screen-only"></td>
        </tr>
        <tr class="workload-summary-row summary-separator">
            <th colspan="2" class="text-start workload-summary-label">No. of Prep:</th>
            <td colspan="4" class="workload-summary-value" id="totalPreparations">0</td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
            <td class="screen-only"></td>
        </tr>
        <tr class="workload-summary-row workload-total-row">
            <th colspan="6" class="text-end fw-semibold total-label">Total Load</th>
            <th class="text-center" id="totalUNIT">0</th>
            <th class="text-center" id="totalLAB">0</th>
            <th class="text-center" id="totalLEC">0</th>
            <th class="text-center fw-semibold screen-only workload-total-load-screen" colspan="3" id="totalLOADCell">0</th>
            <th class="text-center fw-semibold print-only-table-cell" id="printTotalLOADCell">0</th>
            <th class="text-center print-only-table-cell" id="totalStudents"></th>
        </tr>
    </tfoot>
        </table>
    </div>
    
    <div id="printFooter" class="print-only-block print-workload-footer">
    <div class="print-signatory-grid">
        <div class="print-signatory">
            <div class="print-sign-label">Conforme:</div>
            <div id="printConforme" class="print-sign-line print-sign-uppercase"></div>
            <div class="print-sign-caption">Name/Signature over Printed Name</div>
            <div class="print-sign-line short"></div>
            <div class="print-sign-caption">Date</div>
        </div>
        <div class="print-signatory">
            <div class="print-sign-label">Prepared by:</div>
            <div id="printPreparedBy" class="print-sign-line print-sign-uppercase"></div>
            <div class="print-sign-caption">Program Chairperson</div>
            <div class="print-sign-line short"></div>
            <div class="print-sign-caption">Date</div>
        </div>
        <div class="print-signatory full-width">
            <div class="print-sign-label">Approved:</div>
            <div id="printApprovedBy" class="print-sign-line print-sign-uppercase"></div>
            <div class="print-sign-caption">College Dean</div>
        </div>
    </div>

    <div class="print-meta-grid">
        <div class="print-note-block">
            <div class="print-note-title">Note:</div>
            <div class="print-note-line">Regular number of students in the class:</div>
            <div class="print-note-line indent">Lecture: 40, Lab: 25</div>
            <div class="print-note-line">Load of teaching lab only 0.75 per contact hour/week/semester. Load of teaching lecture only 1.0 per contact hour/week/semester.</div>
            <div class="print-note-line">Thesis in final adviser:</div>
            <div class="print-note-line indent">0-15 students, 1 unit</div>
            <div class="print-note-line indent">16-30 students, 2 units</div>
            <div class="print-note-line indent">31-45 students, 3 units</div>
            <div class="print-note-line">Compute additional unit as:</div>
            <div class="print-note-line indent">0.33 unit/subject after the 2nd class preparation</div>
            <div class="print-note-line indent">0.02 unit/subject in excess of the set regular number</div>
        </div>

        <div>
            <div class="print-cc-block">
                <div class="print-cc-title">cc</div>
                <div class="print-cc-line">1 - VP ACA</div>
                <div class="print-cc-line">1 - Registrar</div>
                <div class="print-cc-line">1 - College Dean</div>
                <div class="print-cc-line">1 - Program Chairperson</div>
                <div class="print-cc-line">1 - Faculty Concerned</div>
            </div>

            <div class="print-ack-box">
                <div class="print-ack-title">ACKNOWLEDGEMENT RECEIPT:</div>
                <div class="print-ack-line">Date: ____________________</div>
                <div class="print-ack-line">Time: ____________________</div>
                <div class="print-ack-line">By:</div>
                <div class="print-sign-line" style="margin-top:0.16rem;"></div>
                <div class="print-sign-caption">Name &amp; Signature of Authorized Representative</div>
            </div>
        </div>
    </div>
</div>
</div>
</div>
<!-- SCHEDULED CLASSES CARD -->
<div class="card mb-4 mt-4" id="scheduledClassCard" style="display:none;">
    <div class="card-header">
        <h5 class="m-0">Scheduled Classes</h5>
        <small class="text-muted">
            Select scheduled lecture/laboratory entries for the chosen faculty, A.Y., and semester.
        </small>
    </div>

<!-- SEARCH & FILTER BAR -->
<div class="card-body py-2 border-bottom">
    <div class="row g-2 align-items-center">
        <div class="col-md-8">
            <input type="text"
                   id="scheduleSearch"
                   class="form-control"
                   placeholder="Search by course no, description, section, type, room, or days...">
        </div>

        <div class="col-md-4">
            <select id="scheduleFilter" class="form-select">
                <option value="all">Filter by: All</option>
                <option value="course">Course No.</option>
                <option value="desc">Description</option>
                <option value="section">Section</option>
                <option value="type">Type</option>
                <option value="room">Room</option>
                <option value="days">Days</option>
            </select>
        </div>
    </div>
</div>

    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:40px;">
                        <input type="checkbox" id="checkAllSchedules">
                    </th>
                    <th>Course No.</th>
                    <th>Course Description</th>
                    <th>Section</th>
                    <th>Type</th>
                    <th>Days</th>
                    <th>Time</th>
                    <th>Room</th>
                    <th class="text-center">Units</th>
                    <th class="text-center">Lab Hrs</th>
                    <th class="text-center">Lec Hrs</th>
                </tr>
            </thead>
            <tbody id="scheduledClassTbody">
                <!-- populated via AJAX -->
            </tbody>
        </table>
    </div>

    <div class="card-footer d-flex justify-content-between align-items-center">
        <small class="text-muted">
            Lecture and laboratory entries pulled from <strong>Class Scheduling</strong>
        </small>

        <button class="btn btn-primary btnApplyToWorkloadTrigger" id="btnApplyToWorkload" disabled>
            <i class="bx bx-check"></i> Apply to Workload
        </button>
    </div>
</div>
</div>

<div class="floating-apply-bar screen-only" id="floatingApplyBar">
    <div class="floating-apply-meta">
        <span class="floating-apply-label">Apply To Workload</span>
        <span class="floating-apply-count" id="floatingApplyCount">Select scheduled classes to apply.</span>
    </div>
    <button class="btn btn-primary btnApplyToWorkloadTrigger" id="btnApplyToWorkloadFloating" disabled>
        <i class="bx bx-check me-1"></i> Apply to Workload
    </button>
</div>

<div class="scheduler-activity-indicator screen-only" id="schedulerActivityIndicator" hidden aria-live="polite" aria-atomic="true">
    <span class="scheduler-activity-spinner">
        <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
    </span>
    <div class="scheduler-activity-copy">
        <div class="scheduler-activity-title" id="schedulerActivityTitle">Loading scheduler data...</div>
        <div class="scheduler-activity-meta" id="schedulerActivityMeta">Please wait while Synk updates the selected view.</div>
    </div>
</div>

<button
    type="button"
    class="faculty-browser-launcher screen-only"
    id="btnOpenFacultyBrowser"
    aria-controls="facultyBrowserDrawer"
    aria-label="Open faculty load overview"
>
    <span class="faculty-browser-launcher-icon">
        <i class="bx bx-user-pin"></i>
    </span>
    <span class="faculty-browser-launcher-label">Faculty Load</span>
</button>

<?php include '../footer.php'; ?>

</div>
</div>
</div>

<div class="offcanvas offcanvas-end" tabindex="-1" id="facultyBrowserDrawer" aria-labelledby="facultyBrowserDrawerLabel">
    <div class="offcanvas-header">
        <div class="faculty-browser-title">
            <span class="faculty-browser-title-icon">
                <i class="bx bx-group"></i>
            </span>
            <div>
                <h5 id="facultyBrowserDrawerLabel">Faculty Load Overview</h5>
                <p>Browse faculty load totals for the selected term, then open any faculty workload profile.</p>
            </div>
        </div>
        <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <div class="faculty-browser-summary" id="facultyBrowserSummary"></div>
        <div class="faculty-browser-classification-summary" id="facultyBrowserClassificationSummary"></div>
        <div class="faculty-browser-note">
            Shows assigned workload from all colleges and campuses for the current A.Y. and semester. Click a faculty card to open that faculty in the workload panel.
        </div>
        <div id="facultyBrowserList">
            <div class="faculty-browser-empty">Loading faculty load overview...</div>
        </div>
    </div>
</div>

<div class="modal fade" id="workloadHelpModal" tabindex="-1" aria-labelledby="workloadHelpModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-sm">
            <div class="modal-header border-0 pb-0">
                <div>
                    <h5 class="modal-title" id="workloadHelpModalLabel">Faculty Workload Guide</h5>
                    <small class="text-muted">Quick guide for assigning real faculty and faculty needs.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="p-3 border rounded-3 h-100">
                            <h6 class="fw-semibold mb-2"><i class="bx bx-user me-1"></i> Real Faculty</h6>
                            <p class="text-muted mb-0">Select a faculty to assign available schedules. The workload view checks actual university-wide load and shows other college/campus assignments as view-only.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 border rounded-3 h-100">
                            <h6 class="fw-semibold mb-2"><i class="bx bx-user-plus me-1"></i> Faculty Need</h6>
                            <p class="text-muted mb-0">Use Add Faculty Need when no real faculty can take remaining loads. Faculty needs can receive schedules and print workload, but they do not count as real faculty.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 border rounded-3 h-100">
                            <h6 class="fw-semibold mb-2"><i class="bx bx-lock-alt me-1"></i> View-Only Rows</h6>
                            <p class="text-muted mb-0">Rows from other colleges/campuses are visible for conflict awareness but cannot be removed from this college workload page.</p>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 border rounded-3 h-100">
                            <h6 class="fw-semibold mb-2"><i class="bx bx-printer me-1"></i> Print Scope</h6>
                            <p class="text-muted mb-0">Print workload remains college-scoped. Faculty need workload can also be printed using the same workload print format.</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">I understand</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade consolidated-report-modal" id="consolidatedReportModal" tabindex="-1" aria-labelledby="consolidatedReportModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-fullscreen-lg-down modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title" id="consolidatedReportModalLabel">Consolidated Faculty Workload</h5>
                    <small class="text-muted">Preview first, then print the legal landscape report for the selected term.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="consolidatedReportPreviewRoot" class="consolidated-report-preview-root">
                    <div class="consolidated-report-loading">
                        <div class="consolidated-report-loading-card">
                            <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
                            <div class="consolidated-report-loading-title">Preparing consolidated faculty workload</div>
                            <div class="consolidated-report-loading-copy">Choose an academic year and semester, then generate the preview.</div>
                            <div class="consolidated-report-progress">
                                <div class="consolidated-report-progress-meta">
                                    <span id="consolidatedReportProgressLabel">Waiting to start</span>
                                    <span id="consolidatedReportProgressValue">0%</span>
                                </div>
                                <div class="consolidated-report-progress-track">
                                    <div id="consolidatedReportProgressBar" class="consolidated-report-progress-bar"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="consolidatedReportMeasureRoot" aria-hidden="true"></div>
            </div>
            <div class="modal-footer justify-content-between">
                <div class="consolidated-report-toolbar-note">
                    <i class="bx bx-info-circle"></i>
                    <span>Signatories follow the same configuration used by the faculty workload reports.</span>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-outline-primary" id="btnRefreshConsolidatedReport">
                        <i class="bx bx-refresh me-1"></i> Refresh Preview
                    </button>
                    <button type="button" class="btn btn-primary" id="btnPrintConsolidatedReport" disabled>
                        <i class="bx bx-printer me-1"></i> Print Report
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- JS -->
    <!-- Core JS -->
    <!-- build:js assets/vendor/js/core.js -->
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
/* =========================================================
   GLOBAL STATE
========================================================= */
let currentAyId = null;
let currentSemesterNum = null;
let scheduledClassesRequest = null;
let workloadListRequest = null;
let facultyOverviewRequest = null;
let selectionRequestToken = 0;
let facultyOverviewCacheKey = "";
let facultyOverviewCache = [];
let facultyNeedOverviewCache = [];
let facultyBrowserDrawerInstance = null;
let consolidatedReportModalInstance = null;
let currentScheduledClassRows = [];
let currentWorkloadRows = [];
let consolidatedReportGeneratedHtml = "";
let consolidatedReportIsBuilding = false;
let schedulerActivitySequence = 0;
const schedulerActivityState = new Map();
const panelLoadState = {
    requestToken: 0,
    pending: 0,
    busyToken: null
};
const SCHEDULE_PAIR_COLORS = ["#22a06b", "#e85d75", "#5d68f4", "#d39c0f", "#2e8de4", "#8a63f7"];
const NORMAL_LOAD_SINGLE_PREP_UNITS = 21;
const NORMAL_LOAD_MULTIPLE_PREP_UNITS = 18;
const LOAD_COMPARISON_TOLERANCE = 0.0001;

$(document).ready(function () {

    /* =========================================================
       SEMESTER MAP
    ========================================================= */
    const SEMESTER_MAP = {
        "1st": 1,
        "2nd": 2,
        "Midyear": 3
    };
    const WORKLOAD_COLLEGE_NAME = <?= json_encode($college_name) ?>;
    const WORKLOAD_CAMPUS_NAME = <?= json_encode($campus_name) ?>;
    const CONSOLIDATED_REPORT_SIGNATORIES = <?= json_encode($consolidatedReportSignatories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const CONSOLIDATED_REPORT_BACKGROUND_URL = new URL(
        "../assets/img/print/consolidated-faculty-workload-template.png",
        window.location.href
    ).href;
    const facultyBrowserElement = document.getElementById("facultyBrowserDrawer");
    if (facultyBrowserElement) {
        facultyBrowserDrawerInstance = bootstrap.Offcanvas.getOrCreateInstance(facultyBrowserElement);
    }
    const consolidatedReportModalElement = document.getElementById("consolidatedReportModal");
    if (consolidatedReportModalElement) {
        consolidatedReportModalInstance = bootstrap.Modal.getOrCreateInstance(consolidatedReportModalElement);
    }

    function escapeHtml(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
    }

    function renderSchedulerActivityIndicator() {
        const indicator = $("#schedulerActivityIndicator");
        if (indicator.length === 0) {
            return;
        }

        const labels = Array.from(schedulerActivityState.values());
        if (labels.length === 0) {
            indicator.prop("hidden", true).removeClass("is-visible");
            return;
        }

        const latestLabel = String(labels[labels.length - 1] || "Loading scheduler data...");
        const totalTasks = labels.length;
        $("#schedulerActivityTitle").text(latestLabel);
        $("#schedulerActivityMeta").text(
            totalTasks > 1
                ? `${totalTasks} scheduler tasks are still running.`
                : "Please wait while Synk updates the selected view."
        );
        indicator.prop("hidden", false).addClass("is-visible");
    }

    function beginSchedulerActivity(label) {
        const token = `activity-${++schedulerActivitySequence}`;
        schedulerActivityState.set(token, String(label || "Loading scheduler data..."));
        renderSchedulerActivityIndicator();
        return token;
    }

    function endSchedulerActivity(token) {
        if (!token) {
            return;
        }

        schedulerActivityState.delete(token);
        renderSchedulerActivityIndicator();
    }

    function setButtonBusy(target, isBusy, busyHtml) {
        const buttons = target instanceof jQuery ? target : $(target);

        buttons.each(function () {
            const button = $(this);
            if (isBusy) {
                if (typeof button.data("busyOriginalHtml") === "undefined") {
                    button.data("busyOriginalHtml", button.html());
                }
                if (typeof button.data("busyOriginalDisabled") === "undefined") {
                    button.data("busyOriginalDisabled", button.prop("disabled"));
                }

                button.prop("disabled", true);
                if (busyHtml) {
                    button.html(busyHtml);
                }
                return;
            }

            const originalHtml = button.data("busyOriginalHtml");
            const originalDisabled = button.data("busyOriginalDisabled");
            if (typeof originalHtml !== "undefined") {
                button.html(originalHtml);
                button.removeData("busyOriginalHtml");
            }
            if (typeof originalDisabled !== "undefined") {
                button.prop("disabled", Boolean(originalDisabled));
                button.removeData("busyOriginalDisabled");
            } else {
                button.prop("disabled", false);
            }
        });
    }

    function resetPanelLoadingState() {
        if (panelLoadState.busyToken) {
            endSchedulerActivity(panelLoadState.busyToken);
        }

        panelLoadState.requestToken = 0;
        panelLoadState.pending = 0;
        panelLoadState.busyToken = null;
    }

    function beginPanelLoading(requestToken, label) {
        resetPanelLoadingState();
        panelLoadState.requestToken = requestToken;
        panelLoadState.pending = 2;
        panelLoadState.busyToken = beginSchedulerActivity(label || "Loading selected faculty data...");
    }

    function finishPanelLoading(requestToken) {
        if (panelLoadState.requestToken !== requestToken) {
            return;
        }

        panelLoadState.pending = Math.max(0, panelLoadState.pending - 1);
        if (panelLoadState.pending > 0) {
            return;
        }

        resetPanelLoadingState();
    }

    /* =========================================================
       INIT SELECT2 (ONCE ONLY)
    ========================================================= */
    $('.select2-single').select2({
        width: '100%',
        placeholder: "Select...",
        allowClear: true
    });

    function toNumber(value) {
        const n = Number(value);
        return Number.isFinite(n) ? n : 0;
    }

    function formatNumber(value) {
        const n = toNumber(value);
        return Number.isInteger(n) ? String(n) : String(parseFloat(n.toFixed(2)));
    }

    function formatStudentCount(value) {
        const n = Math.max(0, Math.round(toNumber(value)));
        return String(n);
    }

    function getNormalLoadUnits(preparationCount) {
        const prepCount = Math.max(0, Math.floor(toNumber(preparationCount)));
        return prepCount >= 2 ? NORMAL_LOAD_MULTIPLE_PREP_UNITS : NORMAL_LOAD_SINGLE_PREP_UNITS;
    }

    function getLoadStatus(loadValue, preparationCount = 0) {
        const numericLoad = toNumber(loadValue);
        const normalLoadUnits = getNormalLoadUnits(preparationCount);

        if (numericLoad > normalLoadUnits + LOAD_COMPARISON_TOLERANCE) {
            return { label: "Overload", className: "overload", drawerLabel: "Overload" };
        }

        if (numericLoad >= normalLoadUnits - LOAD_COMPARISON_TOLERANCE) {
            return { label: "", className: "normal", drawerLabel: "Normal Load" };
        }

        return { label: "Underload", className: "underload", drawerLabel: "Underload" };
    }

    function getFacultyBrowserTermContext() {
        const ayText = $("#fw_ay").val();
        const ayId = Number($("#fw_ay option:selected").data("ay-id")) || 0;
        const semesterUi = $("#fw_semester").val();
        const semesterNum = SEMESTER_MAP[semesterUi] || 0;

        if (!ayText || !ayId || !semesterUi || !semesterNum) {
            return null;
        }

        return {
            ayId,
            ayText,
            semesterUi,
            semesterNum
        };
    }

    function getFacultyOptionList() {
        const items = [];
        $("#faculty_id option").each(function () {
            const option = $(this);
            if (String(option.data("assigneeType") || "") === "faculty_need") {
                return;
            }

            const facultyId = parseInt(option.val(), 10) || 0;
            const fullName = String(option.text() || "").trim();

            if (!facultyId || !fullName) {
                return;
            }

            items.push({
                faculty_id: facultyId,
                full_name: fullName
            });
        });

        return items;
    }

    function getFacultyNeedOptionList() {
        const items = [];
        $("#faculty_id option").each(function () {
            const option = $(this);
            if (String(option.data("assigneeType") || "") !== "faculty_need") {
                return;
            }

            const facultyNeedId = Number(option.data("facultyNeedId")) || Number(String(option.val() || "").replace(/^need:/, "")) || 0;
            const label = String(option.text() || "").trim();

            if (!facultyNeedId || !label) {
                return;
            }

            items.push({
                faculty_need_id: facultyNeedId,
                need_label: label
            });
        });

        return items;
    }

    function clearFacultyOverviewCache() {
        abortPendingRequest(facultyOverviewRequest);
        facultyOverviewRequest = null;
        facultyOverviewCacheKey = "";
        facultyOverviewCache = [];
        facultyNeedOverviewCache = [];
    }

    function renderFacultyNeedOptions(needs, selectedValue = "") {
        const optgroup = $("#facultyNeedOptgroup");
        const currentValue = selectedValue || String($("#faculty_id").val() || "");
        const sourceNeeds = Array.isArray(needs) ? needs : [];
        let html = "";

        sourceNeeds.forEach(function (need) {
            const needId = Number(need?.faculty_need_id) || 0;
            const label = String(need?.need_label || "").trim();
            if (!needId || !label) {
                return;
            }

            html += `<option value="need:${escapeHtml(needId)}" data-assignee-type="faculty_need" data-faculty-need-id="${escapeHtml(needId)}">${escapeHtml(label)}</option>`;
        });

        optgroup.html(html);
        if (currentValue && $("#faculty_id option[value='" + currentValue.replace(/'/g, "\\'") + "']").length > 0) {
            $("#faculty_id").val(currentValue);
        } else if (String(currentValue).startsWith("need:")) {
            $("#faculty_id").val("");
        }

        if ($("#faculty_id").hasClass("select2-hidden-accessible")) {
            $("#faculty_id").trigger("change.select2");
        }

        updateFacultyNeedActions();
    }

    function loadFacultyNeedOptions(selectedValue = "") {
        const termContext = getFacultyBrowserTermContext();
        if (!termContext) {
            renderFacultyNeedOptions([], selectedValue);
            return $.Deferred().resolve([]).promise();
        }

        const busyToken = beginSchedulerActivity("Loading faculty needs...");

        return $.ajax({
            url: "../backend/query_faculty_need.php",
            type: "POST",
            dataType: "json",
            data: {
                action: "list",
                ay_id: termContext.ayId,
                semester: termContext.semesterNum
            }
        }).done(function (response) {
            renderFacultyNeedOptions(response && response.status === "ok" ? response.needs : [], selectedValue);
        }).always(function () {
            endSchedulerActivity(busyToken);
        });
    }

    function deleteFacultyNeed(facultyNeedId, needLabel = "") {
        const termContext = getFacultyBrowserTermContext();
        if (!termContext) {
            Swal.fire("Missing Term", "Select Academic Year and Semester first.", "warning");
            return;
        }

        const normalizedNeedId = Number(facultyNeedId) || 0;
        if (!normalizedNeedId) {
            Swal.fire("Unavailable", "The selected faculty need cannot be deleted right now.", "info");
            return;
        }

        if ($("#faculty_id").hasClass("select2-hidden-accessible")) {
            $("#faculty_id").select2("close");
        }

        const labelText = String(needLabel || `FACULTY NEED ${normalizedNeedId}`).trim() || `FACULTY NEED ${normalizedNeedId}`;

        Swal.fire({
            title: "Delete faculty need?",
            html: `Remove <b>${escapeHtml(labelText)}</b> from the selected term? Any workload assigned to this faculty need will also be removed.`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            confirmButtonText: "Delete"
        }).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }

            const deleteButton = $("#btnDeleteFacultyNeed");
            const busyToken = beginSchedulerActivity(`Deleting ${labelText}...`);
            setButtonBusy(
                deleteButton,
                true,
                '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Deleting...'
            );

            $.ajax({
                url: "../backend/query_faculty_need.php",
                type: "POST",
                dataType: "json",
                data: {
                    action: "delete",
                    faculty_need_id: normalizedNeedId,
                    ay_id: termContext.ayId,
                    semester: termContext.semesterNum
                }
            }).done(function (response) {
                if (!response || response.status !== "ok") {
                    Swal.fire("Error", response?.message || "Unable to delete faculty need.", "error");
                    return;
                }

                const deletedPayload = response.deleted || {};
                const removedWorkloadCount = Number(deletedPayload.deleted_workload_count) || 0;
                const deletedLabel = String(deletedPayload.need_label || labelText).trim() || labelText;
                const successMessage = removedWorkloadCount > 0
                    ? `${deletedLabel} was deleted. ${removedWorkloadCount} assigned workload row(s) were also removed.`
                    : `${deletedLabel} was deleted.`;

                Swal.fire({
                    title: "Deleted",
                    text: successMessage,
                    icon: "success",
                    timer: 1200,
                    showConfirmButton: false,
                    allowOutsideClick: false,
                    allowEscapeKey: false
                }).then(function () {
                    window.location.reload();
                });
            }).fail(function (xhr) {
                Swal.fire("Error", xhr.responseText || "Unable to delete faculty need.", "error");
            }).always(function () {
                setButtonBusy(deleteButton, false);
                endSchedulerActivity(busyToken);
            });
        });
    }

    function buildFacultyBrowserMetric(label, value) {
        return `
            <div class="faculty-browser-metric">
                <span class="faculty-browser-metric-label">${escapeHtml(label)}</span>
                <span class="faculty-browser-metric-value">${escapeHtml(String(value))}</span>
            </div>
        `;
    }

    function normalizeFacultyBrowserClassification(value) {
        const normalized = String(value || "").trim().toLowerCase().replace(/[\s-]+/g, "_");
        if (normalized === "cos" || normalized === "contract_service" || normalized === "contract_of_services") {
            return "contract_of_service";
        }

        if (normalized === "parttime") {
            return "part_time";
        }

        return normalized;
    }

    function buildFacultyBrowserClassificationSummary(counts) {
        const items = [
            { key: "permanent", label: "Permanent", className: "is-permanent" },
            { key: "contract_of_service", label: "COS", className: "is-cos" },
            { key: "part_time", label: "Part-time", className: "is-part-time" },
            { key: "faculty_need", label: "Faculty Need", className: "is-faculty-need" }
        ];

        return items.map(function (item) {
            return `
                <span class="faculty-browser-classification-badge ${escapeHtml(item.className)}">
                    <span class="faculty-browser-classification-label">${escapeHtml(item.label)}:</span>
                    <span class="faculty-browser-classification-value">${escapeHtml(String(counts[item.key] || 0))}</span>
                </span>
            `;
        }).join("");
    }

    function setFacultyBrowserMessage(message, isLoading = false) {
        $("#facultyBrowserSummary").html("");
        $("#facultyBrowserClassificationSummary").html("");
        $("#facultyBrowserList").html(`
            <div class="faculty-browser-empty">
                ${isLoading ? '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>' : ''}
                ${escapeHtml(message)}
            </div>
        `);
    }

    function renderFacultyBrowser() {
        const summary = $("#facultyBrowserSummary");
        const list = $("#facultyBrowserList");
        if (summary.length === 0 || list.length === 0) {
            return;
        }

        const termContext = getFacultyBrowserTermContext();
        const facultyOptions = getFacultyOptionList();
        const facultyNeedOptions = getFacultyNeedOptionList();
        if (!termContext) {
            setFacultyBrowserMessage("Select A.Y. and semester to browse faculty loads.");
            return;
        }

        if (facultyOptions.length === 0 && facultyNeedOptions.length === 0) {
            setFacultyBrowserMessage("No faculty or faculty need options are available on this page.");
            return;
        }

        if (facultyOverviewCacheKey === "" && facultyOverviewCache.length === 0 && facultyNeedOverviewCache.length === 0) {
            if (facultyOverviewRequest && facultyOverviewRequest.readyState !== 4) {
                setFacultyBrowserMessage("Loading faculty load overview...", true);
                return;
            }

            setFacultyBrowserMessage("Open the drawer to load the faculty load overview.");
            return;
        }

        const overviewMap = new Map(
            (Array.isArray(facultyOverviewCache) ? facultyOverviewCache : []).map(item => [Number(item.faculty_id), item])
        );
        const facultyNeedOverviewMap = new Map(
            (Array.isArray(facultyNeedOverviewCache) ? facultyNeedOverviewCache : []).map(item => [Number(item.faculty_need_id), item])
        );
        const currentAssigneeValue = String($("#faculty_id").val() || "");
        const merged = facultyOptions.map(option => {
            const summaryRow = overviewMap.get(option.faculty_id) || {};
            const totalLoad = toNumber(summaryRow.total_load);
            const workloadLoad = toNumber(summaryRow.workload_load);
            const designationUnits = toNumber(summaryRow.designation_units);
            const totalPreparations = Math.max(0, Number(summaryRow.total_preparations) || 0);
            const loadStatus = getLoadStatus(totalLoad, totalPreparations);

            return {
                faculty_id: option.faculty_id,
                full_name: option.full_name,
                total_load: totalLoad,
                workload_load: workloadLoad,
                designation_units: designationUnits,
                total_preparations: totalPreparations,
                designation_label: String(summaryRow.designation_label || "").trim(),
                employment_classification: normalizeFacultyBrowserClassification(summaryRow.employment_classification || ""),
                load_status: loadStatus,
                assignee_value: String(option.faculty_id),
                is_faculty_need: false
            };
        }).concat(facultyNeedOptions.map(option => {
            const summaryRow = facultyNeedOverviewMap.get(option.faculty_need_id) || {};
            const totalLoad = toNumber(summaryRow.total_load);
            const workloadLoad = toNumber(summaryRow.workload_load);
            const totalPreparations = Math.max(0, Number(summaryRow.total_preparations) || 0);
            const loadStatus = getLoadStatus(totalLoad, totalPreparations);

            return {
                faculty_id: 0,
                faculty_need_id: option.faculty_need_id,
                full_name: option.need_label,
                total_load: totalLoad,
                workload_load: workloadLoad,
                designation_units: 0,
                total_preparations: totalPreparations,
                designation_label: "",
                employment_classification: "faculty_need",
                load_status: loadStatus,
                assignee_value: `need:${option.faculty_need_id}`,
                is_faculty_need: true
            };
        }));

        const sections = [
            {
                key: "overload",
                title: "Overload",
                subtitle: "Faculty above the normal load for the selected term."
            },
            {
                key: "normal",
                title: "Normal Load",
                subtitle: "Faculty exactly at 21 units for 1 prep, or 18 units for 2 or more preps."
            },
            {
                key: "underload",
                title: "Underload",
                subtitle: "Faculty below the normal load for the selected term."
            }
        ];

        const counts = {
            overload: merged.filter(item => item.load_status.className === "overload").length,
            normal: merged.filter(item => item.load_status.className === "normal").length,
            underload: merged.filter(item => item.load_status.className === "underload").length
        };
        const classificationCounts = merged.reduce(function (accumulator, item) {
            const classification = normalizeFacultyBrowserClassification(item.employment_classification);
            if (Object.prototype.hasOwnProperty.call(accumulator, classification)) {
                accumulator[classification]++;
            }

            return accumulator;
        }, {
            permanent: 0,
            contract_of_service: 0,
            part_time: 0,
            faculty_need: 0
        });

        summary.html(
            buildFacultyBrowserMetric("Overload", counts.overload) +
            buildFacultyBrowserMetric("Normal", counts.normal) +
            buildFacultyBrowserMetric("Underload", counts.underload)
        );
        $("#facultyBrowserClassificationSummary").html(buildFacultyBrowserClassificationSummary(classificationCounts));

        const sectionsHtml = sections.map(section => {
            const rows = merged
                .filter(item => item.load_status.className === section.key)
                .sort((left, right) => String(left.full_name).localeCompare(String(right.full_name)));

            if (rows.length === 0) {
                return "";
            }

            const cards = rows.map(item => {
                const statusClass = item.load_status.className;
                const prepLabel = `${item.total_preparations} prep${item.total_preparations === 1 ? "" : "s"}`;
                const noteParts = [];

                if (item.designation_label) {
                    noteParts.push(item.designation_label);
                }

                if (item.designation_units > 0) {
                    noteParts.push(`Designation ${formatNumber(item.designation_units)}`);
                }

                if (item.workload_load > 0) {
                    noteParts.push(`Teaching ${formatNumber(item.workload_load)}`);
                }

                if (noteParts.length === 0) {
                    noteParts.push("No assigned workload yet");
                }

                return `
                    <button
                        type="button"
                        class="faculty-browser-card ${statusClass === "normal" ? "is-normal" : statusClass === "overload" ? "is-overload" : "is-underload"} ${currentAssigneeValue === String(item.assignee_value) ? "is-current" : ""}"
                        data-faculty-id="${escapeHtml(item.faculty_id || "")}"
                        data-assignee-value="${escapeHtml(item.assignee_value)}"
                    >
                        <div>
                            <div class="faculty-browser-name ${statusClass === "normal" ? "is-normal" : statusClass === "overload" ? "is-overload" : "is-underload"}">
                                ${escapeHtml(item.full_name)}
                            </div>
                            <div class="faculty-browser-subtext">${escapeHtml(noteParts.join(" | "))}</div>
                            <div class="faculty-browser-tags">
                                <span class="faculty-browser-tag ${statusClass === "normal" ? "is-normal" : statusClass === "overload" ? "is-overload" : "is-underload"}">${escapeHtml(item.load_status.drawerLabel)}</span>
                                ${item.is_faculty_need ? '<span class="faculty-browser-tag">Faculty Need</span>' : ""}
                                <span class="faculty-browser-tag">${escapeHtml(prepLabel)}</span>
                                ${currentAssigneeValue === String(item.assignee_value) ? '<span class="faculty-browser-tag">Selected</span>' : ""}
                            </div>
                        </div>
                        <div class="faculty-browser-load">
                            <span class="faculty-browser-load-number">${escapeHtml(formatNumber(item.total_load))}</span>
                            <span class="faculty-browser-load-label">Total Load</span>
                        </div>
                    </button>
                `;
            }).join("");

            return `
                <section class="faculty-browser-section">
                    <div class="faculty-browser-section-header">
                        <div>
                            <span class="faculty-browser-status-chip is-${escapeHtml(section.key)}">
                                <i class="bx bx-user"></i>
                                ${escapeHtml(section.title)}
                            </span>
                            <div class="faculty-browser-section-subtitle">${escapeHtml(section.subtitle)}</div>
                        </div>
                        <span class="faculty-browser-section-badge">${escapeHtml(String(rows.length))}</span>
                    </div>
                    <div class="faculty-browser-list">${cards}</div>
                </section>
            `;
        }).join("");

        list.html(
            sectionsHtml !== ""
                ? sectionsHtml
                : "<div class='faculty-browser-empty'>No faculty load summary is available for the selected term.</div>"
        );
    }

    function loadFacultyOverview(forceReload = false) {
        const dfd = $.Deferred();
        const termContext = getFacultyBrowserTermContext();
        const facultyOptions = getFacultyOptionList();
        const facultyNeedOptions = getFacultyNeedOptionList();

        if (!termContext) {
            clearFacultyOverviewCache();
            renderFacultyBrowser();
            dfd.reject("missing_term");
            return dfd.promise();
        }

        if (facultyOptions.length === 0 && facultyNeedOptions.length === 0) {
            clearFacultyOverviewCache();
            renderFacultyBrowser();
            dfd.reject("missing_assignees");
            return dfd.promise();
        }

        const facultyIds = facultyOptions.map(item => item.faculty_id);
        const facultyNeedIds = facultyNeedOptions.map(item => item.faculty_need_id);
        const cacheKey = `${termContext.ayId}-${termContext.semesterNum}-${facultyIds.join(",")}-needs:${facultyNeedIds.join(",")}`;

        if (!forceReload && cacheKey === facultyOverviewCacheKey && (facultyOverviewCache.length > 0 || facultyNeedOverviewCache.length > 0)) {
            renderFacultyBrowser();
            dfd.resolve(facultyOverviewCache);
            return dfd.promise();
        }

        abortPendingRequest(facultyOverviewRequest);
        setFacultyBrowserMessage("Loading faculty load overview...", true);
        const busyToken = beginSchedulerActivity("Loading faculty load overview...");

        facultyOverviewRequest = $.ajax({
            url: "../backend/query_workload_faculty_overview.php",
            type: "POST",
            dataType: "json",
            data: {
                ay_id: termContext.ayId,
                semester: termContext.semesterNum,
                faculty_ids: facultyIds,
                faculty_need_ids: facultyNeedIds
            }
        }).done(function (response) {
            if (!response || response.status !== "ok" || !Array.isArray(response.faculty)) {
                clearFacultyOverviewCache();
                setFacultyBrowserMessage((response && response.message) ? response.message : "Failed to load faculty load overview.");
                dfd.reject("invalid");
                return;
            }

            facultyOverviewCacheKey = cacheKey;
            facultyOverviewCache = response.faculty;
            facultyNeedOverviewCache = Array.isArray(response.faculty_needs) ? response.faculty_needs : [];
            renderFacultyBrowser();
            dfd.resolve(facultyOverviewCache);
        }).fail(function (xhr, status) {
            if (status === "abort") {
                dfd.reject("abort");
                return;
            }

            clearFacultyOverviewCache();
            setFacultyBrowserMessage("Failed to load faculty load overview.");
            dfd.reject(xhr);
        }).always(function () {
            endSchedulerActivity(busyToken);
        });

        return dfd.promise();
    }

    function clearAllCollegeWorkloadInScope() {
        const termContext = getFacultyBrowserTermContext();
        if (!termContext) {
            Swal.fire("Missing Term", "Select Academic Year and Semester first.", "warning");
            return;
        }

        const collegeLabel = String(WORKLOAD_COLLEGE_NAME || "current college").trim() || "current college";
        const termLabel = `${termContext.semesterUi} Semester | AY ${termContext.ayText}`;
        const button = $("#btnClearCollegeWorkload");
        const originalHtml = button.html();

        Swal.fire({
            icon: "warning",
            title: "Remove all assigned workload?",
            html: [
                `This will remove <b>all assigned faculty workload</b> for <b>${escapeHtml(collegeLabel)}</b>.`,
                `Scope: <b>${escapeHtml(termLabel)}</b> only.`,
                "This will not change other colleges."
            ].join("<br><br>"),
            showCancelButton: true,
            confirmButtonText: "Yes, remove all",
            confirmButtonColor: "#dc3545",
            cancelButtonText: "Cancel",
            allowOutsideClick: false
        }).then(function (result) {
            if (!result.isConfirmed) {
                return;
            }

            button
                .prop("disabled", true)
                .html('<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Removing...');

            Swal.fire({
                title: "Removing Assigned Workload...",
                html: `Please wait while workload rows for <b>${escapeHtml(collegeLabel)}</b> are being removed.`,
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: function () {
                    Swal.showLoading();
                }
            });

            $.ajax({
                url: "../backend/query_remove_all_college_workload.php",
                type: "POST",
                dataType: "json",
                data: {
                    ay_id: termContext.ayId,
                    semester: termContext.semesterNum
                }
            }).done(function (response) {
                Swal.close();

                if (!response || response.status !== "ok") {
                    Swal.fire(
                        "Error",
                        (response && response.message) ? response.message : "Failed to remove college workload.",
                        "error"
                    );
                    return;
                }

                clearFacultyOverviewCache();
                if (getFacultyOptionList().length > 0) {
                    loadFacultyOverview(true);
                } else {
                    renderFacultyBrowser();
                }

                if (getSelectedContext()) {
                    refreshWorkloadPanels();
                }

                const deletedCount = Number(response.deleted_count) || 0;
                Swal.fire(
                    deletedCount > 0 ? "Workload Removed" : "Nothing to Remove",
                    response.message || "College workload has been updated.",
                    deletedCount > 0 ? "success" : "info"
                );
            }).fail(function (xhr) {
                Swal.close();
                Swal.fire("Error", xhr.responseText || "Failed to remove college workload.", "error");
            }).always(function () {
                button.prop("disabled", false).html(originalHtml);
            });
        });
    }

    function formatCompactTime(value) {
        const raw = String(value ?? "").trim();
        if (raw === "") {
            return "";
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

    function normalizeDayList(value) {
        const items = Array.isArray(value)
            ? value
            : String(value ?? "").split(",");
        const uniqueDays = new Set();

        items.forEach(function (item) {
            const day = String(item ?? "").trim().toUpperCase();
            if (day !== "") {
                uniqueDays.add(day);
            }
        });

        return Array.from(uniqueDays);
    }

    function parseTimeToMinutes(value) {
        const raw = String(value ?? "").trim();
        if (raw === "") {
            return NaN;
        }

        let match = raw.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
        if (match) {
            return (Number(match[1]) * 60) + Number(match[2]);
        }

        match = raw.replace(/\s+/g, "").toUpperCase().match(/^(\d{1,2}):(\d{2})(AM|PM)$/);
        if (!match) {
            return NaN;
        }

        let hours = Number(match[1]) % 12;
        if (match[3] === "PM") {
            hours += 12;
        }

        return (hours * 60) + Number(match[2]);
    }

    function getRowTimeWindow(row) {
        const rawStart = String(row?.time_start || "").trim();
        const rawEnd = String(row?.time_end || "").trim();

        if (rawStart !== "" && rawEnd !== "") {
            return {
                start: parseTimeToMinutes(rawStart),
                end: parseTimeToMinutes(rawEnd)
            };
        }

        const timeText = String(row?.time || "").trim();
        const parts = timeText.split("-");

        if (parts.length !== 2) {
            return { start: NaN, end: NaN };
        }

        return {
            start: parseTimeToMinutes(parts[0]),
            end: parseTimeToMinutes(parts[1])
        };
    }

    function rowsHaveTimeConflict(candidate, other) {
        if (candidate?.schedule_status === "tba" || other?.schedule_status === "tba") return false;
        const candidateDays = normalizeDayList(candidate?.days_arr || candidate?.days || []);
        const otherDays = normalizeDayList(other?.days_arr || other?.days || []);

        if (!candidateDays.some(day => otherDays.includes(day))) {
            return false;
        }

        const candidateWindow = getRowTimeWindow(candidate);
        const otherWindow = getRowTimeWindow(other);

        if (
            !Number.isFinite(candidateWindow.start) ||
            !Number.isFinite(candidateWindow.end) ||
            !Number.isFinite(otherWindow.start) ||
            !Number.isFinite(otherWindow.end)
        ) {
            return false;
        }

        return candidateWindow.start < otherWindow.end &&
               candidateWindow.end > otherWindow.start;
    }

    function applyScheduledClassAvailability() {
        if (!Array.isArray(currentScheduledClassRows) || currentScheduledClassRows.length === 0) {
            updateApplyWorkloadControls();
            return;
        }

        const scheduledRowsById = new Map();
        currentScheduledClassRows.forEach(function (row) {
            const scheduleId = String(row?.schedule_id ?? "").trim();
            if (scheduleId !== "") {
                scheduledRowsById.set(scheduleId, row);
            }
        });

        $("#scheduledClassTbody .chkSchedule").each(function () {
            const checkbox = $(this);
            const tableRow = checkbox.closest("tr");
            const scheduleId = String(checkbox.val() || "").trim();
            const scheduledRow = scheduledRowsById.get(scheduleId) || null;
            const conflictRow = scheduledRow
                ? currentWorkloadRows.find(function (workloadRow) {
                    return rowsHaveTimeConflict(scheduledRow, workloadRow);
                })
                : null;
            const hasConflict = Boolean(conflictRow);

            checkbox.prop("checked", hasConflict ? false : checkbox.is(":checked"));
            checkbox.prop("disabled", hasConflict);
            tableRow.toggleClass("schedule-conflict-disabled", hasConflict);

            if (hasConflict) {
                const conflictSource = conflictRow?.is_external ? getWorkloadSourceLabel(conflictRow) : "";
                tableRow.attr(
                    "title",
                    conflictSource
                        ? `Conflicts with view-only workload from ${conflictSource}`
                        : "Conflicts with current faculty workload"
                );
                tableRow.attr("aria-disabled", "true");
            } else {
                tableRow.removeAttr("title");
                tableRow.removeAttr("aria-disabled");
            }
        });

        const selectableCount = $("#scheduledClassTbody .chkSchedule:not(:disabled)").length;
        const checkedCount = $("#scheduledClassTbody .chkSchedule:checked:not(:disabled)").length;
        $("#checkAllSchedules").prop("checked", selectableCount > 0 && selectableCount === checkedCount);

        updateApplyWorkloadControls();
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

    function setPrintField(selector, value, options = {}) {
        const $field = $(selector);
        const normalized = String(value ?? "").trim();
        const rendered = normalized !== ""
            ? (options.uppercase ? normalized.toUpperCase() : normalized)
            : "";

        $field.text(rendered);
        $field.toggleClass("is-filled", normalized !== "");
        $field.toggleClass("is-blank", normalized === "");
    }

    function clearPrintSignatories() {
        setPrintField("#printPreparedBy", "");
        setPrintField("#printApprovedBy", "");
    }

    function applyWorkloadPrintSignatories(signatories) {
        const preparedBy = signatories && typeof signatories === "object" ? signatories.prepared_by : null;
        const approvedBy = signatories && typeof signatories === "object" ? signatories.approved_by : null;

        setPrintField("#printPreparedBy", preparedBy?.name || "", { uppercase: true });
        setPrintField("#printApprovedBy", approvedBy?.name || "", { uppercase: true });
    }

    function normalizeWorkloadResponse(payload) {
        if (Array.isArray(payload)) {
            return {
                rows: payload,
                external_rows: [],
                signatories: {},
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
            external_rows: Array.isArray(payload.external_rows) ? payload.external_rows : [],
            signatories: payload.signatories && typeof payload.signatories === "object" ? payload.signatories : {},
            meta: payload.meta || {}
        };
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

    function setApplyWorkloadButtonsDisabled(disabled) {
        $("#btnApplyToWorkload, #btnApplyToWorkloadFloating").prop("disabled", Boolean(disabled));
    }

    function updateApplyWorkloadControls() {
        const cardVisible = $("#scheduledClassCard").is(":visible");
        const totalRows = $(".chkSchedule").length;
        const total = $(".chkSchedule:not(:disabled)").length;
        const checked = $(".chkSchedule:checked:not(:disabled)").length;

        if (!cardVisible) {
            $("#floatingApplyBar").removeClass("is-visible");
            $("#floatingApplyCount").text("Select scheduled classes to apply.");
            $("#checkAllSchedules").prop("disabled", true).prop("checked", false);
            setApplyWorkloadButtonsDisabled(true);
            return;
        }

        $("#floatingApplyBar").addClass("is-visible");
        $("#checkAllSchedules").prop("disabled", total === 0);
        setApplyWorkloadButtonsDisabled(!(total > 0 && checked > 0));

        if (total === 0) {
            $("#floatingApplyCount").text(
                totalRows > 0
                    ? "All scheduled classes conflict with the current workload."
                    : "No scheduled classes available."
            );
            return;
        }

        $("#floatingApplyCount").text(`${checked} of ${total} class${total === 1 ? "" : "es"} selected`);
    }

    function setScheduledClassesLoadingState(message = "Loading scheduled classes...") {
        $("#scheduledClassCard").show();
        currentScheduledClassRows = [];
        $("#checkAllSchedules").prop("checked", false);
        setApplyWorkloadButtonsDisabled(true);
        $("#scheduledClassTbody").html(buildLoadingRow(11, message));
        updateApplyWorkloadControls();
    }

    function setWorkloadLoadingState(message = "Loading faculty workload...") {
        $("#workloadCard").show();
        currentWorkloadRows = [];
        $("#workloadTbody").html(buildLoadingRow(12, message));
        $("#designationText").text("");
        $("#designationUNIT").text("");
        $("#designationLOAD").text("");
        $("#totalPreparations").text("0");
        $("#totalUNIT").text("0");
        $("#totalLEC").text("0");
        $("#totalLAB").text("0");
        $("#totalLOADCell").text("0");
        $("#printTotalLOADCell").text("0");
        $("#totalStudents").text("");
    }

    function calculateWorkloadMetricTotals(rowsData) {
        const sourceRows = Array.isArray(rowsData) ? rowsData : [];
        const countedGroups = new Set();
        const preparationSet = new Set();
        const totals = {
            unit: 0,
            lab: 0,
            lec: 0,
            load: 0,
            preparations: 0
        };

        sourceRows.forEach(function (row) {
            const groupKey = getWorkloadGroupKey(row);
            if (!countedGroups.has(groupKey)) {
                countedGroups.add(groupKey);
                totals.unit += toNumber(row.units);
                totals.lab += toNumber(row.lab);
                totals.lec += toNumber(row.lec);
                totals.load += toNumber(row.faculty_load);
            }

            const preparationKey = String(row?.sub_code || "").trim();
            if (preparationKey !== "") {
                preparationSet.add(preparationKey);
            }
        });

        totals.preparations = preparationSet.size;
        return totals;
    }

    function buildDualTotalValue(screenValue, printValue, options = {}) {
        const screenText = formatNumber(screenValue);
        const printText = formatNumber(printValue);
        const className = options.className ? ` ${options.className}` : "";

        if (screenText === printText) {
            const sameClass = String(className).trim();
            return sameClass
                ? `<span class="${escapeHtml(sameClass)}">${escapeHtml(screenText)}</span>`
                : `<span>${escapeHtml(screenText)}</span>`;
        }

        return `
            <span class="screen-total-value${className}">${escapeHtml(screenText)}</span>
            <span class="print-total-value${className}">${escapeHtml(printText)}</span>
        `;
    }

    function getWorkloadSourceLabel(row) {
        const campusName = String(row?.schedule_campus_name || "").trim();
        const collegeName = String(row?.schedule_college_name || "").trim();
        const parts = [];

        if (campusName) {
            parts.push(campusName);
        }

        if (collegeName && collegeName.toUpperCase() !== campusName.toUpperCase()) {
            parts.push(collegeName);
        }

        return parts.join(" - ");
    }

    function buildWorkloadDescription(row, isPaired = false) {
        const description = escapeHtml(row?.desc || "");
        const mergeNote = String(row?.merge_note || "").trim();
        const normalizedType = String(row?.type || row?.schedule_type || "").toUpperCase();
        const typeLabel = normalizedType === "LAB"
            ? "Lab"
            : (normalizedType === "LEC" ? "Lec" : "");

        if (!row?.is_external && !isPaired && !mergeNote && !typeLabel) {
            return description;
        }

        const noteParts = [];
        if (isPaired) {
            const type = normalizedType === "LAB" ? "LAB" : "LEC";
            noteParts.push(`<span class="type-pill ${type.toLowerCase()}">${type}</span>`);
        }

        let html = description;
        if (typeLabel) {
            html += `<span class="print-type-suffix"> (${escapeHtml(typeLabel)})</span>`;
        }

        if (noteParts.length > 0) {
            html += `
                <span class="schedule-partner-note">
                    ${noteParts.join("")}
                </span>
            `;
        }

        if (mergeNote) {
            html += `<span class="workload-merge-note">${escapeHtml(mergeNote)}</span>`;
        }

        if (row?.is_external) {
            const sourceLabel = getWorkloadSourceLabel(row);
            html += `
                <span class="workload-external-note">
                    <span class="workload-external-note-screen">
                        View-only workload${sourceLabel ? ` from ${escapeHtml(sourceLabel)}` : ""}
                    </span>
                    <span class="workload-external-note-print">
                        ${sourceLabel ? escapeHtml(sourceLabel) : ""}
                    </span>
                </span>
            `;
        }

        return html;
    }

    function getDisplayUnits(row) {
        if (row && Object.prototype.hasOwnProperty.call(row, "units")) {
            return toNumber(row.units);
        }

        if (row && Object.prototype.hasOwnProperty.call(row, "subject_units")) {
            return toNumber(row.subject_units);
        }

        return toNumber(row?.units);
    }

    function getDisplayLabUnits(row) {
        if (row && Object.prototype.hasOwnProperty.call(row, "hours_lab")) {
            return toNumber(row.hours_lab);
        }

        if (row && Object.prototype.hasOwnProperty.call(row, "lab_units")) {
            return toNumber(row.lab_units);
        }

        return toNumber(row?.hours_lab);
    }

    function getDisplayLecUnits(row) {
        if (row && Object.prototype.hasOwnProperty.call(row, "hours_lec")) {
            return toNumber(row.hours_lec);
        }

        if (row && Object.prototype.hasOwnProperty.call(row, "lec_units")) {
            return toNumber(row.lec_units);
        }

        return toNumber(row?.hours_lec);
    }

    function getWorkloadGroupKey(row) {
        const groupId = Number(row?.group_id) || 0;
        if (groupId > 0) {
            return `group:${groupId}`;
        }

        const offeringId = Number(row?.offering_id) || 0;
        if (offeringId > 0) {
            return `offering:${offeringId}`;
        }

        return `workload:${Number(row?.workload_id) || 0}`;
    }

    function getScheduledClassGroupKey(row) {
        const groupId = Number(row?.group_id) || 0;
        if (groupId > 0) {
            return `group:${groupId}`;
        }

        const offeringId = Number(row?.offering_id) || 0;
        if (offeringId > 0) {
            return `offering:${offeringId}`;
        }

        return `schedule:${Number(row?.schedule_id) || 0}`;
    }

    function uppercaseDisplayText(value) {
        return String(value ?? "").trim().toLocaleUpperCase();
    }

    function getConsolidatedReportTermLabel(termContext) {
        if (!termContext) {
            return "";
        }

        return `${termContext.semesterUi} Semester AY ${termContext.ayText}`;
    }

    function getConsolidatedCampusHeaderLines(termContext) {
        const campusSource = String(WORKLOAD_CAMPUS_NAME || "Campus").trim();
        const campusLabel = campusSource.replace(/\s+campus$/i, "").trim().toLocaleUpperCase() || "CAMPUS";
        const semesterLabel = String(termContext?.semesterUi || "").trim() || "Semester";
        const ayLabel = String(termContext?.ayText || "").trim();

        return [
            campusLabel,
            "Campus",
            semesterLabel,
            ayLabel ? `Semester AY ${ayLabel}` : "Semester AY"
        ];
    }

    function formatConsolidatedRemarkVariance(value) {
        const n = Math.abs(toNumber(value));
        return n > LOAD_COMPARISON_TOLERANCE ? n.toFixed(2) : "";
    }

    function getConsolidatedRemarkMeta(loadStatus) {
        const statusClass = String(loadStatus?.className || "normal").trim() || "normal";

        if (statusClass === "overload") {
            return { code: "OL", className: "is-overload" };
        }

        if (statusClass === "underload") {
            return { code: "UL", className: "is-underload" };
        }

        return { code: "NL", className: "is-normal" };
    }

    function setConsolidatedReportPrintEnabled(enabled) {
        $("#btnPrintConsolidatedReport").prop("disabled", !enabled);
    }

    function setConsolidatedReportProgress(percent, label) {
        const safePercent = Math.max(0, Math.min(100, Math.round(Number(percent) || 0)));
        $("#consolidatedReportProgressLabel").text(String(label || "Preparing preview..."));
        $("#consolidatedReportProgressValue").text(`${safePercent}%`);
        $("#consolidatedReportProgressBar").css("width", `${safePercent}%`);
    }

    function showConsolidatedReportLoading(label = "Preparing preview...", percent = 0) {
        consolidatedReportGeneratedHtml = "";
        setConsolidatedReportPrintEnabled(false);
        setConsolidatedReportProgress(percent, label);
        $("#consolidatedReportPreviewRoot").html(`
            <div class="consolidated-report-loading">
                <div class="consolidated-report-loading-card">
                    <div class="spinner-border text-primary" role="status" aria-hidden="true"></div>
                    <div class="consolidated-report-loading-title">Preparing consolidated faculty workload</div>
                    <div class="consolidated-report-loading-copy">The preview is built from the actual workload assigned in scheduler for the selected college and term.</div>
                    <div class="consolidated-report-progress">
                        <div class="consolidated-report-progress-meta">
                            <span id="consolidatedReportProgressLabel">${escapeHtml(String(label || "Preparing preview..."))}</span>
                            <span id="consolidatedReportProgressValue">${escapeHtml(String(Math.max(0, Math.min(100, Math.round(Number(percent) || 0)))))}%</span>
                        </div>
                        <div class="consolidated-report-progress-track">
                            <div id="consolidatedReportProgressBar" class="consolidated-report-progress-bar" style="width:${Math.max(0, Math.min(100, Math.round(Number(percent) || 0)))}%;"></div>
                        </div>
                    </div>
                </div>
            </div>
        `);
    }

    function setConsolidatedReportEmptyState(title, message) {
        consolidatedReportGeneratedHtml = "";
        setConsolidatedReportPrintEnabled(false);
        $("#consolidatedReportPreviewRoot").html(`
            <div class="consolidated-report-empty-state">
                <i class="bx bx-file-blank"></i>
                <div class="consolidated-report-empty-title">${escapeHtml(title)}</div>
                <div class="consolidated-report-empty-copy">${escapeHtml(message)}</div>
            </div>
        `);
    }

    function fetchConsolidatedFacultyWorkloadPayload(facultyId, termContext) {
        return new Promise(function (resolve, reject) {
            $.ajax({
                url: "../backend/query_load_faculty_workload.php",
                type: "POST",
                dataType: "json",
                data: {
                    assignee_type: "faculty",
                    faculty_id: facultyId,
                    faculty_need_id: 0,
                    ay_id: termContext.ayId,
                    semester: termContext.semesterNum
                }
            }).done(resolve).fail(reject);
        });
    }

    function fetchConsolidatedAssigneeWorkloadPayload(option, termContext) {
        const assigneeType = String(option?.assignee_type || "faculty").trim() === "faculty_need"
            ? "faculty_need"
            : "faculty";
        const facultyId = assigneeType === "faculty" ? (Number(option?.faculty_id) || 0) : 0;
        const facultyNeedId = assigneeType === "faculty_need" ? (Number(option?.faculty_need_id) || 0) : 0;

        return new Promise(function (resolve, reject) {
            $.ajax({
                url: "../backend/query_load_faculty_workload.php",
                type: "POST",
                dataType: "json",
                data: {
                    assignee_type: assigneeType,
                    faculty_id: facultyId,
                    faculty_need_id: facultyNeedId,
                    ay_id: termContext.ayId,
                    semester: termContext.semesterNum
                }
            }).done(resolve).fail(reject);
        });
    }

    function buildConsolidatedScheduleLine(row, includeTypeTag) {
        const dayLabel = String(row?.days || "").trim();
        const timeLabel = String(row?.time || "").trim();
        const typeLabel = String(row?.type || row?.schedule_type || "").trim().toUpperCase();
        const parts = [];

        if (dayLabel) {
            parts.push(dayLabel);
        }

        if (timeLabel) {
            parts.push(timeLabel);
        }

        let line = parts.join(" ").trim() || "-";
        if (includeTypeTag && typeLabel) {
            line += ` (${typeLabel === "LAB" ? "Lab" : "Lec"})`;
        }

        return line;
    }

    function groupWorkloadRowsForConsolidatedReport(rowsData) {
        const groups = [];
        const orderedRows = Array.isArray(rowsData) ? rowsData : [];
        const groupedRows = new Map();

        orderedRows.forEach(function (row) {
            const groupKey = getWorkloadGroupKey(row);
            if (!groupedRows.has(groupKey)) {
                groupedRows.set(groupKey, []);
            }

            groupedRows.get(groupKey).push(row);
        });

        groupedRows.forEach(function (groupRows) {
            const firstRow = groupRows[0] || {};
            const uniqueTypes = Array.from(new Set(groupRows
                .map(function (groupRow) {
                    return String(groupRow?.type || groupRow?.schedule_type || "").trim().toUpperCase();
                })
                .filter(Boolean)));
            const scheduleLines = [];

            groupRows.forEach(function (groupRow) {
                const line = buildConsolidatedScheduleLine(groupRow, uniqueTypes.length > 1);
                if (line && scheduleLines.indexOf(line) === -1) {
                    scheduleLines.push(line);
                }
            });

            groups.push({
                sub_code: String(firstRow?.sub_code || "").trim(),
                desc: String(firstRow?.desc || "").trim(),
                course: String(firstRow?.course || firstRow?.section || "").trim(),
                student_count: groupRows.reduce(function (maxValue, groupRow) {
                    return Math.max(maxValue, Math.round(toNumber(groupRow?.student_count)));
                }, 0),
                units: toNumber(firstRow?.units),
                lec: toNumber(firstRow?.lec),
                lab: toNumber(firstRow?.lab),
                faculty_load: toNumber(firstRow?.faculty_load),
                schedule_lines: scheduleLines.length > 0 ? scheduleLines : ["-"],
                type_labels: uniqueTypes
            });
        });

        return groups;
    }

    function buildConsolidatedFacultyRecord(option, payload, overviewRow = null) {
        const sourcePayload = normalizeWorkloadResponse(payload);
        if (!sourcePayload) {
            return null;
        }

        const collegeRows = Array.isArray(sourcePayload.rows) ? sourcePayload.rows : [];
        const entries = groupWorkloadRowsForConsolidatedReport(collegeRows);
        const meta = sourcePayload.meta || {};
        const totals = calculateWorkloadMetricTotals(collegeRows);
        const designationUnits = Math.max(
            toNumber(meta.designation_units),
            toNumber(overviewRow?.designation_units)
        );
        const totalPreparations = Math.max(
            Number(meta.total_preparations) || 0,
            Number(overviewRow?.total_preparations) || 0,
            totals.preparations
        );
        const teachingLoad = totals.load;
        const totalWorkload = teachingLoad + designationUnits;
        const designationText = formatDesignationDisplay(meta);
        const normalLoadUnits = getNormalLoadUnits(totalPreparations);
        const loadStatus = getLoadStatus(totalWorkload, totalPreparations);
        const remarkMeta = getConsolidatedRemarkMeta(loadStatus);
        const overloadExcess = totalWorkload - normalLoadUnits;
        const remarkTotal = overloadExcess > LOAD_COMPARISON_TOLERANCE
            ? overloadExcess
            : 0;

        if (entries.length === 0 && totalWorkload <= LOAD_COMPARISON_TOLERANCE) {
            return null;
        }

        return {
            assignee_type: String(option?.assignee_type || "faculty").trim() === "faculty_need" ? "faculty_need" : "faculty",
            faculty_id: Number(option?.faculty_id) || 0,
            faculty_need_id: Number(option?.faculty_need_id) || 0,
            full_name: String(option?.full_name || "").trim(),
            display_name: uppercaseDisplayText(option?.full_name || ""),
            total_preparations: totalPreparations,
            designation_text: designationText,
            designation_units: designationUnits,
            teaching_load: teachingLoad,
            total_workload: totalWorkload,
            normal_load_units: normalLoadUnits,
            remark_code: remarkMeta.code,
            remark_class: remarkMeta.className,
            remark_total: remarkTotal,
            entries: entries.length > 0 ? entries : [{
                sub_code: "",
                desc: "",
                course: "",
                student_count: 0,
                units: 0,
                lec: 0,
                lab: 0,
                faculty_load: 0,
                schedule_lines: ["-"],
                type_labels: []
            }]
        };
    }

    function renderConsolidatedReportTableHead(termContext) {
        const campusLines = getConsolidatedCampusHeaderLines(termContext)
            .map(function (line) {
                return `<span class="consolidated-campus-term-line">${escapeHtml(line)}</span>`;
            })
            .join("");

        return `
            <thead>
                <tr>
                    <th rowspan="3" colspan="2" class="consolidated-campus-term">${campusLines}</th>
                    <th rowspan="3">No. of<br>Preparations</th>
                    <th colspan="9">Actual Teaching Loads</th>
                    <th rowspan="3">Designation &amp; Load<br>Displacement</th>
                    <th rowspan="3">Total<br>Workload</th>
                    <th rowspan="3">Remarks<br>(OL/UL)</th>
                </tr>
                <tr>
                    <th rowspan="2">Course<br>Code</th>
                    <th rowspan="2">Course Title</th>
                    <th rowspan="2">Day &amp; Time</th>
                    <th rowspan="2">Course,<br>Year &amp; Section</th>
                    <th rowspan="2">No. of<br>Students</th>
                    <th rowspan="2">No. of<br>Units</th>
                    <th colspan="2">No of Hours</th>
                    <th rowspan="2">Teaching<br>Load</th>
                </tr>
                <tr>
                    <th class="is-lower">Lec</th>
                    <th class="is-lower">Lab</th>
                </tr>
            </thead>
        `;
    }

    function renderConsolidatedReportColGroup() {
        const columnWidths = [
            3.1, 11.4, 4.4, 6.8, 15.6,
            10.9, 9.1, 4.8, 4.4, 4,
            4, 4.8, 8.3, 4.3, 4.1
        ];

        return `
            <colgroup>
                ${columnWidths.map(function (width) {
                    return `<col style="width:${width}%">`;
                }).join("")}
            </colgroup>
        `;
    }

    function renderConsolidatedFacultyBlock(record, options = {}) {
        const rows = Array.isArray(options.entries)
            ? options.entries
            : (Array.isArray(record?.entries) && record.entries.length > 0
                ? record.entries
                : []);
        const safeRows = rows.length > 0
            ? rows
            : [];
        const showTotalRow = options.showTotalRow !== false;
        const contentRowspan = Math.max(safeRows.length, 1);
        const blockRowspan = contentRowspan + (showTotalRow ? 1 : 0);
        const blockClasses = ["consolidated-faculty-block"];
        const designationHtml = record.designation_text
            ? `<div class="consolidated-designation-title">${escapeHtml(record.designation_text)}</div>`
            : (record.designation_units > 0 ? '<div class="consolidated-designation-title">Load Displacement</div>' : "&nbsp;");
        const remarkTotalText = record.remark_code === "OL"
            ? formatConsolidatedRemarkVariance(record.remark_total)
            : "";

        if (options.continuation) {
            blockClasses.push("is-continuation");
        }

        return `
            <tbody class="${blockClasses.join(" ")}">
                ${safeRows.map(function (entry, index) {
                    const scheduleHtml = (Array.isArray(entry?.schedule_lines) ? entry.schedule_lines : ["-"])
                        .map(function (line) {
                            return `<span class="consolidated-schedule-line">${escapeHtml(line)}</span>`;
                        })
                        .join("");

                    return `
                        <tr>
                            ${index === 0 ? `
                                <td rowspan="${blockRowspan}" class="consolidated-cell-index">${escapeHtml(String(record.sequence || ""))}</td>
                                <td rowspan="${blockRowspan}" class="consolidated-cell-name">${escapeHtml(record.display_name || record.full_name || "")}</td>
                                <td rowspan="${blockRowspan}" class="consolidated-cell-prep">${escapeHtml(String(record.total_preparations || 0))}</td>
                            ` : ""}
                            <td class="consolidated-cell-code">${escapeHtml(entry?.sub_code || "")}</td>
                            <td class="consolidated-cell-title">
                                <span class="consolidated-course-title">${escapeHtml(entry?.desc || "")}</span>
                            </td>
                            <td class="consolidated-cell-schedule">${scheduleHtml}</td>
                            <td class="consolidated-cell-course">${escapeHtml(entry?.course || "")}</td>
                            <td class="consolidated-cell-students">${escapeHtml(formatStudentCount(entry?.student_count || 0))}</td>
                            <td class="consolidated-cell-units">${escapeHtml(formatNumber(entry?.units || 0))}</td>
                            <td class="consolidated-cell-hours">${escapeHtml(formatNumber(entry?.lec || 0))}</td>
                            <td class="consolidated-cell-hours">${escapeHtml(formatNumber(entry?.lab || 0))}</td>
                            <td class="consolidated-cell-load">${escapeHtml(formatNumber(entry?.faculty_load || 0))}</td>
                            ${index === 0 ? `
                                <td rowspan="${contentRowspan}" class="consolidated-cell-designation">${designationHtml}</td>
                                <td rowspan="${contentRowspan}" class="consolidated-cell-total">${escapeHtml(formatNumber(record.total_workload || 0))}</td>
                                <td rowspan="${contentRowspan}" class="consolidated-cell-remark">
                                    <span class="consolidated-remark-text">${escapeHtml(record.remark_code || "NL")}</span>
                                </td>
                            ` : ""}
                        </tr>
                    `;
                }).join("")}
                ${showTotalRow ? `
                    <tr class="consolidated-faculty-total-row">
                        <td class="consolidated-cell-code">&nbsp;</td>
                        <td class="consolidated-cell-title">&nbsp;</td>
                        <td class="consolidated-cell-schedule">&nbsp;</td>
                        <td class="consolidated-cell-course">&nbsp;</td>
                        <td class="consolidated-cell-students">&nbsp;</td>
                        <td class="consolidated-cell-units">&nbsp;</td>
                        <td class="consolidated-cell-hours">&nbsp;</td>
                        <td class="consolidated-cell-hours">&nbsp;</td>
                        <td class="consolidated-cell-load">${escapeHtml(formatNumber(record.teaching_load || 0))}</td>
                        <td class="consolidated-cell-designation">${record.designation_units > 0 ? escapeHtml(formatNumber(record.designation_units)) : "&nbsp;"}</td>
                        <td class="consolidated-cell-total">&nbsp;</td>
                        <td class="consolidated-cell-remark-total">${remarkTotalText ? escapeHtml(remarkTotalText) : "&nbsp;"}</td>
                    </tr>
                ` : ""}
            </tbody>
        `;
    }

    function renderConsolidatedSignatoryBlock(label, signatory) {
        const name = uppercaseDisplayText(signatory?.signatory_name || "");
        const title = String(signatory?.signatory_title || "").trim();

        return `
            <div class="consolidated-signatory-block">
                <div class="consolidated-signatory-label">${escapeHtml(label)}</div>
                <div class="consolidated-signatory-name">${name ? escapeHtml(name) : "&nbsp;"}</div>
                <div class="consolidated-signatory-title">${title ? escapeHtml(title) : "&nbsp;"}</div>
            </div>
        `;
    }

    function createConsolidatedReportElement(templateHtml) {
        const template = document.createElement("template");
        template.innerHTML = String(templateHtml || "").trim();
        return template.content.firstElementChild;
    }

    function createConsolidatedReportPage(termContext, options = {}) {
        const page = document.createElement("section");
        page.className = `consolidated-report-page ${options.measure ? "is-measure" : ""} ${options.signatoryPage ? "consolidated-signatory-page" : ""}`.trim();
        page.style.setProperty("--consolidated-report-bg", `url("${CONSOLIDATED_REPORT_BACKGROUND_URL}")`);

        const campusLabel = uppercaseDisplayText(WORKLOAD_CAMPUS_NAME || "Campus");
        const collegeLabel = uppercaseDisplayText(WORKLOAD_COLLEGE_NAME || "College");
        const titleLabel = options.signatoryPage ? "CONSOLIDATED FACULTY WORKLOAD SIGNATORIES" : "CONSOLIDATED FACULTY WORKLOAD";
        const bodyHtml = options.signatoryPage
            ? `
                <div class="consolidated-signatory-wrap">
                    <div class="consolidated-signatory-top">
                        ${renderConsolidatedSignatoryBlock("Prepared by:", CONSOLIDATED_REPORT_SIGNATORIES?.prepared_by || {})}
                        ${renderConsolidatedSignatoryBlock("Checked by:", CONSOLIDATED_REPORT_SIGNATORIES?.checked_by_left || {})}
                        ${renderConsolidatedSignatoryBlock("Checked by:", CONSOLIDATED_REPORT_SIGNATORIES?.checked_by_right || {})}
                    </div>
                    <div class="consolidated-signatory-bottom">
                        ${renderConsolidatedSignatoryBlock("Recommending Approval:", CONSOLIDATED_REPORT_SIGNATORIES?.recommending_approval || {})}
                        ${renderConsolidatedSignatoryBlock("Approved by:", CONSOLIDATED_REPORT_SIGNATORIES?.approved_by || {})}
                    </div>
                </div>
            `
            : `
                <div class="consolidated-report-table-wrap">
                    <table class="consolidated-report-table">
                        ${renderConsolidatedReportColGroup()}
                        ${renderConsolidatedReportTableHead(termContext)}
                    </table>
                </div>
            `;

        page.innerHTML = `
            <div class="consolidated-report-page-background"></div>
            <div class="consolidated-report-page-inner">
                <div class="consolidated-report-page-header">
                    <div class="consolidated-report-title">${escapeHtml(titleLabel)}</div>
                    <div class="consolidated-report-subtitle">${escapeHtml(campusLabel)}</div>
                    <div class="consolidated-report-subtitle">${escapeHtml(collegeLabel)}</div>
                    <div class="consolidated-report-term">${escapeHtml(getConsolidatedReportTermLabel(termContext))}</div>
                    <div class="consolidated-report-page-count">Page <span class="consolidated-page-number">1</span> of <span class="consolidated-page-total">1</span></div>
                </div>
                ${bodyHtml}
            </div>
        `;

        return {
            page,
            inner: page.querySelector(".consolidated-report-page-inner"),
            table: page.querySelector(".consolidated-report-table")
        };
    }

    function updateConsolidatedReportPageMeta(pageElements) {
        const pages = Array.isArray(pageElements) ? pageElements : [];
        const total = pages.length;

        pages.forEach(function (page, index) {
            $(page).find(".consolidated-page-number").text(index + 1);
            $(page).find(".consolidated-page-total").text(total);
        });
    }

    function createConsolidatedMeasurePageBundle(measureRoot, generatedPages, termContext) {
        const pageBundle = createConsolidatedReportPage(termContext, { measure: true });
        measureRoot.appendChild(pageBundle.page);
        generatedPages.push(pageBundle.page);
        return pageBundle;
    }

    function consolidatedReportPageOverflows(pageBundle) {
        if (!pageBundle?.inner) {
            return false;
        }

        return pageBundle.inner.scrollHeight > pageBundle.inner.clientHeight + 1;
    }

    function pageHasConsolidatedReportRows(pageBundle) {
        return Boolean(pageBundle?.table?.querySelector("tbody"));
    }

    function createConsolidatedFacultyChunkElement(record, entries, options = {}) {
        return createConsolidatedReportElement(renderConsolidatedFacultyBlock(record, {
            entries,
            continuation: Boolean(options.continuation),
            showTotalRow: options.showTotalRow !== false
        }));
    }

    function consolidatedFacultyChunkFits(pageBundle, record, entries, options = {}) {
        const blockElement = createConsolidatedFacultyChunkElement(record, entries, options);
        if (!(blockElement instanceof HTMLElement)) {
            return false;
        }

        pageBundle.table.appendChild(blockElement);
        const fits = !consolidatedReportPageOverflows(pageBundle);
        pageBundle.table.removeChild(blockElement);
        return fits;
    }

    function getConsolidatedFittableEntryCount(pageBundle, record, entries, startIndex) {
        const safeEntries = Array.isArray(entries) ? entries : [];
        const remainingCount = safeEntries.length - startIndex;
        let bestCount = 0;

        for (let count = 1; count <= remainingCount; count++) {
            const isFinalChunk = (startIndex + count) >= safeEntries.length;
            const chunkEntries = safeEntries.slice(startIndex, startIndex + count);
            const fits = consolidatedFacultyChunkFits(pageBundle, record, chunkEntries, {
                continuation: startIndex > 0,
                showTotalRow: isFinalChunk
            });

            if (!fits) {
                break;
            }

            bestCount = count;
        }

        return bestCount;
    }

    function appendConsolidatedFacultyChunk(pageBundle, record, entries, options = {}) {
        const blockElement = createConsolidatedFacultyChunkElement(record, entries, options);
        if (!(blockElement instanceof HTMLElement)) {
            return false;
        }

        pageBundle.table.appendChild(blockElement);
        return true;
    }

    function buildConsolidatedPreviewHtml(records, termContext) {
        const measureRoot = document.getElementById("consolidatedReportMeasureRoot");
        if (!measureRoot) {
            return "";
        }

        measureRoot.innerHTML = "";
        const generatedPages = [];
        let pageBundle = createConsolidatedMeasurePageBundle(measureRoot, generatedPages, termContext);

        records.forEach(function (record) {
            const entries = Array.isArray(record?.entries) && record.entries.length > 0
                ? record.entries
                : [];
            let startIndex = 0;

            while (startIndex < entries.length) {
                let fitCount = getConsolidatedFittableEntryCount(pageBundle, record, entries, startIndex);

                if (fitCount <= 0 && pageHasConsolidatedReportRows(pageBundle)) {
                    pageBundle = createConsolidatedMeasurePageBundle(measureRoot, generatedPages, termContext);
                    fitCount = getConsolidatedFittableEntryCount(pageBundle, record, entries, startIndex);
                }

                if (fitCount <= 0) {
                    fitCount = 1;
                }

                const chunkEntries = entries.slice(startIndex, startIndex + fitCount);
                const isFinalChunk = (startIndex + fitCount) >= entries.length;
                const appended = appendConsolidatedFacultyChunk(pageBundle, record, chunkEntries, {
                    continuation: startIndex > 0,
                    showTotalRow: isFinalChunk
                });

                if (!appended) {
                    break;
                }

                startIndex += fitCount;
            }
        });

        const signatoryBundle = createConsolidatedReportPage(termContext, { measure: true, signatoryPage: true });
        measureRoot.appendChild(signatoryBundle.page);
        generatedPages.push(signatoryBundle.page);

        const printablePages = generatedPages.map(function (page) {
            return page.cloneNode(true);
        });
        updateConsolidatedReportPageMeta(printablePages);
        measureRoot.innerHTML = "";

        return printablePages.map(function (page) {
            return page.outerHTML;
        }).join("");
    }

    async function openConsolidatedReportPreview(forceReloadOverview = false) {
        const termContext = getFacultyBrowserTermContext();
        if (!termContext) {
            Swal.fire("Missing Term", "Select Academic Year and Semester first.", "warning");
            return;
        }

        const facultyOptions = getFacultyOptionList().map(function (option) {
            return {
                assignee_type: "faculty",
                faculty_id: option.faculty_id,
                faculty_need_id: 0,
                full_name: option.full_name
            };
        });
        const facultyNeedOptions = getFacultyNeedOptionList().map(function (option) {
            return {
                assignee_type: "faculty_need",
                faculty_id: 0,
                faculty_need_id: option.faculty_need_id,
                full_name: option.need_label
            };
        });
        const assigneeOptions = facultyOptions.concat(facultyNeedOptions)
            .slice()
            .sort(function (left, right) {
                return String(left.full_name || "").localeCompare(String(right.full_name || ""));
            });

        if (assigneeOptions.length === 0) {
            Swal.fire("No Faculty", "No active faculty or faculty need is available in this college for the selected term.", "info");
            return;
        }

        if (consolidatedReportIsBuilding) {
            return;
        }

        if (consolidatedReportModalInstance) {
            consolidatedReportModalInstance.show();
        }

        consolidatedReportIsBuilding = true;
        showConsolidatedReportLoading("Loading faculty load overview...", 5);
        $("#btnRefreshConsolidatedReport").prop("disabled", true);

        try {
            let overviewRows = [];

            try {
                overviewRows = await loadFacultyOverview(forceReloadOverview);
            } catch (overviewError) {
                overviewRows = Array.isArray(facultyOverviewCache) ? facultyOverviewCache : [];
            }

            const overviewMap = new Map(
                (Array.isArray(overviewRows) ? overviewRows : []).map(function (row) {
                    return [Number(row?.faculty_id) || 0, row];
                })
            );
            const facultyNeedOverviewMap = new Map(
                (Array.isArray(facultyNeedOverviewCache) ? facultyNeedOverviewCache : []).map(function (row) {
                    return [Number(row?.faculty_need_id) || 0, row];
                })
            );
            const records = [];

            for (let index = 0; index < assigneeOptions.length; index++) {
                const option = assigneeOptions[index];
                const percent = Math.round(((index + 1) / assigneeOptions.length) * 86);
                setConsolidatedReportProgress(
                    percent,
                    `Loading ${option.full_name} (${index + 1} of ${assigneeOptions.length})...`
                );

                try {
                    const payload = await fetchConsolidatedAssigneeWorkloadPayload(option, termContext);
                    const overviewRow = option.assignee_type === "faculty_need"
                        ? (facultyNeedOverviewMap.get(option.faculty_need_id) || null)
                        : (overviewMap.get(option.faculty_id) || null);
                    const record = buildConsolidatedFacultyRecord(option, payload, overviewRow);
                    if (record) {
                        records.push(record);
                    }
                } catch (facultyError) {
                    console.error("Failed to load consolidated workload for assignee", option, facultyError);
                }
            }

            records.sort(function (left, right) {
                return String(left.full_name || "").localeCompare(String(right.full_name || ""));
            });
            records.forEach(function (record, index) {
                record.sequence = index + 1;
            });

            if (records.length === 0) {
                setConsolidatedReportEmptyState(
                    "No report rows available",
                    "There is no assigned scheduler workload yet for the selected college and term."
                );
                return;
            }

            setConsolidatedReportProgress(95, "Building legal landscape preview...");
            const previewHtml = buildConsolidatedPreviewHtml(records, termContext);

            if (!previewHtml) {
                setConsolidatedReportEmptyState(
                    "Preview unavailable",
                    "The consolidated report preview could not be generated right now."
                );
                return;
            }

            consolidatedReportGeneratedHtml = previewHtml;
            $("#consolidatedReportPreviewRoot").html(previewHtml);
            setConsolidatedReportProgress(100, "Preview ready");
            setConsolidatedReportPrintEnabled(true);
        } catch (reportError) {
            console.error("Failed to build consolidated workload preview", reportError);
            setConsolidatedReportEmptyState(
                "Preview unavailable",
                "The consolidated faculty workload preview could not be generated right now."
            );
        } finally {
            consolidatedReportIsBuilding = false;
            $("#btnRefreshConsolidatedReport").prop("disabled", false);
        }
    }

    function printConsolidatedReportPreview() {
        if (!consolidatedReportGeneratedHtml) {
            return;
        }

        const reportStyleTag = document.getElementById("consolidatedReportStyleTag");
        const frameId = "consolidatedReportPrintFrame";
        const existingFrame = document.getElementById(frameId);
        if (existingFrame) {
            existingFrame.remove();
        }

        const printFrame = document.createElement("iframe");
        printFrame.id = frameId;
        printFrame.style.position = "fixed";
        printFrame.style.right = "0";
        printFrame.style.bottom = "0";
        printFrame.style.width = "0";
        printFrame.style.height = "0";
        printFrame.style.border = "0";
        printFrame.setAttribute("aria-hidden", "true");
        document.body.appendChild(printFrame);

        const printDocument = printFrame.contentWindow?.document;
        if (!printDocument || !printFrame.contentWindow) {
            printFrame.remove();
            Swal.fire("Print Error", "The print preview could not be opened right now.", "error");
            return;
        }

        printDocument.open();
        printDocument.write(`
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
                <title>Consolidated Faculty Workload</title>
                <style>
                    @media print {
                        @page {
                            size: legal landscape;
                            margin: 0;
                        }
                    }
                </style>
                ${reportStyleTag ? reportStyleTag.outerHTML : ""}
            </head>
            <body class="consolidated-report-print-body">
                <div class="consolidated-report-preview-root">
                    ${consolidatedReportGeneratedHtml}
                </div>
            </body>
            </html>
        `);
        printDocument.close();

        let printTriggered = false;
        const triggerPrint = function () {
            if (printTriggered) {
                return;
            }

            printTriggered = true;
            setTimeout(function () {
                try {
                    printFrame.contentWindow.focus();
                    printFrame.contentWindow.print();
                } finally {
                    setTimeout(function () {
                        printFrame.remove();
                    }, 1500);
                }
            }, 350);
        };

        printFrame.addEventListener("load", triggerPrint, { once: true });
        setTimeout(triggerPrint, 700);
    }

    function getSchedulePairColor(groupKey) {
        const text = String(groupKey || "");
        let hash = 0;

        for (let i = 0; i < text.length; i++) {
            hash = ((hash << 5) - hash) + text.charCodeAt(i);
            hash |= 0;
        }

        return SCHEDULE_PAIR_COLORS[Math.abs(hash) % SCHEDULE_PAIR_COLORS.length];
    }

    function getSchedulePairRowClass(index, total) {
        if (total <= 1) {
            return "";
        }

        if (index === 0) {
            return "linked-schedule-start";
        }

        if (index === (total - 1)) {
            return "linked-schedule-end";
        }

        return "linked-schedule-middle";
    }

    function buildRemoveWorkloadButton(options = {}) {
        const rowAssigneeType = String(options.assigneeType || options?.row?.assignee_type || "faculty").trim() || "faculty";
        const workloadIds = Array.isArray(options.workloadIds)
            ? options.workloadIds
                .map(value => Number(value) || 0)
                .filter((value, index, list) => value > 0 && list.indexOf(value) === index)
            : [];
        const workloadId = workloadIds[0] || Number(options?.row?.workload_id) || 0;
        const ids = workloadIds.length > 0 ? workloadIds : (workloadId > 0 ? [workloadId] : []);
        if (ids.length === 0) {
            return "";
        }

        const subjectCode = String(options.subjectCode || options?.row?.sub_code || "").trim();
        const deleteCount = ids.length;
        const title = deleteCount > 1 ? "Remove linked lecture/laboratory workload" : "Remove workload";

        return `
            <button class="btn btn-sm btn-delete-workload btnRemoveWL"
                    data-ids="${escapeHtml(ids.join(","))}"
                    data-assignee-type="${escapeHtml(rowAssigneeType)}"
                    data-subject="${escapeHtml(subjectCode)}"
                    data-delete-count="${escapeHtml(String(deleteCount))}"
                    title="${escapeHtml(title)}">
                <i class="bx bx-trash"></i>
            </button>
        `;
    }

    function buildExternalWorkloadAction(row) {
        const sourceLabel = getWorkloadSourceLabel(row);
        const title = sourceLabel
            ? `Assigned outside this college: ${sourceLabel}`
            : "Assigned outside this college";

        return `
            <span class="external-workload-lock" title="${escapeHtml(title)}">
                <i class="bx bx-lock-alt"></i>
                View only
            </span>
        `;
    }

    function getProgramMajorLabel(row) {
        const majors = [];
        const addMajor = function (value) {
            const major = String(value || "").trim();
            if (major !== "" && majors.indexOf(major) === -1) {
                majors.push(major);
            }
        };

        addMajor(row?.program_major);

        if (Array.isArray(row?.program_scope)) {
            row.program_scope.forEach(function (program) {
                addMajor(program?.major);
            });
        }

        return majors.join(" / ");
    }

    function buildProgramMajorNote(row) {
        const major = getProgramMajorLabel(row);
        return major !== ""
            ? `<span class="program-major-note">Major: ${escapeHtml(major)}</span>`
            : "";
    }

    function buildWorkloadCourseCell(row) {
        const courseLabel = String(row?.course || row?.section || "").trim();
        return `${escapeHtml(courseLabel)}${buildProgramMajorNote(row)}`;
    }

    function buildMergedWorkloadCourseCell(groupRows) {
        return Array.from(new Set(groupRows.map(buildWorkloadCourseCell))).join("<br>");
    }

    function sortWorkloadRowsForDisplay(rowsData) {
        const groups = [];

        // Sort linked rows together, retaining their existing lecture/lab order.
        rowsData.forEach(function (row) {
            const lastGroup = groups[groups.length - 1];
            if (lastGroup && getWorkloadGroupKey(lastGroup[0]) === getWorkloadGroupKey(row)) {
                lastGroup.push(row);
            } else {
                groups.push([row]);
            }
        });

        const sortOptions = { numeric: true, sensitivity: "base" };
        groups.sort(function (left, right) {
            const codeCompare = String(left[0]?.sub_code || "").trim().localeCompare(
                String(right[0]?.sub_code || "").trim(), undefined, sortOptions
            );
            if (codeCompare !== 0) {
                return codeCompare;
            }

            return String(left[0]?.course || left[0]?.section || "").trim().localeCompare(
                String(right[0]?.course || right[0]?.section || "").trim(), undefined, sortOptions
            );
        });

        return groups.flat();
    }

    function buildScheduledSectionCell(row, sectionLabel) {
        const label = String(sectionLabel || "").trim();
        return `${escapeHtml(label)}${buildProgramMajorNote(row)}`;
    }

    function renderExternalWorkloadRows(externalRowsData) {
        const sourceRows = Array.isArray(externalRowsData) ? externalRowsData : [];
        if (sourceRows.length === 0) {
            return "";
        }

        let rows = `
            <tr class="external-workload-divider">
                <td colspan="12">
                    <span class="external-divider-screen">Other college / campus workload - view only</span>
                </td>
            </tr>
        `;

        for (let i = 0; i < sourceRows.length; i++) {
            const row = sourceRows[i];
            const groupKey = getWorkloadGroupKey(row);
            const groupRows = [row];

            while ((i + groupRows.length) < sourceRows.length) {
                const candidateRow = sourceRows[i + groupRows.length];
                if (getWorkloadGroupKey(candidateRow) !== groupKey) {
                    break;
                }
                groupRows.push(candidateRow);
            }

            const displayUnits = toNumber(row.units);
            const displayLabUnits = toNumber(row.lab);
            const displayLecUnits = toNumber(row.lec);
            const isMergedGroup = groupRows.length > 1;

            if (isMergedGroup) {
                const mergedStudents = groupRows.reduce(function (maxValue, groupRow) {
                    return Math.max(maxValue, toNumber(groupRow.student_count));
                }, 0);

                groupRows.forEach(function (groupRow, groupIndex) {
                    rows += `
                        <tr class="external-workload-row ${groupIndex === 0 ? "paired-row paired-anchor workload-group-start" : "paired-row"}${groupIndex === groupRows.length - 1 ? " workload-group-end" : ""}">
                            ${groupIndex === 0 ? `<td class="workload-code" rowspan="${groupRows.length}">${escapeHtml(groupRow.sub_code)}</td>` : ""}
                            <td class="workload-desc">${buildWorkloadDescription(groupRow, true)}</td>
                            ${groupIndex === 0 ? `<td class="workload-course" rowspan="${groupRows.length}">${buildMergedWorkloadCourseCell(groupRows)}</td>` : ""}
                            <td class="workload-days">${escapeHtml(groupRow.days)}</td>
                            <td class="workload-time">${formatCompactTime(groupRow.time)}</td>
                            <td class="workload-room">${escapeHtml(groupRow.room)}</td>
                            ${groupIndex === 0 ? `
                                <td class="text-center merged-metric" rowspan="${groupRows.length}">${formatNumber(displayUnits)}</td>
                                <td class="text-center merged-metric" rowspan="${groupRows.length}">${formatNumber(displayLabUnits)}</td>
                                <td class="text-center merged-metric" rowspan="${groupRows.length}">${formatNumber(displayLecUnits)}</td>
                                <td class="text-center merged-metric" rowspan="${groupRows.length}">${formatNumber(row.faculty_load)}</td>
                                <td class="text-center merged-metric" rowspan="${groupRows.length}">${formatStudentCount(mergedStudents)}</td>
                                <td class="text-end screen-only" rowspan="${groupRows.length}">
                                    ${buildExternalWorkloadAction(row)}
                                </td>
                            ` : ""}
                        </tr>
                    `;
                });

                i += groupRows.length - 1;
                continue;
            }

            rows += `
                <tr class="external-workload-row workload-group-start workload-group-end">
                    <td class="workload-code">${escapeHtml(row.sub_code)}</td>
                    <td class="workload-desc">${buildWorkloadDescription(row)}</td>
                    <td class="workload-course">${buildWorkloadCourseCell(row)}</td>
                    <td class="workload-days">${escapeHtml(row.days)}</td>
                    <td class="workload-time">${formatCompactTime(row.time)}</td>
                    <td class="workload-room">${escapeHtml(row.room)}</td>
                    <td class="text-center">${formatNumber(displayUnits)}</td>
                    <td class="text-center">${formatNumber(displayLabUnits)}</td>
                    <td class="text-center">${formatNumber(displayLecUnits)}</td>
                    <td class="text-center fw-semibold">${formatNumber(row.faculty_load)}</td>
                    <td class="text-center">${formatStudentCount(row.student_count)}</td>
                    <td class="text-end screen-only">
                        ${buildExternalWorkloadAction(row)}
                    </td>
                </tr>
            `;
        }

        return rows;
    }

    function getSelectedContext() {
        const selectedValue = String($("#faculty_id").val() || "").trim();
        const ayText = $("#fw_ay").val();
        const semesterUi = $("#fw_semester").val();
        const ayId = Number($("#fw_ay option:selected").data("ay-id")) || 0;
        const semesterNum = SEMESTER_MAP[semesterUi] || 0;
        const needMatch = selectedValue.match(/^need:(\d+)$/);
        const assigneeType = needMatch ? "faculty_need" : "faculty";
        const facultyNeedId = needMatch ? (Number(needMatch[1]) || 0) : 0;
        const facultyId = assigneeType === "faculty" ? (Number(selectedValue) || 0) : 0;

        if (!selectedValue || (assigneeType === "faculty" && !facultyId) || (assigneeType === "faculty_need" && !facultyNeedId) || !ayText || !semesterUi || !ayId || !semesterNum) {
            return null;
        }

        return {
            assigneeType,
            facultyId,
            facultyNeedId,
            assigneeValue: selectedValue,
            isFacultyNeed: assigneeType === "faculty_need",
            facultyName: $("#faculty_id option:selected").text(),
            ayId,
            ayText,
            semesterUi,
            semesterNum
        };
    }

    function updateFacultyNeedActions(context = getSelectedContext()) {
        const deleteButton = $("#btnDeleteFacultyNeed");
        if (deleteButton.length === 0) {
            return;
        }

        if (!context || !context.isFacultyNeed || !context.facultyNeedId) {
            deleteButton.hide().data("needId", 0).data("needLabel", "");
            return;
        }

        deleteButton
            .show()
            .data("needId", context.facultyNeedId)
            .data("needLabel", context.facultyName);
    }

    function abortPendingRequest(request) {
        if (request && request.readyState !== 4) {
            request.abort();
        }
    }

    function hideSelectionPanels() {
        abortPendingRequest(scheduledClassesRequest);
        abortPendingRequest(workloadListRequest);
        resetPanelLoadingState();
        currentAyId = null;
        currentSemesterNum = null;
        currentScheduledClassRows = [];
        currentWorkloadRows = [];
        $("#facultyAlert").hide();
        $("#scheduledClassCard").hide();
        $("#workloadCard").hide();
        $("#workloadStartPanel").stop(true, true).fadeIn(160);
        $("#checkAllSchedules").prop("checked", false);
        setApplyWorkloadButtonsDisabled(true);
        $("#workloadTbody").html("");
        $("#designationText").text("");
        $("#designationUNIT").text("");
        $("#designationLOAD").text("");
        $("#totalPreparations").text("0");
        $("#totalUNIT").text("0");
        $("#totalLEC").text("0");
        $("#totalLAB").text("0");
        $("#totalLOADCell").text("0");
        $("#printTotalLOADCell").text("0");
        $("#totalStudents").text("");
        setPrintField("#printFacultyName", "");
        setPrintField("#printSemesterAy", "");
        setPrintField("#printConforme", "");
        clearPrintSignatories();
        updateFacultyNeedActions(null);
        updateApplyWorkloadControls();
        renderFacultyBrowser();
    }

    function refreshWorkloadPanels() {
        const context = getSelectedContext();

        if (!context) {
            hideSelectionPanels();
            return null;
        }

        currentAyId = context.ayId;
        currentSemesterNum = context.semesterNum;

        const requestToken = ++selectionRequestToken;
        beginPanelLoading(
            requestToken,
            context.isFacultyNeed
                ? "Loading faculty need schedules and workload..."
                : "Loading faculty schedules and workload..."
        );
        loadScheduledClasses(context, requestToken);
        loadWorkloadList(context, requestToken);

        return context;
    }

    /* =========================================================
       LOAD SCHEDULED CLASSES
    ========================================================= */
    function loadScheduledClasses(context = getSelectedContext(), requestToken = selectionRequestToken) {
        if (!context) return;

        abortPendingRequest(scheduledClassesRequest);
        setScheduledClassesLoadingState();

        scheduledClassesRequest = $.ajax({
            url: "../backend/query_class_schedule_loader.php",
            type: "POST",
            dataType: "json",
            data: {
                assignee_type: context.assigneeType,
                faculty_id: context.facultyId,
                faculty_need_id: context.facultyNeedId,
                ay_id: context.ayId,
                ay: context.ayText,
                semester_num: context.semesterNum,
                semester: context.semesterUi
            }
        }).done(function (data) {
            if (requestToken !== selectionRequestToken) {
                return;
            }

            if (!Array.isArray(data)) {
                showInvalid();
                return;
            }

            if (data.length === 0) {
                currentScheduledClassRows = [];
                $("#checkAllSchedules").prop("checked", false);
                $("#scheduledClassTbody").html(`
                    <tr>
                        <td colspan="11" class="text-center text-muted">
                            No scheduled classes found.
                        </td>
                    </tr>
                `);
                updateApplyWorkloadControls();
                return;
            }

            let rows = "";
            for (let i = 0; i < data.length; i++) {
                const item = data[i];
                const groupKey = getScheduledClassGroupKey(item);
                const groupRows = [item];

                while ((i + groupRows.length) < data.length) {
                    const candidateRow = data[i + groupRows.length];
                    if (getScheduledClassGroupKey(candidateRow) !== groupKey) {
                        break;
                    }
                    groupRows.push(candidateRow);
                }

                const pairColor = groupRows.length > 1 ? getSchedulePairColor(groupKey) : "";

                groupRows.forEach(function (groupRow, groupIndex) {
                    const type = String(groupRow.schedule_type || "").toUpperCase();
                    const sectionLabel = String(
                        groupRow.section_label ||
                        groupRow.merged_course_label ||
                        groupRow.full_section ||
                        groupRow.section_name ||
                        ""
                    );
                    const displayUnits = getDisplayUnits(groupRow);
                    const displayLabUnits = getDisplayLabUnits(groupRow);
                    const displayLecUnits = getDisplayLecUnits(groupRow);
                    const pendingBadge = groupRow.schedule_status === "tba" ? ' <span class="badge bg-label-warning">TBA</span>'
                        : (groupRow.schedule_status === "room_tba" ? ' <span class="badge bg-label-warning">Room TBA</span>' : '');
                    const typeBadge = (type === "LAB"
                        ? '<span class="type-pill lab">LAB</span>'
                        : '<span class="type-pill lec">LEC</span>') + pendingBadge;
                    const rowClass = groupRows.length > 1
                        ? `linked-schedule-row ${getSchedulePairRowClass(groupIndex, groupRows.length)}`
                        : "";
                    const rowStyle = groupRows.length > 1
                        ? ` style="--schedule-pair-color: ${pairColor};"`
                        : "";
                    const partnerNote = (groupRows.length > 1 && groupIndex === 0)
                        ? `<span class="scheduled-pair-note" style="--schedule-pair-color: ${pairColor};">Linked lecture/lab partner</span>`
                        : "";
                    const mergeNote = String(groupRow.merge_note || "").trim();
                    const mergeNoteHtml = mergeNote !== ""
                        ? `<span class="scheduled-merge-note">${escapeHtml(mergeNote)}</span>`
                        : "";

                    rows += `
                        <tr class="${rowClass}"${rowStyle}>
                            <td>
                                <input type="checkbox"
                                       class="chkSchedule"
                                       value="${escapeHtml(groupRow.schedule_id)}">
                            </td>
                            <td>${escapeHtml(groupRow.subject_code)}</td>
                            <td>${escapeHtml(groupRow.subject_description)}${partnerNote}${mergeNoteHtml}</td>
                            <td>${buildScheduledSectionCell(groupRow, sectionLabel)}</td>
                            <td class="text-center">${typeBadge}</td>
                            <td>${escapeHtml(groupRow.days)}</td>
                            <td>${escapeHtml(groupRow.time)}</td>
                            <td>${escapeHtml(groupRow.room_code)}</td>
                            <td class="text-center">${formatNumber(displayUnits)}</td>
                            <td class="text-center">${formatNumber(displayLabUnits)}</td>
                            <td class="text-center">${formatNumber(displayLecUnits)}</td>
                        </tr>
                    `;
                });

                i += groupRows.length - 1;
            }
            currentScheduledClassRows = data;
            $("#scheduledClassTbody").html(rows);
            applyScheduledClassAvailability();
        }).fail(function (xhr, status) {
            if (status === "abort" || requestToken !== selectionRequestToken) {
                return;
            }

            showInvalid();
            updateApplyWorkloadControls();
        }).always(function () {
            finishPanelLoading(requestToken);
        });
    }

    function showInvalid() {
        currentScheduledClassRows = [];
        $("#checkAllSchedules").prop("checked", false);
        $("#scheduledClassTbody").html(`
            <tr>
                <td colspan="11" class="text-danger text-center">
                    Invalid response from server
                </td>
            </tr>
        `);
        updateApplyWorkloadControls();
    }

    $(document).on("change", "#checkAllSchedules", function () {
        $(".chkSchedule:not(:disabled)").prop("checked", $(this).is(":checked"));
        updateApplyWorkloadControls();
    });

    $(document).on("change", ".chkSchedule", function () {
        const total = $(".chkSchedule:not(:disabled)").length;
        const checked = $(".chkSchedule:checked:not(:disabled)").length;
        $("#checkAllSchedules").prop("checked", total > 0 && total === checked);
        updateApplyWorkloadControls();
    });

    /* =========================================================
       LOAD FACULTY WORKLOAD LIST
    ========================================================= */
    function loadWorkloadList(context = getSelectedContext(), requestToken = selectionRequestToken) {
        if (!context) return;

        abortPendingRequest(workloadListRequest);
        setWorkloadLoadingState();

        workloadListRequest = $.ajax({
            url: "../backend/query_load_faculty_workload.php",
            type: "POST",
            dataType: "json",
            data: {
                assignee_type: context.assigneeType,
                faculty_id: context.facultyId,
                faculty_need_id: context.facultyNeedId,
                ay_id: context.ayId,
                semester: context.semesterNum
            }
        }).done(function (data) {
            if (requestToken !== selectionRequestToken) {
                return;
            }

            const payload = normalizeWorkloadResponse(data);
            if (!payload) {
                currentWorkloadRows = [];
                $("#workloadTbody").html(`
                    <tr>
                        <td colspan="12" class="text-danger text-center">
                            Invalid response from server
                        </td>
                    </tr>
                `);
                $("#designationText").text("");
                $("#designationUNIT").text("");
                $("#designationLOAD").text("");
                $("#totalPreparations").text("0");
                $("#totalUNIT").text("0");
                $("#totalLEC").text("0");
                $("#totalLAB").text("0");
                $("#totalLOADCell").text("0");
                $("#printTotalLOADCell").text("0");
                $("#totalStudents").text("");
                setPrintField("#printFacultyName", "");
                setPrintField("#printSemesterAy", "");
                setPrintField("#printConforme", "");
                clearPrintSignatories();
                $("#workloadCard").show();
                applyScheduledClassAvailability();
                return;
            }

            const workloadRows = payload.rows;
            const externalWorkloadRows = payload.external_rows.map(function (row) {
                return Object.assign({}, row, { is_external: true });
            });
            currentWorkloadRows = workloadRows.concat(externalWorkloadRows);
            const rowsData = sortWorkloadRowsForDisplay(workloadRows);
            const externalRowsData = sortWorkloadRowsForDisplay(externalWorkloadRows);
            const meta = payload.meta || {};
            let rows = "";

            for (let i = 0; i < rowsData.length; i++) {
                const row = rowsData[i];
                const groupKey = getWorkloadGroupKey(row);
                const groupRows = [row];

                while ((i + groupRows.length) < rowsData.length) {
                    const candidateRow = rowsData[i + groupRows.length];
                    if (getWorkloadGroupKey(candidateRow) !== groupKey) {
                        break;
                    }
                    groupRows.push(candidateRow);
                }

                const displayUnits = toNumber(row.units);
                const displayLabUnits = toNumber(row.lab);
                const displayLecUnits = toNumber(row.lec);
                const isMergedGroup = groupRows.length > 1;

                if (isMergedGroup) {
                    const mergedStudents = groupRows.reduce(function (maxValue, groupRow) {
                        return Math.max(maxValue, toNumber(groupRow.student_count));
                    }, 0);
                    const groupedWorkloadIds = groupRows
                        .map(function (groupRow) {
                            return Number(groupRow?.workload_id) || 0;
                        })
                        .filter(function (value, index, list) {
                            return value > 0 && list.indexOf(value) === index;
                        });

                    groupRows.forEach(function (groupRow, groupIndex) {
                        rows += `
                            <tr class="${groupIndex === 0 ? "paired-row paired-anchor workload-group-start" : "paired-row"}${groupIndex === groupRows.length - 1 ? " workload-group-end" : ""}">
                                ${groupIndex === 0 ? `<td class="workload-code" rowspan="${groupRows.length}">${escapeHtml(groupRow.sub_code)}</td>` : ""}
                                <td class="workload-desc">${buildWorkloadDescription(groupRow, true)}</td>
                                ${groupIndex === 0 ? `<td class="workload-course" rowspan="${groupRows.length}">${buildMergedWorkloadCourseCell(groupRows)}</td>` : ""}
                                <td class="workload-days">${escapeHtml(groupRow.days)}</td>
                                <td class="workload-time">${formatCompactTime(groupRow.time)}</td>
                                <td class="workload-room">${escapeHtml(groupRow.room)}</td>
                                ${groupIndex === 0 ? `
                                    <td class="text-center merged-metric" rowspan="${groupRows.length}">${formatNumber(displayUnits)}</td>
                                    <td class="text-center merged-metric" rowspan="${groupRows.length}">${formatNumber(displayLabUnits)}</td>
                                    <td class="text-center merged-metric" rowspan="${groupRows.length}">${formatNumber(displayLecUnits)}</td>
                                    <td class="text-center merged-metric" rowspan="${groupRows.length}">${formatNumber(row.faculty_load)}</td>
                                    <td class="text-center merged-metric" rowspan="${groupRows.length}">${formatStudentCount(mergedStudents)}</td>
                                    <td class="text-end screen-only" rowspan="${groupRows.length}">
                                        ${buildRemoveWorkloadButton({
                                            workloadIds: groupedWorkloadIds,
                                            subjectCode: groupRow.sub_code,
                                            assigneeType: groupRow.assignee_type
                                        })}
                                    </td>
                                ` : ""}
                            </tr>
                        `;
                    });

                    i += groupRows.length - 1;
                    continue;
                }

                rows += `
                    <tr class="workload-group-start workload-group-end">
                        <td class="workload-code">${escapeHtml(row.sub_code)}</td>
                        <td class="workload-desc">${buildWorkloadDescription(row)}</td>
                        <td class="workload-course">${buildWorkloadCourseCell(row)}</td>
                        <td class="workload-days">${escapeHtml(row.days)}</td>
                        <td class="workload-time">${formatCompactTime(row.time)}</td>
                        <td class="workload-room">${escapeHtml(row.room)}</td>
                        <td class="text-center">${formatNumber(displayUnits)}</td>
                        <td class="text-center">${formatNumber(displayLabUnits)}</td>
                        <td class="text-center">${formatNumber(displayLecUnits)}</td>
                        <td class="text-center fw-semibold">${formatNumber(row.faculty_load)}</td>
                        <td class="text-center">${formatStudentCount(row.student_count)}</td>
                        <td class="text-end screen-only">
                            ${buildRemoveWorkloadButton({ row })}
                        </td>
                    </tr>
                `;
            }

            if (!rows) {
                rows = `
                    <tr>
                        <td colspan="12" class="text-center text-muted">
                            ${externalRowsData.length > 0 ? "No workload assigned in this college." : "No workload assigned yet."}
                        </td>
                    </tr>
                `;
            }

            rows += renderExternalWorkloadRows(externalRowsData);

            const designationUnits = toNumber(meta.designation_units);
            const screenTotals = calculateWorkloadMetricTotals(currentWorkloadRows);
            const printTotalPreparations = Math.max(
                Number(meta.total_preparations) || 0,
                screenTotals.preparations
            );
            const screenTotalPreparations = Math.max(printTotalPreparations, screenTotals.preparations);
            const printGrandTotalUnits = screenTotals.unit + designationUnits;
            const printGrandTotalLoad = screenTotals.load + designationUnits;
            const screenGrandTotalUnits = screenTotals.unit + designationUnits;
            const screenGrandTotalLoad = screenTotals.load + designationUnits;
            const loadStatus = getLoadStatus(screenGrandTotalLoad, screenTotalPreparations);

            $("#workloadTbody").html(rows);
            $("#designationText").text(formatDesignationDisplay(meta));
            $("#designationUNIT").text(designationUnits > 0 ? formatNumber(designationUnits) : "");
            $("#designationLOAD").text(designationUnits > 0 ? formatNumber(designationUnits) : "");
            $("#totalPreparations").html(buildDualTotalValue(screenTotalPreparations, printTotalPreparations));
            $("#totalLAB").html(buildDualTotalValue(screenTotals.lab, screenTotals.lab));
            $("#totalLEC").html(buildDualTotalValue(screenTotals.lec, screenTotals.lec));
            $("#totalUNIT").html(buildDualTotalValue(screenGrandTotalUnits, printGrandTotalUnits));
            $("#totalLOADCell").html(`
                <span class="total-load-screen-inner">
                    <span class="total-load-value">${escapeHtml(formatNumber(screenGrandTotalLoad))}</span>
                    ${loadStatus.label ? `<span class="load-status-inline ${escapeHtml(loadStatus.className)}">${escapeHtml(loadStatus.label)}</span>` : ""}
                </span>
            `);
            $("#printTotalLOADCell").text(formatNumber(printGrandTotalLoad));
            $("#totalStudents").text("");

            setPrintField("#printFacultyName", context.facultyName);
            setPrintField("#printSemesterAy", context.semesterUi + " Semester | AY " + context.ayText);
            setPrintField("#printConforme", context.facultyName, { uppercase: true });
            applyWorkloadPrintSignatories(payload.signatories);

            $("#workloadCard").show();
            applyScheduledClassAvailability();
        }).fail(function (xhr, status) {
            if (status === "abort" || requestToken !== selectionRequestToken) {
                return;
            }

            currentWorkloadRows = [];
            $("#workloadTbody").html(`
                <tr>
                    <td colspan="12" class="text-danger text-center">
                        Failed to load faculty workload.
                    </td>
                </tr>
            `);
            setPrintField("#printFacultyName", "");
            setPrintField("#printSemesterAy", "");
            setPrintField("#printConforme", "");
            clearPrintSignatories();
            $("#workloadCard").show();
            applyScheduledClassAvailability();
        }).always(function () {
            finishPanelLoading(requestToken);
        });
    }

    /* =========================================================
       REMOVE WORKLOAD
    ========================================================= */
    $(document).on("click", ".btnRemoveWL", function () {
        const button = $(this);
        const workloadIds = String(button.data("ids") || "")
            .split(",")
            .map(function (value) {
                return parseInt(String(value).trim(), 10) || 0;
            })
            .filter(function (value, index, list) {
                return value > 0 && list.indexOf(value) === index;
            });
        const workloadId = workloadIds[0] || 0;
        const deleteCount = parseInt(button.data("deleteCount"), 10) || workloadIds.length || 1;
        const subjectCode = String(button.data("subject") || "this class").trim() || "this class";
        const assigneeType = String(button.data("assigneeType") || "faculty").trim() || "faculty";

        if (!workloadId) {
            Swal.fire("Unavailable", "The selected workload row cannot be removed right now.", "info");
            return;
        }

        Swal.fire({
            title: deleteCount > 1 ? "Remove linked classes?" : "Remove this class?",
            html: deleteCount > 1
                ? `Remove the linked workload rows for <b>${escapeHtml(subjectCode)}</b>? This will remove the paired lecture/laboratory assignment together.`
                : `Remove <b>${escapeHtml(subjectCode)}</b> from the selected faculty workload?`,
            icon: "warning",
            showCancelButton: true,
            confirmButtonColor: "#d33",
            confirmButtonText: "Remove"
        }).then(res => {

            if (!res.isConfirmed) return;

            const busyToken = beginSchedulerActivity(
                deleteCount > 1
                    ? `Removing linked workload for ${subjectCode}...`
                    : `Removing workload for ${subjectCode}...`
            );
            setButtonBusy(
                button,
                true,
                '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>'
            );

            $.post(
                "../backend/query_remove_workload.php",
                deleteCount > 1
                    ? { workload_ids: workloadIds, assignee_type: assigneeType }
                    : { workload_id: workloadId, assignee_type: assigneeType },
                function () {
                    refreshWorkloadPanels();
                    loadFacultyOverview(true);
                }
            ).fail(function (xhr) {
                Swal.fire("Error", xhr.responseText || "Failed to remove workload.", "error");
            }).always(function () {
                setButtonBusy(button, false);
                endSchedulerActivity(busyToken);
            });
        });
    });

    $("#btnClearCollegeWorkload").on("click", function () {
        clearAllCollegeWorkloadInScope();
    });

    /* =========================================================
       AUTO LOAD ON SELECTION CHANGE
    ========================================================= */
    $("#faculty_id, #fw_ay, #fw_semester").on("change", function () {
        const termChanged = this.id === "fw_ay" || this.id === "fw_semester";
        if (termChanged) {
            const currentAssigneeValue = String($("#faculty_id").val() || "");
            if (currentAssigneeValue.startsWith("need:")) {
                $("#faculty_id").val("").trigger("change.select2");
                loadFacultyNeedOptions("");
                clearFacultyOverviewCache();
                updateFacultyNeedActions(null);
                hideSelectionPanels();
                return;
            }

            clearFacultyOverviewCache();
            loadFacultyNeedOptions(currentAssigneeValue).always(function () {
                loadFacultyOverview(true);
            });
        } else {
            renderFacultyBrowser();
        }

        const context = getSelectedContext();
        updateFacultyNeedActions(context);
        if (!context) {
            hideSelectionPanels();
            return;
        }

        $("#facultyNameText").text(context.facultyName);
        $("#selectedAssigneeLabel").text(context.isFacultyNeed ? "Faculty Need Selected:" : "Faculty Selected:");
        $("#termSummary").text(context.semesterUi + " A.Y. " + context.ayText);

        $("#facultyAlert").stop(true, true).slideDown();
        $("#scheduledClassCard").stop(true, true).slideDown();
        $("#workloadStartPanel").stop(true, true).fadeOut(120);

        refreshWorkloadPanels();
    });

    $("#btnStartOpenFacultyOverview").on("click", function () {
        $("#btnOpenFacultyBrowser").trigger("click");
    });

    $("#btnOpenConsolidatedReport, #btnStartOpenConsolidatedReport").on("click", function () {
        openConsolidatedReportPreview(false);
    });

    $("#btnRefreshConsolidatedReport").on("click", function () {
        openConsolidatedReportPreview(true);
    });

    $("#btnPrintConsolidatedReport").on("click", function () {
        printConsolidatedReportPreview();
    });

    $("#btnStartChooseFaculty").on("click", function () {
        const selectElement = $("#faculty_id");
        if (selectElement.hasClass("select2-hidden-accessible")) {
            selectElement.select2("open");
            return;
        }

        selectElement.trigger("focus");
    });

    $("#btnAddFacultyNeed").on("click", function () {
        const termContext = getFacultyBrowserTermContext();
        if (!termContext) {
            Swal.fire("Missing Term", "Select Academic Year and Semester first.", "warning");
            return;
        }

        const button = $(this);
        const busyToken = beginSchedulerActivity("Creating faculty need...");
        setButtonBusy(
            button,
            true,
            '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Creating...'
        );

        $.ajax({
            url: "../backend/query_faculty_need.php",
            type: "POST",
            dataType: "json",
            data: {
                action: "create",
                ay_id: termContext.ayId,
                semester: termContext.semesterNum
            }
        }).done(function (response) {
            if (!response || response.status !== "ok" || !response.need) {
                Swal.fire("Error", response?.message || "Unable to create faculty need.", "error");
                return;
            }

            const selectedValue = `need:${Number(response.need.faculty_need_id) || 0}`;
            renderFacultyNeedOptions(response.needs || [], selectedValue);
            clearFacultyOverviewCache();
            $("#faculty_id").val(selectedValue).trigger("change");
            loadFacultyOverview(true);
        }).fail(function (xhr) {
            Swal.fire("Error", xhr.responseText || "Unable to create faculty need.", "error");
        }).always(function () {
            setButtonBusy(button, false);
            endSchedulerActivity(busyToken);
        });
    });

    $("#btnDeleteFacultyNeed").on("click", function () {
        const button = $(this);
        deleteFacultyNeed(
            Number(button.data("needId")) || 0,
            String(button.data("needLabel") || "")
        );
    });

    $("#btnOpenFacultyBrowser").on("click", function () {
        setFacultyBrowserMessage("Loading faculty load overview...", true);
        if (facultyBrowserDrawerInstance) {
            facultyBrowserDrawerInstance.show();
        }
        loadFacultyOverview(false).always(function () {
            renderFacultyBrowser();
        });
    });

    $(document).on("click", ".faculty-browser-card", function () {
        const assigneeValue = String($(this).data("assigneeValue") || $(this).data("facultyId") || "").trim();
        if (!assigneeValue) {
            return;
        }

        if ($("#faculty_id option[value='" + assigneeValue.replace(/'/g, "\\'") + "']").length === 0) {
            return;
        }

        if (String($("#faculty_id").val() || "") !== assigneeValue) {
            $("#faculty_id").val(assigneeValue).trigger("change");
            return;
        }

        renderFacultyBrowser();
    });

    /* =========================================================
       APPLY TO WORKLOAD
    ========================================================= */
    $(document).on("click", ".btnApplyToWorkloadTrigger", function () {

        const context = getSelectedContext();

        if (!context) {
            Swal.fire("Missing Data", "Please select Faculty or Faculty Need, A.Y., and Semester.", "warning");
            return;
        }

        let schedule_ids = [];
        $(".chkSchedule:checked").each(function () {
            schedule_ids.push($(this).val());
        });

        if (schedule_ids.length === 0) {
            Swal.fire("No Selection", "Please select at least one class.", "warning");
            return;
        }

        const busyToken = beginSchedulerActivity("Applying selected classes to workload...");
        setButtonBusy(
            $("#btnApplyToWorkload, #btnApplyToWorkloadFloating"),
            true,
            '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Applying...'
        );

        $.post("../backend/query_apply_workload.php", {
            csrf_token: <?= json_encode($_SESSION['csrf_token']) ?>,
            assignee_type: context.assigneeType,
            faculty_id: context.facultyId,
            faculty_need_id: context.facultyNeedId,
            ay_id: currentAyId,
            semester: currentSemesterNum,
            schedule_ids: schedule_ids
        }, null, "json").done(function (res) {
                if (!res || typeof res !== "object") {
                    Swal.fire("Error", "Invalid response from server.", "error");
                    return;
                }

                if (res.status === "success") {
                    Swal.fire(
                        "Applied",
                        res.message || (res.inserted + " class(es) added to workload."),
                        "success"
                    );
                    refreshWorkloadPanels();
                    loadFacultyOverview(true);
                    return;
                }

                if (res.status === "partial" || res.status === "conflict") {
                    Swal.fire({
                        icon: res.status === "partial" ? "warning" : "error",
                        title: res.status === "partial" ? "Partially Applied" : "Workload Conflict",
                        html: res.message || "Selected classes conflict with this faculty's current workload."
                    });
                    refreshWorkloadPanels();
                    loadFacultyOverview(true);
                    return;
                }

                Swal.fire("Error", res.message || "Failed to apply workload.", "error");
            }).fail(function (xhr) {
                Swal.fire("Error", xhr.responseText || "Failed to apply workload.", "error");
            }).always(function () {
                setButtonBusy($("#btnApplyToWorkload, #btnApplyToWorkloadFloating"), false);
                endSchedulerActivity(busyToken);
            });
    });


/* =========================================================
   SCHEDULED CLASSES SEARCH & FILTER (UI ONLY)
========================================================= */
    $("#scheduleSearch, #scheduleFilter").on("keyup change", function () {

    let keyword = $("#scheduleSearch").val().toLowerCase();
    let filter  = $("#scheduleFilter").val();

    $("#scheduledClassTbody tr").each(function () {

        let row = $(this);

        let course  = row.find("td:eq(1)").text().toLowerCase();
        let desc    = row.find("td:eq(2)").text().toLowerCase();
        let section = row.find("td:eq(3)").text().toLowerCase();
        let type    = row.find("td:eq(4)").text().toLowerCase();
        let days    = row.find("td:eq(5)").text().toLowerCase();
        let room    = row.find("td:eq(7)").text().toLowerCase();

        let match = false;

        switch (filter) {
            case "course":
                match = course.includes(keyword);
                break;
            case "desc":
                match = desc.includes(keyword);
                break;
            case "section":
                match = section.includes(keyword);
                break;
            case "type":
                match = type.includes(keyword);
                break;
            case "room":
                match = room.includes(keyword);
                break;
            case "days":
                match = days.includes(keyword);
                break;
            default:
                match =
                    course.includes(keyword) ||
                    desc.includes(keyword) ||
                    section.includes(keyword) ||
                    type.includes(keyword) ||
                    days.includes(keyword) ||
                    room.includes(keyword);
        }

        row.toggle(match);
    });
});

loadFacultyOverview(true);

// Subject Assignments links select an existing current-term faculty option only.
// All loading, permission, conflict and assignment behavior stays with the existing handlers.
const subjectAssignmentLink = new URLSearchParams(window.location.search);
const linkedFacultyId = subjectAssignmentLink.get("faculty_id") || "";
if (/^[1-9]\d*$/.test(linkedFacultyId)
    && subjectAssignmentLink.get("college_id") === <?= json_encode((string)$college_id) ?>
    && subjectAssignmentLink.get("ay_id") === <?= json_encode((string)$defaultAyId) ?>
    && subjectAssignmentLink.get("semester") === <?= json_encode((string)$currentTerm['semester']) ?>
    && document.querySelector(`#faculty_id option[value="${linkedFacultyId}"]`)) {
    $("#faculty_id").val(linkedFacultyId).trigger("change");
}

    

});
</script>



</body>
</html>
