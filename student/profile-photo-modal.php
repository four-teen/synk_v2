<?php if (!isset($profilePhotoRequired,$studentDirectoryRecord) || $isAdminPreview) { return; } ?>
<div class="modal fade student-photo-modal" id="studentProfilePhotoModal" tabindex="-1" aria-labelledby="studentPhotoTitle" aria-hidden="true" data-required="<?= $profilePhotoRequired ? '1' : '0' ?>" data-open="<?= $profilePhotoRequired || $profilePhotoError!=='' ? '1' : '0' ?>"<?= $profilePhotoRequired ? ' data-bs-backdrop="static" data-bs-keyboard="false"' : '' ?>>
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <form method="post" action="profile.php" enctype="multipart/form-data" id="student-photo-form">
      <div class="modal-header"><h2 class="modal-title fs-5" id="studentPhotoTitle">Take your profile photo</h2><?php if (!$profilePhotoRequired): ?><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button><?php endif; ?></div>
      <div class="modal-body">
        <?php if ($profilePhotoRequired): ?><p class="student-photo-required-note">Add your profile photo to continue.</p><?php else: ?><p>Take a new photo to replace your current one.</p><?php endif; ?>
        <div class="student-photo-identity"><strong><?= synk_student_h($studentName) ?></strong><span><?= synk_student_h($studentDirectoryRecord['student_number']) ?></span></div>
        <p class="student-photo-instructions" id="student-photo-help">Keep your whole face inside the guide and clearly visible. Only one person should be in the frame. Any background is allowed.</p>
        <div class="student-camera-frame" id="student-camera-frame">
          <img id="student-photo-preview" src="<?= synk_student_h($profilePhotoUrl ?: 'profile-placeholder.svg') ?>" alt="Profile photo preview">
          <video id="student-photo-video" autoplay muted playsinline aria-label="Live camera preview" hidden></video>
          <div class="student-camera-guide" id="student-camera-guide" aria-hidden="true" hidden></div>
        </div>
        <p class="student-camera-status" id="student-camera-status" role="status" aria-live="polite">Open your camera to take a new photo.</p>
        <div class="student-camera-actions">
          <button class="btn btn-outline-primary" type="button" id="student-camera-open"><i class="bx bx-camera me-1" aria-hidden="true"></i>Open camera</button>
          <button class="btn btn-primary" type="button" id="student-camera-capture" hidden disabled>Take photo</button>
          <button class="btn btn-outline-secondary" type="button" id="student-camera-retake" hidden>Retake photo</button>
        </div>
        <p class="form-text">Use your phone camera or a computer webcam. If a camera is unavailable or checks keep failing, ask your college office for assisted capture. Only your latest saved photo is kept.</p>
        <?php if ($profilePhotoError!==''): ?><div class="alert alert-danger" role="alert"><?= synk_student_h($profilePhotoError) ?></div><?php endif; ?>
        <div class="alert alert-danger" id="student-photo-error" role="alert" hidden></div>
        <input type="hidden" name="csrf_token" value="<?= synk_student_h(synk_student_login_csrf()) ?>">
        <input type="hidden" name="save_student_photo" value="1">
        <noscript><div class="alert alert-warning">Enable JavaScript to capture and save your profile photo.</div></noscript>
      </div>
      <div class="modal-footer"><?php if ($profilePhotoRequired): ?><a href="../logout.php" class="btn btn-outline-secondary">Log out</a><?php else: ?><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><?php endif; ?><button class="btn btn-primary" id="student-photo-save" type="submit" disabled><?= $profilePhotoRequired ? 'Save photo and continue' : 'Save photo' ?></button></div>
    </form>
  </div></div>
</div>
