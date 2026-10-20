<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/db.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$snapshot=static function()use($conn):string { $data=[];foreach(['tbl_student_class_roster_audit','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_offering_enrollee_counts','tbl_useraccount','tbl_useraccount_roles','tbl_sections','tbl_prospectus_offering','tbl_class_schedule','tbl_faculty_workload_sched'] as $table)$data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($data)); };
$before=$snapshot();$token=bin2hex(random_bytes(24));$route=__DIR__.'/../tmp/manual-roster-'.bin2hex(random_bytes(8)).'.php';
$source='<?php if(!in_array($_SERVER[\'REMOTE_ADDR\'] ?? \'\',[\'127.0.0.1\',\'::1\'],true)||!is_string($_GET[\'qa\'] ?? null)||!hash_equals('.var_export($token,true).',$_GET[\'qa\'])){http_response_code(404);exit;} define(\'SYNK_DEAN_ROSTER_QA\',true);require '.var_export(__DIR__.'/fixtures/college-dean-roster-request.php',true).';';
file_put_contents($route,$source);
$request=static function(string $case)use($route,$token,$check):array {
    $context=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'timeout'=>30,'header'=>"Connection: close\r\n",'content'=>'']]);
    $body=file_get_contents('http://localhost/synk/tmp/'.basename($route).'?qa='.$token.'&scenario=manual_'.rawurlencode($case),false,$context);
    $data=json_decode((string)$body,true);
    $check(is_array($data)&&!isset($data['fixture_error']),'Actual HTTP handler completed: '.$case.' '.substr((string)$body,0,1300));
    $check($data['manual_state']['protected_unchanged'],'Student additions cannot change scheduling, official enrollments, evaluations or login records: '.$case);
    return $data;
};
try {
    $r=$request('lookup_new');$check($r['http_status']===200 && !$r['response']['found'] && $r['before_counts']===$r['after_counts'],'Unknown-ID lookup is read only.');
    $r=$request('lookup_existing');$check($r['http_status']===200 && $r['response']['found'] && !isset($r['response']['student']['email_address']) && !isset($r['response']['student']['student_id']),'Exact-ID lookup reveals only the identity needed for class addition.');
    foreach (['preview_new','preview_existing'] as $case) { $r=$request($case);$check($r['http_status']===200 && strlen($r['response']['preview_token'])===64 && $r['before_counts']===$r['after_counts'] && !$r['manual_state']['audit'],'Preview issues a server token without writing: '.$case); }
    foreach (['save_new','save_existing','save_cross_college','blank_email','csv_reimport','csv_append'] as $case) {
        $r=$request($case);$s=$r['manual_state'];
        $check($r['http_status']===200 && count($s['audit'])===1 && $s['audit'][0]['action']==='add' && count($s['memberships'])===1 && (int)$s['memberships'][0]['is_active']===1,'Successful addition is atomic and audited once: '.$case.' '.json_encode($r['response']));
        $check(substr($s['profile']['student_number'],0,3)==='000' && $s['profile']['first_name']==='José Carlo' && $s['profile']['last_name']==='Peña','Leading zeros and accented complete names are preserved: '.$case);
        $check($r['profile_unchanged'] && $r['roster'][0]['entry_source']==='manual' && $r['roster'][0]['manual_reason_label']==='Late enrollee' && $s['pending']===0,'Existing profiles are unchanged and roster provenance is available: '.$case);
        $check(count($s['portal_subjects'])===1 && $s['portal_subjects'][0]['subject_code']===$r['target']['subject_code'] && $s['portal_subjects'][0]['faculty_id']===$r['target']['faculty_id'],'The added membership is visible through the real student subject lookup: '.$case);
        if ($case==='blank_email') $check($s['profile']['email_address']==='','A roster member can await email verification.');
        if ($case==='csv_reimport') $check($s['csv_preview']['summary']['existing']===1 && $s['csv_preview']['summary']['add']===0,'A later CSV skips the manual member instead of duplicating it.');
        if ($case==='csv_append') $check($s['csv_result']['added']===1 && in_array($s['profile']['student_number'],array_column($s['after_csv_roster'],'student_number'),true),'A later CSV omitting a manual member keeps that member and appends other students.');
    }
    foreach (['transfer','legacy_transfer'] as $case) {
        $r=$request($case);$s=$r['manual_state'];
        $check($r['http_status']===200 && count($s['memberships'])===2 && (int)$s['memberships'][0]['is_active']===0 && (int)$s['memberships'][1]['is_active']===1 && $s['audit'][0]['action']==='transfer','Confirmed transfer keeps the original membership inactive: '.$case.' '.json_encode($r['response']));
        $check(count(json_decode($s['audit'][0]['previous_memberships'],true))===1 && $s['previous_roster']===[] && $s['csv_protected'],'The previous membership is audited, counted correctly and protected against older CSVs: '.$case);
        $check($s['csv_preview']['summary']['conflicts']===1 && !$s['csv_preview']['can_confirm'],'An old CSV cannot reverse a manual transfer: '.$case);
        $check(count($s['portal_subjects'])===1 && $s['portal_subjects'][0]['section_display']===$r['target']['section_display'],'After a section transfer the student sees the destination class, with the previous membership excluded: '.$case);
    }
    $r=$request('historical_membership');$check($r['http_status']===200 && $r['manual_state']['audit'][0]['action']==='add' && count($r['manual_state']['memberships'])===2 && array_sum(array_column($r['manual_state']['memberships'],'is_active'))===2,'Adding a current class preserves active memberships in previous semesters.');
    foreach (['transfer_no_ack'=>422,'duplicate'=>422,'concurrent_duplicate'=>422,'stale_profile'=>422,'stale_membership'=>422,'expired'=>422,'forged'=>422,'other_user'=>422,'other_class'=>422,
        'bad_email'=>422,'bad_name'=>422,'bad_reason'=>422,'bad_note'=>422,'bad_number'=>422,'bad_year'=>422,'bad_program'=>422,'email_collision'=>422,
        'bad_csrf'=>403,'scope'=>403,'historical'=>422,'revoked'=>403,'inactive'=>403,'reassigned'=>403,'assignment_removed'=>422,'term_changed'=>422,'foreign_class'=>422,
        'chair'=>403,'array'=>400,'get'=>405,'audit_failure'=>500,'transfer_failure'=>500] as $case=>$status) {
        $r=$request($case);
        $check($r['http_status']===$status && $r['before_counts']===$r['after_counts'] && !$r['manual_state']['audit'],'Rejected or failed additions leave no partial data: '.$case.' HTTP '.$r['http_status'].' '.json_encode($r['response']));
        if ($case==='transfer_failure' || $case==='transfer_no_ack') $check((int)$r['manual_state']['memberships'][0]['is_active']===1,'A failed/unconfirmed transfer keeps the previous membership active.');
    }
    $r=$request('manual_count');$check($r['http_status']===200 && (int)$r['count_map'][$r['target']['offering_id']]===77,'Manually entered offering counts are retained.');
    $r=$request('replay');$check($r['http_status']===422 && count($r['manual_state']['audit'])===1 && count($r['manual_state']['memberships'])===1,'A consumed review cannot be replayed into duplicate membership or audit rows.');
    $check($snapshot()===$before,'All test data rolled back; real records are unchanged.');
} finally {if(is_file($route))unlink($route);}
echo 'Manual class roster: ',$checks,' checks passed over actual HTTP. Fixtures rolled back.',PHP_EOL;
