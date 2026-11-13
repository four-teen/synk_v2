<?php
if (!isset($announcements,$isPreview) || $isPreview || ($_SESSION['role'] ?? '') !== 'student') { http_response_code(404); return; }
$unreadAnnouncements = array_values(array_filter($announcements,static function(array $row): bool { return $row['unread']; }));
$announcementDate = static function(?string $date): string {
    return $date === null ? '' : (new DateTimeImmutable($date,new DateTimeZone('Asia/Manila')))->format('M j, Y · g:i A');
};
?>
<section class="card student-announcement-panel" aria-labelledby="announcements-title" id="studentAnnouncements">
  <div class="student-subject-toolbar"><div><h2 id="announcements-title" tabindex="-1">Announcements <span class="badge bg-label-primary" id="announcementUnreadCount"<?= !$unreadAnnouncements ? ' hidden' : '' ?>><?= count($unreadAnnouncements) ?> new</span></h2><p>Class notices and automatically posted activity updates. Times are Philippine time.</p></div><span class="student-announcement-icon" aria-hidden="true"><i class="bx bx-bell"></i></span></div>
  <?php if ($announcementError !== ''): ?><p class="alert alert-info m-3" role="status"><?= synk_student_h($announcementError) ?></p>
  <?php elseif (!$announcements): ?>
    <div class="student-empty-state"><span class="student-empty-icon"><i class="bx bx-bell" aria-hidden="true"></i></span><h3>No announcements yet</h3><p>Your professors' notices and published activities will appear here.</p><a class="btn btn-outline-primary" href="assessments_lms.php">View my subjects</a></div>
  <?php else: ?>
    <div class="student-update-toolbar"><p>Latest <?= count($announcements) ?> class update<?= count($announcements)===1?'':'s' ?></p><?php if ($announcementReadsReady): ?><button class="btn btn-sm btn-outline-primary" type="button" id="announcementMarkRead"<?= !$unreadAnnouncements ? ' hidden' : '' ?>>Mark all as read</button><?php endif; ?></div>
    <p class="student-update-status" id="announcementReadStatus" role="status" aria-live="polite"></p>
    <div class="student-update-list">
      <?php foreach ($announcements as $notice): ?>
      <article class="student-update<?= $notice['unread'] ? ' is-unread' : '' ?>" data-notice-key="<?= synk_student_h($notice['event_key']) ?>">
        <div class="student-update-meta"><span class="badge <?= $notice['kind']==='announcement'?'bg-label-primary':'bg-label-success' ?>"><?= $notice['kind']==='announcement'?'Class notice':synk_student_h(ucfirst($notice['kind']).' published') ?></span><span class="student-update-new"<?= !$notice['unread']?' hidden':'' ?>>New</span><time datetime="<?= synk_student_h(str_replace(' ','T',$notice['event_at']).'+08:00') ?>"><?= synk_student_h($announcementDate($notice['event_at'])) ?></time></div>
        <p class="student-update-subject"><?= synk_student_h($notice['subject_label']) ?> <span>· <?= synk_student_h($notice['faculty_name']) ?></span></p>
        <h3><?= synk_student_h($notice['title']) ?></h3>
        <?php if ($notice['kind']==='announcement'): ?><p class="student-update-message"><?= synk_student_h($notice['body']) ?></p>
        <?php else: ?><p>Your professor published a new <?= synk_student_h($notice['kind']) ?> for this class.</p>
        <?php if ($notice['opens_at'] !== null): ?><p class="student-update-schedule">Opens <?= synk_student_h($announcementDate($notice['opens_at'])) ?></p><?php endif; ?>
        <?php if ($notice['due_at'] !== null): ?><p class="student-update-schedule">Due <?= synk_student_h($announcementDate($notice['due_at'])) ?></p><?php endif; ?>
        <?php if ($notice['status']==='closed'): ?><p class="student-update-schedule">This activity is now closed.</p><?php endif; ?>
        <a class="btn btn-sm btn-outline-primary mt-2" href="<?= synk_student_h($notice['url']) ?>">View activity <i class="bx bx-right-arrow-alt" aria-hidden="true"></i></a><?php endif; ?>
      </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php if ($announcementReadsReady && $announcements): ?>
<div class="modal fade" id="studentUpdatesModal" tabindex="-1" aria-labelledby="studentUpdatesTitle" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
  <div class="modal-header"><h2 class="modal-title fs-5" id="studentUpdatesTitle">New class updates</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body"><p>You have <?= count($unreadAnnouncements) ?> unread class update<?= count($unreadAnnouncements)===1?'':'s' ?>. Full notices are on your dashboard.</p><ul class="student-update-alert-list">
    <?php foreach ($unreadAnnouncements as $notice): ?><li><strong><?= synk_student_h($notice['title']) ?></strong><span><?= synk_student_h($notice['subject_label']) ?></span><small><?= synk_student_h($announcementDate($notice['event_at'])) ?></small></li><?php endforeach; ?>
  </ul><p class="text-muted mb-0">Closing this alert marks these updates as read. They remain on your dashboard.</p></div>
  <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Dismiss</button><button class="btn btn-primary" type="button" data-bs-dismiss="modal" id="announcementViewBoard">View announcements</button></div>
</div></div></div>
<script id="studentAnnouncementConfig" type="application/json"><?= json_encode(['endpoint'=>'announcements_api_lms.php','csrf_token'=>$_SESSION['student_announcement_csrf'],'unread_keys'=>array_column($unreadAnnouncements,'event_key')],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<?php endif; ?>
