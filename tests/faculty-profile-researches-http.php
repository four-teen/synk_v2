<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/faculty_profile_research_helper.php';
require_once __DIR__.'/../backend/faculty_profile_degrees_helper.php';
synk_faculty_profile_research_ensure($conn);synk_faculty_profile_degrees_ensure($conn);
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$root=dirname(__DIR__);$token=bin2hex(random_bytes(24));$route=$root.'/tmp/profile-research-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php define("SYNK_DEAN_PROFILE_QA",'.var_export($token,true).');define("SYNK_RESEARCH_QA",true);define("SYNK_DEGREE_QA",true);require '.var_export(__DIR__.'/fixtures/college-dean-profile-request.php',true).';');
$call=static function(string $scenario,?array $post=null,array $query=[])use($route,$token):array{
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(array_merge(['qa'=>$token,'scenario'=>$scenario],$query)));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false]);
    if($post!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    return ['status'=>$status,'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];
};
$post=['profile_action'=>'research_save','csrf_token'=>$token,'research_id'=>'0','research_revision'=>'0','research_title'=>'Accurate Research QA',
    'research_scope'=>'Local','funding_type'=>'Personal','conference_name'=>'Faculty Research Forum','conference_date'=>'2026-11-14',
    'publisher'=>'Sample Publisher','publisher_link'=>'https://publisher.example/article?x=1&y=2','scopus_link'=>'https://www.scopus.com/record/display.uri?eid=123'];
try{
    $get=$call('professor_get',null,['tab'=>'researches']);$check($get['status']===200,'The Researches tab loads.');
    $check(!preg_match('/(Warning|Fatal error|Parse error|Deprecated):/', $get['body']),'The local web PHP runtime renders without warnings or errors.');
    $dom=new DOMDocument();@$dom->loadHTML($get['body']);$xpath=new DOMXPath($dom);
    $check($xpath->query('//*[@id="faculty-tab-researches" and @aria-selected="true"]')->length===1&&$xpath->query('//*[@id="faculty-panel-researches" and not(@hidden)]')->length===1,'The requested tab is active in the server-rendered page.');
    $check($xpath->query('//*[@id="faculty-research-form"]//input[not(@type="hidden")] | //*[@id="faculty-research-form"]//select')->length===8,'All eight requested research fields are present.');
    $check($xpath->query('//*[@id="faculty-profile-form"]//form')->length===0&&$xpath->query('//*[@id="faculty-research-form"]')->length===1,'Research has its own form without nested profile forms.');
    $editData=json_decode($xpath->query('//*[@data-research-edit]')->item(0)->getAttribute('data-research-record'),true);
    $check(is_array($editData)&&$editData['conference_date']==='2026-10-15','The record edit data renders as valid JSON on the web PHP runtime.');
    $check(strpos($get['body'],'Community learning and sustainable agriculture')!==false&&strpos($get['body'],'Foreign private research')===false,'Only the signed-in owner’s research is listed.');
    $edit=$call('professor_get',null,['research'=>'record']);$check($edit['status']===200&&strpos($edit['body'],'Edit research record')!==false,'An existing research opens for editing.');
    $foreign=$call('professor_get',null,['research'=>'foreign']);$check($foreign['status']===404&&strpos($foreign['body'],'Foreign private research')===false,'A foreign record cannot be opened.');
    foreach(['professor_save','professor_account'] as $scenario){
        $r=$call($scenario,$post);
        $check($r['status']===303&&strpos($r['headers'],'Location: manage-profile.php?tab=researches')!==false&&strpos($r['headers'],'X-QA-Researches: 2')!==false,'Research saves independently for '.$scenario.': '.$r['status'].' '.$r['body']);
        $revisionHeader=$scenario==='professor_account'?'X-QA-Account-Revision: 1':'X-QA-Record-Revision: 1';
        $check(strpos($r['headers'],$revisionHeader)!==false&&strpos($r['headers'],'X-QA-Additional-Degrees: 1')!==false,'Research leaves the personal profile revision and additional degrees intact.');
    }
    $update=array_replace($post,['research_id'=>'record','research_revision'=>'1']);$r=$call('professor_save',$update);
    $check($r['status']===303&&strpos($r['headers'],'X-QA-Researches: 1')!==false&&strpos($r['headers'],'X-QA-Research-Title: Accurate%20Research%20QA')!==false&&strpos($r['headers'],'X-QA-Research-Revision: 2')!==false,'Editing changes the existing research and increments its version.');
    $delete=['profile_action'=>'research_delete','csrf_token'=>$token,'research_id'=>'record','research_revision'=>'1'];$r=$call('professor_save',$delete);
    $check($r['status']===303&&strpos($r['headers'],'X-QA-Researches: 0')!==false,'Removing a research deletes only the selected record.');
    foreach(['invalid_date','invalid_link','invalid_scope','scopus','csrf','owner','stale','foreign','bad_id','bad_action','stale_delete','foreign_delete'] as $case){
        $bad=$update;$scenario='professor_save';$expected=422;
        if($case==='invalid_date')$bad['conference_date']='2026-02-30';
        if($case==='invalid_link')$bad['publisher_link']='javascript:alert(1)';
        if($case==='invalid_scope')$bad['research_scope']='Other';
        if($case==='scopus')$bad['scopus_link']='https://scopus.com.evil.example/record';
        if($case==='csrf'){$bad['csrf_token']='wrong';$expected=403;}
        if($case==='owner'){$scenario='professor_owner';$expected=409;}
        if($case==='stale'){$bad['research_revision']='2';$expected=409;}
        if($case==='foreign'){$bad['research_id']='foreign';$expected=409;}
        if($case==='bad_id')$bad['research_id']=['malformed'];
        if($case==='bad_action')$bad['profile_action']='research_other';
        if($case==='stale_delete'){$bad=array_replace($delete,['research_revision'=>'2']);$expected=409;}
        if($case==='foreign_delete'){$bad=array_replace($delete,['research_id'=>'foreign']);$expected=409;}
        $r=$call($scenario,$bad);
        $check($r['status']===$expected&&strpos($r['headers'],'X-QA-Changed: no')!==false&&strpos($r['headers'],'X-QA-Researches: 1')!==false,'Rejected '.$case.' leaves research and profiles unchanged: '.$r['status'].' '.$r['body']);
        if($case==='invalid_link')$check(strpos($r['body'],'value="Accurate Research QA"')!==false&&strpos($r['body'],'id="research_publisher_link-error"')!==false,'Validation retains the draft with a field-specific error.');
    }
    $xss=array_replace($post,['research_title'=>'<script>alert("qa")</script>','publisher_link'=>'bad']);$r=$call('professor_save',$xss);
    $check($r['status']===422&&strpos($r['body'],'<script>alert("qa")</script>')===false&&strpos($r['body'],'&lt;script&gt;')!==false,'Research text is escaped when validation re-renders the draft.');
    if(($argv[1]??'')==='--render'){
        if(empty($argv[2])||file_put_contents($argv[2],$get['body'])===false)throw new RuntimeException('Could not write research preview.');
    }
}finally{if(is_file($route))unlink($route);}
echo 'Faculty profile research HTTP: ',$checks,' checks passed. All form writes rolled back.',PHP_EOL;
