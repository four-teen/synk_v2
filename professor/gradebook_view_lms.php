<?php
if (!isset($class,$gradebookReady) || $view!=='gradebook' || ($_SESSION['role']??'')!=='professor') {http_response_code(404);return;}
?>
<section id="gradebookWorkspace" class="gb-workspace" aria-labelledby="gradebookTitle">
  <div class="gb-heading"><div><p class="gb-eyebrow">SCORES &amp; GRADES</p><h2 id="gradebookTitle">Grade Book</h2></div><a class="btn btn-outline-secondary" href="<?= synk_professor_h(synk_professor_class_url_lms($class,'grading')) ?>"><i class="bx bx-slider-alt" aria-hidden="true"></i>Grading system</a></div>
  <?php if (!$gradebookReady): ?>
    <div class="lms-feedback-state" role="status">Gradebook storage needs to be prepared. Ask your administrator to install the LMS gradebook.</div>
  <?php else: ?>
    <div id="gbLoadState" class="lms-feedback-state" role="status">Loading gradebook...</div><button type="button" id="gbRetry" class="btn btn-outline-primary" hidden>Retry loading</button>
    <div id="gbContent" hidden>
      <nav class="gb-periods" aria-label="Gradebook period"><button type="button" data-gb-period="midterm" aria-pressed="true">Midterm</button><button type="button" data-gb-period="final" aria-pressed="false">Final</button><button type="button" data-gb-period="summary" aria-pressed="false">Semester summary</button></nav>
      <div id="gbOverview">
        <div id="gbSchemeNotice" class="gb-notice" role="status" hidden></div>
        <div class="gb-section-heading"><h3 id="gbPeriodTitle">Midterm gradebook</h3><div class="gb-actions"><button type="button" id="gbReload" class="btn btn-outline-secondary"><i class="bx bx-refresh" aria-hidden="true"></i>Refresh</button><button type="button" id="gbOpenWork" class="btn btn-primary"><i class="bx bx-list-ul" aria-hidden="true"></i>Open graded work <span id="gbWorkCount" class="gb-work-count">0</span></button></div></div>
        <section class="card gb-results" aria-labelledby="gbResultsTitle"><div class="gb-results-heading"><h3 id="gbResultsTitle">Student grades</h3><label class="gb-search"><span class="visually-hidden">Search students</span><input type="search" id="gbStudentSearch" class="form-control" placeholder="Search student name or number"></label></div><div id="gbResultsTable" class="gb-table-scroll" tabindex="0" aria-label="Student grades table"></div></section>
        <details class="gb-legend"><summary>Rating legend</summary><table><thead><tr><th>Rating</th><th>Grade</th><th>Remark</th></tr></thead><tbody>
          <?php foreach ([['1.00','99–100',''],['1.25','96–98',''],['1.50','93–95',''],['1.75','90–92',''],['2.00','87–89',''],['2.25','84–86',''],['2.50','81–83',''],['2.75','78–80',''],['3.00','75–77',''],['4.00','73–74','Conditional'],['5.00','72 and below','Failed']] as $band): ?><tr><td><?= $band[0] ?></td><td><?= $band[1] ?></td><td><?= $band[2] ?></td></tr><?php endforeach; ?>
        </tbody></table></details>
      </div>
      <section id="gbScoreEditor" class="card gb-score-editor" hidden aria-labelledby="gbScoreTitle">
        <div class="gb-section-heading"><div><button type="button" id="gbScoreBack" class="gb-text-button"><i class="bx bx-arrow-back" aria-hidden="true"></i>Back to graded work</button><h3 id="gbScoreTitle" tabindex="-1"></h3><p id="gbScoreHelp"></p></div><div class="gb-actions"><span id="gbSaveState" role="status" aria-live="polite"></span><button type="button" id="gbSaveScores" class="btn btn-primary">Save scores</button></div></div>
        <div class="gb-editor-toolbar"><label class="gb-search"><span class="visually-hidden">Filter score entry students</span><input type="search" id="gbScoreSearch" class="form-control" placeholder="Search student name or number"></label><button type="button" id="gbMarkPresent" class="btn btn-outline-primary" hidden>Mark unrecorded students present</button></div>
        <div id="gbScoreTable" class="gb-table-scroll" tabindex="0" aria-label="Student score entry table"></div>
      </section>
      <section id="gbAssessmentPreview" class="card gb-score-editor" hidden aria-labelledby="gbAssessmentPreviewTitle"><div class="gb-section-heading"><div><button type="button" id="gbPreviewBack" class="gb-text-button"><i class="bx bx-arrow-back" aria-hidden="true"></i>Back to graded work</button><h3 id="gbAssessmentPreviewTitle" tabindex="-1"></h3><p id="gbAssessmentPreviewHelp"></p></div><button type="button" id="gbPreviewLink" class="btn btn-primary">Assign grading component</button></div><div id="gbAssessmentPreviewTable" class="gb-table-scroll" tabindex="0" aria-label="LMS assessment submission scores"></div></section>
    </div>
    <div class="modal fade" id="gbWorkModal" tabindex="-1" aria-labelledby="gbWorkTitle"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content">
      <div class="modal-header"><div><p class="gb-eyebrow">GRADEBOOK ENTRIES</p><h3 class="modal-title" id="gbWorkTitle">Graded work</h3></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close graded work"></button></div>
      <div class="modal-body">
        <div class="gb-work-toolbar"><label class="gb-search"><span class="visually-hidden">Search graded work</span><input type="search" id="gbWorkSearch" class="form-control" placeholder="Search graded work"></label><button type="button" id="gbAdd" class="btn btn-primary"><i class="bx bx-plus" aria-hidden="true"></i>Add entry</button></div>
        <form id="gbAssignmentForm" class="gb-assignment-panel" hidden><div><strong id="gbAssignmentTitle"></strong><label for="gbAssignmentComponent" class="form-label">Grading component</label><select id="gbAssignmentComponent" class="form-select" required></select></div><div class="gb-actions"><button type="button" id="gbAssignmentCancel" class="btn btn-outline-secondary">Cancel</button><button type="submit" class="btn btn-primary">Save assignment</button></div></form>
        <div id="gbWorkTable" class="gb-table-scroll" tabindex="0" aria-label="Graded work list"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
    </div></div></div>
    <div class="modal fade" id="gbItemModal" tabindex="-1" aria-labelledby="gbItemTitle" data-bs-backdrop="static"><div class="modal-dialog modal-dialog-centered"><form id="gbItemForm" class="modal-content"><div class="modal-header"><h3 class="modal-title" id="gbItemTitle">Add gradebook entry</h3><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close entry form"></button></div><div class="modal-body">
      <label class="form-label" for="gbItemComponent">Grading component</label><select id="gbItemComponent" class="form-select mb-3" required></select>
      <label class="form-label" for="gbItemKind">Entry type</label><select id="gbItemKind" class="form-select mb-3"><option value="score">Manual score · exam, quiz, activity or other work</option><option value="attendance">Attendance session · present / absent</option><option value="lms">Link an LMS assessment</option></select>
      <div id="gbAssessmentField" hidden><label class="form-label" for="gbItemAssessment">LMS assessment</label><select id="gbItemAssessment" class="form-select mb-3"></select></div>
      <label class="form-label" for="gbItemName">Entry title</label><input type="text" id="gbItemName" class="form-control mb-3" maxlength="200" placeholder="e.g. Written midterm exam" required>
      <div class="gb-item-fields"><div><label class="form-label" for="gbItemMax">Maximum points</label><input type="number" id="gbItemMax" class="form-control" min="0.01" max="10000" step="0.01" value="100" required></div><div><label class="form-label" for="gbItemDate">Date <span class="text-muted">(optional)</span></label><input type="date" id="gbItemDate" class="form-control" min="2000-01-01" max="2100-12-31"></div></div><p id="gbItemHelp" class="gb-help"></p>
    </div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save entry</button></div></form></div></div>
    <script id="gradebookConfig" type="application/json"><?= json_encode(['endpoint'=>'gradebook_api_lms.php','csrf_token'=>$_SESSION['lms_gradebook_csrf'],'context'=>array_intersect_key($_GET,array_flip(['offering_id','ay_id','semester','view','signature']))],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
  <?php endif; ?>
</section>
