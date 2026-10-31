<?php
$sefPage='report';
require __DIR__.'/../backend/supervisor_evaluation_page.php';
$sefTitle='Individual Faculty Performance Evaluation';
$facultyId=0; $faculty=null; $studentDetail=['categories'=>[],'comments'=>[]];
try {
    $facultyId=synk_sef_int($_GET,'faculty_id');
    $results=synk_sef_performance($conn,$sefScope,$sefTerm,$sefActor);
    if (!isset($results[$facultyId])) throw new OutOfBoundsException('This faculty member is outside your college and selected academic term.');
    $faculty=$results[$facultyId];
    $studentDetail=synk_sef_student_detail($conn,$sefScope,$sefTerm,$facultyId);
} catch (OutOfBoundsException $error) { http_response_code(404); $sefError=$error->getMessage(); }
catch (DomainException $error) { http_response_code(422); $sefError=$error->getMessage(); }
$record=$faculty['supervisor_record'] ?? null; $supervisorCategories=[];
$submittedLabel=$record && $record['submitted_at'] ? (new DateTimeImmutable($record['submitted_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('F j, Y g:i A').' PHT' : '';
if ($record) foreach ($record['answers'] as $question) {
    $key=$question['category_key'];
    if (!isset($supervisorCategories[$key])) $supervisorCategories[$key]=['points'=>0,'answers'=>0];
    $supervisorCategories[$key]['points']+=(int)$question['rating']; $supervisorCategories[$key]['answers']++;
}
require __DIR__.'/evaluation-page-header.php';
if ($faculty): ?>
<div class="d-flex flex-wrap gap-2 mb-3 sef-print-tools"><button class="btn btn-primary" type="button" id="sefPrintReport"><i class="bx bx-printer" aria-hidden="true"></i> Print / Save PDF</button><a class="btn btn-outline-secondary" href="<?= sef_h(sef_url('faculty-performance.php',$sefQuery)) ?>">Back to Results</a></div>
<article class="sef-report card" id="sefIndividualReport"><div class="card-body">
<header class="sef-report-header"><div class="sef-report-logos"><img src="../assets/img/favicon/bagong-pilipinas-logo.png" alt="Bagong Pilipinas" width="66" height="66"><img src="../assets/img/favicon/logo.png" alt="Sultan Kudarat State University" width="66" height="66"></div><div><p class="mb-1">Republic of the Philippines</p><h2>SULTAN KUDARAT STATE UNIVERSITY</h2><p><?= sef_h(($record['context']['campus_name'] ?? $sefScope['campus_name']).' · '.($record['context']['college_name'] ?? $sefScope['college_name'])) ?></p></div></header>
<h1 class="sef-report-title">INDIVIDUAL FACULTY PERFORMANCE EVALUATION</h1><p class="text-center fw-semibold"><?= sef_h($sefTerm['term_text']) ?></p>
<p class="mt-4"><strong>Name of Faculty:</strong> <?= sef_h($record['faculty_name'] ?? $faculty['faculty_name']) ?></p>
<?php if ($record && ($record['context']['academic_rank'] ?? '')!==''): ?><p><strong>Academic Rank:</strong> <?= sef_h($record['context']['academic_rank']) ?></p><?php endif; ?>
<div class="sef-report-totals"><div><h3>Students Rating (60%)</h3><div class="sef-report-score"><span>Mean<strong><?= $faculty['student']['score']?number_format($faculty['student']['score']['mean'],2):'Pending' ?></strong></span><span>Weighted Percentage<strong><?= $faculty['student']['score']?number_format($faculty['student']['score']['component'],2).'%':'Pending' ?></strong></span></div></div><div><h3>Supervisors Rating (40%)</h3><div class="sef-report-score"><span>Mean<strong><?= $faculty['supervisor']?number_format($faculty['supervisor']['mean'],2):'Pending' ?></strong></span><span>Weighted Percentage<strong><?= $faculty['supervisor']?number_format($faculty['supervisor']['component'],2).'%':'Pending' ?></strong></span></div></div><div class="sef-report-total"><span>Total (100%)</span><strong data-sef-total><?= $faculty['total']!==null?number_format($faculty['total'],2).'%':'Pending' ?></strong></div></div>
<?php foreach (['Student Rating'=>$studentDetail['categories'],'Supervisor Rating'=>$supervisorCategories] as $label=>$categories): ?><section class="sef-report-categories"><h3><?= $label ?></h3><div class="sef-category-grid"><?php foreach (synk_sef_categories() as $key=>$category): $value=$categories[$key] ?? null; ?><div><h4><?= sef_h($category['title']) ?></h4><span>Mean</span><strong><?= $value && (int)$value['answers']>0?number_format((float)$value['points']/(int)$value['answers'],2):'Pending' ?></strong></div><?php endforeach; ?><div><h4>Overall Mean</h4><span>Mean</span><strong><?php $score=$label==='Student Rating'?$faculty['student']['score']:$faculty['supervisor']; ?><?= $score?number_format($score['mean'],2):'Pending' ?></strong></div></div></section><?php endforeach; ?>
<section class="mt-4 sef-report-comments"><h3 class="fs-6">COMMENT/S (Unedited)</h3><ol><?php foreach ($studentDetail['comments'] as $comment): ?><li class="mb-2"><?= nl2br(sef_h($comment)) ?></li><?php endforeach; ?><?php if ($record): ?><li><strong><?= $record['evaluator_role']==='college_dean'?'Dean':'Program Chair' ?>:</strong> <?= nl2br(sef_h($record['comment_text'])) ?></li><?php endif; ?></ol><?php if (!$studentDetail['comments'] && !$record): ?><p class="text-muted">No submitted comments available.</p><?php endif; ?></section>
<div class="small text-muted mt-4"><p><?= (int)$faculty['student']['submitted_count'] ?> submitted student response(s); <?= (int)$faculty['student']['included_count'] ?> counted; <?= (int)$faculty['student']['excluded_count'] ?> excluded. <?= (int)$faculty['student']['review_count'] ?> counted response(s) require subject review. Submitted comments remain preserved.</p>
<p>Student component = raw points ÷ maximum points × 60. Supervisor component = raw points ÷ maximum points × 40. Total uses unrounded components. The student and supervisor instruments each contain 15 statements; their original wording is preserved separately.</p>
<?php if ($record): ?><p><strong>Supervisor submitted by:</strong> <?= sef_h($record['evaluator_name']) ?> · <?= $record['evaluator_role']==='college_dean'?'College Dean':'Program Chair' ?><br>Submitted: <?= sef_h($submittedLabel) ?> · Reference: <?= sef_h($record['submission_token']) ?></p><?php endif; ?></div>
<div class="sef-report-signatures"><div><span>Evaluated by:</span><div></div><strong>Chairperson, Faculty Performance Evaluation</strong></div><div><span>Recommending Approval:</span><div></div><strong>College Dean</strong></div><div><span>Approved:</span><div></div><strong>Campus Director</strong></div></div><p class="small text-muted mt-3 mb-0">Signature lines are for approval; displaying this report does not record an approval or electronic signature.</p>
</div></article>
<?php if ($record): ?><details class="card mt-4 sef-print-tools"><summary class="card-body">Submitted supervisor statements and ratings</summary><div class="card-body pt-0"><?php foreach ($record['answers'] as $question): ?><p><strong><?= (int)$question['order'] ?>. <?= sef_h($question['text']) ?></strong><br>Rating: <?= (int)$question['rating'] ?> / 5</p><?php endforeach; ?></div></details><?php endif; ?>
<?php endif; require __DIR__.'/evaluation-page-footer.php'; ?>
