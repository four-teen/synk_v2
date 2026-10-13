<?php
// This heading is enabled only by the College Dean profile editor.
if (!isset($facultyNavigation, $facultyId, $profileEditor)) return;
?>
<div class="dean-profile-heading-actions">
  <form id="dean-faculty-switcher" class="dean-faculty-switcher" action="manage-faculty-profile.php" method="get">
    <label for="dean-faculty-select" id="dean-faculty-switcher-label">Switch faculty</label>
    <select id="dean-faculty-select" name="faculty_id" class="form-select" data-current-faculty-id="<?= $facultyId ?>">
      <?php foreach ($facultyNavigation as $navigationFaculty):
          $navigationLabel = $navigationFaculty['faculty_name'];
          if (($navigationFaculty['status'] ?? 'active') !== 'active') {
              $navigationLabel .= ' (' . ucwords(str_replace('_', ' ', (string)$navigationFaculty['status'])) . ')';
          }
      ?>
        <option value="<?= $navigationFaculty['faculty_id'] ?>"<?= $navigationFaculty['faculty_id'] === $facultyId ? ' selected' : '' ?>><?= synk_faculty_profile_h($navigationLabel) ?></option>
      <?php endforeach; ?>
    </select>
    <noscript><button type="submit" class="btn btn-primary mt-2">Open profile</button></noscript>
  </form>
  <a href="<?= synk_faculty_profile_h($profileEditor['back_url']) ?>" class="btn btn-outline-secondary"><i class="bx bx-arrow-back me-2" aria-hidden="true"></i><?= synk_faculty_profile_h($profileEditor['back_label']) ?></a>
</div>
