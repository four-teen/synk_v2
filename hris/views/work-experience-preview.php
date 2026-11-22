<?php
$workColumns = synk_hris_work_columns();
$workRows = [];
foreach ($sectionData['values']['work'] ?? [] as $workRecord) {
    $cells = array_intersect_key($workRecord, $workColumns);
    if (($workRecord['current'] ?? '') === '1') $cells['date_to'] = 'PRESENT';
    if (!array_filter($cells, static function (string $value): bool { return trim($value) !== ''; })) continue;

    foreach (['date_from', 'date_to'] as $dateKey) {
        $cells[$dateKey] = preg_replace('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D', '$3/$2/$1', $cells[$dateKey] ?? '');
    }
    if (is_numeric($cells['salary'] ?? '')) $cells['salary'] = '₱' . number_format((float)$cells['salary'], 2);
    $cells['government_service'] = ['Yes' => 'Y', 'No' => 'N'][$cells['government_service'] ?? ''] ?? '';
    $workRows[] = $cells;
}
?>
<?php if (!$workRows): ?>
    <p class="hris-preview-note">No work experience recorded.</p>
<?php else: ?>
    <div class="hris-work-scroll hris-work-preview" tabindex="0" role="region" aria-label="Work experience table">
        <table class="hris-work-table" aria-label="Work experience">
            <?php $workEditable = false; require __DIR__ . '/work-experience-table-head.php'; ?>
            <tbody>
                <?php foreach ($workRows as $cells): ?>
                    <tr>
                        <?php foreach ($workColumns as $key => $label): ?>
                            <td<?= $key === 'salary' ? ' class="hris-work-preview-salary"' : '' ?>><?= synk_hris_h($cells[$key] ?? '') ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
