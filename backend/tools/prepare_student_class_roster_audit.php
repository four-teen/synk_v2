<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../db.php';
if (!$conn->query(file_get_contents(__DIR__.'/../sql/phase28_student_class_roster_audit.sql'))) throw new RuntimeException('Unable to prepare the roster audit table.');
echo 'Manual class roster audit is ready. Existing tables and records are unchanged.',PHP_EOL;
