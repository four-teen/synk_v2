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
$permissions=synk_management_permissions($conn,$scope);
if (empty($_SESSION['management_csrf_token'])) $_SESSION['management_csrf_token'] = bin2hex(random_bytes(32));
$currentTerm = synk_management_fetch_current_period($conn,$scope);
$page = $managementPage ?? 'dashboard';
$navigation = [
    'dashboard'=>['Dashboard','index.php','bx-home-circle','College metrics and community overview','primary'],
    'financial'=>['Financial','financial.php','bx-receipt','Payment register and receipt lookup','info'],
    'payment_corrections'=>['Payment Corrections','payment-corrections.php','bx-undo','Review and reverse student payments','warning'],
    'contributions'=>['Contributions','contributions.php','bx-wallet','Contribution setup, amounts, and records','primary'],
    'events'=>['Events','events.php','bx-calendar-event','Organization events and QR attendance','success'],
    'flag_raising'=>['Flag Raising Ceremony','flag-raising.php','bx-flag','Ceremony attendance and absence counts','success'],
    'violations'=>['Violations','violations.php','bx-error-circle','Violation types and standard amounts','warning'],
    'students'=>['Students','students.php','bx-user-pin','Unique students and imported subject lists','secondary'],
    'student_permits'=>['Print Student Permit','student-permits.php','bx-id-card','Examination permits by course and section','primary'],
    'reports'=>['Reports','reports.php','bx-printer','Period collections, fines, and event attendance','info'],
    'officers'=>['Student Officers','officers.php','bx-user-check','Authorize students and choose their operations','primary'],
    'exemptions'=>['Exemptions','exemptions.php','bx-shield-quarter','Reusable lists and saved event or contribution exemptions','warning'],
];
if (!isset($navigation[$page])) { http_response_code(404); exit('Page not found.'); }
try{
    if(in_array($page,['officers','payment_corrections'],true))synk_management_require_permission($conn,$scope,$page);
    elseif(in_array($page,['students','financial'],true))synk_management_require_permission($conn,$scope,$page==='students'?'student_records':'records');
    elseif(in_array($page,['contributions','events','flag_raising','violations','reports','exemptions','student_permits'],true))synk_management_require_permission($conn,$scope,$page);
}catch(SynkManagementPermissionDenied $error){http_response_code(403);exit(synk_management_h($error->getMessage()));}
try {
    $term = synk_management_view_term($conn, $_GET, $scope);
    $report=null;
    if($page==='reports'){
        require_once __DIR__.'/../backend/management_reports_helper.php';
        $report=synk_management_reports_prepare($conn,$scope,$term,$_GET);
    }
    if ($page === 'student_permits') {
        require_once __DIR__.'/../backend/management_student_permits_helper.php';
        $permits = synk_management_student_permits($conn,$scope,$term,$_GET);
    }
    if ($page === 'financial') {
        require_once __DIR__.'/../backend/management_payment_register_helper.php';
        $paymentRegister = synk_management_payment_register($conn,$scope,$term,$currentTerm,$_GET);
    }
    if ($page === 'payment_corrections') require __DIR__.'/payment-corrections-controller.php';
    $analytics = $page === 'dashboard' ? synk_management_analytics($conn,$scope,$term) : [];
    $collectionTotals = null;
    if ($page === 'dashboard' && synk_management_operation_allowed($permissions,'records')) {
        require_once __DIR__.'/../backend/management_collection_helper.php';
        $collectionTotals = synk_management_collection_totals($conn,$scope,$currentTerm);
    }
    $directory = null;
    if ($page === 'students') {
        // The header owns the viewing period. Removed controls must not remain
        // active through bookmarked directory/program/section filter values.
        $directoryInput = array_intersect_key($_GET,array_flip(['term','exam_term','view_ay_id','view_semester','search','page','page_size']));
        $directory = synk_management_directory($conn, $scope, $directoryInput);
        $studentIds = array_column($directory['rows'], 'student_id');
        require_once __DIR__.'/../backend/management_collection_helper.php';
        $directory['balances'] = synk_management_student_balances($conn, $scope, $term, $studentIds);
        $directory['evaluation_progress'] = synk_management_student_evaluation_progress($conn, $scope, $term, $currentTerm, $studentIds);
    }
    $violations = null;
    if ($page === 'violations') {
        if (!synk_table_exists($conn,'tbl_management_violations')) throw new RuntimeException('Violation storage has not been prepared.');
        $violations = synk_management_violations_list($conn,$scope,$_GET);
    }
    $catalog = null; $catalogOptions = null; $activityCounts = ['contributions'=>0,'events'=>0];
    if (!in_array($page,['reports','student_permits','financial','payment_corrections'],true) && synk_management_catalog_ready($conn)) {
        foreach (['contributions','events'] as $kind) {
            $periodValues = [$scope['college_id'],$scope['organization_id'],$term['ay_id'],$term['semester'],$term['exam_term']];
            $eventFilter=$kind==='events' ? ' AND NOT '.synk_management_flag_raising_sql('source_metadata') : '';
            $activityCounts[$kind] = (int)synk_management_query($conn, 'SELECT COUNT(*) AS n FROM tbl_management_' . $kind . ' WHERE college_id=? AND organization_id=? AND ay_id=? AND semester=? AND exam_term=?'.$eventFilter,'iiiis',$periodValues)[0]['n'];
        }
        if (in_array($page,['contributions','events','flag_raising'],true)) {
            $catalog = synk_management_catalog_list($conn,$scope,$term,$page,$_GET);
            $catalogOptions = synk_management_catalog_options($conn,$scope,$term);
            if ($page==='flag_raising') {
                require_once __DIR__.'/../backend/management_flag_raising_helper.php';
                $ceremonyAbsences=synk_management_flag_raising_absences($conn,$scope,$term,$_GET);
            } elseif ($page==='events' && $catalog['filters']['event_id'] && !$catalog['rows']) {
                $id=$catalog['filters']['event_id'];
                $ceremony=synk_management_query($conn,'SELECT event_id FROM tbl_management_events
                    WHERE event_id=? AND college_id=? AND organization_id=? AND ay_id=? AND semester=? AND '.synk_management_flag_raising_sql('source_metadata'),
                    'iiiii',[$id,$scope['college_id'],$scope['organization_id'],$term['ay_id'],$term['semester']]);
                if ($ceremony) {
                    header('Location: flag-raising.php?'.http_build_query(['term'=>$term['key'],'event_id'=>$id]).'#event-'.$id);
                    exit;
                }
            }
        }
    } elseif (in_array($page,['contributions','events','flag_raising'],true)) throw new RuntimeException('The Management catalog has not been prepared.');
} catch (SynkManagementPermissionDenied $error) { http_response_code(403); exit(synk_management_h($error->getMessage())); }
catch (OutOfBoundsException $error) { http_response_code(404); exit(synk_management_h($error->getMessage())); }
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
  <?php if($page==='officers'): ?><link rel="stylesheet" href="officers.css?v=<?= (int)filemtime(__DIR__.'/officers.css') ?>"><?php endif; ?>
  <?php if($page==='reports'): ?><link rel="stylesheet" href="reports.css?v=<?= (int)filemtime(__DIR__.'/reports.css') ?>"><?php endif; ?>
  <link rel="stylesheet" href="portal.css?v=<?= (int)filemtime(__DIR__.'/portal.css') ?>">
  <?php if($page==='financial'): ?><link rel="stylesheet" href="financial.css?v=<?= (int)filemtime(__DIR__.'/financial.css') ?>"><?php endif; ?>
  <?php if($page==='payment_corrections'): ?><link rel="stylesheet" href="payment-corrections.css?v=<?= (int)filemtime(__DIR__.'/payment-corrections.css') ?>"><?php endif; ?>
  <?php if($page==='student_permits'): ?><link rel="stylesheet" href="student-permits.css?v=<?= (int)filemtime(__DIR__.'/student-permits.css') ?>"><?php endif; ?>
