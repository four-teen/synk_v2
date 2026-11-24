<fieldset class="hris-account-field hris-account-role-picker" data-account-roles>
    <legend>Select account to use</legend>
    <?php foreach ($roleChoices as $code => $label): ?>
        <?php $fixed = in_array($code, $fixedRoles, true); ?>
        <label class="hris-account-role-option">
            <input type="checkbox" name="roles[]" value="<?= synk_hris_h($code) ?>"
                <?= in_array($code, $selectedRoles, true) ? 'checked' : '' ?><?= $fixed ? ' disabled' : '' ?>>
            <span><?= synk_hris_h($label) ?></span>
        </label>
        <?php if ($fixed): ?>
            <input type="hidden" name="roles[]" value="<?= synk_hris_h($code) ?>">
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if (count($fixedRoles) > 1): ?>
        <small>Switch to Administrator to change your roles.</small>
    <?php endif; ?>
</fieldset>
