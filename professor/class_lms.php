<?php
session_start();
ob_start();
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/db_lms.php';
require_once __DIR__ . '/../backend/professor_class_helper_lms.php';
synk_professor_require_login($conn);
header('Cache-Control: no-store, private');
$class = null;
$roster = null;
$pageError = '';
$backUrl = 'workload.php';
$view = 'overview';
$assessmentsReady = false;
$gradingReady = false;
$gradebookReady = false;
$announcementsReady = false;
$overviewSummary = null;
try {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        throw new DomainException('Open this class from your workload.', 405);
    }
    $context = synk_professor_class_context_lms($conn, $_GET);
    $portal = $context['portal'];
    $class = $context['class'];
    $view = $context['view'];
    $backUrl .= '?term=' . (int)$class['ay_id'] . '-' . (int)$class['semester'];
    $lmsConn = synk_database_connection_lms($conn);
    if ($view === 'announcements') {
        require_once __DIR__.'/../backend/announcement_helper_lms.php';
        $announcementsReady = synk_announcement_ready_lms($lmsConn);
        if (empty($_SESSION['lms_announcement_csrf'])) $_SESSION['lms_announcement_csrf'] = bin2hex(random_bytes(32));
    }
    if ($view === 'overview') {
        require_once __DIR__ . '/../backend/class_overview_helper_lms.php';
        $overviewSummary = synk_class_overview_summary_lms($lmsConn, $context);
    }
    if ($view === 'assessments') {
        require_once __DIR__ . '/../backend/assessment_helper_lms.php';
        $assessmentsReady = synk_assessment_ready_lms($lmsConn);
        if (empty($_SESSION['lms_assessment_csrf'])) $_SESSION['lms_assessment_csrf'] = bin2hex(random_bytes(32));
    }
    if ($view === 'grading') {
        require_once __DIR__ . '/../backend/grading_helper_lms.php';
        $gradingReady = synk_grading_ready_lms($lmsConn);
        if (empty($_SESSION['lms_grading_csrf'])) $_SESSION['lms_grading_csrf'] = bin2hex(random_bytes(32));
    }
    if ($view === 'gradebook') {
        require_once __DIR__.'/../backend/gradebook_helper_lms.php';
        $gradebookReady = synk_gradebook_ready_lms($lmsConn);
        if (empty($_SESSION['lms_gradebook_csrf'])) $_SESSION['lms_gradebook_csrf'] = bin2hex(random_bytes(32));
    }
    if ($view === 'students') {
        require_once __DIR__ . '/../backend/professor_workload_students_helper.php';
        $roster = synk_professor_workload_students($conn, (int)$portal['faculty_id'], (int)$class['ay_id'], (int)$class['semester'], (int)$class['offering_id']);
    }
} catch (DomainException $error) {
    http_response_code(in_array($error->getCode(), [400, 401, 403, 404, 405, 503], true) ? $error->getCode() : 403);
    $pageError = $error->getMessage();
    $class = null;
} catch (Throwable $error) {
    http_response_code(503);
    error_log('Professor LMS class workspace is unavailable. Check the LMS connection and application logs.');
    $pageError = 'Your class workspace is temporarily unavailable. Please try again later or contact your administrator.';
    $class = null;
}
$professorPortalDisplayName = $portal['faculty_name'] ?? (string)($_SESSION['username'] ?? 'Professor');
$professorPortalDisplayEmail = $portal['email'] ?? (string)($_SESSION['email'] ?? '');
$professorPortalCompactHeader = true;
$termLabel = $class ? trim($class['academic_year_label'] . ' / ' . $class['semester_label']) : '';
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $class ? synk_professor_h($class['subject_code']) . ' | ' : '' ?>Manage Class | synQ</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon-login.png">
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
  <link rel="stylesheet" href="../assets/vendor/css/core.css">
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css">
  <link rel="stylesheet" href="../assets/css/demo.css">
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
  <link rel="stylesheet" href="custom_css.css?v=<?= (int)filemtime(__DIR__.'/custom_css.css') ?>">
  <link rel="stylesheet" href="class_lms.css?v=<?= (int)filemtime(__DIR__.'/class_lms.css') ?>">
  <?php if ($class && in_array($view, ['overview','assessments','grading','gradebook','announcements'], true)): ?><link rel="stylesheet" href="feedback_lms.css?v=<?= (int)filemtime(__DIR__.'/feedback_lms.css') ?>"><?php endif; ?>
  <?php if ($view === 'announcements' && $class): ?><link rel="stylesheet" href="announcements_lms.css?v=<?= (int)filemtime(__DIR__.'/announcements_lms.css') ?>"><?php endif; ?>
  <?php if ($view === 'assessments' && $class): ?><link rel="stylesheet" href="assessments_lms.css?v=<?= (int)filemtime(__DIR__.'/assessments_lms.css') ?>"><?php endif; ?>
  <?php if ($view === 'grading' && $class): ?><link rel="stylesheet" href="grading_lms.css?v=<?= (int)filemtime(__DIR__.'/grading_lms.css') ?>"><?php endif; ?>
  <?php if ($view === 'gradebook' && $class): ?><link rel="stylesheet" href="gradebook_lms.css?v=<?= (int)filemtime(__DIR__.'/gradebook_lms.css') ?>"><?php endif; ?>
  <script src="../assets/vendor/js/helpers.js"></script><script src="../assets/js/config.js"></script>
