<?php
// Idempotent preparation for college evaluation controls. No responses are removed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../student_portal_helper.php';
synk_student_faculty_evaluation_ensure_schema($conn);
$unassigned = $conn->query('SELECT COUNT(*) AS n FROM tbl_student_faculty_evaluations WHERE college_id=0')->fetch_assoc();
echo 'Evaluation access schema is ready. Student evaluations default to closed; faculty results default to hidden.', PHP_EOL;
echo 'Historical responses requiring college ownership review: ', $unassigned['n'], PHP_EOL;
