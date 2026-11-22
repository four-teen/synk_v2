<dialog class="hris-confirm-dialog hris-employee-delete" aria-labelledby="delete-employee-title" aria-describedby="delete-employee-description" data-delete-employee-dialog>
    <header>
        <div class="hris-employee-delete-heading">
            <span class="hris-confirm-icon"><i class="bx bx-trash" aria-hidden="true"></i></span>
            <h2 id="delete-employee-title">Delete employee?</h2>
        </div>
        <div class="hris-status-identity">
            <strong data-delete-employee-name></strong>
            <small data-delete-employee-number></small>
        </div>
    </header>
    <div class="hris-employee-delete-body">
        <p id="delete-employee-description">Review the records that would be removed before confirming deletion.</p>
        <div class="hris-account-alert hris-account-alert-error" data-delete-employee-error role="alert" tabindex="-1" hidden>
            <i class="bx bx-error-circle" aria-hidden="true"></i><span></span>
        </div>
        <section class="hris-employee-delete-protected" aria-labelledby="delete-employee-protected-title" data-delete-employee-protected hidden>
            <h3 id="delete-employee-protected-title" tabindex="-1">Linked records prevent deletion</h3>
            <ul class="hris-employee-delete-records"></ul>
        </section>
        <section aria-labelledby="delete-employee-review-title" data-delete-employee-review hidden>
            <h3 id="delete-employee-review-title" tabindex="-1">Records to be deleted</h3>
            <ul class="hris-employee-delete-records"></ul>
        </section>
    </div>
    <form class="hris-confirm-actions" method="post" action="delete-employee.php">
        <input type="hidden" name="csrf_token" value="<?= synk_hris_h($token) ?>">
        <input type="hidden" name="faculty_id" value="">
        <input type="hidden" name="review_token" value="">
        <button class="hris-action" type="button" data-cancel-delete-employee autofocus>Cancel</button>
        <button class="hris-action hris-action-primary" type="submit" data-review-delete-employee>Proceed to delete</button>
        <button class="hris-action hris-action-danger-primary" type="submit" data-confirm-delete-employee hidden disabled>Delete employee</button>
    </form>
</dialog>
