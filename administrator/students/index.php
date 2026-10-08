<?php
require_once __DIR__ . '/bootstrap.php';

$flash = student_management_pull_flash();
$summary = [
    'total_students' => 0,
    'total_programs' => 0,
    'total_terms' => 0,
    'unassigned_students' => 0,
    'last_uploaded_at' => '',
];
$latestBatch = [
    'academic_year_label' => '',
    'semester_label' => '',
    'college_name' => '',
    'campus_name' => '',
    'student_count' => 0,
    'program_count' => 0,
    'year_level_count' => 0,
    'last_uploaded_at' => '',
];
$chartData = [
    'categories' => [],
    'series' => [],
    'max_value' => 0,
];
$campuses = [];

if ($studentManagementPageError === '') {
    try {
        $summary = synk_student_management_summary($conn);
        $latestBatch = synk_student_management_latest_batch($conn);
        $chartData = synk_student_management_program_year_level_chart($conn, $latestBatch);
        $campuses = synk_student_management_fetch_active_campuses($conn);
    } catch (Throwable $e) {
        $studentManagementPageError = $e->getMessage();
    }
}

$dashboardUser = htmlspecialchars($_SESSION['username'] ?? 'Administrator', ENT_QUOTES, 'UTF-8');
$hasLatestBatch = $latestBatch['academic_year_label'] !== '' && $latestBatch['semester_label'] !== '';
$latestBatchLabel = trim(
    implode(' | ', array_filter([
        $latestBatch['academic_year_label'],
        $latestBatch['semester_label'],
    ]))
);
$latestBatchLocation = trim(
    implode(' | ', array_filter([
        $latestBatch['college_name'],
        $latestBatch['campus_name'],
    ]))
);
$chartInsight = $hasLatestBatch
    ? 'The chart compares student volume per program using separate lines for each detected year level in the latest imported batch.'
    : 'Upload a student workbook first to generate the year-level distribution chart.';
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../../assets/"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Student Dashboard | Synk</title>

    <link rel="icon" type="image/png" href="../../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../../assets/css/demo.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/apex-charts/apex-charts.css" />

    <script src="../../assets/vendor/js/helpers.js"></script>
    <script src="../../assets/js/config.js"></script>

    <style>
      .hero-card {
        overflow: hidden;
      }

      .hero-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
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

      .chart-shell {
        min-height: 360px;
      }

      .chart-fallback {
        min-height: 360px;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        color: #8592a3;
      }

      .chart-insight {
        min-height: 40px;
        margin-top: 0.75rem;
        font-size: 0.84rem;
        color: #8592a3;
      }

      .campus-scroll-area {
        max-height: 760px;
        overflow-y: auto;
      }

      .campus-card {
        border: 1px solid #e4e4e4;
        border-radius: 14px;
        padding: 14px 18px;
        margin-bottom: 14px;
        background: #fff;
        transition: 0.2s ease-in-out;
      }

      .campus-card:hover {
        transform: translateX(4px);
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
      }

      .campus-icon {
        width: 36px;
        height: 36px;
        border-radius: 999px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
      }

      .campus-tag {
        display: inline-flex;
        margin-top: 0.45rem;
        padding: 0.2rem 0.55rem;
        border-radius: 999px;
        background: #f4f3ff;
        color: #696cff;
        font-size: 0.68rem;
        font-weight: 700;
        letter-spacing: 0.03em;
        text-transform: uppercase;
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
              <?php if ($flash): ?>
                <div class="alert alert-<?php echo htmlspecialchars((string)($flash['status'] ?? 'info'), ENT_QUOTES, 'UTF-8'); ?> alert-dismissible mb-4" role="alert">
                  <div class="fw-semibold"><?php echo htmlspecialchars((string)($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                  <?php if (!empty($flash['details'])): ?>
                    <div class="small mt-1"><?php echo htmlspecialchars((string)$flash['details'], ENT_QUOTES, 'UTF-8'); ?></div>
                  <?php endif; ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
              <?php endif; ?>

              <?php if ($studentManagementPageError !== ''): ?>
                <div class="alert alert-danger mb-4" role="alert">
                  <div class="fw-semibold">Student management is unavailable.</div>
                  <div class="small mt-1"><?php echo htmlspecialchars($studentManagementPageError, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
              <?php endif; ?>

              <div class="row">
                <div class="col-lg-8 mb-4 order-0">
                  <div class="row">
                    <div class="col-lg-12 order-0 mb-4">
                      <div class="card shadow-sm hero-card">
                        <div class="d-flex align-items-center row">
                          <div class="col-sm-8 px-4 py-4">
                            <h4 class="text-primary fw-bold mb-2">Student Management Dashboard</h4>

                            <p class="mb-3 text-muted">
                              Welcome back, <strong><?php echo $dashboardUser; ?></strong>.
                              This dashboard gives you a clean overview of the latest student import batch,
                              year-level distribution, and the separate student workspace.
                            </p>

                            <div class="hero-meta mb-3">
                              <?php if ($hasLatestBatch): ?>
                                <span class="badge bg-label-primary"><?php echo htmlspecialchars($latestBatchLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php if ($latestBatch['college_name'] !== ''): ?>
                                  <span class="badge bg-label-info"><?php echo htmlspecialchars($latestBatch['college_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                                <?php if ($latestBatch['campus_name'] !== ''): ?>
                                  <span class="badge bg-label-info"><?php echo htmlspecialchars($latestBatch['campus_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <?php endif; ?>
                              <?php else: ?>
                                <span class="badge bg-label-warning">No student batch uploaded yet</span>
                              <?php endif; ?>
                            </div>

                            <div class="d-flex flex-wrap gap-2">
                              <a href="exam-permits.php" class="btn btn-sm btn-primary">Upload Backward Encoding CSV</a>
                              <a href="directory.php" class="btn btn-sm btn-outline-primary">Open Student Directory</a>
                              <a href="faculty-evaluation-reports.php" class="btn btn-sm btn-outline-warning">Faculty Evaluation Reports</a>
                            </div>
                          </div>

                          <div class="col-sm-4 text-center">
                            <img
                              src="../../assets/img/illustrations/man-with-laptop-light.png"
                              height="130"
                              alt="Student Dashboard Overview"
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
                            <h5 class="m-0 me-2">Student Operations Overview</h5>
                            <small class="text-muted">Current student-module indicators from the latest loaded data</small>
                          </div>
                          <span class="badge bg-label-primary">KPI Summary</span>
                        </div>

                        <div class="card-body px-4 py-3">
                          <div class="row gy-4">
                            <div class="col-md-3 col-6">
                              <div class="kpi-box text-center">
                                <div class="kpi-icon bg-primary">
                                  <i class="bx bx-group"></i>
                                </div>
                                <h3 class="kpi-value text-primary mt-2"><?php echo number_format((int)$latestBatch['student_count']); ?></h3>
                                <small class="text-muted">Students</small>
                                <div class="metric-note">Rows in the latest imported batch</div>
                              </div>
                            </div>

                            <div class="col-md-3 col-6">
                              <div class="kpi-box text-center">
                                <div class="kpi-icon bg-info">
                                  <i class="bx bx-book-open"></i>
                                </div>
                                <h3 class="kpi-value text-info mt-2"><?php echo number_format((int)$latestBatch['program_count']); ?></h3>
                                <small class="text-muted">Programs</small>
                                <div class="metric-note">Programs detected in the latest batch</div>
                              </div>
                            </div>

                            <div class="col-md-3 col-6">
                              <div class="kpi-box text-center">
                                <div class="kpi-icon bg-success">
                                  <i class="bx bx-layer"></i>
                                </div>
                                <h3 class="kpi-value text-success mt-2"><?php echo number_format((int)$latestBatch['year_level_count']); ?></h3>
                                <small class="text-muted">Year Levels</small>
                                <div class="metric-note">Detected across the latest workbook</div>
                              </div>
                            </div>

                            <div class="col-md-3 col-6">
                              <div class="kpi-box text-center">
                                <div class="kpi-icon bg-warning">
                                  <i class="bx bx-link-alt"></i>
                                </div>
                                <h3 class="kpi-value text-warning mt-2"><?php echo number_format((int)$summary['unassigned_students']); ?></h3>
                                <small class="text-muted">Program ID 0</small>
                                <div class="metric-note">Rows still waiting for program mapping</div>
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
                            <h5 class="m-0">Student Distribution by Program and Year Level</h5>
                            <small class="text-muted">Each line represents a year level across the programs inside the latest imported student batch.</small>
                          </div>
                          <span class="badge bg-label-info"><?php echo htmlspecialchars($hasLatestBatch ? $latestBatchLabel : 'Awaiting upload', ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>

                        <div class="card-body">
                          <div id="studentYearLevelChart" class="chart-shell"></div>
                          <p class="chart-insight mb-0"><?php echo htmlspecialchars($chartInsight, ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="col-md-6 col-lg-4 order-2 mb-4">
                  <div class="card shadow-sm mb-4">
                    <div class="card-body">
                      <div class="d-flex align-items-start justify-content-between gap-3">
                        <div>
                          <span class="badge bg-label-warning mb-2">New Card Menu</span>
                          <h5 class="mb-2">Faculty Evaluation Reports</h5>
                          <p class="text-muted mb-3">
                            Open the dedicated administrator page for student rating sheet rows, individual faculty reports, and the consolidated report.
                          </p>
                          <a href="faculty-evaluation-reports.php" class="btn btn-warning">
                            <i class="bx bx-bar-chart-alt-2 me-1"></i>
                            Open Reports Page
                          </a>
                        </div>
                        <div class="kpi-icon bg-warning flex-shrink-0">
                          <i class="bx bx-spreadsheet"></i>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="card h-100 shadow-sm">
                    <div class="card-header d-flex align-items-center justify-content-between">
                      <h5 class="card-title m-0">Campus Snapshot</h5>
                      <span class="badge bg-label-primary">Future Wiring</span>
                    </div>

                    <div class="card-body campus-scroll-area">
                      <?php if (!empty($campuses)): ?>
                        <?php foreach ($campuses as $campus): ?>
                          <?php $isLatestCampus = $latestBatch['campus_name'] !== '' && strcasecmp($latestBatch['campus_name'], $campus['campus_name']) === 0; ?>
                          <div class="campus-card shadow-sm">
                            <div class="d-flex justify-content-between align-items-center gap-3">
                              <div>
                                <h6 class="mb-1 fw-bold text-dark"><?php echo htmlspecialchars($campus['campus_name'], ENT_QUOTES, 'UTF-8'); ?></h6>
                                <small class="text-muted">
                                  <?php echo $isLatestCampus ? 'Latest imported batch is currently from this campus.' : 'Student analytics wiring will be connected here later.'; ?>
                                </small>
                                <div>
                                  <span class="campus-tag"><?php echo $isLatestCampus ? 'Latest Batch' : 'Placeholder'; ?></span>
                                </div>
                              </div>

                              <div class="campus-icon <?php echo $isLatestCampus ? 'bg-primary' : 'bg-label-secondary'; ?>">
                                <i class="bx <?php echo $isLatestCampus ? 'bx-check' : 'bx-chevron-right'; ?> <?php echo $isLatestCampus ? 'text-white' : ''; ?>"></i>
                              </div>
                            </div>
                          </div>
                        <?php endforeach; ?>

                        <div class="campus-card shadow-sm bg-light mb-0">
                          <div class="d-flex justify-content-between align-items-center gap-3">
                            <div>
                              <h6 class="mb-1 fw-bold text-dark">University Summary</h6>
                              <small class="text-muted">Campus-wide student snapshot wiring will land here next.</small>
                            </div>
                            <div class="campus-icon bg-success">
                              <i class="bx bx-globe text-white"></i>
                            </div>
                          </div>
                        </div>
                      <?php else: ?>
                        <div class="alert alert-light border mb-0">
                          No active campus records were found for the campus snapshot panel.
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <?php include '../../footer.php'; ?>

            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>

      <div class="layout-overlay layout-menu-toggle"></div>
    </div>

    <script src="../../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../../assets/vendor/libs/popper/popper.js"></script>
    <script src="../../assets/vendor/js/bootstrap.js"></script>
    <script src="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../../assets/vendor/js/menu.js"></script>
    <script src="../../assets/vendor/libs/apex-charts/apexcharts.js"></script>
    <script src="../../assets/js/main.js"></script>

    <script>
      (function () {
        var categories = <?php echo json_encode($chartData['categories']); ?>;
        var series = <?php echo json_encode($chartData['series'], JSON_NUMERIC_CHECK); ?>;
        var chartRoot = document.getElementById('studentYearLevelChart');

        if (!chartRoot) {
          return;
        }

        if (!Array.isArray(categories) || !categories.length || !Array.isArray(series) || !series.length || typeof ApexCharts === 'undefined') {
          chartRoot.innerHTML = '<div class="chart-fallback"><p class="mb-0">No year-level chart data is available yet. Upload a student workbook to populate this chart.</p></div>';
          return;
        }

        var maxValue = Math.max(5, <?php echo (int)$chartData['max_value']; ?> + 5);

        new ApexCharts(chartRoot, {
          series: series,
          chart: {
            type: 'line',
            height: 360,
            toolbar: { show: false },
            zoom: { enabled: false }
          },
          colors: ['#696cff', '#00cfe8', '#71dd37', '#ffab00', '#ff5b5c'],
          stroke: {
            curve: 'smooth',
            width: 3
          },
          markers: {
            size: 4,
            hover: { sizeOffset: 2 }
          },
          dataLabels: {
            enabled: false
          },
          legend: {
            position: 'top',
            horizontalAlign: 'left'
          },
          grid: {
            borderColor: '#ebeef5',
            strokeDashArray: 4
          },
          xaxis: {
            categories: categories,
            labels: {
              rotate: -20,
              style: {
                fontSize: '12px',
                colors: '#7b8798'
              }
            }
          },
          yaxis: {
            min: 0,
            max: maxValue,
            tickAmount: 5,
            title: {
              text: 'Student Count',
              style: { color: '#7b8798' }
            },
            labels: {
              style: { colors: '#7b8798' }
            }
          },
          tooltip: {
            shared: true,
            intersect: false
          }
        }).render();
      })();
    </script>
  </body>
</html>
