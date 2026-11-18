<?php
if (!isset($profileEditor) || empty($profileEditor['show_pds_preview'])) { http_response_code(404); return; }
require_once __DIR__.'/faculty_pds_preview_helper.php';
$pdsSections = synk_faculty_pds_preview_sections();
$pdsStorage = $profileEditor['pds_state'] ?? null;
$pdsAttachments=$pdsStorage['sections']['certification']['attachments'] ?? [];
$pdsPrefill = synk_faculty_pds_preview_prefill($storedProfile ?? [], $profileEditor['additional_degrees'] ?? [],
    $profileEditor['training_state']['records'] ?? [], $eligibilities ?? [], (string)($account['email'] ?? ''),
    $pdsStorage['faculty_link'] ?? null);
foreach (synk_hr_pds_sections() as $pdsSectionKey) {
    if (isset($pdsStorage['sections'][$pdsSectionKey]['values'])) {
        foreach ($pdsStorage['sections'][$pdsSectionKey]['values'] as $key=>$value) {
            if (is_array($value)) $pdsPrefill['lists'][$key]=$value;
            else $pdsPrefill['values'][$key]=$value;
        }
    }
}

function synk_faculty_pds_preview_item(array $item, string $prefix, array $values, array $lists, string $today): void
{
    $h = 'synk_faculty_profile_h';
    $type = $item['type'];
    $condition = isset($item['when']) ? ' data-pds-when="'.$h(json_encode($item['when'])).'"' : '';
    if ($type === 'group') {
        $collapsible = !empty($item['collapsible']);
        echo $collapsible ? '<details class="pds-group pds-disclosure"'.$condition.'><summary>'.$h($item['label']).'</summary>'
            : '<fieldset class="pds-group'.(!empty($item['question'])?' pds-question':'').'"'.$condition.'><legend>'.$h($item['label']).'</legend>';
        if (!empty($item['hint'])) echo '<p class="pds-hint">'.$h($item['hint']).'</p>';
        if (!empty($item['officer'])) echo '<fieldset disabled class="pds-officer-fields"><legend class="visually-hidden">For the authorized officer</legend>';
        echo '<div class="pds-fields">';
        foreach ($item['fields'] as $child) synk_faculty_pds_preview_item($child,$prefix,$values,$lists,$today);
        echo '</div>';
        if (!empty($item['officer'])) echo '</fieldset>';
        echo $collapsible ? '</details>' : '</fieldset>';
        return;
    }
    if ($type === 'list') {
        $key = $item['key']; $records = array_values($lists[$key] ?? []);
        $section=substr($prefix,4); $definition=synk_hr_pds_record_lists($section)[$key] ?? [];
        echo '<section class="pds-list" data-pds-list="'.$h($key).'" data-pds-singular="'.$h($item['singular']).'"'.(isset($definition['limit'])?' data-pds-limit="'.(int)$definition['limit'].'"':'').'>';
        echo '<div class="pds-list-heading"><h3>'.$h($item['label']).' <span data-pds-list-count>'.count($records).'</span></h3>';
        echo '<button type="button" class="btn btn-outline-primary" data-pds-add aria-label="Add '.$h($item['singular']).'"><i class="bx bx-plus" aria-hidden="true"></i>Add '.$h($item['singular']).'</button></div>';
        echo '<div class="pds-records" data-pds-records>';
        foreach ($records as $index=>$record) synk_faculty_pds_preview_record($item,$prefix.'-'.$key.'-'.$index,$record,$today,true);
        echo '</div><p class="pds-empty" data-pds-empty'.($records?' hidden':'').'>No records added. Add one when you are ready, or continue to another section.</p>';
        echo '<template data-pds-template>';
        synk_faculty_pds_preview_record($item,$prefix.'-'.$key.'-__PDS_INDEX__',[],$today,false);
        echo '</template></section>';
        return;
    }
    $key = $item['key']; $id = $prefix.'-'.$key; $value = (string)($values[$key] ?? '');
    $persistedSection = in_array(substr($prefix,4),synk_hr_pds_sections(),true) ? substr($prefix,4) : null;
    $rule = $persistedSection ? (synk_hr_pds_fields($persistedSection)[$key] ?? []) : [];
    if (preg_match('/^pds-([a-z]+)-([a-z]+)-/',$prefix,$parts)) $rule=synk_hr_pds_record_lists($parts[1])[$parts[2]]['fields'][$key] ?? [];
    $wide = in_array($type,['answer','checkbox','textarea','file'],true);
    echo '<div class="pds-field'.($wide?' pds-field-wide':'').'"'.$condition.($type==='file'?' data-pds-file-slot="'.$h($key).'"':'').'>';
    if ($type === 'answer') {
        echo '<fieldset class="pds-answer"><legend class="visually-hidden">'.$h($item['label']).'</legend>';
        foreach (['Yes','No'] as $answer) echo '<label><input type="radio" id="'.$h($id.'-'.strtolower($answer)).'" name="'.$h($id).'" value="'.$answer.'"'.($value===$answer?' checked':'').' data-pds-control data-pds-key="'.$h($key).'" data-pds-label="'.$h($item['label']).'">'.$answer.'</label>';
        echo '</fieldset></div>'; return;
    }
    if ($type === 'checkbox') {
        echo '<label class="pds-check"><input type="checkbox" id="'.$h($id).'"'.($value==='1'?' checked':'').' data-pds-control data-pds-key="'.$h($key).'" data-pds-label="'.$h($item['label']).'">'.$h($item['label']).'</label></div>'; return;
    }
    echo '<label class="form-label" for="'.$h($id).'">'.$h($item['label']).'</label>';
    $attributes = ' id="'.$h($id).'" data-pds-control data-pds-key="'.$h($key).'" data-pds-label="'.$h($item['label']).'"';
    if (isset($rule['max'])) $attributes .= ' maxlength="'.(int)$rule['max'].'"';
    if (!empty($rule['year'])) $attributes .= ' max="'.substr($today,0,4).'"';
    if (!empty($rule['date']) && empty($rule['future']) && !isset($item['max'])) $attributes .= ' max="'.$h($today).'"';
    if (!empty($item['hint'])) $attributes .= ' aria-describedby="'.$h($id).'-hint"';
    foreach (['min','max','step','accept'] as $attribute) {
        if (isset($item[$attribute])) $attributes .= ' '.$attribute.'="'.$h($item[$attribute]==='today'?$today:$item[$attribute]).'"';
    }
    if ($type === 'select') {
        echo '<select class="form-select"'.$attributes.'><option value="">Choose when ready</option>';
        foreach ($item['options'] as $option) echo '<option value="'.$h($option).'"'.($option===$value?' selected':'').'>'.$h($option).'</option>';
        echo '</select>';
    } elseif ($type === 'textarea') {
        echo '<textarea class="form-control" rows="3"'.(!isset($rule['max'])?' maxlength="5000"':'').$attributes.'>'.$h($value).'</textarea>';
    } else {
        echo '<input class="form-control" type="'.$h($type).'"'.$attributes.($type==='file'?'':' value="'.$h($value).'"').($type==='text' && !isset($rule['max'])?' maxlength="500"':'').'>';
    }
    if ($type==='file') {
        global $pdsAttachments;
        $file=$pdsAttachments[$key] ?? null;
        echo '<div class="pds-attachment-saved" data-pds-attachment-saved'.(!$file?' hidden':'').'><a data-pds-attachment-link href="pds-attachment.php?slot='.$h(rawurlencode($key)).'">'.$h($file['name'] ?? '').'</a><label class="pds-check"><input type="checkbox" data-pds-attachment-remove value="'.$h($key).'">Remove saved file on next save</label></div>';
    }
    if (!empty($item['hint'])) echo '<small class="pds-hint" id="'.$h($id).'-hint">'.$h($item['hint']).'</small>';
    echo '</div>';
}

