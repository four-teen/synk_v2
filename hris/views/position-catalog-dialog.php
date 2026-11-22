<dialog id="position-catalog" class="hris-confirm-dialog hris-position-dialog" aria-labelledby="position-catalog-title" data-position-catalog>
    <header class="hris-position-header">
        <span class="hris-icon hris-tone-green"><i class="bx bx-list-ul" aria-hidden="true"></i></span>
        <div>
            <h2 id="position-catalog-title">Position Catalog</h2>
            <p>Non-teaching position titles and salary grades.</p>
        </div>
        <button type="button" class="hris-action hris-position-close" data-close-position-catalog aria-label="Close Position Catalog" autofocus><i class="bx bx-x" aria-hidden="true"></i></button>
    </header>
    <div class="hris-position-feedback">
        <div class="hris-account-alert hris-account-alert-success" role="status" data-position-success hidden>
            <i class="bx bx-check-circle" aria-hidden="true"></i><span></span>
        </div>
        <div class="hris-account-alert hris-account-alert-error" role="alert" data-position-error hidden>
            <i class="bx bx-error-circle" aria-hidden="true"></i><span></span>
        </div>
    </div>
    <div class="hris-position-body">
        <form method="post" action="positions.php" autocomplete="off" data-position-form>
            <input type="hidden" name="csrf_token" value="<?= synk_hris_h($token) ?>">
            <input type="hidden" name="operation" value="add">
            <input type="hidden" name="position_id" value="">
            <input type="hidden" name="version" value="">
            <div class="hris-position-form-heading">
                <h3 data-position-mode>Add a position</h3>
                <button type="button" class="hris-text-link hris-position-catalog-link" data-cancel-position-edit hidden>Back to add</button>
            </div>
            <fieldset class="hris-position-form" disabled>
                <div class="hris-employee-field">
                    <label for="catalog-position-title">Official position title</label>
                    <input id="catalog-position-title" name="position_title" maxlength="200" placeholder="e.g. Administrative Assistant II" required>
                </div>
                <div class="hris-employee-field">
                    <label for="catalog-salary-grade">Salary grade (optional)</label>
                    <input id="catalog-salary-grade" name="salary_grade" type="number" min="1" max="255" step="1">
                </div>
                <button type="submit" class="hris-action hris-action-primary"><i class="bx bx-plus" aria-hidden="true"></i><span>Add position</span></button>
            </fieldset>
        </form>
        <section class="hris-position-entries" aria-label="Catalog entries">
            <div class="hris-employee-field hris-position-search">
                <label for="catalog-position-search">Find a position</label>
                <input id="catalog-position-search" type="search" maxlength="200" placeholder="Position title or salary grade" data-position-search disabled>
            </div>
            <p class="hris-position-empty" role="status" data-position-loading hidden>Loading positions…</p>
            <button type="button" class="hris-action" data-position-retry hidden>Retry loading positions</button>
            <p class="hris-position-empty" role="status" data-position-empty hidden></p>
            <table class="hris-record-table hris-position-table" aria-label="Non-teaching positions" role="table" hidden>
                <thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Position title</th><th scope="col" role="columnheader">Salary grade</th><th scope="col" role="columnheader">Actions</th></tr></thead>
                <tbody role="rowgroup" data-position-rows></tbody>
            </table>
            <template data-position-row>
                <tr role="row">
                    <th scope="row" role="rowheader" data-position-title></th>
                    <td role="cell" data-label="Salary grade" data-position-grade></td>
                    <td role="cell" class="hris-position-actions-cell">
                        <div class="hris-position-actions">
                            <button type="button" class="hris-action hris-action-pill hris-tone-blue" data-edit-position><i class="bx bx-edit-alt" aria-hidden="true"></i> Edit</button>
                            <button type="button" class="hris-action hris-action-pill hris-action-danger" data-delete-position aria-haspopup="dialog"><i class="bx bx-trash" aria-hidden="true"></i> Delete</button>
                        </div>
                    </td>
                </tr>
            </template>
        </section>
    </div>
</dialog>

<dialog class="hris-confirm-dialog hris-position-delete" aria-labelledby="delete-position-title" aria-describedby="delete-position-name" data-position-confirm>
    <span class="hris-confirm-icon"><i class="bx bx-trash" aria-hidden="true"></i></span>
    <h2 id="delete-position-title">Delete position?</h2>
    <p><strong id="delete-position-name" data-delete-position-name></strong></p>
    <div class="hris-account-alert hris-account-alert-error" role="alert" data-delete-position-error hidden>
        <i class="bx bx-error-circle" aria-hidden="true"></i><span></span>
    </div>
    <div class="hris-confirm-actions">
        <button type="button" class="hris-action" data-cancel-position-delete autofocus>Cancel</button>
        <button type="button" class="hris-action hris-action-danger-primary" data-confirm-position-delete>Delete position</button>
    </div>
</dialog>
