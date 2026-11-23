<?php
session_start();
ob_start();
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/professor_student_roster_helper.php';
require_once __DIR__.'/../backend/auth_useraccount.php';
synk_professor_require_login($conn);
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

$roster=['students'=>[],'contexts'=>[]];
$error='';
$term=[];
try {
    $account=synk_find_useraccount_by_id($conn,(int)$_SESSION['user_id']);
    if (!$account || $account['status']!=='active' || !synk_useraccount_has_active_role($conn,$account,'professor')) {
        throw new DomainException('Your professor account is no longer available.');
    }
    $portal=synk_professor_resolve_portal_context($conn);
    $professorPortalDisplayName=$portal['faculty_name'];
    $professorPortalDisplayEmail=$portal['email'];
    if (!$portal['faculty_is_linked'] || !$portal['faculty_is_active']) {
        throw new DomainException('Ask your administrator to link an active faculty record to your account.');
    }
    $term=synk_fetch_current_academic_term($conn);
    if (!$term['ay_id'] || !$term['ay_label'] || !in_array($term['semester'],[1,2,3],true)) {
        throw new DomainException('Ask your administrator to set the current academic year and semester.');
    }
    $roster=synk_professor_student_roster($conn,(int)$portal['faculty_id'],$term);
    if ($roster['students'] && empty($_SESSION['lms_class_students_csrf'])) {
        $_SESSION['lms_class_students_csrf']=bin2hex(random_bytes(32));
    }
} catch (DomainException $exception) {
    http_response_code(403);
    $error=$exception->getMessage();
} catch (Throwable $exception) {
    http_response_code(503);
    error_log('Professor student roster could not be loaded.');
    $error='Your student roster could not be loaded. Reload the page to try again.';
}
$canEditStudentEmail=$error==='' && !empty($roster['students']);
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Student Roster | Professor | Synk</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png">
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
  <link rel="stylesheet" href="../assets/vendor/css/core.css">
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css">
  <link rel="stylesheet" href="../assets/css/demo.css">
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
  <link rel="stylesheet" href="custom_css.css?v=<?= filemtime(__DIR__.'/custom_css.css') ?>">
  <link rel="stylesheet" href="workload.css?v=<?= filemtime(__DIR__.'/workload.css') ?>">
  <link rel="stylesheet" href="class_students_lms.css?v=<?= filemtime(__DIR__.'/class_students_lms.css') ?>">
  <link rel="stylesheet" href="student-roster.css?v=<?= filemtime(__DIR__.'/student-roster.css') ?>">
  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>
