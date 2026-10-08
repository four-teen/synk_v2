<?php
// php tests/subject-assignments.php — production handlers on Apache, transaction fixtures.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/db.php';
$checks=0;
$check=static function(bool $ok,string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; };
$tables=['tbl_program','tbl_prospectus_header','tbl_prospectus_year_sem','tbl_prospectus_subjects','tbl_subject_masterlist','tbl_sections','tbl_section_curriculum','tbl_prospectus_offering','tbl_faculty','tbl_college_faculty','tbl_class_schedule','tbl_faculty_workload_sched','tbl_class_schedule_merge','tbl_faculty_need','tbl_faculty_need_workload_sched','tbl_useraccount','tbl_useraccount_roles','tbl_user_college_access'];
$snapshot=static function() use ($conn,$tables): array {
    $result=[]; foreach ($tables as $table) $result[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM `' . $table . '` ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    return $result;
};
$before=$snapshot(); $token=bin2hex(random_bytes(24)); $name='subject-assignments-qa-' . bin2hex(random_bytes(8)) . '.php';
$route=__DIR__ . '/../tmp/' . $name;
file_put_contents($route, '<?php if (!in_array($_SERVER["REMOTE_ADDR"] ?? "",["127.0.0.1","::1"],true) || !hash_equals(' . var_export($token,true) . ',(string)($_GET["qa"] ?? ""))) {http_response_code(404);exit;} define("SYNK_SUBJECT_ASSIGNMENTS_QA",true);require ' . var_export(__DIR__ . '/fixtures/subject-assignments-request.php',true) . ';');
$request=static function(string $scenario,array $body=[]) use ($name,$token,$check): array {
    $context=stream_context_create(['http'=>['method'=>'POST','timeout'=>30,'ignore_errors'=>true,'header'=>"Connection: close\r\nContent-Type: application/x-www-form-urlencoded\r\n",'content'=>http_build_query($body)]]);
    $text=file_get_contents('http://localhost/synk/tmp/' . $name . '?qa=' . $token . '&scenario=' . $scenario,false,$context);
    $data=json_decode((string)$text,true);
    if (!$data || isset($data['fixture_error']) || !isset($data['unchanged'])) throw new RuntimeException($scenario . ': ' . substr((string)$text,0,2000));
    $check($data['unchanged'], $scenario . ' mutated data while viewing.');
    return $data;
};
try {
    $r=$request('list'); $data=$r['response']; $known=$r['known'];
    $check($data['status']==='ok', 'List failed: ' . json_encode($data));
    $check(count($data['rows'])===3 && $data['total']===3, 'Subject/program rows were duplicated or omitted.');
    $rows=array_column($data['rows'],null,'subject_id');
    $main=$rows[$known['subject']]; $different=$rows[$known['different_subject']]; $need=$rows[$known['need_subject']];
    $check($main['faculty_count']===2 && $main['section_count']===2, 'Lecture/lab or repeated blocks inflated faculty/section counts.');
    $check($main['need_count']===1 && $main['unassigned_meetings']===2, 'Faculty Need and missing instructors were not separate.');
    $check($different['faculty_count']===0, 'A wrong-term workload counted as a current assignment.');
    $check($different['subject_code']===$main['subject_code'] && $different['description']!==$main['description'], 'Different subject master identities were merged by code.');
    $check($need['faculty_count']===0 && $need['need_count']===1, 'Faculty Need counted as real faculty.');
    $check($data['summary']===['subjects'=>3,'assigned'=>1,'unassigned'=>2,'faculty'=>2], 'Summary totals were not distinct or scoped.');
    $check(in_array('Peña, José Niño',$main['faculty_names'],true) && in_array('Muñoz, Ana',$main['faculty_names'],true), 'Unicode faculty names were corrupted.');
    foreach (['Peña'=>1,'QA NEED'=>2,'Different Description'=>1,'QA 111'=>2,'Nobody'=>0] as $search=>$expected) {
        $r=$request('list',['search'=>$search]); $check($r['response']['total']===$expected, 'Search failed: ' . $search);
    }
    foreach (['assigned'=>1,'unassigned'=>2,'needs'=>2] as $status=>$expected) {
        $r=$request('list',['assignment'=>$status]); $check($r['response']['total']===$expected, 'Assignment filter failed: ' . $status);
    }
    $r=$request('detail'); $detail=$r['response']['detail']; $known=$r['known'];
    $check(count($detail['faculty'])===2 && count($detail['meetings'])===6, 'Detail omitted assignments or meeting blocks.');
    $check(count(array_filter($detail['meetings'],static function(array $m): bool {return $m['status']==='tba' && $m['days']==='TBA' && $m['time']==='TBA' && $m['room']==='TBA';}))===3, 'Full TBA was shown as a real slot.');
    $check(count(array_filter($detail['meetings'],static function(array $m): bool {return $m['status']==='room_tba' && $m['days']==='M, W' && $m['time']==='9:00 AM – 10:00 AM' && $m['room']==='TBA';}))===3, 'Room TBA lost confirmed day/time.');
    $check(strpos($detail['faculty'][0]['workload_url'],'faculty_id=')!==false && strpos($detail['faculty'][0]['workload_url'],'college_id=')!==false, 'Current workload drilldown is missing.');
    $r=$request('historical'); $detail=$r['response']['detail'];
    $check($detail['is_current']===false && count($detail['faculty'])===1 && $detail['faculty'][0]['workload_url']==='', 'Historical detail opened a current-term workload.');
    $r=$request('merged'); $detail=$r['response']['detail']; $known=$r['known'];
    $check(count($detail['faculty'])===1 && $detail['faculty'][0]['faculty_id']===$known['faculty'][0], 'Merged lecture used a stale local instructor.');
    $check($detail['section_count']===2 && count($detail['meetings'])===6, 'Merged chain lost sections or split meeting types.');
    $check(count(array_filter($detail['meetings'],static function(array $m): bool {return $m['merged'];}))===5, 'Inherited lecture/full merges were not identified.');
    $r=$request('options');
    $check($r['response']['status']==='ok' && count($r['response']['options']['terms'])>=2, 'Term options are missing.');
    $check(!in_array($r['known']['foreign_program'],array_column($r['response']['options']['programs'],'program_id'),true), 'Options leaked another college program.');
    $r=$request('pagination',['search'=>'QA PAGE']);
    $check($r['response']['total']===28 && count($r['response']['rows'])===25 && $r['response']['pages']===2, 'Pagination did not preserve total counts.');
    $r=$request('pagination',['search'=>'QA PAGE','page'=>999]);
    $check($r['response']['page']===2 && count($r['response']['rows'])===3, 'Page bounds were not clamped.');
    foreach (['wrong_role'=>401,'inactive_account'=>403,'revoked_role'=>403,'revoked_college'=>403,'foreign_program'=>403,'foreign_college'=>409,'foreign_detail'=>404,'bad_term'=>422,'bad_array'=>422,'bad_search'=>422,'bad_status'=>422,'bad_page'=>422,'post'=>405] as $scenario=>$code) {
        $r=$request($scenario); $check($r['http_code']===$code && $r['response']['status']==='error', 'Access/input was accepted: ' . $scenario);
    }
    $r=$request('page');
    $check(is_string($r['response']) && strpos($r['response'],'id="subjectAssignments"')!==false && strpos($r['response'],'Subject Assignments')!==false, 'The page did not render on Apache.');
    $check(!preg_match('/Fatal error|Warning:|Notice:|Parse error/', $r['response']), 'The rendered page contained a PHP error.');
    $r=$request('workload_page');
    $check(is_string($r['response']) && strpos($r['response'],'const subjectAssignmentLink')!==false && !preg_match('/Fatal error|Warning:|Notice:|Parse error/', $r['response']), 'Linked workload page did not render safely.');
    $check($snapshot()===$before, 'Fixture records were not rolled back.');
    echo 'Subject Assignments: ' . $checks . ' checks passed on PHP ' . $r['php_version'] . ". Viewing was read-only; all fixtures rolled back.\n";
} finally { if (is_file($route)) unlink($route); }
