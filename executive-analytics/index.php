<?php
session_start();
ob_start();

require_once '../backend/db.php';
require_once '../backend/executive_analytics_helper.php';

function exec_portal_format_datetime(string $value): string
{
    $safeValue = trim($value);
    if ($safeValue === '') {
        return 'No recent activity yet';
    }

    $timestamp = strtotime($safeValue);
    return $timestamp ? date('M d, Y g:i A', $timestamp) : $safeValue;
}

function exec_portal_compact_label(array $row, string $kind): string
{
    if ($kind === 'college') {
        $code = trim((string)($row['college_code'] ?? ''));
        $name = trim((string)($row['college_name'] ?? ''));
        return $code !== '' ? $code : $name;
    }

    $code = trim((string)($row['campus_code'] ?? ''));
    $name = trim((string)($row['campus_name'] ?? ''));
    return $code !== '' ? $code : $name;
}

$viewer = synk_exec_analytics_require_login($conn);
$currentTerm = synk_fetch_current_academic_term($conn);
$currentAyId = (int)($currentTerm['ay_id'] ?? 0);
$currentSemester = (int)($currentTerm['semester'] ?? 0);
$termText = trim((string)($currentTerm['term_text'] ?? 'Current academic term'));

$campusCatalog = synk_exec_analytics_fetch_active_campuses($conn);
$validCampusIds = array_map(static function (array $row): int {
    return (int)($row['campus_id'] ?? 0);
}, $campusCatalog);
$requestedCampusId = isset($_GET['campus_id']) && ctype_digit((string)$_GET['campus_id'])
    ? (int)$_GET['campus_id']
    : 0;
$selectedCampusId = in_array($requestedCampusId, $validCampusIds, true) ? $requestedCampusId : 0;

$campusRows = synk_exec_analytics_fetch_campus_rows($conn, $currentAyId, $currentSemester);
$campusMetricsById = [];
foreach ($campusRows as $campusRow) {
    $campusMetricsById[(int)($campusRow['campus_id'] ?? 0)] = $campusRow;
}

$selectedCampusRow = $selectedCampusId > 0 ? synk_exec_analytics_find_campus_row($campusRows, $selectedCampusId) : null;
$summary = synk_exec_analytics_scope_summary($campusRows, $selectedCampusId);
$facultyHealthSummary = synk_exec_analytics_faculty_health_summary($conn, $currentAyId, $currentSemester, $selectedCampusId);
$collegeRows = synk_exec_analytics_fetch_college_rows($conn, $currentAyId, $currentSemester, $selectedCampusId);
$workflowStatusRows = synk_exec_analytics_fetch_workflow_status_rows($conn, $currentAyId, $currentSemester, $selectedCampusId);

$activeSignalCampuses = count(array_filter($campusRows, static function (array $row): bool {
    return ((int)($row['sections'] ?? 0)) > 0
        || ((int)($row['schedules'] ?? 0)) > 0
        || ((int)($row['workflows'] ?? 0)) > 0;
}));

$scopeTitle = $selectedCampusRow
    ? trim((string)($selectedCampusRow['campus_name'] ?? 'Campus'))
    : 'University Academic Monitoring';
$scopeDisplayName = $selectedCampusRow
    ? trim((string)($selectedCampusRow['campus_name'] ?? 'Selected campus'))
    : 'All campuses';
$heroNarrative = $selectedCampusRow
    ? 'This campus view turns the main cards into labeled college signals and keeps every chart focused on ' . $scopeDisplayName . ' for faster executive drill-down.'
    : 'This executive dashboard starts with the complete university picture, then lets you move campus by campus from the sidebar without losing the institutional context.';
$coverageChartNote = $selectedCampusRow
    ? 'Coverage by college inside ' . $scopeDisplayName . ', based on offerings that already carry live schedule rows.'
    : 'A clean read of how much of the offering footprint already carries live schedule rows.';
$footprintChartNote = $selectedCampusRow
    ? 'Sections, offerings, and schedules for each college inside ' . $scopeDisplayName . ' in one stacked operational view.'
    : 'Sections, offerings, and schedule counts in one stacked view to show where operational weight is sitting.';
$resourceChartNote = $selectedCampusRow
    ? 'Programs, faculty, and rooms aligned per college so ' . $scopeDisplayName . ' resource balance is easy to compare.'
    : 'Programs, faculty, and rooms aligned on the same axis for fast resource comparison.';
$workflowChartTitle = $selectedCampusRow
    ? ($scopeDisplayName . ' Workflow Status')
    : 'Enrollment Workflow Status';
$workflowChartNote = $selectedCampusRow
    ? 'Workflow distribution for ' . $scopeDisplayName . ' so executives can spot where campus processing is concentrated.'
    : 'A compact executive snapshot of current enrollment workflow distribution.';

