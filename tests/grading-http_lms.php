<?php
// Actual PHP 7.1 HTTP endpoint. Uses an existing assigned class with no grading
// setup, tags every temporary component, and removes only those exact schemes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/db_lms.php';
require_once __DIR__.'/../backend/grading_helper_lms.php';
$lms=synk_database_connection_lms($conn);$root=dirname(__DIR__);$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$tag='GradingHttpQA'.bin2hex(random_bytes(12));$token=bin2hex(random_bytes(24));
$route=$root.'/tmp/grading-http-'.bin2hex(random_bytes(10)).'_lms.php';$cookieJar=$root.'/temp/'.$tag.'.cookies';
$snapshot=static function(mysqli $db,string $tables):array{return $db->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC);};
$coreTables='tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_faculty,tbl_class_schedule,tbl_faculty_workload_sched,tbl_student_management,tbl_student_management_enrolled_subjects,tbl_academic_settings';
$lmsTables='tbl_lms_grading_schemes,tbl_lms_grading_components,tbl_lms_grading_logs,tbl_lms_assessments,tbl_lms_assessment_questions,tbl_lms_assessment_logs';
$coreBefore=$snapshot($conn,$coreTables);$lmsBefore=$snapshot($lms,$lmsTables);
$check($lms->query('SELECT DATABASE() AS name')->fetch_assoc()['name']==='synk_lms_db','Grading must use the configured separate LMS database.');
$check($conn->query("SHOW TABLES LIKE 'tbl_lms_%'")->num_rows===0,'LMS assessment and grading tables must not exist in the shared core database.');
$bootstrap= <<<'PHP'
<?php
if (!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'], true) || !is_string($_GET['qa']??null) || !hash_equals(QA_TOKEN,$_GET['qa'])) { http_response_code(404); exit; }
require CORE_DB;
require GRADING_HELPER;
session_start();header('Content-Type: application/json');header('Cache-Control: no-store');
if (!empty($_GET['logout'])) { $_SESSION=[];session_destroy();echo '{"ok":true}';exit; }
$lms=synk_database_connection_lms($conn);
$users=$conn->query("SELECT DISTINCT u.user_id,l.faculty_id FROM tbl_useraccount u JOIN tbl_useraccount_faculty_links l ON l.user_id=u.user_id JOIN tbl_faculty f ON f.faculty_id=l.faculty_id JOIN tbl_faculty_workload_sched fw ON fw.faculty_id=f.faculty_id WHERE u.status='active' AND f.status='active' ORDER BY u.user_id")->fetch_all(MYSQLI_ASSOC);
$term=synk_fetch_current_academic_term($conn);
foreach ($users as $user) {
    $account=synk_find_useraccount_by_id($conn,(int)$user['user_id']);
    if (!synk_useraccount_has_active_role($conn,$account,'professor')) continue;
    $classes=synk_professor_fetch_subject_rows_by_academic_year($conn,(int)$user['faculty_id'],(int)$term['ay_id'],(int)$term['semester']);
    foreach ($classes as $class) {
        $context=['portal'=>['faculty_id'=>$user['faculty_id']],'class'=>$class];
        if(synk_grading_detail_lms($lms,$context,'midterm')['scheme_id']||synk_grading_detail_lms($lms,$context,'final')['scheme_id'])continue;
        $_SESSION=['user_id'=>(int)$user['user_id'],'role'=>'professor','username'=>'Grading QA','email'=>$account['email'],'lms_grading_csrf'=>bin2hex(random_bytes(32))];
        $url=synk_professor_class_url_lms($class,'grading');$params=[];parse_str((string)parse_url($url,PHP_URL_QUERY),$params);
        echo json_encode(['context'=>$params,'csrf_token'=>$_SESSION['lms_grading_csrf'],'faculty_id'=>(int)$user['faculty_id'],'user_id'=>(int)$user['user_id'],'php_version'=>PHP_VERSION]);exit;
    }
}
http_response_code(503);echo '{"message":"No unused assigned class is available for a grading HTTP check."}';
PHP;
file_put_contents($route,str_replace(['QA_TOKEN','CORE_DB','GRADING_HELPER'],[var_export($token,true),var_export($root.'/backend/db.php',true),var_export($root.'/backend/grading_api_helper_lms.php',true)],$bootstrap));
$request=static function(string $url,?string $body=null,bool $cookies=true,string $method='POST')use($cookieJar):array{
    $curl=curl_init($url);$options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false];
    if($cookies){$options[CURLOPT_COOKIEJAR]=$cookieJar;$options[CURLOPT_COOKIEFILE]=$cookieJar;}
    if($method==='POST'){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=$body??'';$options[CURLOPT_HTTPHEADER]=['Content-Type: application/json'];}
    curl_setopt_array($curl,$options);$text=curl_exec($curl);if($text===false)throw new RuntimeException(curl_error($curl));
    $status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$data=json_decode($text,true);
    if(!is_array($data))throw new RuntimeException('Invalid API JSON: '.substr($text,0,300));return ['status'=>$status,'data'=>$data];
};
$bootstrapUrl='http://localhost/synk/tmp/'.basename($route).'?qa='.$token;$apiUrl='http://localhost/synk/professor/grading_api_lms.php';
try {
    $r=$request($bootstrapUrl,null,true,'GET');$check($r['status']===200,'Could not establish a safe grading test session.');$session=$r['data'];
    $check(strpos($session['php_version'],'7.1.')===0,'This test must exercise the actual PHP 7.1 server.');
    $call=static function(string $action,array $payload=[],array $overrides=[])use($session,$request,$apiUrl):array{
        return $request($apiUrl,json_encode(array_replace(['action'=>$action,'payload'=>$payload,'context'=>$session['context'],'csrf_token'=>$session['csrf_token']],$overrides)));
    };
    $r=$call('get');$check($r['status']===200&&$r['data']['schemes']['midterm']['version']===0&&$r['data']['schemes']['final']['version']===0,'Unused periods must return empty setups.');
    $sample=['grading_period'=>'midterm','version'=>0,'faculty_id'=>2147483000,'components'=>[
        ['name'=>$tag.' Participation','description'=>str_repeat('📝',2000),'weight'=>'33.33'],
        ['name'=>$tag.' Written','description'=>'Literal <img src=x onerror=alert(1)>','weight'=>'33.33'],
        ['name'=>$tag.' Output','description'=>'Programming outputs','weight'=>'33.34']
    ]];
    $r=$call('save',$sample);$check($r['status']===200&&$r['data']['ok'],'The real endpoint must save balanced grading components.');$midterm=$r['data']['scheme'];
    $check($midterm['version']===1&&$midterm['total_weight']==100&&$midterm['components'][0]['description']===$sample['components'][0]['description'],'Weights and full Unicode descriptions must round-trip.');
    $scope=synk_grading_query_lms($lms,'SELECT faculty_id,created_by_user_id FROM tbl_lms_grading_schemes WHERE scheme_id=?','i',[$midterm['scheme_id']])[0];
    $check($scope['faculty_id']===$session['faculty_id']&&$scope['created_by_user_id']===$session['user_id'],'Client identity fields must not override the professor session.');
    $r=$call('get');$check($r['data']['schemes']['final']['version']===0,'Saving Midterm must leave Final unconfigured.');
    $r=$call('save',array_replace($sample,['grading_period'=>'final']));$check($r['status']===200,'Final must save separately.');$final=$r['data']['scheme'];
    $payload=$midterm;$payload['components']=array_reverse($payload['components']);$payload['components'][0]['name']=$tag.' Renamed <script>alert(1)</script>';
    $payload['components'][0]['weight']='8.34';
    array_unshift($payload['components'],['name'=>$tag.' New first component','description'=>'Prepended component','weight'=>'25']);
    $r=$call('save',$payload);$check($r['status']===200&&$r['data']['scheme']['version']===2&&array_column(array_slice($r['data']['scheme']['components'],1),'component_id')===array_reverse(array_column($midterm['components'],'component_id')),'Adding a component first must preserve the previous IDs and advance revision.');$edited=$r['data']['scheme'];
    $check($edited['components'][0]['name']===$tag.' New first component'&&!in_array($edited['components'][0]['component_id'],array_column($midterm['components'],'component_id'),true),'The new first component requires its own stable database ID.');
    $storedOrder=synk_grading_query_lms($lms,"SELECT component_id,sort_order FROM tbl_lms_grading_components WHERE scheme_id=? AND status='active' ORDER BY sort_order",'i',[$edited['scheme_id']]);
    $check(array_column($storedOrder,'component_id')===array_column($edited['components'],'component_id')&&array_column($storedOrder,'sort_order')===[1,2,3,4],'The LMS database must store the new first component before all prior components.');
    $r=$call('save',$payload);$check($r['status']===409,'Stale edits must be rejected.');
    $invalid=$edited;$invalid['components'][0]['weight']='24.99';$r=$call('save',$invalid);$check($r['status']===422,'The endpoint must reject totals below 100%.');
    $invalid=$edited;$invalid['components'][0]['weight']='25.01';$r=$call('save',$invalid);$check($r['status']===422,'The endpoint must reject totals above 100%.');
    $invalid=$edited;$invalid['components'][0]['component_id']=$final['components'][0]['component_id'];$r=$call('save',$invalid);$check($r['status']===409,'Final component IDs must not be accepted in Midterm.');
    $r=$call('get');$check($r['data']['schemes']['final']===$final&&$r['data']['schemes']['midterm']===$edited,'Failed saves and Midterm edits must leave the saved schemes unchanged.');
    foreach ([['csrf_token'=>'bad'],['csrf_token'=>[]],['context'=>array_replace($session['context'],['semester'=>$session['context']['semester']==='1'?'2':'1'])],
        ['context'=>array_diff_key($session['context'],['signature'=>true])],['context'=>array_replace($session['context'],['view'=>'assessments'])]] as $bad){
        $r=$call('get',[],$bad);$check($r['status']===403&&empty($r['data']['schemes']),'Invalid CSRF or signed context must not expose grading data.');
    }
    $r=$call('delete');$check($r['status']===400,'Unsupported grading actions must be rejected.');
    $r=$request($apiUrl,'invalid json');$check($r['status']===400,'Malformed JSON must be rejected.');
    $r=$request($apiUrl,str_repeat('a',524289));$check($r['status']===413,'Oversized grading requests must be rejected.');
    $r=$request($apiUrl,null,true,'GET');$check($r['status']===405,'The grading endpoint must reject GET.');
    $r=$request($apiUrl,'{}',false);$check($r['status']===401,'Anonymous clients must not read grading setups.');
} finally {
    $rows=synk_grading_query_lms($lms,'SELECT DISTINCT scheme_id FROM tbl_lms_grading_components WHERE name LIKE ?','s',[$tag.'%']);
    foreach($rows as $row){
        $components=synk_grading_query_lms($lms,'SELECT name FROM tbl_lms_grading_components WHERE scheme_id=?','i',[(int)$row['scheme_id']]);
        foreach($components as $component)if(strpos($component['name'],$tag)!==0)throw new RuntimeException('Unexpected grading cleanup target.');
        synk_grading_query_lms($lms,'DELETE FROM tbl_lms_grading_schemes WHERE scheme_id=?','i',[(int)$row['scheme_id']]);
    }
    try{$request($bootstrapUrl.'&logout=1',null,true,'GET');}catch(Throwable $ignored){}
    if(is_file($route))unlink($route);if(is_file($cookieJar))unlink($cookieJar);
}
$check($snapshot($conn,$coreTables)===$coreBefore,'The grading API changed core accounts, workload, scheduling, or academic records.');
$check($snapshot($lms,$lmsTables)===$lmsBefore,'Temporary grading records were not completely removed, or assessments changed.');
echo 'LMS grading HTTP: ',$checks,' checks passed on PHP ',$session['php_version'],'. Temporary schemes removed; core records unchanged.',PHP_EOL;
