<?php
require_once __DIR__.'/online_attendance_grade_lms.php';

function synk_online_attendance_students_lms(mysqli $core, mysqli $lms, array $context): array
{
    $roster = synk_class_students_roster_lms($core,$lms,$context)['students'];
    return array_values(array_filter($roster,static function (array $student): bool {
        return $student['class_status'] === 'enrolled';
    }));
}

function synk_online_attendance_workspace_lms(mysqli $lms, array $context, string $period, ?array $attendance, array $students, string $now): array
{
    $id = (int)($attendance['attendance_period_id'] ?? 0);
    $sessions = synk_online_attendance_sessions_lms($lms,$id);
    $totals = synk_online_attendance_totals_lms($lms,$id,$sessions,$students,$now);
    $book = synk_gradebook_period_lms($lms,$context,$period);
    $item = synk_online_attendance_grade_item_lms($lms,$attendance,$book);
    $applied = $attendance['applied_digest'] ?? null;
    $gradeState = 'unassigned';
    if ($applied !== null) {
        if ($applied !== $totals['digest']) $gradeState = 'changes';
        elseif ($item['status'] === 'active') $gradeState = 'applied';
        else $gradeState = $totals['scored_count'] > 0 ? 'archived' : 'cleared';
    }
    return [
        'server_time'=>synk_online_attendance_iso_lms($now),'grading_period'=>$period,
        'sessions'=>array_map(static function (array $row) use ($now): array {
            return synk_online_attendance_public_session_lms($row,$now);
        },$sessions),
        'totals'=>$totals,
        'gradebook'=>[
            'components'=>$book['scheme']['components'],'version'=>$book['version'],'scheme_version'=>$book['scheme']['version'],
            'scheme_changed'=>$book['scheme_changed'],'component_id'=>$item ? (int)$item['component_id'] : null,
            'state'=>$gradeState,'applied_at'=>synk_online_attendance_iso_lms($attendance['applied_at'] ?? null),
            'can_apply'=>$totals['scored_count'] > 0 || ($item && $item['status'] === 'active'),
        ],
    ];
}

function synk_online_attendance_professor_request_lms(mysqli $core, array $input): array
{
    if (($_SESSION['role'] ?? '') !== 'professor' || empty($_SESSION['user_id'])) throw new DomainException('Sign in as a professor to continue.',401);
    if (empty($_SESSION['lms_online_attendance_csrf']) || !is_string($input['csrf_token'] ?? null)
        || !hash_equals($_SESSION['lms_online_attendance_csrf'],$input['csrf_token'])) {
        throw new DomainException('Reload Online Attendance before continuing.',403);
    }
    if (array_diff(array_keys($input),['action','context','payload','csrf_token'])
        || !is_array($input['context'] ?? null) || ($input['context']['view'] ?? '') !== 'attendance') {
        throw new DomainException('Open Online Attendance from your class workspace.',403);
    }
    $fields = [
        'get'=>[],
        'detail'=>['session_id'],
        'schedule'=>['title','opens_at','closes_at','request_key'],
        'edit'=>['session_id','version','title','opens_at','closes_at'],
        'correct'=>['session_id','version','student_id','status','reason'],
        'cancel'=>['session_id','version','reason'],
        'apply'=>['component_id','version','scheme_version','attendance_digest'],
    ];
    $action = $input['action'] ?? null;
    $payload = $input['payload'] ?? null;
    if (!is_string($action) || !isset($fields[$action]) || !is_array($payload)
        || array_diff(array_keys($payload),array_merge(['grading_period'],$fields[$action]))) {
        throw new DomainException('Choose an available attendance action.',400);
    }
    $period = synk_grading_period_lms($payload['grading_period'] ?? null);
    $context = synk_professor_class_context_lms($core,$input['context']);
    $lms = synk_database_connection_lms($core);
    if (!synk_online_attendance_ready_lms($lms) || !synk_gradebook_ready_lms($lms)) {
        throw new DomainException('Online Attendance storage is unavailable. Ask your administrator to install the LMS update.',503);
    }
    $write = !in_array($action,['get','detail'],true);
    if (!$lms->begin_transaction($write ? 0 : MYSQLI_TRANS_START_READ_ONLY)) throw new RuntimeException('Unable to open the attendance request.');
    try {
        $attendance = synk_online_attendance_period_lms($lms,$context,$period,$write,$action === 'schedule');
        $id = (int)($attendance['attendance_period_id'] ?? 0);
        $actor = (int)$_SESSION['user_id'];
        $result = [];
        if ($action === 'detail') {
            $result = synk_online_attendance_detail_lms($lms,$id,synk_assessment_id_lms($payload['session_id'] ?? null),synk_online_attendance_now_lms());
        } elseif ($action === 'schedule' || $action === 'edit') {
            $students = synk_online_attendance_students_lms($core,$lms,$context);
            $result['saved_session_id'] = synk_online_attendance_schedule_lms($lms,$id,$payload,$students,$actor,$action === 'edit');
        } elseif ($action === 'correct' || $action === 'cancel') {
            $result['saved_session_id'] = synk_online_attendance_review_lms($lms,$id,$payload,$actor,$action === 'cancel');
        } elseif ($action === 'apply') {
            if (!$attendance) throw new DomainException('Close an attendance session before applying scores.',422);
            // Lock the grade scheme and book before the first consistent read
            // of the grading components and attendance totals.
            $book = synk_gradebook_period_lms($lms,$context,$period,true);
            $students = synk_online_attendance_students_lms($core,$lms,$context);
            $now = synk_online_attendance_now_lms();
            $totals = synk_online_attendance_totals_lms($lms,$id,synk_online_attendance_sessions_lms($lms,$id),$students,$now);
            synk_online_attendance_apply_lms($lms,$context,$attendance,$book,$totals,$payload,$actor);
        }
        if ($action !== 'detail') {
            if ($action === 'apply') $attendance = synk_online_attendance_period_lms($lms,$context,$period);
            $students = $students ?? synk_online_attendance_students_lms($core,$lms,$context);
            $result += synk_online_attendance_workspace_lms($lms,$context,$period,$attendance,$students,synk_online_attendance_now_lms());
        }
        if (!$lms->commit()) throw new RuntimeException('Unable to complete the attendance request.');
        return $result;
    } catch (Throwable $error) {
        $lms->rollback();
        if ($error instanceof mysqli_sql_exception && in_array((int)$error->getCode(),[1062,1205,1213],true)) {
            throw new DomainException('Attendance is being updated in another request. Refresh and try again.',409);
        }
        throw $error;
    }
}
