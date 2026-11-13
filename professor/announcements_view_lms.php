<?php
if (!isset($class,$announcementsReady) || $view !== 'announcements' || ($_SESSION['role'] ?? '') !== 'professor') { http_response_code(404); return; }
?>
<section class="card class-lms-panel" aria-labelledby="announcementTitle" id="professorAnnouncements">
  <div class="class-lms-panel-head"><div><h2 id="announcementTitle">Announcements</h2><p>Post a notice for the students enrolled in this class.</p></div><a class="btn btn-outline-secondary" href="<?= synk_professor_h(synk_professor_class_url_lms($class)) ?>">Back to overview</a></div>
  <p class="announcement-info"><i class="bx bx-info-circle" aria-hidden="true"></i>Published activities appear automatically on students' dashboards. Use this space for other class updates.</p>
  <?php if (!$announcementsReady): ?>
    <p class="alert alert-info" role="status">Announcements are temporarily unavailable. Please contact your administrator.</p>
  <?php else: ?>
    <div class="announcement-workspace">
      <form id="announcementForm" class="announcement-editor">
        <h3 id="announcementEditorTitle">New announcement</h3>
        <p class="text-muted">Posting makes this notice visible to your class immediately.</p>
        <label class="form-label" for="announcementSubject">Title</label><input class="form-control mb-3" id="announcementSubject" maxlength="200" required autocomplete="off">
        <label class="form-label" for="announcementBody">Message</label><textarea class="form-control mb-3" id="announcementBody" rows="7" maxlength="10000" required></textarea>
        <div class="announcement-actions"><button type="submit" class="btn btn-primary" id="announcementPost">Post announcement</button><button type="button" class="btn btn-outline-secondary" id="announcementCancel" hidden>Cancel edit</button></div>
        <p id="announcementFormStatus" class="mt-2" role="status" aria-live="polite"></p>
      </form>
      <div class="announcement-history"><div class="announcement-list-head"><h3>Class notices</h3><button class="btn btn-sm btn-outline-secondary" type="button" id="announcementRefresh">Refresh</button></div><div id="announcementList" aria-live="polite"><p>Loading announcements...</p></div></div>
    </div>
    <script id="announcementConfig" type="application/json"><?= json_encode(['endpoint'=>'announcements_api_lms.php','csrf_token'=>$_SESSION['lms_announcement_csrf'],'context'=>array_intersect_key($_GET,array_flip(['offering_id','ay_id','semester','view','signature']))],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
  <?php endif; ?>
</section>
