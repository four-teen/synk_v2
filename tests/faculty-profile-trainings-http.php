<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/faculty_profile_training_helper.php';
require_once __DIR__.'/../backend/faculty_profile_research_helper.php';
require_once __DIR__.'/../backend/faculty_profile_degrees_helper.php';
synk_faculty_profile_training_ensure($conn);synk_faculty_profile_research_ensure($conn);synk_faculty_profile_degrees_ensure($conn);
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$root=dirname(__DIR__);$token=bin2hex(random_bytes(24));$route=$root.'/tmp/profile-training-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php define("SYNK_DEAN_PROFILE_QA",'.var_export($token,true).');define("SYNK_TRAINING_QA",true);define("SYNK_RESEARCH_QA",true);define("SYNK_DEGREE_QA",true);require '.var_export(__DIR__.'/fixtures/college-dean-profile-request.php',true).';');
$call=static function(string $scenario,?array $post=null,array $query=[])use($route,$token):array{
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(array_merge(['qa'=>$token,'scenario'=>$scenario],$query)));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false]);
    if($post!==null)curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post)]);
    $raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    return ['status'=>$status,'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];
};
$post=['profile_action'=>'training_save','csrf_token'=>$token,'training_id'=>'0','training_revision'=>'0','training_title'=>'Accurate Training QA',
    'agency'=>'Faculty Development Institute','date_from'=>'2026-11-14','date_to'=>'2026-11-14','venue'=>'Zoom','mode'=>'Online'];
try{
    $get=$call('professor_get',null,['tab'=>'trainings']);$check($get['status']===200,'The Training tab loads.');
    $check(!preg_match('/(Warning|Fatal error|Parse error|Deprecated):/', $get['body']),'The web PHP runtime renders without warnings or errors.');
    $dom=new DOMDocument();@$dom->loadHTML($get['body']);$xpath=new DOMXPath($dom);
    $check($xpath->query('//*[@id="faculty-tab-trainings" and @aria-selected="true"]')->length===1&&$xpath->query('//*[@id="faculty-panel-trainings" and not(@hidden)]')->length===1,'The requested training tab is active.');
    $check($xpath->query('//*[@id="faculty-training-form"]//input[not(@type="hidden")] | //*[@id="faculty-training-form"]//select')->length===6,'All six training fields are present.');
    $check($xpath->query('//*[@id="faculty-profile-form"]//form')->length===0&&$xpath->query('//*[@data-trainings]//thead//th')->length===7,'Training has a separate form and seven table columns.');
    $editData=json_decode($xpath->query('//*[@data-training-edit]')->item(0)->getAttribute('data-training-record'),true);
    $check(is_array($editData)&&$editData['date_from']==='2026-10-14'&&$editData['date_to']==='2026-10-15','Both training dates are available for editing.');
    $check(strpos($get['body'],'Inclusive teaching and learning')!==false&&strpos($get['body'],'Foreign private training')===false,'Only the signed-in owner’s training is listed.');
    $edit=$call('professor_get',null,['training'=>'record']);$check($edit['status']===200&&strpos($edit['body'],'Edit training record')!==false,'An existing training opens for editing.');
    $foreign=$call('professor_get',null,['training'=>'foreign']);$check($foreign['status']===404&&strpos($foreign['body'],'Foreign private training')===false,'Foreign training cannot be opened.');
    foreach(['professor_save','professor_account'] as $scenario){
        $r=$call($scenario,$post);
        $check($r['status']===303&&strpos($r['headers'],'Location: manage-profile.php?tab=trainings')!==false&&strpos($r['headers'],'X-QA-Trainings: 2')!==false,'Training saves independently for '.$scenario.': '.$r['status'].' '.$r['body']);
        foreach(['tbl-faculty-record-profiles','tbl-faculty-profiles','tbl-faculty-profile-additional-degrees','tbl-faculty-profile-researches'] as $table)$check(strpos($r['headers'],'X-QA-Table-'.$table.': unchanged')!==false,'Training preserves '.$table.'.');
    }
    $update=array_replace($post,['training_id'=>'record','training_revision'=>'1']);$r=$call('professor_save',$update);
    preg_match('/X-QA-Training-Record: ([^\r\n]*)/i',$r['headers'],$recordHeader);$saved=json_decode(rawurldecode($recordHeader[1]??''),true);
    $check($r['status']===303&&strpos($r['headers'],'X-QA-Trainings: 1')!==false&&(int)($saved['revision']??0)===2,'Editing updates the training version.');
    foreach(array_keys(synk_faculty_profile_training_fields()) as $key)$check(($saved[$key]??null)===$post[$key],$key.' persists through the web controller.');
    $delete=['profile_action'=>'training_delete','csrf_token'=>$token,'training_id'=>'record','training_revision'=>'1'];$r=$call('professor_save',$delete);
    $check($r['status']===303&&strpos($r['headers'],'X-QA-Trainings: 0')!==false&&strpos($r['headers'],'X-QA-Table-tbl-faculty-profile-researches: unchanged')!==false,'Removing training preserves research.');
    foreach(['missing_title','invalid_date','reversed_dates','invalid_mode','array','csrf','owner','stale','foreign','bad_id','bad_revision','bad_action','stale_delete','foreign_delete'] as $case){
        $bad=$update;$scenario='professor_save';$expected=422;
        if($case==='missing_title')$bad['training_title']='';
        if($case==='invalid_date')$bad['date_from']='2026-02-30';
        if($case==='reversed_dates')$bad['date_to']='2026-11-13';
        if($case==='invalid_mode')$bad['mode']='Hybrid';
        if($case==='array')$bad['agency']=['malformed'];
        if($case==='csrf'){$bad['csrf_token']='wrong';$expected=403;}
        if($case==='owner'){$scenario='professor_owner';$expected=409;}
        if($case==='stale'){$bad['training_revision']='2';$expected=409;}
        if($case==='foreign'){$bad['training_id']='foreign';$expected=409;}
        if($case==='bad_id')$bad['training_id']=['malformed'];
        if($case==='bad_revision')$bad['training_revision']='0';
        if($case==='bad_action')$bad['profile_action']='training_other';
        if($case==='stale_delete'){$bad=array_replace($delete,['training_revision'=>'2']);$expected=409;}
        if($case==='foreign_delete'){$bad=array_replace($delete,['training_id'=>'foreign']);$expected=409;}
        $r=$call($scenario,$bad);
        $check($r['status']===$expected&&strpos($r['headers'],'X-QA-Changed: no')!==false&&strpos($r['headers'],'X-QA-Trainings: 1')!==false,'Rejected '.$case.' preserves training, research and profiles: '.$r['status'].' '.$r['body']);
        if($case==='reversed_dates')$check(strpos($r['body'],'value="Accurate Training QA"')!==false&&strpos($r['body'],'id="training_date_to-error"')!==false,'Validation keeps the draft and identifies the invalid end date.');
    }
    $xss=array_replace($post,['training_title'=>'<script>alert("qa")</script>','mode'=>'bad']);$r=$call('professor_save',$xss);
    $check($r['status']===422&&strpos($r['body'],'<script>alert("qa")</script>')===false&&strpos($r['body'],'&lt;script&gt;')!==false,'Training text is escaped.');
    if(($argv[1]??'')==='--render'&&(empty($argv[2])||file_put_contents($argv[2],$get['body'])===false))throw new RuntimeException('Could not write training preview.');
}finally{if(is_file($route))unlink($route);}
echo 'Faculty profile training HTTP: ',$checks,' checks passed. All form writes rolled back.',PHP_EOL;