</head>
<body class="management-portal">
<a class="management-skip" href="#main-content">Skip to content</a>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php require __DIR__ . '/sidebar.php'; ?>
<div class="layout-page">
  <?php require __DIR__ . '/navbar.php'; ?>
  <div class="content-wrapper">
  <main id="main-content" class="container-xxl flex-grow-1 container-p-y management-main">
    <?php if(!in_array($page,['students','contributions','events','flag_raising'],true)): ?>
      <section class="card dean-page-card management-heading">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
          <div>
            <p class="card-section-title management-heading-context mb-1"><span><?= synk_management_h($organizationLabel) ?> WORKSPACE</span><span aria-hidden="true">·</span><span><?= synk_management_h($scope['college_name']) ?></span></p>
            <h1 class="dean-page-title"><?= synk_management_h($pageTitle) ?></h1>
          </div>
          <?php if ($page === 'dashboard' && $permissions['payment_corrections']): ?>
            <a class="btn btn-outline-primary" id="management-dashboard-payment-corrections" href="<?= synk_management_h($pageUrl('payment-corrections.php')) ?>"><i class="bx bx-undo me-1" aria-hidden="true"></i>Payment Corrections</a>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>
    <?php if ($page === 'dashboard'): ?>
      <?php
      $dashboardStats = [['students','Students','bx-group','primary']];
      $dashboardStats = array_merge($dashboardStats,$collectionTotals !== null
          ? [['expected','Expected Collections','bx-target-lock','info'],['collected','Total Collected','bx-receipt','success'],['outstanding','Outstanding Balance','bx-wallet','warning']]
          : [['programs','Programs represented','bx-book-open','success'],['sections','Class sections','bx-buildings','info'],['enrollments','Subject registrations','bx-list-check','warning']]);
      ?>
      <section class="management-stats management-dashboard-stats" aria-label="Dashboard totals">
        <?php foreach ($dashboardStats as [$statKey,$label,$icon,$color]): ?>
        <?php if ($statKey === 'collected'): ?>
        <button type="button" class="card management-stat management-collected-stat" id="management-collected-stat" data-bs-toggle="modal" data-bs-target="#management-collections" aria-haspopup="dialog">
          <span class="management-stat-icon bg-label-<?= $color ?>"><i class="bx <?= $icon ?>" aria-hidden="true"></i></span>
          <span class="management-collected-copy"><span><?= $label ?></span><strong data-stat="collected"><?= synk_management_account_money($collectionTotals['collected']) ?></strong></span>
          <i class="bx bx-chevron-right management-collected-chevron" aria-hidden="true"></i>
        </button>
        <?php else: ?>
        <article class="card management-stat"><span class="management-stat-icon bg-label-<?= $color ?>"><i class="bx <?= $icon ?>" aria-hidden="true"></i></span><div><span><?= $label ?></span><strong data-stat="<?= $statKey ?>"><?= isset($collectionTotals[$statKey]) ? synk_management_account_money((string)$collectionTotals[$statKey]) : number_format($analytics[$statKey]) ?></strong><?php if ($statKey === 'expected'): ?><small class="text-muted">After exemptions</small><?php endif; ?></div></article>
        <?php endif; ?>
        <?php endforeach; ?>
      </section>
      <p class="management-data-note"><?php if ($collectionTotals !== null): ?><strong>Collections for <?= synk_management_h($currentTerm['period_text']) ?></strong><br><?php endif; ?>Unique students and active imported class records in <?= synk_management_h($term['term_text']) ?>.<?php if ($analytics['last_import']): ?> Latest import: <?= synk_management_h(date('M j, Y, g:i A', strtotime($analytics['last_import']))) ?>.<?php endif; ?></p>
      <div class="management-dashboard-grid">
        <section class="card management-panel" aria-labelledby="program-distribution-title"><div class="management-panel-heading"><div><span class="management-eyebrow">COLLEGE COMMUNITY</span><h2 id="program-distribution-title">Students by program</h2></div><a<?= !synk_management_operation_allowed($permissions,'student_records')?' hidden':'' ?> href="<?= synk_management_h($pageUrl('students.php')) ?>">View students <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a></div>
          <?php if (!$analytics['by_program']): ?><div class="management-empty"><i class="bx bx-group" aria-hidden="true"></i><h3>No students for this term yet</h3><p>Your college students will appear when class lists are imported into Synk.</p></div>
          <?php else: $maxStudents=max(array_column($analytics['by_program'],'students')); ?><div class="management-programs"><?php foreach ($analytics['by_program'] as $program): ?>
            <div class="management-program-row"><div><a<?= !synk_management_operation_allowed($permissions,'student_records')?' style="pointer-events:none;color:inherit" tabindex="-1"':'' ?> href="<?= synk_management_h($pageUrl('students.php',['program_id'=>$program['program_id']])) ?>"><?= synk_management_h($program['program_code']) ?><?= trim((string)$program['major']) !== '' ? ' · '.synk_management_h($program['major']) : '' ?></a><strong><?= number_format($program['students']) ?> <small>students</small></strong></div><div class="management-bar-track" aria-hidden="true"><span style="width:<?= max(1,round($program['students']/$maxStudents*100)) ?>%"></span></div><small><?= synk_management_h($program['program_name']) ?></small></div>
          <?php endforeach; ?></div><?php endif; ?>
        </section>
        <section class="card management-panel" aria-labelledby="activity-title"><div class="management-panel-heading"><div><span class="management-eyebrow">YOUR WORKSPACE</span><h2 id="activity-title">Organization activities</h2></div></div>
          <a class="management-feature"<?= !synk_management_operation_allowed($permissions,'contributions')?' hidden':'' ?> href="<?= synk_management_h($pageUrl('contributions.php')) ?>"><span class="management-stat-icon bg-label-primary"><i class="bx bx-wallet" aria-hidden="true"></i></span><div><strong>Contributions</strong><p>Contribution records for your organization.</p><span class="management-feature-state"><?= number_format($activityCounts['contributions']) ?> in this term</span></div><i class="bx bx-chevron-right" aria-hidden="true"></i></a>
          <a class="management-feature"<?= !synk_management_operation_allowed($permissions,'events')?' hidden':'' ?> href="<?= synk_management_h($pageUrl('events.php')) ?>"><span class="management-stat-icon bg-label-success"><i class="bx bx-calendar-event" aria-hidden="true"></i></span><div><strong>Events</strong><p>Your organization calendar and participation records.</p><span class="management-feature-state"><?= number_format($activityCounts['events']) ?> in this term</span></div><i class="bx bx-chevron-right" aria-hidden="true"></i></a>
        </section>
      </div>
    <?php elseif ($page === 'officers'): ?>
      <?php require __DIR__.'/officers-view.php'; ?>
    <?php elseif ($page === 'exemptions'): ?>
      <?php require __DIR__.'/exemptions-view.php'; ?>
    <?php elseif ($page === 'reports'): ?>
      <?php require __DIR__.'/reports-view.php'; ?>
    <?php elseif ($page === 'financial'): ?>
      <?php require __DIR__.'/financial-view.php'; ?>
    <?php elseif ($page === 'payment_corrections'): ?>
      <?php require __DIR__.'/payment-corrections-view.php'; ?>
    <?php elseif ($page === 'violations'): ?>
      <?php require __DIR__ . '/violations-view.php'; ?>
    <?php elseif ($page === 'students'): ?>
      <?php require __DIR__ . '/students-view.php'; ?>
    <?php elseif ($page === 'student_permits'): ?>
      <?php require __DIR__.'/student-permits-view.php'; ?>
    <?php else: ?>
      <?php require __DIR__ . '/catalog-view.php'; ?>
      <?php if ($page==='flag_raising') require __DIR__.'/flag-raising-absences-view.php'; ?>
    <?php endif; ?>
  </main>
  <?php require __DIR__ . '/../footer.php'; ?>
  <div class="content-backdrop fade"></div>
  </div>
