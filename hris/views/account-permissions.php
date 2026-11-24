<fieldset class="hris-user-permissions" data-user-permissions<?= in_array(SYNK_HRIS_USER, $selectedRoles, true) ? '' : ' hidden disabled' ?>>
    <legend>User permissions</legend>
    <input type="hidden" name="permissions_set" value="1">
    <?php if ($permissionOptions): ?>
        <?php if (isset($permissionOptions['employee_manage']) || isset($permissionOptions['employee_delete'])): ?>
            <p class="hris-permission-note">Editing and deleting employee records require Employee Profiles.</p>
        <?php endif; ?>
        <div class="hris-permission-options">
            <?php foreach ($permissionOptions as $permission => [$label, $description]): ?>
                <label class="hris-permission-option">
                    <input type="checkbox" name="permissions[]" value="<?= synk_hris_h($permission) ?>"
                        <?= in_array($permission, $checkedPermissions, true) ? 'checked' : '' ?>>
                    <span>
                        <strong><?= synk_hris_h($label) ?></strong>
                        <small><?= synk_hris_h($description) ?></small>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="hris-note">Your administrator can grant tools to this account.</p>
    <?php endif; ?>
</fieldset>
