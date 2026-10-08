<?php
session_start();
ob_start();

include '../backend/db.php';
require_once '../backend/program_chair_helper.php';

synk_program_chair_require_login($conn);

$programChairPortalContext = synk_program_chair_portal_context($conn);
$programChairPrograms = is_array($programChairPortalContext['program_rows'] ?? null)
    ? $programChairPortalContext['program_rows']
    : [];
$programChairCollege = is_array($programChairPortalContext['college'] ?? null)
    ? $programChairPortalContext['college']
    : null;
$programChairPortalDisplayName = (string)($programChairPortalContext['account_name'] ?? 'Program Chair');
$programChairPortalDisplayEmail = (string)($programChairPortalContext['email'] ?? '');
$programChairRoleBadges = is_array($programChairPortalContext['role_badges'] ?? null)
    ? $programChairPortalContext['role_badges']
    : [];
$programChairProgramCount = (int)($programChairPortalContext['program_count'] ?? 0);
$programChairActivePrograms = (int)($programChairPortalContext['active_program_count'] ?? 0);
$programChairInactivePrograms = (int)($programChairPortalContext['inactive_program_count'] ?? 0);
$programChairMajorPrograms = (int)($programChairPortalContext['major_program_count'] ?? 0);
$programChairCollegeLabel = $programChairCollege
    ? trim(implode(' - ', array_filter([
        (string)($programChairCollege['college_code'] ?? ''),
        (string)($programChairCollege['college_name'] ?? ''),
    ])))
    : 'Not assigned';
$collegeId = (int)($programChairPortalContext['college_id'] ?? 0);
$collegeName = trim((string)($programChairCollege['college_name'] ?? 'Assigned College'));
$prospectusVersionsByProgram = synk_program_chair_fetch_prospectus_versions_by_program($conn, $collegeId);

foreach ($programChairPrograms as $programRow) {
    $programId = (int)($programRow['program_id'] ?? 0);
    if ($programId <= 0 || array_key_exists($programId, $prospectusVersionsByProgram)) {
        continue;
    }

    $prospectusVersionsByProgram[$programId] = [];
}

