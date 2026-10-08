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

$statusMatrix = [];
foreach ((array)($statusChart['labels'] ?? []) as $index => $label) {
    $statusMatrix[] = [
        'label' => (string)$label,
        'count' => (int)($statusChart['series'][$index] ?? 0),
    ];
}

$recentRows = $termRows;
usort($recentRows, static function (array $left, array $right): int {
    $leftTime = strtotime((string)($left['submitted_at'] ?? $left['updated_at'] ?? $left['created_at'] ?? ''));
    $rightTime = strtotime((string)($right['submitted_at'] ?? $right['updated_at'] ?? $right['created_at'] ?? ''));
    return $rightTime <=> $leftTime;
});
$recentRows = array_slice($recentRows, 0, 12);
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <title>Registrar Reports | Synk</title>
    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
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
                  <div class="card-body p-3">Enrollment draft tables are not ready yet. Run the provided SQL first so registrar reports can load live term data.</div>
                </div>
              <?php elseif (!$registrarCampus): ?>
                <div class="card registrar-note-card mb-4">
                  <div class="card-body p-3">Assign a registrar campus in Access Accounts so this report pack can load the correct campus scope.</div>
                </div>
              <?php endif; ?>

              <div class="card registrar-hero-card mb-4">
                <div class="card-body p-4">
                  <div class="row g-4 align-items-center">
                    <div class="col-lg-8">
                      <span class="registrar-kicker"><i class="bx bx-bar-chart-alt-2"></i> Reports</span>
                      <h3 class="mt-3 mb-2">Registrar operational report pack</h3>
                      <p class="text-muted mb-0">This page keeps the registrar reporting view practical: workflow totals, latest transaction lines, and ready-reference tables that can support daily campus registrar monitoring.</p>
                    </div>
                    <div class="col-lg-4">
                      <div class="registrar-metric-grid">
                        <div class="registrar-metric-card">
                          <span class="registrar-metric-label">Current Term</span>
                          <span class="registrar-metric-value" style="font-size:1.2rem;"><?php echo htmlspecialchars($registrarTermText, ENT_QUOTES, 'UTF-8'); ?></span>
                          <span class="registrar-metric-caption">Reports are scoped to the active academic term.</span>
                        </div>
                        <div class="registrar-metric-card">
                          <span class="registrar-metric-label">Campus Scope</span>
                          <span class="registrar-metric-value" style="font-size:1.2rem;"><?php echo htmlspecialchars(trim((string)($registrarCampus['campus_name'] ?? 'Campus required')), ENT_QUOTES, 'UTF-8'); ?></span>
                          <span class="registrar-metric-caption"><?php echo number_format((int)($snapshot['total_records'] ?? 0)); ?> total campus transactions.</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="registrar-metric-grid mb-4">
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">Ongoing Enrollees</span>
                  <span class="registrar-metric-value"><?php echo number_format((int)($snapshot['ongoing_enrollees'] ?? 0)); ?></span>
                  <span class="registrar-metric-caption">Transactions still active in the workflow.</span>
                </div>
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">Draft / Returned</span>
                  <span class="registrar-metric-value"><?php echo number_format((int)($snapshot['chair_drafts'] ?? 0)); ?></span>
                  <span class="registrar-metric-caption">Still parked with Program Chair for preparation or correction.</span>
                </div>
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">Submitted Queue</span>
                  <span class="registrar-metric-value"><?php echo number_format((int)($snapshot['registrar_queue'] ?? 0)); ?></span>
                  <span class="registrar-metric-caption">Already waiting in the registrar queue.</span>
                </div>
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">Approved / Posted</span>
                  <span class="registrar-metric-value"><?php echo number_format((int)($snapshot['approved_posted'] ?? 0)); ?></span>
                  <span class="registrar-metric-caption">Completed registrar-side processing.</span>
                </div>
              </div>

              <div class="registrar-split-grid">
                <div class="card registrar-table-card">
                  <div class="card-body p-0">
                    <span class="registrar-kicker"><i class="bx bx-list-check"></i> Status Summary</span>
                    <h4 class="mt-3 mb-2">Workflow totals</h4>
                    <p class="text-muted mb-0">A compact summary of how current-term records are distributed by workflow state.</p>
                    <?php if (empty($statusMatrix)): ?>
                      <div class="registrar-empty-state mt-3">No workflow totals are available yet for the active term.</div>
                    <?php else: ?>
                      <div class="table-responsive mt-3">
                        <table class="table registrar-table mb-0">
                          <thead>
                            <tr>
                              <th>Status</th>
                              <th class="text-center">Count</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($statusMatrix as $statusRow): ?>
                              <tr>
                                <td><?php echo htmlspecialchars((string)($statusRow['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-center"><?php echo number_format((int)($statusRow['count'] ?? 0)); ?></td>
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
                    <span class="registrar-kicker"><i class="bx bx-collection"></i> Scope Summary</span>
                    <h4 class="mt-3 mb-2">Campus coverage</h4>
                    <p class="text-muted mb-0">This section gives a quick count of where registrar activity is spread in the active term.</p>
                    <div class="registrar-list-stack mt-3">
                      <div class="registrar-list-row">
                        <strong>Colleges with records</strong>
                        <div class="registrar-table-subtext mt-1"><?php echo number_format((int)($snapshot['college_count'] ?? 0)); ?> colleges currently active in this registrar scope.</div>
                      </div>
                      <div class="registrar-list-row">
                        <strong>Programs with records</strong>
                        <div class="registrar-table-subtext mt-1"><?php echo number_format((int)($snapshot['program_count'] ?? 0)); ?> programs currently active in this registrar scope.</div>
                      </div>
                      <div class="registrar-list-row">
                        <strong>Stored subject lines</strong>
                        <div class="registrar-table-subtext mt-1"><?php echo number_format((int)($snapshot['total_subjects'] ?? 0)); ?> subject rows totaling <?php echo htmlspecialchars(number_format((float)($snapshot['total_units'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?> units.</div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card registrar-table-card registrar-dashboard-section">
                <div class="card-body p-0">
                  <div class="d-flex justify-content-between gap-3 align-items-start">
                    <div>
                      <span class="registrar-kicker"><i class="bx bx-history"></i> Recent Transactions</span>
                      <h4 class="mt-3 mb-2">Latest registrar-facing records</h4>
                      <p class="text-muted mb-0">This running list helps the registrar office review the most recent enrollment transactions without leaving the report page.</p>
                    </div>
                    <a href="queue.php" class="btn btn-outline-primary btn-sm">Open Queue</a>
                  </div>

                  <?php if (empty($recentRows)): ?>
                    <div class="registrar-empty-state mt-3">No recent registrar transactions are available for the active term yet.</div>
                  <?php else: ?>
                    <div class="table-responsive mt-3">
                      <table class="table registrar-table mb-0">
                        <thead>
                          <tr>
                            <th>Reference</th>
                            <th>Student</th>
                            <th>College / Program</th>
                            <th>Status</th>
                            <th>Subjects / Units</th>
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
                              <td><?php echo number_format((int)($recentRow['subject_count'] ?? 0)); ?> / <?php echo htmlspecialchars(number_format((float)($recentRow['total_units'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></td>
                              <td><?php echo htmlspecialchars((string)($recentRow['submitted_at'] ?? $recentRow['updated_at'] ?? $recentRow['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="registrar-split-grid registrar-dashboard-section">
                <div class="card registrar-table-card">
                  <div class="card-body p-0">
                    <div class="d-flex justify-content-between gap-3 align-items-start">
                      <div>
                        <span class="registrar-kicker"><i class="bx bx-buildings"></i> Top Colleges</span>
                        <h4 class="mt-3 mb-2">Colleges with the largest registrar workload</h4>
                        <p class="text-muted mb-0">Useful for spotting which colleges may need closer queue attention.</p>
                      </div>
                      <a href="colleges.php" class="btn btn-outline-primary btn-sm">View Colleges</a>
                    </div>
                    <?php if (empty($collegeRows)): ?>
                      <div class="registrar-empty-state mt-3">No college report rows are available yet.</div>
                    <?php else: ?>
                      <div class="registrar-list-stack mt-3">
                        <?php foreach (array_slice($collegeRows, 0, 5) as $collegeRow): ?>
                          <div class="registrar-list-row">
                            <strong><?php echo htmlspecialchars((string)($collegeRow['college_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <div class="registrar-table-subtext mt-1"><?php echo htmlspecialchars((string)($collegeRow['college_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="registrar-inline-meta mt-3">
                              <span class="registrar-inline-chip"><?php echo number_format((int)($collegeRow['record_count'] ?? 0)); ?> records</span>
                              <span class="registrar-inline-chip"><?php echo number_format((int)($collegeRow['submitted_count'] ?? 0)); ?> submitted</span>
                              <span class="registrar-inline-chip"><?php echo number_format((int)($collegeRow['approved_count'] ?? 0)); ?> approved</span>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="card registrar-table-card">
                  <div class="card-body p-0">
                    <div class="d-flex justify-content-between gap-3 align-items-start">
                      <div>
                        <span class="registrar-kicker"><i class="bx bx-book-content"></i> Top Programs</span>
                        <h4 class="mt-3 mb-2">Programs with the largest registrar workload</h4>
                        <p class="text-muted mb-0">A quick reference list for the busiest programs in this campus term.</p>
                      </div>
                      <a href="programs.php" class="btn btn-outline-primary btn-sm">View Programs</a>
                    </div>
                    <?php if (empty($programRows)): ?>
                      <div class="registrar-empty-state mt-3">No program report rows are available yet.</div>
                    <?php else: ?>
                      <div class="registrar-list-stack mt-3">
                        <?php foreach (array_slice($programRows, 0, 5) as $programRow): ?>
                          <div class="registrar-list-row">
                            <strong><?php echo htmlspecialchars((string)($programRow['program_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                            <div class="registrar-table-subtext mt-1"><?php echo htmlspecialchars((string)($programRow['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="registrar-inline-meta mt-3">
                              <span class="registrar-inline-chip"><?php echo number_format((int)($programRow['record_count'] ?? 0)); ?> records</span>
                              <span class="registrar-inline-chip"><?php echo number_format((int)($programRow['submitted_count'] ?? 0)); ?> submitted</span>
                              <span class="registrar-inline-chip"><?php echo htmlspecialchars(number_format((float)($programRow['total_units'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?> units</span>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    <?php endif; ?>
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
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
  </body>
</html>
