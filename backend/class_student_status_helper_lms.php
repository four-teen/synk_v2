<?php
require_once __DIR__.'/assessment_helper_lms.php';
require_once __DIR__.'/professor_workload_students_helper.php';

function synk_class_student_status_ready_lms(mysqli $lms): bool
{
    foreach (['tbl_lms_class_student_statuses','tbl_lms_class_student_status_logs'] as $table) {
        if (!synk_assessment_query_lms($lms,'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?','s',[$table])) return false;
    }
    return true;
}

// A class status never changes shared enrollment, sign-in activity or LMS access.
function synk_class_student_status_map_lms(mysqli $lms,array $context): array
{
    if (!synk_class_student_status_ready_lms($lms)) return [];
    $rows=synk_assessment_query_lms($lms,'SELECT student_id,status,reason,version,updated_at FROM tbl_lms_class_student_statuses WHERE faculty_id=? AND offering_id=? AND ay_id=? AND semester=?','iiii',synk_assessment_scope_params_lms($context));
    $map=[];foreach ($rows as $row) {$row['version']=(int)$row['version'];$map[(int)$row['student_id']]=$row;}
    return $map;
}

function synk_class_students_roster_lms(mysqli $core,mysqli $lms,array $context): array
{
    $class=$context['class'];
    $roster=synk_professor_workload_students($core,(int)$context['portal']['faculty_id'],(int)$class['ay_id'],(int)$class['semester'],(int)$class['offering_id']);
    $ready=synk_class_student_status_ready_lms($lms);$map=$ready?synk_class_student_status_map_lms($lms,$context):[];$dropped=0;$visible=[];$excluded=[];
    foreach ($roster['students'] as &$student) {
        $record=$map[$student['student_id']]??['status'=>'enrolled','reason'=>'','version'=>0,'updated_at'=>null];
        $student['class_status']=$record['status'];$student['status_reason']=$record['reason'];$student['status_version']=$record['version'];$student['status_updated_at']=$record['updated_at'];
        $dropped+=$record['status']==='dropped'?1:0;
        if ($record['status']==='excluded') $excluded[]=$student;else $visible[]=$student;
    }unset($student);
    $roster['students']=$visible;$roster['excluded_students']=$excluded;$roster['excluded_count']=count($excluded);
    $roster['counts']=['students'=>count($visible),'enrolled'=>count($visible)-$dropped,'dropped'=>$dropped];
    $roster['status_ready']=$ready;
    return $roster;
}

// The caller owns the LMS transaction; each change and audit entry commit together.
function synk_class_student_status_change_lms(mysqli $core,mysqli $lms,array $context,array $input,int $actor): array
{
    if (!synk_class_student_status_ready_lms($lms)) throw new DomainException('Class status storage is not ready. Contact your administrator.',503);
    if (array_diff(array_keys($input),['student_id','status','version','reason'])) throw new DomainException('This request contains unsupported student status fields.',400);
    $id=synk_assessment_id_lms($input['student_id']??null);
    $version=$input['version']??null;
    if (!is_int($version) || $version<0 || $version>2147483646) throw new DomainException('Refresh the roster before changing this status.',422);
    $target=$input['status']??null;
    if (!is_string($target) || !in_array($target,['enrolled','dropped','excluded'],true)) throw new DomainException('Choose Enrolled, Dropped or Excluded.',422);
    $reason=synk_assessment_text_lms($input['reason']??'','Reason',500);
    $roster=synk_class_students_roster_lms($core,$lms,$context);
    if (!in_array($id,array_column(array_merge($roster['students'],$roster['excluded_students']),'student_id'),true)) throw new DomainException('This student is not in your current class roster.',404);
    $scope=synk_assessment_scope_params_lms($context);$now=(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
    // Insert the default state to serialize simultaneous first changes on the unique key.
    synk_assessment_query_lms($lms,'INSERT INTO tbl_lms_class_student_statuses (faculty_id,offering_id,ay_id,semester,student_id,updated_by_user_id,updated_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE student_id=VALUES(student_id)','iiiiiis',array_merge($scope,[$id,$actor,$now]));
    $rows=synk_assessment_query_lms($lms,'SELECT status_id,status,version FROM tbl_lms_class_student_statuses WHERE faculty_id=? AND offering_id=? AND ay_id=? AND semester=? AND student_id=? FOR UPDATE','iiiii',array_merge($scope,[$id]));$before=$rows[0];
    if ((int)$before['version']!==$version) throw new DomainException('This student’s status changed in another tab. Refresh the roster and try again.',409);
    if ($before['status']===$target) throw new DomainException('This student is already marked as '.$target.'.',409);
    synk_assessment_query_lms($lms,'UPDATE tbl_lms_class_student_statuses SET status=?,reason=?,version=version+1,updated_by_user_id=?,updated_at=? WHERE status_id=?','ssisi',[$target,$reason,$actor,$now,(int)$before['status_id']]);
    synk_assessment_query_lms($lms,'INSERT INTO tbl_lms_class_student_status_logs (status_id,actor_user_id,previous_status,status,reason,version,recorded_at) VALUES (?,?,?,?,?,?,?)','iisssis',[(int)$before['status_id'],$actor,$before['status'],$target,$reason,$version+1,$now]);
    return synk_class_students_roster_lms($core,$lms,$context);
}

function synk_class_student_counted_members_lms(array $members,array $statuses): array
{
    foreach ($statuses as $id=>$record) if ($record['status']==='excluded') unset($members[$id]);
    return $members;
}
