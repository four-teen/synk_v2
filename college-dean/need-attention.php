<?php
session_start();
ob_start();
require_once '../backend/db.php';
require_once '../backend/college_dean_dashboard_helper.php';
require_once '../backend/college_dean_term_helper.php';
require_once '../backend/program_chair_attention_helper.php';
synk_college_dean_require_login($conn);
if (($_SESSION['role'] ?? '') !== 'program_chair') { http_response_code(404); exit; }
$attentionScope = synk_college_dean_dashboard_require_scope($conn, [], true);
$attentionTerm = synk_dean_view_term($conn);
$attentionProgramId = 0;
$attentionYearLevel = 0;
$attentionYearLabels = [1=>'First Year', 2=>'Second Year', 3=>'Third Year', 4=>'Fourth Year', 5=>'Fifth Year', 6=>'Sixth Year'];
$attentionPrograms = [];
$attentionProgram = null;
$attentionReport = null;
$attentionMessage = '';
$attentionError = false;
try {
    $attentionPrograms = synk_chair_attention_query($conn,
        'SELECT program_id,program_code,program_name,major FROM tbl_program WHERE college_id=? ORDER BY program_code,program_name,major',
        'i', [$attentionScope['college_id']]);
    $attentionProgramId = synk_chair_attention_program_id($_GET['program_id'] ?? null);
    $attentionYearLevel = synk_chair_attention_year_level($_GET['year_level'] ?? null);
    foreach ($attentionPrograms as $program) {
        if ((int)$program['program_id'] === $attentionProgramId) { $attentionProgram = $program; break; }
    }
    if ($attentionProgramId && !$attentionProgram) throw new DomainException('Choose a program assigned to your college.');
    if ($attentionProgram) {
        if (empty($attentionTerm['ay_id']) || empty($attentionTerm['semester'])) {
            throw new DomainException('Set an academic year and semester before checking students.');
        }
        $attentionReport = synk_chair_attention_load($conn, $attentionScope, $attentionTerm, $attentionProgramId, $attentionYearLevel);
    }
} catch (DomainException $exception) {
    $attentionMessage = $exception->getMessage();
    $attentionError = true;
    http_response_code(400);
} catch (Throwable $exception) {
    error_log('Program Chair Need Attention: ' . $exception->getMessage());
    $attentionMessage = 'Unable to complete the student load review. Please reload the page and try again.';
    $attentionError = true;
    http_response_code(500);
}
$collegeDeanPortalContext = synk_college_dean_portal_context($conn);
$collegeDeanPortalDisplayName = (string)($collegeDeanPortalContext['account_name'] ?? 'Program Chair');
$collegeDeanPortalDisplayEmail = (string)($collegeDeanPortalContext['email'] ?? '');
$escapeAttention = static function ($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
};
$attentionTermLabel = (string)($attentionTerm['term_text'] ?? 'Not set');
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Need Attention | Program Chair | Synk</title>
  <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
  <link rel="stylesheet" href="../assets/vendor/css/core.css" />
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
  <link rel="stylesheet" href="../assets/css/demo.css" />
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
  <link rel="stylesheet" href="custom_css.css?v=<?= (int)filemtime(__DIR__ . '/custom_css.css') ?>" />
  <style>
    .attention-card { border: 1px solid #e7e7ee; box-shadow: 0 3px 12px rgba(67, 89, 113, .04); }
    .attention-table th { white-space: nowrap; }
    .attention-table td { vertical-align: top; }
    .attention-student { min-width: 210px; }
    .attention-curriculum { max-width: 300px; font-size: .8rem; }
    .attention-subjects { min-width: 220px; }
    .attention-subjects summary { cursor: pointer; color: #696cff; white-space: nowrap; }
    .attention-subjects ul { padding-left: 1.2rem; margin: .75rem 0 0; }
    .attention-subjects li + li { margin-top: .6rem; }
  </style>
  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>
</head>
<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">
    <?php include 'sidebar.php'; ?>
    <div class="layout-page">
      <?php include 'navbar.php'; ?>
      <div class="content-wrapper">
        <main class="container-xxl flex-grow-1 container-p-y">
          <section class="card attention-card mb-4" aria-labelledby="attentionTitle">
            <div class="card-body p-4">
              <p class="text-uppercase text-muted small mb-1">Academic Management</p>
              <h4 id="attentionTitle" class="mb-2">Need Attention</h4>
              <p class="text-muted mb-3">Find students whose active subject units exceed their curriculum load for their year level and semester. Select a program to begin.</p>
              <div class="small text-muted mb-3">Viewing term: <strong><?= $escapeAttention($attentionTermLabel) ?></strong>. Use the term settings above to change the academic year or semester.</div>
              <form method="get" action="need-attention.php" class="row g-3 align-items-end">
                <input type="hidden" name="dean_ay_id" value="<?= (int)$attentionTerm['ay_id'] ?>" />
                <input type="hidden" name="dean_semester" value="<?= (int)$attentionTerm['semester'] ?>" />
                <div class="col-12 col-lg-6">
                  <label class="form-label" for="attentionProgram">Program</label>
                  <select class="form-select" id="attentionProgram" name="program_id" required<?= !$attentionPrograms ? ' disabled' : '' ?>>
                    <option value="">Select a program</option>
                    <?php foreach ($attentionPrograms as $program): ?>
                      <option value="<?= (int)$program['program_id'] ?>"<?= (int)$program['program_id'] === $attentionProgramId ? ' selected' : '' ?>><?= $escapeAttention(synk_program_chair_program_display_name($program)) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-12 col-md-6 col-lg-3">
                  <label class="form-label" for="attentionYear">Year level</label>
                  <select class="form-select" id="attentionYear" name="year_level">
                    <option value="">All year levels</option>
                    <?php foreach ($attentionYearLabels as $year=>$yearLabel): ?>
                      <option value="<?= (int)$year ?>"<?= $attentionYearLevel === $year ? ' selected' : '' ?>><?= $escapeAttention($yearLabel) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-12 col-md-auto">
                  <button class="btn btn-primary" type="submit"<?= !$attentionPrograms ? ' disabled' : '' ?>><i class="bx bx-search me-1" aria-hidden="true"></i>Check students</button>
                </div>
              </form>
              <?php if (!$attentionPrograms && !$attentionError): ?>
                <div class="alert alert-info mt-3 mb-0">No programs are available in your assigned college.</div>
              <?php elseif (!$attentionProgram && !$attentionError): ?>
                <p class="small text-muted mt-3 mb-0">Only students with a confirmed unit overload will appear in the results, grouped by year level.</p>
              <?php endif; ?>
              <?php if ($attentionMessage !== ''): ?>
                <div class="alert alert-danger mt-3 mb-0" role="alert"><?= $escapeAttention($attentionMessage) ?></div>
              <?php endif; ?>
            </div>
          </section>
          <?php if ($attentionReport !== null): ?>
            <section class="card attention-card" aria-labelledby="attentionResultsTitle">
              <div class="card-body p-4">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                  <div>
                    <h5 id="attentionResultsTitle" class="mb-1"><?= $escapeAttention(synk_program_chair_program_display_name($attentionProgram)) ?></h5>
                    <p class="small text-muted mb-0"><?= (int)$attentionReport['students'] ?> students with active subject records in this term<?= $attentionYearLevel ? ' / ' . $escapeAttention($attentionYearLabels[$attentionYearLevel]) : '' ?>.</p>
                  </div>
                  <span class="badge <?= $attentionReport['flagged'] ? 'bg-label-danger' : 'bg-label-secondary' ?>"><?= (int)$attentionReport['flagged'] ?> student<?= $attentionReport['flagged'] === 1 ? '' : 's' ?> flagged</span>
                </div>
                <p class="small text-muted">The limit follows the assigned curriculum for each student's year level. Totals include subjects taken from other year levels or programs within your college. Repeated records of the same subject count once.</p>
                <?php if ($attentionReport['incomplete']): ?>
                  <div class="alert alert-warning" role="status"><?= (int)$attentionReport['incomplete'] ?> student<?= $attentionReport['incomplete'] === 1 ? '' : 's' ?> could not be fully checked because the year level, assigned curriculum, or subject units need review. A confirmed overload still appears when the known units already exceed the limit.</div>
                <?php endif; ?>
                <?php if (!$attentionReport['students']): ?>
                  <div class="alert alert-info mb-0">No active student subject records were found for this program and academic term.</div>
                <?php elseif (!$attentionReport['flagged']): ?>
                  <div class="alert <?= $attentionReport['incomplete'] ? 'alert-info' : 'alert-success' ?> mb-0"><?= $attentionReport['incomplete'] ? 'No confirmed unit overloads were found among the records that could be checked.' : 'No students are above their curriculum unit limit for this term.' ?></div>
                <?php endif; ?>
                <?php foreach ($attentionReport['groups'] as $year=>$students): ?>
                  <h6 class="mt-4 mb-3">Year <?= (int)$year ?> <span class="badge bg-label-danger ms-1"><?= count($students) ?></span></h6>
                  <div class="table-responsive">
                    <table class="table table-hover attention-table">
                      <caption class="visually-hidden">Students above the curriculum unit limit in Year <?= (int)$year ?></caption>
                      <thead><tr><th scope="col">Student</th><th scope="col" class="text-end">Enrolled units</th><th scope="col" class="text-end">Curriculum limit</th><th scope="col" class="text-end">Over by</th><th scope="col">Subject details</th></tr></thead>
                      <tbody>
                        <?php foreach ($students as $student): ?>
                          <tr>
                            <td class="attention-student"><strong><?= $escapeAttention($student['student_name']) ?></strong><div class="small text-muted"><?= $escapeAttention($student['student_number']) ?></div><div class="small text-muted mt-1"><?= $escapeAttention(implode(', ', $student['sections'])) ?></div></td>
                            <td class="text-end fw-semibold"><?= $student['pending_subjects'] ? 'At least ' : '' ?><?= $escapeAttention(synk_chair_attention_number($student['units'])) ?><?php if ($student['pending_subjects']): ?><div class="small text-warning"><?= (int)$student['pending_subjects'] ?> subject units pending</div><?php endif; ?></td>
                            <td class="text-end"><?= $escapeAttention(synk_chair_attention_number($student['unit_limit'])) ?><div class="attention-curriculum text-muted mt-1"><?= $escapeAttention($student['curriculum_label']) ?></div></td>
                            <td class="text-end text-danger fw-semibold"><?= $student['pending_subjects'] ? 'At least ' : '' ?>+<?= $escapeAttention(synk_chair_attention_number($student['excess_units'])) ?></td>
                            <td><details class="attention-subjects"><summary><?= count($student['subjects']) ?> subjects <span class="visually-hidden">for <?= $escapeAttention($student['student_name']) ?></span></summary><ul>
                              <?php foreach ($student['subjects'] as $subject): ?>
                                <li><strong><?= $escapeAttention($subject['code'] ?: 'Subject') ?></strong> - <?= $escapeAttention($subject['title']) ?><div class="small text-muted"><?= $subject['units'] === null ? 'Units pending' : $escapeAttention(synk_chair_attention_number($subject['units'])) . ' units' ?><?= $subject['sections'] ? ' / ' . $escapeAttention(implode(', ', $subject['sections'])) : '' ?></div></li>
                              <?php endforeach; ?>
                            </ul></details></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endif; ?>
        </main>
        <?php include '../footer.php'; ?>
        <div class="content-backdrop fade"></div>
      </div>
    </div>
  </div>
  <div class="layout-overlay layout-menu-toggle"></div>
</div>
<script src="../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script>
<script src="../assets/js/main.js"></script>
</body>
</html>
