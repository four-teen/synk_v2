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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <title>Program Chair Programs | Synk</title>
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
      .pc-card {
        border: 1px solid #dce5f1;
        border-radius: 22px;
        box-shadow: 0 18px 38px rgba(67, 89, 113, 0.08);
      }

      .pc-kicker {
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

      .pc-program-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
        gap: 1rem;
        align-items: start;
      }

      .pc-program-card {
        border: 1px solid #dfe7f1;
        border-radius: 22px;
        padding: 1rem;
        background: #ffffff;
        box-shadow: 0 12px 28px rgba(67, 89, 113, 0.06);
        transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
      }

      .pc-program-card:hover {
        border-color: #cfdcf0;
        box-shadow: 0 16px 32px rgba(67, 89, 113, 0.08);
        transform: translateY(-1px);
      }

      .pc-program-card.is-expanded {
        grid-column: 1 / -1;
        box-shadow: 0 18px 36px rgba(67, 89, 113, 0.11);
        border-color: #c8d7ee;
      }

      .pc-program-title {
        margin: 0;
        color: #111827;
        font-size: 1.05rem;
        font-weight: 800;
        line-height: 1.25;
      }

      .pc-program-copy {
        margin-top: 0.65rem;
        margin-bottom: 0;
        color: #53667f;
        line-height: 1.55;
      }

      .pc-program-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
        margin-top: 0.95rem;
      }

      .pc-program-controls {
        margin-top: 1rem;
        padding-top: 1rem;
        border-top: 1px dashed #d8e2ef;
      }

      .pc-program-controls-label {
        display: block;
        margin-bottom: 0.55rem;
        color: #111827;
        font-size: 0.9rem;
        font-weight: 800;
        letter-spacing: 0.03em;
        text-transform: uppercase;
      }

      .pc-program-control-row {
        display: flex;
        align-items: flex-end;
        gap: 0.85rem;
      }

      .pc-program-control-select {
        flex: 1 1 auto;
        min-width: 0;
      }

      .pc-program-control-button {
        flex: 0 0 150px;
      }

      .pc-program-control-button .btn {
        min-height: 41px;
        white-space: nowrap;
        display: inline-flex;
        align-items: center;
        justify-content: center;
      }

      .pc-program-control-select .form-select:disabled {
        background-color: #f8fafc;
        color: #2f3d55;
        opacity: 1;
      }

      .pc-program-note {
        margin-top: 0.55rem;
        color: #5b6f89;
        font-size: 0.82rem;
        line-height: 1.45;
      }

      .pc-prospectus-panel {
        margin-top: 1.15rem;
        padding-top: 1.15rem;
        border-top: 1px solid #e5ecf4;
      }

      .pc-prospectus-state {
        border-radius: 14px;
        margin-bottom: 0;
      }

      .pc-prospectus-viewer .sheet-card {
        box-shadow: none;
      }

      .sheet-card {
        border: 1px solid #dde5f0;
        border-radius: 22px;
        background: linear-gradient(180deg, rgba(246, 250, 255, 0.96) 0%, rgba(255, 255, 255, 1) 100%);
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

      @media (max-width: 991.98px) {
        .pc-program-grid,
        .semester-grid {
          grid-template-columns: 1fr;
        }

        .pc-program-card.is-expanded,
        .semester-card.midyear {
          grid-column: auto;
        }

        .pc-program-control-row {
          flex-direction: column;
          align-items: stretch;
        }

        .pc-program-control-button {
          flex-basis: auto;
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
              <div class="card pc-card">
                <div class="card-body p-4">
                  <span class="pc-kicker"><i class="bx bx-book-content"></i> Programs</span>
                  <h4 class="mt-3 mb-2">Programs under <?php echo htmlspecialchars((string)($programChairCollege['college_name'] ?? 'your assigned college'), ENT_QUOTES, 'UTF-8'); ?></h4>
                  <p class="text-muted mb-4">Review each program under your assigned college and open the approved prospectus used as the curriculum basis for enrollment preparation.</p>

                  <?php if (!empty($programChairPrograms)): ?>
                    <div class="pc-program-grid">
                      <?php foreach ($programChairPrograms as $programRow): ?>
                        <?php
                          $programId = (int)($programRow['program_id'] ?? 0);
                          $programStatus = strtolower(trim((string)($programRow['status'] ?? 'inactive')));
                          $programMajor = trim((string)($programRow['major'] ?? ''));
                          $prospectusVersions = array_values($prospectusVersionsByProgram[$programId] ?? []);
                          $prospectusCount = count($prospectusVersions);
                          $defaultProspectusId = $prospectusCount > 0 ? (int)($prospectusVersions[0]['prospectus_id'] ?? 0) : 0;
                        ?>
                        <div
                          class="pc-program-card"
                          id="program-card-<?php echo $programId; ?>"
                          data-program-id="<?php echo $programId; ?>"
                          data-default-prospectus-id="<?php echo $defaultProspectusId; ?>"
                        >
                          <h5 class="pc-program-title"><?php echo htmlspecialchars((string)($programRow['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></h5>
                          <p class="pc-program-copy">
                            <?php
                              echo htmlspecialchars(
                                  $programMajor !== ''
                                      ? 'Major-specific degree offering under the assigned college. Use the approved prospectus below as the curriculum basis for enrollment preparation.'
                                      : 'Degree offering under the assigned college. Use the approved prospectus below as the curriculum basis for enrollment preparation.',
                                  ENT_QUOTES,
                                  'UTF-8'
                              );
                            ?>
                          </p>

                          <div class="pc-program-meta">
                            <span class="badge bg-label-primary"><?php echo htmlspecialchars((string)($programRow['program_code'] ?? 'NO CODE'), ENT_QUOTES, 'UTF-8'); ?></span>
                            <span class="badge <?php echo $programStatus === 'active' ? 'bg-label-success' : 'bg-label-secondary'; ?>">
                              <?php echo htmlspecialchars(ucfirst((string)($programRow['status'] ?? 'inactive')), ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                            <span class="badge bg-label-info"><?php echo htmlspecialchars($prospectusCount . ' Prospectus' . ($prospectusCount === 1 ? '' : ' Versions'), ENT_QUOTES, 'UTF-8'); ?></span>
                          </div>

                          <div class="pc-program-controls">
                            <label for="program-prospectus-<?php echo $programId; ?>" class="pc-program-controls-label">Prospectus Version</label>
                            <div class="pc-program-control-row">
                              <div class="pc-program-control-select">
                                <select
                                  id="program-prospectus-<?php echo $programId; ?>"
                                  class="form-select pc-prospectus-select"
                                  data-program-id="<?php echo $programId; ?>"
                                  <?php echo $prospectusCount <= 1 ? 'disabled' : ''; ?>
                                >
                                  <?php if ($prospectusCount === 0): ?>
                                    <option value="">No prospectus available</option>
                                  <?php else: ?>
                                    <?php foreach ($prospectusVersions as $index => $prospectusVersion): ?>
                                      <option
                                        value="<?php echo (int)($prospectusVersion['prospectus_id'] ?? 0); ?>"
                                        <?php echo $index === 0 ? 'selected' : ''; ?>
                                      >
                                        <?php echo htmlspecialchars((string)($prospectusVersion['label'] ?? 'Prospectus Version'), ENT_QUOTES, 'UTF-8'); ?>
                                      </option>
                                    <?php endforeach; ?>
                                  <?php endif; ?>
                                </select>
                              </div>

                              <div class="pc-program-control-button">
                                <button
                                  type="button"
                                  class="btn btn-outline-primary w-100 pc-open-prospectus"
                                  data-program-id="<?php echo $programId; ?>"
                                  <?php echo $prospectusCount === 0 ? 'disabled' : ''; ?>
                                >
                                  <i class="bx bx-book-open me-1"></i>
                                  <?php echo $prospectusCount === 0 ? 'No Prospectus Yet' : 'Show Prospectus'; ?>
                                </button>
                              </div>
                            </div>

                            <div class="pc-program-note">
                              <?php
                                if ($prospectusCount === 0) {
                                    echo 'No prospectus has been encoded for this program yet.';
                                } elseif ($prospectusCount === 1) {
                                    echo 'This program currently has one available prospectus version ready for viewing.';
                                } else {
                                    echo 'This program has multiple prospectus versions. Select the version you want to review.';
                                }
                              ?>
                            </div>
                          </div>

                          <div class="pc-prospectus-panel d-none" id="program-prospectus-panel-<?php echo $programId; ?>">
                            <div class="alert alert-info pc-prospectus-state mb-3">
                              Select a prospectus version to review this program's approved curriculum flow.
                            </div>

                            <div class="pc-prospectus-viewer d-none">
                              <div class="card sheet-card mb-0">
                                <div class="card-body p-4 p-lg-5">
                                  <div class="sheet-head">
                                    <div class="sheet-kicker">
                                      <i class="bx bx-copy-alt"></i>
                                      Prospectus Viewer
                                    </div>
                                    <div class="sheet-title pc-sheet-program-title">Program Prospectus</div>
                                    <div class="sheet-subtitle pc-sheet-program-subtitle"><?php echo htmlspecialchars($collegeName, ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="sheet-meta pc-sheet-meta"></div>
                                  </div>

                                  <div class="pc-sheet-content"></div>
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <div class="alert alert-warning mb-0">No programs are currently loaded for this Program Chair account.</div>
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
        return $(`.pc-open-prospectus[data-program-id="${programId}"]`);
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
        const state = panel.find('.pc-prospectus-state');
        const viewer = panel.find('.pc-prospectus-viewer');
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
        $('.pc-program-card.is-expanded').each(function () {
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
        const viewer = panel.find('.pc-prospectus-viewer');
        const title = panel.find('.pc-sheet-program-title');
        const subtitle = panel.find('.pc-sheet-program-subtitle');
        const meta = panel.find('.pc-sheet-meta');
        const content = panel.find('.pc-sheet-content');

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
        panel.find('.pc-prospectus-state').hide();
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
        $(document).on('click', '.pc-open-prospectus', function () {
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

        $(document).on('change', '.pc-prospectus-select', function () {
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
