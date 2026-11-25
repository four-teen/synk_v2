<?php
require_once __DIR__.'/online_attendance_helper_lms.php';
require_once __DIR__.'/student_gradebook_helper_lms.php';
require_once __DIR__.'/db_lms.php';

function synk_online_attendance_student_list_lms(mysqli $lms, array $term, array $classes, int $studentId, string $now, bool $cards = false): array
{
    if (!$classes) return [];
    $subjects = [];
    $pairs = [];
    foreach ($classes as $key=>$class) {
        [$faculty,$offering] = synk_grading_scope_lms($class['context']);
        array_push($pairs,$faculty,$offering);
        $subjects[$key] = [
            'subject_key'=>$class['subject']['subject_key'],'subject_code'=>$class['subject']['subject_code'],
            'title'=>$class['subject']['descriptive_title'],'instructor'=>$class['subject']['faculty_name'],
            'section'=>$class['context']['class']['section_display'],'sessions'=>[],
        ];
    }
    $slots = implode(',',array_fill(0,count($classes),'(?,?)'));
    $type = 'iii'.str_repeat('i',count($pairs));
    $args = array_merge([$studentId,(int)$term['ay_id'],(int)$term['semester']],$pairs);
    if (synk_class_student_status_ready_lms($lms)) {
        $inactive = synk_grading_query_lms($lms,"SELECT faculty_id,offering_id FROM tbl_lms_class_student_statuses
            WHERE student_id=? AND ay_id=? AND semester=? AND (faculty_id,offering_id) IN ($slots)
            AND status IN ('dropped','excluded')",$type,$args);
        foreach ($inactive as $row) unset($subjects[$row['faculty_id'].':'.$row['offering_id']]);
    }
    if (!$subjects) return [];
    // The live cards need every open window, the next opening and the most
    // recent completed result for this student. Keep full history on its page.
    $filter = '';
    if ($cards) {
        $filter = " AND s.cancelled_at IS NULL AND (
            (s.opens_at<=? AND s.closes_at>?)
            OR s.session_id=(SELECT upcoming.session_id FROM tbl_lms_online_attendance_sessions upcoming
                WHERE upcoming.attendance_period_id=p.attendance_period_id AND upcoming.cancelled_at IS NULL
                    AND upcoming.opens_at>? ORDER BY upcoming.opens_at,upcoming.session_id LIMIT 1)
            OR s.session_id=(SELECT past.session_id FROM tbl_lms_online_attendance_sessions past
                JOIN tbl_lms_online_attendance_roster eligible ON eligible.session_id=past.session_id AND eligible.student_id=?
                WHERE past.attendance_period_id=p.attendance_period_id AND past.cancelled_at IS NULL
                    AND past.closes_at<=? ORDER BY past.closes_at DESC,past.session_id DESC LIMIT 1))";
        $type .= 'sssis';
        array_push($args,$now,$now,$now,$studentId,$now);
    }
    $rows = synk_grading_query_lms($lms,"SELECT s.session_id,s.title,s.opens_at,s.closes_at,s.cancelled_at,s.version,
        p.faculty_id,p.offering_id,p.grading_period,r.student_id,r.checked_in_at,r.override_status
        FROM tbl_lms_online_attendance_periods p JOIN tbl_lms_online_attendance_sessions s USING (attendance_period_id)
        LEFT JOIN tbl_lms_online_attendance_roster r ON r.session_id=s.session_id AND r.student_id=?
        WHERE p.ay_id=? AND p.semester=? AND (p.faculty_id,p.offering_id) IN ($slots)".$filter.
        ' ORDER BY s.opens_at DESC,s.session_id DESC',$type,$args);
    foreach ($rows as $row) {
        $key = $row['faculty_id'].':'.$row['offering_id'];
        if (!isset($subjects[$key])) continue;
        $session = synk_online_attendance_public_session_lms($row,$now);
        unset($session['version']);
        $subjects[$key]['sessions'][] = $session+[
            'grading_period'=>$row['grading_period'],'checked_in_at'=>synk_online_attendance_iso_lms($row['checked_in_at']),
            'result'=>synk_online_attendance_result_lms($session['state'],$row['student_id'] === null ? null : $row),
        ];
    }
    return array_values($subjects);
}

