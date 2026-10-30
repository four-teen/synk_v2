<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../backend/db.php';
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$snapshot=static function()use($conn):string{$state=[];foreach(['tbl_academic_settings','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_college_evaluation_settings','tbl_class_schedule','tbl_faculty_workload_sched']as$table)$state[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($state));};
$before=$snapshot();$token=bin2hex(random_bytes(24));$route=__DIR__.'/../tmp/student-evaluation-prompt-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php define(\'SYNK_STUDENT_PORTAL_QA\','.var_export($token,true).');require '.var_export(__DIR__.'/fixtures/student-portal-request.php',true).';');
$request=static function(string $case)use($token,$route):array{
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>'evaluation_access_prompt_'.$case]));curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false]);
    $raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);$body=substr($raw,$size);$headers=substr($raw,0,$size);$dom=new DOMDocument();@$dom->loadHTML($body?:'<html></html>');$xpath=new DOMXPath($dom);$config=$xpath->query('//*[@id="studentEvaluationPromptConfig"]')->item(0);return ['status'=>$status,'body'=>$body,'headers'=>$headers,'xpath'=>$xpath,'config'=>$config?json_decode($config->textContent,true):null,'data'=>json_decode($body,true)];
};
try{
    foreach(['open'=>[true,2,0,2],'drafts'=>[true,2,2,0],'complete'=>[false,0,0,0],'partial'=>[true,1,1,0],'revised'=>[true,2,0,2],'incomplete_answers'=>[true,2,1,1],'no_comment'=>[true,2,1,1],'closed'=>[false,0,0,0],'other_college'=>[false,0,0,0],'inactive'=>[false,0,0,0],'unlinked'=>[false,0,0,0]]as$case=>$expected){
        $r=$request($case);$state=$r['config']['initial']??[];
        $check($r['status']===200&&isset($state['required']),$case.' prompt state failed: '.substr(strip_tags($r['body']),0,500));
        $check([$state['required'],$state['remaining'],$state['drafts'],$state['missing']]===$expected,$case.' prompt progress is incorrect: '.json_encode($state));
        $check(strpos($r['headers'],'X-QA-Protected-Unchanged: yes')!==false,'Reading prompt modified evaluations, academic settings or schedules.');
        if($expected[0]){$url=$state['colleges'][0]['url'];$check(strpos($url,'class-program.php?')===0&&strpos($url,'evaluation_college_id=')!==false&&strpos($url,'#facultyEvaluationOverview')!==false,'Start link is outside the authorized faculty selection.');}
    }
    $r=$request('faculty_page');$check($r['status']===200&&$r['xpath']->query('//*[@data-faculty-evaluation-card]')->length===2,'Start destination does not contain the assigned faculty list.');
    $check($r['xpath']->query('//*[@id="studentEvaluationPrompt" and @data-bs-backdrop="static" and @data-bs-keyboard="false"]')->length===1 && $r['xpath']->query('//*[@id="studentEvaluationPrompt"]//*[@data-bs-dismiss]')->length===0,'Required evaluation guidance can be dismissed without starting.');
    $r=$request('incomplete_submit');$check($r['status']===422&&strpos($r['body'],'save draft evaluations for all linked faculty')!==false,'A draft with missing answers unlocked final submission.');
    $r=$request('partial');$review=$r['xpath']->query('//button[@data-bs-target="#finalEvaluationReviewModal"]')->item(0);$check($review&&!$review->hasAttribute('disabled'),'An earlier submitted faculty blocks final review of remaining drafts.');
    $r=$request('no_comment_page');$review=$r['xpath']->query('//button[@data-bs-target="#finalEvaluationReviewModal"]')->item(0);
    $check($review&&$review->hasAttribute('disabled')&&strpos($r['body'],'Add your required comment')!==false,'A blank-comment draft unlocks final review or lacks an editing prompt.');
    $r=$request('no_comment_submit');$check($r['status']===422&&strpos($r['body'],'required comments')!==false,'Final submission accepts a draft without its required comment.');
    $r=$request('review');
    $check($r['status']===200&&$r['xpath']->query('//*[@id="finalEvaluationReviewModal"]//*[contains(@class,"faculty-review-item")]//*[contains(@class,"faculty-review-name")]')->length===2
        &&$r['xpath']->query('//*[@id="finalEvaluationReviewModal"]//*[contains(@class,"faculty-review-comment")]/p')->length===2
        &&$r['xpath']->query('//*[@id="finalEvaluationReviewModal"]//*[contains(@class,"faculty-review-subject")]')->length===0,'Final review does not show only faculty summaries without subjects.');
    $ready=$r['xpath']->query('//button[contains(@class,"faculty-final-review-button") and contains(@class,"is-ready")]')->item(0);
    $check($ready&&!$ready->hasAttribute('disabled')&&$r['xpath']->query('//*[@id="faculty-final-review-note"]')->length===1,'Ready drafts do not emphasize the final review action and explain final submission.');
    $check($r['xpath']->query('//textarea[@name="comment_text" and @required]')->length===2,'Every editable faculty form must require its own comment.');
    $r=$request('partial_submit');$check($r['status']===302&&strpos($r['headers'],'X-QA-Submitted-Preserved: yes')!==false,'Submitting missing evaluations changed a previously submitted response.');
    preg_match('/X-QA-Evaluation-State: ([^\r\n]+)/i',$r['headers'],$matches);$saved=json_decode(rawurldecode($matches[1]??''),true);$check(count(array_filter($saved['responses']??[],static function($row){return $row['submission_status']==='submitted';}))===2,'Remaining faculty drafts were not submitted.');
    $r=$request('partial_no_comment_submit');$check($r['status']===302&&strpos($r['headers'],'X-QA-Submitted-Preserved: yes')!==false,'A previously submitted response without a comment is altered or blocks new complete drafts.');
    foreach(['endpoint'=>200,'endpoint_csrf'=>403,'endpoint_get'=>405,'endpoint_staff'=>401,'endpoint_anonymous'=>401,'endpoint_identity'=>401,'endpoint_idle'=>401]as$case=>$code){$r=$request($case);$check($r['status']===$code,$case.' endpoint returned '.$r['status'].' instead of '.$code.': '.substr($r['body'],0,300));if($case==='endpoint')$check(($r['data']['evaluation']['remaining']??0)===2,'Prompt status trusts a forged student ID.');}
}finally{if(is_file($route))unlink($route);}
$check($snapshot()===$before,'Prompt fixtures left shared records changed.');
echo 'Student evaluation prompt: ',$checks," checks passed; all fixtures rolled back.\n";
