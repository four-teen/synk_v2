<?php
if (!defined('SYNK_STUDENT_PORTAL_QA') || !isset($conn,$teachers,$students,$program)) { http_response_code(404);exit; }
if (!defined('SYNK_PROGRAM_CHAIR_API')) define('SYNK_PROGRAM_CHAIR_API',true);
require_once $root.'/backend/supervisor_evaluation_helper.php';
$conn->requestMode=true;$case=substr($scenario,strlen('supervisor_'));
$college=(int)$program['college_id'];$ay=(int)$term['ay_id'];$semester=(int)$term['semester'];
synk_evaluation_query($conn,"INSERT INTO tbl_faculty (first_name,last_name,status) VALUES ('TeachingDean',?,'active')",'s',[$tag]);$deanFaculty=(int)$conn->insert_id;
synk_evaluation_query($conn,"INSERT INTO tbl_student_management_enrolled_subjects (import_batch_key,student_id,campus_id,college_id,program_id,ay_id,semester,subject_id,subject_code,descriptive_title,faculty_id,is_active) VALUES (?,?,?,?,?,?,?,99,'DEAN-101','Teaching Dean Subject',?,1)",'siiiiiii',[$tag,$students['first'],$program['campus_id'],$college,$program['program_id'],$ay,$semester,$deanFaculty]);
$sefUsers=[];
foreach (['dean'=>$deanFaculty,'chair'=>$teachers[1]] as $key=>$linkedFaculty) {
    $role=$key==='dean'?'college_dean':'program_chair';
    synk_evaluation_query($conn,"INSERT INTO tbl_useraccount (username,email,password,role,status,college_id,college_dean_college_id) VALUES (?,?,'unusable',?,'active',?,?)",'sssii',[$tag.' '.$key,$key.'-sef-'.$email,$role,$college,$college]);$sefUsers[$key]=(int)$conn->insert_id;
    synk_persist_useraccount_roles($conn,$sefUsers[$key],[$role],$role);
    synk_evaluation_query($conn,'INSERT INTO tbl_useraccount_faculty_links (user_id,faculty_id) VALUES (?,?)','ii',[$sefUsers[$key],$linkedFaculty]);
}
$sefSession=static function(string $key)use(&$sefUsers,$csrf):void { $_SESSION=['user_id'=>$sefUsers[$key],'role'=>$key==='dean'?'college_dean':'program_chair','csrf_token'=>$csrf]; };
$base=['csrf_token'=>$csrf,'college_id'=>$college,'ay_id'=>$ay,'semester'=>$semester];
require_once __DIR__.'/supervisor-workloads.php';
synk_evaluation_query($conn,"INSERT INTO tbl_faculty_college_appointments(college_id,ay_id,semester,appointment_role,program_id,faculty_id,status) VALUES (?,?,?,'chair',?,?,'active')",'iiiii',[$college,$ay,$semester,$program['program_id'],$teachers[1]]);
foreach ([$teachers[0],$deanFaculty] as $target) sef_qa_workload($conn,(int)$program['program_id'],$ay,$semester,$target,$sefUsers['dean']);
$sefSession('dean');synk_sef_write($conn,$base+['action'=>'collection','is_open'=>1,'version'=>0]);
$seedDrafts=static function(string $key)use($conn,$base,$sefSession,$teachers,$deanFaculty):void {
    $sefSession($key);
    foreach ($key==='dean'?[$teachers[1]]:[$teachers[0],$deanFaculty] as $id) {
        $ratings=array_fill_keys(array_keys(synk_sef_questions()),5); $ratings['SEF13']=$ratings['SEF14']=$ratings['SEF15']=4;
        synk_sef_write($conn,$base+['action'=>'save','faculty_id'=>$id,'assignment_revision'=>1,'version'=>0,'ratings'=>$ratings,'comment_text'=>($key==='dean'?'Dean submitted assessment':'PRIVATE_CHAIR_DRAFT').' <script>unsafe()</script>']);
    }
};
$seedFinal=static function(string $key)use($conn,$base,$seedDrafts,$teachers,$deanFaculty):void {
    $seedDrafts($key);$versions=[];$actor=synk_sef_actor($conn);$period=synk_dean_term_from_ids($conn,$base['ay_id'],$base['semester']);$rows=synk_sef_records($conn,$actor['scope'],$period,$actor);foreach ($key==='dean'?[$teachers[1]]:[$teachers[0],$deanFaculty] as $id) $versions[$id]=synk_sef_review_token($rows[$id]);
    synk_sef_write($conn,$base+['action'=>'finalize','confirm_final'=>'1','draft_versions'=>$versions]);
};
if (in_array($case,['chair_shared_draft','chair_shared_done'],true)) {
    synk_evaluation_query($conn,"INSERT INTO tbl_faculty(first_name,last_name,status) VALUES ('OtherChair',?,'active')",'s',[$tag]);$otherChair=(int)$conn->insert_id;
    synk_evaluation_query($conn,"INSERT INTO tbl_useraccount(username,email,password,role,status,college_id) VALUES (?,?,'unusable','program_chair','active',?)",'ssi',[$tag.' other Chair','other-sef-'.$email,$college]);$sefUsers['chair2']=(int)$conn->insert_id;
    synk_persist_useraccount_roles($conn,$sefUsers['chair2'],['program_chair'],'program_chair');
    synk_evaluation_query($conn,'INSERT INTO tbl_useraccount_faculty_links(user_id,faculty_id) VALUES (?,?)','ii',[$sefUsers['chair2'],$otherChair]);
    synk_evaluation_query($conn,"INSERT INTO tbl_program(college_id,program_code,program_name,status) VALUES (?,'TIED','Tied program','active')",'i',[$college]);$otherProgram=(int)$conn->insert_id;
    synk_evaluation_query($conn,"INSERT INTO tbl_faculty_college_appointments(college_id,ay_id,semester,appointment_role,program_id,faculty_id,status) VALUES (?,?,?,'chair',?,?,'active')",'iiiii',[$college,$ay,$semester,$otherProgram,$otherChair]);
    sef_qa_workload($conn,$otherProgram,$ay,$semester,$teachers[0],$sefUsers['dean']);
    if ($case==='chair_shared_done') $seedFinal('chair');else $seedDrafts('chair');
}
$role=strpos($case,'chair_')===0?'chair':'dean';$handler=$role==='dean'?'college-dean/supervisor-evaluations.php':'program-chair/supervisor-evaluations.php';
if (in_array($case,['chair_drafts','dean_private','chair_final'],true)) $seedDrafts('chair');
if ($case==='dean_final') $seedDrafts('dean');
if (in_array($case,['dean_results','chair_results','dean_report','chair_report','report_foreign','historical'],true)) {
    $seedFinal('chair');$seedFinal('dean');
    for ($index=0;$index<4;$index++) {
        if ($index<2) $student=array_values($students)[$index];
        else {
            synk_evaluation_query($conn,"INSERT INTO tbl_student_management (student_number,first_name,last_name,ay_id,semester,program_id) VALUES (?,'Report','QA',?,?,?)",'siii',[$tag.'-report'.$index,$ay,$semester,$program['program_id']]);$student=(int)$conn->insert_id;
        }
        $ratings=array_fill_keys(array_keys(synk_student_faculty_evaluation_flat_questions()),3);$keys=array_keys($ratings);
        foreach (array_slice($keys,0,$index===0?9:10) as $key) $ratings[$key]=4;
        $token=$tag.'student'.$index;
        synk_evaluation_query($conn,"INSERT INTO tbl_student_faculty_evaluations (student_id,faculty_id,college_id,ay_id,semester,faculty_name,instrument_version,total_score,question_count,average_rating,evaluation_token,submission_status,comment_text) VALUES (?,?,?,?,?,?,?, ?,15,?,?, 'submitted',?)",'iiiiissidss',[$student,$teachers[0],$college,$ay,$semester,'Evaluated Faculty',synk_student_faculty_evaluation_current_version(),array_sum($ratings),array_sum($ratings)/15,$token,'Student feedback <script>unsafe()</script>']);
        $evaluation=(int)$conn->insert_id;
        foreach (synk_student_faculty_evaluation_flat_questions() as $key=>$question) synk_evaluation_query($conn,'INSERT INTO tbl_student_faculty_evaluation_answers (evaluation_id,category_key,category_title,question_key,question_order,question_text,rating) VALUES (?,?,?,?,?,?,?)','isssisi',[$evaluation,$question['category_key'],$question['category_title'],$key,$question['question_order'],$question['question_text'],$ratings[$key]]);
    }
    $handler=strpos($case,'report')!==false?'college-dean/faculty-performance-report.php':'college-dean/faculty-performance.php';
    if (strpos($case,'chair_')===0) $handler=str_replace('college-dean/','program-chair/',$handler);
    if ($case==='historical') synk_evaluation_query($conn,'UPDATE tbl_academic_settings SET current_semester=?','i',[$semester===1?2:1]);
}
if (in_array($case,['chair_shared_draft','chair_shared_done'],true)) $role='chair2';
$sefSession($role);
synk_dean_select_view_term($conn,$ay,$semester);
$_GET=['qa'=>SYNK_STUDENT_PORTAL_QA,'scenario'=>$scenario,'dean_ay_id'=>$ay,'dean_semester'=>$semester,'faculty_id'=>$teachers[0]];
if ($case==='report_foreign') $_GET['faculty_id']=2147483647;
if (in_array($case,['chair_save','chair_bad_csrf','chair_self','chair_closed','chair_past'],true)) {
    $_POST=$base+['action'=>'save','faculty_id'=>$case==='chair_self'?$teachers[1]:$teachers[0],'assignment_revision'=>1,'version'=>0,'ratings'=>array_fill_keys(array_keys(synk_sef_questions()),4),'comment_text'=>'Actual HTTP draft'];
    if ($case==='chair_bad_csrf') $_POST['csrf_token']='bad';
    if ($case==='chair_closed') synk_evaluation_query($conn,'UPDATE tbl_supervisor_evaluation_settings SET is_open=0 WHERE college_id=?','i',[$college]);
    if ($case==='chair_past') { $_POST['semester']=$_GET['dean_semester']=$semester===1?2:1; }
}
if (in_array($case,['chair_final','dean_final'],true)) {
    $versions=[];$actor=synk_sef_actor($conn);$period=synk_dean_term_from_ids($conn,$ay,$semester);$rows=synk_sef_records($conn,$actor['scope'],$period,$actor);foreach ($case==='dean_final'?[$teachers[1]]:[$teachers[0],$deanFaculty] as $id) $versions[$id]=synk_sef_review_token($rows[$id]);
    $_POST=$base+['action'=>'finalize','confirm_final'=>'1','draft_versions'=>$versions];
}
register_shutdown_function(static function()use($conn,$college):void {
    $records=synk_evaluation_query($conn,'SELECT faculty_id,evaluator_role,submission_status,total_score,question_count FROM tbl_supervisor_evaluations WHERE college_id=? ORDER BY faculty_id','i',[$college]);
    header('X-QA-Supervisor-State: '.rawurlencode(json_encode($records)));
});
