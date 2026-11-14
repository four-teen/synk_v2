<?php
// Actual professor controller, isolated loopback fixtures and rollback-only writes.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/faculty_profile_degrees_helper.php';
synk_faculty_profile_degrees_ensure($conn);
require_once __DIR__.'/../backend/faculty_profile_research_helper.php';
synk_faculty_profile_research_ensure($conn);
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$root=dirname(__DIR__);$token=bin2hex(random_bytes(24));$route=$root.'/tmp/profile-degrees-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php define("SYNK_DEAN_PROFILE_QA",'.var_export($token,true).');define("SYNK_DEGREE_QA",true);require '.var_export(__DIR__.'/fixtures/college-dean-profile-request.php',true).';');
$call=static function(string $scenario,?array $post=null)use($route,$token):array{
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>$scenario]));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false]);
    if($post!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    return ['status'=>$status,'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];
};
$post=['full_name'=>'Additional Degree QA','revision'=>'1','csrf_token'=>$token,'additional_degrees_present'=>'1',
    'bachelor_status'=>'Completed','bachelor_degree'=>'BS Computer Science',
    'doctoral_status'=>'Completed','doctoral_degree'=>'PhD Computer Science',
    'additional_degrees'=>[['level'=>'bachelor','status'=>'Completed','degree'=>'BS Nursing','institution'=>'QA University','address'=>'QA City','specialization'=>'Nursing'],['level'=>'doctoral','status'=>'Completed','degree'=>'Doctor of Education','institution'=>'QA University','address'=>'QA City','specialization'=>'Education']]];
try{
    $get=$call('professor_get');$check($get['status']===200,'Professor profile loads.');
    $check(strpos($get['body'],'data-profile-tab="profile"')!==false&&strpos($get['body'],'data-profile-tab="researches"')!==false&&strpos($get['body'],'data-profile-tab="trainings"')!==false,'Profile, Researches and Trainings tabs render.');
    $check(substr_count($get['body'],'data-add-degree=')===3&&strpos($get['body'],'Previously saved second degree')!==false,'Each degree level has its own Add Degree button.');
    $dom=new DOMDocument();@$dom->loadHTML($get['body']);$xpath=new DOMXPath($dom);
    $check($xpath->query('//*[@data-degree-list="bachelor"]//*[@data-additional-degree]')->length===1&&$xpath->query('//*[@data-degree-list="doctoral"]//*[@data-additional-degree]')->length===0,'Saved degrees render under their corresponding degree level.');
    $check($xpath->query('//*[@data-additional-degree]//select[contains(@name,"[level]")]')->length===0,'Additional degrees inherit their level without another level selector.');
    $check($xpath->query('//*[@id="faculty-panel-researches"]//*[@id="faculty-research-form"]')->length===1,'The Researches panel contains its independent record editor.');
    $check(trim($xpath->query('//*[@id="faculty-panel-trainings"]')->item(0)->textContent)==='','The Trainings panel stays blank.');
    foreach(['professor_save','professor_account'] as $scenario){$r=$call($scenario,$post);$check($r['status']===303&&strpos($r['headers'],'X-QA-Additional-Degrees: 2')!==false&&strpos($r['headers'],'X-QA-Additional-Degree-Levels: bachelor,doctoral')!==false,'A second Bachelor and second Doctoral degree save for '.$scenario.': '.$r['status'].' '.$r['body']);}
    $sparse=$post;$sparse['additional_degrees']=[3=>$post['additional_degrees'][0],8=>$post['additional_degrees'][1]];
    $r=$call('professor_save',$sparse);
    $check($r['status']===303&&strpos($r['headers'],'X-QA-Additional-Degree-Levels: bachelor,doctoral')!==false,'Removing and adding rows with gaps in their indices preserves their degree levels.');
    $old=$post;unset($old['additional_degrees_present'],$old['additional_degrees']);$r=$call('professor_save',$old);
    $check($r['status']===303&&strpos($r['headers'],'X-QA-Additional-Degrees: 1')!==false,'Existing form posts preserve additional degrees.');
    $clear=$post;unset($clear['additional_degrees']);$r=$call('professor_save',$clear);
    $check($r['status']===303&&strpos($r['headers'],'X-QA-Additional-Degrees: 0')!==false,'Explicitly removing all additional degrees saves.');
    foreach(['invalid','stale','csrf','owner'] as $case){
        $bad=$post;$scenario='professor_save';$expected=422;
        if($case==='invalid')$bad['additional_degrees'][1]['degree']='';
        if($case==='stale'){$bad['revision']='0';$expected=409;}
        if($case==='csrf'){$bad['csrf_token']='wrong';$expected=403;}
        if($case==='owner'){$scenario='professor_owner';$expected=409;}
        $r=$call($scenario,$bad);$check($r['status']===$expected&&strpos($r['headers'],'X-QA-Changed: no')!==false&&strpos($r['headers'],'X-QA-Additional-Degrees: 1')!==false,'Rejected '.$case.' posts preserve every saved field: '.$r['status']);
        if($case==='invalid'){
            $dom=new DOMDocument();@$dom->loadHTML($r['body']);$xpath=new DOMXPath($dom);
            $check($xpath->query('//*[@data-degree-list="bachelor"]//*[@id="additional_degree_0_degree"]//option[@selected and @value="BS Nursing"] | //*[@data-degree-list="bachelor"]//input[@id="additional_degree_0_degree" and @value="BS Nursing"]')->length===1&&$xpath->query('//*[@data-degree-list="doctoral"]//*[@id="additional_degree_1_degree-error"]')->length===1,'Validation preserves the Bachelor entry and identifies the invalid degree inside the Doctoral section.');
        }
    }
    if(($argv[1]??'')==='--render'){
        if(empty($argv[2])||file_put_contents($argv[2],$get['body'])===false)throw new RuntimeException('Could not write the degree editor preview.');
    }
}finally{if(is_file($route))unlink($route);}
echo 'Faculty profile degree HTTP: ',$checks,' checks passed. All form writes rolled back.',PHP_EOL;
