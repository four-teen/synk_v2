<?php
require_once __DIR__.'/online_attendance_storage_lms.php';
require_once __DIR__.'/class_student_status_helper_lms.php';

function synk_online_attendance_now_lms(): string
{
    return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

function synk_online_attendance_iso_lms(?string $utc): ?string
{
    return $utc === null ? null : str_replace(' ','T',$utc).'Z';
}

function synk_online_attendance_window_lms(array $input): array
{
    $opens = synk_assessment_date_lms($input['opens_at'] ?? null,'opening time');
    $closes = synk_assessment_date_lms($input['closes_at'] ?? null,'closing time');
    if ($opens === null || $closes === null || $closes <= $opens) {
        throw new DomainException('Choose a Philippine-time start and a closing time after it.',422);
    }
    $manila = new DateTimeZone('Asia/Manila');
    $utc = new DateTimeZone('UTC');
    return [
        'title'=>synk_assessment_text_lms($input['title'] ?? '','Session title',200,true),
        'opens_at'=>(new DateTimeImmutable($opens,$manila))->setTimezone($utc)->format('Y-m-d H:i:s'),
        'closes_at'=>(new DateTimeImmutable($closes,$manila))->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];
}

function synk_online_attendance_state_lms(array $session, string $now): string
{
    if ($session['cancelled_at'] !== null) return 'cancelled';
    if ($now < $session['opens_at']) return 'scheduled';
    return $now < $session['closes_at'] ? 'open' : 'closed';
}

function synk_online_attendance_result_lms(string $state, ?array $student): string
{
    if ($state === 'cancelled') return 'cancelled';
    if (!empty($student['override_status'])) return $student['override_status'];
    if (!empty($student['checked_in_at'])) return 'present';
    if (!$student && $state === 'closed') return 'not_eligible';
    return $state === 'closed' ? 'absent' : 'pending';
}

function synk_online_attendance_period_lms(mysqli $lms, array $context, string $period, bool $lock = false, bool $create = false): ?array
{
    $args = array_merge(synk_grading_scope_lms($context),[$period]);
    if ($create) {
        synk_grading_query_lms($lms,'INSERT INTO tbl_lms_online_attendance_periods
            (faculty_id,offering_id,ay_id,semester,grading_period) VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE attendance_period_id=attendance_period_id','iiiis',$args);
    }
    return synk_grading_query_lms($lms,'SELECT attendance_period_id,gradebook_item_id,applied_digest,applied_at
        FROM tbl_lms_online_attendance_periods WHERE faculty_id=? AND offering_id=? AND ay_id=? AND semester=? AND grading_period=?'
        .($lock ? ' FOR UPDATE' : ''),'iiiis',$args)[0] ?? null;
}

function synk_online_attendance_session_lms(mysqli $lms, int $periodId, int $sessionId, bool $lock = false): array
{
    $rows = synk_grading_query_lms($lms,'SELECT session_id,title,opens_at,closes_at,cancelled_at,version
        FROM tbl_lms_online_attendance_sessions WHERE session_id=? AND attendance_period_id=?'
        .($lock ? ' FOR UPDATE' : ''),'ii',[$sessionId,$periodId]);
    if (!$rows) throw new DomainException('This attendance session is unavailable in the selected class and period.',404);
    return $rows[0];
}

function synk_online_attendance_public_session_lms(array $row, string $now): array
{
    return ['session_id'=>(int)$row['session_id'],'title'=>$row['title'],'state'=>synk_online_attendance_state_lms($row,$now),
        'opens_at'=>synk_online_attendance_iso_lms($row['opens_at']),'closes_at'=>synk_online_attendance_iso_lms($row['closes_at']),
        'version'=>(int)$row['version']];
}

function synk_online_attendance_add_roster_lms(mysqli $lms, int $sessionId, array $students): void
{
    foreach (array_chunk($students,150) as $chunk) {
        $values = [];
        foreach ($chunk as $student) {
            array_push($values,$sessionId,(int)$student['student_id'],(string)$student['student_number'],$student['full_name']);
        }
        synk_grading_query_lms($lms,'INSERT INTO tbl_lms_online_attendance_roster (session_id,student_id,student_number,student_name)
            VALUES '.implode(',',array_fill(0,count($chunk),'(?,?,?,?)')),str_repeat('iiss',count($chunk)),$values);
    }
}

function synk_online_attendance_log_lms(mysqli $lms, int $sessionId, int $actor, string $action, array $details, string $now): void
{
    synk_grading_query_lms($lms,'INSERT INTO tbl_lms_online_attendance_logs (session_id,actor_user_id,action,details_json,recorded_at)
        VALUES (?,?,?,?,?)','iisss',[$sessionId,$actor,$action,json_encode($details,JSON_UNESCAPED_UNICODE),$now]);
}

function synk_online_attendance_detail_lms(mysqli $lms, int $periodId, int $sessionId, string $now): array
{
    $session = synk_online_attendance_public_session_lms(synk_online_attendance_session_lms($lms,$periodId,$sessionId),$now);
    $rows = synk_grading_query_lms($lms,'SELECT student_id,student_number,student_name,checked_in_at,override_status,override_reason
        FROM tbl_lms_online_attendance_roster WHERE session_id=? ORDER BY student_name,student_number,student_id','i',[$sessionId]);
    foreach ($rows as &$row) {
        $row['student_id'] = (int)$row['student_id'];
        $row['result'] = synk_online_attendance_result_lms($session['state'],$row);
        $row['checked_in_at'] = synk_online_attendance_iso_lms($row['checked_in_at']);
    }
    unset($row);
    return ['session'=>$session,'roster'=>$rows];
}

// All mutations take the same attendance-period lock before reading or
// changing the session, including student check-ins and grade application.
function synk_online_attendance_schedule_lms(mysqli $lms, int $periodId, array $input, array $roster, int $actor, bool $edit): int
{
    $window = synk_online_attendance_window_lms($input);
    $now = synk_online_attendance_now_lms();
    if (!$edit) {
        $key = $input['request_key'] ?? null;
        if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D',$key)) throw new DomainException('Reload the session form before scheduling attendance.',422);
        $hash = hash('sha256',json_encode([$window,$actor]));
        $prior = synk_grading_query_lms($lms,'SELECT session_id,request_hash FROM tbl_lms_online_attendance_sessions
            WHERE attendance_period_id=? AND request_key=?','is',[$periodId,$key])[0] ?? null;
        if ($prior) {
            if (!hash_equals($prior['request_hash'],$hash)) throw new DomainException('This submission was already used. Open a new session form.',409);
            return (int)$prior['session_id'];
        }
    }
    if ($window['closes_at'] <= $now) throw new DomainException('Choose a closing time in the future.',422);
    $eligible = array_filter($roster,static function (array $student): bool { return $student['class_status'] === 'enrolled'; });
    if (!$eligible) throw new DomainException('This class has no enrolled students to record attendance for.',422);

    if ($edit) {
        $id = synk_assessment_id_lms($input['session_id'] ?? null);
        $before = synk_online_attendance_session_lms($lms,$periodId,$id,true);
        if (synk_grading_integer_lms($input['version'] ?? null) !== (int)$before['version']) {
            throw new DomainException('This session changed. Refresh before editing it.',409);
        }
        if (synk_online_attendance_state_lms($before,$now) !== 'scheduled') {
            throw new DomainException('The attendance window has already opened or been cancelled.',409);
        }
        synk_grading_query_lms($lms,'UPDATE tbl_lms_online_attendance_sessions SET title=?,opens_at=?,closes_at=?,version=version+1
            WHERE session_id=?','sssi',[$window['title'],$window['opens_at'],$window['closes_at'],$id]);
        synk_grading_query_lms($lms,'DELETE FROM tbl_lms_online_attendance_roster WHERE session_id=?','i',[$id]);
        $action = 'rescheduled';
        $details = ['before'=>array_intersect_key($before,$window),'after'=>$window,'students'=>count($eligible)];
    } else {
        synk_grading_query_lms($lms,'INSERT INTO tbl_lms_online_attendance_sessions
            (attendance_period_id,title,opens_at,closes_at,request_key,request_hash,created_at) VALUES (?,?,?,?,?,?,?)',
            'issssss',[$periodId,$window['title'],$window['opens_at'],$window['closes_at'],$key,$hash,$now]);
        $id = (int)$lms->insert_id;
        $action = 'scheduled';
        $details = ['after'=>$window,'students'=>count($eligible)];
    }
    synk_online_attendance_add_roster_lms($lms,$id,$eligible);
    synk_online_attendance_log_lms($lms,$id,$actor,$action,$details,$now);
    return $id;
}

function synk_online_attendance_review_lms(mysqli $lms, int $periodId, array $input, int $actor, bool $cancel): int
{
    $id = synk_assessment_id_lms($input['session_id'] ?? null);
    $session = synk_online_attendance_session_lms($lms,$periodId,$id,true);
    $now = synk_online_attendance_now_lms();
    $state = synk_online_attendance_state_lms($session,$now);
    if (synk_grading_integer_lms($input['version'] ?? null) !== (int)$session['version'] || $state === 'cancelled') {
        throw new DomainException('This session changed. Refresh its attendance before saving.',409);
    }
    $reason = synk_assessment_text_lms($input['reason'] ?? '','Reason',500,true);
    if ($cancel) {
        synk_grading_query_lms($lms,'UPDATE tbl_lms_online_attendance_sessions SET cancelled_at=?,version=version+1 WHERE session_id=?','si',[$now,$id]);
        synk_online_attendance_log_lms($lms,$id,$actor,'cancelled',['reason'=>$reason,'previous_state'=>$state],$now);
        return $id;
    }
    if ($state !== 'closed') throw new DomainException('Correct attendance after the session closes.',422);
    $studentId = synk_assessment_id_lms($input['student_id'] ?? null);
    $new = $input['status'] ?? null;
    if (!in_array($new,['present','absent','automatic'],true)) throw new DomainException('Choose Present, Absent, or Use automatic result.',422);
    $student = synk_grading_query_lms($lms,'SELECT checked_in_at,override_status,override_reason
        FROM tbl_lms_online_attendance_roster WHERE session_id=? AND student_id=?','ii',[$id,$studentId])[0] ?? null;
    if (!$student) throw new DomainException('This student is not on the attendance roster for this session.',404);
    $status = $new === 'automatic' ? null : $new;
    if ($student['override_status'] === $status) return $id;
    synk_grading_query_lms($lms,'UPDATE tbl_lms_online_attendance_roster SET override_status=?,override_reason=? WHERE session_id=? AND student_id=?',
        'ssii',[$status,$status === null ? '' : $reason,$id,$studentId]);
    synk_grading_query_lms($lms,'UPDATE tbl_lms_online_attendance_sessions SET version=version+1 WHERE session_id=?','i',[$id]);
    synk_online_attendance_log_lms($lms,$id,$actor,'corrected',[
        'student_id'=>$studentId,'before'=>synk_online_attendance_result_lms($state,$student),
        'after'=>synk_online_attendance_result_lms($state,array_replace($student,['override_status'=>$status])),
        'override'=>$new,'reason'=>$reason,
    ],$now);
    return $id;
}

function synk_online_attendance_check_in_lms(mysqli $lms, array $context, string $period, int $sessionId, array $student): array
{
    $p = synk_online_attendance_period_lms($lms,$context,$period,true);
    if (!$p) throw new DomainException('This attendance session is unavailable.',404);
    $session = synk_online_attendance_session_lms($lms,(int)$p['attendance_period_id'],$sessionId,true);
    $studentId = (int)$student['student_id'];
    if (synk_class_student_status_ready_lms($lms)) {
        $status = synk_grading_query_lms($lms,'SELECT status FROM tbl_lms_class_student_statuses
            WHERE faculty_id=? AND offering_id=? AND ay_id=? AND semester=? AND student_id=? LOCK IN SHARE MODE',
            'iiiii',array_merge(synk_grading_scope_lms($context),[$studentId]))[0]['status'] ?? 'enrolled';
        if ($status !== 'enrolled') throw new DomainException('This attendance session is unavailable in your enrolled subjects.',404);
    }
    $now = synk_online_attendance_now_lms();
    $state = synk_online_attendance_state_lms($session,$now);
    $row = synk_grading_query_lms($lms,'SELECT checked_in_at,override_status FROM tbl_lms_online_attendance_roster
        WHERE session_id=? AND student_id=? FOR UPDATE','ii',[$sessionId,$studentId])[0] ?? null;
    if (empty($row['checked_in_at'])) {
        if ($state !== 'open') throw new DomainException('Check-in is available only during the displayed Philippine-time window.',409);
        // Newly enrolled students may check in without being counted absent
        // for previous sessions that never included them.
        if (!$row) synk_online_attendance_add_roster_lms($lms,$sessionId,[$student]);
        synk_grading_query_lms($lms,'UPDATE tbl_lms_online_attendance_roster SET checked_in_at=? WHERE session_id=? AND student_id=?',
            'sii',[$now,$sessionId,$studentId]);
        $row['checked_in_at'] = $now;
    }
    return ['session_id'=>$sessionId,'state'=>$state,'checked_in_at'=>synk_online_attendance_iso_lms($row['checked_in_at']),
        'result'=>synk_online_attendance_result_lms($state,$row),'server_time'=>synk_online_attendance_iso_lms($now)];
}

function synk_online_attendance_sessions_lms(mysqli $lms, int $periodId): array
{
    if (!$periodId) return [];
    return synk_grading_query_lms($lms,'SELECT session_id,title,opens_at,closes_at,cancelled_at,version
        FROM tbl_lms_online_attendance_sessions WHERE attendance_period_id=? ORDER BY opens_at DESC,session_id DESC','i',[$periodId]);
}

function synk_online_attendance_totals_lms(mysqli $lms, int $periodId, array $sessions, array $students, string $now): array
{
    $closed = [];
    foreach ($sessions as $session) {
        if (synk_online_attendance_state_lms($session,$now) === 'closed') $closed[(int)$session['session_id']] = (int)$session['version'];
    }
    $counts = $closed ? synk_grading_query_lms($lms,"SELECT r.student_id,COUNT(*) AS eligible,
        SUM(CASE WHEN r.override_status='present' OR (r.override_status IS NULL AND r.checked_in_at IS NOT NULL)
            THEN 1 ELSE 0 END) AS present
        FROM tbl_lms_online_attendance_roster r JOIN tbl_lms_online_attendance_sessions s ON s.session_id=r.session_id
        WHERE s.attendance_period_id=? AND s.cancelled_at IS NULL AND s.closes_at<=? GROUP BY r.student_id",
        'is',[$periodId,$now]) : [];
    $counts = array_column($counts,null,'student_id');
    $rows = [];
    $graded = [];
    foreach ($students as $student) {
        $id = (int)$student['student_id'];
        $eligible = (int)($counts[$id]['eligible'] ?? 0);
        $present = (int)($counts[$id]['present'] ?? 0);
        $score = $eligible ? round($present * 100 / $eligible,2) : null;
        $rows[] = array_intersect_key($student,array_flip(['student_id','student_number','full_name','class_status']))+
            ['present'=>$present,'absent'=>$eligible-$present,'eligible'=>$eligible,'score'=>$score];
        if ($eligible) $graded[$id] = [$present,$eligible];
    }
    ksort($closed,SORT_NUMERIC);
    ksort($graded,SORT_NUMERIC);
    return ['closed_count'=>count($closed),'rows'=>$rows,'scored_count'=>count($graded),
        'digest'=>hash('sha256',json_encode([$closed,$graded]))];
}
