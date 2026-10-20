<?php
if (!isset($deanEvaluationScope,$deanEvaluationSettings)) { http_response_code(404); exit; }
if ($deanEvaluationControlError!==''): ?>
<div class="alert alert-danger" role="alert"><?php echo synk_dean_evaluation_h($deanEvaluationControlError); ?></div>
<?php endif;
if (($_GET['access_saved'] ?? '')==='1'): ?>
<div class="alert alert-success" role="status">Evaluation access has been saved for your college and selected term.</div>
<?php endif;
if (($_SESSION['role'] ?? '')!=='college_dean') return;
?>
<section class="card mb-4" aria-labelledby="evaluationAccessTitle" id="evaluationAccessControls">
  <div class="card-body p-4">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
      <div><h5 id="evaluationAccessTitle" class="mb-1">Evaluation access</h5><p class="text-muted mb-0"><?php echo synk_dean_evaluation_h($deanEvaluationScope['college_name']); ?> &middot; <?php echo synk_dean_evaluation_h($deanEvaluationTerm['term_text']); ?></p></div>
      <span class="badge bg-label-secondary align-self-start"><?php echo $deanEvaluationCurrent ? 'Current term' : 'Read-only term'; ?></span>
    </div>
    <div class="row g-3">
      <?php foreach ([
          'student_open'=>['Student evaluations','Students can answer, save drafts, and submit evaluations.','Open evaluations','Close evaluations'],
          'faculty_released'=>['Faculty results','Professors can view their own submitted evaluation results for this college.','Release results','Hide results']
      ] as $control=>$copy):
          $enabled=!empty($deanEvaluationSettings[$control]) && ($control!=='student_open' || $deanEvaluationCurrent); ?>
      <div class="col-md-6"><div class="border rounded-3 p-3 h-100">
        <div class="d-flex justify-content-between gap-2 mb-2">
          <h6 class="mb-0"><?php echo $copy[0]; ?></h6>
          <span class="badge <?php echo $enabled?'bg-label-success':'bg-label-secondary'; ?>" data-evaluation-control="<?php echo $control; ?>"><?php echo $control==='student_open'?($enabled?'Open':'Closed'):($enabled?'Released':'Hidden'); ?></span>
        </div>
        <p class="small text-muted"><?php echo $copy[1]; ?></p>
        <?php if ($control==='student_open' && !$deanEvaluationCurrent): ?>
        <p class="small text-muted mb-0">This term is read only. Previous responses are preserved.</p>
        <?php else: ?>
        <form method="post" action="<?php echo synk_dean_evaluation_h(synk_dean_evaluation_url($deanEvaluationTerm,$deanEvaluationFilters)); ?>">
          <?php foreach (['save_evaluation_control'=>'1','csrf_token'=>$_SESSION['csrf_token'],'college_id'=>$deanEvaluationScope['college_id'],'ay_id'=>$deanEvaluationTerm['ay_id'],'semester'=>$deanEvaluationTerm['semester'],'control'=>$control,'value'=>$enabled?'0':'1','version'=>$deanEvaluationSettings['version']] as $name=>$value): ?>
          <input type="hidden" name="<?php echo $name; ?>" value="<?php echo synk_dean_evaluation_h($value); ?>">
          <?php endforeach; ?>
          <button type="submit" class="btn btn-sm <?php echo $enabled?'btn-outline-secondary':'btn-primary'; ?>"<?php echo (int)$deanEvaluationTerm['ay_id']<=0?' disabled':''; ?>><?php echo $copy[$enabled?3:2]; ?></button>
        </form>
        <?php endif; ?>
      </div></div>
      <?php endforeach; ?>
    </div>
    <p class="small text-muted mt-3 mb-0">These controls apply only to this college and term. Closing evaluations keeps saved drafts and submitted responses. Each new term starts closed with faculty results hidden.</p>
    <?php if ($deanEvaluationAudit): ?>
    <details class="mt-3"><summary class="small text-primary">Recent access changes</summary><ul class="small mt-2 mb-0">
      <?php foreach ($deanEvaluationAudit as $change): ?>
      <li><?php echo synk_dean_evaluation_h($change['changed_at'].' · '.($change['username'] ?: 'Dean').' · '.($change['control_name']==='student_open'?'Student evaluations':'Faculty results').' '.($change['control_name']==='student_open'?($change['new_value']?'opened':'closed'):($change['new_value']?'released':'hidden'))); ?></li>
      <?php endforeach; ?>
    </ul></details>
    <?php endif; ?>
  </div>
</section>
