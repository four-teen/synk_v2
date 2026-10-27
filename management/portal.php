<?php
// Shared page shell; direct requests use the dashboard and the same access checks.
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/management_portal_helper.php';
require_once __DIR__ . '/../backend/management_catalog_helper.php';
require_once __DIR__ . '/../backend/management_violations_helper.php';
require_once __DIR__ . '/../backend/management_students_helper.php';
require_once __DIR__ . '/../backend/account_switch_helper.php';
$scope = synk_management_require_scope($conn);
if (empty($_SESSION['management_csrf_token'])) $_SESSION['management_csrf_token'] = bin2hex(random_bytes(32));
$currentTerm = synk_management_fetch_current_period($conn,$scope);
$page = $managementPage ?? 'dashboard';
$navigation = [
    'dashboard'=>['Dashboard','index.php','bx-home-circle','College metrics and community overview','primary'],
    'financial'=>['Financial','financial.php','bx-receipt','New student financial records','info'],
    'contributions'=>['Contributions','contributions.php','bx-wallet','Contribution setup, amounts, and records','primary'],
    'events'=>['Events','events.php','bx-calendar-event','Organization events and QR attendance','success'],
    'violations'=>['Violations','violations.php','bx-error-circle','Violation types and standard amounts','warning'],
    'students'=>['Students','students.php','bx-user-pin','Unique students and imported subject lists','secondary'],
];
if (!isset($navigation[$page])) { http_response_code(404); exit('Page not found.'); }
try {
    $term = synk_management_view_term($conn, $_GET, $scope);
    $analytics = synk_management_analytics($conn, $scope, $term);
    $directory = null;
    if ($page === 'students') {
        // The header owns the viewing period. Removed controls must not remain
        // active through bookmarked directory/program/section filter values.
        $directoryInput = array_intersect_key($_GET,array_flip(['term','exam_term','view_ay_id','view_semester','search','page','page_size']));
        $directory = synk_management_directory($conn, $scope, $directoryInput);
    }
    $violations = null;
    if ($page === 'violations') {
        if (!synk_table_exists($conn,'tbl_management_violations')) throw new RuntimeException('Violation storage has not been prepared.');
        $violations = synk_management_violations_list($conn,$scope,$_GET);
    }
    $catalog = null; $catalogOptions = null; $activityCounts = ['contributions'=>0,'events'=>0];
    if (synk_management_catalog_ready($conn)) {
        foreach (['contributions','events'] as $kind) {
            $periodValues = [$scope['college_id'],$scope['organization_id'],$term['ay_id'],$term['semester'],$term['exam_term']];
            $activityCounts[$kind] = (int)synk_management_query($conn, 'SELECT COUNT(*) AS n FROM tbl_management_' . $kind . ' WHERE college_id=? AND organization_id=? AND ay_id=? AND semester=? AND exam_term=?','iiiis',$periodValues)[0]['n'];
        }
        if (in_array($page,['contributions','events'],true)) {
            $catalog = synk_management_catalog_list($conn,$scope,$term,$page,$_GET);
            $catalogOptions = synk_management_catalog_options($conn,$scope,$term);
        }
    } elseif (in_array($page,['contributions','events'],true)) throw new RuntimeException('The Management catalog has not been prepared.');
} catch (OutOfBoundsException $error) { http_response_code(404); exit(synk_management_h($error->getMessage())); }
catch (DomainException $error) { http_response_code(400); exit(synk_management_h($error->getMessage())); }
catch (Throwable $error) {
    error_log('Management portal: ' . $error->getMessage());
    http_response_code(503); exit('Unable to load your college workspace. Please try again later.');
}
$years = synk_management_query($conn, 'SELECT ay_id,ay FROM tbl_academic_years ORDER BY ay DESC,ay_id DESC');
$collegeLabel = trim($scope['college_code']) ?: $scope['college_name'];
$organizationLabel = $scope['organization_code'];
$pageTitle = $navigation[$page][0];
$pageUrl = static function (string $file, array $params = []) use ($term): string { return $file . '?' . http_build_query(array_merge(['term'=>$term['key'],'exam_term'=>$term['exam_term']], $params)); };
$accountName = trim((string)$_SESSION['username']) ?: 'Management';
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title><?= synk_management_h($pageTitle . ' | ' . $collegeLabel . ' Management | Synk') ?></title>
  <link rel="icon" href="../assets/img/favicon/synk-icon.png" type="image/png">
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
  <link rel="stylesheet" href="../assets/vendor/css/core.css"><link rel="stylesheet" href="../assets/vendor/css/theme-default.css">
  <link rel="stylesheet" href="../assets/css/demo.css">
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
  <link rel="stylesheet" href="../college-dean/custom_css.css?v=<?= (int)filemtime(__DIR__.'/../college-dean/custom_css.css') ?>">
  <link rel="stylesheet" href="../college-dean/students.css?v=<?= (int)filemtime(__DIR__.'/../college-dean/students.css') ?>">
  <script src="../assets/vendor/js/helpers.js"></script><script src="../assets/js/config.js"></script>
  <link rel="stylesheet" href="portal.css?v=<?= (int)filemtime(__DIR__.'/portal.css') ?>">
