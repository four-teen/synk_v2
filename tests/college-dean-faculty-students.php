<?php
// php tests/college-dean-faculty-students.php — all request fixtures roll back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/db.php';
$checks=0;$check=static function(bool $value,string $message) use(&$checks):void{if(!$value)throw new RuntimeException($message);$checks++;};
$tables=['tbl_useraccount','tbl_useraccount_roles','tbl_faculty','tbl_college_faculty','tbl_class_schedule','tbl_faculty_workload_sched',
    'tbl_sections','tbl_section_curriculum','tbl_prospectus_offering','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_offering_enrollee_counts'];
$snapshot=static function() use($conn,$tables):array{$rows=[];foreach($tables as $table)$rows[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));return $rows;};
$before=$snapshot();$token=bin2hex(random_bytes(24));$routeName='faculty-students-qa-' . bin2hex(random_bytes(8)) . '.php';$routePath=__DIR__ . '/../tmp/' . $routeName;
$route= <<<'PHP'
<?php
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true) || !is_string($_GET['qa'] ?? null) || !hash_equals(QA_TOKEN,$_GET['qa'])) { http_response_code(404); exit; }
header('X-QA-PHP-Version: ' . PHP_VERSION);define('SYNK_DEAN_FACULTY_STUDENTS_QA',true);require QA_FIXTURE;
PHP;
file_put_contents($routePath,str_replace(['QA_TOKEN','QA_FIXTURE'],[var_export($token,true),var_export(__DIR__ . '/fixtures/college-dean-faculty-students-request.php',true)],$route));
$version='';
$request=static function(string $scenario) use($routeName,$token,&$version,$check):array{
    $context=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'follow_location'=>0,'timeout'=>30,'header'=>"Connection: close\r\n",'content'=>'']]);
    $body=file_get_contents('http://localhost/synk/tmp/' . $routeName . '?qa=' . $token . '&scenario=' . rawurlencode($scenario),false,$context);
    preg_match('/X-QA-PHP-Version: ([^\r\n]+)/i',implode("\n",$http_response_header ?? []),$match);$version=$match[1] ?? 'unknown';
    $result=json_decode((string)$body,true);if(!$result||isset($result['fixture_error']))throw new RuntimeException($scenario . ': ' . substr((string)$body,0,2300));
    $check($result['records_unchanged']===true,'The report wrote data: ' . $scenario);return $result;
};
try {
    $r=$request('page');$check($r['http_status']===200&&is_string($r['response'])&&!preg_match('/Fatal error|Parse error|Warning|xdebug-error/i',$r['response']),'Reports failed to render.');
    foreach(['Faculty Student Summary','deanReportTerm','deanReportProgram','deanReportStatus','reports.js','deanFacultyClassesModal','dashboard'] as $feature)$check(strpos($r['response'],$feature)!==false,'Missing report feature: ' . $feature);
    $check($r['session_college']===$r['college_id'],'Page filters widened the Dean scope.');
    $r=$request('bootstrap');$options=$r['response']['options'];
    $check(in_array($r['program_a'],array_column($options['programs'],'program_id'),true)&&!in_array((int)$r['foreign']['program_id'],array_column($options['programs'],'program_id'),true),'Program options leaked another college.');
    $check(in_array($r['historical']['ay_id'] . ':' . $r['term']['semester'],array_column($options['terms'],'key'),true),'Historical assignment terms were omitted.');
    $r=$request('list');$rows=array_column($r['response']['rows'],null,'faculty_id');
    $check($r['response']['summary']===['faculties'=>3,'assigned_classes'=>4,'with_lists'=>3,'without_lists'=>1,'faculties_missing'=>1,'unique_students'=>3,'total_enrollments'=>5],'College totals duplicated shared faculty/classes/students or included archived/foreign rows.');
    $a=$rows[$r['faculty']['a']];$b=$rows[$r['faculty']['b']];$c=$rows[$r['faculty']['c']];
    $check([$a['assigned_classes'],$a['with_lists'],$a['without_lists'],$a['unique_students'],$a['total_enrollments']] === [3,2,1,2,3],'Faculty A totals are inaccurate.');
    $check([$b['assigned_classes'],$b['with_lists'],$b['without_lists'],$b['unique_students'],$b['total_enrollments']] === [2,2,0,3,4],'Shared teaching assignments lost or duplicated student memberships.');
    $check($c['assigned_classes']===0&&$c['without_lists']===0&&!isset($rows[$r['faculty']['inactive']])&&!isset($rows[$r['faculty']['foreign']]),'Unassigned/inactive/foreign faculty were treated as missing imports.');
    $check(strpos($a['faculty_name'],'Peña')!==false&&strpos($a['faculty_name'],'José')!==false&&strpos($a['faculty_name'],'Niño')!==false,'Accented faculty names were damaged.');
    foreach(['missing'=>'a','complete'=>'b','unassigned'=>'c','search_accent'=>'a'] as $scenario=>$key){$r=$request($scenario);$check(count($r['response']['rows'])===1&&$r['response']['rows'][0]['faculty_id']===$r['faculty'][$key],'Faculty status/name filter failed: ' . $scenario);}
    $r=$request('missing');$check($r['response']['summary']['unique_students']===2&&$r['response']['summary']['total_enrollments']===3&&$r['response']['summary']['without_lists']===1,'Summary ignored the missing-list filter.');
    $r=$request('program_a');$check($r['response']['summary']['assigned_classes']===2&&$r['response']['summary']['without_lists']===0&&$r['response']['summary']['unique_students']===2&&$r['response']['summary']['total_enrollments']===3,'Program filter broadened counts or included unassigned faculty.');
    $r=$request('program_b');$check($r['response']['summary']['assigned_classes']===2&&$r['response']['summary']['without_lists']===1&&$r['response']['summary']['unique_students']===2&&$r['response']['summary']['total_enrollments']===2,'Second program counts are inaccurate.');
    $r=$request('historical');$check($r['response']['summary']['faculties']===1&&$r['response']['summary']['assigned_classes']===1&&$r['response']['summary']['unique_students']===1&&$r['response']['summary']['total_enrollments']===1,'Academic term filtering included current classes.');
    $r=$request('detail');$classes=array_column($r['response']['classes'],null,'offering_id');
    $check(count($classes)===3&&$classes[$r['classes']['a1']['offering_id']]['student_count']===2&&$classes[$r['classes']['a2']['offering_id']]['student_count']===1,'Lecture/lab duplicates or legacy offering links changed class counts.');
    $check($classes[$r['classes']['a3']['offering_id']]['student_count']===0,'Estimated workload counts or inactive/wrong-college memberships were mistaken for imported students.');
    foreach($classes as $class)$check($class['student_count']===$r['workload_counts'][$class['offering_id']],'Report and workload disagree on imported students, including a membership stored against another campus.');
    $check(strpos($classes[$r['classes']['a3']['offering_id']]['workload_url'],'list_status=missing')!==false&&strpos($classes[$r['classes']['a3']['offering_id']]['workload_url'],'offering_id=')!==false,'Missing classes did not link to their workload import view.');
    $r=$request('detail_program');$check(count($r['response']['classes'])===2,'Class detail ignored the selected program.');
    $r=$request('detail_old');$check($r['response']['is_current_term']===false&&$r['response']['classes'][0]['workload_url']===null,'A previous-term report exposed a current-term import link.');
    $r=$request('detail_unassigned');$check($r['response']['classes']===[]&&$r['response']['faculty']['without_lists']===0,'Unassigned faculty had phantom missing classes.');
    foreach(['detail_foreign','detail_inactive'] as $scenario){$r=$request($scenario);$check($r['http_status']===404,'Guessed faculty IDs widened report access: ' . $scenario);}
    foreach(['search_literal','search_sql'] as $scenario){$r=$request($scenario);$check($r['response']['rows']===[]&&$r['response']['summary']['unique_students']===0,'Search interpreted data as a wildcard or SQL.');}
    $r=$request('reassigned');$check($r['session_college']===(int)$r['foreign']['college_id']&&$r['response']['summary']['unique_students']===2&&$r['response']['summary']['assigned_classes']===1,'The report retained stale Dean college access.');
    foreach(['bad_csrf'=>403,'other_college'=>403,'other_campus'=>403,'other_role'=>403,'revoked'=>403,'inactive'=>403,'bad_method'=>405,
        'array_input'=>400,'bad_term'=>422,'bad_program'=>422,'bad_status'=>422,'bad_faculty'=>422,'bad_action'=>422] as $scenario=>$status){$r=$request($scenario);$check($r['http_status']===$status,'Report validation failed: ' . $scenario);}
    $check($snapshot()===$before,'Report fixtures changed real records.');
    echo 'Faculty Student Summary HTTP: ', $checks, ' checks passed on PHP ', $version, '. All fixtures rolled back.', PHP_EOL;
} finally {if(is_file($routePath))unlink($routePath);}
