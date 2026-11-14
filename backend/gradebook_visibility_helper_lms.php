<?php
require_once __DIR__.'/grading_helper_lms.php';
require_once __DIR__.'/assessment_helper_lms.php';

function synk_gradebook_visibility_ready_lms(mysqli $lms): bool
{
    foreach(['tbl_lms_gradebook_visibility','tbl_lms_gradebook_visibility_logs'] as $table){
        if(!synk_grading_query_lms($lms,'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?','s',[$table]))return false;
    }
    return true;
}

function synk_gradebook_visibility_lms(mysqli $lms,array $context): array
{
    $visibility=['ready'=>synk_gradebook_visibility_ready_lms($lms),'version'=>0,'midterm'=>false,'final'=>false,'overall'=>false];
    // Missing storage or a new class always fails closed, including existing gradebooks.
    if(!$visibility['ready'])return $visibility;
    $rows=synk_grading_query_lms($lms,'SELECT version,midterm_visible,final_visible,overall_visible FROM tbl_lms_gradebook_visibility WHERE faculty_id=? AND offering_id=? AND ay_id=? AND semester=?','iiii',synk_grading_scope_lms($context));
    if($rows){
        $visibility['version']=(int)$rows[0]['version'];
        foreach(['midterm','final','overall'] as $part)$visibility[$part]=(bool)$rows[0][$part.'_visible'];
    }
    return $visibility;
}

// The caller validates professor ownership/CSRF and holds the LMS transaction.
function synk_gradebook_set_visibility_lms(mysqli $lms,array $context,array $payload,int $actor): array
{
    if(!synk_gradebook_visibility_ready_lms($lms))throw new DomainException('Student grade visibility storage is not ready. Ask your administrator to update the LMS gradebook.',503);
    $part=$payload['grade_part']??null;$visible=$payload['visible']??null;
    if(!is_string($part)||!in_array($part,['midterm','final','overall'],true)||!is_bool($visible))throw new DomainException('Choose a grade and whether to show or hide it from students.',422);
    $expected=synk_grading_integer_lms($payload['version']??null);$scope=synk_grading_scope_lms($context);
    // The unique class scope serializes the first save as well as later saves.
    synk_grading_query_lms($lms,'INSERT INTO tbl_lms_gradebook_visibility (faculty_id,offering_id,ay_id,semester) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE visibility_id=visibility_id','iiii',$scope);
    $row=synk_grading_query_lms($lms,'SELECT * FROM tbl_lms_gradebook_visibility WHERE faculty_id=? AND offering_id=? AND ay_id=? AND semester=? FOR UPDATE','iiii',$scope)[0];
    if((int)$row['version']!==$expected)throw new DomainException('Student grade visibility changed in another tab. Refresh the gradebook before trying again.',409);
    $before=(bool)$row[$part.'_visible'];if($before===$visible)return [];
    $version=$expected+1;$now=synk_assessment_now_lms();
    // $part is selected exclusively from the fixed allowlist above.
    synk_grading_query_lms($lms,'UPDATE tbl_lms_gradebook_visibility SET '.$part.'_visible=?,version=?,updated_by=?,updated_at=? WHERE visibility_id=?','iiisi',[(int)$visible,$version,$actor,$now,(int)$row['visibility_id']]);
    synk_grading_query_lms($lms,'INSERT INTO tbl_lms_gradebook_visibility_logs (visibility_id,actor_user_id,version,grade_part,was_visible,is_visible,recorded_at) VALUES (?,?,?,?,?,?,?)','iiisiis',[(int)$row['visibility_id'],$actor,$version,$part,(int)$before,(int)$visible,$now]);
    return [];
}
