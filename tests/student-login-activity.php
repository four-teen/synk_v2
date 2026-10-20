<?php
// Actual authentication/read routes, loopback-only fixtures, all records rolled back.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/student_login_activity_helper.php';
if (!synk_student_login_activity_ready($conn)) throw new RuntimeException('Prepare phase27_student_login_activity.sql before running tests.');
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void { if (!$ok) throw new RuntimeException($message); $checks++; };
$snapshot=static function()use($conn):string {
    $data=[];
    foreach (['tbl_student_login_activity','tbl_student_login_issues','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_useraccount','tbl_useraccount_roles','tbl_academic_settings','tbl_faculty','tbl_class_schedule','tbl_faculty_workload_sched'] as $table) $data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    return hash('sha256',json_encode($data));
};
$before=$snapshot(); $token=bin2hex(random_bytes(24)); $route=__DIR__.'/../tmp/student-login-activity-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php define(\'SYNK_STUDENT_PORTAL_QA\','.var_export($token,true).'); require '.var_export(__DIR__.'/fixtures/student-portal-request.php',true).';');
$request=static function(string $scenario)use($route,$token,$check):array {
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>$scenario]));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_USERAGENT=>'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130.0 Safari/537.36',
        CURLOPT_HTTPHEADER=>['X-Forwarded-For: 203.0.113.99']]);
    $raw=curl_exec($curl); if ($raw===false) throw new RuntimeException(curl_error($curl));
    $size=curl_getinfo($curl,CURLINFO_HEADER_SIZE); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
    $body=substr($raw,$size); $headers=substr($raw,0,$size);
    $check(!preg_match('/Fatal error|Parse error|xdebug-error|Warning(?:<\/b>)?:/i',$body),'The actual request completed without PHP errors: '.$scenario.' '.substr(strip_tags($body),-600));
    $activity=[];
    if (preg_match('/X-QA-Login-Activity: ([^\r\n]+)/i',$headers,$match)) $activity=json_decode(rawurldecode($match[1]),true);
    return ['status'=>$status,'body'=>$body,'headers'=>$headers,'data'=>json_decode($body,true),'activity'=>$activity];
};
try {
    $r=$request('verified_login_success');
    $check($r['status']===200 && $r['data']['authenticated']===true && count($r['activity'])===1 && $r['activity'][0]['outcome']==='success' && (int)$r['activity'][0]['student_id']===(int)$r['data']['student_id'],'A completed verified student sign-in records exactly one successful login.');
    $check((int)$r['activity'][0]['identity_verified']===1 && $r['activity'][0]['auth_method']==='google','Successful login evidence is explicitly verified and Google-only.');
    $check(in_array($r['activity'][0]['ip_address'],['127.0.0.1','::1'],true) && $r['activity'][0]['browser']==='Chrome' && $r['activity'][0]['operating_system']==='Windows','Request metadata is captured and an untrusted forwarded IP is not accepted.');
    $r=$request('verified_login_storage_failure');
    $check($r['status']===200 && $r['data']['authenticated']===true && count($r['activity'])===0,'An activity storage failure cannot prevent a verified student from signing in.');
    foreach (['start_invalid'=>'student_email_invalid','start_domain'=>'email_domain_denied','start_unknown'=>'student_directory_access_denied','start_duplicate'=>'student_email_ambiguous','start_bad_csrf'=>'student_login_request_invalid','start_rate'=>'student_login_rate_limited','callback_student_cancelled'=>'google_cancelled','callback_expired'=>'google_state_invalid','callback_missing_code'=>'google_code_missing','callback_invalid_state'=>'google_state_invalid','verified_mismatch_known_student'=>'student_email_mismatch','verified_unverified'=>'google_profile_invalid'] as $scenario=>$reason) {
        $r=$request($scenario);
        $check(count($r['activity'])===1 && $r['activity'][0]['outcome']==='failure' && $r['activity'][0]['reason_code']===$reason,'Every observed failed sign-in outcome produces one event, without duplicates: '.$scenario);
        if (in_array($scenario,['start_unknown','start_duplicate','callback_invalid_state'],true)) $check($r['activity'][0]['student_id']===null,'Unknown, ambiguous or unbound attempts are never assigned to a guessed student: '.$scenario);
        if ($scenario==='verified_mismatch_known_student') $check($r['activity'][0]['student_id']!==null && (int)$r['activity'][0]['identity_verified']===1,'A mismatched Google account is an attempt using the entered student email, with verification distinguished from successful sign-in.');
    }
    foreach (['start_success','callback_main_cancelled','verified_success'] as $scenario) {
        $r=$request($scenario); $check(count($r['activity'])===0,'Pending Google redirects, staff logins and identity lookup alone never count as student logins: '.$scenario);
    }
    $r=$request('login_activity_list');
    $students=$r['data']['rows'];
    $withActivity=array_values(array_filter($students,static function($row){return $row['login_success_count']===20;}));
    $check($r['status']===200 && count($withActivity)===1 && $withActivity[0]['login_failure_count']===11,'The paginated directory counts successful and failed attempts separately.');
    $check(count(array_filter($students,static function($row){return $row['login_success_count']===0 && $row['login_failure_count']===0;}))===1,'A student with no sign-ins has zero counts without borrowing another student history.');
    foreach (['history','all_time'] as $case) {
        $r=$request('login_activity_'.$case); $data=$r['data'];
        $check($r['status']===200 && $data['summary']['total_attempts']===31 && $data['summary']['successful_logins']===20 && $data['summary']['failed_attempts']===11,'Modal statistics include all recorded terms independently of directory filters: '.$case);
        $check(count($data['rows'])===25 && $data['rows'][0]['user_agent']==='<img src=x onerror=alert(1)>' && $data['rows'][1]['user_agent']==='Login fixture 29','History is newest first with a stable activity-ID tie break.');
        $check($data['timezone']==='Asia/Manila' && $data['summary']['last_success_at']==='2026-10-01T00:00:29Z','Stored UTC timestamps are explicit and the display timezone is Philippine Time.');
        $check(strpos($r['headers'],'X-QA-Protected-Unchanged: yes')!==false,'Reading login activity neither records new logins nor changes students/scheduling.');
    }
    $r=$request('login_activity_page2');
    $check($r['status']===200 && $r['data']['pagination']['page']===2 && count($r['data']['rows'])===6 && end($r['data']['rows'])['submitted_email']==='old-address@sksu.edu.ph','Pagination retains old sign-in emails and returns the oldest events last.');
    foreach (['success_only'=>['success',20],'failure_only'=>['failure',11]] as $case=>$expected) {
        $r=$request('login_activity_'.$case);
        $check($r['status']===200 && count($r['data']['rows'])===$expected[1] && count(array_unique(array_column($r['data']['rows'],'outcome')))===1 && $r['data']['rows'][0]['outcome']===$expected[0],'Outcome filtering keeps complete summary statistics and returns the requested events: '.$case);
    }
    $r=$request('login_activity_empty');
    $check($r['status']===200 && $r['data']['summary']['total_attempts']===0 && $r['data']['rows']===[] && $r['data']['summary']['last_success_at']===null,'An empty history remains inspectable and does not fabricate prior logins.');
    $r=$request('login_activity_snapshot');
    $check($r['status']===200 && $r['data']['summary']['total_attempts']===31 && strpos(json_encode($r['data']['rows']),'NEWER_THAN_SNAPSHOT')===false,'A history page excludes newer arrivals until refreshed, preventing pagination shifts.');
    $r=$request('login_activity_refresh');
    $check($r['status']===200 && $r['data']['summary']['total_attempts']===32 && $r['data']['rows'][0]['user_agent']==='NEWER_THAN_SNAPSHOT','Refreshing includes the newest sign-in at the beginning of the list.');
    foreach (['foreign'=>404,'bad_csrf'=>403,'revoked'=>403,'inactive'=>403,'chair'=>403,'bad_page'=>422,'bad_snapshot'=>422,'bad_outcome'=>422,'array_input'=>400] as $case=>$expected) {
        $r=$request('login_activity_'.$case);
        $check($r['status']===$expected && empty($r['data']['rows']) && strpos($r['body'],'FOREIGN_LOGIN_SECRET')===false,'College/role access and history inputs are checked on the server: '.$case.' HTTP '.$r['status'].' '.substr($r['body'],0,500));
    }
    $r=$request('login_activity_page');
    $check($r['status']===200 && strpos($r['body'],'Login Activity')!==false && strpos($r['body'],'dean-student-login-dialog')!==false && strpos($r['body'],'student-login-activity.js')!==false,'The Dean page contains the new column and wide login-statistics modal.');
    $check($before===$snapshot(),'All fixture authentication events, accounts and student records rolled back.');
} finally { if (is_file($route)) unlink($route); }
echo 'Student login activity: ',$checks,' checks passed. All fixture records rolled back.',PHP_EOL;
