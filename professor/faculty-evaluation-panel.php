<?php
// Included only after an authenticated class-detail read; no standalone access.
if (!isset($evaluationDetail,$evaluationView)) { http_response_code(404); exit; }
?>
<?php if ($evaluationView==='pending'): ?>
  <div class="evaluation-panel-heading"><div><h3>Students not yet evaluated</h3><p><?= count($evaluationDetail['students']) ?> students · <?= synk_professor_evaluation_h($evaluationDetail['class']['subject_code'].' · '.$evaluationDetail['class']['section_display']) ?></p></div></div>
  <?php if (!$evaluationDetail['students']): ?><p class="evaluation-panel-empty" role="status">All students on this class roster have submitted their evaluation.</p>
  <?php else: ?>
  <div class="evaluation-pending-list"><table class="table"><caption class="visually-hidden">Students who have not submitted an evaluation for this professor in the current college and term</caption><thead><tr><th scope="col">Student number</th><th scope="col">Student name</th></tr></thead><tbody>
    <?php foreach ($evaluationDetail['students'] as $student): ?><tr><td><?= synk_professor_evaluation_h($student['student_number']) ?></td><td><?= synk_professor_evaluation_h($student['full_name']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
<?php else: ?>
  <?php if ($evaluationView==='consolidated'): ?>
  <p class="evaluation-panel-note">Only submitted responses linked to your assigned subjects and current class rosters are included. A response shared across subject cards contributes once to these totals and comments. Questionnaire versions are reported separately.</p>
  <?php else: ?>
  <div class="evaluation-panel-heading"><div><h3>Subject evaluation results</h3><p><?= synk_professor_evaluation_h($evaluationDetail['class']['subject_code'].' · '.$evaluationDetail['class']['section_display']) ?></p></div></div>
  <p class="evaluation-panel-note">Only submitted responses linked to this subject from students on this class roster are included.</p>
  <?php endif; ?>
  <?php if ($evaluationDetail['combined_count']): ?><p class="evaluation-panel-note evaluation-combined-note"><?= (int)$evaluationDetail['combined_count'] ?> submitted <?= $evaluationDetail['combined_count']===1?'response also covers':'responses also cover' ?> other subjects. These responses retain their original combined ratings; separate ratings for each subject were not collected.</p><?php endif; ?>
  <?php if (!$evaluationDetail['groups']): ?><p class="evaluation-panel-empty" role="status">No submitted results can be linked to this subject and class roster yet. Responses with missing or ambiguous subject associations are not included.</p><?php endif; ?>
  <?php foreach ($evaluationDetail['groups'] as $group): ?>
  <section class="evaluation-instrument" data-professor-evaluation-instrument="<?= synk_professor_evaluation_h($group['instrument_version']) ?>">
    <h4><?= synk_professor_evaluation_h($group['instrument']['label']) ?></h4>
    <div class="d-flex flex-wrap gap-2 mb-3"><span class="badge bg-label-primary"><?= (int)$group['response_count'] ?> evaluations included in score</span><?php if ($group['score']): ?><span class="badge bg-label-success"><?= number_format($group['score']['percentage'],2) ?>% normalized score</span><span class="badge bg-label-info">Mean <?= number_format($group['score']['mean'],2) ?> / 5.00</span><?php else: ?><span class="text-muted">No eligible evaluations</span><?php endif; ?></div>
    <p class="evaluation-panel-note">Normalized score = total rating points ÷ maximum possible points × 100. Questions have equal weight. Only final results are rounded.</p>
    <details class="mb-4"><summary class="text-primary">Rating scale</summary><ul class="mt-2 mb-0"><?php foreach (synk_student_faculty_evaluation_scale($group['instrument_version']) as $rating=>$definition): ?><li><strong><?= (int)$rating ?> · <?= synk_professor_evaluation_h($definition['label']) ?></strong>: <?= synk_professor_evaluation_h($definition['description']) ?></li><?php endforeach; ?></ul></details>
    <?php foreach ($group['categories'] as $category): ?>
    <section class="mb-4"><h5><?= synk_professor_evaluation_h($category['title']) ?></h5><p class="evaluation-panel-note">Mean <?= number_format($category['score']['mean'],2) ?> / 5.00 · <?= number_format($category['score']['percentage'],2) ?>% of maximum category points</p>
      <div class="table-responsive" tabindex="0" role="region" aria-label="<?= synk_professor_evaluation_h($category['title'].' statement scores') ?>"><table class="table evaluation-score-table"><thead><tr><th scope="col">Statement</th><th scope="col">Mean</th><th scope="col">Normalized score</th><th scope="col">Responses</th></tr></thead><tbody>
      <?php foreach ($category['questions'] as $question): ?><tr><td><?= (int)$question['order'] ?>. <?= synk_professor_evaluation_h($question['text']) ?></td><td><?= number_format($question['score']['mean'],2) ?></td><td><?= number_format($question['score']['percentage'],2) ?>%</td><td><?= (int)$question['response_count'] ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
    </section><?php endforeach; ?>
    <h5>Student comments</h5>
    <?php if ($group['submitted_count']>$group['response_count']): ?><p class="evaluation-panel-note">Submitted comments are retained independently of score eligibility.</p><?php endif; ?>
    <?php foreach ($group['comments'] as $comment): ?><p class="evaluation-comment"><?= nl2br(synk_professor_evaluation_h($comment)) ?></p><?php endforeach; ?>
    <?php if (!$group['comments']): ?><p class="evaluation-panel-note">No comments were submitted.</p><?php endif; ?>
  </section><?php endforeach; ?>
<?php endif; ?>