function synk_online_attendance_student_request_lms(mysqli $core, array $input): array
{
    if (($_SESSION['role'] ?? '') !== 'student' || empty($_SESSION['user_id']) || !synk_student_login_session_record($core)) {
        throw new DomainException('Sign in with your student account to open attendance.',401);
    }
    $studentId = (int)$_SESSION['student_id'];
    $action = $input['action'] ?? null;
    if (!in_array($action,['list','cards','check_in'],true) || array_diff(array_keys($input),$action === 'check_in'
        ? ['action','csrf_token','session_id'] : ['action','csrf_token'])) {
        throw new DomainException('Choose an available attendance action.',400);
    }
    if (empty($_SESSION['student_online_attendance_csrf']) || !is_string($input['csrf_token'] ?? null)
        || !hash_equals($_SESSION['student_online_attendance_csrf'],$input['csrf_token'])) {
        throw new DomainException('Reload your attendance page before continuing.',403);
    }
    try { synk_student_presence_check($core,$action === 'check_in'); }
    catch (DomainException $error) { throw new DomainException('Your student session expired. Sign in again to open attendance.',401,$error); }
    if ($action === 'cards' && session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $lms = synk_database_connection_lms($core);
    if (!synk_online_attendance_ready_lms($lms)) throw new DomainException('Online Attendance is temporarily unavailable.',503);
    $term = $offerings = null;
    if ($action === 'cards') {
        $term = synk_fetch_current_academic_term($core);
        $offerings = [];
        // Limit background roster lookups to classes with attendance sessions.
        $scopes = synk_grading_query_lms($lms,'SELECT p.faculty_id,p.offering_id FROM tbl_lms_online_attendance_periods p
            WHERE p.ay_id=? AND p.semester=? AND EXISTS (SELECT 1 FROM tbl_lms_online_attendance_sessions s
                WHERE s.attendance_period_id=p.attendance_period_id AND s.cancelled_at IS NULL)',
            'ii',[(int)$term['ay_id'],(int)$term['semester']]);
        foreach ($scopes as $scope) $offerings[(int)$scope['faculty_id']][(int)$scope['offering_id']] = true;
    }
    $resolved = synk_student_gradebook_classes_lms($core,$studentId,$term,$offerings);
    $classes = [];
    foreach ($resolved['classes'] as $class) {
        if (!$class['context']) continue;
        [$faculty,$offering] = synk_grading_scope_lms($class['context']);
        $classes[$faculty.':'.$offering] = $class;
    }
    if ($action === 'list' || $action === 'cards') {
        $clock = new DateTimeImmutable('now',new DateTimeZone('UTC'));
        return ['server_time'=>$clock->format('Y-m-d\TH:i:s.v\Z'),
            'subjects'=>synk_online_attendance_student_list_lms($lms,$resolved['term'],$classes,$studentId,$clock->format('Y-m-d H:i:s'),$action === 'cards')];
    }

    $sessionId = synk_assessment_id_lms($input['session_id'] ?? null);
    $owner = synk_grading_query_lms($lms,'SELECT p.faculty_id,p.offering_id,p.grading_period
        FROM tbl_lms_online_attendance_sessions s JOIN tbl_lms_online_attendance_periods p USING (attendance_period_id)
        WHERE s.session_id=? AND p.ay_id=? AND p.semester=?','iii',
        [$sessionId,(int)$resolved['term']['ay_id'],(int)$resolved['term']['semester']])[0] ?? null;
    $context = $owner ? ($classes[$owner['faculty_id'].':'.$owner['offering_id']]['context'] ?? null) : null;
    if (!$context) throw new DomainException('This attendance session is unavailable in your enrolled subjects.',404);
    $student = synk_student_fetch_directory_record_by_student_id($core,$studentId);
    if (!$student) throw new DomainException('Your student record is unavailable.',404);
    $student['full_name'] = synk_student_directory_display_name($student);
    if (!$lms->begin_transaction()) throw new RuntimeException('Unable to open the attendance request.');
    try {
        $result = synk_online_attendance_check_in_lms($lms,$context,$owner['grading_period'],$sessionId,$student);
        if (!$lms->commit()) throw new RuntimeException('Unable to complete your check-in.');
        return $result;
    } catch (Throwable $error) {
        $lms->rollback();
        if ($error instanceof mysqli_sql_exception && in_array((int)$error->getCode(),[1062,1205,1213],true)) {
            throw new DomainException('Attendance is being updated. Please try checking in again.',409);
        }
        throw $error;
    }
}
