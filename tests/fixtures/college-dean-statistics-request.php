<?php
// Loopback-only fixtures; all records are discarded after each request.
if (!defined('SYNK_DEAN_STATISTICS_QA') || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(SYNK_DEAN_STATISTICS_QA, $_GET['qa'])) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__, 2);
require_once $root . '/backend/db.php';
require_once $root . '/backend/college_dean_statistics_helper.php';
$conn->begin_transaction();
$scenario = (string)($_GET['scenario'] ?? 'metrics');
$term = synk_fetch_current_academic_term($conn);
$otherSemester = $term['semester'] === 1 ? 2 : 1;
$campusId = (int)$conn->query("SELECT campus_id FROM tbl_campus WHERE status='active' ORDER BY campus_id LIMIT 1")->fetch_assoc()['campus_id'];
$tag = 'DeanStatisticsQA' . bin2hex(random_bytes(7));
$conn->query("INSERT INTO tbl_college (campus_id,college_code,college_name,status) VALUES ({$campusId},'QA','Statistics QA College','active')");
$collegeId = (int)$conn->insert_id;
$conn->query("INSERT INTO tbl_college (campus_id,college_code,college_name,status) VALUES ({$campusId},'QAF','Statistics Foreign College','active')");
$foreignCollege = (int)$conn->insert_id;
$scope = ['college_id' => $collegeId, 'campus_id' => $campusId];
$conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_dean_college_id)
    VALUES ('Statistics QA Dean','{$tag}@sksu.edu.ph','unusable-test-password','college_dean','active',{$collegeId})");
