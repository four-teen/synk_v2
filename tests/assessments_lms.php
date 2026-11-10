<?php
// php tests/assessments_lms.php. Assessment fixtures and core fixtures roll back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/assessment_api_helper_lms.php';
require_once __DIR__ . '/fixtures/professor-workload-students-data.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void { if(!$ok)throw new RuntimeException($message); $checks++; };
$denied=static function(callable $action,int $code)use($check):void {
    try {$action();$check(false,'An invalid assessment request was accepted.');}
    catch(DomainException $error){$check($error->getCode()===$code,'Unexpected assessment validation status: '.$error->getMessage());}
};
$coreSnapshot=static function()use($conn):array{return $conn->query('CHECKSUM TABLE tbl_student_management,tbl_student_management_enrolled_subjects,tbl_faculty,tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_class_schedule,tbl_faculty_workload_sched,tbl_academic_settings,tbl_offering_enrollee_counts,'.synk_schedule_merge_table_name())->fetch_all(MYSQLI_ASSOC);};
$lms=synk_database_connection_lms($conn);
$lmsSnapshot=static function()use($lms):array{return $lms->query('CHECKSUM TABLE tbl_lms_assessments,tbl_lms_assessment_questions,tbl_lms_assessment_logs')->fetch_all(MYSQLI_ASSOC);};
$coreBefore=$coreSnapshot();$lmsBefore=$lmsSnapshot();$originalSession=$_SESSION??[];
$sample=['grading_period'=>'midterm','assessment_type'=>'quiz','title'=>'Quiz 1: Variables 📝','instructions'=>"Read each question.\nShow your work.",
    'topic'=>'Programming','opens_at'=>'2026-11-15T08:00','due_at'=>'2026-11-15T09:00','time_limit_minutes'=>'30','questions'=>[
        ['question_type'=>'multiple_choice','prompt'=>'What is 2 + 2?','points'=>'2.50','options'=>['3','4','5'],'answer_key'=>1],
        ['question_type'=>'true_false','prompt'=>'The expression 2 > 3 is true.','points'=>1,'answer_key'=>false],
        ['question_type'=>'short_answer','prompt'=>'Name the language.','points'=>3,'answer_key'=>['PHP','php']],
        ['question_type'=>'essay','prompt'=>'Explain your solution.','points'=>5,'answer_key'=>null]
    ]];
