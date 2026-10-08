<?php
// Run: php tests/professor-workload.php [--database]
// Database fixtures and assignment changes are always rolled back.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../backend/professor_workload_view_helper.php';
$checks = 0;
function workload_check(bool $condition, string $description): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($description);
    }
    $checks++;
}
$terms = [
    ['ay_id' => 99, 'academic_year_label' => '2024-2025', 'semester' => 1],
    ['ay_id' => 77, 'academic_year_label' => '2026-2027', 'semester' => 2],
    ['ay_id' => 77, 'academic_year_label' => '2026-2027', 'semester' => 1],
    ['ay_id' => 1, 'academic_year_label' => '2028-2029', 'semester' => 1],
    ['ay_id' => 1, 'academic_year_label' => '2028-2029', 'semester' => 2],
    ['ay_id' => 1, 'academic_year_label' => '2028-2029', 'semester' => 2],
];
$current = ['ay_id' => 77, 'semester' => 1];
$navigation = synk_professor_workload_navigation($terms, $current);
workload_check(array_keys($navigation['years']) === [1, 77, 99], 'Sort actual academic years newest first, independently of database IDs.');
workload_check($navigation['ay_id'] === 1 && $navigation['semester'] === 2, 'Default to the latest available year and latest semester when it is not the current year.');
workload_check($navigation['years'][1]['semesters'] === [1, 2], 'Semester filters are ordered and deduplicated.');
$currentSelection = synk_professor_workload_navigation($terms, $current, 77);
workload_check($currentSelection['semester'] === 1, 'Prefer the active semester when opening the current year.');
$explicit = synk_professor_workload_navigation($terms, $current, 77, 2);
workload_check($explicit['ay_id'] === 77 && $explicit['semester'] === 2, 'Honor an explicit year and semester from dashboard links.');
$all = synk_professor_workload_navigation($terms, $current, 77, 0);
workload_check($all['semester'] === 1 && $all['ay_id'] === 77, 'Legacy all-semester links select an available concrete term.');
$stale = synk_professor_workload_navigation($terms, $current, 99, 2);
workload_check($stale['ay_id'] === 99 && $stale['semester'] === 1, 'A missing semester falls back to an available term in the requested year.');
$invalidYear = synk_professor_workload_navigation($terms, $current, 12345);
workload_check($invalidYear['ay_id'] === 1, 'An unknown year falls back to the latest available year.');
$empty = synk_professor_workload_navigation([], $current);
workload_check($empty['selected_year'] === null && $empty['ay_id'] === 0 && $empty['semester'] === 0, 'Accounts without assignments have a safe empty selection.');
$growth = $terms;
$growth[] = ['ay_id' => 5, 'academic_year_label' => '2029-2030', 'semester' => 1];
$newest = synk_professor_workload_navigation($growth, $current);
workload_check($newest['latest_id'] === 5 && count($newest['years']) === 4, 'A new academic year automatically appears first without removing history.');
foreach ([null, [], ['1'], '-1', '1 OR 1=1', '1.2', '99999999999999'] as $malformed) {
    workload_check(synk_professor_workload_query_integer($malformed) === null, 'Malformed filter parameters are ignored safely.');
}
workload_check(synk_professor_workload_query_integer('0') === 0 && synk_professor_workload_query_integer('77') === 77, 'Parse all-semester and academic-year selections.');
workload_check(array_keys($navigation['terms']) === ['1-2', '1-1', '77-2', '77-1', '99-1'], 'Combined options contain only available year/semester pairs, newest first without duplicates.');
workload_check($explicit['selected_term']['label'] === '2026-2027 / 2nd Semester', 'Combined selection includes the academic year and semester in its label.');
workload_check($empty['terms'] === [] && $empty['selected_term'] === null, 'An account with no classes has no selectable term.');
workload_check(synk_professor_workload_query_term('77-2') === ['ay_id' => 77, 'semester' => 2], 'Parse the combined selector value.');
foreach ([null, [], '0-1', '1-0', '1-4', '77-2-extra', '1 OR 1=1', '9999999999999-1'] as $malformedTerm) {
    workload_check(synk_professor_workload_query_term($malformedTerm) === null, 'Reject malformed or unavailable semester selector formats.');
}
$lab = synk_professor_meeting_details(['schedule_type' => 'LAB', 'days_json' => '["Th","T","T"]', 'time_start' => '07:30:00', 'time_end' => '09:00:00', 'room_name' => 'Lab 309']);
$lecture = synk_professor_meeting_details(['schedule_type' => 'LEC', 'days_json' => '["W","M"]', 'time_start' => '09:00:00', 'time_end' => '10:00:00', 'room_name' => 'Room 304']);
workload_check($lab['days'] === 'Tue, Thu' && $lab['type_label'] === 'Lab' && $lab['room'] === 'Lab 309', 'Keep lab days and their room together.');
workload_check($lecture['days'] === 'Mon, Wed' && $lecture['type_label'] === 'Lecture' && $lecture['room'] === 'Room 304', 'Keep lecture days and their room together.');
workload_check($lab['time'] === '7:30 AM - 9:00 AM' && $lecture['time'] === '9:00 AM - 10:00 AM', 'Format each meeting time independently.');
$missingMeeting = synk_professor_meeting_details(['days_json' => 'invalid']);
workload_check($missingMeeting['days'] === '' && $missingMeeting['time'] === '' && $missingMeeting['room'] === '', 'Missing schedule data stays empty for honest pending labels.');

