<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$tables='tbl_student_faculty_evaluations,tbl_student_faculty_evaluation_answers,tbl_student_faculty_evaluation_subject_scope,tbl_student_evaluation_reset_history,tbl_student_management,tbl_student_management_enrolled_subjects,tbl_useraccount,tbl_useraccount_roles,tbl_college,tbl_program,tbl_supervisor_evaluations,tbl_supervisor_evaluation_assignments,tbl_college_evaluation_settings,tbl_academic_settings,tbl_class_schedule,tbl_faculty_workload_sched';
$before=$conn->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC);
$token=bin2hex(random_bytes(24));$route=dirname(__DIR__).'/tmp/evaluation-reset-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php define(\'SYNK_EVALUATION_RESET_QA\','.var_export($token,true).');require '.var_export(__DIR__.'/fixtures/student-evaluation-reset-request.php',true).';');
try {
    foreach(['entry'=>200,'page'=>200,'open'=>200,'success'=>303,'csrf'=>403,'scope'=>409,'expired'=>409,'failure'=>503,'unauthorized'=>403,'method'=>405] as $case=>$status){
        $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'case'=>$case]));
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false]);
        $raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));$actual=curl_getinfo($curl,CURLINFO_HTTP_CODE);$headerSize=curl_getinfo($curl,CURLINFO_HEADER_SIZE);curl_close($curl);
        $headers=substr($raw,0,$headerSize);$body=substr($raw,$headerSize);preg_match('/X-QA-Reset-State: ([^\r\n]+)/i',$headers,$match);$state=json_decode(rawurldecode($match[1]??''),true);
        $check($actual===$status,'Unexpected HTTP status for '.$case.': '.$actual.' '.substr(strip_tags($body),-600));
        $check($state&&$state['protected_unchanged'],'Protected records changed: '.$case);
        $check($state['remaining']===($case==='success'?0:4)&&$state['history']===($case==='success'?1:0),'Unexpected evaluation deletion or history: '.$case.' '.json_encode($state));
        $check(!preg_match('/Fatal error|Parse error|xdebug-error|Warning(?:<\/b>)?:/i',$body),'PHP errors in '.$case);
        if($case==='entry')$check(strpos($body,'id="evaluationResetReview"')===false&&strpos($body,'id="resetYear"')!==false,'The initial page must select a scope before showing deletion.');
        if($case==='page'){
            $dom=new DOMDocument();@$dom->loadHTML($body);$xpath=new DOMXPath($dom);
            foreach(['students'=>'2','submitted'=>'3','drafts'=>'1','records'=>'4'] as $name=>$count)$check($xpath->query('//*[@data-reset-count="'.$name.'"]')->item(0)->textContent===$count,'The page must display correct '.$name.' counts.');
            $check(strpos($body,'id="evaluationResetConfirm"')!==false&&strpos($body,'private comment')===false,'Only scope and counts reach the confirmation page.');
            if(($argv[1]??'')==='--render'&&isset($argv[2]))file_put_contents($argv[2],$body);
        }
        if($case==='open')$check(strpos($body,'Student evaluation collection is open.')!==false&&strpos($body,'id="evaluationResetConfirm"')===false,'Open collection must block deletion.');
        if($case==='success')$check(strpos($headers,'Location: student-evaluation-reset.php?')!==false,'Success redirects to a fresh scoped review.');
        if($case==='unauthorized')$check(strpos($body,'data-reset-count')===false,'Other roles must not see evaluation counts or reset controls.');
    }
}finally{if(is_file($route))unlink($route);}
$check($conn->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC)===$before,'HTTP fixture records must all roll back.');
echo 'Administrator evaluation reset HTTP: ',$checks,' checks passed. All fixtures rolled back.',PHP_EOL;