$valid=synk_assessment_validate_lms($sample);
$check($valid['total_points']===11.5&&$valid['time_limit_minutes']===30,'Points are totaled in the server and a valid time limit is accepted.');
$check($valid['opens_at']==='2026-11-15 08:00:00'&&$valid['questions'][1]['answer_key']===false,'Philippine dates and a False answer are preserved.');
$check(synk_assessment_validate_lms(array_replace($sample,['questions'=>[]]))['total_points']===0,'A draft may begin without questions.');
$assignment=['grading_period'=>'final','assessment_type'=>'assignment','title'=>'Project','instructions'=>'Build a calculator.','total_points'=>'100.25','questions'=>[]];
$check(synk_assessment_validate_lms($assignment)['total_points']===100.25,'Assignments use their own total points.');
foreach ([['title'=>''],['title'=>['bad']],['title'=>str_repeat('x',201)],['grading_period'=>'other'],['grading_period'=>['final']],
    ['assessment_type'=>'unknown'],['opens_at'=>'2026-02-30T12:00'],['opens_at'=>'2026-11-15T10:00'],['due_at'=>'2026-11-15T08:00'],
    ['time_limit_minutes'=>'241'],['time_limit_minutes'=>['30']],['questions'=>array_fill(0,101,$sample['questions'][0])],
    ['questions'=>['bad'=>'entry']],['questions'=>['bad']]] as $bad) {
    $denied(static function()use($sample,$bad){synk_assessment_validate_lms(array_replace($sample,$bad));},422);
}
foreach ([['question_type'=>'unknown'],['prompt'=>''],['points'=>0],['points'=>'1.001'],['points'=>'10001'],
    ['options'=>['same','same']],['options'=>['one']],['options'=>['a','b'],'answer_key'=>2],['answer_key'=>'1']] as $bad) {
    $input=$sample;$input['questions']=[array_replace($sample['questions'][0],$bad)];
    $denied(static function()use($input){synk_assessment_validate_lms($input);},422);
}
foreach ([['question_type'=>'true_false','prompt'=>'Test','points'=>1,'answer_key'=>'false'],
    ['question_type'=>'short_answer','prompt'=>'Test','points'=>1,'answer_key'=>[]],
    ['question_type'=>'short_answer','prompt'=>'Test','points'=>1,'answer_key'=>array_fill(0,11,'answer')]] as $badQuestion) {
    $input=$sample;$input['questions']=[$badQuestion];$denied(static function()use($input){synk_assessment_validate_lms($input);},422);
}
$denied(static function()use($assignment){synk_assessment_validate_lms(array_replace($assignment,['total_points'=>0]));},422);
$denied(static function()use($assignment,$sample){synk_assessment_validate_lms(array_replace($assignment,['questions'=>$sample['questions']]));},422);
$conn->begin_transaction();$lms->begin_transaction();
try {
    $fixture=professor_students_fixtures($conn);$_SESSION=['user_id'=>$fixture['user_id'],'role'=>'professor','lms_assessment_csrf'=>bin2hex(random_bytes(32))];
    $link=synk_professor_class_url_lms(['faculty_id'=>$fixture['faculty_id'],'offering_id'=>$fixture['offerings'][0]['offering_id'],
        'ay_id'=>$fixture['term']['ay_id'],'semester'=>$fixture['term']['semester']],'assessments');
    $params=[];parse_str((string)parse_url($link,PHP_URL_QUERY),$params);$context=synk_professor_class_context_lms($conn,$params);
    $actor=$fixture['user_id'];
    $record=synk_assessment_save_lms($lms,$context,$sample,$actor);$id=$record['assessment_id'];
    $check($id>0&&$record['version']===1&&$record['status']==='draft'&&count($record['questions'])===4,'The draft and four question types save together.');
    $check($record['total_points']===11.5&&$record['questions'][0]['answer_key']===1&&$record['questions'][1]['answer_key']===false
        &&$record['questions'][2]['answer_key']===['PHP','php']&&$record['questions'][3]['answer_key']===null,'Answer keys and points round-trip without losing types.');
    $check(count(synk_assessment_list_lms($lms,$context))===1,'The own-class list includes its draft.');
    $other=$context;$other['class']['offering_id']=(int)$fixture['offerings'][1]['offering_id'];
    $foreign=$context;$foreign['portal']['faculty_id']++;
    foreach ([$other,$foreign,array_replace_recursive($context,['class'=>['semester'=>3]]),array_replace_recursive($context,['class'=>['ay_id'=>999999]])] as $outside) {
        $check(synk_assessment_list_lms($lms,$outside)===[],'Class, professor, year and semester scopes exclude foreign drafts.');
        $denied(static function()use($lms,$outside,$id){synk_assessment_detail_lms($lms,$outside,$id);},404);
        $denied(static function()use($lms,$outside,$record,$sample,$actor){synk_assessment_save_lms($lms,$outside,array_replace($sample,['assessment_id'=>$record['assessment_id'],'version'=>$record['version']]),$actor);},404);
    }
    $updated=synk_assessment_save_lms($lms,$context,array_replace($sample,['assessment_id'=>$id,'version'=>1,'grading_period'=>'final','title'=>'Edited quiz']),$actor);
    $check($updated['version']===2&&$updated['grading_period']==='final'&&count($updated['questions'])===4,'Saving edits replaces the question set once and advances the draft version.');
    $denied(static function()use($lms,$context,$id,$sample,$actor){synk_assessment_save_lms($lms,$context,array_replace($sample,['assessment_id'=>$id,'version'=>1]),$actor);},409);
    $copy=synk_assessment_duplicate_lms($lms,$context,['assessment_id'=>$id,'version'=>2,'grading_period'=>'midterm'],$actor);
    $check($copy['assessment_id']!==$id&&$copy['grading_period']==='midterm'&&$copy['title']==='Copy of Edited quiz'
        &&$copy['opens_at']===null&&$copy['due_at']===null&&$copy['questions'][0]['answer_key']===1,'Duplicate copies questions and keys into the selected period, with clear dates.');
    $assignmentRecord=synk_assessment_save_lms($lms,$context,$assignment,$actor);
    $check($assignmentRecord['total_points']===100.25&&$assignmentRecord['questions']===[],'Assignments save independently of quizzes.');
    synk_assessment_archive_lms($lms,$context,['assessment_id'=>$copy['assessment_id'],'version'=>1],$actor);
    $check(count(synk_assessment_list_lms($lms,$context))===2,'Archived drafts leave the active list.');
    $denied(static function()use($lms,$context,$copy,$actor){synk_assessment_archive_lms($lms,$context,['assessment_id'=>$copy['assessment_id'],'version'=>1],$actor);},409);
    $denied(static function()use($lms,$context,$copy,$actor){synk_assessment_duplicate_lms($lms,$context,['assessment_id'=>$copy['assessment_id'],'version'=>2],$actor);},409);
    $logs=synk_assessment_query_lms($lms,'SELECT action FROM tbl_lms_assessment_logs WHERE assessment_id=? ORDER BY log_id','i',[$id]);
    $check(array_column($logs,'action')===['created','updated'],'Draft changes produce an audit trail.');
    $request=['action'=>'list','csrf_token'=>$_SESSION['lms_assessment_csrf'],'context'=>$params];
    $check(count(synk_assessment_request_lms($conn,$request)['assessments'])===2,'The request dispatcher permits signed, authenticated own-class reads.');
    foreach ([['csrf_token'=>'bad'],['csrf_token'=>['bad']],['context'=>[]],['context'=>array_replace($params,['semester'=>'3'])],
        ['context'=>array_diff_key($params,['signature'=>true])],['context'=>array_replace($params,['view'=>'students'])]] as $bad) {
        $denied(static function()use($conn,$request,$bad){synk_assessment_request_lms($conn,array_replace($request,$bad));},403);
    }
    $denied(static function()use($conn,$request){synk_assessment_request_lms($conn,array_replace($request,['action'=>'release_grades']));},400);
    $denied(static function()use($conn,$request){synk_assessment_request_lms($conn,array_replace($request,['payload'=>'bad']));},400);
    $_SESSION['role']='student';$denied(static function()use($conn,$request){synk_assessment_request_lms($conn,$request);},401);$_SESSION['role']='professor';
    $conn->query('DELETE FROM tbl_faculty_workload_sched WHERE faculty_id='.$fixture['faculty_id']);
    $denied(static function()use($conn,$request){synk_assessment_request_lms($conn,$request);},404);
} finally {$lms->rollback();$conn->rollback();$_SESSION=$originalSession;}
$check($coreSnapshot()===$coreBefore,'Assessment tests preserve core accounts, rosters, workload, scheduling and academic settings.');
$check($lmsSnapshot()===$lmsBefore,'All LMS assessment test records are rolled back.');

