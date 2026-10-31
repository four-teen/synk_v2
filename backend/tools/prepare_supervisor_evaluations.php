<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../db.php';
$sql=file_get_contents(__DIR__.'/../sql/phase37_supervisor_evaluations.sql');
if ($sql===false || !$conn->multi_query($sql)) throw new RuntimeException('Unable to prepare supervisor evaluations.');
do {
    if ($result=$conn->store_result()) $result->free();
    if (!$conn->more_results()) break;
    if (!$conn->next_result()) throw new RuntimeException('Unable to complete supervisor evaluation setup.');
} while (true);
echo 'Supervisor evaluation storage is ready. Collection defaults to closed; existing records are unchanged.',PHP_EOL;
