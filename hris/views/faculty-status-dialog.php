<dialog class="hris-confirm-dialog hris-status-dialog" aria-labelledby="faculty-status-title" data-faculty-status-dialog>
    <h2 id="faculty-status-title">Set HR status</h2>
    <div class="hris-status-identity">
        <strong data-status-faculty-name></strong>
        <small data-status-employee-id></small>
    </div>
    <p data-status-review-detail hidden></p>
    <form class="hris-status-form" method="post" action="faculty-status.php">
        <input type="hidden" name="csrf_token" value="<?= synk_hris_h($token) ?>">
        <input type="hidden" name="record_key" value="">
        <input type="hidden" name="revision" value="">
        <div class="hris-account-alert hris-account-alert-error" data-status-error role="alert" tabindex="-1" hidden>
            <i class="bx bx-error-circle" aria-hidden="true"></i><span></span>
        </div>
        <label for="faculty-hr-status">HR status</label>
        <select id="faculty-hr-status" name="status" required autofocus>
            <option value="" disabled selected>Select status</option>
            <?php foreach ($facultyStatusOptions as $code => [$label]): ?>
                <option value="<?= synk_hris_h($code) ?>"><?= synk_hris_h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="hris-status-verification" data-status-verification hidden>
            <input type="checkbox" name="verified_by_hr" value="1" disabled>
            <span>I verified this employee’s identity and current employment against official HR records.</span>
        </label>
        <div class="hris-confirm-actions">
            <button class="hris-action" type="button" data-cancel-faculty-status>Cancel</button>
            <button class="hris-action hris-action-primary" type="submit">Save status</button>
        </div>
    </form>
</dialog>
