<?php
// Workload-only meeting details inside each responsive class card.
if (!isset($row) || !is_array($row)) return;
$classMeetings = $row['meetings'] ?? [];
$meetingCount = count($classMeetings);
?>
<div class="workload-subject-overview">
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
