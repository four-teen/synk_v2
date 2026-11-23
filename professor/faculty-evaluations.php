<?php
session_start();
ob_start();
require_once '../backend/db.php';
require_once '../backend/professor_evaluations_helper.php';
header('Cache-Control: no-store, private');
header('Vary: Cookie');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET')!=='GET') { http_response_code(405); header('Allow: GET'); exit; }
synk_professor_require_login($conn);
$evaluationError=''; $evaluationTerm=null; $evaluationSubjects=[]; $evaluationCounts=[]; $consolidated=null; $multipleColleges=false;
$evaluationType='student';
$professorPortalDisplayName='Professor'; $professorPortalDisplayEmail=(string)($_SESSION['email'] ?? '');
try {
    $evaluationPortal=synk_professor_evaluation_identity($conn);
    $professorPortalDisplayName=$evaluationPortal['faculty_name']; $professorPortalDisplayEmail=$evaluationPortal['email'];
    if (isset($_GET['type']) && (!is_string($_GET['type']) || !in_array($_GET['type'],['student','supervisor'],true))) throw new DomainException('Choose Student evaluation or Supervisor evaluation.');
    $evaluationType=$_GET['type'] ?? 'student';
    $evaluationTerm=synk_professor_evaluation_current_term($conn);
    if ($evaluationType==='student') {
        $consolidated=synk_professor_evaluation_consolidated_results($conn,$evaluationTerm);
        $evaluationSubjects=$consolidated['classes'];
        $evaluationCounts=$consolidated['class_counts'];
        $visibleColleges=array_merge(array_column($evaluationSubjects,'evaluation_college_id'),array_column($consolidated['colleges'],'college_id'));
        $multipleColleges=count(array_unique($visibleColleges))>1;
    }
} catch (DomainException $error) { http_response_code(403); $evaluationError=$error->getMessage(); }
catch (Throwable $error) { http_response_code(500); error_log('Professor evaluation results: '.$error->getMessage()); $evaluationError='Unable to load evaluation results. Please try again.'; }
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>My Evaluation Results | Professor | Synk</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png">
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
  <link rel="stylesheet" href="../assets/vendor/css/core.css"><link rel="stylesheet" href="../assets/vendor/css/theme-default.css">
  <link rel="stylesheet" href="../assets/css/demo.css"><link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
  <link rel="stylesheet" href="custom_css.css?v=<?php echo filemtime(__DIR__.'/custom_css.css'); ?>">
  <link rel="stylesheet" href="workload.css?v=<?php echo filemtime(__DIR__.'/workload.css'); ?>">
  <link rel="stylesheet" href="faculty-evaluations.css?v=<?php echo filemtime(__DIR__.'/faculty-evaluations.css'); ?>">
  <script src="../assets/vendor/js/helpers.js"></script><script src="../assets/js/config.js"></script>
