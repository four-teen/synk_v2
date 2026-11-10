<?php
if (!isset($class, $overviewSummary) || $view !== 'overview' || ($_SESSION['role'] ?? '') !== 'professor') { http_response_code(404); return; }
$classTools = [
    ['key'=>'students','title'=>'Students','icon'=>'bx-group','status'=>'Available','view'=>'students','purpose'=>'View the students enrolled in this subject and section.'],
    ['key'=>'activities','title'=>'Activities & assessments','icon'=>'bx-task','status'=>'Draft builder','view'=>'assessments','purpose'=>'Prepare quizzes, exams, assignments, and answer keys.'],
    ['key'=>'grading','title'=>'Manage Grading System','icon'=>'bx-slider-alt','status'=>'Available','view'=>'grading','purpose'=>'Set the grading components and percentage weights for each period.'],
    ['key'=>'gradebook','title'=>'Grade Book','icon'=>'bx-spreadsheet','status'=>'Planned','view'=>null,'purpose'=>'Record scores and calculate grades using your grading components.'],
    ['key'=>'materials','title'=>'Learning materials','icon'=>'bx-folder-open','status'=>'Planned','view'=>null,'purpose'=>'Organize modules, lesson notes, and learning resources.'],
    ['key'=>'announcements','title'=>'Announcements','icon'=>'bx-bell','status'=>'Planned','view'=>null,'purpose'=>'Post class updates, reminders, and important notices.']
];
?>
<section class="class-lms-tools" aria-labelledby="class-lms-tools-title">
  <div class="class-lms-tools-heading"><h2 id="class-lms-tools-title">Manage this subject</h2><p>Your class roster, assessments, grading, and learning tools in one place.</p></div>
  <div class="class-lms-tool-grid">
    <?php foreach ($classTools as $tool): $toolId = 'class-tool-' . $tool['key']; ?>
      <article class="card class-lms-tool class-lms-tool-<?= $tool['key'] ?>" data-class-tool="<?= $tool['key'] ?>">
        <div class="class-lms-tool-top"><span class="class-lms-card-icon class-lms-icon-<?= $tool['key'] ?>"><i class="bx <?= $tool['icon'] ?>" aria-hidden="true"></i></span><span class="class-lms-tool-status<?= $tool['view'] ? ' class-lms-tool-available' : '' ?>"><?= $tool['status'] ?></span></div>
        <h3 id="<?= $toolId ?>">
          <?php if ($tool['view']): ?>
            <a class="class-lms-tool-link" href="<?= synk_professor_h(synk_professor_class_url_lms($class,$tool['view'])) ?>" aria-describedby="<?= $toolId ?>-footer"><?= synk_professor_h($tool['title']) ?><span class="visually-hidden"> — <?= synk_professor_h($tool['purpose']) ?></span></a>
          <?php else: ?>
            <button type="button" class="class-lms-tool-link" data-planned-tool data-title="<?= synk_professor_h($tool['title']) ?>" data-purpose="<?= synk_professor_h($tool['purpose']) ?>" aria-describedby="<?= $toolId ?>-footer"><?= synk_professor_h($tool['title']) ?></button>
          <?php endif; ?>
          <i class="bx <?= $tool['view'] ? 'bx-right-arrow-alt' : 'bx-info-circle' ?> class-lms-tool-direction" aria-hidden="true"></i>
        </h3>
        <div class="class-lms-tool-footer" id="<?= $toolId ?>-footer">
          <?php if ($tool['key'] === 'students'): ?>
            <span class="class-lms-tool-metric"><strong><?= number_format((int)$class['student_count']) ?></strong><span>enrolled <?= (int)$class['student_count'] === 1 ? 'student' : 'students' ?></span></span>
          <?php elseif ($tool['key'] === 'activities' && $overviewSummary['assessment_count'] !== null): ?>
            <span class="class-lms-tool-metric"><strong><?= number_format($overviewSummary['assessment_count']) ?></strong><span><?= $overviewSummary['assessment_count'] === 1 ? 'assessment' : 'assessments' ?> created</span></span>
          <?php elseif ($tool['key'] === 'grading' && $overviewSummary['grading_periods'] !== null): ?>
            <div class="class-lms-tool-periods">
              <?php foreach (['midterm'=>'Midterm','final'=>'Final'] as $period=>$label): $isSet = $overviewSummary['grading_periods'][$period]; ?>
                <span class="class-lms-tool-period"><span><?= $label ?></span><strong class="<?= $isSet ? 'is-set' : 'is-not-set' ?>"><?= $isSet ? 'Set' : 'Not yet set' ?></strong></span>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <span class="class-lms-tool-purpose"><?= synk_professor_h($tool['purpose']) ?></span>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</section>
