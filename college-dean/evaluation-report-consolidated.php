<?php if (!isset($sefReportGroups, $sefScope, $sefReportForm)) { http_response_code(404); exit; }
$sefCampusName = trim($sefScope['campus_name']);
if (!preg_match('/\bcampus$/i', $sefCampusName)) {
    $sefCampusName .= ' Campus';
}
?>
<div class="sef-report-sheet sef-word-sheet sef-word-consolidated">
    <?php require __DIR__.'/evaluation-report-stationery.php'; ?>
    <div class="sef-report-body">
        <div class="sef-consolidated-heading">
            <h1 class="sef-word-title" id="sefWordReportTitle">CONSOLIDATED FACULTY PERFORMANCE EVALUATION</h1>
            <p><?= sef_h(synk_sef_report_term_label($sefTerm)) ?></p>
            <p class="sef-consolidated-campus"><?= sef_h($sefCampusName) ?></p>
        </div>

        <table class="sef-word-table sef-consolidated-table" aria-label="Faculty Performance Rating">
            <colgroup><col><col></colgroup>
            <colgroup><col><col></colgroup>
            <colgroup><col><col></colgroup>
            <colgroup><col></colgroup>
            <thead>
                <tr>
                    <th scope="col" rowspan="2">No</th>
                    <th scope="col" rowspan="2">NAME OF FACULTY</th>
                    <th colspan="4">FACULTY PERFORMANCE RATING</th>
                    <th scope="col" rowspan="2">Total<br>(100%)</th>
                </tr>
                <tr>
                    <th scope="colgroup" colspan="2">Students<br>Rating (60%)</th>
                    <th scope="colgroup" colspan="2">Supervisors Rating<br>(40%)</th>
                </tr>
            </thead>
            <?php foreach ($sefReportGroups as $heading => $members):
                $rowCount = $heading === 'III. OTHER FACULTY' ? count($members) : max(5, count($members));
            ?>
            <tbody>
                <tr class="sef-consolidated-group">
                    <?php if ($heading === 'II. CONTRACT OF SERVICE FACULTY'): ?>
                    <th scope="rowgroup" colspan="4"><?= sef_h($heading) ?></th><td></td><td></td><td></td>
                    <?php else: ?>
                    <th scope="rowgroup" colspan="7"><?= sef_h($heading) ?></th>
                    <?php endif; ?>
                </tr>
                <?php for ($index = 0; $index < $rowCount; $index++):
                    $member = $members[$index] ?? null;
                ?>
                <tr<?= $member ? ' data-faculty-id="'.(int)$member['faculty_id'].'"' : '' ?>>
                    <td><?= $index + 1 ?>.</td>
                    <td><?= sef_h($member['supervisor_record']['faculty_name'] ?? $member['faculty_name'] ?? '') ?></td>
                    <?php foreach ([$member['student']['score'] ?? null, $member['supervisor'] ?? null] as $score): ?>
                    <td><?= $score ? number_format($score['mean'], 2) : ($member ? 'Pending' : '') ?></td>
                    <td><?= $score ? number_format($score['component'], 2).'%' : '' ?></td>
                    <?php endforeach; ?>
                    <td><?= $member ? ($member['total'] === null ? 'Pending' : number_format($member['total'], 2).'%') : '' ?></td>
                </tr>
                <?php endfor; ?>
            </tbody>
            <?php endforeach; ?>
        </table>

        <div class="sef-consolidated-signatures">
            <section>
                <p>Prepared by:</p>
                <div>Chairperson, Committee on Faculty evaluation</div>
            </section>
            <section>
                <p>Checked:</p>
                <div>College Dean</div>
            </section>
            <section>
                <p>Recommending Approval:</p>
                <div>Campus Director</div>
            </section>
            <section>
                <p>Approved:</p>
                <div>Vice President for Academic Affairs</div>
            </section>
        </div>
    </div>
</div>
