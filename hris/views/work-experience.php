<?php
$workColumns = synk_hris_work_columns();
$workRules = synk_hr_pds_record_lists('work')['work'];
$workPositions = [];
foreach (['INSTRUCTOR' => 3, 'ASSISTANT PROFESSOR' => 4, 'ASSOCIATE PROFESSOR' => 5, 'PROFESSOR' => 6] as $rank => $levels) {
    foreach (array_slice(['I', 'II', 'III', 'IV', 'V', 'VI'], 0, $levels) as $level) {
        $workPositions[] = $rank . ' ' . $level;
    }
}
$workPositions = array_merge($workPositions, ['JOB ORDER (JO)', 'CONTRACT OF SERVICE (COS)', 'PART TIME']);
$workAppointments = ['PERMANENT', 'TEMPORARY', 'JOB ORDER', 'CONTRACT OF SERVICE'];
?>
<dialog class="hris-work-dialog" id="work-experience" aria-labelledby="work-experience-title"
    data-csrf="<?= synk_hris_h($token) ?>" data-limit="<?= $workRules['limit'] ?>">
    <form class="hris-work-form">
        <header>
            <h2 id="work-experience-title">V. WORK EXPERIENCE</h2>
            <p data-work-employee></p>
        </header>
        <div class="hris-work-body">
            <p class="hris-work-instructions">(Include private employment. Start from your recent work) Description of duties should be indicated in the attached Work Experience sheet.</p>
            <p id="work-date-format">Enter dates as dd/mm/yyyy or yyyy. Enter PRESENT in To for a current position.</p>
            <p data-work-status role="status"></p>
            <p data-work-error role="alert" hidden></p>
            <div class="hris-work-scroll" data-work-table hidden tabindex="0" role="region" aria-label="Work experience table">
                <table class="hris-work-table" aria-label="Work experience" aria-describedby="work-date-format">
                    <?php $workEditable = true; require __DIR__ . '/work-experience-table-head.php'; ?>
                    <tbody data-work-records></tbody>
                </table>
            </div>
            <button class="hris-action" type="button" data-work-add hidden><i class="bx bx-plus" aria-hidden="true"></i> Add row</button>
        </div>
        <footer>
            <button class="hris-action" type="button" data-work-close>Close</button>
            <button class="hris-action hris-action-primary" type="submit" data-work-save disabled>Save work experience</button>
        </footer>
    </form>
    <datalist id="work-position-options">
        <?php foreach ($workPositions as $position): ?>
            <option value="<?= synk_hris_h($position) ?>"></option>
        <?php endforeach; ?>
    </datalist>
    <datalist id="work-employer-options"><option value="SULTAN KUDARAT STATE UNIVERSITY"></option></datalist>
    <template data-work-template>
        <tr>
            <?php foreach ($workColumns as $key => $label): ?>
                <?php $rule = $workRules['fields'][$key]; ?>
                <td>
                    <?php if (isset($rule['options'])): ?>
                        <select data-work-field="<?= $key ?>" aria-label="<?= synk_hris_h($label) ?>">
                            <option value=""></option>
                            <option value="Yes">Y</option>
                            <option value="No">N</option>
                        </select>
                    <?php elseif ($key === 'appointment_status'): ?>
                        <select data-work-field="<?= $key ?>" aria-label="<?= synk_hris_h($label) ?>">
                            <option value=""></option>
                            <?php foreach ($workAppointments as $appointment): ?>
                                <option value="<?= $appointment ?>"><?= $appointment ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif (isset($rule['amount'])): ?>
                        <input data-work-field="<?= $key ?>" aria-label="<?= synk_hris_h($label) ?>" type="text" inputmode="decimal" placeholder="₱0.00">
                    <?php else: ?>
                        <input data-work-field="<?= $key ?>" aria-label="<?= synk_hris_h($label) ?>" type="text"
                            maxlength="<?= $rule['max'] ?? 20 ?>"
                            <?php if ($key === 'position'): ?> list="work-position-options" autocomplete="off"<?php endif; ?>
                            <?php if ($key === 'employer'): ?> list="work-employer-options" autocomplete="off"<?php endif; ?>
                            <?php if (!empty($rule['work_date'])): ?> placeholder="dd/mm/yyyy" aria-describedby="work-date-format" autocomplete="off" spellcheck="false"<?php endif; ?>>
                    <?php endif; ?>
                    <small data-work-field-error hidden></small>
                </td>
            <?php endforeach; ?>
            <td><button class="hris-work-remove-button" type="button" data-work-remove aria-label="Remove row" title="Remove row"><i class="bx bx-trash" aria-hidden="true"></i></button></td>
        </tr>
    </template>
</dialog>
