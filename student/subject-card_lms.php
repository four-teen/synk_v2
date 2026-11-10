<?php
if(!isset($subject,$subjectCardId))return;
$scheduleLines=array_values(array_filter(array_map('trim',preg_split('/(?:;|\R)+/u',$subject['schedule_text']))));
if(!$scheduleLines)$scheduleLines=['Schedule to be posted'];
?>
<article class="card student-class-card" aria-labelledby="<?= synk_student_h($subjectCardId) ?>" data-subject-card data-subject-key="<?= synk_student_h($subject['subject_key']) ?>" data-search="<?= synk_student_h(implode(' ',[$subject['subject_code'],$subject['descriptive_title'],$subject['faculty_name'],$subject['section_display']])) ?>">
  <div class="student-class-card-body">
    <div class="student-class-card-top"><p class="student-class-code"><?= synk_student_h($subject['subject_code']?:'Subject') ?></p></div>
    <h3 id="<?= synk_student_h($subjectCardId) ?>"><?= synk_student_h($subject['descriptive_title']?:'Subject title to be posted') ?></h3>
    <div class="student-class-faculty"><div class="student-professor-photo" aria-hidden="true"><i class="bx bx-user" aria-hidden="true"></i><?php if($subject['faculty_image_url']!==''): ?><img src="<?= synk_student_h($subject['faculty_image_url']) ?>" alt="" width="48" height="48" loading="lazy" decoding="async" onerror="this.remove();"><?php endif; ?></div><div><span class="student-class-label">Professor</span><p class="student-professor-name"><?= synk_student_h($subject['faculty_name']?:'Instructor to be posted') ?></p></div></div>
    <div class="student-subject-schedule"><i class="bx bx-time-five" aria-hidden="true"></i><div><?php foreach($scheduleLines as $line): ?><span class="student-schedule-line"><?= synk_student_h($line) ?></span><?php endforeach; ?></div></div>
    <div class="student-subject-meta"><span><i class="bx bx-map" aria-hidden="true"></i><span><?= synk_student_h($subject['room_name']?:'Room to be posted') ?></span></span><?php if($subject['section_display']!==''): ?><span><i class="bx bx-group" aria-hidden="true"></i><span><?= synk_student_h($subject['section_display']) ?></span></span><?php endif; ?></div>
  </div>
  <div class="student-class-card-footer">
    <button type="button" class="btn btn-outline-primary" data-open-subject="<?= synk_student_h($subject['subject_key']) ?>" aria-label="<?= synk_student_h('View assessments for '.$subject['subject_code'].' with '.($subject['faculty_name']?:'your professor')) ?>">View assessments<i class="bx bx-right-arrow-alt" aria-hidden="true"></i></button>
    <div class="student-class-summary" data-subject-count role="status" aria-live="polite">Loading assessments...</div>
  </div>
</article>
