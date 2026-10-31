<?php
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
require_once __DIR__.'/../backend/db.php';
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void {if(!$ok)throw new RuntimeException($message);$checks++;};
$snapshot=static function()use($conn):array {$data=[];foreach(['tbl_supervisor_evaluation_settings','tbl_supervisor_evaluation_assignments','tbl_supervisor_evaluations','tbl_supervisor_evaluation_audit','tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_class_schedule','tbl_faculty_workload_sched','tbl_offering_enrollee_counts','tbl_academic_settings'] as $table)$data[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));return $data;};
$before=$snapshot();$token=bin2hex(random_bytes(24));$route=__DIR__.'/../tmp/supervisor-qa-'.bin2hex(random_bytes(8)).'.php';
if(file_put_contents($route,'<?php define(\'SYNK_STUDENT_PORTAL_QA\','.var_export($token,true).');require '.var_export(__DIR__.'/fixtures/student-portal-request.php',true).';')===false)throw new RuntimeException('Unable to create the protected local test route.');
$request=static function(string $case)use($route,$token,$check):array {
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>'supervisor_'.$case]));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>35,CURLOPT_FOLLOWLOCATION=>false]);$raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$headers=substr($raw,0,$size);$body=substr($raw,$size);$state=null;
    if(preg_match('/X-QA-Supervisor-State: ([^\r\n]+)/i',$headers,$match))$state=json_decode(rawurldecode($match[1]),true);
    $check(!preg_match('/Fatal error|Parse error|xdebug-error|Warning(?:<\/b>)?:/i',$body),'No PHP errors: '.$case.' HTTP '.$status.' '.substr(strip_tags($body),-1700));
    $check(is_array($state),'The protected fixture completed: '.$case.' '.substr(strip_tags($body),-1000));
    return ['status'=>$status,'headers'=>$headers,'body'=>$body,'state'=>$state];
};
try {
    $r=$request('chair_get');$check($r['status']===200 && substr_count($r['body'],'name="ratings[')===15 && strpos($r['body'],'Submits updated syllabus')!==false,'The chair receives the exact independent 15-item supervisor form.');
    $check(strpos($r['body'],'Supervisor evaluation controls')===false && strpos($r['body'],'name="reviewer_user_id"')===false,'The chair cannot manage collection or assign reviewers.');
    $check(strpos($r['headers'],'X-QA-Protected-Unchanged: yes')!==false,'Viewing the workspace does not alter students, schedules or workloads.');
    $r=$request('dean_get');$check($r['status']===200 && strpos($r['body'],'Supervisor evaluation controls')!==false && strpos($r['body'],'name="reviewer_user_id"')===false,'The Dean sees automatic routing and can open supervisor collection.');
    $check(strpos($r['body'],'id="sefYear"')===false && strpos($r['body'],'Load Evaluations')===false,'Evaluation pages use the module viewing term without duplicate selectors.');
    $r=$request('dean_private');$check($r['status']===200 && strpos($r['body'],'PRIVATE_CHAIR_DRAFT')===false,'A chair draft is not exposed to the Dean workspace or embedded form data.');
    $r=$request('chair_save');$check($r['status']===302 && count($r['state'])===1 && $r['state'][0]['submission_status']==='draft','Actual chair form saves a complete draft.');
    foreach(['chair_bad_csrf','chair_self','chair_closed','chair_past'] as $case){$r=$request($case);$check(in_array($r['status'],[403,422],true) && count($r['state'])===0,'Unauthorized or closed/historical saves are rejected: '.$case);}
    foreach(['chair_final','dean_final'] as $case){$r=$request($case);$check($r['status']===302 && count($r['state'])===($case==='chair_final'?2:1) && !array_filter($r['state'],static function(array $row):bool{return $row['submission_status']!=='submitted';}),'Final submission works through the actual portal: '.$case);}
    foreach(['chair_results','dean_results','historical'] as $case){$r=$request($case);$check($r['status']===200 && strpos($r['body'],'82.20%')!==false && strpos($r['body'],'43.80')!==false && strpos($r['body'],'38.40')!==false,'The shared college list matches the supplied 60/40 sample, including historical viewing: '.$case);}
    foreach(['chair_report','dean_report'] as $case){$r=$request($case);$check($r['status']===200 && strpos($r['body'],'data-sef-total>82.20%')!==false && strpos($r['body'],'PRIVATE_CHAIR_DRAFT &lt;script&gt;unsafe()&lt;/script&gt;')!==false,'Both portals receive the same individual report with escaped supervisor comments: '.$case);$check(strpos($r['body'],'Student feedback &lt;script&gt;unsafe()&lt;/script&gt;')!==false && strpos($r['body'],'name="ratings[')===false,'Report includes original submitted comments, without editable rating fields.');}
    $r=$request('chair_shared_draft');$check($r['status']===200 && strpos($r['body'],'PRIVATE_CHAIR_DRAFT')!==false && strpos($r['body'],'Draft saved')!==false,'A tied program Chair receives the first Chair saved draft inputs.');
    $r=$request('chair_shared_done');$check($r['status']===200 && strpos($r['body'],'Submitted by')!==false && strpos($r['body'],'Completed')!==false && strpos($r['body'],'data-sef-evaluate=')===false,'The other tied Chair sees completion and cannot start a second evaluation.');
    $r=$request('report_foreign');$check($r['status']===404 && strpos($r['body'],'id="sefIndividualReport"')===false,'A guessed report faculty outside the college is rejected.');
} finally {if(is_file($route))unlink($route);}
$check($before===$snapshot(),'All HTTP fixture records rolled back, including schedules and workloads.');
echo 'Supervisor evaluations HTTP: ',$checks,' checks passed. All fixture records rolled back.',PHP_EOL;
