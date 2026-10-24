<?php
// Real HTTP handlers and rollback-only fixtures; no live academic records are changed.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/scheduler_term_helper.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$snapshot=static function()use($conn):string{$rows=[];foreach(['tbl_academic_settings','tbl_scheduler_planning_terms','tbl_academic_years','tbl_college_faculty','tbl_room_college_access','tbl_faculty_workload_sched','tbl_faculty_term_designations']as$table)$rows[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($rows));};
$before=$snapshot();$official=synk_fetch_current_academic_term($conn);
$token=bin2hex(random_bytes(24));$route=__DIR__.'/../tmp/scheduler-working-qa-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php define(\'SYNK_SCHEDULER_LIST_QA\','.var_export($token,true).');require '.var_export(__DIR__.'/fixtures/scheduler-term-lists-request.php',true).';');
$request=static function(string $scenario)use($route,$token):array{
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>'planning_'.$scenario]));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>30]);$raw=curl_exec($curl);
    if($raw===false)throw new RuntimeException(curl_error($curl));$size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    $body=substr($raw,$size);return ['status'=>$status,'headers'=>substr($raw,0,$size),'body'=>$body,'data'=>json_decode($body,true)];
};
$header=static function(array $response,string $name){preg_match('/'.preg_quote($name,'/').': ([^\r\n]+)/i',$response['headers'],$match);return isset($match[1])?json_decode(rawurldecode($match[1]),true):null;};
try {
    foreach(['faculty_copy'=>2,'faculty_legacy_master_copy'=>1,'rooms_copy'=>1]as$scenario=>$count){
        $r=$request($scenario);$term=$header($r,'X-QA-Working-Term');$current=$header($r,'X-QA-Official-Term');
        $check($r['status']===200&&($r['data']['copied']??-1)===$count,'Lists copy into the future working term: '.$scenario.' '.substr($r['body'],0,600));
        $check($term['is_future']&&$term['can_write']&&$term['key']===$r['data']['target']['key'],'The copy target is the scheduler working term.');
        $check($current['ay_id']===$official['ay_id']&&$current['semester']===$official['semester'],'Advance planning preserves the official active term.');
        $check(strpos($r['headers'],'X-QA-Master-Unchanged: yes')!==false&&strpos($r['headers'],'X-QA-Previous-Unchanged: yes')!==false,'Copying preserves shared masters and previous-term records.');
    }
    $r=$request('designation_set');$check($r['status']===200&&($r['data']['status']??'')==='updated','Faculty appointments can be updated for the future term. '.substr($r['body'],0,600));
    $check(strpos($r['headers'],'X-QA-Previous-Unchanged: yes')!==false,'Future appointments preserve the current-semester appointments.');
    $r=$request('closed');$check($r['status']===200&&($r['data']['copied']??0)===2,'A legacy closed planning flag does not restrict scheduler term access. '.substr($r['body'],0,500));
    foreach(['mismatch'=>409,'stale_college'=>409,'invalid_year'=>422,'array_term'=>400]as$scenario=>$status){
        $r=$request($scenario);$check($r['status']===$status,'Unsafe working-term requests are rejected: '.$scenario.' HTTP '.$r['status'].' '.substr($r['body'],0,400));
        $check(strpos($r['headers'],'X-QA-Lists-Unchanged: yes')!==false,'Rejected requests do not modify faculty or room lists.');
    }
    foreach(['faculty_page','rooms_page']as$scenario){
        $r=$request($scenario);$term=$header($r,'X-QA-Working-Term');
        $check($r['status']===200&&!preg_match('/Fatal error|Parse error|Warning:|<b>Warning|xdebug-error/',$r['body']),'The real future-term page renders cleanly: '.$scenario.' '.substr(strip_tags($r['body']),0,400));
        $check(strpos($r['body'],'id="schedulerTermSettingsModal"')!==false&&strpos($r['body'],'Advance planning')!==false,'Future pages include the shared term settings and planning status.');
        $check(strpos($r['body'],'"scheduler_term_key":"'.$term['key'].'"')!==false,'Copy requests stay bound to the page academic term.');
    }
    $r=$request('legacy_copy');$check($r['status']===200&&($r['data']['copied']??0)===1,'Undated current faculty memberships can be copied into a separate future list. '.substr($r['body'],0,500));
    foreach(['admin_open'=>0,'admin_close'=>1]as$scenario=>$expected){
        $r=$request($scenario);$policy=$header($r,'X-QA-Planning-State');
        $check($r['status']===410&&(int)$policy[0]['is_open']===$expected,'Stale planning settings cannot change retired permission flags: '.$scenario.' '.substr($r['body'],0,500));
        $check($header($r,'X-QA-Working-Term')['can_write']&&$header($r,'X-QA-Official-Term')['semester']===$official['semester'],'Scheduler editing stays independent of the retired settings and official semester.');
    }
    foreach(['scheduler_open'=>403,'admin_invalid'=>410,'admin_array'=>410,'admin_bad_csrf'=>403]as$scenario=>$status){$r=$request($scenario);$check($r['status']===$status,'The retired planning endpoint preserves authentication and makes no changes: '.$scenario.' '.substr($r['body'],0,500));}
    foreach(['index','manage-sections','manage-offerings','manage-class-schedule','manage-college-faculty','manage-workload','manage-faculty-workload','manage-workload-simulations','manage-rooms','manage-class-program','manage-iso-class-program','manage-room-utilization','manage-report-signatories','manage-schedule-activity','campus-consolidated-workload','print-workload-simulations','subject-assignments','manage-faculty','manage-programs','manage-prospectus']as$page){
        $r=$request('render_'.$page);
        $check($r['status']===200&&!preg_match('/Fatal error|Parse error|Warning:|<b>Warning|xdebug-error/',$r['body']),'Scheduler page renders for the future working term: '.$page.' HTTP '.$r['status'].' '.substr(strip_tags($r['body']),0,700));
    }
    $r=$request('admin_page');$check($r['status']===200&&strpos($r['body'],'id="schedulerWorkingTermsInfo"')!==false&&strpos($r['body'],'id="schedulerPlanningTermsForm"')===false&&!preg_match('/Fatal error|Warning:|<b>Warning|Parse error/',$r['body']),'Academic Settings explains independent scheduler terms without obsolete permission controls. '.substr(strip_tags($r['body']),0,500));
    $_SESSION=['user_id'=>123,'role'=>'scheduler'];
    $current=synk_scheduler_working_term($conn);$check($current['is_current']&&$current['can_write'],'Scheduler sessions default to the official active term.');
    $_SESSION['scheduler_working_term']=['user_id'=>999,'ay_id'=>$official['ay_id'],'semester'=>2];
    $check(synk_scheduler_working_term($conn)['key']===$current['key'],'A working-term choice cannot leak between users.');
    $conn->begin_transaction();
    try {
        $pastYears=synk_scheduler_term_query($conn,'SELECT ay_id FROM tbl_academic_years WHERE ay<? ORDER BY ay DESC LIMIT 1','s',[$official['ay_label']]);
        if (!$pastYears) throw new RuntimeException('A previous academic year is required for working-term tests.');
        $pastId=(int)$pastYears[0]['ay_id'];$conn->query("UPDATE tbl_academic_years SET status='inactive' WHERE ay_id={$pastId}");
        foreach([1,2,3]as$sem){$past=synk_scheduler_term_from_ids($conn,$pastId,$sem);$check($past['can_write']&&!$past['is_current']&&!$past['is_future'],'Previous and inactive academic years remain editable in every semester.');}
        foreach([[$pastId,2],[$official['ay_id'],$official['semester']],[$official['ay_id'],3],[$pastId,1]]as$selection){
            $selected=synk_scheduler_select_term($conn,['scheduler_ay_id'=>$selection[0],'scheduler_semester'=>$selection[1]]);
            $check($selected['can_write']&&synk_scheduler_working_term($conn)['key']===$selected['key'],'Schedulers can switch back and forth between previous, current, and future terms.');
        }
        $check(synk_fetch_current_academic_term($conn)===$official,'Selecting a previous term never changes the official academic term.');
    } finally { $conn->rollback(); }
    foreach([[[],1],[1,[]],[0,1],[1,4],['1 OR 1=1',1]]as$input){
        try{synk_scheduler_term_from_ids($conn,$input[0],$input[1]);$check(false,'Invalid term accepted.');}catch(DomainException $error){$check(true,'Malformed academic-term input is rejected.');}
    }
    $_SESSION['role']='college_dean';
    try{synk_scheduler_select_term($conn,['scheduler_ay_id'=>$official['ay_id'],'scheduler_semester'=>$official['semester']]);$check(false,'Another role changed the scheduler workspace.');}catch(DomainException $error){$check($error->getCode()===403,'Scheduler term preferences require the scheduler role.');}
    require_once __DIR__.'/../backend/workload_student_guard_helper.php';
    try{synk_workload_remove_guarded($conn,1,'faculty',[],[]);$check(false,'An empty removal request was accepted.');}catch(DomainException $error){$check(true,'An empty removal scope cannot become a whole-college deletion.');}
    $check($snapshot()===$before,'All integration fixtures rolled back without changing academic records or settings.');
    echo 'Scheduler working term: '.$checks.' checks passed. Fixtures rolled back.'.PHP_EOL;
} finally { if(is_file($route))unlink($route); }
