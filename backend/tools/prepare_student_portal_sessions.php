<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../db.php';
$sql = file_get_contents(__DIR__.'/../sql/phase31_student_portal_sessions.sql');
if (!$conn->query($sql)) throw new RuntimeException('Unable to prepare student session tracking.');
echo 'Student session tracking is ready. Existing records are unchanged.', PHP_EOL;
