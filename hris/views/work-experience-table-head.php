<?php
$workHeadings = [
    'position' => '(Write in full/Do not abbreviate)',
    'employer' => '(Write in full/Do not abbreviate)',
    'pay_grade' => '(Format "00-0")',
    'government_service' => '(Y/ N)',
];
?>
<colgroup span="2" class="hris-work-date-columns"></colgroup>
<colgroup>
    <col class="hris-work-position"><col class="hris-work-employer"><col class="hris-work-salary">
    <col class="hris-work-grade"><col class="hris-work-appointment"><col class="hris-work-government"><?php if ($workEditable): ?><col class="hris-work-remove"><?php endif; ?>
</colgroup>
<thead>
    <tr>
        <th scope="colgroup" colspan="2"><span class="hris-work-item-number">28.</span>INCLUSIVE DATES<small>(dd/mm/yyyy)</small></th>
        <?php foreach (array_slice($workColumns, 2) as $key => $label): ?>
            <th scope="col" rowspan="2">
                <?= synk_hris_h($label) ?>
                <?php if (isset($workHeadings[$key])): ?><small><?= synk_hris_h($workHeadings[$key]) ?></small><?php endif; ?>
            </th>
        <?php endforeach; ?>
        <?php if ($workEditable): ?><th scope="col" rowspan="2" aria-label="Actions"></th><?php endif; ?>
    </tr>
    <tr><th scope="col">From</th><th scope="col">To</th></tr>
</thead>
