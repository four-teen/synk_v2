<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/db_lms.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$lms = synk_database_connection_lms($conn);
foreach ($lms->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) as $table) {
    if (strpos($table[0], 'tbl_management_') === 0) throw new RuntimeException('Management storage is not an LMS installation target.');
}
$sql = file_get_contents(__DIR__ . '/sql/grading_lms.sql');
if ($sql === false) throw new RuntimeException('The LMS grading schema is missing.');
$lms->multi_query($sql);
do {
    $result = $lms->store_result();
    if ($result instanceof mysqli_result) $result->free();
    if (!$lms->more_results()) break;
    $lms->next_result();
} while (true);
echo 'Grading system tables are ready in the separate LMS database.', PHP_EOL;
