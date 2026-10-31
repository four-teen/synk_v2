<?php
session_start();
ob_start();
require_once '../backend/db.php';
require_once '../backend/college_dean_evaluations_helper.php';
require_once '../backend/college_dean_evaluation_clear_helper.php';
header('Cache-Control: no-store');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','POST'],true)
    || (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST' && !isset($_POST['save_evaluation_control']))) {
    http_response_code(405); header('Allow: GET, POST'); exit('Use the evaluation controls to change access.');
}
synk_college_dean_require_login($conn);
$deanEvaluationScope = synk_college_dean_dashboard_require_scope($conn, [], true);
$deanEvaluationTerm = synk_dean_view_term($conn);
$deanEvaluationClearReady=synk_dean_evaluation_clear_ready($conn) && ($_SESSION['role'] ?? '')==='college_dean';
$deanEvaluationClearNotice='';
$clearNotice=$_SESSION['dean_faculty_evaluation_clear_notice'] ?? null;
if (is_array($clearNotice) && (int)$clearNotice['college_id']===$deanEvaluationScope['college_id']
    && (int)$clearNotice['ay_id']===(int)$deanEvaluationTerm['ay_id'] && (int)$clearNotice['semester']===(int)$deanEvaluationTerm['semester']) {
    $deanEvaluationClearNotice=(string)$clearNotice['message'];
    unset($_SESSION['dean_faculty_evaluation_clear_notice']);
}
$deanEvaluationError = '';
$deanEvaluationControlError = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') {
    try {
        synk_evaluation_save_control($conn,$_POST);
        header('Location: faculty-evaluations.php?' . http_build_query(['dean_ay_id'=>(int)$_POST['ay_id'],'dean_semester'=>(int)$_POST['semester'],'access_saved'=>'1']));
        exit;
    } catch (DomainException $exception) {
        http_response_code(422); $deanEvaluationControlError=$exception->getMessage();
    } catch (Throwable $exception) {
        http_response_code(500); error_log('Evaluation control: '.$exception->getMessage());
        $deanEvaluationControlError='Unable to save evaluation access. Please try again.';
    }
}
$deanEvaluationSettings=synk_evaluation_settings($conn,(int)$deanEvaluationScope['college_id'],(int)$deanEvaluationTerm['ay_id'],(int)$deanEvaluationTerm['semester']);
$deanEvaluationCurrent=synk_evaluation_is_current($deanEvaluationTerm,synk_fetch_current_academic_term($conn));
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token']=bin2hex(random_bytes(32));
$deanEvaluationAudit=[];
if (($_SESSION['role'] ?? '')==='college_dean' && synk_evaluation_table_exists($conn,'tbl_college_evaluation_audit')) {
    $deanEvaluationAudit=synk_evaluation_query($conn,'SELECT a.control_name,a.new_value,a.changed_at,u.username FROM tbl_college_evaluation_audit a LEFT JOIN tbl_useraccount u ON u.user_id=a.user_id WHERE a.college_id=? AND a.ay_id=? AND a.semester=? ORDER BY a.audit_id DESC LIMIT 5','iii',[(int)$deanEvaluationScope['college_id'],(int)$deanEvaluationTerm['ay_id'],(int)$deanEvaluationTerm['semester']]);
}
$deanEvaluationFilters = ['faculty_id' => 0, 'instrument_version' => 'all', 'search' => ''];
$deanEvaluationData = ['rows' => [], 'detail' => null, 'summary' => ['faculty_count' => 0, 'submitted_count' => 0, 'draft_count' => 0, 'student_count' => 0]];
try {
    $deanEvaluationFilters = synk_dean_evaluations_filters($_GET);
    if ((int)$deanEvaluationTerm['ay_id'] > 0 && (int)$deanEvaluationTerm['semester'] > 0) {
        $deanEvaluationData = synk_dean_evaluations_data($conn, $deanEvaluationScope, $deanEvaluationTerm, $deanEvaluationFilters);
    }
} catch (OutOfBoundsException $exception) {
    http_response_code(404); $deanEvaluationError = $exception->getMessage();
} catch (DomainException $exception) {
    http_response_code(422); $deanEvaluationError = $exception->getMessage();
} catch (Throwable $exception) {
    http_response_code(500); error_log('College Dean evaluations: ' . $exception->getMessage());
    $deanEvaluationError = 'Unable to load faculty evaluations. Please try again.';
}
$deanEvaluationYears = synk_dean_academic_years($conn);
$collegeDeanPortalContext = synk_college_dean_portal_context($conn);
$collegeDeanPortalDisplayName = (string)($collegeDeanPortalContext['account_name'] ?? 'College Dean');
$collegeDeanPortalDisplayEmail = (string)($collegeDeanPortalContext['email'] ?? '');
function synk_dean_evaluation_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function synk_dean_evaluation_url(array $term, array $filters, int $facultyId = 0): string {
    $params = ['dean_ay_id' => $term['ay_id'], 'dean_semester' => $term['semester'],
        'instrument_version' => $filters['instrument_version'], 'search' => $filters['search']];
    if ($facultyId > 0) $params['faculty_id'] = $facultyId;
    return 'faculty-evaluations.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Faculty Evaluation | College Dean | Synk</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
  <link rel="stylesheet" href="../assets/vendor/css/core.css" />
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
  <link rel="stylesheet" href="../assets/css/demo.css" />
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
  <link rel="stylesheet" href="custom_css.css?v=<?php echo (int)filemtime(__DIR__ . '/custom_css.css'); ?>" />
  <link rel="stylesheet" href="faculty-evaluations.css?v=<?= (int)filemtime(__DIR__.'/faculty-evaluations.css') ?>" />
  <style>
    .dean-evaluation-table td, .dean-evaluation-table th { white-space: normal; }
    .dean-evaluation-benchmark { min-width: 540px; }
    .dean-evaluation-benchmark td:first-child { width: 62%; }
    .dean-evaluation-summary { display: grid; grid-template-columns: repeat(4, minmax(0,1fr)); gap: 1rem; }
    #deanEvaluationDetail { scroll-margin-top: 6rem; }
    @media (max-width: 767px) { .dean-evaluation-summary { grid-template-columns: repeat(2,minmax(0,1fr)); } }
  </style>
  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>
</head>
<body>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container">
  <?php include 'sidebar.php'; ?>
  <div class="layout-page">
    <?php include 'navbar.php'; ?>
    <div class="content-wrapper">
      <main class="container-xxl flex-grow-1 container-p-y" id="deanFacultyEvaluations">
        <div class="card mb-4"><div class="card-body p-4">
          <h4 class="mb-2">Faculty Evaluation</h4>
          <p class="text-muted mb-3">Review student evaluations for <?php echo synk_dean_evaluation_h($deanEvaluationScope['college_name']); ?>. Choose an academic year and semester, then show a faculty member's results.</p>
          <form method="get" action="faculty-evaluations.php" id="deanEvaluationFilters" class="row g-3 align-items-end">
            <div class="col-6 col-lg-3"><label for="deanEvaluationYear" class="form-label">Academic Year</label><select id="deanEvaluationYear" name="dean_ay_id" class="form-select" required>
              <?php foreach ($deanEvaluationYears as $year): ?><option value="<?php echo (int)$year['ay_id']; ?>"<?php echo $year['ay_id'] === (int)$deanEvaluationTerm['ay_id'] ? ' selected' : ''; ?>><?php echo synk_dean_evaluation_h($year['ay']); ?></option><?php endforeach; ?>
            </select></div>
            <div class="col-6 col-lg-3"><label for="deanEvaluationSemester" class="form-label">Semester</label><select id="deanEvaluationSemester" name="dean_semester" class="form-select" required>
              <?php foreach ([1,2,3] as $semester): ?><option value="<?php echo $semester; ?>"<?php echo $semester === (int)$deanEvaluationTerm['semester'] ? ' selected' : ''; ?>><?php echo synk_dean_evaluation_h(synk_semester_label($semester)); ?></option><?php endforeach; ?>
            </select></div>
            <div class="col-12 col-lg-3"><label for="deanEvaluationInstrument" class="form-label">Instrument</label><select id="deanEvaluationInstrument" name="instrument_version" class="form-select">
              <?php foreach (['all' => 'All instruments', synk_student_faculty_evaluation_current_version() => 'Revised SET · 15 questions', 'legacy-20' => 'Previous instrument · 20 questions'] as $version => $label): ?><option value="<?php echo synk_dean_evaluation_h($version); ?>"<?php echo $version === $deanEvaluationFilters['instrument_version'] ? ' selected' : ''; ?>><?php echo synk_dean_evaluation_h($label); ?></option><?php endforeach; ?>
            </select></div>
            <div class="col-12 col-lg-3"><label for="deanEvaluationSearch" class="form-label">Faculty Name</label><input type="search" id="deanEvaluationSearch" name="search" class="form-control" maxlength="150" placeholder="Search faculty" value="<?php echo synk_dean_evaluation_h($deanEvaluationFilters['search']); ?>" /></div>
            <div class="col-12 d-flex flex-wrap gap-2"><button type="submit" class="btn btn-primary">Load Evaluations</button><a href="faculty-evaluations.php?dean_term=current" class="btn btn-outline-secondary">Current Term</a></div>
          </form>
          <p class="small text-muted mt-3 mb-0">Selected term: <strong><?php echo synk_dean_evaluation_h($deanEvaluationTerm['term_text'] ?? 'No academic term configured'); ?></strong>. This selection follows the Dean portal's viewing term.</p>
        </div></div>
        <?php include __DIR__ . '/evaluation-access-controls.php'; ?>
        <?php if (($_SESSION['role'] ?? '')==='college_dean'): ?><div class="card mb-4"><div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3"><div><h5 class="mb-1">Subject exclusions from faculty scores</h5><p class="text-muted mb-0">Manage selected subjects while students continue evaluating all linked teachers normally.</p></div><a class="btn btn-outline-primary" href="evaluation-subject-exclusions.php?<?= synk_dean_evaluation_h(http_build_query(['dean_ay_id'=>$deanEvaluationTerm['ay_id'],'dean_semester'=>$deanEvaluationTerm['semester']])) ?>">Manage Subject Exclusions</a></div></div><?php endif; ?>
        <?php if ($deanEvaluationClearNotice!==''): ?><div class="alert alert-success" role="status"><?= synk_dean_evaluation_h($deanEvaluationClearNotice) ?></div><?php endif; ?>
        <?php if ($deanEvaluationError !== ''): ?><div class="alert alert-danger" role="alert"><?php echo synk_dean_evaluation_h($deanEvaluationError); ?></div><?php else: ?>
          <div class="dean-evaluation-summary mb-4">
            <?php foreach (['faculty_count' => 'Faculty', 'submitted_count' => 'Submitted Evaluations', 'student_count' => 'Student Evaluators', 'draft_count' => 'Unsubmitted Drafts'] as $key => $label): ?><div class="card"><div class="card-body"><span class="small text-muted"><?php echo $label; ?></span><h4 class="mt-2 mb-0" data-evaluation-summary="<?php echo $key; ?>"><?php echo number_format($deanEvaluationData['summary'][$key]); ?></h4></div></div><?php endforeach; ?>
          </div>
          <?php if ($deanEvaluationData['summary']['submitted_count'] === 0): ?><p class="text-muted" id="deanEvaluationEmptyNotice">No submitted evaluations are recorded for the selected term and instrument. Results will appear here after students complete their final submission.</p><?php endif; ?>
          <details class="card mb-4" id="deanEvaluationFacultyList"<?php echo $deanEvaluationData['detail'] ? '' : ' open'; ?>><summary class="card-body"><span class="h5 d-block mb-1">Faculty in the Selected Term</span><span class="small text-muted">Choose a faculty member to show results or clear that faculty's evaluations in the selected term and instrument.</span></summary>
            <p class="dean-evaluation-count-help">Submission counts show <span class="dean-evaluation-submitted">submitted</span> / <span class="dean-evaluation-pending">not submitted</span>, with each student counted once per faculty. Subjects count distinct subjects; repeated sections count once. Counts follow the selected college, term and instrument.</p>
            <?php if (!$deanEvaluationClearReady && ($_SESSION['role'] ?? '')==='college_dean'): ?><p class="dean-evaluation-count-help">Clearing requires the faculty evaluation clearing history update.</p><?php endif; ?>
            <div class="table-responsive dean-evaluation-list-wrap"><table class="table dean-evaluation-table mb-0"><thead><tr><th scope="col">Faculty</th><th scope="col">Subjects</th><th scope="col">Submitted Count</th><th scope="col">Drafts</th><th scope="col">Normalized Score</th><th scope="col">Action</th></tr></thead><tbody>
              <?php foreach ($deanEvaluationData['rows'] as $faculty): ?><tr data-evaluation-faculty="<?= (int)$faculty['faculty_id'] ?>"><td data-label="Faculty"><?php echo synk_dean_evaluation_h($faculty['faculty_name']); ?></td><td data-label="Subjects"><span class="dean-evaluation-subject-count"><?= (int)$faculty['subject_count'] ?></span></td><td data-label="Submitted Count"><span class="dean-evaluation-submission-count" aria-label="<?= (int)$faculty['submitted_student_count'] ?> submitted, <?= (int)$faculty['not_submitted_count'] ?> not submitted"><span class="dean-evaluation-submitted"><?= (int)$faculty['submitted_student_count'] ?></span><span aria-hidden="true">/</span><span class="dean-evaluation-pending"><?= (int)$faculty['not_submitted_count'] ?></span></span></td><td data-label="Drafts"><?php echo $faculty['draft_count']; ?></td><td data-label="Normalized Score">
                <?php if ($faculty['score']): ?><?php echo number_format($faculty['score']['percentage'],2); ?>%<small class="d-block text-muted"><?php echo number_format($faculty['score']['mean'],2); ?> / 5.00</small><?php elseif ($faculty['instrument_count'] > 1): ?><span class="text-muted">View by instrument</span><?php elseif ($faculty['excluded_count']): ?><span class="text-muted">No eligible evaluations</span><?php else: ?><span class="text-muted">No results yet</span><?php endif; ?>
                <small class="d-block text-muted"><?= (int)$faculty['included_count'] ?> included · <?= (int)$faculty['excluded_count'] ?> excluded<?php if ($faculty['review_count']): ?> · <?= (int)$faculty['review_count'] ?> counted, needs review<?php endif; ?></small>
              </td><td data-label="Action"><div class="dean-evaluation-actions"><a class="btn btn-sm btn-outline-primary" data-show-evaluation href="<?php echo synk_dean_evaluation_h(synk_dean_evaluation_url($deanEvaluationTerm,$deanEvaluationFilters,$faculty['faculty_id'])); ?>#deanEvaluationDetail">Show Evaluation</a><?php if (($_SESSION['role'] ?? '')==='college_dean'): ?><button type="button" class="btn btn-sm btn-outline-danger" data-clear-evaluation data-bs-toggle="modal" data-bs-target="#deanClearEvaluationModal" data-faculty-id="<?= (int)$faculty['faculty_id'] ?>" data-faculty-name="<?= synk_dean_evaluation_h($faculty['faculty_name']) ?>"<?= (!$deanEvaluationClearReady || (!$faculty['submitted_count'] && !$faculty['draft_count']))?' disabled':'' ?>><i class="bx bx-eraser" aria-hidden="true"></i>Clear Evaluation</button><?php endif; ?></div></td></tr><?php endforeach; ?>
              <?php if (!$deanEvaluationData['rows']): ?><tr><td colspan="6" class="text-center text-muted py-4">No faculty match this college, term, and name filter.</td></tr><?php endif; ?>
            </tbody></table></div>
          </details>
          <?php $detail = $deanEvaluationData['detail']; if ($detail): ?>
            <section id="deanEvaluationDetail" aria-labelledby="deanEvaluationDetailTitle">
              <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3"><div><h4 id="deanEvaluationDetailTitle" class="mb-1"><?php echo synk_dean_evaluation_h($detail['faculty']['faculty_name']); ?></h4><p class="text-muted mb-0"><?php echo synk_dean_evaluation_h($deanEvaluationTerm['term_text']); ?></p></div><a class="btn btn-outline-secondary" href="<?php echo synk_dean_evaluation_h(synk_dean_evaluation_url($deanEvaluationTerm,$deanEvaluationFilters)); ?>">Close Details</a></div>
              <?php if ($detail['excluded_count'] || $detail['review_count']): ?><div class="alert alert-info" role="status"><?= (int)$detail['excluded_count'] ?> submitted evaluation(s) excluded from scores. <?= (int)$detail['review_count'] ?> combined or unverified response(s) remain counted for review. Original submissions are preserved.</div><?php endif; ?>
              <?php if (!$detail['groups']): ?><div class="card"><div class="card-body text-muted"><?= $detail['excluded_count']?'No eligible evaluations remain for the selected term and instrument.':'This faculty member has no submitted evaluation results for the selected term and instrument.' ?></div></div><?php endif; ?>
              <?php foreach ($detail['groups'] as $group): ?><article class="card mb-4" data-evaluation-instrument="<?php echo synk_dean_evaluation_h($group['instrument_version']); ?>"><div class="card-body p-4">
                <h5><?php echo synk_dean_evaluation_h($group['instrument']['label']); ?></h5>
                <div class="d-flex flex-wrap gap-2 mb-3"><span class="badge bg-label-primary"><?php echo $group['evaluation_count']; ?> submitted evaluation(s) included in score</span><?php if ($group['score']): ?><span class="badge bg-label-success"><?php echo number_format($group['score']['percentage'],2); ?>% normalized score</span><span class="badge bg-label-info">Mean <?php echo number_format($group['score']['mean'],2); ?> / 5.00</span><?php else: ?><span class="text-muted">No eligible evaluations</span><?php endif; ?></div>
                <p class="small text-muted">Normalized score = total rating points ÷ maximum possible points × 100. Questions have equal weight; only final results are rounded. Instrument versions are reported separately.</p>
                <details class="mb-3"><summary class="text-primary">Rating Scale</summary><ul class="mt-2 mb-0"><?php foreach (synk_student_faculty_evaluation_scale($group['instrument_version']) as $rating => $definition): ?><li class="mb-2"><strong><?php echo $rating; ?> — <?php echo synk_dean_evaluation_h($definition['label']); ?></strong>: <?php echo synk_dean_evaluation_h($definition['description']); ?></li><?php endforeach; ?></ul></details>
                <?php foreach ($group['categories'] as $category): ?><div class="mb-4"><h6 class="mb-2"><?php echo synk_dean_evaluation_h($category['title']); ?></h6><p class="small text-muted mb-2">Mean <?php echo number_format($category['score']['mean'],2); ?> / 5.00 · <?php echo number_format($category['score']['percentage'],2); ?>% of maximum category points</p><div class="table-responsive"><table class="table dean-evaluation-benchmark"><thead><tr><th>Statement</th><th>Mean</th><th>Normalized Score</th><th>Responses</th></tr></thead><tbody>
                  <?php foreach ($category['questions'] as $question): ?><tr><td><?php echo $question['order']; ?>. <?php echo synk_dean_evaluation_h($question['text']); ?></td><td><?php echo number_format($question['score']['mean'],2); ?></td><td><?php echo number_format($question['score']['percentage'],2); ?>%</td><td><?php echo $question['response_count']; ?></td></tr><?php endforeach; ?>
                </tbody></table></div></div><?php endforeach; ?>
                <h6>Submitted Comments</h6>
                <?php if ($detail['excluded_count']): ?><p class="small text-muted">Submitted comments are retained, including comments from responses excluded from score computation.</p><?php endif; ?>
                <?php foreach ($group['comments'] as $comment): ?><p class="border-top pt-3"><?php echo nl2br(synk_dean_evaluation_h($comment)); ?></p><?php endforeach; ?>
                <?php if (!$group['comments']): ?><p class="text-muted mb-0">No comments were submitted.</p><?php endif; ?>
              </div></article><?php endforeach; ?>
            </section>
          <?php endif; ?>
        <?php endif; ?>
      </main>
      <?php include '../footer.php'; ?>
      <div class="content-backdrop fade"></div>
    </div>
  </div>
</div><div class="layout-overlay layout-menu-toggle"></div></div>
<?php if (($_SESSION['role'] ?? '')==='college_dean'): ?>
<div class="modal fade dean-clear-evaluation-modal" id="deanClearEvaluationModal" tabindex="-1" aria-labelledby="deanClearEvaluationTitle" aria-describedby="deanClearEvaluationHelp" data-endpoint="evaluation-clear.php" data-csrf="<?= synk_dean_evaluation_h($_SESSION['csrf_token']) ?>" data-ay-id="<?= (int)$deanEvaluationTerm['ay_id'] ?>" data-semester="<?= (int)$deanEvaluationTerm['semester'] ?>" data-instrument="<?= synk_dean_evaluation_h($deanEvaluationFilters['instrument_version']) ?>">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h2 class="modal-title fs-5" id="deanClearEvaluationTitle">Clear Faculty Evaluation</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close clearing review"></button></div>
    <div class="modal-body"><p id="deanClearEvaluationHelp">This clears submitted evaluations and saved drafts for <strong id="deanClearEvaluationFaculty"></strong> in the scope below.</p><div id="deanClearEvaluationStatus" role="status" aria-live="polite"></div>
      <div id="deanClearEvaluationPreview" hidden><dl class="dean-clear-evaluation-scope"><div><dt>College</dt><dd id="deanClearEvaluationCollege"></dd></div><div><dt>Term</dt><dd id="deanClearEvaluationTerm"></dd></div><div><dt>Instrument</dt><dd id="deanClearEvaluationInstrument"></dd></div></dl><div class="dean-clear-evaluation-counts"><div><strong id="deanClearEvaluationSubmitted"></strong><span>Submitted evaluations</span></div><div><strong id="deanClearEvaluationDrafts"></strong><span>Saved drafts</span></div></div><p class="alert alert-warning mb-0">Ratings, comments and submission records shown above will be removed from active results. A copy is retained in clearing history. Other faculty are unaffected.</p></div>
      <button type="button" class="btn btn-outline-primary mt-3" id="deanClearEvaluationRetry" hidden>Review latest records</button>
    </div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-danger" id="deanClearEvaluationConfirm" disabled>Clear Evaluation</button></div>
  </div></div>
</div>
<?php endif; ?>
<script src="../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../assets/vendor/libs/popper/popper.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script>
<script src="../assets/js/main.js"></script>
<script src="faculty-evaluations.js?v=<?= (int)filemtime(__DIR__.'/faculty-evaluations.js') ?>"></script>
</body>
</html>