</head>
<body class="management-portal">
<a class="management-skip" href="#main-content">Skip to content</a>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php require __DIR__ . '/sidebar.php'; ?>
<div class="layout-page">
  <?php require __DIR__ . '/navbar.php'; ?>
  <div class="content-wrapper">
  <main id="main-content" class="container-xxl flex-grow-1 container-p-y management-main">
    <?php if(!in_array($page,['students','contributions','events'],true)): ?><section class="card dean-page-card management-heading"><div><p class="card-section-title mb-1"><?= synk_management_h($organizationLabel) ?> WORKSPACE</p><h1 class="dean-page-title"><?= $page === 'dashboard' ? 'Dashboard' : synk_management_h($pageTitle) ?></h1><p class="dean-page-copy mb-0"><?= synk_management_h($scope['organization_name'].' · '.$scope['college_name']) ?></p></div></section><?php endif; ?>
    <?php if ($page === 'dashboard'): ?>
      <section class="management-stats" aria-label="College overview">
        <?php foreach ([['students','Students','bx-group','primary'],['programs','Programs represented','bx-book-open','success'],['sections','Class sections','bx-buildings','info'],['enrollments','Subject registrations','bx-list-check','warning']] as $stat): ?>
        <article class="card management-stat"><span class="management-stat-icon bg-label-<?= $stat[3] ?>"><i class="bx <?= $stat[2] ?>" aria-hidden="true"></i></span><div><span><?= $stat[1] ?></span><strong data-stat="<?= $stat[0] ?>"><?= number_format($analytics[$stat[0]]) ?></strong></div></article>
        <?php endforeach; ?>
      </section>
      <p class="management-data-note">Unique students and active imported class records in <?= synk_management_h($term['term_text']) ?>.<?php if ($analytics['last_import']): ?> Latest import: <?= synk_management_h(date('M j, Y, g:i A', strtotime($analytics['last_import']))) ?>.<?php endif; ?></p>
      <div class="management-dashboard-grid">
        <section class="card management-panel" aria-labelledby="program-distribution-title"><div class="management-panel-heading"><div><span class="management-eyebrow">COLLEGE COMMUNITY</span><h2 id="program-distribution-title">Students by program</h2></div><a href="<?= synk_management_h($pageUrl('students.php')) ?>">View students <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a></div>
          <?php if (!$analytics['by_program']): ?><div class="management-empty"><i class="bx bx-group" aria-hidden="true"></i><h3>No students for this term yet</h3><p>Your college students will appear when class lists are imported into Synk.</p></div>
          <?php else: $maxStudents=max(array_column($analytics['by_program'],'students')); ?><div class="management-programs"><?php foreach ($analytics['by_program'] as $program): ?>
            <div class="management-program-row"><div><a href="<?= synk_management_h($pageUrl('students.php',['program_id'=>$program['program_id']])) ?>"><?= synk_management_h($program['program_code']) ?><?= trim((string)$program['major']) !== '' ? ' · '.synk_management_h($program['major']) : '' ?></a><strong><?= number_format($program['students']) ?> <small>students</small></strong></div><div class="management-bar-track" aria-hidden="true"><span style="width:<?= max(1,round($program['students']/$maxStudents*100)) ?>%"></span></div><small><?= synk_management_h($program['program_name']) ?></small></div>
          <?php endforeach; ?></div><?php endif; ?>
        </section>
        <section class="card management-panel" aria-labelledby="activity-title"><div class="management-panel-heading"><div><span class="management-eyebrow">YOUR WORKSPACE</span><h2 id="activity-title">Organization activities</h2></div></div>
          <a class="management-feature" href="<?= synk_management_h($pageUrl('contributions.php')) ?>"><span class="management-stat-icon bg-label-primary"><i class="bx bx-wallet" aria-hidden="true"></i></span><div><strong>Contributions</strong><p>Contribution records for your organization.</p><span class="management-feature-state"><?= number_format($activityCounts['contributions']) ?> in this term</span></div><i class="bx bx-chevron-right" aria-hidden="true"></i></a>
          <a class="management-feature" href="<?= synk_management_h($pageUrl('events.php')) ?>"><span class="management-stat-icon bg-label-success"><i class="bx bx-calendar-event" aria-hidden="true"></i></span><div><strong>Events</strong><p>Your organization calendar and participation records.</p><span class="management-feature-state"><?= number_format($activityCounts['events']) ?> in this term</span></div><i class="bx bx-chevron-right" aria-hidden="true"></i></a>
        </section>
      </div>
    <?php elseif ($page === 'financial'): ?>
      <?php require __DIR__.'/financial-view.php'; ?>
    <?php elseif ($page === 'violations'): ?>
      <?php require __DIR__ . '/violations-view.php'; ?>
    <?php elseif ($page === 'students'): ?>
      <?php require __DIR__ . '/students-view.php'; ?>
    <?php else: ?>
      <?php require __DIR__ . '/catalog-view.php'; ?>
    <?php endif; ?>
  </main>
  <?php require __DIR__ . '/../footer.php'; ?>
  <div class="content-backdrop fade"></div>
  </div>
