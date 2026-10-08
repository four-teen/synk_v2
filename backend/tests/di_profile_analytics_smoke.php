<?php
// Run: php backend/tests/di_profile_analytics_smoke.php
// All fixture accounts, profiles, faculty records, and roster rows are rolled back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../academic_term_helper.php';
require_once __DIR__ . '/../di_profile_analytics_helper.php';

function di_analytics_check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

foreach (['tbl_useraccount', 'tbl_useraccount_roles', 'tbl_faculty', 'tbl_useraccount_faculty_links', 'tbl_college_faculty', 'tbl_faculty_profiles', 'tbl_faculty_profile_eligibilities'] as $table) {
    $engine = $conn->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}'")->fetch_assoc();
    di_analytics_check(strtolower((string)($engine['ENGINE'] ?? '')) === 'innodb', 'Transactional test storage is required: ' . $table);
}
$empty = synk_di_profile_summary([]);
di_analytics_check($empty['accounts'] === 0 && $empty['coverage_percent'] === 0 && $empty['average_completion'] === 0, 'Empty analytics must not divide by zero.');
$degrees = ['bachelor_status' => 'Completed', 'bachelor_degree' => 'BS Test', 'master_status' => 'Ongoing', 'master_degree' => 'MS Test', 'doctoral_status' => 'Not applicable'];
di_analytics_check(synk_di_highest_completed_degree($degrees, []) === "Bachelor's", 'An ongoing degree was counted as completed.');
$degrees['master_status'] = 'Completed';
di_analytics_check(synk_di_highest_completed_degree($degrees, []) === "Master's", 'The highest completed degree was not selected.');
di_analytics_check(synk_di_highest_completed_degree($degrees, ['master_degree' => 'Invalid']) === "Bachelor's", 'An invalid completed degree was counted.');

