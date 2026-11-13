<?php
// Read-only workload summaries; all core and LMS fixtures roll back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth_useraccount.php';
require_once __DIR__ . '/../backend/professor_workload_students_helper.php';
require_once __DIR__ . '/../backend/professor_workload_summary_helper_lms.php';
require_once __DIR__ . '/fixtures/professor-workload-students-data.php';
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$coreTables = 'tbl_faculty,tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_faculty_workload_sched,tbl_class_schedule,tbl_student_management,tbl_student_management_enrolled_subjects,tbl_student_login_activity,tbl_offering_enrollee_counts,tbl_academic_settings,' . synk_schedule_merge_table_name();
$lms = synk_database_connection_lms($conn);
$coreBefore = $conn->query('CHECKSUM TABLE ' . $coreTables)->fetch_all(MYSQLI_ASSOC);
$lmsBefore = $lms->query('CHECKSUM TABLE tbl_lms_assessments')->fetch_all(MYSQLI_ASSOC);
$tablesBefore = $lms->query('SHOW TABLES')->fetch_all(MYSQLI_NUM);
$conn->begin_transaction();
$lms->begin_transaction();
try {
    $fixture = professor_students_fixtures($conn);
    $faculty = $fixture['faculty_id'];
    $ay = (int)$fixture['term']['ay_id'];
    $semester = (int)$fixture['term']['semester'];
    $classes = synk_professor_fetch_subject_rows_by_academic_year($conn, $faculty, $ay, $semester);
    $first = (int)$fixture['offerings'][0]['offering_id'];
    $second = (int)$fixture['offerings'][1]['offering_id'];
    $read = static function () use ($conn, $faculty, &$classes, $ay, $semester): array {
        return synk_professor_workload_summaries_lms($conn, $faculty, $classes, $ay, $semester);
    };
    $empty = ['activity_count' => 0, 'draft_count' => 0, 'next_deadline' => null];
    $check($read() === [$first => $empty, $second => $empty], 'Empty classes must show honest zero counts and no deadline.');
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
    $date = static function (string $offset) use ($now): string { return $now->modify($offset)->format('Y-m-d H:i:s'); };
    $insert = static function (array $scope, string $status, string $title, ?string $due) use ($lms, $now): int {
        synk_assessment_query_lms($lms, "INSERT INTO tbl_lms_assessments
            (faculty_id,offering_id,ay_id,semester,created_by_user_id,grading_period,assessment_type,title,instructions,status,total_points,due_at,created_at,updated_at)
            VALUES (?,?,?,?,?,'midterm','assignment',?,'',?,100,?,?,?)", 'iiiiisssss',
            array_merge($scope, [$title, $status, $due, $now->format('Y-m-d H:i:s'), $now->format('Y-m-d H:i:s')]));
        return (int)$lms->insert_id;
    };
    $scope = [$faculty, $first, $ay, $semester, $fixture['user_id']];
    $insert($scope, 'published', 'Assignment 3', $date('+5 days'));
    $insert($scope, 'draft', 'Earlier private draft', $date('+1 day'));
    $insert($scope, 'draft', 'Unscheduled draft', null);
    $insert($scope, 'published', 'Past deadline', $date('-1 day'));
    $insert($scope, 'closed', 'Closed activity', $date('+1 day'));
    $insert($scope, 'published', 'Later deadline', $date('+7 days'));
    $insert($scope, 'archived', 'Archived activity', $date('+1 day'));
    $expected = ['activity_count' => 6, 'draft_count' => 2,
        'next_deadline' => ['title' => 'Assignment 3', 'due_at' => $date('+5 days')]];
    $check($read()[$first] === $expected, 'Counts exclude archives, and only the earliest future published deadline is eligible.');
    $check($read()[$second] === $empty, 'Activities must not spill into another assigned section of the same subject.');
    foreach ([array_replace($scope, [0 => $faculty + 100000]),
        array_replace($scope, [1 => (int)$fixture['offerings'][2]['offering_id']]),
        array_replace($scope, [2 => $ay + 100000]), array_replace($scope, [3 => $semester === 1 ? 2 : 1])] as $foreign) {
        $insert($foreign, 'published', 'Foreign deadline', $date('+1 day'));
    }
    $check($read() === [$first => $expected, $second => $empty], 'Other faculty, unassigned offerings, years, and semesters must not affect summaries.');
    $tie = $insert($scope, 'published', 'Same deadline, newer activity', $date('+5 days'));
    $check($read()[$first]['next_deadline'] === $expected['next_deadline'], 'Equal deadlines must select a stable title.');
    synk_assessment_query_lms($lms, "UPDATE tbl_lms_assessments SET status='archived' WHERE assessment_id=?", 'i', [$tie]);
    $check(synk_professor_workload_summaries_lms($conn, $faculty, [], $ay, $semester) === [], 'Empty workloads must not query unrelated LMS records.');
    $check(synk_professor_workload_summaries_lms($conn, $faculty + 1, $classes, $ay, $semester) === [], 'Rows from another faculty context must be rejected.');
    $check(synk_professor_workload_summaries_lms($conn, $faculty, $classes, $ay + 1, $semester) === [], 'Rows from another academic year must be rejected.');
    $check(synk_professor_workload_summaries_lms($conn, $faculty, $classes, $ay, 4) === [], 'Invalid semester contexts must be rejected.');
    if (($argv[1] ?? '') === '--render') {
        session_save_path(dirname(__DIR__) . '/.tmp');
        session_id('workloadsummaryqa' . bin2hex(random_bytes(10)));
        session_start();
        $_SESSION = ['user_id' => $fixture['user_id'], 'role' => 'professor', 'username' => 'QA Professor', 'email' => 'qa@example.test'];
        session_write_close();
        $_GET = ['term' => $ay . '-' . $semester];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['PHP_SELF'] = '/synk/professor/workload.php';
        $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/professor/workload.php';
        $_SERVER['SCRIPT_NAME'] = '/synk/professor/workload.php';
        chdir(dirname(__DIR__) . '/professor');
        $bufferLevel = ob_get_level();
        ob_start();
        require 'workload.php';
        $html = '';
        while (ob_get_level() > $bufferLevel) $html = ob_get_clean() . $html;
        if (file_put_contents($argv[2], $html) === false) throw new RuntimeException('Could not write the workload summary preview.');
        session_destroy();
    }
    $check($lms->query('SHOW TABLES')->fetch_all(MYSQLI_NUM) === $tablesBefore, 'Reading summaries must not create LMS storage.');
} finally { $lms->rollback(); $conn->rollback(); }
$check($conn->query('CHECKSUM TABLE ' . $coreTables)->fetch_all(MYSQLI_ASSOC) === $coreBefore, 'Core academic, student, account, and workload data must be preserved.');
$check($lms->query('CHECKSUM TABLE tbl_lms_assessments')->fetch_all(MYSQLI_ASSOC) === $lmsBefore, 'LMS activity fixtures must roll back.');
echo 'Professor workload LMS summaries: ', $checks, ' checks passed. Fixtures rolled back.', PHP_EOL;
