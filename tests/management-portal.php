<?php
// php tests/management-portal.php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/db.php';
$snapshot=static function()use($conn):array {
    $data=[];foreach(['tbl_useraccount','tbl_useraccount_roles','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_class_schedule','tbl_faculty_workload_sched']as$table){
        $data[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM `'.$table.'` ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }return $data;
};
$before=$snapshot();$checks=0;$check=static function(bool$ok,string$message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$token=bin2hex(random_bytes(24));$name='management-qa-'.bin2hex(random_bytes(8)).'.php';$route=__DIR__.'/../tmp/'.$name;
file_put_contents($route,'<?php if(!in_array($_SERVER["REMOTE_ADDR"]??"",["127.0.0.1","::1"],true)||!hash_equals('.var_export($token,true).',(string)($_GET["qa"]??""))){http_response_code(404);exit;}define("SYNK_MANAGEMENT_QA",true);require '.var_export(__DIR__.'/fixtures/management-request.php',true).';');
$request=static function(string$scenario)use($name,$token):array {
    $context=stream_context_create(['http'=>['timeout'=>35,'ignore_errors'=>true,'follow_location'=>0,'header'=>"Connection: close\r\n"]]);
    $body=file_get_contents('http://localhost/synk/tmp/'.$name.'?qa='.$token.'&scenario='.rawurlencode($scenario),false,$context);
    $data=json_decode((string)$body,true);if(!is_array($data)||isset($data['fixture_error']))throw new RuntimeException($scenario.': '.substr((string)$body,0,2400));return $data;
};
try {
    $check($conn->query("SHOW COLUMNS FROM tbl_academic_settings LIKE 'current_exam_term'")->num_rows===0,'Management term leaked into shared Academic Settings storage.');
    require_once __DIR__.'/../backend/academic_term_helper.php';
    $check(!array_key_exists('exam_term',synk_fetch_current_academic_term($conn)),'Shared academic helper exposes a Management-only term.');
    $r=$request('login');$html=$r['response'];
    $check($r['status']===200 && strpos($html,'CCS Management.')!==false && strpos($html,'One Place.')!==false,'Management login branding is missing.');
    $check(strpos($html,'managementGoogleLogin')!==false && !preg_match('/<input[^>]+type=["\'](?:email|password)["\']/i',$html),'Management login is not Google-only.');
    foreach(['dashboard','financial','students','students_page','contributions','events','violations']as$scenario){
        $r=$request($scenario);$html=$r['response'];
        $check($r['status']===200 && is_string($html) && !preg_match('/Fatal error|Parse error|xdebug-error|<b>Warning/i',$html),$scenario.': page failed to render: '.json_encode(['status'=>$r['status'],'response'=>is_string($html)?substr($html,0,500):$html]));
        $check((int)$r['session']['college_id']===$r['scope']['college_id'],'Management trusted a stale session college.');
        foreach(['Financial','Contributions','Events','Violations','Students','Dashboard']as$menu)$check(strpos($html,$menu)!==false,'A Management menu is missing: '.$menu);
        if($scenario==='financial')$check(strpos($html,'id="financial-fresh-start"')!==false && strpos($html,'No financial records yet')!==false,'Financial retained imported history instead of a fresh workspace.');
        if($scenario==='students')$check(strpos($html,$r['tag'].'Foreign')===false && substr_count($html,'<tr>')===26,'Directory scope or pagination is incorrect.');
        if($scenario==='students_page')$check(substr_count($html,'<tr>')===4 && strpos($html,'Page 2 of 2')!==false,'Student directory did not deduplicate or paginate correctly.');
        if($scenario==='contributions'||$scenario==='events')$check(strpos($html,'id="management-add"')!==false && strpos($html,'id="management-record-form"')!==false,'Catalog management forms are missing.');
    }
    $r=$request('analytics');$check($r['response']['students']===$r['baseline']['students']+28,'Analytics counted duplicate memberships or foreign-only students.');
    $check($r['response']['enrollments']===$r['baseline']['enrollments']+29,'Analytics included foreign-college imported classes.');
    $r=$request('students_empty');$check($r['status']===200&&strpos($r['response'],'No matching students')!==false,'Student empty-search state is missing.');
    foreach(['students_wildcard','students_otherterm']as$scenario){$r=$request($scenario);$check($r['status']===200&&strpos($r['response'],'No matching students')!==false,$scenario.': search matched unintended memberships.');}
    $r=$request('students_xss');$check($r['status']===200&&strpos($r['response'],'<img src=x onerror=alert(1)>')===false&&strpos($r['response'],'&lt;img src=x onerror=alert(1)&gt;')!==false,'Search text is not escaped.');
    foreach(['forged_college'=>403,'forged_campus'=>403,'bad_term'=>400,'students_array'=>400,'bad_exam_term'=>400,'array_exam_term'=>400]as$scenario=>$status){$r=$request($scenario);$check($r['status']===$status,$scenario.': invalid scope/filter was accepted.');}
    foreach(['view_final','view_final_fields'] as $scenario) {
        $r=$request($scenario); $html=$r['response'];
        $check($r['status']===200 && strpos($html,'"exam_term":"FINAL"')!==false && strpos($html,'Final</span>')!==false,'Final viewing period was not displayed.');
        $check(strpos($html,'exam_term=FINAL')!==false,'Management navigation lost the selected Final term.');
    }
    $r=$request('students_foreign');$check($r['status']===200 && strpos($r['response'],'No matching students')===false && strpos($r['response'],$r['tag'].'Foreign')===false,'A removed program filter still changed the current-period directory or leaked another college.');
    foreach(['revoked','no_google','wrong_role','anonymous']as$scenario){$r=$request($scenario);$check($r['status']===302&&strpos($r['location'],'login.php')===0,$scenario.': unauthorized session accessed Management.');}
    $r=$request('reassigned');$check($r['status']===200&&(int)$r['session']['college_id']===$r['other_scope']['college_id'],'Administrator reassignment was not refreshed.');
    foreach(['account_create','account_update','account_multi','account_remove']as$scenario){
        $r=$request($scenario);$check($r['response']==='success',$scenario.': account save failed: '.json_encode($r['response']));
        $account=end($r['after']['accounts']);
        if($scenario==='account_remove')$check($account['management_college_id']===null&&$account['management_organization_id']===null,'Removing Management retained its assignment.');
        else $check((int)$account['management_college_id']===$r['scope']['college_id']&&(int)$account['management_organization_id']===$r['scope']['organization_id'],'Management organization assignment did not save.');
        if($scenario==='account_multi')$check((int)$account['college_id']===$r['other_scope']['college_id'],'Management overwrote Program Chair scope.');
    }
    foreach(['account_missing'=>'need_management_college','account_invalid'=>'invalid_management_organization','account_array'=>'invalid_management_college','account_bad_csrf'=>'invalid_csrf','account_mixed_gmail'=>'invalid_domain','account_forbidden'=>'unauthorized']as$scenario=>$message){
        $r=$request($scenario);$check($r['response']===$message&&$r['before']===$r['after'],$scenario.': rejected account request changed data.');
    }
    foreach(['account_missing_org'=>'need_management_organization','account_foreign_org'=>'invalid_management_organization','account_foreign_campus'=>'invalid_management_organization','account_inactive_org'=>'invalid_management_organization'] as $scenario=>$message) {
        $r=$request($scenario); $check($r['response']===$message&&$r['before']===$r['after'],$scenario.': invalid organization assignment was accepted.');
    }
    $r=$request('account_identity_preserved');$account=end($r['after']['accounts']);$check($r['response']==='success'&&$account['google_sub']==='known-management-identity','Editing college access cleared the verified Google identity.');
    $r=$request('account_identity_changed');$account=end($r['after']['accounts']);$check($r['response']==='success'&&$account['google_sub']===null,'Changing email retained the previous Google identity binding.');
    $r=$request('account_load');$row=$r['response']['accounts'][0];$check(in_array('management',$row['roles'],true)&&(int)$row['management_college_id']===$r['scope']['college_id']&&$row['management_college_label']!=='','Administrator account list lost Management assignment.');
    $r=$request('account_page');foreach(['add','edit']as$mode)$check(strpos($r['response'],'id="'.$mode.'_role_management"')!==false&&strpos($r['response'],'id="'.$mode.'_management_college_id"')!==false,'Administrator role/college controls missing.');
    $r=$request('identity_ok');$check($r['response']['status']==='ok'&&$r['session']['role']==='management'&&$r['session']['auth_method']==='google'&&(int)$r['session']['college_id']===$r['scope']['college_id'],'Verified Google identity did not open Management in its own college.');
    foreach(['identity_unknown','identity_unverified','identity_bad_domain','identity_sub','identity_inactive','identity_revoked','identity_missing_college','identity_college_inactive','identity_missing_org','identity_inactive_org']as$scenario){$r=$request($scenario);$check($r['response']['status']==='error'&&$r['before']===$r['after']&&!isset($r['session']['user_id']),$scenario.': unauthorized Google identity was accepted.');}
    $r=$request('oauth_start');$query=[];parse_str((string)parse_url($r['location'],PHP_URL_QUERY),$query);
    $check($r['status']===302&&strpos($r['location'],'https://accounts.google.com/')===0&&!isset($query['hd'])&&strlen($query['state']??'')===64,'Management OAuth did not allow a Google account chooser without the staff domain hint.');
    $check($r['session']['google_oauth_state']==='main-state'&&$r['session']['student_google_oauth_state']==='student-state','Starting Management sign-in replaced another portal flow.');
    foreach(['oauth_cancelled'=>'google_cancelled','oauth_invalid'=>'google_state_invalid','oauth_expired'=>'google_state_invalid','oauth_start_bad_csrf'=>'login_request_invalid']as$scenario=>$status){$r=$request($scenario);$check($r['status']===302&&strpos($r['location'],'../management/login.php?auth_status='.$status)===0,$scenario.': callback returned to the wrong portal.');$check($r['session']['google_oauth_state']==='main-state'&&$r['session']['student_google_oauth_state']==='student-state','Management callback consumed another portal flow.');}
    foreach(['oauth_main_cancelled'=>'../index.php','oauth_student_cancelled'=>'../student/login.php']as$scenario=>$target){$r=$request($scenario);$check($r['status']===302&&strpos($r['location'],$target.'?auth_status=google_cancelled')===0&&($r['session']['management_google_oauth_state']??'')==='management-state','Staff/student callback consumed the Management sign-in flow.');}
    foreach(['legacy_login','legacy_choose_management']as$scenario){$r=$request($scenario);$check(!isset($r['session']['user_id'])&&($r['response']['status']??'')!=='success','Legacy authentication entered Management.');}
    $check($snapshot()===$before,'Fixtures left real records changed.');
    echo 'Management portal: '.$checks." HTTP checks passed. Test records rolled back.\n";
} finally {if(is_file($route))unlink($route);}
