<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../backend/student_portal_helper.php';

$reportError = $studentManagementPageError;
$termOptions = [];
$selectedTerm = null;
$selectedTermKey = trim((string)($_GET['term_key'] ?? ''));
$allowedViews = ['rating_sheet', 'individual', 'consolidated'];
$selectedView = trim((string)($_GET['view'] ?? 'rating_sheet'));
if (!in_array($selectedView, $allowedViews, true)) {
    $selectedView = 'rating_sheet';
}
$summary = [
    'evaluation_count' => 0,
    'student_count' => 0,
    'faculty_count' => 0,
    'final_submission_count' => 0,
    'overall_mean' => 0.0,
    'overall_percentage' => 0.0,
    'latest_submitted_at' => '',
];
$ratingSheetRows = [];
$individualRows = [];
$consolidatedRows = [];

if ($reportError === '') {
    try {
        synk_student_faculty_evaluation_ensure_schema($conn);
        $termOptions = synk_student_fetch_faculty_evaluation_report_term_options($conn);

        if ($selectedTermKey === '' && !empty($termOptions)) {
            $selectedTermKey = (string)($termOptions[0]['term_key'] ?? '');
        }

        foreach ($termOptions as $termOption) {
            if ((string)($termOption['term_key'] ?? '') === $selectedTermKey) {
                $selectedTerm = $termOption;
                break;
            }
        }

        if ($selectedTerm === null && !empty($termOptions)) {
            $selectedTerm = $termOptions[0];
            $selectedTermKey = (string)($selectedTerm['term_key'] ?? '');
        }

        if (is_array($selectedTerm) && !empty($selectedTerm)) {
            $selectedAyId = (int)($selectedTerm['ay_id'] ?? 0);
            $selectedSemester = (int)($selectedTerm['semester'] ?? 0);
            $summary = synk_student_fetch_faculty_evaluation_report_summary($conn, $selectedAyId, $selectedSemester);
            $ratingSheetRows = synk_student_fetch_faculty_evaluation_rating_sheet_rows($conn, $selectedAyId, $selectedSemester);
            $individualRows = synk_student_fetch_faculty_evaluation_individual_report_rows($conn, $selectedAyId, $selectedSemester);
            $consolidatedRows = synk_student_build_faculty_evaluation_consolidated_rows($individualRows);
        }
    } catch (Throwable $e) {
        $reportError = $e->getMessage();
    }
}

$selectedTermLabel = is_array($selectedTerm) ? trim((string)($selectedTerm['term_label'] ?? '')) : '';

