<?php
// Real PHP 7 HTTP requests; management fixture writes use outer rolled-back transactions.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/db.php';
$checks=0; $check=static function (bool $ok,string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
$snapshot=static function () use ($conn): array {
    $values=[];
    foreach (['tbl_useraccount','tbl_useraccount_roles','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_sections',
        'tbl_enrollment_headers','tbl_student_portal_profile','tbl_class_schedule','tbl_faculty_workload_sched','tbl_prospectus_offering','tbl_offering_enrollee_counts'] as $table) {
        $values[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }
    return $values;
};
$before=$snapshot(); $token=bin2hex(random_bytes(24)); $routeName='student-management-qa-'.bin2hex(random_bytes(8)).'.php';
$routePath=__DIR__.'/../tmp/'.$routeName;
$route= <<<'PHP'
<?php
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true) || !is_string($_GET['qa'] ?? null) || !hash_equals(QA_TOKEN,$_GET['qa'])) { http_response_code(404); exit; }
header('X-QA-PHP-Version: '.PHP_VERSION);
define('SYNK_DEAN_STUDENTS_QA',true); define('SYNK_STUDENT_MANAGEMENT_QA',(string)($_GET['scenario'] ?? 'create'));
$_GET['scenario']='list'; require QA_FIXTURE;
PHP;
file_put_contents($routePath,str_replace(['QA_TOKEN','QA_FIXTURE'],[var_export($token,true),var_export(__DIR__.'/fixtures/college-dean-students-request.php',true)],$route));
$version='';
$request=static function (string $scenario) use ($routeName,$token,&$version,$check): array {
    $ctx=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'follow_location'=>0,'timeout'=>30,'header'=>"Connection: close\r\n",'content'=>'']]);
    $body=file_get_contents('http://localhost/synk/tmp/'.$routeName.'?qa='.$token.'&scenario='.rawurlencode($scenario),false,$ctx);
    preg_match('/X-QA-PHP-Version: ([^\r\n]+)/i',implode("\n",$http_response_header ?? []),$m); $version=$m[1] ?? 'unknown';
    $r=json_decode((string)$body,true);
    if (!$r || isset($r['fixture_error'])) throw new RuntimeException($scenario.': '.substr((string)$body,0,2300));
    $check($r['schedules_unchanged'],'Scheduling records changed in '.$scenario);
    return $r;
};
try {
    $r=$request('form_options'); $check($r['response']['status']==='ok' && in_array($r['scope']['program_id'],array_column($r['response']['programs'],'program_id')) && !in_array($r['foreign_scope']['program_id'],array_column($r['response']['programs'],'program_id')),'Creation options leaked another college.');
    $r=$request('selection'); $check(count($r['response']['student_ids'])===2 && !in_array($r['profiles']['foreign']['student_id'],$r['response']['student_ids']),'Select matching results included foreign students.');
    $r=$request('edit_load'); $check($r['response']['student']['student_id']===$r['profiles']['own']['student_id'] && strlen($r['response']['student']['version'])===64,'Editor did not issue a student version.');
    $r=$request('edit_load_foreign'); $check($r['http_status']===422 && $r['records_unchanged'],'Editor leaked a foreign profile.');
    $r=$request('propose'); $check($r['response']['candidate']['status']==='ready' && strpos($r['response']['candidate']['email'],'caroljanambrocio')===0,'Email suggestion lost a compound first name.');
    foreach (['propose_initial','propose_collision'] as $scenario) { $r=$request($scenario); $check($r['response']['candidate']['status']==='review','An unsafe email was proposed: '.$scenario); }
    foreach (['create','create_blank_email'] as $scenario) {
        $r=$request($scenario); $check($r['http_status']===200 && $r['response']['student_id']>0,'Student creation failed: '.json_encode($r['response']));
        $created=array_column($r['manage_students'],null,'student_id')[$r['response']['student_id']];
        $check($created['source_sheet_name']==='DEAN MANUAL' && strpos($created['student_number'],'000')===0 && $created['first_name']==='Carol Jane' && strpos($created['middle_name'],'Güell')===0,'Creating a student lost identity fields or leading zeros.');
        $check(($scenario==='create_blank_email')===($created['email_address']===''),'Creation did not preserve the explicitly chosen blank/email value.');
        $listed=array_column($r['manage_directory']['rows'],null,'student_id');
        $check(isset($listed[$created['student_id']]) && $listed[$created['student_id']]['subject_count']===0 && $listed[$created['student_id']]['home_section']==='Not verified','New standalone students did not appear in the directory or received an invented class/home section.');
    }
    foreach (['save','save_shared','save_portal'] as $scenario) {
        $r=$request($scenario); $check($r['http_status']===200 && $r['response']['status']==='ok','Student edit failed: '.json_encode($r['response']));
        $edited=array_column($r['manage_students'],null,'student_id')[$r['response']['student_id']];
        $check($edited['first_name']==='Carol Jane' && $edited['middle_name']==='Güell' && strpos($edited['email_address'],'verified')===0,'The editor did not save the corrected name and verified email.');
        $check(count($r['manage_memberships'])===7,'Editing a profile altered its class membership links.');
        if ($scenario==='save_portal') $check($r['manage_portal_profiles'][0]['email_address']===$edited['email_address'],'Changing a login email did not preserve the existing portal profile link.');
    }
    foreach (['create_duplicate'=>422,'create_foreign_program'=>422,'create_bad_term'=>422,'create_bad_year'=>422,
        'save_foreign'=>422,'save_stale'=>422,'save_id_change'=>422,'save_collision'=>422,'save_bad_email'=>422,'save_damaged_name'=>422,'save_blank_name'=>422,'save_array'=>400,
        'save_portal_conflict'=>422,'save_bad_csrf'=>403,'save_revoked'=>403] as $scenario=>$status) {
        $r=$request($scenario); $check($r['http_status']===$status && $r['records_unchanged'],'Rejected student edit wrote data or returned wrong status: '.$scenario.' '.json_encode($r['response']));
    }
    foreach (['home_verified','home_unapproved','home_conflict','home_foreign','home_other_term'] as $scenario) {
        $r=$request($scenario); $rows=array_column($r['response']['rows'],null,'student_id'); $home=$rows[$r['profiles']['shared']['student_id']]['home_section'];
        $check($scenario==='home_verified' ? strpos($home,'QA 1')===0 : ($scenario==='home_conflict' ? $home==='Needs verification' : $home==='Not verified'),'Home section inferred from an unapproved, foreign, other-term or conflicting enrollment: '.$scenario.' '.$home);
    }
    $r=$request('delete_preview'); $check($r['records_unchanged'] && $r['response']['summary']['class_records']===3 && $r['response']['summary']['students']===2,'Deletion preview changed records or used the wrong term.');
    foreach (['delete'=>3,'delete_year'=>4,'delete_section'=>1,'delete_count'=>3,'delete_manual_count'=>3] as $scenario=>$count) {
        $r=$request($scenario); $check($r['http_status']===200 && $r['response']['removed']===$count,'Deleting selected term records failed: '.$scenario.' '.json_encode($r['response']));
        $links=$r['manage_memberships']; $active=array_values(array_filter($links,static function ($row) { return (int)$row['is_active']===1; }));
        $check(count($active)===6-$count && count($r['manage_students'])===5,'Deletion removed master identities or affected unexpected class lists.');
        foreach ($links as $row) {
            if ((int)$row['college_id']===$r['foreign_scope']['college_id']) $check((int)$row['is_active']===1,'Deletion affected another college.');
            if ((int)$row['semester']!==$r['term']['semester'] && $scenario!=='delete_year' && $scenario!=='delete_section') $check((int)$row['is_active']===1,'Deletion affected another semester.');
        }
        if ($scenario==='delete_count') $check((int)$r['manage_count']['total_enrollees']===$r['manage_count_expected'],'Roster-derived workload counts were not refreshed.');
        if ($scenario==='delete_manual_count') $check((int)$r['manage_count']['total_enrollees']===$r['manage_count_expected']+1 && $r['manage_count']['source_kind']==='manual','Deletion overwrote a manually managed student count.');
        $check($r['manage_deleted_pending']===0,'A successful deletion token remained reusable.');
    }
    $r=$request('delete_manual');
    $check($r['response']['manual_removed']===1 && count($r['manage_students'])===6 && count($r['manage_directory']['rows'])===2,'Deleting a manual term entry erased its shared identity or left it in the active directory.');
    foreach (['delete_all_terms'=>422,'delete_foreign'=>422,'delete_empty'=>422,'delete_duplicate'=>422,'delete_expired'=>422,'delete_other_user'=>422,'delete_forged'=>422,'delete_stale'=>422,
        'delete_reassigned'=>422,'delete_revoked'=>403,'delete_bad_csrf'=>403,'delete_count_failure'=>500,'delete_replay'=>422] as $scenario=>$status) {
        $r=$request($scenario); $check($r['http_status']===$status && $r['records_unchanged'],'Rejected deletion affected records or returned wrong status: '.$scenario.' '.json_encode($r['response']));
    }
    $check($snapshot()===$before,'Student management tests changed real student data or scheduling.');
    echo 'Student management: '.$checks.' checks passed on PHP '.$version.'. All fixture records rolled back.'.PHP_EOL;
} finally { if (is_file($routePath)) unlink($routePath); }
