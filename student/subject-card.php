<?php
if (!isset($subject, $subjectCardId)) return;
$scheduleLines = array_values(array_filter(array_map('trim', preg_split('/(?:;|\R)+/u', $subject['schedule_text']))));
if (!$scheduleLines) $scheduleLines = ['Schedule to be posted'];
?>
<article class="student-subject-item" aria-labelledby="<?= synk_student_h($subjectCardId) ?>" data-subject-card data-search="<?= synk_student_h(implode(' ', [$subject['subject_code'],$subject['descriptive_title'],$subject['faculty_name'],$subject['section_display']])) ?>">
  <div class="student-professor-photo" aria-hidden="true">
    <i class="bx bx-user" aria-hidden="true"></i>
    <?php if ($subject['faculty_image_url'] !== ''): ?><img src="<?= synk_student_h($subject['faculty_image_url']) ?>" alt="" width="72" height="72" loading="lazy" decoding="async" onerror="this.remove();"><?php endif; ?>
  </div>
  <div class="student-subject-content">
    <p class="student-professor-name"><span class="visually-hidden">Professor: </span><?= synk_student_h($subject['faculty_name'] ?: 'Instructor to be posted') ?></p>
    <h3 id="<?= synk_student_h($subjectCardId) ?>"><?= synk_student_h($subject['descriptive_title'] ?: 'Subject title to be posted') ?></h3>
    <div class="student-subject-schedule"><i class="bx bx-time-five" aria-hidden="true"></i><div><span class="visually-hidden">Schedule: </span><?php foreach ($scheduleLines as $line): ?><span class="student-schedule-line"><?= synk_student_h($line) ?></span><?php endforeach; ?></div></div>
    <div class="student-subject-meta">
      <span><i class="bx bx-map" aria-hidden="true"></i><span><span class="visually-hidden">Room: </span><?= synk_student_h($subject['room_name'] ?: 'Room to be posted') ?></span></span>
      <?php if ($subject['section_display'] !== ''): ?><span><i class="bx bx-group" aria-hidden="true"></i><span><span class="visually-hidden">Section: </span><?= synk_student_h($subject['section_display']) ?></span></span><?php endif; ?>
      <?php if ($subject['subject_code'] !== ''): ?><span class="student-course-code"><span class="visually-hidden">Subject code: </span><?= synk_student_h($subject['subject_code']) ?></span><?php endif; ?>
    </div>
  </div>
</article>