</head>
<body>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
  <?php include __DIR__.'/sidebar.php'; ?>
  <div class="layout-page">
    <?php include __DIR__.'/navbar.php'; ?>
    <div class="content-wrapper">
      <main class="container-xxl flex-grow-1 container-p-y class-lms-page">
        <a class="class-lms-back" href="<?= synk_professor_h($backUrl) ?>"><i class="bx bx-arrow-back" aria-hidden="true"></i>Back to my workload</a>
        <?php if ($pageError !== ''): ?>
          <section class="card class-lms-empty" role="alert"><i class="bx bx-info-circle" aria-hidden="true"></i><h1>Class workspace unavailable</h1><p><?= synk_professor_h($pageError) ?></p><a class="btn btn-primary" href="<?= synk_professor_h($backUrl) ?>">Go to my workload</a></section>
        <?php else: ?>
          <div class="class-lms-heading">
            <div><h1>Manage Class</h1><p>Your teaching space for this subject and section.</p></div>
            <span class="class-lms-heading-label"><i class="bx bx-book-open" aria-hidden="true"></i>Learning management</span>
          </div>
          <header class="card class-lms-hero">
            <div class="class-lms-identity">
              <span class="class-lms-eyebrow">CLASS WORKSPACE</span>
              <h2 class="class-lms-subject"><?= synk_professor_h($class['subject_code']) ?>: <?= synk_professor_h($class['descriptive_title']) ?></h2>
              <p class="class-lms-context"><span class="class-lms-section"><?= synk_professor_h($class['section_display'] ?: 'Section pending') ?></span><span class="class-lms-context-divider" aria-hidden="true">&middot;</span><span class="class-lms-program"><?= synk_professor_h($class['program_label']) ?></span></p>
            </div>
            <div class="class-lms-hero-meta">
              <span class="class-lms-term"><i class="bx bx-calendar" aria-hidden="true"></i><?= synk_professor_h($termLabel) ?></span>
              <span class="class-lms-enrollment"><strong><?= (int)$class['student_count'] ?></strong> enrolled</span>
            </div>
          </header>
          <nav class="class-lms-tabs" aria-label="Class workspace views" style="--class-lms-active-x: <?= ['overview'=>10,'students'=>30,'assessments'=>50,'grading'=>70,'gradebook'=>90,'announcements'=>10][$view] ?>%">
            <span class="class-lms-tab-surface" aria-hidden="true">
              <svg class="class-lms-notch-desktop" viewBox="0 0 84 48" preserveAspectRatio="xMidYMin meet" focusable="false"><path d="M0 0 C6 0 10 0 10 6 A32 32 0 0 0 74 6 C74 0 78 0 84 0 V48 H0 Z" fill="white"/></svg>
              <svg class="class-lms-notch-mobile" viewBox="0 0 76 44" preserveAspectRatio="xMidYMin meet" focusable="false"><path d="M0 0 C5 0 8 0 8 4 A30 30 0 0 0 68 4 C68 0 71 0 76 0 V44 H0 Z" fill="white"/></svg>
              <svg class="class-lms-notch-small" viewBox="0 0 66 44" preserveAspectRatio="xMidYMin meet" focusable="false"><path d="M0 0 C3 0 6 0 6 4 A27 27 0 0 0 60 4 C60 0 63 0 66 0 V44 H0 Z" fill="white"/></svg>
            </span>
            <a href="<?= synk_professor_h(synk_professor_class_url_lms($class)) ?>" aria-label="Class overview"<?= in_array($view,['overview','announcements'],true)?' aria-current="page"':'' ?>><i class="bx bx-grid-alt" aria-hidden="true"></i><span>Overview</span></a>
            <a href="<?= synk_professor_h(synk_professor_class_url_lms($class,'students')) ?>" aria-label="Students"<?= $view==='students'?' aria-current="page"':'' ?>><i class="bx bx-group" aria-hidden="true"></i><span>Students</span></a>
            <a href="<?= synk_professor_h(synk_professor_class_url_lms($class,'assessments')) ?>" aria-label="Activities and assessments"<?= $view==='assessments'?' aria-current="page"':'' ?>><i class="bx bx-task" aria-hidden="true"></i><span>Activities</span></a>
            <a href="<?= synk_professor_h(synk_professor_class_url_lms($class,'grading')) ?>" aria-label="Grading system"<?= $view==='grading'?' aria-current="page"':'' ?>><i class="bx bx-slider-alt" aria-hidden="true"></i><span>Grading</span></a>
            <a href="<?= synk_professor_h(synk_professor_class_url_lms($class,'gradebook')) ?>" aria-label="Grade Book"<?= $view==='gradebook'?' aria-current="page"':'' ?>><i class="bx bx-spreadsheet" aria-hidden="true"></i><span>Grade Book</span></a>
          </nav>
          <?php if ($view === 'students'): ?>
            <section class="card class-lms-panel" aria-labelledby="class-lms-students-title">
              <div class="class-lms-panel-head"><div><h2 id="class-lms-students-title">Class roster</h2><p>The students currently listed for this class.</p></div><span class="class-lms-count"><?= (int)$roster['counts']['students'] ?> listed</span></div>
              <?php if (!$roster['students']): ?><div class="class-lms-roster-empty"><i class="bx bx-user" aria-hidden="true"></i><p>No named students are listed for this class yet.</p></div><?php else: ?>
                <ul class="class-lms-students">
                  <?php foreach ($roster['students'] as $student): ?><li><img src="<?= synk_professor_h($student['photo_url']) ?>" alt="" width="44" height="44" loading="lazy" decoding="async"><div><strong><?= synk_professor_h($student['full_name']) ?></strong><span><?= synk_professor_h($student['student_number']) ?></span></div></li><?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <p class="class-lms-note">The named roster and recorded enrollment total may differ. Contact your college office if a student is missing.</p>
            </section>
          <?php elseif ($view === 'announcements'): ?>
            <?php include __DIR__.'/announcements_view_lms.php'; ?>
          <?php elseif ($view === 'assessments'): ?>
            <?php include __DIR__ . '/assessments_view_lms.php'; ?>
          <?php elseif ($view === 'grading'): ?>
            <?php include __DIR__ . '/grading_view_lms.php'; ?>
          <?php elseif ($view === 'gradebook'): ?>
            <?php include __DIR__ . '/gradebook_view_lms.php'; ?>
          <?php else: ?>
            <?php include __DIR__ . '/class_overview_lms.php'; ?>
          <?php endif; ?>
        <?php endif; ?>
      </main>
      <?php include __DIR__.'/../footer.php'; ?>
    </div>
  </div>
