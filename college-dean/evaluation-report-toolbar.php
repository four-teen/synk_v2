<?php if (!isset($sefReportForm)) { http_response_code(404); exit; } ?>
<section class="card mb-3 sef-print-tools" aria-label="Report options">
    <div class="card-body d-flex flex-wrap align-items-end justify-content-between gap-3">
        <form class="sef-report-filter d-flex flex-wrap align-items-end gap-2" method="get" action="<?= sef_h($sefReportForm['href']) ?>">
            <div class="sef-report-filter-field">
                <label class="form-label" for="sefReportFaculty">Faculty</label>
                <select class="form-select" id="sefReportFaculty" name="faculty_id"<?= $sefReportIsConsolidated ? '' : ' required' ?><?= $sefReportResults ? '' : ' disabled' ?>>
                    <option value=""><?= $sefReportIsConsolidated ? 'All faculty' : 'Select a faculty member' ?></option>
                    <?php foreach ($sefReportResults as $option): ?>
                    <option value="<?= (int)$option['faculty_id'] ?>"<?= $sefReportFacultyId === (int)$option['faculty_id'] ? ' selected' : '' ?>><?= sef_h($option['faculty_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-outline-primary" type="submit"<?= $sefReportResults ? '' : ' disabled' ?>>View Report</button>
        </form>
        <div class="d-flex flex-wrap gap-2">
            <?php if ($sefReportVisible): ?>
            <button class="btn btn-primary" type="button" id="sefPrintReport">
                <i class="bx bx-printer" aria-hidden="true"></i> Print / Save PDF
            </button>
            <?php endif; ?>
            <a class="btn btn-outline-secondary" href="../backend/download_evaluation_report_template.php?form=<?= sef_h($sefReportKey) ?>">
                <i class="bx bx-download" aria-hidden="true"></i> Download Word Template
            </a>
            <?php if ($sefReportKey === 'individual' && $faculty): ?>
            <a class="btn btn-outline-secondary" href="<?= sef_h(sef_url('faculty-performance.php', $sefQuery)) ?>">Back to Results</a>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php if (!$sefReportVisible && $sefError === ''): ?>
<p class="text-muted"><?= $sefReportResults ? 'Select a faculty member to preview the report.' : 'No faculty are available for this college and academic term.' ?></p>
<?php endif; ?>
