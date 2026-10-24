<?php
// php tests/scheduler-assigned-schedule.php
// Actual endpoint requests run against transactional fixtures and roll back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/db.php';
$checks=0;
$check=static function(bool $ok,string $message) use(&$checks):void { if(!$ok) throw new RuntimeException($message); $checks++; };
$snapshot=static function() use($conn):array {
    $state=[];
    foreach(['tbl_class_schedule','tbl_class_schedule_merge','tbl_class_schedule_tba_event','tbl_faculty_workload_sched','tbl_faculty_need_workload_sched','tbl_student_management_enrolled_subjects'] as $table) {
        $state[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM `'.$table.'` ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }
    return $state;
};
$before=$snapshot(); $token=bin2hex(random_bytes(24));
$name='assigned-schedule-qa-'.bin2hex(random_bytes(8)).'.php'; $route=__DIR__.'/../tmp/'.$name;
file_put_contents($route,'<?php if (!in_array($_SERVER["REMOTE_ADDR"] ?? "",["127.0.0.1","::1"],true) || !hash_equals('.var_export($token,true).',(string)($_GET["qa"] ?? ""))) { http_response_code(404);exit; } define("SYNK_SCHEDULER_TBA_QA",true); require '.var_export(__DIR__.'/fixtures/scheduler-tba-request.php',true).';');
$request=static function(string $scenario) use($name,$token):array {
    $context=stream_context_create(['http'=>['method'=>'POST','timeout'=>30,'ignore_errors'=>true,'header'=>"Connection: close\r\n",'content'=>'']]);
    $body=file_get_contents('http://localhost/synk/tmp/'.$name.'?qa='.$token.'&scenario='.rawurlencode($scenario),false,$context);
    $r=json_decode((string)$body,true);
    if(!is_array($r) || isset($r['fixture_error']) || !is_array($r['response'] ?? null)) throw new RuntimeException($scenario.': '.substr((string)$body,0,2000));
    return $r;
};
try {
    foreach(['assigned_confirmed_edit','assigned_confirmed_students','assigned_confirmed_need','assigned_confirmed_room_tba','assigned_confirmed_tba','planning_nextyear_assigned_confirmed_edit'] as $scenario) {
        $r=$request($scenario);
        $check($r['response']['status']==='ok',$scenario.': '.json_encode($r['response']));
        $check($r['before']['workload']===$r['after']['workload'] && $r['before']['need_workload']===$r['after']['need_workload'],'Editing changed assignment IDs: '.$scenario);
        $check($r['before']['enrollments']===$r['after']['enrollments'],'Editing changed enrollment: '.$scenario);
        $check(array_map('intval',array_column($r['after']['classes'],'schedule_id'))===$r['expected_ids'],'Editing replaced schedule IDs: '.$scenario);
        $check(array_column($r['before']['classes'],'schedule_group_id')===array_column($r['after']['classes'],'schedule_group_id'),'Editing changed workload grouping: '.$scenario);
        $check(count($r['after']['events'])===2,'Changed meetings were not audited: '.$scenario);
        if($scenario==='assigned_confirmed_students') $check(count($r['after']['enrollments'])>0,'The enrollment preservation fixture is empty.');
        if($scenario==='assigned_confirmed_edit') {
            $row=$r['after']['classes'][0];
            $check($row['days_json']==='["T"]' && $row['time_start']==='10:00:00' && $row['time_end']==='12:00:00' && (int)$row['room_id']===$r['room2_id'],'Days, time, and room changes did not save.');
            $event=$r['after']['events'][0];
            $check($event['previous_status']==='scheduled' && $event['new_status']==='scheduled' && $event['previous_values']!==$event['new_values'],'Confirmed schedule edit history is incomplete.');
        }
    }
    foreach(['faculty_conflict','room_conflict','section_conflict','internal','need_conflict','locked','merged','add','remove','foreign','wrong_type','history_failure'] as $case) {
        $r=$request('assigned_confirmed_'.$case);
        $check($r['response']['status']!=='ok','Invalid edit was accepted: '.$case);
        $check($r['before']===$r['after'],'Rejected edit changed data: '.$case);
        if(in_array($case,['faculty_conflict','room_conflict','section_conflict','internal','need_conflict'],true)) $check($r['response']['status']==='conflict','Conflict was not reported: '.$case);
    }
    $r=$request('remove_students');
    $check(($r['response']['code'] ?? '')==='active_students' && $r['before']===$r['after'],'Active-student workload removal protection changed.');
    $check($snapshot()===$before,'Test fixtures were not rolled back.');
    echo 'Assigned schedule editing: '.$checks." checks passed over actual HTTP. All temporary records rolled back.\n";
} finally { if(is_file($route)) unlink($route); }
