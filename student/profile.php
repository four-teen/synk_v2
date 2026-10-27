<?php
session_start();
ob_start();

require_once '../backend/db.php';
require_once '../backend/student_dashboard_helper.php';

synk_student_require_login($conn, true);

$isAdminPreview=synk_student_is_staff_preview_mode();
$previewStudentId=$isAdminPreview ? synk_student_preview_student_id_from_request() : 0;
$studentId=$isAdminPreview ? $previewStudentId : (int)$_SESSION['student_id'];
$studentDirectoryRecord=synk_student_fetch_directory_record_by_student_id($conn,$studentId);
if (!$studentDirectoryRecord) { http_response_code(404);exit('Student record unavailable.'); }
$studentEmail=(string)$studentDirectoryRecord['email_address'];
$studentName=synk_student_directory_display_name($studentDirectoryRecord);
require_once __DIR__.'/../backend/student_profile_image_helper.php';
$profilePhotoError='';
$photoWasRequired=synk_student_profile_image_url($studentId)==='';
if (!$isAdminPreview && ($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
    try {
        if (!synk_student_login_check_csrf($_POST['csrf_token'] ?? null)) throw new DomainException('Reload your profile before uploading a photo.',403);
        synk_student_profile_save_image($conn,$studentId,$_FILES['profile_photo'] ?? null);
        header('Location: '.($photoWasRequired ? 'index.php' : 'profile.php?photo_updated=1'),true,303);exit;
    } catch (DomainException|InvalidArgumentException $error) { http_response_code((int)$error->getCode()===403 ? 403 : 422);$profilePhotoError=$error->getMessage(); }
    catch (Throwable $error) { error_log('Student photo: '.$error->getMessage());http_response_code(503);$profilePhotoError='Your photo could not be saved. Please try again.'; }
}
$profilePhotoUrl=synk_student_profile_image_url($studentId);
$profilePhotoRequired=!$isAdminPreview && $profilePhotoUrl==='';
$currentTerm=synk_fetch_current_academic_term($conn);
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

$activeCampusName=trim((string)($studentDirectoryRecord['campus_name'] ?? ''));
$activeCollegeName=trim((string)($studentDirectoryRecord['college_name'] ?? ''));
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

    <title>Manage Profile | Synk Student</title>

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
                  <h1 id="studentWorkspaceTitle">Manage Profile</h1>
                  <p class="student-profile-intro">Your name and student ID come from your college?s imported records.</p>
                  <div class="student-managed-profile">
                    <img class="student-profile-photo" src="<?= synk_student_h($profilePhotoUrl ?: 'profile-placeholder.svg') ?>" alt="<?= synk_student_h($studentName) ?>" onerror="this.onerror=null;this.src='profile-placeholder.svg';">
                    <div><strong><?= synk_student_h($studentName) ?></strong><span><?= synk_student_h($studentDirectoryRecord['student_number']) ?></span><small><?= synk_student_h($studentEmail) ?></small>
                    <?php if (!$isAdminPreview): ?><button class="btn btn-outline-primary mt-2" type="button" data-bs-toggle="modal" data-bs-target="#studentProfilePhotoModal"><i class="bx bx-camera me-1" aria-hidden="true"></i><?= $profilePhotoUrl ? 'Update photo' : 'Add photo' ?></button><?php endif; ?></div>
                  </div>
                  <?php if (!$isAdminPreview && ($_GET['photo_updated'] ?? '')==='1'): ?><p class="text-success mt-3 mb-0" role="status">Your profile photo has been updated.</p><?php endif; ?>
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

            <?php if (!$isAdminPreview) require __DIR__.'/profile-photo-modal.php'; ?>

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
    <?php if (!$isAdminPreview): ?><script src="profile-photo.js?v=<?= (int)filemtime(__DIR__.'/profile-photo.js') ?>" defer></script><?php endif; ?>
  </body>
</html>
