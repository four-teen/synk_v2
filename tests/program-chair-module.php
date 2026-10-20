<?php
// Run: php tests/program-chair-module.php
require_once __DIR__ . '/../backend/db.php';
$root=dirname(__DIR__);
$snapshot=static function () use ($conn): array {
    return $conn->query('CHECKSUM TABLE tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_user_college_access,tbl_faculty,tbl_college_faculty,tbl_faculty_term_designations,tbl_academic_settings,tbl_class_schedule,tbl_faculty_workload_sched')->fetch_all(MYSQLI_ASSOC);
};
$before=$snapshot(); $checks=0;
$http=in_array('--http',$argv,true);
$accountsOnly=in_array('--accounts',$argv,true);
$route=$root.'/tmp/chair-module-'.bin2hex(random_bytes(8)).'.php';
$token=bin2hex(random_bytes(24));
if ($http) {
    $source="<?php\nif (!in_array(\$_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true) || !is_string(\$_GET['qa'] ?? null) || !hash_equals(".var_export($token,true).", \$_GET['qa'])) { http_response_code(404); exit; }\ndefine('SYNK_PROGRAM_CHAIR_QA_HTTP',true);\nrequire ".var_export($root.'/tests/fixtures/program-chair-request.php',true).";\n";
    file_put_contents($route,$source);
    register_shutdown_function(static function () use ($route): void { if (is_file($route)) unlink($route); });
}
$check=static function (bool $condition,string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    $checks++;
};
$request=static function (string $scenario) use ($root,$http,$route,$token): array {
    if ($http) {
        $body=file_get_contents('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>$scenario]));
    } else {
        $process=proc_open([PHP_BINARY,$root.'/tests/fixtures/program-chair-request.php',$scenario],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
        $body=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process)!==0 || $errors!=='') throw new RuntimeException($scenario.': '.$errors.substr($body,0,1000));
    }
    $result=json_decode($body,true);
    if (!is_array($result) || isset($result['fixture_error'])) throw new RuntimeException($scenario.': '.substr($body,0,1200));
    return $result;
};
if (!$accountsOnly) {
$r=$request('login');
$check($r['response']['status']==='success' && $r['response']['redirect']==='program-chair/' && $r['session_role']==='program_chair' && $r['session_college']===$r['college_id'], 'Shared faculty login did not open Program Chair with the assigned college.');
$r=$request('missing_scope');
$check(!in_array('program_chair',$r['response']['roles'],true) && in_array('professor',$r['response']['roles'],true), 'An unassigned chair could log in, or another role was lost.');
foreach (['index','programs','students','reports','faculty-workload','faculty-profile','manage-faculty-profile','faculty-evaluations','student-statistics','faculty-statistics','manage-college-faculty'] as $page) {
    $r=$request('page_'.$page); $body=$r['response'];
    $check($r['http_status']===200 && is_string($body) && !preg_match('/Fatal error|Parse error|<b>Warning<\/b>|xdebug-error/i',$body), 'Page failed: '.$page);
    $check(strpos($body,'Program Chair Portal')!==false && strpos($body,'href="manage-college-faculty.php"')!==false, 'Chair navigation missing: '.$page);
    $check(strpos($body,'College Dean Portal')===false && $r['session_role']==='program_chair' && $r['session_college']===$r['college_id'], 'Page used Dean identity or stale college: '.$page);
    if ($page!=='manage-college-faculty') $check(strpos($body,'../college-dean/custom_css.css')!==false, 'Shared theme path missing: '.$page);
    if ($page==='students') $check(strpos($body,'Login Activity')===false && strpos($body,'id="deanStudentLoginModal"')===false && strpos($body,'student-login-activity.js')===false,'The shared Chair student page exposed Dean login activity controls.');
    if ($page==='index') {
        $check(strpos($body,'dashboard_program_chair_schedule_heatmap.php')!==false && strpos($body,'load_program_chair_room_time_matrix.php')!==false, 'Chair dashboard chart endpoints missing.');
        preg_match_all('/<script(?:\s[^>]*)?>(.*?)<\/script>/s',$body,$matches);
        $scriptPath=$root.'/tmp/chair-rendered-dashboard.js'; file_put_contents($scriptPath,implode("\n",$matches[1]));
        exec('node --check '.escapeshellarg($scriptPath).' 2>&1',$output,$exit); unlink($scriptPath);
        $check($exit===0,'Rendered dashboard JavaScript failed to compile: '.implode("\n",$output));
    }
}
$r=$request('reassigned_page');
$check($r['http_status']===200 && $r['session_college']===$r['other_college'], 'Administrator reassignment was not refreshed by the page.');
$r=$request('historical_page');
$check($r['http_status']===200 && strpos($r['response'],'data-can-assign="0"')!==false && strpos($r['response'],'This term is for viewing only')!==false,'An earlier term was editable in the faculty page.');
foreach (['heatmap','matrix','rosters','students','reports','prospectus'] as $scenario) {
    $r=$request($scenario);
    $check($r['http_status']===200 && $r['session_college']===$r['college_id'], 'Shared endpoint failed or used a stale scope: '.$scenario);
    if ($scenario==='matrix') $check(is_string($r['response']) && strpos($r['response'],'Unauthorized')===false && strpos($r['response'],'CSRF validation failed')===false, 'Read-only room matrix did not render.');
    elseif ($scenario==='prospectus') $check(!isset($r['response']['error']), 'Assigned-college prospectus failed.');
    else $check(($r['response']['status']??'')==='ok', 'Shared endpoint returned an error: '.$scenario.' '.json_encode($r['response']));
    if ($scenario==='students') $check(strpos(json_encode($r['response']),'login_success_count')===false && strpos(json_encode($r['response']),'login_failure_count')===false,'The Chair student directory exposed Dean login statistics.');
}
$r=$request('student_login_activity');
$check($r['http_status']===403 && strpos($r['response']['message'] ?? '','Only the assigned College Dean')!==false,'An authorized Program Chair could read student login history.');
$r=$request('prospectus_outside');
$check($r['http_status']===403,'Program Chair could view another college prospectus.');
foreach (['script_workload'=>'query_program_chair_rosters.php','script_students'=>'query_program_chair_students.php','script_reports'=>'query_program_chair_faculty_students.php'] as $scenario=>$endpoint) {
    $r=$request($scenario);
    $check($r['http_status']===200 && strpos($r['response'],$endpoint)!==false && strpos($r['response'],'query_college_dean_')===false,'Shared JavaScript did not use Chair endpoints: '.$scenario);
}
$r=$request('script_invalid');
$check($r['http_status']===404 && $r['response']==='', 'The shared-script endpoint exposed a non-whitelisted file.');
$r=$request('list');
$check($r['response']['status']==='ok' && in_array($r['faculty_id'],array_column($r['response']['available'],'faculty_id'),true), 'Shared active faculty directory did not populate available faculty.');
foreach (['assign'=>'inserted','reactivate'=>'reactivated','legacy_reactivate'=>'reactivated','duplicate'=>'duplicate','legacy_duplicate'=>'duplicate'] as $scenario=>$status) {
    $r=$request($scenario);
    $check($r['response']['status']===$status && count($r['assignments'])===1 && (int)$r['assignments'][0]['college_id']===$r['college_id'] && $r['assignments'][0]['status']==='active', 'Assignment/reactivation/duplicate handling failed: '.$scenario.' '.json_encode($r['response']));
    $check($r['classification']===($status==='duplicate'?'permanent':'temporary'),'Shared faculty classification changed incorrectly: '.$scenario);
}
$r=$request('write_failure');
$check($r['http_status']===500 && $r['response']['status']==='error' && !$r['assignments'] && $r['classification']==='permanent', 'A failed classification write did not roll back the new college-list entry.');
$r=$request('scheduler_sync');
$schedulerRows=array_column($r['response']['data'],null,'faculty_id');
$check($r['response']['status']==='success' && isset($schedulerRows[$r['faculty_id']])
    && $schedulerRows[$r['faculty_id']]['employment_classification']==='temporary',
    'The unchanged Scheduler endpoint did not see the Chair faculty assignment and classification.');
$r=$request('manage_list');
$check($r['response']['status']==='success' && count($r['response']['data'])===10 && $r['response']['pagination']['total']===26 && $r['response']['pagination']['has_more']===true,'The management list did not search and paginate like Scheduler.');
$r=$request('manage_dropdown');
$check(!in_array($r['faculty_id'],array_column($r['response'],'id'),true),'The faculty picker offered an already assigned faculty member.');
$r=$request('manage_catalog');
$check($r['response']['status']==='success' && count($r['response']['data'])>0,'Active designation choices did not load.');
$r=$request('account_list');
$check(count($r['response']['data'])===1 && !isset($r['response']['data'][0]['faculty_email']) && $r['response']['data'][0]['login_roles']===['professor'] && $r['response']['data'][0]['login_can_manage']===true,'The faculty list must return only relevant login roles without credentials.');
}
$r=$request('account_load');
$check($r['response']['status']==='ok' && $r['response']['account']['can_manage'] && $r['response']['account']['roles']===['professor'] && $r['response']['account']['revision']!=='','The login dialog did not load the linked faculty account.');
$publicAccountSafe=static function (array $account) use ($check): void {
    $check(!array_intersect(array_keys($account),['email','username','password','protected_roles','user_id'])
        && !array_diff($account['roles'],['professor','scheduler'])
        && in_array($account['primary_role'],['','professor','scheduler'],true),'Program Chair responses exposed credentials or unrelated roles.');
};
$publicAccountSafe($r['response']['account']);
foreach (['account_create','account_create_inactive','account_create_scheduler','account_deactivate','account_activate','account_scheduler_only','account_professor_only','account_existing_no_credentials'] as $scenario) {
    $r=$request($scenario);
    $check($r['http_status']===200 && $r['response']['status']==='saved' && $r['protected_unchanged'] && $r['credentials_unchanged'], 'Faculty login account could not be saved without changing existing credentials/workloads: '.$scenario.' '.json_encode($r['response']));
    $check($r['login_status']===(in_array($scenario,['account_create_inactive','account_deactivate'],true)?'inactive':'active') && !array_diff($r['login_roles'],['professor','scheduler']), 'The saved status or roles are incorrect: '.$scenario);
    $check($r['login_college']===$r['college_id'],'The assigned college was lost when saving faculty login roles: '.$scenario);
    if (strpos($scenario,'scheduler')!==false || !in_array($scenario,['account_professor_only'],true)) {
        if (in_array('scheduler',$r['login_roles'],true)) $check(count($r['login_scopes'])===1 && (int)$r['login_scopes'][0]['college_id']===$r['college_id'],'Scheduler access expanded beyond the Chair college: '.$scenario);
    }
    if ($scenario==='account_professor_only') $check(!$r['login_scopes'],'Removing Scheduler left its college access enabled.');
    $check($r['login_email']!=='' && $r['login_email']===strtolower($r['login_email']),'Faculty identity link was lost when saving login roles: '.$scenario);
    $publicAccountSafe($r['response']['account']);
}
$r=$request('account_login_scheduler');
$check($r['response']['status']==='success' && $r['response']['redirect']==='scheduler/' && $r['session_role']==='scheduler' && $r['session_college']===$r['college_id'],'The real login chooser did not use the Chair-assigned Scheduler role and college.');
$r=$request('account_login_inactive');
$check($r['response']['status']==='inactive','The real shared login accepted a Chair-deactivated account.');
foreach (['account_protected','account_protected_primary','account_inactive_privilege','account_legacy_primary','account_empty_primary','account_legacy_protected'] as $scenario) {
    $r=$request($scenario);
    $check($r['http_status']===200 && $r['response']['status']==='saved' && in_array('scheduler',$r['login_roles'],true)
        && $r['credentials_unchanged'] && $r['protected_unchanged'],'Faculty/Scheduler editing failed on an administrator-managed or legacy account: '.$scenario.' '.json_encode($r['response']));
    if ($scenario!=='account_legacy_protected') $check($r['admin_roles_unchanged'],'Administrator role rows changed: '.$scenario);
    if ($scenario==='account_inactive_privilege') $check($r['admin_roles'][0]['status']==='inactive','Editing faculty access reactivated an inactive DI role.');
    if (in_array($scenario,['account_protected_primary','account_legacy_protected'],true)) $check($r['login_primary']==='admin' && $r['response']['account']['can_manage_primary']===false,'The administrator default workspace was replaced.');
    $publicAccountSafe($r['response']['account']);
}
foreach (['account_protected_status'=>403,'account_protected_default'=>403,'account_protected_failure'=>500,'account_other_scope'=>403,'account_other_home'=>403,'account_escalation'=>403,'account_empty_roles'=>422,'account_duplicate_roles'=>422,'account_bad_primary'=>422,'account_invalid_status'=>422,'account_email_change'=>403,'account_duplicate_email'=>409,'account_invalid_email'=>422,'account_long_email'=>422,'account_stale'=>409,'account_cross_college'=>403,'account_unassigned'=>403,'account_bad_csrf'=>403,'account_old_term'=>409,'account_extra_fields'=>400,'account_array_input'=>400,'account_inactive_faculty'=>422,'account_wrong_role'=>403,'account_revoked'=>403,'account_reassigned'=>403,'account_create_failure'=>500] as $scenario=>$status) {
    $r=$request($scenario);
    $check($r['http_status']===$status && $r['response']['status']==='error' && $r['protected_unchanged'] && $r['credentials_unchanged'] && $r['account_unchanged'] && $r['account_count_unchanged'],'Login permission, validation, or transaction rollback failed: '.$scenario.' '.json_encode($r['response']));
    if (in_array($scenario,['account_create_failure','account_duplicate_email','account_invalid_email','account_long_email'],true)) $check($r['login_email']==='','An invalid or failed request left a new login linked to the faculty.');
    else $check(!in_array('scheduler',$r['login_roles'],true) || $scenario==='account_other_scope','A denied request still granted Scheduler: '.$scenario);
}
if (!$accountsOnly) {
foreach (['manage_designation','manage_clear','manage_classification','manage_remove'] as $scenario) {
    $r=$request($scenario);
    $check($r['http_status']===200 && $r['response']['status']===($scenario==='manage_remove'?'removed':'updated') && $r['protected_unchanged'],'Faculty management failed or changed schedules/workloads: '.$scenario);
    if ($scenario==='manage_designation') $check((int)$r['designation_rows'][0]['designation_id']>0 && $r['designation_rows'][0]['record_source']==='selected','The designation was not saved to the same term storage as Scheduler.');
    if ($scenario==='manage_clear') $check($r['designation_rows'][0]['designation_id']===null && $r['designation_rows'][0]['designation_name']==='','No designation did not clear the term appointment.');
    if ($scenario==='manage_classification') $check($r['classification']==='temporary','Classification did not update the shared faculty record.');
    if ($scenario==='manage_remove') $check(!$r['assignments'],'Remove did not delete only the college-list entry.');
}
foreach (['manage_invalid_designation'=>422,'manage_unassigned'=>403,'manage_old_term'=>409,'manage_remove_old_term'=>409,'manage_remove_outside'=>403,'manage_cross_college'=>403,'manage_bad_csrf'=>403] as $scenario=>$status) {
    $r=$request($scenario);
    $check($r['http_status']===$status && count($r['assignments'])===1 && !$r['designation_rows'] && $r['classification']==='permanent' && $r['protected_unchanged'],'Invalid faculty management changed data: '.$scenario.' '.json_encode($r['response']));
}
$r=$request('copy_options');
$check($r['response']['status']==='ok' && count($r['response']['terms'])>0 && count($r['assignments'])===1,'Opening the previous-list dialog changed current-term faculty.');
$r=$request('copy_preview');
$previewRows=array_column($r['response']['rows'],null,'id');
$check($r['response']['status']==='ok' && $previewRows[$r['faculty_id']]['eligible'] && $r['response']['preview_token'] && count($r['assignments'])===1,'Copy preview did not select eligible source faculty without adding records.');
foreach (['copy_confirm','copy_selected','copy_duplicate'] as $scenario) {
    $r=$request($scenario);
    $check($r['response']['status']==='ok' && count($r['assignments'])===2 && $r['protected_unchanged'],'Copying a previous faculty list failed or changed schedules/workloads: '.$scenario.' '.json_encode($r['response']));
    if ($scenario==='copy_duplicate') $check($r['response']['copied']===0 && $r['response']['skipped']===1,'Copy did not skip a faculty member who was already added.');
    else $check(count($r['designation_rows'])===2 && $r['response']['copied']===1 && $r['designation_rows'][1]['record_source']===($scenario==='copy_selected'?'selected':'copied'), 'Copy did not retain the source designation or preserve a selected current appointment.');
}
foreach (['copy_forged'=>403,'copy_expired'=>409,'copy_old_term'=>409,'copy_bad_csrf'=>403,'copy_outside'=>403,'copy_rooms'=>400,'copy_replay'=>409] as $scenario=>$status) {
    $r=$request($scenario);
    $check($r['http_status']===$status && $r['response']['status']==='error' && count($r['assignments'])===($scenario==='copy_replay'?2:1) && $r['protected_unchanged'],'Copy accepted an invalid or reused request: '.$scenario.' '.json_encode($r['response']));
}
foreach (['csrf'=>403,'cross_college'=>403,'reassigned'=>403,'revoked'=>403,'inactive'=>403,'wrong_role'=>403,'array_input'=>403,
    'inactive_faculty'=>422,'invalid_faculty'=>422,'invalid_classification'=>422,'old_term'=>422,'method'=>405] as $scenario=>$status) {
    $r=$request($scenario);
    $check($r['http_status']===$status && $r['response']['status']==='error' && !$r['assignments'] && $r['classification']==='permanent', 'Invalid request changed faculty data: '.$scenario.' '.json_encode($r));
}
foreach (['dashboard_college_dean_schedule_heatmap.php'=>'dashboard_program_chair_schedule_heatmap.php','load_college_dean_room_time_matrix.php'=>'load_program_chair_room_time_matrix.php'] as $source=>$copy) {
    $expected=str_replace(["!== 'college_dean'","['college_dean']"],["!== 'program_chair'","['program_chair']"],file_get_contents($root.'/backend/'.$source));
    $expected=preg_replace('/^<\?php\r?\n/', "<?php\ndefine('SYNK_PROGRAM_CHAIR_API', true);\n", $expected);
    $expected=str_replace("\r\n", "\n", $expected);
    $check(str_replace("\r\n", "\n", file_get_contents($root.'/backend/'.$copy))===$expected,'Chair chart calculations differ from the unchanged Dean endpoint.');
}
}
$check($before===$snapshot(),'Fixture requests changed real faculty, account, scheduling, or workload data.');
echo 'Program Chair module ', $http?'HTTP':'CLI', ': ',$checks,' checks passed. All fixture records rolled back.',PHP_EOL;
