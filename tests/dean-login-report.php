<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/college_dean_login_report_helper.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void { if (!$ok) throw new RuntimeException($message); $checks++; };
$snapshot=static function()use($conn):string {
    $values=[];
    foreach (['tbl_student_login_activity','tbl_student_login_issues','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_useraccount','tbl_useraccount_roles','tbl_academic_settings','tbl_faculty','tbl_class_schedule','tbl_faculty_workload_sched'] as $table) $values[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    return hash('sha256',json_encode($values));
};
$before=$snapshot(); $token=bin2hex(random_bytes(24)); $route=__DIR__.'/../tmp/dean-login-report-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php define(\'SYNK_STUDENT_PORTAL_QA\','.var_export($token,true).'); require '.var_export(__DIR__.'/fixtures/student-portal-request.php',true).';');
$request=static function(string $case)use($route,$token,$check):array {
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>'login_report_'.$case]));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false]);
    $raw=curl_exec($curl); if ($raw===false) throw new RuntimeException(curl_error($curl));
    $size=curl_getinfo($curl,CURLINFO_HEADER_SIZE); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
    $body=substr($raw,$size); $headers=substr($raw,0,$size);
    $check(!preg_match('/Fatal error|Parse error|xdebug-error|Warning(?:<\/b>)?:/i',$body),'No PHP errors: '.$case.' '.substr(strip_tags($body),-800));
    $check(strpos($headers,'X-QA-Protected-Unchanged: yes')!==false,'The report must not change login, student, evaluation or scheduling records: '.$case);
    return ['status'=>$status,'body'=>$body,'headers'=>$headers,'data'=>json_decode($body,true)];
};
try {
    $r=$request('default'); $data=$r['data'];
    $check($r['status']===200 && $data['filters']['date_from']===synk_dean_login_report_defaults()['date_from'] && $data['filters']['date_to']===synk_dean_login_report_defaults()['date_to'],'Default is today and four previous Philippine calendar days.');
    $check($data['summary']['total_attempts']===33 && $data['summary']['successful_logins']===20 && $data['summary']['failed_attempts']===13,'College membership joins must not multiply events from multiple subjects.');
    $check($data['summary']['students_logged_in']===1 && $data['summary']['students_with_failures']===2 && $data['summary']['success_rate']===60.6,'Statistics count unique students independently of repeated logins.');
    $check(count($data['daily'])===5 && $data['daily'][0]['successful_logins']===1 && $data['daily'][0]['failed_attempts']===1 && $data['daily'][1]['successful_logins']===0,'Daily totals use PH dates and include days with zero activity.');
    $check(array_sum(array_column($data['daily'],'successful_logins'))===20 && array_sum(array_column($data['daily'],'failed_attempts'))===13,'Daily and overall totals reconcile.');
    $check(count($data['attention'])===2 && $data['attention'][0]['failed_attempts']===10 && $data['attention'][1]['successful_logins']===0 && $data['reasons'][0]['attempts']===13,'Attention and common failure reasons identify students with repeated/only failed sign-ins.');
    $check(count($data['rows'])===25 && $data['rows'][0]['user_agent']==='<img src=x onerror=alert(1)>' && $data['pagination']['pages']===2,'History is newest first and paginated, retaining metadata as plain data.');
    $check(strpos(json_encode($data),'FOREIGN_REPORT_SECRET')===false && strpos(json_encode($data),'UNASSIGNED_REPORT_SECRET')===false && strpos(json_encode($data),'BEFORE_REPORT_RANGE')===false && strpos(json_encode($data),'AFTER_REPORT_RANGE')===false,'Foreign/unassigned records and events outside inclusive local-date boundaries are excluded.');
    $r=$request('day'); $data=$r['data'];
    $check($r['status']===200 && $data['summary']['total_attempts']===2 && count($data['daily'])===1,'A one-day range includes midnight but excludes the preceding second.');
    $expected=(new DateTimeImmutable(synk_dean_login_report_defaults()['date_from'],new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $check($data['rows'][1]['occurred_at']===$expected && $data['timezone']==='Asia/Manila','UTC storage converts to Philippine midnight exactly.');
    foreach (['success'=>20,'failure'=>13] as $case=>$expected) {
        $r=$request($case); $data=$r['data'];
        $check($r['status']===200 && $data['pagination']['total']===$expected && count($data['rows'])===$expected && $data['summary']['total_attempts']===33 && count(array_unique(array_column($data['rows'],'outcome')))===1,'Outcome filtering changes history while preserving complete statistics: '.$case);
    }
    $r=$request('page2');
    $check($r['status']===200 && count($r['data']['rows'])===8 && $r['data']['pagination']['page']===2,'The last page contains the remaining events.');
    $r=$request('size50'); $check($r['status']===200 && count($r['data']['rows'])===33 && $r['data']['pagination']['pages']===1,'Supported page size is applied.');
    foreach (['email'=>1,'ip'=>1,'name'=>30,'student'=>3,'program'=>33,'empty'=>0,'foreign_student'=>0,'foreign_program'=>0,'wildcard'=>0,'injection'=>0] as $case=>$expected) {
        $r=$request($case);
        $check($r['status']===200 && $r['data']['summary']['total_attempts']===$expected,'Name, email, IP, exact student and program filters stay inside college access: '.$case.' '.substr($r['body'],0,300));
    }
    $r=$request('snapshot'); $check($r['status']===200 && $r['data']['summary']['total_attempts']===33 && strpos($r['body'],'NEW_REPORT_ARRIVAL')===false,'A loaded snapshot excludes events arriving during pagination.');
    $r=$request('refresh'); $check($r['status']===200 && $r['data']['summary']['total_attempts']===34 && $r['data']['rows'][0]['user_agent']==='NEW_REPORT_ARRIVAL','Refresh includes new arrivals first.');
    $r=$request('historical_view'); $check($r['status']===200 && $r['data']['summary']['total_attempts']===33,'A historical academic-term view does not suppress account login activity.');
    $r=$request('max_range'); $check($r['status']===200 && count($r['data']['daily'])===366,'The maximum 366-day range is supported.');
    foreach (['bad_date'=>422,'reversed'=>422,'future'=>422,'too_long'=>422,'bad_page'=>422,'bad_snapshot'=>422,'bad_size'=>422,'bad_outcome'=>422,'bad_search'=>422,'array'=>400,
        'bad_csrf'=>403,'scope'=>403,'campus'=>403,'revoked'=>403,'inactive'=>403,'reassigned'=>403,'chair'=>403,'anonymous'=>403,'get'=>405] as $case=>$expected) {
        $r=$request($case);
        $check($r['status']===$expected && empty($r['data']['rows']) && !isset($r['data']['summary']),'Invalid inputs and unauthorized access must not disclose report data: '.$case.' HTTP '.$r['status'].' '.substr($r['body'],0,300));
    }
    $r=$request('page');
    $check($r['status']===200 && strpos($r['body'],'id="deanLoginReport"')!==false && strpos($r['body'],'data-login-days="5"')!==false && strpos($r['body'],'login-activity.js?v=')!==false,'The dedicated Dean page provides filters, shortcuts and the real controller.');
    $check(strpos($r['body'],'href="login-activity.php"')!==false && strpos($r['body'],'id="deanLoginTrace"')!==false,'Sidebar navigation and student tracing controls are present.');
    $check($snapshot()===$before,'All fixture events and accounts rolled back; real records are unchanged.');
} finally { if (is_file($route)) unlink($route); }
echo 'Dean login report: ',$checks,' checks passed over actual HTTP. Fixtures rolled back.',PHP_EOL;
