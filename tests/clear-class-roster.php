<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/db.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$snapshot=static function()use($conn):string { $data=[];foreach(['tbl_student_class_clear_history','tbl_student_class_roster_audit','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_offering_enrollee_counts','tbl_useraccount','tbl_useraccount_roles','tbl_sections','tbl_prospectus_offering','tbl_class_schedule','tbl_faculty_workload_sched'] as $table)$data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($data)); };
$before=$snapshot(); $token=bin2hex(random_bytes(24)); $route=__DIR__.'/../tmp/clear-roster-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php if(!in_array($_SERVER[\'REMOTE_ADDR\'] ?? \'\',[\'127.0.0.1\',\'::1\'],true)||!is_string($_GET[\'qa\'] ?? null)||!hash_equals('.var_export($token,true).',$_GET[\'qa\'])){http_response_code(404);exit;} define(\'SYNK_DEAN_ROSTER_QA\',true);require '.var_export(__DIR__.'/fixtures/college-dean-roster-request.php',true).';');
$request=static function(string $case)use($route,$token,$check):array {
    $context=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'timeout'=>30,'header'=>"Connection: close\r\n",'content'=>'']]);
    $body=file_get_contents('http://localhost/synk/tmp/'.basename($route).'?qa='.$token.'&scenario=clear_'.rawurlencode($case),false,$context);
    $r=json_decode((string)$body,true);
    $check(is_array($r)&&!isset($r['fixture_error']),'Actual HTTP handler completed: '.$case.' '.substr((string)$body,0,1300));
    $check($r['clear_state']['protected_unchanged'],'Profiles, accounts, scheduling, evaluations and login records stay unchanged: '.$case);
    $check($r['clear_state']['outside_unchanged'],'Other subjects, colleges, sections and terms stay unchanged: '.$case);
    $check($r['clear_state']['row_count_unchanged'],'Membership history is retained: '.$case);
    return $r;
};
try {
    $r=$request('preview'); $check($r['http_status']===200 && strlen($r['response']['preview_token'])===64 && $r['clear_state']['memberships_unchanged'] && !$r['clear_state']['audit'],'Preview is read only and lists the selected class.');
    $check(in_array('manual',array_column($r['response']['students'],'entry_source'),true) && in_array('csv',array_column($r['response']['students'],'entry_source'),true),'Both imported and manually added students appear in the clearing preview.');
    $r=$request('bootstrap_missing'); $check($r['http_status']===200 && $r['response']['can_clear_students']===false && $r['response']['can_add_students']===true && $r['response']['can_import']===true,'Missing clearing schema hides only clearing and preserves existing workflows.');
    foreach (['save','manual_count','delete_directory','reimport'] as $case) {
        $r=$request($case); $state=$r['clear_state'];
        $check($r['http_status']===200 && !$r['roster'] && $state['audit'] && !$state['pending'],'Clearing empties exactly the class and consumes the preview: '.$case.' '.json_encode($r['response']));
        foreach ($state['ids'] as $i=>$id) {
            $check($state['directory'][$id]['summary']['students']===1 && $state['directory'][$id]['rows'][0]['student_id']===$id,'Student remains in the current-term Dean directory, including a shared foreign-origin profile.');
            $check($state['directory'][$id]['rows'][0]['subject_count']===($i?1:0),'Cleared students have accurate remaining subject counts.');
            $check(count($state['details'][$id]['subjects'])===($i?1:0) && $state['login'][$id]!==null,'Student details and login tracing remain accessible after clearing.');
            $check(count($state['portal'][$id])===($i?2:0),'The student portal removes the cleared class and retains other subjects, including another college.');
        }
        $check((int)$r['count_map'][$r['target']['offering_id']]===($case==='manual_count'?77:0),'Derived roster counts become zero; explicit manual estimates remain unchanged.');
        foreach ($state['audit'] as $audit) $check($audit['actor_name']!=='' && $audit['recorded_at']!=='' && (int)$audit['directory_retained']===1 && (int)json_decode($audit['membership_snapshot'],true)['student_enrollment_id']===(int)$audit['student_enrollment_id'],'Clearing records who, when and the original membership.');
        if ($case==='delete_directory') $check($state['delete_preview']['summary']['retained_directory_students']===1 && $state['deleted_directory'] && $state['identity_kept'] && $state['audit_kept']>0,'Explicit directory deletion can remove the retained connection without deleting identity or clearing history.');
        if ($case==='reimport') $check($state['profile_reused'] && count($state['after_reimport'])===1 && $state['inactive_preserved']===1,'Corrected CSV reuses the same profile and preserves the inactive membership.');
    }
    foreach (['no_ack'=>422,'expired'=>422,'other_user'=>422,'forged'=>422,'other_class'=>422,'changed_roster'=>422,'assignment_removed'=>422,
        'audit_failure'=>500,'bad_csrf'=>403,'scope'=>403,'historical'=>422,'chair'=>403,'get'=>405,'array'=>400,'unknown'=>422,'revoked'=>403,'inactive'=>403,'foreign_class'=>422,'missing_schema'=>422] as $case=>$status) {
        $r=$request($case);
        $check($r['http_status']===$status && $r['clear_state']['memberships_unchanged'] && !$r['clear_state']['audit'],'Rejected or failed requests clear nothing, including a mid-operation failure: '.$case.' '.json_encode($r['response']));
    }
    $r=$request('cancel'); $check($r['http_status']===200 && !$r['clear_state']['pending'] && $r['clear_state']['memberships_unchanged'],'Cancel discards the preview and preserves memberships.');
    foreach (['replay','empty'] as $case) { $r=$request($case); $check($r['http_status']===422 && $r['clear_state']['memberships_unchanged'] && !$r['roster'],'A consumed preview or empty class cannot be cleared again: '.$case); }
    $check($snapshot()===$before,'All fixtures rolled back; real data is unchanged.');
} finally { if(is_file($route))unlink($route); }
echo 'Clear class roster: ',$checks,' checks passed over actual HTTP. Fixtures rolled back.',PHP_EOL;