if (in_array('--database', $argv, true)) {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require __DIR__ . '/../backend/db.php';
    $links = $conn->query("SELECT l.user_id, l.faculty_id FROM tbl_useraccount_faculty_links l
        JOIN tbl_faculty f ON f.faculty_id = l.faculty_id AND f.status = 'active'
        ORDER BY l.user_id LIMIT 2")->fetch_all(MYSQLI_ASSOC);
    workload_check(count($links) === 2, 'Faculty isolation checks need two active linked faculty accounts.');
    $facultyA = (int)$links[0]['faculty_id'];
    $facultyB = (int)$links[1]['faculty_id'];
    $baseline = [];
    foreach ([$facultyA, $facultyB] as $facultyId) {
        $baseline[$facultyId] = synk_professor_workload_available_terms($conn, $facultyId);
        foreach ($baseline[$facultyId] as $term) {
            $rows = synk_professor_fetch_subject_rows_by_academic_year($conn, $facultyId, (int)$term['ay_id'], (int)$term['semester']);
            workload_check((bool)$rows, 'Every listed term produces actual classes for its faculty.');
            foreach ($rows as $row) {
                workload_check($row['faculty_id'] === $facultyId && $row['ay_id'] === (int)$term['ay_id'] && $row['semester'] === (int)$term['semester'], 'A displayed class has the requested faculty and exact term.');
                foreach ($row['schedule_ids'] as $scheduleId) {
                    $assignment = $conn->query('SELECT faculty_id, ay_id, semester FROM tbl_faculty_workload_sched WHERE schedule_id = ' . (int)$scheduleId)->fetch_all(MYSQLI_ASSOC);
                    workload_check((bool)array_filter($assignment, static function ($record) use ($facultyId, $term) {
                        return (int)$record['faculty_id'] === $facultyId && (int)$record['ay_id'] === (int)$term['ay_id'] && (int)$record['semester'] === (int)$term['semester'];
                    }), 'Every displayed meeting has an explicit assignment to this faculty.');
                }
            }
        }
    }
    $source = synk_professor_workload_assignment_source_sql();
    $template = $conn->query("SELECT cs.schedule_id, po.offering_id, po.section_id, po.prospectus_id, fw.ay_id, fw.semester {$source} WHERE fw.faculty_id = {$facultyA} LIMIT 1")->fetch_assoc();
    workload_check((bool)$template, 'A valid class is available as a fixture template.');
    $tables = ['tbl_academic_years', 'tbl_sections', 'tbl_section_curriculum', 'tbl_prospectus_offering', 'tbl_class_schedule', 'tbl_faculty_workload_sched'];
    $countsBefore = [];
    foreach ($tables as $table) $countsBefore[$table] = (int)$conn->query("SELECT COUNT(*) total FROM {$table}")->fetch_assoc()['total'];
    $clone = static function (string $table, string $primaryKey, int $id, array $changes) use ($conn): int {
        $row = $conn->query("SELECT * FROM {$table} WHERE {$primaryKey} = {$id}")->fetch_assoc();
        if (!$row) throw new RuntimeException('Missing workload fixture template.');
        unset($row[$primaryKey]);
        $row = array_merge($row, $changes);
        $columns = '`' . implode('`, `', array_keys($row)) . '`';
        $placeholders = implode(', ', array_fill(0, count($row), '?'));
        $stmt = $conn->prepare("INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})");
        $values = array_values($row);
        $stmt->bind_param(str_repeat('s', count($values)), ...$values);
        $stmt->execute();
        $newId = (int)$conn->insert_id;
        $stmt->close();
        return $newId;
    };
    $assign = static function (int $scheduleId, int $facultyId, int $year, int $semester) use ($conn): int {
        $stmt = $conn->prepare('INSERT INTO tbl_faculty_workload_sched (schedule_id, faculty_id, ay_id, semester) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('iiii', $scheduleId, $facultyId, $year, $semester);
        $stmt->execute();
        $id = (int)$conn->insert_id;
        $stmt->close();
        return $id;
    };
    $hasTerm = static function (int $facultyId, int $year, int $semester) use ($conn): bool {
        foreach (synk_professor_workload_available_terms($conn, $facultyId) as $term) {
            if ((int)$term['ay_id'] === $year && (int)$term['semester'] === $semester) return true;
        }
        return false;
    };
    $savedSession = $_SESSION ?? [];
    $savedGet = $_GET;
    $conn->begin_transaction();
    try {
        $label = 'QA-' . bin2hex(random_bytes(6));
        $stmt = $conn->prepare("INSERT INTO tbl_academic_years (ay, status) VALUES (?, 'inactive')");
        $stmt->bind_param('s', $label);
        $stmt->execute();
        $year = (int)$conn->insert_id;
        $stmt->close();
        $semester = (int)$template['semester'];
        $section = $clone('tbl_sections', 'section_id', (int)$template['section_id'], ['ay_id' => $year, 'section_name' => 'QA', 'full_section' => $label, 'status' => 'active']);
        $stmt = $conn->prepare('INSERT INTO tbl_section_curriculum (section_id, prospectus_id) VALUES (?, ?)');
        $prospectus = (int)$template['prospectus_id'];
        $stmt->bind_param('ii', $section, $prospectus);
        $stmt->execute();
        $stmt->close();
        $offering = $clone('tbl_prospectus_offering', 'offering_id', (int)$template['offering_id'], ['ay_id' => $year, 'section_id' => $section]);
        $lecture = $clone('tbl_class_schedule', 'schedule_id', (int)$template['schedule_id'], ['offering_id' => $offering, 'schedule_type' => 'LEC', 'schedule_group_id' => null, 'faculty_id' => null, 'days_json' => '["M"]', 'time_start' => '06:01:00', 'time_end' => '06:59:00']);
        $lab = $clone('tbl_class_schedule', 'schedule_id', (int)$template['schedule_id'], ['offering_id' => $offering, 'schedule_type' => 'LAB', 'schedule_group_id' => null, 'faculty_id' => null, 'days_json' => '["F"]', 'time_start' => '18:01:00', 'time_end' => '18:59:00']);
        $unassigned = $clone('tbl_class_schedule', 'schedule_id', (int)$template['schedule_id'], ['offering_id' => $offering, 'faculty_id' => null, 'schedule_group_id' => null]);
        workload_check(!$hasTerm($facultyA, $year, $semester) && !$hasTerm($facultyB, $year, $semester), 'An offered but unassigned class creates no faculty term option.');
        $lectureAssignment = $assign($lecture, $facultyA, $year, $semester);
        $labAssignment = $assign($lab, $facultyB, $year, $semester);
        $rowsA = synk_professor_fetch_subject_rows_by_academic_year($conn, $facultyA, $year, $semester);
        $rowsB = synk_professor_fetch_subject_rows_by_academic_year($conn, $facultyB, $year, $semester);
        workload_check(count($rowsA) === 1 && $rowsA[0]['schedule_ids'] === [$lecture] && $rowsA[0]['meetings'][0]['type'] === 'LEC', 'Faculty A sees only their lecture, excluding another faculty lab and unassigned meeting.');
        workload_check(count($rowsB) === 1 && $rowsB[0]['schedule_ids'] === [$lab] && $rowsB[0]['meetings'][0]['type'] === 'LAB', 'Faculty B sees only their lab, excluding another faculty lecture.');
        workload_check($hasTerm($facultyA, $year, $semester) && $hasTerm($facultyB, $year, $semester), 'A term appears when a faculty has an actual assigned class.');
        $_SESSION = ['user_id' => (int)$links[0]['user_id'], 'role' => 'professor', 'faculty_id' => $facultyB];
        $_GET = ['faculty_id' => (string)$facultyB, 'user_id' => (string)$links[1]['user_id']];
        $portal = synk_professor_resolve_portal_context($conn);
        workload_check($portal['faculty_id'] === $facultyA, 'Forged faculty IDs in the URL or session cannot replace the signed-in account faculty link.');
        $conn->query("UPDATE tbl_faculty_workload_sched SET faculty_id = {$facultyA} WHERE workload_id = {$labAssignment}");
        workload_check(synk_professor_fetch_subject_rows_by_academic_year($conn, $facultyB, $year, $semester) === [] && !$hasTerm($facultyB, $year, $semester), 'Reassignment removes the class and term from the previous faculty immediately.');
        $rowsA = synk_professor_fetch_subject_rows_by_academic_year($conn, $facultyA, $year, $semester);
        workload_check(count($rowsA[0]['schedule_ids']) === 2 && count($rowsA[0]['meetings']) === 2, 'Reassignment gives the new faculty both of their assigned meetings.');
        $wrongSemester = $semester === 1 ? 2 : 1;
        $conn->query("UPDATE tbl_faculty_workload_sched SET faculty_id = {$facultyB}, semester = {$wrongSemester} WHERE workload_id = {$lectureAssignment}");
        workload_check(synk_professor_fetch_subject_rows_by_academic_year($conn, $facultyB, $year, $wrongSemester) === [] && !$hasTerm($facultyB, $year, $wrongSemester), 'A stale assignment with a mismatched offering semester creates neither a class nor a selectable term.');
        $rowsA = synk_professor_fetch_subject_rows_by_academic_year($conn, $facultyA, $year, $semester);
        workload_check($rowsA[0]['schedule_ids'] === [$lab], 'Other faculty and mismatched-term lecture records cannot leak into the remaining lab.');
        $conn->query("UPDATE tbl_faculty_workload_sched SET ay_id = {$template['ay_id']}, semester = {$semester} WHERE workload_id = {$lectureAssignment}");
        $wrongYearRows = synk_professor_fetch_subject_rows_by_academic_year($conn, $facultyB, (int)$template['ay_id'], $semester);
        $wrongYearScheduleIds = array_merge([], ...array_column($wrongYearRows, 'schedule_ids'));
        workload_check(!in_array($lecture, $wrongYearScheduleIds, true), 'An assignment from a different academic year cannot expose the schedule even when the semester matches.');
        $conn->query('SAVEPOINT inactive_faculty_check');
        $conn->query("UPDATE tbl_faculty SET status = 'inactive' WHERE faculty_id = {$facultyA}");
        workload_check(synk_professor_workload_available_terms($conn, $facultyA) === [] && synk_professor_fetch_subject_rows_by_academic_year($conn, $facultyA, $year, $semester) === [], 'Inactive faculty have no workload data or selectable terms.');
        $conn->query('ROLLBACK TO SAVEPOINT inactive_faculty_check');
        $conn->query("DELETE FROM tbl_faculty_workload_sched WHERE workload_id = {$labAssignment}");
        workload_check(synk_professor_fetch_subject_rows_by_academic_year($conn, $facultyA, $year, $semester) === [] && !$hasTerm($facultyA, $year, $semester), 'Removing the final assignment removes the class and term.');
        workload_check(synk_professor_workload_available_terms($conn, 0) === [] && synk_professor_fetch_subject_rows_by_academic_year($conn, 0, $year, $semester) === [], 'Unlinked accounts cannot see any faculty workload.');
    } finally {
        $conn->rollback();
        $_SESSION = $savedSession;
        $_GET = $savedGet;
    }
    foreach ($tables as $table) workload_check((int)$conn->query("SELECT COUNT(*) total FROM {$table}")->fetch_assoc()['total'] === $countsBefore[$table], 'All ' . $table . ' fixture writes were rolled back.');
    foreach ([$facultyA, $facultyB] as $facultyId) workload_check(synk_professor_workload_available_terms($conn, $facultyId) === $baseline[$facultyId], 'Real faculty term selections were restored after tests.');
}
echo 'Professor workload: ', $checks, ' checks passed.', PHP_EOL;
