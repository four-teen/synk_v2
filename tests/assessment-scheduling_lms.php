<?php
// Schedule shortcuts use existing LMS dates. All fixtures are rolled back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/student_assessment_helper_lms.php';
require_once __DIR__.'/../backend/professor_class_helper_lms.php';
require_once __DIR__.'/fixtures/professor-workload-students-data.php';
$lms=synk_database_connection_lms($conn);$checks=0;$session=$_SESSION??[];
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$denied=static function(callable $fn,int $code)use($check):void{try{$fn();$check(false,'An invalid schedule succeeded.');}catch(DomainException $error){$check($error->getCode()===$code,$error->getMessage());}};
$coreTables='tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_faculty,tbl_class_schedule,tbl_faculty_workload_sched,tbl_academic_settings,tbl_student_management,tbl_student_management_enrolled_subjects';
$lmsTables='tbl_lms_assessments,tbl_lms_assessment_questions,tbl_lms_assessment_logs,tbl_lms_assessment_attempts';
$coreBefore=$conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC);
$lmsBefore=$lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC);
$conn->begin_transaction();$lms->begin_transaction();
try {
    $f=professor_students_fixtures($conn,true);$actor=$f['user_id'];$sid=$f['students']['active'];$fresh=$f['students']['failed'];
    $context=['portal'=>['faculty_id'=>$f['faculty_id']],'class'=>array_replace($f['offerings'][0],['ay_id'=>$f['term']['ay_id'],'semester'=>$f['term']['semester']])];
    $sample=['grading_period'=>'final','assessment_type'=>'quiz','title'=>'Schedule shortcut QA','time_limit_minutes'=>30,
        'questions'=>[['question_type'=>'true_false','prompt'=>'Scheduling preserves questions.','points'=>1,'answer_key'=>true]]];
    $published=synk_assessment_publish_lms($lms,$context,$sample,$actor);$id=$published['assessment_id'];
    $start=synk_student_assessment_action_lms($conn,$lms,$sid,'start',['assessment_id'=>$id]);
    // Protect pre-upgrade attempts as well as snapshots from current students.
    synk_assessment_query_lms($lms,'UPDATE tbl_lms_assessment_attempts SET assessment_snapshot_json=NULL WHERE assessment_id=?','i',[$id]);
    $schedule=['assessment_id'=>$id,'version'=>1,'opens_at'=>'2099-01-01T08:00','due_at'=>'2099-01-01T09:00'];
    $updated=synk_assessment_schedule_lms($lms,$context,$schedule+['title'=>'Unwanted edit','questions'=>[],'status'=>'closed'],$actor);
    $check($updated['status']==='published'&&$updated['version']===2&&$updated['opens_at']==='2099-01-01 08:00:00'&&$updated['due_at']==='2099-01-01 09:00:00','Schedule saves Philippine start/end dates while preserving published status.');
    $check($updated['questions']===$published['questions']&&$updated['title']===$published['title']&&$updated['time_limit_minutes']===30&&$updated['grading_period']==='final','Scheduling must leave content, question IDs, grading period and time limit unchanged.');
    $original=synk_student_assessment_action_lms($conn,$lms,$sid,'detail',['assessment_id'=>$id]);
    $check($original['assessment']===$start['assessment']&&$original['attempt']['deadline_at']===$start['attempt']['deadline_at']&&$original['attempt']['version']===$start['attempt']['version'],'Scheduling must preserve existing student content and deadline, including pre-upgrade attempts.');
    $denied(static function()use($conn,$lms,$fresh,$id){synk_student_assessment_action_lms($conn,$lms,$fresh,'start',['assessment_id'=>$id]);},409);
    $denied(static function()use($lms,$context,$schedule,$actor){synk_assessment_schedule_lms($lms,$context,$schedule,$actor);},409);
    foreach ([['opens_at'=>'invalid'],['due_at'=>'2099-01-01T08:00'],['due_at'=>'2098-12-31T23:00'],['opens_at'=>[]],['due_at'=>'2101-01-01T00:00']] as $bad) {
        $denied(static function()use($lms,$context,$schedule,$bad,$actor){synk_assessment_schedule_lms($lms,$context,array_replace($schedule,['version'=>2],$bad),$actor);},422);
    }
    $denied(static function()use($lms,$context,$id,$actor){synk_assessment_schedule_lms($lms,$context,['assessment_id'=>$id,'version'=>2,'opens_at'=>null],$actor);},422);
    foreach (['faculty','offering','year','semester'] as $scope) {
        $foreign=$context;
        if($scope==='faculty')$foreign['portal']['faculty_id']++;
        else{$key=['offering'=>'offering_id','year'=>'ay_id','semester'=>'semester'][$scope];$foreign['class'][$key]++;}
        $denied(static function()use($lms,$foreign,$schedule,$actor){synk_assessment_schedule_lms($lms,$foreign,array_replace($schedule,['version'=>2]),$actor);},404);
    }
    $check(synk_assessment_detail_lms($lms,$context,$id)===$updated,'Rejected schedule requests must leave the record untouched.');
    $cleared=synk_assessment_schedule_lms($lms,$context,['assessment_id'=>$id,'version'=>2,'opens_at'=>'','due_at'=>null],$actor);
    $check($cleared['opens_at']===null&&$cleared['due_at']===null&&$cleared['version']===3,'Blank schedule fields remove optional dates.');
    $new=synk_student_assessment_action_lms($conn,$lms,$fresh,'start',['assessment_id'=>$id]);
    $check($new['attempt']['status']==='in_progress'&&$new['assessment']['opens_at']===null,'New starts follow the updated schedule.');
    $submitted=synk_student_assessment_action_lms($conn,$lms,$sid,'submit',['assessment_id'=>$id,'version'=>1,'answers'=>[$start['assessment']['questions'][0]['question_id']=>true]]);
    $check($submitted['attempt']['status']==='submitted','A student already started can still submit after schedule changes.');
    $closed=synk_assessment_close_lms($lms,$context,['assessment_id'=>$id,'version'=>3],$actor);
    $closed=synk_assessment_schedule_lms($lms,$context,array_replace($schedule,['version'=>4]),$actor);
    $check($closed['status']==='closed'&&$closed['version']===5,'Scheduling a closed assessment must not reopen it.');
    $check(synk_student_assessment_action_lms($conn,$lms,$sid,'detail',['assessment_id'=>$id])['attempt']['status']==='submitted','Scheduling must leave submitted attempts locked.');
    foreach (['quiz','exam','assignment'] as $type) {
        $draft=synk_assessment_save_lms($lms,$context,['grading_period'=>'midterm','assessment_type'=>$type,'title'=>'Incomplete schedule QA','total_points'=>10],$actor);
        $draft=synk_assessment_schedule_lms($lms,$context,['assessment_id'=>$draft['assessment_id'],'version'=>1,'opens_at'=>null,'due_at'=>'2099-01-01T09:00'],$actor);
        $check($draft['status']==='draft'&&$draft['due_at']==='2099-01-01 09:00:00'&&$draft['questions']===[],'Even an incomplete '.$type.' draft can be scheduled without publishing.');
        synk_assessment_archive_lms($lms,$context,['assessment_id'=>$draft['assessment_id'],'version'=>2],$actor);
        $denied(static function()use($lms,$context,$draft,$actor){synk_assessment_schedule_lms($lms,$context,['assessment_id'=>$draft['assessment_id'],'version'=>3,'opens_at'=>null,'due_at'=>null],$actor);},409);
    }
    $logs=synk_assessment_query_lms($lms,"SELECT details_json FROM tbl_lms_assessment_logs WHERE assessment_id=? AND action='scheduled' ORDER BY log_id",'i',[$id]);
    $audit=json_decode($logs[0]['details_json'],true);
    $check(count($logs)===3&&$audit['previous_opens_at']===null&&$audit['due_at']==='2099-01-01 09:00:00','The LMS audit trail records previous and updated dates.');
} finally {$lms->rollback();$conn->rollback();$_SESSION=$session;}
$check($conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC)===$coreBefore,'Core data changed.');
$check($lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC)===$lmsBefore,'Temporary LMS records remain.');
echo 'LMS assessment scheduling: ',$checks,' checks passed. All fixtures rolled back.',PHP_EOL;