function synk_faculty_pds_preview_record(array $item, string $prefix, array $values, string $today, bool $saved): void
{
    $title = '';
    foreach (['training_title','degree','institution','name','full_name','organization','position','surname'] as $key) {
        if (!empty($values[$key])) { $title = (string)$values[$key]; break; }
    }
    $title = $title ?: ucfirst($item['singular']);
    echo '<details class="pds-record" data-pds-record'.(!$saved?' open':'').'>';
    $persistent=(bool)preg_match('/^pds-('.implode('|',synk_hr_pds_sections()).')-/',$prefix);
    echo '<summary><span class="pds-record-icon"><i class="bx bx-file" aria-hidden="true"></i></span><span class="pds-record-summary"><strong data-pds-record-title>'.synk_faculty_profile_h($title).'</strong><small data-pds-record-note>'.($saved?'Ready to review':($persistent?'Unsaved entry':'Preview entry')).'</small></span><i class="bx bx-chevron-down" aria-hidden="true"></i></summary>';
    echo '<div class="pds-record-body"><div class="pds-fields">';
    foreach ($item['fields'] as $field) synk_faculty_pds_preview_item($field,$prefix,$values,[],$today);
    echo '</div><button type="button" class="pds-remove" data-pds-remove aria-label="Remove '.synk_faculty_profile_h($item['singular']).'"><i class="bx bx-x" aria-hidden="true"></i>'.($persistent?'Remove record':'Remove from preview').'</button></div></details>';
}
?>
<div class="pds-preview" id="pds-preview"<?php if ($pdsStorage): ?> data-pds-save-url="<?= synk_faculty_profile_h($pdsStorage['save_url']) ?>" data-pds-owner="<?= synk_faculty_profile_h($pdsStorage['owner']) ?>" data-pds-csrf="<?= synk_faculty_profile_h($pdsStorage['csrf_token']) ?>" data-pds-saving-enabled="<?= !empty($pdsStorage['enabled'])?'1':'0' ?>"<?php endif; ?>>
  <header class="pds-intro">
    <div><span class="pds-eyebrow">CS FORM 212 · REVISED 2026 <span class="pds-preview-badge"><?= $pdsStorage?'Draft':'UI preview' ?></span></span>
      <h2>Your Personal Data Sheet</h2><p>A little at a time. Start anywhere and move between sections freely.</p></div>
    <button type="button" class="btn btn-outline-secondary" data-pds-review><i class="bx bx-show" aria-hidden="true"></i>Review PDS</button>
  </header>
  <div class="pds-preview-notice" role="note"><i class="bx bx-info-circle" aria-hidden="true"></i><p><?php if ($pdsStorage): ?><strong>Save a little at a time.</strong> Every section saves independently, even while incomplete. Use Review PDS to check your details whenever you like.<?php else: ?><strong>UI preview only.</strong> New PDS entries reset when you leave or reload.<?php endif; ?> Your existing <button type="button" data-pds-profile>Profile tab</button> stays available. Sidebar progress tracks that tab.</p></div>
  <?php if ($pdsStorage && empty($pdsStorage['enabled'])): ?><p class="alert alert-warning" role="alert">PDS saving is temporarily unavailable. Your existing Profile tab is still available. Reload this page before entering new PDS details.</p><?php endif; ?>
  <div class="pds-layout">
    <nav class="pds-navigation card" aria-label="PDS sections">
      <div class="pds-navigation-heading"><strong>Your sections</strong><small>Choose where to begin</small></div>
      <?php foreach ($pdsSections as $index=>$section): ?>
      <button type="button" data-pds-step="<?= $index ?>"<?= $index===0?' aria-current="step"':'' ?> aria-controls="pds-section-<?= $section['key'] ?>"><span class="pds-step-number"><?= str_pad((string)($index+1),2,'0',STR_PAD_LEFT) ?></span><span><?= synk_faculty_profile_h($section['title']) ?><?php if ($pdsStorage): ?><small class="pds-step-state" data-pds-step-state="<?= $section['key'] ?>"><?= in_array($section['key'],synk_hr_pds_sections(),true)?(!empty($pdsStorage['sections'][$section['key']])?'Saved':'Draft'):'Preview' ?></small><?php endif; ?></span><i class="bx bx-chevron-right" aria-hidden="true"></i></button>
      <?php endforeach; ?>
      <div class="pds-navigation-note"><i class="bx bx-lock-alt" aria-hidden="true"></i><span><?= $pdsStorage?'Your PDS is a saved draft. Files are private to your profile.':'No submission or uploads in this preview.' ?></span></div>
    </nav>
    <div class="pds-content">
      <noscript><p class="alert alert-info">Enable JavaScript to use the guided PDS and section saving. Your existing Profile tab remains available.</p></noscript>
      <div id="pds-editor">
      <?php foreach ($pdsSections as $index=>$section): ?>
        <?php $pdsCanSave = $pdsStorage && in_array($section['key'],synk_hr_pds_sections(),true); $pdsSavedSection=$pdsStorage['sections'][$section['key']] ?? null; ?>
        <section class="card pds-section" id="pds-section-<?= $section['key'] ?>" data-pds-section="<?= $index ?>" data-pds-key="<?= $section['key'] ?>"<?php if ($pdsCanSave): ?> data-pds-persistent="1" data-pds-revision="<?= (int)($pdsSavedSection['revision'] ?? 0) ?>" data-pds-saved-at="<?= synk_faculty_profile_h($pdsSavedSection['updated_at'] ?? '') ?>"<?php endif; ?> data-pds-title="<?= synk_faculty_profile_h($section['title']) ?>" aria-labelledby="pds-heading-<?= $section['key'] ?>"<?= $index!==0?' hidden':'' ?>>
          <header class="pds-section-heading"><span class="pds-section-icon"><i class="bx <?= $section['icon'] ?>" aria-hidden="true"></i></span><div><small>SECTION <?= $index+1 ?> OF <?= count($pdsSections) ?></small><h3 id="pds-heading-<?= $section['key'] ?>" tabindex="-1"><?= synk_faculty_profile_h($section['title']) ?></h3><p><?= synk_faculty_profile_h($section['description']) ?></p></div></header>
          <?php if ($section['key']==='personal'): ?>
          <div class="pds-saved-context"><i class="bx bx-user-circle" aria-hidden="true"></i><div><small>Your saved profile name</small><strong><?= synk_faculty_profile_h((string)(($storedProfile ?? [])['full_name'] ?? ($account['username'] ?? 'Not entered'))) ?></strong><span><?= synk_faculty_profile_h((string)($account['email'] ?? '')) ?></span></div></div>
          <?php endif; ?>
          <?php if ($pdsCanSave): ?><div class="pds-section-errors alert alert-danger" role="alert" tabindex="-1" hidden></div><?php endif; ?>
          <?php foreach ($section['groups'] as $group) synk_faculty_pds_preview_item($group,'pds-'.$section['key'],$pdsPrefill['values'],$pdsPrefill['lists'],$today->format('Y-m-d')); ?>
        </section>
      <?php endforeach; ?>
      </div>
      <section class="card pds-review" id="pds-review" aria-labelledby="pds-review-title" hidden>
        <span class="pds-eyebrow">PERSONAL DATA SHEET</span><h3 id="pds-review-title" tabindex="-1">Your PDS at a glance</h3><p>Review your saved details and unsaved edits. Saving a draft does not submit or digitally sign your PDS.</p><div data-pds-review-content></div>
        <button type="button" class="btn btn-outline-primary" data-pds-edit>Back to editing</button>
      </section>
      <footer class="pds-footer">
        <span id="pds-preview-status" role="status" aria-live="polite">Preview only · Changes are not saved</span>
        <div><button type="button" class="btn btn-outline-secondary" data-pds-previous disabled><i class="bx bx-left-arrow-alt" aria-hidden="true"></i>Previous</button><?php if ($pdsStorage): ?><button type="button" class="btn btn-primary" data-pds-save<?= empty($pdsStorage['enabled'])?' disabled':'' ?>>Save section</button><?php endif; ?><button type="button" class="btn btn-outline-primary" data-pds-next>Next section<i class="bx bx-right-arrow-alt" aria-hidden="true"></i></button></div>
      </footer>
    </div>
  </div>
</div>
