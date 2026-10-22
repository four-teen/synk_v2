<?php
// Real HTTP routes, loopback-only fixtures, no fixture data is committed.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/student_presence_helper.php';
if (!synk_student_presence_ready($conn)) throw new RuntimeException('Run backend/tools/prepare_student_portal_sessions.php first.');
$snapshot=static function()use($conn):array { return $conn->query('CHECKSUM TABLE tbl_student_portal_sessions,tbl_student_login_activity,tbl_student_management,tbl_student_management_enrolled_subjects,tbl_useraccount,tbl_useraccount_roles')->fetch_all(MYSQLI_ASSOC); };
$before=$snapshot(); $checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void { if(!$ok)throw new RuntimeException($message); $checks++; };
$token=bin2hex(random_bytes(24)); $route=__DIR__.'/../tmp/student-live-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php define(\'SYNK_STUDENT_PORTAL_QA\','.var_export($token,true).'); require '.var_export(__DIR__.'/fixtures/student-portal-request.php',true).';');
$request=static function(string $scenario)use($route,$token,$check):array {
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>$scenario]));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false]);
    $raw=curl_exec($curl); if($raw===false)throw new RuntimeException(curl_error($curl));
    $size=curl_getinfo($curl,CURLINFO_HEADER_SIZE); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
    $headers=substr($raw,0,$size); $body=substr($raw,$size);
    $check(!preg_match('/Fatal error|Parse error|xdebug-error|<b>Warning/i',$body),'No PHP errors: '.$scenario.' '.substr(strip_tags($body),0,350));
    preg_match('/X-QA-Live-State: ([^\r\n]+)/i',$headers,$m);
    return ['status'=>$status,'body'=>$body,'headers'=>$headers,'data'=>json_decode($body,true),'state'=>isset($m[1])?json_decode(rawurldecode($m[1]),true):[]];
};
try {
    $r=$request('presence_status'); $session=$r['state']['sessions'][0];
    $check($r['status']===200 && $r['data']['seconds_remaining']<=840 && (int)$session['activity_age']>=60 && (int)$session['seen_age']<5,'A background heartbeat updates connection presence without resetting inactivity.');
    $r=$request('presence_activity');
    $check($r['status']===200 && $r['data']['seconds_remaining']>=895 && (int)$r['state']['sessions'][0]['activity_age']<5,'User activity extends a valid session to 15 minutes.');
    foreach(['expired','boundary','revive','missing'] as $case) {
        $r=$request('presence_'.$case);
        $check($r['status']===401 && $r['data']['status']==='signed_out' && $r['state']['role']==='', 'Expired or missing sessions cannot be revived: '.$case);
        if($case!=='missing')$check($r['state']['sessions'][0]['end_reason']==='idle' && (int)$r['state']['sessions'][0]['activity_age']>=900,'The idle logout is recorded without touching last activity: '.$case);
    }
    $r=$request('presence_logout');
    $check($r['status']===401 && $r['state']['sessions'][0]['end_reason']==='logout' && $r['state']['role']==='', 'Explicit logout ends live presence and the PHP session.');
    $r=$request('presence_logout_page');
    $check($r['status']===302 && strpos($r['headers'],'Location: student/login.php')!==false && $r['state']['sessions'][0]['end_reason']==='logout' && $r['state']['role']==='', 'The existing Log Out link ends presence and destroys authentication.');
    $r=$request('presence_switch');
    $check($r['status']===200 && $r['state']['sessions'][0]['end_reason']==='replaced' && $r['state']['role']==='admin','Switching into staff access ends the old student presence.');
    foreach(['guard','guard_post'] as $case) {
        $r=$request('presence_'.$case);
        $check($r['status']===302 && strpos($r['headers'],'student_idle_timeout')!==false && strpos($r['body'],'QA-CS101')===false, 'Expired students cannot read or save guarded pages: '.$case);
        $check(strpos($r['headers'],'X-QA-Protected-Unchanged: yes')!==false,'Expired requests leave student data unchanged.');
    }
    foreach(['bad_csrf'=>403,'array'=>422,'staff'=>401] as $case=>$status) {
        $r=$request('presence_'.$case);
        $check($r['status']===$status && (int)$r['state']['sessions'][0]['seen_age']>=45,'Invalid or staff heartbeats cannot update student presence: '.$case);
        if($case==='staff')$check($r['state']['role']==='admin','A stale student tab cannot sign out the new staff account.');
    }
    $r=$request('presence_changed');
    $check($r['status']===401 && $r['state']['sessions'][0]['end_reason']==='identity_changed','Directory identity changes end live student access.');
    $r=$request('presence_foreign');
    $check($r['status']===200 && count($r['state']['sessions'])===1 && (int)$r['state']['sessions'][0]['student_id']===$r['state']['ids']['first'],'Posted student identifiers cannot change heartbeat ownership.');
    $r=$request('presence_preview');
    $check($r['status']===200 && $r['state']['sessions']===[] && strpos($r['body'],'studentPresenceConfig')===false,'Staff previews neither create online sessions nor load the activity heartbeat.');
    $r=$request('activity_grid_current'); $rows=$r['data']['students']; $ids=$r['state']['ids']; $byId=array_column($rows,null,'student_id');
    $check($r['status']===200 && count($rows)>=1005 && count($rows)===count(array_unique(array_column($rows,'student_id'))),'The grid returns every student beyond 1,000, deduplicating subjects and terms.');
    $check(isset($byId[$ids['first']],$byId[$ids['other']]) && !isset($byId[$ids['foreign']]) && strpos($r['body'],'LIVE_SECRET')===false,'Grid membership follows the active Dean college and excludes foreign students.');
    foreach([0,1,1,2,2,3,3,1] as $index=>$level) {
        $check($byId[$ids['level'.$index]]['level']===$level,'Login intensity uses only successful logins tagged for this term: '.$index);
    }
    $check($byId[$ids['level0']]['successful_logins']===0,'Other years, semesters and unclassified legacy events never affect the selected-term count.');
    $check(!isset($byId[$ids['historical_only']]) && !isset($byId[$ids['semester_only']]) && !isset($byId[$ids['year_only']]),'Students who belong only to a different academic year/semester stay outside the selected roster.');
    $check($byId[$ids['first']]['successful_logins']===2 && $r['data']['live_presence']===true,'A shared student uses only current-term events while current-term online status remains available.');
    $positions=array_flip(array_column($rows,'student_id'));
    for($i=1;$i<8;$i++)$check($positions[$ids['level'.($i-1)]]<$positions[$ids['level'.$i]],'Square order follows surname ascending.');
    $check($byId[$ids['level1']]['presence']==='online' && $byId[$ids['level1']]['online_seconds']>0,'Another live device keeps a student online despite one logged-out device.');
    $check($byId[$ids['level3']]['presence']==='logged_out' && $byId[$ids['level3']]['last_logout_at']!==null,'Idle expiry is shown as logged out even without another browser request.');
    $check($byId[$ids['level5']]['presence']==='offline' && $byId[$ids['level5']]['online_seconds']===0,'A disconnected browser stops glowing orange after the connection lease expires.');
    $check(strpos($r['body'],'presence_key')===false && strpos($r['headers'],'X-QA-Protected-Unchanged: yes')!==false,'Grid reads expose no session keys and never create sign-in events or mutate student data.');
    foreach(['historical','session_term'] as $case) {
        $r=$request('activity_grid_'.$case); $ids=$r['state']['ids']; $past=array_column($r['data']['students'],null,'student_id');
        $check($r['status']===200 && isset($past[$ids['first']],$past[$ids['historical_only']]) && !isset($past[$ids['other']]),'Historical enrollment and manual directory memberships follow the selected term: '.$case);
        $check($past[$ids['first']]['successful_logins']===3 && $r['data']['live_presence']===false,'The same student has an independent historical term count: '.$case);
        $check($r['data']['summary']['online']===0 && count(array_unique(array_column($r['data']['students'],'presence')))===1 && $past[$ids['first']]['presence']==='historical','Current online sessions are never displayed as historical online activity: '.$case);
    }
    foreach(['other_semester'=>'semester_only','other_year'=>'year_only'] as $case=>$which) {
        $r=$request('activity_grid_'.$case); $ids=$r['state']['ids']; $period=array_column($r['data']['students'],null,'student_id');
        $check($r['status']===200 && isset($period[$ids[$which]]) && !isset($period[$ids['level0']]),'Changing just the semester or academic year selects its own student roster: '.$case);
        $check($period[$ids[$which]]['successful_logins']===5,'The selected academic year and semester both constrain logins: '.$case);
    }
    foreach(['pinned'=>2,'capture'=>3] as $case=>$expected) {
        $r=$request('activity_grid_'.$case); $ids=$r['state']['ids']; $pinned=array_column($r['data']['students'],null,'student_id');
        $check($r['status']===200 && $r['data']['live_presence']===true && $pinned[$ids['first']]['successful_logins']===$expected,'Each tab pins its requested term; authentication records the system term even with a historical viewing preference: '.$case);
    }
    $r=$request('activity_grid_recovery'); $ids=$r['state']['ids']; $recovered=array_column($r['data']['students'],null,'student_id');
    $check($r['status']===200 && $recovered[$ids['level0']]['successful_logins']===1 && $recovered[$ids['level0']]['level']===1,'A previously grey square becomes light green after recovery of its earlier successful login.');
    $check($recovered[$ids['first']]['successful_logins']===2 && $recovered[$ids['level3']]['successful_logins']===5,'Recovery preserves assigned term counts and never adds failures or another term to the green levels.');
    $check(strpos($r['headers'],'X-QA-Protected-Unchanged: yes')!==false,'Dashboard refreshes read the recovered history without modifying it.');
    foreach(['bad_csrf'=>403,'array'=>400,'foreign'=>403,'revoked'=>403,'inactive'=>403,'staff'=>403,'get'=>405] as $case=>$status) {
        $r=$request('activity_grid_'.$case);
        $check($r['status']===$status && empty($r['data']['students']),'Activity grid checks method, CSRF and current college access: '.$case);
    }
    foreach(['bad_year'=>422,'bad_semester'=>422,'partial_term'=>422,'array_term'=>400] as $case=>$status) {
        $r=$request('activity_grid_'.$case);
        $check($r['status']===$status && empty($r['data']['students']),'Invalid academic year and semester inputs cannot silently broaden the grid: '.$case);
    }
    foreach(['page_current'=>true,'page_historical'=>false] as $case=>$live) {
        $r=$request('activity_grid_'.$case);
        preg_match('/<script id="studentActivityConfig"[^>]*>(.*?)<\/script>/s',$r['body'],$match);
        $config=json_decode($match[1] ?? '',true);
        $check($r['status']===200 && $config['live_presence']===$live && $config['ay_id']>0 && $config['semester']>0,'The real dashboard pins the card to its selected academic term: '.$case);
        $check(strpos($r['body'],'Successful sign-ins recorded for the selected term.')!==false && strpos($r['body'],'across all recorded dates')===false,'The card explains that counts follow the selected term.');
    }
    $check($snapshot()===$before,'All session, login, student and account fixtures were rolled back.');
    echo 'Student live activity: ',$checks,' checks passed. Fixtures rolled back.',PHP_EOL;
} finally { if(is_file($route))unlink($route); }
