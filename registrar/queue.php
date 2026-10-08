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
$draftTablesReady = synk_enrollment_draft_tables_ready($conn);
$queueRows = ($draftTablesReady && $campusId > 0)
    ? synk_enrollment_fetch_registrar_queue_rows($conn, $campusId)
    : [];

$queueCounts = [
    'total' => count($queueRows),
    'submitted' => 0,
    'returned' => 0,
    'approved' => 0,
];
foreach ($queueRows as $queueRow) {
    $status = strtolower(trim((string)($queueRow['workflow_status'] ?? 'submitted')));
    if ($status === 'submitted') {
        $queueCounts['submitted']++;
    } elseif ($status === 'returned') {
        $queueCounts['returned']++;
    } elseif (in_array($status, ['approved', 'posted'], true)) {
        $queueCounts['approved']++;
    }
}

$selectedEnrollmentId = max(0, (int)($_GET['enrollment_id'] ?? 0));
if ($selectedEnrollmentId <= 0 && !empty($queueRows)) {
    $selectedEnrollmentId = (int)($queueRows[0]['enrollment_id'] ?? 0);
}

$selectedQueueDetail = ($draftTablesReady && $campusId > 0 && $selectedEnrollmentId > 0)
    ? synk_enrollment_fetch_registrar_detail($conn, $selectedEnrollmentId, $campusId)
    : null;

function registrar_queue_section_label(array $row): string
{
    $sectionLabel = trim((string)($row['full_section'] ?? ''));
    if ($sectionLabel === '') {
        $sectionLabel = trim((string)($row['section_name'] ?? ''));
    }

    return $sectionLabel !== '' ? $sectionLabel : 'Section pending';
}

