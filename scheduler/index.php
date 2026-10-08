<?php
session_start();
ob_start();
include '../backend/db.php';
require_once '../backend/academic_term_helper.php';
require_once '../backend/scheduler_access_helper.php';

synk_scheduler_bootstrap_session_scope($conn);

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

if ($_SESSION['role'] !== 'scheduler') {
    header("Location: ../index.php");
    exit;
}

if (!isset($_SESSION['college_id']) || $_SESSION['college_id'] === null) {
    echo "Scheduler error: missing college assignment.";
    exit;
}

$currentTerm = synk_fetch_current_academic_term($conn);
$academicTermText = $currentTerm['term_text'];

$collegeName = htmlspecialchars($_SESSION['college_name'] ?? 'Assigned College', ENT_QUOTES, 'UTF-8');
$campusName = htmlspecialchars($_SESSION['campus_name'] ?? 'Current Campus', ENT_QUOTES, 'UTF-8');
$schedulerName = htmlspecialchars($_SESSION['username'] ?? 'Scheduler', ENT_QUOTES, 'UTF-8');
$academicTermTextEscaped = htmlspecialchars($academicTermText, ENT_QUOTES, 'UTF-8');
$campusViewEnabled = (int)($_SESSION['campus_id'] ?? 0) > 0;
?>

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

    <title>Scheduler Dashboard</title>

    <meta name="description" content="" />

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
      rel="stylesheet"
    />

    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" class="template-customizer-core-css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/apex-charts/apex-charts.css" />
    <link rel="stylesheet" type="text/css" href="custom_css.css" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
      .hero-card {
        overflow: hidden;
      }

      .kpi-box {
        padding: 10px;
        border-radius: 14px;
        transition: 0.2s ease;
      }

      .kpi-box:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
      }

      .kpi-icon {
        width: 48px;
        height: 48px;
        border-radius: 10px;
        display: flex;
        justify-content: center;
        align-items: center;
        font-size: 22px;
        color: #fff;
        margin: auto;
      }

      .kpi-value {
        font-size: 1.75rem;
        font-weight: 700;
      }

      .metric-note {
        min-height: 18px;
        font-size: 0.82rem;
        color: #8592a3;
      }

      .kpi-warning {
        background: #fff8f3;
        border: 1px solid #ffd6bf;
      }

      .kpi-warning-active {
        background: #fff2f0;
        border-color: #ffccc7;
        box-shadow: 0 8px 20px rgba(255, 77, 79, 0.12);
      }

      .workspace-scroll-area {
        max-height: 700px;
        overflow-y: auto;
        padding-left: 1.5rem;
        padding-right: 1.5rem;
      }

      .workspace-card-link {
        text-decoration: none !important;
      }

      .workspace-card {
        border: 1px solid #e4e4e4;
        border-radius: 10px;
        padding: 14px 18px;
        margin-bottom: 14px;
        background: #fff;
        transition: 0.2s ease-in-out;
      }

      .workspace-card:hover {
        transform: translateX(4px);
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
      }

      .workspace-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: flex;
        justify-content: center;
        align-items: center;
        flex-shrink: 0;
        font-size: 20px;
        color: #fff;
      }

      .status-list .list-group-item {
        padding-left: 0;
        padding-right: 0;
      }

      .status-list .list-group-item:first-child {
        border-top: 0;
      }

      .chart-shell {
        min-height: 340px;
      }

      .chart-shell-compact {
        min-height: 340px;
      }

      .chart-fallback {
        min-height: 340px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #8592a3;
        text-align: center;
      }

      .chart-loader {
        min-height: 340px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #8592a3;
        text-align: center;
      }

      .chart-loader .loader-inline {
        display: inline-flex;
        align-items: center;
        gap: 0.65rem;
        font-weight: 600;
      }

      .hero-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
      }

      .chart-insight {
        min-height: 40px;
        margin-top: 0.75rem;
        font-size: 0.84rem;
        color: #8592a3;
      }

      .heatmap-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-end;
        justify-content: space-between;
        gap: 0.85rem;
      }

      .heatmap-filter-control {
        min-width: 240px;
        max-width: 100%;
      }

      .heatmap-filter-label {
        display: block;
        margin-bottom: 0.35rem;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #8592a3;
      }

      .heatmap-room-filter {
        min-width: 240px;
      }

      .weekly-analysis {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 0.85rem;
        margin-top: 1rem;
        margin-bottom: 0.75rem;
      }

      .weekly-analysis-card {
        border: 1px solid #e5e9f2;
        border-radius: 14px;
        background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        padding: 0.9rem 1rem;
      }

      .weekly-analysis-label {
        display: block;
        margin-bottom: 0.35rem;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #8592a3;
      }

      .weekly-analysis-value {
        margin: 0 0 0.2rem;
        font-size: 1.05rem;
        font-weight: 700;
        color: #233446;
      }

      .weekly-analysis-note {
        margin: 0;
        font-size: 0.8rem;
        line-height: 1.45;
        color: #8592a3;
      }

      .weekly-analysis-empty,
      .weekly-analysis-loading {
        border: 1px dashed #d9e2ef;
        border-radius: 14px;
        background: #fbfcfe;
        padding: 0.95rem 1rem;
        color: #8592a3;
      }

      .weekly-analysis-loading .loader-inline {
        display: inline-flex;
        align-items: center;
        gap: 0.65rem;
        font-weight: 600;
      }

      .dashboard-scope-toggle .btn {
        min-width: 7.5rem;
      }

      .kpi-actionable {
        cursor: pointer;
      }

      .kpi-actionable:focus {
        outline: none;
        box-shadow: 0 0 0 0.2rem rgba(255, 171, 0, 0.18);
      }

      .unscheduled-summary-card {
        border: 1px solid #e5e9f2;
        border-radius: 14px;
        background: linear-gradient(180deg, #ffffff 0%, #fafcff 100%);
        padding: 1rem 1.1rem;
      }

      .unscheduled-summary-metric {
        min-width: 8rem;
      }

      .unscheduled-list-shell {
        border: 1px solid #e2e8f3;
        border-radius: 14px;
        background: #fff;
        overflow: hidden;
      }

      .unscheduled-list-shell .table-responsive {
        max-height: 62vh;
      }

      .unscheduled-list-table th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #f8fafc;
        white-space: nowrap;
        font-size: 0.76rem;
        text-transform: uppercase;
        letter-spacing: 0.03em;
      }

      .unscheduled-list-table td {
        vertical-align: top;
        font-size: 0.88rem;
      }

      .unscheduled-list-table .hours-col {
        width: 76px;
        min-width: 76px;
        text-align: center;
        font-weight: 600;
      }

      .unscheduled-list-empty,
      .unscheduled-list-loading {
        min-height: 220px;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        color: #8592a3;
        padding: 1.5rem;
      }

      .unscheduled-list-loading .loader-inline {
        display: inline-flex;
        align-items: center;
        gap: 0.65rem;
        font-weight: 600;
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
              <div class="row">
                <div class="col-lg-8 mb-4 order-0">
                  <div class="row">
                    <div class="col-lg-12 order-0 mb-4">
                      <div class="card shadow-sm hero-card">
                        <div class="d-flex align-items-center row">
                          <div class="col-sm-8 px-4 py-4">
                            <h4 class="text-primary fw-bold mb-2">Scheduler Dashboard</h4>

                            <p class="mb-3 text-muted">
                              Welcome back, <strong><?php echo $schedulerName; ?></strong>.
                              You are managing the scheduling workspace for
                              <strong><?php echo $collegeName; ?></strong> during
                              <strong><?php echo $academicTermTextEscaped; ?></strong>.
                            </p>

                            <div class="hero-meta mb-3">
                              <span class="badge bg-label-primary"><?php echo $collegeName; ?></span>
                              <span class="badge bg-label-info"><?php echo $campusName; ?></span>
                              <span class="badge bg-label-info"><?php echo $academicTermTextEscaped; ?></span>
                            </div>

                            <a href="manage-class-schedule.php" class="btn btn-sm btn-primary">
                              Open Class Scheduling
                            </a>
                          </div>

                          <div class="col-sm-4 text-center">
                            <img
                              src="../assets/img/illustrations/man-with-laptop-light.png"
                              height="130"
                              alt="Scheduler Overview"
                              class="img-fluid p-2"
                            />
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="row">
                    <div class="col-lg-12 order-0 mb-4">
                      <div class="card h-100 shadow-sm">
                        <div class="card-header d-flex align-items-center justify-content-between pb-0">
                          <div class="card-title mb-0">
                            <h5 class="m-0 me-2">Scheduling Operations Overview</h5>
                            <small class="text-muted" id="operationsOverviewSubtitle">College-level scheduling indicators</small>
                          </div>
                          <div class="d-flex align-items-center gap-2 flex-wrap">
                            <div class="btn-group btn-group-sm dashboard-scope-toggle" role="group" aria-label="Dashboard scope toggle">
                              <button type="button" class="btn btn-outline-primary active" data-dashboard-scope="college">Current College</button>
                              <?php if ($campusViewEnabled): ?>
                                <button type="button" class="btn btn-outline-primary" data-dashboard-scope="campus">Current Campus</button>
                              <?php endif; ?>
                            </div>
                            <span class="badge bg-label-primary" id="dashboardScopeBadge">College View</span>
                          </div>
                        </div>

                        <div class="card-body px-4 py-3">
                          <div class="row gy-4">
                            <div class="col-md-3 col-6">
                              <div class="kpi-box text-center">
                                <div class="kpi-icon bg-primary">
                                  <i class="bx bx-book-open"></i>
                                </div>
                                <h3 class="kpi-value text-primary mt-2" id="countPrograms">--</h3>
                                <small class="text-muted">Programs</small>
                                <div class="metric-note">Programs with offerings this term</div>
                              </div>
                            </div>

                            <div class="col-md-3 col-6">
                              <div class="kpi-box text-center">
                                <div class="kpi-icon bg-success">
                                  <i class="bx bx-user-voice"></i>
                                </div>
                                <h3 class="kpi-value text-success mt-2" id="countFaculty">--</h3>
                                <small class="text-muted">Assigned Faculty</small>
                                <div class="metric-note">With workload in the active term</div>
                              </div>
                            </div>

                            <div class="col-md-3 col-6">
                              <div class="kpi-box text-center">
                                <div class="kpi-icon bg-info">
                                  <i class="bx bx-detail"></i>
                                </div>
                                <h3 class="kpi-value text-info mt-2" id="countProspectus">--</h3>
                                <small class="text-muted">Class Offerings</small>
                                <div class="metric-note">Generated for the active term</div>
                              </div>
                            </div>

                            <div class="col-md-3 col-6">
                              <div class="kpi-box kpi-warning text-center kpi-actionable" id="cardUnscheduled" role="button" tabindex="0" aria-label="Open unscheduled classes list">
                                <div class="kpi-icon bg-warning">
                                  <i class="bx bx-error-circle"></i>
                                </div>
                                <h3 class="kpi-value text-warning mt-2" id="countUnscheduled">--</h3>
                                <small class="text-muted">Unscheduled Classes</small>
                                <div class="metric-note" id="unscheduledNote">Awaiting dashboard data</div>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>

                    <div class="col-lg-12 order-1 mb-4">
                      <div class="card h-100 shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                          <div>
                            <h5 class="m-0" id="programChartTitle">Scheduling Progress by Program</h5>
                            <small class="text-muted" id="programChartSubtitle">Each line shows how many classes a program has, how many are already scheduled, and how many still need schedule assignment.</small>
                          </div>
                          <span class="badge bg-label-primary" id="programChartBadge">Scheduler Focus</span>
                        </div>

                        <div class="card-body">
                          <div id="programSchedulingChart" class="chart-shell"></div>
                          <p class="chart-insight mb-0" id="programProgressInsight">
                            This graph helps you see which program still has the most unscheduled classes.
                          </p>
                        </div>
                      </div>
                    </div>

                    <div class="col-lg-12 order-1 mb-4">
                      <div class="card h-100 shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                          <div>
                            <h5 class="m-0" id="scheduleHeatmapTitle">Accessible Room Occupancy Heatmap</h5>
                            <small class="text-muted" id="scheduleHeatmapSubtitle">Each cell shows the percentage of active rooms accessible to your college that are occupied during each 30-minute weekday block from 7:00 AM to 6:00 PM.</small>
                          </div>
                          <span class="badge bg-label-info" id="scheduleHeatmapBadge">Room Heat</span>
                        </div>

                        <div class="card-body">
                          <div class="heatmap-toolbar mb-3">
                            <div class="small text-muted" id="scheduleHeatmapFilterSummary">
                              Filter by room to inspect one classroom schedule.
                            </div>
                            <div class="heatmap-filter-control">
                              <label class="heatmap-filter-label" for="scheduleHeatmapRoomFilter">Room View</label>
                              <select class="form-select form-select-sm heatmap-room-filter" id="scheduleHeatmapRoomFilter" aria-label="Select room heatmap view" disabled>
                                <option value="all">All accessible rooms</option>
                              </select>
                            </div>
                          </div>
                          <div id="scheduleHeatmapChart" class="chart-shell"></div>
                          <p class="chart-insight mb-0" id="scheduleHeatmapInsight">
                            This heat map highlights the busiest 30-minute room intervals for scheduling.
                          </p>
                        </div>
                      </div>
                    </div>

                    <div class="col-lg-12 order-1 mb-4">
                      <div class="card h-100 shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                          <div>
                            <h5 class="m-0" id="weeklyPressureTitle">Weekly Scheduling Pressure</h5>
                            <small class="text-muted" id="weeklyPressureSubtitle">Bars show scheduled classes while lines track rooms in use and faculty activity for each weekday.</small>
                          </div>
                          <span class="badge bg-label-info" id="weeklyPressureBadge">Demand Map</span>
                        </div>

                        <div class="card-body">
                          <div id="weeklyPressureChart" class="chart-shell chart-shell-compact"></div>
                          <div id="weeklyPressureAnalysis" class="weekly-analysis">
                            <div class="weekly-analysis-loading">
                              <div class="loader-inline">
                                <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                                <span>Analyzing weekday load...</span>
                              </div>
                            </div>
                          </div>
                          <p class="chart-insight mb-0" id="weeklyPressureInsight">
                            This graph helps you spot which days are crowded and may need balancing.
                          </p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="col-md-6 col-lg-4 order-2 mb-4">
                  <div class="card h-100 shadow-sm">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <h5 class="card-title m-0">Scheduler Workspace</h5>
                      <span class="badge bg-label-primary">Action Center</span>
                    </div>

                    <div class="card-body workspace-scroll-area">
                      <a href="manage-prospectus.php" class="workspace-card-link">
                        <div class="workspace-card shadow-sm">
                          <div class="d-flex justify-content-between align-items-center gap-3">
                            <div class="d-flex align-items-center gap-3">
                              <div class="workspace-icon bg-primary">
                                <i class="bx bx-book-bookmark"></i>
                              </div>
                              <div>
                                <h6 class="mb-1 fw-bold text-dark">Prospectus Viewer</h6>
                                <small class="text-muted">Review subjects by year and semester</small>
                              </div>
                            </div>
                            <i class="bx bx-chevron-right text-primary fs-4"></i>
                          </div>
                        </div>
                      </a>

                      <a href="manage-workload.php" class="workspace-card-link">
                        <div class="workspace-card shadow-sm">
                          <div class="d-flex justify-content-between align-items-center gap-3">
                            <div class="d-flex align-items-center gap-3">
                              <div class="workspace-icon bg-success">
                                <i class="bx bx-user-check"></i>
                              </div>
                              <div>
                                <h6 class="mb-1 fw-bold text-dark">Faculty Workload</h6>
                                <small class="text-muted">Align units and teaching assignments</small>
                              </div>
                            </div>
                            <i class="bx bx-chevron-right text-success fs-4"></i>
                          </div>
                        </div>
                      </a>

                      <a href="manage-workload-simulations.php" class="workspace-card-link">
                        <div class="workspace-card shadow-sm">
                          <div class="d-flex justify-content-between align-items-center gap-3">
                            <div class="d-flex align-items-center gap-3">
                              <div class="workspace-icon bg-danger">
                                <i class="bx bx-git-compare"></i>
                              </div>
                              <div>
                                <h6 class="mb-1 fw-bold text-dark">Workload Simulations</h6>
                                <small class="text-muted">Test what-if faculty loading without blocking conflicts</small>
                              </div>
                            </div>
                            <i class="bx bx-chevron-right text-danger fs-4"></i>
                          </div>
                        </div>
                      </a>

                      <a href="manage-class-schedule.php" class="workspace-card-link">
                        <div class="workspace-card shadow-sm">
                          <div class="d-flex justify-content-between align-items-center gap-3">
                            <div class="d-flex align-items-center gap-3">
                              <div class="workspace-icon bg-warning">
                                <i class="bx bx-time-five"></i>
                              </div>
                              <div>
                                <h6 class="mb-1 fw-bold text-dark">Class Scheduling</h6>
                                <small class="text-muted">Set time, days, and room assignments</small>
                              </div>
                            </div>
                            <i class="bx bx-chevron-right text-warning fs-4"></i>
                          </div>
                        </div>
                      </a>

                      <a href="manage-room-utilization.php" class="workspace-card-link">
                        <div class="workspace-card shadow-sm">
                          <div class="d-flex justify-content-between align-items-center gap-3">
                            <div class="d-flex align-items-center gap-3">
                              <div class="workspace-icon bg-info">
                                <i class="bx bx-building-house"></i>
                              </div>
                              <div>
                                <h6 class="mb-1 fw-bold text-dark">Room Utilization</h6>
                                <small class="text-muted">Inspect room usage and scheduling pressure</small>
                              </div>
                            </div>
                            <i class="bx bx-chevron-right text-info fs-4"></i>
                          </div>
                        </div>
                      </a>

                      <a href="manage-class-program.php" class="workspace-card-link">
                        <div class="workspace-card shadow-sm">
                          <div class="d-flex justify-content-between align-items-center gap-3">
                            <div class="d-flex align-items-center gap-3">
                              <div class="workspace-icon bg-primary">
                                <i class="bx bx-table"></i>
                              </div>
                              <div>
                                <h6 class="mb-1 fw-bold text-dark">Class Program</h6>
                                <small class="text-muted">Review and print a section weekly class grid</small>
                              </div>
                            </div>
                            <i class="bx bx-chevron-right text-primary fs-4"></i>
                          </div>
                        </div>
                      </a>

                      <a href="manage-iso-class-program.php" class="workspace-card-link">
                        <div class="workspace-card shadow-sm">
                          <div class="d-flex justify-content-between align-items-center gap-3">
                            <div class="d-flex align-items-center gap-3">
                              <div class="workspace-icon bg-secondary">
                                <i class="bx bx-file"></i>
                              </div>
                              <div>
                                <h6 class="mb-1 fw-bold text-dark">ISO-Class Program</h6>
                                <small class="text-muted">Review and print the ISO program-based class matrix</small>
                              </div>
                            </div>
                            <i class="bx bx-chevron-right text-secondary fs-4"></i>
                          </div>
                        </div>
                      </a>

                      <a href="manage-schedule-activity.php" class="workspace-card-link">
                        <div class="workspace-card shadow-sm">
                          <div class="d-flex justify-content-between align-items-center gap-3">
                            <div class="d-flex align-items-center gap-3">
                              <div class="workspace-icon bg-secondary">
                                <i class="bx bx-radar"></i>
                              </div>
                              <div>
                                <h6 class="mb-1 fw-bold text-dark">Schedule Activity</h6>
                                <small class="text-muted">Monitor class windows, faculty location, and load heat</small>
                              </div>
                            </div>
                            <i class="bx bx-chevron-right text-secondary fs-4"></i>
                          </div>
                        </div>
                      </a>

                      <a href="campus-consolidated-workload.php" class="workspace-card-link">
                        <div class="workspace-card shadow-sm">
                          <div class="d-flex justify-content-between align-items-center gap-3">
                            <div class="d-flex align-items-center gap-3">
                              <div class="workspace-icon bg-success">
                                <i class="bx bx-spreadsheet"></i>
                              </div>
                              <div>
                                <h6 class="mb-1 fw-bold text-dark">Consolidated Workload for <?php echo $campusName; ?></h6>
                                <small class="text-muted">Generate the campus report for print, PDF, and Excel</small>
                              </div>
                            </div>
                            <i class="bx bx-chevron-right text-success fs-4"></i>
                          </div>
                        </div>
                      </a>

                      <div class="border-top pt-3 mt-3">
                        <h6 class="fw-bold mb-3">Current Checks</h6>

                        <ul class="list-group list-group-flush status-list">
                          <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span class="fw-semibold">Programs available</span>
                            <span class="badge bg-primary rounded-pill" id="summaryPrograms">--</span>
                          </li>
                          <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span class="fw-semibold">Faculty with workload</span>
                            <span class="badge bg-success rounded-pill" id="summaryFaculty">--</span>
                          </li>
                          <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span class="fw-semibold">Class offerings</span>
                            <span class="badge bg-info rounded-pill" id="summaryProspectus">--</span>
                          </li>
                          <li class="list-group-item d-flex justify-content-between align-items-center">
                            <span class="fw-semibold">Classes still unscheduled</span>
                            <span class="badge bg-success rounded-pill" id="summaryUnscheduled">--</span>
                          </li>
                        </ul>

                        <p class="text-muted small mb-0 mt-3" id="schedulerStatusMessage">
                          Scheduling looks clear for this workspace.
                        </p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <div class="modal fade" id="unscheduledClassesModal" tabindex="-1" aria-labelledby="unscheduledClassesModalLabel" aria-hidden="true">
              <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                  <div class="modal-header">
                    <div>
                      <h5 class="modal-title mb-0" id="unscheduledClassesModalLabel">Unscheduled Classes</h5>
                      <div class="small text-muted mt-1" id="unscheduledClassesModalSubtitle">Review classes that still have no saved schedule rows for the active dashboard scope.</div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                      <button type="button" class="btn btn-outline-primary btn-sm btn-print-unscheduled" disabled>
                        <i class="bx bx-printer me-1"></i> Print A4
                      </button>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                  </div>
                  <div class="modal-body">
                    <div class="unscheduled-summary-card mb-3" id="unscheduledClassesSummary">
                      <strong>Loading unscheduled classes...</strong>
                    </div>

                    <div class="small text-muted mb-3">
                      This list follows the same dashboard rule as the card count: it includes offerings with no saved lecture or laboratory schedule rows yet.
                    </div>

                    <div class="unscheduled-list-shell" id="unscheduledClassesListContainer">
                      <div class="unscheduled-list-loading">
                        <div class="loader-inline">
                          <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>
                          <span>Loading unscheduled classes...</span>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary btn-print-unscheduled" disabled>
                      <i class="bx bx-printer me-1"></i> Print A4 List
                    </button>
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
    <script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="../assets/js/dashboards-analytics.js"></script>

    <script>
      document.addEventListener("DOMContentLoaded", function () {
        let schedulingProgressChart = null;
        let scheduleHeatmapChart = null;
        let weeklyPressureChart = null;
        let scheduleHeatmapRequest = null;
        let unscheduledClassesRequest = null;
        let unscheduledClassesState = null;
        let dashboardScope = "college";
        let scheduleHeatmapRoomSelection = "all";
        let syncingScheduleHeatmapRoomFilter = false;
        const campusViewEnabled = <?php echo $campusViewEnabled ? 'true' : 'false'; ?>;
        const defaultCollegeName = <?php echo json_encode($_SESSION['college_name'] ?? 'Assigned College'); ?>;
        const defaultCampusName = <?php echo json_encode($_SESSION['campus_name'] ?? 'Current Campus'); ?>;
        const dashboardTermText = <?php echo json_encode($academicTermText); ?>;
        const unscheduledModal = document.getElementById("unscheduledClassesModal");
        const unscheduledModalTitle = document.getElementById("unscheduledClassesModalSubtitle");
        const unscheduledSummary = document.getElementById("unscheduledClassesSummary");
        const unscheduledListContainer = document.getElementById("unscheduledClassesListContainer");
        const scheduleHeatmapRoomFilter = document.getElementById("scheduleHeatmapRoomFilter");
        const scheduleHeatmapFilterSummary = document.getElementById("scheduleHeatmapFilterSummary");

        function escapeHtml(value) {
          return String(value == null ? "" : value)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#39;");
        }

        function buildInlineLoader(message) {
          return (
            '<div class="loader-inline">' +
              '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>' +
              '<span>' + message + "</span>" +
            "</div>"
          );
        }

        function yearLevelLabel(value) {
          const year = Number(value) || 0;
          if (year <= 0) {
            return "--";
          }

          if (year === 1) return "1st Year";
          if (year === 2) return "2nd Year";
          if (year === 3) return "3rd Year";
          if (year === 4) return "4th Year";
          if (year === 5) return "5th Year";
          if (year === 6) return "6th Year";
          return year + "th Year";
        }

        function formatHoursValue(value) {
          const number = Number(value) || 0;
          return Number.isInteger(number) ? String(number) : number.toFixed(1).replace(/\.0$/, "");
        }

        function activeScopeType() {
          return dashboardScope === "campus" && campusViewEnabled ? "campus" : "college";
        }

        function activeScopeLabel() {
          return activeScopeType() === "campus" ? defaultCampusName : defaultCollegeName;
        }

        function scheduleHeatmapAllRoomsLabel() {
          return activeScopeType() === "campus" ? "All campus rooms" : "All accessible rooms";
        }

        function syncScheduleHeatmapFilter(payload) {
          const roomOptions = payload && Array.isArray(payload.room_options) ? payload.room_options : [];
          const selectedRoomId = Number(payload && payload.selected_room_id) || 0;
          const selectedRoomLabel = payload && payload.selected_room_label ? payload.selected_room_label : "the selected room";
          const scheduleHeatmapSubtitle = document.getElementById("scheduleHeatmapSubtitle");
          const aggregateSubtitle = activeScopeType() === "campus"
            ? "Each cell shows the percentage of active campus rooms occupied during each 30-minute weekday block from 7:00 AM to 6:00 PM across all colleges in the current campus."
            : "Each cell shows the percentage of active rooms accessible to your college that are occupied during each 30-minute weekday block from 7:00 AM to 6:00 PM.";

          if (scheduleHeatmapRoomFilter) {
            const optionMarkup = roomOptions.map(function (room) {
              const roomId = Number(room && room.room_id) || 0;
              const roomLabel = room && room.label ? room.label : "Room " + roomId;
              return '<option value="' + roomId + '">' + escapeHtml(roomLabel) + "</option>";
            }).join("");
            const nextValue = roomOptions.some(function (room) {
              return String(Number(room && room.room_id) || 0) === String(selectedRoomId);
            }) ? String(selectedRoomId) : "all";

            syncingScheduleHeatmapRoomFilter = true;
            scheduleHeatmapRoomFilter.innerHTML =
              '<option value="all">' + escapeHtml(scheduleHeatmapAllRoomsLabel()) + "</option>" + optionMarkup;
            scheduleHeatmapRoomFilter.value = nextValue;
            scheduleHeatmapRoomFilter.disabled = roomOptions.length === 0;
            syncingScheduleHeatmapRoomFilter = false;
            scheduleHeatmapRoomSelection = nextValue;
          }

          if (scheduleHeatmapFilterSummary) {
            if (roomOptions.length === 0) {
              scheduleHeatmapFilterSummary.textContent = "No active rooms are available for this heatmap.";
            } else if (selectedRoomId > 0) {
              scheduleHeatmapFilterSummary.textContent = "Viewing classroom heatmap for " + selectedRoomLabel + ".";
            } else {
              scheduleHeatmapFilterSummary.textContent = "Filter by room to inspect one classroom schedule.";
            }
          }

          if (scheduleHeatmapSubtitle) {
            scheduleHeatmapSubtitle.textContent = selectedRoomId > 0
              ? "Each cell shows whether " + selectedRoomLabel + " is occupied during each 30-minute weekday block from 7:00 AM to 6:00 PM."
              : aggregateSubtitle;
          }
        }

        function setScheduleHeatmapFilterLoadingState() {
          if (scheduleHeatmapRoomFilter) {
            scheduleHeatmapRoomFilter.disabled = true;
          }

          if (scheduleHeatmapFilterSummary) {
            scheduleHeatmapFilterSummary.textContent = "Loading room options for the heatmap.";
          }
        }

        function setWeeklyPressureAnalysisHtml(markup) {
          const analysisContainer = document.getElementById("weeklyPressureAnalysis");

          if (analysisContainer) {
            analysisContainer.innerHTML = markup;
          }
        }

        function setWeeklyPressureAnalysisLoadingState() {
          setWeeklyPressureAnalysisHtml(
            '<div class="weekly-analysis-loading">' + buildInlineLoader("Analyzing weekday load...") + "</div>"
          );
        }

        function scheduleHeatmapInsightLoadingText() {
          if (scheduleHeatmapRoomSelection !== "all") {
            return "Loading the classroom heat map for the selected room.";
          }

          return dashboardScope === "campus"
            ? "Loading which 30-minute weekday blocks from 7:00 AM to 6:00 PM have the highest room occupancy across the current campus."
            : "Loading which 30-minute weekday blocks from 7:00 AM to 6:00 PM are busiest across rooms accessible to your college.";
        }

        function buildScheduleHeatmapRequestData() {
          return {
            scope: dashboardScope,
            room_id: scheduleHeatmapRoomSelection === "all" ? "" : scheduleHeatmapRoomSelection
          };
        }

        function loadScheduleHeatmap() {
          if (scheduleHeatmapRequest && scheduleHeatmapRequest.readyState !== 4) {
            scheduleHeatmapRequest.abort();
          }

          if (scheduleHeatmapChart) {
            scheduleHeatmapChart.destroy();
            scheduleHeatmapChart = null;
          }

          setScheduleHeatmapFilterLoadingState();
          setChartLoading("scheduleHeatmapChart", "Loading room occupancy heat map...");
          setInsight("scheduleHeatmapInsight", scheduleHeatmapInsightLoadingText());

          scheduleHeatmapRequest = $.ajax({
            url: "../backend/dashboard_scheduler_charts.php",
            type: "POST",
            dataType: "json",
            data: buildScheduleHeatmapRequestData(),
            success: function (data) {
              scheduleHeatmapRequest = null;
              renderScheduleHeatmap((data || {}).schedule_heatmap || {});
            },
            error: function (xhr, statusText) {
              if (statusText === "abort") {
                return;
              }

              scheduleHeatmapRequest = null;
              console.error("Scheduler heatmap error:", xhr.responseText);

              if (scheduleHeatmapRoomFilter) {
                scheduleHeatmapRoomFilter.disabled = scheduleHeatmapRoomFilter.options.length <= 1;
              }
              if (scheduleHeatmapFilterSummary) {
                scheduleHeatmapFilterSummary.textContent = "Unable to refresh room options for the heatmap.";
              }

              setChartFallback("scheduleHeatmapChart", "Unable to load room occupancy heat map.");
              setInsight("scheduleHeatmapInsight", "Room occupancy data could not be loaded.");
            }
          });
        }

        function setUnscheduledPrintButtonsDisabled(disabled) {
          document.querySelectorAll(".btn-print-unscheduled").forEach(function (button) {
            button.disabled = disabled;
          });
        }

        function setUnscheduledModalLoadingState() {
          unscheduledClassesState = null;
          setUnscheduledPrintButtonsDisabled(true);

          if (unscheduledModalTitle) {
            unscheduledModalTitle.textContent = "Loading unscheduled classes for the active dashboard scope.";
          }

          if (unscheduledSummary) {
            unscheduledSummary.innerHTML = "<strong>Loading unscheduled classes...</strong>";
          }

          if (unscheduledListContainer) {
            unscheduledListContainer.innerHTML =
              '<div class="unscheduled-list-loading">' + buildInlineLoader("Loading unscheduled classes...") + "</div>";
          }
        }

        function renderUnscheduledSummary(payload) {
          if (!unscheduledSummary) {
            return;
          }

          const safePayload = payload && typeof payload === "object" ? payload : {};
          const scopeType = safePayload.scope === "campus" && campusViewEnabled ? "campus" : "college";
          const scopeLabel = safePayload.scope_label || (scopeType === "campus" ? defaultCampusName : defaultCollegeName);
          const termText = safePayload.term_text || dashboardTermText;
          const total = Number(safePayload.total) || 0;

          unscheduledSummary.innerHTML =
            '<div class="d-flex flex-wrap justify-content-between align-items-start gap-3">' +
              '<div>' +
                '<strong>' + escapeHtml(total + " unscheduled class" + (total === 1 ? "" : "es")) + "</strong>" +
                '<div class="small text-muted mt-1">Scope: ' + escapeHtml(scopeLabel) + "</div>" +
                '<div class="small text-muted">Term: ' + escapeHtml(termText) + "</div>" +
              "</div>" +
              '<div class="unscheduled-summary-metric text-md-end">' +
                '<span class="badge bg-label-warning text-warning">Needs Scheduling</span>' +
              "</div>" +
            "</div>";
        }

        function renderUnscheduledList(payload) {
          const safePayload = payload && typeof payload === "object" ? payload : {};
          const rows = Array.isArray(safePayload.rows) ? safePayload.rows : [];
          const scopeType = safePayload.scope === "campus" && campusViewEnabled ? "campus" : "college";
          const scopeLabel = safePayload.scope_label || (scopeType === "campus" ? defaultCampusName : defaultCollegeName);
          const total = Number(safePayload.total) || rows.length;

          if (unscheduledModalTitle) {
            unscheduledModalTitle.textContent =
              total > 0
                ? "Review the current term list below, then print it on A4 if needed."
                : "No unscheduled classes were found for " + scopeLabel + ".";
          }

          renderUnscheduledSummary({
            scope: scopeType,
            scope_label: scopeLabel,
            term_text: safePayload.term_text || dashboardTermText,
            total: total
          });

          if (!unscheduledListContainer) {
            return;
          }

          if (rows.length === 0) {
            unscheduledListContainer.innerHTML =
              '<div class="unscheduled-list-empty">' +
                '<div><strong>No unscheduled classes found.</strong><div class="small text-muted mt-2">Everything in the current dashboard scope already has at least one saved schedule row.</div></div>' +
              "</div>";
            setUnscheduledPrintButtonsDisabled(true);
            return;
          }

          let headerHtml =
            "<tr>" +
              "<th>#</th>";

          if (scopeType === "campus") {
            headerHtml += "<th>College</th>";
          }

          headerHtml +=
              "<th>Program</th>" +
              "<th>Year</th>" +
              "<th>Section</th>" +
              "<th>Subject Code</th>" +
              "<th>Description</th>" +
              '<th class="hours-col">LEC</th>' +
              '<th class="hours-col">LAB</th>' +
            "</tr>";

          const rowsHtml = rows.map(function (row, index) {
            let cells =
              "<td>" + escapeHtml(index + 1) + "</td>";

            if (scopeType === "campus") {
              cells += "<td>" + escapeHtml(row.college_label || "--") + "</td>";
            }

            cells +=
              "<td>" + escapeHtml(row.program_label || "--") + "</td>" +
              "<td>" + escapeHtml(yearLevelLabel(row.year_level)) + "</td>" +
              "<td>" + escapeHtml(row.section_name || "--") + "</td>" +
              '<td class="text-nowrap">' + escapeHtml(row.sub_code || "--") + "</td>" +
              "<td>" + escapeHtml(row.sub_description || "--") + "</td>" +
              '<td class="hours-col">' + escapeHtml(formatHoursValue(row.lec_units)) + "</td>" +
              '<td class="hours-col">' + escapeHtml(formatHoursValue(row.lab_units)) + "</td>";

            return "<tr>" + cells + "</tr>";
          }).join("");

          unscheduledListContainer.innerHTML =
            '<div class="table-responsive">' +
              '<table class="table table-bordered table-hover mb-0 unscheduled-list-table">' +
                "<thead>" + headerHtml + "</thead>" +
                "<tbody>" + rowsHtml + "</tbody>" +
              "</table>" +
            "</div>";

          setUnscheduledPrintButtonsDisabled(false);
        }

        function openUnscheduledClassesModal() {
          if (!unscheduledModal) {
            return;
          }

          setUnscheduledModalLoadingState();
          const modalInstance = bootstrap.Modal.getOrCreateInstance(unscheduledModal);
          modalInstance.show();
          loadUnscheduledClassesList();
        }

        function loadUnscheduledClassesList() {
          if (unscheduledClassesRequest && unscheduledClassesRequest.readyState !== 4) {
            unscheduledClassesRequest.abort();
          }

          setUnscheduledModalLoadingState();

          unscheduledClassesRequest = $.ajax({
            url: "../backend/dashboard_unscheduled_classes.php",
            type: "POST",
            dataType: "json",
            data: { scope: dashboardScope },
            success: function (payload) {
              unscheduledClassesState = payload || {};
              renderUnscheduledList(unscheduledClassesState);
            },
            error: function (xhr) {
              if (xhr.statusText === "abort") {
                return;
              }

              if (unscheduledModalTitle) {
                unscheduledModalTitle.textContent = "Unable to load the unscheduled classes list.";
              }

              if (unscheduledSummary) {
                unscheduledSummary.innerHTML = "<strong>Unable to load the unscheduled classes list.</strong>";
              }

              if (unscheduledListContainer) {
                unscheduledListContainer.innerHTML =
                  '<div class="unscheduled-list-empty">' +
                    '<div><strong>Unable to load unscheduled classes.</strong><div class="small text-muted mt-2">Please try again.</div></div>' +
                  "</div>";
              }

              setUnscheduledPrintButtonsDisabled(true);
              console.error("Dashboard unscheduled classes error:", xhr.responseText);
            }
          });
        }

        function buildUnscheduledPrintHtml(payload) {
          const safePayload = payload && typeof payload === "object" ? payload : {};
          const rows = Array.isArray(safePayload.rows) ? safePayload.rows : [];
          const scopeType = safePayload.scope === "campus" && campusViewEnabled ? "campus" : "college";
          const scopeLabel = safePayload.scope_label || (scopeType === "campus" ? defaultCampusName : defaultCollegeName);
          const termText = safePayload.term_text || dashboardTermText;
          const printedAt = new Date().toLocaleString();

          let headerHtml =
            "<tr>" +
              "<th>#</th>";

          if (scopeType === "campus") {
            headerHtml += "<th>College</th>";
          }

          headerHtml +=
              "<th>Program</th>" +
              "<th>Year</th>" +
              "<th>Section</th>" +
              "<th>Subject Code</th>" +
              "<th>Description</th>" +
              "<th>LEC</th>" +
              "<th>LAB</th>" +
            "</tr>";

          const rowsHtml = rows.length > 0
            ? rows.map(function (row, index) {
                let cells =
                  "<td>" + escapeHtml(index + 1) + "</td>";

                if (scopeType === "campus") {
                  cells += "<td>" + escapeHtml(row.college_label || "--") + "</td>";
                }

                cells +=
                  "<td>" + escapeHtml(row.program_label || "--") + "</td>" +
                  "<td>" + escapeHtml(yearLevelLabel(row.year_level)) + "</td>" +
                  "<td>" + escapeHtml(row.section_name || "--") + "</td>" +
                  "<td>" + escapeHtml(row.sub_code || "--") + "</td>" +
                  "<td>" + escapeHtml(row.sub_description || "--") + "</td>" +
                  "<td>" + escapeHtml(formatHoursValue(row.lec_units)) + "</td>" +
                  "<td>" + escapeHtml(formatHoursValue(row.lab_units)) + "</td>";

                return "<tr>" + cells + "</tr>";
              }).join("")
            : "<tr><td colspan='" + (scopeType === "campus" ? 9 : 8) + "'>No unscheduled classes found for this scope.</td></tr>";

          return (
            "<!DOCTYPE html>" +
            '<html lang="en"><head><meta charset="utf-8">' +
            "<title>Unscheduled Classes List</title>" +
            "<style>" +
              "@page { size: A4 portrait; margin: 12mm; }" +
              "body { font-family: Arial, sans-serif; color: #1f2937; margin: 0; font-size: 12px; }" +
              ".report-header { margin-bottom: 14px; }" +
              ".report-title { font-size: 20px; font-weight: 700; margin: 0 0 6px; }" +
              ".report-subtitle { margin: 0; color: #4b5563; line-height: 1.45; }" +
              ".report-note { margin: 12px 0 14px; padding: 10px 12px; background: #f8fafc; border: 1px solid #d9e2ef; border-radius: 8px; }" +
              ".report-meta { display: flex; flex-wrap: wrap; gap: 14px; margin-top: 8px; color: #4b5563; }" +
              "table { width: 100%; border-collapse: collapse; }" +
              "thead { display: table-header-group; }" +
              "tr { page-break-inside: avoid; }" +
              "th, td { border: 1px solid #d7dee8; padding: 7px 8px; vertical-align: top; }" +
              "th { background: #eef3f9; text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: 0.03em; }" +
              "td { font-size: 11px; }" +
              ".hours-col { width: 42px; text-align: center; font-weight: 700; }" +
            "</style></head><body>" +
              '<div class="report-header">' +
                '<p class="report-title">Unscheduled Classes List</p>' +
                '<p class="report-subtitle">Scope: ' + escapeHtml(scopeLabel) + "<br>Term: " + escapeHtml(termText) + "</p>" +
                '<div class="report-meta">' +
                  "<span>Total Classes: " + escapeHtml(rows.length) + "</span>" +
                  "<span>Printed: " + escapeHtml(printedAt) + "</span>" +
                "</div>" +
              "</div>" +
              '<div class="report-note">This report lists offerings with no saved lecture or laboratory schedule rows yet.</div>' +
              "<table>" +
                "<thead>" + headerHtml + "</thead>" +
                "<tbody>" + rowsHtml + "</tbody>" +
              "</table>" +
            "</body></html>"
          );
        }

        function printUnscheduledClassesList() {
          if (!unscheduledClassesState || !Array.isArray(unscheduledClassesState.rows)) {
            return;
          }

          const printWindow = window.open("", "_blank", "width=960,height=1200");
          if (!printWindow) {
            window.alert("Please allow pop-ups so the unscheduled classes list can be printed.");
            return;
          }

          printWindow.document.open();
          printWindow.document.write(buildUnscheduledPrintHtml(unscheduledClassesState));
          printWindow.document.close();
          printWindow.focus();
          window.setTimeout(function () {
            printWindow.print();
          }, 250);
        }

        function animateCount(elementId, target) {
          const element = document.getElementById(elementId);
          const safeTarget = Number(target) || 0;

          if (!element) {
            return;
          }

          let current = 0;
          const increment = Math.max(1, Math.ceil(safeTarget / 20));

          const timer = window.setInterval(function () {
            current += increment;

            if (current >= safeTarget) {
              current = safeTarget;
              window.clearInterval(timer);
            }

            element.textContent = current;
          }, 20);
        }

        function setChartFallback(containerId, message) {
          const chartContainer = document.getElementById(containerId);

          if (!chartContainer) {
            return;
          }

          chartContainer.innerHTML =
            '<div class="chart-fallback"><p class="mb-0">' + message + "</p></div>";
        }

        function setChartLoading(containerId, message) {
          const chartContainer = document.getElementById(containerId);

          if (!chartContainer) {
            return;
          }

          chartContainer.innerHTML =
            '<div class="chart-loader">' + buildInlineLoader(message) + "</div>";
        }

        function setInsight(elementId, message) {
          const element = document.getElementById(elementId);

          if (element) {
            element.textContent = message;
          }
        }

        function setScopeButtons() {
          document.querySelectorAll("[data-dashboard-scope]").forEach(function (button) {
            const isActive = button.getAttribute("data-dashboard-scope") === dashboardScope;
            button.classList.toggle("active", isActive);
            button.classList.toggle("btn-primary", isActive);
            button.classList.toggle("btn-outline-primary", !isActive);
          });
        }

        function applyDashboardScopeMeta(meta) {
          const scopeType = meta && meta.scope === "campus" && campusViewEnabled ? "campus" : "college";
          const scopeLabel = meta && meta.scope_label ? meta.scope_label : (scopeType === "campus" ? <?php echo json_encode($_SESSION['campus_name'] ?? 'Current Campus'); ?> : <?php echo json_encode($_SESSION['college_name'] ?? 'Assigned College'); ?>);
          const scopeBadge = document.getElementById("dashboardScopeBadge");
          const overviewSubtitle = document.getElementById("operationsOverviewSubtitle");
          const programChartTitle = document.getElementById("programChartTitle");
          const programChartSubtitle = document.getElementById("programChartSubtitle");
          const programChartBadge = document.getElementById("programChartBadge");
          const scheduleHeatmapTitle = document.getElementById("scheduleHeatmapTitle");
          const scheduleHeatmapSubtitle = document.getElementById("scheduleHeatmapSubtitle");
          const scheduleHeatmapBadge = document.getElementById("scheduleHeatmapBadge");
          const weeklyPressureTitle = document.getElementById("weeklyPressureTitle");
          const weeklyPressureSubtitle = document.getElementById("weeklyPressureSubtitle");
          const weeklyPressureBadge = document.getElementById("weeklyPressureBadge");

          if (scopeBadge) {
            scopeBadge.textContent = scopeType === "campus" ? "Campus View" : "College View";
          }

          if (overviewSubtitle) {
            overviewSubtitle.textContent = scopeType === "campus"
              ? "Read-only scheduling indicators across all colleges in " + scopeLabel
              : "College-level scheduling indicators";
          }

          if (programChartTitle) {
            programChartTitle.textContent = scopeType === "campus"
              ? "Scheduling Progress by College"
              : "Scheduling Progress by Program";
          }

          if (programChartSubtitle) {
            programChartSubtitle.textContent = scopeType === "campus"
              ? "Each line shows total classes, scheduled classes, and remaining unscheduled classes for every college in the current campus."
              : "Each line shows how many classes a program has, how many are already scheduled, and how many still need schedule assignment.";
          }

          if (programChartBadge) {
            programChartBadge.textContent = scopeType === "campus" ? "Campus Monitor" : "Scheduler Focus";
          }

          if (scheduleHeatmapTitle) {
            scheduleHeatmapTitle.textContent = scopeType === "campus"
              ? "Campus Room Occupancy Heatmap"
              : "Accessible Room Occupancy Heatmap";
          }

          if (scheduleHeatmapSubtitle) {
            scheduleHeatmapSubtitle.textContent = scopeType === "campus"
              ? "Each cell shows the percentage of active campus rooms occupied during each 30-minute weekday block from 7:00 AM to 6:00 PM across all colleges in the current campus."
              : "Each cell shows the percentage of active rooms accessible to your college that are occupied during each 30-minute weekday block from 7:00 AM to 6:00 PM.";
          }

          if (scheduleHeatmapBadge) {
            scheduleHeatmapBadge.textContent = scopeType === "campus" ? "Campus Heat" : "Room Heat";
          }

          if (weeklyPressureTitle) {
            weeklyPressureTitle.textContent = scopeType === "campus"
              ? "Weekly Scheduling Pressure Across Campus"
              : "Weekly Scheduling Pressure";
          }

          if (weeklyPressureSubtitle) {
            weeklyPressureSubtitle.textContent = scopeType === "campus"
              ? "Bars show scheduled class volume while lines trace rooms in use and faculty activity across all colleges in the current campus."
              : "Bars show scheduled classes while lines track rooms in use and faculty activity for each weekday.";
          }

          if (weeklyPressureBadge) {
            weeklyPressureBadge.textContent = scopeType === "campus" ? "Campus Demand" : "Demand Map";
          }
        }

        function setCountsLoadingState() {
          ["countPrograms", "countFaculty", "countProspectus", "countUnscheduled"].forEach(function (id) {
            const element = document.getElementById(id);
            if (element) {
              element.textContent = "--";
            }
          });

          ["summaryPrograms", "summaryFaculty", "summaryProspectus", "summaryUnscheduled"].forEach(function (id) {
            const element = document.getElementById(id);
            if (element) {
              element.textContent = "--";
            }
          });

          const unscheduledCard = document.getElementById("cardUnscheduled");
          const unscheduledNote = document.getElementById("unscheduledNote");
          const schedulerStatusMessage = document.getElementById("schedulerStatusMessage");

          if (unscheduledCard) {
            unscheduledCard.classList.remove("kpi-warning-active");
          }
          if (unscheduledNote) {
            unscheduledNote.textContent = "Loading dashboard counts";
          }
          if (schedulerStatusMessage) {
            schedulerStatusMessage.textContent = "Loading dashboard data...";
          }
        }

        function renderProgramSchedulingChart(data) {
          if (!Array.isArray(data) || data.length === 0) {
            setChartFallback("programSchedulingChart", "No program scheduling data available.");
            setInsight("programProgressInsight", "No class offerings were found for the current term, so there is nothing to compare yet.");
            return;
          }

          const categories = [];
          const totalValues = [];
          const scheduledValues = [];
          const pendingValues = [];
          let highestPending = null;
          let totalPending = 0;

          data.forEach(function (row, index) {
            const programLabel = row.program || "Program " + (index + 1);
            const total = Number(row.total) || 0;
            const scheduled = Number(row.scheduled) || 0;
            const pending = Number(row.pending);
            const safePending = Number.isFinite(pending) ? pending : Math.max(0, total - scheduled);

            categories.push(programLabel);
            totalValues.push(total);
            scheduledValues.push(scheduled);
            pendingValues.push(safePending);
            totalPending += safePending;

            if (!highestPending || safePending > highestPending.pending || (safePending === highestPending.pending && total > highestPending.total)) {
              highestPending = {
                program: programLabel,
                pending: safePending,
                total: total
              };
            }
          });

          const chartContainer = document.querySelector("#programSchedulingChart");

          if (!chartContainer) {
            return;
          }

          if (schedulingProgressChart) {
            schedulingProgressChart.destroy();
          }

          chartContainer.innerHTML = "";

          schedulingProgressChart = new ApexCharts(chartContainer, {
            chart: {
              type: "line",
              height: 360,
              toolbar: { show: false },
              zoom: { enabled: false }
            },
            series: [
              {
                name: "All Classes",
                data: totalValues
              },
              {
                name: "Already Scheduled",
                data: scheduledValues
              },
              {
                name: "Still Unscheduled",
                data: pendingValues
              }
            ],
            colors: ["#275efe", "#1f9d55", "#ff8a00"],
            stroke: {
              curve: "straight",
              width: [4, 4, 4],
              dashArray: [0, 6, 2],
              lineCap: "round"
            },
            fill: {
              type: "solid",
              opacity: 1
            },
            markers: {
              size: 4,
              strokeWidth: 2,
              strokeColors: "#ffffff",
              hover: {
                sizeOffset: 3
              }
            },
            dataLabels: {
              enabled: false
            },
            legend: {
              position: "top",
              horizontalAlign: "left",
              fontSize: "12px",
              markers: {
                width: 10,
                height: 10,
                radius: 12
              }
            },
            grid: {
              borderColor: "#eceef1",
              strokeDashArray: 4,
              padding: {
                top: 8
              }
            },
            xaxis: {
              categories: categories,
              tickPlacement: "on",
              labels: {
                rotate: -25,
                trim: true,
                style: {
                  fontSize: "12px",
                  colors: "#697a8d"
                }
              }
            },
            yaxis: {
              min: 0,
              max: Math.max(5, Math.ceil((Math.max.apply(null, totalValues.concat(scheduledValues, pendingValues)) || 0) * 1.12)),
              tickAmount: 5,
              forceNiceScale: true,
              title: {
                text: "Offerings",
                style: {
                  color: "#697a8d"
                }
              }
            },
            tooltip: {
              shared: true,
              intersect: false,
              y: {
                formatter: function (value, context) {
                  const labels = [" total classes", " already scheduled", " still unscheduled"];
                  return value + labels[context.seriesIndex];
                }
              }
            },
            responsive: [
              {
                breakpoint: 768,
                options: {
                  chart: {
                    height: 320
                  },
                  xaxis: {
                    labels: {
                      rotate: -40
                    }
                  }
                }
              }
            ]
          });

          schedulingProgressChart.render();

          if (totalPending === 0) {
            setInsight("programProgressInsight", "All programs are fully scheduled. The unscheduled line is at zero across the chart.");
            return;
          }

          setInsight(
            "programProgressInsight",
            "The orange line shows classes that still need schedule assignment. " +
              highestPending.program +
              " has the highest unscheduled count with " +
              highestPending.pending +
              " class" +
              (highestPending.pending === 1 ? "" : "s") +
              " still not scheduled out of " +
              highestPending.total +
              " total."
          );
        }

        function renderScheduleHeatmap(heatmap) {
          const payload = heatmap && typeof heatmap === "object" ? heatmap : {};
          const series = Array.isArray(payload.series) ? payload.series : [];
          const totalRooms = Number(payload.total_rooms) || 0;
          const peakPercent = Number(payload.peak_percent) || 0;
          const peakOccupiedRooms = Number(payload.peak_occupied_rooms) || 0;
          const peakLabel = payload.peak_label || "No occupied room slots";
          const occupiedSlotCount = Number(payload.occupied_slot_count) || 0;
          const busiestDayLabel = payload.busiest_day_label || "";
          const busiestDaySlots = Number(payload.busiest_day_slots) || 0;
          const selectedRoomLabel = payload.selected_room_label || "Selected room";
          const isSingleRoomView = payload.view_mode === "room";
          const scopeType = dashboardScope === "campus" && campusViewEnabled ? "campus" : "college";
          const poolLabel = scopeType === "campus" ? "campus room pool" : "accessible room pool";
          const chartContainer = document.querySelector("#scheduleHeatmapChart");

          if (!chartContainer) {
            return;
          }

          syncScheduleHeatmapFilter(payload);

          if (!series.length) {
            setChartFallback("scheduleHeatmapChart", "No room occupancy heat map is available.");
            setInsight("scheduleHeatmapInsight", "Room occupancy could not be measured for the current scheduling scope.");
            return;
          }

          if (scheduleHeatmapChart) {
            scheduleHeatmapChart.destroy();
          }

          chartContainer.innerHTML = "";

          scheduleHeatmapChart = new ApexCharts(chartContainer, {
            series: series,
            chart: {
              type: "heatmap",
              height: 340,
              toolbar: { show: false }
            },
            dataLabels: {
              enabled: false
            },
            stroke: {
              width: 1,
              colors: ["#ffffff"]
            },
            plotOptions: {
              heatmap: {
                shadeIntensity: 0.6,
                enableShades: false,
                colorScale: {
                  ranges: isSingleRoomView
                    ? [
                        { from: 0, to: 0, name: "Available", color: "#edf1f7" },
                        { from: 0.1, to: 100, name: "Occupied", color: "#1f6fd1" }
                      ]
                    : [
                        { from: 0, to: 0, name: "Idle", color: "#edf1f7" },
                        { from: 0.1, to: 25, name: "Light", color: "#8fc2ff" },
                        { from: 25.1, to: 50, name: "Moderate", color: "#4b97f2" },
                        { from: 50.1, to: 75, name: "Busy", color: "#1f6fd1" },
                        { from: 75.1, to: 100, name: "Peak", color: "#114aa3" }
                      ]
                }
              }
            },
            xaxis: {
              labels: {
                rotate: -55,
                trim: false,
                hideOverlappingLabels: false,
                style: {
                  fontSize: "9px",
                  colors: "#697a8d"
                }
              }
            },
            yaxis: {
              labels: {
                style: {
                  colors: "#697a8d"
                }
              }
            },
            legend: {
              position: "top",
              horizontalAlign: "left"
            },
            grid: {
              borderColor: "#eceef4",
              strokeDashArray: 4
            },
            tooltip: {
              y: {
                formatter: function (value) {
                  return isSingleRoomView
                    ? (Number(value) > 0 ? "Occupied" : "Available")
                    : Number(value).toFixed(1) + "% occupied";
                }
              }
            }
          });

          scheduleHeatmapChart.render();

          if (totalRooms <= 0) {
            setInsight(
              "scheduleHeatmapInsight",
              "No active rooms are available in the current " + poolLabel + ", so occupancy cannot be measured yet."
            );
            return;
          }

          if (isSingleRoomView) {
            if (occupiedSlotCount <= 0) {
              setInsight(
                "scheduleHeatmapInsight",
                selectedRoomLabel + " has no scheduled occupancy between 7:00 AM and 6:00 PM on weekdays for the current term."
              );
              return;
            }

            setInsight(
              "scheduleHeatmapInsight",
              selectedRoomLabel +
                " is occupied during " +
                occupiedSlotCount +
                " weekday 30-minute block" +
                (occupiedSlotCount === 1 ? "" : "s") +
                (busiestDaySlots > 0
                  ? ". " + busiestDayLabel + " is the busiest day with " + busiestDaySlots + " occupied block" + (busiestDaySlots === 1 ? "" : "s") + "."
                  : ".")
            );
            return;
          }

          if (peakPercent <= 0) {
            setInsight(
              "scheduleHeatmapInsight",
              "No occupied room slots are mapped between 7:00 AM and 6:00 PM across the " + poolLabel + " of " + totalRooms + " room" + (totalRooms === 1 ? "" : "s") + "."
            );
            return;
          }

          setInsight(
            "scheduleHeatmapInsight",
            "Peak room pressure hits " +
              peakLabel +
              " with " +
              peakOccupiedRooms +
              "/" +
              totalRooms +
              " rooms occupied (" +
              peakPercent.toFixed(1) +
              "%) in the current " +
              poolLabel +
              "."
          );
        }

        function renderWeeklyPressureChart(data) {
          if (!Array.isArray(data) || data.length === 0) {
            setChartFallback("weeklyPressureChart", "No weekday scheduling pressure data available.");
            setWeeklyPressureAnalysisHtml('<div class="weekly-analysis-empty">No weekday load analysis is available yet.</div>');
            setInsight("weeklyPressureInsight", "No scheduled classes were found for the current term, so weekday pressure cannot be measured yet.");
            return;
          }

          const days = [];
          const meetings = [];
          const rooms = [];
          const faculty = [];
          let busiestDay = null;
          let highestRoomDay = null;
          let highestFacultyDay = null;
          let tightestRoomDay = null;
          let quietestActiveDay = null;
          const noActivityDays = [];

          data.forEach(function (row) {
            const dayLabel = row.day || "--";
            const meetingCount = Number(row.meetings) || 0;
            const roomCount = Number(row.rooms) || 0;
            const facultyCount = Number(row.faculty) || 0;
            const hasActivity = meetingCount > 0 || roomCount > 0 || facultyCount > 0;

            days.push(dayLabel);
            meetings.push(meetingCount);
            rooms.push(roomCount);
            faculty.push(facultyCount);

            if (!busiestDay || meetingCount > busiestDay.meetings || (meetingCount === busiestDay.meetings && roomCount > busiestDay.rooms)) {
              busiestDay = {
                day: dayLabel,
                meetings: meetingCount,
                rooms: roomCount
              };
            }

            if (!highestRoomDay || roomCount > highestRoomDay.rooms || (roomCount === highestRoomDay.rooms && meetingCount > highestRoomDay.meetings)) {
              highestRoomDay = {
                day: dayLabel,
                meetings: meetingCount,
                rooms: roomCount
              };
            }

            if (!highestFacultyDay || facultyCount > highestFacultyDay.faculty || (facultyCount === highestFacultyDay.faculty && meetingCount > highestFacultyDay.meetings)) {
              highestFacultyDay = {
                day: dayLabel,
                meetings: meetingCount,
                faculty: facultyCount
              };
            }

            if (roomCount > 0 && meetingCount > 0) {
              const classesPerRoom = meetingCount / roomCount;
              if (!tightestRoomDay || classesPerRoom > tightestRoomDay.classesPerRoom || (classesPerRoom === tightestRoomDay.classesPerRoom && meetingCount > tightestRoomDay.meetings)) {
                tightestRoomDay = {
                  day: dayLabel,
                  meetings: meetingCount,
                  rooms: roomCount,
                  classesPerRoom: classesPerRoom
                };
              }
            }

            if (hasActivity) {
              if (!quietestActiveDay || meetingCount < quietestActiveDay.meetings || (meetingCount === quietestActiveDay.meetings && roomCount < quietestActiveDay.rooms)) {
                quietestActiveDay = {
                  day: dayLabel,
                  meetings: meetingCount,
                  rooms: roomCount,
                  faculty: facultyCount
                };
              }
            } else {
              noActivityDays.push(dayLabel);
            }
          });

          const chartContainer = document.querySelector("#weeklyPressureChart");

          if (!chartContainer) {
            return;
          }

          if (weeklyPressureChart) {
            weeklyPressureChart.destroy();
          }

          chartContainer.innerHTML = "";

          const maxClasses = Math.max.apply(null, meetings.concat([0]));
          const maxResourceLoad = Math.max.apply(null, rooms.concat(faculty, [0]));
          const classAxisMax = Math.max(5, Math.ceil(maxClasses * 1.15));
          const resourceAxisMax = Math.max(5, Math.ceil(maxResourceLoad * 1.2));

          weeklyPressureChart = new ApexCharts(chartContainer, {
            chart: {
              type: "line",
              height: 340,
              toolbar: { show: false },
              zoom: { enabled: false }
            },
            series: [
              {
                name: "Scheduled Classes",
                type: "column",
                data: meetings
              },
              {
                name: "Rooms In Use",
                type: "line",
                data: rooms
              },
              {
                name: "Faculty Active",
                type: "line",
                data: faculty
              }
            ],
            colors: ["#03c3ec", "#696cff", "#71dd37"],
            stroke: {
              curve: "straight",
              width: [0, 3, 3]
            },
            fill: {
              type: ["solid", "solid", "solid"],
              opacity: [0.9, 1, 1]
            },
            markers: {
              size: 4,
              strokeWidth: 0,
              hover: {
                sizeOffset: 2
              }
            },
            dataLabels: {
              enabled: true,
              enabledOnSeries: [0],
              offsetY: -6,
              style: {
                fontSize: "10px",
                fontWeight: 600,
                colors: ["#233446"]
              },
              background: {
                enabled: false
              },
              formatter: function (value) {
                const count = Number(value) || 0;
                return count > 0 ? String(count) : "";
              }
            },
            plotOptions: {
              bar: {
                columnWidth: "48%",
                borderRadius: 6
              }
            },
            legend: {
              position: "top",
              horizontalAlign: "left",
              fontSize: "12px"
            },
            grid: {
              borderColor: "#eceef1",
              strokeDashArray: 4
            },
            xaxis: {
              categories: days,
              labels: {
                style: {
                  fontSize: "12px",
                  colors: "#697a8d"
                }
              }
            },
            yaxis: [
              {
                min: 0,
                max: classAxisMax,
                tickAmount: 5,
                forceNiceScale: true,
                title: {
                  text: "Scheduled Classes",
                  style: {
                    color: "#03c3ec"
                  }
                },
                labels: {
                  style: {
                    colors: "#697a8d"
                  }
                }
              },
              {
                seriesName: "Rooms In Use",
                opposite: true,
                min: 0,
                max: resourceAxisMax,
                tickAmount: 5,
                forceNiceScale: true,
                title: {
                  text: "Rooms / Faculty",
                  style: {
                    color: "#696cff"
                  }
                },
                labels: {
                  style: {
                    colors: "#697a8d"
                  }
                }
              },
              {
                seriesName: "Faculty Active",
                opposite: true,
                min: 0,
                max: resourceAxisMax,
                show: false
              }
            ],
            responsive: [
              {
                breakpoint: 768,
                options: {
                  chart: {
                    height: 360
                  },
                  plotOptions: {
                    bar: {
                      columnWidth: "58%"
                    }
                  }
                }
              }
            ],
            tooltip: {
              shared: true,
              intersect: false,
              y: {
                formatter: function (value, context) {
                  const labels = [" scheduled classes", " rooms in use", " faculty active"];
                  return value + labels[context.seriesIndex];
                }
              }
            }
          });

          weeklyPressureChart.render();

          if (!busiestDay || busiestDay.meetings === 0) {
            setWeeklyPressureAnalysisHtml('<div class="weekly-analysis-empty">Weekday activity exists in the chart, but no scheduled class pressure was detected.</div>');
            setInsight("weeklyPressureInsight", "No scheduled classes are mapped to weekdays yet.");
            return;
          }

          const analysisCards = [];

          analysisCards.push({
            label: "Peak Load",
            value: busiestDay.day,
            note:
              busiestDay.meetings +
              " scheduled class" +
              (busiestDay.meetings === 1 ? "" : "es") +
              " using " +
              busiestDay.rooms +
              " room" +
              (busiestDay.rooms === 1 ? "" : "s") +
              "."
          });

          if (tightestRoomDay) {
            analysisCards.push({
              label: "Room Pressure",
              value: tightestRoomDay.classesPerRoom.toFixed(1) + " classes/room",
              note:
                tightestRoomDay.day +
                " carries the highest class-to-room pressure with " +
                tightestRoomDay.meetings +
                " classes across " +
                tightestRoomDay.rooms +
                " rooms."
            });
          } else if (highestRoomDay && highestRoomDay.rooms > 0) {
            analysisCards.push({
              label: "Room Usage",
              value: highestRoomDay.day,
              note:
                highestRoomDay.rooms +
                " room" +
                (highestRoomDay.rooms === 1 ? "" : "s") +
                (highestRoomDay.rooms === 1 ? " is" : " are") +
                " active on this day."
            });
          }

          if (highestFacultyDay && highestFacultyDay.faculty > 0) {
            analysisCards.push({
              label: "Faculty Peak",
              value: highestFacultyDay.day,
              note:
                highestFacultyDay.faculty +
                " faculty member" +
                (highestFacultyDay.faculty === 1 ? " is" : "s are") +
                " active on the strongest faculty day."
            });
          } else {
            analysisCards.push({
              label: "Faculty Mapping",
              value: "Not mapped",
              note: "No faculty activity is currently linked to this weekly pressure view."
            });
          }

          if (noActivityDays.length > 0) {
            analysisCards.push({
              label: "Open Days",
              value: noActivityDays.join(", "),
              note: "These days have no classes, room usage, or faculty activity recorded."
            });
          } else if (quietestActiveDay) {
            analysisCards.push({
              label: "Lightest Day",
              value: quietestActiveDay.day,
              note:
                quietestActiveDay.meetings +
                " scheduled class" +
                (quietestActiveDay.meetings === 1 ? "" : "es") +
                " across " +
                quietestActiveDay.rooms +
                " room" +
                (quietestActiveDay.rooms === 1 ? "" : "s") +
                "."
            });
          }

          setWeeklyPressureAnalysisHtml(
            analysisCards.map(function (card) {
              return (
                '<div class="weekly-analysis-card">' +
                  '<span class="weekly-analysis-label">' + escapeHtml(card.label) + "</span>" +
                  '<p class="weekly-analysis-value">' + escapeHtml(card.value) + "</p>" +
                  '<p class="weekly-analysis-note">' + escapeHtml(card.note) + "</p>" +
                "</div>"
              );
            }).join("")
          );

          setInsight(
            "weeklyPressureInsight",
            "The bars show total class volume while the lines show how much room and faculty support that load needs. " +
              busiestDay.day +
              " carries the highest class load with " +
              busiestDay.meetings +
              " scheduled class" +
              (busiestDay.meetings === 1 ? "" : "es") +
              (noActivityDays.length > 0
                ? ", while " + noActivityDays.join(", ") + " show no recorded weekday activity."
                : quietestActiveDay && quietestActiveDay.day !== busiestDay.day
                  ? ", while " + quietestActiveDay.day + " is the lightest active day."
                  : ".")
          );
        }
        function updateDashboardCounts(data) {
          const programs = Number(data.programs) || 0;
          const faculty = Number(data.faculty) || 0;
          const offerings = Number(data.offering_items) || 0;
          const unscheduled = Number(data.unscheduled_classes) || 0;
          const scopeType = data && data.scope === "campus" && campusViewEnabled ? "campus" : "college";
          const scopeLabel = data && data.scope_label ? data.scope_label : (scopeType === "campus" ? <?php echo json_encode($_SESSION['campus_name'] ?? 'Current Campus'); ?> : <?php echo json_encode($_SESSION['college_name'] ?? 'Assigned College'); ?>);

          animateCount("countPrograms", programs);
          animateCount("countFaculty", faculty);
          animateCount("countProspectus", offerings);
          animateCount("countUnscheduled", unscheduled);

          document.getElementById("summaryPrograms").textContent = programs;
          document.getElementById("summaryFaculty").textContent = faculty;
          document.getElementById("summaryProspectus").textContent = offerings;
          document.getElementById("summaryUnscheduled").textContent = unscheduled;

          const unscheduledCard = document.getElementById("cardUnscheduled");
          const unscheduledNote = document.getElementById("unscheduledNote");
          const unscheduledBadge = document.getElementById("summaryUnscheduled");
          const schedulerStatusMessage = document.getElementById("schedulerStatusMessage");

          if (unscheduled > 0) {
            unscheduledCard.classList.add("kpi-warning-active");
            unscheduledNote.textContent = scopeType === "campus" ? "Need schedule assignment across campus" : "Needs schedule assignment";
            unscheduledBadge.className = "badge bg-danger rounded-pill";
            schedulerStatusMessage.textContent =
              scopeType === "campus"
                ? unscheduled + " classes still need time or room assignment across " + scopeLabel + "."
                : unscheduled + " classes still need time or room assignment.";
            return;
          }

          unscheduledCard.classList.remove("kpi-warning-active");
          unscheduledNote.textContent = scopeType === "campus" ? "All campus classes scheduled" : "All classes scheduled";
          unscheduledBadge.className = "badge bg-success rounded-pill";
          schedulerStatusMessage.textContent =
            scopeType === "campus"
              ? "Scheduling looks clear across " + scopeLabel + "."
              : "Scheduling looks clear for this workspace.";
        }

        function loadDashboardData() {
          applyDashboardScopeMeta({
            scope: dashboardScope,
            scope_label: dashboardScope === "campus"
              ? defaultCampusName
              : defaultCollegeName
          });

          if (schedulingProgressChart) {
            schedulingProgressChart.destroy();
            schedulingProgressChart = null;
          }
          if (scheduleHeatmapChart) {
            scheduleHeatmapChart.destroy();
            scheduleHeatmapChart = null;
          }
          if (weeklyPressureChart) {
            weeklyPressureChart.destroy();
            weeklyPressureChart = null;
          }

          setCountsLoadingState();
          setChartLoading("programSchedulingChart", "Loading program scheduling progress...");
          setChartLoading("weeklyPressureChart", "Loading weekday scheduling pressure...");
          setInsight(
            "programProgressInsight",
            dashboardScope === "campus"
              ? "Loading the comparison of total classes, scheduled classes, and unscheduled classes for each college in the current campus."
              : "Loading the comparison of total classes, scheduled classes, and unscheduled classes per program."
          );
          setInsight(
            "weeklyPressureInsight",
            dashboardScope === "campus"
              ? "Loading which weekdays are busiest across all colleges in the current campus."
              : "Loading which weekdays are busiest based on classes, rooms, and faculty activity."
          );
          setWeeklyPressureAnalysisLoadingState();

          loadScheduleHeatmap();

          $.ajax({
            url: "../backend/dashboard_scheduler_charts.php",
            type: "POST",
            dataType: "json",
            data: { scope: dashboardScope },
            success: function (data) {
              applyDashboardScopeMeta(data || {});
              renderProgramSchedulingChart(data.program_progress || []);
              renderWeeklyPressureChart(data.weekly_pressure || []);
            },
            error: function (xhr) {
              console.error("Scheduler charts error:", xhr.responseText);
              setChartFallback("programSchedulingChart", "Unable to load program scheduling progress.");
              setChartFallback("weeklyPressureChart", "Unable to load weekday scheduling pressure.");
              setInsight("programProgressInsight", "Program comparison data could not be loaded.");
              setWeeklyPressureAnalysisHtml('<div class="weekly-analysis-empty">Weekday load analysis could not be loaded.</div>');
              setInsight("weeklyPressureInsight", "Weekday load data could not be loaded.");
            }
          });

          $.ajax({
            url: "../backend/dashboard_counts.php",
            type: "POST",
            dataType: "json",
            data: { scope: dashboardScope },
            success: function (data) {
              updateDashboardCounts(data || {});
            },
            error: function (xhr) {
              console.error("Dashboard counts error:", xhr.responseText);
              document.getElementById("schedulerStatusMessage").textContent =
                "Unable to load dashboard counts.";
            }
          });
        }

        if (scheduleHeatmapRoomFilter) {
          scheduleHeatmapRoomFilter.addEventListener("change", function (event) {
            if (syncingScheduleHeatmapRoomFilter) {
              return;
            }

            event.preventDefault();
            scheduleHeatmapRoomSelection = scheduleHeatmapRoomFilter.value || "all";
            loadScheduleHeatmap();
          });
        }

        document.querySelectorAll("[data-dashboard-scope]").forEach(function (button) {
          button.addEventListener("click", function () {
            const requestedScope = button.getAttribute("data-dashboard-scope");
            if (requestedScope === dashboardScope) {
              return;
            }

            if (requestedScope === "campus" && !campusViewEnabled) {
              return;
            }

            dashboardScope = requestedScope;
            setScopeButtons();
            loadDashboardData();

            if (unscheduledModal && unscheduledModal.classList.contains("show")) {
              loadUnscheduledClassesList();
            }
          });
        });

        const unscheduledCard = document.getElementById("cardUnscheduled");
        if (unscheduledCard) {
          unscheduledCard.addEventListener("click", function () {
            openUnscheduledClassesModal();
          });

          unscheduledCard.addEventListener("keydown", function (event) {
            if (event.key !== "Enter" && event.key !== " ") {
              return;
            }

            event.preventDefault();
            openUnscheduledClassesModal();
          });
        }

        document.querySelectorAll(".btn-print-unscheduled").forEach(function (button) {
          button.addEventListener("click", function () {
            printUnscheduledClassesList();
          });
        });

        if (unscheduledModal) {
          unscheduledModal.addEventListener("hidden.bs.modal", function () {
            if (unscheduledClassesRequest && unscheduledClassesRequest.readyState !== 4) {
              unscheduledClassesRequest.abort();
            }
          });
        }

        setScopeButtons();
        loadDashboardData();
      });
    </script>
  </body>
</html>
