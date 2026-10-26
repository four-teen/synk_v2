<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../db.php';
if (!$conn->query("SHOW TABLES LIKE 'tbl_management_organizations'")->num_rows) throw new RuntimeException('Management organizations must be installed first.');
$sql = file_get_contents(__DIR__ . '/../sql/create_management_period_settings.sql');
if ($sql === false) throw new RuntimeException('The examination term migration is unavailable.');
$conn->multi_query($sql);
do {
    $result = $conn->store_result();
    if ($result instanceof mysqli_result) $result->free();
    if (!$conn->more_results()) break;
} while ($conn->next_result());
if ($conn->errno) throw new RuntimeException('Unable to prepare examination term storage.');
echo "Management-only Midterm / Final storage prepared. Shared Academic Settings are unchanged.\n";
