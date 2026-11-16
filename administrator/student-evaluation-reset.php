<?php
session_start(); ob_start();
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/student_evaluation_reset_helper.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
try { synk_student_evaluation_reset_actor($conn); }
catch (DomainException $error) { http_response_code(403); exit('Administrator access is required.'); }
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit; }
if (empty($_SESSION['student_evaluation_reset_csrf'])) $_SESSION['student_evaluation_reset_csrf'] = bin2hex(random_bytes(32));
$error = ''; $preview = null;
$notice = $_SESSION['student_evaluation_reset_notice'] ?? ''; unset($_SESSION['student_evaluation_reset_notice']);
$current = synk_fetch_current_academic_term($conn);
$input = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET;
$filters = ['campus_id'=>0,'college_id'=>0,'ay_id'=>(int)$current['ay_id'],'semester'=>(int)$current['semester']];
foreach ($filters as $key=>$default) {
    if (array_key_exists($key, $input)) {
        try { $filters[$key] = synk_student_evaluation_reset_id($input[$key]); }
        catch (DomainException $invalid) { $filters[$key] = 0; $error = $invalid->getMessage(); }
    }
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        if (($_POST['action'] ?? '') !== 'confirm') throw new DomainException('Choose a valid reset action.', 422);
        $result = synk_student_evaluation_reset_confirm($conn, $_POST);
        $_SESSION['student_evaluation_reset_notice'] = 'Deleted '.$result['counts']['submitted'].' submitted evaluation record(s) and '.$result['counts']['drafts'].' saved draft(s) for '.$result['scope']['college_name'].' — '.$result['scope']['term_text'].'. Student records and supervisor evaluations were preserved.';
        header('Location: student-evaluation-reset.php?'.http_build_query(array_intersect_key($result['scope'], $filters)), true, 303); exit;
    } catch (Throwable $exception) {
        $known = $exception instanceof DomainException;
        http_response_code($known ? $exception->getCode() : 503);
        $error = $known ? $exception->getMessage() : 'The reset could not be completed. Nothing was deleted. Filter again to review the latest counts.';
        if (!$known) error_log('Administrator student evaluation reset: '.$exception->getMessage());
    }
}
$campuses = synk_evaluation_query($conn, "SELECT campus_id,campus_name,status FROM tbl_campus ORDER BY status='active' DESC,campus_name,campus_id");
$colleges = synk_evaluation_query($conn, "SELECT college_id,campus_id,college_code,college_name,status FROM tbl_college ORDER BY status='active' DESC,college_name,college_id");
$years = synk_evaluation_query($conn, 'SELECT ay_id,ay FROM tbl_academic_years ORDER BY ay DESC,ay_id DESC');
$ready = synk_student_evaluation_reset_ready($conn);
if ($error === '' && $filters['campus_id'] && $filters['college_id']) {
    try { $preview = synk_student_evaluation_reset_preview($conn, $filters); }
    catch (Throwable $exception) {
        $error = $exception instanceof DomainException ? $exception->getMessage() : 'Evaluation counts could not be loaded. Try filtering again.';
        if (!$exception instanceof DomainException) error_log('Administrator evaluation reset preview: '.$exception->getMessage());
    }
}
$history = $ready ? synk_evaluation_query($conn, 'SELECT reset_at,student_count,submitted_count,draft_count,actor_name FROM tbl_student_evaluation_reset_history WHERE campus_id=? AND college_id=? AND ay_id=? AND semester=? ORDER BY reset_id DESC LIMIT 10', 'iiii', array_values($filters)) : [];
$h = static function($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html><html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Student Evaluation Reset | Synk</title>
<link rel="icon" href="../assets/img/favicon/synk-icon.png" type="image/png"><link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css"><link rel="stylesheet" href="../assets/vendor/css/core.css"><link rel="stylesheet" href="../assets/vendor/css/theme-default.css"><link rel="stylesheet" href="../assets/css/demo.css"><link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
<link rel="stylesheet" href="student-evaluation-reset.css?v=<?= (int)filemtime(__DIR__.'/student-evaluation-reset.css') ?>">
<script src="../assets/vendor/js/helpers.js"></script><script src="../assets/js/config.js"></script></head><body>
<div class="layout-wrapper layout-content-navbar"><div class="layout-container"><?php require __DIR__.'/sidebar.php'; ?><div class="layout-page"><?php require __DIR__.'/navbar.php'; ?><main class="content-wrapper"><div class="container-xxl flex-grow-1 container-p-y evaluation-reset-page">
  <header class="evaluation-reset-heading"><div><p class="small text-muted text-uppercase mb-1">Administrator</p><h1 class="h4 mb-2">Student Evaluation Reset</h1><p class="text-muted mb-0">Review student evaluations for one college and academic term before deleting them.</p></div></header>
  <?php if ($notice !== ''): ?><div class="alert alert-success" role="status"><?= $h($notice) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-danger" role="alert"><?= $h($error) ?></div><?php endif; ?>
  <?php if (!$ready): ?><div class="alert alert-info" role="status">Reset storage needs preparation. Run the student evaluation reset preparation before using deletion.</div><?php endif; ?>
  <section class="card mb-4" aria-labelledby="resetFilterTitle"><div class="card-body">
    <h2 class="h5" id="resetFilterTitle">Select evaluation scope</h2>
    <form method="get" id="evaluationResetFilters" class="evaluation-reset-filters">
      <div><label class="form-label" for="resetCampus">Campus</label><select class="form-select" id="resetCampus" name="campus_id" required><option value="">Select campus</option><?php foreach ($campuses as $campus): ?><option value="<?= (int)$campus['campus_id'] ?>"<?= (int)$campus['campus_id'] === $filters['campus_id'] ? ' selected' : '' ?>><?= $h($campus['campus_name']) ?><?= $campus['status'] !== 'active' ? ' (inactive)' : '' ?></option><?php endforeach; ?></select></div>
      <div><label class="form-label" for="resetCollege">College</label><select class="form-select" id="resetCollege" name="college_id" required><option value="">Select college</option><?php foreach ($colleges as $college): ?><option value="<?= (int)$college['college_id'] ?>" data-campus="<?= (int)$college['campus_id'] ?>"<?= (int)$college['college_id'] === $filters['college_id'] ? ' selected' : '' ?>><?= $h($college['college_code'].' — '.$college['college_name']) ?><?= $college['status'] !== 'active' ? ' (inactive)' : '' ?></option><?php endforeach; ?></select></div>
      <div><label class="form-label" for="resetYear">Academic year</label><select class="form-select" id="resetYear" name="ay_id" required><option value="">Select academic year</option><?php foreach ($years as $year): ?><option value="<?= (int)$year['ay_id'] ?>"<?= (int)$year['ay_id'] === $filters['ay_id'] ? ' selected' : '' ?>><?= $h($year['ay']) ?></option><?php endforeach; ?></select></div>
      <div><label class="form-label" for="resetSemester">Semester</label><select class="form-select" id="resetSemester" name="semester" required><?php foreach ([1=>'1st Semester',2=>'2nd Semester',3=>'Midyear'] as $id=>$label): ?><option value="<?= $id ?>"<?= $id === $filters['semester'] ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></div>
      <button type="submit" class="btn btn-primary">Filter</button>
    </form>
  </div></section>
  <?php if ($preview): $scope = $preview['scope']; $counts = $preview['counts']; ?>
    <section id="evaluationResetReview" aria-labelledby="resetReviewTitle">
      <h2 class="h5 mb-1" id="resetReviewTitle"><?= $h($scope['college_name']) ?></h2>
      <p class="text-muted mb-3"><?= $h($scope['campus_name'].' · '.$scope['term_text']) ?></p>
      <div class="evaluation-reset-counts mb-3">
        <?php foreach (['students'=>'Students who evaluated','submitted'=>'Submitted evaluations','drafts'=>'Saved drafts','records'=>'Total evaluation records'] as $key=>$label): ?><div class="card"><div class="card-body"><span class="small text-muted"><?= $label ?></span><strong data-reset-count="<?= $key ?>"><?= number_format($counts[$key]) ?></strong></div></div><?php endforeach; ?>
      </div>
      <p class="small text-muted">Students are counted once when they have at least one submitted evaluation. A student evaluating five faculty members counts as one student and five evaluation records. All questionnaire versions are included.</p>
      <?php if ($preview['unassigned']): ?><p class="alert alert-info" role="status"><?= number_format($preview['unassigned']) ?> evaluation record(s) in this academic term have no saved college. They are excluded and require ownership review.</p><?php endif; ?>
      <div class="card mb-4"><div class="card-body"><h3 class="h5">Delete student evaluations</h3><p>Deletes <?= number_format($counts['records']) ?> evaluation record(s), <?= number_format($counts['answers']) ?> answer row(s), and their evaluation-only subject snapshots for the college and term shown above.</p><p class="text-muted">Student accounts, profiles, enrollments, faculty records, schedules, workloads, supervisor evaluations, exclusion rules and evaluation settings are preserved.</p>
        <?php if ($preview['blocked']): ?><p class="alert alert-warning mb-0" role="status">Student evaluation collection is open. Close it in College Dean → Faculty Evaluation → Student Results, then filter again to review deletion.</p>
        <?php elseif (!$counts['records']): ?><p class="text-muted mb-0">No student evaluation records to delete for this college and term.</p>
        <?php else: ?><button class="btn btn-outline-danger" type="button" data-reset-review data-bs-toggle="modal" data-bs-target="#evaluationResetModal">Review deletion</button><?php endif; ?>
      </div></div>
      <?php if ($history): ?><details class="card mb-4"><summary class="card-body">Recent resets for this college and term</summary><ul class="small px-4 pb-3 mb-0"><?php foreach ($history as $entry): ?><li><?= $h((new DateTimeImmutable($entry['reset_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A').' (Philippine time) · '.$entry['actor_name']) ?> · <?= (int)$entry['submitted_count'] ?> submitted evaluations and <?= (int)$entry['draft_count'] ?> drafts deleted.</li><?php endforeach; ?></ul></details><?php endif; ?>
    </section>
    <?php if ($preview['preview_token']): ?>
    <div class="modal fade" id="evaluationResetModal" tabindex="-1" aria-labelledby="resetConfirmTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><form method="post" class="modal-content" id="evaluationResetConfirm">
      <div class="modal-header"><h2 class="modal-title fs-5" id="resetConfirmTitle">Confirm student evaluation deletion</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <p class="fw-semibold mb-1"><?= $h($scope['college_name']) ?></p><p><?= $h($scope['campus_name'].' · '.$scope['term_text']) ?></p>
        <p><?= (int)$counts['students'] ?> student(s) with submitted evaluations. Deletes <?= (int)$counts['submitted'] ?> submitted evaluation(s) and <?= (int)$counts['drafts'] ?> draft(s). This cannot be undone from this page.</p>
        <input type="hidden" name="action" value="confirm"><input type="hidden" name="csrf_token" value="<?= $h($_SESSION['student_evaluation_reset_csrf']) ?>"><input type="hidden" name="preview_token" value="<?= $h($preview['preview_token']) ?>">
        <?php foreach ($filters as $key=>$value): ?><input type="hidden" name="<?= $key ?>" value="<?= $value ?>"><?php endforeach; ?>
        <label class="form-label" for="resetConfirmation">Type <strong>DELETE STUDENT EVALUATIONS</strong></label><input class="form-control" id="resetConfirmation" name="confirmation" type="text" required autocomplete="off" spellcheck="false" pattern="DELETE STUDENT EVALUATIONS">
        <div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="confirm_delete" value="1" id="resetAcknowledge" required><label class="form-check-label" for="resetAcknowledge">I reviewed this college, term and affected counts.</label></div>
      </div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger" type="submit" id="resetDelete">Delete evaluations</button></div>
    </form></div></div>
    <?php endif; ?>
  <?php else: ?><div class="card"><div class="card-body text-muted">Select a campus and college, then filter to see evaluation counts.</div></div><?php endif; ?>
</div><?php require __DIR__.'/../footer.php'; ?></main></div></div><div class="layout-overlay layout-menu-toggle"></div></div>
<script src="../assets/vendor/libs/jquery/jquery.js"></script><script src="../assets/vendor/libs/popper/popper.js"></script><script src="../assets/vendor/js/bootstrap.js"></script><script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script><script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script><script src="student-evaluation-reset.js?v=<?= (int)filemtime(__DIR__.'/student-evaluation-reset.js') ?>" defer></script>
</body></html>
