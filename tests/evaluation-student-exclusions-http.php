<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/db.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void { if (!$ok) throw new RuntimeException($message); $checks++; };
$snapshot=static function()use($conn):string {
    $data=[];
    foreach (['tbl_college_evaluation_student_exclusions','tbl_college_evaluation_student_exclusion_audit','tbl_college_evaluation_subject_exclusions','tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_class_schedule','tbl_faculty_workload_sched','tbl_academic_settings'] as $table) $data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    return hash('sha256',json_encode($data));
};
$before=$snapshot(); $token=bin2hex(random_bytes(24)); $route=__DIR__.'/../tmp/student-exclusion-qa-'.bin2hex(random_bytes(8)).'.php';
if (file_put_contents($route,'<?php define(\'SYNK_STUDENT_PORTAL_QA\','.var_export($token,true).'); require '.var_export(__DIR__.'/fixtures/student-portal-request.php',true).';')===false) throw new RuntimeException('Unable to create the protected localhost fixture.');
$request=static function(string $case,array $query=[])use($route,$token,$check):array {
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>'student_exclusion_'.$case]+$query));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false]);
    $raw=curl_exec($curl); if ($raw===false) throw new RuntimeException(curl_error($curl));
    $size=curl_getinfo($curl,CURLINFO_HEADER_SIZE); $status=curl_getinfo($curl,CURLINFO_HTTP_CODE); curl_close($curl);
    $headers=substr($raw,0,$size); $body=substr($raw,$size); $state=[];
    if (preg_match('/X-QA-Student-Exclusions: ([^\r\n]+)/i',$headers,$match)) $state=json_decode(rawurldecode($match[1]),true);
    $check(!preg_match('/Fatal error|Parse error|xdebug-error|Warning(?:<\/b>)?:/i',$body),'No server errors: '.$case.' '.substr(strip_tags($body),-1200));
    $check(isset($state['rules'],$state['audit'],$state['responses']),'The rollback fixture completed: '.$case.' HTTP '.$status.' '.substr(strip_tags($body),-500));
    return ['status'=>$status,'headers'=>$headers,'body'=>$body,'state'=>$state];
};
try {
    $r=$request('get');
    $check($r['status']===200 && strpos($r['body'],'id="studentExclusionForm"')!==false && strpos($r['body'],'name="csrf_token"')!==false,'The assigned Dean can open the scoped student form.');
    $check(strpos($r['body'],'PRIVATE_STUDENT_REASON &lt;script&gt;private&lt;/script&gt;')!==false && strpos($r['body'],'PRIVATE_STUDENT_REASON <script>')===false,'Private reasons are HTML escaped.');
    $check(strpos($r['headers'],'X-QA-Protected-Unchanged: yes')!==false,'Viewing student rules preserves original responses and academic records.');
    $check(strpos($r['body'],'Preserved original student feedback')===false && strpos($r['body'],'name="ratings[')===false,'The management list does not reveal named student ratings or feedback.');
    $r=$request('list',['search'=>'Alex','rule_filter'=>'excluded']);
    $check(strpos($r['body'],'1 of 2 students')!==false && strpos($r['body'],'value="Alex"')!==false,'Search and student-rule filters apply within the college and term.');
    $r=$request('list',['search'=>'<script>bad()</script>']);
    $check(strpos($r['body'],'value="&lt;script&gt;bad()&lt;/script&gt;"')!==false && strpos($r['body'],'<script>bad()</script>')===false,'Search text cannot inject HTML.');
    foreach (['save','restore'] as $case) {
        $r=$request($case);
        $check($r['status']===302 && count($r['state']['audit'])===1 && (int)$r['state']['rules'][0]['excluded']===($case==='save'?1:0),'Student eligibility saves and restores with audit: '.$case);
        $check(strpos($r['headers'],'X-QA-Protected-Unchanged: yes')!==false,'Student rule writes preserve responses, profiles and academic records.');
        $check(count(array_filter($r['state']['responses'],static fn(array $row):bool=>$row['score_state']==='excluded'))===($case==='save'?2:0),'Every teacher response follows the saved student rule: '.$case);
    }
    foreach (['bad_csrf','stale','foreign_student','empty_reason','invalid_action','foreign_college','foreign_term'] as $case) {
        $r=$request($case);
        $check($r['status']===422 && count($r['state']['audit'])===0 && (int)$r['state']['rules'][0]['version']===1,'Invalid or out-of-scope requests cannot update eligibility: '.$case);
    }
    $r=$request('faculty_report');
    $check($r['status']===200 && strpos($r['body'],'No eligible evaluations')!==false && strpos($r['body'],'Preserved original student feedback')!==false && strpos($r['body'],'Manage Student Exclusions')!==false,'Faculty results show no eligible score, preserve comments and link to student management.');
    foreach (['chair','revoked'] as $case) { $r=$request($case); $check($r['status']===302 && strpos($r['body'],'id="studentExclusionForm"')===false,'Only an active assigned Dean can open student management: '.$case); }
    $r=$request('student_normal');
    $check($r['status']===200 && substr_count($r['body'],'faculty-evaluate-button"')===2 && substr_count($r['body'],'name="ratings[')===30,'An excluded student still sees every teacher and all assessment questions.');
    $check(strpos($r['body'],'PRIVATE_STUDENT_REASON')===false && strpos($r['body'],'id="studentExclusionForm"')===false,'Students cannot see exclusion controls or private reasons.');
    $r=$request('student_save');
    $check($r['status']===302 && count($r['state']['responses'])===1 && $r['state']['responses'][0]['submission_status']==='draft','An excluded student can save a normal draft.');
    $r=$request('student_final');
    $check($r['status']===302 && count($r['state']['responses'])===2 && count(array_filter($r['state']['responses'],static fn(array $row):bool=>$row['submission_status']==='submitted' && $row['score_state']==='excluded'))===2,'An excluded student can finalize all teachers and later submissions are excluded from scores.');
} finally { if (is_file($route)) unlink($route); }
$check($before===$snapshot(),'Every HTTP fixture record rolled back, including student rules and original academic records.');
echo 'Evaluation student exclusions HTTP: ',$checks,' checks passed. All fixture records rolled back.',PHP_EOL;
