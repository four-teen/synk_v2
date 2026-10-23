<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../db.php';
require_once __DIR__.'/../schema_helper.php';
$migrations = [];
if (!synk_table_exists($conn,'tbl_faculty_term_designations')) $migrations[]='phase23_faculty_term_designations.sql';
$migrations[]='phase34_scheduler_planning_terms.sql';
foreach ($migrations as $migration) {
    $sql=file_get_contents(__DIR__.'/../sql/'.$migration);
    $conn->multi_query($sql);
    do { $result=$conn->store_result(); if($result instanceof mysqli_result)$result->free(); } while($conn->more_results() && $conn->next_result());
    echo 'Prepared '.$migration.PHP_EOL;
}
echo 'Scheduler planning storage ready. Existing active-term settings are unchanged.'.PHP_EOL;
