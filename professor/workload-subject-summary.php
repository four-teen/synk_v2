<?php
// Workload-only subject details inside each responsive class card.
if (!isset($row) || !is_array($row)) return;
$classMeetings = $row['meetings'] ?? [];
$meetingCount = count($classMeetings);
?>
<div class="workload-subject-overview">
  <div class="workload-class-reference">
    <span class="workload-class-code"><?= synk_professor_h($row['subject_code'] ?: 'Subject') ?></span>
    <span class="workload-class-section"><span class="visually-hidden">Section: </span><?= synk_professor_h($row['section_display'] ?: 'Section pending') ?></span>
  </div>
  <h3 class="workload-class-title"><?= implode('/<wbr>', array_map('synk_professor_h', explode('/', $row['descriptive_title'] ?: 'Subject title unavailable'))) ?></h3>
  <details class="workload-meeting-details">
    <summary aria-label="<?= synk_professor_h('Schedule & rooms for '.$row['subject_code'].' '.$row['section_display']) ?>">
      <i class="bx bx-time-five" aria-hidden="true"></i><span>Schedule &amp; rooms</span>
      <span class="workload-meeting-count"><?= $meetingCount ? $meetingCount.' '.($meetingCount === 1 ? 'meeting' : 'meetings') : 'To be posted' ?></span>
      <i class="bx bx-chevron-down workload-meeting-chevron" aria-hidden="true"></i>
    </summary>
    <div class="workload-meeting-content">
      <?php if ($classMeetings): ?>
        <ul class="workload-meeting-list">
          <?php foreach ($classMeetings as $meeting): ?>
            <li>
              <span class="workload-meeting-type"><?= synk_professor_h($meeting['type_label'] ?: 'Class') ?></span>
              <div class="workload-meeting-info">
                <span class="workload-meeting-days"><?= synk_professor_h($meeting['days'] ?: 'Days to be arranged') ?></span>
                <span class="workload-meeting-time"><?= synk_professor_h($meeting['time'] ?: 'Time to be arranged') ?></span>
                <span class="workload-meeting-room"><i class="bx bx-map" aria-hidden="true"></i><?= synk_professor_h($meeting['room'] ?: 'Room to be assigned') ?></span>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="workload-meeting-pending">Schedule to be posted.</p>
        <p class="workload-meeting-room"><i class="bx bx-map" aria-hidden="true"></i><?= synk_professor_h($row['room_name'] ?: 'Room to be assigned') ?></p>
      <?php endif; ?>
    </div>
  </details>
</div>
