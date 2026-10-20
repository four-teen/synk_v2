<?php
if (!defined('SYNK_DEAN_ROSTER_QA') && PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once $root.'/backend/college_dean_clear_roster_helper.php';
require_once $root.'/backend/student_portal_helper.php';
require_once $root.'/backend/college_dean_student_login_helper.php';
$case=substr($scenario,strlen('clear_')); $scenario='classes';
$handler='backend/query_college_dean_clear_roster.php';
$ids=[]; $numbers=[];
$foreign=$conn->query('SELECT p.program_id,p.college_id,c.campus_id FROM tbl_program p JOIN tbl_college c ON c.college_id=p.college_id WHERE p.college_id<>'.$collegeId.' LIMIT 1')->fetch_assoc();
if (!$foreign) throw new RuntimeException('A foreign program is needed to verify directory isolation.');
for ($i=0;$i<2;$i++) {
    $number='000'.(string)random_int(8000000000,8900000000); $numbers[]=$number;
    $ids[]=synk_student_identity_insert_record($conn,['student_number'=>$number,'first_name'=>'ClassClear','last_name'=>'Student','middle_name'=>'','suffix_name'=>'',
        'email_address'=>'clear-'.$i.'-'.strtolower($tag).'@sksu.edu.ph','ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'year_level'=>2,
        'program_id'=>$i?(int)$foreign['program_id']:$class['program_id'],'source_sheet_name'=>'QA CSV','source_file_name'=>'qa.csv','source_row_number'=>$i+1,'uploaded_by'=>$userId]);
}
$insert=static function(array $target,int $id)use($conn,$tag,$userId):int {
    $params=[$tag,$target['campus_id'],$target['college_id'],$target['program_id'],$target['ay_id'],$target['semester'],$target['year_level'],$target['section_id'],
        $target['section_display'],$target['offering_id'],$target['subject_id'],$target['subject_code'],$target['descriptive_title'],$id,$target['faculty_id'],$userId];
    $stmt=$conn->prepare("INSERT INTO tbl_student_management_enrolled_subjects
        (import_batch_key,source_file_name,campus_id,college_id,program_id,ay_id,semester,year_level,section_id,section_text,offering_id,subject_id,subject_code,descriptive_title,student_id,faculty_id,uploaded_by,is_active)
        VALUES (?,'qa-clear.csv',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
    synk_bind_dynamic_params($stmt,'siiiiiiisiissiii',$params); $stmt->execute(); $id=(int)$stmt->insert_id; $stmt->close(); return $id;
};
$legacy=$class; $legacy['offering_id']=0; $insert($legacy,$ids[0]);
$profile=synk_student_identity_find($conn,$numbers[1]);
$manual=synk_dean_manual_preview($conn,$class,['student_number'=>$numbers[1],'student_mode'=>'existing','profile_version'=>synk_dean_student_profile_version($profile),'reason_code'=>'late_enrollee']);
synk_dean_manual_save($conn,$scope,$term,$_SESSION['dean_manual_roster_previews'][$manual['preview_token']],$manual['preview_token'],false);
$other=null;
foreach (synk_dean_workload_classes($conn,$scope,$term,$facultyId) as $candidate) if ($candidate['subject_id']!==$class['subject_id']) { $other=$candidate; break; }
if (!$other) throw new RuntimeException('Two current subjects are required for class clearing tests.');
$insert($other,$ids[1]);
$historical=$class; $historical['offering_id']=0; $historical['semester']=$term['semester']===1?2:1; $insert($historical,$ids[1]);
$foreignClass=$class; $foreignClass['offering_id']=0; $foreignClass['college_id']=(int)$foreign['college_id']; $foreignClass['campus_id']=(int)$foreign['campus_id']; $foreignClass['program_id']=(int)$foreign['program_id']; $insert($foreignClass,$ids[1]);
synk_dean_manual_count($conn,$class,$userId);
if ($case==='manual_count') $conn->query("UPDATE tbl_offering_enrollee_counts SET total_enrollees=77,source_kind='manual' WHERE offering_id={$offeringId}");
$plan=synk_dean_clear_plan($conn,$class); $clearIds=array_map('intval',array_column($plan['rows'],'student_enrollment_id'));
$outsideSQL='SELECT * FROM tbl_student_management_enrolled_subjects WHERE student_enrollment_id NOT IN ('.implode(',',$clearIds).') ORDER BY student_enrollment_id';
$snapshot=static function(array $tables)use($conn):string { $data=[];foreach($tables as $table)$data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($data)); };
$protected=['tbl_student_management','tbl_student_portal_profile','tbl_useraccount','tbl_class_schedule','tbl_faculty_workload_sched','tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_enrollment_headers','tbl_enrollment_subjects','tbl_student_login_activity','tbl_student_class_roster_audit'];
$_POST['action']='preview';
if ($case!=='preview' && !in_array($case,['bad_csrf','scope','historical','chair','get','array','unknown','missing_schema','bootstrap_missing'],true)) {
    $preview=synk_dean_clear_preview($conn,$class); $token=$preview['preview_token'];
    $_POST['action']='confirm'; $_POST['preview_token']=$token; $_POST['confirm_clear']='1';
    if ($case==='no_ack') $_POST['confirm_clear']='0';
    if ($case==='expired') $_SESSION['dean_clear_roster_previews'][$token]['expires_at']=time()-1;
    if ($case==='other_user') $_SESSION['dean_clear_roster_previews'][$token]['user_id']++;
    if ($case==='forged') $_POST['preview_token']='forged';
    if ($case==='other_class') $_POST['offering_id']=2147483647;
    if ($case==='changed_roster') $conn->query('UPDATE tbl_student_management_enrolled_subjects SET faculty_id=0 WHERE student_enrollment_id='.$clearIds[0]);
    if ($case==='audit_failure') $conn->failClearAudit=true;
    if ($case==='assignment_removed') $conn->query('DELETE FROM tbl_faculty_workload_sched WHERE faculty_id='.$facultyId.' AND schedule_id IN (SELECT schedule_id FROM tbl_class_schedule WHERE offering_id='.$offeringId.')');
    if ($case==='cancel') $_POST['action']='cancel';
    if ($case==='replay' || $case==='empty') {
        synk_dean_clear_save($conn,$scope,$term,$_SESSION['dean_clear_roster_previews'][$token],$token); unset($_SESSION['dean_clear_roster_previews'][$token]);
        if ($case==='empty') $_POST['action']='preview';
    }
}
if ($case==='bad_csrf') $_POST['csrf_token']='bad';
if ($case==='scope') $_POST['college_id']=2147483647;
if ($case==='historical') $_POST['semester']=$term['semester']===1?2:1;
if ($case==='chair') $_SESSION['role']='program_chair';
if ($case==='get') $_SERVER['REQUEST_METHOD']='GET';
if ($case==='array') $_POST['confirm_clear']=['1'];
if ($case==='unknown') $_POST['action']='remove_all';
if ($case==='missing_schema' || $case==='bootstrap_missing') $conn->hideClearTable=true;
if ($case==='bootstrap_missing') { $handler='backend/query_college_dean_rosters.php'; $_POST['action']='bootstrap'; }
if ($case==='revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId} AND role='college_dean'");
if ($case==='inactive') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$userId}");
if ($case==='foreign_class') {
    $assignment=$conn->query('SELECT fw.faculty_id,po.offering_id '.$source.' JOIN tbl_program p ON p.program_id=po.program_id WHERE p.college_id<>'.$collegeId.' AND fw.ay_id='.$term['ay_id'].' AND fw.semester='.$term['semester'].' LIMIT 1')->fetch_assoc();
    if (!$assignment) throw new RuntimeException('An assigned foreign class is needed for scope tests.');
    $_POST['faculty_id']=(int)$assignment['faculty_id']; $_POST['offering_id']=(int)$assignment['offering_id'];
}
$beforeCounts=$takeCounts(); $protectedBefore=$snapshot($protected);
$outsideBefore=hash('sha256',json_encode($conn->query($outsideSQL)->fetch_all(MYSQLI_ASSOC)));
$beforeMemberships=$conn->query('SELECT * FROM tbl_student_management_enrolled_subjects ORDER BY student_enrollment_id')->fetch_all(MYSQLI_ASSOC);
$GLOBALS['synk_clear_roster_qa']=static function()use($conn,$scope,$class,$ids,$numbers,$userId,$case,$protected,$snapshot,$protectedBefore,$outsideSQL,$outsideBefore,$beforeMemberships):array {
    $filters=synk_dean_students_filters(['term'=>$class['ay_id'].':'.$class['semester']]);
    $directory=[]; $details=[]; $login=[]; $portal=[];
    foreach ($ids as $id) {
        $f=$filters; $f['search']=synk_student_identity_find($conn,$numbers[array_search($id,$ids,true)])['student_number'];
        $directory[$id]=synk_dean_students_list($conn,$scope,$f);
        $details[$id]=synk_dean_students_detail($conn,$scope,$id,$filters);
        $login[$id]=synk_dean_student_login_history($conn,$scope,$id,[]);
        $portal[$id]=synk_student_fetch_subject_rows_by_academic_year($conn,$id,$class['ay_id'],$class['semester']);
    }
    $audit=$conn->query('SELECT * FROM tbl_student_class_clear_history WHERE actor_user_id='.$userId.' ORDER BY clear_id')->fetch_all(MYSQLI_ASSOC);
    $afterMemberships=$conn->query('SELECT * FROM tbl_student_management_enrolled_subjects ORDER BY student_enrollment_id')->fetch_all(MYSQLI_ASSOC);
    $state=['ids'=>$ids,'directory'=>$directory,'details'=>$details,'login'=>$login,'portal'=>$portal,'audit'=>$audit,
        'protected_unchanged'=>$protectedBefore===$snapshot($protected),'outside_unchanged'=>$outsideBefore===hash('sha256',json_encode($conn->query($outsideSQL)->fetch_all(MYSQLI_ASSOC))),
        'memberships_unchanged'=>$beforeMemberships===$afterMemberships,'pending'=>count($_SESSION['dean_clear_roster_previews'] ?? []),
        'row_count_unchanged'=>count($beforeMemberships)===count($afterMemberships)];
    if ($case==='delete_directory' && $audit) {
        $preview=synk_dean_students_delete_preview($conn,$scope,$filters,[$ids[0]]);
        $state['delete_preview']=$preview;
        synk_dean_students_delete_save($conn,$scope,$preview['preview_token']);
        $state['deleted_directory']=synk_dean_students_detail($conn,$scope,$ids[0],$filters)===null;
        $state['identity_kept']=(bool)synk_student_identity_find($conn,$numbers[0]);
        $state['audit_kept']=(int)$conn->query('SELECT COUNT(*) n FROM tbl_student_class_clear_history WHERE student_id='.$ids[0])->fetch_assoc()['n'];
    }
    if ($case==='reimport' && $audit) {
        $student=synk_student_identity_find($conn,$numbers[0]);
        $parsed=['students'=>[$student+['source_row_number'=>8,'student_name_raw'=>$student['last_name'].', '.$student['first_name']]],'duplicate_rows'=>0,
            'subject_section_raw'=>$class['subject_code'].' - '.$class['section_display'],'campus_header'=>$class['campus_name'],'faculty_name'=>$class['faculty_name'],'descriptive_title'=>$class['descriptive_title']];
        $state['csv_result']=synk_dean_roster_save($conn,$scope,$class,['user_id'=>$userId,'college_id'=>$class['college_id'],'ay_id'=>$class['ay_id'],'semester'=>$class['semester'],
            'faculty_id'=>$class['faculty_id'],'offering_id'=>$class['offering_id'],'expires_at'=>time()+300,'parsed'=>$parsed,'file_name'=>'corrected.csv'],$userId);
        $state['after_reimport']=synk_dean_roster_rows($conn,$class);
        $state['profile_reused']=(int)synk_student_identity_find($conn,$numbers[0])['student_id']===$ids[0];
        $state['inactive_preserved']=(int)$conn->query('SELECT COUNT(*) n FROM tbl_student_management_enrolled_subjects WHERE student_id='.$ids[0].' AND is_active=0')->fetch_assoc()['n'];
    }
    return $state;
};
