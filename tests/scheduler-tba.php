<?php
// php tests/scheduler-tba.php --http
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__ . '/../backend/db.php';
$checks=0;$check=static function(bool $ok,string $message) use(&$checks):void {if(!$ok)throw new RuntimeException($message);$checks++;};
$snapshot=static function() use($conn):array {
    $data=[];foreach(['tbl_class_schedule','tbl_faculty_workload_sched','tbl_class_schedule_tba_event','tbl_program_schedule_set','tbl_program_schedule_set_row','tbl_program_schedule_set_workload_row','tbl_program_schedule_live_workspace_state','tbl_rooms','tbl_room_college_access','tbl_faculty','tbl_college_faculty','tbl_sections','tbl_section_curriculum','tbl_prospectus_offering','tbl_program','tbl_prospectus_header','tbl_prospectus_year_sem','tbl_prospectus_subjects'] as $table) {
        $data[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }return $data;
};
$before=$snapshot();$token=bin2hex(random_bytes(24));$name='scheduler-tba-qa-' . bin2hex(random_bytes(8)) . '.php';$route=__DIR__ . '/../tmp/' . $name;
$source='<?php if (!in_array($_SERVER["REMOTE_ADDR"] ?? "",["127.0.0.1","::1"],true) || !hash_equals(' . var_export($token,true) . ',(string)($_GET["qa"] ?? ""))) { http_response_code(404);exit; } define("SYNK_SCHEDULER_TBA_QA",true); require ' . var_export(__DIR__ . '/fixtures/scheduler-tba-request.php',true) . ';';
file_put_contents($route,$source);
$request=static function(string $scenario,array $body=[]) use($name,$token):array {
    $context=stream_context_create(['http'=>['method'=>'POST','timeout'=>30,'ignore_errors'=>true,'header'=>"Connection: close\r\nContent-Type: application/x-www-form-urlencoded\r\n",'content'=>http_build_query($body)]]);
    $body=file_get_contents('http://localhost/synk/tmp/' . $name . '?qa=' . $token . '&scenario=' . $scenario,false,$context);$result=json_decode((string)$body,true);
    if(!$result || isset($result['fixture_error']))throw new RuntimeException($scenario . ': ' . substr((string)$body,0,2400));return $result;
};
try {
    $r=$request('create');$check($r['response']['status']==='ok','TBA creation failed: ' . json_encode($r['response']));
    $check(count($r['after']['classes'])===2,'TBA lecture/lab blocks were not created.');
    foreach($r['after']['classes'] as $row){$check($row['schedule_status']==='tba' && $row['days_json']==='[]' && $row['room_id']===null && $row['time_start']===null && $row['time_end']===null,'TBA reserved a real slot.');}
    $check(count($r['after']['events'])===2,'TBA creation history is missing.');
    $formBlock=['schedule_id'=>'0','type'=>'LEC','schedule_status'=>'tba','is_inherited'=>'false','room_id'=>'','time_start'=>'','time_end'=>'','days_json'=>'[]'];
    $r=$request('form_create',['blocks'=>[$formBlock,array_merge($formBlock,['type'=>'LAB'])]]);
    $check($r['response']['status']==='ok' && count($r['after']['classes'])===2,'Actual form encoding did not create TBA blocks.');
    foreach(['create_room','normal_save','room_tba_shared_time'] as $scenario){$r=$request($scenario);$check($r['response']['status']==='ok',$scenario . ': ' . json_encode($r['response']));}
    foreach(['missing_room','invalid_slot','invalid_status','array_input','bad_csrf','wrong_role','foreign_offering','foreign_schedule','locked','history_failure'] as $scenario) {
        $r=$request($scenario);$check($r['response']['status']!=='ok',$scenario . ' was accepted.');$check($r['before']===$r['after'],$scenario . ' changed data.');
    }
    $r=$request('resolve_ok');$check($r['response']['status']==='ok','Assigned TBA resolution failed: ' . json_encode($r['response']));
    $check($r['before']['workload']===$r['after']['workload'],'Resolution altered teacher assignment IDs.');
    $check(array_column($r['before']['classes'],'schedule_group_id')===array_column($r['after']['classes'],'schedule_group_id'),'Resolution changed lecture/lab grouping used for load totals.');
    $check(array_map('intval',array_column($r['after']['classes'],'schedule_id'))===$r['expected_ids'],'Resolution replaced schedule IDs.');
    $check($r['after']['classes'][0]['schedule_status']==='scheduled' && $r['after']['classes'][1]['schedule_status']==='tba','Mixed confirmed/pending meetings were not preserved.');
    foreach(['resolve_all','resolve_room_ok','resolve_need_ok','resolve_room_change_time','assigned_edit'] as $scenario){$r=$request($scenario);$check($r['response']['status']==='ok',$scenario . ': ' . json_encode($r['response']));$check($r['before']['workload']===$r['after']['workload'] && $r['before']['need_workload']===$r['after']['need_workload'],'Resolution changed an assignment.');}
    foreach(['resolve_faculty_conflict','resolve_external_faculty_conflict','resolve_room_conflict','resolve_section_conflict','resolve_internal','resolve_wrong_room','resolve_lab_wrong_type','resolve_blocked_time','resolve_need_conflict','assigned_add','assigned_remove'] as $scenario) {
        $r=$request($scenario);$check($r['response']['status']!=='ok',$scenario . ' was accepted.');$check($r['before']===$r['after'],$scenario . ' changed data.');
    }
    $r=$request('load_assigned');$check($r['response']['status']==='ok' && $r['response']['has_assigned_blocks']===true && $r['response']['tba_ready']===true,'Assigned TBA editor flags are missing.');
    $r=$request('available');$check(is_array($r['response']),'Available classes failed: ' . substr(json_encode($r['response']),0,1700));$pending=array_values(array_filter($r['response'],static function(array $row) use($r):bool{return (int)$row['offering_id']===$r['target']['offering_id'];}));
    $check(count($pending)===2,'TBA subjects were absent from Scheduled Classes.');
    foreach($pending as $row){$check($row['days']==='TBA' && $row['time']==='TBA' && $row['room_code']==='TBA','Scheduled Classes showed a false slot.');$check($row['faculty_load']>0,'TBA lost faculty load.');}
    $r=$request('workload');$check(is_array($r['response']),'Faculty workload did not load.');
    $encoded=json_encode($r['response']);$check(strpos($encoded,'TBA')!==false && strpos($encoded,'12:00AM-12:00AM')===false,'Faculty workload showed a false midnight schedule.');
    $r=$request('apply');$check($r['response']['status']==='success' && $r['response']['inserted']===1,'Full TBA could not be assigned alongside overlapping confirmed workload.');
    foreach(['apply_room_conflict','normal_apply_conflict','apply_duplicate','apply_bad_csrf','apply_foreign_faculty'] as $scenario){$r=$request($scenario);$check($r['response']['status']!=='success',$scenario . ' was accepted.');$check($r['before']===$r['after'],$scenario . ' changed data.');}
    foreach(['merge_pending','clear_need','clear_all_need'] as $scenario){$r=$request($scenario);$check($r['response']['status']==='error',$scenario . ' was accepted.');$check($r['before']===$r['after'],$scenario . ' changed data.');}
    $r=$request('merge_normal');$check($r['response']['status']==='ok','Confirmed merge behavior changed: ' . json_encode($r['response']));
    $r=$request('save_set');$check($r['response']['status']==='ok','Saving a TBA set failed: ' . json_encode($r['response']));
    $saved=array_values(array_filter($r['after']['saved_rows'],static function(array $row) use($r):bool{return (int)$row['offering_id']===$r['target']['offering_id'];}));
    $check(count($saved)===2 && $saved[0]['schedule_status']==='tba' && $saved[0]['time_start']===null && $saved[1]['schedule_status']==='room_tba' && $saved[1]['room_id']===null,'Saved set lost TBA status/absent values.');
    $r=$request('restore_set');$check($r['response']['status']==='ok','Restoring a TBA set failed: ' . json_encode($r['response']));
    $check(count($r['after']['workload'])===2 && count($r['after']['classes'])===2,'TBA restore lost assignments or meetings.');
    $check($r['after']['classes'][0]['schedule_status']==='tba' && $r['after']['classes'][0]['time_start']===null && $r['after']['classes'][1]['schedule_status']==='room_tba','Restored TBA became a confirmed schedule.');
    $check($snapshot()===$before,'Fixture data was not rolled back.');
    echo 'Scheduler TBA: ' . $checks . ' checks passed on PHP ' . $r['php_version'] . ". All temporary records rolled back.\n";
} finally {if(is_file($route))unlink($route);}
