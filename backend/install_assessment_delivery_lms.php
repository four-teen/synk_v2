<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/db.php';require_once __DIR__.'/db_lms.php';require_once __DIR__.'/assessment_helper_lms.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$lms=synk_database_connection_lms($conn);
if (!synk_assessment_ready_lms($lms)) throw new RuntimeException('Install LMS assessment tables first.');
foreach ($lms->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as $table) {
    if (strpos($table[0],'tbl_management_')===0) throw new RuntimeException('Management storage is not an LMS installation target.');
}
$sql=file_get_contents(__DIR__.'/sql/assessment_delivery_lms.sql');
if ($sql===false || !$lms->query($sql)) throw new RuntimeException('Unable to prepare LMS assessment delivery storage.');
if (!synk_assessment_snapshots_ready_lms($lms)) {
    $lms->query('ALTER TABLE tbl_lms_assessment_attempts ADD COLUMN assessment_snapshot_json MEDIUMTEXT NULL AFTER answers_json');
}
echo 'Assessment delivery storage is ready in the separate LMS database.',PHP_EOL;
