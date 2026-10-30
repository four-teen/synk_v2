<?php
// Included only by the loopback/token guarded, rollback-only portal fixture.
require_once $root.'/backend/college_dean_evaluation_clear_helper.php';
$case=substr($scenario,strlen('dean_faculty_evaluations_clear_'));
$scope=synk_college_dean_dashboard_scope($conn);
$own=(int)$program['college_id'];$faculty=(int)$teachers[0];$foreign=(int)$foreignProgram['college_id'];
// Two explicit submitted owners; one unambiguous legacy draft owner.
$conn->query("UPDATE tbl_student_faculty_evaluations SET college_id={$own} WHERE faculty_id={$faculty} AND ay_id={$term['ay_id']} AND semester={$term['semester']}");
$conn->query("INSERT INTO tbl_student_management (student_number,email_address,first_name,last_name,ay_id,semester,program_id,year_level) SELECT CONCAT(student_number,'-third'),CONCAT('third-',email_address),'Third',last_name,ay_id,semester,program_id,year_level FROM tbl_student_management WHERE student_id={$students['first']}");
$third=(int)$conn->insert_id;$import($third,$term,'QA-CS101','Introduction to Computing',$faculty);
$seed($third,$faculty,$term,synk_student_faculty_evaluation_current_version(),'draft',4,'CLEAR_OWN_DRAFT');
// The same faculty, student and term in another college must survive.
$seed($students['first'],$faculty,$term,synk_student_faculty_evaluation_current_version(),'submitted',1,'CLEAR_FOREIGN_OWNER');
$conn->query("UPDATE tbl_student_faculty_evaluations SET college_id={$foreign} WHERE faculty_id={$faculty} AND comment_text='CLEAR_FOREIGN_OWNER'");
// Ambiguous legacy ownership is never guessed, even for this faculty.
$stmt=$conn->prepare("INSERT INTO tbl_student_management_enrolled_subjects (import_batch_key,campus_id,college_id,program_id,ay_id,semester,student_id,subject_id,subject_code,descriptive_title,faculty_id,is_active) VALUES (?,?,?,?,?,?,?,1,'FOREIGN-QA','Foreign subject',?,1)");
$stmt->bind_param('siiiiiii',$tag,$foreignProgram['campus_id'],$foreignProgram['college_id'],$foreignProgram['program_id'],$term['ay_id'],$term['semester'],$students['first'],$faculty);$stmt->execute();$stmt->close();
$seed($students['first'],$faculty,$term,synk_student_faculty_evaluation_current_version(),'draft',2,'CLEAR_AMBIGUOUS_LEGACY');
$conn->query("INSERT INTO tbl_college_evaluation_settings (college_id,ay_id,semester,student_open) VALUES ({$own},{$term['ay_id']},{$term['semester']},1)");
$period=$case==='historical'?$oldTerm:$term;$instrument=$case==='instrument'?'legacy-20':'all';
$plan=synk_dean_evaluation_clear_plan($conn,$scope,$period,$faculty,$instrument);
$allIds=array_column($conn->query('SELECT evaluation_id FROM tbl_student_faculty_evaluations WHERE faculty_id IN ('.implode(',',array_map('intval',[$faculty,$teachers[1],$foreignFaculty])).') ORDER BY evaluation_id')->fetch_all(MYSQLI_ASSOC),'evaluation_id');
$otherIds=array_values(array_diff($allIds,$plan['ids']));
$records=static function(array $ids)use($conn):array{
    $slots=implode(',',array_map('intval',$ids));
    return ['headers'=>$conn->query('SELECT * FROM tbl_student_faculty_evaluations WHERE evaluation_id IN ('.$slots.') ORDER BY evaluation_id')->fetch_all(MYSQLI_ASSOC),
        'answers'=>$conn->query('SELECT * FROM tbl_student_faculty_evaluation_answers WHERE evaluation_id IN ('.$slots.') ORDER BY answer_id')->fetch_all(MYSQLI_ASSOC)];
};
$otherBefore=$records($otherIds);
$targetBefore=$records($plan['ids']);
$controlBefore=$conn->query("SELECT * FROM tbl_college_evaluation_settings WHERE college_id={$own}")->fetch_all(MYSQLI_ASSOC);
$handler='college-dean/evaluation-clear.php';
$_POST=['action'=>'preview','csrf_token'=>$csrf,'faculty_id'=>(string)$faculty,'ay_id'=>(string)$period['ay_id'],'semester'=>(string)$period['semester'],'instrument_version'=>$instrument];
if (!in_array($case,['preview','get','csrf','role','faculty_zero','faculty_array','foreign','college','campus','bad_term','bad_instrument','bulk'],true)) {
    $preview=synk_dean_evaluation_clear_preview($conn,$scope,$period,$faculty,$instrument);
    $previewToken=$preview['preview_token'];
    $_POST=['action'=>'confirm','csrf_token'=>$csrf,'preview_token'=>$previewToken];
}
if($case==='get')$_POST=[];
if($case==='csrf')$_POST['csrf_token']='invalid';
if($case==='role')$_SESSION['role']='program_chair';
if($case==='faculty_zero')$_POST['faculty_id']='0';
if($case==='faculty_array')$_POST['faculty_id']=[$faculty,$teachers[1]];
if($case==='foreign')$_POST['faculty_id']=(string)$foreignFaculty;
if($case==='college')$_POST['college_id']=(string)$foreign;
if($case==='campus')$_POST['campus_id']='2147483647';
if($case==='bad_term')$_POST['semester']='4';
if($case==='bad_instrument')$_POST['instrument_version']=['all'];
if($case==='bulk')$_POST['action']='clear_all';
if($case==='expired')$_SESSION['dean_evaluation_clear_previews'][$previewToken]['expires_at']=time()-1;
if($case==='unknown')$_POST['preview_token']=str_repeat('0',64);
if($case==='malformed')$_POST['preview_token']=['all'];
if($case==='actor')$_SESSION['dean_evaluation_clear_previews'][$previewToken]['user_id']=$adminId;
if($case==='reassigned')$conn->query("UPDATE tbl_useraccount SET college_dean_college_id={$foreign} WHERE user_id={$deanId}");
if($case==='revoked')$conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$deanId} AND role='college_dean'");
if($case==='stale')$conn->query('UPDATE tbl_student_faculty_evaluations SET comment_text=CONCAT(comment_text,\' Changed\') WHERE evaluation_id='.(int)$plan['ids'][0]);
if($case==='stale_answer')$conn->query('UPDATE tbl_student_faculty_evaluation_answers SET rating=1 WHERE evaluation_id='.(int)$plan['ids'][0]);
if($case==='failure_history')$conn->failEvaluationClear='history';
if($case==='failure_headers')$conn->failEvaluationClear='headers';
if($case==='replay'){
    $conn->requestMode=true;
    synk_dean_evaluation_clear_save($conn,$scope,$previewToken);
}
// Read-only browser fixture: retain seeded counts, use the real page and mock transport.
if($case==='browser'){$handler='college-dean/faculty-evaluations.php';$_POST=[];unset($_GET['faculty_id']);}
$targetBeforeRequest=$records($plan['ids']);
register_shutdown_function(static function()use($conn,$own,$faculty,$plan,$targetBefore,$targetBeforeRequest,$otherIds,$otherBefore,$records,$controlBefore):void{
    $rows=$conn->query("SELECT * FROM tbl_college_faculty_evaluation_clear_history WHERE college_id={$own} AND faculty_id={$faculty}")->fetch_all(MYSQLI_ASSOC);
    $history=$rows?json_decode($rows[0]['evaluation_snapshot'],true):null;
    $state=['remaining'=>count($records($plan['ids'])['headers']),'answers'=>count($records($plan['ids'])['answers']),
        'target_unchanged'=>$records($plan['ids'])===$targetBeforeRequest,'other_unchanged'=>$records($otherIds)===$otherBefore,
        'controls_unchanged'=>$conn->query("SELECT * FROM tbl_college_evaluation_settings WHERE college_id={$own}")->fetch_all(MYSQLI_ASSOC)===$controlBefore,
        'history_count'=>count($rows),'archived_exactly'=>$history && $history['evaluations']==$targetBefore['headers'] && $history['answers']==$targetBefore['answers'],
        'submitted'=>$rows?(int)$rows[0]['submitted_count']:0,'drafts'=>$rows?(int)$rows[0]['draft_count']:0,
        'actor_correct'=>$rows && (int)$rows[0]['actor_user_id']===(int)($_SESSION['user_id']??0),
        'token_consumed'=>empty($_SESSION['dean_evaluation_clear_previews'])];
    header('X-QA-Clear-State: '.rawurlencode(json_encode($state)));
});