</div><div class="layout-overlay layout-menu-toggle"></div></div>
<script src="../assets/vendor/libs/jquery/jquery.js"></script><script src="../assets/vendor/libs/popper/popper.js"></script><script src="../assets/vendor/js/bootstrap.js"></script><script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script><script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script>
<?php if ($class && in_array($view, ['overview','assessments','grading','gradebook','announcements'], true)): ?><script src="../assets/js/sweetalert2.all.min.js"></script><script src="feedback_lms.js?v=<?= (int)filemtime(__DIR__.'/feedback_lms.js') ?>" defer></script><?php endif; ?>
<?php if ($view === 'announcements' && $class && $announcementsReady): ?><script src="announcements_lms.js?v=<?= (int)filemtime(__DIR__.'/announcements_lms.js') ?>" defer></script><?php endif; ?>
<?php if ($view === 'overview' && $class): ?><script src="class_overview_lms.js?v=<?= (int)filemtime(__DIR__.'/class_overview_lms.js') ?>" defer></script><?php endif; ?>
<?php if ($view === 'assessments' && $class && $assessmentsReady): ?><script src="content_lms.js?v=<?= (int)filemtime(__DIR__.'/content_lms.js') ?>" defer></script><script src="assessments_lms.js?v=<?= (int)filemtime(__DIR__.'/assessments_lms.js') ?>" defer></script><?php endif; ?>
<?php if ($view === 'grading' && $class && $gradingReady): ?><script src="grading_lms.js?v=<?= (int)filemtime(__DIR__.'/grading_lms.js') ?>" defer></script><?php endif; ?>
<?php if ($view === 'gradebook' && $class && $gradebookReady): ?><script src="gradebook_lms.js?v=<?= (int)filemtime(__DIR__.'/gradebook_lms.js') ?>" defer></script><?php endif; ?>
</body>
</html>
