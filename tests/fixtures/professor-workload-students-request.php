<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__, 2);
require_once $root . '/backend/db.php';
require_once $root . '/backend/auth_useraccount.php';
require_once $root . '/backend/professor_workload_students_helper.php';
require_once __DIR__ . '/professor-workload-students-data.php';
$conn->begin_transaction();
try {
    $fixture = professor_students_fixtures($conn);
    $case = $argv[1] ?? 'valid';
    session_save_path(sys_get_temp_dir()); session_id('workloadrequestqa' . bin2hex(random_bytes(12))); session_start();
    $_SESSION = ['user_id'=>$fixture['user_id'],'role'=>'professor'];
    if ($case === 'anonymous') $_SESSION = [];
    if ($case === 'wrong_role') $_SESSION['role'] = 'student';
    if ($case === 'disabled') $conn->query('UPDATE tbl_useraccount SET status=\'inactive\' WHERE user_id=' . $fixture['user_id']);
    if ($case === 'revoked') $conn->query('UPDATE tbl_useraccount_roles SET status=\'inactive\' WHERE user_id=' . $fixture['user_id']);
    if ($case === 'unlinked') $conn->query('DELETE FROM tbl_useraccount_faculty_links WHERE user_id=' . $fixture['user_id']);
    if ($case === 'inactive_faculty') $conn->query('UPDATE tbl_faculty SET status=\'inactive\' WHERE faculty_id=' . $fixture['faculty_id']);
    if ($case === 'reassigned') $conn->query('DELETE FROM tbl_faculty_workload_sched WHERE faculty_id=' . $fixture['faculty_id']);
    $_GET = ['ay_id'=>(string)$fixture['term']['ay_id'], 'semester'=>(string)$fixture['term']['semester'],
        'offering_id'=>(string)$fixture['offerings'][0]['offering_id']];
    if ($case === 'foreign') $_GET['offering_id'] = (string)$fixture['offerings'][2]['offering_id'];
    if ($case === 'wrong_term') $_GET['ay_id'] = '999999999';
    if ($case === 'malformed') $_GET['offering_id'] = ['bad'];
    if ($case === 'bad_semester') $_GET['semester'] = '4';
    if ($case === 'forged_identity') { $_GET['faculty_id'] = '999999999'; $_GET['user_id'] = '999999999'; $_SESSION['faculty_id'] = 999999999; }
    session_write_close();
    $_SERVER['REQUEST_METHOD'] = $case === 'method' ? 'POST' : 'GET';
    ob_start(); require $root . '/professor/workload-students.php'; $body = ob_get_clean();
    echo json_encode(['status'=>http_response_code() ?: 200,'body'=>json_decode($body,true)]);
} finally {
    $conn->rollback();
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
}
