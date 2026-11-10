<?php
if (!isset($class, $assessmentsReady) || $view !== 'assessments' || ($_SESSION['role'] ?? '') !== 'professor') { http_response_code(404); return; }
?>
<section class="assessment-workspace" id="assessmentWorkspace" aria-labelledby="assessmentWorkspaceTitle">
  <div class="assessment-heading">
    <div><p class="assessment-eyebrow">CLASSWORK</p><h2 id="assessmentWorkspaceTitle">Activities &amp; assessments</h2><p>Create and reuse quizzes, exams, and assignments for each grading period.</p></div>
    <?php if ($assessmentsReady): ?><button type="button" class="btn btn-primary" id="assessmentNew"><i class="bx bx-plus me-1" aria-hidden="true"></i>Create draft</button><?php endif; ?>
  </div>
  <p class="assessment-stage-note"><i class="bx bx-edit-alt" aria-hidden="true"></i>Drafts are private. You can edit published assessments at any time. Changes apply to new attempts; students who have started keep their original questions, answers, and timer.</p>
  <?php if (!$assessmentsReady): ?>
    <div class="lms-feedback-state" role="status">The assessment workspace needs to be set up. Ask your administrator to prepare LMS assessment storage.</div>
  <?php else: ?>
    <div id="assessmentLibrary">
      <div class="assessment-periods" role="group" aria-label="Grading period">
        <button type="button" data-period="midterm" class="is-active" aria-pressed="true"><span class="assessment-period-label"><i class="bx bx-sun" aria-hidden="true"></i>Midterm</span><span class="assessment-period-count" id="assessmentMidtermCount" title="Midterm assessments">0</span></button>
        <button type="button" data-period="final" aria-pressed="false"><span class="assessment-period-label"><i class="bx bx-flag" aria-hidden="true"></i>Final</span><span class="assessment-period-count" id="assessmentFinalCount" title="Final assessments">0</span></button>
      </div>
      <div class="assessment-toolbar">
        <div class="assessment-search"><label for="assessmentSearch" class="visually-hidden">Search assessment title or topic</label><i class="bx bx-search" aria-hidden="true"></i><input type="search" class="form-control" id="assessmentSearch" maxlength="200" placeholder="Search title or topic"></div>
        <div><label for="assessmentTypeFilter" class="visually-hidden">Filter by type</label><select class="form-select" id="assessmentTypeFilter"><option value="all">All types</option><option value="quiz">Quizzes</option><option value="exam">Exams</option><option value="assignment">Assignments</option></select></div>
        <button class="btn btn-outline-secondary" type="button" id="assessmentRefresh" aria-label="Refresh assessment list"><i class="bx bx-refresh" aria-hidden="true"></i></button>
      </div>
      <div id="assessmentList" class="assessment-list" aria-live="polite" aria-busy="true"><div class="card assessment-empty">Loading assessments...</div></div>
    </div>
    <div id="assessmentEditor" hidden>
      <div class="assessment-editor-heading">
        <div><button class="assessment-text-button" type="button" id="assessmentBack"><i class="bx bx-arrow-back" aria-hidden="true"></i>All assessments</button><h3 id="assessmentEditorTitle">New assessment</h3><span class="assessment-save-state" id="assessmentSaveState" role="status">Not saved yet</span></div>
        <div class="assessment-actions"><button type="button" class="btn btn-outline-primary" id="assessmentPreview">Preview</button><button type="submit" form="assessmentForm" class="btn btn-primary" id="assessmentSave">Save draft</button><button type="submit" form="assessmentForm" class="btn btn-success" id="assessmentPublish">Publish</button></div>
      </div>
      <p class="assessment-stage-note" id="assessmentLiveNote" role="note" hidden></p>
      <form id="assessmentForm">
        <div class="assessment-editor-grid">
          <div class="assessment-content">
            <section class="card assessment-form-card" aria-labelledby="assessmentContentTitle">
              <h4 id="assessmentContentTitle">Assessment details</h4>
              <div class="mb-3"><label class="form-label" for="assessmentTitle">Title <span aria-hidden="true">*</span></label><input class="form-control" id="assessmentTitle" type="text" maxlength="200" required placeholder="e.g. Quiz 1: Variables and data types"></div>
              <div><label class="form-label" for="assessmentInstructions">Instructions</label><textarea class="form-control" id="assessmentInstructions" rows="5" maxlength="20000" placeholder="Explain what students should do. For assignments, describe the expected work."></textarea></div>
            </section>
            <section id="assessmentQuestionsSection" aria-labelledby="assessmentQuestionsTitle">
              <div class="assessment-question-heading"><div><h4 id="assessmentQuestionsTitle">Questions</h4><p>Choose a type, set points, and prepare the answer key.</p></div><span class="assessment-total" id="assessmentQuestionTotal">0 questions / 0 points</span></div>
              <button type="button" class="btn btn-outline-primary w-100" id="assessmentAddQuestion"><i class="bx bx-plus me-1" aria-hidden="true"></i>Add question</button>
              <div id="assessmentQuestions" class="assessment-questions"></div>
              <p class="assessment-help mt-2">Drafts can start without questions. Short answers accept the alternatives you list; essays require manual grading.</p>
            </section>
            <div class="card assessment-form-card" id="assessmentAssignmentNote" hidden><h4>Assignment instructions</h4><p class="assessment-help mb-0">Describe the work and set its total points in Settings. Students submit a written response. File attachments will be added separately.</p></div>
          </div>
          <aside class="card assessment-form-card assessment-settings" aria-labelledby="assessmentSettingsTitle">
            <h4 id="assessmentSettingsTitle">Settings</h4>
            <div><label class="form-label" for="assessmentPeriod">Grading period</label><select class="form-select" id="assessmentPeriod"><option value="midterm">Midterm</option><option value="final">Final</option></select></div>
            <div><label class="form-label" for="assessmentType">Type</label><select class="form-select" id="assessmentType"><option value="quiz">Quiz</option><option value="exam">Exam</option><option value="assignment">Assignment</option></select></div>
            <div><label class="form-label" for="assessmentTopic">Topic <span class="text-muted">(optional)</span></label><input class="form-control" id="assessmentTopic" type="text" maxlength="120" placeholder="e.g. Programming fundamentals"></div>
            <div id="assessmentAssignmentPoints" hidden><label class="form-label" for="assessmentPoints">Total points</label><input class="form-control" id="assessmentPoints" type="number" min="0.01" max="10000" step="0.01" value="100"></div>
            <div><label class="form-label" for="assessmentOpens">Opens <span class="text-muted">(optional)</span></label><input class="form-control" id="assessmentOpens" type="datetime-local" min="2000-01-01T00:00" max="2100-12-31T23:59"></div>
            <div><label class="form-label" for="assessmentDue">Due <span class="text-muted">(optional)</span></label><input class="form-control" id="assessmentDue" type="datetime-local" min="2000-01-01T00:00" max="2100-12-31T23:59"></div>
            <div id="assessmentTimer"><label class="form-label" for="assessmentTimeLimit">Time limit <span class="text-muted">(optional)</span></label><div class="input-group"><input class="form-control" id="assessmentTimeLimit" type="number" min="1" max="240" step="1" placeholder="No limit"><span class="input-group-text">min</span></div></div>
            <p class="assessment-help mb-0" id="assessmentSettingsHelp">Dates use Philippine time (Asia/Manila). Saving a draft does not publish it.</p>
          </aside>
        </div>
      </form>
      <div class="assessment-floating-actions" id="assessmentFloatingActions" role="group" aria-label="Unsaved assessment actions" hidden>
        <span class="assessment-floating-state" id="assessmentFloatingState">Not saved yet</span>
        <div class="assessment-actions"><button type="button" class="btn btn-outline-primary" id="assessmentPreviewBottom">Preview</button><button type="submit" form="assessmentForm" class="btn btn-primary" id="assessmentSaveBottom">Save draft</button><button type="submit" form="assessmentForm" class="btn btn-success" id="assessmentPublishBottom">Publish</button></div>
      </div>
    </div>
    <div class="modal fade" id="assessmentPreviewModal" tabindex="-1" aria-labelledby="assessmentPreviewTitle" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><div><p class="assessment-eyebrow mb-1">PROFESSOR PREVIEW</p><h5 class="modal-title" id="assessmentPreviewTitle">Assessment preview</h5></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close preview"></button></div>
        <div class="modal-body"><div class="form-check form-switch mb-4"><input class="form-check-input" type="checkbox" id="assessmentShowAnswers"><label class="form-check-label" for="assessmentShowAnswers">Show answer key</label></div><div id="assessmentPreviewBody"></div></div>
        <div class="modal-footer"><span class="assessment-help me-auto">Preview only. Responses are not recorded.</span><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
      </div></div>
    </div>
    <div class="modal fade" id="assessmentDuplicateModal" tabindex="-1" aria-labelledby="assessmentDuplicateTitle" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="assessmentDuplicateForm">
        <div class="modal-header"><h5 class="modal-title" id="assessmentDuplicateTitle">Duplicate assessment</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body"><p id="assessmentDuplicateName" class="assessment-plain-text"></p><label class="form-label" for="assessmentDuplicatePeriod">Grading period for the copy</label><select class="form-select" id="assessmentDuplicatePeriod"><option value="midterm">Midterm</option><option value="final">Final</option></select><p class="assessment-help mt-3 mb-0">Creates a private draft with the same questions and answer key. Opening and due dates are cleared.</p></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Create copy</button></div>
      </form></div>
    </div>
    <div class="modal fade" id="assessmentSubmissionsModal" tabindex="-1" aria-labelledby="assessmentSubmissionsTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="assessmentSubmissionsTitle">Student responses</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body" id="assessmentSubmissionsBody"></div><div class="modal-footer"><span class="assessment-help me-auto">Responses are saved. Grading and grade release will be added with the gradebook.</span><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div></div></div></div>
    <script id="assessmentConfig" type="application/json"><?= json_encode(['csrf_token'=>(string)$_SESSION['lms_assessment_csrf'], 'endpoint'=>'assessments_api_lms.php', 'context'=>array_intersect_key($_GET, array_flip(['offering_id','ay_id','semester','view','signature']))], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
  <?php endif; ?>
</section>
