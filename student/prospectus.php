<?php
session_start();
ob_start();

include '../backend/db.php';
require_once '../backend/student_portal_helper.php';

synk_student_require_login($conn, true);

$studentPortalContext = synk_student_resolve_portal_context($conn);
$studentEmail = (string)($studentPortalContext['student_email'] ?? '');
$studentDirectoryRecord = is_array($studentPortalContext['directory_record'] ?? null)
    ? $studentPortalContext['directory_record']
    : null;
$studentPortalProfile = is_array($studentPortalContext['portal_profile'] ?? null)
    ? $studentPortalContext['portal_profile']
    : null;
$selectedProgramId = (int)($studentPortalProfile['program_id'] ?? $studentDirectoryRecord['program_id'] ?? 0);
$studentPortalDisplayName = (string)($studentPortalContext['student_name'] ?? 'Student');
$studentPortalDisplayEmail = $studentEmail !== ''
    ? $studentEmail
    : trim((string)($_SESSION['email'] ?? ''));
$studentPortalPreviewMode = !empty($studentPortalContext['is_admin_preview']);
$studentPortalBackUrl = $studentPortalPreviewMode
    ? synk_student_preview_return_to_url('../administrator/students/directory.php')
    : '';

$prospectusVersions = synk_student_fetch_prospectus_versions($conn, $selectedProgramId);
$selectedProspectusId = !empty($prospectusVersions)
    ? (int)($prospectusVersions[0]['prospectus_id'] ?? 0)
    : 0;

