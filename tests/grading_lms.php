<?php
// Grading and core fixtures roll back. Run with the configured PHP CLI.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/grading_api_helper_lms.php';
require_once __DIR__.'/fixtures/professor-workload-students-data.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$denied=static function(callable $action,int $code)use($check):void{try{$action();$check(false,'An invalid grading request was accepted.');}catch(DomainException $error){$check($error->getCode()===$code,'Unexpected status: '.$error->getMessage());}};
$snapshot=static function(mysqli $db,string $tables):array{return $db->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC);};
$coreTables='tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_faculty,tbl_class_schedule,tbl_faculty_workload_sched,tbl_academic_settings,tbl_student_management,tbl_student_management_enrolled_subjects';
$lmsTables='tbl_lms_grading_schemes,tbl_lms_grading_components,tbl_lms_grading_logs,tbl_lms_assessments,tbl_lms_assessment_questions,tbl_lms_assessment_logs';
$lms=synk_database_connection_lms($conn);$coreBefore=$snapshot($conn,$coreTables);$lmsBefore=$snapshot($lms,$lmsTables);$originalSession=$_SESSION??[];
$sample=['grading_period'=>'midterm','version'=>0,'components'=>[
    ['name'=>'Participation','description'=>'Attendance and discussion','weight'=>'20'],
    ['name'=>'Written Assessments & Quizzes','description'=>'Literal <script>code</script> remains text.','weight'=>'30.00'],
    ['name'=>'Draft Submissions','description'=>'Programming outputs','weight'=>30],
    ['name'=>'Midterm Output','description'=>'Final outline','weight'=>'20.00']
]];
$valid=synk_grading_validate_lms($sample);
$check(array_sum(array_column($valid['components'],'weight_bps'))===10000,'Percentage weights must total exactly 10000 hundredths.');
foreach (['0.01'=>1,'33.33'=>3333,'100.00'=>10000] as $weight=>$expected)$check(synk_grading_weight_lms((string)$weight)===$expected,'Decimal weights were rounded incorrectly.');
foreach ([0,-1,100.01,'1.001','1e2','20%','NaN',true,null,[],INF] as $weight)$denied(static function()use($weight){synk_grading_weight_lms($weight);},422);
foreach ([['grading_period'=>'other'],['grading_period'=>[]],['version'=>-1],['version'=>'2147483648'],['components'=>[]],['components'=>null],
    ['components'=>array_fill(0,31,$sample['components'][0])],['components'=>['invalid'=>$sample['components'][0]]],['components'=>['bad']]] as $bad){
    $denied(static function()use($sample,$bad){synk_grading_validate_lms(array_replace($sample,$bad));},422);
}
foreach ([['name'=>''],['name'=>str_repeat('x',161)],['name'=>[]],['description'=>str_repeat('x',2001)],['weight'=>'19.99'],['weight'=>'20.01'],['component_id'=>[]]] as $bad){
    $input=$sample;$input['components'][0]=array_replace($input['components'][0],$bad);$denied(static function()use($input){synk_grading_validate_lms($input);},422);
}
$input=$sample;$input['components'][1]['name']='participation';$denied(static function()use($input){synk_grading_validate_lms($input);},422);
$input=$sample;$input['components'][0]['component_id']=1;$input['components'][1]['component_id']=1;$denied(static function()use($input){synk_grading_validate_lms($input);},422);
$input=$sample;$input['components']=[['name'=>'One','weight'=>'33.33'],['name'=>'Two','weight'=>'33.33'],['name'=>'Three','weight'=>'33.34']];
$check(count(synk_grading_validate_lms($input)['components'])===3,'Decimal weights summing to 100% must be accepted exactly.');
$conn->begin_transaction();$lms->begin_transaction();
try {
    $fixture=professor_students_fixtures($conn);$_SESSION=['user_id'=>$fixture['user_id'],'role'=>'professor','lms_grading_csrf'=>bin2hex(random_bytes(32))];
    $params=[];parse_str((string)parse_url(synk_professor_class_url_lms(['faculty_id'=>$fixture['faculty_id'],'offering_id'=>$fixture['offerings'][0]['offering_id'],'ay_id'=>$fixture['term']['ay_id'],'semester'=>$fixture['term']['semester']],'grading'),PHP_URL_QUERY),$params);
    $context=synk_professor_class_context_lms($conn,$params);$actor=$fixture['user_id'];
    $check(synk_grading_detail_lms($lms,$context,'midterm')['version']===0,'An unconfigured class must remain empty.');
    $saved=synk_grading_save_lms($lms,$context,$sample,$actor);$ids=array_column($saved['components'],'component_id');
    $check($saved['version']===1&&$saved['total_weight']==100&&count($ids)===4,'A balanced grading setup must save.');
    $check($saved['components'][1]['description']===$sample['components'][1]['description'],'Literal text must round-trip unchanged.');
    $check(synk_grading_detail_lms($lms,$context,'final')['components']===[],'Saving Midterm must not initialize Final.');
    $final=synk_grading_save_lms($lms,$context,array_replace($sample,['grading_period'=>'final']),$actor);
    $check($final['scheme_id']!==$saved['scheme_id'],'Midterm and Final require separate schemes.');
    $input=$saved;$input['components']=array_reverse($input['components']);$input['components'][0]['name']='Renamed Output';
    $edited=synk_grading_save_lms($lms,$context,$input,$actor);
    $check($edited['version']===2&&array_column($edited['components'],'component_id')===array_reverse($ids),'Reordering must retain component IDs.');
    $denied(static function()use($lms,$context,$input,$actor){synk_grading_save_lms($lms,$context,$input,$actor);},409);
    $input=$edited;$removed=array_pop($input['components']);$input['components'][0]['weight']+=20;
    $edited=synk_grading_save_lms($lms,$context,$input,$actor);
    $archived=synk_grading_query_lms($lms,'SELECT status FROM tbl_lms_grading_components WHERE component_id=?','i',[$removed['component_id']]);
    $check($archived[0]['status']==='archived'&&count($edited['components'])===3,'Removing a component must preserve its archived history.');
    $check(synk_grading_detail_lms($lms,$context,'final')===$final,'Midterm edits must leave Final unchanged.');
    $input=$edited;$input['components'][0]['weight']-=10;$input['components'][]=['name'=>'New component','weight'=>'10'];
    $edited=synk_grading_save_lms($lms,$context,$input,$actor);
    $check($edited['components'][3]['component_id']>0&&!in_array($edited['components'][3]['component_id'],$ids,true),'A new component requires a new stable ID.');
    $input=$edited;$input['components'][0]['component_id']=$final['components'][0]['component_id'];
    $denied(static function()use($lms,$context,$input,$actor){synk_grading_save_lms($lms,$context,$input,$actor);},409);
    $input=$edited;$input['components'][0]['component_id']=$removed['component_id'];
    $denied(static function()use($lms,$context,$input,$actor){synk_grading_save_lms($lms,$context,$input,$actor);},409);
    foreach (['faculty_id','offering_id','ay_id','semester'] as $field){
        $foreign=$context;if($field==='faculty_id')$foreign['portal'][$field]++;else $foreign['class'][$field]++;
        $check(synk_grading_detail_lms($lms,$foreign,'midterm')['components']===[],'A scheme leaked outside its '.$field.' scope.');
    }
    $logs=synk_grading_query_lms($lms,'SELECT version,details_json FROM tbl_lms_grading_logs WHERE scheme_id=? ORDER BY version','i',[$edited['scheme_id']]);
    $details=json_decode($logs[3]['details_json'],true);
    $check(array_column($logs,'version')===[1,2,3,4]&&$details['before']['version']===3&&$details['after']['version']===4,'Every revision must preserve before/after snapshots.');
    $request=['action'=>'get','csrf_token'=>$_SESSION['lms_grading_csrf'],'context'=>$params];
    foreach ([['csrf_token'=>'bad'],['csrf_token'=>[]],['context'=>[]],['context'=>array_replace($params,['view'=>'assessments'])],
        ['context'=>array_diff_key($params,['signature'=>true])],['context'=>array_replace($params,['semester'=>$params['semester']==='1'?'2':'1'])]] as $bad){
        $denied(static function()use($conn,$request,$bad){synk_grading_request_lms($conn,array_replace($request,$bad));},403);
    }
    $denied(static function()use($conn,$request){synk_grading_request_lms($conn,array_replace($request,['action'=>'delete']));},400);
    $denied(static function()use($conn,$request){synk_grading_request_lms($conn,array_replace($request,['payload'=>'bad']));},400);
    $_SESSION['role']='student';$denied(static function()use($conn,$request){synk_grading_request_lms($conn,$request);},401);$_SESSION['role']='professor';
    $conn->query('DELETE FROM tbl_faculty_workload_sched WHERE faculty_id='.$fixture['faculty_id']);
    $denied(static function()use($conn,$request){synk_grading_request_lms($conn,$request);},404);
} finally {$lms->rollback();$conn->rollback();$_SESSION=$originalSession;}
$check($snapshot($conn,$coreTables)===$coreBefore,'Grading tests changed core academic, account, or scheduling records.');
$check($snapshot($lms,$lmsTables)===$lmsBefore,'Grading fixtures or assessment changes were left behind.');
class GradingFailingLogConnectionLms extends mysqli
{
    #[\ReturnTypeWillChange]
    public function prepare($sql) {
        if (strpos($sql,'INSERT INTO tbl_lms_grading_logs')===0) throw new RuntimeException('Simulated grading audit failure.');
        return parent::prepare($sql);
    }
}
$settings=require __DIR__.'/../backend/db_config.local_lms.php';
$failure=new GradingFailingLogConnectionLms($settings['host'],$settings['username'],$settings['password'],$settings['database']);unset($settings);$failure->set_charset('utf8mb4');
$failure->begin_transaction();
try {
    synk_grading_save_lms($failure,['portal'=>['faculty_id'=>2147483000],'class'=>['offering_id'=>2147483000,'ay_id'=>2147483000,'semester'=>1]],$sample,2147483000);
    $check(false,'The simulated grading audit failure did not fail.');
}catch(RuntimeException $error){$check($error->getMessage()==='Simulated grading audit failure.','An unexpected database failure occurred.');}
finally{$failure->rollback();$failure->close();}
$check($snapshot($lms,$lmsTables)===$lmsBefore,'A late grading write failure must roll back the scheme and its components.');
echo 'LMS grading: ',$checks,' checks passed. All fixtures rolled back.',PHP_EOL;
