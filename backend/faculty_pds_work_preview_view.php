<?php
if (!isset($workRecords)) { http_response_code(404); return; }
$workColumns = synk_hr_pds_work_columns();
$sheetFields = empty($showWorkExperienceSheet) ? [] : [
    'office_unit' => 'Division / office / unit',
    'supervisor' => 'Immediate supervisor',
    'duties' => 'Summary of actual duties',
    'accomplishments' => 'Accomplishments and contributions',
];
$hasWorkValue = static function ($value): bool { return trim((string)$value) !== ''; };
$workRows = [];
foreach ($workRecords as $workRecord) {
    $cells = array_intersect_key($workRecord, $workColumns);
    if (($workRecord['current'] ?? '') === '1') $cells['date_to'] = 'PRESENT';
    $sheetValues = array_filter(array_intersect_key($workRecord, $sheetFields), $hasWorkValue);
    if (!array_filter($cells, $hasWorkValue) && !$sheetValues) continue;

    foreach (['date_from', 'date_to'] as $dateKey) {
        $cells[$dateKey] = preg_replace('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', '$3/$2/$1', $cells[$dateKey] ?? '');
    }
    if (is_numeric($cells['salary'] ?? '')) $cells['salary'] = '₱' . number_format((float)$cells['salary'], 2);
    $cells['government_service'] = ['Yes' => 'Y', 'No' => 'N'][$cells['government_service'] ?? ''] ?? '';
    $workRows[] = [$cells, $sheetValues];
}
?>
<?php if (!$workRows): ?>
    <p class="pds-work-note">No work experience recorded.</p>
<?php else: ?>
    <div class="hris-work-scroll hris-work-preview" tabindex="0" role="region" aria-label="Work experience table">
        <table class="hris-work-table" aria-label="Work experience">
            <?php $workEditable = false; require __DIR__ . '/faculty_pds_work_table_head.php'; ?>
            <tbody>
                <?php foreach ($workRows as [$cells, $sheetValues]): ?>
                    <tr>
                        <?php foreach ($workColumns as $key => $label): ?>
                            <td<?= $key === 'salary' ? ' class="hris-work-preview-salary"' : '' ?>><?= htmlspecialchars($cells[$key] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <?php if ($sheetValues): ?>
                        <tr><td colspan="<?= count($workColumns) ?>">
                            <details class="pds-work-sheet">
                                <summary>Work Experience Sheet details</summary>
                                <dl>
                                    <?php foreach (array_intersect_key($sheetFields, $sheetValues) as $key => $label): ?>
                                        <dt><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                                        <dd><?= htmlspecialchars($sheetValues[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                                    <?php endforeach; ?>
                                </dl>
                            </details>
                        </td></tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
