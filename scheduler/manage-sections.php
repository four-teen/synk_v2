<?php
/**
 * ======================================================================
 * Page Name : Manage Sections (Section Generator)
 * File Path : synk/scheduler/manage-sections.php
 * ======================================================================
 * PURPOSE:
 * - Generate sections scoped by Academic Year + Semester
 * - Preview and save generated sections
 * - Load grouped sections filtered by AY + Semester (auto load on selection)
 * ======================================================================
 */

session_start();
ob_start();
include '../backend/db.php';
require_once '../backend/academic_term_helper.php';
require_once '../backend/schema_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'scheduler') {
    header("Location: ../index.php");
    exit;
}

$college_id = (int)($_SESSION['college_id'] ?? 0);

// FETCH COLLEGE NAME
$col = mysqli_fetch_assoc(mysqli_query(
    $conn,
    "SELECT college_name FROM tbl_college WHERE college_id='$college_id'"
));
$college_name = $col['college_name'] ?? '';

$currentTerm = synk_fetch_current_academic_term($conn);
$default_ay_id = (int)$currentTerm['ay_id'];
$default_semester = (int)$currentTerm['semester'];
$sectionCurriculumEnabled = synk_table_exists($conn, 'tbl_section_curriculum');

$programOptions = [];
$programStmt = $conn->prepare("
    SELECT
        program_id,
        program_code,
        program_name,
        COALESCE(major, '') AS major
    FROM tbl_program
    WHERE college_id = ?
    ORDER BY program_code ASC, program_name ASC, major ASC
");
if ($programStmt instanceof mysqli_stmt) {
    $programStmt->bind_param("i", $college_id);
    $programStmt->execute();
    $programRes = $programStmt->get_result();

    while ($programRes && ($row = $programRes->fetch_assoc())) {
        $programOptions[] = [
            'program_id' => (int)($row['program_id'] ?? 0),
            'program_code' => (string)($row['program_code'] ?? ''),
            'program_name' => (string)($row['program_name'] ?? ''),
            'major' => (string)($row['major'] ?? '')
        ];
    }

    $programStmt->close();
}

$prospectusVersionsByProgram = [];
foreach ($programOptions as $programOption) {
    $prospectusVersionsByProgram[(string)$programOption['program_id']] = [];
}

if ($sectionCurriculumEnabled) {
    $versionStmt = $conn->prepare("
        SELECT
            h.prospectus_id,
            h.program_id,
            COALESCE(h.cmo_no, '') AS cmo_no,
            COALESCE(h.effective_sy, '') AS effective_sy
        FROM tbl_prospectus_header h
        INNER JOIN tbl_program p
            ON p.program_id = h.program_id
        WHERE p.college_id = ?
        ORDER BY h.program_id ASC, h.effective_sy DESC, h.prospectus_id DESC
    ");

    if ($versionStmt instanceof mysqli_stmt) {
        $versionStmt->bind_param("i", $college_id);
        $versionStmt->execute();
        $versionRes = $versionStmt->get_result();

        while ($versionRes && ($row = $versionRes->fetch_assoc())) {
            $programKey = (string)((int)($row['program_id'] ?? 0));
            if (!array_key_exists($programKey, $prospectusVersionsByProgram)) {
                continue;
            }

            $effectiveSy = trim((string)($row['effective_sy'] ?? ''));
            $cmoNo = trim((string)($row['cmo_no'] ?? ''));
            $label = $effectiveSy !== '' && $cmoNo !== ''
                ? ('SY ' . $effectiveSy . ' - ' . $cmoNo)
                : ($effectiveSy !== '' ? ('SY ' . $effectiveSy) : ($cmoNo !== '' ? $cmoNo : ('Prospectus #' . (int)$row['prospectus_id'])));

            $prospectusVersionsByProgram[$programKey][] = [
                'prospectus_id' => (int)($row['prospectus_id'] ?? 0),
                'label' => $label,
                'effective_sy' => (string)($row['effective_sy'] ?? ''),
                'cmo_no' => (string)($row['cmo_no'] ?? '')
            ];
        }

        $versionStmt->close();
    }
}

$programOptionsJson = json_encode($programOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$prospectusVersionsJson = json_encode($prospectusVersionsByProgram, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed">
<head>
    <meta charset="utf-8" />
    <title>Section Generator | Synk Scheduler</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet" />

    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" type="text/css" href="custom_css.css">

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
        body {
            background:
                radial-gradient(circle at top left, rgba(105, 108, 255, 0.08), transparent 28%),
                linear-gradient(180deg, #f5f7fc 0%, #f8fbff 52%, #eff4fb 100%);
        }

        #previewCard { display:none; }
        #groupedSections {
            display:none;
            margin-top: 1.25rem;
        }

        .sections-page-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        .sections-page-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            margin-bottom: .55rem;
            color: #5d6ad6;
            font-size: .76rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
        }

        .sections-page-title {
            margin: 0;
            color: #223053;
            font-size: 1.9rem;
            font-weight: 800;
            letter-spacing: -.03em;
        }

        .sections-page-title small {
            color: #6c7d95 !important;
            font-size: 1rem;
            font-weight: 600;
        }

        .sections-page-copy {
            margin: .45rem 0 0;
            max-width: 700px;
            color: #6c7d95;
            font-size: .95rem;
            line-height: 1.6;
        }

        .sections-page-badge {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            padding: .7rem .95rem;
            border: 1px solid #dbe4f2;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.76);
            color: #52667f;
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            white-space: nowrap;
            box-shadow: 0 12px 26px rgba(67, 89, 113, 0.08);
        }

        .sections-page-badge i {
            font-size: 1rem;
            color: #696cff;
        }

        .sections-panel {
            border: 1px solid #e1e8f2;
            border-radius: 1.1rem;
            background: rgba(255, 255, 255, 0.94);
            box-shadow: 0 14px 32px rgba(67, 89, 113, 0.08);
            overflow: hidden;
        }

        .sections-panel .card-body {
            padding: 1.45rem 1.5rem 1.5rem;
        }

        .panel-heading {
            display: flex;
            align-items: flex-start;
            gap: .95rem;
            margin-bottom: 1.2rem;
        }

        .panel-heading-icon {
            width: 46px;
            height: 46px;
            border-radius: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            flex: 0 0 46px;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.4);
        }

        .panel-title {
            margin: 0;
            color: #223053;
            font-size: 1.14rem;
            font-weight: 800;
        }

        .panel-subtitle {
            margin: .3rem 0 0;
            color: #6c7d95;
            font-size: .9rem;
            line-height: 1.55;
        }

        .generator-heading {
            justify-content: space-between;
            gap: 1rem;
        }

        .generator-meta {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .55rem .8rem;
            border-radius: 999px;
            background: #f2f5ff;
            color: #5867d8;
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .generator-meta i {
            font-size: .95rem;
        }

        .sections-panel .form-label {
            color: #5c6d84;
            font-size: .74rem;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .sections-panel .form-control,
        .sections-panel .form-select {
            min-height: 3rem;
            border-color: #d7e0ec;
            border-radius: .9rem;
            background-color: #fbfcff;
            box-shadow: none;
        }

        .sections-panel .form-control:focus,
        .sections-panel .form-select:focus {
            border-color: #8ca0ff;
            background-color: #ffffff;
            box-shadow: 0 0 0 .2rem rgba(105, 108, 255, 0.14);
        }

        .generator-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-top: 1.2rem;
        }

        .generator-hint {
            color: #6c7d95;
            font-size: .85rem;
            line-height: 1.5;
        }

        .generator-submit {
            min-height: 3rem;
            padding-inline: 1.15rem;
            border-radius: .9rem;
            font-weight: 700;
            box-shadow: 0 12px 22px rgba(105, 108, 255, 0.24);
            transition: transform .18s ease, box-shadow .18s ease;
        }

        .generator-submit:hover,
        .generator-submit:focus {
            transform: translateY(-1px);
            box-shadow: 0 16px 28px rgba(105, 108, 255, 0.28);
        }

        .program-cards-grid {
            display: grid;
            gap: 1.35rem;
        }

        .program-card {
            border: 1px solid #dde6f2;
            border-radius: 1.2rem;
            background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
            box-shadow: 0 18px 36px rgba(67, 89, 113, 0.08);
            overflow: hidden;
        }

        .program-card-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            padding: 1.3rem 1.35rem 1.15rem;
            border-bottom: 1px solid #ebf0f7;
            background: linear-gradient(135deg, #f8faff 0%, #eef4ff 100%);
        }

        .program-card-header-actions {
            display: flex;
            align-items: flex-start;
            gap: .8rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .program-curriculum-btn {
            min-height: 2.2rem;
            padding: .45rem .95rem;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .program-card-identity {
            display: flex;
            align-items: flex-start;
            gap: .95rem;
            min-width: 0;
        }

        .program-card-code {
            min-width: 3.5rem;
            height: 3.5rem;
            padding: 0 .75rem;
            border-radius: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #696cff 0%, #8475ff 100%);
            color: #ffffff;
            font-size: .95rem;
            font-weight: 800;
            letter-spacing: .03em;
            box-shadow: 0 14px 22px rgba(105, 108, 255, 0.24);
        }

        .program-card-title {
            margin: 0;
            color: #223053;
            font-size: 1.16rem;
            font-weight: 800;
            line-height: 1.28;
        }

        .program-card-subtitle {
            margin-top: .32rem;
            color: #6c7d95;
            font-size: .85rem;
            line-height: 1.5;
        }

        .program-card-metric {
            min-width: 8.3rem;
            padding: .7rem .9rem;
            border: 1px solid #dce5f4;
            border-radius: .95rem;
            background: rgba(255, 255, 255, 0.88);
            text-align: right;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.7);
        }

        .program-card-metric-label {
            display: block;
            color: #7a8aa2;
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .program-card-metric-value {
            display: block;
            margin-top: .15rem;
            color: #5667d6;
            font-size: 1.4rem;
            font-weight: 800;
            letter-spacing: -.03em;
        }

        .program-card-body {
            padding: 1.2rem 1.35rem 1.35rem;
        }

        .year-stack {
            display: grid;
            gap: 1rem;
        }

        .year-level-card {
            border: 1px solid #dbe4f2;
            border-radius: 1rem;
            background: #ffffff;
            box-shadow: 0 10px 20px rgba(67, 89, 113, 0.05);
            overflow: hidden;
        }

        .year-level-card .year-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: .95rem 1rem;
            border-bottom: 1px solid #e8eef7;
            background: linear-gradient(90deg, #f5f8ff 0%, #edf4ff 100%);
            color: #223053;
        }

        .year-header-actions {
            display: flex;
            align-items: center;
            gap: .65rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .year-curriculum-btn {
            min-height: 2rem;
            padding: .38rem .8rem;
            border-radius: 999px;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
        }

        .year-title-wrap {
            min-width: 0;
        }

        .year-title-kicker {
            display: block;
            color: #7b8ba4;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
        }

        .year-title-text {
            display: block;
            margin-top: .15rem;
            color: #223053;
            font-size: 1rem;
            font-weight: 800;
        }

        .year-count {
            display: inline-flex;
            align-items: center;
            padding: .4rem .7rem;
            border: 1px solid #d5dfef;
            border-radius: 999px;
            background: #ffffff;
            color: #5d7086;
            font-size: .74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            white-space: nowrap;
        }

        .year-sections-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: .9rem;
            padding: 1rem;
        }

        .section-tile {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: .9rem;
            min-height: 7rem;
            padding: .95rem;
            border: 1px solid #e2e8f2;
            border-radius: 1rem;
            background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
            box-shadow: 0 8px 18px rgba(67, 89, 113, 0.06);
        }

        .section-tile-main {
            display: flex;
            align-items: flex-start;
            gap: .75rem;
            min-width: 0;
        }

        .section-avatar {
            width: 2.8rem;
            height: 2.8rem;
            border-radius: .95rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 2.8rem;
            background: linear-gradient(135deg, #eef3ff 0%, #e2eaff 100%);
            color: #475ad8;
            font-size: .95rem;
            font-weight: 800;
            letter-spacing: .03em;
        }

        .section-code {
            color: #223053;
            font-size: 1rem;
            font-weight: 800;
            line-height: 1.2;
        }

        .section-fullcode {
            margin-top: .25rem;
            color: #6c7d95;
            font-size: .82rem;
            line-height: 1.45;
            word-break: break-word;
        }

        .section-meta {
            margin-top: .38rem;
            color: #8a97aa;
            font-size: .73rem;
            font-weight: 600;
            letter-spacing: .02em;
        }

        .section-tile-actions {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: .6rem;
            flex: 0 0 auto;
        }

        .section-status-badge {
            display: inline-flex;
            align-items: center;
            padding: .34rem .62rem;
            border-radius: 999px;
            font-size: .68rem;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .section-status-active {
            background: #e8f7ef;
            color: #1f8d4b;
        }

        .section-status-inactive {
            background: #eef2f7;
            color: #6a7a90;
        }

        .section-delete-btn {
            width: 2.25rem;
            height: 2.25rem;
            border: 0;
            border-radius: .8rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #fff2ef;
            color: #ff5a36;
            box-shadow: inset 0 0 0 1px #ffd7ce;
            transition: transform .18s ease, background-color .18s ease, box-shadow .18s ease;
        }

        .section-delete-btn:hover,
        .section-delete-btn:focus {
            transform: translateY(-1px);
            background: #ffe7e1;
            box-shadow: inset 0 0 0 1px #ffcabd, 0 8px 16px rgba(255, 90, 54, 0.14);
        }

        .sections-state {
            padding: 2.2rem 1.4rem;
            border: 1px dashed #d4deec;
            border-radius: 1.1rem;
            background: rgba(255, 255, 255, 0.86);
            text-align: center;
            box-shadow: 0 12px 24px rgba(67, 89, 113, 0.05);
        }

        .sections-state-icon {
            width: 3.2rem;
            height: 3.2rem;
            margin: 0 auto .9rem;
            border-radius: 1rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #eef3ff;
            color: #5f70df;
            font-size: 1.35rem;
        }

        .sections-state-title {
            color: #223053;
            font-size: 1.05rem;
            font-weight: 800;
        }

        .sections-state-text {
            margin-top: .4rem;
            color: #6c7d95;
            font-size: .9rem;
            line-height: 1.55;
        }

        .sections-preview-list {
            max-height: 320px;
            overflow: auto;
            display: grid;
            gap: .75rem;
            text-align: left;
            padding-right: .15rem;
        }

        .sections-preview-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: .8rem;
            padding: .85rem .95rem;
            border: 1px solid #e0e8f3;
            border-radius: .95rem;
            background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%);
        }

        .sections-preview-name {
            color: #223053;
            font-size: .95rem;
            font-weight: 700;
            line-height: 1.35;
        }

        .sections-preview-sub {
            margin-top: .18rem;
            color: #7788a1;
            font-size: .76rem;
            font-weight: 600;
            letter-spacing: .03em;
            text-transform: uppercase;
        }

        .sections-preview-chip {
            display: inline-flex;
            align-items: center;
            padding: .42rem .68rem;
            border-radius: 999px;
            background: #eef3ff;
            color: #4f61d8;
            font-size: .78rem;
            font-weight: 800;
            letter-spacing: .04em;
        }

        @media (max-width: 991.98px) {
            .sections-page-header,
            .generator-heading,
            .generator-actions,
            .program-card-header {
                flex-direction: column;
                align-items: stretch;
            }

            .sections-page-title {
                font-size: 1.65rem;
            }

            .sections-page-badge,
            .generator-meta,
            .program-card-metric {
                align-self: flex-start;
            }
        }

        @media (max-width: 767.98px) {
            .sections-panel .card-body,
            .program-card-header,
            .program-card-body {
                padding: 1.1rem;
            }

            .year-level-card .year-header,
            .section-tile {
                padding: .9rem;
            }

            .year-level-card .year-header,
            .section-tile,
            .section-tile-main {
                flex-direction: column;
                align-items: flex-start;
            }

            .section-tile-actions {
                flex-direction: row;
                align-items: center;
            }

            .year-sections-grid {
                grid-template-columns: 1fr;
                padding: .9rem;
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

    <div class="sections-page-header">
        <div>
            <div class="sections-page-eyebrow">
                <i class="bx bx-grid-alt"></i>
                Scheduler Workspace
            </div>
            <h4 class="sections-page-title">
                Section Generator
                <small>(<?= htmlspecialchars($college_name) ?>)</small>
            </h4>
            <p class="sections-page-copy">
                Build section sets by academic context, review them per program, and manage year-level groupings from a cleaner card-based workspace.
            </p>
        </div>
        <div class="sections-page-badge">
            <i class="bx bx-layout"></i>
            Program Section Cards
        </div>
    </div>

    <!-- ===============================
         AY + SEMESTER FILTER (REQUIRED)
         Manual load (Q3 = B)
    ================================ -->
    <div class="card sections-panel mb-4">
        <div class="card-body">
            <div class="panel-heading">
                <div class="panel-heading-icon bg-label-primary">
                    <i class="bx bx-calendar-event"></i>
                </div>
                <div>
                    <h5 class="panel-title">Academic Context</h5>
                    <p class="panel-subtitle">Select Academic Year and Semester before loading or generating sections.</p>
                </div>
            </div>
            <div class="row g-3 align-items-end">

                <div class="col-md-6">
                    <label class="form-label">Academic Year</label>
                    <select id="ay_id" class="form-select">
                        <option value="">Select...</option>
                        <?php
                            $ayQ = $conn->query("SELECT ay_id, ay FROM tbl_academic_years ORDER BY ay DESC");
                            while ($ay = $ayQ->fetch_assoc()) {
                                $selected = ((int)$ay['ay_id'] === $default_ay_id) ? " selected" : "";
                                echo "<option value='".(int)$ay['ay_id']."'{$selected}>".htmlspecialchars($ay['ay'])."</option>";
                            }
                        ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Semester</label>
                    <select id="semester" class="form-select">
                        <option value="">Select...</option>
                        <option value="1" <?= $default_semester === 1 ? 'selected' : '' ?>>First Semester</option>
                        <option value="2" <?= $default_semester === 2 ? 'selected' : '' ?>>Second Semester</option>
                        <option value="3" <?= $default_semester === 3 ? 'selected' : '' ?>>Midyear</option>
                    </select>
                </div>

            </div>
        </div>
    </div>

    <!-- ===============================
         GENERATOR FORM
    ================================ -->
    <div class="card sections-panel mb-4">
        <div class="card-body">
            <div class="panel-heading generator-heading">
                <div class="d-flex align-items-start gap-3">
                    <div class="panel-heading-icon bg-label-info">
                        <i class="bx bx-layer-plus"></i>
                    </div>
                    <div>
                        <h5 class="panel-title">Create Sections</h5>
                        <p class="panel-subtitle">Generate sections for a program and year level within the selected academic scope.</p>
                    </div>
                </div>
                <div class="generator-meta">
                    <i class="bx bx-target-lock"></i>
                    Scoped by AY + Semester
                </div>
            </div>

            <div class="row g-3">

                <div class="col-md-4">
                    <label class="form-label">Program</label>
                    <select id="program_id" class="form-select">
                        <option value="">Select Program</option>
                        <?php
                            $q = mysqli_query($conn, "
                                SELECT program_id, program_code, program_name, COALESCE(major, '') AS major
                                FROM tbl_program
                                WHERE college_id='$college_id'
                                ORDER BY program_code ASC, program_name ASC, major ASC
                            ");
                            while ($r = mysqli_fetch_assoc($q)) {
                                $major = trim((string)($r['major'] ?? ''));
                                if ($major !== '') {
                                    $baseProgramName = trim((string)($r['program_name'] ?? ''));
                                    $r['program_name'] = $baseProgramName !== ''
                                        ? $baseProgramName . ' (Major in ' . $major . ')'
                                        : 'Major in ' . $major;
                                }
                                echo "<option value='".(int)$r['program_id']."' data-code='".htmlspecialchars($r['program_code'], ENT_QUOTES)."'>
                                        ".htmlspecialchars($r['program_code'])." — ".htmlspecialchars($r['program_name'])."
                                      </option>";
                            }
                        ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Year Level</label>
                    <select id="year_level" class="form-select">
                        <option value="">Select Year Level</option>
                        <option value="1">1st Year</option>
                        <option value="2">2nd Year</option>
                        <option value="3">3rd Year</option>
                        <option value="4">4th Year</option>
                        <option value="5">5th Year</option>
                        <option value="6">6th Year</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Number of Sections</label>
                    <input type="number" id="section_count" class="form-control" value="1" min="1">
                </div>

                <div class="col-md-12">
                    <?php if ($sectionCurriculumEnabled): ?>
                        <label class="form-label">Assigned Curriculum</label>
                        <select id="prospectus_id" class="form-select" disabled>
                            <option value="">Select Program First</option>
                        </select>
                        <div class="generator-hint mt-2" id="curriculumHint">
                            First-year sections can start on the newest prospectus automatically. Higher years should be reviewed before saving.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-warning mb-0">
                            Section curriculum assignment is not available yet on this database. Create <code>tbl_section_curriculum</code> first before generating mixed-curriculum sections.
                        </div>
                    <?php endif; ?>
                </div>

            </div>

            <div class="generator-actions">
                <div class="generator-hint">
                    Preview new sections first, then confirm the save when the generated list looks correct.
                </div>
                <button class="btn btn-primary generator-submit" id="btnGenerate" type="button">
                    <i class="bx bx-cog me-1"></i> Generate Preview
                </button>
            </div>

        </div>
    </div>

    <!-- PREVIEW CARD -->
    <div class="card" id="previewCard">
        <div class="card-header d-flex justify-content-between">
            <h5 class="m-0">Generated Sections Preview</h5>
            <button class="btn btn-success btn-sm" id="btnSaveSections">
                <i class="bx bx-save"></i> Save Sections
            </button>
        </div>
        <ul class="list-group list-group-flush" id="previewList"></ul>
    </div>

    <!-- GROUPED SECTION LIST -->
    <div id="groupedSections"></div>

    <div class="modal fade" id="sectionCurriculumModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="sectionCurriculumModalTitle">Assign Curriculum</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="sectionCurriculumMode" value="single">
                    <input type="hidden" id="sectionCurriculumSectionId">
                    <input type="hidden" id="sectionCurriculumProgramId">
                    <input type="hidden" id="sectionCurriculumYearLevel">
                    <input type="hidden" id="sectionCurriculumAyId">
                    <input type="hidden" id="sectionCurriculumSemester">
                    <div class="mb-3">
                        <label class="form-label" id="sectionCurriculumTargetLabel">Section</label>
                        <div class="fw-semibold text-dark" id="sectionCurriculumSectionLabel">-</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="sectionCurriculumProspectus">Curriculum</label>
                        <select id="sectionCurriculumProspectus" class="form-select">
                            <option value="">Select Curriculum</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="btnSaveSectionCurriculum">Save Curriculum</button>
                </div>
            </div>
        </div>
    </div>

</div>

<?php include '../footer.php'; ?>

</div>
</div>
</div>

<!-- JS LIBS -->
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
const SECTION_CURRICULUM_ENABLED = <?= $sectionCurriculumEnabled ? 'true' : 'false' ?>;
const PROSPECTUS_VERSIONS_BY_PROGRAM = <?= $prospectusVersionsJson ?: '{}' ?>;
let sectionStartIndex = 0;
let sectionCurriculumModalInstance = null;

function saveSectionsRequest(ctx, program_id, program_code, year, count) {
    const payload = {
        save_sections: 1,
        program_id: program_id,
        program_code: program_code,
        ay_id: ctx.ay_id,
        semester: ctx.semester,
        year_level: year,
        count: count,
        start_index: sectionStartIndex
    };

    if (SECTION_CURRICULUM_ENABLED) {
        payload.prospectus_id = $("#prospectus_id").val();
    }

    $.post("../backend/query_sections.php",
    payload,
    function(response){

        if (response.trim() === "success") {
            Swal.fire("Saved!", "Sections created successfully.", "success");
            $("#previewCard").hide();
            loadGroupedSections(); // refresh with same AY+Sem
        } else {
            Swal.fire("Error", response, "error");
        }
    });
}

/* =========================
   Helper: validate AY + Sem
========================= */
function getContextOrWarn() {
    const ay  = $("#ay_id").val();
    const sem = $("#semester").val();

    if (!ay || !sem) {
        Swal.fire("Missing Filters", "Please select Academic Year and Semester first.", "warning");
        return null;
    }
    return { ay_id: ay, semester: sem };
}

function yearLevelLabel(year) {
    const y = String(year);
    if (y === "1") return "1st Year";
    if (y === "2") return "2nd Year";
    if (y === "3") return "3rd Year";
    if (y === "4") return "4th Year";
    if (y === "5") return "5th Year";
    if (y === "6") return "6th Year";
    return `Year ${y}`;
}

function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, function(char) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        };
        return map[char];
    });
}

function parseProgramLabel(program) {
    const text = String(program || "").trim();
    const parts = text.split(/\s+[—-]\s+/);

    if (parts.length > 1) {
        const code = parts.shift().trim();
        return {
            code: code,
            title: parts.join(" - ").trim() || code
        };
    }

    return {
        code: text || "Program",
        title: text || "Program"
    };
}

function normalizeProgramText(value) {
    return String(value || "")
        .replace(/\s+[^\x20-\x7E]+\s+/g, " - ")
        .replace(/\s{2,}/g, " ")
        .trim();
}

parseProgramLabel = function(program) {
    const text = normalizeProgramText(program);
    const parts = text.split(/\s+-\s+/);

    if (parts.length > 1) {
        const code = parts.shift().trim();
        return {
            code: code,
            title: parts.join(" - ").trim() || code
        };
    }

    return {
        code: text || "Program",
        title: text || "Program"
    };
};

$("#program_id option").each(function () {
    $(this).text(normalizeProgramText($(this).text()));
});

function getProgramProspectusOptions(programId) {
    return PROSPECTUS_VERSIONS_BY_PROGRAM[String(programId || "")] || [];
}

function buildProspectusOptionsHtml(options, selectedId, placeholder) {
    let html = `<option value="">${escapeHtml(placeholder || "Select Curriculum")}</option>`;

    (Array.isArray(options) ? options : []).forEach(function (option) {
        const optionId = String(option.prospectus_id || "");
        const selected = optionId !== "" && optionId === String(selectedId || "") ? " selected" : "";
        html += `<option value="${escapeHtml(optionId)}"${selected}>${escapeHtml(option.label || "")}</option>`;
    });

    return html;
}

function syncCurriculumSelect() {
    if (!SECTION_CURRICULUM_ENABLED) {
        return;
    }

    const programId = $("#program_id").val();
    const yearLevel = $("#year_level").val();
    const select = $("#prospectus_id");
    const hint = $("#curriculumHint");

    if (!programId) {
        select.prop("disabled", true).html('<option value="">Select Program First</option>');
        hint.text("Select a program first, then choose the curriculum that owns these sections.");
        return;
    }

    const options = getProgramProspectusOptions(programId);
    const previousValue = select.val();

    if (!options.length) {
        select.prop("disabled", true).html('<option value="">No Curriculum Found</option>');
        hint.text("No prospectus version is available for the selected program yet.");
        return;
    }

    let nextValue = previousValue;
    const hasPrevious = options.some(function (option) {
        return String(option.prospectus_id) === String(previousValue || "");
    });

    if (!hasPrevious) {
        nextValue = yearLevel === "1" ? String(options[0].prospectus_id || "") : "";
    }

    select.prop("disabled", false).html(buildProspectusOptionsHtml(options, nextValue, "Select Curriculum"));

    if (yearLevel === "1") {
        hint.text("Newest prospectus is preselected for first-year sections. Change it only if this cohort should stay on an older curriculum.");
    } else {
        hint.text("Review the curriculum carefully for upper-year sections before saving.");
    }
}

function getSelectedProspectusLabel() {
    if (!SECTION_CURRICULUM_ENABLED) {
        return "";
    }

    const select = document.getElementById("prospectus_id");
    if (!select || select.selectedIndex < 0) {
        return "";
    }

    return (select.options[select.selectedIndex] || {}).text || "";
}

function resolveBulkSelectedProspectusId(rows) {
    const values = Array.from(new Set((Array.isArray(rows) ? rows : [])
        .map(function (row) {
            return Number(row && row.prospectus_id ? row.prospectus_id : 0);
        })
        .filter(function (value) {
            return value > 0;
        })));

    return values.length === 1 ? values[0] : 0;
}

function openSectionCurriculumModal(config) {
    if (!SECTION_CURRICULUM_ENABLED) {
        Swal.fire("Unavailable", "Section curriculum assignment is not enabled on this database yet.", "info");
        return;
    }

    const mode = String((config && config.mode) || "single");
    const programId = Number((config && config.programId) || 0);
    const options = getProgramProspectusOptions(programId);
    if (!options.length) {
        Swal.fire("Missing Curriculum", "No prospectus version is available for this program yet.", "warning");
        return;
    }

    $("#sectionCurriculumMode").val(mode);
    $("#sectionCurriculumSectionId").val(Number((config && config.sectionId) || 0));
    $("#sectionCurriculumProgramId").val(programId);
    $("#sectionCurriculumYearLevel").val(Number((config && config.yearLevel) || 0));
    $("#sectionCurriculumAyId").val(Number((config && config.ayId) || 0));
    $("#sectionCurriculumSemester").val(Number((config && config.semester) || 0));
    const title = mode === "program"
        ? "Assign Program Curriculum"
        : (mode === "bulk" ? "Assign Year-Level Curriculum" : "Assign Curriculum");
    const targetLabel = mode === "program"
        ? "Program Scope"
        : (mode === "bulk" ? "Year Level Scope" : "Section");

    $("#sectionCurriculumModalTitle").text(title);
    $("#sectionCurriculumTargetLabel").text(targetLabel);
    $("#sectionCurriculumSectionLabel").text((config && config.label) || "-");
    $("#sectionCurriculumProspectus").html(
        buildProspectusOptionsHtml(options, Number((config && config.selectedProspectusId) || 0), "Select Curriculum")
    );

    const modalEl = document.getElementById("sectionCurriculumModal");
    sectionCurriculumModalInstance = sectionCurriculumModalInstance || new bootstrap.Modal(modalEl);
    sectionCurriculumModalInstance.show();
}

/* =========================
   DELETE SECTION
========================= */
$(document).on("click", ".btnDeleteSection", function () {

    const ctx = getContextOrWarn();
    if (!ctx) return;

    let id = $(this).data("id");

    Swal.fire({
        icon: "warning",
        title: "Delete Section?",
        text: "This section will be permanently removed.",
        showCancelButton: true,
        confirmButtonColor: "#d33",
        confirmButtonText: "Delete",
        cancelButtonText: "Cancel"
    }).then(result => {
        if (result.isConfirmed) {

            $.post("../backend/query_sections.php",
            { delete_section: 1, section_id: id },
            function(res){

                if (res.trim() === "success") {
                    Swal.fire("Deleted!", "Section removed successfully.", "success");
                    loadGroupedSections(); // refresh UI
                }
                else if (res.trim() === "forbidden") {
                    Swal.fire("Access Denied", "You cannot delete this section.", "error");
                }
                else {
                    Swal.fire("Error", res, "error");
                }

            });

        }
    });

});

$(document).on("click", ".btnSectionCurriculum", function () {
    openSectionCurriculumModal(
        {
            mode: "single",
            sectionId: Number($(this).data("id") || 0),
            programId: Number($(this).data("programId") || 0),
            label: $(this).data("sectionLabel") || "",
            selectedProspectusId: Number($(this).data("prospectusId") || 0)
        }
    );
});

$(document).on("click", ".btnYearCurriculum", function () {
    const ctx = getContextOrWarn();
    if (!ctx) return;

    openSectionCurriculumModal({
        mode: "bulk",
        programId: Number($(this).data("programId") || 0),
        yearLevel: Number($(this).data("yearLevel") || 0),
        ayId: Number(ctx.ay_id || 0),
        semester: Number(ctx.semester || 0),
        label: $(this).data("scopeLabel") || "",
        selectedProspectusId: Number($(this).data("prospectusId") || 0)
    });
});

$(document).on("click", ".btnProgramCurriculum", function () {
    const ctx = getContextOrWarn();
    if (!ctx) return;

    openSectionCurriculumModal({
        mode: "program",
        programId: Number($(this).data("programId") || 0),
        ayId: Number(ctx.ay_id || 0),
        semester: Number(ctx.semester || 0),
        label: $(this).data("scopeLabel") || "",
        selectedProspectusId: Number($(this).data("prospectusId") || 0)
    });
});

$("#btnSaveSectionCurriculum").on("click", function () {
    const mode = String($("#sectionCurriculumMode").val() || "single");
    const sectionId = Number($("#sectionCurriculumSectionId").val() || 0);
    const programId = Number($("#sectionCurriculumProgramId").val() || 0);
    const yearLevel = Number($("#sectionCurriculumYearLevel").val() || 0);
    const ayId = Number($("#sectionCurriculumAyId").val() || 0);
    const semester = Number($("#sectionCurriculumSemester").val() || 0);
    const prospectusId = Number($("#sectionCurriculumProspectus").val() || 0);

    if (!prospectusId) {
        Swal.fire("Missing Data", "Select a curriculum before saving.", "warning");
        return;
    }

    let payload = null;
    let successMessage = "Section curriculum updated successfully.";

    if (mode === "bulk") {
        if (!programId || !yearLevel || !ayId || !semester) {
            Swal.fire("Missing Data", "The selected year-level scope is incomplete.", "warning");
            return;
        }

        payload = {
            update_year_level_prospectus: 1,
            program_id: programId,
            year_level: yearLevel,
            ay_id: ayId,
            semester: semester,
            prospectus_id: prospectusId
        };
        successMessage = "Year-level curriculum updated successfully.";
    } else if (mode === "program") {
        if (!programId || !ayId || !semester) {
            Swal.fire("Missing Data", "The selected program scope is incomplete.", "warning");
            return;
        }

        payload = {
            update_program_prospectus: 1,
            program_id: programId,
            ay_id: ayId,
            semester: semester,
            prospectus_id: prospectusId
        };
        successMessage = "Program curriculum updated successfully.";
    } else {
        if (!sectionId) {
            Swal.fire("Missing Data", "Select a section before saving.", "warning");
            return;
        }

        payload = {
            update_section_prospectus: 1,
            section_id: sectionId,
            prospectus_id: prospectusId
        };
    }

    $.post("../backend/query_sections.php", payload, function (response) {
        const trimmed = String(response || "").trim();

        if (trimmed === "success") {
            if (sectionCurriculumModalInstance) {
                sectionCurriculumModalInstance.hide();
            }
            Swal.fire("Saved!", successMessage, "success");
            loadGroupedSections();
            return;
        }

        if (trimmed === "forbidden") {
            Swal.fire("Access Denied", "You cannot update this section.", "error");
            return;
        }

        Swal.fire("Error", trimmed || "Unable to update this section right now.", "error");
    });
});

/* =========================
   Load grouped sections into UI (filtered by AY + Sem)
========================= */
function loadGroupedSections() {

    const ctx = getContextOrWarn();
    if (!ctx) return;

    $("#groupedSections").show().html(`
        <div class="sections-state">
            <div class="sections-state-icon">
                <div class="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true"></div>
            </div>
            <div class="sections-state-title">Loading section cards</div>
            <div class="sections-state-text">Preparing the selected program and year-level groups.</div>
        </div>
    `);

    $.post("../backend/query_sections.php",
        {
            load_grouped_sections: 1,
            ay_id: ctx.ay_id,
            semester: ctx.semester
        },
        function(res) {

            let data;
            try {
                data = JSON.parse(res);
            } catch(e) {
                $("#groupedSections").html(`<div class="alert alert-danger">Invalid response: ${res}</div>`);
                return;
            }

            let html = "";

            const programs = Object.keys(data);
            if (programs.length === 0) {
                $("#groupedSections").html(`
                    <div class="sections-state">
                        <div class="sections-state-icon">
                            <i class="bx bx-folder-open"></i>
                        </div>
                        <div class="sections-state-title">No sections found</div>
                        <div class="sections-state-text">
                            There are no saved sections for the selected Academic Year and Semester yet.
                        </div>
                    </div>
                `);
                return;
            }

            html += `<div class="program-cards-grid">`;

            for (let program in data) {
                const programMeta = parseProgramLabel(program);
                const programSubtitle = programMeta.title === programMeta.code
                    ? "Program sections grouped by year level."
                    : `${programMeta.code} program sections grouped by year level.`;
                const programRows = data[program];
                const programId = programRows.length ? Number(programRows[0].program_id || 0) : 0;
                const programScopeLabel = `${programMeta.code} - all sections in this term`;
                const programSelectedProspectusId = resolveBulkSelectedProspectusId(programRows);
                const byYear = {};
                programRows.forEach((s) => {
                    const y = String(s.year_level || "");
                    if (!byYear[y]) byYear[y] = [];
                    byYear[y].push(s);
                });
                const yearKeys = Object.keys(byYear).sort((a, b) => Number(a) - Number(b));

                html += `
                    <div class="program-card">
                        <div class="program-card-header">
                            <div class="program-card-identity">
                                <div class="program-card-code">${escapeHtml(programMeta.code)}</div>
                                <div class="min-w-0">
                                    <h5 class="program-card-title">${escapeHtml(programMeta.title)}</h5>
                                    <div class="program-card-subtitle">
                                        ${escapeHtml(programSubtitle)}
                                    </div>
                                </div>
                            </div>
                            <div class="program-card-header-actions">
                                ${SECTION_CURRICULUM_ENABLED ? `
                                    <button
                                        class="btn btn-sm btn-outline-primary program-curriculum-btn btnProgramCurriculum"
                                        data-program-id="${programId}"
                                        data-scope-label="${escapeHtml(programScopeLabel)}"
                                        data-prospectus-id="${programSelectedProspectusId}"
                                        type="button"
                                    >
                                        Set Program Curriculum
                                    </button>
                                ` : ''}
                                <div class="program-card-metric">
                                    <span class="program-card-metric-label">Total Sections</span>
                                    <span class="program-card-metric-value">${programRows.length}</span>
                                </div>
                            </div>
                        </div>
                        <div class="program-card-body">
                            <div class="year-stack">
                `;

                yearKeys.forEach((year) => {
                    const rows = byYear[year];
                    const sectionCountLabel = `${rows.length} ${rows.length === 1 ? 'section' : 'sections'}`;
                    const bulkScopeLabel = `${programMeta.code} ${yearLevelLabel(year)} - ${sectionCountLabel}`;
                    const bulkSelectedProspectusId = resolveBulkSelectedProspectusId(rows);
                    html += `
                        <div class="year-level-card">
                            <div class="year-header">
                                <div class="year-title-wrap">
                                    <span class="year-title-kicker">Year Level</span>
                                    <span class="year-title-text">${yearLevelLabel(year)}</span>
                                </div>
                                <div class="year-header-actions">
                                    ${SECTION_CURRICULUM_ENABLED ? `
                                        <button
                                            class="btn btn-sm btn-outline-primary year-curriculum-btn btnYearCurriculum"
                                            data-program-id="${Number(rows[0].program_id || 0)}"
                                            data-year-level="${Number(year || 0)}"
                                            data-scope-label="${escapeHtml(bulkScopeLabel)}"
                                            data-prospectus-id="${bulkSelectedProspectusId}"
                                            type="button"
                                        >
                                            Set Curriculum
                                        </button>
                                    ` : ''}
                                    <span class="year-count">${sectionCountLabel}</span>
                                </div>
                            </div>
                            <div class="year-sections-grid">
                    `;

                    rows.forEach((s) => {
                        const normalizedStatus = String(s.status || 'inactive').toLowerCase();
                        const statusClass = normalizedStatus === 'active' ? 'section-status-active' : 'section-status-inactive';
                        const sectionName = String(s.section_name || '');
                        const shortCode = sectionName.replace(new RegExp(`^${String(year)}`), "") || sectionName;
                        const fullSection = String(s.full_section || sectionName || '');
                        const curriculumLabel = String(s.prospectus_label || '').trim();
                        const hasCurriculum = Boolean(s.has_curriculum);
                        const curriculumMeta = SECTION_CURRICULUM_ENABLED
                            ? (hasCurriculum
                                ? `Curriculum: ${curriculumLabel}`
                                : 'Curriculum not assigned yet')
                            : `Ready for ${yearLevelLabel(year)}`;
                        html += `
                                <div class="section-tile">
                                    <div class="section-tile-main">
                                        <div class="section-avatar">${escapeHtml(shortCode || sectionName)}</div>
                                        <div class="min-w-0">
                                            <div class="section-code">${escapeHtml(sectionName || fullSection)}</div>
                                            <div class="section-fullcode">${escapeHtml(fullSection)}</div>
                                            <div class="section-meta">${escapeHtml(curriculumMeta)}</div>
                                        </div>
                                    </div>
                                    <div class="section-tile-actions">
                                        <span class="section-status-badge ${statusClass}">${escapeHtml(normalizedStatus)}</span>
                                        ${SECTION_CURRICULUM_ENABLED ? `
                                            <button
                                                class="btn btn-sm btn-outline-primary btnSectionCurriculum"
                                                data-id="${Number(s.section_id)}"
                                                data-program-id="${Number(s.program_id || 0)}"
                                                data-section-label="${escapeHtml(fullSection)}"
                                                data-prospectus-id="${Number(s.prospectus_id || 0)}"
                                                type="button"
                                            >
                                                Curriculum
                                            </button>
                                        ` : ''}
                                        <button class="section-delete-btn btnDeleteSection" data-id="${Number(s.section_id)}" title="Delete section" type="button">
                                            <i class="bx bx-trash"></i>
                                        </button>
                                    </div>
                                </div>
                        `;
                    });

                    html += `
                            </div>
                        </div>
                    `;
                });

                html += `
                            </div>
                        </div>
                    </div>
                `;
            }

            html += `</div>`;

            $("#groupedSections").html(html);

        }
    );
}

/* =========================
   Generate Preview
========================= */
$("#btnGenerate").click(function () {

    const ctx = getContextOrWarn();
    if (!ctx) return;

    let program_id   = $("#program_id").val();
    let program_code = $("#program_id option:selected").data("code");
    let year         = $("#year_level").val();
    let count        = parseInt($("#section_count").val(), 10);

    if (!program_id || !year || !count || count <= 0) {
        Swal.fire("Missing Data", "Please fill Program, Year Level, and Number of Sections.", "warning");
        return;
    }

    const selectedProspectusId = SECTION_CURRICULUM_ENABLED ? $("#prospectus_id").val() : "";
    const selectedProspectusLabel = getSelectedProspectusLabel();
    if (SECTION_CURRICULUM_ENABLED && !selectedProspectusId) {
        Swal.fire("Missing Curriculum", "Select the curriculum that should own these sections before generating the preview.", "warning");
        return;
    }

    $.post("../backend/query_sections.php", {
        get_next_section_index: 1,
        program_id: program_id,
        year_level: year,
        ay_id: ctx.ay_id,
        semester: ctx.semester
    }, function (res) {

        let data;
        try {
            data = JSON.parse(res);
        } catch(e) {
            Swal.fire("Error", "Invalid response: " + res, "error");
            return;
        }

        let startIndex = parseInt(data.next_index || 0, 10);
        sectionStartIndex = startIndex;

        let letters = "ABCDEFGHIJKLMNOPQRSTUVWXYZ".split("");
        let previewRows = [];

        for (let i = 0; i < count; i++) {

            let idx = startIndex + i;

            if (idx >= letters.length) {
                Swal.fire("Limit Reached", "Maximum section limit (A-Z) reached for this scope.", "warning");
                break;
            }

            let letter = letters[idx];
            let section_name = year + letter;                 // e.g., 1A
            let full_section  = program_code + " " + section_name;
            previewRows.push({ section_name, full_section });
        }
        
        if (previewRows.length === 0) {
            Swal.fire("No Preview", "No sections available to preview in this scope.", "info");
            return;
        }

        let previewHtml = `<div class="sections-preview-list">`;
        previewRows.forEach((r) => {
            previewHtml += `
                <div class="sections-preview-item">
                    <div>
                        <div class="sections-preview-name">${escapeHtml(r.full_section)}</div>
                        <div class="sections-preview-sub">${escapeHtml(yearLevelLabel(year))}${SECTION_CURRICULUM_ENABLED ? ` • ${escapeHtml(selectedProspectusLabel)}` : ''}</div>
                    </div>
                    <span class="sections-preview-chip">${escapeHtml(r.section_name)}</span>
                </div>
            `;
        });
        previewHtml += `</div>`;

        Swal.fire({
            title: "Generated Sections Preview",
            html: previewHtml,
            width: 700,
            showCancelButton: true,
            confirmButtonText: "Save Sections",
            cancelButtonText: "Close"
        }).then((result) => {
            if (!result.isConfirmed) return;
            saveSectionsRequest(ctx, program_id, program_code, year, count);
        });

    });

});

/* =========================
   Save Sections
========================= */
$("#btnSaveSections").click(function () {

    const ctx = getContextOrWarn();
    if (!ctx) return;

    let program_id   = $("#program_id").val();
    let program_code = $("#program_id option:selected").data("code");
    let year         = $("#year_level").val();
    let count        = parseInt($("#section_count").val(), 10);

    if (!program_id || !year || !count || count <= 0) {
        Swal.fire("Missing Data", "Please fill Program, Year Level, and Number of Sections.", "warning");
        return;
    }

    if (SECTION_CURRICULUM_ENABLED && !$("#prospectus_id").val()) {
        Swal.fire("Missing Curriculum", "Select the curriculum that should own these sections before saving.", "warning");
        return;
    }

    saveSectionsRequest(ctx, program_id, program_code, year, count);
});

// Auto-load whenever AY/Semester changes
$("#ay_id, #semester").on("change", function () {
    if ($("#ay_id").val() && $("#semester").val()) {
        loadGroupedSections();
    } else {
        $("#groupedSections").show().html(`
            <div class="sections-state">
                <div class="sections-state-icon">
                    <i class="bx bx-filter-alt"></i>
                </div>
                <div class="sections-state-title">Select academic context</div>
                <div class="sections-state-text">Choose Academic Year and Semester to view section cards.</div>
            </div>
        `);
    }
});

if (SECTION_CURRICULUM_ENABLED) {
    $("#program_id, #year_level").on("change", function () {
        syncCurriculumSelect();
    });

    syncCurriculumSelect();
}

// Initial auto-load if defaults are already selected
if ($("#ay_id").val() && $("#semester").val()) {
    loadGroupedSections();
} else {
    $("#groupedSections").show().html(`
        <div class="sections-state">
            <div class="sections-state-icon">
                <i class="bx bx-filter-alt"></i>
            </div>
            <div class="sections-state-title">Select academic context</div>
            <div class="sections-state-text">Choose Academic Year and Semester to view section cards.</div>
        </div>
    `);
}
</script>

</body>
</html>
