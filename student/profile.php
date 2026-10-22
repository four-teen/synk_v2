<?php
session_start();
ob_start();

require_once '../backend/db.php';
require_once '../backend/student_dashboard_helper.php';

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
        if (!synk_student_login_check_csrf($_POST['csrf_token'] ?? null)) throw new RuntimeException('Refresh the profile page before saving.');
        $studentPortalProfile = synk_student_save_first_portal_profile_setup(
            $conn,
            $studentEmail,
            (int)($_POST['program_id'] ?? 0),
            (string)($_POST['student_number'] ?? '')
        );

        header('Location: ' . synk_student_build_portal_url('profile.php', ['profile_setup' => 'completed']));
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
$facultyImages = synk_student_dashboard_faculty_images($conn, array_column($subjectRows, 'faculty_id'));
foreach ($subjectRows as &$subjectRow) $subjectRow['faculty_image_url'] = $facultyImages[(int)$subjectRow['faculty_id']] ?? '';
unset($subjectRow);
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
$studentPortalDisplayEmail = $studentEmail !== '' || $isAdminPreview
    ? $studentEmail
    : trim((string)($_SESSION['email'] ?? ''));
$studentPortalPreviewMode = $isAdminPreview;
$studentPortalBackUrl = $isAdminPreview
    ? synk_student_preview_back_url()
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
      content="width=device-width, initial-scale=1.0, viewport-fit=cover"
    />

    <title>My Profile | Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" type="text/css" href="custom_css.css?v=<?= (int)filemtime(__DIR__.'/custom_css.css') ?>" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

  </head>

  <body class="student-portal student-profile">
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <?php include 'sidebar.php'; ?>

        <div class="layout-page">
          <?php include 'navbar.php'; ?>

          <div class="content-wrapper">
            <div class="container-xxl flex-grow-1 container-p-y">
              <?php if ($isAdminPreview): ?>
                <div class="alert alert-info student-preview-notice mb-4" role="status">
                  <i class="bx bx-show-alt" aria-hidden="true"></i>
                  <div><strong><?= synk_student_h(synk_student_preview_label()) ?> &middot; Read only</strong><br>You are reviewing <?= synk_student_h($studentName) ?>'s profile.</div>
                </div>
              <?php endif; ?>
              <?php if ($isAdminPreview && !$studentDirectoryRecord): ?>
                <div class="alert alert-danger mb-4" role="alert">The selected student record could not be found.</div>
              <?php endif; ?>

              <section class="card student-profile-workspace mb-4" aria-labelledby="studentWorkspaceTitle">
                <div>
                  <p class="student-eyebrow">Student Workspace</p>
                  <h1 id="studentWorkspaceTitle">Welcome, <?= synk_student_h($studentName) ?>.</h1>
                  <p class="student-profile-intro">Review your enrolled subjects and class details by academic year.</p>
                </div>
                <div class="student-profile-context" aria-label="Current academic term and college">
                  <span class="badge bg-label-primary"><?= synk_student_h($currentTerm['term_text'] ?? 'Current academic term') ?></span>
                  <span class="badge bg-label-info"><?= synk_student_h($activeCampusName) ?></span>
                  <span class="badge bg-label-secondary"><?= synk_student_h($activeCollegeName) ?></span>
                </div>
              </section>

              <section class="card student-subject-panel mb-4" aria-labelledby="profileSubjectsTitle">
                <div class="student-subject-toolbar">
                  <div>
                    <h2 id="profileSubjectsTitle">Enrolled subjects <span class="badge bg-label-primary"><?= count($subjectRows) ?></span></h2>
                    <p>Your professors, schedules and rooms, grouped by semester.</p>
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

                <?php if (empty($subjectRows)): ?>
                  <div class="student-empty-state">
                    <span class="student-empty-icon"><i class="bx bx-book-open" aria-hidden="true"></i></span>
                    <h3>No subjects for this academic year yet</h3>
                    <p>Your subjects will appear here when your college updates its student lists.</p>
                  </div>
                <?php else: ?>
                  <?php foreach ($subjectSemesterGroups as $subjectSemesterGroup): ?>
                    <section class="student-profile-semester" aria-labelledby="profile-semester-<?= (int)$subjectSemesterGroup['semester'] ?>">
                      <div class="student-profile-semester-head">
                        <h3 id="profile-semester-<?= (int)$subjectSemesterGroup['semester'] ?>"><?= synk_student_h($subjectSemesterGroup['semester_label'] ?: 'Semester') ?> <span class="badge bg-label-secondary"><?= count($subjectSemesterGroup['subjects']) ?></span></h3>
                        <span><?= synk_student_h($selectedSubjectYearLabel ?: 'Academic year') ?></span>
                      </div>
                      <div class="student-subject-grid">
                        <?php foreach ($subjectSemesterGroup['subjects'] as $subject):
                          $subjectCardId = 'profile-subject-'.$subject['student_enrollment_id'];
                          require __DIR__.'/subject-card.php';
                        endforeach; ?>
                      </div>
                    </section>
                  <?php endforeach; ?>
                <?php endif; ?>
                <div class="student-subject-help"><i class="bx bx-info-circle" aria-hidden="true"></i><p>Missing a subject or seeing an incorrect detail? Ask your college office to check your record.</p></div>
              </section>
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
                        <p class="text-muted mb-0">Complete your profile once. Your imported subjects are already available on the dashboard.</p>
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
                        Review the program and student number carefully. They are locked after the first successful submission.
                      </div>

                      <form method="post" class="row g-3">
                        <input type="hidden" name="csrf_token" value="<?php echo synk_student_h(synk_student_login_csrf()); ?>">
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
