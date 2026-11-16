<?php
if (!isset($profileEditor) || $profileEditor['training_state'] === null) {http_response_code(404); return;}
$trainingView = $profileEditor['training_state'];
$trainingRecord = $trainingView['record'];
?>
<div class="profile-trainings profile-researches" data-trainings>
  <div class="profile-research-heading">
    <div><span class="profile-eyebrow">TRAINING PORTFOLIO</span><h2>Training records <span class="profile-research-count"><?= count($trainingView['records']) ?></span></h2><p>Keep a record of your training and professional development.</p></div>
    <a class="btn btn-primary" href="manage-profile.php?tab=trainings&amp;training=new#training-editor" data-training-add><i class="bx bx-plus" aria-hidden="true"></i>Add training</a>
  </div>
  <?php if ($trainingView['notice'] !== ''): ?><div class="alert alert-success" role="status"><?= synk_faculty_profile_h($trainingView['notice']) ?></div><?php endif; ?>
  <?php if ($trainingView['error'] !== '' || $trainingView['errors']): ?>
    <div class="alert alert-danger" role="alert" tabindex="-1" id="training-errors">
      <?php if ($trainingView['error'] !== ''): ?><p class="mb-0"><?= synk_faculty_profile_h($trainingView['error']) ?></p><?php endif; ?>
      <?php if ($trainingView['errors']): ?><strong>Please review the training details:</strong><ul class="mb-0 mt-2"><?php foreach ($trainingView['errors'] as $key=>$error): ?><li><a href="#training_<?= synk_faculty_profile_h($key) ?>"><?= synk_faculty_profile_h($error) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
  <?php endif; ?>

  <section class="card profile-research-editor" id="training-editor" aria-labelledby="training-editor-title"<?= !$trainingView['show_editor']?' hidden':'' ?>>
    <div class="profile-research-editor-heading"><span class="profile-research-icon"><i class="bx bx-briefcase-alt" aria-hidden="true"></i></span><div><h3 id="training-editor-title"><?= (int)$trainingRecord['training_id']>0?'Edit training record':'Add training record' ?></h3><p>Fields marked <span class="text-danger">*</span> are required. For online training, enter the platform as the venue.</p></div></div>
    <form id="faculty-training-form" action="manage-profile.php?tab=trainings" method="post" data-has-errors="<?= $trainingView['errors']||$trainingView['error']!==''?'1':'0' ?>">
      <input type="hidden" name="profile_action" value="training_save">
      <input type="hidden" name="csrf_token" value="<?= synk_faculty_profile_h($profileEditor['csrf_token']) ?>">
      <input type="hidden" name="profile_owner" value="<?= synk_faculty_profile_h($profileOwner) ?>">
      <input type="hidden" name="training_id" value="<?= (int)$trainingRecord['training_id'] ?>">
      <input type="hidden" name="training_revision" value="<?= (int)$trainingRecord['revision'] ?>">
      <div class="row g-4">
        <?php foreach (synk_faculty_profile_training_fields() as $key=>$field): $fieldError=$trainingView['errors'][$key]??''; $fieldId='training_'.$key; ?>
          <div class="<?= in_array($key,['training_title','agency'],true)?'col-12':'col-md-6' ?>">
            <label class="form-label" for="<?= $fieldId ?>"><?= synk_faculty_profile_h($field['label']) ?> <span class="text-danger" aria-hidden="true">*</span></label>
            <?php if (isset($field['options'])): ?>
              <select id="<?= $fieldId ?>" name="<?= $key ?>" class="form-select<?= $fieldError!==''?' is-invalid':'' ?>" required<?= $fieldError!==''?' aria-invalid="true" aria-describedby="'.$fieldId.'-error"':'' ?>>
                <option value="">Select mode</option>
                <?php foreach ($field['options'] as $option): ?><option value="<?= $option ?>"<?= $trainingRecord[$key]===$option?' selected':'' ?>><?= $option ?></option><?php endforeach; ?>
              </select>
            <?php else: ?>
              <input type="<?= $field['type']??'text' ?>" id="<?= $fieldId ?>" name="<?= $key ?>" class="form-control<?= $fieldError!==''?' is-invalid':'' ?>" value="<?= synk_faculty_profile_h($trainingRecord[$key]??'') ?>" required<?= isset($field['max'])?' maxlength="'.$field['max'].'"':'' ?><?= ($field['type']??'')==='date'?' min="1900-01-01"':'' ?><?= $fieldError!==''?' aria-invalid="true" aria-describedby="'.$fieldId.'-error"':'' ?>>
            <?php endif; ?>
            <?php if ($fieldError!==''): ?><div class="invalid-feedback" id="<?= $fieldId ?>-error"><?= synk_faculty_profile_h($fieldError) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="profile-research-form-footer"><span id="training-save-status" role="status" aria-live="polite">Each training record is saved separately.</span><div><a href="manage-profile.php?tab=trainings" class="btn btn-outline-secondary" data-training-cancel>Cancel</a><button type="submit" class="btn btn-primary" id="training-save-button"><i class="bx bx-save" aria-hidden="true"></i>Save training</button></div></div>
    </form>
  </section>

  <div class="card profile-research-table-card">
    <div class="profile-research-table-scroll" role="region" aria-label="Training records table" tabindex="0">
      <table class="profile-research-table profile-training-table">
        <caption class="visually-hidden">Saved training records, agencies, dates, venues, modes, and actions</caption>
        <thead><tr><th scope="col">Training title</th><th scope="col">Agency/Entity</th><th scope="col">Date from</th><th scope="col">Date to</th><th scope="col">Venue</th><th scope="col">Mode</th><th scope="col">Actions</th></tr></thead>
        <tbody>
        <?php if (!$trainingView['records']): ?>
          <tr><td colspan="7" class="profile-research-table-empty"><span class="profile-research-icon"><i class="bx bx-briefcase-alt" aria-hidden="true"></i></span><h3>No training records yet</h3><p>Add your first training to start your portfolio.</p></td></tr>
        <?php endif; ?>
        <?php foreach ($trainingView['records'] as $record): $recordId=(int)$record['training_id']; ?>
        <tr class="profile-training-record" data-training-id="<?= $recordId ?>">
          <th scope="row" class="profile-research-title"><?= synk_faculty_profile_h($record['training_title']) ?></th>
          <td><?= synk_faculty_profile_h($record['agency']) ?></td>
          <?php foreach (['date_from','date_to'] as $dateField): ?><td><time datetime="<?= synk_faculty_profile_h($record[$dateField]) ?>"><?= synk_faculty_profile_h((new DateTimeImmutable($record[$dateField]))->format('M j, Y')) ?></time></td><?php endforeach; ?>
          <td><?= synk_faculty_profile_h($record['venue']) ?></td>
          <td><?= synk_faculty_profile_h($record['mode']) ?></td>
          <td class="profile-research-action-cell"><div class="profile-research-actions">
            <a href="manage-profile.php?tab=trainings&amp;training=<?= $recordId ?>#training-editor" class="btn btn-outline-primary" data-training-edit data-training-record="<?= synk_faculty_profile_h(json_encode($record,JSON_UNESCAPED_UNICODE)) ?>" title="Edit training" aria-label="<?= synk_faculty_profile_h('Edit '.$record['training_title']) ?>"><i class="bx bx-edit-alt" aria-hidden="true"></i><span class="visually-hidden">Edit</span></a>
            <form action="manage-profile.php?tab=trainings" method="post" data-training-delete data-training-title="<?= synk_faculty_profile_h($record['training_title']) ?>">
              <input type="hidden" name="profile_action" value="training_delete"><input type="hidden" name="csrf_token" value="<?= synk_faculty_profile_h($profileEditor['csrf_token']) ?>"><input type="hidden" name="profile_owner" value="<?= synk_faculty_profile_h($profileOwner) ?>"><input type="hidden" name="training_id" value="<?= $recordId ?>"><input type="hidden" name="training_revision" value="<?= (int)$record['revision'] ?>">
              <button type="submit" class="btn btn-outline-danger" title="Remove training" aria-label="<?= synk_faculty_profile_h('Remove '.$record['training_title']) ?>"><i class="bx bx-trash" aria-hidden="true"></i><span class="visually-hidden">Remove</span></button>
            </form>
          </div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