$campusTileRows = [];
foreach ($campusCatalog as $campus) {
    $campusId = (int)($campus['campus_id'] ?? 0);
    $campusTileRows[] = $campusMetricsById[$campusId] ?? [
        'campus_id' => $campusId,
        'campus_code' => (string)($campus['campus_code'] ?? ''),
        'campus_name' => (string)($campus['campus_name'] ?? ''),
        'schedules' => 0,
        'faculty' => 0,
        'schedule_coverage' => 0,
        'projected_enrollees' => 0,
    ];
}

$chartContextKind = $selectedCampusRow ? 'college' : 'campus';
$chartRows = $selectedCampusRow ? array_slice($collegeRows, 0, 10) : $campusTileRows;
if (empty($chartRows) && $selectedCampusRow) {
    $chartRows = [$selectedCampusRow];
    $chartContextKind = 'campus';
}

$chartLabels = [];
$coverageSeries = [];
$sectionsSeries = [];
$offeringsSeries = [];
$schedulesSeries = [];
$programsSeries = [];
$facultySeries = [];
$roomsSeries = [];

foreach ($chartRows as $chartRow) {
    $chartLabels[] = exec_portal_compact_label($chartRow, $chartContextKind);
    $coverageSeries[] = (float)($chartRow['schedule_coverage'] ?? 0);
    $sectionsSeries[] = (int)($chartRow['sections'] ?? 0);
    $offeringsSeries[] = (int)($chartRow['offerings'] ?? 0);
    $schedulesSeries[] = (int)($chartRow['schedules'] ?? 0);
    $programsSeries[] = (int)($chartRow['programs'] ?? 0);
    $facultySeries[] = (int)($chartRow['faculty'] ?? 0);
    $roomsSeries[] = (int)($chartRow['rooms'] ?? 0);
}

$workflowLabels = [];
$workflowSeries = [];
foreach ($workflowStatusRows as $workflowStatusRow) {
    $palette = synk_exec_analytics_status_palette((string)($workflowStatusRow['workflow_status'] ?? ''));
    $workflowLabels[] = (string)($palette['label'] ?? 'Unknown');
    $workflowSeries[] = (int)($workflowStatusRow['total_rows'] ?? 0);
}

$latestActivityText = exec_portal_format_datetime((string)($summary['latest_activity_at'] ?? ''));

$execNavbarTitle = $scopeTitle;
$execNavbarSubtitle = $selectedCampusRow ? 'Campus-level executive monitoring' : 'University-wide executive monitoring';
$execNavbarTermText = $termText;
$execNavbarViewer = $viewer;
$execNavbarShowAllCampusesLink = $selectedCampusRow !== null;

