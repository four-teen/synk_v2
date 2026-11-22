<?php require_once __DIR__ . '/personnel-fields.php'; ?>
<dialog class="hris-confirm-dialog hris-employee-dialog hris-personnel-dialog" aria-labelledby="employee-personnel-title" data-employee-personnel-dialog>
    <header>
        <h2 id="employee-personnel-title">Personnel details</h2>
        <p><strong data-personnel-name></strong></p>
    </header>
    <form method="post" action="employee-personnel.php" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= synk_hris_h($token) ?>">
        <input type="hidden" name="record_key" value="">
        <input type="hidden" name="revision" value="">
        <div class="hris-employee-form-body">
            <div class="hris-account-alert hris-account-alert-error" data-employee-error role="alert" tabindex="-1" hidden>
                <i class="bx bx-error-circle" aria-hidden="true"></i><span></span>
            </div>
            <p class="hris-notice" data-personnel-suggestion hidden>Teaching is suggested based on the recorded academic rank. Confirm the category against the employee’s appointment.</p>
            <?php synk_hris_render_personnel_category('edit-personnel'); ?>
            <?php synk_hris_render_non_teaching_fields($positionCatalog, 'edit-personnel'); ?>
            <?php synk_hris_render_plantilla_field('edit-personnel'); ?>
        </div>
        <div class="hris-confirm-actions">
            <button class="hris-action" type="button" data-cancel-employee-form>Cancel</button>
            <button class="hris-action hris-action-primary" type="submit">Save personnel details</button>
        </div>
    </form>
</dialog>
