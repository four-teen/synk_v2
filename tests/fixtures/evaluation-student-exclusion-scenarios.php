<?php
if (!defined('SYNK_STUDENT_PORTAL_QA') || !isset($conn,$program,$students,$teachers)) { http_response_code(404); exit; }
require_once $root.'/backend/college_dean_evaluations_helper.php';
$case=substr($scenario,strlen('student_exclusion_'));
$conn->requestMode=true;
$college=(int)$program['college_id']; $ay=(int)$term['ay_id']; $semester=(int)$term['semester'];
synk_evaluation_query($conn,'INSERT INTO tbl_college_evaluation_settings (college_id,ay_id,semester,student_open) VALUES (?,?,?,1)','iii',[$college,$ay,$semester]);
synk_evaluation_query($conn,"INSERT INTO tbl_useraccount (username,email,password,role,status,college_dean_college_id) VALUES (?,?,'unusable','college_dean','active',?)",'ssi',[$tag.' Dean','exclusion-'.$email,$college]);
$dean=(int)$conn->insert_id; synk_persist_useraccount_roles($conn,$dean,['college_dean'],'college_dean');
if ($case!=='save') synk_evaluation_query($conn,"INSERT INTO tbl_college_evaluation_student_exclusions (college_id,ay_id,semester,student_id,excluded,reason,version,updated_by) VALUES (?,?,?,?,1,'PRIVATE_STUDENT_REASON <script>private</script>',1,?)",'iiiii',[$college,$ay,$semester,$students['first'],$dean]);
$saveAll=static function()use($conn,$students,$ay,$semester,$college):array {
    $ids=[];
    foreach (synk_student_fetch_faculty_cards_for_term($conn,$students['first'],$ay,$semester,$college) as $card) {
        $summary=implode('; ',array_map('synk_evaluation_subject_label',$card['subjects']));
        synk_student_save_faculty_evaluation($conn,['student_id'=>$students['first'],'faculty_id'=>$card['faculty_id'],'college_id'=>$college,'ay_id'=>$ay,'semester'=>$semester,'faculty_name'=>$card['faculty_name'],'subject_summary'=>$summary,'comment_text'=>'Preserved original student feedback'],array_fill_keys(array_keys(synk_student_faculty_evaluation_flat_questions()),4));
        $ids[]=$card['faculty_id'];
    }
    return $ids;
};
if (!in_array($case,['student_normal','student_save','student_final'],true)) synk_student_finalize_faculty_evaluations_for_term($conn,$students['first'],$ay,$semester,$saveAll(),['college_id'=>$college]);
$studentUiQuery=array_intersect_key($_GET,array_flip(['search','rule_filter','saved']));
$_GET=['qa'=>SYNK_STUDENT_PORTAL_QA,'scenario'=>$scenario,'dean_ay_id'=>$ay,'dean_semester'=>$semester]+$studentUiQuery;
if ($case!=='list') $_GET['student_id']=$students['first'];
$handler='college-dean/evaluation-student-exclusions.php';
if (in_array($case,['student_normal','student_save','student_final'],true)) {
    $handler='student/class-program.php'; $_SESSION['student_evaluation_csrf']=$csrf; $_GET['evaluation_college_id']=$college;
    if ($case==='student_save') $_POST=['save_faculty_evaluation_draft'=>1,'csrf_token'=>$csrf,'evaluation_college_id'=>$college,'term_key'=>$ay.'-'.$semester,'faculty_key'=>'faculty-'.$teachers[0],'ratings'=>array_fill_keys(array_keys(synk_student_faculty_evaluation_flat_questions()),4),'comment_text'=>'Excluded student saves normally'];
    if ($case==='student_final') { $saveAll(); $_POST=['submit_final_faculty_evaluation'=>1,'csrf_token'=>$csrf,'evaluation_college_id'=>$college,'term_key'=>$ay.'-'.$semester]; }
} else {
    $_SESSION=['user_id'=>$dean,'role'=>'college_dean','username'=>$tag.' Dean','email'=>'exclusion-'.$email,'csrf_token'=>$csrf];
    if (in_array($case,['save','restore','bad_csrf','stale','foreign_student','empty_reason','invalid_action','foreign_college','foreign_term'],true)) {
        $_POST=['csrf_token'=>$csrf,'college_id'=>$college,'ay_id'=>$ay,'semester'=>$semester,'student_id'=>$students['first'],'excluded'=>$case==='restore'?0:1,'reason'=>'Reviewed student eligibility','version'=>$case==='save'?0:1];
        if ($case==='bad_csrf') $_POST['csrf_token']='wrong';
        if ($case==='stale') $_POST['version']=0;
        if ($case==='foreign_student') $_POST['student_id']=2147483647;
        if ($case==='empty_reason') $_POST['reason']='  ';
        if ($case==='invalid_action') $_POST['excluded']=2;
        if ($case==='foreign_college') $_POST['college_id']=2147483647;
        if ($case==='foreign_term') { $_POST['ay_id']=$oldTerm['ay_id']; $_POST['semester']=$oldTerm['semester']; $_POST['student_id']=$students['other']; $_POST['version']=0; }
    }
    if ($case==='revoked') synk_evaluation_query($conn,"UPDATE tbl_useraccount SET status='inactive' WHERE user_id=?",'i',[$dean]);
    if ($case==='chair') $_SESSION['role']='program_chair';
    if ($case==='faculty_report') { $_GET['faculty_id']=$teachers[0]; $handler='college-dean/faculty-evaluations.php'; }
}
register_shutdown_function(static function()use($conn,$college,$students,$ay,$semester):void {
    $rules=synk_evaluation_query($conn,'SELECT student_id,excluded,version FROM tbl_college_evaluation_student_exclusions WHERE college_id=?','i',[$college]);
    $audit=synk_evaluation_query($conn,'SELECT student_id FROM tbl_college_evaluation_student_exclusion_audit WHERE college_id=?','i',[$college]);
    $responses=synk_evaluation_query($conn,'SELECT evaluation_id,faculty_id,submission_status FROM tbl_student_faculty_evaluations WHERE student_id=?','i',[$students['first']]);
    $states=synk_evaluation_score_states($conn,$ay,$semester);
    foreach ($responses as &$response) $response['score_state']=$states[(int)$response['evaluation_id']]['state'] ?? 'included';
    header('X-QA-Student-Exclusions: '.rawurlencode(json_encode(['rules'=>$rules,'audit'=>$audit,'responses'=>$responses])));
});
