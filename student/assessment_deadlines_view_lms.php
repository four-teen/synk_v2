<?php
if (!isset($assessmentDeadlines,$assessmentDeadlineError,$isPreview) || $isPreview || ($_SESSION['role']??'')!=='student') { http_response_code(404); return; }
?>
<section class="card student-deadline-panel" id="studentDeadlinePanel" aria-labelledby="studentDeadlineTitle">
  <div class="student-deadline-toolbar"><div><h2 id="studentDeadlineTitle"><i class="bx bx-calendar-event" aria-hidden="true"></i>Assessment deadlines</h2><p>Pending deadlines are red. Submitted work turns green. All times are Philippine time.</p></div><button type="button" class="btn btn-outline-primary" id="studentDeadlineRefresh">Refresh deadlines</button></div>
  <p class="student-deadline-error" id="studentDeadlineError" role="status"><?= synk_student_h($assessmentDeadlineError) ?></p>
  <ul class="student-deadline-list" id="studentDeadlineList">
    <?php foreach ($assessmentDeadlines as $notice): ?>
    <li class="student-deadline-item<?= $notice['is_complete']?' is-complete':'' ?><?= $notice['is_today']?' is-today':'' ?>" data-deadline-assessment="<?= $notice['assessment_id'] ?>">
      <div class="student-deadline-content"><a class="student-deadline-title" href="assessments_lms.php#assessment-<?= $notice['assessment_id'] ?>"><?= synk_student_h($notice['title']) ?></a><p class="student-deadline-subject"><?= synk_student_h($notice['subject_label']) ?></p><p class="student-deadline-notice"><strong><?= synk_student_h($notice['notice_label']) ?></strong> &middot; <time datetime="<?= synk_student_h(str_replace(' ','T',$notice['deadline_at']).'+08:00') ?>"><?= synk_student_h($notice['deadline_label']) ?></time></p></div>
      <span class="student-deadline-status"><i class="bx <?= $notice['is_complete']?'bx-check-circle':'bx-time-five' ?>" aria-hidden="true"></i><?= synk_student_h($notice['status_label']) ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
  <p class="student-deadline-empty" id="studentDeadlineEmpty"<?= $assessmentDeadlines||$assessmentDeadlineError!==''?' hidden':'' ?>>No assessment deadlines for your current subjects yet.</p>
  <div class="student-deadline-footer"><a href="assessments_lms.php">View all activities &amp; assessments<i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a></div>
</section>
<script id="studentDeadlineConfig" type="application/json"><?= json_encode(['csrf_token'=>$_SESSION['student_assessment_csrf']],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
