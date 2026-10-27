<?php if (!isset($profilePhotoRequired,$studentDirectoryRecord) || $isAdminPreview) { return; } ?>
<div class="modal fade student-photo-modal" id="studentProfilePhotoModal" tabindex="-1" aria-labelledby="studentPhotoTitle" aria-hidden="true" data-required="<?= $profilePhotoRequired ? '1' : '0' ?>" data-open="<?= $profilePhotoRequired || $profilePhotoError!=='' ? '1' : '0' ?>"<?= $profilePhotoRequired ? ' data-bs-backdrop="static" data-bs-keyboard="false"' : '' ?>>
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
    <form method="post" action="profile.php" enctype="multipart/form-data" id="student-photo-form">
      <div class="modal-header"><h2 class="modal-title fs-5" id="studentPhotoTitle">Update Profile</h2><?php if (!$profilePhotoRequired): ?><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button><?php endif; ?></div>
      <div class="modal-body">
        <?php if ($profilePhotoRequired): ?><p class="student-photo-required-note">Add your profile photo to continue.</p><?php else: ?><p>Choose a new photo to replace your current one.</p><?php endif; ?>
        <div class="student-photo-identity"><strong><?= synk_student_h($studentName) ?></strong><span><?= synk_student_h($studentDirectoryRecord['student_number']) ?></span></div>
        <img class="student-profile-photo student-photo-preview" id="student-photo-preview" src="<?= synk_student_h($profilePhotoUrl ?: 'profile-placeholder.svg') ?>" alt="Profile photo preview">
        <p class="small text-muted">Your name, ID, and program come from the imported student record. Ask your college office if a correction is needed.</p>
        <?php if ($profilePhotoError!==''): ?><div class="alert alert-danger" role="alert"><?= synk_student_h($profilePhotoError) ?></div><?php endif; ?>
        <div class="alert alert-danger" id="student-photo-error" role="alert" hidden></div>
        <input type="hidden" name="csrf_token" value="<?= synk_student_h(synk_student_login_csrf()) ?>">
        <input type="hidden" name="save_student_photo" value="1">
        <input type="hidden" name="MAX_FILE_SIZE" value="5242880">
        <label class="form-label" for="student-profile-photo">Profile photo</label>
        <input class="form-control" type="file" id="student-profile-photo" name="profile_photo" accept="image/jpeg,image/png,image/webp" aria-describedby="student-photo-help" required>
        <p class="form-text" id="student-photo-help">JPG, PNG, or WebP, up to 5 MB and 16 megapixels. Only your latest photo is kept.</p>
      </div>
      <div class="modal-footer"><?php if ($profilePhotoRequired): ?><a href="../logout.php" class="btn btn-outline-secondary">Log out</a><?php else: ?><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><?php endif; ?><button class="btn btn-primary" id="student-photo-save" type="submit"><?= $profilePhotoRequired ? 'Save photo and continue' : 'Save photo' ?></button></div>
    </form>
  </div></div>
</div>
