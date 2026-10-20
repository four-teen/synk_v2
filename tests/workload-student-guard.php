<?php
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__.'/../backend/db.php';
$checks=0; $check=static function(bool $ok,string $message)use(&$checks):void { if (!$ok) throw new RuntimeException($message); $checks++; };
$snapshot=static function()use($conn):string { $data=[];foreach(['tbl_student_management','tbl_student_management_enrolled_subjects','tbl_faculty_workload_sched','tbl_scheduler_workload_audit_logs','tbl_student_class_clear_history','tbl_student_class_roster_audit','tbl_offering_enrollee_counts','tbl_prospectus_offering','tbl_class_schedule','tbl_useraccount','tbl_useraccount_roles'] as $table)$data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($data)); };
$before=$snapshot(); $token=bin2hex(random_bytes(24)); $route=__DIR__.'/../tmp/workload-guard-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php if(!in_array($_SERVER[\'REMOTE_ADDR\'] ?? \'\',[\'127.0.0.1\',\'::1\'],true)||!is_string($_GET[\'qa\'] ?? null)||!hash_equals('.var_export($token,true).',$_GET[\'qa\'])){http_response_code(404);exit;} define(\'SYNK_DEAN_ROSTER_QA\',true);require '.var_export(__DIR__.'/fixtures/college-dean-roster-request.php',true).';');
$request=static function(string $case)use($route,$token,$check):array {
    $context=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'timeout'=>30,'header'=>"Connection: close\r\n",'content'=>'']]);
    $body=file_get_contents('http://localhost/synk/tmp/'.basename($route).'?qa='.$token.'&scenario=guard_'.rawurlencode($case),false,$context);
    $r=json_decode((string)$body,true);
    $check(is_array($r)&&!isset($r['fixture_error']),'HTTP handler completed: '.$case.' '.substr((string)$body,0,1400));
    $check($r['guard_state']['protected_unchanged'],'Removal/review leaves student records, schedules, evaluations and history unchanged: '.$case);
    $check($r['guard_state']['outside_workload_unchanged'],'Other workloads, colleges and terms are unchanged: '.$case);
    return $r;
};
try {
    foreach (['single','csv','manual','legacy','mixed','csv_ids','positive_corrupt_metadata','bulk_students','count39','paired','faculty_need'] as $case) {
        $r=$request($case); $s=$r['guard_state'];
        $check($r['http_status']===409 && $r['response']['code']==='active_students' && $s['workload_unchanged'] && !$s['audit_added'],'Active students block the entire removal request: '.$case.' '.json_encode($r['response']));
        if ($case!=='bulk_students') $check($r['response']['classes'][0]['student_count']===$s['expected_students'] && strpos($r['response']['message'],'Clear its student list in the Dean module')!==false,'Warning uses actual distinct students, including legacy/manual rows.');
        if ($case==='count39') $check(strpos($r['response']['message'],'This class has 39 students.')===0,'The Scheduler warning shows the exact 39-student count.');
        if ($case==='paired') $check($s['selected_count']>=2 && count($r['response']['classes'])===1,'Lecture/laboratory rows count the class once and remain assigned together.');
    }
    foreach (['empty','inactive','other_term','other_college_students','cleared','bulk_empty'] as $case) {
        $r=$request($case); $s=$r['guard_state'];
        $check($r['http_status']===200 && !$s['remaining'] && $s['audit_added']>0,'Empty/cleared class removal succeeds and is audited without touching other terms/colleges: '.$case.' '.json_encode($r['response']));
    }
    foreach (['bad_csrf'=>403,'role'=>403,'get'=>405,'bad_ids'=>422,'stale_ids'=>422,'foreign_selection'=>422,'bulk_bad_term'=>422,'audit_failure'=>500] as $case=>$status) {
        $r=$request($case); $s=$r['guard_state'];
        $check($r['http_status']===$status && $s['workload_unchanged'] && !$s['audit_added'],'Invalid requests and failed audit writes remove nothing: '.$case.' '.json_encode($r['response']));
    }
    foreach (['review_unassigned'=>'missing_assignment','review_legacy_unassigned'=>'missing_assignment','review_reassigned'=>'faculty_mismatch','review_mismatch'=>'faculty_mismatch','review_unavailable'=>'class_unavailable','review_ambiguous'=>'ambiguous_class'] as $case=>$expected) {
        $r=$request($case); $matches=array_values(array_filter($r['response']['rows'] ?? [],static function(array $row)use($r):bool { return $row['subject_code']===$r['target']['subject_code'] && $row['section_label']===$r['target']['section_display']; }));
        $check($r['http_status']===200 && count($matches)===1 && $matches[0]['status']===$expected && $matches[0]['student_count']===1 && $r['guard_state']['workload_unchanged'],'Read-only college review identifies the affected class: '.$case.' '.json_encode($matches));
        if ($expected==='faculty_mismatch') $check($matches[0]['review_faculty_id']>0 && $matches[0]['saved_faculty']!==$matches[0]['assigned_faculty'],'Reassigned classes expose the current faculty for the existing clear/reimport workflow.');
        else $check(!$matches[0]['review_faculty_id'],'Unassigned or unverifiable classes cannot open a different class by mistake.');
    }
    foreach (['review_valid','review_legacy','review_foreign','review_historical'] as $case) {
        $r=$request($case); $matches=array_values(array_filter($r['response']['rows'] ?? [],static function(array $row)use($r):bool{return $row['offering_id']===$r['target']['offering_id'];}));
        $check($r['http_status']===200 && !$matches && $r['guard_state']['workload_unchanged'],'Correct assignments and other colleges/terms are excluded from review: '.$case.' '.json_encode($matches));
    }
    foreach (['review_chair'=>403,'review_scope'=>403] as $case=>$status) { $r=$request($case); $check($r['http_status']===$status && $r['guard_state']['workload_unchanged'],'Review is restricted to the assigned Dean and college.'); }
    $r=$request('review_recovered'); $state=$r['guard_state']['recovery'];
    $matches=array_filter($r['response']['rows'],static function(array $row)use($r):bool{return $row['offering_id']===$r['target']['offering_id'];});
    $check(!$matches && $state['same_identity'] && $state['old_membership_inactive'] && $state['new_faculty']===$state['portal_faculty'],'Assign the same offering, clear and reimport fixes student faculty links without replacing identity/history.');
    $lockConn=new mysqli($servername,$username,$password,$dbase);
    try {
        $lockConn->query("SELECT GET_LOCK('synk_college_dean_roster_import',1)");
        $r=$request('locked');
        $check($r['http_status']===422 && $r['guard_state']['workload_unchanged'],'A real held roster-import lock prevents removal during a class-list update.');
    } finally { $lockConn->query("SELECT RELEASE_LOCK('synk_college_dean_roster_import')"); $lockConn->close(); }
    $check($snapshot()===$before,'All fixtures rolled back; actual records are unchanged.');
} finally { if(is_file($route))unlink($route); }
echo 'Workload student guard and roster review: ',$checks,' checks passed over actual HTTP. Fixtures rolled back.',PHP_EOL;