$sidebarCampusCatalog = $campusCatalog;
$sidebarCampusMetricsById = $campusMetricsById;
$sidebarSelectedCampusId = $selectedCampusId;
$sidebarSummary = $summary;
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
    <title><?php echo synk_exec_analytics_h($scopeTitle); ?></title>
    <meta name="description" content="Executive analytics for all campuses and campus-level command views." />

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet" />

    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" class="template-customizer-core-css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/apex-charts/apex-charts.css" />
    <link rel="stylesheet" href="custom_css.css" />
  </head>

  <body class="exec-dashboard-page">
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <?php include 'sidebar.php'; ?>

        <div class="layout-page">
          <?php include 'navbar.php'; ?>

          <div class="content-wrapper">
            <div class="container-xxl flex-grow-1 container-p-y">
              <?php if ($currentAyId <= 0 || $currentSemester <= 0): ?>
                <div class="alert alert-warning mb-4" role="alert">
                  Current academic term is not configured yet. Executive analytics cards and charts stay available, but term-driven counts will remain at zero until Academic Settings is set.
                </div>
              <?php endif; ?>

              <section class="exec-hero mb-4">
                <div class="exec-hero-grid">
                  <div>
                    <span class="exec-hero-kicker">Executive Signal Layer</span>
                    <h1 class="exec-hero-title"><?php echo synk_exec_analytics_h($scopeTitle); ?></h1>
                    <p class="exec-hero-copy"><?php echo synk_exec_analytics_h($heroNarrative); ?></p>

                    <div class="exec-hero-pill-row">
                      <span class="exec-hero-pill">
                        <i class="bx bx-buildings"></i>
                        <span><?php echo number_format((int)($summary['campus_count'] ?? count($campusCatalog))); ?> campuses in view</span>
                      </span>
                      <span class="exec-hero-pill">
                        <i class="bx bx-broadcast"></i>
                        <span><?php echo number_format($activeSignalCampuses); ?> campuses with live signal</span>
                      </span>
                      <span class="exec-hero-pill">
                        <i class="bx bx-time-five"></i>
                        <span><?php echo synk_exec_analytics_h($latestActivityText); ?></span>
                      </span>
                    </div>
                  </div>

                  <div class="exec-hero-card">
                    <div class="exec-hero-card-grid">
                      <div>
                        <div class="exec-hero-card-label">Faculty Overloaded</div>
                        <div class="exec-hero-card-value"><?php echo synk_exec_analytics_number((int)($facultyHealthSummary['overload_count'] ?? 0)); ?></div>
                        <div class="exec-hero-card-note">Faculty above the normal load for the selected term.</div>
                      </div>
                      <div>
                        <div class="exec-hero-card-label">Faculty Underload</div>
                        <div class="exec-hero-card-value"><?php echo synk_exec_analytics_number((int)($facultyHealthSummary['underload_count'] ?? 0)); ?></div>
                        <div class="exec-hero-card-note">Faculty below the normal load for the selected term.</div>
                      </div>
                      <div>
                        <div class="exec-hero-card-label">Faculty Normal Load</div>
                        <div class="exec-hero-card-value"><?php echo synk_exec_analytics_number((int)($facultyHealthSummary['normal_count'] ?? 0)); ?></div>
                        <div class="exec-hero-card-note">Faculty matching the expected normal load rule.</div>
                      </div>
                      <div>
                        <div class="exec-hero-card-label">COS Faculty</div>
                        <div class="exec-hero-card-value"><?php echo synk_exec_analytics_number((int)($facultyHealthSummary['cos_count'] ?? 0)); ?></div>
                        <div class="exec-hero-card-note">Faculty classified as contract of service.</div>
                      </div>
                    </div>
                  </div>
                </div>
              </section>

              <?php if ($selectedCampusRow): ?>
                <section class="exec-surface-card card mb-4">
                  <div class="card-body">
                    <div class="exec-surface-header mb-3">
                      <div>
                        <span class="exec-surface-kicker">College Signal Cards</span>
                        <h2 class="exec-surface-title"><?php echo synk_exec_analytics_h($scopeDisplayName); ?> College Command Cards</h2>
                        <p class="exec-surface-note">Each card shows the important college labels for the selected campus so the drill-down view feels immediate and actionable.</p>
                      </div>
                      <span class="exec-surface-badge"><?php echo count($collegeRows); ?> colleges</span>
                    </div>

                    <?php if (!empty($collegeRows)): ?>
                      <div class="exec-college-grid">
                        <?php foreach ($collegeRows as $collegeIndex => $collegeCardRow): ?>
                          <?php
                            $collegeToneMap = ['primary', 'mint', 'amber', 'coral'];
                            $collegeTone = $collegeToneMap[$collegeIndex % count($collegeToneMap)];
                            $collegeCode = trim((string)($collegeCardRow['college_code'] ?? ''));
                            $collegeName = trim((string)($collegeCardRow['college_name'] ?? 'College'));
                          ?>
                          <article class="exec-college-card tone-<?php echo synk_exec_analytics_h($collegeTone); ?>">
                            <div class="exec-college-card-top">
                              <span class="exec-college-code"><?php echo synk_exec_analytics_h($collegeCode !== '' ? $collegeCode : 'COL'); ?></span>
                              <span class="exec-coverage-chip"><?php echo number_format((float)($collegeCardRow['schedule_coverage'] ?? 0), 1); ?>% coverage</span>
                            </div>

                            <h3 class="exec-college-title"><?php echo synk_exec_analytics_h($collegeName); ?></h3>
                            <div class="exec-college-chip-grid">
                              <div class="exec-college-chip">
                                <span class="exec-college-chip-label">Programs</span>
                                <strong><?php echo synk_exec_analytics_number((int)($collegeCardRow['programs'] ?? 0)); ?></strong>
                              </div>
                              <div class="exec-college-chip">
                                <span class="exec-college-chip-label">Sections</span>
                                <strong><?php echo synk_exec_analytics_number((int)($collegeCardRow['sections'] ?? 0)); ?></strong>
                              </div>
                              <div class="exec-college-chip">
                                <span class="exec-college-chip-label">Offerings</span>
                                <strong><?php echo synk_exec_analytics_number((int)($collegeCardRow['offerings'] ?? 0)); ?></strong>
                              </div>
                              <div class="exec-college-chip">
                                <span class="exec-college-chip-label">Schedules</span>
                                <strong><?php echo synk_exec_analytics_number((int)($collegeCardRow['schedules'] ?? 0)); ?></strong>
                              </div>
                              <div class="exec-college-chip">
                                <span class="exec-college-chip-label">Faculty</span>
                                <strong><?php echo synk_exec_analytics_number((int)($collegeCardRow['faculty'] ?? 0)); ?></strong>
                              </div>
                              <div class="exec-college-chip">
                                <span class="exec-college-chip-label">Rooms</span>
                                <strong><?php echo synk_exec_analytics_number((int)($collegeCardRow['rooms'] ?? 0)); ?></strong>
                              </div>
                            </div>
                          </article>
                        <?php endforeach; ?>
                      </div>
                    <?php else: ?>
                      <div class="exec-empty-state">No college analytics are available for the selected campus in the active term yet.</div>
                    <?php endif; ?>
                  </div>
                </section>
              <?php else: ?>
                <div class="row g-4 exec-metric-grid mb-4">
                  <div class="col-md-6 col-xl-3">
                    <div class="card exec-metric-card">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                          <div>
                            <div class="exec-metric-label">Colleges</div>
                            <div class="exec-metric-value"><?php echo synk_exec_analytics_number((int)($summary['colleges'] ?? 0)); ?></div>
                            <div class="exec-metric-note">Active academic units under the current scope.</div>
                          </div>
                          <span class="exec-metric-icon"><i class="bx bx-library"></i></span>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-md-6 col-xl-3">
                    <div class="card exec-metric-card mint">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                          <div>
                            <div class="exec-metric-label">Programs</div>
                            <div class="exec-metric-value"><?php echo synk_exec_analytics_number((int)($summary['programs'] ?? 0)); ?></div>
                            <div class="exec-metric-note">Active program footprint used by the analytics layer.</div>
                          </div>
                          <span class="exec-metric-icon"><i class="bx bx-book-content"></i></span>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-md-6 col-xl-3">
                    <div class="card exec-metric-card amber">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                          <div>
                            <div class="exec-metric-label">Sections</div>
                            <div class="exec-metric-value"><?php echo synk_exec_analytics_number((int)($summary['sections'] ?? 0)); ?></div>
                            <div class="exec-metric-note">Active term sections feeding the academic workload.</div>
                          </div>
                          <span class="exec-metric-icon"><i class="bx bx-grid-alt"></i></span>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-md-6 col-xl-3">
                    <div class="card exec-metric-card coral">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                          <div>
                            <div class="exec-metric-label">Schedules</div>
                            <div class="exec-metric-value"><?php echo synk_exec_analytics_number((int)($summary['schedules'] ?? 0)); ?></div>
                            <div class="exec-metric-note">Lecture and laboratory schedule rows across the scope.</div>
                          </div>
                          <span class="exec-metric-icon"><i class="bx bx-time-five"></i></span>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-md-6 col-xl-3">
                    <div class="card exec-metric-card">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                          <div>
                            <div class="exec-metric-label">Offerings</div>
                            <div class="exec-metric-value"><?php echo synk_exec_analytics_number((int)($summary['offerings'] ?? 0)); ?></div>
                            <div class="exec-metric-note">Generated active-term offerings ready for monitoring.</div>
                          </div>
                          <span class="exec-metric-icon"><i class="bx bx-layer-plus"></i></span>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-md-6 col-xl-3">
                    <div class="card exec-metric-card mint">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                          <div>
                            <div class="exec-metric-label">Rooms</div>
                            <div class="exec-metric-value"><?php echo synk_exec_analytics_number((int)($summary['rooms'] ?? 0)); ?></div>
                            <div class="exec-metric-note">Active room inventory available to the current term.</div>
                          </div>
                          <span class="exec-metric-icon"><i class="bx bx-door-open"></i></span>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-md-6 col-xl-3">
                    <div class="card exec-metric-card coral">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start gap-3">
                          <div>
                            <div class="exec-metric-label">Activity Score</div>
                            <div class="exec-metric-value"><?php echo synk_exec_analytics_number((int)($summary['activity_score'] ?? 0)); ?></div>
                            <div class="exec-metric-note">Composite operational energy across the selected scope.</div>
                          </div>
                          <span class="exec-metric-icon"><i class="bx bx-pulse"></i></span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endif; ?>

              <div class="row g-4 mb-4">
                <div class="col-xl-6">
                  <section class="exec-surface-card card exec-chart-shell h-100">
                    <div class="card-body">
                      <div class="exec-surface-header mb-3">
                        <div>
                          <span class="exec-surface-kicker">Coverage Map</span>
                          <h2 class="exec-surface-title"><?php echo $selectedCampusRow ? 'College Schedule Coverage' : 'Campus Schedule Coverage'; ?></h2>
                          <p class="exec-surface-note"><?php echo synk_exec_analytics_h($coverageChartNote); ?></p>
                        </div>
                        <span class="exec-surface-badge"><?php echo count($chartLabels); ?> <?php echo $selectedCampusRow ? 'colleges' : 'campuses'; ?></span>
                      </div>
                      <?php if (!empty($chartLabels)): ?>
                        <div id="execCoverageChart" class="exec-chart-target"></div>
                      <?php else: ?>
                        <div class="exec-empty-state">Coverage visualization will appear as soon as the current scope has active academic records.</div>
                      <?php endif; ?>
                    </div>
                  </section>
                </div>

                <div class="col-xl-6">
                  <section class="exec-surface-card card exec-chart-shell h-100">
                    <div class="card-body">
                      <div class="exec-surface-header mb-3">
                        <div>
                          <span class="exec-surface-kicker">Academic Footprint</span>
                          <h2 class="exec-surface-title"><?php echo $selectedCampusRow ? 'College Activity Stack' : 'Campus Activity Stack'; ?></h2>
                          <p class="exec-surface-note"><?php echo synk_exec_analytics_h($footprintChartNote); ?></p>
                        </div>
                      </div>
                      <?php if (!empty($chartLabels)): ?>
                        <div id="execFootprintChart" class="exec-chart-target"></div>
                      <?php else: ?>
                        <div class="exec-empty-state">Footprint visualization will appear when current-term section and offering rows are available.</div>
                      <?php endif; ?>
                    </div>
                  </section>
                </div>
              </div>

              <div class="row g-4 mb-4">
                <div class="col-xl-7">
                  <section class="exec-surface-card card exec-chart-shell h-100">
                    <div class="card-body">
                      <div class="exec-surface-header mb-3">
                        <div>
                          <span class="exec-surface-kicker">Resource Balance</span>
                          <h2 class="exec-surface-title"><?php echo $selectedCampusRow ? 'College Resource Grid' : 'Campus Resource Grid'; ?></h2>
                          <p class="exec-surface-note"><?php echo synk_exec_analytics_h($resourceChartNote); ?></p>
                        </div>
                      </div>
                      <?php if (!empty($chartLabels)): ?>
                        <div id="execResourceChart" class="exec-chart-target"></div>
                      <?php else: ?>
                        <div class="exec-empty-state">Resource comparisons will appear once the selected scope has active structural records.</div>
                      <?php endif; ?>
                    </div>
                  </section>
                </div>

                <div class="col-xl-5">
                  <section class="exec-surface-card card exec-chart-shell h-100">
                    <div class="card-body">
                      <div class="exec-surface-header mb-3">
                        <div>
                          <span class="exec-surface-kicker">Workflow Pulse</span>
                          <h2 class="exec-surface-title"><?php echo synk_exec_analytics_h($workflowChartTitle); ?></h2>
                          <p class="exec-surface-note"><?php echo synk_exec_analytics_h($workflowChartNote); ?></p>
                        </div>
                      </div>
                      <?php if (!empty($workflowSeries)): ?>
                        <div id="execWorkflowChart" class="exec-chart-target"></div>
                      <?php else: ?>
                        <div class="exec-empty-state">No enrollment workflow rows are available for the selected scope in the active term yet.</div>
                      <?php endif; ?>
                    </div>
                  </section>
                </div>
              </div>

              <section class="exec-surface-card card mb-4">
                <div class="card-body">
                  <div class="exec-surface-header mb-3">
                    <div>
                      <span class="exec-surface-kicker">Command Table</span>
                      <h2 class="exec-surface-title"><?php echo $selectedCampusRow ? 'College Intelligence Table' : 'Campus Intelligence Table'; ?></h2>
                      <p class="exec-surface-note"><?php echo synk_exec_analytics_h($selectedCampusRow ? ('College-by-college operating detail for ' . $scopeDisplayName . ' in the active term.') : 'Every campus in one executive table for quick comparison of coverage, load, and demand.'); ?></p>
                    </div>
                    <span class="exec-surface-badge"><?php echo $selectedCampusRow ? count($collegeRows) : count($campusTileRows); ?> rows</span>
                  </div>

                  <div class="exec-table-shell">
                    <table class="exec-table">
                      <thead>
                        <?php if ($selectedCampusRow): ?>
                          <tr>
                            <th>College</th>
                            <th>Programs</th>
                            <th>Sections</th>
                            <th>Offerings</th>
                            <th>Schedules</th>
                            <th>Faculty</th>
                            <th>Rooms</th>
                            <th>Workflows</th>
                            <th>Coverage</th>
                          </tr>
                        <?php else: ?>
                          <tr>
                            <th>Campus</th>
                            <th>Colleges</th>
                            <th>Programs</th>
                            <th>Sections</th>
                            <th>Offerings</th>
                            <th>Schedules</th>
                            <th>Faculty</th>
                            <th>Rooms</th>
                            <th>Coverage</th>
                          </tr>
                        <?php endif; ?>
                      </thead>
                      <tbody>
                        <?php if ($selectedCampusRow): ?>
                          <?php if (!empty($collegeRows)): ?>
                            <?php foreach ($collegeRows as $collegeTableRow): ?>
                              <tr>
                                <td>
                                  <div class="exec-table-campus-badge">
                                    <span><?php echo synk_exec_analytics_h((string)($collegeTableRow['college_code'] ?? 'COL')); ?></span>
                                    <div>
                                      <div class="exec-table-name"><?php echo synk_exec_analytics_h((string)($collegeTableRow['college_name'] ?? 'College')); ?></div>
                                      <span class="exec-table-subtext"><?php echo synk_exec_analytics_h($scopeDisplayName); ?></span>
                                    </div>
                                  </div>
                                </td>
                                <td><?php echo synk_exec_analytics_number((int)($collegeTableRow['programs'] ?? 0)); ?></td>
                                <td><?php echo synk_exec_analytics_number((int)($collegeTableRow['sections'] ?? 0)); ?></td>
                                <td><?php echo synk_exec_analytics_number((int)($collegeTableRow['offerings'] ?? 0)); ?></td>
                                <td><?php echo synk_exec_analytics_number((int)($collegeTableRow['schedules'] ?? 0)); ?></td>
                                <td><?php echo synk_exec_analytics_number((int)($collegeTableRow['faculty'] ?? 0)); ?></td>
                                <td><?php echo synk_exec_analytics_number((int)($collegeTableRow['rooms'] ?? 0)); ?></td>
                                <td><?php echo synk_exec_analytics_number((int)($collegeTableRow['workflows'] ?? 0)); ?></td>
                                <td><span class="exec-coverage-chip"><?php echo number_format((float)($collegeTableRow['schedule_coverage'] ?? 0), 1); ?>%</span></td>
                              </tr>
                            <?php endforeach; ?>
                          <?php else: ?>
                            <tr>
                              <td colspan="9">No college analytics are available for the selected campus in the active term yet.</td>
                            </tr>
                          <?php endif; ?>
                        <?php else: ?>
                          <?php foreach ($campusTileRows as $campusTableRow): ?>
                            <tr>
                              <td>
                                <div class="exec-table-campus-badge">
                                  <span><?php echo synk_exec_analytics_h((string)($campusTableRow['campus_code'] ?? '')); ?></span>
                                  <div>
                                    <div class="exec-table-name"><?php echo synk_exec_analytics_h((string)($campusTableRow['campus_name'] ?? 'Campus')); ?></div>
                                    <span class="exec-table-subtext"><?php echo exec_portal_format_datetime((string)($campusTableRow['latest_activity_at'] ?? '')); ?></span>
                                  </div>
                                </div>
                              </td>
                              <td><?php echo synk_exec_analytics_number((int)($campusTableRow['colleges'] ?? 0)); ?></td>
                              <td><?php echo synk_exec_analytics_number((int)($campusTableRow['programs'] ?? 0)); ?></td>
                              <td><?php echo synk_exec_analytics_number((int)($campusTableRow['sections'] ?? 0)); ?></td>
                              <td><?php echo synk_exec_analytics_number((int)($campusTableRow['offerings'] ?? 0)); ?></td>
                              <td><?php echo synk_exec_analytics_number((int)($campusTableRow['schedules'] ?? 0)); ?></td>
                              <td><?php echo synk_exec_analytics_number((int)($campusTableRow['faculty'] ?? 0)); ?></td>
                              <td><?php echo synk_exec_analytics_number((int)($campusTableRow['rooms'] ?? 0)); ?></td>
                              <td><span class="exec-coverage-chip"><?php echo number_format((float)($campusTableRow['schedule_coverage'] ?? 0), 1); ?>%</span></td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </section>

              <?php if (false): ?>
              <section class="exec-surface-card card mb-4">
                <div class="card-body">
                  <div class="exec-surface-header mb-3">
                    <div>
                      <span class="exec-surface-kicker"><?php echo $selectedCampusRow ? 'College Detail' : 'Institution Spotlight'; ?></span>
                      <h2 class="exec-surface-title"><?php echo $selectedCampusRow ? 'College Spotlight Table' : 'Most Active Colleges Across Campuses'; ?></h2>
                      <p class="exec-surface-note"><?php echo synk_exec_analytics_h($scopeSubtitle); ?></p>
                    </div>
                    <span class="exec-surface-badge"><?php echo count($collegeTableRows); ?> rows shown</span>
                  </div>

                  <div class="exec-table-shell">
                    <table class="exec-table">
                      <thead>
                        <tr>
                          <th>College</th>
                          <th>Programs</th>
                          <th>Sections</th>
                          <th>Offerings</th>
                          <th>Schedules</th>
                          <th>Faculty</th>
                          <th>Rooms</th>
                          <th>Students</th>
                          <th>Workflows</th>
                          <th>Coverage</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (!empty($collegeTableRows)): ?>
                          <?php foreach ($collegeTableRows as $collegeTableRow): ?>
                            <tr>
                              <td>
                                <div class="exec-table-name">
                                  <?php echo synk_exec_analytics_h((string)($collegeTableRow['college_name'] ?? 'College')); ?>
                                </div>
                                <span class="exec-table-subtext">
                                  <?php echo synk_exec_analytics_h((string)($collegeTableRow['campus_code'] ?? '')); ?>
                                  <?php if (!$selectedCampusRow): ?>
                                    · <?php echo synk_exec_analytics_h((string)($collegeTableRow['campus_name'] ?? '')); ?>
                                  <?php endif; ?>
                                </span>
                              </td>
                              <td><?php echo synk_exec_analytics_number((int)($collegeTableRow['programs'] ?? 0)); ?></td>
                              <td><?php echo synk_exec_analytics_number((int)($collegeTableRow['sections'] ?? 0)); ?></td>
                              <td><?php echo synk_exec_analytics_number((int)($collegeTableRow['offerings'] ?? 0)); ?></td>
                              <td><?php echo synk_exec_analytics_number((int)($collegeTableRow['schedules'] ?? 0)); ?></td>
                              <td><?php echo synk_exec_analytics_number((int)($collegeTableRow['faculty'] ?? 0)); ?></td>
                              <td><?php echo synk_exec_analytics_number((int)($collegeTableRow['rooms'] ?? 0)); ?></td>
                              <td><?php echo synk_exec_analytics_number((int)($collegeTableRow['students'] ?? 0)); ?></td>
                              <td><?php echo synk_exec_analytics_number((int)($collegeTableRow['workflows'] ?? 0)); ?></td>
                              <td><span class="exec-coverage-chip"><?php echo number_format((float)($collegeTableRow['schedule_coverage'] ?? 0), 1); ?>%</span></td>
                            </tr>
                          <?php endforeach; ?>
                        <?php else: ?>
                          <tr>
                            <td colspan="10">No college analytics are available for the selected scope yet.</td>
                          </tr>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </section>

              <div class="row g-4 mb-4">
                <div class="col-xl-6">
                  <section class="exec-surface-card card h-100">
                    <div class="card-body">
                      <div class="exec-surface-header mb-3">
                        <div>
                          <span class="exec-surface-kicker">Latest Movement</span>
                          <h2 class="exec-surface-title">Recent Enrollment Activity</h2>
                          <p class="exec-surface-note">The latest workflow rows that executives can review without leaving the command deck.</p>
                        </div>
                      </div>

                      <div class="exec-table-shell">
                        <table class="exec-table">
                          <thead>
                            <tr>
                              <th>Reference</th>
                              <th>Student / Program</th>
                              <th>Campus</th>
                              <th>Status</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php if (!empty($latestEnrollmentRows)): ?>
                              <?php foreach ($latestEnrollmentRows as $latestEnrollmentRow): ?>
                                <?php $statusPalette = synk_exec_analytics_status_palette((string)($latestEnrollmentRow['workflow_status'] ?? '')); ?>
                                <tr>
                                  <td>
                                    <div class="exec-table-name"><?php echo synk_exec_analytics_h((string)($latestEnrollmentRow['enrollment_reference'] ?? '')); ?></div>
                                    <span class="exec-table-subtext"><?php echo synk_exec_analytics_h(exec_portal_format_datetime((string)($latestEnrollmentRow['updated_at'] ?? ''))); ?></span>
                                  </td>
                                  <td>
                                    <div class="exec-table-name"><?php echo synk_exec_analytics_h((string)($latestEnrollmentRow['student_name'] ?? 'Enrollment draft')); ?></div>
                                    <span class="exec-table-subtext">
                                      <?php echo synk_exec_analytics_h(trim((string)($latestEnrollmentRow['program_code'] ?? '') . ' ' . (string)($latestEnrollmentRow['program_name'] ?? ''))); ?>
                                    </span>
                                  </td>
                                  <td>
                                    <div class="exec-table-name"><?php echo synk_exec_analytics_h((string)($latestEnrollmentRow['campus_name'] ?? '')); ?></div>
                                    <span class="exec-table-subtext"><?php echo synk_exec_analytics_h((string)($latestEnrollmentRow['college_name'] ?? '')); ?></span>
                                  </td>
                                  <td><span class="exec-status-pill <?php echo synk_exec_analytics_h((string)($statusPalette['class'] ?? 'status-generic')); ?>"><?php echo synk_exec_analytics_h((string)($statusPalette['label'] ?? 'Unknown')); ?></span></td>
                                </tr>
                              <?php endforeach; ?>
                            <?php else: ?>
                              <tr>
                                <td colspan="4">No enrollment activity is available for the selected scope in the current term yet.</td>
                              </tr>
                            <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </section>
                </div>

                <div class="col-xl-6">
                  <section class="exec-surface-card card h-100">
                    <div class="card-body">
                      <div class="exec-surface-header mb-3">
                        <div>
                          <span class="exec-surface-kicker">Live Source Tables</span>
                          <h2 class="exec-surface-title">Analytics Source Readiness</h2>
                          <p class="exec-surface-note">This block shows the tables feeding the portal, including the new table introduced for executive access.</p>
                        </div>
                      </div>

                      <div class="exec-table-shell">
                        <table class="exec-table">
                          <thead>
                            <tr>
                              <th>Table</th>
                              <th>Purpose</th>
                              <th>Rows</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($sourceTableRows as $sourceTableRow): ?>
                              <tr>
                                <td>
                                  <div class="exec-table-name"><?php echo synk_exec_analytics_h((string)($sourceTableRow['table_name'] ?? '')); ?></div>
                                  <span class="exec-table-subtext">
                                    <span class="exec-source-chip <?php echo !empty($sourceTableRow['is_new']) ? 'new' : ''; ?>">
                                      <?php echo !empty($sourceTableRow['is_new']) ? 'New' : 'Existing'; ?>
                                    </span>
                                  </span>
                                </td>
                                <td><?php echo synk_exec_analytics_h((string)($sourceTableRow['description'] ?? '')); ?></td>
                                <td><?php echo !empty($sourceTableRow['exists']) ? synk_exec_analytics_number((int)($sourceTableRow['row_count'] ?? 0)) : 'Missing'; ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </section>
                </div>
              </div>
              <?php endif; ?>
            </div>

            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>
    </div>

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>
    <script src="../assets/js/main.js"></script>

    <script>
      const execChartLabels = <?php echo json_encode($chartLabels); ?>;
      const execCoverageSeries = <?php echo json_encode($coverageSeries); ?>;
      const execSectionsSeries = <?php echo json_encode($sectionsSeries); ?>;
      const execOfferingsSeries = <?php echo json_encode($offeringsSeries); ?>;
      const execSchedulesSeries = <?php echo json_encode($schedulesSeries); ?>;
      const execProgramsSeries = <?php echo json_encode($programsSeries); ?>;
      const execFacultySeries = <?php echo json_encode($facultySeries); ?>;
      const execRoomsSeries = <?php echo json_encode($roomsSeries); ?>;
      const execWorkflowLabels = <?php echo json_encode($workflowLabels); ?>;
      const execWorkflowSeries = <?php echo json_encode($workflowSeries); ?>;

      const execChartColors = {
        primary: "#1f7af6",
        mint: "#15b985",
        coral: "#ff7c5c",
        amber: "#ffb545",
        slate: "#7c95ad"
      };

      if (document.querySelector("#execCoverageChart") && execChartLabels.length) {
        new ApexCharts(document.querySelector("#execCoverageChart"), {
          chart: {
            type: "bar",
            height: 310,
            toolbar: { show: false }
          },
          series: [{
            name: "Coverage",
            data: execCoverageSeries
          }],
          plotOptions: {
            bar: {
              horizontal: true,
              borderRadius: 8,
              barHeight: "58%"
            }
          },
          dataLabels: {
            enabled: true,
            formatter: value => `${Number(value).toFixed(1)}%`
          },
          xaxis: {
            categories: execChartLabels,
            max: 100,
            labels: {
              formatter: value => `${value}%`
            }
          },
          colors: [execChartColors.primary],
          grid: {
            borderColor: "#e4edf4",
            strokeDashArray: 4
          },
          tooltip: {
            y: {
              formatter: value => `${Number(value).toFixed(1)}% scheduled coverage`
            }
          }
        }).render();
      }

      if (document.querySelector("#execFootprintChart") && execChartLabels.length) {
        new ApexCharts(document.querySelector("#execFootprintChart"), {
          chart: {
            type: "bar",
            stacked: true,
            height: 310,
            toolbar: { show: false }
          },
          series: [
            { name: "Sections", data: execSectionsSeries },
            { name: "Offerings", data: execOfferingsSeries },
            { name: "Schedules", data: execSchedulesSeries }
          ],
          colors: [execChartColors.mint, execChartColors.primary, execChartColors.coral],
          plotOptions: {
            bar: {
              borderRadius: 8,
              columnWidth: "48%"
            }
          },
          xaxis: {
            categories: execChartLabels
          },
          legend: {
            position: "top",
            horizontalAlign: "left"
          },
          grid: {
            borderColor: "#e4edf4",
            strokeDashArray: 4
          }
        }).render();
      }

      if (document.querySelector("#execResourceChart") && execChartLabels.length) {
        new ApexCharts(document.querySelector("#execResourceChart"), {
          chart: {
            type: "line",
            height: 310,
            toolbar: { show: false }
          },
          stroke: {
            width: [3, 3, 3],
            curve: "smooth"
          },
          markers: {
            size: 4,
            strokeWidth: 0
          },
          series: [
            { name: "Programs", data: execProgramsSeries },
            { name: "Faculty", data: execFacultySeries },
            { name: "Rooms", data: execRoomsSeries }
          ],
          colors: [execChartColors.primary, execChartColors.mint, execChartColors.amber],
          xaxis: {
            categories: execChartLabels
          },
          grid: {
            borderColor: "#e4edf4",
            strokeDashArray: 4
          },
          legend: {
            position: "top",
            horizontalAlign: "left"
          }
        }).render();
      }

      if (document.querySelector("#execWorkflowChart") && execWorkflowSeries.length) {
        new ApexCharts(document.querySelector("#execWorkflowChart"), {
          chart: {
            type: "donut",
            height: 310
          },
          series: execWorkflowSeries,
          labels: execWorkflowLabels,
          colors: [execChartColors.primary, execChartColors.mint, execChartColors.amber, execChartColors.coral, execChartColors.slate],
          legend: {
            position: "bottom"
          },
          dataLabels: {
            enabled: true,
            formatter: value => `${Number(value).toFixed(0)}%`
          },
          plotOptions: {
            pie: {
              donut: {
                size: "64%"
              }
            }
          }
        }).render();
      }
    </script>
  </body>
</html>
