<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../db.php';
$sql=file_get_contents(__DIR__.'/../sql/phase27_student_login_activity.sql');
if (!$conn->query($sql)) throw new RuntimeException('Unable to prepare student login activity.');
echo 'Student login activity schema is ready. Existing student records and login diagnostics are unchanged.',PHP_EOL;
