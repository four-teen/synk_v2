<?php
require_once __DIR__.'/assessment_helper_lms.php';
require_once __DIR__.'/professor_workload_students_helper.php';

function synk_assessment_delivery_ready_lms(mysqli $lms): bool
{
    return synk_assessment_ready_lms($lms) && synk_assessment_snapshots_ready_lms($lms) && (bool)synk_assessment_query_lms($lms,
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tbl_lms_assessment_attempts'");
}
function synk_assessment_now_lms(): string { return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s'); }

// Read only: use the same deduplicated class membership as student delivery,
// including merged classes and legacy enrollment matches. Attempts stay in LMS.
function synk_assessment_list_with_participation_lms(mysqli $core,mysqli $lms,array $context): array
{
    $rows=synk_assessment_list_lms($lms,$context);
    if (!$rows) return [];
    $class=$context['class']; $offering=(int)$class['offering_id'];
    $memberships=synk_professor_workload_student_memberships($core,[$class],(int)$class['ay_id'],(int)$class['semester']);
    $students=$memberships[$offering] ?? [];
    $ready=synk_assessment_delivery_ready_lms($lms); $answered=[];
    if ($ready && $students) {
        $attempts=synk_assessment_query_lms($lms,"SELECT DISTINCT t.assessment_id,t.student_id
            FROM tbl_lms_assessment_attempts t JOIN tbl_lms_assessments a ON a.assessment_id=t.assessment_id
            WHERE a.faculty_id=? AND a.offering_id=? AND a.ay_id=? AND a.semester=?
              AND a.status IN ('published','closed') AND t.status='submitted'",'iiii',synk_assessment_scope_params_lms($context));
        foreach ($attempts as $attempt) {
            if (isset($students[(int)$attempt['student_id']])) {
                $id=(int)$attempt['assessment_id'];
                $answered[$id]=($answered[$id] ?? 0)+1;
            }
        }
    }
    foreach ($rows as &$row) {
        $row['student_count']=count($students);
        $row['answered_count']=$ready ? ($answered[$row['assessment_id']] ?? 0) : null;
    }
    unset($row);
    return $rows;
}

function synk_assessment_publish_lms(mysqli $lms,array $context,array $input,int $actor): array
{
    if (!synk_assessment_delivery_ready_lms($lms)) throw new DomainException('Assessment delivery storage needs to be prepared before publishing.',503);
    $valid=synk_assessment_validate_lms($input);
    synk_assessment_validate_published_lms($valid);
    if ($valid['due_at']!==null && $valid['due_at']<=synk_assessment_now_lms()) throw new DomainException('Choose a future deadline before publishing.',422);
    $id=synk_assessment_id_lms($input['assessment_id']??null,true);
    $before=$id?synk_assessment_detail_lms($lms,$context,$id,true):null;
    if ($before && !in_array($before['status'],['draft','closed'],true)) throw new DomainException('Use Save changes to edit this published assessment.',409);
    $record=synk_assessment_save_lms($lms,$context,$input,$actor);
    synk_assessment_query_lms($lms,"UPDATE tbl_lms_assessments SET status='published' WHERE assessment_id=?",'i',[$record['assessment_id']]);
    synk_assessment_log_lms($lms,$record['assessment_id'],$actor,$before && $before['status']==='closed'?'reopened':'published',['version'=>$record['version']]);
    return synk_assessment_detail_lms($lms,$context,$record['assessment_id']);
}
function synk_assessment_close_lms(mysqli $lms,array $context,array $input,int $actor): array
{
    $record=synk_assessment_detail_lms($lms,$context,synk_assessment_id_lms($input['assessment_id']??null),true);
    if ($record['status']!=='published' || synk_assessment_id_lms($input['version']??null)!==$record['version']) throw new DomainException('This assessment changed. Refresh the list before closing it.',409);
    $now=synk_assessment_now_lms();
    synk_assessment_query_lms($lms,"UPDATE tbl_lms_assessments SET status='closed',version=version+1,updated_at=? WHERE assessment_id=?",'si',[$now,$record['assessment_id']]);
    synk_assessment_query_lms($lms,"UPDATE tbl_lms_assessment_attempts SET status='expired',submitted_at=?,updated_at=?,version=version+1 WHERE assessment_id=? AND status='in_progress'",'ssi',[$now,$now,$record['assessment_id']]);
    synk_assessment_log_lms($lms,$record['assessment_id'],$actor,'closed',['version'=>$record['version']+1]);
    return synk_assessment_detail_lms($lms,$context,$record['assessment_id']);
}
function synk_assessment_submissions_lms(mysqli $core,mysqli $lms,array $context,int $id): array
{
    $record=synk_assessment_detail_lms($lms,$context,$id,true);
    $rows=synk_assessment_query_lms($lms,'SELECT attempt_id,student_id,status,answers_json,assessment_snapshot_json,started_at,deadline_at,submitted_at FROM tbl_lms_assessment_attempts WHERE assessment_id=? ORDER BY started_at,attempt_id','i',[$id]);
    foreach ($rows as &$row) {
        $row['attempt_id']=(int)$row['attempt_id'];$row['student_id']=(int)$row['student_id'];
        $names=synk_assessment_query_lms($core,'SELECT student_number,first_name,middle_name,last_name,suffix_name FROM tbl_student_management WHERE student_id=?','i',[$row['student_id']]);
        $name=$names[0]??[];$row['student_number']=$name['student_number']??'';
        $row['student_name']=trim(implode(' ',array_filter([$name['first_name']??'',$name['middle_name']??'',$name['last_name']??'',$name['suffix_name']??''])));
        $row['assessment']=synk_assessment_attempt_record_lms($record,$row);
        $row['answers']=json_decode($row['answers_json'],true)?:[];unset($row['answers_json'],$row['assessment_snapshot_json']);
        if ($row['status']==='in_progress' && $row['deadline_at']!==null && $row['deadline_at']<=synk_assessment_now_lms()) $row['status']='expired';
    }unset($row);
    return $rows;
}
