<?php
// Exercise the actual web endpoints on the local PHP runtime. Only tagged LMS
// records in an otherwise unused class setup are created, then removed exactly.
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/gradebook_api_helper_lms.php';
$lms=synk_database_connection_lms($conn);$root=dirname(__DIR__);$tag='GradebookHttpQA'.bin2hex(random_bytes(10));$token=bin2hex(random_bytes(24));$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$route=$root.'/tmp/gradebook-http-'.bin2hex(random_bytes(10)).'_lms.php';$cookies=$root.'/temp/'.$tag.'.cookies';
$coreTables='tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_faculty,tbl_class_schedule,tbl_faculty_workload_sched,tbl_student_management,tbl_student_management_enrolled_subjects,tbl_academic_settings';
$lmsTables='tbl_lms_gradebook_periods,tbl_lms_gradebook_items,tbl_lms_gradebook_scores,tbl_lms_gradebook_logs,tbl_lms_grading_schemes,tbl_lms_grading_components,tbl_lms_grading_logs';
$snapshot=static function(mysqli $db,string $tables):array{return $db->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC);};
$coreBefore=$snapshot($conn,$coreTables);$lmsBefore=$snapshot($lms,$lmsTables);
$bootstrap= <<<'PHP'
<?php
if (!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)||!is_string($_GET['qa']??null)||!hash_equals(QA_TOKEN,$_GET['qa'])) {http_response_code(404);exit;}
require CORE_DB;require GRADEBOOK_HELPER;
session_start();header('Content-Type: application/json');header('Cache-Control: no-store');
if (!empty($_GET['logout'])) {$_SESSION=[];session_destroy();echo '{"ok":true}';exit;}
$lms=synk_database_connection_lms($conn);$term=synk_fetch_current_academic_term($conn);
$users=$conn->query("SELECT DISTINCT u.user_id,l.faculty_id FROM tbl_useraccount u JOIN tbl_useraccount_faculty_links l ON l.user_id=u.user_id JOIN tbl_faculty f ON f.faculty_id=l.faculty_id JOIN tbl_faculty_workload_sched fw ON fw.faculty_id=f.faculty_id WHERE u.status='active' AND f.status='active' ORDER BY u.user_id")->fetch_all(MYSQLI_ASSOC);
foreach ($users as $user) {
    $account=synk_find_useraccount_by_id($conn,(int)$user['user_id']);if(!synk_useraccount_has_active_role($conn,$account,'professor'))continue;
    $classes=synk_professor_fetch_subject_rows_by_academic_year($conn,(int)$user['faculty_id'],(int)$term['ay_id'],(int)$term['semester']);
    foreach ($classes as $class) {
        $context=['portal'=>['faculty_id'=>(int)$user['faculty_id']],'class'=>$class];
        if(synk_gradebook_period_lms($lms,$context,'midterm')['book_id']||synk_gradebook_period_lms($lms,$context,'final')['book_id']||synk_grading_detail_lms($lms,$context,'midterm')['scheme_id']||synk_grading_detail_lms($lms,$context,'final')['scheme_id'])continue;
        $roster=synk_gradebook_roster_lms($conn,$context);if(!$roster)continue;
        $_SESSION=['user_id'=>(int)$user['user_id'],'role'=>'professor','username'=>'Gradebook QA','lms_grading_csrf'=>bin2hex(random_bytes(32)),'lms_gradebook_csrf'=>bin2hex(random_bytes(32))];
        $book=[];$grading=[];parse_str((string)parse_url(synk_professor_class_url_lms($class,'gradebook'),PHP_URL_QUERY),$book);parse_str((string)parse_url(synk_professor_class_url_lms($class,'grading'),PHP_URL_QUERY),$grading);
        echo json_encode(['book_context'=>$book,'grading_context'=>$grading,'book_csrf'=>$_SESSION['lms_gradebook_csrf'],'grading_csrf'=>$_SESSION['lms_grading_csrf'],'student_id'=>(int)$roster[0]['student_id'],'php_version'=>PHP_VERSION]);exit;
    }
}
http_response_code(503);echo '{"message":"No unused assigned class with a named roster is available for gradebook HTTP checks."}';
PHP;
file_put_contents($route,str_replace(['QA_TOKEN','CORE_DB','GRADEBOOK_HELPER'],[var_export($token,true),var_export($root.'/backend/db.php',true),var_export($root.'/backend/gradebook_api_helper_lms.php',true)],$bootstrap));
$request=static function(string $url,?array $input=null,bool $withCookies=true,string $method='POST')use($cookies):array {
    $curl=curl_init($url);$options=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>25];
    if($withCookies){$options[CURLOPT_COOKIEFILE]=$cookies;$options[CURLOPT_COOKIEJAR]=$cookies;}
    if($method==='POST'){$options[CURLOPT_POST]=true;$options[CURLOPT_POSTFIELDS]=json_encode($input);$options[CURLOPT_HTTPHEADER]=['Content-Type: application/json'];}
    curl_setopt_array($curl,$options);$body=curl_exec($curl);if($body===false)throw new RuntimeException(curl_error($curl));$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    $data=json_decode($body,true);if(!is_array($data))throw new RuntimeException('Endpoint returned invalid JSON: '.substr($body,0,400));return ['status'=>$status,'data'=>$data];
};
$url='http://localhost/synk/tmp/'.basename($route).'?qa='.$token;$apiUrl='http://localhost/synk/professor/gradebook_api_lms.php';
try {
    $boot=$request($url,null,true,'GET');$check($boot['status']===200,'Unable to establish a safe test session: '.json_encode($boot));$session=$boot['data'];
    $check(strpos($session['php_version'],'7.1.')===0,'The HTTP tests must run on the actual PHP 7.1 web server.');
    $bookCall=static function(string $action,array $payload=[],array $overrides=[])use($request,$session,$apiUrl):array {return $request($apiUrl,array_replace(['action'=>$action,'payload'=>$payload,'context'=>$session['book_context'],'csrf_token'=>$session['book_csrf']],$overrides));};
    $gradingCall=static function(array $payload)use($request,$session):array {return $request('http://localhost/synk/professor/grading_api_lms.php',['action'=>'save','payload'=>$payload,'context'=>$session['grading_context'],'csrf_token'=>$session['grading_csrf']]);};
    $r=$bookCall('get');$check($r['status']===200&&$r['data']['periods']['midterm']['version']===0&&$r['data']['periods']['final']['version']===0,'An unused gradebook must load without creating periods.');
    $scheme=$gradingCall(['grading_period'=>'midterm','version'=>0,'components'=>[['name'=>$tag.' Exam','weight'=>'60'],['name'=>$tag.' Attendance','weight'=>'40']]]);
    $check($scheme['status']===200,'Unable to create the tagged grading setup.');$scheme=$scheme['data']['scheme'];
    $r=$bookCall('save_item',['grading_period'=>'midterm','version'=>0,'scheme_version'=>$scheme['version'],'component_id'=>$scheme['components'][0]['component_id'],'entry_kind'=>'score','title'=>$tag.' Written exam','max_points'=>50]);
    $check($r['status']===200,'Actual endpoint failed to create a manual exam: '.json_encode($r));$data=$r['data'];$exam=$data['periods']['midterm']['items'][0]['item_id'];
    $revision=static function(array $data,string $period='midterm'):array {$b=$data['periods'][$period];return ['grading_period'=>$period,'version'=>$b['version'],'scheme_version'=>$b['scheme']['version']];};
    $r=$bookCall('save_item',$revision($data)+['component_id'=>$scheme['components'][1]['component_id'],'entry_kind'=>'attendance','title'=>$tag.' Attendance','max_points'=>1]);$check($r['status']===200,'Attendance session creation failed.');$data=$r['data'];$attendance=$data['periods']['midterm']['items'][1]['item_id'];
    $stale=$revision($data);$r=$bookCall('save_scores',$revision($data)+['item_id'=>$exam,'scores'=>[['student_id'=>$session['student_id'],'points'=>'40.00']]]);$check($r['status']===200,'Actual endpoint failed to save exam points.');$data=$r['data'];
    $r=$bookCall('save_scores',$revision($data)+['item_id'=>$attendance,'scores'=>[['student_id'=>$session['student_id'],'points'=>1]]]);$check($r['status']===200,'Actual endpoint failed to save attendance.');$data=$r['data'];
    $result=$data['periods']['midterm']['results'][$session['student_id']];$check(abs($result['percent']-88)<0.0000001&&$result['rating']==2,'The web endpoint must compute 40/50 × 60 + 1/1 × 40 = 88.');
    $r=$bookCall('save_item',$revision($data)+['component_id'=>$scheme['components'][0]['component_id'],'entry_kind'=>'score','title'=>$tag.' Second exam','max_points'=>50]);$check($r['status']===200,'Unable to assign a second entry to the same grading component.');$data=$r['data'];$secondExam=$data['periods']['midterm']['items'][2]['item_id'];
    $check(abs($data['periods']['midterm']['results'][$session['student_id']]['percent']-64)<0.0000001,'The actual endpoint must include the missing second exam as zero: (40+0)/(50+50) × 60 + 40 = 64.');
    $r=$bookCall('save_scores',$revision($data)+['item_id'=>$secondExam,'scores'=>[['student_id'=>$session['student_id'],'points'=>10]]]);$check($r['status']===200,'Unable to update the second exam score.');$data=$r['data'];
    $check(abs($data['periods']['midterm']['results'][$session['student_id']]['percent']-70)<0.0000001,'Recording the second score must immediately recalculate the component and period total.');
    $check($data['summary'][$session['student_id']]['average']===null,'Final must remain independent and leave the average incomplete.');
    $finalScheme=$gradingCall(['grading_period'=>'final','version'=>0,'components'=>[['name'=>$tag.' Final exam','weight'=>100]]]);
    $check($finalScheme['status']===200,'Unable to create the tagged Final grading setup.');$finalScheme=$finalScheme['data']['scheme'];
    $r=$bookCall('save_item',['grading_period'=>'final','version'=>0,'scheme_version'=>$finalScheme['version'],'component_id'=>$finalScheme['components'][0]['component_id'],'entry_kind'=>'score','title'=>$tag.' Final exam','max_points'=>100]);
    $check($r['status']===200,'Unable to create a Final exam through the actual endpoint.');$data=$r['data'];$finalExam=$data['periods']['final']['items'][0]['item_id'];
    $check(abs($data['periods']['midterm']['results'][$session['student_id']]['percent']-70)<0.0000001 && $data['periods']['final']['results'][$session['student_id']]['percent']==0 && abs($data['summary'][$session['student_id']]['average']-35)<0.0000001,'The endpoint must combine configured Midterm and zero-score Final periods immediately: '.json_encode($data['summary'][$session['student_id']]));
    $r=$bookCall('save_scores',$revision($data,'final')+['item_id'=>$finalExam,'scores'=>[['student_id'=>$session['student_id'],'points'=>80]]]);
    $check($r['status']===200,'Unable to save the Final exam score.');$data=$r['data'];
    $check($data['summary'][$session['student_id']]['midterm']==70 && $data['summary'][$session['student_id']]['final']==80 && $data['summary'][$session['student_id']]['average']==75 && $data['summary'][$session['student_id']]['rating']==3,'Saving Final must return the synchronized semester average and legend rating in the same response.');
    $r=$bookCall('save_scores',$revision($data)+['item_id'=>$secondExam,'scores'=>[['student_id'=>$session['student_id'],'points'=>50]]]);
    $check($r['status']===200,'Unable to update a Midterm score after Final has been recorded.');$data=$r['data'];
    $check($data['summary'][$session['student_id']]['midterm']==94 && $data['summary'][$session['student_id']]['final']==80 && $data['summary'][$session['student_id']]['average']==87 && $data['summary'][$session['student_id']]['rating']==2,'Updating Midterm must preserve Final and recalculate the semester result and rating immediately.');
    $r=$bookCall('save_scores',$stale+['item_id'=>$exam,'scores'=>[['student_id'=>$session['student_id'],'points'=>10]]]);$check($r['status']===409,'The actual endpoint must reject a stale gradebook revision.');
    $r=$bookCall('save_scores',$revision($data)+['item_id'=>$exam,'scores'=>[['student_id'=>$session['student_id'],'points'=>20],['student_id'=>2147483000,'points'=>10]]]);$check($r['status']===422,'An out-of-roster student must reject the whole score batch.');
    $unchanged=$bookCall('get');$check($unchanged['data']['periods']['midterm']['version']===$data['periods']['midterm']['version']&&$unchanged['data']['periods']['midterm']['scores'][$exam][$session['student_id']]['points']==40,'A rejected batch must roll back valid student changes and revision increments.');
    foreach ([['csrf_token'=>'bad'],['context'=>array_diff_key($session['book_context'],['signature'=>true])],['context'=>$session['grading_context']]] as $bad){$r=$bookCall('get',[],$bad);$check($r['status']===403&&!isset($r['data']['roster']),'Invalid CSRF or signed views must not expose the gradebook roster.');}
    $r=$bookCall('save_scores',$revision($data)+['item_id'=>$exam,'scores'=>[['student_id'=>$session['student_id'],'points'=>51]]]);$check($r['status']===422,'The web endpoint must reject scores above the maximum.');
    $r=$bookCall('get',[],['faculty_id'=>1]);$check($r['status']===400,'Identity overrides must be rejected.');
    $r=$request($apiUrl,null,true,'GET');$check($r['status']===405,'GET must not mutate or read protected gradebook data.');
    $r=$request($apiUrl,[],false);$check($r['status']===401,'Anonymous users cannot access the gradebook.');
} finally {
    $schemes=synk_grading_query_lms($lms,'SELECT DISTINCT scheme_id FROM tbl_lms_grading_components WHERE name LIKE ?','s',[$tag.'%']);
    foreach ($schemes as $scheme) {
        $id=(int)$scheme['scheme_id'];$components=synk_grading_query_lms($lms,'SELECT name FROM tbl_lms_grading_components WHERE scheme_id=?','i',[$id]);
        foreach($components as $component)if(strpos($component['name'],$tag)!==0)throw new RuntimeException('Refusing cleanup of a non-test grading component.');
        $books=synk_grading_query_lms($lms,'SELECT book_id FROM tbl_lms_gradebook_periods WHERE scheme_id=?','i',[$id]);
        foreach($books as $book){$bookId=(int)$book['book_id'];$items=synk_grading_query_lms($lms,'SELECT item_id,title FROM tbl_lms_gradebook_items WHERE book_id=?','i',[$bookId]);foreach($items as $item){if(strpos($item['title'],$tag)!==0)throw new RuntimeException('Refusing cleanup of a non-test gradebook entry.');synk_grading_query_lms($lms,'DELETE FROM tbl_lms_gradebook_scores WHERE item_id=?','i',[(int)$item['item_id']]);}synk_grading_query_lms($lms,'DELETE FROM tbl_lms_gradebook_items WHERE book_id=?','i',[$bookId]);synk_grading_query_lms($lms,'DELETE FROM tbl_lms_gradebook_logs WHERE book_id=?','i',[$bookId]);synk_grading_query_lms($lms,'DELETE FROM tbl_lms_gradebook_periods WHERE book_id=?','i',[$bookId]);}
        synk_grading_query_lms($lms,'DELETE FROM tbl_lms_grading_schemes WHERE scheme_id=?','i',[$id]);
    }
    try{$request($url.'&logout=1',null,true,'GET');}catch(Throwable $ignored){}
    if(is_file($route))unlink($route);if(is_file($cookies))unlink($cookies);
}
$check($snapshot($conn,$coreTables)===$coreBefore,'Core schedules, workload, profiles or academic settings changed during HTTP checks.');
$check($snapshot($lms,$lmsTables)===$lmsBefore,'Tagged HTTP test data was not fully removed.');
echo 'LMS gradebook HTTP: ',$checks,' checks passed. Tagged test data removed.',PHP_EOL;