$programChairProgramsJson = json_encode(array_values($programChairPrograms), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />

    <title>Program Chair Dashboard | Synk</title>

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
      .program-chair-dashboard-card,
      .program-chair-dashboard-hero,
      .program-chair-dashboard-note {
        border: 1px solid #dce5f1;
        border-radius: 22px;
        box-shadow: 0 18px 38px rgba(67, 89, 113, 0.08);
      }

      .program-chair-dashboard-hero {
        background: linear-gradient(135deg, #f8fbff 0%, #eef5ff 55%, #f1f8ef 100%);
      }

      .program-chair-dashboard-note {
        background: linear-gradient(135deg, #fff8ea 0%, #fffdf6 100%);
        color: #855b16;
      }

      .program-chair-dashboard-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.35rem 0.8rem;
        border-radius: 999px;
        background: #eef4ff;
        color: #4f6595;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }

      .program-chair-summary-grid,
      .program-chair-action-grid,
      .program-chair-program-grid {
        display: grid;
        gap: 0.85rem;
      }

      .program-chair-summary-grid {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      }

      .program-chair-action-grid {
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      }

      .program-chair-program-grid {
        grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
        align-items: start;
      }

      .program-chair-summary-card,
      .program-chair-action-card,
      .program-chair-program-card {
        border: 1px solid #e2e9f2;
        border-radius: 18px;
        padding: 1rem;
        background: rgba(255, 255, 255, 0.94);
        height: 100%;
      }

      .program-chair-summary-label {
        display: block;
        color: #7d8ea5;
        font-size: 0.75rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        text-transform: uppercase;
      }

      .program-chair-summary-value {
        display: block;
        margin-top: 0.35rem;
        color: #33475b;
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.55;
      }

      .program-chair-action-card {
        text-decoration: none;
        color: inherit;
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
      }

      .program-chair-action-card:hover {
        transform: translateY(-2px);
        border-color: #cfdaf4;
        box-shadow: 0 16px 30px rgba(67, 89, 113, 0.1);
      }

      .program-chair-action-title,
      .program-chair-program-title {
        margin: 0;
        color: #25364a;
        font-size: 1rem;
        font-weight: 800;
      }

      .program-chair-action-copy,
      .program-chair-program-copy {
        margin-top: 0.4rem;
        color: #53667f;
        line-height: 1.55;
      }

      .program-chair-role-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin-top: 1rem;
      }

      .program-chair-role-list .badge {
        padding: 0.5rem 0.75rem;
        font-size: 0.76rem;
      }

      .program-chair-program-card {
        transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
      }

      .program-chair-program-card:hover {
        border-color: #cfdbef;
        box-shadow: 0 14px 26px rgba(67, 89, 113, 0.08);
        transform: translateY(-1px);
      }

      .program-chair-program-card.is-expanded {
        grid-column: 1 / -1;
        border-color: #c4d5ef;
        box-shadow: 0 18px 32px rgba(67, 89, 113, 0.1);
        background: linear-gradient(180deg, rgba(249, 252, 255, 0.98) 0%, rgba(255, 255, 255, 1) 100%);
      }

      .program-chair-program-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
        margin-top: 0.9rem;
      }

      .program-chair-program-controls {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px dashed #d9e3ef;
      }

      .program-chair-program-controls .form-label {
        font-size: 0.75rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #75859c;
      }

      .program-chair-program-status-note {
        color: #72839a;
        font-size: 0.82rem;
        line-height: 1.5;
      }

      .program-chair-prospectus-panel {
        margin-top: 1.1rem;
        padding-top: 1.1rem;
        border-top: 1px solid #e4ebf3;
      }

      .program-chair-prospectus-viewer .sheet-card {
        box-shadow: none;
        border-radius: 20px;
      }

      .sheet-card {
        border: 1px solid #dde5f0;
        border-radius: 22px;
        background: linear-gradient(180deg, rgba(246, 250, 255, 0.96) 0%, rgba(255, 255, 255, 1) 100%);
        box-shadow: 0 18px 38px rgba(67, 89, 113, 0.08);
      }

      .sheet-head {
        text-align: center;
        padding-bottom: 1.75rem;
        border-bottom: 1px solid #e4ebf3;
        margin-bottom: 1.75rem;
      }

      .sheet-kicker {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.35rem;
        padding: 0.35rem 0.85rem;
        border-radius: 999px;
        background: #eef5ea;
        color: #537043;
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-weight: 700;
      }

      .sheet-title {
        margin-top: 1rem;
        margin-bottom: 0.35rem;
        font-size: 1.6rem;
        font-weight: 800;
        color: #243246;
        line-height: 1.25;
      }

      .sheet-subtitle {
        color: #6b7c93;
        font-size: 0.96rem;
      }

      .sheet-meta {
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 1rem;
      }

      .sheet-meta-pill {
        padding: 0.45rem 0.8rem;
        border-radius: 999px;
        background: #f3f6fb;
        color: #55667f;
        font-size: 0.82rem;
        font-weight: 600;
      }

      .year-block + .year-block {
        margin-top: 1.75rem;
      }

      .year-banner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
        padding: 0.75rem 1rem;
        border-radius: 14px;
        background: linear-gradient(90deg, #f5f8ec 0%, #eef5ff 100%);
        border: 1px solid #dfe8cf;
      }

      .year-banner-title {
        font-size: 1rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #37465d;
      }

      .year-banner-note {
        color: #70829a;
        font-size: 0.8rem;
      }

      .semester-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
      }

      .semester-card {
        border: 1px solid #dfe7f1;
        border-radius: 16px;
        background: #fff;
        overflow: hidden;
      }

      .semester-card.midyear {
        grid-column: 1 / -1;
      }

      .semester-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.8rem 1rem;
        background: #edf6e8;
        border-bottom: 1px solid #dbe6d2;
      }

      .semester-title {
        margin: 0;
        font-size: 0.9rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #4e6541;
      }

      .semester-badge {
        background: #ffffff;
        color: #5f738f;
        border-radius: 999px;
        padding: 0.25rem 0.65rem;
        font-size: 0.76rem;
        font-weight: 700;
      }

      .semester-table {
        width: 100%;
        margin: 0;
        border-collapse: collapse;
        font-size: 0.84rem;
      }

      .semester-table th,
      .semester-table td {
        padding: 0.55rem 0.65rem;
        border-bottom: 1px solid #eef2f7;
        vertical-align: top;
      }

      .semester-table thead th {
        background: #f8fafc;
        color: #5c6e86;
        font-size: 0.73rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        font-weight: 700;
      }

      .semester-table tbody tr:last-child td {
        border-bottom: 0;
      }

      .semester-table tfoot th,
      .semester-table tfoot td {
        background: #fafcf6;
        border-top: 1px solid #dbe6d2;
        border-bottom: 0;
        font-weight: 700;
        color: #50636f;
      }

      .col-code {
        width: 124px;
        white-space: nowrap;
        font-weight: 700;
        color: #32455e;
      }

      .col-num {
        width: 52px;
        text-align: center;
        white-space: nowrap;
      }

      .col-prereq {
        width: 120px;
        color: #61748a;
      }

      .empty-semester {
        padding: 1.25rem 1rem;
        color: #7d8da3;
        font-size: 0.88rem;
        text-align: center;
        background: #fbfcfe;
      }

      .state-alert {
        border-radius: 14px;
        margin-bottom: 0;
      }

      @media (max-width: 991.98px) {
        .semester-grid {
          grid-template-columns: 1fr;
        }

        .semester-card.midyear,
        .program-chair-program-card.is-expanded {
          grid-column: auto;
        }

        .sheet-card .card-body {
          padding: 1.25rem !important;
        }

        .sheet-title {
          font-size: 1.28rem;
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
              <?php if (!$programChairCollege): ?>
                <div class="card program-chair-dashboard-note mb-4">
                  <div class="card-body p-3">
                    Assign a college to this Program Chair account in Access Accounts so the dashboard can load the correct programs, prospectus references, and enrollment workspace.
                  </div>
                </div>
              <?php endif; ?>

              <div class="card program-chair-dashboard-hero mb-4">
                <div class="card-body p-4">
                  <div class="row align-items-center g-4">
                    <div class="col-lg-8">
                      <span class="program-chair-dashboard-kicker">
                        <i class="bx bx-briefcase-alt-2"></i>
                        Program Chair Workspace
                      </span>

                      <h3 class="mt-3 mb-2">Welcome, <?php echo htmlspecialchars($programChairPortalDisplayName, ENT_QUOTES, 'UTF-8'); ?>.</h3>
                      <p class="text-muted mb-0">
                        Review programs under your assigned college, open the approved prospectus for each degree, and keep enrollment preparation aligned with the current curriculum basis.
                      </p>

                      <?php if (!empty($programChairRoleBadges)): ?>
                        <div class="program-chair-role-list">
                          <?php foreach ($programChairRoleBadges as $roleBadge): ?>
                            <span class="badge <?php echo !empty($roleBadge['is_active']) ? 'bg-label-primary' : 'bg-label-secondary'; ?>">
                              <?php echo htmlspecialchars((string)($roleBadge['label'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                          <?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </div>

                    <div class="col-lg-4">
                      <div class="program-chair-summary-grid">
                        <div class="program-chair-summary-card">
                          <span class="program-chair-summary-label">Assigned College</span>
                          <span class="program-chair-summary-value">
                            <?php echo htmlspecialchars($programChairCollegeLabel, ENT_QUOTES, 'UTF-8'); ?>
                          </span>
                        </div>
                        <div class="program-chair-summary-card">
                          <span class="program-chair-summary-label">Campus</span>
                          <span class="program-chair-summary-value"><?php echo htmlspecialchars((string)($programChairCollege['campus_name'] ?? 'Not assigned'), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="program-chair-summary-grid mb-4">
                <div class="program-chair-summary-card">
                  <span class="program-chair-summary-label">Programs Under College</span>
                  <span class="program-chair-summary-value"><?php echo number_format($programChairProgramCount); ?></span>
                </div>
                <div class="program-chair-summary-card">
                  <span class="program-chair-summary-label">Active Programs</span>
                  <span class="program-chair-summary-value"><?php echo number_format($programChairActivePrograms); ?></span>
                </div>
                <div class="program-chair-summary-card">
                  <span class="program-chair-summary-label">Programs With Major</span>
                  <span class="program-chair-summary-value"><?php echo number_format($programChairMajorPrograms); ?></span>
                </div>
                <div class="program-chair-summary-card">
                  <span class="program-chair-summary-label">Inactive Programs</span>
                  <span class="program-chair-summary-value"><?php echo number_format($programChairInactivePrograms); ?></span>
                </div>
              </div>

              <div class="card program-chair-dashboard-card mb-4">
                <div class="card-body p-4">
                  <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                    <div>
                      <span class="program-chair-dashboard-kicker">
                        <i class="bx bx-grid-alt"></i>
                        Management Menu
                      </span>
                      <h4 class="mt-3 mb-2">Program Chair management areas</h4>
                      <p class="text-muted mb-0">These are the main workspace sections prepared for program review, enrollment preparation, and college-level monitoring.</p>
                    </div>
                  </div>

                  <div class="program-chair-action-grid">
                    <a href="programs.php" class="program-chair-action-card">
                      <h5 class="program-chair-action-title">Programs</h5>
                      <p class="program-chair-action-copy">Open the full program list under your assigned college and compare degree records, majors, and status at a glance.</p>
                    </a>
                    <a href="enrollment.php" class="program-chair-action-card">
                      <h5 class="program-chair-action-title">Enrollment</h5>
                      <p class="program-chair-action-copy">Prepare student loads from the current curriculum basis and manage draft-to-submission flow for campus registrar review.</p>
                    </a>
                    <a href="drafts.php" class="program-chair-action-card">
                      <h5 class="program-chair-action-title">Draft List</h5>
                      <p class="program-chair-action-copy">Track saved enrollment drafts, reopen editable records, and see which loads are already sitting in the registrar queue.</p>
                    </a>
                    <a href="students.php" class="program-chair-action-card">
                      <h5 class="program-chair-action-title">Students</h5>
                      <p class="program-chair-action-copy">Support first-year encoding, verification follow-up, and advising preparation before final enrollment is posted.</p>
                    </a>
                    <a href="reports.php" class="program-chair-action-card">
                      <h5 class="program-chair-action-title">Reports</h5>
                      <p class="program-chair-action-copy">Prepare chair-level enrollment summaries, pending submissions, and college-facing review outputs.</p>
                    </a>
                  </div>
                </div>
              </div>

              <div class="card program-chair-dashboard-card">
                <div class="card-body p-4">
                  <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                    <div>
                      <span class="program-chair-dashboard-kicker">
                        <i class="bx bx-book-reader"></i>
                        College Programs
                      </span>
                      <h4 class="mt-3 mb-2">Programs visible to this Program Chair</h4>
                      <p class="text-muted mb-0">Use the Programs page to open prospectus versions and review the approved curriculum basis for each degree under the assigned college.</p>
                    </div>
                    <a href="programs.php" class="btn btn-outline-primary align-self-start">
                      <i class="bx bx-right-arrow-alt me-1"></i> Open Programs Page
                    </a>
                  </div>

                  <?php if (!empty($programChairPrograms)): ?>
                    <div class="program-chair-program-grid">
                      <?php foreach ($programChairPrograms as $programRow): ?>
                        <div class="program-chair-program-card">
                          <h5 class="program-chair-program-title"><?php echo htmlspecialchars((string)($programRow['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h5>
                          <p class="program-chair-program-copy mb-0">
                            <?php echo htmlspecialchars(trim((string)($programRow['major'] ?? '')) !== '' ? 'Major-specific program under the assigned college.' : 'General degree program under the assigned college.', ENT_QUOTES, 'UTF-8'); ?>
                          </p>
                          <div class="program-chair-program-meta">
                            <span class="badge bg-label-primary"><?php echo htmlspecialchars((string)($programRow['program_code'] ?? 'NO CODE'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="badge <?php echo strtolower((string)($programRow['status'] ?? 'inactive')) === 'active' ? 'bg-label-success' : 'bg-label-secondary'; ?>">
                              <?php echo htmlspecialchars(ucfirst((string)($programRow['status'] ?? 'inactive')), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <div class="alert alert-warning mb-0">
                      No programs are currently available for this Program Chair account. Assign a college first or check whether programs have already been encoded under that college.
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
    <script>
      const COLLEGE_NAME = <?= json_encode($collegeName, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
      const PROGRAM_ROWS = <?= $programChairProgramsJson ?: '[]' ?>;

      function escapeHtml(value) {
        return String(value ?? '')
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/"/g, '&quot;')
          .replace(/'/g, '&#39;');
      }

      function yearLabel(year) {
        const num = Number(year);
        if (num === 1) return 'First Year';
        if (num === 2) return 'Second Year';
        if (num === 3) return 'Third Year';
        if (num === 4) return 'Fourth Year';
        return `Year ${escapeHtml(year)}`;
      }

      function semesterLabel(semester) {
        const num = Number(semester);
        if (num === 1) return '1st Semester';
        if (num === 2) return '2nd Semester';
        if (num === 3) return 'Midyear';
        return `Semester ${escapeHtml(semester)}`;
      }

      function getProgramRecord(programId) {
        const numericProgramId = Number(programId);
        return PROGRAM_ROWS.find(program => Number(program.program_id) === numericProgramId) || null;
      }

      function getProspectusPanel(programId) {
        return $(`#program-prospectus-panel-${programId}`);
      }

      function getProspectusButton(programId) {
        return $(`.program-chair-open-prospectus[data-program-id="${programId}"]`);
      }

      function getProspectusSelect(programId) {
        return $(`#program-prospectus-${programId}`);
      }

      function setProspectusButtonExpanded(programId, expanded) {
        const button = getProspectusButton(programId);
        if (!button.length || button.is(':disabled')) {
          return;
        }

        if (expanded) {
          button
            .removeClass('btn-outline-primary')
            .addClass('btn-primary')
            .html('<i class="bx bx-chevron-up me-1"></i> Hide Prospectus');
          return;
        }

        button
          .removeClass('btn-primary')
          .addClass('btn-outline-primary')
          .html('<i class="bx bx-book-open me-1"></i> Show Prospectus');
      }

      function showProspectusState(panel, type, message) {
        const state = panel.find('.program-chair-prospectus-state');
        const viewer = panel.find('.program-chair-prospectus-viewer');
        const typeClassMap = {
          info: 'alert-info',
          warning: 'alert-warning',
          danger: 'alert-danger',
          success: 'alert-success'
        };

        state
          .removeClass('alert-info alert-warning alert-danger alert-success')
          .addClass(typeClassMap[type] || 'alert-info')
          .html(message)
          .show();

        viewer.addClass('d-none');
      }

      function collapseProspectusCard(programId) {
        const card = $(`#program-card-${programId}`);
        const panel = getProspectusPanel(programId);

        card.removeClass('is-expanded');
        panel.addClass('d-none');
        showProspectusState(panel, 'info', "Select a prospectus version to review this program's approved curriculum flow.");
        setProspectusButtonExpanded(programId, false);
      }

      function collapseOtherProspectusCards(activeProgramId) {
        $('.program-chair-program-card.is-expanded').each(function () {
          const programId = Number($(this).data('programId'));
          if (programId && programId !== Number(activeProgramId)) {
            collapseProspectusCard(programId);
          }
        });
      }

      function buildSemesterTable(rows, semester) {
        const safeRows = Array.isArray(rows) ? rows : [];
        let totalLec = 0;
        let totalLab = 0;
        let totalUnits = 0;

        if (safeRows.length === 0) {
          return `
            <div class="semester-card${Number(semester) === 3 ? ' midyear' : ''}">
              <div class="semester-head">
                <h6 class="semester-title">${escapeHtml(semesterLabel(semester))}</h6>
                <span class="semester-badge">0 Subjects</span>
              </div>
              <div class="empty-semester">No subjects listed for this semester.</div>
            </div>
          `;
        }

        const bodyRows = safeRows.map(row => {
          const lec = Number(row.lec_units) || 0;
          const lab = Number(row.lab_units) || 0;
          const units = Number(row.total_units) || 0;

          totalLec += lec;
          totalLab += lab;
          totalUnits += units;

          return `
            <tr>
              <td class="col-code">${escapeHtml(row.sub_code)}</td>
              <td>${escapeHtml(row.sub_description)}</td>
              <td class="col-num">${lec}</td>
              <td class="col-num">${lab}</td>
              <td class="col-num">${units}</td>
              <td class="col-prereq">${escapeHtml(row.prerequisites || 'None')}</td>
            </tr>
          `;
        }).join('');

        return `
          <div class="semester-card${Number(semester) === 3 ? ' midyear' : ''}">
            <div class="semester-head">
              <h6 class="semester-title">${escapeHtml(semesterLabel(semester))}</h6>
              <span class="semester-badge">${safeRows.length} Subject${safeRows.length === 1 ? '' : 's'}</span>
            </div>
            <div class="table-responsive">
              <table class="semester-table">
                <thead>
                  <tr>
                    <th>Course Code</th>
                    <th>Course Title</th>
                    <th class="col-num">Lec</th>
                    <th class="col-num">Lab</th>
                    <th class="col-num">Unit</th>
                    <th>Pre-Requisite</th>
                  </tr>
                </thead>
                <tbody>${bodyRows}</tbody>
                <tfoot>
                  <tr>
                    <th colspan="2" class="text-end">Total</th>
                    <td class="col-num">${totalLec}</td>
                    <td class="col-num">${totalLab}</td>
                    <td class="col-num">${totalUnits}</td>
                    <td>Units</td>
                  </tr>
                </tfoot>
              </table>
            </div>
          </div>
        `;
      }

      function renderProspectus(panel, data) {
        const header = data.header || {};
        const structure = data.structure || {};
        const subjects = data.subjects || {};
        const programRecord = getProgramRecord(header.program_id);
        const viewer = panel.find('.program-chair-prospectus-viewer');
        const title = panel.find('.program-chair-sheet-program-title');
        const subtitle = panel.find('.program-chair-sheet-program-subtitle');
        const meta = panel.find('.program-chair-sheet-meta');
        const content = panel.find('.program-chair-sheet-content');

        const titleParts = [];
        if (header.program_name) titleParts.push(header.program_name);
        if (header.major) titleParts.push(`major in ${header.major}`);

        title.text(titleParts.join(' ') || 'Program Prospectus');
        subtitle.text(COLLEGE_NAME);

        meta.html(`
          <span class="sheet-meta-pill"><strong>Program Code:</strong> ${escapeHtml(header.program_code || (programRecord ? programRecord.program_code : '-'))}</span>
          <span class="sheet-meta-pill"><strong>CMO:</strong> ${escapeHtml(header.cmo_no || '-')}</span>
          <span class="sheet-meta-pill"><strong>Effectivity:</strong> ${escapeHtml(header.effective_sy || '-')}</span>
        `);

        const yearKeys = Array.from(
          new Set([
            ...Object.keys(structure || {}),
            ...Object.keys(subjects || {})
          ])
        ).sort((a, b) => Number(a) - Number(b));

        if (yearKeys.length === 0) {
          showProspectusState(panel, 'warning', 'This prospectus has no year and semester structure yet.');
          return;
        }

        const layoutHtml = yearKeys.map((yearKey) => {
          const yearStructure = structure[yearKey] || {};
          const yearSubjects = subjects[yearKey] || {};
          const hasMidyear = Boolean(yearStructure['3']) || Boolean(yearSubjects['3']);

          return `
            <section class="year-block">
              <div class="year-banner">
                <div class="year-banner-title">${escapeHtml(yearLabel(yearKey))}</div>
                <div class="year-banner-note">Approved curriculum breakdown by semester</div>
              </div>
              <div class="semester-grid">
                ${buildSemesterTable(yearSubjects['1'] || [], 1)}
                ${buildSemesterTable(yearSubjects['2'] || [], 2)}
                ${hasMidyear ? buildSemesterTable(yearSubjects['3'] || [], 3) : ''}
              </div>
            </section>
          `;
        }).join('');

        content.html(layoutHtml);
        viewer.removeClass('d-none');
        panel.find('.program-chair-prospectus-state').hide();
      }

      async function loadProspectusView(programId, prospectusId) {
        const panel = getProspectusPanel(programId);
        if (!panel.length) {
          return;
        }

        if (!prospectusId) {
          showProspectusState(panel, 'warning', 'No prospectus version is selected for this program.');
          return;
        }

        showProspectusState(panel, 'info', 'Loading prospectus view...');

        try {
          const response = await fetch(`../backend/query_view_prospectus.php?prospectus_id=${encodeURIComponent(prospectusId)}`, {
            credentials: 'same-origin'
          });

          const data = await response.json();

          if (!response.ok || data.error) {
            showProspectusState(panel, 'danger', escapeHtml(data.error || 'Unable to load prospectus view.'));
            return;
          }

          renderProspectus(panel, data);
        } catch (error) {
          showProspectusState(panel, 'danger', 'Unable to load prospectus view.');
        }
      }

      function openProspectusCard(programId) {
        const card = $(`#program-card-${programId}`);
        const panel = getProspectusPanel(programId);
        const select = getProspectusSelect(programId);
        const selectedProspectusId = select.val() || String(card.data('defaultProspectusId') || '');

        collapseOtherProspectusCards(programId);

        card.addClass('is-expanded');
        panel.removeClass('d-none');
        setProspectusButtonExpanded(programId, true);
        loadProspectusView(programId, selectedProspectusId);

        if (panel.length && typeof panel[0].scrollIntoView === 'function') {
          panel[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
      }

      $(document).ready(function () {
        $(document).on('click', '.program-chair-open-prospectus', function () {
          const programId = Number($(this).data('programId'));
          if (!programId) {
            return;
          }

          const card = $(`#program-card-${programId}`);
          if (card.hasClass('is-expanded')) {
            collapseProspectusCard(programId);
            return;
          }

          openProspectusCard(programId);
        });

        $(document).on('change', '.program-chair-prospectus-select', function () {
          const programId = Number($(this).data('programId'));
          if (!programId) {
            return;
          }

          const card = $(`#program-card-${programId}`);
          if (card.hasClass('is-expanded')) {
            loadProspectusView(programId, $(this).val());
          }
        });
      });
    </script>
  </body>
</html>
