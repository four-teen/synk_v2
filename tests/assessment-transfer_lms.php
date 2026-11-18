<?php
// Transfer fixtures live only inside rolled-back core/LMS transactions.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/student_assessment_helper_lms.php';
require_once __DIR__.'/../backend/professor_class_helper_lms.php';
require_once __DIR__.'/fixtures/professor-workload-students-data.php';
$lms=synk_database_connection_lms($conn);$checks=0;$session=$_SESSION??[];
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$denied=static function(callable $fn,int $code)use($check):void{try{$fn();$check(false,'An invalid transfer succeeded.');}catch(DomainException $error){$check($error->getCode()===$code,$error->getMessage());}};
$coreTables='tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_faculty,tbl_class_schedule,tbl_faculty_workload_sched,tbl_academic_settings,tbl_student_management,tbl_student_management_enrolled_subjects';
$lmsTables='tbl_lms_assessments,tbl_lms_assessment_questions,tbl_lms_assessment_logs,tbl_lms_assessment_attempts';
$gradebookTables='tbl_lms_gradebook_periods,tbl_lms_gradebook_items,tbl_lms_gradebook_scores';
$coreBefore=$conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC);
$lmsBefore=$lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC);
$gradebookBefore=$lms->query('CHECKSUM TABLE '.$gradebookTables)->fetch_all(MYSQLI_ASSOC);
$conn->begin_transaction();$lms->begin_transaction();
try {
    $f=professor_students_fixtures($conn,true);$actor=$f['user_id'];$sid=$f['students']['active'];$second=$f['students']['inactive'];
    $context=['portal'=>['faculty_id'=>$f['faculty_id']],'class'=>array_replace($f['offerings'][0],['ay_id'=>$f['term']['ay_id'],'semester'=>$f['term']['semester']])];
    $sample=['grading_period'=>'midterm','assessment_type'=>'quiz','title'=>'Transfer QA','time_limit_minutes'=>30,
        'questions'=>[['question_type'=>'true_false','prompt'=>'Keep original work.','points'=>2,'answer_key'=>true]]];
    $record=synk_assessment_publish_lms($lms,$context,$sample,$actor);$id=$record['assessment_id'];
    $start=synk_student_assessment_action_lms($conn,$lms,$sid,'start',['assessment_id'=>$id]);
    $answers=[$start['assessment']['questions'][0]['question_id']=>true];
    $saved=synk_student_assessment_action_lms($conn,$lms,$sid,'save',['assessment_id'=>$id,'version'=>1,'answers'=>$answers]);
    synk_student_assessment_action_lms($conn,$lms,$second,'start',['assessment_id'=>$id]);
    $submitted=synk_student_assessment_action_lms($conn,$lms,$second,'submit',['assessment_id'=>$id,'version'=>1,'answers'=>$answers]);
    $attemptBefore=synk_assessment_query_lms($lms,'SELECT * FROM tbl_lms_assessment_attempts WHERE assessment_id=? ORDER BY attempt_id','i',[$id]);
    // Backfill only this pre-upgrade submission; current attempt snapshots stay byte-for-byte intact.
    synk_assessment_query_lms($lms,'UPDATE tbl_lms_assessment_attempts SET assessment_snapshot_json=NULL WHERE assessment_id=? AND student_id=?','ii',[$id,$second]);
    $payload=['assessment_id'=>$id,'version'=>1,'grading_period'=>'final'];
    $moved=synk_assessment_transfer_lms($lms,$context,$payload,$actor);
    $check($moved===array_replace($record,['grading_period'=>'final','version'=>2,'updated_at'=>$moved['updated_at']]),'Transfer changes only the period, revision and update time on the same assessment.');
    $attemptAfter=synk_assessment_query_lms($lms,'SELECT * FROM tbl_lms_assessment_attempts WHERE assessment_id=? ORDER BY attempt_id','i',[$id]);
    $check($attemptAfter[0]===$attemptBefore[0],'Transfer must preserve current attempt snapshots, answers, timers, revisions and status.');
    $check($attemptAfter[1]===array_replace($attemptBefore[1],['assessment_snapshot_json'=>$attemptAfter[1]['assessment_snapshot_json']])
        &&json_decode($attemptAfter[1]['assessment_snapshot_json'],true)==json_decode($attemptBefore[1]['assessment_snapshot_json'],true),'Pre-upgrade submitted work is backfilled without changing original content or other attempt fields.');
    $original=synk_student_assessment_action_lms($conn,$lms,$sid,'detail',['assessment_id'=>$id]);
    $check($original['assessment']===array_replace($start['assessment'],['grading_period'=>'final'])&&$original['attempt']===$saved['attempt'],'Started work moves to Final with its exact content, answers and deadline.');
    $completed=synk_student_assessment_action_lms($conn,$lms,$second,'detail',['assessment_id'=>$id]);
    $check($completed['result']===$submitted['result']&&$completed['attempt']===$submitted['attempt'],'Submitted work and calculated results stay intact.');
    foreach ([$sid,$second,$f['students']['failed']] as $student) {
        $listed=array_column(synk_student_assessment_list_lms($conn,$lms,$student),null,'assessment_id');
        $check($listed[$id]['grading_period']==='final','Started, submitted and unstarted student cards all move to Final.');
    }
    $done=synk_student_assessment_action_lms($conn,$lms,$sid,'submit',['assessment_id'=>$id,'version'=>2,'answers'=>$answers]);
    $check($done['attempt']['status']==='submitted'&&$done['result']===$submitted['result'],'An existing browser can still submit after the transfer.');
    foreach ([[[],422],[['grading_period'=>'other'],422],[['grading_period'=>['final']],422],[['version'=>1,'grading_period'=>'midterm'],409],[['grading_period'=>'final'],409],[['title'=>'Unauthorized edit'],400]] as $case) {
        list($bad,$code)=$case;
        $input=array_replace($payload,['version'=>2],$bad);
        if (!$bad) unset($input['grading_period']);
        $denied(static function()use($lms,$context,$input,$actor){synk_assessment_transfer_lms($lms,$context,$input,$actor);},$code);
    }
    foreach (['faculty','offering','year','semester'] as $scope) {
        $foreign=$context;
        if($scope==='faculty')$foreign['portal']['faculty_id']++;
        else{$key=['offering'=>'offering_id','year'=>'ay_id','semester'=>'semester'][$scope];$foreign['class'][$key]++;}
        $denied(static function()use($lms,$foreign,$payload,$actor){synk_assessment_transfer_lms($lms,$foreign,array_replace($payload,['version'=>2,'grading_period'=>'midterm']),$actor);},404);
    }
    $check(synk_assessment_detail_lms($lms,$context,$id)===$moved,'Rejected transfers must leave the assessment unchanged.');
    $closed=synk_assessment_close_lms($lms,$context,['assessment_id'=>$id,'version'=>2],$actor);
    $returned=synk_assessment_transfer_lms($lms,$context,['assessment_id'=>$id,'version'=>3,'grading_period'=>'midterm'],$actor);
    $check($returned['status']==='closed'&&$returned['grading_period']==='midterm'&&$returned['questions']===$record['questions'],'Final to Midterm transfer preserves closed status and question IDs.');
    $logs=synk_assessment_query_lms($lms,"SELECT details_json FROM tbl_lms_assessment_logs WHERE assessment_id=? AND action='transferred' ORDER BY log_id",'i',[$id]);
    $audit=json_decode($logs[0]['details_json'],true);
    $check(count($logs)===2&&$audit['previous_grading_period']==='midterm'&&$audit['grading_period']==='final'&&$audit['version']===2,'Each transfer records old/new periods and revisions in the LMS audit trail.');
    $lms->query('SAVEPOINT transfer_rollback');
    synk_assessment_transfer_lms($lms,$context,['assessment_id'=>$id,'version'=>4,'grading_period'=>'final'],$actor);
    $lms->query('ROLLBACK TO SAVEPOINT transfer_rollback');
    $check(synk_assessment_detail_lms($lms,$context,$id)===$returned&&synk_assessment_query_lms($lms,"SELECT details_json FROM tbl_lms_assessment_logs WHERE assessment_id=? AND action='transferred' ORDER BY log_id",'i',[$id])===$logs,'Rollback restores the period, revision and audit entries together.');
    foreach (['quiz','exam','assignment'] as $type) {
        $draft=synk_assessment_save_lms($lms,$context,['grading_period'=>'midterm','assessment_type'=>$type,'title'=>'Incomplete transfer QA','total_points'=>10],$actor);
        $draft=synk_assessment_transfer_lms($lms,$context,['assessment_id'=>$draft['assessment_id'],'version'=>1,'grading_period'=>'final'],$actor);
        $check($draft['status']==='draft'&&$draft['questions']===[]&&$draft['grading_period']==='final','Incomplete '.$type.' drafts move without publishing or requiring questions.');
        synk_assessment_archive_lms($lms,$context,['assessment_id'=>$draft['assessment_id'],'version'=>2],$actor);
        $denied(static function()use($lms,$context,$draft,$actor){synk_assessment_transfer_lms($lms,$context,['assessment_id'=>$draft['assessment_id'],'version'=>3,'grading_period'=>'midterm'],$actor);},409);
    }
    $check($lms->query('CHECKSUM TABLE '.$gradebookTables)->fetch_all(MYSQLI_ASSOC)===$gradebookBefore,'Transfers must not change existing gradebook placements or scores.');
} finally {$lms->rollback();$conn->rollback();$_SESSION=$session;}
$check($conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC)===$coreBefore,'Core records changed.');
$check($lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC)===$lmsBefore,'Temporary LMS fixtures remain.');
echo 'LMS assessment transfer: ',$checks,' checks passed. All fixtures rolled back.',PHP_EOL;
