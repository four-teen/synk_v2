<?php
if (!isset($class, $gradingReady) || $view !== 'grading' || ($_SESSION['role'] ?? '') !== 'professor') { http_response_code(404); return; }
?>
<section class="grading-workspace" id="gradingWorkspace" aria-labelledby="gradingTitle">
  <div class="grading-heading"><div><p class="grading-eyebrow">GRADING SETUP</p><h2 id="gradingTitle">Manage Grading System</h2><p>Choose how each component contributes to the Midterm and Final grade.</p></div></div>
  <p class="grading-note"><i class="bx bx-info-circle" aria-hidden="true"></i><span>Each period must total 100%. Grade Book uses these components for manual entries and linked LMS assessments. After changing a setup used by recorded scores, open Grade Book to review and apply the new revision.</span></p>
  <?php if (!$gradingReady): ?>
    <div class="lms-feedback-state" role="status">Grading storage needs to be prepared. Ask your administrator to set up the LMS grading system.</div>
  <?php else: ?>
    <div id="gradingLoadNotice" class="lms-feedback-state" role="status">Loading grading components...</div>
    <button type="button" class="btn btn-outline-secondary mb-3" id="gradingRetry" hidden>Retry loading</button>
    <div class="grading-period-grid" id="gradingPeriods" hidden>
      <?php foreach (['midterm'=>'Midterm','final'=>'Final'] as $period=>$label): ?>
      <form class="card grading-period" data-grading-period="<?= $period ?>" aria-labelledby="gradingHeading-<?= $period ?>">
        <div class="grading-period-heading"><div><p class="grading-eyebrow">GRADING PERIOD</p><h3 id="gradingHeading-<?= $period ?>"><?= $label ?></h3><span data-save-state class="grading-save-state" role="status">Not configured</span></div><span class="grading-total" data-total>0%</span></div>
        <div class="grading-period-tools"><div class="grading-primary-actions"><button type="button" class="btn btn-sm btn-outline-primary" data-action="add"><i class="bx bx-plus" aria-hidden="true"></i>Add component</button><button type="submit" class="btn btn-sm btn-primary" data-save>Save <?= $label ?></button></div></div>
        <div class="grading-components" data-components></div>
        <div class="grading-period-footer"><div><strong><?= $label ?> total: <span data-total-label>0%</span></strong><p data-balance>Add components to reach 100%.</p></div><button type="button" class="grading-text-button" data-action="reload">Reload period</button></div>
      </form>
      <?php endforeach; ?>
    </div>
    <script id="gradingConfig" type="application/json"><?= json_encode(['csrf_token'=>(string)$_SESSION['lms_grading_csrf'],'endpoint'=>'grading_api_lms.php','context'=>array_intersect_key($_GET,array_flip(['offering_id','ay_id','semester','view','signature']))], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
  <?php endif; ?>
</section>