function registrar_queue_timestamp_label(array $row): string
{
    $value = trim((string)($row['submitted_at'] ?? $row['updated_at'] ?? $row['created_at'] ?? ''));
    return $value !== '' ? $value : 'Pending timestamp';
}
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <title>Registrar Queue | Synk</title>
    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" type="text/css" href="custom_css.css" />
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
    <style>
      .registrar-queue-detail-grid {
        display: grid;
        gap: 1rem;
        grid-template-columns: 1.15fr 0.85fr;
      }
      @media (max-width: 991.98px) {
        .registrar-queue-detail-grid {
          grid-template-columns: 1fr;
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
              <?php if (!$draftTablesReady): ?>
                <div class="card registrar-note-card mb-4">
                  <div class="card-body p-3">Enrollment draft tables are not ready yet. Run the provided SQL first so campus registrar queue pages can load live data.</div>
                </div>
              <?php elseif (!$registrarCampus): ?>
                <div class="card registrar-note-card mb-4">
                  <div class="card-body p-3">Assign a registrar campus in Access Accounts so this account can open the correct registrar queue.</div>
                </div>
              <?php endif; ?>

              <div class="card registrar-hero-card mb-4">
                <div class="card-body p-4">
                  <div class="row g-4 align-items-center">
                    <div class="col-lg-8">
                      <span class="registrar-kicker"><i class="bx bx-clipboard-check"></i> Registrar Queue</span>
                      <h3 class="mt-3 mb-2">Submitted drafts ready for campus review</h3>
                      <p class="text-muted mb-0">This page keeps the registrar-facing queue separate from the dashboard. Use it to inspect each draft handed off by Program Chair, confirm the student load, and monitor how items move through registrar review.</p>
                    </div>
                    <div class="col-lg-4">
                      <div class="registrar-metric-grid">
                        <div class="registrar-metric-card">
                          <span class="registrar-metric-label">Current Term</span>
                          <span class="registrar-metric-value" style="font-size:1.2rem;"><?php echo htmlspecialchars($registrarTermText, ENT_QUOTES, 'UTF-8'); ?></span>
                          <span class="registrar-metric-caption">Queue is scoped to the active academic term.</span>
                        </div>
                        <div class="registrar-metric-card">
                          <span class="registrar-metric-label">Campus Scope</span>
                          <span class="registrar-metric-value" style="font-size:1.2rem;"><?php echo htmlspecialchars(trim((string)($registrarCampus['campus_name'] ?? 'Campus required')), ENT_QUOTES, 'UTF-8'); ?></span>
                          <span class="registrar-metric-caption">Only drafts for this campus can appear here.</span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="registrar-metric-grid mb-4">
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">Total Queue Rows</span>
                  <span class="registrar-metric-value"><?php echo number_format($queueCounts['total']); ?></span>
                  <span class="registrar-metric-caption">All registrar-facing records in this campus scope.</span>
                </div>
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">Submitted</span>
                  <span class="registrar-metric-value"><?php echo number_format($queueCounts['submitted']); ?></span>
                  <span class="registrar-metric-caption">Waiting for registrar action.</span>
                </div>
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">Returned</span>
                  <span class="registrar-metric-value"><?php echo number_format($queueCounts['returned']); ?></span>
                  <span class="registrar-metric-caption">Sent back to Program Chair for adjustment.</span>
                </div>
                <div class="registrar-metric-card">
                  <span class="registrar-metric-label">Approved / Posted</span>
                  <span class="registrar-metric-value"><?php echo number_format($queueCounts['approved']); ?></span>
                  <span class="registrar-metric-caption">Already reviewed on the registrar side.</span>
                </div>
              </div>

              <div class="card registrar-table-card mb-4">
                <div class="card-body p-0">
                  <div class="p-4 pb-3">
                    <span class="registrar-kicker"><i class="bx bx-list-ul"></i> Queue List</span>
                    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start mt-3">
                      <div>
                        <h4 class="mb-2">Registrar worklist for this campus</h4>
                        <p class="text-muted mb-0">Open any row to inspect the student load, subject list, and workflow trail. This page stays focused on review and queue handling only.</p>
                      </div>
                      <a href="index.php" class="btn btn-outline-primary btn-sm">
                        <i class="bx bx-grid-alt me-1"></i> Open Dashboard
                      </a>
                    </div>
                  </div>

                  <?php if (!$draftTablesReady): ?>
                    <div class="p-4 pt-0">
                      <div class="alert alert-warning mb-0">Run the enrollment draft SQL first, then refresh this page.</div>
                    </div>
                  <?php elseif (empty($queueRows)): ?>
                    <div class="p-4 pt-0">
                      <div class="registrar-empty-state">No registrar queue rows are available for this campus yet.</div>
                    </div>
                  <?php else: ?>
                    <div class="table-responsive px-4 pb-4">
                      <table class="table registrar-table mb-0">
                        <thead>
                          <tr>
                            <th>Reference</th>
                            <th>Student</th>
                            <th>Program / Section</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th class="text-end">Action</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($queueRows as $queueRow): ?>
                            <tr>
                              <td>
                                <strong><?php echo htmlspecialchars((string)($queueRow['enrollment_reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <div class="registrar-table-subtext"><?php echo htmlspecialchars((string)($queueRow['college_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                              </td>
                              <td><?php echo htmlspecialchars(synk_registrar_student_label($queueRow), ENT_QUOTES, 'UTF-8'); ?></td>
                              <td>
                                <div><?php echo htmlspecialchars((string)($queueRow['program_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="registrar-table-subtext"><?php echo htmlspecialchars(registrar_queue_section_label($queueRow), ENT_QUOTES, 'UTF-8'); ?></div>
                              </td>
                              <td>
                                <span class="badge <?php echo htmlspecialchars(synk_registrar_status_badge_class((string)($queueRow['workflow_status'] ?? 'submitted')), ENT_QUOTES, 'UTF-8'); ?>">
                                  <?php echo htmlspecialchars((string)($queueRow['status_label'] ?? 'Submitted'), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                              </td>
                              <td><?php echo htmlspecialchars(registrar_queue_timestamp_label($queueRow), ENT_QUOTES, 'UTF-8'); ?></td>
                              <td class="text-end">
                                <a href="queue.php?enrollment_id=<?php echo (int)($queueRow['enrollment_id'] ?? 0); ?>" class="btn btn-outline-primary btn-sm">Open</a>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($selectedQueueDetail): ?>
                <div class="registrar-queue-detail-grid">
                  <div class="card registrar-shell-card">
                    <div class="card-body p-0">
                      <div class="p-4 pb-3">
                        <span class="registrar-kicker"><i class="bx bx-file"></i> Queue Detail</span>
                        <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start mt-3 mb-3">
                          <div>
                            <h4 class="mb-1"><?php echo htmlspecialchars((string)($selectedQueueDetail['enrollment_reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h4>
                            <p class="text-muted mb-0"><?php echo htmlspecialchars((string)($selectedQueueDetail['program_name'] ?? 'Program not set'), ENT_QUOTES, 'UTF-8'); ?></p>
                          </div>
                          <span class="badge <?php echo htmlspecialchars(synk_registrar_status_badge_class((string)($selectedQueueDetail['workflow_status'] ?? 'submitted')), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars((string)($selectedQueueDetail['status_label'] ?? 'Submitted to Registrar'), ENT_QUOTES, 'UTF-8'); ?>
                          </span>
                        </div>

                        <div class="registrar-summary-grid mb-4">
                          <div class="registrar-metric-card">
                            <span class="registrar-metric-label">Student</span>
                            <span class="registrar-metric-value" style="font-size:1.15rem;"><?php echo htmlspecialchars(synk_registrar_student_label($selectedQueueDetail), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="registrar-metric-caption">Draft student profile captured by Program Chair.</span>
                          </div>
                          <div class="registrar-metric-card">
                            <span class="registrar-metric-label">Section</span>
                            <span class="registrar-metric-value" style="font-size:1.15rem;"><?php echo htmlspecialchars(registrar_queue_section_label($selectedQueueDetail), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="registrar-metric-caption">Live section context carried into the draft.</span>
                          </div>
                          <div class="registrar-metric-card">
                            <span class="registrar-metric-label">Load Summary</span>
                            <span class="registrar-metric-value" style="font-size:1.15rem;"><?php echo (int)($selectedQueueDetail['subject_count'] ?? 0); ?> subjects</span>
                            <span class="registrar-metric-caption"><?php echo htmlspecialchars((string)($selectedQueueDetail['total_units'] ?? 0), ENT_QUOTES, 'UTF-8'); ?> total units in this draft.</span>
                          </div>
                          <div class="registrar-metric-card">
                            <span class="registrar-metric-label">Chair Notes</span>
                            <span class="registrar-metric-value" style="font-size:1.05rem;"><?php echo htmlspecialchars((string)($selectedQueueDetail['chair_notes'] ?? 'No chair notes'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="registrar-metric-caption">Use this note as the starting review context.</span>
                          </div>
                        </div>
                      </div>

                      <div class="table-responsive px-4 pb-4">
                        <table class="table registrar-table mb-0">
                          <thead>
                            <tr>
                              <th>Course</th>
                              <th>Schedule</th>
                              <th>Faculty / Room</th>
                              <th class="text-center">Units</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php if (!empty($selectedQueueDetail['subjects'])): ?>
                              <?php foreach ((array)$selectedQueueDetail['subjects'] as $subjectRow): ?>
                                <tr>
                                  <td>
                                    <strong><?php echo htmlspecialchars((string)($subjectRow['subject_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <div><?php echo htmlspecialchars((string)($subjectRow['descriptive_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                  </td>
                                  <td><?php echo htmlspecialchars((string)($subjectRow['schedule_text'] ?? 'Schedule pending'), ENT_QUOTES, 'UTF-8'); ?></td>
                                  <td>
                                    <div><?php echo htmlspecialchars((string)($subjectRow['faculty_text'] ?? 'TBA'), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="registrar-table-subtext"><?php echo htmlspecialchars((string)($subjectRow['room_text'] ?? 'TBA'), ENT_QUOTES, 'UTF-8'); ?></div>
                                  </td>
                                  <td class="text-center"><?php echo htmlspecialchars((string)($subjectRow['units'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                              <?php endforeach; ?>
                            <?php else: ?>
                              <tr>
                                <td colspan="4" class="text-center py-4 text-muted">No subject rows are stored for this draft yet.</td>
                              </tr>
                            <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>

                  <div class="card registrar-shell-card">
                    <div class="card-body p-0">
                      <div class="p-4">
                        <span class="registrar-kicker"><i class="bx bx-history"></i> Workflow Log</span>
                        <h5 class="mt-3 mb-3">Tracking trail</h5>
                        <?php if (!empty($selectedQueueDetail['workflow_logs'])): ?>
                          <div class="registrar-list-stack">
                            <?php foreach ((array)$selectedQueueDetail['workflow_logs'] as $logRow): ?>
                              <div class="registrar-list-row">
                                <div class="d-flex justify-content-between gap-3 align-items-start">
                                  <div>
                                    <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($logRow['action_type'] ?? 'updated'))), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <div class="registrar-table-subtext mt-1"><?php echo htmlspecialchars((string)($logRow['remarks'] ?? 'No remarks'), ENT_QUOTES, 'UTF-8'); ?></div>
                                  </div>
                                  <span class="badge bg-label-secondary"><?php echo htmlspecialchars((string)($logRow['acted_by_role'] ?? 'system'), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <div class="registrar-table-subtext mt-3">
                                  <?php echo htmlspecialchars((string)($logRow['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                  <?php if (trim((string)($logRow['actor_name'] ?? '')) !== ''): ?>
                                    by <?php echo htmlspecialchars((string)($logRow['actor_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                  <?php endif; ?>
                                </div>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        <?php else: ?>
                          <div class="registrar-empty-state">No workflow log entries are available yet for this draft.</div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endif; ?>
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
