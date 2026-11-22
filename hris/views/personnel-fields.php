<?php

function synk_hris_render_personnel_category(string $prefix): void
{
    $id = $prefix . '-category';
    ?>
    <fieldset class="hris-personnel-chooser">
        <legend>Personnel category <span aria-hidden="true">*</span></legend>
        <div class="hris-personnel-choices">
            <?php foreach (synk_hris_personnel_categories() as $value => $label): ?>
                <label>
                    <input name="personnel_category" type="radio" value="<?= synk_hris_h($value) ?>" aria-describedby="<?= $id ?>-error" required>
                    <span><?= synk_hris_h($label) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <small class="hris-employee-field-error" data-error-for="personnel_category" id="<?= $id ?>-error" hidden></small>
    </fieldset>
    <?php
}

function synk_hris_render_non_teaching_fields(array $positions, string $prefix): void
{
    $positionId = $prefix . '-position';
    $officeId = $prefix . '-office';
    ?>
    <div class="hris-employee-fields" data-personnel-for="non_teaching" hidden>
        <div class="hris-employee-field">
            <label for="<?= $positionId ?>">Position title <span data-position-required aria-hidden="true" hidden>*</span></label>
            <select id="<?= $positionId ?>" name="position_id" aria-describedby="<?= $positionId ?>-error" data-position-picker
                data-required-classifications="<?= synk_hris_h(json_encode(SYNK_HRIS_POSITION_REQUIRED_CLASSIFICATIONS)) ?>" disabled>
                <option value="">Select a position</option>
                <?php foreach ($positions as $position): ?>
                    <option value="<?= (int)$position['position_id'] ?>"><?= synk_hris_h($position['position_title']) ?><?= $position['salary_grade'] === null ? '' : ' · SG ' . (int)$position['salary_grade'] ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="hris-text-link hris-position-catalog-link" data-open-position-catalog data-empty-position-picker aria-haspopup="dialog" aria-controls="position-catalog"<?= $positions ? ' hidden' : '' ?>>Add a position to the catalog</button>
            <small class="hris-employee-field-error" data-error-for="position_id" id="<?= $positionId ?>-error" hidden></small>
        </div>
        <div class="hris-employee-field">
            <label for="<?= $officeId ?>">Office or unit</label>
            <input id="<?= $officeId ?>" name="office_unit" maxlength="200" aria-describedby="<?= $officeId ?>-error" disabled>
            <small class="hris-employee-field-error" data-error-for="office_unit" id="<?= $officeId ?>-error" hidden></small>
        </div>
    </div>
    <?php
}

function synk_hris_render_plantilla_field(string $prefix): void
{
    $id = $prefix . '-plantilla';
    ?>
    <div class="hris-employee-field">
        <label for="<?= $id ?>">Plantilla item number (if applicable)</label>
        <input id="<?= $id ?>" name="plantilla_item_number" maxlength="64" aria-describedby="<?= $id ?>-error">
        <small class="hris-employee-field-error" data-error-for="plantilla_item_number" id="<?= $id ?>-error" hidden></small>
    </div>
    <?php
}