$actorId = (int)$conn->insert_id;
synk_persist_useraccount_roles($conn, $actorId, ['college_dean'], 'college_dean');
$programs = [];
foreach (['BSCS', 'BSIT', 'BSIS', 'FOREIGN'] as $code) {
    $programCollege = $code === 'FOREIGN' ? $foreignCollege : $collegeId;
    $conn->query("INSERT INTO tbl_program (college_id,program_code,program_name,major,status) VALUES ({$programCollege},'{$code}','{$code} QA Program','','active')");
    $programs[$code] = (int)$conn->insert_id;
}
$section = static function (string $code, int $semester, string $status = 'active') use ($conn, $programs, $term): int {
    $name = 'QA' . bin2hex(random_bytes(2));
    $conn->query("INSERT INTO tbl_sections (program_id,ay_id,semester,year_level,section_name,full_section,status)
        VALUES ({$programs[$code]},{$term['ay_id']},{$semester},1,'{$name}','{$code} 1{$name}','{$status}')");
    return (int)$conn->insert_id;
};
$csSection = $section('BSCS', $term['semester']);
$section('BSCS', $term['semester']); // Count an empty active section too.
$itSection = $section('BSIT', $term['semester']);
$foreignSection = $section('FOREIGN', $term['semester']);
$historicalSection = $section('BSCS', $otherSemester);
$section('BSCS', $term['semester'], 'inactive');
$prefix = '000' . (string)(3900000000 + random_int(1, 10000000));
$students = [];
foreach (['shared', 'own', 'manual', 'unimported', 'inactive', 'historical', 'foreign'] as $index => $name) {
    $students[$name] = synk_student_identity_insert_record($conn, [
        'ay_id' => $term['ay_id'], 'semester' => $term['semester'], 'source_sheet_name' => $name === 'manual' ? 'DEAN MANUAL' : 'QA',
        'source_file_name' => 'statistics-qa.csv', 'year_level' => 1, 'student_number' => $prefix . $index,
        'last_name' => 'QA', 'first_name' => $name, 'middle_name' => '', 'suffix_name' => '', 'email_address' => '',
        'program_id' => $programs[$name === 'foreign' || $name === 'shared' ? 'FOREIGN' : ($name === 'manual' ? 'BSIT' : 'BSCS')],
        'uploaded_by' => $actorId, 'source_row_number' => $index + 1]);
}
$subjectIds = array_column($conn->query('SELECT sub_id FROM tbl_subject_masterlist ORDER BY sub_id LIMIT 2')->fetch_all(MYSQLI_ASSOC), 'sub_id');
$link = static function (string $name, string $code, int $sectionId, int $subjectId, int $semester, int $active = 1) use ($conn, $students, $programs, $collegeId, $foreignCollege, $campusId, $actorId, $term, $tag): void {
    $enrollmentCollege = $code === 'FOREIGN' ? $foreignCollege : $collegeId;
    $conn->query("INSERT INTO tbl_student_management_enrolled_subjects
        (import_batch_key,campus_id,college_id,program_id,ay_id,semester,section_id,subject_id,student_id,uploaded_by,is_active)
        VALUES ('{$tag}',{$campusId},{$enrollmentCollege},{$programs[$code]},{$term['ay_id']},{$semester},{$sectionId},{$subjectId},{$students[$name]},{$actorId},{$active})");
};
$link('shared', 'BSCS', $csSection, $subjectIds[0], $term['semester']);
$link('shared', 'BSCS', $csSection, $subjectIds[1], $term['semester']);
$link('shared', 'BSIT', $itSection, $subjectIds[0], $term['semester']);
$link('own', 'BSCS', $csSection, $subjectIds[0], $term['semester']);
$link('inactive', 'BSCS', $csSection, $subjectIds[0], $term['semester'], 0);
$link('historical', 'BSCS', $historicalSection, $subjectIds[0], $otherSemester);
$link('foreign', 'FOREIGN', $foreignSection, $subjectIds[0], $term['semester']);
$faculties = [];
foreach (['doctor', 'linked', 'bachelor', 'missing', 'on_leave', 'retired', 'roster', 'old_roster', 'foreign', 'wrong_campus'] as $name) {
    $status = in_array($name, ['on_leave', 'retired'], true) ? $name : 'active';
    $home = in_array($name, ['roster', 'old_roster'], true) ? 0 : ($name === 'foreign' ? $foreignCollege : $collegeId);
    $facultyCampus = $name === 'wrong_campus' ? $campusId + 1000000 : $campusId;
    $conn->query("INSERT INTO tbl_faculty (first_name,last_name,employment_classification,status,campus_id,college_id)
        VALUES ('{$name}','Statistics QA','permanent','{$status}',{$facultyCampus},{$home})");
    $faculties[$name] = (int)$conn->insert_id;
    if (in_array($name, ['roster', 'old_roster', 'foreign'], true)) {
        $semester = $name === 'old_roster' ? $otherSemester : $term['semester'];
        $conn->query("INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status)
            VALUES ({$collegeId},{$faculties[$name]},{$term['ay_id']},{$semester},'active')");
    }
}
$conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_id)
    VALUES ('Statistics QA Professor','professor{$tag}@sksu.edu.ph','unusable-test-password','professor','active',{$collegeId})");
$profileUserId = (int)$conn->insert_id;
$conn->query("INSERT INTO tbl_useraccount_faculty_links (user_id,faculty_id) VALUES ({$profileUserId},{$faculties['linked']})");
$accountProfile = synk_faculty_profile_defaults(['username' => 'Statistics QA Professor'], null, 'permanent');
$accountProfile = array_merge($accountProfile, ['faculty_rank' => 'Instructor I', 'master_status' => 'Completed', 'master_degree' => 'MS Computer Science', 'doctoral_status' => 'Ongoing', 'doctoral_degree' => 'PhD Computer Science']);
synk_faculty_profile_save($conn, $profileUserId, $accountProfile, 0);
$recordProfiles = [
    'doctor' => ['faculty_rank' => 'Professor I', 'doctoral_status' => 'Completed', 'doctoral_degree' => 'Doctor of Education', 'master_status' => 'Completed', 'master_degree' => 'MA Education'],
    'linked' => ['faculty_rank' => 'Professor I', 'doctoral_status' => 'Completed', 'doctoral_degree' => 'PhD'], // Existing account profile wins.
    'bachelor' => ['faculty_rank' => 'Instructor I', 'bachelor_status' => 'Completed', 'bachelor_degree' => 'BS Computer Science', 'master_status' => 'Ongoing', 'master_degree' => 'MS'],
    'on_leave' => ['faculty_rank' => 'Associate Professor I', 'doctoral_status' => 'Completed', 'doctoral_degree' => 'PhD'],
    'roster' => ['faculty_rank' => 'Instructor II', 'master_status' => 'Completed', 'master_degree' => 'MS'],
    'retired' => ['faculty_rank' => 'Professor I', 'doctoral_status' => 'Completed', 'doctoral_degree' => 'PhD'],
    'foreign' => ['faculty_rank' => 'Professor I', 'doctoral_status' => 'Completed', 'doctoral_degree' => 'PhD'],
];
foreach ($recordProfiles as $name => $profile) {
    $json = json_encode($profile, JSON_UNESCAPED_UNICODE);
    $id = $faculties[$name];
    $stmt = $conn->prepare('INSERT INTO tbl_faculty_record_profiles (faculty_id,profile_data,updated_by) VALUES (?,?,?)');
    $stmt->bind_param('isi', $id, $json, $actorId); $stmt->execute(); $stmt->close();
}
$snapshot = static function () use ($conn): string {
    $data = [];
    foreach (['tbl_student_management', 'tbl_student_management_enrolled_subjects', 'tbl_faculty_profiles', 'tbl_faculty_record_profiles', 'tbl_sections'] as $table) $data[$table] = $conn->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    return hash('sha256', json_encode($data));
};
$before = $snapshot();
session_save_path($root . '/temp'); session_id('deanstatisticsqa' . bin2hex(random_bytes(12))); session_start();
$_SESSION = ['user_id' => $actorId, 'role' => 'college_dean', 'csrf_token' => SYNK_DEAN_STATISTICS_QA];
if (strpos($scenario, 'historical_') === 0 && $scenario !== 'historical_page') synk_dean_select_view_term($conn, $term['ay_id'], $otherSemester);
if ($scenario === 'wrong_role') $_SESSION['role'] = 'professor';
if ($scenario === 'revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$actorId} AND role='college_dean'");
session_write_close();
register_shutdown_function(static function () use ($conn, $snapshot, $before): void {
    header('X-QA-Unchanged: ' . ($before === $snapshot() ? 'yes' : 'no'));
    $conn->rollback();
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
});
if ($scenario === 'metrics') {
    header('Content-Type: application/json');
    echo json_encode(['students' => synk_dean_student_statistics($conn, $scope, $term),
        'faculty' => synk_dean_faculty_statistics($conn, $scope, $term), 'terms' => synk_dean_statistics_terms($conn, $scope, $term),
        'historical' => synk_dean_student_statistics($conn, $scope, ['ay_id' => $term['ay_id'], 'semester' => $otherSemester])]);
    exit;
}
if ($scenario === 'view_checks') {
    $initial = synk_dean_view_term($conn);
    $selected = synk_dean_select_view_term($conn, $term['ay_id'], $otherSemester);
    $remembered = synk_dean_view_term($conn);
    $read = synk_dean_read_term($conn, ['ay_id' => $term['ay_id'], 'semester' => $term['semester']]);
    $stillSelected = synk_dean_view_term($conn);
    $invalidRejected = false;
    try { synk_dean_select_view_term($conn, 4294967295, 1); } catch (DomainException $exception) { $invalidRejected = true; }
    $afterInvalid = synk_dean_view_term($conn);
    $writeRejected = false;
    try { synk_dean_require_current_term_for_write($conn); } catch (DomainException $exception) { $writeRejected = true; }
    $currentUnchanged = synk_fetch_current_academic_term($conn) === $term;
    synk_dean_apply_view_term_request($conn, ['dean_term' => 'current']);
    $restored = synk_dean_view_term($conn);
    $_SESSION['college_dean_view_term'] = ['user_id' => $actorId + 100000, 'ay_id' => $term['ay_id'], 'semester' => $otherSemester];
    $newUser = synk_dean_view_term($conn);
    echo json_encode(compact('initial', 'selected', 'remembered', 'read', 'stillSelected', 'invalidRejected', 'afterInvalid', 'writeRejected', 'currentUnchanged', 'restored', 'newUser'));
    exit;
}
if (in_array($scenario, ['historical_bootstrap', 'historical_classes', 'historical_import', 'historical_student_update', 'historical_delete', 'historical_student_bootstrap', 'historical_heatmap'], true)) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['college_id' => $collegeId, 'campus_id' => $campusId, 'ay_id' => $term['ay_id'], 'semester' => $otherSemester,
        'csrf_token' => SYNK_DEAN_STATISTICS_QA, 'faculty_id' => $faculties['old_roster']];
    $actions = ['historical_bootstrap' => 'bootstrap', 'historical_classes' => 'classes', 'historical_import' => 'preview',
        'historical_student_bootstrap' => 'bootstrap', 'historical_student_update' => 'student_save', 'historical_delete' => 'delete_preview'];
    $_POST['action'] = $actions[$scenario] ?? '';
    $backend = strpos($scenario, 'historical_student') === 0 || $scenario === 'historical_delete'
        ? 'query_college_dean_students.php' : ($scenario === 'historical_heatmap' ? 'dashboard_college_dean_schedule_heatmap.php' : 'query_college_dean_rosters.php');
    chdir($root . '/backend');
    require $root . '/backend/' . $backend;
    exit;
}
if ($scenario === 'empty') {
    $conn->query("DELETE FROM tbl_college_faculty WHERE college_id={$collegeId}");
    $conn->query("UPDATE tbl_faculty SET status='retired' WHERE college_id={$collegeId}");
}
if ($scenario === 'bad_term') $_GET['term'] = 'invalid';
if ($scenario === 'array_term') $_GET['term'] = ['invalid'];
if ($scenario === 'scope_tamper') $_GET['college_id'] = $foreignCollege;
if ($scenario === 'historical_page') $_GET['term'] = $term['ay_id'] . ':' . $otherSemester;
if ($scenario === 'invalid_view') { $_GET['dean_ay_id'] = $term['ay_id']; $_GET['dean_semester'] = 4; }
if ($scenario === 'array_view') { $_GET['dean_ay_id'] = [$term['ay_id']]; $_GET['dean_semester'] = 1; }
if (in_array($scenario, ['historical_profile', 'historical_profile_post'], true)) $_GET['faculty_id'] = (string)$faculties['linked'];
if ($scenario === 'historical_profile_post') $_SERVER['REQUEST_METHOD'] = 'POST';
$pageHandlers = ['summary' => 'reports.php', 'faculty' => 'faculty-statistics.php', 'empty' => 'faculty-statistics.php',
    'historical_faculty' => 'faculty-statistics.php', 'historical_dashboard' => 'index.php',
    'historical_profile' => 'manage-faculty-profile.php', 'historical_profile_post' => 'manage-faculty-profile.php',
    'historical_student_page' => 'students.php', 'historical_workload' => 'faculty-workload.php'];
$handler = $pageHandlers[$scenario] ?? 'student-statistics.php';
$_SERVER['PHP_SELF'] = '/synk/college-dean/' . $handler;
ob_start(static function ($html): string { return str_replace('<head>', '<head><base href="http://localhost/synk/college-dean/">', $html); });
chdir($root . '/college-dean');
require $root . '/college-dean/' . $handler;
