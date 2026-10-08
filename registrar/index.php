<?php
session_start();
ob_start();

include '../backend/db.php';
require_once '../backend/registrar_portal_helper.php';
require_once '../backend/enrollment_draft_helper.php';

synk_registrar_require_login($conn);

$registrarPortalContext = synk_registrar_portal_context($conn);
$registrarPortalDisplayName = (string)($registrarPortalContext['account_name'] ?? 'Registrar');
$registrarPortalDisplayEmail = (string)($registrarPortalContext['email'] ?? '');
$registrarCampus = is_array($registrarPortalContext['campus'] ?? null)
    ? $registrarPortalContext['campus']
    : null;
$registrarCurrentTerm = is_array($registrarPortalContext['current_term'] ?? null)
    ? $registrarPortalContext['current_term']
    : [];
$registrarTermText = trim((string)($registrarCurrentTerm['term_text'] ?? 'Current academic term'));
$campusId = (int)($registrarPortalContext['campus_id'] ?? 0);
$termAyId = (int)($registrarCurrentTerm['ay_id'] ?? 0);
$termSemester = (int)($registrarCurrentTerm['semester'] ?? 0);
$draftTablesReady = synk_enrollment_draft_tables_ready($conn);

$termRows = ($draftTablesReady && $campusId > 0 && $termAyId > 0 && $termSemester > 0)
    ? synk_registrar_fetch_term_transaction_rows($conn, $campusId, $termAyId, $termSemester)
    : [];
$snapshot = synk_registrar_dashboard_snapshot($termRows);
$collegeRows = synk_registrar_college_summary_rows($termRows);
$programRows = synk_registrar_program_summary_rows($termRows);
$statusChart = synk_registrar_status_chart_data($termRows);
$activityChart = synk_registrar_recent_activity_chart_data($termRows, 7);

$queuePreviewRows = array_values(array_filter($termRows, static function (array $row): bool {
    return strtolower(trim((string)($row['workflow_status'] ?? 'draft'))) === 'submitted';
}));
usort($queuePreviewRows, static function (array $left, array $right): int {
    $leftTime = strtotime((string)($left['submitted_at'] ?? $left['updated_at'] ?? $left['created_at'] ?? ''));
    $rightTime = strtotime((string)($right['submitted_at'] ?? $right['updated_at'] ?? $right['created_at'] ?? ''));
    return $rightTime <=> $leftTime;
});
$queuePreviewRows = array_slice($queuePreviewRows, 0, 6);

$collegeChartRows = array_slice($collegeRows, 0, 6);
$collegeChartData = [
    'categories' => array_map(static function (array $row): string {
        $label = trim((string)($row['college_code'] ?? ''));
        if ($label === '') {
            $label = trim((string)($row['college_name'] ?? 'College'));
        }
        return $label !== '' ? $label : 'College';
    }, $collegeChartRows),
    'drafts' => array_map(static function (array $row): int {
        return (int)($row['draft_count'] ?? 0) + (int)($row['returned_count'] ?? 0);
    }, $collegeChartRows),
    'submitted' => array_map(static function (array $row): int {
        return (int)($row['submitted_count'] ?? 0);
    }, $collegeChartRows),
    'approved' => array_map(static function (array $row): int {
        return (int)($row['approved_count'] ?? 0);
    }, $collegeChartRows),
];

$programChartRows = array_slice($programRows, 0, 8);
$programChartData = [
    'categories' => array_map(static function (array $row): string {
        $label = trim((string)($row['program_code'] ?? ''));
        if ($label === '') {
            $label = trim((string)($row['display_name'] ?? 'Program'));
        }
        return $label !== '' ? $label : 'Program';
    }, $programChartRows),
    'records' => array_map(static function (array $row): int {
        return (int)($row['record_count'] ?? 0);
    }, $programChartRows),
];

