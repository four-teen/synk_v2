<?php
if (($_SESSION['role'] ?? '')!=='student' || !empty($studentPortalPreviewMode)) return;
require_once __DIR__.'/../backend/student_evaluation_prompt_helper.php';
if (empty($_SESSION['student_evaluation_prompt_csrf'])) $_SESSION['student_evaluation_prompt_csrf']=bin2hex(random_bytes(32));
try { $studentEvaluationPrompt=synk_student_evaluation_prompt_state($conn,(int)$_SESSION['student_id']); }
catch (Throwable $error) { error_log('Student evaluation reminder: '.$error->getMessage());$studentEvaluationPrompt=null; }
?>
<div class="modal fade student-evaluation-prompt" id="studentEvaluationPrompt" tabindex="-1" aria-labelledby="studentEvaluationPromptTitle" aria-describedby="studentEvaluationPromptDescription" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header"><div><p class="student-eyebrow mb-1">FACULTY EVALUATION</p><h2 class="modal-title fs-5" id="studentEvaluationPromptTitle">Your evaluation is incomplete</h2></div></div>
  <div class="modal-body"><p id="studentEvaluationPromptDescription">Complete the remaining faculty evaluations, then review and submit your answers.</p><p class="student-evaluation-prompt-period" id="studentEvaluationPromptPeriod"></p><div class="student-evaluation-prompt-progress" id="studentEvaluationPromptProgress" role="status"></div>
    <ul class="student-evaluation-prompt-missing" id="studentEvaluationPromptMissing"></ul>
    <p class="student-evaluation-prompt-note" id="studentEvaluationPromptNote">Saved answers are not submitted until you complete final submission.</p>
    <div id="studentEvaluationPromptColleges" class="student-evaluation-prompt-colleges"></div>
  </div><div class="modal-footer"><a class="btn btn-outline-secondary" href="../logout.php">Log out</a><a class="btn btn-primary" id="studentEvaluationPromptStart" href="class-program.php">Continue Evaluation</a></div>
</div></div></div>
<script type="application/json" id="studentEvaluationPromptConfig"><?= json_encode(['student_id'=>(int)$_SESSION['student_id'],'csrf_token'=>$_SESSION['student_evaluation_prompt_csrf'],'endpoint'=>'../backend/query_student_evaluation_prompt.php','initial'=>$studentEvaluationPrompt],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="evaluation-prompt.js?v=<?= (int)filemtime(__DIR__.'/evaluation-prompt.js') ?>" defer></script>
