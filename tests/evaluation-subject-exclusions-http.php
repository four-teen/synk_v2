<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/db.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void { if (!$ok) throw new RuntimeException($message); $checks++; };
$snapshot=static function()use($conn):string {
    $data=[];
    foreach (['tbl_college_evaluation_subject_exclusions','tbl_college_evaluation_subject_exclusion_audit','tbl_student_faculty_evaluation_subject_scope','tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_class_schedule','tbl_faculty_workload_sched','tbl_academic_settings'] as $table) $data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    return hash('sha256',json_encode($data));
};
$before=$snapshot(); $token=bin2hex(random_bytes(24)); $route=__DIR__.'/../tmp/subject-exclusion-qa-'.bin2hex(random_bytes(8)).'.php';
if (file_put_contents($route,'<?php define(\'SYNK_STUDENT_PORTAL_QA\','.var_export($token,true).'); require '.var_export(__DIR__.'/fixtures/student-portal-request.php',true).';')===false) throw new RuntimeException('Unable to create the protected localhost fixture.');
$request=static function(string $case,array $query=[])use($route,$token,$check):array {
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>'subject_exclusion_'.$case]+$query));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false]);
    $raw=curl_exec($curl); if ($raw===false) throw new RuntimeException(curl_error($curl));
    $size=curl_getinfo($curl,CURLINFO_HEADER_SIZE); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
    $headers=substr($raw,0,$size); $body=substr($raw,$size); $state=[];
    if (preg_match('/X-QA-Subject-Exclusions: ([^\r\n]+)/i',$headers,$match)) $state=json_decode(rawurldecode($match[1]),true);
    $check(!preg_match('/Fatal error|Parse error|xdebug-error|Warning(?:<\/b>)?:/i',$body),'No server errors: '.$case.' '.substr(strip_tags($body),-1200));
    $check(isset($state['rules'],$state['audit'],$state['responses']),'The rollback fixture completed: '.$case.' HTTP '.$status.' '.substr(strip_tags($body),-500));
    return ['status'=>$status,'headers'=>$headers,'body'=>$body,'state'=>$state];
};
try {
    $r=$request('get');
    $check($r['status']===200 && strpos($r['body'],'id="subjectExclusionForm"')!==false && strpos($r['body'],'name="csrf_token"')!==false,'The Dean can select a subject and see its protected management form.');
    $check(strpos($r['body'],'PRIVATE_DEAN_REASON &lt;script&gt;private&lt;/script&gt;')!==false && strpos($r['body'],'PRIVATE_DEAN_REASON <script>')===false,'Private reasons are rendered safely.');
    $check(strpos($r['headers'],'X-QA-Protected-Unchanged: yes')!==false,'Viewing the list cannot change original responses or academic records.');
    $check(strpos($r['body'],'id="subjectExclusionModal"')!==false && strpos($r['body'],'data-manage-subject="1"')!==false && strpos($r['body'],'id="dean-mobile-nav"')!==false,'Dean subject management uses a modal and the shared mobile navigation.');
    $r=$request('get',['search'=>'discrete','rule_filter'=>'included']);
    $check(strpos($r['body'],'1 of 4 subjects')!==false && strpos($r['body'],'value="discrete"')!==false,'Server-rendered searches match subject titles within the existing selected college and term.');
    $r=$request('get',['search'=>'<script>bad()</script>']);
    $check(strpos($r['body'],'value="&lt;script&gt;bad()&lt;/script&gt;"')!==false && strpos($r['body'],'<script>bad()</script>')===false,'Search text is escaped and cannot insert HTML.');
    foreach (['save','restore'] as $case) {
        $r=$request($case);
        $check($r['status']===302 && count($r['state']['audit'])===1 && (int)$r['state']['rules'][0]['excluded']===($case==='save'?1:0),'The actual Dean form saves and restores subject eligibility with audit: '.$case);
        $check(strpos($r['headers'],'X-QA-Protected-Unchanged: yes')!==false,'A subject-rule change preserves original responses, schedules and workloads.');
    }
    foreach (['bad_csrf','stale','foreign_subject'] as $case) {
        $r=$request($case);
        $check($r['status']===422 && count($r['state']['audit'])===0 && (int)$r['state']['rules'][0]['version']===1,'Unsafe or stale Dean requests cannot update eligibility: '.$case);
    }
    $r=$request('mixed');
    $check($r['status']===200 && strpos($r['body'],'Combined or unverified responses needing review')!==false && strpos($r['body'],'QA-CS101 - Introduction to Computing; QA-MATH101 - Discrete Mathematics')!==false,'The Dean sees mixed-subject teacher evaluations for review.');
    $r=$request('faculty_report');
    $check($r['status']===200 && strpos($r['body'],'No eligible evaluations')!==false && strpos($r['body'],'Retained original feedback &lt;script&gt;private&lt;/script&gt;')!==false,'All-excluded results retain comments and avoid a misleading zero score.');
    foreach (['chair','revoked'] as $case) { $r=$request($case); $check($r['status']===302 && strpos($r['body'],'id="subjectExclusionForm"')===false,'Only an active assigned Dean can open the management page: '.$case); }
    $r=$request('student_normal');
    $check($r['status']===200 && substr_count($r['body'],'faculty-evaluate-button"')===2 && substr_count($r['body'],'name="ratings[')===30,'All linked teachers remain evaluable with the full instrument.');
    $check(strpos($r['body'],'PRIVATE_DEAN_REASON')===false && strpos($r['body'],'excludeMixedResponses')===false,'Students receive no exclusion controls or private reasons.');
    $r=$request('student_save');
    $check($r['status']===302 && count($r['state']['responses'])===1 && $r['state']['responses'][0]['submission_status']==='draft','Students can save a normal draft for an excluded subject.');
    $r=$request('student_final');
    $check($r['status']===302 && count($r['state']['responses'])===2 && count(array_filter($r['state']['responses'],static function(array $row):bool { return $row['submission_status']==='submitted'; }))===2,'Students must still evaluate and can finalize all teachers, including excluded subjects.');
} finally { if (is_file($route)) unlink($route); }
$check($before===$snapshot(),'All fixture records rolled back; scheduling and workload data are unchanged.');
echo 'Evaluation subject exclusions HTTP: ',$checks,' checks passed. All fixture records rolled back.',PHP_EOL;
