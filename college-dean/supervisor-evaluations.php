<?php
$sefPage='supervisor';
require __DIR__.'/../backend/supervisor_evaluation_page.php';
$sefTitle="Supervisor's Evaluation of Faculty";
$catalog=synk_sef_catalog($conn,$sefScope,$sefTerm); $reviewers=synk_sef_reviewers($conn,$sefScope);
$assignments=synk_sef_assignments($conn,$sefScope,$sefTerm); $records=synk_sef_records($conn,$sefScope,$sefTerm,$sefActor);
$owned=[]; $draftData=[]; $pending=[]; $complete=true;
foreach ($assignments as $facultyId=>$assignment) {
    try { synk_sef_require_assignment($sefActor,$catalog[$facultyId] ?? null,$assignment,$reviewers); }
    catch (DomainException $ignored) { continue; }
    $owned[$facultyId]=$assignment;
    $record=$records[$facultyId] ?? null;
    if ($record && $record['submission_status']==='submitted') continue;
    $pending[$facultyId]=$record;
    if (!$record) $complete=false;
    $ratings=[];
    if ($record) foreach ($record['answers'] as $key=>$question) $ratings[$key]=(int)$question['rating'];
    $context=synk_sef_context($conn,$sefScope,$sefTerm,(int)$facultyId);
    $subjectLabels=[];$yearLabels=[];
    foreach ($context['subjects'] as $subject) { $subjectLabels[synk_evaluation_subject_label($subject)]=true;if ((int)$subject['year_level']>0) $yearLabels[(int)$subject['year_level']]=true; }
    $draftData[$facultyId]=['name'=>$catalog[$facultyId]['faculty_name'],'assignment_revision'=>(int)$assignment['revision'],'version'=>(int)($record['version'] ?? 0),'ratings'=>$ratings,'comment'=>$record['comment_text'] ?? '',
        'academic_rank'=>$context['academic_rank'],'subjects'=>implode('; ',array_keys($subjectLabels)),'program_years'=>implode(', ',array_keys($yearLabels))];
}
$selected=is_scalar($_POST['faculty_id'] ?? $_GET['faculty_id'] ?? null)?(int)($_POST['faculty_id'] ?? $_GET['faculty_id']):0;
if ($sefError!=='' && isset($draftData[$selected]) && ($_POST['action'] ?? '')==='save') {
    if (is_string($_POST['comment_text'] ?? null)) $draftData[$selected]['comment']=$_POST['comment_text'];
    if (is_array($_POST['ratings'] ?? null)) foreach (synk_sef_questions() as $key=>$question) if (is_scalar($_POST['ratings'][$key] ?? null)) $draftData[$selected]['ratings'][$key]=(string)$_POST['ratings'][$key];
    if (is_scalar($_POST['version'] ?? null)) $draftData[$selected]['version']=(int)$_POST['version'];
    if (is_scalar($_POST['assignment_revision'] ?? null)) $draftData[$selected]['assignment_revision']=(int)$_POST['assignment_revision'];
}
$writable=$sefReady && $sefCurrent && !empty($sefSettings['is_open']);
require __DIR__.'/evaluation-page-header.php';
?>
<section class="card mb-4"><div class="card-body"><p>Faculty appear under the program with their highest teaching load. Tied program Chairs share one evaluation; submission by either completes it for both. The Dean evaluates Program Chairs.</p>
<p class="fw-semibold mb-0"><?= sef_h($sefTerm['term_text']) ?> <small class="text-muted">Follows your module viewing term.</small></p>
<div class="d-flex flex-wrap gap-2 mt-3"><span class="badge <?= $writable?'bg-label-success':'bg-label-secondary' ?>">Supervisor collection: <?= !empty($sefSettings['is_open']) && $sefCurrent?'Open':'Closed / read only' ?></span><span class="badge bg-label-primary"><?= count($owned) ?> assigned to you</span><span class="badge bg-label-info"><?= count($owned)-count($pending) ?> submitted</span><span class="badge bg-label-warning"><?= count($pending) ?> pending</span></div>
<p class="small text-muted mt-3 mb-0"><?= sef_h(synk_sef_instrument()['label']) ?> · Effective January 14, 2026 · 15 statements. Tied program Chairs can view and edit the same draft. Changes are version checked; final submission locks the shared evaluation and makes it available in Combined Results. Student collection settings are independent.</p>
<?php if (!$sefActor['faculty_id']): ?><p class="alert alert-warning mt-3 mb-0">An administrator must link your account to your active faculty record before you can rate faculty. Result viewing remains available.</p><?php endif; ?>
</div></section>
<?php if ($sefActor['role']==='college_dean' && $sefReady): ?>
<section class="card mb-4"><div class="card-body"><h5>Supervisor evaluation controls</h5><div class="d-flex flex-wrap gap-3">
<form method="post" data-sef-once><?php sef_hidden($sefFields+['action'=>'collection','is_open'=>empty($sefSettings['is_open'])?1:0,'version'=>$sefSettings['version']]); ?><button class="btn btn-outline-primary"<?= !$sefCurrent?' disabled':'' ?>><?= empty($sefSettings['is_open'])?'Open Supervisor Evaluations':'Close Supervisor Evaluations' ?></button></form>
</div><p class="small text-muted mt-3 mb-0">Reviewer lists follow actual teaching workload and program Chair appointments automatically. Once a draft is saved, its reviewer group is preserved for this evaluation.</p></div></section>
<?php endif; ?>
<section class="card mb-4"><div class="card-body"><div class="d-flex flex-wrap justify-content-between gap-3 mb-3"><h5 class="mb-0"><?= $sefActor['role']==='college_dean'?'College Faculty and Automatic Routing':'Your Assigned Faculty' ?></h5><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#sefFinalReview"<?= !$writable || !$pending || !$complete?' disabled':'' ?>>Review Final Evaluations</button></div>
<label for="sefFacultySearch" class="form-label">Search faculty</label><input class="form-control mb-3" type="search" id="sefFacultySearch" placeholder="Faculty name" data-sef-search>
<div class="sef-table-wrap"><table class="table sef-table"><thead><tr><th>Faculty</th><th>Evaluator</th><th>Your Evaluation</th><th>Action</th><th>Assignment basis</th></tr></thead><tbody>
<?php $displayed=0; foreach ($catalog as $facultyId=>$faculty): if ($sefActor['role']!=='college_dean' && !isset($owned[$facultyId])) continue; $displayed++; $assignment=$assignments[$facultyId] ?? null; $record=$records[$facultyId] ?? null; $submitted=$record && $record['submission_status']==='submitted'; ?>
<tr data-sef-row data-name="<?= sef_h($faculty['faculty_name']) ?>"><td data-label="Faculty"><strong><?= sef_h($faculty['faculty_name']) ?></strong><small class="d-block text-muted"><?= $faculty['is_chair']?'Program Chair':($faculty['is_dean']?'Teaching Dean':'Faculty') ?></small></td>
<td data-label="Evaluator"><?= sef_h(($assignment['reviewer_name'] ?? '') ?: 'No eligible evaluator') ?><small class="d-block text-muted"><?= $faculty['reviewer_role']==='college_dean'?'Dean evaluates this faculty':'Program Chair evaluates this faculty' ?></small></td>
<td data-label="Your Evaluation"><?php if ($submitted): ?><span class="badge bg-label-success">Completed</span><small class="d-block text-muted">Submitted by <?= sef_h($record['evaluator_name']) ?></small><?php elseif (isset($owned[$facultyId])): ?><span class="badge bg-label-warning"><?= $record?'Draft saved':'Not started' ?></span><?php else: ?><span class="text-muted">Assigned evaluator only</span><?php endif; ?></td>
<td data-label="Action"><?php if (isset($draftData[$facultyId])): ?><button class="btn btn-sm btn-primary" data-sef-evaluate="<?= (int)$facultyId ?>"<?= !$writable?' disabled':'' ?>><?= $record?'Review / Edit Draft':'Evaluate Faculty' ?></button><?php endif; ?><?php if ($submitted): ?><a class="btn btn-sm btn-outline-primary" href="<?= sef_h(sef_url('faculty-performance-report.php',$sefQuery+['faculty_id'=>$facultyId])) ?>">View Report</a><?php endif; ?></td>
<td data-label="Assignment basis"><?= sef_h($assignment['routing_reason'] ?? 'No assigned teaching workload') ?></td></tr>
<?php endforeach; ?></tbody></table></div><p class="text-muted mt-3" data-sef-empty<?= $displayed?' hidden':'' ?>>No faculty match your assignments or search. Faculty appear automatically from their program teaching workloads and Chair appointments.</p>
<?php if ($pending && !$complete): ?><p class="small text-muted mt-3 mb-0">Complete and save all 15 ratings and a comment for every assigned faculty to unlock final review.</p><?php endif; ?></div></section>
<?php if ($sefReady): ?>
<script type="application/json" id="sefDraftData"><?= json_encode($draftData,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<div class="modal fade sef-modal" id="sefEvaluationModal" tabindex="-1" aria-labelledby="sefModalTitle" data-bs-backdrop="static" data-selected="<?= $writable && isset($draftData[$selected])?$selected:0 ?>"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><form method="post" class="modal-content" id="sefEvaluationForm" data-sef-once>
<div class="modal-header"><div><h2 class="modal-title fs-5" id="sefModalTitle">Supervisor Evaluation</h2><p class="fw-semibold mt-2 mb-0" id="sefModalFaculty"></p></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close evaluation"></button></div>
<div class="modal-body"><p class="small text-muted"><?= sef_h($sefScope['college_name'].' · '.$sefTerm['term_text']) ?></p><p class="small"><?= sef_h(synk_sef_instrument()['label']) ?>. Rate the faculty member's teaching across their subjects. Use the suggested evidence to guide your assessment.</p>
<dl class="sef-context"><div><dt>Academic Rank</dt><dd id="sefAcademicRank"></dd></div><div><dt>Course Code / Title</dt><dd id="sefSubjectContext"></dd></div><div><dt>Program Year</dt><dd id="sefProgramYear"></dd></div></dl>
<?php if ($sefError!=='' && ($_POST['action'] ?? '')==='save'): ?><div class="alert alert-danger" role="alert"><?= sef_h($sefError) ?></div><?php endif; ?>
<?php sef_hidden($sefFields+['action'=>'save','faculty_id'=>0,'assignment_revision'=>0,'version'=>0]); ?>
<details class="mb-4"><summary>Rating scale</summary><ul class="mt-2"><?php foreach (synk_sef_scale() as $rating=>$definition): ?><li><strong><?= $rating ?> — <?= sef_h($definition['label']) ?></strong>: <?= sef_h($definition['description']) ?></li><?php endforeach; ?></ul></details>
<?php $lastCategory=''; foreach (synk_sef_questions() as $key=>$question): if ($question['category_key']!==$lastCategory): $lastCategory=$question['category_key']; ?><h3 class="fs-6 sef-category"><?= sef_h($question['category_title']) ?></h3><?php endif; ?>
<div class="sef-question"><label class="form-label fw-semibold" for="sefRating<?= $question['order'] ?>"><?= $question['order'] ?>. <?= sef_h($question['text']) ?></label><select class="form-select" id="sefRating<?= $question['order'] ?>" name="ratings[<?= $key ?>]" required><option value="">Choose a rating</option><?php foreach (synk_sef_scale() as $rating=>$definition): ?><option value="<?= $rating ?>"><?= $rating ?> — <?= sef_h($definition['label']) ?></option><?php endforeach; ?></select><details class="mt-2 small text-muted"><summary>Suggested means of verification</summary><ul class="mt-2 mb-0"><?php foreach ($question['verification'] as $evidence): ?><li><?= sef_h($evidence) ?></li><?php endforeach; ?></ul></details></div>
<?php endforeach; ?>
<label class="form-label fw-semibold" for="sefComment">Other Comments / Suggestions</label><textarea class="form-control" id="sefComment" name="comment_text" rows="4" maxlength="10000" required></textarea><p class="form-text">Enter a comment before saving. This is a draft until final submission.</p></div>
<div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="submit"<?= !$writable?' disabled':'' ?>>Save Draft</button></div></form></div></div>
<div class="modal fade sef-modal" id="sefFinalReview" tabindex="-1" aria-labelledby="sefFinalTitle" data-bs-backdrop="static"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><form class="modal-content" method="post" data-sef-once id="sefFinalForm"><div class="modal-header"><h2 class="modal-title fs-5" id="sefFinalTitle">Review Final Supervisor Evaluations</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close final review"></button></div><div class="modal-body">
<?php sef_hidden($sefFields+['action'=>'finalize']); ?><p>Review your saved drafts for <?= sef_h($sefTerm['term_text']) ?>. Final submission locks these ratings. Previously submitted evaluations retain their original records.</p>
<?php foreach ($pending as $facultyId=>$record): if (!$record) continue; ?><article class="border rounded p-3 mb-3"><h3 class="fs-6"><?= sef_h($catalog[$facultyId]['faculty_name']) ?></h3><p>Mean: <?= number_format(synk_sef_score((float)$record['total_score'],(int)$record['question_count'])['mean'],2) ?> / 5.00</p><p class="mb-0 sef-comment"><?= nl2br(sef_h($record['comment_text'])) ?></p><?php sef_hidden(['draft_versions['.$facultyId.']'=>synk_sef_review_token($record)]); ?></article><?php endforeach; ?>
<div class="form-check"><input class="form-check-input" type="checkbox" id="sefFinalConfirm" name="confirm_final" value="1" required><label class="form-check-label" for="sefFinalConfirm">I have reviewed these evaluations and confirm final submission.</label></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Review Again</button><button class="btn btn-primary" type="submit" id="sefFinalSubmit"<?= !$writable || !$pending || !$complete?' disabled':'' ?>>Submit Final Evaluations</button></div></form></div></div>
<?php endif; require __DIR__.'/evaluation-page-footer.php'; ?>
