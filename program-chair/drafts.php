<?php
session_start();
ob_start();

include '../backend/db.php';
require_once '../backend/program_chair_helper.php';
require_once '../backend/enrollment_draft_helper.php';

synk_program_chair_require_login($conn);

$programChairPortalContext = synk_program_chair_portal_context($conn);
$programChairPortalDisplayName = (string)($programChairPortalContext['account_name'] ?? 'Program Chair');
$programChairPortalDisplayEmail = (string)($programChairPortalContext['email'] ?? '');
$programChairCollege = is_array($programChairPortalContext['college'] ?? null)
    ? $programChairPortalContext['college']
    : null;
$programChairCurrentTerm = is_array($programChairPortalContext['current_term'] ?? null)
    ? $programChairPortalContext['current_term']
    : [];
$programChairTermText = trim((string)($programChairCurrentTerm['term_text'] ?? 'Current academic term'));
$collegeId = (int)($programChairPortalContext['college_id'] ?? 0);
$draftTablesReady = synk_enrollment_draft_tables_ready($conn);
$draftRows = $draftTablesReady
    ? synk_enrollment_fetch_program_chair_draft_rows($conn, (int)($_SESSION['user_id'] ?? 0), $collegeId)
    : [];

$draftCounts = [
    'total' => count($draftRows),
    'with_chair' => 0,
    'submitted' => 0,
    'approved' => 0,
];

foreach ($draftRows as $draftRow) {
    $status = strtolower(trim((string)($draftRow['workflow_status'] ?? 'draft')));
    if (in_array($status, ['draft', 'returned'], true)) {
        $draftCounts['with_chair']++;
    }
    if ($status === 'submitted') {
        $draftCounts['submitted']++;
    }
    if (in_array($status, ['approved', 'posted'], true)) {
        $draftCounts['approved']++;
    }
}

$selectedEnrollmentId = max(0, (int)($_GET['enrollment_id'] ?? 0));
if ($selectedEnrollmentId <= 0 && !empty($draftRows)) {
    $selectedEnrollmentId = (int)($draftRows[0]['enrollment_id'] ?? 0);
}

$selectedDraftDetail = $draftTablesReady && $selectedEnrollmentId > 0
    ? synk_enrollment_fetch_program_chair_draft_detail(
        $conn,
        $selectedEnrollmentId,
        (int)($_SESSION['user_id'] ?? 0),
        $collegeId,
        false
    )
    : null;

function program_chair_draft_status_badge_class(string $status): string
{
    $safeStatus = strtolower(trim($status));
    if ($safeStatus === 'submitted') {
        return 'bg-label-warning';
    }
    if ($safeStatus === 'returned') {
        return 'bg-label-danger';
    }
    if ($safeStatus === 'approved' || $safeStatus === 'posted') {
        return 'bg-label-success';
    }

    return 'bg-label-primary';
}

