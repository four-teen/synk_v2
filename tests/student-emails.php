<?php
// Actual PHP 7 HTTP requests; every fixture and email save rolls back.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/student_email_helper.php';
$checks=0;
$check=static function (bool $ok,string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
$snapshot=static function () use ($conn): array {
    $rows=[];
    foreach (['tbl_useraccount','tbl_useraccount_roles','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_sections',
        'tbl_faculty_workload_sched','tbl_class_schedule','tbl_prospectus_offering','tbl_offering_enrollee_counts'] as $table) {
        $rows[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }
    return $rows;
};
$before=$snapshot(); $token=bin2hex(random_bytes(24));
$routeName='student-emails-qa-'.bin2hex(random_bytes(8)).'.php'; $routePath=__DIR__.'/../tmp/'.$routeName;
$route= <<<'PHP'
<?php
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true) || !is_string($_GET['qa'] ?? null) || !hash_equals(QA_TOKEN,$_GET['qa'])) { http_response_code(404); exit; }
header('X-QA-PHP-Version: '.PHP_VERSION);
define('SYNK_DEAN_STUDENTS_QA',true); define('SYNK_STUDENT_EMAIL_QA',(string)($_GET['scenario'] ?? 'preview'));
$_GET['scenario']='list'; require QA_FIXTURE;
PHP;
file_put_contents($routePath,str_replace(['QA_TOKEN','QA_FIXTURE'],[var_export($token,true),var_export(__DIR__.'/fixtures/college-dean-students-request.php',true)],$route));
$version='';
$request=static function (string $scenario) use ($routeName,$token,&$version): array {
    $ctx=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'follow_location'=>0,'timeout'=>30,'header'=>"Connection: close\r\n",'content'=>'']]);
    $body=file_get_contents('http://localhost/synk/tmp/'.$routeName.'?qa='.$token.'&scenario='.rawurlencode($scenario),false,$ctx);
    preg_match('/X-QA-PHP-Version: ([^\r\n]+)/i',implode("\n",$http_response_header ?? []),$match); $version=$match[1] ?? 'unknown';
    $r=json_decode((string)$body,true);
    if (!$r || isset($r['fixture_error'])) throw new RuntimeException($scenario.': '.substr((string)$body,0,1800));
    return $r;
};
try {
    foreach ([['Elbren','Antonio','elbrenantonio'],['Carol Jan','Ambrocio','caroljanambrocio'],['José','Peña','josepena'],
        ['JOSÉ','PEÑA','josepena'],["Jose\u{0301}","Pen\u{0303}a",'josepena'],['María','Muñoz','mariamunoz'],['Mary Anne','Dela Cruz','maryannedelacruz'],["Jean-Luc","O’Neill",'jeanluconeill']] as $case) {
        $candidate=synk_student_email_candidate(['first_name'=>$case[0],'last_name'=>$case[1],'middle_name'=>'Excluded']);
        $check($candidate['status']==='ready' && $candidate['email']===$case[2].'@sksu.edu.ph','Email normalization failed for '.$case[0]);
    }
    foreach ([['','Pena'],['José','Pe??a'],['J.','Pena'],['J A','Pena'],['José',"Pe\u{FFFD}a"],['123','Pena'],['明','李'],[str_repeat('A',65),'Pena']] as $case) {
        $check(synk_student_email_candidate(['first_name'=>$case[0],'last_name'=>$case[1]])['status']==='review','An unclear or invalid name was assigned an email.');
    }
    $check(synk_student_email_candidate(['email_address'=>'Verified.Alias@sksu.edu.ph'])['email']==='Verified.Alias@sksu.edu.ph','An existing email was normalized or replaced.');
    $r=$request('preview');
    $check($r['http_status']===200 && $r['response']['summary']===['ready'=>2,'preserved'=>0,'review'=>0,'students'=>2],'Preview did not deduplicate memberships or scope by imported college.');
    $check($r['records_unchanged'],'Preview wrote student data.');
    $previewRows=array_column($r['response']['rows'],null,'student_id');
    $check(strpos($previewRows[$r['profiles']['shared']['student_id']]['student_name'],'Peña')!==false,'Preview changed display name accents.');
    $r=$request('filtered'); $check($r['response']['summary']['students']===1,'Email preview ignored directory filters.');
    foreach (['collision_existing','collision_proposed','unclear','preserved'] as $scenario) {
        $r=$request($scenario); $s=$r['response']['summary'];
        $check($s['ready']===1 && ($scenario==='preserved' ? $s['preserved']===1 : $s['review']===1),'Email review failed: '.$scenario);
        $check($r['records_unchanged'],'Review preview modified data.');
        $check(strpos(json_encode($r['response']),'ForeignQA')===false,'Collision review exposed a foreign student.');
    }
    foreach (['save'=>2,'save_subset'=>1] as $scenario=>$expected) {
        $r=$request($scenario);
        $check($r['http_status']===200 && $r['response']['saved']===$expected,'Approved emails did not save: '.json_encode($r['response']));
        $emails=array_column($r['email_profiles'],'email_address','student_id');
        $check($emails[$r['profiles']['shared']['student_id']]!=='' && ($expected===1 ? $emails[$r['profiles']['own']['student_id']]==='' : strpos($emails[$r['profiles']['own']['student_id']],'caroljanambrocio')===0),'Selection was ignored or given name spelling was changed.');
        $check($emails[$r['profiles']['foreign']['student_id']]==='' && $emails[$r['profiles']['archived']['student_id']]==='' && $emails[$r['profiles']['unimported']['student_id']]==='','Bulk save touched foreign, archived, or unimported profiles.');
        $check($r['email_pending_count']===0,'Saved preview remained reusable.');
    }
    $r=$request('save_preserved'); $emails=array_column($r['email_profiles'],'email_address','student_id');
    $check($r['response']['saved']===1 && $emails[$r['profiles']['own']['student_id']]==='verified.alias@sksu.edu.ph','An existing school alias was overwritten while filling missing emails.');
    foreach (['save_foreign'=>422,'save_empty'=>422,'save_duplicate'=>422,'save_string'=>422,'save_forged'=>422,'save_bad_json'=>422,'save_object'=>422,'save_array'=>400,
        'expired'=>422,'other_user'=>422,'reassigned'=>422,'revoked'=>403,'stale_name'=>422,'stale_number'=>422,'stale_email'=>422,'stale_membership'=>422,'collision_stale'=>422,
        'failure'=>500,'bad_csrf'=>403,'scope_tamper'=>403,'other_role'=>403] as $scenario=>$status) {
        $r=$request($scenario);
        $check($r['http_status']===$status,'Email request validation failed: '.$scenario.' '.json_encode($r['response']));
        $check($r['records_unchanged'],'Rejected save changed student data: '.$scenario);
    }
    $r=$request('replay'); $check($r['http_status']===422 && $r['records_unchanged'],'An email preview token could be replayed.');
    $check($snapshot()===$before,'Email tests changed real data or scheduling records.');
    echo 'Student emails: '.$checks.' checks passed; HTTP on PHP '.$version.'. All fixture records rolled back.'.PHP_EOL;
} finally { if (is_file($routePath)) unlink($routePath); }
