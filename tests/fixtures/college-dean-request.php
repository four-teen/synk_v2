<?php
// Real request handlers run inside savepoints. No fixture records are committed.
if (PHP_SAPI !== 'cli' && !defined('SYNK_COLLEGE_DEAN_QA_HTTP')) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__, 2);
require_once $root . '/backend/db.php';
require_once $root . '/backend/college_dean_helper.php';
require_once $root . '/backend/professor_portal_helper.php';

class CollegeDeanRequestConnection extends mysqli
{
    #[\ReturnTypeWillChange]
    public function query($query, $result_mode = MYSQLI_STORE_RESULT)
    {
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $query)) {
            throw new RuntimeException('Request tests must not execute schema changes.');
        }
        return parent::query($query, $result_mode);
    }
    public function startFixtures(): void { parent::begin_transaction(); }
    public function discardFixtures(): void { parent::rollback(); }
    public function begin_transaction($flags = null, $name = null): bool { return $this->query('SAVEPOINT college_dean_request'); }
    public function commit($flags = null, $name = null): bool { return $this->query('RELEASE SAVEPOINT college_dean_request'); }
    public function rollback($flags = null, $name = null): bool
    {
        $this->query('ROLLBACK TO SAVEPOINT college_dean_request');
        return $this->query('RELEASE SAVEPOINT college_dean_request');
    }
}
$conn->close();
$conn = new CollegeDeanRequestConnection($servername, $username, $password, $dbase);
$conn->set_charset('utf8mb4');
foreach (['tbl_useraccount', 'tbl_useraccount_roles', 'tbl_useraccount_faculty_links', 'tbl_user_college_access', 'tbl_useraccount_registrar_scopes', 'tbl_enrollment_headers', 'tbl_enrollment_subjects', 'tbl_enrollment_workflow_logs'] as $table) {
    $engine = $conn->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetch_assoc();
    if (strtolower((string)($engine['ENGINE'] ?? '')) !== 'innodb') throw new RuntimeException('Transactional table required: ' . $table);
}
$scenario = PHP_SAPI === 'cli' ? ($argv[1] ?? 'save_multi') : (string)($_GET['scenario'] ?? '');
$conn->startFixtures();
$term = synk_fetch_current_academic_term($conn);
$section = $conn->query("SELECT s.section_id, s.program_id, p.college_id, c.campus_id FROM tbl_sections s JOIN tbl_program p ON p.program_id=s.program_id JOIN tbl_college c ON c.college_id=p.college_id WHERE c.status='active' AND s.ay_id=" . (int)$term['ay_id'] . ' AND s.semester=' . (int)$term['semester'] . ' ORDER BY s.section_id LIMIT 1')->fetch_assoc();
if (!$section) throw new RuntimeException('An active current-term section is required.');
$deanCollege = (int)$section['college_id'];
$chairCollege = (int)$conn->query("SELECT college_id FROM tbl_college WHERE status='active' AND college_id <> {$deanCollege} ORDER BY college_id LIMIT 1")->fetch_assoc()['college_id'];
$tag = 'DeanQA' . bin2hex(random_bytes(8));
$email = strtolower($tag) . '@sksu.edu.ph';
$conn->query("INSERT INTO tbl_faculty (first_name,last_name,status) VALUES ('College Dean','{$tag}','active')");
$facultyId = (int)$conn->insert_id;
$conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_id,college_dean_college_id) VALUES ('{$tag}','{$email}','unusable-test-password','college_dean','active',{$chairCollege},{$deanCollege})");
$userId = (int)$conn->insert_id;
$roles = synk_supported_module_roles();
if (synk_persist_useraccount_roles($conn, $userId, $roles, 'college_dean') !== null
    || synk_persist_scheduler_college_access($conn, $userId, $roles, [$chairCollege], $chairCollege) !== null
    || synk_persist_professor_faculty_link($conn, $userId, $roles, $facultyId) !== null
    || synk_persist_registrar_scope($conn, $userId, $roles, (int)$section['campus_id']) !== null) {
    throw new RuntimeException('Fixture account setup failed.');
}
$base = ['username' => $tag, 'email' => $email, 'roles' => $roles, 'primary_role' => 'college_dean', 'status' => 'active',
    'program_chair_college_id' => $chairCollege, 'college_dean_college_id' => $deanCollege,
    'college_ids' => [$chairCollege], 'default_college_id' => $chairCollege, 'faculty_id' => $facultyId, 'registrar_campus_id' => (int)$section['campus_id']];
