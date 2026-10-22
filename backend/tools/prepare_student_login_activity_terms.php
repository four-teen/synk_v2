<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../db.php';
$sql = file_get_contents(__DIR__.'/../sql/phase32_student_login_activity_terms.sql');
if (!$conn->multi_query($sql)) throw new RuntimeException('Unable to prepare academic-term login tracking.');
do {
    $result = $conn->store_result();
    if ($result instanceof mysqli_result) $result->free();
} while ($conn->more_results() && $conn->next_result());
if ($conn->errno) throw new RuntimeException('Unable to prepare academic-term login tracking.');
$recoverySql = file_get_contents(__DIR__.'/../sql/phase33_student_login_activity_term_recovery.sql');
if (!$conn->query($recoverySql)) throw new RuntimeException('Unable to recover known-term login history.');
$recovered = $conn->affected_rows;
$remaining = (int)$conn->query('SELECT COUNT(*) AS n FROM tbl_student_login_activity WHERE ay_id IS NULL OR semester IS NULL')->fetch_assoc()['n'];
echo 'Academic-term login tracking is ready. Recovered ', $recovered,
    ' events from the known current-term period; ', $remaining, ' events still have an unknown term.', PHP_EOL;
