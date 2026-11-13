<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/db.php';
require_once __DIR__.'/db_lms.php';
require_once __DIR__.'/announcement_helper_lms.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$lms = synk_database_connection_lms($conn);
foreach ($lms->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as $table) {
    if (strpos($table[0], 'tbl_management_') === 0) throw new RuntimeException('Management storage is not an LMS installation target.');
}
$sql = file_get_contents(__DIR__.'/sql/announcements_lms.sql');
if ($sql === false || !$lms->multi_query($sql)) throw new RuntimeException('Unable to prepare LMS announcements.');
do { if ($result = $lms->store_result()) $result->free(); } while ($lms->more_results() && $lms->next_result());
if (!synk_announcement_ready_lms($lms)) throw new RuntimeException('LMS announcement storage is incomplete.');
echo 'Announcement storage is ready in the separate LMS database.', PHP_EOL;
