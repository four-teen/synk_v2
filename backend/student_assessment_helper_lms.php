<?php
require_once __DIR__.'/db_lms.php';
require_once __DIR__.'/assessment_delivery_helper_lms.php';
require_once __DIR__.'/student_portal_helper.php';
require_once __DIR__.'/professor_workload_students_helper.php';

function synk_student_assessment_identity_lms(mysqli $core): int
{
    if (($_SESSION['role']??'')!=='student' || empty($_SESSION['user_id']) || !synk_student_login_session_record($core)) throw new DomainException('Sign in again with your student account.',401);
    synk_student_presence_check($core,true);
    return (int)$_SESSION['student_id'];
}
function synk_student_assessment_class_lms(mysqli $core,array $record,int $studentId): array
{
    $term=synk_fetch_current_academic_term($core);
    if ((int)$record['ay_id']!==(int)$term['ay_id'] || (int)$record['semester']!==(int)$term['semester']) throw new DomainException('This assessment is unavailable in your current term.',404);
    $faculty=synk_assessment_query_lms($core,"SELECT faculty_id FROM tbl_faculty WHERE faculty_id=? AND status='active'",'i',[(int)$record['faculty_id']]);
    if (!$faculty) throw new DomainException('This assessment is unavailable.',404);
    $classes=synk_professor_fetch_subject_rows_by_academic_year($core,(int)$record['faculty_id'],(int)$record['ay_id'],(int)$record['semester']);
    foreach ($classes as $class) {
        if ((int)$class['offering_id']!==(int)$record['offering_id']) continue;
        $members=synk_professor_workload_student_memberships($core,[$class],(int)$record['ay_id'],(int)$record['semester']);
        if (isset($members[(int)$record['offering_id']][$studentId])) return $class;
    }
    throw new DomainException('This assessment is unavailable in your enrolled subjects.',404);
}
function synk_student_assessment_record_lms(mysqli $core,mysqli $lms,int $id,int $studentId): array
{
    $rows=synk_assessment_query_lms($lms,"SELECT * FROM tbl_lms_assessments WHERE assessment_id=? AND status IN ('published','closed') FOR UPDATE",'i',[$id]);
    if (!$rows) throw new DomainException('This assessment is unavailable.',404);
    $record=$rows[0];$record['assessment_id']=(int)$record['assessment_id'];
    $class=synk_student_assessment_class_lms($core,$record,$studentId);
    $record['subject_label']=$class['subject_code'].': '.$class['descriptive_title'];
    return $record;
}
function synk_student_attempt_lms(mysqli $lms,int $assessmentId,int $studentId): ?array
{
    $rows=synk_assessment_query_lms($lms,'SELECT * FROM tbl_lms_assessment_attempts WHERE assessment_id=? AND student_id=? FOR UPDATE','ii',[$assessmentId,$studentId]);
    if (!$rows) return null;
    $row=$rows[0];
    foreach (['attempt_id','assessment_id','student_id','version'] as $key) $row[$key]=(int)$row[$key];
    if ($row['status']==='in_progress' && $row['deadline_at']!==null && $row['deadline_at']<=synk_assessment_now_lms()) {
        $now=synk_assessment_now_lms();
        synk_assessment_query_lms($lms,"UPDATE tbl_lms_assessment_attempts SET status='expired',submitted_at=?,updated_at=?,version=version+1 WHERE attempt_id=?",'ssi',[$row['deadline_at'],$now,$row['attempt_id']]);
        $row['status']='expired';$row['submitted_at']=$row['deadline_at'];$row['version']++;
    }
    $row['answers']=json_decode($row['answers_json'],true)?:[];unset($row['answers_json']);
    return $row;
}
function synk_student_assessment_public_lms(mysqli $lms,array $record,?array $attempt): array
{
    // Explicit allowlist. Answer keys and professor-only fields never enter a student response.
    $public=array_intersect_key($record,array_flip(['assessment_id','grading_period','assessment_type','title','instructions','topic','status','total_points','opens_at','due_at','time_limit_minutes','subject_label']));
    $public['total_points']=(float)$public['total_points'];$public['time_limit_minutes']=$public['time_limit_minutes']===null?null:(int)$public['time_limit_minutes'];
    $public['questions']=[];
    if ($attempt) {
        $questions=synk_assessment_query_lms($lms,'SELECT question_id,question_type,prompt,points,options_json FROM tbl_lms_assessment_questions WHERE assessment_id=? ORDER BY sort_order,question_id','i',[$record['assessment_id']]);
        foreach ($questions as $q) $public['questions'][]=['question_id'=>(int)$q['question_id'],'question_type'=>$q['question_type'],'prompt'=>$q['prompt'],'points'=>(float)$q['points'],'options'=>json_decode($q['options_json'],true)?:[]];
    }
    if ($attempt) unset($attempt['student_id'],$attempt['assessment_id']);
    return ['assessment'=>$public,'attempt'=>$attempt,'server_time'=>synk_assessment_now_lms()];
}
function synk_student_assessment_answers_lms(array $record,array $questions,$input): array
{
    if (!is_array($input) || count($input)>101) throw new DomainException('Enter valid assessment responses.',422);
    $answers=[];$questionMap=array_column($questions,null,'question_id');
    foreach ($input as $key=>$value) {
        if ($record['assessment_type']==='assignment' && $key==='assignment_response') { $answers[$key]=synk_assessment_text_lms($value,'Assignment response',20000);continue; }
        if (!isset($questionMap[$key])) throw new DomainException('An answer refers to a question outside this assessment.',422);
        $q=$questionMap[$key];
        if ($value===null || $value==='') { $answers[$key]=null;continue; }
        if ($q['question_type']==='multiple_choice') {
            if (!is_int($value) || $value<0 || $value>=count($q['options'])) throw new DomainException('Choose a valid answer option.',422);
        } elseif ($q['question_type']==='true_false') {
            if (!is_bool($value)) throw new DomainException('Choose True or False.',422);
        } else $value=synk_assessment_text_lms($value,'Response',$q['question_type']==='short_answer'?500:10000);
        $answers[$key]=$value;
    }
    return $answers;
}
function synk_student_assessment_list_lms(mysqli $core,mysqli $lms,int $studentId): array
{
    $term=synk_fetch_current_academic_term($core);
    $rows=synk_assessment_query_lms($lms,"SELECT assessment_id,faculty_id,offering_id,ay_id,semester,grading_period,assessment_type,title,status,total_points,opens_at,due_at,time_limit_minutes
        FROM tbl_lms_assessments WHERE ay_id=? AND semester=? AND status IN ('published','closed') ORDER BY assessment_id DESC",'ii',[(int)$term['ay_id'],(int)$term['semester']]);
    $result=[];$classes=[];$members=[];
    foreach ($rows as $row) {
        $facultyId=(int)$row['faculty_id'];
        if (!isset($classes[$facultyId])) {
            $active=synk_assessment_query_lms($core,"SELECT faculty_id FROM tbl_faculty WHERE faculty_id=? AND status='active'",'i',[$facultyId]);
            $classes[$facultyId]=$active?synk_professor_fetch_subject_rows_by_academic_year($core,$facultyId,(int)$term['ay_id'],(int)$term['semester']):[];
            $members[$facultyId]=synk_professor_workload_student_memberships($core,$classes[$facultyId],(int)$term['ay_id'],(int)$term['semester']);
            $classes[$facultyId]=array_column($classes[$facultyId],null,'offering_id');
        }
        $offering=(int)$row['offering_id'];
        if (!isset($members[$facultyId][$offering][$studentId],$classes[$facultyId][$offering])) continue;
        $class=$classes[$facultyId][$offering];
        $item=array_intersect_key($row,array_flip(['assessment_id','grading_period','assessment_type','title','status','total_points','opens_at','due_at','time_limit_minutes']));
        $item['assessment_id']=(int)$item['assessment_id'];$item['subject_label']=$class['subject_code'].': '.$class['descriptive_title'];
        $attempt=synk_assessment_query_lms($lms,'SELECT status,deadline_at FROM tbl_lms_assessment_attempts WHERE assessment_id=? AND student_id=?','ii',[$item['assessment_id'],$studentId]);
        $item['attempt_status']=$attempt?$attempt[0]['status']:null;
        if ($item['attempt_status']==='in_progress' && $attempt[0]['deadline_at']!==null && $attempt[0]['deadline_at']<=synk_assessment_now_lms()) $item['attempt_status']='expired';
        $result[]=$item;
    }
    return $result;
}
function synk_student_assessment_action_lms(mysqli $core,mysqli $lms,int $studentId,string $action,array $payload): array
{
    $id=synk_assessment_id_lms($payload['assessment_id']??null);
    $record=synk_student_assessment_record_lms($core,$lms,$id,$studentId);
    $attempt=synk_student_attempt_lms($lms,$id,$studentId);$now=synk_assessment_now_lms();
    if ($action==='start' && !$attempt) {
        if ($record['status']!=='published') throw new DomainException('This assessment is closed.',409);
        if ($record['opens_at']!==null && $record['opens_at']>$now) throw new DomainException('This assessment has not opened yet.',409);
        if ($record['due_at']!==null && $record['due_at']<=$now) throw new DomainException('The assessment deadline has passed.',409);
        $deadline=$record['due_at'];
        if ($record['time_limit_minutes']!==null) {
            $timer=(new DateTimeImmutable($now,new DateTimeZone('Asia/Manila')))->modify('+'.(int)$record['time_limit_minutes'].' minutes')->format('Y-m-d H:i:s');
            if ($deadline===null || $timer<$deadline) $deadline=$timer;
        }
        synk_assessment_query_lms($lms,"INSERT INTO tbl_lms_assessment_attempts (assessment_id,student_id,status,answers_json,started_at,deadline_at,updated_at) VALUES (?,?,'in_progress','{}',?,?,?)",'iisss',[$id,$studentId,$now,$deadline,$now]);
        $attempt=synk_student_attempt_lms($lms,$id,$studentId);
    }
    if (in_array($action,['save','submit'],true)) {
        if (!$attempt) throw new DomainException('Start this assessment before saving responses.',409);
        if ($attempt['status']!=='in_progress') return synk_student_assessment_public_lms($lms,$record,$attempt);
        if ($record['status']!=='published') throw new DomainException('This assessment is closed.',409);
        if (synk_assessment_id_lms($payload['version']??null)!==$attempt['version']) throw new DomainException('Your responses changed in another tab. Reload the assessment to continue.',409);
        $public=synk_student_assessment_public_lms($lms,$record,$attempt);
        $answers=synk_student_assessment_answers_lms($record,$public['assessment']['questions'],$payload['answers']??null);
        if ($action==='submit' && $record['assessment_type']==='assignment' && trim((string)($answers['assignment_response']??''))==='') throw new DomainException('Enter your assignment response before submitting.',422);
        $status=$action==='submit'?'submitted':'in_progress';$submitted=$action==='submit'?$now:null;
        synk_assessment_query_lms($lms,'UPDATE tbl_lms_assessment_attempts SET answers_json=?,status=?,submitted_at=?,updated_at=?,version=version+1 WHERE attempt_id=?','ssssi',[json_encode($answers,JSON_UNESCAPED_UNICODE),$status,$submitted,$now,$attempt['attempt_id']]);
        $attempt=synk_student_attempt_lms($lms,$id,$studentId);
    }
    return synk_student_assessment_public_lms($lms,$record,$attempt);
}
function synk_student_assessment_request_lms(mysqli $core,array $input): array
{
    $studentId=synk_student_assessment_identity_lms($core);
    if (empty($_SESSION['student_assessment_csrf']) || !is_string($input['csrf_token']??null) || !hash_equals($_SESSION['student_assessment_csrf'],$input['csrf_token'])) throw new DomainException('Reload your assessments page before continuing.',403);
    $action=$input['action']??null;
    if (!is_string($action) || !in_array($action,['list','detail','start','save','submit'],true)) throw new DomainException('Choose a valid assessment action.',400);
    $payload=$input['payload']??[];if (!is_array($payload)) throw new DomainException('Enter valid assessment details.',400);
    $lms=synk_database_connection_lms($core);
    if (!synk_assessment_delivery_ready_lms($lms)) throw new DomainException('Your assessments workspace is not ready yet.',503);
    if (!$lms->begin_transaction()) throw new RuntimeException('Unable to begin the assessment request.');
    try {
        $data=$action==='list'?['assessments'=>synk_student_assessment_list_lms($core,$lms,$studentId),'server_time'=>synk_assessment_now_lms()]:synk_student_assessment_action_lms($core,$lms,$studentId,$action,$payload);
        if (!$lms->commit()) throw new RuntimeException('Unable to save assessment responses.');
        return $data;
    } catch (Throwable $error) { $lms->rollback();throw $error; }
}