session_save_path($root . '/temp');
session_start();
$_SESSION = ['user_id' => $userId, 'role' => 'admin', 'college_id' => $chairCollege];
$handler = 'backend/query_accounts.php';
$_POST = []; $_GET = [];
switch ($scenario) {
    case 'save_standalone':
    case 'save_multi':
        $base['email'] = 'new-' . $email;
        if ($scenario === 'save_standalone') $base['roles'] = ['college_dean'];
        if ($scenario === 'save_multi') {
            $conn->query("INSERT INTO tbl_faculty (first_name,last_name,status) VALUES ('New College Dean','{$tag}','active')");
            $base['faculty_id'] = (int)$conn->insert_id;
        }
        $_POST = $base + ['save_account' => 1]; break;
    case 'update_multi':
        $base['primary_role'] = 'program_chair';
        $_POST = $base + ['update_account' => 1, 'user_id' => $userId]; break;
    case 'update_remove':
        $base['roles'] = array_values(array_diff($roles, ['college_dean']));
        $base['primary_role'] = 'program_chair';
        $_POST = $base + ['update_account' => 1, 'user_id' => $userId]; break;
    case 'missing_scope':
    case 'invalid_scope':
        $base['college_dean_college_id'] = $scenario === 'missing_scope' ? '' : 2147483647;
        $_POST = $base + ['update_account' => 1, 'user_id' => $userId]; break;
    case 'duplicate_email':
        $_POST = $base + ['save_account' => 1]; break;
    case 'matrix_assign':
    case 'matrix_missing':
    case 'matrix_remove':
        if ($scenario !== 'matrix_remove') synk_persist_useraccount_roles($conn, $userId, ['admin','program_chair'], 'admin');
        if ($scenario === 'matrix_missing') $conn->query('UPDATE tbl_useraccount SET college_dean_college_id=NULL WHERE user_id=' . $userId);
        $_POST = ['set_role' => 1, 'role' => 'college_dean', 'assigned' => $scenario === 'matrix_remove' ? '0' : '1', 'user_ids' => [$userId], 'response_format' => 'json']; break;
    case 'load':
        $_POST = ['load_accounts' => 1, 'response_format' => 'json']; break;
    case 'login':
        $_SESSION = [];
        $account = synk_find_useraccount_by_id($conn, $userId);
        $roleRows = synk_fetch_useraccount_role_rows($conn, $userId, $account['role']);
        synk_store_pending_role_login($account, synk_filter_loginable_role_rows($conn, $account, $roleRows));
        $_POST = ['complete_role_login' => 1, 'selected_role' => 'college_dean'];
        $handler = 'index.php'; break;
    case 'prospectus':
    case 'prospectus_outside':
        $_SESSION['role'] = 'college_dean';
        $comparison = $scenario === 'prospectus' ? '=' : '<>';
        $header = $conn->query("SELECT h.prospectus_id FROM tbl_prospectus_header h JOIN tbl_program p ON p.program_id=h.program_id WHERE p.college_id {$comparison} {$deanCollege} LIMIT 1")->fetch_assoc();
        if (!$header) throw new RuntimeException('A prospectus fixture is required.');
        $_GET = ['prospectus_id' => (int)$header['prospectus_id']];
        $handler = 'backend/query_view_prospectus.php'; break;
    case 'offerings':
    case 'offerings_outside':
        $_SESSION['role'] = 'college_dean';
        $_GET = ['load_section_offerings' => 1, 'section_id' => $scenario === 'offerings' ? (int)$section['section_id'] : 2147483647];
        $handler = 'backend/query_college_dean_enrollment.php'; break;
    case 'draft_save':
    case 'draft_submit':
        $_SESSION['role'] = 'college_dean';
        $offeringRows = synk_program_chair_fetch_section_scheduled_offerings($conn, $deanCollege, (int)$section['section_id'], (int)$term['ay_id'], (int)$term['semester']);
        $subjects = array_slice($offeringRows, 0, 1);
        if (!$subjects) throw new RuntimeException('A scheduled offering is required.');
        $draftPayload = ['program_id' => (int)$section['program_id'], 'section_id' => (int)$section['section_id'], 'student_number' => $tag, 'last_name' => $tag, 'first_name' => 'Test', 'sex' => 'Male', 'birthdate' => '2000-01-01', 'email_address' => $email, 'contact_number' => '09000000000', 'draft_items_json' => json_encode($subjects)];
        if ($scenario === 'draft_save') {
            $_POST = $draftPayload + ['save_draft' => 1];
        } else {
            $context = synk_enrollment_fetch_section_context($conn, (int)$section['section_id'], $deanCollege, (int)$term['ay_id'], (int)$term['semester']);
            $saved = synk_enrollment_save_program_chair_draft($conn, $userId, $deanCollege, $context, $draftPayload, $subjects, 0, 'college_dean');
            if (isset($saved['error'])) throw new RuntimeException('Draft fixture failed: ' . $saved['error']);
            $_POST = ['submit_draft' => 1, 'enrollment_id' => (int)$saved['detail']['enrollment_id']];
        }
        $handler = 'backend/query_college_dean_drafts.php'; break;
    case 'revoked':
        $_SESSION['role'] = 'college_dean';
        $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId} AND role='college_dean'");
        $handler = 'backend/query_college_dean_enrollment.php'; break;
    case 'unauthorized':
        $_SESSION['role'] = 'program_chair';
        $handler = 'backend/query_college_dean_drafts.php'; break;
    case 'dashboard_fixed':
    case 'dashboard_reassigned':
        $_SESSION['role'] = 'college_dean';
        if ($scenario === 'dashboard_reassigned') {
            $conn->query('UPDATE tbl_useraccount SET college_dean_college_id=' . $chairCollege . ' WHERE user_id=' . $userId);
            $_SESSION['college_id'] = $deanCollege;
        }
        $_GET = ['campus_id' => 'all', 'college_id' => 2147483647];
        $handler = 'college-dean/index.php'; break;
    case 'dashboard_heatmap':
    case 'dashboard_heatmap_other':
    case 'dashboard_heatmap_all':
    case 'dashboard_heatmap_bad_csrf':
    case 'dashboard_heatmap_room':
        $_SESSION['role'] = 'college_dean';
        $_SESSION['csrf_token'] = 'dean-dashboard-qa';
        $_POST = ['csrf_token' => $scenario === 'dashboard_heatmap_bad_csrf' ? 'invalid' : 'dean-dashboard-qa'];
        if ($scenario === 'dashboard_heatmap_other') $_POST['college_id'] = $chairCollege;
        if ($scenario === 'dashboard_heatmap_all') $_POST['campus_id'] = 'all';
        if ($scenario === 'dashboard_heatmap_room') $_POST['room_id'] = 2147483647;
        $handler = 'backend/dashboard_college_dean_schedule_heatmap.php'; break;
    case 'dashboard_workload':
    case 'dashboard_workload_other':
        $_SESSION['role'] = 'college_dean';
        $faculty = $conn->query('SELECT fw.faculty_id FROM tbl_faculty_workload_sched fw JOIN tbl_class_schedule cs ON cs.schedule_id=fw.schedule_id JOIN tbl_prospectus_offering o ON o.offering_id=cs.offering_id JOIN tbl_program p ON p.program_id=o.program_id WHERE p.college_id=' . $deanCollege . ' AND fw.ay_id=' . (int)$term['ay_id'] . ' AND fw.semester=' . (int)$term['semester'] . ' LIMIT 1')->fetch_assoc();
        if (!$faculty) throw new RuntimeException('A current faculty workload is required.');
        $_POST = ['faculty_id' => (int)$faculty['faculty_id'], 'include_external' => 1];
        if ($scenario === 'dashboard_workload_other') $_POST['college_id'] = $chairCollege;
        $handler = 'backend/query_college_dean_faculty_workload.php'; break;
    case 'dashboard_need':
        $_SESSION['role'] = 'college_dean';
        $conn->query("INSERT INTO tbl_faculty_need (college_id,ay_id,semester,need_label,status) VALUES ({$deanCollege}," . (int)$term['ay_id'] . ',' . (int)$term['semester'] . ",'{$tag}','active')");
        $_POST = ['assignee_type' => 'faculty_need', 'faculty_need_id' => (int)$conn->insert_id];
        $handler = 'backend/query_college_dean_faculty_workload.php'; break;
    case 'dashboard_matrix':
    case 'dashboard_matrix_other':
    case 'dashboard_matrix_bad_csrf':
        $_SESSION['role'] = 'college_dean';
        $_SESSION['csrf_token'] = 'dean-dashboard-qa';
        $_POST = ['csrf_token' => $scenario === 'dashboard_matrix_bad_csrf' ? 'invalid' : 'dean-dashboard-qa', 'ay_id' => 2147483647, 'semester' => 3];
        if ($scenario === 'dashboard_matrix_other') $_POST['college_id'] = $chairCollege;
        $handler = 'backend/load_college_dean_room_time_matrix.php'; break;
    case 'dashboard_revoked':
    case 'dashboard_inactive':
    case 'dashboard_unauthorized':
        $_SESSION['role'] = 'college_dean';
        if ($scenario === 'dashboard_revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId} AND role='college_dean'");
        if ($scenario === 'dashboard_inactive') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$userId}");
        if ($scenario === 'dashboard_unauthorized') $_SESSION['role'] = 'program_chair';
        $handler = 'backend/dashboard_college_dean_schedule_heatmap.php'; break;
    default:
        if (strpos($scenario, 'portal_') !== 0 || !in_array(substr($scenario, 7), ['index','programs','enrollment','drafts','students','reports'], true)) throw new RuntimeException('Unknown scenario.');
        $_SESSION['role'] = 'college_dean';
        $handler = 'college-dean/' . substr($scenario, 7) . '.php';
}
session_write_close();
ob_start();
register_shutdown_function(static function () use ($conn, $userId, $email, $scenario, $deanCollege, $chairCollege): void {
    $body = '';
    while (ob_get_level() > 0) $body = ob_get_clean() . $body;
    try {
        $response = json_decode($body, true);
        if ($scenario === 'load' && is_array($response)) {
            $rows = array_column($response['accounts'], null, 'id');
            $response['accounts'] = [$rows[$userId]];
        }
        $account = $conn->query("SELECT * FROM tbl_useraccount WHERE email IN ('{$email}','new-{$email}') ORDER BY user_id DESC LIMIT 1")->fetch_assoc();
        $roleRows = synk_fetch_useraccount_role_rows($conn, (int)$account['user_id'], $account['role']);
        $logs = $conn->query('SELECT acted_by_role,action_type FROM tbl_enrollment_workflow_logs WHERE acted_by_user_id=' . $userId . ' ORDER BY workflow_log_id')->fetch_all(MYSQLI_ASSOC);
        $redirect = '';
        foreach (headers_list() as $header) {
            if (stripos($header, 'Location:') === 0) $redirect = trim(substr($header, 9));
        }
        $result = ['response' => is_array($response) ? $response : trim($body), 'http_status' => http_response_code() ?: 200, 'redirect' => $redirect,
            'roles' => array_column($roleRows, 'role'), 'primary_role' => $account['role'], 'college_id' => (int)$account['college_id'],
            'college_dean_college_id' => (int)($account['college_dean_college_id'] ?? 0), 'dean_college' => $deanCollege, 'chair_college' => $chairCollege,
            'session_role' => $_SESSION['role'] ?? '', 'session_college' => (int)($_SESSION['college_id'] ?? 0), 'logs' => $logs];
        $conn->discardFixtures();
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
    } catch (Throwable $error) {
        $conn->discardFixtures();
        if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
        http_response_code(500);
        echo json_encode(['fixture_error' => $error->getMessage(), 'output' => substr($body, 0, 1000)]);
    }
});
chdir(dirname($root . '/' . $handler));
require $root . '/' . $handler;