class AssessmentFailingLogConnectionLms extends mysqli
{
    #[\ReturnTypeWillChange]
    public function prepare($sql) {
        if(strpos($sql,'INSERT INTO tbl_lms_assessment_logs')===0)throw new RuntimeException('Simulated log write failure.');
        return parent::prepare($sql);
    }
}
$settings=require __DIR__.'/../backend/db_config.local_lms.php';
$failure=new AssessmentFailingLogConnectionLms($settings['host'],$settings['username'],$settings['password'],$settings['database']);
$failure->set_charset('utf8mb4');unset($settings);
$failureBefore=$failure->query('CHECKSUM TABLE tbl_lms_assessments,tbl_lms_assessment_questions,tbl_lms_assessment_logs')->fetch_all(MYSQLI_ASSOC);
$failure->begin_transaction();
try {
    synk_assessment_save_lms($failure,['portal'=>['faculty_id'=>1],'class'=>['offering_id'=>944,'ay_id'=>2,'semester'=>1]],$sample,1);
    $check(false,'The simulated audit failure did not fail.');
}catch(RuntimeException $error){$check($error->getMessage()==='Simulated log write failure.','An unexpected database failure occurred.');}
finally{$failure->rollback();}
$check($failure->query('CHECKSUM TABLE tbl_lms_assessments,tbl_lms_assessment_questions,tbl_lms_assessment_logs')->fetch_all(MYSQLI_ASSOC)===$failureBefore,
    'A late write failure rolls back the assessment and question rows.');$failure->close();
echo 'LMS assessment drafts: ',$checks,' checks passed. All records rolled back.',PHP_EOL;
