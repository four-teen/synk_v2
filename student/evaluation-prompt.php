<?php
if (($_SESSION['role'] ?? '')!=='student' || !empty($studentPortalPreviewMode)) return;
require_once __DIR__.'/../backend/student_evaluation_prompt_helper.php';
if (empty($_SESSION['student_evaluation_prompt_csrf'])) $_SESSION['student_evaluation_prompt_csrf']=bin2hex(random_bytes(32));
try { $studentEvaluationPrompt=synk_student_evaluation_prompt_state($conn,(int)$_SESSION['student_id']); }
catch (Throwable $error) { error_log('Student evaluation reminder: '.$error->getMessage());$studentEvaluationPrompt=null; }
?>
<div class="modal fade student-evaluation-prompt" id="studentEvaluationPrompt" tabindex="-1" aria-labelledby="studentEvaluationPromptTitle" aria-describedby="studentEvaluationPromptDescription" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header"><div><p class="student-eyebrow mb-1">FACULTY EVALUATION</p><h2 class="modal-title fs-5" id="studentEvaluationPromptTitle">Your evaluation is ready</h2></div></div>
  <div class="modal-body"><p id="studentEvaluationPromptDescription">Your college has opened faculty evaluation. Please complete every assigned faculty member and submit the final evaluation.</p><p class="student-evaluation-prompt-period" id="studentEvaluationPromptPeriod"></p><div class="student-evaluation-prompt-progress" id="studentEvaluationPromptProgress" role="status"></div>
    <ol><li>Select a faculty member from your current enrolled subjects.</li><li>Answer all 15 statements honestly using the rating guide, add optional comments, then save the draft.</li><li>Repeat for every listed faculty member in each open college.</li><li>Choose <strong>Review Final Evaluation</strong>, review your answers, and select <strong>Submit Final Evaluation</strong>.</li></ol>
    <p class="mb-0"><strong>Saving a draft does not submit your evaluation.</strong> This reminder returns while a required evaluation is incomplete. Submitted responses remain locked.</p>
    <div id="studentEvaluationPromptColleges" class="student-evaluation-prompt-colleges"></div>
  </div><div class="modal-footer"><a class="btn btn-outline-secondary" href="../logout.php">Log out</a><a class="btn btn-primary" id="studentEvaluationPromptStart" href="class-program.php">Start evaluation</a></div>
</div></div></div>
<script type="application/json" id="studentEvaluationPromptConfig"><?= json_encode(['student_id'=>(int)$_SESSION['student_id'],'csrf_token'=>$_SESSION['student_evaluation_prompt_csrf'],'endpoint'=>'../backend/query_student_evaluation_prompt.php','initial'=>$studentEvaluationPrompt],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="evaluation-prompt.js?v=<?= (int)filemtime(__DIR__.'/evaluation-prompt.js') ?>" defer></script>
