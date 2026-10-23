<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../backend/db.php';
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$snapshot=static function()use($conn):string{$data=[];foreach(['tbl_academic_settings','tbl_scheduler_planning_terms','tbl_program','tbl_class_schedule','tbl_faculty_workload_sched','tbl_student_management_enrolled_subjects','tbl_scheduler_workload_audit_logs']as$table)$data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($data));};
$before=$snapshot();$token=bin2hex(random_bytes(24));$route=__DIR__.'/../tmp/scheduler-advance-qa-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php if(!in_array($_SERVER[\'REMOTE_ADDR\']??\'\',[\'127.0.0.1\',\'::1\'],true)||!is_string($_GET[\'qa\']??null)||!hash_equals('.var_export($token,true).',$_GET[\'qa\'])){http_response_code(404);exit;} define(\'SYNK_SCHEDULER_TBA_QA\',true); require '.var_export(__DIR__.'/fixtures/scheduler-tba-request.php',true).';');
$request=static function(string $scenario,bool $planning=true)use($route,$token):array{$curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'scenario'=>($planning?'planning_':'').$scenario]));curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);$body=curl_exec($curl);if($body===false)throw new RuntimeException(curl_error($curl));curl_close($curl);$r=json_decode($body,true);if(!is_array($r)||isset($r['fixture_error']))throw new RuntimeException('Invalid fixture response: '.substr($body,0,1800));return $r;};
try{
    foreach(['create','normal_save','resolve_all']as$case){
        $r=$request($case);$check(($r['response']['status']??'')==='ok','Schedules can be prepared in the future term: '.$case.' '.json_encode($r['response']));
        $check(count($r['after']['classes'])===2,'Future schedules retain both lecture and laboratory blocks.');
        $check($r['after']['official_term']===$r['before']['official_term']&&$r['after']['working_term']['is_future'],'Schedule preparation preserves the official academic term.');
    }
    foreach(['apply','normal_apply']as$case){$r=$request($case);$check(($r['response']['status']??'')==='success'&&count($r['after']['workload'])===2,'Future workload assignment supports pending and fully scheduled classes: '.$case.' '.json_encode($r['response']));$check(array_unique(array_column($r['after']['workload'],'semester'))===[(string)$r['after']['working_term']['semester']],'Workload rows use the future semester.');}
    foreach(['normal_apply_bad_csrf','normal_apply_foreign','remove_other_term','merge_other_term','simulation_foreign']as$case){$r=$request($case);$check(($r['response']['status']??'')==='error','Unsafe future scheduling requests fail: '.$case.' '.json_encode($r['response']));$check($r['before']===$r['after'],'Rejected requests preserve every schedule and workload row: '.$case);}
    foreach(['closed_create','closed_normal_apply','closed_sections_save','closed_offerings_generate','closed_simulation_add','closed_remove_selected']as$case){
        $r=$request($case);$status=is_array($r['response'])?($r['response']['status']??''):$r['response'];
        $check(in_array($status,['ok','success'],true)&&$r['after']['working_term']['can_write'],'A previously closed future term supports preparation and workload removal: '.$case.' '.json_encode($r['response']));
        $check($r['before']['official_term']===$r['after']['official_term'],'Future-term changes preserve the official academic term.');
    }
    $r=$request('sections_save');$check($r['response']==='success'&&count($r['after']['sections'])===3,'Sections can be created in the open future term. '.json_encode($r['response']));
    $check(array_unique(array_column($r['after']['sections'],'semester'))===[(string)$r['after']['working_term']['semester']],'New sections retain the selected semester.');
    $r=$request('offerings_generate');$check(($r['response']['status']??'')==='ok'&&count($r['after']['offerings'])===2,'Offerings generate from the future term section curricula. '.json_encode($r['response']));
    $r=$request('simulation_add');$check(($r['response']['status']??'')==='success'&&count($r['after']['simulations'])===1,'Future-term workload simulation assignments remain available. '.json_encode($r['response']));
    $check($r['after']['workload']===$r['before']['workload'],'Simulation preparation does not assign live workload.');
    $r=$request('tab_workload');$check(strpos(json_encode($r['response']), 'TBA')!==false&&$r['before']===$r['after'],'A page-bound future workload report survives another tab changing the session term. '.substr(json_encode($r['response']),0,800));
    foreach(['campus_report','campus_report_college']as$case){$r=$request($case);$check(($r['response']['status']??'')==='ok'&&(int)($r['response']['meta']['semester']??0)===$r['after']['working_term']['semester'],'Existing campus-wide and in-campus college reports use the page working term: '.$case.' '.substr(json_encode($r['response']),0,800));$check($r['before']===$r['after'],'Campus reporting remains read-only.');}
    $r=$request('campus_report_other_campus');$check(($r['response']['status']??'')==='error'&&$r['before']===$r['after'],'Campus reports still reject a campus outside the scheduler assignment.');
    foreach(['sections_save','offerings_generate','normal_save','normal_apply','simulation_add']as$case){
        $r=$request('nextyear_'.$case);$status=is_array($r['response'])?($r['response']['status']??''):$r['response'];
        $check(in_array($status,['ok','success'],true),'Advance preparation works in a later academic year: '.$case.' '.json_encode($r['response']));
        $future=$r['after']['working_term'];$check($future['ay_id']!==$r['after']['official_term']['ay_id']&&$r['after']['official_term']===$r['before']['official_term'],'Future-year preparation keeps the official year unchanged.');
        foreach(['sections','offerings','workload','simulations']as$table)foreach($r['after'][$table]as$row)$check((int)$row['ay_id']===$future['ay_id']&&(int)$row['semester']===$future['semester'],'Every created '.$table.' row retains the future academic year and semester.');
    }
    foreach(['pastyear_sections_save','pastyear_offerings_generate','pastyear_normal_save','pastyear_normal_apply','pastyear_simulation_add','pastyear_remove_selected','inactiveyear_normal_save','inactiveyear_normal_apply','inactiveyear_remove_selected']as$case){
        $r=$request($case);$status=is_array($r['response'])?($r['response']['status']??''):$r['response'];
        $check(in_array($status,['ok','success'],true),'Previous and inactive academic years support editing: '.$case.' '.json_encode($r['response']));
        $selected=$r['after']['working_term'];$check($selected['can_write']&&!$selected['is_future']&&!$selected['is_current']&&$r['before']['official_term']===$r['after']['official_term'],'Historical editing uses the selected term independently of the official term.');
        foreach(['sections','offerings','workload','simulations']as$table)foreach($r['after'][$table]as$row)$check((int)$row['ay_id']===$selected['ay_id']&&(int)$row['semester']===$selected['semester'],'Historical '.$table.' records stay in the selected term.');
    }
    foreach(['pastyear_remove_students','inactiveyear_remove_students']as$case){$r=$request($case);$check(($r['response']['code']??'')==='active_students'&&$r['before']===$r['after'],'Student enrollment still protects workload in historical terms: '.$case.' '.json_encode($r['response']));}
    $r=$request('remove_selected');$check(($r['response']['status']??'')==='ok'&&($r['response']['deleted_count']??0)===1,'Removing a selected future workload deletes exactly one selected row. '.json_encode($r['response']));
    $check(count($r['before']['workload'])===2&&count($r['after']['workload'])===1&&$r['before']['classes']===$r['after']['classes'],'Removing selected workload preserves the other assignment and the class schedule.');
    foreach([true,false]as$planning){$r=$request('remove_students',$planning);$check(($r['response']['status']??'')==='blocked'&&($r['response']['code']??'')==='active_students','Actual student enrollments block workload removal in current and future terms. '.json_encode($r['response']));$check($r['before']===$r['after'],'Student-protected workloads and class schedules remain unchanged.');}
    $check($snapshot()===$before,'All advance scheduling fixtures rolled back and preserved live academic records.');
    echo 'Advance scheduling: '.$checks.' checks passed. Fixtures rolled back.'.PHP_EOL;
}finally{if(is_file($route))unlink($route);}
