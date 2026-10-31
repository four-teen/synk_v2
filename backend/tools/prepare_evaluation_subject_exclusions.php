<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../db.php';
$sql=file_get_contents(__DIR__.'/../sql/phase36_evaluation_subject_exclusions.sql');
if ($sql===false || !$conn->multi_query($sql)) throw new RuntimeException('Unable to prepare evaluation subject exclusions.');
do {
    if ($result=$conn->store_result()) $result->free();
    if (!$conn->more_results()) break;
    if (!$conn->next_result()) throw new RuntimeException('Unable to finish evaluation subject exclusions.');
} while (true);
echo 'Evaluation subject exclusions are ready. Existing responses and academic data are unchanged.',PHP_EOL;