</head>
<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">
    <?php require __DIR__.'/sidebar.php'; ?>
    <div class="layout-page">
      <?php require __DIR__.'/navbar.php'; ?>
      <div class="content-wrapper">
        <main class="container-xxl flex-grow-1 container-p-y workload-page" id="professorStudentRoster">
          <header class="workload-heading">
            <div><h1>Student Roster</h1><p><?php if ($term): ?><?= synk_professor_h($term['term_text']) ?> · <?php endif; ?>Correct school emails for students in your classes.</p></div>
            <a class="btn btn-outline-secondary" href="workload.php"><i class="bx bx-book-open" aria-hidden="true"></i> My workload</a>
          </header>
          <?php if ($error!==''): ?>
            <p class="alert alert-warning" role="alert"><?= synk_professor_h($error) ?></p>
          <?php elseif (!$canEditStudentEmail): ?>
            <section class="card workload-empty"><i class="bx bx-group" aria-hidden="true"></i><h2>No students listed</h2><p>Students will appear here when they are enrolled in your current classes.</p></section>
          <?php else: ?>
            <section class="card student-roster-card" aria-labelledby="student-roster-heading">
              <div class="student-roster-toolbar">
                <div><h2 id="student-roster-heading">Your students</h2><p id="student-roster-count" role="status"><?= count($roster['students']) ?> students</p></div>
                <div class="student-roster-search" id="student-roster-search" hidden>
                  <i class="bx bx-search" aria-hidden="true"></i>
                  <label class="visually-hidden" for="student-roster-query">Search students</label>
                  <input class="form-control" type="search" id="student-roster-query" placeholder="Search name, student ID, or email" autocomplete="off">
                </div>
              </div>
              <p class="student-roster-saved" id="student-roster-saved" role="status" hidden></p>
              <div class="student-roster-columns" aria-hidden="true"><span>Student</span><span>School email</span><span>Classes</span><span></span></div>
              <ul class="student-roster-list" id="student-roster-list">
                <?php foreach ($roster['students'] as $student): ?>
                <li class="student-roster-row" data-roster-row>
                  <div class="student-roster-identity">
                    <button type="button" class="student-roster-avatar" data-roster-photo aria-haspopup="dialog" aria-controls="student-roster-photo-modal" aria-label="<?= synk_professor_h('View photo: '.$student['name']) ?>">
                      <img src="<?= synk_professor_h($student['photo_url']) ?>" alt="" width="44" height="44" loading="lazy" decoding="async">
                    </button>
                    <div><strong data-roster-name><?= synk_professor_h($student['name']) ?></strong><small data-roster-number><?= synk_professor_h($student['student_number']) ?></small></div>
                  </div>
                  <div class="student-roster-email"><span class="student-roster-label">School email</span><span data-roster-email class="<?= $student['email']===''?'student-roster-missing':'' ?>"><?= synk_professor_h($student['email'] ?: 'No email on file') ?></span></div>
                  <div class="student-roster-classes"><span class="student-roster-label">Classes</span><span data-roster-classes><?= synk_professor_h(implode(' / ',$student['classes'])) ?></span></div>
                  <button type="button" class="btn btn-sm btn-outline-primary student-roster-edit" data-csr-email="<?= $student['student_id'] ?>" data-roster-offering="<?= (int)key($student['classes']) ?>" aria-label="<?= synk_professor_h('Edit email: '.$student['name']) ?>"><i class="bx bx-edit-alt" aria-hidden="true"></i> Edit email</button>
                </li>
                <?php endforeach; ?>
              </ul>
              <div class="student-roster-empty" id="student-roster-empty" hidden>
                <p>No students match your search.</p><button type="button" class="btn btn-outline-primary btn-sm" id="student-roster-clear">Clear search</button>
              </div>
              <nav class="student-roster-pagination" aria-label="Student roster pages" id="student-roster-pages" hidden>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="student-roster-previous">Previous</button>
                <span id="student-roster-page"></span>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="student-roster-next">Next</button>
              </nav>
            </section>
            <?php require __DIR__.'/student-email-dialog.php'; ?>
            <div class="modal fade" id="student-roster-photo-modal" tabindex="-1" aria-labelledby="student-roster-photo-name">
              <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content student-roster-photo-dialog">
                  <div class="modal-header">
                    <h2 class="modal-title fs-5" id="student-roster-photo-name"></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close photo"></button>
                  </div>
                  <div class="modal-body">
                    <div class="student-roster-photo-stage"><img id="student-roster-photo-image" alt=""></div>
                    <dl class="student-roster-photo-details">
                      <div><dt>Student ID</dt><dd id="student-roster-photo-number"></dd></div>
                      <div><dt>School email</dt><dd id="student-roster-photo-email"></dd></div>
                      <div><dt>Classes</dt><dd id="student-roster-photo-classes"></dd></div>
                    </dl>
                  </div>
                </div>
              </div>
            </div>
            <script id="student-roster-config" type="application/json"><?= json_encode(['contexts'=>$roster['contexts'],'csrf_token'=>$_SESSION['lms_class_students_csrf']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
          <?php endif; ?>
        </main>
        <?php require __DIR__.'/../footer.php'; ?>
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
<script src="../assets/vendor/js/menu.js"></script>
<script src="../assets/js/main.js"></script>
<?php if ($canEditStudentEmail): ?>
<script src="student-email-editor.js?v=<?= filemtime(__DIR__.'/student-email-editor.js') ?>" defer></script>
<script src="student-roster.js?v=<?= filemtime(__DIR__.'/student-roster.js') ?>" defer></script>
<?php endif; ?>
</body>
</html>
