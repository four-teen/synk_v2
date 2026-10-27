<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/../backend/db.php';
$snapshot=static function()use($conn):array {
    $data=[];foreach(['tbl_management_organizations','tbl_management_contributions','tbl_management_events','tbl_management_event_sessions','tbl_management_violations','tbl_management_period_settings','tbl_academic_settings','tbl_useraccount','tbl_useraccount_roles','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_class_schedule']as$table){
        $data[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM `'.$table.'` ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }return $data;
};
$before=$snapshot(); $checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$token=bin2hex(random_bytes(24));$name='management-crud-'.bin2hex(random_bytes(8)).'.php';$route=__DIR__.'/../tmp/'.$name;
file_put_contents($route,'<?php if(!in_array($_SERVER["REMOTE_ADDR"]??"",["127.0.0.1","::1"],true)||!hash_equals('.var_export($token,true).',(string)($_GET["qa"]??""))){http_response_code(404);exit;}define("SYNK_MANAGEMENT_CRUD_QA",true);require '.var_export(__DIR__.'/fixtures/management-crud-request.php',true).';');
$request=static function(string $scenario)use($name,$token):array {
    $context=stream_context_create(['http'=>['timeout'=>25,'ignore_errors'=>true,'header'=>"Connection: close\r\n"]]);
    $body=file_get_contents('http://localhost/synk/tmp/'.$name.'?qa='.$token.'&scenario='.rawurlencode($scenario),false,$context);
    $data=json_decode((string)$body,true);if(!is_array($data)||isset($data['fixture_error']))throw new RuntimeException($scenario.': '.substr((string)$body,0,2000));return $data;
};
$find=static function(array $rows,int $id,string $key):?array {foreach($rows as $row)if((int)$row[$key]===$id)return $row;return null;};
try {
    foreach(['collect_preview'=>200,'collect_post'=>200,'collect_csrf'=>403,'collect_role'=>401,'collect_foreign'=>403,'collect_stale'=>409,'collect_period'=>409,'collect_overpay'=>422,'collect_fraction'=>422,'collect_array'=>400]as$scenario=>$code){$r=$request($scenario);$check($r['status']===$code,$scenario.': payment endpoint returned '.$r['status']);if($scenario==='collect_post'){$check(isset($r['response']['receipt_number'])&&count($r['after']['tbl_management_payments'])===count($r['before']['tbl_management_payments'])+1,'Payment HTTP request did not create a receipt.');}else{$check($r['before']===$r['after'],'Preview or invalid payment changed data: '.$scenario);}}
    foreach(['attendance_http_open','attendance_http_scan','attendance_http_duplicate','attendance_http_close']as$scenario){
        $r=$request($scenario);$check($r['status']===200 && $r['response']['status']==='ok',$scenario.': attendance endpoint failed.');
        if ($scenario==='attendance_http_duplicate')$check($r['response']['duplicate'] && $r['response']['attendance']['present']===1,'HTTP duplicate scan was not idempotent.');
        if ($scenario==='attendance_http_close')$check($r['response']['attendance']['attendance_status']==='CLOSED','HTTP attendance close was not saved.');
    }
    foreach(['attendance_http_csrf'=>403,'attendance_http_role'=>401,'attendance_http_foreign'=>404,'attendance_http_stale'=>409,'attendance_http_array'=>400]as$scenario=>$status){
        $r=$request($scenario);$check($r['status']===$status && $r['before']===$r['after'],$scenario.': unauthorized attendance request changed data.');
    }
    foreach(['get_contribution','get_event']as$scenario){$r=$request($scenario);$check($r['status']===200&&strlen($r['response']['record']['version']??'')===64,$scenario.': record/version unavailable.');$check($r['before']===$r['after'],'Reading changed catalog records.');}
    foreach(['add_contribution','save_contribution','add_event','save_event','save_event_simple','add_second_session','remove_session']as$scenario){
        $r=$request($scenario);$check($r['status']===200&&($r['response']['status']??'')==='ok',$scenario.': save failed: '.json_encode($r['response']));
        $events=strpos($scenario,'event')!==false || strpos($scenario,'session')!==false;$table='tbl_management_'.($events?'events':'contributions');$key=$events?'event_id':'contribution_id';
        $row=$find($r['after'][$table],(int)$r['response']['record_id'],$key);
        $check($row && $row['title']==='NEW LOWERCASE TITLE Ñ' && $row['description']==='DESCRIPTION WITH <SCRIPT>IGNORED</SCRIPT>',$scenario.': uppercase content not persisted.');
        if(!$events)$check($row['amount']==='123.45','Exact contribution amount changed.');
        if($scenario==='unassigned_event')$check($row['ay_id']===null&&$row['semester']===null&&$row['organization_id']===null,'Unassigned values were guessed.');
        $sessions=array_values(array_filter($r['after']['tbl_management_event_sessions'],static function($s)use($row,$events){return $events && (int)$s['event_id']===(int)$row['event_id'];}));
        if($events){$expected=$scenario==='add_second_session'?2:($scenario==='remove_session'?0:1);$check(count($sessions)===$expected,'Event sessions were not saved atomically.');}
        if(strpos($scenario,'add_')!==0){$check($row['source_checksum']===str_repeat($events?'A':'C',64),'Editing changed source provenance.');}
        if($scenario==='save_event')$check($sessions[0]['fine_per_minute']==='0.1250'&&$sessions[0]['source_checksum']===str_repeat('B',64),'Editing lost exact rate or session provenance.');
        if($scenario==='save_event_simple'){
            $check($row['absence_fine']==='25.25' && $row['latitude']==='6.6155942' && $row['longitude']==='124.5997341' && $row['attendance_radius_m']==='100','The simplified event form lost its absence fine or existing location.');
            $check($r['before']['tbl_management_event_sessions']===$r['after']['tbl_management_event_sessions'],'The simplified event form changed or removed existing sessions.');
        }
    }
    foreach(['delete_contribution','delete_event']as$scenario){$r=$request($scenario);$check($r['status']===200,$scenario.': deletion failed.');$events=$scenario==='delete_event';$key=$events?'event_id':'contribution_id';$table='tbl_management_'.($events?'events':'contributions');$check($find($r['after'][$table],$r['ids'][$events?'event':'contribution'],$key)===null,'Deletion retained its definition.');if($events)$check($find($r['after']['tbl_management_event_sessions'],$r['ids']['session'],'session_id')===null,'Deleting an event retained its sessions.');}
    $invalid=['delete_linked_event'=>409,'stale_contribution'=>409,'stale_delete_event'=>409,'foreign_contribution'=>404,'contribution_other_period_get'=>404,'event_other_period_get'=>404,'old_event_status'=>422,'old_session_status'=>422,'foreign_event'=>404,'foreign_org'=>403,'unassigned_event'=>403,'foreign_event_link'=>422,
        'bad_amount'=>422,'negative_amount'=>422,'large_amount'=>422,'bad_date'=>422,'bad_term'=>422,'bad_title'=>422,'bad_utf8'=>422,'bad_coordinates_event'=>422,'partial_coordinates_event'=>422,
        'bad_session'=>422,'duplicate_session'=>422,'foreign_session'=>422,'bad_csrf'=>403,'array_input'=>400,'forged_college'=>403,'forged_campus'=>403,'no_google'=>403,'no_role'=>401,'anonymous'=>401,'revoked'=>403,'reassigned'=>403,'get_method'=>405,'invalid_action'=>422,'invalid_kind'=>422,'add_organization'=>422,'duplicate_organization'=>422,'invalid_organization'=>422,
        'violation_foreign'=>404,'violation_stale'=>409,'violation_negative'=>422,'violation_precision'=>422,'violation_blank'=>422,'violation_duplicate'=>422,'violation_duplicate_legacy'=>422];
    foreach($invalid as$scenario=>$code){$r=$request($scenario);$check($r['status']===$code,$scenario.': expected '.$code.', got '.$r['status'].' '.json_encode($r['response']));$check($r['before']===$r['after'],$scenario.': rejected request changed catalog data.');}
    foreach (['changed_exam_term','violation_changed_exam_term','missing_exam_term','invalid_exam_term'] as $scenario) {
        $r=$request($scenario);
        $check($r['status']===409 && $r['before']===$r['after'],$scenario.': outdated term request was accepted or changed data.');
    }
    $r=$request('management_term_save');
    $check($r['response']['status']==='ok' && $r['response']['current_exam_term']==='FINAL','Management did not save its own Final term.');
    $check($r['before']['tbl_academic_settings']===$r['after']['tbl_academic_settings'],'Management term save changed shared Academic Settings.');
    $own=array_values(array_filter($r['after']['tbl_management_period_settings'],static fn($row)=>(int)$row['organization_id']===$r['scope']['organization_id']));
    $check(count($own)===1 && $own[0]['current_exam_term']==='FINAL','Term setting was not stored for the assigned organization.');
    $otherPeriods=static fn($rows)=>array_values(array_filter($rows,static fn($row)=>(int)$row['organization_id']!==$r['scope']['organization_id']));
    $check($otherPeriods($r['before']['tbl_management_period_settings'])===$otherPeriods($r['after']['tbl_management_period_settings']),'Saving one organization term changed another organization.');
    foreach($r['before'] as $table=>$rows) if($table!=='tbl_management_period_settings') $check($rows===$r['after'][$table],'Management term save changed '.$table);
    foreach (['management_term_invalid'=>422,'management_term_missing'=>422,'management_term_stale'=>409,'management_term_forbidden'=>401,'management_term_foreign'=>403,'management_term_csrf'=>403] as $scenario=>$status) {
        $r=$request($scenario); $check($r['status']===$status && $r['before']===$r['after'],$scenario.': rejected Management term request changed data.');
    }
    $r=$request('management_term_switch_back');
    $check($r['response']['stale_rejected'] && $r['response']['period']['exam_term']==='MIDTERM','Switching Final back to Midterm accepted an outdated settings revision.');
    $check($r['before']['tbl_academic_settings']===$r['after']['tbl_academic_settings'],'Switching Management terms changed shared Academic Settings.');
    foreach(['page_contributions','page_events','page_violations']as$scenario){$r=$request($scenario);$check($r['status']===200&&is_string($r['response'])&&strpos($r['response'],'id="management-record-form"')!==false,$scenario.': CRUD page did not render.');$check(!preg_match('/Fatal error|<b>Warning|Parse error/',$r['response']),'Page emitted PHP diagnostics.');}
    foreach(['violation_get','violation_add','violation_save','violation_delete']as$scenario){
        $r=$request($scenario);$check($r['status']===200,$scenario.': violation action failed.');
        if($scenario==='violation_get'){$check(strlen($r['response']['record']['version'])===64&&$r['before']===$r['after'],'Violation read/version changed records.');continue;}
        $row=$find($r['after']['tbl_management_violations'],(int)$r['response']['record_id'],'violation_id');
        if($scenario==='violation_delete')$check($row===null,'Deleted violation was retained.');
        else $check($row['violation_type']==='NEW VIOLATION Ñ'&&$row['amount']==='125.25'&&(int)$row['college_id']===$r['scope']['college_id'],'Violation type, amount, or college was not saved correctly.');
    }
    $r=$request('student_detail');$check($r['status']===200&&isset($r['response']['student'],$r['response']['subjects']),'Student detail is unavailable.');
    $financial=$r['response']['financial'];
    $check(!array_key_exists('is_draft',$financial) && $financial['summary']['amount_paid']==='0.00' && $financial['summary']['absence_count']===0 && $financial['summary']['total_to_pay']==='0.00' && $financial['violations']===[],'Financial overview did not calculate automatic totals or treated pending attendance as an absence.');
    $check($financial['summary']['contribution_count']===0 && $financial['summary']['event_count']===1 && $financial['events'][0]['event_id']==$r['ids']['event'],'Financial overview mixed inactive records or another organization.');
    $check($r['before']===$r['after'],'Financial overview changed financial catalog or period data.');
    foreach(['student_foreign'=>404,'student_foreign_program'=>422,'student_array'=>400]as$scenario=>$code){$r=$request($scenario);$check($r['status']===$code,$scenario.': student access was not scoped.');}
    $r=$request('directory_comparison');$check(!empty($r['response']['same']),'Management and Dean directory results diverged.');
    $r=$request('catalog_lists');$check($r['response']===['selected'=>1,'all'=>1,'unassigned'=>1,'wildcard'=>0,'inactive'=>0,'organization'=>1],'Catalog filters mixed periods, colleges, organizations, or literal search characters.');
    $check($r['before']===$r['after'],'Reading filtered catalog lists changed records.');
    foreach (['sibling_event'=>404,'sibling_contribution'=>404,'sibling_violation'=>404,'sibling_event_link'=>422,'sibling_scope'=>403,'historical_event_link'=>422,'historical_edit'=>409,'stale_term'=>409,'changed_term'=>409,
        'violation_stale_term'=>409,'violation_changed_term'=>409,'org_admin_forbidden'=>403,'org_admin_revoked'=>403,'org_admin_csrf'=>403,'org_admin_array'=>400,'org_admin_college'=>422,'org_admin_campus'=>422,'org_admin_all_colleges'=>422,'org_admin_code'=>422,'org_admin_duplicate'=>422,'org_admin_stale'=>409,'org_admin_delete_used'=>409,'org_admin_move_used'=>409] as $scenario=>$code) {
        $r=$request($scenario); $check($r['status']===$code,$scenario.': expected '.$code.', received '.json_encode($r['response']));
        $check($r['before']===$r['after'],$scenario.': rejected request changed records.');
    }
    $r=$request('auto_term_violation'); $check($r['status']===200,'Automatic violation term save failed.');
    $row=$find($r['after']['tbl_management_violations'],(int)$r['response']['record_id'],'violation_id');
    $source=$find($r['before']['tbl_management_contributions'],$r['ids']['contribution'],'contribution_id');
    $check((int)$row['organization_id']===$r['scope']['organization_id']&&$row['ay_id']===$source['ay_id']&&$row['semester']===$source['semester'],'New violation did not adopt the assigned organization and current term.');
    foreach (['auto_term_contribution','auto_term_event'] as $scenario) {
        $r=$request($scenario); $check($r['status']===200,$scenario.': automatic term save failed: '.json_encode($r['response']));
        $event=$scenario==='auto_term_event'; $table=$event?'tbl_management_events':'tbl_management_contributions'; $key=$event?'event_id':'contribution_id';
        $row=$find($r['after'][$table],(int)$r['response']['record_id'],$key);
        $check((int)$row['organization_id']===$r['scope']['organization_id'] && (int)$row['college_id']===$r['scope']['college_id'],$scenario.': automatic organization scope failed.');
        $source=$find($r['before']['tbl_management_contributions'],$r['ids']['contribution'],'contribution_id');
        $check($row['ay_id']===$source['ay_id'] && $row['semester']===$source['semester'],'New record did not adopt the current academic term.');
        $check($row['exam_term']===$source['exam_term'],'New record did not adopt the current Management term.');
    }
    foreach (['org_admin_get','org_admin_edit','org_admin_inactive','org_admin_delete_unused','org_admin_add'] as $scenario) {
        $r=$request($scenario); $check($r['status']===200,$scenario.': organization CRUD failed: '.json_encode($r['response']));
        if (in_array($scenario,['org_admin_edit','org_admin_add'],true)) { $row=$find($r['after']['tbl_management_organizations'],(int)$r['response']['organization_id'],'organization_id'); $check($row['organization_name']==='NEW ORGANIZATION Ñ','Organization name was not uppercased.'); }
    }
    $r=$request('assignment_across_terms'); $check($r['response']['allowed']===true,'Term changes removed a persistent organization assignment.');
    $r=$request('organization_options'); $check(count($r['response']['organizations'])===1 && (int)$r['response']['organizations'][0]['organization_id']===$r['scope']['organization_id'],'Editor organization options leaked another organization.');
    foreach ($r['response']['events'] as $event) $check((int)$event['event_id']===$r['ids']['event'],'Editor event options leaked another organization.');
    foreach (['payment_context_current'=>200,'payment_context_historical'=>409,'payment_context_sibling'=>422,'payment_context_wrong_exam_term'=>409] as $scenario=>$code) { $r=$request($scenario); $check($r['status']===$code && $r['response']['allowed']===($code===200),'Payment context must require the assigned organization and current period.'); }
    $after=$snapshot();
    $changed=[];foreach($before as$table=>$hash)if($hash!==$after[$table])$changed[]=$table;
    $check(!$changed,'CRUD fixtures changed persistent Synk data: '.implode(', ',$changed));
    echo 'Management CRUD HTTP: ',$checks," checks passed; all fixtures rolled back.\n";
} finally {if(is_file($route))unlink($route);}
