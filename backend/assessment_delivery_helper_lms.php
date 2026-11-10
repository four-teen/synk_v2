<?php
require_once __DIR__.'/assessment_helper_lms.php';

function synk_assessment_delivery_ready_lms(mysqli $lms): bool
{
    return synk_assessment_ready_lms($lms) && (bool)synk_assessment_query_lms($lms,
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tbl_lms_assessment_attempts'");
}
function synk_assessment_now_lms(): string { return (new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s'); }

function synk_assessment_publish_lms(mysqli $lms,array $context,array $input,int $actor): array
{
    if (!synk_assessment_delivery_ready_lms($lms)) throw new DomainException('Assessment delivery storage needs to be prepared before publishing.',503);
    $valid=synk_assessment_validate_lms($input);
    if ($valid['assessment_type']!=='assignment' && !$valid['questions']) throw new DomainException('Add at least one complete question before publishing.',422);
    if ($valid['assessment_type']==='assignment' && $valid['instructions']==='') throw new DomainException('Add assignment instructions before publishing.',422);
    if ($valid['due_at']!==null && $valid['due_at']<=synk_assessment_now_lms()) throw new DomainException('Choose a future deadline before publishing.',422);
    $record=synk_assessment_save_lms($lms,$context,$input,$actor);
    synk_assessment_query_lms($lms,"UPDATE tbl_lms_assessments SET status='published' WHERE assessment_id=?",'i',[$record['assessment_id']]);
    synk_assessment_log_lms($lms,$record['assessment_id'],$actor,'published',['version'=>$record['version']]);
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
    synk_assessment_detail_lms($lms,$context,$id);
    $rows=synk_assessment_query_lms($lms,'SELECT attempt_id,student_id,status,answers_json,started_at,deadline_at,submitted_at FROM tbl_lms_assessment_attempts WHERE assessment_id=? ORDER BY started_at,attempt_id','i',[$id]);
    foreach ($rows as &$row) {
        $row['attempt_id']=(int)$row['attempt_id'];$row['student_id']=(int)$row['student_id'];
        $names=synk_assessment_query_lms($core,'SELECT student_number,first_name,middle_name,last_name,suffix_name FROM tbl_student_management WHERE student_id=?','i',[$row['student_id']]);
        $name=$names[0]??[];$row['student_number']=$name['student_number']??'';
        $row['student_name']=trim(implode(' ',array_filter([$name['first_name']??'',$name['middle_name']??'',$name['last_name']??'',$name['suffix_name']??''])));
        $row['answers']=json_decode($row['answers_json'],true)?:[];unset($row['answers_json']);
        if ($row['status']==='in_progress' && $row['deadline_at']!==null && $row['deadline_at']<=synk_assessment_now_lms()) $row['status']='expired';
    }unset($row);
    return $rows;
}
