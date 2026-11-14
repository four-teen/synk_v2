<?php
// Published editing, original attempts, and reopening. Every fixture is rolled back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/student_assessment_helper_lms.php';
require_once __DIR__.'/../backend/professor_class_helper_lms.php';
require_once __DIR__.'/fixtures/professor-workload-students-data.php';
$lms=synk_database_connection_lms($conn);$checks=0;$session=$_SESSION??[];
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$denied=static function(callable $fn,int $code)use($check):void{try{$fn();$check(false,'An invalid edit succeeded.');}catch(DomainException $error){$check($error->getCode()===$code,$error->getMessage());}};
$coreTables='tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_faculty,tbl_class_schedule,tbl_faculty_workload_sched,tbl_academic_settings,tbl_student_management,tbl_student_management_enrolled_subjects,tbl_student_portal_sessions';
$lmsTables='tbl_lms_assessments,tbl_lms_assessment_questions,tbl_lms_assessment_logs,tbl_lms_assessment_attempts';
$coreBefore=$conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC);
$lmsBefore=$lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC);
$conn->begin_transaction();$lms->begin_transaction();
try {
    $f=professor_students_fixtures($conn,true);$actor=$f['user_id'];
    $context=['portal'=>['faculty_id'=>$f['faculty_id']],'class'=>array_replace($f['offerings'][0],['ay_id'=>$f['term']['ay_id'],'semester'=>$f['term']['semester']])];
    $sid=$f['students']['active'];$second=$f['students']['inactive'];$third=$f['students']['failed'];
    $sample=['grading_period'=>'midterm','assessment_type'=>'quiz','title'=>'Original quiz','instructions'=>'Original instructions.',
        'time_limit_minutes'=>30,'questions'=>[
            ['question_type'=>'multiple_choice','prompt'=>'Original choice question','points'=>2,'options'=>['3','4'],'answer_key'=>1],
            ['question_type'=>'true_false','prompt'=>'Original statement','points'=>1,'answer_key'=>false],
            ['question_type'=>'short_answer','prompt'=>'Original short answer','points'=>1,'answer_key'=>['PHP']],
            ['question_type'=>'essay','prompt'=>'Original essay','points'=>5]
        ]];
    $published=synk_assessment_publish_lms($lms,$context,$sample,$actor);$id=$published['assessment_id'];
    $start=synk_student_assessment_action_lms($conn,$lms,$sid,'start',['assessment_id'=>$id]);
    $q=$start['assessment']['questions'];$answers=[$q[0]['question_id']=>1,$q[1]['question_id']=>false,$q[2]['question_id']=>'PHP',$q[3]['question_id']=>'Original essay response'];
    $saved=synk_student_assessment_action_lms($conn,$lms,$sid,'save',['assessment_id'=>$id,'version'=>1,'answers'=>$answers]);
    $other=synk_student_assessment_action_lms($conn,$lms,$second,'start',['assessment_id'=>$id]);
    $submitted=synk_student_assessment_action_lms($conn,$lms,$second,'submit',['assessment_id'=>$id,'version'=>1,'answers'=>$answers]);
    // Simulate attempts created before the upgrade, including a completed attempt.
    synk_assessment_query_lms($lms,'UPDATE tbl_lms_assessment_attempts SET assessment_snapshot_json=NULL WHERE assessment_id=?','i',[$id]);
    $edit=array_replace($sample,['assessment_id'=>$id,'version'=>1,'grading_period'=>'final','assessment_type'=>'exam',
        'title'=>'Corrected exam','instructions'=>'Corrected instructions.','opens_at'=>'2099-01-01T08:00','due_at'=>'2099-01-01T10:00','time_limit_minutes'=>10,
        'questions'=>[
            ['question_type'=>'essay','prompt'=>'New essay first','points'=>3],
            ['question_type'=>'multiple_choice','prompt'=>'Corrected question','points'=>2,'options'=>['4','5','6'],'answer_key'=>0]
        ]]);
    $updated=synk_assessment_save_lms($lms,$context,$edit,$actor);
    $check($updated['status']==='published'&&$updated['version']===2&&$updated['opens_at']==='2099-01-01 08:00:00','Published edits must save dates and keep the assessment published.');
    $check(count($updated['questions'])===2&&$updated['questions'][0]['question_type']==='essay'&&$updated['questions'][1]['answer_key']===0,'Question additions, removals, types, order and answer keys must save.');
    $snapshots=synk_assessment_query_lms($lms,'SELECT assessment_snapshot_json FROM tbl_lms_assessment_attempts WHERE assessment_id=?','i',[$id]);
    $check(count($snapshots)===2&&count(array_filter(array_column($snapshots,'assessment_snapshot_json')))===2,'Every pre-upgrade attempt must be preserved before the first published edit.');
    $original=synk_student_assessment_action_lms($conn,$lms,$sid,'detail',['assessment_id'=>$id]);
    $check($original['assessment']===$start['assessment']&&$original['attempt']['answers']===$answers,'Started attempts must retain their exact original content, question IDs and answers.');
    $check($original['attempt']['deadline_at']===$start['attempt']['deadline_at']&&$original['attempt']['version']===2,'Editing must not reset a timer or invalidate unsaved responses in an existing tab.');
    $check(strpos(json_encode($original),'answer_key')===false&&strpos(json_encode($original),'assessment_snapshot_json')===false,'Original-version answer keys and snapshots must never reach student responses.');
    $completed=synk_student_assessment_action_lms($conn,$lms,$second,'detail',['assessment_id'=>$id]);
    $check($completed['result']===$submitted['result']&&$completed['attempt']['answers']===$answers,'Submitted answers and automatic results must stay on the original answer keys.');
    $listed=array_column(synk_student_assessment_list_lms($conn,$lms,$second),null,'assessment_id');
    $check($listed[$id]['question_count']===4,'A started assessment card retains its original item count after questions are edited.');
    $check($listed[$id]['title']==='Original quiz'&&$listed[$id]['grading_period']==='midterm'&&$listed[$id]['result']===$submitted['result'],'Student list cards must use the attempt version, including its grading period and result.');
    $check(strpos(json_encode($listed),'answer_key')===false&&strpos(json_encode($listed),'assessment_snapshot_json')===false,'List cards must not leak snapshot questions or answer keys.');
    $review=synk_assessment_submissions_lms($conn,$lms,$context,$id);
    $check(count($review)===2&&$review[0]['assessment']['title']==='Original quiz'&&$review[0]['assessment']['questions'][0]['question_id']===$q[0]['question_id'],'Professor review must pair old answers with original question text, type and choices.');
    $fresh=synk_student_assessment_action_lms($conn,$lms,$third,'detail',['assessment_id'=>$id]);
    $check($fresh['assessment']['title']==='Corrected exam'&&$fresh['assessment']['questions']===[],'Students who have not started must receive the edited title and settings without question disclosure.');
    $denied(static function()use($conn,$lms,$third,$id){synk_student_assessment_action_lms($conn,$lms,$third,'start',['assessment_id'=>$id]);},409);
    $denied(static function()use($lms,$context,$edit,$actor){synk_assessment_save_lms($lms,$context,$edit,$actor);},409);
    $foreign=$context;$foreign['portal']['faculty_id']++;
    $denied(static function()use($lms,$foreign,$edit,$actor){synk_assessment_save_lms($lms,$foreign,array_replace($edit,['version'=>2]),$actor);},404);
    $denied(static function()use($lms,$context,$edit,$actor){synk_assessment_save_lms($lms,$context,array_replace($edit,['version'=>2,'questions'=>[]]),$actor);},422);
    $edit['version']=2;$edit['opens_at']=null;$edit['due_at']=null;
    $updated=synk_assessment_save_lms($lms,$context,$edit,$actor);
    $newStart=synk_student_assessment_action_lms($conn,$lms,$third,'start',['assessment_id'=>$id]);
    $check(array_column($newStart['assessment']['questions'],'question_id')===array_column($updated['questions'],'question_id')&&strpos(json_encode($newStart),'answer_key')===false,'New attempts must receive the corrected question set without private answer keys.');
    $check($newStart['assessment']['questions'][0]['prompt']==='New essay first'&&$newStart['assessment']['assessment_type']==='exam'&&$newStart['assessment']['time_limit_minutes']===10,'New attempts must use the current type, order, points and time limit.');
    // The old browser payload still saves/submits with its original question IDs and revision.
    $done=synk_student_assessment_action_lms($conn,$lms,$sid,'submit',['assessment_id'=>$id,'version'=>2,'answers'=>$answers]);
    $check($done['result']===$submitted['result']&&$done['attempt']['status']==='submitted','An attempt started before editing must still submit and score using its original keys.');
    $closed=synk_assessment_close_lms($lms,$context,['assessment_id'=>$id,'version'=>3],$actor);
    $check($closed['status']==='closed','The edited assessment can still be closed.');
    $closedEdit=array_replace($edit,['version'=>4,'assessment_type'=>'assignment','instructions'=>'New written task','total_points'=>50,'questions'=>[]]);
    $closedSaved=synk_assessment_save_lms($lms,$context,$closedEdit,$actor);
    $check($closedSaved['status']==='closed'&&$closedSaved['version']===5,'Saving a closed assessment must keep it closed.');
    $newOriginal=synk_student_assessment_action_lms($conn,$lms,$third,'detail',['assessment_id'=>$id]);
    $check($newOriginal['assessment']['assessment_type']==='exam'&&count($newOriginal['assessment']['questions'])===2&&$newOriginal['attempt']['status']==='expired','Later edits must preserve the second revision and closing must retain its saved work.');
    $reopened=synk_assessment_publish_lms($lms,$context,array_replace($closedEdit,['version'=>5]),$actor);
    $check($reopened['status']==='published'&&$reopened['version']===6,'The professor can explicitly reopen a closed assessment.');
    $stillLocked=synk_student_assessment_action_lms($conn,$lms,$third,'start',['assessment_id'=>$id]);
    $check($stillLocked['attempt']['status']==='expired'&&$stillLocked['attempt']['attempt_id']===$newStart['attempt']['attempt_id'],'Reopening must not reset or unlock a prior attempt.');
    $oldList=array_column(synk_student_assessment_list_lms($conn,$lms,$sid),null,'assessment_id');
    $check($oldList[$id]['assessment_type']==='quiz'&&$oldList[$id]['result']===$submitted['result'],'Converting the current assessment to Assignment must not change old quiz results.');
    $copy=synk_assessment_duplicate_lms($lms,$context,['assessment_id'=>$id,'version'=>6,'grading_period'=>'midterm'],$actor);
    $check($copy['status']==='draft'&&$copy['assessment_type']==='assignment','Duplication must still create an independent private draft.');
    $logs=synk_assessment_query_lms($lms,'SELECT action FROM tbl_lms_assessment_logs WHERE assessment_id=? ORDER BY log_id','i',[$id]);
    $check(in_array('reopened',array_column($logs,'action'),true),'Reopening must be recorded in the LMS audit trail.');
    $written=['grading_period'=>'final','assessment_type'=>'assignment','title'=>'Original assignment','instructions'=>'Original written task','total_points'=>20];
    $written=synk_assessment_publish_lms($lms,$context,$written,$actor);$writtenId=$written['assessment_id'];
    synk_student_assessment_action_lms($conn,$lms,$sid,'start',['assessment_id'=>$writtenId]);
    synk_assessment_save_lms($lms,$context,array_replace($sample,['assessment_id'=>$writtenId,'version'=>1]),$actor);
    $writtenSubmit=synk_student_assessment_action_lms($conn,$lms,$sid,'submit',['assessment_id'=>$writtenId,'version'=>1,'answers'=>['assignment_response'=>'My original written response']]);
    $check($writtenSubmit['assessment']['assessment_type']==='assignment'&&$writtenSubmit['attempt']['answers']['assignment_response']==='My original written response'&&$writtenSubmit['result']['pending_review_count']===1,'An original written assignment must still submit after the current assessment becomes a quiz.');
    // A failed transaction must also roll back newly backfilled originals.
    $lms->query('SAVEPOINT snapshot_rollback');
    synk_assessment_query_lms($lms,'UPDATE tbl_lms_assessment_attempts SET assessment_snapshot_json=NULL WHERE assessment_id=?','i',[$id]);
    $lms->query('SAVEPOINT snapshot_edit');
    synk_assessment_save_lms($lms,$context,array_replace($closedEdit,['version'=>6,'title'=>'Rollback title']),$actor);
    $lms->query('ROLLBACK TO SAVEPOINT snapshot_edit');
    $nulls=synk_assessment_query_lms($lms,'SELECT COUNT(*) AS total FROM tbl_lms_assessment_attempts WHERE assessment_id=? AND assessment_snapshot_json IS NULL','i',[$id]);
    $check((int)$nulls[0]['total']===3&&synk_assessment_detail_lms($lms,$context,$id)['version']===6,'Rollback must restore the header, questions and snapshot writes together.');
    $lms->query('ROLLBACK TO SAVEPOINT snapshot_rollback');
} finally { $lms->rollback();$conn->rollback();$_SESSION=$session; }
$check($conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC)===$coreBefore,'Core data changed.');
$check($lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC)===$lmsBefore,'Temporary LMS records remain.');
echo 'LMS published assessment editing: ',$checks,' checks passed. All fixtures rolled back.',PHP_EOL;
