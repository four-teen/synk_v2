<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../hr_faculty_pds_helper.php';
synk_hr_pds_ensure($conn);
echo "All faculty HR PDS section and private attachment tables are ready. Existing account and faculty data are unchanged.",PHP_EOL;
