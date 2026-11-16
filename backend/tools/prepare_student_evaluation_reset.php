<?php
// Additive preparation only. No existing records are deleted or changed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../db.php';
$sql = file_get_contents(__DIR__.'/../sql/student_evaluation_reset.sql');
if ($sql === false || !$conn->query($sql)) throw new RuntimeException('Unable to prepare student evaluation reset history.');
echo 'Student evaluation reset history is ready. Existing records are unchanged.', PHP_EOL;
