<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../db.php';
$sql = file_get_contents(__DIR__ . '/../sql/management_contribution_status.sql');
if ($sql === false) throw new RuntimeException('The contribution status migration is unavailable.');
$conn->multi_query($sql);
do {
    $result = $conn->store_result();
    if ($result instanceof mysqli_result) $result->free();
    if (!$conn->more_results()) break;
} while ($conn->next_result());
echo "Contribution statuses prepared: ACTIVE / INACTIVE. New contributions default to ACTIVE.\n";
