<?php
session_start();
ob_start();

include '../backend/db.php';
require_once '../backend/student_portal_helper.php';

synk_student_require_login($conn, true);

$studentProgramOptions = synk_student_fetch_profile_program_options($conn);
$studentPortalContext = synk_student_resolve_portal_context($conn);
$isAdminPreview = (bool)($studentPortalContext['is_admin_preview'] ?? false);
$previewStudentId = (int)($studentPortalContext['preview_student_id'] ?? 0);
$studentEmail = (string)($studentPortalContext['student_email'] ?? '');
$studentDirectoryRecord = is_array($studentPortalContext['directory_record'] ?? null)
    ? $studentPortalContext['directory_record']
    : null;
$studentPortalProfile = is_array($studentPortalContext['portal_profile'] ?? null)
    ? $studentPortalContext['portal_profile']
    : null;
$studentName = (string)($studentPortalContext['student_name'] ?? 'Student');
$profileSetupError = '';
$profileSetupStatus = $isAdminPreview ? '' : trim((string)($_GET['profile_setup'] ?? ''));

if (!$isAdminPreview && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_student_profile'])) {
    try {
        $studentPortalProfile = synk_student_save_first_portal_profile_setup(
            $conn,
            $studentEmail,
            (int)($_POST['program_id'] ?? 0),
            (string)($_POST['student_number'] ?? '')
        );

        header('Location: ' . synk_student_build_portal_url('index.php', ['profile_setup' => 'completed']));
        exit;
    } catch (Throwable $e) {
        $profileSetupError = $e->getMessage();
    }
}

$profileIsComplete = (bool)($studentPortalContext['profile_is_complete'] ?? false);
$currentTerm = synk_fetch_current_academic_term($conn);
$studentId = (int)($studentDirectoryRecord['student_id'] ?? 0);
$subjectYearOptions = synk_student_fetch_subject_year_options($conn, $studentId);
$availableSubjectAyIds = array_map(static function (array $row): int {
    return (int)($row['ay_id'] ?? 0);
}, $subjectYearOptions);
$requestedSubjectAyId = max(0, (int)($_GET['subject_ay_id'] ?? 0));
$requestedSubjectSemester = max(0, (int)($_GET['subject_semester'] ?? 0));
$selectedSubjectAyId = 0;

if ($requestedSubjectAyId > 0 && in_array($requestedSubjectAyId, $availableSubjectAyIds, true)) {
    $selectedSubjectAyId = $requestedSubjectAyId;
} elseif (in_array((int)($currentTerm['ay_id'] ?? 0), $availableSubjectAyIds, true)) {
    $selectedSubjectAyId = (int)($currentTerm['ay_id'] ?? 0);
} elseif (!empty($subjectYearOptions)) {
    $selectedSubjectAyId = (int)($subjectYearOptions[0]['ay_id'] ?? 0);
}

$subjectRows = $selectedSubjectAyId > 0
    ? synk_student_fetch_subject_rows_by_academic_year($conn, $studentId, $selectedSubjectAyId, $requestedSubjectSemester)
    : [];
$subjectSemesterGroups = synk_student_group_subject_rows_by_semester($subjectRows);
$selectedSubjectYearLabel = '';
foreach ($subjectYearOptions as $subjectYearOption) {
    if ((int)($subjectYearOption['ay_id'] ?? 0) === $selectedSubjectAyId) {
        $selectedSubjectYearLabel = (string)($subjectYearOption['academic_year_label'] ?? '');
        break;
    }
}

$studentNumberValue = $profileIsComplete
    ? trim((string)($studentPortalProfile['student_number'] ?? $studentDirectoryRecord['student_number'] ?? ''))
    : trim((string)($_POST['student_number'] ?? (string)($studentDirectoryRecord['student_number'] ?? '')));
$suggestedProgramId = synk_student_resolve_suggested_program_id($studentProgramOptions, $studentDirectoryRecord);
$selectedProgramSetupId = ($profileIsComplete && (int)($studentPortalProfile['program_id'] ?? 0) > 0)
    ? (int)($studentPortalProfile['program_id'] ?? 0)
    : max(0, (int)($_POST['program_id'] ?? $suggestedProgramId));
