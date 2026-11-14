<?php
if (!isset($profileEditor) || $profileEditor['research_state'] === null) {http_response_code(404); return;}
$researchView = $profileEditor['research_state'];
$researchRecord = $researchView['record'];
$researchFields = synk_faculty_profile_research_fields();
?>
<div class="profile-researches" data-researches>
  <div class="profile-research-heading">
    <div><span class="profile-eyebrow">RESEARCH PORTFOLIO</span><h2>Research records <span class="profile-research-count"><?= count($researchView['records']) ?></span></h2><p>Keep a record of your research, conferences, and publications.</p></div>
    <a class="btn btn-primary" href="manage-profile.php?tab=researches&amp;research=new#research-editor" data-research-add><i class="bx bx-plus" aria-hidden="true"></i>Add research</a>
  </div>
  <?php if ($researchView['notice'] !== ''): ?><div class="alert alert-success" role="status"><?= synk_faculty_profile_h($researchView['notice']) ?></div><?php endif; ?>
  <?php if ($researchView['error'] !== '' || $researchView['errors']): ?>
    <div class="alert alert-danger" role="alert" tabindex="-1" id="research-errors">
      <?php if ($researchView['error'] !== ''): ?><p class="mb-0"><?= synk_faculty_profile_h($researchView['error']) ?></p><?php endif; ?>
      <?php if ($researchView['errors']): ?><strong>Please review the research details:</strong><ul class="mb-0 mt-2"><?php foreach ($researchView['errors'] as $key=>$error): ?><li><a href="#research_<?= synk_faculty_profile_h($key) ?>"><?= synk_faculty_profile_h($error) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
  <?php endif; ?>

  <section class="card profile-research-editor" id="research-editor" aria-labelledby="research-editor-title"<?= !$researchView['show_editor']?' hidden':'' ?>>
    <div class="profile-research-editor-heading"><span class="profile-research-icon"><i class="bx bx-edit-alt" aria-hidden="true"></i></span><div><h3 id="research-editor-title"><?= (int)$researchRecord['research_id']>0?'Edit research record':'Add research record' ?></h3><p>Fields marked <span class="text-danger">*</span> are required. Add conference and publication details when available.</p></div></div>
    <form id="faculty-research-form" action="manage-profile.php?tab=researches" method="post" data-has-errors="<?= $researchView['errors']||$researchView['error']!==''?'1':'0' ?>">
      <input type="hidden" name="profile_action" value="research_save">
      <input type="hidden" name="csrf_token" value="<?= synk_faculty_profile_h($profileEditor['csrf_token']) ?>">
      <input type="hidden" name="profile_owner" value="<?= synk_faculty_profile_h($profileOwner) ?>">
      <input type="hidden" name="research_id" value="<?= (int)$researchRecord['research_id'] ?>">
      <input type="hidden" name="research_revision" value="<?= (int)$researchRecord['revision'] ?>">
      <div class="row g-4">
        <?php foreach ($researchFields as $key=>$field): $fieldError=$researchView['errors'][$key]??''; $fieldId='research_'.$key; ?>
          <div class="<?= in_array($key,['research_title','publisher'],true)?'col-12':'col-md-6' ?>">
            <label class="form-label" for="<?= $fieldId ?>"><?= synk_faculty_profile_h($field['label']) ?><?php if (!empty($field['required'])): ?> <span class="text-danger" aria-hidden="true">*</span><?php endif; ?></label>
            <?php if (isset($field['options'])): ?>
              <select id="<?= $fieldId ?>" name="<?= $key ?>" class="form-select<?= $fieldError!==''?' is-invalid':'' ?>" required<?= $fieldError!==''?' aria-invalid="true" aria-describedby="'.$fieldId.'-error"':'' ?>>
                <option value="">Select <?= $key==='research_scope'?'scope':'funding' ?></option>
                <?php foreach ($field['options'] as $option): ?><option value="<?= $option ?>"<?= $researchRecord[$key]===$option?' selected':'' ?>><?= $option ?></option><?php endforeach; ?>
              </select>
            <?php else: ?>
              <input type="<?= $field['type']??'text' ?>" id="<?= $fieldId ?>" name="<?= $key ?>" class="form-control<?= $fieldError!==''?' is-invalid':'' ?>" value="<?= synk_faculty_profile_h($researchRecord[$key]??'') ?>"<?= !empty($field['required'])?' required':'' ?><?= isset($field['max'])?' maxlength="'.$field['max'].'"':'' ?><?= ($field['type']??'')==='date'?' min="1900-01-01"':'' ?><?= ($field['type']??'')==='url'?' placeholder="https://'.($key==='scopus_link'?'www.scopus.com/…':'publisher.example/article').'"':'' ?><?= $fieldError!==''?' aria-invalid="true"':'' ?><?= $fieldError!==''||$key==='scopus_link'?' aria-describedby="'.($fieldError!==''?$fieldId.'-error ':'').($key==='scopus_link'?'research-scopus-help':'').'"':'' ?>>
            <?php endif; ?>
            <?php if ($fieldError!==''): ?><div class="invalid-feedback" id="<?= $fieldId ?>-error"><?= synk_faculty_profile_h($fieldError) ?></div><?php endif; ?>
            <?php if ($key==='scopus_link'): ?><div class="form-text" id="research-scopus-help">Optional. Use the publication's page on scopus.com.</div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="profile-research-form-footer"><span id="research-save-status" role="status" aria-live="polite">Each research record is saved separately.</span><div><a href="manage-profile.php?tab=researches" class="btn btn-outline-secondary" data-research-cancel>Cancel</a><button type="submit" class="btn btn-primary" id="research-save-button"><i class="bx bx-save" aria-hidden="true"></i>Save research</button></div></div>
    </form>
  </section>

  <div class="card profile-research-table-card">
    <div class="profile-research-table-scroll" role="region" aria-label="Research records table" tabindex="0">
      <table class="profile-research-table">
        <caption class="visually-hidden">Saved research records, conference details, publications, and actions</caption>
        <thead><tr><th scope="col">Research title</th><th scope="col">Scope</th><th scope="col">Funding</th><th scope="col">Conference</th><th scope="col">Publisher</th><th scope="col">Publication links</th><th scope="col">Actions</th></tr></thead>
        <tbody>
        <?php if (!$researchView['records']): ?>
          <tr><td colspan="7" class="profile-research-table-empty"><span class="profile-research-icon"><i class="bx bx-book-open" aria-hidden="true"></i></span><h3>No research records yet</h3><p>Add your first research to start your portfolio.</p></td></tr>
        <?php endif; ?>
        <?php foreach ($researchView['records'] as $record): $recordId=(int)$record['research_id']; ?>
        <tr class="profile-research-record" data-research-id="<?= $recordId ?>">
          <th scope="row" id="research-title-<?= $recordId ?>" class="profile-research-title"><?= synk_faculty_profile_h($record['research_title']) ?></th>
          <td><?= synk_faculty_profile_h($record['research_scope']) ?></td>
          <td><?= synk_faculty_profile_h($record['funding_type']) ?></td>
          <td class="profile-research-conference"><div class="profile-research-lines"><span class="profile-research-conference-name" title="<?= synk_faculty_profile_h($record['conference_name']) ?>"><?= $record['conference_name']!==''?synk_faculty_profile_h($record['conference_name']):'<span class="profile-research-missing">Not entered</span>' ?></span><?php if (!empty($record['conference_date'])): ?><time class="profile-research-secondary" datetime="<?= synk_faculty_profile_h($record['conference_date']) ?>"><?= synk_faculty_profile_h((new DateTimeImmutable($record['conference_date']))->format('M j, Y')) ?></time><?php elseif ($record['conference_name']!==''): ?><span class="profile-research-secondary profile-research-missing">Date not entered</span><?php endif; ?></div></td>
          <td><?= $record['publisher']!==''?synk_faculty_profile_h($record['publisher']):'<span class="profile-research-missing">Not entered</span>' ?></td>
          <td class="profile-research-links"><div class="profile-research-lines"><?php if ($record['publisher_link']!=='' || $record['scopus_link']!==''): ?><?php foreach (['publisher_link'=>'Publisher','scopus_link'=>'Scopus'] as $linkKey=>$linkLabel): if ($record[$linkKey]==='') continue; ?><a href="<?= synk_faculty_profile_h($record[$linkKey]) ?>" target="_blank" rel="noopener noreferrer"><?= $linkLabel ?><i class="bx bx-link-external" aria-hidden="true"></i><span class="visually-hidden"> (opens in a new tab)</span></a><?php endforeach; ?><?php else: ?><span class="profile-research-missing">Not entered</span><?php endif; ?></div></td>
          <td class="profile-research-action-cell"><div class="profile-research-actions">
              <a href="manage-profile.php?tab=researches&amp;research=<?= $recordId ?>#research-editor" class="btn btn-outline-primary" data-research-edit data-research-record="<?= synk_faculty_profile_h(json_encode($record,JSON_UNESCAPED_UNICODE)) ?>" title="Edit research" aria-label="<?= synk_faculty_profile_h('Edit '.$record['research_title']) ?>"><i class="bx bx-edit-alt" aria-hidden="true"></i><span class="visually-hidden">Edit</span></a>
              <form action="manage-profile.php?tab=researches" method="post" data-research-delete data-research-title="<?= synk_faculty_profile_h($record['research_title']) ?>">
                <input type="hidden" name="profile_action" value="research_delete"><input type="hidden" name="csrf_token" value="<?= synk_faculty_profile_h($profileEditor['csrf_token']) ?>"><input type="hidden" name="profile_owner" value="<?= synk_faculty_profile_h($profileOwner) ?>"><input type="hidden" name="research_id" value="<?= $recordId ?>"><input type="hidden" name="research_revision" value="<?= (int)$record['revision'] ?>">
                <button type="submit" class="btn btn-outline-danger" title="Remove research" aria-label="<?= synk_faculty_profile_h('Remove '.$record['research_title']) ?>"><i class="bx bx-trash" aria-hidden="true"></i><span class="visually-hidden">Remove</span></button>
              </form>
          </div></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