$recentRows = $termRows;
usort($recentRows, static function (array $left, array $right): int {
    $leftTime = strtotime((string)($left['submitted_at'] ?? $left['updated_at'] ?? $left['created_at'] ?? ''));
    $rightTime = strtotime((string)($right['submitted_at'] ?? $right['updated_at'] ?? $right['created_at'] ?? ''));
    return $rightTime <=> $leftTime;
});
$recentRows = array_slice($recentRows, 0, 5);

$hasStatusChartData = array_sum(array_map('intval', (array)($statusChart['series'] ?? []))) > 0;
$hasActivityData = array_sum(array_map('intval', (array)($activityChart['series'] ?? []))) > 0;
$hasCollegeChartData = array_sum(array_map('intval', (array)($collegeChartData['submitted'] ?? []))) > 0
    || array_sum(array_map('intval', (array)($collegeChartData['drafts'] ?? []))) > 0
    || array_sum(array_map('intval', (array)($collegeChartData['approved'] ?? []))) > 0;
$hasProgramChartData = array_sum(array_map('intval', (array)($programChartData['records'] ?? []))) > 0;

$quickLinks = [
    [
        'href' => 'queue.php',
        'icon' => 'bx-clipboard-check',
        'icon_bg' => 'bg-label-danger',
        'title' => 'Registrar Queue',
        'description' => 'Inspect drafts already submitted to the registrar side.',
    ],
    [
        'href' => 'colleges.php',
        'icon' => 'bx-buildings',
        'icon_bg' => 'bg-label-success',
        'title' => 'Colleges',
        'description' => 'Compare workflow movement per college inside this campus.',
    ],
    [
        'href' => 'programs.php',
        'icon' => 'bx-book-content',
        'icon_bg' => 'bg-label-info',
        'title' => 'Programs',
        'description' => 'Track course-level load and enrollment movement by program.',
    ],
    [
        'href' => 'reports.php',
        'icon' => 'bx-bar-chart-alt-2',
        'icon_bg' => 'bg-label-secondary',
        'title' => 'Reports',
        'description' => 'Open registrar-ready operational summaries for the active term.',
    ],
];