</div></div>
<button type="button" class="layout-overlay layout-menu-toggle" id="management-menu-backdrop" aria-label="Close navigation" hidden></button>
</div>
<?php require __DIR__.'/mobile-navigation.php'; ?>
<script type="application/json" id="management-config"><?= json_encode(['csrf_token'=>$_SESSION['management_csrf_token'],'college_id'=>$scope['college_id'],'campus_id'=>$scope['campus_id'],'organization_id'=>$scope['organization_id'],'current_term'=>$currentTerm['key'],'current_exam_term'=>$currentTerm['exam_term'],'current_period_version'=>$currentTerm['period_version'],'current_period'=>$currentTerm['period_key'],'current_term_text'=>$currentTerm['term_text'],'term'=>$term['key'],'exam_term'=>$term['exam_term'],'directory_term'=>$directory['filters']['term'] ?? $term['key'],'term_labels'=>array_reduce($years,static function($labels,$year){ for($sem=1;$sem<=3;$sem++) $labels[$year['ay_id'].':'.$sem]=$year['ay'].' · '.synk_semester_label($sem); return $labels; },[]),'page'=>$page,'today'=>date('Y-m-d'),'endpoint'=>'../backend/query_management.php'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script><script src="portal.js?v=<?= (int)filemtime(__DIR__.'/portal.js') ?>" defer></script>
<?php if ($page === 'students'): ?><script src="student-financial.js?v=<?= (int)filemtime(__DIR__.'/student-financial.js') ?>" defer></script><?php endif; ?>
<?php if ($page === 'events'): ?><script src="../assets/vendor/libs/management-qr/jsQR.js" defer></script><script src="attendance.js?v=<?= (int)filemtime(__DIR__.'/attendance.js') ?>" defer></script><?php endif; ?>
<script src="workspace.js?v=<?= (int)filemtime(__DIR__.'/workspace.js') ?>" defer></script>
</body></html>
