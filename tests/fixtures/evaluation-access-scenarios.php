<?php
// Included only by the loopback/token protected, rollback-only portal fixture.
if (!defined('SYNK_STUDENT_PORTAL_QA') || !isset($students,$teachers,$program,$conn)) { http_response_code(404); exit; }
require_once $root.'/backend/professor_evaluations_helper.php';
$conn->requestMode=true;
$case=substr($scenario,strlen('evaluation_access_'));
$otherProgram=$conn->query('SELECT p.program_id,c.college_id,c.campus_id FROM tbl_program p JOIN tbl_college c ON c.college_id=p.college_id JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.status=\'active\' AND cp.status=\'active\' AND c.college_id<>'.(int)$program['college_id'].' LIMIT 1')->fetch_assoc();
if (!$otherProgram) throw new RuntimeException('Two active colleges are required for evaluation access tests.');
$collegeA=(int)$program['college_id']; $collegeB=(int)$otherProgram['college_id'];
foreach ([$collegeA,$collegeB] as $college) foreach ([$term,$oldTerm] as $period) {
    $conn->query("INSERT INTO tbl_college_evaluation_settings (college_id,ay_id,semester) VALUES ({$college},{$period['ay_id']},{$period['semester']}) ON DUPLICATE KEY UPDATE student_open=0,faculty_released=0,version=0");
}
$seedAccess=static function(int $college,int $faculty,array $period,string $status,int $rating,string $comment,?string $version=null)use($conn,&$students,$tag):void {
    $token=$tag.'-'.bin2hex(random_bytes(5)); $version=$version ?? synk_student_faculty_evaluation_current_version(); $questions=synk_student_faculty_evaluation_flat_questions($version);
    $count=count($questions); $points=$count*$rating; $mean=(float)$rating; $final=$status==='submitted'?$token:''; $facultyName='Saved faculty';
    $params=[$students['first'],$faculty,$college,$period['ay_id'],$period['semester'],$facultyName,$version,$count,$points,$mean,$token,$status,$final,$comment];
    synk_evaluation_query($conn,'INSERT INTO tbl_student_faculty_evaluations (student_id,faculty_id,college_id,ay_id,semester,faculty_name,instrument_version,question_count,total_score,average_rating,evaluation_token,submission_status,final_submission_token,comment_text) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)','iiiiissiidssss',$params);
    $evaluationId=(int)$conn->insert_id;
    foreach ($questions as $question) synk_evaluation_query($conn,'INSERT INTO tbl_student_faculty_evaluation_answers (evaluation_id,category_key,category_title,question_key,question_order,question_text,rating) VALUES (?,?,?,?,?,?,?)','isssisi',[$evaluationId,$question['category_key'],$question['category_title'],$question['question_key'],$question['question_order'],$question['question_text'],$rating]);
};
$_SESSION['student_evaluation_csrf']=$csrf;
$handler='student/class-program.php';
$_GET['evaluation_college_id']=(string)$collegeA;
if (in_array($case,['student_open','student_open_post','student_bad_csrf','student_shared_final','student_partial_final'],true)) $conn->query("UPDATE tbl_college_evaluation_settings SET student_open=1 WHERE college_id={$collegeA} AND ay_id={$term['ay_id']} AND semester={$term['semester']}");
if (in_array($case,['student_closed_final','student_shared_final'],true)) {
    foreach ($teachers as $teacher) $seedAccess($collegeA,$teacher,$term,'draft',4,'SAVED_DRAFT_A');
}
if ($case==='student_shared_final') {
    synk_evaluation_query($conn,"INSERT INTO tbl_student_management_enrolled_subjects (import_batch_key,campus_id,college_id,program_id,ay_id,semester,student_id,subject_id,subject_code,faculty_id,is_active) VALUES (?,?,?,?,?,?,?,1,'SHARED-QA',?,1)",'siiiiiii',[$tag,$otherProgram['campus_id'],$collegeB,$otherProgram['program_id'],$term['ay_id'],$term['semester'],$students['first'],$teachers[0]]);
    $seedAccess($collegeB,$teachers[0],$term,'draft',1,'HIDDEN_B_DRAFT');
}
if (in_array($case,['student_closed_post','student_open_post','student_bad_csrf','student_historical_post'],true)) {
    $_POST=['save_faculty_evaluation_draft'=>'1','csrf_token'=>$case==='student_bad_csrf'?'wrong':$csrf,'evaluation_college_id'=>(string)$collegeA,'term_key'=>$term['ay_id'].'-'.$term['semester'],'faculty_key'=>'faculty-'.$teachers[0],'ratings'=>array_fill_keys(array_keys(synk_student_faculty_evaluation_flat_questions()),4),'comment_text'=>'HTTP_DRAFT_SAVED'];
}
if (in_array($case,['student_historical_post','student_historical','student_archived_without_roster'],true)) {
    $_GET['term_key']=$oldTerm['ay_id'].'-'.$oldTerm['semester'];
    $conn->query("UPDATE tbl_college_evaluation_settings SET student_open=1 WHERE college_id={$collegeA} AND ay_id={$oldTerm['ay_id']} AND semester={$oldTerm['semester']}");
    if ($case==='student_historical_post') $_POST['term_key']=$_GET['term_key'];
    else {
        $seedAccess($collegeA,$teachers[0],$oldTerm,'submitted',5,'ARCHIVED_SUBMITTED_RESPONSE');
        $conn->query('UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE student_id='.(int)$students['first'].' AND ay_id='.(int)$oldTerm['ay_id'].' AND semester='.(int)$oldTerm['semester']);
        if ($case==='student_archived_without_roster') $conn->query('DELETE FROM tbl_student_management_enrolled_subjects WHERE student_id='.(int)$students['first'].' AND ay_id='.(int)$oldTerm['ay_id'].' AND semester='.(int)$oldTerm['semester']);
    }
}
if (in_array($case,['student_closed_final','student_shared_final'],true)) $_POST=['submit_final_faculty_evaluation'=>'1','csrf_token'=>$csrf,'evaluation_college_id'=>(string)$collegeA,'term_key'=>$term['ay_id'].'-'.$term['semester']];
if (strpos($case,'dean_')===0 || strpos($case,'chair_')===0) {
    $role=strpos($case,'chair_')===0?'program_chair':'college_dean';
    $conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_id,college_dean_college_id) VALUES ('Evaluation Access QA','dean-access-{$email}','unusable','{$role}','active',{$collegeA},{$collegeA})");
    $deanAccessId=(int)$conn->insert_id; synk_persist_useraccount_roles($conn,$deanAccessId,[$role],$role);
    $_SESSION=['user_id'=>$deanAccessId,'role'=>$role,'email'=>'dean-access-'.$email,'username'=>'Evaluation Access QA','csrf_token'=>$csrf];
    $_GET=['qa'=>SYNK_STUDENT_PORTAL_QA,'scenario'=>$scenario,'dean_ay_id'=>(string)$term['ay_id'],'dean_semester'=>(string)$term['semester']];
    $handler=$role==='program_chair'?'program-chair/faculty-evaluations.php':'college-dean/faculty-evaluations.php';
    if (!in_array($case,['dean_get','chair_get'],true)) {
        $_POST=['save_evaluation_control'=>'1','csrf_token'=>$csrf,'college_id'=>(string)$collegeA,'ay_id'=>(string)$term['ay_id'],'semester'=>(string)$term['semester'],'control'=>'student_open','value'=>'1','version'=>'0'];
        if ($case==='dean_bad_csrf') $_POST['csrf_token']='wrong';
        if ($case==='dean_foreign') $_POST['college_id']=(string)$collegeB;
        if ($case==='dean_changed_scope') $conn->query("UPDATE tbl_useraccount SET college_dean_college_id={$collegeB} WHERE user_id={$deanAccessId}");
        if ($case==='dean_stale') $_POST['version']='2';
        if ($case==='dean_hide') {
            $conn->query("UPDATE tbl_college_evaluation_settings SET faculty_released=1 WHERE college_id={$collegeA} AND ay_id={$term['ay_id']} AND semester={$term['semester']}");
            $_POST['control']='faculty_released'; $_POST['value']='0';
        }
        if (in_array($case,['dean_open_past','dean_release_past'],true)) {
            $_POST['ay_id']=$_GET['dean_ay_id']=(string)$oldTerm['ay_id']; $_POST['semester']=$_GET['dean_semester']=(string)$oldTerm['semester'];
            if ($case==='dean_release_past') $_POST['control']='faculty_released';
        }
    }
}
if (strpos($case,'professor_')===0) {
    $conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status) VALUES ('Faculty Access QA','faculty-access-{$email}','unusable','professor','active')");
    $professorId=(int)$conn->insert_id; synk_persist_useraccount_roles($conn,$professorId,['professor'],'professor');
    synk_evaluation_query($conn,'INSERT INTO tbl_useraccount_faculty_links (user_id,faculty_id) VALUES (?,?)','ii',[$professorId,$teachers[0]]);
    $_SESSION=['user_id'=>$professorId,'role'=>'professor','email'=>'faculty-access-'.$email,'username'=>'Faculty Access QA'];
    $_GET=['qa'=>SYNK_STUDENT_PORTAL_QA,'scenario'=>$scenario]; $handler='professor/faculty-evaluations.php';
    $seedAccess($collegeA,$teachers[0],$term,'submitted',4,'RELEASED_A_FEEDBACK <script>bad()</script>');
    $seedAccess($collegeB,$teachers[0],$term,'submitted',1,'HIDDEN_B_FEEDBACK');
    $seedAccess($collegeA,$teachers[1],$term,'submitted',5,'OTHER_FACULTY_FEEDBACK');
    // A different student draft for the same professor must never enter the result.
    $savedFirst=$students['first']; $students['first']=$students['other'];
    if ($case==='professor_mixed') $seedAccess($collegeA,$teachers[0],$term,'submitted',3,'LEGACY_RELEASED_FEEDBACK','legacy-20');
    else $seedAccess($collegeA,$teachers[0],$term,'draft',1,'PRIVATE_DRAFT_COMMENT');
    $students['first']=$savedFirst;
    if ($case!=='professor_hidden') $conn->query("UPDATE tbl_college_evaluation_settings SET faculty_released=1 WHERE college_id={$collegeA} AND ay_id={$term['ay_id']} AND semester={$term['semester']}");
    if ($case==='professor_foreign_faculty') $_GET['faculty_id']=(string)$teachers[1];
    if ($case==='professor_ambiguous_legacy') {
        $conn->query('UPDATE tbl_student_faculty_evaluations SET college_id=0 WHERE student_id='.(int)$students['first'].' AND faculty_id='.(int)$teachers[0].' AND college_id='.$collegeA);
        synk_evaluation_query($conn,"INSERT INTO tbl_student_management_enrolled_subjects (import_batch_key,campus_id,college_id,program_id,ay_id,semester,student_id,subject_id,subject_code,faculty_id,is_active) VALUES (?,?,?,?,?,?,?,1,'AMBIGUOUS-QA',?,1)",'siiiiiii',[$tag,$otherProgram['campus_id'],$collegeB,$otherProgram['program_id'],$term['ay_id'],$term['semester'],$students['first'],$teachers[0]]);
    }
    if (in_array($case,['professor_hidden_direct','professor_rehidden'],true)) {
        $_GET['college_id']=(string)($case==='professor_rehidden'?$collegeA:$collegeB); $_GET['ay_id']=(string)$term['ay_id']; $_GET['semester']=(string)$term['semester'];
        if ($case==='professor_rehidden') $conn->query("UPDATE tbl_college_evaluation_settings SET faculty_released=0 WHERE college_id={$collegeA}");
    }
    if ($case==='professor_historical') {
        $seedAccess($collegeA,$teachers[0],$oldTerm,'submitted',5,'ARCHIVED_RELEASED_FEEDBACK');
        $conn->query("UPDATE tbl_college_evaluation_settings SET faculty_released=0 WHERE college_id={$collegeA}");
        $conn->query("UPDATE tbl_college_evaluation_settings SET faculty_released=1 WHERE college_id={$collegeA} AND ay_id={$oldTerm['ay_id']} AND semester={$oldTerm['semester']}");
    }
    if ($case==='professor_revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$professorId}");
    if ($case==='professor_inactive') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$professorId}");
    if ($case==='professor_unlinked') $conn->query("DELETE FROM tbl_useraccount_faculty_links WHERE user_id={$professorId}");
}
if (strpos($case,'student_nav_')===0 || strpos($case,'preview_nav_')===0) {
    $handler='student/index.php';
    if (strpos($case,'_profile_')!==false) $handler='student/profile.php';
    if (strpos($case,'_prospectus_')!==false) $handler='student/prospectus.php';
    if (in_array($case,['student_nav_open','preview_nav_open','preview_nav_admin_open','student_nav_inactive','student_nav_unlinked'],true)) {
        $conn->query("UPDATE tbl_college_evaluation_settings SET student_open=1 WHERE college_id={$collegeA} AND ay_id={$term['ay_id']} AND semester={$term['semester']}");
    }
    if ($case==='student_nav_faculty_only') $conn->query("UPDATE tbl_college_evaluation_settings SET faculty_released=1 WHERE college_id={$collegeA} AND ay_id={$term['ay_id']} AND semester={$term['semester']}");
    if ($case==='student_nav_past_only') $conn->query("UPDATE tbl_college_evaluation_settings SET student_open=1 WHERE college_id={$collegeA} AND ay_id={$oldTerm['ay_id']} AND semester={$oldTerm['semester']}");
    if (in_array($case,['student_nav_other_college','student_nav_shared_open','preview_nav_foreign_open'],true)) {
        $conn->query("UPDATE tbl_college_evaluation_settings SET student_open=1 WHERE college_id={$collegeB} AND ay_id={$term['ay_id']} AND semester={$term['semester']}");
        if ($case!=='student_nav_other_college') {
            synk_evaluation_query($conn,"INSERT INTO tbl_student_management_enrolled_subjects (import_batch_key,campus_id,college_id,program_id,ay_id,semester,student_id,subject_id,subject_code,faculty_id,is_active) VALUES (?,?,?,?,?,?,?,1,'SHARED-NAV',?,1)",'siiiiiii',[$tag,$otherProgram['campus_id'],$collegeB,$otherProgram['program_id'],$term['ay_id'],$term['semester'],$students['first'],$teachers[0]]);
        }
    }
    if ($case==='student_nav_inactive') $conn->query('UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE student_id='.(int)$students['first']);
    if ($case==='student_nav_unlinked') $conn->query('UPDATE tbl_student_management_enrolled_subjects SET faculty_id=0 WHERE student_id='.(int)$students['first']);
    if (strpos($case,'preview_nav_')===0) {
        if (strpos($case,'_admin_')!==false) {
            $_SESSION=['user_id'=>$adminId,'role'=>'admin','email'=>'admin-'.$email,'username'=>'Portal QA Admin','csrf_token'=>$csrf];
        } else {
            $conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_dean_college_id) VALUES ('Navigation QA Dean','nav-dean-{$email}','unusable','college_dean','active',{$collegeA})");
            $navDeanId=(int)$conn->insert_id; synk_persist_useraccount_roles($conn,$navDeanId,['college_dean'],'college_dean');
            $_SESSION=['user_id'=>$navDeanId,'role'=>'college_dean','email'=>'nav-dean-'.$email,'username'=>'Navigation QA Dean','csrf_token'=>$csrf];
        }
        $_GET['preview_student_id']=(string)$students['first'];
        $_GET['student_term']=$term['ay_id'].':'.$term['semester'];
        $_GET['return_to']=($_SESSION['role']==='college_dean'?'../college-dean/students.php':'../administrator/students/directory.php');
    }
}
