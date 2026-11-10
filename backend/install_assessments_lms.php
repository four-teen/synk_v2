<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/db_lms.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$lms = synk_database_connection_lms($conn);
$database = $lms->query('SELECT DATABASE() AS name')->fetch_assoc()['name'];
$tables = $lms->query('SHOW TABLES')->fetch_all(MYSQLI_NUM);
foreach ($tables as $table) {
    if (strpos($table[0], 'tbl_management_') === 0) throw new RuntimeException('Management storage is not an LMS installation target.');
}
$sql = file_get_contents(__DIR__ . '/sql/assessments_lms.sql');
if ($sql === false) throw new RuntimeException('The LMS assessment schema is missing.');
$lms->multi_query($sql);
do {
    $result = $lms->store_result();
    if ($result instanceof mysqli_result) $result->free();
    if (!$lms->more_results()) break;
    $lms->next_result();
} while (true);
$instructionsColumn = $lms->query("SHOW COLUMNS FROM tbl_lms_assessments LIKE 'instructions'")->fetch_assoc();
if (($instructionsColumn['Type'] ?? '') === 'text') {
    $lms->query('ALTER TABLE tbl_lms_assessments MODIFY instructions MEDIUMTEXT NOT NULL');
}
echo 'Assessment tables are ready in the separate LMS database: ', $database, PHP_EOL;
