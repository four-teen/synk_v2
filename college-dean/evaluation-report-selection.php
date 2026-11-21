<?php
if (!isset($sefReportKey, $sefActor)) { http_response_code(404); exit; }

require_once __DIR__.'/../backend/evaluation_report_forms.php';

$sefReportForm = synk_sef_report_forms()[$sefReportKey] ?? null;
if ($sefReportForm === null) {
    http_response_code(404);
    exit;
}

$sefTitle = $sefReportForm['title'];
$sefReportResults = synk_sef_performance($conn, $sefScope, $sefTerm, $sefActor);
$sefReportFacultyId = 0;
$faculty = null;
try {
    if (isset($_GET['faculty_id']) && $_GET['faculty_id'] !== '') {
        $sefReportFacultyId = synk_sef_int($_GET, 'faculty_id');
        $faculty = $sefReportResults[$sefReportFacultyId] ?? null;
        if ($faculty === null) {
            throw new OutOfBoundsException('This faculty member is outside your college and selected academic term.');
        }
    }
} catch (OutOfBoundsException $error) {
    http_response_code(404);
    $sefError = $error->getMessage();
} catch (DomainException $error) {
    http_response_code(422);
    $sefError = $error->getMessage();
}

$sefReportIsConsolidated = $sefReportKey === 'consolidated';
$sefReportVisible = $sefError === '' && ($faculty !== null || ($sefReportIsConsolidated && $sefReportResults));
