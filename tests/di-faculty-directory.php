<?php
// Run: php tests/di-faculty-directory.php
// Schema setup precedes the transaction. All test records and updates are rolled back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/academic_term_helper.php';
require_once __DIR__ . '/../backend/faculty_directory_helper.php';
require_once __DIR__ . '/../backend/di_profile_analytics_helper.php';
synk_faculty_directory_ensure_schema($conn);
$checks = 0;
function di_directory_check(bool $condition, string $description): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($description);
    $checks++;
}
$before = synk_faculty_directory_records($conn);
$term = synk_fetch_current_academic_term($conn);
$dashboard = synk_di_profile_dashboard($conn, $term);
$emptyTermDashboard = synk_di_profile_dashboard($conn, ['ay_id' => 0, 'semester' => 0]);
di_directory_check($dashboard === $emptyTermDashboard, 'Changing or clearing the semester cannot restrict faculty or professor analytics.');
di_directory_check(count($before['faculty']) === (int)$conn->query('SELECT COUNT(*) total FROM tbl_faculty')->fetch_assoc()['total'], 'Every faculty record is included regardless of status or assignments.');
di_directory_check(count(synk_di_account_connections($conn, $before)) === (int)$conn->query('SELECT COUNT(*) total FROM tbl_useraccount')->fetch_assoc()['total'], 'Connection review includes every active and inactive account.');
$collegeIds = array_keys($before['colleges']);
$collegeA = (int)$collegeIds[0];
$campusA = (int)$before['colleges'][$collegeA]['campus_id'];
$collegeB = 0;
foreach ($before['colleges'] as $id => $college) if ((int)$college['campus_id'] !== $campusA) { $collegeB = $id; break; }
di_directory_check($collegeB > 0, 'Use colleges from two different campuses to test scope boundaries.');
$campusB = (int)$before['colleges'][$collegeB]['campus_id'];
$baseValues = ['status' => 'active', 'campus_id' => $campusA, 'college_id' => $collegeA, 'remarks' => ''];
foreach (array_keys(synk_faculty_record_statuses()) as $status) {
    [$values, $errors] = synk_faculty_directory_validate(array_merge($baseValues, ['status' => $status, 'remarks' => 'QA record note']), $before);
    di_directory_check(!$errors && $values['status'] === $status, 'Accept lifecycle status: ' . $status);
}
foreach ([['status' => 'deleted'], ['status' => ['active']], ['college_id' => $collegeB], ['campus_id' => ['1']], ['college_id' => '1 OR 1=1'], ['remarks' => str_repeat('x', 2001)], ['remarks' => "Invalid\x00text"], ['status' => 'other', 'remarks' => '']] as $invalid) {
    [, $errors] = synk_faculty_directory_validate(array_merge($baseValues, $invalid), $before);
    di_directory_check((bool)$errors, 'Reject malformed status, invalid affiliation, or invalid remarks.');
}
$tables = ['tbl_faculty', 'tbl_useraccount', 'tbl_useraccount_roles', 'tbl_useraccount_faculty_links', 'tbl_faculty_profiles', 'tbl_college_faculty'];
$counts = [];
foreach ($tables as $table) {
    $engine = $conn->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetch_assoc();
    di_directory_check(strtolower($engine['ENGINE']) === 'innodb', 'Fixture table uses transactional storage: ' . $table);
    $counts[$table] = (int)$conn->query("SELECT COUNT(*) total FROM {$table}")->fetch_assoc()['total'];
}
$createAccount = static function (string $role, string $status = 'active') use ($conn): int {
    $email = 'di-directory-' . bin2hex(random_bytes(8)) . '@sksu.edu.ph';
    $stmt = $conn->prepare("INSERT INTO tbl_useraccount (username, email, password, role, status) VALUES ('DI Directory QA', ?, 'unusable-test-password', ?, ?)");
    $stmt->bind_param('sss', $email, $role, $status);
    $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $id;
};
$conn->begin_transaction();
try {
    $actorId = $createAccount('DI');
    $nonDiId = $createAccount('scheduler');
    $inactiveActorId = $createAccount('DI', 'inactive');
    $professorId = $createAccount('scheduler');
    $conn->query("INSERT INTO tbl_useraccount_roles (user_id, role, is_primary, status) VALUES ({$professorId}, 'professor', 1, 'active')");
    $conn->query("INSERT INTO tbl_faculty (first_name, last_name, status) VALUES ('DI Directory', 'QA Faculty', 'active')");
    $facultyId = (int)$conn->insert_id;
    $conn->query("INSERT INTO tbl_useraccount_faculty_links (user_id, faculty_id) VALUES ({$professorId}, {$facultyId})");
    $fixture = synk_faculty_directory_records($conn);
    di_directory_check(isset($fixture['faculty'][$facultyId]) && !$fixture['faculty'][$facultyId]['connection_complete'], 'A faculty member without a roster remains visible and is flagged for affiliation review.');
    $auditById = array_column(synk_di_account_connections($conn, $fixture), null, 'user_id');
    di_directory_check($auditById[$actorId]['connection_status'] === 'institution_wide' && !$auditById[$actorId]['issues'], 'A DI account needs no invented college for institution-wide access.');
    di_directory_check($auditById[$nonDiId]['connection_status'] === 'needs_review', 'A scheduler without a college is flagged.');
    di_directory_check($auditById[$professorId]['connection_status'] === 'needs_review', 'A linked professor without affiliation is flagged.');
    $otherTerm = $conn->query('SELECT ay_id FROM tbl_academic_years WHERE ay_id <> ' . (int)$term['ay_id'] . ' LIMIT 1')->fetch_assoc();
    $oldYear = (int)($otherTerm['ay_id'] ?? $term['ay_id']);
    $stmt = $conn->prepare("INSERT INTO tbl_college_faculty (faculty_id, college_id, ay_id, semester, status) VALUES (?, ?, ?, 2, 'active')");
    $stmt->bind_param('iii', $facultyId, $collegeA, $oldYear);
    $stmt->execute();
    $stmt->close();
    $fixture = synk_faculty_directory_records($conn);
    di_directory_check($fixture['faculty'][$facultyId]['college_ids'] === [$collegeA], 'Earlier-semester college records are available independent of current term.');
    di_directory_check(synk_di_profile_dashboard($conn, $term) === synk_di_profile_dashboard($conn, ['ay_id' => $oldYear, 'semester' => 3]), 'Term changes cannot remove earlier roster links from directory analytics.');
    $rosterCount = (int)$conn->query("SELECT COUNT(*) total FROM tbl_college_faculty WHERE faculty_id = {$facultyId}")->fetch_assoc()['total'];
    $campusName = $before['campuses'][$campusB]['campus_name'];
    $collegeName = $before['colleges'][$collegeB]['college_name'];
    $stmt = $conn->prepare("INSERT INTO tbl_faculty_profiles (user_id, full_name, faculty_id_number, campus_name, college_name, eligibility) VALUES (?, 'Saved QA Professor', 'QA-A01', ?, ?, '')");
    $stmt->bind_param('iss', $professorId, $campusName, $collegeName);
    $stmt->execute();
    $stmt->close();
    di_directory_check(synk_faculty_directory_records($conn)['faculty'][$facultyId]['college_ids'] === [$collegeB], 'The latest saved professor affiliation takes precedence over historical rosters.');
    $reviewValues = array_merge($baseValues, ['status' => 'resigned', 'remarks' => 'QA resignation note <script>retained as plain text</script>']);
    foreach ([$nonDiId, $inactiveActorId] as $unauthorizedActor) {
        $rejected = false;
        try { synk_faculty_directory_save($conn, $facultyId, 0, $unauthorizedActor, $reviewValues); } catch (RuntimeException $exception) { $rejected = true; }
        di_directory_check($rejected, 'Reject status updates from non-DI or inactive DI accounts.');
    }
    di_directory_check(synk_faculty_directory_save($conn, $facultyId, 0, $actorId, $reviewValues), 'An active DI can save resigned status, a home affiliation, and remarks.');
    di_directory_check(!synk_faculty_directory_save($conn, $facultyId, 0, $actorId, $reviewValues), 'A stale review cannot overwrite a more recent update.');
    $fixture = synk_faculty_directory_records($conn);
    $record = $fixture['faculty'][$facultyId];
    di_directory_check($record['status'] === 'resigned' && $record['remarks'] === $reviewValues['remarks'] && (int)$record['record_revision'] === 1, 'Lifecycle status and remarks are preserved exactly with a revision.');
    di_directory_check($record['college_ids'] === [$collegeA] && $record['campus_ids'] === [$campusA] && $record['connection_source'] === 'Faculty record', 'Reviewed home affiliation overrides profile and roster fallbacks.');
    di_directory_check($record['profile']['faculty_id_number'] === 'QA-A01' && $record['profile']['college_name'] === $collegeName, 'DI review does not overwrite professor profile data.');
    di_directory_check((int)$conn->query("SELECT COUNT(*) total FROM tbl_college_faculty WHERE faculty_id = {$facultyId}")->fetch_assoc()['total'] === $rosterCount, 'Changing faculty status retains scheduler roster history.');
    $filters = synk_di_faculty_filters(['status' => 'resigned', 'q' => 'QA Faculty', 'semester' => '1', 'ay_id' => '999'], $fixture);
    $filtered = synk_di_filter_faculty_records($fixture['faculty'], $filters);
    di_directory_check(count($filtered) === 1 && $filtered[0]['faculty_id'] === $facultyId, 'Resigned faculty remain searchable and term parameters do not restrict the list.');
    di_directory_check(synk_di_profile_dashboard($conn, $term)['faculty_count'] === $dashboard['faculty_count'], 'Resigned faculty remain in the directory while leaving active-faculty analytics.');
    $badFilters = synk_di_faculty_filters(['campus_id' => '999999999', 'college_id' => [], 'status' => [], 'q' => []], $fixture);
    di_directory_check($badFilters['campus_id'] === 0 && $badFilters['college_id'] === 0 && $badFilters['status'] === '' && $badFilters['q'] === '', 'Malformed filters normalize safely.');
    di_directory_check(synk_di_faculty_filters(['campus_id' => (string)$campusA, 'college_id' => (string)$collegeB], $fixture)['college_id'] === 0, 'A college from another campus is rejected by filters.');
    di_directory_check(synk_faculty_directory_save($conn, $facultyId, 1, $actorId, $baseValues), 'An existing faculty record can be reactivated without being recreated.');
    di_directory_check(synk_faculty_directory_records($conn)['faculty'][$facultyId]['status'] === 'active', 'Reactivation restores the same faculty record.');
} finally { $conn->rollback(); }
foreach ($tables as $table) di_directory_check((int)$conn->query("SELECT COUNT(*) total FROM {$table}")->fetch_assoc()['total'] === $counts[$table], 'Fixture records rolled back: ' . $table);
di_directory_check(synk_faculty_directory_records($conn) === $before, 'All real faculty records, profiles, accounts, and affiliations remain unchanged after tests.');
echo 'DI faculty directory: ', $checks, ' checks passed. Fixtures rolled back.', PHP_EOL;
