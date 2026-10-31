<?php
if (!defined('SYNK_STUDENT_PORTAL_QA') || !isset($conn,$program,$students,$teachers)) { http_response_code(404); exit; }
require_once $root.'/backend/college_dean_evaluations_helper.php';
$case=substr($scenario,strlen('subject_exclusion_'));
$conn->requestMode=true;
$college=(int)$program['college_id']; $ay=(int)$term['ay_id']; $semester=(int)$term['semester'];
synk_evaluation_query($conn,'INSERT INTO tbl_college_evaluation_settings (college_id,ay_id,semester,student_open) VALUES (?,?,?,1)','iii',[$college,$ay,$semester]);
synk_evaluation_query($conn,"INSERT INTO tbl_useraccount (username,email,password,role,status,college_dean_college_id) VALUES (?,?,'unusable','college_dean','active',?)",'ssi',[$tag.' Dean','exclusion-'.$email,$college]);
$dean=(int)$conn->insert_id; synk_persist_useraccount_roles($conn,$dean,['college_dean'],'college_dean');
if ($case==='mixed') synk_evaluation_query($conn,'UPDATE tbl_student_management_enrolled_subjects SET faculty_id=? WHERE student_id=? AND ay_id=? AND semester=? AND is_active=1','iiii',[$teachers[0],$students['first'],$ay,$semester]);
if (!in_array($case,['student_normal','student_save','student_final'],true)) {
    $cards=synk_student_fetch_faculty_cards_for_term($conn,$students['first'],$ay,$semester,$college);
    $ids=[];
    foreach ($cards as $card) {
        $summary=implode('; ',array_map(static function(array $subject):string { return synk_evaluation_subject_label($subject); },$card['subjects']));
        synk_student_save_faculty_evaluation($conn,['student_id'=>$students['first'],'faculty_id'=>$card['faculty_id'],'college_id'=>$college,'ay_id'=>$ay,'semester'=>$semester,'faculty_name'=>$card['faculty_name'],'subject_summary'=>$summary,'comment_text'=>'Retained original feedback <script>private</script>'],array_fill_keys(array_keys(synk_student_faculty_evaluation_flat_questions()),4));
        $ids[]=$card['faculty_id'];
    }
    synk_student_finalize_faculty_evaluations_for_term($conn,$students['first'],$ay,$semester,$ids,['college_id'=>$college]);
}
if ($case!=='save') synk_evaluation_query($conn,"INSERT INTO tbl_college_evaluation_subject_exclusions (college_id,ay_id,semester,subject_id,excluded,reason,version,updated_by) VALUES (?,?,?,1,1,'PRIVATE_DEAN_REASON <script>private</script>',1,?)",'iiii',[$college,$ay,$semester,$dean]);
$subjectUiQuery=array_intersect_key($_GET,array_flip(['search','rule_filter']));
$_GET=['qa'=>SYNK_STUDENT_PORTAL_QA,'scenario'=>$scenario,'dean_ay_id'=>$ay,'dean_semester'=>$semester,'subject_id'=>1]+$subjectUiQuery;
$handler='college-dean/evaluation-subject-exclusions.php';
if (in_array($case,['student_normal','student_save','student_final'],true)) {
    $handler='student/class-program.php'; $_SESSION['student_evaluation_csrf']=$csrf;
    $_GET['evaluation_college_id']=$college;
    if ($case==='student_save') $_POST=['save_faculty_evaluation_draft'=>1,'csrf_token'=>$csrf,'evaluation_college_id'=>$college,'term_key'=>$ay.'-'.$semester,'faculty_key'=>'faculty-'.$teachers[0],'ratings'=>array_fill_keys(array_keys(synk_student_faculty_evaluation_flat_questions()),4),'comment_text'=>'Student still saves normally'];
    if ($case==='student_final') {
        foreach (synk_student_fetch_faculty_cards_for_term($conn,$students['first'],$ay,$semester,$college) as $card) {
            $summary=implode('; ',array_map(static function(array $subject):string { return synk_evaluation_subject_label($subject); },$card['subjects']));
            synk_student_save_faculty_evaluation($conn,['student_id'=>$students['first'],'faculty_id'=>$card['faculty_id'],'college_id'=>$college,'ay_id'=>$ay,'semester'=>$semester,'faculty_name'=>$card['faculty_name'],'subject_summary'=>$summary,'comment_text'=>'Student finalizes normally'],array_fill_keys(array_keys(synk_student_faculty_evaluation_flat_questions()),4));
        }
        $_POST=['submit_final_faculty_evaluation'=>1,'csrf_token'=>$csrf,'evaluation_college_id'=>$college,'term_key'=>$ay.'-'.$semester];
    }
} else {
    $_SESSION=['user_id'=>$dean,'role'=>'college_dean','username'=>$tag.' Dean','email'=>'exclusion-'.$email,'csrf_token'=>$csrf];
    if (in_array($case,['save','restore','bad_csrf','stale','foreign_subject'],true)) {
        $_POST=['csrf_token'=>$csrf,'college_id'=>$college,'ay_id'=>$ay,'semester'=>$semester,'subject_id'=>1,'excluded'=>$case==='restore'?0:1,'reason'=>'Reviewed faculty issue','version'=>$case==='save'?0:1];
        if ($case==='bad_csrf') $_POST['csrf_token']='wrong';
        if ($case==='stale') $_POST['version']=0;
        if ($case==='foreign_subject') $_POST['subject_id']=2147483647;
    }
    if ($case==='revoked') synk_evaluation_query($conn,"UPDATE tbl_useraccount SET status='inactive' WHERE user_id=?",'i',[$dean]);
    if ($case==='chair') $_SESSION['role']='program_chair';
    if ($case==='faculty_report') { $_GET['faculty_id']=$teachers[0]; $handler='college-dean/faculty-evaluations.php'; }
}
register_shutdown_function(static function()use($conn,$college,$students):void {
    $rules=synk_evaluation_query($conn,'SELECT subject_id,excluded,version FROM tbl_college_evaluation_subject_exclusions WHERE college_id=?','i',[$college]);
    $audit=synk_evaluation_query($conn,'SELECT subject_id FROM tbl_college_evaluation_subject_exclusion_audit WHERE college_id=?','i',[$college]);
    $responses=synk_evaluation_query($conn,'SELECT faculty_id,submission_status FROM tbl_student_faculty_evaluations WHERE student_id=?','i',[$students['first']]);
    header('X-QA-Subject-Exclusions: '.rawurlencode(json_encode(['rules'=>$rules,'audit'=>$audit,'responses'=>$responses])));
});