$studentSetupCampusOptions = [];
foreach ($studentProgramOptions as $programOption) {
    $campusId = (int)($programOption['campus_id'] ?? 0);
    if ($campusId <= 0 || isset($studentSetupCampusOptions[$campusId])) {
        continue;
    }

    $studentSetupCampusOptions[$campusId] = [
        'campus_id' => $campusId,
        'campus_code' => (string)($programOption['campus_code'] ?? ''),
        'campus_name' => (string)($programOption['campus_name'] ?? ''),
    ];
}

$selectedSetupCampusId = 0;
foreach ($studentProgramOptions as $programOption) {
    if ((int)($programOption['program_id'] ?? 0) === $selectedProgramSetupId) {
        $selectedSetupCampusId = (int)($programOption['campus_id'] ?? 0);
        break;
    }
}

$requestedSetupCampusId = (int)($_POST['setup_campus_id'] ?? 0);
if ($requestedSetupCampusId > 0 && isset($studentSetupCampusOptions[$requestedSetupCampusId])) {
    $selectedSetupCampusId = $requestedSetupCampusId;
} elseif ($selectedSetupCampusId <= 0 && $studentDirectoryRecord) {
    $directoryCampusName = strtolower(trim((string)($studentDirectoryRecord['campus_name'] ?? '')));
    foreach ($studentSetupCampusOptions as $campusOption) {
        if (strtolower(trim((string)($campusOption['campus_name'] ?? ''))) === $directoryCampusName) {
            $selectedSetupCampusId = (int)($campusOption['campus_id'] ?? 0);
            break;
        }
    }
}

$activeCampusName = trim((string)($studentPortalProfile['campus_name'] ?? $studentDirectoryRecord['campus_name'] ?? ''));
if ($activeCampusName === '') {
    $activeCampusName = 'All Campuses';
}

$activeCollegeName = trim((string)($studentPortalProfile['college_name'] ?? $studentDirectoryRecord['college_name'] ?? ''));
if ($activeCollegeName === '') {
    $activeCollegeName = 'All Colleges';
}

$studentPortalDisplayName = $studentName;
$studentPortalDisplayEmail = $studentEmail !== ''
    ? $studentEmail
    : trim((string)($_SESSION['email'] ?? ''));
