<?php
session_start();
ob_start();
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/student_dashboard_helper.php';
synk_student_require_login($conn, true);
$isPreview = synk_student_is_staff_preview_mode();
$studentId = $isPreview ? synk_student_preview_student_id_from_request() : (int)$_SESSION['student_id'];
$student = synk_student_fetch_directory_record_by_student_id($conn, $studentId);
if (!$student) { http_response_code(404); exit('The student record could not be found.'); }
$terms = synk_student_dashboard_terms($conn, $studentId);
$viewTerm = synk_student_dashboard_view_term($studentId, $terms, $isPreview);
$subjects = synk_student_dashboard_subjects($conn, $studentId, $viewTerm);
$studentWorkload = synk_student_dashboard_workload($conn, $subjects);
$currentTerm = synk_fetch_current_academic_term($conn);
$instructors = []; $sections = [];
foreach ($subjects as $subject) {
    if ((int)$subject['faculty_id'] > 0) $instructors[(int)$subject['faculty_id']] = true;
    if (trim($subject['section_display']) !== '') $sections[$subject['section_display']] = true;
}
$studentPortalDisplayName = synk_student_directory_display_name($student);
$studentPortalDisplayEmail = (string)$student['email_address'];
$studentPortalPreviewMode = $isPreview;
$studentPortalBackUrl = $isPreview ? synk_student_preview_back_url() : '';
$studentPortalViewTerm = $viewTerm;
$firstName = trim($student['first_name']) ?: 'Student';
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>My Subjects | Synk Student</title>
  <link rel="icon" href="../assets/img/favicon/synk-icon.png" type="image/png">
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
  <link rel="stylesheet" href="../assets/vendor/css/core.css">
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css">
  <link rel="stylesheet" href="../assets/css/demo.css">
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
  <link rel="stylesheet" href="custom_css.css?v=<?= (int)filemtime(__DIR__.'/custom_css.css') ?>">
  <script src="../assets/vendor/js/helpers.js"></script><script src="../assets/js/config.js"></script>
