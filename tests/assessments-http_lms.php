<?php
// Exercise the actual JSON endpoint with an isolated QA session. No account records are written.
// Temporary assessment drafts are tagged and removed by exact ID in finally.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/assessment_helper_lms.php';
require_once __DIR__.'/../backend/db_lms.php';
$lms=synk_database_connection_lms($conn);$root=dirname(__DIR__);$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$tag='AssessmentHttpQA'.bin2hex(random_bytes(12));$token=bin2hex(random_bytes(24));
$route=$root.'/tmp/assessment-http-'.bin2hex(random_bytes(10)).'_lms.php';$cookieJar=$root.'/temp/'.$tag.'.cookies';
$coreSnapshot=static function()use($conn):array{return $conn->query('CHECKSUM TABLE tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_faculty,tbl_class_schedule,tbl_faculty_workload_sched,tbl_student_management,tbl_student_management_enrolled_subjects,tbl_academic_settings')->fetch_all(MYSQLI_ASSOC);};
$lmsSnapshot=static function()use($lms):array{return $lms->query('CHECKSUM TABLE tbl_lms_assessments,tbl_lms_assessment_questions,tbl_lms_assessment_logs')->fetch_all(MYSQLI_ASSOC);};
$before=$coreSnapshot();$lmsBefore=$lmsSnapshot();
$bootstrap= <<<'PHP'
<?php
if (!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'], true) || !is_string($_GET['qa']??null) || !hash_equals(QA_TOKEN,$_GET['qa'])) { http_response_code(404); exit; }
require CORE_DB;
require CLASS_HELPER;
session_start();
header('Content-Type: application/json');header('Cache-Control: no-store');
if (!empty($_GET['logout'])) { $_SESSION=[];session_destroy();echo '{"ok":true}';exit; }
$users=$conn->query("SELECT DISTINCT u.user_id,l.faculty_id FROM tbl_useraccount u JOIN tbl_useraccount_faculty_links l ON l.user_id=u.user_id JOIN tbl_faculty f ON f.faculty_id=l.faculty_id JOIN tbl_faculty_workload_sched fw ON fw.faculty_id=f.faculty_id WHERE u.status='active' AND f.status='active' ORDER BY u.user_id")->fetch_all(MYSQLI_ASSOC);
$term=synk_fetch_current_academic_term($conn);
foreach ($users as $user) {
    $account=synk_find_useraccount_by_id($conn,(int)$user['user_id']);
    if (!synk_useraccount_has_active_role($conn,$account,'professor')) continue;
    $classes=synk_professor_fetch_subject_rows_by_academic_year($conn,(int)$user['faculty_id'],(int)$term['ay_id'],(int)$term['semester']);
    if (!$classes) continue;
    $_SESSION=['user_id'=>(int)$user['user_id'],'role'=>'professor','username'=>'Assessment QA','email'=>$account['email'],'lms_assessment_csrf'=>bin2hex(random_bytes(32))];
    $url=synk_professor_class_url_lms($classes[0],'assessments');$context=[];parse_str((string)parse_url($url,PHP_URL_QUERY),$context);
    echo json_encode(['context'=>$context,'csrf_token'=>$_SESSION['lms_assessment_csrf'],'php_version'=>PHP_VERSION]);exit;
}
http_response_code(503);echo '{"message":"No assigned active professor is available for the HTTP test."}';
PHP;
file_put_contents($route,str_replace(['QA_TOKEN','CORE_DB','CLASS_HELPER'],[var_export($token,true),var_export($root.'/backend/db.php',true),var_export($root.'/backend/professor_class_helper_lms.php',true)],$bootstrap));
$request=static function(string $url,?string $body=null,bool $cookies=true,string $method='POST')use($cookieJar):array{
    $curl=curl_init($url);$options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>false,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false];
    if($cookies){$options[CURLOPT_COOKIEJAR]=$cookieJar;$options[CURLOPT_COOKIEFILE]=$cookieJar;}
    if($method==='POST'){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=$body??'';$options[CURLOPT_HTTPHEADER]=['Content-Type: application/json'];}
    curl_setopt_array($curl,$options);$text=curl_exec($curl);if($text===false)throw new RuntimeException(curl_error($curl));
    $status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$data=json_decode($text,true);
    if(!is_array($data))throw new RuntimeException('Invalid API JSON: '.substr($text,0,300));return ['status'=>$status,'data'=>$data];
};
$bootstrapUrl='http://localhost/synk/tmp/'.basename($route).'?qa='.$token;
$apiUrl='http://localhost/synk/professor/assessments_api_lms.php';
try {
    $r=$request($bootstrapUrl,null,true,'GET');$check($r['status']===200,'Could not establish the isolated assessment test session.');
    $session=$r['data'];$check(strpos($session['php_version'],'7.1.')===0,'The HTTP test must run on the actual PHP 7.1 server.');
    $call=static function(string $action,array $payload=[],array $overrides=[])use($session,$request,$apiUrl):array{
        return $request($apiUrl,json_encode(array_replace(['action'=>$action,'payload'=>$payload,'context'=>$session['context'],'csrf_token'=>$session['csrf_token']],$overrides)));
    };
    $sample=['grading_period'=>'midterm','assessment_type'=>'quiz','title'=>$tag,'instructions'=>str_repeat('📝',20000),
        'opens_at'=>'2026-11-15T08:00','due_at'=>'2026-11-15T09:00','time_limit_minutes'=>30,'questions'=>[
            ['question_type'=>'multiple_choice','prompt'=>"What does `echo` output?\n```php\n<?php\n    echo \"<script>alert(1)</script>\";\n?>\n```",'points'=>2.5,'options'=>['A','B'],'answer_key'=>1],
            ['question_type'=>'true_false','prompt'=>'Test statement.','points'=>1,'answer_key'=>false],
            ['question_type'=>'short_answer','prompt'=>'Type a name.','points'=>2,'answer_key'=>['PHP']],
            ['question_type'=>'essay','prompt'=>'Explain.','points'=>5]
        ]];
    $r=$call('save',$sample);$check($r['status']===200&&$r['data']['ok'],'The real JSON endpoint could not create a draft.');
    $record=$r['data']['assessment'];$id=$record['assessment_id'];
    $check($record['total_points']===10.5&&count($record['questions'])===4&&$record['questions'][1]['answer_key']===false,'Question types and totals survive the real HTTP request.');
    $check($record['instructions']===$sample['instructions'],'The full allowed Unicode instructions save without truncation.');
    $check($record['questions'][0]['prompt']===$sample['questions'][0]['prompt'],'Code fences, literal HTML, and code indentation survive a real database save.');
    $r=$call('detail',['assessment_id'=>$id]);$check($r['status']===200&&$r['data']['assessment']['title']===$tag,'The same session can reopen its own assessment.');
    $r=$call('save',array_replace($sample,['assessment_id'=>$id,'version'=>1,'grading_period'=>'final']));
    $check($r['status']===200&&$r['data']['assessment']['version']===2,'A signed update saves and advances its revision.');
    $r=$call('save',array_replace($sample,['assessment_id'=>$id,'version'=>1]));$check($r['status']===409,'A stale HTTP update must be rejected.');
    $r=$call('duplicate',['assessment_id'=>$id,'version'=>2,'grading_period'=>'midterm']);
    $check($r['status']===200&&$r['data']['assessment']['grading_period']==='midterm'&&$r['data']['assessment']['opens_at']===null,'HTTP duplication preserves questions but clears draft dates.');
    $check($r['data']['assessment']['questions'][0]['prompt']===$sample['questions'][0]['prompt'],'Real database duplication preserves code formatting exactly.');
    $copyId=$r['data']['assessment']['assessment_id'];
    foreach ([['csrf_token'=>'bad'],['csrf_token'=>['bad']],['context'=>array_replace($session['context'],['semester'=>$session['context']['semester']==='1'?'2':'1'])],
        ['context'=>array_diff_key($session['context'],['signature'=>true])]] as $bad) {
        $r=$call('detail',['assessment_id'=>$id],$bad);$check($r['status']===403&&empty($r['data']['assessment']),'A CSRF or signed-class failure must not reveal draft questions.');
    }
    $r=$call('detail',['assessment_id'=>2147483647]);$check($r['status']===404,'Unknown assessment IDs must not return data.');
    $r=$call('save',array_replace($sample,['title'=>'']));$check($r['status']===422,'The real endpoint validates required draft fields.');
    $r=$call('release_grades',['assessment_id'=>$id]);$check($r['status']===400,'The workspace must reject unimplemented grade release.');
    $r=$call('archive',['assessment_id'=>$copyId,'version'=>1]);$check($r['status']===200,'A draft can be archived through the real API.');
    $r=$call('list');$ids=array_column($r['data']['assessments'],'assessment_id');
    $check($r['status']===200&&in_array($id,$ids,true)&&!in_array($copyId,$ids,true),'The refreshed list includes the draft and excludes the archived copy.');
    $r=$request($apiUrl,'invalid json');$check($r['status']===400,'Malformed JSON must be rejected.');
    $r=$request($apiUrl,str_repeat('a',1048577));$check($r['status']===413,'Oversized assessment requests must be rejected.');
    $r=$request($apiUrl,null,true,'GET');$check($r['status']===405,'The assessment API must reject GET.');
    $r=$request($apiUrl,'{}',false);$check($r['status']===401,'An anonymous client must not read drafts or answer keys.');
} finally {
    // The random title tag identifies only records created by this run. Check IDs before deleting.
    $testRows=synk_assessment_query_lms($lms,'SELECT assessment_id,title FROM tbl_lms_assessments WHERE title LIKE ?','s',['%'.$tag.'%']);
    foreach($testRows as $testRow){
        if(strpos($testRow['title'],$tag)===false)throw new RuntimeException('Unexpected assessment cleanup target.');
        synk_assessment_query_lms($lms,'DELETE FROM tbl_lms_assessments WHERE assessment_id=?','i',[(int)$testRow['assessment_id']]);
    }
    try{$request($bootstrapUrl.'&logout=1',null,true,'GET');}catch(Throwable $ignored){}
    if(is_file($route))unlink($route);if(is_file($cookieJar))unlink($cookieJar);
}
$check($coreSnapshot()===$before,'Real API operations must not change core account, academic, roster, or scheduling records.');
$check($lmsSnapshot()===$lmsBefore,'Temporary HTTP assessment records and logs were not fully removed.');
echo 'LMS assessment HTTP: ',$checks,' checks passed on PHP ',$session['php_version'],'. Temporary drafts removed; core records unchanged.',PHP_EOL;
