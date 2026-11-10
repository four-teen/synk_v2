<?php
// Real professor/student endpoints, isolated sessions, tagged LMS records only.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';require_once __DIR__.'/../backend/db_lms.php';require_once __DIR__.'/../backend/assessment_helper_lms.php';
$lms=synk_database_connection_lms($conn);$root=dirname(__DIR__);$checks=0;$tag='DeliveryHttpQA'.bin2hex(random_bytes(12));$token=bin2hex(random_bytes(24));
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$route=$root.'/tmp/delivery-http-'.bin2hex(random_bytes(10)).'_lms.php';$jars=['professor'=>$root.'/temp/'.$tag.'prof.cookies','student'=>$root.'/temp/'.$tag.'student.cookies','second_student'=>$root.'/temp/'.$tag.'second.cookies'];$presence=null;$secondPresence=null;
// Live portal heartbeats may update unrelated sessions while this test runs.
// Check the exact QA presence row separately after cleanup.
$coreTables='tbl_academic_settings,tbl_student_management,tbl_student_management_enrolled_subjects,tbl_faculty,tbl_useraccount,tbl_useraccount_faculty_links,tbl_faculty_workload_sched,tbl_class_schedule';
$lmsTables='tbl_lms_assessments,tbl_lms_assessment_questions,tbl_lms_assessment_logs,tbl_lms_assessment_attempts';
$before=$conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC);$lmsBefore=$lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC);
$bootstrap= <<<'PHP'
<?php
if(!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)||!is_string($_GET['qa']??null)||!hash_equals(QA_TOKEN,$_GET['qa'])){http_response_code(404);exit;}
require CORE_DB;require CLASS_HELPER;require STUDENT_HELPER;
session_start();header('Content-Type: application/json');header('Cache-Control: no-store');
if(!empty($_GET['logout'])){$_SESSION=[];session_destroy();echo '{"ok":true}';exit;}
$term=synk_fetch_current_academic_term($conn);
$users=$conn->query("SELECT DISTINCT u.user_id,l.faculty_id FROM tbl_useraccount u JOIN tbl_useraccount_faculty_links l ON l.user_id=u.user_id JOIN tbl_faculty f ON f.faculty_id=l.faculty_id WHERE u.status='active' AND f.status='active' ORDER BY u.user_id")->fetch_all(MYSQLI_ASSOC);
foreach($users as $user){
 $account=synk_find_useraccount_by_id($conn,(int)$user['user_id']);if(!synk_useraccount_has_active_role($conn,$account,'professor'))continue;
 $classes=synk_professor_fetch_subject_rows_by_academic_year($conn,(int)$user['faculty_id'],(int)$term['ay_id'],(int)$term['semester']);
 foreach($classes as $class){
  if(($_GET['as']??'')==='second_student'&&(int)$class['offering_id']!==(int)($_GET['offering_id']??0))continue;
  $members=synk_professor_workload_student_memberships($conn,[$class],(int)$term['ay_id'],(int)$term['semester']);
  foreach(array_keys($members[(int)$class['offering_id']]??[])as$id){
   $rows=synk_assessment_query_lms($conn,'SELECT student_id,email_address,first_name FROM tbl_student_management WHERE student_id=?','i',[(int)$id]);$student=$rows[0]??null;
   if(!$student||!filter_var($student['email_address'],FILTER_VALIDATE_EMAIL)||count(synk_student_login_find_directory($conn,$student['email_address']))!==1)continue;
   if(($_GET['as']??'')==='second_student'&&(int)$id===(int)($_GET['exclude_student_id']??0))continue;
   if(in_array($_GET['as']??'',['student','second_student'],true)){
    synk_complete_student_login($student['email_address'],$student['first_name'],'qa-delivery-sub-'.bin2hex(random_bytes(8)));$_SESSION['student_id']=(int)$id;
    synk_student_presence_start($conn);$_SESSION['student_assessment_csrf']=bin2hex(random_bytes(32));
    echo json_encode(['csrf_token'=>$_SESSION['student_assessment_csrf'],'student_id'=>(int)$id,'presence_key'=>$_SESSION['student_presence_key'],'offering_id'=>(int)$class['offering_id']]);exit;
   }
   $_SESSION=['user_id'=>(int)$user['user_id'],'role'=>'professor','email'=>$account['email'],'lms_assessment_csrf'=>bin2hex(random_bytes(32))];
   $context=[];parse_str((string)parse_url(synk_professor_class_url_lms($class,'assessments'),PHP_URL_QUERY),$context);
   echo json_encode(['context'=>$context,'csrf_token'=>$_SESSION['lms_assessment_csrf'],'student_id'=>(int)$id,'php_version'=>PHP_VERSION]);exit;
  }
 }
}
http_response_code(503);echo '{"message":"No enrolled student and professor pair available."}';
PHP;
file_put_contents($route,str_replace(['QA_TOKEN','CORE_DB','CLASS_HELPER','STUDENT_HELPER'],[var_export($token,true),var_export($root.'/backend/db.php',true),var_export($root.'/backend/professor_class_helper_lms.php',true),var_export($root.'/backend/student_assessment_helper_lms.php',true)],$bootstrap));
$request=static function(string $url,?array $input,string $role='professor')use($jars):array{
 $curl=curl_init($url);$options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false];
 if(isset($jars[$role])){$options[CURLOPT_COOKIEFILE]=$jars[$role];$options[CURLOPT_COOKIEJAR]=$jars[$role];}
 if($input!==null){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=json_encode($input);$options[CURLOPT_HTTPHEADER]=['Content-Type: application/json'];}
 curl_setopt_array($curl,$options);$text=curl_exec($curl);if($text===false)throw new RuntimeException(curl_error($curl));$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$data=json_decode($text,true);
 if(!is_array($data))throw new RuntimeException('Invalid endpoint response: '.substr($text,0,300));return ['status'=>$status,'data'=>$data];
};
$base='http://localhost/synk/tmp/'.basename($route).'?qa='.$token;
try{
 $r=$request($base,null);$check($r['status']===200,'Could not establish professor session.');$prof=$r['data'];$check(strpos($prof['php_version'],'7.1.')===0,'Use the actual PHP 7.1 server.');
 $r=$request($base.'&as=student',null,'student');$check($r['status']===200,'Could not establish student session.');$student=$r['data'];$presence=$student;
 $check($prof['student_id']===$student['student_id']&&(int)$prof['context']['offering_id']===$student['offering_id'],'The independent sessions must refer to the same enrolled class.');
 $r=$request($base.'&as=second_student&offering_id='.$student['offering_id'].'&exclude_student_id='.$student['student_id'],null,'second_student');$check($r['status']===200,'Could not establish a second enrolled student session.');$secondStudent=$r['data'];$secondPresence=$secondStudent;
 $check($secondStudent['student_id']!==$student['student_id']&&$secondStudent['offering_id']===$student['offering_id'],'The privacy test requires two different students in the same class.');
 $profCall=static function(string $action,array $payload=[])use($request,$prof):array{return $request('http://localhost/synk/professor/assessments_api_lms.php',['action'=>$action,'payload'=>$payload,'context'=>$prof['context'],'csrf_token'=>$prof['csrf_token']]);};
 $studentCall=static function(string $action,array $payload=[],array $overrides=[])use($request,$student):array{return $request('http://localhost/synk/student/assessments_api_lms.php',array_replace(['action'=>$action,'payload'=>$payload,'csrf_token'=>$student['csrf_token']],$overrides),'student');};
 $secondCall=static function(string $action,array $payload=[],array $overrides=[])use($request,$secondStudent):array{return $request('http://localhost/synk/student/assessments_api_lms.php',array_replace(['action'=>$action,'payload'=>$payload,'csrf_token'=>$secondStudent['csrf_token']],$overrides),'second_student');};
 $sample=['grading_period'=>'midterm','assessment_type'=>'quiz','title'=>$tag,'instructions'=>'Answer the programming questions.','time_limit_minutes'=>15,'questions'=>[
 ['question_type'=>'multiple_choice','prompt'=>'Choose 2 + 2.','points'=>2,'options'=>['3','4'],'answer_key'=>1],['question_type'=>'true_false','prompt'=>'2 > 3','points'=>1,'answer_key'=>false]]];
 $r=$profCall('save',$sample);$check($r['status']===200,'The professor could not save the draft.');$draft=$r['data']['assessment'];$id=$draft['assessment_id'];
 $r=$studentCall('detail',['assessment_id'=>$id]);$check($r['status']===404,'Private drafts must stay hidden through the real student endpoint.');
 $r=$profCall('publish',array_replace($sample,['assessment_id'=>$id,'version'=>1]));$check($r['status']===200&&$r['data']['assessment']['status']==='published','The professor could not publish the draft.');$published=$r['data']['assessment'];
 $r=$studentCall('list');$check($r['status']===200&&in_array($id,array_column($r['data']['assessments'],'assessment_id'),true),'The published assessment did not appear for its enrolled student.');
 foreach(['student_id','attempt_id','faculty_id','offering_id','ay_id','semester','student_term','preview_student_id']as$field){$r=$studentCall('detail',['assessment_id'=>$id,$field=>$secondStudent['student_id']]);$check($r['status']===400,'Injected identity, attempt or scope field was accepted: '.$field);}
 $r=$studentCall('list',[],['student_id'=>$secondStudent['student_id']]);$check($r['status']===400,'A top-level student selector must be rejected.');
 $r=$request('http://localhost/synk/student/assessments_api_lms.php?student_id='.$secondStudent['student_id'],['action'=>'list','csrf_token'=>$student['csrf_token']],'student');$check($r['status']===400,'API URL selectors must be rejected.');
 $r=$request('http://localhost/synk/student/assessments_api_lms.php',null,'student');$check($r['status']===405,'The student API must reject GET requests.');
 $r=$secondCall('detail',['assessment_id'=>$id],['csrf_token'=>$student['csrf_token']]);$check($r['status']===403,'Another student session must not reuse the first student CSRF token.');
 $r=$studentCall('start',['assessment_id'=>$id]);$check($r['status']===200,'An enrolled student could not start.');$start=$r['data'];
 $check(strpos(json_encode($start),'answer_key')===false&&count($start['assessment']['questions'])===2,'Student HTTP responses must never contain answer keys.');
 $check($start['result']===null,'The real endpoint must withhold results while the student can still edit.');
 $answers=[$start['assessment']['questions'][0]['question_id']=>1,$start['assessment']['questions'][1]['question_id']=>false];
 $r=$studentCall('save',['assessment_id'=>$id,'version'=>1,'answers'=>$answers]);$check($r['status']===200&&$r['data']['attempt']['answers']===$answers,'The real endpoint must preserve typed objective answers.');
 $r=$secondCall('detail',['assessment_id'=>$id]);$check($r['status']===200&&$r['data']['attempt']===null&&$r['data']['assessment']['questions']===[]&&$r['data']['result']===null,'A classmate must never see the first student attempt, questions or saved answers before starting.');
 $r=$secondCall('start',['assessment_id'=>$id]);$check($r['status']===200&&$r['data']['attempt']['attempt_id']!==$start['attempt']['attempt_id'],'Each student must receive a separate attempt.');$secondAttempt=$r['data']['attempt'];
 $otherAnswers=[$start['assessment']['questions'][0]['question_id']=>0,$start['assessment']['questions'][1]['question_id']=>true];
 $r=$secondCall('submit',['assessment_id'=>$id,'version'=>1,'answers'=>$otherAnswers]);$check($r['status']===200&&$r['data']['result']['correct_count']===0,'The second student must submit only their own answers.');
 $r=$studentCall('save',['assessment_id'=>$id,'attempt_id'=>$secondAttempt['attempt_id'],'version'=>2,'answers'=>[]]);$check($r['status']===400,'An injected classmate attempt ID must not redirect a save.');
 $r=$studentCall('submit',['assessment_id'=>$id,'student_id'=>$secondStudent['student_id'],'version'=>2,'answers'=>[]]);$check($r['status']===400,'An injected classmate student ID must not redirect submission.');
 $r=$studentCall('detail',['assessment_id'=>$id]);$check($r['status']===200&&$r['data']['attempt']['answers']===$answers&&$r['data']['attempt']['status']==='in_progress','Classmate submissions and forged selectors must leave the first student saved work unchanged.');
 $r=$studentCall('save',['assessment_id'=>$id,'version'=>1,'answers'=>[]]);$check($r['status']===409,'A stale tab must not overwrite saved answers.');
 $r=$studentCall('detail',['assessment_id'=>$id],['csrf_token'=>'wrong']);$check($r['status']===403,'CSRF must protect student reads and writes.');
 $r=$studentCall('submit',['assessment_id'=>$id,'version'=>2,'answers'=>$answers]);$check($r['status']===200&&$r['data']['attempt']['status']==='submitted','Student submission failed.');
 $check($r['data']['result']===['correct_count'=>2,'mistake_count'=>0,'pending_review_count'=>0,'checked_count'=>2]&&strpos(json_encode($r['data']),'answer_key')===false,'The real endpoint must calculate aggregate results without exposing answer keys.');
 $r=$studentCall('detail',['assessment_id'=>$id]);$check($r['status']===200&&$r['data']['result']['correct_count']===2,'Reopening a submitted assessment must return the same result.');
 $r=$studentCall('list');$listed=array_column($r['data']['assessments'],null,'assessment_id');
 $check($r['status']===200&&$listed[$id]['result']===['correct_count'=>2,'mistake_count'=>0,'pending_review_count'=>0,'checked_count'=>2]&&strpos(json_encode($listed),'answers')===false&&strpos(json_encode($listed),'answer_key')===false,'The real list endpoint must supply submitted card results without exposing private answers or keys.');
 $r=$secondCall('detail',['assessment_id'=>$id]);$check($r['status']===200&&$r['data']['attempt']['answers']===$otherAnswers&&$r['data']['result']['correct_count']===0,'The second student must retain their own answers and result after the first student submits.');
 $r=$secondCall('list');$otherListed=array_column($r['data']['assessments'],null,'assessment_id');$check($r['status']===200&&$otherListed[$id]['result']['correct_count']===0,'List-card results must be scoped to the signed-in student.');
 $r=$studentCall('submit',['assessment_id'=>$id,'version'=>2,'answers'=>[]]);$check($r['status']===200&&$r['data']['attempt']['answers']===$answers,'Repeated submission changed locked answers.');
 $r=$profCall('submissions',['assessment_id'=>$id]);$check($r['status']===200&&count($r['data']['submissions'])===2&&in_array($student['student_id'],array_column($r['data']['submissions'],'student_id'),true)&&in_array($secondStudent['student_id'],array_column($r['data']['submissions'],'student_id'),true),'Only the authorized professor can review both students responses.');
 $r=$studentCall('submissions',['assessment_id'=>$id]);$check($r['status']===400,'Students must not invoke professor-only response review.');
 $r=$profCall('save',array_replace($sample,['assessment_id'=>$id,'version'=>$published['version']]));$check($r['status']===409,'Published questions must be immutable.');
 $r=$profCall('close',['assessment_id'=>$id,'version'=>$published['version']]);$check($r['status']===200&&$r['data']['assessment']['status']==='closed','Closing failed.');
 $r=$request('http://localhost/synk/student/assessments_api_lms.php',['action'=>'list','csrf_token'=>'bad'],'anonymous');$check($r['status']===401,'Anonymous callers must not read student assessment data.');
}finally{
 $rows=synk_assessment_query_lms($lms,'SELECT assessment_id,title FROM tbl_lms_assessments WHERE title=?','s',[$tag]);
 foreach($rows as$row){if($row['title']!==$tag)throw new RuntimeException('Unexpected cleanup target.');synk_assessment_query_lms($lms,'DELETE FROM tbl_lms_assessments WHERE assessment_id=?','i',[(int)$row['assessment_id']]);}
 foreach(array_filter([$presence,$secondPresence])as$sessionPresence)synk_assessment_query_lms($conn,'DELETE FROM tbl_student_portal_sessions WHERE presence_key=? AND student_id=?','si',[$sessionPresence['presence_key'],$sessionPresence['student_id']]);
 foreach(array_keys($jars)as$role){try{$request($base.'&logout=1',null,$role);}catch(Throwable $ignored){}if(is_file($jars[$role]))unlink($jars[$role]);}if(is_file($route))unlink($route);
}
$check($conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC)===$before,'Core academic, enrollment, faculty, workload or scheduling records changed.');
foreach(array_filter([$presence,$secondPresence])as$sessionPresence)$check(synk_assessment_query_lms($conn,'SELECT presence_key FROM tbl_student_portal_sessions WHERE presence_key=? AND student_id=?','si',[$sessionPresence['presence_key'],$sessionPresence['student_id']])===[],'The exact QA presence record was not removed.');
$check($lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC)===$lmsBefore,'Temporary assessment, answer, or attempt data remains.');
echo 'LMS delivery HTTP: ',$checks,' checks passed on PHP ',$prof['php_version'],'. Temporary records removed.',PHP_EOL;
