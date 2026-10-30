<?php
// CLI regression checks. Every fixture write is rolled back; no schema changes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/auth_useraccount.php';
require_once __DIR__ . '/../backend/professor_workload_students_helper.php';
require_once __DIR__ . '/fixtures/professor-workload-students-data.php';
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void { if (!$condition) throw new RuntimeException($message); $checks++; };
$tables = ['tbl_faculty','tbl_useraccount','tbl_useraccount_roles','tbl_useraccount_faculty_links','tbl_faculty_workload_sched',
    'tbl_student_management','tbl_student_management_enrolled_subjects','tbl_student_login_activity','tbl_offering_enrollee_counts',synk_schedule_merge_table_name(),'tbl_academic_settings'];
$snapshot = static function () use ($conn, $tables): string {
    $rows = [];
    foreach ($tables as $table) $rows[$table] = hash('sha256', json_encode($conn->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    return hash('sha256', json_encode($rows));
};
$before = $snapshot();
$ownedPhoto = null;
$conn->begin_transaction();
try {
    $fixture = professor_students_fixtures($conn);
    extract($fixture);
    $classes = synk_professor_fetch_subject_rows_by_academic_year($conn, $faculty_id, $term['ay_id'], $term['semester']);
    $check(count($classes) === 2, 'The QA professor sees only two assigned classes.');
    $summary = synk_professor_workload_student_summary($conn, $classes, $term['ay_id'], $term['semester']);
    $check($summary['totals'] === ['active'=>1,'inactive'=>3,'students'=>4], 'Top counts deduplicate students across classes and count historical successful Student sign-ins.');
    $firstId = (int)$offerings[0]['offering_id']; $secondId = (int)$offerings[1]['offering_id'];
    $check($summary['classes'][$firstId] === ['active'=>1,'inactive'=>2,'students'=>3], 'Legacy offering-zero enrollments match; failed logins, withdrawn rows and another term do not create active students.');
    $check($summary['classes'][$secondId] === ['active'=>1,'inactive'=>1,'students'=>2], 'Each class has its own activity split.');
    $roster = synk_professor_workload_students($conn, $faculty_id, $term['ay_id'], $term['semester'], $firstId);
    $check($roster['class']['enrolled'] === 260 && count($roster['students']) === 3, 'Keep recorded enrollment totals separate from actual named students.');
    $byId = array_column($roster['students'], null, 'student_id');
    $check($byId[$students['active']]['active'] && $byId[$students['active']]['full_name'] === 'José Test Student Jr.', 'Full names and login classification are accurate.');
    $check(!$byId[$students['failed']]['active'] && !isset($byId[$students['foreign']]), 'Failed sign-ins are inactive; unrelated students are excluded.');
    $check($byId[$students['active']]['photo_url'] === '../student/profile-placeholder.svg', 'Missing photos use the Student placeholder.');
    $photoPath = synk_student_profile_image_path($students['active']);
    $photoHandle = fopen($photoPath, 'x+b');
    if (!$photoHandle) throw new RuntimeException('Never overwrite a real student photo.');
    $ownedPhoto = $photoPath;
    $photo = imagecreatetruecolor(24,24); imagejpeg($photo,$photoHandle); imagedestroy($photo); fclose($photoHandle);
    $photoRoster = synk_professor_workload_students($conn,$faculty_id,$term['ay_id'],$term['semester'],$firstId);
    $photoById = array_column($photoRoster['students'],null,'student_id');
    $check(strpos($photoById[$students['active']]['photo_url'],'../uploads/student-profile/student_' . $students['active'] . '.jpg?v=')===0,'Student images use the real uploaded photo with its cache version.');
    foreach ([['faculty'=>0,'year'=>$term['ay_id'],'offering'=>$firstId], ['faculty'=>$faculty_id,'year'=>$term['ay_id']+100000,'offering'=>$firstId],
        ['faculty'=>$faculty_id,'year'=>$term['ay_id'],'offering'=>(int)$offerings[2]['offering_id']]] as $denied) {
        try { synk_professor_workload_students($conn, $denied['faculty'], $denied['year'], $term['semester'], $denied['offering']); $check(false, 'Unauthorized roster was returned.'); }
        catch (DomainException $error) { $check($error->getCode() === 404, 'Foreign, unlinked or wrong-term roster access is denied.'); }
    }
    // Render the real page against these same rollback-only records for browser QA.
    if (($argv[1] ?? '') === '--render') {
        $renderHtmlPath = $argv[2]; $renderDataPath = $argv[3];
        session_save_path(dirname(__DIR__) . '/.tmp'); session_id('workloadqa' . bin2hex(random_bytes(10))); session_start();
        $_SESSION = ['user_id'=>$user_id,'role'=>'professor','username'=>'QA Professor','email'=>'qa@example.test'];
        session_write_close();
        $_GET = ['term'=>$term['ay_id'] . '-' . $term['semester']];
        $_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['PHP_SELF'] = '/synk/professor/workload.php';
        $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/professor/workload.php'; $_SERVER['SCRIPT_NAME'] = '/synk/professor/workload.php';
        chdir(dirname(__DIR__) . '/professor');
        ob_start(); require 'workload.php'; $html = ob_get_clean();
        if (file_put_contents($renderHtmlPath, $html) === false) throw new RuntimeException('Could not write the workload preview.');
        if (file_put_contents($renderDataPath, json_encode(['roster'=>$roster,'other'=>synk_professor_workload_students($conn,$faculty_id,$term['ay_id'],$term['semester'],$secondId)])) === false) throw new RuntimeException('Could not write the roster preview.');
        session_destroy();
    }
    $mergeTable = synk_schedule_merge_table_name();
    $conn->query("INSERT INTO {$mergeTable} (owner_offering_id,member_offering_id,merge_scope) VALUES ({$firstId},{$secondId},'FULL')");
    $classes = synk_professor_fetch_subject_rows_by_academic_year($conn, $faculty_id, $term['ay_id'], $term['semester']);
    $merged = synk_professor_workload_students($conn, $faculty_id, $term['ay_id'], $term['semester'], $firstId);
    $check(count($classes) === 1 && $merged['counts'] === ['active'=>1,'inactive'=>3,'students'=>4], 'Merged classes combine student rosters without counting a student twice.');
    $conn->query("UPDATE tbl_faculty SET status='inactive' WHERE faculty_id={$faculty_id}");
    try { synk_professor_workload_students($conn,$faculty_id,$term['ay_id'],$term['semester'],$firstId); $check(false,'Inactive faculty can access rosters.'); }
    catch (DomainException $error) { $check($error->getCode()===404,'Inactive faculty lose roster access.'); }
} finally { $conn->rollback(); if ($ownedPhoto !== null && is_file($ownedPhoto)) unlink($ownedPhoto); }
$check($snapshot() === $before, 'All fixtures and academic data are unchanged after rollback.');
echo 'Professor workload students: ', $checks, ' checks passed. Fixtures rolled back.', PHP_EOL;
