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
$professorPortalFacultyStatusLabel = 'Manage Class';
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
            <div class="class-lms-hero-top"><span class="class-lms-eyebrow">CLASS WORKSPACE</span><span class="class-lms-term"><i class="bx bx-calendar" aria-hidden="true"></i><?= synk_professor_h($termLabel) ?></span></div>
            <div class="class-lms-subject"><span class="class-lms-code"><?= synk_professor_h($class['subject_code']) ?></span><h2><?= synk_professor_h($class['descriptive_title']) ?></h2></div>
            <p class="class-lms-section"><i class="bx bx-group" aria-hidden="true"></i><?= synk_professor_h($class['section_display'] ?: 'Section pending') ?></p>
            <p class="class-lms-program"><?= synk_professor_h($class['program_label']) ?></p>
            <div class="class-lms-hero-bottom"><span><i class="bx bx-user-circle" aria-hidden="true"></i><?= synk_professor_h($portal['faculty_name']) ?></span><span><i class="bx bx-map" aria-hidden="true"></i><?= synk_professor_h($class['campus_name']) ?></span><span><strong><?= (int)$class['student_count'] ?></strong> enrolled</span></div>
          </header>
          <nav class="class-lms-tabs" aria-label="Class workspace views">
            <a href="<?= synk_professor_h(synk_professor_class_url_lms($class)) ?>"<?= $view==='overview'?' aria-current="page"':'' ?>><i class="bx bx-grid-alt" aria-hidden="true"></i>Class overview</a>
            <a href="<?= synk_professor_h(synk_professor_class_url_lms($class,'students')) ?>"<?= $view==='students'?' aria-current="page"':'' ?>><i class="bx bx-group" aria-hidden="true"></i>Students</a>
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
          <?php else: ?>
            <div class="class-lms-overview">
              <section class="class-lms-tools" aria-labelledby="class-lms-tools-title">
                <div class="class-lms-tools-heading"><h2 id="class-lms-tools-title">Manage this subject</h2><p>Students, learning content, and class activities in one place.</p></div>
                <div class="class-lms-tool-grid">
                  <article class="card class-lms-tool class-lms-tool-students">
                    <div class="class-lms-tool-top"><span class="class-lms-card-icon"><i class="bx bx-group" aria-hidden="true"></i></span><span class="class-lms-tool-status class-lms-tool-available">Available</span></div>
                    <h3>Students</h3><p>See the students enrolled in this subject and section.</p>
                    <a class="class-lms-tool-link" href="<?= synk_professor_h(synk_professor_class_url_lms($class,'students')) ?>">View class roster<i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a>
                  </article>
                  <article class="card class-lms-tool">
                    <div class="class-lms-tool-top"><span class="class-lms-card-icon class-lms-icon-materials"><i class="bx bx-folder-open" aria-hidden="true"></i></span><span class="class-lms-tool-status">Planned</span></div>
                    <h3>Learning materials</h3><p>Organize modules, lessons, and resources for your students.</p>
                    <span class="class-lms-tool-pending">Materials will appear here</span>
                  </article>
                  <article class="card class-lms-tool">
                    <div class="class-lms-tool-top"><span class="class-lms-card-icon class-lms-icon-announcements"><i class="bx bx-bell" aria-hidden="true"></i></span><span class="class-lms-tool-status">Planned</span></div>
                    <h3>Announcements</h3><p>Share class updates, reminders, and important notices.</p>
                    <span class="class-lms-tool-pending">Class updates will appear here</span>
                  </article>
                  <article class="card class-lms-tool">
                    <div class="class-lms-tool-top"><span class="class-lms-card-icon class-lms-icon-activities"><i class="bx bx-task" aria-hidden="true"></i></span><span class="class-lms-tool-status">Planned</span></div>
                    <h3>Activities &amp; assessments</h3><p>Manage assignments, quizzes, and student submissions.</p>
                    <span class="class-lms-tool-pending">Learning activities will appear here</span>
                  </article>
                </div>
                <p class="class-lms-tools-note"><i class="bx bx-info-circle" aria-hidden="true"></i><span>Your class roster is ready to view. The other class tools are planned for the next stages.</span></p>
              </section>
              <aside class="class-lms-reference" aria-label="Selected class information">
                <section class="card class-lms-panel" aria-labelledby="class-lms-details-title">
                  <div class="class-lms-panel-head"><div><h2 id="class-lms-details-title">Class details</h2><p>Your teaching assignment.</p></div></div>
                  <dl class="class-lms-details"><div><dt>College</dt><dd><?= synk_professor_h($class['college_name']) ?></dd></div><div><dt>Program</dt><dd><?= synk_professor_h($class['program_label']) ?></dd></div><div><dt>Section</dt><dd><?= synk_professor_h($class['section_display'] ?: 'Section pending') ?></dd></div></dl>
                </section>
                <section class="card class-lms-panel" aria-labelledby="class-lms-meetings-title">
                  <div class="class-lms-panel-head"><div><h2 id="class-lms-meetings-title">Class meetings</h2><p>Your assigned schedule and rooms.</p></div></div>
                  <?php if (!$class['meetings']): ?><p class="class-lms-note">No class meetings are listed yet.</p><?php else: ?>
                    <ul class="class-lms-meetings">
                      <?php foreach ($class['meetings'] as $meeting): ?><li><span class="class-lms-meeting-type"><?= synk_professor_h($meeting['type_label']) ?></span><div><strong><?= synk_professor_h($meeting['days'] ?: 'Days to be arranged') ?></strong><span><?= synk_professor_h($meeting['time'] ?: 'Time to be arranged') ?></span><small><?= synk_professor_h($meeting['room'] ?: 'Room to be arranged') ?></small></div></li><?php endforeach; ?>
                    </ul>
                  <?php endif; ?>
                </section>
              </aside>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </main>
      <?php include __DIR__.'/../footer.php'; ?>
    </div>
  </div>
</div><div class="layout-overlay layout-menu-toggle"></div></div>
<script src="../assets/vendor/libs/jquery/jquery.js"></script><script src="../assets/vendor/libs/popper/popper.js"></script><script src="../assets/vendor/js/bootstrap.js"></script><script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script><script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script>
</body>
</html>