$prospectusSheet = $selectedProspectusId > 0 ? synk_student_fetch_prospectus_sheet($conn, $selectedProspectusId) : null;
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

    <title>Prospectus Viewer | Synk Student</title>

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
      .student-sheet-card {
        border: 1px solid #dde5f0;
        border-radius: 22px;
        box-shadow: 0 18px 38px rgba(67, 89, 113, 0.08);
      }

      .student-sheet-card {
        background: linear-gradient(180deg, rgba(246, 250, 255, 0.96) 0%, rgba(255, 255, 255, 1) 100%);
      }

      .student-sheet-head {
        text-align: center;
        padding-bottom: 1.75rem;
        border-bottom: 1px solid #e4ebf3;
        margin-bottom: 1.75rem;
      }

      .student-sheet-title {
        margin-top: 0;
        margin-bottom: 0.35rem;
        font-size: 1.65rem;
        font-weight: 800;
        color: #243246;
      }

      .student-sheet-subtitle {
        color: #6b7c93;
        font-size: 0.96rem;
      }

      .student-sheet-meta {
        display: flex;
        justify-content: center;
        flex-wrap: wrap;
        gap: 0.75rem;
        margin-top: 1rem;
      }

      .student-sheet-meta-pill {
        padding: 0.45rem 0.8rem;
        border-radius: 999px;
        background: #f3f6fb;
        color: #55667f;
        font-size: 0.82rem;
        font-weight: 600;
      }

      .student-year-block + .student-year-block {
        margin-top: 1.75rem;
      }

      .student-year-banner {
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

      .student-year-banner-title {
        font-size: 1rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #37465d;
      }

      .student-year-banner-note {
        color: #70829a;
        font-size: 0.8rem;
      }

      .student-semester-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
      }

      .student-semester-card {
        border: 1px solid #dfe7f1;
        border-radius: 16px;
        background: #fff;
        overflow: hidden;
      }

      .student-semester-card.midyear {
        grid-column: 1 / -1;
      }

      .student-semester-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.8rem 1rem;
        border-bottom: 1px solid #e8eef5;
        background: #fbfdff;
      }

      .student-semester-title {
        margin: 0;
        font-size: 0.92rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #39495f;
      }

      .student-semester-chip {
        padding: 0.28rem 0.65rem;
        border-radius: 999px;
        background: #eef4ff;
        color: #5168a3;
        font-size: 0.72rem;
        font-weight: 700;
      }

      .student-subject-list {
        display: grid;
        gap: 0.9rem;
        padding: 1rem;
      }

      .student-subject-item {
        border: 1px solid #dfe7f1;
        border-radius: 14px;
        background: #fbfdff;
        padding: 0.95rem 1rem;
      }

      .student-subject-code {
        margin: 0;
        font-size: 0.96rem;
        font-weight: 800;
        color: #243246;
        letter-spacing: 0.03em;
        text-transform: uppercase;
      }

      .student-subject-description {
        margin-top: 0.35rem;
        font-size: 0.95rem;
        line-height: 1.6;
        color: #35465a;
      }

      .student-subject-summary,
      .student-subject-prerequisite {
        margin-top: 0.55rem;
        font-size: 0.88rem;
        font-weight: 600;
        color: #516277;
        line-height: 1.5;
      }

      .student-subject-prerequisite {
        margin-top: 0.2rem;
      }

      .student-empty-state {
        border: 1px dashed #d7e0ec;
        border-radius: 18px;
        background: #fbfcff;
        padding: 2.1rem 1.25rem;
        text-align: center;
        color: #72839a;
      }

      @media print {
        @page {
          margin: 0.5in;
        }

        .layout-menu,
        .layout-navbar,
        .content-footer {
          display: none !important;
        }

        .layout-wrapper,
        .layout-container,
        .layout-page,
        .content-wrapper,
        .container-xxl {
          margin: 0 !important;
          padding: 0 !important;
          background: #fff !important;
        }

        .student-sheet-card {
          border: 0 !important;
          box-shadow: none !important;
        }
      }

      @media (max-width: 991.98px) {
        .student-semester-grid {
          grid-template-columns: 1fr;
        }

        .student-semester-card.midyear {
          grid-column: auto;
        }
      }

      @media (max-width: 575.98px) {
        .student-sheet-card .card-body {
          padding: 1rem !important;
        }

        .student-sheet-title {
          font-size: 1.3rem;
        }

        .student-sheet-meta {
          gap: 0.55rem;
        }

        .student-year-banner,
        .student-semester-head {
          flex-direction: column;
          align-items: flex-start;
        }

        .student-subject-list {
          padding: 0.85rem;
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
              <h4 class="fw-bold mb-3">
                <i class="bx bx-book-content me-2"></i>
                Prospectus Viewer
              </h4>

              <?php if ($selectedProgramId <= 0): ?>
                <div class="student-empty-state">
                  No enrolled program is locked on your student profile yet.
                </div>
              <?php elseif (empty($prospectusVersions)): ?>
                <div class="student-empty-state">
                  No saved prospectus version was found for your locked program.
                </div>
              <?php elseif (!$prospectusSheet): ?>
                <div class="student-empty-state">
                  The selected prospectus for your locked program could not be loaded right now.
                </div>
              <?php else: ?>
                <?php
                $header = $prospectusSheet['header'];
                $structure = $prospectusSheet['structure'];
                $subjects = $prospectusSheet['subjects'];
                ?>
                <div class="card student-sheet-card">
                  <div class="card-body p-4">
                    <div class="student-sheet-head">
                      <h1 class="student-sheet-title"><?php echo synk_student_h(synk_student_format_program_label($header)); ?></h1>
                      <p class="student-sheet-subtitle">
                        <?php echo synk_student_h((string)($header['college_name'] ?? '')); ?>
                        <?php if (trim((string)($header['campus_name'] ?? '')) !== ''): ?>
                          | <?php echo synk_student_h((string)($header['campus_name'] ?? '')); ?>
                        <?php endif; ?>
                      </p>
                      <div class="student-sheet-meta">
                        <span class="student-sheet-meta-pill"><?php echo synk_student_h((string)($header['cmo_no'] ?? 'N/A')); ?></span>
                        <span class="student-sheet-meta-pill">Effectivity SY <?php echo synk_student_h((string)($header['effective_sy'] ?? 'N/A')); ?></span>
                      </div>
                    </div>

                    <?php
                    if (!empty($structure)) {
                        ksort($structure, SORT_NUMERIC);
                    }
                    ?>

                    <?php foreach ($structure as $yearLevel => $semesterSet): ?>
                      <?php
                      if (is_array($semesterSet)) {
                          ksort($semesterSet, SORT_NUMERIC);
                      }
                      ?>
                      <section class="student-year-block">
                        <div class="student-year-banner">
                          <div class="student-year-banner-title">Year Level <?php echo synk_student_h($yearLevel); ?></div>
                          <div class="student-year-banner-note">Curriculum guide by semester</div>
                        </div>

                        <div class="student-semester-grid">
                          <?php foreach ($semesterSet as $semesterKey => $_unused): ?>
                            <?php
                            $semesterSubjects = $subjects[$yearLevel][$semesterKey] ?? [];
                            $semesterUnits = 0.0;
                            foreach ($semesterSubjects as $subjectRow) {
                                $semesterUnits += (float)($subjectRow['total_units'] ?? 0);
                            }
                            ?>
                            <div class="student-semester-card<?php echo (int)$semesterKey === 3 ? ' midyear' : ''; ?>">
                              <div class="student-semester-head">
                                <h2 class="student-semester-title"><?php echo synk_student_h(synk_semester_label((int)$semesterKey)); ?></h2>
                                <span class="student-semester-chip">
                                  <?php echo number_format(count($semesterSubjects)); ?> subject(s) | <?php echo rtrim(rtrim(number_format($semesterUnits, 2, '.', ''), '0'), '.'); ?> units
                                </span>
                              </div>

                              <div class="student-subject-list">
                                <?php if (empty($semesterSubjects)): ?>
                                  <div class="student-empty-state">
                                    No subjects encoded for this semester.
                                  </div>
                                <?php else: ?>
                                  <?php foreach ($semesterSubjects as $subjectRow): ?>
                                    <?php
                                    $subjectCode = trim((string)($subjectRow['sub_code'] ?? ''));
                                    $subjectDescription = trim((string)($subjectRow['sub_description'] ?? ''));
                                    $subjectPrerequisite = trim((string)($subjectRow['prerequisites'] ?? ''));
                                    if ($subjectPrerequisite === '') {
                                        $subjectPrerequisite = 'None';
                                    }
                                    $lecUnits = rtrim(rtrim(number_format((float)($subjectRow['lec_units'] ?? 0), 2, '.', ''), '0'), '.');
                                    $labUnits = rtrim(rtrim(number_format((float)($subjectRow['lab_units'] ?? 0), 2, '.', ''), '0'), '.');
                                    $totalUnits = rtrim(rtrim(number_format((float)($subjectRow['total_units'] ?? 0), 2, '.', ''), '0'), '.');
                                    $lecUnitsText = $lecUnits !== '' ? $lecUnits : '0';
                                    $labUnitsText = $labUnits !== '' ? $labUnits : '0';
                                    $totalUnitsText = $totalUnits !== '' ? $totalUnits : '0';
                                    ?>
                                    <article class="student-subject-item">
                                      <h3 class="student-subject-code"><?php echo synk_student_h($subjectCode !== '' ? $subjectCode : 'No Code'); ?></h3>
                                      <div class="student-subject-description">
                                        <?php echo synk_student_h($subjectDescription !== '' ? $subjectDescription : 'No description available.'); ?>
                                      </div>
                                      <div class="student-subject-summary">
                                        LECTURE: <?php echo synk_student_h($lecUnitsText); ?> hour<?php echo (float)$lecUnitsText === 1.0 ? '' : 's'; ?>
                                        | LABORATORY: <?php echo synk_student_h($labUnitsText); ?> hour<?php echo (float)$labUnitsText === 1.0 ? '' : 's'; ?>
                                        | UNITS: <?php echo synk_student_h($totalUnitsText); ?>
                                      </div>
                                      <div class="student-subject-prerequisite">
                                        PREREQUISITE: <?php echo synk_student_h(strtoupper($subjectPrerequisite)); ?>
                                      </div>
                                    </article>
                                  <?php endforeach; ?>
                                <?php endif; ?>
                              </div>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      </section>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <?php include '../footer.php'; ?>
          </div>
        </div>
      </div>
    </div>

    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
  </body>
</html>
