<?php
// php backend/tools/prepare_management_violations.php — additive and repeatable.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../db.php';
$sql = file_get_contents(__DIR__ . '/../sql/create_management_violations.sql');
if ($sql === false) throw new RuntimeException('The violation schema is unavailable.');
$conn->query($sql);
echo "Management violation type and amount storage prepared. Existing data preserved.\n";