$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <title>Registrar Dashboard | Synk</title>
    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/apex-charts/apex-charts.css" />
    <link rel="stylesheet" type="text/css" href="custom_css.css" />
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
  </head>
  <body>
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <?php include 'sidebar.php'; ?>
        <div class="layout-page">
          <?php include 'navbar.php'; ?>
          <div class="content-wrapper">
            <div class="container-xxl flex-grow-1 container-p-y">
              <?php if (!$draftTablesReady): ?>
                <div class="card registrar-note-card mb-4">
                  <div class="card-body p-3">Enrollment draft tables are not ready yet. Run the provided SQL first so the registrar dashboard can show live term snapshots.</div>
                </div>
              <?php elseif (!$registrarCampus): ?>
                <div class="card registrar-note-card mb-4">
                  <div class="card-body p-3">Assign a registrar campus in Access Accounts so this dashboard can load the correct campus registrar transactions.</div>
                </div>
              <?php endif; ?>

              <div class="card registrar-hero-card mb-4">
                <div class="card-body p-4">
                  <div class="row g-4 align-items-center">
                    <div class="col-lg-8">
                      <span class="registrar-kicker"><i class="bx bx-grid-alt"></i> Registrar Dashboard</span>
                      <h3 class="mt-3 mb-2">Campus enrollment snapshot for the active term</h3>
                      <p class="text-muted mb-0">This dashboard keeps the registrar landing page focused on numbers, movement, and review priorities. Use it to monitor how many drafts are still with Program Chair, how many already reached registrar, and which colleges or programs are carrying the most enrollment activity in the current term.</p>
                    </div>
                    <div class="col-lg-4">
                      <div class="registrar-metric-grid">
                        <div class="registrar-metric-card">
                          <span class="registrar-metric-label">Current Term</span>
                          <span class="registrar-metric-value" style="font-size:1.2rem;"><?php echo htmlspecialchars($registrarTermText, ENT_QUOTES, 'UTF-8'); ?></span>
                          <span class="registrar-metric-caption">Dashboard figures follow the academic settings term.</span>
                        </div>
                        <div class="registrar-metric-card">
                          <span class="registrar-metric-label">Campus Scope</span>
                          <span class="registrar-metric-value" style="font-size:1.2rem;"><?php echo htmlspecialchars(trim((string)($registrarCampus['campus_name'] ?? 'Campus required')), ENT_QUOTES, 'UTF-8'); ?></span>
                          <span class="registrar-metric-caption">Only records from this campus are counted here.</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="registrar-link-grid mb-4">
                <?php foreach ($quickLinks as $quickLink): ?>
                  <a href="<?php echo htmlspecialchars($quickLink['href'], ENT_QUOTES, 'UTF-8'); ?>" class="registrar-link-card">
                    <div class="d-flex gap-3">
                      <span class="registrar-link-icon <?php echo htmlspecialchars($quickLink['icon_bg'], ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="bx <?php echo htmlspecialchars($quickLink['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                      </span>
                      <div>
                        <div class="fw-semibold"><?php echo htmlspecialchars($quickLink['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="registrar-table-subtext mt-1"><?php echo htmlspecialchars($quickLink['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                      </div>
                    </div>
                    <span class="registrar-link-arrow"><i class="bx bx-right-arrow-alt"></i></span>
                  </a>
                <?php endforeach; ?>
              </div>

              <div class="registrar-metric-grid mb-4">
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">Ongoing Enrollees</span>
                  <span class="registrar-metric-value"><?php echo number_format((int)($snapshot['ongoing_enrollees'] ?? 0)); ?></span>
                  <span class="registrar-metric-caption">All active draft-to-posted transactions in this campus term.</span>
                </div>
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">With Program Chair</span>
                  <span class="registrar-metric-value"><?php echo number_format((int)($snapshot['chair_drafts'] ?? 0)); ?></span>
                  <span class="registrar-metric-caption">Draft and returned transactions still outside the registrar queue.</span>
                </div>
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">In Registrar Queue</span>
                  <span class="registrar-metric-value"><?php echo number_format((int)($snapshot['registrar_queue'] ?? 0)); ?></span>
                  <span class="registrar-metric-caption">Submitted drafts already waiting for registrar handling.</span>
                </div>
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">Approved / Posted</span>
                  <span class="registrar-metric-value"><?php echo number_format((int)($snapshot['approved_posted'] ?? 0)); ?></span>
                  <span class="registrar-metric-caption">Transactions already completed on the registrar side.</span>
                </div>
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">Colleges Covered</span>
                  <span class="registrar-metric-value"><?php echo number_format((int)($snapshot['college_count'] ?? 0)); ?></span>
                  <span class="registrar-metric-caption">Unique colleges with current-term registrar activity.</span>
                </div>
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">Programs Active</span>
                  <span class="registrar-metric-value"><?php echo number_format((int)($snapshot['program_count'] ?? 0)); ?></span>
                  <span class="registrar-metric-caption"><?php echo number_format((int)($snapshot['total_subjects'] ?? 0)); ?> subject rows across <?php echo htmlspecialchars(number_format((float)($snapshot['total_units'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?> units.</span>
                </div>
              </div>

              <div class="registrar-chart-grid mb-4">
                <div class="card registrar-chart-card">
                  <div class="card-body p-0">
                    <span class="registrar-kicker"><i class="bx bx-pie-chart-alt-2"></i> Workflow Mix</span>
                    <h4 class="mt-3 mb-2">Current term status distribution</h4>
                    <p class="text-muted mb-0">A quick view of where the campus enrollment workload is sitting right now.</p>
                    <?php if ($hasStatusChartData): ?>
                      <div id="registrarStatusChart" class="registrar-chart-wrap mt-3"></div>
                    <?php else: ?>
                      <div class="registrar-chart-empty mt-3">No registrar transactions are available for the active term yet.</div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="card registrar-chart-card">
                  <div class="card-body p-0">
                    <span class="registrar-kicker"><i class="bx bx-line-chart"></i> Activity Trend</span>
                    <h4 class="mt-3 mb-2">Recent submission movement</h4>
                    <p class="text-muted mb-0">Use this trend to see whether workload is climbing or slowing down over the last seven days.</p>
                    <?php if ($hasActivityData): ?>
                      <div id="registrarActivityChart" class="registrar-chart-wrap mt-3"></div>
                    <?php else: ?>
                      <div class="registrar-chart-empty mt-3">Recent activity will appear here once drafts begin moving through registrar workflow.</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="registrar-chart-grid mb-4">
                <div class="card registrar-chart-card">
                  <div class="card-body p-0">
                    <span class="registrar-kicker"><i class="bx bx-buildings"></i> College Snapshot</span>
                    <h4 class="mt-3 mb-2">Registrar flow per college</h4>
                    <p class="text-muted mb-0">Drafts with Program Chair, submitted queue items, and approved loads are grouped here by college.</p>
                    <?php if ($hasCollegeChartData): ?>
                      <div id="registrarCollegeChart" class="registrar-chart-wrap mt-3"></div>
                    <?php else: ?>
                      <div class="registrar-chart-empty mt-3">College movement charts will appear as soon as campus records are saved for this term.</div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="card registrar-chart-card">
                  <div class="card-body p-0">
                    <span class="registrar-kicker"><i class="bx bx-book-content"></i> Program Snapshot</span>
                    <h4 class="mt-3 mb-2">Top programs by transaction count</h4>
                    <p class="text-muted mb-0">This chart highlights which programs are carrying the heaviest registrar enrollment load in the current term.</p>
                    <?php if ($hasProgramChartData): ?>
                      <div id="registrarProgramChart" class="registrar-chart-wrap mt-3"></div>
                    <?php else: ?>
                      <div class="registrar-chart-empty mt-3">Program-level counts will appear here once drafts are saved under active sections.</div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="registrar-split-grid registrar-dashboard-section">
                <div class="card registrar-table-card">
                  <div class="card-body p-0">
                    <div class="d-flex justify-content-between gap-3 align-items-start">
                      <div>
                        <span class="registrar-kicker"><i class="bx bx-table"></i> College Summary</span>
                        <h4 class="mt-3 mb-2">Current-term college breakdown</h4>
                        <p class="text-muted mb-0">A concise operational table for the campus registrar team.</p>
                      </div>
                      <a href="colleges.php" class="btn btn-outline-primary btn-sm">View Colleges</a>
                    </div>
                    <?php if (empty($collegeRows)): ?>
                      <div class="registrar-empty-state mt-3">No college summary is available yet for the active term.</div>
                    <?php else: ?>
                      <div class="table-responsive mt-3">
                        <table class="table registrar-table mb-0">
                          <thead>
                            <tr>
                              <th>College</th>
                              <th class="text-center">Programs</th>
                              <th class="text-center">Drafts</th>
                              <th class="text-center">Submitted</th>
                              <th class="text-center">Approved</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach (array_slice($collegeRows, 0, 6) as $collegeRow): ?>
                              <tr>
                                <td>
                                  <strong><?php echo htmlspecialchars((string)($collegeRow['college_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                  <div class="registrar-table-subtext"><?php echo htmlspecialchars((string)($collegeRow['college_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td class="text-center"><?php echo number_format((int)($collegeRow['program_count'] ?? 0)); ?></td>
                                <td class="text-center"><?php echo number_format((int)($collegeRow['draft_count'] ?? 0) + (int)($collegeRow['returned_count'] ?? 0)); ?></td>
                                <td class="text-center"><?php echo number_format((int)($collegeRow['submitted_count'] ?? 0)); ?></td>
                                <td class="text-center"><?php echo number_format((int)($collegeRow['approved_count'] ?? 0)); ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="card registrar-table-card">
                  <div class="card-body p-0">
                    <div class="d-flex justify-content-between gap-3 align-items-start">
                      <div>
                        <span class="registrar-kicker"><i class="bx bx-time-five"></i> Queue Preview</span>
                        <h4 class="mt-3 mb-2">Newest drafts already with registrar</h4>
                        <p class="text-muted mb-0">Use this preview to see which items most recently entered the registrar queue.</p>
                      </div>
                      <a href="queue.php" class="btn btn-outline-primary btn-sm">Open Queue</a>
                    </div>
                    <?php if (empty($queuePreviewRows)): ?>
                      <div class="registrar-empty-state mt-3">No submitted registrar queue items are available yet for this term.</div>
                    <?php else: ?>
                      <div class="registrar-list-stack mt-3">
                        <?php foreach ($queuePreviewRows as $queueRow): ?>
                          <div class="registrar-list-row">
                            <div class="d-flex justify-content-between gap-3 align-items-start">
                              <div>
                                <strong><?php echo htmlspecialchars((string)($queueRow['enrollment_reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <div class="registrar-table-subtext mt-1"><?php echo htmlspecialchars(synk_registrar_student_label($queueRow), ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="registrar-inline-meta mt-3">
                                  <span class="registrar-inline-chip"><?php echo htmlspecialchars((string)($queueRow['college_code'] ?? 'College'), ENT_QUOTES, 'UTF-8'); ?></span>
                                  <span class="registrar-inline-chip"><?php echo htmlspecialchars((string)($queueRow['program_code'] ?? 'Program'), ENT_QUOTES, 'UTF-8'); ?></span>
                                  <span class="registrar-inline-chip"><?php echo htmlspecialchars((string)($queueRow['subject_count'] ?? 0), ENT_QUOTES, 'UTF-8'); ?> subjects</span>
                                </div>
                              </div>
                              <div class="text-end">
                                <span class="badge <?php echo htmlspecialchars(synk_registrar_status_badge_class((string)($queueRow['workflow_status'] ?? 'submitted')), ENT_QUOTES, 'UTF-8'); ?>">
                                  <?php echo htmlspecialchars((string)($queueRow['status_label'] ?? 'Submitted'), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <div class="registrar-table-subtext mt-2"><?php echo htmlspecialchars((string)($queueRow['submitted_at'] ?? $queueRow['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                <a href="queue.php?enrollment_id=<?php echo (int)($queueRow['enrollment_id'] ?? 0); ?>" class="btn btn-outline-primary btn-sm mt-3">Open</a>
                              </div>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <div class="card registrar-table-card registrar-dashboard-section">
                <div class="card-body p-0">
                  <div class="d-flex justify-content-between gap-3 align-items-start">
                    <div>
                      <span class="registrar-kicker"><i class="bx bx-news"></i> Recent Activity</span>
                      <h4 class="mt-3 mb-2">Latest campus transactions</h4>
                      <p class="text-muted mb-0">A compact list of the most recent registrar-related enrollment transactions in the active term.</p>
                    </div>
                    <a href="reports.php" class="btn btn-outline-primary btn-sm">Open Reports</a>
                  </div>
                  <?php if (empty($recentRows)): ?>
                    <div class="registrar-empty-state mt-3">Recent campus activity will appear here once enrollment drafts start moving through workflow.</div>
                  <?php else: ?>
                    <div class="table-responsive mt-3">
                      <table class="table registrar-table mb-0">
                        <thead>
                          <tr>
                            <th>Reference</th>
                            <th>Student</th>
                            <th>College / Program</th>
                            <th>Status</th>
                            <th>Updated</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($recentRows as $recentRow): ?>
                            <tr>
                              <td><strong><?php echo htmlspecialchars((string)($recentRow['enrollment_reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong></td>
                              <td><?php echo htmlspecialchars(synk_registrar_student_label($recentRow), ENT_QUOTES, 'UTF-8'); ?></td>
                              <td>
                                <div><?php echo htmlspecialchars((string)($recentRow['college_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="registrar-table-subtext"><?php echo htmlspecialchars((string)($recentRow['program_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                              </td>
                              <td>
                                <span class="badge <?php echo htmlspecialchars(synk_registrar_status_badge_class((string)($recentRow['workflow_status'] ?? 'draft')), ENT_QUOTES, 'UTF-8'); ?>">
                                  <?php echo htmlspecialchars((string)($recentRow['status_label'] ?? 'Draft'), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                              </td>
                              <td><?php echo htmlspecialchars((string)($recentRow['submitted_at'] ?? $recentRow['updated_at'] ?? $recentRow['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
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
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
      const registrarStatusChartData = <?php echo json_encode($statusChart, $jsonFlags); ?>;
      const registrarActivityChartData = <?php echo json_encode($activityChart, $jsonFlags); ?>;
      const registrarCollegeChartData = <?php echo json_encode($collegeChartData, $jsonFlags); ?>;
      const registrarProgramChartData = <?php echo json_encode($programChartData, $jsonFlags); ?>;

      if (document.querySelector('#registrarStatusChart') && Array.isArray(registrarStatusChartData.series)) {
        new ApexCharts(document.querySelector('#registrarStatusChart'), {
          chart: {
            type: 'donut',
            height: 320,
            toolbar: { show: false }
          },
          labels: registrarStatusChartData.labels,
          series: registrarStatusChartData.series,
          legend: {
            position: 'bottom'
          },
          dataLabels: {
            enabled: true
          },
          colors: ['#8592a3', '#ffab00', '#ff3e1d', '#71dd37']
        }).render();
      }

      if (document.querySelector('#registrarActivityChart') && Array.isArray(registrarActivityChartData.series)) {
        new ApexCharts(document.querySelector('#registrarActivityChart'), {
          chart: {
            type: 'area',
            height: 320,
            toolbar: { show: false }
          },
          series: [{
            name: 'Transactions',
            data: registrarActivityChartData.series
          }],
          xaxis: {
            categories: registrarActivityChartData.categories
          },
          stroke: {
            curve: 'smooth',
            width: 3
          },
          fill: {
            type: 'gradient',
            gradient: {
              shadeIntensity: 1,
              opacityFrom: 0.35,
              opacityTo: 0.06
            }
          },
          colors: ['#696cff']
        }).render();
      }

      if (document.querySelector('#registrarCollegeChart') && Array.isArray(registrarCollegeChartData.categories)) {
        new ApexCharts(document.querySelector('#registrarCollegeChart'), {
          chart: {
            type: 'bar',
            stacked: true,
            height: 320,
            toolbar: { show: false }
          },
          series: [
            {
              name: 'With Program Chair',
              data: registrarCollegeChartData.drafts
            },
            {
              name: 'Submitted',
              data: registrarCollegeChartData.submitted
            },
            {
              name: 'Approved / Posted',
              data: registrarCollegeChartData.approved
            }
          ],
          xaxis: {
            categories: registrarCollegeChartData.categories
          },
          legend: {
            position: 'bottom'
          },
          plotOptions: {
            bar: {
              borderRadius: 6,
              columnWidth: '42%'
            }
          },
          colors: ['#8592a3', '#ffab00', '#71dd37']
        }).render();
      }

      if (document.querySelector('#registrarProgramChart') && Array.isArray(registrarProgramChartData.categories)) {
        new ApexCharts(document.querySelector('#registrarProgramChart'), {
          chart: {
            type: 'bar',
            height: 320,
            toolbar: { show: false }
          },
          series: [{
            name: 'Transactions',
            data: registrarProgramChartData.records
          }],
          xaxis: {
            categories: registrarProgramChartData.categories
          },
          plotOptions: {
            bar: {
              horizontal: true,
              borderRadius: 6,
              barHeight: '58%'
            }
          },
          dataLabels: {
            enabled: true
          },
          colors: ['#03c3ec']
        }).render();
      }
    </script>
  </body>
</html>