$term = synk_fetch_current_academic_term($conn);
di_analytics_check($term['ay_id'] > 0 && $term['semester'] > 0, 'Set the current academic term before running this integration test.');
$before = synk_di_profile_dashboard($conn, $term);
$campusColleges = [];
foreach ($before['colleges'] as $id => $college) { $campusColleges[(int)$college['campus_id']][] = $id; }
$testCampusId = 0;
foreach ($campusColleges as $id => $ids) { if (count($ids) >= 2 && isset($before['campuses'][$id])) { $testCampusId = $id; break; } }
di_analytics_check($testCampusId > 0, 'Two colleges in one active campus are required for the deduplication test.');
[$testCollegeId, $secondCollegeId] = $campusColleges[$testCampusId];
$beforeCampus = synk_di_profile_dashboard($conn, $term, $testCampusId);
$ranks = synk_faculty_profile_ranks($conn);
$rank = (string)array_keys($ranks)[0];
$eligibilities = synk_faculty_profile_eligibility_catalog($conn);
$eligibilityId = (int)array_keys($eligibilities)[0];
$testUserIds = [];
$conn->begin_transaction();
try {
    $conn->query("INSERT INTO tbl_faculty (first_name, last_name, employment_classification, status) VALUES ('DI Analytics', 'Test Faculty', 'permanent', 'active')");
    $facultyId = (int)$conn->insert_id;
    foreach ([$testCollegeId, $secondCollegeId] as $collegeId) {
        $stmt = $conn->prepare("INSERT INTO tbl_college_faculty (faculty_id, college_id, ay_id, semester, status) VALUES (?, ?, ?, ?, 'active')");
        $stmt->bind_param('iiii', $facultyId, $collegeId, $term['ay_id'], $term['semester']);
        $stmt->execute();
        $stmt->close();
    }
    foreach (['complete', 'partial', 'not_started', 'inactive_account', 'inactive_role'] as $kind) {
        $email = 'di-analytics-' . bin2hex(random_bytes(8)) . '@sksu.edu.ph';
        $accountStatus = $kind === 'inactive_account' ? 'inactive' : 'active';
        $stmt = $conn->prepare("INSERT INTO tbl_useraccount (username, email, password, role, college_id, status) VALUES ('DI Analytics Test', ?, 'unusable-test-password', 'admin', ?, ?)");
        $stmt->bind_param('sis', $email, $testCollegeId, $accountStatus);
        $stmt->execute();
        $userId = (int)$conn->insert_id;
        $stmt->close();
        $testUserIds[] = $userId;
        di_analytics_check(synk_persist_useraccount_roles($conn, $userId, ['professor'], 'professor') === null, 'Could not assign the test professor role.');
        if ($kind === 'inactive_role') { $conn->query("UPDATE tbl_useraccount_roles SET status = 'inactive' WHERE user_id = {$userId}"); }
        if ($kind === 'complete') {
            $conn->query("INSERT INTO tbl_useraccount_faculty_links (user_id, faculty_id) VALUES ({$userId}, {$facultyId})");
        }
        if (!in_array($kind, ['complete', 'partial'], true)) { continue; }
        $data = array_fill_keys(array_keys(synk_faculty_profile_fields()), '');
        $data['full_name'] = 'DI Analytics Test';
        $data['campus_name'] = $before['campuses'][$testCampusId]['campus_name'];
        $data['college_name'] = $before['colleges'][$testCollegeId]['college_name'];
        if ($kind === 'complete') {
            $data = array_merge($data, [
                'gender' => 'Female', 'civil_status' => 'Single', 'date_of_birth' => '1985-01-01',
                'faculty_rank' => $rank, 'salary_grade' => 'SG ' . $ranks[$rank]['salary_grade'],
                'employment_classification' => 'Permanent', 'service_start_date' => '2010-01-01',
                'length_of_service' => synk_faculty_profile_service('2010-01-01'),
                'bachelor_status' => 'Completed', 'bachelor_degree' => 'BS Test',
                'bachelor_institution' => 'Test University', 'bachelor_address' => 'Test Address',
                'bachelor_specialization' => 'Test Specialization', 'master_status' => 'Not applicable',
                'doctoral_status' => 'Not applicable',
            ]);
        }
        foreach (['date_of_birth', 'service_start_date'] as $dateField) {
            if ($data[$dateField] === '') { $data[$dateField] = null; }
        }
        $columns = array_keys($data);
        $columns[] = 'user_id';
        $values = array_values($data);
        $values[] = $userId;
        $stmt = $conn->prepare('INSERT INTO tbl_faculty_profiles (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
        $types = str_repeat('s', count($data)) . 'i';
        synk_bind_dynamic_params($stmt, $types, $values);
        $stmt->execute();
        $stmt->close();
        if ($kind === 'complete') { $conn->query("INSERT INTO tbl_faculty_profile_eligibilities (user_id, eligibility_id) VALUES ({$userId}, {$eligibilityId})"); }
    }
    $after = synk_di_profile_dashboard($conn, $term);
    foreach (['accounts' => 3, 'saved' => 2, 'complete' => 1, 'in_progress' => 1, 'not_started' => 1, 'with_eligibility' => 1, 'linked' => 1] as $key => $delta) {
        di_analytics_check($after['summary'][$key] === $before['summary'][$key] + $delta, 'Incorrect summary change for ' . $key);
    }
    di_analytics_check($after['faculty_count'] === $before['faculty_count'] + 1, 'A faculty member with several college assignments was counted twice.');
    $campusAfter = synk_di_profile_dashboard($conn, $term, $testCampusId);
    di_analytics_check($campusAfter['faculty_count'] === $beforeCampus['faculty_count'] + 1, 'Campus faculty counts are not distinct.');
    di_analytics_check($campusAfter['summary']['accounts'] === $beforeCampus['summary']['accounts'] + 3, 'Campus scope lost or duplicated professor accounts.');
    $completeAccount = array_values(array_filter($after['accounts'], static function ($row) use ($testUserIds) { return $row['user_id'] === $testUserIds[0]; }))[0];
    di_analytics_check($completeAccount['completion']['percent'] === 100 && $completeAccount['highest_degree'] === "Bachelor's", 'Not-applicable graduate studies were treated as missing profile fields.');
    di_analytics_check(count($completeAccount['eligibility_labels']) === 1, 'Selected profile credentials were not included.');
    $invalidScope = synk_di_profile_dashboard($conn, $term, PHP_INT_MAX, PHP_INT_MAX);
    di_analytics_check($invalidScope['campus_id'] === 0 && $invalidScope['college_id'] === 0, 'Unknown filter IDs were not normalized.');
    foreach ($before['colleges'] as $id => $college) {
        if ((int)$college['campus_id'] !== $testCampusId) {
            $mismatched = synk_di_profile_dashboard($conn, $term, $testCampusId, $id);
            di_analytics_check($mismatched['college_id'] === 0, 'A college outside the selected campus was accepted.');
            break;
        }
    }
} finally {
    $conn->rollback();
}
foreach ($testUserIds as $userId) {
    di_analytics_check($conn->query("SELECT user_id FROM tbl_useraccount WHERE user_id = {$userId}")->num_rows === 0, 'A fixture account was left behind.');
}
echo "PASS: profile completion, Not applicable studies, degree status, credentials, active roles, campus filters, distinct faculty counts, and empty summaries. Fixtures rolled back.\n";
