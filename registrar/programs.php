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
$programRows = synk_registrar_program_summary_rows($termRows);
$snapshot = synk_registrar_dashboard_snapshot($termRows);
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <title>Registrar Programs | Synk</title>
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
                  <div class="card-body p-3">Enrollment draft tables are not ready yet. Run the provided SQL first so program-level registrar counts can load.</div>
                </div>
              <?php elseif (!$registrarCampus): ?>
                <div class="card registrar-note-card mb-4">
                  <div class="card-body p-3">Assign a registrar campus in Access Accounts so this page can load the correct program scope.</div>
                </div>
              <?php endif; ?>

              <div class="card registrar-hero-card mb-4">
                <div class="card-body p-4">
                  <div class="row g-4 align-items-center">
                    <div class="col-lg-8">
                      <span class="registrar-kicker"><i class="bx bx-book-content"></i> Programs</span>
                      <h3 class="mt-3 mb-2">Program and course-level registrar workload</h3>
                      <p class="text-muted mb-0">This page helps the campus registrar see which programs are generating the most draft movement, queue pressure, and approved enrollment volume for the active term.</p>
                    </div>
                    <div class="col-lg-4">
                      <div class="registrar-metric-grid">
                        <div class="registrar-metric-card">
                          <span class="registrar-metric-label">Current Term</span>
                          <span class="registrar-metric-value" style="font-size:1.2rem;"><?php echo htmlspecialchars($registrarTermText, ENT_QUOTES, 'UTF-8'); ?></span>
                          <span class="registrar-metric-caption">All program counts are term-based.</span>
                        </div>
                        <div class="registrar-metric-card">
                          <span class="registrar-metric-label">Programs Active</span>
                          <span class="registrar-metric-value" style="font-size:1.2rem;"><?php echo number_format(count($programRows)); ?></span>
                          <span class="registrar-metric-caption"><?php echo number_format((int)($snapshot['total_records'] ?? 0)); ?> total campus records across active programs.</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="registrar-metric-grid mb-4">
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">Program Rows</span>
                  <span class="registrar-metric-value"><?php echo number_format(count($programRows)); ?></span>
                  <span class="registrar-metric-caption">Distinct programs with registrar activity this term.</span>
                </div>
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">Subject Rows</span>
                  <span class="registrar-metric-value"><?php echo number_format((int)($snapshot['total_subjects'] ?? 0)); ?></span>
                  <span class="registrar-metric-caption">All stored subject lines under the current term drafts.</span>
                </div>
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">Total Units</span>
                  <span class="registrar-metric-value"><?php echo htmlspecialchars(number_format((float)($snapshot['total_units'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></span>
                  <span class="registrar-metric-caption">Units recorded across all active program transactions.</span>
                </div>
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">Registrar Queue</span>
                  <span class="registrar-metric-value"><?php echo number_format((int)($snapshot['registrar_queue'] ?? 0)); ?></span>
                  <span class="registrar-metric-caption">Current submitted items already on the registrar side.</span>
                </div>
              </div>

              <div class="card registrar-table-card">
                <div class="card-body p-0">
                  <div class="d-flex justify-content-between gap-3 align-items-start">
                    <div>
                      <span class="registrar-kicker"><i class="bx bx-spreadsheet"></i> Program Matrix</span>
                      <h4 class="mt-3 mb-2">Current-term program breakdown</h4>
                      <p class="text-muted mb-0">This table is useful when the registrar office needs to compare which programs are still with Program Chair, already submitted, or already approved.</p>
                    </div>
                    <a href="index.php" class="btn btn-outline-primary btn-sm">Open Dashboard</a>
                  </div>

                  <?php if (empty($programRows)): ?>
                    <div class="registrar-empty-state mt-3">No program-level registrar records are available for the active term yet.</div>
                  <?php else: ?>
                    <div class="table-responsive mt-3">
                      <table class="table registrar-table mb-0">
                        <thead>
                          <tr>
                            <th>Program</th>
                            <th>College</th>
                            <th class="text-center">Records</th>
                            <th class="text-center">Draft / Returned</th>
                            <th class="text-center">Submitted</th>
                            <th class="text-center">Approved</th>
                            <th class="text-center">Subjects</th>
                            <th class="text-center">Units</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($programRows as $programRow): ?>
                            <tr>
                              <td>
                                <strong><?php echo htmlspecialchars((string)($programRow['program_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <div class="registrar-table-subtext"><?php echo htmlspecialchars((string)($programRow['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                              </td>
                              <td>
                                <div><?php echo htmlspecialchars((string)($programRow['college_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="registrar-table-subtext"><?php echo htmlspecialchars((string)($programRow['college_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                              </td>
                              <td class="text-center"><?php echo number_format((int)($programRow['record_count'] ?? 0)); ?></td>
                              <td class="text-center"><?php echo number_format((int)($programRow['draft_count'] ?? 0) + (int)($programRow['returned_count'] ?? 0)); ?></td>
                              <td class="text-center"><?php echo number_format((int)($programRow['submitted_count'] ?? 0)); ?></td>
                              <td class="text-center"><?php echo number_format((int)($programRow['approved_count'] ?? 0)); ?></td>
                              <td class="text-center"><?php echo number_format((int)($programRow['subject_count'] ?? 0)); ?></td>
                              <td class="text-center"><?php echo htmlspecialchars(number_format((float)($programRow['total_units'] ?? 0), 2), ENT_QUOTES, 'UTF-8'); ?></td>
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
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
  </body>
</html>
