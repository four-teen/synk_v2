<?php
if (!isset($sefReportKey)) { http_response_code(404); exit; }
$sefPage = 'report';
require __DIR__.'/../backend/supervisor_evaluation_page.php';
require __DIR__.'/evaluation-report-selection.php';
require_once __DIR__.'/../backend/evaluation_report_data.php';

if ($sefReportVisible) {
    if ($sefReportIsConsolidated) {
        $sefReportGroups = synk_sef_report_groups($conn, $faculty ? [$sefReportFacultyId => $faculty] : $sefReportResults);
    } else {
        $sefFacultyInfo = synk_sef_report_faculty_info($conn, $sefScope, $sefTerm, $faculty);
        $sefStudentScore = $faculty['student']['score'];
        $sefSupervisorScore = $faculty['supervisor'];
    }
}

require __DIR__.'/evaluation-page-header.php';
require __DIR__.'/evaluation-report-toolbar.php';
if ($sefReportVisible): ?>
<article class="sef-report-preview sef-word-report" id="sefWordReport" aria-labelledby="sefWordReportTitle" tabindex="0">
    <?php
    if ($sefReportIsConsolidated) {
        require __DIR__.'/evaluation-report-consolidated.php';
    } elseif ($sefReportKey === 'training') {
        require __DIR__.'/evaluation-report-training.php';
    } else {
        require __DIR__.'/evaluation-report-feedback.php';
    }
    ?>
</article>
<?php endif; require __DIR__.'/evaluation-page-footer.php'; ?>