$studentPortalPreviewMode = $isAdminPreview;
$studentPortalBackUrl = $isAdminPreview
    ? synk_student_preview_return_to_url('../administrator/students/directory.php')
    : '';
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

    <title>Student Dashboard | Synk</title>

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
      .student-hero-card {
        border: 1px solid #dce5f1;
        border-radius: 22px;
        overflow: hidden;
        background: linear-gradient(135deg, #f8fbff 0%, #eef5ff 55%, #eff9f1 100%);
        box-shadow: 0 18px 38px rgba(67, 89, 113, 0.08);
      }

      .student-setup-lead {
        color: #5d7289;
        line-height: 1.65;
      }

      .student-setup-lock-note {
        border: 1px solid #ffd8a8;
        border-radius: 14px;
        background: #fff5e7;
        color: #8a5714;
        padding: 0.95rem 1rem;
        font-size: 0.9rem;
        line-height: 1.55;
      }

      .student-hero-kicker {
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

      .student-setup-modal .modal-dialog {
        max-width: 980px;
      }

      .student-setup-modal .modal-content {
        border: 1px solid #dce5f1;
        border-radius: 24px;
        box-shadow: 0 22px 44px rgba(67, 89, 113, 0.18);
      }

      .student-setup-modal .modal-header {
        border-bottom: 0;
        padding-bottom: 0;
      }

      .student-setup-modal .modal-body {
        padding-top: 0.5rem;
      }

      .student-setup-hint {
        color: #667b92;
        font-size: 0.9rem;
      }

      .student-preview-banner,
      .student-subject-card {
        border: 1px solid #dce5f1;
        border-radius: 22px;
        box-shadow: 0 18px 38px rgba(67, 89, 113, 0.08);
      }

      .student-preview-banner {
        background: linear-gradient(135deg, #fff8ea 0%, #fffdf6 100%);
        color: #855b16;
      }

      .student-preview-kicker,
      .student-subject-kicker {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.35rem 0.8rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }

      .student-preview-kicker {
        background: rgba(255, 233, 194, 0.92);
        color: #8a5714;
      }

      .student-subject-kicker {
        background: #eef4ff;
        color: #4f6595;
      }

      .student-subject-card {
        background: linear-gradient(180deg, #ffffff 0%, #fbfdff 100%);
      }

      .student-subject-filter {
        display: grid;
        gap: 0.45rem;
        min-width: min(100%, 280px);
      }

      .student-subject-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 0.65rem;
      }

      .student-subject-semester + .student-subject-semester {
        margin-top: 1rem;
      }

      .student-subject-semester-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 0.95rem 1rem;
        border: 1px solid #dfe7f1;
        border-radius: 18px 18px 0 0;
        background: linear-gradient(90deg, #f6f9ff 0%, #fbfcff 100%);
      }

      .student-subject-semester-title {
        margin: 0;
        font-size: 1rem;
        font-weight: 800;
        color: #2e4057;
      }

      .student-subject-semester-note {
        margin: 0.18rem 0 0;
        color: #70829a;
        font-size: 0.85rem;
      }

      .student-subject-grid {
        display: grid;
        gap: 0.85rem;
        padding: 1rem;
        border: 1px solid #dfe7f1;
        border-top: 0;
        border-radius: 0 0 18px 18px;
        background: #fff;
      }

      .student-subject-entry {
        border: 1px solid #e3eaf3;
        border-radius: 16px;
        background: #fbfdff;
        padding: 1rem;
      }

      .student-subject-entry-head {
        display: flex;
        flex-wrap: wrap;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
      }

      .student-subject-code {
        margin: 0;
        font-size: 0.98rem;
        font-weight: 800;
        letter-spacing: 0.04em;
        color: #25364a;
        text-transform: uppercase;
      }

      .student-subject-title {
        margin: 0.35rem 0 0;
        font-size: 0.94rem;
        line-height: 1.55;
        color: #53667f;
      }

      .student-subject-meta {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 0.6rem;
        margin-top: 0.9rem;
      }

      .student-subject-meta-line {
        display: flex;
        align-items: flex-start;
        gap: 0.55rem;
        color: #4b5e78;
        font-size: 0.88rem;
        line-height: 1.5;
      }

      .student-subject-meta-line i {
        color: #6c7bf2;
        font-size: 1rem;
        margin-top: 0.08rem;
      }

      .student-subject-empty {
        border: 1px dashed #d8e1ec;
        border-radius: 18px;
        background: #fbfcff;
        padding: 2.3rem 1.25rem;
        text-align: center;
        color: #70829a;
      }

      @media (max-width: 767.98px) {
        .student-subject-semester-head,
        .student-subject-entry-head {
          flex-direction: column;
          align-items: flex-start;
        }

        .student-subject-filter {
          min-width: 100%;
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
              <?php if ($isAdminPreview): ?>
                <div class="card student-preview-banner mb-4">
                  <div class="card-body p-4">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                      <div>
                        <span class="student-preview-kicker">
                          <i class="bx bx-show-alt"></i>
                          Administrator Preview
                        </span>
                        <h5 class="mt-3 mb-1">You are viewing the student dashboard as administrator.</h5>
                        <p class="mb-0">
                          This preview uses the selected student directory record so you can review the live student-facing layout without signing in to the student's account.
                        </p>
                      </div>
                      <a href="<?php echo synk_student_h($studentPortalBackUrl); ?>" class="btn btn-outline-warning">
                        <i class="bx bx-arrow-back me-1"></i> Back to Student Directory
                      </a>
                    </div>
                  </div>
                </div>
              <?php endif; ?>

              <?php if ($isAdminPreview && !$studentDirectoryRecord): ?>
                <div class="alert alert-danger mb-4" role="alert">
                  The selected student record could not be found in `tbl_student_management`.
                </div>
              <?php endif; ?>

              <div class="card student-hero-card mb-4">
                <div class="card-body p-4">
                  <div class="row align-items-center g-4">
                    <div class="col-lg-8">
                      <span class="student-hero-kicker">
                        <i class="bx bx-book-reader"></i>
                        Student Workspace
                      </span>
                      <h4 class="mt-3 mb-2">Welcome, <?php echo synk_student_h($studentName); ?>.</h4>
                      <p class="mb-3 text-muted">
                        Browse the active academic term, review curriculum prospectuses, and inspect
                        section schedules without entering the scheduler workspace.
                      </p>
                      <div class="d-flex flex-wrap gap-2">
                        <span class="badge bg-label-primary"><?php echo synk_student_h($currentTerm['term_text'] ?? 'Current academic term'); ?></span>
                        <span class="badge bg-label-info"><?php echo synk_student_h($activeCampusName); ?></span>
                        <span class="badge bg-label-success"><?php echo synk_student_h($activeCollegeName); ?></span>
                      </div>
                    </div>
                    <div class="col-lg-4 text-center">
                      <img
                        src="../assets/img/illustrations/man-with-laptop-light.png"
                        alt="Student Portal Overview"
                        class="img-fluid"
                        style="max-height: 150px;"
                      />
                    </div>
                  </div>
                </div>
              </div>

              <div class="card student-subject-card mb-4">
                <div class="card-body p-4">
                  <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
                    <div>
                      <span class="student-subject-kicker">
                        <i class="bx bx-detail"></i>
                        Subject Listing
                      </span>
                      <h5 class="mt-3 mb-1">Enrolled subjects by academic year</h5>
                      <p class="text-muted mb-0">
                        Review the student's encoded subject records, schedules, rooms, and instructors from the student enrollment table.
                      </p>
                    </div>

                    <form method="get" class="student-subject-filter">
                      <?php if ($isAdminPreview): ?>
                        <input type="hidden" name="preview_student_id" value="<?php echo (int)$previewStudentId; ?>" />
                        <input type="hidden" name="return_to" value="<?php echo synk_student_h($studentPortalBackUrl); ?>" />
                      <?php endif; ?>
                      <?php if ($requestedSubjectSemester > 0): ?>
                        <input type="hidden" name="subject_semester" value="<?php echo (int)$requestedSubjectSemester; ?>" />
                      <?php endif; ?>
                      <label class="form-label fw-semibold mb-0" for="subject_ay_id">Academic Year</label>
                      <select
                        class="form-select"
                        id="subject_ay_id"
                        name="subject_ay_id"
                        onchange="this.form.submit()"
                        <?php echo empty($subjectYearOptions) ? 'disabled' : ''; ?>
                      >
                        <?php if (empty($subjectYearOptions)): ?>
                          <option value="0">No encoded academic year found</option>
                        <?php else: ?>
                          <?php foreach ($subjectYearOptions as $subjectYearOption): ?>
                            <option
                              value="<?php echo (int)($subjectYearOption['ay_id'] ?? 0); ?>"
                              <?php echo (int)($subjectYearOption['ay_id'] ?? 0) === $selectedSubjectAyId ? 'selected' : ''; ?>
                            >
                              <?php
                              $yearOptionLabel = trim((string)($subjectYearOption['academic_year_label'] ?? 'Academic Year'));
                              $yearOptionCount = (int)($subjectYearOption['subject_count'] ?? 0);
                              echo synk_student_h($yearOptionLabel . ' (' . $yearOptionCount . ' subjects)');
                              ?>
                            </option>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </select>
                    </form>
                  </div>

                  <div class="student-subject-summary mb-4">
                    <span class="badge bg-label-primary">
                      <?php echo synk_student_h($selectedSubjectYearLabel !== '' ? $selectedSubjectYearLabel : 'No academic year selected'); ?>
                    </span>
                    <span class="badge bg-label-info"><?php echo (int)count($subjectRows); ?> subjects</span>
                    <span class="badge bg-label-success"><?php echo (int)count($subjectSemesterGroups); ?> semester groups</span>
                  </div>

                  <?php if (empty($subjectRows)): ?>
                    <div class="student-subject-empty">
                      <i class="bx bx-spreadsheet fs-1 d-block mb-2 text-primary"></i>
                      <div class="fw-semibold mb-1">No encoded subjects are available yet.</div>
                      <div>
                        <?php if ($studentId > 0): ?>
                          Upload or encode the student's enrolled subjects first, then they will appear here by academic year.
                        <?php else: ?>
                          Save the student in the student directory first so the dashboard can load subject records.
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php else: ?>
                    <?php foreach ($subjectSemesterGroups as $subjectSemesterGroup): ?>
                      <div class="student-subject-semester">
                        <div class="student-subject-semester-head">
                          <div>
                            <h6 class="student-subject-semester-title">
                              <?php echo synk_student_h((string)($subjectSemesterGroup['semester_label'] ?? 'Semester')); ?>
                            </h6>
                            <p class="student-subject-semester-note">
                              <?php echo (int)count((array)($subjectSemesterGroup['subjects'] ?? [])); ?> encoded subjects for this term group
                            </p>
                          </div>
                          <span class="badge bg-label-secondary">
                            <?php echo synk_student_h($selectedSubjectYearLabel !== '' ? $selectedSubjectYearLabel : 'Academic Year'); ?>
                          </span>
                        </div>

                        <div class="student-subject-grid">
                          <?php foreach ((array)($subjectSemesterGroup['subjects'] ?? []) as $subjectRow): ?>
                            <article class="student-subject-entry">
                              <div class="student-subject-entry-head">
                                <div>
                                  <h6 class="student-subject-code"><?php echo synk_student_h((string)($subjectRow['subject_code'] ?? 'NO CODE')); ?></h6>
                                  <p class="student-subject-title"><?php echo synk_student_h((string)($subjectRow['descriptive_title'] ?? 'Untitled subject')); ?></p>
                                </div>

                                <?php if (trim((string)($subjectRow['section_display'] ?? '')) !== ''): ?>
                                  <span class="badge bg-label-primary"><?php echo synk_student_h((string)$subjectRow['section_display']); ?></span>
                                <?php endif; ?>
                              </div>

                              <div class="student-subject-meta">
                                <div class="student-subject-meta-line">
                                  <i class="bx bx-time-five"></i>
                                  <span><?php echo synk_student_h(trim((string)($subjectRow['schedule_text'] ?? '')) !== '' ? (string)$subjectRow['schedule_text'] : 'Schedule not available'); ?></span>
                                </div>
                                <div class="student-subject-meta-line">
                                  <i class="bx bx-map"></i>
                                  <span><?php echo synk_student_h(trim((string)($subjectRow['room_name'] ?? '')) !== '' ? (string)$subjectRow['room_name'] : 'Room not assigned'); ?></span>
                                </div>
                                <div class="student-subject-meta-line">
                                  <i class="bx bx-user-pin"></i>
                                  <span><?php echo synk_student_h(trim((string)($subjectRow['faculty_name'] ?? '')) !== '' ? (string)$subjectRow['faculty_name'] : 'Instructor not linked'); ?></span>
                                </div>
                              </div>
                            </article>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <?php if (!$isAdminPreview && !$profileIsComplete): ?>
              <div
                class="modal fade student-setup-modal"
                id="studentSetupModal"
                tabindex="-1"
                aria-labelledby="studentSetupModalLabel"
                aria-hidden="true"
                data-bs-backdrop="static"
                data-bs-keyboard="false"
              >
                <div class="modal-dialog modal-dialog-centered">
                  <div class="modal-content">
                    <div class="modal-header px-4 pt-4">
                      <div>
                        <h4 class="modal-title mb-1" id="studentSetupModalLabel">First-Time Student Setup</h4>
                        <p class="text-muted mb-0">This is required only once before the rest of the dashboard becomes available.</p>
                      </div>
                    </div>
                    <div class="modal-body px-4 pb-4">
                      <?php if ($profileSetupError !== ''): ?>
                        <div class="alert alert-danger mb-3" role="alert">
                          <?php echo synk_student_h($profileSetupError); ?>
                        </div>
                      <?php endif; ?>

                      <?php if ($profileSetupStatus === 'required'): ?>
                        <div class="alert alert-warning mb-3" role="alert">
                          Complete your enrolled program and ID number first before opening the rest of the student portal.
                        </div>
                      <?php endif; ?>

                      <p class="student-setup-lead mb-3">
                        Enter your enrolled program and ID number now. Once submitted, these details cannot be changed from the student portal because they will be used as the basis of future student information.
                      </p>

                      <div class="student-setup-lock-note mb-4">
                        This setup is mandatory. Review the program and ID number carefully before saving because the portal will lock them after the first successful submission.
                      </div>

                      <form method="post" class="row g-3">
                        <input type="hidden" name="complete_student_profile" value="1" />

                        <div class="col-md-6">
                          <label class="form-label fw-semibold" for="student_profile_campus_id">Campus</label>
                          <select class="form-select" id="student_profile_campus_id" name="setup_campus_id" required>
                            <option value="0">Select campus first</option>
                            <?php foreach ($studentSetupCampusOptions as $campusOption): ?>
                              <option value="<?php echo (int)($campusOption['campus_id'] ?? 0); ?>"<?php echo (int)($campusOption['campus_id'] ?? 0) === $selectedSetupCampusId ? ' selected' : ''; ?>>
                                <?php echo synk_student_h((string)($campusOption['campus_name'] ?? 'Campus')); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>

                        <div class="col-md-6">
                          <label class="form-label fw-semibold" for="student_profile_program_id">Enrolled Program</label>
                          <select class="form-select" id="student_profile_program_id" name="program_id" required<?php echo $selectedSetupCampusId > 0 ? '' : ' disabled'; ?>>
                            <option value="0"><?php echo $selectedSetupCampusId > 0 ? 'Select enrolled program' : 'Select campus first'; ?></option>
                          </select>
                          <div class="form-text student-setup-hint" id="student_profile_program_help">Program options will show as program code and major only.</div>
                        </div>

                        <div class="col-md-6">
                          <label class="form-label fw-semibold" for="student_profile_student_number">ID Number</label>
                          <input
                            type="text"
                            class="form-control"
                            id="student_profile_student_number"
                            name="student_number"
                            value="<?php echo synk_student_h($studentNumberValue); ?>"
                            inputmode="numeric"
                            pattern="\d{4,10}"
                            maxlength="10"
                            placeholder="Enter your ID number"
                            required
                          />
                        </div>

                        <div class="col-md-6">
                          <label class="form-label fw-semibold">Institutional Email</label>
                          <input type="text" class="form-control" value="<?php echo synk_student_h($studentEmail); ?>" readonly />
                        </div>

                        <div class="col-md-6">
                          <label class="form-label fw-semibold">Directory Name</label>
                          <input type="text" class="form-control" value="<?php echo synk_student_h($studentName); ?>" readonly />
                        </div>

                        <div class="col-12 d-flex flex-wrap gap-2">
                          <button type="submit" class="btn btn-primary">I Confirm</button>
                          <a href="../logout.php" class="btn btn-outline-secondary">Log Out</a>
                        </div>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
            <?php endif; ?>

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
    <?php if (!$isAdminPreview && !$profileIsComplete): ?>
      <script>
        window.addEventListener('load', function () {
          var setupPrograms = <?php
          $setupProgramsPayload = array_map(static function (array $programOption): array {
              return [
                  'program_id' => (int)($programOption['program_id'] ?? 0),
                  'campus_id' => (int)($programOption['campus_id'] ?? 0),
                  'label' => synk_student_format_setup_program_label($programOption),
              ];
          }, $studentProgramOptions);
          echo json_encode($setupProgramsPayload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
          ?>;
          var modalElement = document.getElementById('studentSetupModal');
          var campusSelect = document.getElementById('student_profile_campus_id');
          var programSelect = document.getElementById('student_profile_program_id');
          var programHelp = document.getElementById('student_profile_program_help');
          var selectedProgramId = <?php echo (int)$selectedProgramSetupId; ?>;

          if (!modalElement) {
            return;
          }

          function renderProgramOptions() {
            if (!campusSelect || !programSelect) {
              return;
            }

            var campusId = parseInt(campusSelect.value || '0', 10);
            var filteredPrograms = setupPrograms.filter(function (program) {
              return campusId > 0 && program.campus_id === campusId;
            });

            programSelect.innerHTML = '';

            var placeholder = document.createElement('option');
            placeholder.value = '0';

            if (campusId <= 0) {
              placeholder.textContent = 'Select campus first';
              programSelect.appendChild(placeholder);
              programSelect.disabled = true;
              if (programHelp) {
                programHelp.textContent = 'Select a campus first to load matching programs.';
              }
              return;
            }

            placeholder.textContent = filteredPrograms.length > 0 ? 'Select enrolled program' : 'No programs available for this campus';
            programSelect.appendChild(placeholder);
            programSelect.disabled = filteredPrograms.length === 0;

            filteredPrograms.forEach(function (program) {
              var option = document.createElement('option');
              option.value = String(program.program_id);
              option.textContent = program.label;
              if (program.program_id === selectedProgramId) {
                option.selected = true;
              }
              programSelect.appendChild(option);
            });

            if (!filteredPrograms.some(function (program) { return program.program_id === selectedProgramId; })) {
              selectedProgramId = 0;
              programSelect.value = '0';
            }

            if (programHelp) {
              programHelp.textContent = filteredPrograms.length > 0
                ? 'Only programs under the selected campus are shown.'
                : 'No active programs are available under the selected campus.';
            }
          }

          if (campusSelect) {
            campusSelect.addEventListener('change', function () {
              selectedProgramId = 0;
              renderProgramOptions();
            });
          }

          if (programSelect) {
            programSelect.addEventListener('change', function () {
              selectedProgramId = parseInt(programSelect.value || '0', 10);
            });
          }

          renderProgramOptions();

          var studentSetupModal = new bootstrap.Modal(modalElement, {
            backdrop: 'static',
            keyboard: false
          });
          studentSetupModal.show();
        });
      </script>
    <?php endif; ?>
  </body>
</html>