function faculty_evaluation_reports_page_url(string $view, string $termKey = ''): string
{
    $query = ['view' => $view];
    if ($termKey !== '') {
        $query['term_key'] = $termKey;
    }

    return 'faculty-evaluation-reports.php' . (!empty($query) ? ('?' . http_build_query($query)) : '');
}
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
    <title>Faculty Evaluation Reports | Synk</title>

    <link rel="icon" type="image/png" href="../../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../../assets/css/demo.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <script src="../../assets/vendor/js/helpers.js"></script>
    <script src="../../assets/js/config.js"></script>

    <style>
      .report-hero,
      .report-card,
      .report-nav-card,
      .faculty-report-card {
        border: 1px solid #e8edf7;
        border-radius: 1.1rem;
        background: #fff;
        box-shadow: 0 12px 24px rgba(20, 33, 61, 0.04);
      }

      .report-hero {
        background:
          radial-gradient(circle at top right, rgba(105, 108, 255, 0.12), transparent 30%),
          linear-gradient(135deg, #ffffff 0%, #f8faff 100%);
      }

      .summary-card {
        border: 1px solid #e8edf7;
        border-radius: 1rem;
        padding: 1rem;
        height: 100%;
        background: #fff;
      }

      .summary-card .label {
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #8592a3;
      }

      .summary-card .value {
        font-size: 1.55rem;
        font-weight: 700;
      }

      .report-nav-card {
        display: block;
        padding: 1rem;
        text-decoration: none;
        color: inherit;
        transition: 0.2s ease;
        height: 100%;
      }

      .report-nav-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 16px 28px rgba(105, 108, 255, 0.12);
      }

      .report-nav-card.active {
        border-color: #cfd6ff;
        background: linear-gradient(135deg, #f7f8ff 0%, #eef2ff 100%);
        box-shadow: 0 16px 28px rgba(105, 108, 255, 0.12);
      }

      .report-nav-card .eyebrow {
        display: inline-flex;
        padding: 0.2rem 0.55rem;
        border-radius: 999px;
        background: #eef2ff;
        color: #696cff;
        font-size: 0.68rem;
        font-weight: 700;
      }

      .report-table-shell {
        overflow-x: auto;
      }

      .report-table-shell .table {
        min-width: 980px;
      }

      .faculty-report-card {
        padding: 1.1rem;
      }

      .faculty-report-card + .faculty-report-card {
        margin-top: 1rem;
      }

      .category-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 0.85rem;
      }

      .category-box {
        border: 1px solid #edf1f7;
        border-radius: 1rem;
        background: #fbfcff;
        padding: 0.9rem 1rem;
      }

      .comment-item {
        border-left: 4px solid #696cff;
        padding: 0.8rem 0.9rem;
        border-radius: 0.85rem;
        background: #f8faff;
      }

      .empty-state {
        border: 1px dashed #d8deec;
        border-radius: 1rem;
        padding: 2.5rem 1.25rem;
        text-align: center;
        color: #8592a3;
        background: #fbfcff;
      }

      .view-indicator {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.45rem 0.8rem;
        border-radius: 999px;
        background: #eef2ff;
        color: #696cff;
        font-size: 0.8rem;
        font-weight: 700;
      }

      .report-workspace {
        position: relative;
      }

      .report-workspace.is-loading {
        opacity: 0.65;
        pointer-events: none;
        transition: opacity 0.18s ease;
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
              <?php if ($reportError !== ''): ?>
                <div class="alert alert-danger mb-4" role="alert">
                  <div class="fw-semibold">Faculty evaluation reports are unavailable.</div>
                  <div class="small mt-1"><?php echo htmlspecialchars($reportError, ENT_QUOTES, 'UTF-8'); ?></div>
                </div>
              <?php endif; ?>

              <div class="card report-hero mb-4">
                <div class="card-body p-4">
                  <div class="d-flex flex-column flex-lg-row justify-content-between gap-4">
                    <div>
                      <span class="badge bg-label-primary mb-2">Faculty Evaluation Reports</span>
                      <h4 class="text-primary fw-bold mb-2">Provide the student-side faculty evaluation reports from one administrator page.</h4>
                      <p class="text-muted mb-3">
                        This report page is driven by finalized student evaluations. Student-generated rating sections are shown here,
                        while supervisor columns remain manual so the official forms stay accurate.
                      </p>
                      <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-label-info">EFP-02 Rating Sheet</span>
                        <span class="badge bg-label-success">EFP-05 Individual</span>
                        <span class="badge bg-label-warning">EFP-04 Consolidated</span>
                      </div>
                    </div>

                    <div class="d-flex align-items-start">
                      <a href="index.php" class="btn btn-outline-primary">
                        <i class="bx bx-arrow-back me-1"></i>
                        Back to Dashboard
                      </a>
                    </div>
                  </div>
                </div>
              </div>

              <div
                id="facultyEvaluationReportsWorkspace"
                class="report-workspace"
                data-current-view="<?php echo htmlspecialchars($selectedView, ENT_QUOTES, 'UTF-8'); ?>"
                data-current-term="<?php echo htmlspecialchars($selectedTermKey, ENT_QUOTES, 'UTF-8'); ?>"
              >
              <div class="card report-card mb-4">
                <div class="card-body p-4">
                  <form id="facultyEvaluationReportFilterForm" method="get" action="faculty-evaluation-reports.php" class="row g-3 align-items-end">
                    <input type="hidden" name="view" value="<?php echo htmlspecialchars($selectedView, ENT_QUOTES, 'UTF-8'); ?>" />
                    <div class="col-lg-8">
                      <label for="term_key" class="form-label fw-semibold">Academic Term</label>
                      <select class="form-select" id="term_key" name="term_key">
                        <option value="">Select submitted term</option>
                        <?php foreach ($termOptions as $termOption): ?>
                          <?php $termKey = (string)($termOption['term_key'] ?? ''); ?>
                          <option value="<?php echo htmlspecialchars($termKey, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $termKey === $selectedTermKey ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars((string)($termOption['term_label'] ?? 'Unknown term'), ENT_QUOTES, 'UTF-8'); ?>
                            <?php echo ' (' . number_format((int)($termOption['evaluation_count'] ?? 0)) . ' evaluations)'; ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                      <div class="form-text">Only one-time finalized student submissions are included here.</div>
                    </div>
                    <div class="col-lg-4 d-flex gap-2">
                      <button type="submit" class="btn btn-primary">
                        <i class="bx bx-filter-alt me-1"></i>
                        Load Reports
                      </button>
                      <a href="faculty-evaluation-reports.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                  </form>
                </div>
              </div>

              <?php if ($selectedTerm === null): ?>
                <div class="empty-state mb-4">
                  <h5 class="mb-2">No submitted faculty evaluations yet.</h5>
                  <p class="mb-0">Once students finish and finalize their evaluation flow, the administrator reports will appear here.</p>
                </div>
              <?php else: ?>
                <div class="row g-3 mb-4">
                  <div class="col-sm-6 col-xl-3">
                    <div class="summary-card">
                      <div class="label">Final Submission Sets</div>
                      <div class="value text-primary"><?php echo number_format((int)$summary['final_submission_count']); ?></div>
                      <div class="text-muted small mt-1">Unique finalized student term submissions</div>
                    </div>
                  </div>
                  <div class="col-sm-6 col-xl-3">
                    <div class="summary-card">
                      <div class="label">Faculty Evaluated</div>
                      <div class="value text-info"><?php echo number_format((int)$summary['faculty_count']); ?></div>
                      <div class="text-muted small mt-1">Distinct faculty members with submitted rows</div>
                    </div>
                  </div>
                  <div class="col-sm-6 col-xl-3">
                    <div class="summary-card">
                      <div class="label">Student Evaluators</div>
                      <div class="value text-success"><?php echo number_format((int)$summary['student_count']); ?></div>
                      <div class="text-muted small mt-1">Students who completed evaluation for this term</div>
                    </div>
                  </div>
                  <div class="col-sm-6 col-xl-3">
                    <div class="summary-card">
                      <div class="label">Overall Student Rating</div>
                      <div class="value text-warning"><?php echo number_format((float)$summary['overall_percentage'], 2); ?>%</div>
                      <div class="text-muted small mt-1">Mean <?php echo number_format((float)$summary['overall_mean'], 2); ?> / 5.00</div>
                    </div>
                  </div>
                </div>

                <div class="row g-3 mb-4">
                  <div class="col-lg-4">
                    <a
                      href="<?php echo htmlspecialchars(faculty_evaluation_reports_page_url('rating_sheet', $selectedTermKey), ENT_QUOTES, 'UTF-8'); ?>"
                      class="report-nav-card<?php echo $selectedView === 'rating_sheet' ? ' active' : ''; ?>"
                      data-report-nav="1"
                    >
                      <span class="eyebrow">EFP-02</span>
                      <h5 class="mt-3 mb-2">Student Rating Sheet</h5>
                      <p class="text-muted mb-0">Per-student, per-faculty submitted rating rows with comments and submission code.</p>
                    </a>
                  </div>
                  <div class="col-lg-4">
                    <a
                      href="<?php echo htmlspecialchars(faculty_evaluation_reports_page_url('individual', $selectedTermKey), ENT_QUOTES, 'UTF-8'); ?>"
                      class="report-nav-card<?php echo $selectedView === 'individual' ? ' active' : ''; ?>"
                      data-report-nav="1"
                    >
                      <span class="eyebrow">EFP-05</span>
                      <h5 class="mt-3 mb-2">Individual Faculty Report</h5>
                      <p class="text-muted mb-0">Student-rating category means grouped under each evaluated faculty.</p>
                    </a>
                  </div>
                  <div class="col-lg-4">
                    <a
                      href="<?php echo htmlspecialchars(faculty_evaluation_reports_page_url('consolidated', $selectedTermKey), ENT_QUOTES, 'UTF-8'); ?>"
                      class="report-nav-card<?php echo $selectedView === 'consolidated' ? ' active' : ''; ?>"
                      data-report-nav="1"
                    >
                      <span class="eyebrow">EFP-04</span>
                      <h5 class="mt-3 mb-2">Consolidated Report</h5>
                      <p class="text-muted mb-0">Cross-faculty summary ready for supervisor-side completion later.</p>
                    </a>
                  </div>
                </div>

                <?php if ($selectedView === 'rating_sheet'): ?>
                <div class="card report-card mb-4" id="rating-sheet-report">
                  <div class="card-body p-4">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                      <div>
                        <span class="badge bg-label-primary mb-2">EFP-02</span>
                        <h5 class="mb-1">Student Faculty Performance Evaluation Rating Sheet</h5>
                        <p class="text-muted mb-0">Submitted rating rows for <?php echo htmlspecialchars($selectedTermLabel, ENT_QUOTES, 'UTF-8'); ?>.</p>
                      </div>
                      <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-label-info"><?php echo number_format((int)$summary['evaluation_count']); ?> evaluations</span>
                        <span class="badge bg-label-secondary"><?php echo number_format((int)$summary['student_count']); ?> students</span>
                        <span class="view-indicator"><i class="bx bx-show"></i>Dedicated View: Student Rating Sheet</span>
                      </div>
                    </div>

                    <?php if (empty($ratingSheetRows)): ?>
                      <div class="empty-state">No submitted rating-sheet rows were found for this term.</div>
                    <?php else: ?>
                      <div class="report-table-shell">
                        <table class="table table-hover align-middle">
                          <thead>
                            <tr>
                              <th>Submission</th>
                              <th>Student</th>
                              <th>Faculty</th>
                              <th>Subjects</th>
                              <th>Rating</th>
                              <th>Comment/s</th>
                              <th>Submitted</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($ratingSheetRows as $row): ?>
                              <tr>
                                <td>
                                  <div class="fw-semibold"><?php echo htmlspecialchars((string)($row['final_submission_token'] ?: $row['evaluation_token']), ENT_QUOTES, 'UTF-8'); ?></div>
                                  <div class="small text-muted"><?php echo htmlspecialchars((string)$selectedTermLabel, ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td>
                                  <div class="fw-semibold"><?php echo htmlspecialchars((string)$row['student_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                  <div class="small text-muted"><?php echo htmlspecialchars((string)($row['student_number'] ?: 'No student number'), ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars((string)($row['faculty_name'] ?: 'Instructor not linked'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($row['subject_summary'] ?: 'No subject summary'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                  <div class="fw-semibold"><?php echo number_format((float)$row['average_mean'], 2); ?> / 5.00</div>
                                  <div class="small text-success"><?php echo number_format((float)$row['average_percentage'], 2); ?>%</div>
                                </td>
                                <td><?php echo htmlspecialchars((string)($row['comment_text'] !== '' ? $row['comment_text'] : 'No comment provided.'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(student_management_format_datetime((string)($row['final_submitted_at'] ?: $row['completed_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                </div>
                <?php endif; ?>
              </div>
            </div>
                </div>
                <?php elseif ($selectedView === 'individual'): ?>

                <div class="card report-card mb-4" id="individual-report">
                  <div class="card-body p-4">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                      <div>
                        <span class="badge bg-label-success mb-2">EFP-05</span>
                        <h5 class="mb-1">Individual Faculty Performance Evaluation</h5>
                        <p class="text-muted mb-0">Student-rating component only. Supervisor and total rating remain manual by design.</p>
                      </div>
                      <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-label-info"><?php echo number_format(count($individualRows)); ?> faculty rows</span>
                        <span class="badge bg-label-warning">Supervisor component pending</span>
                        <span class="view-indicator"><i class="bx bx-show"></i>Dedicated View: Individual Faculty Report</span>
                      </div>
                    </div>

                    <?php if (empty($individualRows)): ?>
                      <div class="empty-state">No individual faculty report rows were found for this term.</div>
                    <?php else: ?>
                      <?php foreach ($individualRows as $facultyRow): ?>
                        <div class="faculty-report-card">
                          <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                            <div>
                              <h5 class="mb-1"><?php echo htmlspecialchars((string)($facultyRow['faculty_name'] ?: 'Instructor not linked'), ENT_QUOTES, 'UTF-8'); ?></h5>
                              <div class="d-flex flex-wrap gap-2">
                                <span class="badge bg-label-primary"><?php echo number_format((int)$facultyRow['student_count']); ?> students</span>
                                <span class="badge bg-label-info"><?php echo number_format((int)$facultyRow['evaluations_count']); ?> evaluations</span>
                                <span class="badge bg-label-success"><?php echo number_format((float)$facultyRow['overall_percentage'], 2); ?>% student rating</span>
                                <span class="badge bg-label-warning"><?php echo number_format((float)$facultyRow['student_component_percentage'], 2); ?>% weighted 60%</span>
                              </div>
                            </div>
                            <div class="text-lg-end">
                              <div class="small text-muted">Overall Mean</div>
                              <div class="h4 mb-0 text-primary"><?php echo number_format((float)$facultyRow['overall_mean'], 2); ?> / 5.00</div>
                            </div>
                          </div>

                          <div class="category-grid mb-3">
                            <?php foreach ((array)($facultyRow['categories'] ?? []) as $categoryRow): ?>
                              <div class="category-box">
                                <div class="small text-muted mb-2"><?php echo htmlspecialchars((string)($categoryRow['title'] ?? 'Category'), ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="fw-semibold"><?php echo number_format((float)($categoryRow['mean'] ?? 0), 2); ?> / 5.00</div>
                                <div class="small text-success mt-1"><?php echo number_format((float)($categoryRow['percentage'] ?? 0), 2); ?>%</div>
                              </div>
                            <?php endforeach; ?>
                          </div>

                          <div class="row g-3">
                            <div class="col-lg-5">
                              <div class="border rounded-3 h-100 p-3">
                                <div class="fw-semibold mb-2">Students included</div>
                                <?php if (!empty($facultyRow['students'])): ?>
                                  <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ((array)$facultyRow['students'] as $studentNumber): ?>
                                      <span class="badge bg-label-secondary"><?php echo htmlspecialchars((string)$studentNumber, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endforeach; ?>
                                  </div>
                                <?php else: ?>
                                  <div class="text-muted small">No student numbers were saved in the submitted rows.</div>
                                <?php endif; ?>
                              </div>
                            </div>
                            <div class="col-lg-7">
                              <div class="border rounded-3 h-100 p-3">
                                <div class="fw-semibold mb-2">Comment/s (Unedited)</div>
                                <?php if (!empty($facultyRow['comments'])): ?>
                                  <div class="d-grid gap-2">
                                    <?php foreach ((array)$facultyRow['comments'] as $commentRow): ?>
                                      <div class="comment-item">
                                        <div class="small text-muted mb-1"><?php echo htmlspecialchars((string)(($commentRow['student_number'] ?? '') !== '' ? 'Student No. ' . $commentRow['student_number'] : 'Student comment'), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div><?php echo nl2br(htmlspecialchars((string)($commentRow['comment_text'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></div>
                                      </div>
                                    <?php endforeach; ?>
                                  </div>
                                <?php else: ?>
                                  <div class="text-muted small">No comments were submitted for this faculty.</div>
                                <?php endif; ?>
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </div>
                </div>
                <?php else: ?>

                <div class="card report-card" id="consolidated-report">
                  <div class="card-body p-4">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                      <div>
                        <span class="badge bg-label-warning mb-2">EFP-04</span>
                        <h5 class="mb-1">Consolidated Faculty Performance Evaluation</h5>
                        <p class="text-muted mb-0">Student-side consolidation only. Supervisor rating and final total are left manual on purpose.</p>
                      </div>
                      <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-label-info"><?php echo number_format(count($consolidatedRows)); ?> faculty rows</span>
                        <span class="badge bg-label-warning">Supervisor columns pending manual completion</span>
                        <span class="view-indicator"><i class="bx bx-show"></i>Dedicated View: Consolidated Report</span>
                      </div>
                    </div>

                    <?php if (empty($consolidatedRows)): ?>
                      <div class="empty-state">No consolidated report rows were found for this term.</div>
                    <?php else: ?>
                      <div class="report-table-shell">
                        <table class="table table-hover align-middle">
                          <thead>
                            <tr>
                              <th>Name of Faculty</th>
                              <th>Students</th>
                              <th>Evaluations</th>
                              <th>Students Rating (100%)</th>
                              <th>Students Rating (60%)</th>
                              <th>Supervisors Rating (40%)</th>
                              <th>Total (100%)</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($consolidatedRows as $row): ?>
                              <tr>
                                <td><?php echo htmlspecialchars((string)($row['faculty_name'] ?: 'Instructor not linked'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo number_format((int)$row['student_count']); ?></td>
                                <td><?php echo number_format((int)$row['evaluations_count']); ?></td>
                                <td>
                                  <div class="fw-semibold"><?php echo number_format((float)$row['student_rating_percentage'], 2); ?>%</div>
                                  <div class="small text-muted"><?php echo number_format((float)$row['student_rating_mean'], 2); ?> / 5.00</div>
                                </td>
                                <td><?php echo number_format((float)$row['student_rating_component'], 2); ?>%</td>
                                <td><span class="badge bg-label-warning">Manual</span></td>
                                <td><span class="badge bg-label-secondary">Pending supervisor input</span></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
                <?php endif; ?>
              <?php endif; ?>
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
    <script src="../../assets/js/main.js"></script>
    <script>
      (function () {
        var workspaceId = 'facultyEvaluationReportsWorkspace';

        function getWorkspace() {
          return document.getElementById(workspaceId);
        }

        function normalizeUrl(input) {
          var nextUrl = new URL(input, window.location.href);
          nextUrl.hash = '';
          return nextUrl.toString();
        }

        async function loadWorkspace(url, historyMode) {
          var workspace = getWorkspace();
          if (!workspace) {
            return;
          }

          var resolvedUrl = normalizeUrl(url);
          var savedScrollY = window.scrollY || window.pageYOffset || 0;
          workspace.classList.add('is-loading');

          try {
            var response = await fetch(resolvedUrl, {
              headers: {
                'X-Requested-With': 'XMLHttpRequest'
              },
              credentials: 'same-origin'
            });

            if (!response.ok) {
              throw new Error('Unable to load the requested report view.');
            }

            var html = await response.text();
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            var nextWorkspace = doc.getElementById(workspaceId);

            if (!nextWorkspace) {
              throw new Error('The report workspace could not be refreshed.');
            }

            workspace.innerHTML = nextWorkspace.innerHTML;
            workspace.setAttribute('data-current-view', nextWorkspace.getAttribute('data-current-view') || '');
            workspace.setAttribute('data-current-term', nextWorkspace.getAttribute('data-current-term') || '');

            if (historyMode === 'push') {
              window.history.pushState({ url: resolvedUrl }, '', resolvedUrl);
            } else if (historyMode === 'replace') {
              window.history.replaceState({ url: resolvedUrl }, '', resolvedUrl);
            }

            window.scrollTo(0, savedScrollY);
          } catch (error) {
            window.location.href = resolvedUrl;
          } finally {
            workspace.classList.remove('is-loading');
          }
        }

        document.addEventListener('click', function (event) {
          var link = event.target.closest('a[data-report-nav="1"]');
          if (!link) {
            return;
          }

          event.preventDefault();
          loadWorkspace(link.href, 'push');
        });

        document.addEventListener('submit', function (event) {
          var form = event.target.closest('#facultyEvaluationReportFilterForm');
          if (!form) {
            return;
          }

          event.preventDefault();
          var formData = new FormData(form);
          var nextUrl = new URL(form.action || window.location.href, window.location.href);

          formData.forEach(function (value, key) {
            if (String(value).trim() !== '') {
              nextUrl.searchParams.set(key, value);
            }
          });

          loadWorkspace(nextUrl.toString(), 'push');
        });

        window.addEventListener('popstate', function () {
          loadWorkspace(window.location.href, 'replace');
        });
      })();
    </script>
  </body>
</html>
