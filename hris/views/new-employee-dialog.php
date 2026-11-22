<?php
require_once __DIR__ . '/personnel-fields.php';
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$renderEmployeeFields = static function (array $keys) use ($employeeFields, $today): void { ?>
    <div class="hris-employee-fields">
        <?php foreach ($keys as $key):
            if ($key === 'plantilla_item_number') {
                synk_hris_render_plantilla_field('new-employee');
                continue;
            }
            $field = $employeeFields[$key];
            $id = 'new-employee-' . $key;
            $type = !empty($field['date']) ? 'date' : ($field['type'] ?? 'text');
        ?>
            <div class="hris-employee-field<?= in_array($key, ['employee_id', 'official_email'], true) ? ' hris-employee-field-wide' : '' ?>">
                <label for="<?= $id ?>"><?= synk_hris_h($field['label']) ?><?= empty($field['required']) ? '' : ' <span aria-hidden="true">*</span>' ?></label>
                <?php if (isset($field['options'])): ?>
                    <select id="<?= $id ?>" name="<?= $key ?>" aria-describedby="<?= $id ?>-error"<?= $key === 'college_name' || !$field['options'] ? ' disabled' : '' ?>>
                        <option value=""><?= $key === 'college_name' ? 'Select a campus first' : 'Select ' . synk_hris_h(strtolower($field['label'])) ?></option>
                        <?php foreach ($field['options'] as $option): ?>
                            <option value="<?= synk_hris_h($option) ?>"<?php if ($key === 'college_name'): ?> data-campuses="<?= synk_hris_h(json_encode($field['campus_options'][$option])) ?>"<?php endif; ?>><?= synk_hris_h($option) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input id="<?= $id ?>" name="<?= $key ?>" type="<?= $type ?>" aria-describedby="<?= $id ?>-error<?= $key === 'employee_id' ? ' new-employee-id-hint' : '' ?>"
                        <?php if (isset($field['max'])): ?>maxlength="<?= $field['max'] ?>"<?php endif; ?>
                        <?php if (isset($field['placeholder'])): ?>placeholder="<?= synk_hris_h($field['placeholder']) ?>"<?php endif; ?>
                        <?php if ($type === 'date'): ?>min="1900-01-01" max="<?= $today ?>"<?php endif; ?>
                        <?= empty($field['required']) ? '' : ' required' ?>>
                <?php endif; ?>
                <?php if ($key === 'employee_id'): ?><small class="hris-muted" id="new-employee-id-hint">Kawani fills in a temporary ID. You can enter the official number here.</small><?php endif; ?>
                <small class="hris-employee-field-error" data-error-for="<?= $key ?>" id="<?= $id ?>-error" hidden></small>
            </div>
        <?php endforeach; ?>
    </div>
<?php }; ?>
<dialog class="hris-confirm-dialog hris-employee-dialog" aria-labelledby="new-employee-title" data-new-employee-dialog>
    <header>
        <h2 id="new-employee-title">New Employee</h2>
        <p>Choose the personnel category to see the relevant position fields.</p>
    </header>
    <form method="post" action="register-employee.php" autocomplete="off" data-temporary-id-suffix="<?= SYNK_HRIS_TEMPORARY_ID_SUFFIX ?>">
        <input type="hidden" name="csrf_token" value="<?= synk_hris_h($token) ?>">
        <div class="hris-employee-form-body">
            <div class="hris-account-alert hris-account-alert-error" data-employee-error role="alert" tabindex="-1" hidden>
                <i class="bx bx-error-circle" aria-hidden="true"></i><span></span>
            </div>
            <?php synk_hris_render_personnel_category('new-employee'); ?>
            <fieldset>
                <legend>Employee details</legend>
                <?php $renderEmployeeFields(['surname', 'first_name', 'middle_name', 'name_extension', 'employee_id']); ?>
                <?php if (isset($employeeFields['official_email'])) $renderEmployeeFields(['official_email']); ?>
            </fieldset>
            <fieldset>
                <legend>Employment</legend>
                <?php $renderEmployeeFields(['employment_classification', 'campus_name']); ?>
                <div data-personnel-for="teaching" hidden>
                    <?php $renderEmployeeFields(['faculty_rank', 'college_name']); ?>
                </div>
                <?php synk_hris_render_non_teaching_fields($positionCatalog, 'new-employee'); ?>
                <?php $renderEmployeeFields(['service_start_date', 'plantilla_item_number']); ?>
            </fieldset>
            <details class="hris-employee-more">
                <summary>Personal details <span>Optional</span></summary>
                <?php $renderEmployeeFields(['date_of_birth', 'gender', 'civil_status']); ?>
            </details>
        </div>
        <div class="hris-confirm-actions">
            <button class="hris-action" type="button" data-cancel-employee-form>Cancel</button>
            <button class="hris-action hris-action-primary" type="submit">Save employee</button>
        </div>
    </form>
</dialog>
