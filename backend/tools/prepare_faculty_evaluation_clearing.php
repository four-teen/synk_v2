<?php
// Additive setup only. Never clears, relabels or imports evaluations.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../db.php';
$sql=file_get_contents(__DIR__.'/../sql/phase35_college_faculty_evaluation_clear_history.sql');
if ($sql===false || !$conn->query($sql)) throw new RuntimeException('Unable to prepare faculty evaluation clearing history.');
echo 'Faculty evaluation clearing history is ready. Existing evaluations are unchanged.',PHP_EOL;