</head>
<body class="student-portal student-dashboard">
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
  <?php include __DIR__.'/sidebar.php'; ?>
  <div class="layout-page">
    <?php include __DIR__.'/navbar.php'; ?>
    <main class="content-wrapper" id="main-content">
      <div class="container-xxl flex-grow-1 container-p-y">
        <?php if ($isPreview): ?><div class="alert alert-info student-preview-notice" role="status"><i class="bx bx-show-alt" aria-hidden="true"></i><div><strong><?= synk_student_h(synk_student_preview_label()) ?> · Read only</strong><br>You are viewing <?= synk_student_h($studentPortalDisplayName) ?>’s subjects<?= ($_SESSION['role'] ?? '')==='college_dean' ? ' for your college' : '' ?>.</div></div><?php endif; ?>
        <section class="student-welcome">
          <div><p class="student-eyebrow">STUDENT WORKSPACE</p><h1>Welcome, <?= synk_student_h($firstName) ?>.</h1><p>Your subjects and class details, at a glance.</p></div>
          <div class="student-welcome-program"><i class="bx bx-book-reader" aria-hidden="true"></i><div><strong><?= synk_student_h($student['source_program_name'] ?: 'Student record') ?></strong><span><?= synk_student_h($student['campus_name']) ?><?= (int)$student['year_level'] > 0 ? ' · Year '.(int)$student['year_level'] : '' ?></span></div></div>
        </section>
        <div class="student-summary-grid" aria-label="Subjects overview">
          <div class="card student-summary-card"><span class="student-stat-icon bg-label-primary"><i class="bx bx-book-open" aria-hidden="true"></i></span><div><span>Subjects</span><strong><?= count($subjects) ?></strong></div></div>
          <div class="card student-summary-card"><span class="student-stat-icon bg-label-success"><i class="bx bx-user-voice" aria-hidden="true"></i></span><div><span>Instructors</span><strong><?= count($instructors) ?></strong></div></div>
          <div class="card student-summary-card"><span class="student-stat-icon bg-label-info"><i class="bx bx-group" aria-hidden="true"></i></span><div><span>Sections</span><strong><?= count($sections) ?></strong></div></div>
          <div class="card student-summary-card student-units-card"><span class="student-stat-icon bg-label-warning"><i class="bx bx-time-five" aria-hidden="true"></i></span><div><span>Total units / hours</span><strong id="studentTotalUnits"><?= $studentWorkload['pending_subjects']===$studentWorkload['subjects'] && $studentWorkload['subjects']>0 ? '&mdash;' : synk_student_workload_number($studentWorkload['units']) ?><small> units<?= $studentWorkload['pending_subjects']>0 && $studentWorkload['pending_subjects']<$studentWorkload['subjects'] ? ' confirmed' : '' ?></small></strong><small class="student-hours" id="studentWeeklyHours"><?= $studentWorkload['pending_subjects']===$studentWorkload['subjects'] && $studentWorkload['subjects']>0 ? 'Hours pending' : synk_student_workload_number($studentWorkload['weekly_hours']).' contact hrs / week' ?></small><?php if ($studentWorkload['pending_subjects']>0): ?><small class="student-units-pending"><?= $studentWorkload['pending_subjects'] ?> subject<?= $studentWorkload['pending_subjects']===1?'':'s' ?> awaiting unit details</small><?php endif; ?></div></div>
        </div>
        <p class="student-workload-note">Units and weekly lecture/laboratory contact hours for <?= synk_student_h($viewTerm['term_text']) ?>.</p>
        <?php if (empty($viewTerm['is_current'])): ?><div class="student-term-notice" role="status"><i class="bx bx-history" aria-hidden="true"></i><span>You’re viewing <?= synk_student_h($viewTerm['term_text']) ?>. The current term is <?= synk_student_h($currentTerm['term_text']) ?>.</span></div><?php endif; ?>
        <section class="card student-subject-panel" aria-labelledby="subjects-title">
          <div class="student-subject-toolbar"><div><h2 id="subjects-title">My subjects <span class="badge bg-label-primary"><?= count($subjects) ?></span></h2><p><?= synk_student_h($viewTerm['term_text']) ?></p></div>
            <?php if ($subjects): ?><div class="student-search"><i class="bx bx-search" aria-hidden="true"></i><label class="visually-hidden" for="subject-search">Search your subjects</label><input id="subject-search" class="form-control" type="search" placeholder="Search subject or instructor" autocomplete="off"></div><?php endif; ?>
          </div>
          <?php if (!$subjects): ?><div class="student-empty-state"><span class="student-empty-icon"><i class="bx bx-book-open" aria-hidden="true"></i></span><h3>No subjects for this term yet</h3><p>Your subjects will appear here when your college updates its student lists.</p><?php if (count($terms) > 1): ?><button class="btn btn-outline-primary" type="button" data-bs-toggle="modal" data-bs-target="#studentTermModal"><i class="bx bx-calendar me-1" aria-hidden="true"></i>Review another term</button><?php endif; ?></div>
          <?php else: ?>
          <div class="student-subject-grid" id="student-subject-grid">
            <?php foreach ($subjects as $index=>$subject):
              $subjectCardId = 'subject-title-'.$index;
              require __DIR__.'/subject-card.php';
            endforeach; ?>
          </div>
          <div class="student-empty-state" id="subject-search-empty" hidden><i class="bx bx-search" aria-hidden="true"></i><h3>No matching subjects</h3><p>Try a subject code, title, section, or instructor name.</p><button type="button" class="btn btn-outline-primary" id="subject-search-clear">Clear search</button></div>
          <p class="student-search-result" id="subject-search-result" role="status" aria-live="polite">Showing <?= count($subjects) ?> subjects</p>
          <?php endif; ?>
          <div class="student-subject-help"><i class="bx bx-info-circle" aria-hidden="true"></i><p>These subjects come from your college’s imported student lists. Missing a subject or seeing an incorrect detail? Ask your college office to check your record.</p></div>
        </section>
      </div>
      <?php include __DIR__.'/../footer.php'; ?>
    </main>
  </div>
</div><div class="layout-overlay layout-menu-toggle"></div></div>
<div class="modal fade student-term-modal" id="studentTermModal" tabindex="-1" aria-labelledby="studentTermTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <form action="index.php" method="get" id="student-term-form">
    <div class="modal-header"><h2 class="modal-title fs-5" id="studentTermTitle">View an academic term</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><p class="text-muted">Review your imported subjects for another term.</p><label class="form-label" for="student-term">Academic year and semester</label><select class="form-select" name="student_term" id="student-term"><?php foreach ($terms as $term): ?><option value="<?= synk_student_h($term['key']) ?>"<?= $term['key']===$viewTerm['key'] ? ' selected' : '' ?>><?= synk_student_h($term['term_text']) ?><?= $term['is_current'] ? ' (Current)' : '' ?></option><?php endforeach; ?></select><?php if ($isPreview): ?><input type="hidden" name="preview_student_id" value="<?= $studentId ?>"><input type="hidden" name="return_to" value="<?= synk_student_h($studentPortalBackUrl) ?>"><?php endif; ?></div>
    <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit"<?= !$terms ? ' disabled' : '' ?>>Apply</button></div>
  </form>
</div></div></div>
<script src="../assets/vendor/libs/jquery/jquery.js"></script><script src="../assets/vendor/libs/popper/popper.js"></script><script src="../assets/vendor/js/bootstrap.js"></script><script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script><script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script>
<script src="dashboard.js?v=<?= (int)filemtime(__DIR__.'/dashboard.js') ?>" defer></script>
</body></html>
