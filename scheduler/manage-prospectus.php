<?php
session_start();
ob_start();
include '../backend/db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'scheduler') {
    header("Location: ../index.php");
    exit;
}

$collegeId = (int)($_SESSION['college_id'] ?? 0);
$collegeName = (string)($_SESSION['college_name'] ?? 'Assigned College');

if ($collegeId <= 0) {
    echo "Scheduler error: missing college assignment.";
    exit;
}

$programOptions = [];
$programStmt = $conn->prepare("
    SELECT
        program_id,
        program_code,
        program_name,
        major
    FROM tbl_program
    WHERE college_id = ?
      AND status = 'active'
    ORDER BY program_name ASC, major ASC, program_code ASC
");
$programStmt->bind_param("i", $collegeId);
$programStmt->execute();
$programRes = $programStmt->get_result();

while ($row = $programRes->fetch_assoc()) {
    $programId = (int)$row['program_id'];
    $major = trim((string)($row['major'] ?? ''));
    $programOptions[] = [
        'program_id' => $programId,
        'program_code' => (string)$row['program_code'],
        'program_name' => (string)$row['program_name'],
        'major' => $major
    ];
}
$programStmt->close();

$prospectusVersionsByProgram = [];
foreach ($programOptions as $program) {
    $prospectusVersionsByProgram[(string)$program['program_id']] = [];
}

$versionStmt = $conn->prepare("
    SELECT
        h.prospectus_id,
        h.program_id,
        h.cmo_no,
        h.effective_sy,
        COUNT(DISTINCT ys.pys_id) AS term_count,
        COUNT(ps.ps_id) AS subject_count
    FROM tbl_prospectus_header h
    INNER JOIN tbl_program p
        ON p.program_id = h.program_id
    LEFT JOIN tbl_prospectus_year_sem ys
        ON ys.prospectus_id = h.prospectus_id
    LEFT JOIN tbl_prospectus_subjects ps
        ON ps.pys_id = ys.pys_id
    WHERE p.college_id = ?
      AND p.status = 'active'
    GROUP BY
        h.prospectus_id,
        h.program_id,
        h.cmo_no,
        h.effective_sy
    ORDER BY h.effective_sy DESC, h.prospectus_id DESC
");
$versionStmt->bind_param("i", $collegeId);
$versionStmt->execute();
$versionRes = $versionStmt->get_result();

while ($row = $versionRes->fetch_assoc()) {
    $programKey = (string)((int)$row['program_id']);
    if (!array_key_exists($programKey, $prospectusVersionsByProgram)) {
        continue;
    }

    $prospectusVersionsByProgram[$programKey][] = [
        'prospectus_id' => (int)$row['prospectus_id'],
        'cmo_no' => (string)$row['cmo_no'],
        'effective_sy' => (string)$row['effective_sy'],
        'term_count' => (int)$row['term_count'],
        'subject_count' => (int)$row['subject_count'],
        'label' => trim((string)$row['effective_sy']) !== ''
            ? ('SY ' . (string)$row['effective_sy'] . ' - ' . (string)$row['cmo_no'])
            : (string)$row['cmo_no']
    ];
}
$versionStmt->close();

$programOptionsJson = json_encode($programOptions, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
$prospectusVersionsJson = json_encode($prospectusVersionsByProgram, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

  <title>Prospectus Information | Synk</title>

  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
  <link rel="stylesheet" href="../assets/vendor/css/core.css" />
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
  <link rel="stylesheet" href="../assets/css/demo.css" />
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
  <link
    rel="stylesheet"
    href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css"
  />
  <link rel="stylesheet" type="text/css" href="custom_css.css" />

  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>

  <style>
    .prospectus-note {
      border: 1px solid #d9e7d7;
      background: #f6fbf5;
      color: #48624b;
      border-radius: 14px;
      padding: 14px 18px;
    }

    .prospectus-note strong {
      color: #2d4c31;
    }

    .sheet-card {
      border: 1px solid #dde5f0;
      border-radius: 22px;
      background:
        linear-gradient(180deg, rgba(246, 250, 255, 0.96) 0%, rgba(255, 255, 255, 1) 100%);
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

      .semester-card.midyear {
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
            <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                <h4 class="fw-bold mb-1">
                  <i class="bx bx-book-bookmark me-2"></i> Prospectus Information
                </h4>
                <p class="text-muted mb-0">Review existing prospectus records for programs under <?= htmlspecialchars($collegeName) ?>.</p>
              </div>
            </div>

            <div class="card mb-4">
              <div class="card-header">
                <h5 class="mb-0">Prospectus Selector</h5>
                <small class="text-muted">Choose a program first, then select the available prospectus version.</small>
              </div>
              <div class="card-body">
                <div class="row g-3">
                  <div class="col-md-6">
                    <label for="program_id" class="form-label text-uppercase small fw-semibold text-muted">Program</label>
                    <select id="program_id" class="form-select">
                      <option value="">Select Program</option>
                      <?php foreach ($programOptions as $program): ?>
                        <?php
                          $label = $program['program_name'];
                          if ($program['major'] !== '') {
                              $label .= ' major in ' . $program['major'];
                          }
                          $label .= ' (' . $program['program_code'] . ')';
                        ?>
                        <option value="<?= (int)$program['program_id'] ?>"><?= htmlspecialchars($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="col-md-6">
                    <label for="prospectus_version" class="form-label text-uppercase small fw-semibold text-muted">Prospectus Version</label>
                    <select id="prospectus_version" class="form-select" disabled>
                      <option value="">Select Program First</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>

            <div id="prospectusState" class="alert alert-info state-alert">
              Select a program to view its existing prospectus.
            </div>

            <div id="prospectusViewer" class="d-none">
              <div class="card sheet-card">
                <div class="card-body p-4 p-lg-5">
                  <div class="sheet-head">
                    <div class="sheet-title" id="sheetProgramTitle">Program Prospectus</div>
                    <div class="sheet-subtitle" id="sheetProgramSubtitle"><?= htmlspecialchars($collegeName) ?></div>
                    <div class="sheet-meta" id="sheetMeta"></div>
                  </div>

                  <div id="sheetContent"></div>
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
  <script src="../assets/vendor/libs/popper/popper.js"></script>
  <script src="../assets/vendor/js/bootstrap.js"></script>
  <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
  <script src="../assets/vendor/js/menu.js"></script>
  <script src="../assets/js/main.js"></script>

  <script>
    const COLLEGE_NAME = <?= json_encode($collegeName) ?>;
    const PROGRAM_OPTIONS = <?= $programOptionsJson ?: '[]' ?>;
    const PROSPECTUS_VERSIONS_BY_PROGRAM = <?= $prospectusVersionsJson ?: '{}' ?>;

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

    function showState(type, message) {
      const state = $('#prospectusState');
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

      $('#prospectusViewer').addClass('d-none');
    }

    function getProgramRecord(programId) {
      const numericProgramId = Number(programId);
      return PROGRAM_OPTIONS.find(program => Number(program.program_id) === numericProgramId) || null;
    }

    function populateProspectusVersions(programId) {
      const select = $('#prospectus_version');
      const versions = PROSPECTUS_VERSIONS_BY_PROGRAM[String(programId)] || [];

      select.empty();

      if (!programId) {
        select.append('<option value="">Select Program First</option>');
        select.prop('disabled', true).trigger('change.select2');
        showState('info', 'Select a program to view its existing prospectus.');
        return;
      }

      if (versions.length === 0) {
        select.append('<option value="">No Prospectus Available</option>');
        select.prop('disabled', true).trigger('change.select2');
        showState(
          'warning',
          'No prospectus is available for the selected program. Prospectus creation is handled in the Administrator module.'
        );
        return;
      }

      versions.forEach((version, index) => {
        const selected = index === 0 ? ' selected' : '';
        select.append(
          `<option value="${version.prospectus_id}"${selected}>${escapeHtml(version.label)}</option>`
        );
      });

      select.prop('disabled', false).trigger('change.select2');
      loadProspectusView(select.val());
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

    function renderProspectus(data) {
      const header = data.header || {};
      const structure = data.structure || {};
      const subjects = data.subjects || {};
      const programRecord = getProgramRecord(header.program_id);

      const titleParts = [];
      if (header.program_name) titleParts.push(header.program_name);
      if (header.major) titleParts.push(`major in ${header.major}`);

      $('#sheetProgramTitle').text(titleParts.join(' ') || 'Program Prospectus');
      $('#sheetProgramSubtitle').text(COLLEGE_NAME);

      $('#sheetMeta').html(`
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
        showState('warning', 'This prospectus has no year and semester structure yet.');
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
              <div class="year-banner-note">Curriculum breakdown by semester</div>
            </div>
            <div class="semester-grid">
              ${buildSemesterTable(yearSubjects['1'] || [], 1)}
              ${buildSemesterTable(yearSubjects['2'] || [], 2)}
              ${hasMidyear ? buildSemesterTable(yearSubjects['3'] || [], 3) : ''}
            </div>
          </section>
        `;
      }).join('');

      $('#sheetContent').html(layoutHtml);
      $('#prospectusViewer').removeClass('d-none');
      $('#prospectusState').hide();
    }

    async function loadProspectusView(prospectusId) {
      if (!prospectusId) {
        showState('info', 'Select a prospectus version to review.');
        return;
      }

      showState('info', 'Loading prospectus information...');

      try {
        const response = await fetch(`../backend/query_view_prospectus.php?prospectus_id=${encodeURIComponent(prospectusId)}`, {
          credentials: 'same-origin'
        });

        const data = await response.json();

        if (!response.ok || data.error) {
          showState('danger', escapeHtml(data.error || 'Unable to load prospectus information.'));
          return;
        }

        renderProspectus(data);
      } catch (error) {
        showState('danger', 'Unable to load prospectus information.');
      }
    }

    $(document).ready(function () {
      $('#program_id').select2({
        placeholder: 'Select Program',
        width: '100%'
      });

      $('#prospectus_version').select2({
        placeholder: 'Select Prospectus Version',
        width: '100%'
      });

      $('#program_id').on('change', function () {
        populateProspectusVersions($(this).val());
      });

      $('#prospectus_version').on('change', function () {
        loadProspectusView($(this).val());
      });

      if (PROGRAM_OPTIONS.length === 0) {
        $('#program_id').prop('disabled', true).trigger('change.select2');
        showState('warning', 'No active programs are currently assigned to your scheduler college.');
        return;
      }

      if (PROGRAM_OPTIONS.length === 1) {
        $('#program_id').val(String(PROGRAM_OPTIONS[0].program_id)).trigger('change');
      }
    });
  </script>
</body>
</html>