</div></div>
<button type="button" class="layout-overlay layout-menu-toggle" id="management-menu-backdrop" aria-label="Close navigation" hidden></button>
</div>
<?php require __DIR__.'/mobile-navigation.php'; ?>
<?php if ($collectionTotals !== null) require __DIR__.'/dashboard-collections-view.php'; ?>
<?php if (in_array($page,['exemptions','events','flag_raising','contributions','students'],true)) require __DIR__.'/exemptions-modal.php'; ?>
<script type="application/json" id="management-config"><?= json_encode(['permissions'=>$permissions,'csrf_token'=>$_SESSION['management_csrf_token'],'user_id'=>(int)$_SESSION['user_id'],'college_id'=>$scope['college_id'],'campus_id'=>$scope['campus_id'],'organization_id'=>$scope['organization_id'],'current_term'=>$currentTerm['key'],'current_exam_term'=>$currentTerm['exam_term'],'current_period_version'=>$currentTerm['period_version'],'current_period'=>$currentTerm['period_key'],'current_term_text'=>$currentTerm['term_text'],'term'=>$term['key'],'exam_term'=>$term['exam_term'],'directory_term'=>$directory['filters']['term'] ?? $term['key'],'term_labels'=>array_reduce($years,static function($labels,$year){ for($sem=1;$sem<=3;$sem++) $labels[$year['ay_id'].':'.$sem]=$year['ay'].' · '.synk_semester_label($sem); return $labels; },[]),'page'=>$page,'today'=>date('Y-m-d'),'endpoint'=>'../backend/query_management.php'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script><script src="portal.js?v=<?= (int)filemtime(__DIR__.'/portal.js') ?>" defer></script>
<?php if ($page === 'students'): ?><script src="student-financial.js?v=<?= (int)filemtime(__DIR__.'/student-financial.js') ?>" defer></script><?php endif; ?>
<?php if ($page === 'officers'): ?><script src="officers.js?v=<?= (int)filemtime(__DIR__.'/officers.js') ?>" defer></script><?php endif; ?>
<?php if ($page === 'reports'): ?><script src="reports.js?v=<?= (int)filemtime(__DIR__.'/reports.js') ?>" defer></script><?php endif; ?>
<?php if ($page === 'student_permits'): ?><script src="student-permits.js?v=<?= (int)filemtime(__DIR__.'/student-permits.js') ?>" defer></script><?php endif; ?>
<?php if ($page === 'payment_corrections'): ?><script src="payment-corrections.js?v=<?= (int)filemtime(__DIR__.'/payment-corrections.js') ?>" defer></script><?php endif; ?>
<?php if (in_array($page,['events','flag_raising'],true)): ?><script src="../assets/vendor/libs/management-qr/jsQR.js" defer></script><script src="attendance.js?v=<?= (int)filemtime(__DIR__.'/attendance.js') ?>" defer></script><?php endif; ?>
<?php if (!empty($canRecordPastAttendance)): ?><script src="past-attendance.js?v=<?= (int)filemtime(__DIR__.'/past-attendance.js') ?>" defer></script><?php endif; ?>
<script src="workspace.js?v=<?= (int)filemtime(__DIR__.'/workspace.js') ?>" defer></script>
<?php if (in_array($page,['exemptions','events','flag_raising','contributions','students'],true)): ?><script src="exemptions.js?v=<?= (int)filemtime(__DIR__.'/exemptions.js') ?>" defer></script><?php endif; ?>
</body></html>
