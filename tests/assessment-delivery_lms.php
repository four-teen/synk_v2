<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';require_once __DIR__.'/../backend/student_assessment_helper_lms.php';require_once __DIR__.'/../backend/professor_class_helper_lms.php';require_once __DIR__.'/fixtures/professor-workload-students-data.php';
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$denied=static function(callable $fn,int $code)use($check):void{try{$fn();$check(false,'An invalid delivery request succeeded.');}catch(DomainException $e){$check($e->getCode()===$code,$e->getMessage().' / expected '.$code);}};
$lms=synk_database_connection_lms($conn);$originalSession=$_SESSION??[];
$coreTables='tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_faculty,tbl_class_schedule,tbl_faculty_workload_sched,tbl_academic_settings,tbl_student_management,tbl_student_management_enrolled_subjects,tbl_student_portal_sessions';
$lmsTables='tbl_lms_assessments,tbl_lms_assessment_questions,tbl_lms_assessment_logs,tbl_lms_assessment_attempts';
$before=$conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC);$lmsBefore=$lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC);
$conn->begin_transaction();$lms->begin_transaction();
try{
    $f=professor_students_fixtures($conn,true);$sid=$f['students']['active'];$actor=$f['user_id'];
    $context=['portal'=>['faculty_id'=>$f['faculty_id']],'class'=>array_replace($f['offerings'][0],['ay_id'=>$f['term']['ay_id'],'semester'=>$f['term']['semester']])];
    $sample=['grading_period'=>'midterm','assessment_type'=>'quiz','title'=>'Published QA quiz','instructions'=>'Answer every item.','time_limit_minutes'=>30,'questions'=>[
        ['question_type'=>'multiple_choice','prompt'=>"What is `2 + 2`?\n```php\necho 2 + 2;\n```",'points'=>2,'options'=>['3','4'],'answer_key'=>1],
        ['question_type'=>'true_false','prompt'=>'2 > 3','points'=>1,'answer_key'=>false],
        ['question_type'=>'short_answer','prompt'=>'Language name','points'=>1,'answer_key'=>['PHP','Hypertext Preprocessor']],
        ['question_type'=>'essay','prompt'=>'Explain your program','points'=>5]
    ]];
    $draft=synk_assessment_save_lms($lms,$context,$sample,$actor);$id=$draft['assessment_id'];
    $subjectCards=synk_student_subjects_lms($conn,$sid);
    $check(count($subjectCards)===2,'Enrolled subject cards must be available before publishing.');
    $check(count(synk_student_subjects_lms($conn,$f['students']['inactive']))===1,'Legacy enrollment must still have a subject card.');
    foreach(['foreign','withdrawn','wrong_term']as$key)$check(synk_student_subjects_lms($conn,$f['students'][$key])===[],'Subject cards must exclude foreign, inactive and historical rosters.');
    $check(synk_student_assessment_list_lms($conn,$lms,$sid)===[],'Students must never see private drafts.');
    $denied(static function()use($conn,$lms,$sid,$id){synk_student_assessment_action_lms($conn,$lms,$sid,'detail',['assessment_id'=>$id]);},404);
    $empty=$sample;$empty['questions']=[];$denied(static function()use($lms,$context,$empty,$actor){synk_assessment_publish_lms($lms,$context,$empty,$actor);},422);
    $past=$sample;$past['due_at']='2000-01-01T00:00';$denied(static function()use($lms,$context,$past,$actor){synk_assessment_publish_lms($lms,$context,$past,$actor);},422);
    $published=synk_assessment_publish_lms($lms,$context,array_replace($sample,['assessment_id'=>$id,'version'=>1]),$actor);
    $check($published['status']==='published'&&$published['version']===2,'Publish saves and advances the draft in the same operation.');
    $check(count(synk_student_assessment_list_lms($conn,$lms,$sid))===1,'An enrolled student sees the published assessment.');
    $visible=synk_student_assessment_list_lms($conn,$lms,$sid);
    $check(in_array($visible[0]['subject_key'],array_column($subjectCards,'subject_key'),true),'Published work must map to the enrolled faculty subject card.');
    $check(count(synk_student_assessment_list_lms($conn,$lms,$f['students']['inactive']))===1,'Legacy enrollment matching works for student delivery.');
    foreach(['foreign','withdrawn','wrong_term']as$key){
        $check(synk_student_assessment_list_lms($conn,$lms,$f['students'][$key])===[],'Unenrolled or inactive students must not see the assessment.');
        $denied(static function()use($conn,$lms,$f,$key,$id){synk_student_assessment_action_lms($conn,$lms,$f['students'][$key],'start',['assessment_id'=>$id]);},404);
    }
    $denied(static function()use($lms,$context,$sample,$id,$actor){synk_assessment_save_lms($lms,$context,array_replace($sample,['assessment_id'=>$id,'version'=>2]),$actor);},409);
    $denied(static function()use($lms,$context,$id,$actor){synk_assessment_archive_lms($lms,$context,['assessment_id'=>$id,'version'=>2],$actor);},409);
    $detail=synk_student_assessment_action_lms($conn,$lms,$sid,'detail',['assessment_id'=>$id]);
    $check($detail['attempt']===null&&$detail['assessment']['questions']===[],'Before Start, student detail must not disclose questions.');
    $check($detail['result']===null,'Results must not be disclosed before an attempt.');
    $started=synk_student_assessment_action_lms($conn,$lms,$sid,'start',['assessment_id'=>$id]);$attempt=$started['attempt'];
    $check(count($started['assessment']['questions'])===4&&!strpos(json_encode($started),'answer_key'),'Student attempts include four question types but never answer keys.');
    $check($started['result']===null,'Starting an attempt must not disclose correctness.');
    $check($attempt['deadline_at']>$attempt['started_at'],'The server starts the configured assessment timer.');
    $again=synk_student_assessment_action_lms($conn,$lms,$sid,'start',['assessment_id'=>$id]);
    $check($again['attempt']['attempt_id']===$attempt['attempt_id']&&$again['attempt']['deadline_at']===$attempt['deadline_at'],'A second tab or reload must resume the same attempt without resetting the timer.');
    $questions=$started['assessment']['questions'];$answers=[$questions[0]['question_id']=>1,$questions[1]['question_id']=>false,$questions[2]['question_id']=>"hYpertext \t preprocessor",$questions[3]['question_id']=>'Literal <script>code</script>'];
    $saved=synk_student_assessment_action_lms($conn,$lms,$sid,'save',['assessment_id'=>$id,'version'=>1,'answers'=>$answers]);
    $check($saved['attempt']['version']===2&&$saved['attempt']['answers']===$answers,'Student responses, including False and code-like text, must round-trip.');
    $check($saved['result']===null,'Saving answers must not create a correctness oracle.');
    $listed=synk_student_assessment_list_lms($conn,$lms,$sid);
    $check($listed[0]['result']===null&&strpos(json_encode($listed),'answers')===false&&strpos(json_encode($listed),'answer_key')===false,'The assessment list must withhold correctness and private answer data while an attempt is editable.');
    $denied(static function()use($conn,$lms,$sid,$id,$answers){synk_student_assessment_action_lms($conn,$lms,$sid,'save',['assessment_id'=>$id,'version'=>1,'answers'=>$answers]);},409);
    $denied(static function()use($conn,$lms,$sid,$id){synk_student_assessment_action_lms($conn,$lms,$sid,'save',['assessment_id'=>$id,'version'=>2,'answers'=>[2147483647=>'bad']]);},422);
    $invalid=$answers;$invalid[$questions[1]['question_id']]='false';$denied(static function()use($conn,$lms,$sid,$id,$invalid){synk_student_assessment_action_lms($conn,$lms,$sid,'save',['assessment_id'=>$id,'version'=>2,'answers'=>$invalid]);},422);
    $submitted=synk_student_assessment_action_lms($conn,$lms,$sid,'submit',['assessment_id'=>$id,'version'=>2,'answers'=>$answers]);
    $check($submitted['attempt']['status']==='submitted','Submitting locks the saved student responses.');
    $check($submitted['result']===['correct_count'=>3,'mistake_count'=>0,'pending_review_count'=>1,'checked_count'=>3],'Final results must count typed objective answers and normalized short-answer alternatives while leaving essays for review.');
    $check(strpos(json_encode($submitted),'answer_key')===false,'Final results must expose aggregate totals without answer keys.');
    $repeat=synk_student_assessment_action_lms($conn,$lms,$sid,'submit',['assessment_id'=>$id,'version'=>2,'answers'=>[]]);
    $check($repeat['attempt']['answers']===$answers&&$repeat['attempt']['version']===3,'Duplicate submissions must not overwrite or resubmit answers.');
    $check($repeat['result']===$submitted['result'],'Repeated submission must preserve result totals.');
    $reopened=synk_student_assessment_action_lms($conn,$lms,$sid,'detail',['assessment_id'=>$id]);
    $check($reopened['result']===$submitted['result'],'Previously submitted work must retain its results when reopened.');
    $listed=synk_student_assessment_list_lms($conn,$lms,$sid);
    $check($listed[0]['result']===$submitted['result']&&strpos(json_encode($listed),'answers')===false&&strpos(json_encode($listed),'answer_key')===false,'Submitted assessment cards must receive the same aggregate result without saved answers or keys.');
    $mistakesRecord=synk_assessment_publish_lms($lms,$context,$sample,$actor);$mistakesId=$mistakesRecord['assessment_id'];
    $mistakesStart=synk_student_assessment_action_lms($conn,$lms,$sid,'start',['assessment_id'=>$mistakesId]);$mistakesQuestions=$mistakesStart['assessment']['questions'];
    $mistakes=synk_student_assessment_action_lms($conn,$lms,$sid,'submit',['assessment_id'=>$mistakesId,'version'=>1,'answers'=>[$mistakesQuestions[0]['question_id']=>0,$mistakesQuestions[1]['question_id']=>true]]);
    $check($mistakes['result']===['correct_count'=>0,'mistake_count'=>3,'pending_review_count'=>1,'checked_count'=>3],'Incorrect choices, incorrect True/False and an unanswered short answer must count as mistakes, not essays.');
    $responses=synk_assessment_submissions_lms($conn,$lms,$context,$id);
    $check(count($responses)===1&&$responses[0]['student_id']===$sid&&$responses[0]['answers']===$answers,'The assigned professor can review the submitted answers.');
    $other=$context;$other['portal']['faculty_id']++;$denied(static function()use($conn,$lms,$other,$id){synk_assessment_submissions_lms($conn,$lms,$other,$id);},404);
    $secondSid=$f['students']['inactive'];synk_student_assessment_action_lms($conn,$lms,$secondSid,'start',['assessment_id'=>$id]);
    synk_assessment_query_lms($lms,"UPDATE tbl_lms_assessment_attempts SET deadline_at='2000-01-01 00:00:00' WHERE assessment_id=? AND student_id=?",'ii',[$id,$secondSid]);
    $expired=synk_student_assessment_action_lms($conn,$lms,$secondSid,'save',['assessment_id'=>$id,'version'=>1,'answers'=>$answers]);
    $check($expired['attempt']['status']==='expired'&&$expired['attempt']['answers']===[],'Late payloads must be rejected while retaining responses saved before the deadline.');
    $check($expired['result']===['correct_count'=>0,'mistake_count'=>3,'pending_review_count'=>1,'checked_count'=>3],'Expired attempts must use only the last saved answers for result totals.');
    $listed=synk_student_assessment_list_lms($conn,$lms,$secondSid);$listed=array_column($listed,null,'assessment_id');
    $check($listed[$id]['result']===$expired['result'],'Expired assessment cards must show results for retained answers.');
    $closed=synk_assessment_close_lms($lms,$context,['assessment_id'=>$id,'version'=>2],$actor);
    $check($closed['status']==='closed','Professors can close published assessments.');
    $denied(static function()use($conn,$lms,$f,$id){synk_student_assessment_action_lms($conn,$lms,$f['students']['failed'],'start',['assessment_id'=>$id]);},409);
    $scheduled=$sample;$scheduled['opens_at']='2099-01-01T08:00';$scheduled=synk_assessment_publish_lms($lms,$context,$scheduled,$actor);
    $denied(static function()use($conn,$lms,$sid,$scheduled){synk_student_assessment_action_lms($conn,$lms,$sid,'start',['assessment_id'=>$scheduled['assessment_id']]);},409);
    $assignment=['grading_period'=>'final','assessment_type'=>'assignment','title'=>'Written project','instructions'=>'Describe your solution.','total_points'=>100];
    $assignment=synk_assessment_publish_lms($lms,$context,$assignment,$actor);
    $data=synk_student_assessment_action_lms($conn,$lms,$sid,'start',['assessment_id'=>$assignment['assessment_id']]);
    $data=synk_student_assessment_action_lms($conn,$lms,$sid,'submit',['assessment_id'=>$assignment['assessment_id'],'version'=>1,'answers'=>['assignment_response'=>'0']]);
    $check($data['attempt']['answers']['assignment_response']==='0','Assignments accept written responses including the string zero.');
    $check($data['result']===['correct_count'=>0,'mistake_count'=>0,'pending_review_count'=>1,'checked_count'=>0],'Assignments must await professor review rather than being marked as mistakes.');
    $listed=array_column(synk_student_assessment_list_lms($conn,$lms,$sid),null,'assessment_id');
    $check($listed[$assignment['assessment_id']]['result']===$data['result'],'Written assignment cards must retain the pending professor review summary.');
    $conn->query('DELETE FROM tbl_faculty_workload_sched WHERE faculty_id='.$f['faculty_id']);
    $check(synk_student_assessment_list_lms($conn,$lms,$sid)===[],'Reassigning the class removes student delivery access immediately.');
}finally{$lms->rollback();$conn->rollback();$_SESSION=$originalSession;}
$after=$conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC);
$changed=[];foreach($after as $index=>$row)if($row!==$before[$index])$changed[]=$row['Table'];
$check($after===$before,'Core academic, student, account, workload and scheduling data changed: '.implode(', ',$changed));
$check($lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC)===$lmsBefore,'Temporary delivery records were not rolled back.');
echo 'LMS assessment delivery: ',$checks,' checks passed. All fixtures rolled back.',PHP_EOL;
