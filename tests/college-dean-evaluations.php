<?php
// php tests/college-dean-evaluations.php -- actual page, scoped and rollback-only fixtures.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/db.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$snapshot=static function()use($conn):string{
    $rows=[];
    foreach(['tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_faculty','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_useraccount','tbl_useraccount_roles','tbl_college','tbl_program','tbl_college_faculty_evaluation_clear_history']as$table)$rows[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    return hash('sha256',json_encode($rows));
};
$before=$snapshot();$token=bin2hex(random_bytes(24));$route=__DIR__.'/../tmp/dean-evaluation-qa-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php define(\'SYNK_STUDENT_PORTAL_QA\','.var_export($token,true).'); require '.var_export(__DIR__.'/fixtures/student-portal-request.php',true).';');
$request=static function(string $scenario)use($route,$token,$check):array{
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>'dean_faculty_evaluations_'.$scenario]));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false]);
    $raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    $headers=substr($raw,0,$size);$body=substr($raw,$size);
    $check(strpos($headers,'X-QA-Protected-Unchanged: yes')!==false,'Viewing evaluations must not change responses, student records or workloads: '.$scenario);
    $check(!preg_match('/Fatal error|Parse error|xdebug-error/i',$body),'The evaluation page must render without PHP errors: '.$scenario.' '.substr(strip_tags($body),-1800));
    return ['status'=>$status,'headers'=>$headers,'body'=>$body];
};
try{
    $r=$request('empty');
    $check($r['status']===200&&strpos($r['body'],'id="deanEvaluationEmptyNotice"')!==false,'An empty evaluation database displays a clear initial state. HTTP '.$r['status'].': '.substr(strip_tags($r['body']),-900));
    $check(strpos($r['body'],'data-show-evaluation')!==false&&strpos($r['body'],'name="dean_ay_id"')!==false&&strpos($r['body'],'name="dean_semester"')!==false,'Assigned/imported faculty remain available with year and semester controls when no responses exist.');
    $r=$request('detail');
    $check($r['status']===200&&substr_count($r['body'],'data-evaluation-instrument=')===2,'Current and previous instruments appear as separate reports for the same faculty.');
    $check(strpos($r['body'],'Management of Teaching and Learning')!==false&&strpos($r['body'],'Knowledge of Subject Matter')!==false,'Each report displays its original category names.');
    $check(strpos($r['body'],'Current SET feedback &lt;script&gt;bad()&lt;/script&gt;')!==false&&strpos($r['body'],'Previous instrument feedback')!==false,'Submitted comments appear with HTML safely escaped.');
    $check(strpos($r['body'],'PRIVATE_DRAFT_FEEDBACK')===false&&strpos($r['body'],'FOREIGN_SUBMITTED_FEEDBACK')===false&&strpos($r['body'],'FOREIGN_EVALUATION_FACULTY')===false,'Draft comments and other college evaluations cannot leak.');
    $check(preg_match('/data-evaluation-summary="submitted_count">2<\/h4>/',$r['body'])===1&&preg_match('/data-evaluation-summary="draft_count">1<\/h4>/',$r['body'])===1,'The selected-term totals include only this college and separate submitted responses from drafts.');
    $check(strpos($r['body'],'View by instrument')!==false&&strpos($r['body'],'80.00% normalized score')!==false&&strpos($r['body'],'60.00% normalized score')!==false,'Scores from different instruments remain separate rather than being averaged together.');
    $check(strpos($r['body'],'>Submitted Count</th>')!==false && strpos($r['body'],'>Action</th>')!==false && strpos($r['body'],'>Subjects</th>')!==false,'Faculty list includes Subjects, Submitted Count and Action columns.');
    $check(substr_count($r['body'],'data-clear-evaluation data-bs-toggle=')===2 && strpos($r['body'],'clear_all')===false,'Clearing is offered individually for each faculty with no bulk action.');
    $check(preg_match('/data-label="Subjects"><span class="dean-evaluation-subject-count">2<\/span>/',$r['body'])===1 && strpos($r['body'],'aria-label="2 submitted, 0 not submitted"')!==false && strpos($r['body'],'aria-label="0 submitted, 1 not submitted"')!==false,'Distinct subject and student submission counts match scoped enrollments and drafts.');
    $r=$request('legacy');
    $check($r['status']===200&&substr_count($r['body'],'data-evaluation-instrument=')===1&&strpos($r['body'],'data-evaluation-instrument="legacy-20"')!==false,'The previous-instrument filter selects only historical questionnaire responses.');
    $r=$request('historical');
    $check($r['status']===200&&strpos($r['body'],'Historical submitted feedback')!==false&&strpos($r['body'],'Current SET feedback')===false,'The page follows the Dean portal selected historical term.');
    $check(strpos($r['body'],'100.00% normalized score')!==false,'An inactive historical import still proves college membership for a previous evaluation.');
    $r=$request('foreign');$check($r['status']===404&&strpos($r['body'],'FOREIGN_SUBMITTED_FEEDBACK')===false,'Guessing a foreign faculty ID cannot expose its evaluation.');
    $r=$request('revoked');$check($r['status']===302&&strpos($r['headers'],'account_not_allowed')!==false,'Revoked Dean access is rejected on the page itself.');
    $r=$request('post');$check($r['status']===405,'Viewing results cannot invoke a clearing or reset action.');
    $r=$request('bad_filter');$check($r['status']===422,'Array filter values are rejected.');
}finally{if(is_file($route))unlink($route);}
$check($before===$snapshot(),'All fixture records rolled back; the original evaluation database is unchanged.');
echo 'College Dean evaluations: ',$checks,' checks passed. All fixture records rolled back.',PHP_EOL;
