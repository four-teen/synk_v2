<?php
// php tests/schedule-set-files.php -- actual multipart HTTP, all fixtures rolled back.
if(PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/../backend/db.php';
$tables=['tbl_class_schedule','tbl_class_schedule_merge','tbl_class_schedule_tba_event','tbl_faculty_workload_sched','tbl_faculty_need_workload_sched',
    'tbl_program_schedule_set','tbl_program_schedule_set_row','tbl_program_schedule_set_workload_row','tbl_program_schedule_live_workspace_state',
    'tbl_student_management_enrolled_subjects','tbl_academic_settings','tbl_scheduler_workload_audit_logs'];
$snapshot=static function() use($conn,$tables):array {$out=[];foreach($tables as $table)$out[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));return $out;};
$before=$snapshot();$checks=0;$check=static function(bool $ok,string $message) use(&$checks):void {if(!$ok)throw new RuntimeException($message);$checks++;};
$token=bin2hex(random_bytes(24));$name='set-file-qa-'.bin2hex(random_bytes(8)).'.php';$route=__DIR__.'/../tmp/'.$name;
file_put_contents($route,'<?php if (!in_array($_SERVER["REMOTE_ADDR"] ?? "",["127.0.0.1","::1"],true) || !hash_equals('.var_export($token,true).',(string)($_GET["qa"] ?? ""))) { http_response_code(404);exit; } define("SYNK_SCHEDULE_SET_FILE_QA",true);require '.var_export(__DIR__.'/fixtures/schedule-set-file-request.php',true).';');
$request=static function(string $case) use($name,$token):array {
    $boundary='synk'.bin2hex(random_bytes(10));
    $body='--'.$boundary."\r\nContent-Disposition: form-data; name=\"schedule_set_file\"; filename=\"Set-A.csv\"\r\nContent-Type: text/csv\r\n\r\nplaceholder\r\n--".$boundary."--\r\n";
    $context=stream_context_create(['http'=>['method'=>'POST','timeout'=>45,'ignore_errors'=>true,'header'=>"Connection: close\r\nContent-Type: multipart/form-data; boundary={$boundary}\r\n",'content'=>$body]]);
    $raw=file_get_contents('http://localhost/synk/tmp/'.$name.'?qa='.$token.'&case='.rawurlencode($case),false,$context);
    $result=json_decode((string)$raw,true);
    if(!is_array($result) || isset($result['fixture_error']) || !is_array($result['response'] ?? null)) throw new RuntimeException($case.': '.substr((string)$raw,0,2400));
    return $result;
};
try {
    $r=$request('export');$check($r['response']['status']==='ok','Export failed: '.json_encode($r['response']));
    $check($r['response']['program_count']===2 && $r['response']['meeting_count']===3 && $r['response']['workload_count']===2,'Export must include all programs, TBA and workload.');
    $check($r['before']===$r['after'],'Export mutated the database.');
    $check(substr($r['response']['csv'],0,3)==="\xEF\xBB\xBF" && strpos($r['response']['csv'],'SYNK_COLLEGE_SET_V1')!==false,'Excel-compatible CSV header missing.');
    foreach(['restore','restore_students','restore_need','merge_roundtrip','workload_changed','swap_faculty','switch_back_b','preview','cancel'] as $case) {
        $r=$request($case);$check($r['response']['status']==='ok',$case.': '.json_encode($r['response']));
        $check($r['before']['legacy_sets']===$r['after']['legacy_sets'] && $r['before']['legacy_rows']===$r['after']['legacy_rows'],'File sets changed legacy snapshots.');
        $check($r['before']['enrollments']===$r['after']['enrollments'],'Student links changed.');
        $check($r['before']['outside_meetings']===$r['after']['outside_meetings'],'Import changed another college.');
        $check(array_column($r['before']['state']['meetings'],'schedule_id')===array_column($r['after']['state']['meetings'],'schedule_id'),'Import recreated meetings.');
        if(in_array($case,['preview','cancel'],true)) $check($r['before']===$r['after'],'Preview changed live data.');
        else {
            $check($r['response']['summary']['program_count']===2,'Import was limited to one program.');
            if($case==='restore' || $case==='restore_students') {
                $check(array_column($r['after']['state']['meetings'],'schedule_status')===['scheduled','scheduled','tba'],'Imported schedule placements are wrong.');
                $check(count($r['after']['state']['assignments']['faculty'])===2,'Faculty workload did not restore.');
                $check($r['original']['assignments']['faculty'][0]['workload_id']===$r['after']['state']['assignments']['faculty'][0]['workload_id'],'Unchanged assignment ID was replaced.');
            }
            if($case==='restore_need') $check(count($r['after']['state']['assignments']['faculty_need'])===1,'Faculty-need workload did not restore.');
            if($case==='swap_faculty' || $case==='switch_back_b') {
                $beforeFaculty=$r['before']['state']['assignments']['faculty'][0]['faculty_id'];
                $afterFaculty=$r['after']['state']['assignments']['faculty'][0]['faculty_id'];
                $check($beforeFaculty!==$afterFaculty && count($r['after']['state']['assignments']['faculty'])===2,'Set import did not replace both faculty assignments.');
                $expectedDays=$case==='swap_faculty'?'["M"]':'["T"]';
                $check($r['after']['state']['meetings'][0]['days_json']===$expectedDays,'Schedule and workload did not switch together.');
            }
        }
    }
    foreach(['edited','truncated','wrong_college','wrong_term','workload_students','missing_meeting','extra_meeting','wrong_group','locked','inactive_faculty','invalid_room',
        'merge_changed','merged_locked','merged_students','room_conflict','faculty_conflict','section_conflict','external_room_conflict','external_faculty_conflict',
        'stale','history_failure','bad_csrf','wrong_role','stale_college','forged_term'] as $case) {
        $r=$request($case);$check($r['response']['status']!=='ok','Unsafe import accepted: '.$case);
        $check($r['before']===$r['after'],'Rejected import changed data: '.$case);
    }
    foreach(['retired_list','retired_save','retired_load','retired_rename','retired_delete'] as $case) {
        $r=$request($case);
        $check($r['response_http_status']===410 && strpos($r['response']['message'],'retired')!==false,'A retired database set action did not return HTTP 410: '.$case);
        $check($r['before']===$r['after'],'A retired database set action changed data: '.$case);
    }
    $check($before===$snapshot(),'Tests changed real schedules, workload, students, settings or snapshots.');
    echo 'Schedule set files: '.$checks." checks passed over actual multipart HTTP. Fixtures rolled back.\n";
} finally {if(is_file($route))unlink($route);}