</head>
<body><div class="layout-wrapper layout-content-navbar"><div class="layout-container">
<?php include 'sidebar.php'; ?>
<div class="layout-page"><?php include 'navbar.php'; ?><div class="content-wrapper">
<main class="container-xxl flex-grow-1 container-p-y workload-page evaluation-page" id="professorEvaluationResults">
  <header class="workload-heading">
    <div><span class="workload-eyebrow">PROFESSOR PORTAL</span><h1>My evaluation results</h1><p>Your subjects and released evaluation feedback for the current term.</p></div>
    <a href="workload.php" class="btn btn-outline-secondary"><i class="bx bx-book-open" aria-hidden="true"></i>My workload</a>
  </header>
  <?php if ($evaluationError!==''): ?><div class="alert alert-warning" role="alert"><?php echo synk_professor_evaluation_h($evaluationError); ?></div><?php endif; ?>
  <?php if (!$evaluationError): ?>
    <section class="card evaluation-controls" aria-label="Evaluation selection">
      <nav class="evaluation-type-switch" aria-label="Evaluation type">
        <?php foreach (['student'=>['Student evaluation','bx-group'],'supervisor'=>['Supervisor evaluation','bx-user-check']] as $type=>$tabInfo): ?>
          <a class="evaluation-type<?= $evaluationType===$type?' is-active':'' ?>"<?= $evaluationType===$type?' aria-current="page"':'' ?> href="faculty-evaluations.php?<?= synk_professor_evaluation_h(http_build_query(['type'=>$type])) ?>"><i class="bx <?= $tabInfo[1] ?>" aria-hidden="true"></i><?= $tabInfo[0] ?></a>
        <?php endforeach; ?>
      </nav>
    </section>
  <?php endif; ?>
  <?php if (!$evaluationError && $evaluationType==='supervisor'): ?>
    <div class="card workload-empty evaluation-supervisor-placeholder"><i class="bx bx-user-check" aria-hidden="true"></i><h2>Supervisor evaluation</h2><p>The supervisor evaluation view will be added later.</p></div>
  <?php elseif (!$evaluationError && !$evaluationSubjects && !$consolidated['groups']): ?>
    <div class="card workload-empty evaluation-locked"><i class="bx bx-lock-alt" aria-hidden="true"></i><h2>No released results yet</h2><p>You have no released student evaluation results for <?= synk_professor_evaluation_h($evaluationTerm['term_text']) ?>.</p></div>
  <?php elseif (!$evaluationError): ?>
    <?php if ($evaluationSubjects): ?>
    <div class="evaluation-scope-note"><i class="bx bx-info-circle" aria-hidden="true"></i><p>Open a subject card to view its linked evaluation results or the students who have not yet evaluated.</p></div>
    <div class="workload-list-heading">
      <div><h2>Your subjects <span class="evaluation-count"><?= count($evaluationSubjects) ?></span></h2><p id="evaluation-result-count" role="status" aria-live="polite"><?= count($evaluationSubjects)===1?'1 assigned class':count($evaluationSubjects).' assigned classes' ?> · <?= synk_professor_evaluation_h($evaluationTerm['ay_label'].' / '.$evaluationTerm['semester_label']) ?></p></div>
      <div class="workload-search" id="evaluation-search-ui" hidden><label class="visually-hidden" for="evaluation-search">Search subjects</label><i class="bx bx-search" aria-hidden="true"></i><input id="evaluation-search" type="search" class="form-control" placeholder="Search subject, section, or room" autocomplete="off"></div>
    </div>
    <div class="evaluation-subject-list">
      <?php foreach ($evaluationSubjects as $row): ?>
      <article class="card evaluation-subject-card" data-evaluation-subject data-offering-id="<?= (int)$row['offering_id'] ?>" data-college-id="<?= (int)$row['evaluation_college_id'] ?>" data-ay-id="<?= (int)$evaluationTerm['ay_id'] ?>" data-semester="<?= (int)$evaluationTerm['semester'] ?>" data-details-url="faculty-evaluation-details.php" data-search="<?= synk_professor_evaluation_h(implode(' ',[$row['subject_code'],$row['descriptive_title'],$row['section_display'],$row['room_name'],$row['schedule_text'],$row['evaluation_college_name']])) ?>" aria-label="<?= synk_professor_evaluation_h($row['subject_code'].' · '.$row['section_display']) ?>">
        <div class="evaluation-subject-identity">
          <?php require __DIR__.'/workload-class-summary.php'; ?>
          <?php if ($multipleColleges): ?><p class="evaluation-panel-note mt-2 mb-0"><?= synk_professor_evaluation_h($row['evaluation_college_name']) ?></p><?php endif; ?>
        </div>
        <?php $counts=$evaluationCounts[$row['offering_id']] ?? ['total'=>0,'evaluated'=>0,'not_evaluated'=>0]; ?>
        <dl class="evaluation-student-counts" aria-label="Student evaluation completion">
          <div><dt>Total students</dt><dd data-count="total"><?= (int)$counts['total'] ?></dd></div>
          <div class="is-evaluated"><dt>Evaluated</dt><dd data-count="evaluated"><?= (int)$counts['evaluated'] ?></dd></div>
          <div class="is-pending"><dt><span class="visually-hidden">Not evaluated</span></dt><dd><button type="button" class="evaluation-pending-button" data-evaluation-view="pending" aria-controls="evaluation-panel-<?= (int)$row['offering_id'] ?>" aria-expanded="false"><strong data-count="not_evaluated"><?= (int)$counts['not_evaluated'] ?></strong><span>Not evaluated <i class="bx bx-chevron-down" aria-hidden="true"></i></span></button></dd></div>
        </dl>
        <div class="evaluation-subject-action"><button type="button" class="btn workload-show-students evaluation-view-report" data-evaluation-view="results" aria-controls="evaluation-panel-<?= (int)$row['offering_id'] ?>" aria-expanded="false"><i class="bx bx-bar-chart-alt-2" aria-hidden="true"></i>View subject results</button></div>
        <section class="evaluation-card-panel" id="evaluation-panel-<?= (int)$row['offering_id'] ?>" aria-label="<?= synk_professor_evaluation_h($row['subject_code'].' · '.$row['section_display'].' evaluation details') ?>" hidden>
          <div class="evaluation-panel-toolbar"><span class="evaluation-panel-status" role="status" aria-live="polite"></span><div><button type="button" class="btn btn-outline-primary evaluation-export" hidden><i class="bx bx-download" aria-hidden="true"></i>Export CSV</button><button type="button" class="btn btn-outline-secondary evaluation-close">Close</button></div></div>
          <div class="evaluation-panel-content" hidden></div>
          <button type="button" class="btn btn-outline-primary evaluation-retry" hidden>Try again</button>
        </section>
      </article>
      <?php endforeach; ?>
    </div>
    <p class="evaluation-counts-note">Class counts use the current roster and submissions for that class’s college and term.</p>
    <div class="card workload-empty" id="evaluation-no-matches" hidden><i class="bx bx-search" aria-hidden="true"></i><h2>No matching subjects</h2><p>Try another subject, section, or room.</p><button type="button" id="evaluation-clear-search" class="btn btn-outline-primary">Clear search</button></div>
    <?php endif; ?>
    <section class="card evaluation-consolidated" id="evaluation-consolidated" aria-labelledby="evaluation-consolidated-title">
      <header class="evaluation-consolidated-heading"><span class="evaluation-consolidated-icon"><i class="bx bx-bar-chart-alt-2" aria-hidden="true"></i></span><div><span class="workload-eyebrow">RELEASED RESULTS</span><h2 id="evaluation-consolidated-title">Consolidated evaluation results</h2><p><?php if ($evaluationSubjects): ?><?= count($evaluationSubjects)===1?'1 assigned class':count($evaluationSubjects).' assigned classes' ?> · <?php endif; ?><?= synk_professor_evaluation_h($evaluationTerm['ay_label'].' / '.$evaluationTerm['semester_label']) ?></p></div></header>
      <p class="evaluation-panel-note">Includes your submitted responses from released colleges, even if a response no longer matches a current class. Each response is counted once.</p>
      <?php if ($evaluationSubjects): ?>
      <dl class="evaluation-consolidated-counts"><div><dt>Unique roster students</dt><dd><?= (int)$consolidated['counts']['total'] ?></dd></div><div><dt>Evaluated</dt><dd><?= (int)$consolidated['counts']['evaluated'] ?></dd></div><div><dt>Not evaluated</dt><dd><?= (int)$consolidated['counts']['not_evaluated'] ?></dd></div></dl>
      <?php if ($multipleColleges): ?><p class="evaluation-panel-note">Students with classes in multiple released colleges are counted as evaluated after submitting in each applicable college.</p><?php endif; ?>
      <?php endif; ?>
      <?php if (!$consolidated['groups']): ?><p class="evaluation-panel-empty" role="status">There are no submitted evaluations in your released colleges for this term yet.</p><?php endif; ?>
      <?php foreach ($consolidated['groups'] as $summary): ?>
      <div class="evaluation-consolidated-score" data-consolidated-instrument="<?= synk_professor_evaluation_h($summary['instrument_version']) ?>">
        <h3><?= synk_professor_evaluation_h($summary['instrument']['label']) ?></h3>
        <dl><div><dt>Evaluations included in score</dt><dd><?= (int)$summary['response_count'] ?></dd></div><div><dt>Overall mean</dt><dd><?= $summary['score'] ? number_format($summary['score']['mean'],2).'<small> / 5.00</small>' : '&mdash;' ?></dd></div><div class="is-score"><dt>Normalized score</dt><dd><?= $summary['score'] ? number_format($summary['score']['percentage'],2).'<small>%</small>' : '&mdash;' ?></dd></div></dl>
        <?php if ($summary['score']): ?><p class="evaluation-panel-note evaluation-formula"><?= number_format($summary['total_points'],0) ?> rating points ÷ <?= number_format($summary['answer_count']) ?> answers = <?= number_format($summary['score']['mean'],2) ?> mean. Normalized score = points ÷ (answers × 5) × 100.</p><?php else: ?><p class="evaluation-panel-note">No eligible evaluations remain for score computation. No zero score is assigned.</p><?php endif; ?>
        <?php if ($summary['submitted_count']>$summary['response_count']): ?><p class="evaluation-panel-note"><?= (int)($summary['submitted_count']-$summary['response_count']) ?> submitted evaluations are excluded from scores under the evaluation eligibility rules.</p><?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php if (count($consolidated['colleges'])>1): ?>
      <div class="table-responsive mt-3">
        <table class="table">
          <caption class="caption-top">Results by college</caption>
          <thead><tr><th scope="col">College</th><th scope="col">Questionnaire</th><th scope="col">Submitted</th><th scope="col">Included in score</th><th scope="col">Normalized score</th></tr></thead>
          <tbody>
            <?php foreach ($consolidated['colleges'] as $collegeResult): ?>
              <?php foreach ($collegeResult['groups'] as $summary): ?>
              <tr><th scope="row"><?= synk_professor_evaluation_h($collegeResult['college_name']) ?></th><td><?= synk_professor_evaluation_h($summary['instrument']['label']) ?></td><td><?= (int)$summary['submitted_count'] ?></td><td><?= (int)$summary['response_count'] ?></td><td><?= $summary['score'] ? number_format($summary['score']['percentage'],2).'%' : '&mdash;' ?></td></tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
      <?php if ($consolidated['groups']): ?>
      <details class="evaluation-consolidated-details"><summary><span>View category scores, statements and comments</span><i class="bx bx-chevron-down" aria-hidden="true"></i></summary><div class="evaluation-consolidated-report"><?php $evaluationDetail=$consolidated; $evaluationView='consolidated'; require __DIR__.'/faculty-evaluation-panel.php'; ?></div></details>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main><?php include '../footer.php'; ?><div class="content-backdrop fade"></div>
</div></div></div><div class="layout-overlay layout-menu-toggle"></div></div>
<script src="../assets/vendor/libs/jquery/jquery.js"></script><script src="../assets/vendor/libs/popper/popper.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script><script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script>
<script src="faculty-evaluations.js?v=<?= filemtime(__DIR__.'/faculty-evaluations.js') ?>"></script>
</body></html>
