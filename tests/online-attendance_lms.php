<?php
// Attendance and Grade Book integration using transaction-scoped fixtures.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/online_attendance_professor_lms.php';
require_once __DIR__.'/../backend/online_attendance_student_lms.php';
require_once __DIR__.'/fixtures/professor-workload-students-data.php';

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$reject = static function (callable $action, int $status) use ($check): void {
    try { $action(); }
    catch (DomainException $error) {
        $check($error->getCode() === $status,'Unexpected rejection: '.$error->getMessage());
        return;
    }
    $check(false,'An invalid attendance action was accepted.');
};
$lms = synk_database_connection_lms($conn);
$check(synk_online_attendance_ready_lms($lms) && synk_gradebook_ready_lms($lms),'Install the LMS attendance and Grade Book tables before testing.');
$check(!$conn->query("SHOW TABLES LIKE 'tbl_lms_online_attendance_%'")->num_rows,'Online attendance storage must be separate from core.');

$manilaWindow = synk_online_attendance_window_lms(['title'=>'Midnight class','opens_at'=>'2026-11-26T00:05','closes_at'=>'2026-11-26T00:15']);
$check($manilaWindow['opens_at'] === '2026-11-25 16:05:00' && $manilaWindow['closes_at'] === '2026-11-25 16:15:00'
    && synk_online_attendance_iso_lms($manilaWindow['opens_at']) === '2026-11-25T16:05:00Z','Philippine midnight did not convert to the previous UTC date.');
$window = $manilaWindow+['cancelled_at'=>null];
foreach (['2026-11-25 16:04:59'=>'scheduled','2026-11-25 16:05:00'=>'open','2026-11-25 16:14:59'=>'open','2026-11-25 16:15:00'=>'closed'] as $now=>$state) {
    $check(synk_online_attendance_state_lms($window,$now) === $state,'The opening-inclusive, closing-exclusive attendance boundary is wrong.');
}
foreach ([['opens_at'=>'2026-02-30T08:00'],['opens_at'=>'2026-11-26T00:15'],['closes_at'=>''],['opens_at'=>'2026-11-26T00:05+08:00']] as $invalid) {
    $reject(static function () use ($invalid): void {
        synk_online_attendance_window_lms($invalid+['title'=>'Invalid','opens_at'=>'2026-11-26T00:05','closes_at'=>'2026-11-26T00:15']);
    },422);
}

$coreTables = 'tbl_class_schedule,tbl_faculty_workload_sched,tbl_student_management,tbl_student_management_enrolled_subjects,tbl_academic_settings';
$lmsTables = 'tbl_lms_online_attendance_periods,tbl_lms_online_attendance_sessions,tbl_lms_online_attendance_roster,tbl_lms_online_attendance_logs'
    .',tbl_lms_grading_schemes,tbl_lms_grading_components,tbl_lms_grading_logs,tbl_lms_gradebook_periods,tbl_lms_gradebook_items,tbl_lms_gradebook_scores,tbl_lms_gradebook_logs';
