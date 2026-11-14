<?php
if(!isset($subject,$subjectCardId))return;
$scheduleLines=array_values(array_filter(array_map('trim',preg_split('/(?:;|\R)+/u',$subject['schedule_text']))));
if(!$scheduleLines)$scheduleLines=['Schedule to be posted'];
?>
<article class="card student-class-card" aria-labelledby="<?= synk_student_h($subjectCardId) ?>" data-subject-card data-subject-key="<?= synk_student_h($subject['subject_key']) ?>" data-search="<?= synk_student_h(implode(' ',[$subject['subject_code'],$subject['descriptive_title'],$subject['faculty_name'],$subject['section_display']])) ?>">
  <header class="student-class-card-header" data-header-color="<?= synk_student_h($subjectHeaderColor ?? 'blue') ?>">
    <h3 id="<?= synk_student_h($subjectCardId) ?>" class="student-subject-title" title="<?= synk_student_h(($subject['subject_code']?:'Subject').': '.($subject['descriptive_title']?:'Subject title to be posted')) ?>"><span class="student-class-code"><?= synk_student_h($subject['subject_code']?:'Subject') ?>:</span> <span class="student-class-description"><?= synk_student_h($subject['descriptive_title']?:'Subject title to be posted') ?></span></h3>
  </header>
  <div class="student-class-card-body">
    <div class="student-class-faculty"><div class="student-professor-photo" aria-hidden="true"><i class="bx bx-user" aria-hidden="true"></i><?php if($subject['faculty_image_url']!==''): ?><img src="<?= synk_student_h($subject['faculty_image_url']) ?>" alt="" width="48" height="48" loading="lazy" decoding="async" onerror="this.remove();"><?php endif; ?></div><div><p class="student-professor-name"><?= synk_student_h($subject['faculty_name']?:'Instructor to be posted') ?></p><span class="student-class-label">Professor</span></div></div>
    <details class="student-class-details"><summary>Class details<i class="bx bx-chevron-down" aria-hidden="true"></i></summary><div class="student-class-details-content">
      <p class="student-class-full-title"><?= synk_student_h(($subject['subject_code']?:'Subject').': '.($subject['descriptive_title']?:'Subject title to be posted')) ?></p>
      <div class="student-subject-schedule"><i class="bx bx-time-five" aria-hidden="true"></i><div><?php foreach($scheduleLines as $line): ?><span class="student-schedule-line"><?= synk_student_h($line) ?></span><?php endforeach; ?></div></div>
      <div class="student-subject-meta"><span><i class="bx bx-map" aria-hidden="true"></i><span><?= synk_student_h($subject['room_name']?:'Room to be posted') ?></span></span><?php if($subject['section_display']!==''): ?><span><i class="bx bx-group" aria-hidden="true"></i><span><?= synk_student_h($subject['section_display']) ?></span></span><?php endif; ?></div>
    </div></details>
  </div>
  <div class="student-class-card-footer">
    <div class="student-class-summary" data-subject-count role="status" aria-live="polite">Loading assessments...</div>
    <button type="button" class="btn btn-outline-primary" data-open-subject="<?= synk_student_h($subject['subject_key']) ?>" aria-label="<?= synk_student_h('View assessments for '.$subject['subject_code'].' with '.($subject['faculty_name']?:'your professor')) ?>">View activities<i class="bx bx-right-arrow-alt" aria-hidden="true"></i></button>
  </div>
</article>
