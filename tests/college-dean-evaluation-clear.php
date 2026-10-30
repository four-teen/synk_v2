<?php
// HTTP controller regression tests; every request rolls back its isolated fixtures.
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/../backend/db.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$snapshot=static function()use($conn):array{
    $state=[];
    foreach(['tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_college_faculty_evaluation_clear_history','tbl_college_evaluation_settings','tbl_college_evaluation_audit','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_useraccount','tbl_useraccount_roles','tbl_faculty','tbl_college','tbl_program','tbl_academic_settings','tbl_class_schedule','tbl_faculty_workload_sched','tbl_college_faculty'] as $table)$state[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    return array_map(static function(array $rows):string{return hash('sha256',json_encode($rows));},$state);
};
$before=$snapshot();$token=bin2hex(random_bytes(24));$route=__DIR__.'/../tmp/dean-clear-qa-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php define(\'SYNK_STUDENT_PORTAL_QA\','.var_export($token,true).');require '.var_export(__DIR__.'/fixtures/student-portal-request.php',true).';');
$request=static function(string $case)use($route,$token,$check):array{
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>'dean_faculty_evaluations_clear_'.$case]));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false]);
    $raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    $headers=substr($raw,0,$size);$body=substr($raw,$size);$data=json_decode($body,true);
    $check(is_array($data),'JSON response for '.$case.': HTTP '.$status.' '.substr(strip_tags($body),-1500));
    preg_match('/X-QA-Clear-State: ([^\r\n]+)/i',$headers,$match);$state=json_decode(rawurldecode($match[1]??''),true);
    $check(is_array($state),'Database verification is available: '.$case);
    $check($state['other_unchanged'] && $state['controls_unchanged'],'Other faculty, colleges, terms, ambiguous legacy responses and access controls are preserved: '.$case);
    return ['status'=>$status,'data'=>$data,'state'=>$state];
};
try{
    $r=$request('preview');$s=$r['state'];
    $check($r['status']===200 && $r['data']['ok'] && $r['data']['counts']===['submitted'=>2,'drafts'=>1,'answers'=>50],'Preview counts both submitted responses and saved drafts.');
    $check($s['target_unchanged'] && $s['history_count']===0,'Preview changes no evaluation or history.');
    $check(!isset($r['data']['answers']) && !isset($r['data']['evaluations']) && strpos(json_encode($r['data']),'CLEAR_OWN_DRAFT')===false,'Preview reveals only scope and counts, never private responses.');
    foreach(['success'=>[2,1],'instrument'=>[1,0],'historical'=>[1,0]] as $case=>$counts){
        $r=$request($case);$s=$r['state'];
        $check($r['status']===200 && $r['data']['ok'],'Scoped clearing succeeds: '.$case.' '.json_encode($r['data']));
        $check($s['remaining']===0 && $s['answers']===0,'Headers and all answers clear together: '.$case);
        $check($s['history_count']===1 && $s['archived_exactly'] && $s['actor_correct'] && [$s['submitted'],$s['drafts']]===$counts,'Complete original responses and actor are archived atomically: '.$case.' '.json_encode($s));
        $check($s['token_consumed'],'Successful clearing consumes its preview: '.$case);
    }
    $rejected=['get'=>405,'csrf'=>403,'role'=>403,'faculty_zero'=>422,'faculty_array'=>422,'foreign'=>404,'college'=>403,'campus'=>403,'bad_term'=>422,'bad_instrument'=>422,'bulk'=>422,'expired'=>409,'unknown'=>409,'malformed'=>422,'actor'=>409,'reassigned'=>403,'revoked'=>403,'stale'=>409,'stale_answer'=>409,'failure_history'=>500,'failure_headers'=>500];
    foreach($rejected as $case=>$status){
        $r=$request($case);$s=$r['state'];
        $check($r['status']===$status && $r['data']['ok']===false,'Invalid or stale request is rejected: '.$case.' HTTP '.$r['status'].' '.json_encode($r['data']));
        $check($s['target_unchanged'] && $s['history_count']===0,'Rejected requests or storage failures roll back all evaluation and history changes: '.$case);
    }
    $r=$request('replay');
    $check($r['status']===409 && $r['state']['history_count']===1 && $r['state']['remaining']===0,'Replaying a completed token cannot perform another clearing.');
}finally{if(is_file($route))unlink($route);}
$after=$snapshot();
$check($before===$after,'Every fixture rolled back; original database records are unchanged. Changed tables: '.implode(', ',array_keys(array_diff_assoc($before,$after))));
echo 'College Dean evaluation clearing: ',$checks,' checks passed. All fixture records rolled back.',PHP_EOL;
