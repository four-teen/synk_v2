<?php
if (!defined('SYNK_DEAN_ROSTER_QA') && PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once $root.'/backend/college_dean_manual_roster_helper.php';
require_once $root.'/backend/student_portal_helper.php';
$case=substr($scenario,strlen('manual_')); $scenario='classes';
$handler='backend/query_college_dean_manual_roster.php';
$number='000'.(string)random_int(7000000000,7900000000);
$input=['student_number'=>$number,'student_mode'=>'new','first_name'=>'José Carlo','last_name'=>'Peña','middle_name'=>'Niño','suffix_name'=>'',
    'email_address'=>'manual-'.strtolower($tag).'@sksu.edu.ph','student_program_id'=>$class['program_id'],'student_year_level'=>2,
    'reason_code'=>'late_enrollee','reason_note'=>'Late enrolment verified <script>not executable</script>'];
$existingCases=['lookup_existing','preview_existing','save_existing','save_cross_college','historical_membership','duplicate','concurrent_duplicate','transfer','transfer_no_ack','transfer_failure','legacy_transfer','stale_profile','stale_membership','csv_reimport','csv_append','manual_count'];
if (in_array($case,$existingCases,true)) {
    $record=$input+['ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'year_level'=>2,'program_id'=>$class['program_id'],'source_sheet_name'=>'DEAN MANUAL','source_file_name'=>'QA identity','source_row_number'=>0,'uploaded_by'=>$userId];
    if ($case==='save_cross_college') $record['program_id']=(int)$conn->query('SELECT program_id FROM tbl_program WHERE college_id<>'.$class['college_id'].' LIMIT 1')->fetch_assoc()['program_id'];
    $profileId=synk_student_identity_insert_record($conn,$record);
    $profileBefore=$conn->query('SELECT * FROM tbl_student_management WHERE student_id='.$profileId)->fetch_assoc();
    $input['student_mode']='existing'; $input['profile_version']=synk_dean_student_profile_version($profileBefore);
}
$insertMembership=static function(array $target,int $student,int $active=1)use($conn,$userId,$tag):int {
    $stmt=$conn->prepare("INSERT INTO tbl_student_management_enrolled_subjects (import_batch_key,source_file_name,campus_id,college_id,program_id,ay_id,semester,year_level,section_id,section_text,offering_id,subject_id,subject_code,descriptive_title,student_id,faculty_id,uploaded_by,is_active)
        VALUES (?,'qa-source.csv',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $params=[$tag,$target['campus_id'],$target['college_id'],$target['program_id'],$target['ay_id'],$target['semester'],$target['year_level'],$target['section_id'],$target['section_display'],$target['offering_id'],$target['subject_id'],$target['subject_code'],$target['descriptive_title'],$student,$target['faculty_id'],$userId,$active];
    synk_bind_dynamic_params($stmt,'siiiiiiisiissiiii',$params); $stmt->execute(); $id=(int)$stmt->insert_id; $stmt->close(); return $id;
};
$oldClass=null;
if (in_array($case,['transfer','transfer_no_ack','transfer_failure','legacy_transfer','stale_membership'],true)) {
    $oldClass=$class;
    $stmt=$conn->prepare("INSERT INTO tbl_sections (program_id,ay_id,semester,year_level,section_name,full_section,status) VALUES (?,?,?,2,?,?,'active')");
    $suffix=substr($tag,-8); $full=$class['program_code'].' 2'.$suffix;
    $stmt->bind_param('iiiss',$class['program_id'],$term['ay_id'],$term['semester'],$suffix,$full);$stmt->execute();$oldClass['section_id']=(int)$stmt->insert_id;$stmt->close();
    $oldClass['section_name']=$suffix; $oldClass['section_display']=$full;
    $template=$conn->query('SELECT prospectus_id,ps_id FROM tbl_prospectus_offering WHERE offering_id='.$offeringId)->fetch_assoc();
    $conn->query("INSERT INTO tbl_prospectus_offering (program_id,prospectus_id,ps_id,year_level,semester,ay_id,section_id,status) VALUES ({$class['program_id']},{$template['prospectus_id']},{$template['ps_id']},2,{$term['semester']},{$term['ay_id']},{$oldClass['section_id']},'active')");
    $oldClass['offering_id']=(int)$conn->insert_id;
    $legacyClass=$oldClass; if ($case==='legacy_transfer') $legacyClass['offering_id']=0;
    $insertMembership($legacyClass,$profileId);
    synk_dean_manual_count($conn,$oldClass,$userId);
}
if ($case==='duplicate') $insertMembership($class,$profileId);
if ($case==='historical_membership') { $historicalClass=$class;$historicalClass['offering_id']=0;$historicalClass['semester']=$term['semester']===1?2:1;$insertMembership($historicalClass,$profileId); }
if ($case==='blank_email') $input['email_address']='';
if ($case==='bad_email') $input['email_address']='wrong@example.com';
if ($case==='bad_name') $input['first_name']='<img src=x>';
if ($case==='bad_reason') $input['reason_code']='unknown';
if ($case==='bad_note') $input['reason_note']=str_repeat('x',501);
if ($case==='bad_number') $input['student_number']='000A123';
if ($case==='bad_year') $input['student_year_level']=6;
if ($case==='bad_program') $input['student_program_id']=2147483647;
if ($case==='email_collision') {
    $collision=$input+['ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'year_level'=>2,'program_id'=>$class['program_id'],'source_sheet_name'=>'QA','source_file_name'=>'QA','source_row_number'=>0,'uploaded_by'=>$userId];
    $collision['student_number']='999'.$number; synk_student_identity_insert_record($conn,$collision);
}
$_POST=array_merge($_POST,$input,['action'=>'preview']);
if (strpos($case,'lookup_')===0) $_POST['action']='lookup';
$confirmCases=['save_new','save_existing','save_cross_college','historical_membership','blank_email','audit_failure','transfer','transfer_no_ack','transfer_failure','legacy_transfer','stale_profile','stale_membership','concurrent_duplicate','expired','forged','other_user','other_class','replay','csv_reimport','csv_append','manual_count','assignment_removed','term_changed','reassigned'];
if (in_array($case,$confirmCases,true)) {
    if ($case==='manual_count') $conn->query("INSERT INTO tbl_offering_enrollee_counts (offering_id,total_enrollees,source_kind,created_by,updated_by) VALUES ({$offeringId},77,'manual',{$userId},{$userId}) ON DUPLICATE KEY UPDATE total_enrollees=77,source_kind='manual'");
    $preview=synk_dean_manual_preview($conn,$class,$input); $token=$preview['preview_token'];
    $_POST['action']='confirm';$_POST['preview_token']=$token;$_POST['confirm_transfer']='1';
    if ($case==='transfer_no_ack') $_POST['confirm_transfer']='0';
    if (in_array($case,['audit_failure','transfer_failure'],true)) $conn->failManualAudit=true;
    if ($case==='stale_profile') $conn->query("UPDATE tbl_student_management SET first_name='Changed' WHERE student_id={$profileId}");
    if ($case==='stale_membership') $conn->query('UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE student_id='.$profileId);
    if ($case==='concurrent_duplicate') $insertMembership($class,$profileId);
    if ($case==='expired') $_SESSION['dean_manual_roster_previews'][$token]['expires_at']=time()-1;
    if ($case==='other_user') $_SESSION['dean_manual_roster_previews'][$token]['user_id']++;
    if ($case==='forged') $_POST['preview_token']='forged';
    if ($case==='other_class') $_POST['offering_id']=2147483647;
    if ($case==='assignment_removed') $conn->query('DELETE FROM tbl_faculty_workload_sched WHERE faculty_id='.$facultyId.' AND schedule_id IN (SELECT schedule_id FROM tbl_class_schedule WHERE offering_id='.$offeringId.')');
    if ($case==='term_changed') $_POST['semester']=$term['semester']===1?2:1;
    if ($case==='reassigned') {
        $otherCollege=(int)$conn->query('SELECT college_id FROM tbl_college WHERE status=\'active\' AND college_id<>'.$collegeId.' LIMIT 1')->fetch_assoc()['college_id'];
        $conn->query('UPDATE tbl_useraccount SET college_dean_college_id='.$otherCollege.' WHERE user_id='.$userId);
        $_POST['college_id']=$collegeId;
    }
    if ($case==='replay') { synk_dean_manual_save($conn,$scope,$term,$_SESSION['dean_manual_roster_previews'][$token],$token,true); unset($_SESSION['dean_manual_roster_previews'][$token]); }
}
if ($case==='bad_csrf') $_POST['csrf_token']='bad';
if ($case==='scope') $_POST['college_id']=2147483647;
if ($case==='historical') $_POST['semester']=$term['semester']===1?2:1;
if ($case==='revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId} AND role='college_dean'");
if ($case==='inactive') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$userId}");
if ($case==='foreign_class') {
    $foreign=$conn->query('SELECT fw.faculty_id,po.offering_id '.$source.' JOIN tbl_program p ON p.program_id=po.program_id WHERE p.college_id<>'.$collegeId.' AND fw.ay_id='.$term['ay_id'].' AND fw.semester='.$term['semester'].' LIMIT 1')->fetch_assoc();
    if (!$foreign) throw new RuntimeException('An assigned foreign class is needed for scope tests.');
    $_POST['faculty_id']=(int)$foreign['faculty_id'];$_POST['offering_id']=(int)$foreign['offering_id'];
}
if ($case==='chair') $_SESSION['role']='program_chair';
if ($case==='array') $_POST['student_number']=['123'];
if ($case==='get') $_SERVER['REQUEST_METHOD']='GET';
$beforeCounts=$takeCounts();
$protected=['tbl_academic_settings','tbl_class_schedule','tbl_faculty_workload_sched','tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_enrollment_headers','tbl_enrollment_subjects','tbl_student_login_activity'];
$readProtected=static function()use($conn,$protected):string { $data=[];foreach($protected as $table)$data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($data)); };
$protectedBefore=$readProtected();
$GLOBALS['synk_manual_roster_qa']=static function()use($conn,$number,$class,$oldClass,$userId,$readProtected,$protectedBefore,$case):array {
    $profile=synk_student_identity_find($conn,$number); $id=$profile?(int)$profile['student_id']:0;
    $state=['profile'=>$profile,'memberships'=>$conn->query('SELECT * FROM tbl_student_management_enrolled_subjects WHERE student_id='.$id.' ORDER BY student_enrollment_id')->fetch_all(MYSQLI_ASSOC),
        'audit'=>$conn->query('SELECT * FROM tbl_student_class_roster_audit WHERE actor_user_id='.$userId.' ORDER BY audit_id')->fetch_all(MYSQLI_ASSOC),
        'portal_subjects'=>$id?synk_student_fetch_subject_rows_by_academic_year($conn,$id,$class['ay_id'],$class['semester']):[],
        'protected_unchanged'=>$protectedBefore===$readProtected(),'pending'=>count($_SESSION['dean_manual_roster_previews'] ?? [])];
    if ($oldClass) { $state['previous_roster']=synk_dean_roster_rows($conn,$oldClass);$state['previous_count']=synk_fetch_offering_enrollee_count_map($conn,[$oldClass['offering_id']]);$state['csv_protected']=isset(synk_roster_transferred_students($conn,$oldClass)[$number]); }
    if (($case==='csv_reimport' || $case==='csv_append' || $oldClass) && $profile) {
        $csvClass=$oldClass ?: $class;
        $student=$profile+['source_row_number'=>8,'student_name_raw'=>$profile['last_name'].', '.$profile['first_name'].' '.$profile['middle_name']];
        $parsed=['students'=>[$student],'duplicate_rows'=>0,'subject_section_raw'=>$csvClass['subject_code'].' - '.$csvClass['section_display'],'campus_header'=>$csvClass['campus_name'],'faculty_name'=>$csvClass['faculty_name'],'descriptive_title'=>$csvClass['descriptive_title']];
        $state['csv_preview']=synk_dean_roster_preview($conn,$csvClass,$parsed);
        if ($case==='csv_append') {
            $another=$student; $another['student_number']='888'.$number; $another['first_name']='Another';$another['last_name']='Student';$another['middle_name']='';$another['email_address']='';$another['student_name_raw']='Student, Another';
            $parsed['students']=[$another];
            $state['csv_result']=synk_dean_roster_save($conn,$class,$class,['user_id'=>$userId,'college_id'=>$class['college_id'],'ay_id'=>$class['ay_id'],'semester'=>$class['semester'],
                'faculty_id'=>$class['faculty_id'],'offering_id'=>$class['offering_id'],'expires_at'=>time()+300,'parsed'=>$parsed,'file_name'=>'later-roster.csv'],$userId);
            $state['after_csv_roster']=synk_dean_roster_rows($conn,$class);
        }
    }
    return $state;
};
