<?php
require __DIR__.'/../backend/db.php';
require __DIR__.'/../backend/student_dashboard_helper.php';
require __DIR__.'/../backend/college_dean_student_workload_helper.php';
$checks=0;
$check=static function(bool $condition,string $message)use(&$checks):void {if(!$condition)throw new RuntimeException($message);$checks++;};
$base=['ay_id'=>2,'semester'=>1,'subject_id'=>1,'subject_code'=>'CS 1','curriculum_subject_id'=>1,'lec_units'=>2,'lab_units'=>1,'total_units'=>3];
$credit=synk_student_workload_totals([$base]);
$contact=synk_student_workload_totals([array_replace($base,['lab_units'=>3])]);
$check($credit===$contact&&$credit['units']===3.0&&$credit['weekly_hours']===5.0,'Laboratory credit and stored contact-hour formats produce the same units and hours.');
$check(synk_student_workload_totals([$base,$base])===$credit,'Repeated class rows do not double-count subject credits or lecture/laboratory hours.');
$pending=array_replace($base,['curriculum_subject_id'=>null,'total_units'=>null,'lec_units'=>null,'lab_units'=>null]);
$check(synk_student_workload_totals([$pending])['pending_subjects']===1,'Missing curriculum is pending rather than a false zero-unit subject.');
$partial=synk_student_workload_totals([$base,array_replace($pending,['subject_id'=>2])]);
$check($partial['subjects']===2&&$partial['pending_subjects']===1&&$partial['units']===3.0&&$partial['weekly_hours']===5.0,'Partial totals preserve known units and hours while identifying the missing subject.');
$check(synk_student_workload_totals([])['subjects']===0&&synk_student_workload_totals([])['units']===0.0,'An empty term has zero subjects and units.');
$classes=[['offering_id'=>1,'subject_code'=>'A','section_text'=>'A1'],['offering_id'=>2,'subject_code'=>'B','section_text'=>'B1']];
$slot=['days'=>['M'],'time_start'=>'09:00:00','time_end'=>'10:00:00'];
$check(count(synk_student_workload_conflicts($classes,[1=>[$slot],2=>[$slot]]))===1,'Different subjects with overlapping days and times are flagged once.');
$check(!synk_student_workload_conflicts($classes,[1=>[$slot],2=>[array_replace($slot,['time_start'=>'10:00:00','time_end'=>'11:00:00'])]]),'Adjacent classes are not schedule conflicts.');
$check(!synk_student_workload_conflicts($classes,[1=>[$slot],2=>[array_replace($slot,['days'=>['T']])]]),'Classes at the same time on different days do not conflict.');
$check(!synk_student_workload_conflicts([$classes[0],$classes[0]],[1=>[$slot,$slot]]),'Lecture and laboratory entries for the same offering cannot create a self-conflict.');
foreach ([['sort'=>'invalid'],['signal'=>'invalid'],['page'=>0]] as $invalid) {
    try {synk_dean_student_workload_filters($invalid);$check(false,'Invalid filters accepted.');}catch(DomainException $e){$checks++;}
}
$conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);
try {
    $term=synk_fetch_current_academic_term($conn);
    $scope=$conn->query('SELECT college_id,campus_id FROM tbl_student_management_enrolled_subjects WHERE is_active=1 GROUP BY college_id,campus_id ORDER BY COUNT(*) DESC LIMIT 1')->fetch_assoc();
    $expected=synk_student_workload_query($conn,'SELECT COUNT(*) AS n FROM (SELECT es.student_id FROM tbl_student_management_enrolled_subjects es JOIN tbl_program p ON p.program_id=es.program_id AND p.college_id=es.college_id WHERE es.is_active=1 AND es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=? GROUP BY es.student_id HAVING COUNT(DISTINCT es.subject_id)>=6) x','iiii',[$scope['college_id'],$scope['campus_id'],$term['ay_id'],$term['semester']])[0]['n'];
    $data=synk_dean_student_workload($conn,$scope,$term,synk_dean_student_workload_filters([]));
    $check($data['summary']['students']===(int)$expected,'The qualifying population matches independent distinct-subject SQL in the selected college and term.');
    $check(count($data['rows'])<=10&&!array_filter($data['rows'],fn($r)=>$r['subject_count']<6),'Results are paged and exclude students with fewer than six subjects.');
    if ($data['rows']) {
        $row=$data['rows'][0];
        $found=synk_dean_student_workload($conn,$scope,$term,synk_dean_student_workload_filters(['search'=>$row['student_number']]));
        $check(in_array($row['student_id'],array_column($found['rows'],'student_id'),true),'Search finds a qualified student by ID.');
        $subjects=synk_student_dashboard_subjects($conn,$row['student_id'],$term);
        $totals=synk_student_dashboard_workload($conn,$subjects);
        $check($row['units']===$totals['units']&&$row['weekly_hours']===$totals['weekly_hours'],'The Student and Dean cards agree on units and contact hours.');
    }
    $filtered=synk_dean_student_workload($conn,$scope,$term,synk_dean_student_workload_filters(['signal'=>'conflicts']));
    $check(!array_filter($filtered['rows'],fn($r)=>!$r['conflicts']),'The schedule-overlap filter includes only flagged students.');
    $last=synk_dean_student_workload($conn,$scope,$term,synk_dean_student_workload_filters(['page'=>100000,'sort'=>'units']));
    $check($last['pagination']['page']===$last['pagination']['pages'],'An out-of-range page is clamped to the final results page.');
    $otherTerm=$term;$otherTerm['semester']=$term['semester']==1?2:1;
    $historical=synk_dean_student_workload($conn,$scope,$otherTerm,synk_dean_student_workload_filters([]));
    $expectedHistorical=synk_student_workload_query($conn,'SELECT COUNT(*) AS n FROM (SELECT es.student_id FROM tbl_student_management_enrolled_subjects es JOIN tbl_program p ON p.program_id=es.program_id AND p.college_id=es.college_id WHERE es.is_active=1 AND es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=? GROUP BY es.student_id HAVING COUNT(DISTINCT es.subject_id)>=6) x','iiii',[$scope['college_id'],$scope['campus_id'],$otherTerm['ay_id'],$otherTerm['semester']])[0]['n'];
    $check($historical['summary']['students']===(int)$expectedHistorical&&$historical['term']['semester']===$otherTerm['semester'],'Another selected semester returns its own independently verified qualifying population.');
} finally {$conn->rollback();}
$http=in_array('--http',$argv,true);$route=null;$token=bin2hex(random_bytes(24));
if ($http) {
    $route=__DIR__.'/../tmp/student-workload-qa-'.bin2hex(random_bytes(8)).'.php';
    file_put_contents($route,'<?php if(!in_array($_SERVER["REMOTE_ADDR"]??"",["127.0.0.1","::1"],true)||!is_string($_GET["qa"]??null)||!hash_equals('.var_export($token,true).',$_GET["qa"])) {http_response_code(404);exit;} define("SYNK_COLLEGE_DEAN_QA_HTTP",true);require '.var_export(__DIR__.'/fixtures/college-dean-request.php',true).';');
    register_shutdown_function(static function()use($route){if(is_file($route))unlink($route);});
}
$request=static function(string $scenario)use($http,$route,$token):array {
    if ($http) {
        $context=stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>20]]);
        $body=file_get_contents('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>$scenario]),false,$context);
        $response=json_decode($body,true);if(!is_array($response)||isset($response['fixture_error']))throw new RuntimeException(substr($body,0,1000));return $response;
    }
    $process=proc_open([PHP_BINARY,__DIR__.'/fixtures/college-dean-request.php',$scenario],[1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    $body=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    if(proc_close($process)!==0||$errors!=='')throw new RuntimeException($scenario.': '.$errors.substr($body,0,500));
    $response=json_decode($body,true);if(!is_array($response)||isset($response['fixture_error']))throw new RuntimeException($body);return $response;
};
$r=$request('student_workload_ok');$check($r['http_status']===200&&$r['response']['status']==='ok','The authorized endpoint returns workload results.');
foreach (['bad_csrf'=>403,'other'=>403,'campus'=>403,'array'=>400,'bad_term'=>422,'role'=>403,'revoked'=>403,'get'=>405] as $suffix=>$status) {
    $r=$request('student_workload_'.$suffix);$check($r['http_status']===$status,'Endpoint access/input restriction: '.$suffix);
}
echo 'Student workload: '.$checks.' checks passed. Read-only data checks and rolled-back access fixtures.'.PHP_EOL;
