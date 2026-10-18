<?php
// php tests/program-chair-curriculum.php [--http]
require_once __DIR__.'/../backend/db.php';
$root=dirname(__DIR__); $checks=0; $http=in_array('--http',$argv,true);
$tables='tbl_useraccount,tbl_useraccount_roles,tbl_program,tbl_subject_masterlist,tbl_prospectus_header,tbl_prospectus_year_sem,tbl_prospectus_subjects,tbl_section_curriculum,tbl_prospectus_offering,tbl_class_schedule,tbl_faculty_workload_sched';
$snapshot=static function() use($conn,$tables):array{return $conn->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC);};
$before=$snapshot();
$route=$root.'/tmp/curriculum-qa-'.bin2hex(random_bytes(8)).'.php'; $token=bin2hex(random_bytes(24));
if($http){
    $source="<?php\nif(!in_array(\$_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)||!is_string(\$_GET['qa']??null)||!hash_equals(".var_export($token,true).",\$_GET['qa'])){http_response_code(404);exit;}\ndefine('SYNK_CHAIR_CURRICULUM_QA_HTTP',true);\nrequire ".var_export($root.'/tests/fixtures/program-chair-curriculum-request.php',true).";\n";
    file_put_contents($route,$source);
    register_shutdown_function(static function()use($route):void{if(is_file($route))unlink($route);});
}
$check=static function(bool $condition,string $message)use(&$checks):void{if(!$condition)throw new RuntimeException($message);$checks++;};
$request=static function(string $scenario)use($root,$http,$route,$token):array{
    if($http)$body=file_get_contents('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>$scenario]));
    else{
        $process=proc_open([PHP_BINARY,$root.'/tests/fixtures/program-chair-curriculum-request.php',$scenario],[1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
        $body=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($process)!==0||$errors!=='')throw new RuntimeException($scenario.': '.$errors.substr($body,0,1000));
    }
    $result=json_decode($body,true);
    if(!is_array($result)||isset($result['fixture_error']))throw new RuntimeException($scenario.': '.substr($body,0,1000));
    return $result;
};
foreach(['page_builder','page_browser','page_view']as$scenario){
    $r=$request($scenario);$body=$r['response'];
    $check($r['http_status']===200&&is_string($body)&&!preg_match('/Fatal error|Parse error|<b>Warning<\/b>|xdebug-error/i',$body),'Curriculum page failed: '.$scenario);
    $check(strpos($body,'Program Chair Portal')!==false&&strpos($body,'Curriculum Browser')!==false,'Chair navigation missing: '.$scenario);
    $check(stripos($body,$r['tag'].' Foreign Program')===false&&strpos($body,'query_prospectus.php')===false,'A page exposed foreign programs or used the administrator write route: '.$scenario);
    if($scenario!=='page_view')$check(stripos($body,$r['tag'].' Own Program')!==false&&strpos($body,'query_program_chair_prospectus')!==false,'Scoped programs or endpoints missing: '.$scenario);
    if($scenario==='page_browser') {
        $check(strpos($body,'id="filterCampus"')===false&&strpos($body,'id="filterCollege"')===false&&strpos($body,'id="filterProgram"')!==false,'Chair browser exposed administrator campus/college selectors.');
    }
    preg_match_all('/<script(?:\s[^>]*)?>(.*?)<\/script>/s',$body,$scripts);
    $script=$root.'/tmp/chair-curriculum-rendered.js';file_put_contents($script,implode("\n",$scripts[1]));
    $output=[];exec('node --check '.escapeshellarg($script).' 2>&1',$output,$status);unlink($script);
    $check($status===0,'Rendered curriculum JavaScript failed: '.implode("\n",$output));
}
$r=$request('page_admin_browser');
$check($r['http_status']===200&&strpos($r['response'],'id="filterCampus"')!==false&&strpos($r['response'],'id="filterCollege"')!==false&&strpos($r['response'],'Program / Major')!==false,'Administrator campus/college browser controls are missing.');
$check($r['session_college']===$r['other_college']&&stripos($r['response'],$r['tag'].' Foreign Program')!==false,'Administrator browser changed session scope or hid another college.');
$r=$request('page_admin_builder');
$check($r['http_status']===200&&strpos($r['response'],'id="btnSaveHeader"')!==false&&strpos($r['response'],'id="btnLoadHeader"')!==false&&strpos($r['response'],'chairProspectusConfig')===false,'Chair builder redesign changed administrator controls.');
$r=$request('page_builder_selected');
$check($r['http_status']===200&&strpos($r['response'],'"initialProspectusId":'.$r['header'])!==false,'Opening a prospectus from the browser lost its selected version.');
$r=$request('builder_program_versions');
$check($r['unchanged']&&$r['http_status']===200&&count($r['response'])===2&&array_sum(array_column($r['response'],'subject_count'))===2,'Program version list hid an empty version or mixed subjects across versions.');
$check(count(array_unique(array_column($r['response'],'program_id')))===1&&(int)$r['response'][0]['program_id']===$r['own_program'],'Program version list included another program.');
$r=$request('duplicate_header');
$check($r['unchanged']&&strpos($r['response'],'EXISTING|'.$r['header'].'|')===0,'Creating the same program/CMO/effectivity created a duplicate.');
$r=$request('load_browser_filters');
$check($r['unchanged']&&$r['http_status']===200&&count($r['response']['colleges'])===1&&(int)$r['response']['colleges'][0]['college_id']===$r['college_id'],'Chair browser filters escaped its assigned college.');
$fixturePrograms=array_values(array_filter($r['response']['programs'],static function($p)use($r){return in_array((int)$p['program_id'],[$r['own_program'],$r['target_program']],true);}));
$check(count($fixturePrograms)===2&&array_sum(array_column($fixturePrograms,'curriculum_count'))===1,'Program filters lost zero-curriculum programs or counted subjects instead of versions.');
foreach(['load_browser_prospectuses','browser_selected_program','browser_empty_curriculum','browser_zero_program','admin_load_browser_prospectuses','browser_admin_foreign_college']as$scenario){
    $r=$request($scenario);$rows=$r['response'];
    $check($r['http_status']===200&&is_array($rows)&&$r['unchanged']&&$r['foreign_unchanged'],'Read-only college browsing failed: '.$scenario.' '.json_encode($rows));
    $ids=array_map('intval',array_column($rows,'program_id'));
    if($scenario==='browser_admin_foreign_college')$check(in_array($r['foreign_program'],$ids,true)&&!in_array($r['own_program'],$ids,true),'Administrator college filtering returned the wrong college.');
    elseif($scenario==='browser_zero_program')$check(count($rows)===0,'A program without curricula returned records.');
    else {
        $check(in_array($r['own_program'],$ids,true)&&!in_array($r['foreign_program'],$ids,true),'College view mixed programs across colleges.');
        $own=array_values(array_filter($rows,static function($row)use($r){return (int)$row['program_id']===$r['own_program'];}))[0];
        $check((int)$own['yearsem_count']===1&&(int)$own['subject_count']===2&&(int)$own['total_units']===7&&!empty($own['program_name']),'College view totals or program identity are incorrect.');
        if($scenario==='browser_empty_curriculum'){
            $empty=array_values(array_filter($rows,static function($row)use($r){return (int)$row['program_id']===$r['target_program'];}));
            $check(count($empty)===1&&(int)$empty[0]['subject_count']===0&&(int)$empty[0]['total_units']===0,'An empty created curriculum was hidden.');
        }
    }
    $adminRequest=strpos($scenario,'admin_')===0||strpos($scenario,'browser_admin_')===0;
    $check($r['session_college']===($adminRequest?$r['other_college']:$r['college_id']),'Browsing changed the account college context.');
}
foreach(['browser_foreign_college'=>403,'browser_foreign_program'=>403,'browser_wrong_campus'=>403,'browser_array_program'=>400,'browser_admin_wrong_campus'=>422,'browser_admin_foreign_program'=>403]as$scenario=>$status){
    $r=$request($scenario);$check($r['http_status']===$status&&$r['unchanged']&&$r['foreign_unchanged'],'Browser accepted an invalid scope: '.$scenario.' '.json_encode($r['response']));
}
$r=$request('admin_load_browser_filters');
$check(in_array($r['foreign_program'],array_map('intval',array_column($r['response']['programs'],'program_id')),true),'Administrator browser filters were restricted to a single college.');
foreach(['load_programs','load_prospectus_list','list_headers','load_header','load_year_sem','load_subject_for_edit','load_prospectus_by_program','get_all_subjects','view']as$scenario){
    $r=$request($scenario);$check($r['http_status']===200&&$r['unchanged']&&$r['session_college']===$r['college_id'],'Reading curriculum failed or changed data: '.$scenario);
    if($scenario==='load_programs')$check(in_array($r['own_program'],array_map('intval',array_column($r['response'],'program_id')),true)&&!in_array($r['foreign_program'],array_map('intval',array_column($r['response'],'program_id')),true),'Program selector escaped the assigned college.');
    if($scenario==='load_prospectus_list')$check(in_array($r['header'],array_map('intval',array_column($r['response'],'prospectus_id')),true),'Own prospectus missing from load list.');
    if($scenario==='load_year_sem')$check(strpos($r['response'],'btnEditSubject')!==false&&strpos($r['response'],'btnDeleteSubject')!==false,'Subject editing/removal controls missing.');
}
$success=['save_header'=>[2,1,2],'save_year_sem'=>[1,2,2],'save_prospectus_subject'=>[1,1,3],'update_prospectus_subject'=>[1,1,2],
    'delete_prospectus_subject'=>[1,1,1],'delete_year_sem'=>[1,0,0],'update_prospectus_header'=>[1,1,2],
    'copy_prospectus'=>[2,2,4],'transfer_prospectus'=>[1,1,2],'delete_prospectus'=>[0,0,0]];
foreach($success as$scenario=>$counts){
    $r=$request($scenario);
    $check($r['http_status']===200&&is_string($r['response'])&&strpos($r['response'],'OK|')===0,'Curriculum mutation failed: '.$scenario.' '.json_encode($r['response']));
    $check([count($r['headers']),count($r['year_sem_rows']),count($r['subject_rows'])]===$counts&&$r['foreign_unchanged']&&$r['master_subject_exists'],'Mutation changed the wrong records or subject masterlist: '.$scenario);
    if($scenario==='save_prospectus_subject'){$row=end($r['subject_rows']);$check((int)$row['total_units']===2&&json_decode($row['prerequisite_sub_ids'],true)===[(string)$r['sub_a']],'Subject units/prerequisites did not round-trip.');}
    if($scenario==='update_prospectus_subject'){$row=$r['subject_rows'][0];$check((int)$row['sub_id']===$r['sub_b']&&(int)$row['total_units']===2&&(int)$row['sort_order']===3,'Edited subject fields did not persist.');}
    if($scenario==='copy_prospectus')$check($r['subject_rows'][1]['prerequisite_sub_ids']===$r['subject_rows'][3]['prerequisite_sub_ids'],'Copy lost prerequisites.');
    if($scenario==='transfer_prospectus')$check((int)$r['headers'][0]['program_id']===$r['target_program'],'Transfer used the wrong target program.');
    $r=$request('csrf_'.$scenario);$check($r['http_status']===403&&$r['unchanged'],'A mutation accepted invalid CSRF: '.$scenario);
}
foreach(['save_header','save_year_sem','save_prospectus_subject','update_prospectus_subject','delete_prospectus_subject','delete_year_sem','load_header','load_year_sem','load_subject_for_edit','load_prospectus_by_program','update_prospectus_header','transfer_prospectus','delete_prospectus','copy_prospectus','view']as$scenario){
    $r=$request('outside_'.$scenario);$check($r['http_status']===403&&$r['unchanged']&&$r['foreign_unchanged'],'Foreign curriculum was accessible: '.$scenario.' '.json_encode($r['response']));
}
foreach(['page_outside'=>403,'copy_outside_target'=>403,'transfer_outside_target'=>403,'duplicate_edit'=>422,'inactive_subject'=>422,
    'bad_prerequisite'=>422,'self_prerequisite'=>422,'bad_hours'=>422,'wrong_role'=>403,'revoked'=>403,'reassigned'=>403,'inactive'=>403,
    'forged_college'=>403,'array_input'=>400,'multiple_actions'=>400,'method'=>405,'copy_failure'=>500,
    'direct_builder'=>403,'direct_browser'=>403,'direct_copy'=>403]as$scenario=>$status){
    $r=$request($scenario);$check($r['http_status']===$status&&$r['unchanged']&&$r['foreign_unchanged'],'Validation, access control, or rollback failed: '.$scenario.' '.json_encode($r['response']));
}
foreach(['duplicate_subject','duplicate_year_sem','duplicate_copy']as$scenario){$r=$request($scenario);$check($r['unchanged'],'A duplicate request changed curriculum: '.$scenario);}
foreach(['save_header','save_year_sem','save_prospectus_subject','update_prospectus_subject','delete_prospectus_subject','delete_year_sem','update_prospectus_header','copy_prospectus','transfer_prospectus','delete_prospectus'] as $action) {
    $r=$request('admin_'.$action);
    $check($r['http_status']===200&&strpos($r['response'],'OK|')===0,'An existing administrator curriculum action regressed: '.$action.' '.json_encode($r['response']));
}
$r=$request('admin_load_programs');
$check(in_array($r['foreign_program'],array_map('intval',array_column($r['response'],'program_id')),true),'The administrator program list was incorrectly restricted.');
$r=$request('scheduler_write');
$check($r['unchanged']&&strpos($r['response'],'ERROR|')===0,'Scheduler gained curriculum write access.');
$check($before===$snapshot(),'Curriculum fixtures changed real curriculum, account, schedule, or workload records.');
echo 'Program Chair curriculum ', $http?'HTTP':'CLI', ': ',$checks,' checks passed. All fixture records rolled back.',PHP_EOL;