$snapshot = static function (mysqli $database, string $tables): array {
    return $database->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC);
};
$coreBefore = $snapshot($conn,$coreTables);
$lmsBefore = $snapshot($lms,$lmsTables);
$conn->begin_transaction();
$lms->begin_transaction();
try {
    $fixture = professor_students_fixtures($conn);
    $actor = $fixture['user_id'];
    $_SESSION = ['role'=>'professor','user_id'=>$actor];
    $contexts = [];
    foreach (array_slice($fixture['offerings'],0,2) as $offering) {
        $query = parse_url(synk_professor_class_url_lms([
            'faculty_id'=>$fixture['faculty_id'],'offering_id'=>$offering['offering_id'],
            'ay_id'=>$fixture['term']['ay_id'],'semester'=>$fixture['term']['semester'],
        ],'attendance'),PHP_URL_QUERY);
        parse_str($query,$params);
        $contexts[] = synk_professor_class_context_lms($conn,$params);
    }
    [$context,$otherContext] = $contexts;
    $coreFixture = $snapshot($conn,$coreTables);
    [$first,$second,$late] = [$fixture['students']['active'],$fixture['students']['inactive'],$fixture['students']['failed']];
    synk_class_student_status_change_lms($conn,$lms,$context,['student_id'=>$late,'version'=>0,'status'=>'excluded','reason'=>'Fixture'],$actor);
    $students = static function () use ($conn,$lms,$context): array {
        return synk_online_attendance_students_lms($conn,$lms,$context);
    };
    $roster = array_column($students(),null,'student_id');
    $check(count($roster) === 2 && !isset($roster[$late]),'The session did not use the eligible LMS class roster.');
    $period = synk_online_attendance_period_lms($lms,$context,'midterm',true,true);
    $periodId = (int)$period['attendance_period_id'];
    $other = synk_online_attendance_period_lms($lms,$otherContext,'midterm',true,true);
    $check($periodId !== (int)$other['attendance_period_id'] && synk_online_attendance_period_lms($lms,$context,'final') === null,
        'Attendance was shared across class sections or grading periods.');

    $pht = new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
    $schedule = static function (string $title, string $starts = '-5 minutes', string $ends = '+15 minutes') use ($pht): array {
        return ['title'=>$title,'opens_at'=>$pht->modify($starts)->format('Y-m-d\TH:i'),
            'closes_at'=>$pht->modify($ends)->format('Y-m-d\TH:i'),'request_key'=>bin2hex(random_bytes(32))];
    };
    $close = static function (int $id) use ($lms): void {
        synk_grading_query_lms($lms,'UPDATE tbl_lms_online_attendance_sessions
            SET opens_at=UTC_TIMESTAMP()-INTERVAL 30 MINUTE,closes_at=UTC_TIMESTAMP()-INTERVAL 1 MINUTE WHERE session_id=?','i',[$id]);
    };
    $checkIn = static function (int $id, int $student) use ($lms,$context,$roster): array {
        return synk_online_attendance_check_in_lms($lms,$context,'midterm',$id,$roster[$student]);
    };
    $firstInput = $schedule('First check-in');
    $firstSession = synk_online_attendance_schedule_lms($lms,$periodId,$firstInput,$roster,$actor,false);
    $check(synk_online_attendance_schedule_lms($lms,$periodId,$firstInput,$roster,$actor,false) === $firstSession,
        'Retrying a schedule created a second session.');
    $reject(static function () use ($lms,$periodId,$firstInput,$roster,$actor): void {
        synk_online_attendance_schedule_lms($lms,$periodId,array_replace($firstInput,['title'=>'Different session']),$roster,$actor,false);
    },409);
    $reject(static function () use ($lms,$other,$firstSession): void {
        synk_online_attendance_session_lms($lms,(int)$other['attendance_period_id'],$firstSession,true);
    },404);
    $checked = $checkIn($firstSession,$first);
    $check($checked['state'] === 'open' && $checked['result'] === 'present' && $checked['checked_in_at'] !== null
        && $checkIn($firstSession,$first)['checked_in_at'] === $checked['checked_in_at'],'A duplicate check-in did not preserve the original timestamp.');
    $detail = synk_online_attendance_detail_lms($lms,$periodId,$firstSession,synk_online_attendance_now_lms());
    $rows = array_column($detail['roster'],null,'student_id');
    $check($rows[$second]['result'] === 'pending','A student was marked absent while the check-in window was open.');
    $reject(static function () use ($lms,$context,$firstSession,$late): void {
        synk_online_attendance_check_in_lms($lms,$context,'midterm',$firstSession,
            ['student_id'=>$late,'student_number'=>'QA','full_name'=>'Excluded student']);
    },404);
    [$faculty,$offering] = synk_grading_scope_lms($context);
    $studentClasses = [$faculty.':'.$offering=>['context'=>$context,'subject'=>[
        'subject_key'=>'fixture-subject','subject_code'=>'QA','descriptive_title'=>'QA','faculty_name'=>'QA',
    ]]];
    $studentView = synk_online_attendance_student_list_lms($lms,$fixture['term'],$studentClasses,$second,synk_online_attendance_now_lms());
    $studentSession = $studentView[0]['sessions'][0];
    $check($studentSession['result'] === 'pending' && $studentSession['checked_in_at'] === null
        && !array_intersect_key($studentSession,array_flip(['student_id','roster','override_reason'])),
        'A student attendance response leaked another student’s check-in or professor-only fields.');
    $liveCards = static function (int $student) use ($lms,$fixture,$studentClasses): array {
        $view = synk_online_attendance_student_list_lms($lms,$fixture['term'],$studentClasses,$student,synk_online_attendance_now_lms(),true);
        return array_column($view[0]['sessions'] ?? [],null,'session_id');
    };
    $check($liveCards($second)[$firstSession]['result'] === 'pending' && $liveCards($second)[$firstSession]['checked_in_at'] === null
        && $liveCards($first)[$firstSession]['checked_in_at'] === $checked['checked_in_at'],
        'The live cards must return the open session and each student’s own confirmed check-in.');
    $reject(static function () use ($lms,$periodId,$firstSession,$second,$actor): void {
        synk_online_attendance_review_lms($lms,$periodId,[
            'session_id'=>$firstSession,'version'=>1,'student_id'=>$second,'status'=>'present','reason'=>'Fixture',
        ],$actor,false);
    },422);
    $close($firstSession);
    $reject(static function () use ($checkIn,$firstSession,$second): void { $checkIn($firstSession,$second); },409);
    $check($checkIn($firstSession,$first)['checked_in_at'] === $checked['checked_in_at'],'A post-close retry changed a recorded check-in.');
    $detail = synk_online_attendance_detail_lms($lms,$periodId,$firstSession,synk_online_attendance_now_lms());
    $check(array_column($detail['roster'],'result','student_id')[$second] === 'absent','Closing did not mark missing check-ins absent.');
    $check($liveCards($second)[$firstSession]['state'] === 'closed' && $liveCards($second)[$firstSession]['result'] === 'absent',
        'The live cards must show the eligible missed result when the window closes.');

    $secondSession = synk_online_attendance_schedule_lms($lms,$periodId,$schedule('Second check-in'),$roster,$actor,false);
    $checkIn($secondSession,$second);
    $close($secondSession);
    $cancelled = synk_online_attendance_schedule_lms($lms,$periodId,$schedule('Cancelled check-in'),$roster,$actor,false);
    $checkIn($cancelled,$first);
    synk_online_attendance_review_lms($lms,$periodId,['session_id'=>$cancelled,'version'=>1,'reason'=>'Fixture cancellation'],$actor,true);
    $reject(static function () use ($checkIn,$cancelled,$second): void { $checkIn($cancelled,$second); },409);
    $scheduled = synk_online_attendance_schedule_lms($lms,$periodId,$schedule('Future check-in','+10 minutes','+20 minutes'),$roster,$actor,false);
    $reject(static function () use ($checkIn,$scheduled,$second): void { $checkIn($scheduled,$second); },409);
    $newWindow = ['session_id'=>$scheduled,'version'=>1]+$schedule('Rescheduled','+12 minutes','+22 minutes');
    $check(synk_online_attendance_schedule_lms($lms,$periodId,$newWindow,$roster,$actor,true) === $scheduled
        && (int)synk_online_attendance_session_lms($lms,$periodId,$scheduled)['version'] === 2,'Editing did not refresh the existing future session.');
    $reject(static function () use ($lms,$periodId,$newWindow,$roster,$actor): void {
        synk_online_attendance_schedule_lms($lms,$periodId,$newWindow,$roster,$actor,true);
    },409);

    $later = synk_online_attendance_schedule_lms($lms,$periodId,$schedule('Later check-in','+25 minutes','+35 minutes'),$roster,$actor,false);
    $check(array_keys($liveCards($first)) === [$scheduled,$secondSession] && $liveCards($first)[$secondSession]['result'] === 'absent',
        'The live cards must compact history and return the next opening, excluding cancelled and later sessions.');
    $history = synk_online_attendance_student_list_lms($lms,$fixture['term'],$studentClasses,$first,synk_online_attendance_now_lms());
    $check(array_diff([$firstSession,$secondSession,$cancelled,$scheduled,$later],array_column($history[0]['sessions'],'session_id')) === [],
        'The attendance history must still contain all the session records.');

    $profile = synk_student_fetch_directory_record_by_student_id($conn,$second);
    $csrf = bin2hex(random_bytes(32));
    $_SESSION = ['role'=>'student','user_id'=>'student:qa','student_google_sub'=>'qa','student_id'=>$second,
        'email'=>$profile['email_address'],'student_online_attendance_csrf'=>$csrf];
    synk_student_presence_start($conn,time()-60);
    $presence = static function () use ($conn): array {
        return synk_grading_query_lms($conn,'SELECT last_activity_at,last_seen_at FROM tbl_student_portal_sessions WHERE presence_key=?',
            's',[$_SESSION['student_presence_key']])[0];
    };
    $beforePoll = $presence();
    $reject(static function () use ($conn): void { synk_online_attendance_student_request_lms($conn,['action'=>'cards','csrf_token'=>'invalid']); },403);
    $reject(static function () use ($conn,$csrf,$first): void {
        synk_online_attendance_student_request_lms($conn,['action'=>'cards','csrf_token'=>$csrf,'student_id'=>$first]);
    },400);
    $poll = synk_online_attendance_student_request_lms($conn,['action'=>'cards','csrf_token'=>$csrf]);
    $pollSessions = array_column($poll['subjects'][0]['sessions'],null,'session_id');
    $subjectKey = synk_student_subject_key_lms($faculty,$context['class']['subject_code'],$context['class']['descriptive_title']);
    $check(count($poll['subjects']) === 1 && $poll['subjects'][0]['subject_key'] === $subjectKey
        && array_keys($pollSessions) === [$scheduled,$secondSession] && $pollSessions[$secondSession]['result'] === 'present'
        && $presence()['last_activity_at'] === $beforePoll['last_activity_at'],
        'The live endpoint must match the card for a legacy enrollment, return its own result and preserve the idle deadline.');
    $_SESSION = ['role'=>'professor','user_id'=>$actor];

    synk_class_student_status_change_lms($conn,$lms,$context,['student_id'=>$late,'version'=>1,'status'=>'enrolled','reason'=>'Fixture re-enrollment'],$actor);
    $check(array_keys($liveCards($late)) === [$scheduled],
        'A newly enrolled student must not see a missed result for a session outside their roster.');
    $totals = static function () use ($lms,$periodId,$students): array {
        return synk_online_attendance_totals_lms($lms,$periodId,synk_online_attendance_sessions_lms($lms,$periodId),$students(),synk_online_attendance_now_lms());
    };
    $tot = $totals();
    $byStudent = array_column($tot['rows'],null,'student_id');
    $check($tot['closed_count'] === 2 && $tot['scored_count'] === 2 && $byStudent[$first]['present'] === 1
        && $byStudent[$first]['absent'] === 1 && $byStudent[$first]['score'] === 50.0 && $byStudent[$late]['score'] === null,
        'Totals included a cancelled/open session or backfilled absences for a student outside the session rosters.');

    $scheme = synk_grading_save_lms($lms,$context,['grading_period'=>'midterm','version'=>0,
        'components'=>[['name'=>'Coursework','weight'=>90],['name'=>'Attendance','weight'=>10]]],$actor);
    [$coursework,$attendance] = array_column($scheme['components'],'component_id');
    $check(synk_gradebook_period_lms($lms,$context,'midterm')['book_id'] === 0,'Online check-ins applied scores without the professor.');
    $apply = static function (string $digest, ?int $component = null, ?int $version = null)
        use ($lms,$context,$totals,$actor,$attendance): array {
        $p = synk_online_attendance_period_lms($lms,$context,'midterm',true);
        $book = synk_gradebook_period_lms($lms,$context,'midterm',true);
        synk_online_attendance_apply_lms($lms,$context,$p,$book,$totals(),[
            'attendance_digest'=>$digest,'component_id'=>$component ?? $attendance,'version'=>$version ?? $book['version'],
            'scheme_version'=>$book['scheme']['version'],
        ],$actor);
        return synk_online_attendance_period_lms($lms,$context,'midterm');
    };
    $reject(static function () use ($apply,$tot): void { $apply($tot['digest'],2147483647); },422);
    $applied = $apply($tot['digest']);
    $itemId = (int)$applied['gradebook_item_id'];
    $book = synk_gradebook_period_lms($lms,$context,'midterm');
    $items = synk_gradebook_items_lms($lms,$book['book_id']);
    $grade = synk_student_gradebook_period_lms($lms,$context,'midterm',$first);
    $check(count($items) === 1 && $items[0]['online_attendance'] && $items[0]['component_id'] === $attendance
        && $grade['entries'][0]['points'] === 50.0 && $grade['result']['percent'] === 5.0,
        'Applying attendance did not yield the agreed 50/100 score weighted at 10%.');
    $lateGrade = synk_student_gradebook_period_lms($lms,$context,'midterm',$late);
    $check($lateGrade['entries'][0]['points'] === null && $lateGrade['result']['components'][1]['possible'] === 0,
        'A student with zero eligible sessions was assigned an attendance score.');
    $apply($tot['digest'],null,0);
    $check(synk_gradebook_period_lms($lms,$context,'midterm')['version'] === $book['version'],'Retrying the same totals generated another grade revision.');
    $reject(static function () use ($conn,$lms,$context,$book,$actor,$itemId,$first): void {
        synk_gradebook_mutate_lms($conn,$lms,$context,'save_scores',['grading_period'=>'midterm','version'=>$book['version'],
            'scheme_version'=>$book['scheme']['version'],'item_id'=>$itemId,'scores'=>[['student_id'=>$first,'points'=>77]]],$actor);
    },422);

    $correction = ['session_id'=>$secondSession,'version'=>1,'student_id'=>$first,'status'=>'present','reason'=>'Verified connection issue'];
    $reject(static function () use ($lms,$periodId,$correction,$actor): void {
        synk_online_attendance_review_lms($lms,$periodId,array_replace($correction,['reason'=>'']),$actor,false);
    },422);
    synk_online_attendance_review_lms($lms,$periodId,$correction,$actor,false);
    $logs = synk_grading_query_lms($lms,"SELECT actor_user_id,details_json FROM tbl_lms_online_attendance_logs WHERE session_id=? AND action='corrected'",'i',[$secondSession]);
    $check(count($logs) === 1 && (int)$logs[0]['actor_user_id'] === $actor
        && json_decode($logs[0]['details_json'],true)['reason'] === $correction['reason'],'A correction did not keep its actor and reason.');
    $reject(static function () use ($apply,$tot): void { $apply($tot['digest']); },409);
    $check(synk_student_gradebook_period_lms($lms,$context,'midterm',$first)['result']['percent'] === 5.0,
        'Correcting attendance changed the grade before applying.');
    $changed = $apply($totals()['digest']);
    $check((int)$changed['gradebook_item_id'] === $itemId
        && synk_student_gradebook_period_lms($lms,$context,'midterm',$first)['result']['percent'] === 10.0,
        'Applying corrected attendance duplicated the entry or used stale scores.');
    $check(synk_gradebook_period_lms($lms,$context,'final')['book_id'] === 0,'Midterm attendance created a Final grade.');
    $apply($totals()['digest'],$coursework);
    $check(synk_gradebook_items_lms($lms,$book['book_id'])[0]['component_id'] === $coursework,'The professor could not choose a different grading component.');

    synk_online_attendance_review_lms($lms,$periodId,['session_id'=>$firstSession,'version'=>1,'reason'=>'Fixture'],$actor,true);
    synk_online_attendance_review_lms($lms,$periodId,['session_id'=>$secondSession,'version'=>2,'reason'=>'Fixture'],$actor,true);
    $apply($totals()['digest']);
    $check(!synk_gradebook_items_lms($lms,$book['book_id']) && $totals()['closed_count'] === 0,
        'Reapplying after cancelling all closed sessions left a grade entry counting.');
    $check($coreFixture === $snapshot($conn,$coreTables),'Online attendance modified core scheduling, workload or enrollment data.');
} finally {
    $lms->rollback();
    $conn->rollback();
}
$check($coreBefore === $snapshot($conn,$coreTables) && $lmsBefore === $snapshot($lms,$lmsTables),'Fixture records escaped rollback.');
echo 'Online Attendance LMS: ',$checks,' checks passed. Fixtures rolled back.',PHP_EOL;
