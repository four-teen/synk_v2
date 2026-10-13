<?php
session_start();
ob_start();
require_once '../backend/db.php';
require_once '../backend/college_dean_evaluations_helper.php';
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { http_response_code(405); header('Allow: GET'); exit('This evaluation page is for viewing results.'); }
synk_college_dean_require_login($conn);
$deanEvaluationScope = synk_college_dean_dashboard_require_scope($conn, [], true);
$deanEvaluationTerm = synk_dean_view_term($conn);
$deanEvaluationError = '';
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
  <style>
    .dean-evaluation-table { min-width: 630px; }
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
        <?php if ($deanEvaluationError !== ''): ?><div class="alert alert-danger" role="alert"><?php echo synk_dean_evaluation_h($deanEvaluationError); ?></div><?php else: ?>
          <div class="dean-evaluation-summary mb-4">
            <?php foreach (['faculty_count' => 'Faculty', 'submitted_count' => 'Submitted Evaluations', 'student_count' => 'Student Evaluators', 'draft_count' => 'Unsubmitted Drafts'] as $key => $label): ?><div class="card"><div class="card-body"><span class="small text-muted"><?php echo $label; ?></span><h4 class="mt-2 mb-0" data-evaluation-summary="<?php echo $key; ?>"><?php echo number_format($deanEvaluationData['summary'][$key]); ?></h4></div></div><?php endforeach; ?>
          </div>
          <?php if ($deanEvaluationData['summary']['submitted_count'] === 0): ?><p class="text-muted" id="deanEvaluationEmptyNotice">No submitted evaluations are recorded for the selected term and instrument. Results will appear here after students complete their final submission.</p><?php endif; ?>
          <details class="card mb-4" id="deanEvaluationFacultyList"<?php echo $deanEvaluationData['detail'] ? '' : ' open'; ?>><summary class="card-body"><span class="h5 d-block mb-1">Faculty in the Selected Term</span><span class="small text-muted">Expand this list to choose a faculty member. Submitted results include students enrolled with the faculty in your college.</span></summary>
            <div class="table-responsive"><table class="table dean-evaluation-table mb-0"><thead><tr><th>Faculty</th><th>Submitted</th><th>Drafts</th><th>Normalized Score</th><th>Evaluation</th></tr></thead><tbody>
              <?php foreach ($deanEvaluationData['rows'] as $faculty): ?><tr data-evaluation-faculty="<?php echo $faculty['faculty_id']; ?>"><td><?php echo synk_dean_evaluation_h($faculty['faculty_name']); ?></td><td><?php echo $faculty['submitted_count']; ?></td><td><?php echo $faculty['draft_count']; ?></td><td>
                <?php if ($faculty['score']): ?><?php echo number_format($faculty['score']['percentage'],2); ?>%<small class="d-block text-muted"><?php echo number_format($faculty['score']['mean'],2); ?> / 5.00</small><?php elseif ($faculty['instrument_count'] > 1): ?><span class="text-muted">View by instrument</span><?php else: ?><span class="text-muted">No results yet</span><?php endif; ?>
              </td><td><a class="btn btn-sm btn-outline-primary" data-show-evaluation href="<?php echo synk_dean_evaluation_h(synk_dean_evaluation_url($deanEvaluationTerm,$deanEvaluationFilters,$faculty['faculty_id'])); ?>#deanEvaluationDetail">Show Evaluation</a></td></tr><?php endforeach; ?>
              <?php if (!$deanEvaluationData['rows']): ?><tr><td colspan="5" class="text-center text-muted py-4">No faculty match this college, term, and name filter.</td></tr><?php endif; ?>
            </tbody></table></div>
          </details>
          <?php $detail = $deanEvaluationData['detail']; if ($detail): ?>
            <section id="deanEvaluationDetail" aria-labelledby="deanEvaluationDetailTitle">
              <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3"><div><h4 id="deanEvaluationDetailTitle" class="mb-1"><?php echo synk_dean_evaluation_h($detail['faculty']['faculty_name']); ?></h4><p class="text-muted mb-0"><?php echo synk_dean_evaluation_h($deanEvaluationTerm['term_text']); ?></p></div><a class="btn btn-outline-secondary" href="<?php echo synk_dean_evaluation_h(synk_dean_evaluation_url($deanEvaluationTerm,$deanEvaluationFilters)); ?>">Close Details</a></div>
              <?php if (!$detail['groups']): ?><div class="card"><div class="card-body text-muted">This faculty member has no submitted evaluation results for the selected term and instrument.</div></div><?php endif; ?>
              <?php foreach ($detail['groups'] as $group): ?><article class="card mb-4" data-evaluation-instrument="<?php echo synk_dean_evaluation_h($group['instrument_version']); ?>"><div class="card-body p-4">
                <h5><?php echo synk_dean_evaluation_h($group['instrument']['label']); ?></h5>
                <div class="d-flex flex-wrap gap-2 mb-3"><span class="badge bg-label-primary"><?php echo $group['evaluation_count']; ?> submitted evaluation(s)</span><span class="badge bg-label-success"><?php echo number_format($group['score']['percentage'],2); ?>% normalized score</span><span class="badge bg-label-info">Mean <?php echo number_format($group['score']['mean'],2); ?> / 5.00</span></div>
                <p class="small text-muted">Normalized score = total rating points ÷ maximum possible points × 100. Questions have equal weight; only final results are rounded. Instrument versions are reported separately.</p>
                <details class="mb-3"><summary class="text-primary">Rating Scale</summary><ul class="mt-2 mb-0"><?php foreach (synk_student_faculty_evaluation_scale($group['instrument_version']) as $rating => $definition): ?><li class="mb-2"><strong><?php echo $rating; ?> — <?php echo synk_dean_evaluation_h($definition['label']); ?></strong>: <?php echo synk_dean_evaluation_h($definition['description']); ?></li><?php endforeach; ?></ul></details>
                <?php foreach ($group['categories'] as $category): ?><div class="mb-4"><h6 class="mb-2"><?php echo synk_dean_evaluation_h($category['title']); ?></h6><p class="small text-muted mb-2">Mean <?php echo number_format($category['score']['mean'],2); ?> / 5.00 · <?php echo number_format($category['score']['percentage'],2); ?>% of maximum category points</p><div class="table-responsive"><table class="table dean-evaluation-benchmark"><thead><tr><th>Statement</th><th>Mean</th><th>Normalized Score</th><th>Responses</th></tr></thead><tbody>
                  <?php foreach ($category['questions'] as $question): ?><tr><td><?php echo $question['order']; ?>. <?php echo synk_dean_evaluation_h($question['text']); ?></td><td><?php echo number_format($question['score']['mean'],2); ?></td><td><?php echo number_format($question['score']['percentage'],2); ?>%</td><td><?php echo $question['response_count']; ?></td></tr><?php endforeach; ?>
                </tbody></table></div></div><?php endforeach; ?>
                <h6>Submitted Comments</h6>
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
<script src="../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../assets/vendor/libs/popper/popper.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script>
<script src="../assets/js/main.js"></script>
</body>
</html>
