<?php // Used by the desktop row and mobile card for the same assigned class. ?>
<div class="workload-subject-summary">
  <div class="workload-subject-heading">
    <span class="workload-subject-code"><?= synk_professor_h($row['subject_code'] ?: 'Subject') ?></span>
    <strong class="workload-subject-title"><?= implode('/<wbr>', array_map('synk_professor_h', explode('/', $row['descriptive_title'] ?: 'Subject title unavailable'))) ?></strong>
  </div>
  <div class="workload-summary-schedule"><i class="bx bx-time-five" aria-hidden="true"></i><div>
    <?php foreach ($row['meetings'] as $meeting): ?>
      <span><b><?= synk_professor_h($meeting['type'] ?: 'Class') ?></b> <?= synk_professor_h($meeting['days'] ?: 'Days to be arranged') ?> <span class="workload-summary-time"><?= synk_professor_h($meeting['time'] ?: 'Time to be arranged') ?></span></span>
    <?php endforeach; ?>
  </div></div>
  <div class="workload-summary-meta">
    <span><i class="bx bx-map" aria-hidden="true"></i><?= synk_professor_h($row['room_name']) ?></span>
    <span><i class="bx bx-group" aria-hidden="true"></i><?= synk_professor_h($row['section_display'] ?: 'Section pending') ?></span>
  </div>
</div>