function program_chair_draft_student_label(array $row): string
{
    $nameTail = trim(implode(' ', array_filter([
        (string)($row['first_name'] ?? ''),
        (string)($row['middle_name'] ?? ''),
        (string)($row['suffix_name'] ?? ''),
    ])));
    $name = trim(implode(', ', array_filter([
        (string)($row['last_name'] ?? ''),
        $nameTail,
    ])));

    $label = trim(implode(' ', array_filter([
        (string)($row['student_number'] ?? ''),
        $name,
    ])));

    return $label !== '' ? $label : 'Student profile pending';
}
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <title>Program Chair Draft List | Synk</title>
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
      .draft-card,.draft-hero,.draft-note{border:1px solid #dce5f1;border-radius:22px;box-shadow:0 18px 38px rgba(67,89,113,.08)}
      .draft-hero{background:linear-gradient(135deg,#f8fbff 0%,#eef5ff 56%,#f3f9ef 100%)}
      .draft-note{background:linear-gradient(135deg,#fff8ea 0%,#fffdf6 100%);color:#855b16}
      .draft-kicker{display:inline-flex;align-items:center;gap:.4rem;padding:.35rem .8rem;border-radius:999px;background:#eef4ff;color:#4f6595;font-size:.72rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
      .draft-metric-grid,.draft-detail-grid,.draft-log-grid{display:grid;gap:.95rem}
      .draft-metric-grid{grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}
      .draft-detail-grid{grid-template-columns:1.15fr .85fr}
      .draft-metric,.draft-summary-box,.draft-log-item{border:1px solid #dfe7f2;border-radius:18px;background:#fff}
      .draft-metric,.draft-summary-box,.draft-log-item{padding:1rem 1.1rem}
      .draft-label{display:block;color:#7d8ea5;font-size:.75rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase}
      .draft-value{display:block;margin-top:.35rem;color:#33475b;font-size:1rem;font-weight:700;line-height:1.55}
      .draft-table td,.draft-table th{vertical-align:middle}
      .draft-log-grid{grid-template-columns:1fr}
      .draft-log-item strong{color:#23354a}
      .draft-log-item small{color:#6c7f97}
      @media (max-width:991.98px){.draft-detail-grid{grid-template-columns:1fr}}
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
                <div class="card draft-note mb-4"><div class="card-body p-3">Enrollment draft tables are not ready yet. Run the provided SQL first so saved drafts and registrar queue tracking can appear here.</div></div>
              <?php endif; ?>

              <div class="card draft-hero mb-4">
                <div class="card-body p-4">
                  <div class="row g-4 align-items-center">
                    <div class="col-lg-8">
                      <span class="draft-kicker"><i class="bx bx-folder-open"></i> Draft Tracking</span>
                      <h3 class="mt-3 mb-2">Program Chair draft list</h3>
                      <p class="text-muted mb-0">Review saved enrollment drafts, reopen editable records, and see which student loads are already sitting in the campus registrar queue.</p>
                    </div>
                    <div class="col-lg-4">
                      <div class="draft-metric-grid">
                        <div class="draft-metric"><span class="draft-label">Current Term</span><span class="draft-value"><?php echo htmlspecialchars($programChairTermText, ENT_QUOTES, 'UTF-8'); ?></span></div>
                        <div class="draft-metric"><span class="draft-label">Program Chair</span><span class="draft-value"><?php echo htmlspecialchars($programChairPortalDisplayName, ENT_QUOTES, 'UTF-8'); ?></span></div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="draft-metric-grid mb-4">
                <div class="draft-metric"><span class="draft-label">Total Saved Drafts</span><span class="draft-value"><?php echo number_format($draftCounts['total']); ?></span></div>
                <div class="draft-metric"><span class="draft-label">Still With Chair</span><span class="draft-value"><?php echo number_format($draftCounts['with_chair']); ?></span></div>
                <div class="draft-metric"><span class="draft-label">In Registrar Queue</span><span class="draft-value"><?php echo number_format($draftCounts['submitted']); ?></span></div>
                <div class="draft-metric"><span class="draft-label">Approved / Posted</span><span class="draft-value"><?php echo number_format($draftCounts['approved']); ?></span></div>
              </div>

              <div class="card draft-card mb-4">
                <div class="card-body p-4">
                  <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start mb-3">
                    <div>
                      <span class="draft-kicker"><i class="bx bx-list-ul"></i> Saved Drafts</span>
                      <h4 class="mt-3 mb-2">My enrollment drafts</h4>
                      <p class="text-muted mb-0">`Draft` and `Returned` records can still be reopened in the builder. `Submitted` records are already visible to the campus registrar.</p>
                    </div>
                    <a href="enrollment.php" class="btn btn-primary"><i class="bx bx-plus me-1"></i> New Draft</a>
                  </div>

                  <?php if (!$draftTablesReady): ?>
                    <div class="alert alert-warning mb-0">Run the draft table SQL first, then refresh this page.</div>
                  <?php elseif (empty($draftRows)): ?>
                    <div class="alert alert-info mb-0">No enrollment drafts have been saved yet for this Program Chair account.</div>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="table draft-table mb-0">
                        <thead>
                          <tr>
                            <th>Reference</th>
                            <th>Student</th>
                            <th>Section</th>
                            <th class="text-center">Load</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th class="text-end">Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($draftRows as $draftRow): ?>
                            <?php
                              $status = strtolower(trim((string)($draftRow['workflow_status'] ?? 'draft')));
                              $isEditable = in_array($status, ['draft', 'returned'], true);
                              $studentLabel = trim(implode(' ', array_filter([
                                  (string)($draftRow['student_number'] ?? ''),
                                  trim(implode(', ', array_filter([
                                      (string)($draftRow['last_name'] ?? ''),
                                      trim(implode(' ', array_filter([
                                          (string)($draftRow['first_name'] ?? ''),
                                          (string)($draftRow['middle_name'] ?? ''),
                                          (string)($draftRow['suffix_name'] ?? ''),
                                      ]))),
                                  ]))),
                              ])));
                              if ($studentLabel === '') {
                                  $studentLabel = 'Student profile pending';
                              }
                              $sectionLabel = trim((string)($draftRow['full_section'] ?? ''));
                              if ($sectionLabel === '') {
                                  $sectionLabel = trim((string)($draftRow['section_name'] ?? ''));
                              }
                            ?>
                            <tr>
                              <td>
                                <strong><?php echo htmlspecialchars((string)($draftRow['enrollment_reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <div class="small text-muted"><?php echo htmlspecialchars((string)($draftRow['program_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                              </td>
                              <td><?php echo htmlspecialchars($studentLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                              <td><?php echo htmlspecialchars($sectionLabel !== '' ? $sectionLabel : 'Section pending', ENT_QUOTES, 'UTF-8'); ?></td>
                              <td class="text-center"><?php echo (int)($draftRow['subject_count'] ?? 0); ?> subj / <?php echo htmlspecialchars((string)($draftRow['total_units'] ?? 0), ENT_QUOTES, 'UTF-8'); ?> u</td>
                              <td><span class="badge <?php echo program_chair_draft_status_badge_class((string)($draftRow['workflow_status'] ?? 'draft')); ?>"><?php echo htmlspecialchars((string)($draftRow['status_label'] ?? 'Draft'), ENT_QUOTES, 'UTF-8'); ?></span></td>
                              <td><?php echo htmlspecialchars((string)($draftRow['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                              <td class="text-end">
                                <a href="drafts.php?enrollment_id=<?php echo (int)($draftRow['enrollment_id'] ?? 0); ?>" class="btn btn-outline-secondary btn-sm">View</a>
                                <?php if ($isEditable): ?>
                                  <a href="enrollment.php?draft_id=<?php echo (int)($draftRow['enrollment_id'] ?? 0); ?>" class="btn btn-outline-primary btn-sm">Edit</a>
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($selectedDraftDetail): ?>
                <div class="draft-detail-grid">
                  <div class="card draft-card">
                    <div class="card-body p-4">
                      <span class="draft-kicker"><i class="bx bx-file"></i> Draft Detail</span>
                      <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start mt-3 mb-3">
                        <div>
                          <h4 class="mb-1"><?php echo htmlspecialchars((string)($selectedDraftDetail['enrollment_reference'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h4>
                          <p class="text-muted mb-0"><?php echo htmlspecialchars((string)($selectedDraftDetail['program_name'] ?? 'Program not set'), ENT_QUOTES, 'UTF-8'); ?></p>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                          <span class="badge <?php echo program_chair_draft_status_badge_class((string)($selectedDraftDetail['workflow_status'] ?? 'draft')); ?>"><?php echo htmlspecialchars((string)($selectedDraftDetail['status_label'] ?? 'Draft'), ENT_QUOTES, 'UTF-8'); ?></span>
                          <?php if (in_array(strtolower(trim((string)($selectedDraftDetail['workflow_status'] ?? 'draft'))), ['draft', 'returned'], true)): ?>
                            <a href="enrollment.php?draft_id=<?php echo (int)($selectedDraftDetail['enrollment_id'] ?? 0); ?>" class="btn btn-outline-primary btn-sm">Open in Builder</a>
                          <?php endif; ?>
                        </div>
                      </div>

                      <div class="draft-metric-grid mb-4">
                        <div class="draft-summary-box"><span class="draft-label">Student</span><div class="draft-value"><?php echo htmlspecialchars(program_chair_draft_student_label($selectedDraftDetail), ENT_QUOTES, 'UTF-8'); ?></div></div>
                        <div class="draft-summary-box"><span class="draft-label">Section</span><div class="draft-value"><?php echo htmlspecialchars((string)($selectedDraftDetail['full_section'] ?? $selectedDraftDetail['section_name'] ?? 'Section pending'), ENT_QUOTES, 'UTF-8'); ?></div></div>
                        <div class="draft-summary-box"><span class="draft-label">Load Summary</span><div class="draft-value"><?php echo (int)($selectedDraftDetail['subject_count'] ?? 0); ?> subjects / <?php echo htmlspecialchars((string)($selectedDraftDetail['total_units'] ?? 0), ENT_QUOTES, 'UTF-8'); ?> units</div></div>
                        <div class="draft-summary-box"><span class="draft-label">Notes</span><div class="draft-value"><?php echo htmlspecialchars((string)($selectedDraftDetail['chair_notes'] ?? 'No chair notes'), ENT_QUOTES, 'UTF-8'); ?></div></div>
                      </div>

                      <div class="table-responsive">
                        <table class="table draft-table mb-0">
                          <thead>
                            <tr>
                              <th>Course</th>
                              <th>Schedule</th>
                              <th>Faculty / Room</th>
                              <th class="text-center">Units</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ((array)($selectedDraftDetail['subjects'] ?? []) as $subjectRow): ?>
                              <tr>
                                <td>
                                  <strong><?php echo htmlspecialchars((string)($subjectRow['subject_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                  <div><?php echo htmlspecialchars((string)($subjectRow['descriptive_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars((string)($subjectRow['schedule_text'] ?? 'Schedule pending'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td>
                                  <div><?php echo htmlspecialchars((string)($subjectRow['faculty_text'] ?? 'TBA'), ENT_QUOTES, 'UTF-8'); ?></div>
                                  <div class="small text-muted"><?php echo htmlspecialchars((string)($subjectRow['room_text'] ?? 'TBA'), ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td class="text-center"><?php echo htmlspecialchars((string)($subjectRow['units'] ?? 0), ENT_QUOTES, 'UTF-8'); ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    </div>
                  </div>

                  <div class="card draft-card">
                    <div class="card-body p-4">
                      <span class="draft-kicker"><i class="bx bx-history"></i> Workflow Log</span>
                      <h5 class="mt-3 mb-3">Status timeline</h5>
                      <?php if (!empty($selectedDraftDetail['workflow_logs'])): ?>
                        <div class="draft-log-grid">
                          <?php foreach ((array)$selectedDraftDetail['workflow_logs'] as $logRow): ?>
                            <div class="draft-log-item">
                              <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($logRow['action_type'] ?? 'updated'))), ENT_QUOTES, 'UTF-8'); ?></strong>
                              <div class="small mt-1"><?php echo htmlspecialchars((string)($logRow['remarks'] ?? 'No remarks'), ENT_QUOTES, 'UTF-8'); ?></div>
                              <small class="d-block mt-2"><?php echo htmlspecialchars((string)($logRow['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><?php if (trim((string)($logRow['actor_name'] ?? '')) !== ''): ?> by <?php echo htmlspecialchars((string)($logRow['actor_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></small>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      <?php else: ?>
                        <div class="alert alert-info mb-0">No workflow log entries are available yet for this draft.</div>
                      <?php endif; ?>
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
