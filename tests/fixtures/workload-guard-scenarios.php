<?php
if (!defined('SYNK_DEAN_ROSTER_QA') && PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once $root.'/backend/college_dean_clear_roster_helper.php';
require_once $root.'/backend/college_dean_roster_review_helper.php';
$case=substr($scenario,strlen('guard_')); $scenario='classes';
$handler='backend/query_remove_workload.php';
$review=strpos($case,'review_')===0;
$allIds=static function(array $target)use($conn):array {
    return array_map('intval',array_column(synk_dean_students_query($conn,'SELECT fw.workload_id FROM tbl_faculty_workload_sched fw JOIN tbl_class_schedule cs ON cs.schedule_id=fw.schedule_id WHERE cs.offering_id=? AND fw.ay_id=? AND fw.semester=? ORDER BY fw.workload_id','iii',[$target['offering_id'],$target['ay_id'],$target['semester']]),'workload_id'));
};
$workloadIds=$allIds($class);
// Isolate the selected class inside the rollback-only fixture.
$stmt=$conn->prepare('UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE (offering_id=? OR offering_id=0) AND program_id=? AND section_id=? AND subject_id=? AND college_id=? AND campus_id=? AND ay_id=? AND semester=? AND is_active=1');
$values=[$class['offering_id'],$class['program_id'],$class['section_id'],$class['subject_id'],$class['college_id'],$class['campus_id'],$class['ay_id'],$class['semester']];
synk_bind_dynamic_params($stmt,'iiiiiiii',$values); $stmt->execute(); $stmt->close();
$number='000'.(string)random_int(9000000000,9900000000);
$record=['student_number'=>$number,'first_name'=>'Guard','last_name'=>'Student','middle_name'=>'','suffix_name'=>'','email_address'=>'guard-'.strtolower($tag).'@sksu.edu.ph',
    'program_id'=>$class['program_id'],'ay_id'=>$class['ay_id'],'semester'=>$class['semester'],'year_level'=>$class['year_level'],
    'source_sheet_name'=>'QA CSV','source_file_name'=>'qa.csv','source_row_number'=>1,'uploaded_by'=>$userId];
$studentId=synk_student_identity_insert_record($conn,$record);
$recovery=[];
$insert=static function(array $target,int $student)use($conn,$tag,$userId):int {
    $params=[$tag,$target['campus_id'],$target['college_id'],$target['program_id'],$target['ay_id'],$target['semester'],$target['year_level'],$target['section_id'],
        $target['section_display'],$target['offering_id'],$target['subject_id'],$target['subject_code'],$target['descriptive_title'],$student,$target['faculty_id'],$userId];
    $stmt=$conn->prepare("INSERT INTO tbl_student_management_enrolled_subjects
        (import_batch_key,source_file_name,campus_id,college_id,program_id,ay_id,semester,year_level,section_id,section_text,offering_id,subject_id,subject_code,descriptive_title,student_id,faculty_id,uploaded_by,is_active)
        VALUES (?,'qa-guard.csv',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
    synk_bind_dynamic_params($stmt,'siiiiiiisiissiii',$params); $stmt->execute(); $id=(int)$stmt->insert_id; $stmt->close(); return $id;
};
$membershipClass=$class;
if (in_array($case,['legacy','review_legacy','review_legacy_unassigned'],true)) $membershipClass['offering_id']=0;
if ($case==='other_term') { $membershipClass['offering_id']=0; $membershipClass['semester']=$class['semester']===1?2:1; }
if ($case==='other_college_students') {
    $foreign=$conn->query('SELECT p.program_id,p.college_id,c.campus_id FROM tbl_program p JOIN tbl_college c ON c.college_id=p.college_id WHERE p.college_id<>'.$collegeId.' LIMIT 1')->fetch_assoc();
    $membershipClass['program_id']=(int)$foreign['program_id']; $membershipClass['college_id']=(int)$foreign['college_id']; $membershipClass['campus_id']=(int)$foreign['campus_id'];
}
if ($case==='manual') {
    $profile=synk_student_identity_find($conn,$number);
    $preview=synk_dean_manual_preview($conn,$class,['student_number'=>$number,'student_mode'=>'existing','profile_version'=>synk_dean_student_profile_version($profile),'reason_code'=>'late_enrollee']);
    synk_dean_manual_save($conn,$scope,$term,$_SESSION['dean_manual_roster_previews'][$preview['preview_token']],$preview['preview_token'],false);
    $membershipId=(int)$conn->query('SELECT student_enrollment_id FROM tbl_student_management_enrolled_subjects WHERE student_id='.$studentId)->fetch_assoc()['student_enrollment_id'];
} else $membershipId=$insert($membershipClass,$studentId);
if ($case==='count39') for ($i=1;$i<39;$i++) {
    $extra=$record; $extra['student_number']='000'.(string)random_int(9000000000,9900000000); $extra['email_address']='guard-'.$i.'-'.strtolower($tag).'@sksu.edu.ph';
    $insert($class,synk_student_identity_insert_record($conn,$extra));
}
if ($case==='positive_corrupt_metadata') $conn->query('UPDATE tbl_student_management_enrolled_subjects SET subject_id=2147483647 WHERE student_enrollment_id='.$membershipId);
if ($case==='inactive' || $case==='empty' || $case==='audit_failure') $conn->query('UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE student_enrollment_id='.$membershipId);
if ($case==='cleared') {
    $preview=synk_dean_clear_preview($conn,$class);
    synk_dean_clear_save($conn,$scope,$term,$_SESSION['dean_clear_roster_previews'][$preview['preview_token']],$preview['preview_token']);
}
if (in_array($case,['review_unassigned','review_legacy_unassigned','review_reassigned','review_mismatch','review_foreign','review_historical','review_unavailable','review_ambiguous','review_recovered'],true)) {
    if ($case==='review_unassigned' || $case==='review_legacy_unassigned') $conn->query('DELETE FROM tbl_faculty_workload_sched WHERE workload_id IN ('.implode(',',$workloadIds).')');
    if ($case==='review_reassigned' || $case==='review_mismatch' || $case==='review_recovered') {
        $otherFaculty=(int)$conn->query("SELECT faculty_id FROM tbl_faculty WHERE status='active' AND faculty_id<>".$facultyId.' LIMIT 1')->fetch_assoc()['faculty_id'];
        if ($case==='review_reassigned' || $case==='review_recovered') $conn->query('UPDATE tbl_faculty_workload_sched SET faculty_id='.$otherFaculty.' WHERE workload_id IN ('.implode(',',$workloadIds).')');
        else $conn->query('UPDATE tbl_student_management_enrolled_subjects SET faculty_id='.$otherFaculty.' WHERE student_enrollment_id='.$membershipId);
    }
    if ($case==='review_recovered') {
        require_once $root.'/backend/student_portal_helper.php';
        $profile=synk_student_identity_find($conn,$number);
        $newClass=synk_dean_workload_class($conn,$scope,$term,$otherFaculty,$offeringId);
        $clear=synk_dean_clear_preview($conn,$newClass);
        synk_dean_clear_save($conn,$scope,$term,$_SESSION['dean_clear_roster_previews'][$clear['preview_token']],$clear['preview_token']);
        $parsed=['students'=>[$profile+['source_row_number'=>8,'student_name_raw'=>$profile['last_name'].', '.$profile['first_name']]],'duplicate_rows'=>0,
            'subject_section_raw'=>$class['subject_code'].' - '.$class['section_display'],'campus_header'=>$class['campus_name'],'faculty_name'=>$newClass['faculty_name'],'descriptive_title'=>$class['descriptive_title']];
        synk_dean_roster_save($conn,$scope,$term,['user_id'=>$userId,'college_id'=>$collegeId,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],
            'faculty_id'=>$otherFaculty,'offering_id'=>$offeringId,'expires_at'=>time()+300,'parsed'=>$parsed,'file_name'=>'corrected.csv'],$userId);
        $subjects=synk_student_fetch_subject_rows_by_academic_year($conn,$studentId,$term['ay_id'],$term['semester']);
        $recovery=['same_identity'=>$profile===synk_student_identity_find($conn,$number),'new_faculty'=>$otherFaculty,'portal_faculty'=>$subjects[0]['faculty_id'],
            'old_membership_inactive'=>(int)$conn->query('SELECT is_active FROM tbl_student_management_enrolled_subjects WHERE student_enrollment_id='.$membershipId)->fetch_assoc()['is_active']===0];
    }
    if ($case==='review_foreign') $conn->query('UPDATE tbl_student_management_enrolled_subjects SET college_id=2147483647 WHERE student_enrollment_id='.$membershipId);
    if ($case==='review_unavailable') $conn->query('UPDATE tbl_student_management_enrolled_subjects SET offering_id=2147483647 WHERE student_enrollment_id='.$membershipId);
    if ($case==='review_ambiguous') {
        $conn->query('UPDATE tbl_student_management_enrolled_subjects SET offering_id=0 WHERE student_enrollment_id='.$membershipId);
        $template=$conn->query('SELECT prospectus_id,ps_id FROM tbl_prospectus_offering WHERE offering_id='.$offeringId)->fetch_assoc();
        $conn->query("INSERT INTO tbl_prospectus_offering (program_id,prospectus_id,ps_id,year_level,semester,ay_id,section_id,status) VALUES ({$class['program_id']},{$template['prospectus_id']},{$template['ps_id']},{$class['year_level']},{$term['semester']},{$term['ay_id']},{$class['section_id']},'active')");
    }
}
if ($case==='paired' && count($workloadIds)<2) {
    $conn->query("INSERT INTO tbl_class_schedule (offering_id,schedule_type,schedule_status,days_json,time_start,time_end,created_by) VALUES ({$offeringId},'LAB','tba','[]',NULL,NULL,{$userId})");
    $scheduleId=(int)$conn->insert_id;
    $conn->query("INSERT INTO tbl_faculty_workload_sched (schedule_id,faculty_id,ay_id,semester) VALUES ({$scheduleId},{$facultyId},{$term['ay_id']},{$term['semester']})");
    $workloadIds=$allIds($class);
}
$_POST=['csrf_token'=>'dean-roster-qa','workload_ids'=>$workloadIds];
if ($case==='faculty_need') {
    $conn->query("INSERT INTO tbl_faculty_need (college_id,ay_id,semester,need_label,status) VALUES ({$collegeId},{$term['ay_id']},{$term['semester']},'{$tag}','active')");
    $need=(int)$conn->insert_id; $scheduleId=(int)$class['schedules'][0]['schedule_id'];
    $conn->query("INSERT INTO tbl_faculty_need_workload_sched (faculty_need_id,schedule_id,ay_id,semester) VALUES ({$need},{$scheduleId},{$term['ay_id']},{$term['semester']})");
    $_POST['workload_ids']=[(int)$conn->insert_id]; $_POST['assignee_type']='faculty_need';
}
if ($review) {
    $handler='backend/query_college_dean_rosters.php'; $_POST+=['action'=>'roster_review','ay_id'=>$class['ay_id'],'semester'=>$class['semester']];
    if ($case==='review_historical') $_POST['semester']=$class['semester']===1?2:1;
    if ($case==='review_chair') $_SESSION['role']='program_chair';
    if ($case==='review_scope') $_POST['college_id']=2147483647;
} else {
    $_SESSION['role']='scheduler'; $_SESSION['college_id']=$collegeId;
    if ($case==='mixed') {
        $other=$conn->query('SELECT fw.workload_id FROM tbl_faculty_workload_sched fw JOIN tbl_class_schedule cs ON cs.schedule_id=fw.schedule_id JOIN tbl_prospectus_offering o ON o.offering_id=cs.offering_id JOIN tbl_program p ON p.program_id=o.program_id WHERE p.college_id='.$collegeId.' AND cs.offering_id<>'.$offeringId.' LIMIT 1')->fetch_assoc();
        $_POST['workload_ids'][]=(int)$other['workload_id'];
    }
    if ($case==='foreign_selection') {
        $other=$conn->query('SELECT fw.workload_id FROM tbl_faculty_workload_sched fw JOIN tbl_class_schedule cs ON cs.schedule_id=fw.schedule_id JOIN tbl_prospectus_offering o ON o.offering_id=cs.offering_id JOIN tbl_program p ON p.program_id=o.program_id WHERE p.college_id<>'.$collegeId.' LIMIT 1')->fetch_assoc();
        $_POST['workload_ids'][]=(int)$other['workload_id'];
    }
    if ($case==='csv_ids') $_POST['workload_ids']=implode(',',$workloadIds);
    if ($case==='single') { unset($_POST['workload_ids']); $_POST['workload_id']=$workloadIds[0]; }
    if ($case==='bad_csrf') $_POST['csrf_token']='bad';
    if ($case==='role') $_SESSION['role']='college_dean';
    if ($case==='get') $_SERVER['REQUEST_METHOD']='GET';
    if ($case==='bad_ids') $_POST['workload_ids']=[['1']];
    if ($case==='stale_ids') $_POST['workload_ids']=[2147483647];
    if ($case==='audit_failure') $conn->failWorkloadAudit=true;
    if (strpos($case,'bulk_')===0) {
        $handler='backend/query_remove_all_college_workload.php'; $_POST+=['ay_id'=>$class['ay_id'],'semester'=>$class['semester']];
        if ($case==='bulk_empty') $conn->query('UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE college_id='.$collegeId.' AND ay_id='.$class['ay_id'].' AND semester='.$class['semester'].' AND is_active=1');
        if ($case==='bulk_bad_term') $_POST['semester']=['1'];
    }
}
$snapshot=static function(array $tables)use($conn):string { $data=[]; foreach ($tables as $table) $data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC); return hash('sha256',json_encode($data)); };
$protected=['tbl_student_management','tbl_student_management_enrolled_subjects','tbl_student_class_clear_history','tbl_student_class_roster_audit','tbl_student_portal_profile','tbl_class_schedule','tbl_prospectus_offering','tbl_offering_enrollee_counts','tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_student_login_activity','tbl_faculty_need','tbl_faculty_need_workload_sched'];
$protectedBefore=$snapshot($protected); $workloadBefore=$snapshot(['tbl_faculty_workload_sched']);
$auditBefore=(int)$conn->query('SELECT COUNT(*) n FROM tbl_scheduler_workload_audit_logs WHERE actor_user_id='.$userId)->fetch_assoc()['n'];
$beforeCounts=$takeCounts();
$allowed=$workloadIds;
if (strpos($case,'bulk_')===0) $allowed=array_map('intval',array_column($conn->query('SELECT fw.workload_id FROM tbl_faculty_workload_sched fw JOIN tbl_class_schedule cs ON cs.schedule_id=fw.schedule_id JOIN tbl_prospectus_offering o ON o.offering_id=cs.offering_id JOIN tbl_program p ON p.program_id=o.program_id WHERE p.college_id='.$collegeId.' AND fw.ay_id='.$class['ay_id'].' AND fw.semester='.$class['semester'])->fetch_all(MYSQLI_ASSOC),'workload_id'));
$outsideSQL='SELECT * FROM tbl_faculty_workload_sched WHERE workload_id NOT IN ('.implode(',',$allowed).') ORDER BY workload_id';
$outsideBefore=hash('sha256',json_encode($conn->query($outsideSQL)->fetch_all(MYSQLI_ASSOC)));
$GLOBALS['synk_workload_guard_qa']=static function()use($conn,$protected,$protectedBefore,$workloadBefore,$snapshot,$userId,$auditBefore,$class,$workloadIds,$membershipId,$studentId,$recovery,$outsideSQL,$outsideBefore):array {
    $remaining=(int)$conn->query('SELECT COUNT(*) n FROM tbl_faculty_workload_sched WHERE workload_id IN ('.implode(',',$workloadIds).')')->fetch_assoc()['n'];
    return ['protected_unchanged'=>$protectedBefore===$snapshot($protected),'workload_unchanged'=>$workloadBefore===$snapshot(['tbl_faculty_workload_sched']),
        'remaining'=>$remaining,'selected_count'=>count($workloadIds),'audit_added'=>(int)$conn->query('SELECT COUNT(*) n FROM tbl_scheduler_workload_audit_logs WHERE actor_user_id='.$userId)->fetch_assoc()['n']-$auditBefore,
        'expected_students'=>synk_workload_students_in_class($conn,$class),'student_id'=>$studentId,'membership_id'=>$membershipId,'recovery'=>$recovery,
        'outside_workload_unchanged'=>$outsideBefore===hash('sha256',json_encode($conn->query($outsideSQL)->fetch_all(MYSQLI_ASSOC)))];
};
