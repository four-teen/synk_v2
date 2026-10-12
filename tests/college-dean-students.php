<?php
// php tests/college-dean-students.php — real HTTP requests with rolled-back imported memberships.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/db.php';
$checks=0;
$check=static function (bool $value,string $message) use (&$checks): void { if (!$value) throw new RuntimeException($message); $checks++; };
$snapshot=static function () use ($conn): array {
    $rows=[];
    foreach (['tbl_useraccount','tbl_useraccount_roles','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_sections'] as $table) $rows[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    return $rows;
};
$before=$snapshot(); $token=bin2hex(random_bytes(24));
$routeName='dean-students-qa-' . bin2hex(random_bytes(8)) . '.php'; $routePath=__DIR__ . '/../tmp/' . $routeName;
$route= <<<'PHP'
<?php
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(QA_TOKEN,$_GET['qa'])) { http_response_code(404); exit; }
header('X-QA-PHP-Version: ' . PHP_VERSION); define('SYNK_DEAN_STUDENTS_QA',true); require QA_FIXTURE;
PHP;
file_put_contents($routePath,str_replace(['QA_TOKEN','QA_FIXTURE'],[var_export($token,true),var_export(__DIR__ . '/fixtures/college-dean-students-request.php',true)],$route));
$version='';
$request=static function (string $scenario) use ($routeName,$token,&$version,$check): array {
    $context=stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'follow_location'=>0,'timeout'=>20,'header'=>"Connection: close\r\n",'content'=>'']]);
    $body=file_get_contents('http://localhost/synk/tmp/' . $routeName . '?qa=' . $token . '&scenario=' . rawurlencode($scenario),false,$context);
    preg_match('/X-QA-PHP-Version: ([^\r\n]+)/i',implode("\n",$http_response_header ?? []),$match); $version=$match[1] ?? 'unknown';
    $r=json_decode((string)$body,true);
    if (!$r || isset($r['fixture_error'])) throw new RuntimeException($scenario . ': ' . substr((string)$body,0,1800));
    $check($r['records_unchanged']===true,'The directory changed student records in scenario ' . $scenario);
    return $r;
};
try {
    $r=$request('page');
    $check($r['http_status']===200 && is_string($r['response']) && !preg_match('/Fatal error|Parse error|Warning(?:<\/b>)?:|xdebug-error/i',$r['response']),'Student Management failed to render.');
    foreach (['Student Management','students.js','custom_css.css','deanStudentsRows','deanStudentDetailsModal','Import Students','Class Sections','Student Name','Add Student','Delete Selected Term Records'] as $feature) $check(strpos($r['response'],$feature)!==false,'Missing student directory feature: ' . $feature);
    $check($r['session_college']===$r['scope']['college_id'],'Page URL filters changed the assigned college.');
    $r=$request('bootstrap');
    $check($r['http_status']===200 && $r['response']['status']==='ok','Directory filters did not load.');
    $programIds=array_column($r['response']['options']['programs'],'program_id');
    $check(in_array($r['scope']['program_id'],$programIds,true) && !in_array($r['foreign_scope']['program_id'],$programIds,true),'Program options leaked another college.');
    $r=$request('list');
    $check($r['response']['summary']===['students'=>2,'classes'=>3,'programs'=>1],'Summary counted subject memberships as duplicate students.');
    $rows=array_column($r['response']['rows'],null,'student_id');
    $shared=$rows[$r['profiles']['shared']['student_id']];
    $check(count($rows)===2 && $shared['subject_count']===2,'Subject totals duplicated the same subject across terms or leaked foreign subjects.');
    $check($shared['student_number']===$r['profiles']['shared']['student_number'] && strpos($shared['student_name'],'Peña, José Niño')===0,'The directory lost leading zeros or accented characters.');
    $check($r['profiles']['shared']['program_id']===$r['foreign_scope']['program_id'] && isset($rows[$r['profiles']['shared']['student_id']]),'Membership was incorrectly inferred from the master profile program.');
    $r=$request('default_term'); $check($r['response']['summary']['classes']===2,'Omitting the term did not default to the current semester.');
    $r=$request('whole_year'); $check($r['response']['summary']['classes']===3,'Academic-year filtering omitted another semester.');
    foreach (['email_missing'=>'shared','email_present'=>'own'] as $scenario=>$studentKey) {
        $r=$request($scenario);
        $check($r['response']['summary']['students']===1 && $r['response']['rows'][0]['student_id']===$r['profiles'][$studentKey]['student_id'] && $r['response']['pagination']['total']===1,'Email status did not filter rows, totals and pagination consistently.');
    }
    $r=$request('email_selection'); $check($r['response']['student_ids']===[$r['profiles']['shared']['student_id']],'Select matching students ignored the missing-email filter.');
    $r=$request('email_preview_filtered'); $check(count($r['response']['rows'])===1 && $r['response']['rows'][0]['student_id']===$r['profiles']['shared']['student_id'],'Fill Missing Emails preview ignored the email filter.');
    $r=$request('email_whitespace'); $check(count($r['response']['rows'])===2,'Whitespace-only email values were treated as present addresses.');
    foreach (['current','section','program'] as $scenario) {
        $r=$request($scenario); $rows=array_column($r['response']['rows'],null,'student_id');
        $check($r['response']['summary']['students']===2 && $rows[$r['profiles']['shared']['student_id']]['subject_count']===2,'Subject totals ignored the selected term, section, or program.');
    }
    $r=$request('historical');
    $check($r['response']['summary']['students']===1 && $r['response']['rows'][0]['subject_count']===1,'The subject count did not follow the selected historical term.');
    $r=$request('historical_section');
    $check($r['response']['summary']['students']===1 && $r['response']['rows'][0]['subject_count']===1,'The subject count did not follow the selected historical section.');
    foreach (['foreign_program','foreign_section','search_literal','search_sql'] as $scenario) {
        $r=$request($scenario); $check($r['response']['rows']===[] && $r['response']['summary']['students']===0,'A filter broadened college access or treated a wildcard as SQL: ' . $scenario);
    }
    foreach (['search_id','search_accent'] as $scenario) {
        $r=$request($scenario); $key=$scenario==='search_id' ? 'own' : 'shared';
        $check(count($r['response']['rows'])===1 && $r['response']['rows'][0]['student_id']===$r['profiles'][$key]['student_id'],'Student ID or accented name search failed.');
    }
    foreach (['page_one'=>1,'page_two'=>2,'page_stale'=>2] as $scenario=>$page) {
        $r=$request($scenario); $check(count($r['response']['rows'])===1 && $r['response']['pagination']['total']===2 && $r['response']['pagination']['page']===$page,'Pagination duplicated students or failed to clamp a stale page.');
    }
    foreach (['sort_id','sort_newest'] as $scenario) {
        $r=$request($scenario); $check($r['http_status']===200 && count($r['response']['rows'])===2,'The selected sort order failed to load.');
        if ($scenario==='sort_id') $check($r['response']['rows'][0]['student_id']===$r['profiles']['shared']['student_id'],'Student ID sorting converted or lost leading zeros.');
    }
    $r=$request('details');
    $check($r['response']['student']['student_number']===$r['profiles']['shared']['student_number'] && count($r['response']['subjects'])===3,'Student Details did not show scoped subject memberships.');
    foreach ($r['response']['subjects'] as $subject) $check($subject['program_id']===$r['scope']['program_id'],'Student Details leaked a foreign college subject.');
    $r=$request('details_current'); $check(count($r['response']['subjects'])===2,'Student Details ignored the selected academic term.');
    foreach (['details_foreign','details_unimported','details_archived'] as $scenario) {
        $r=$request($scenario); $check($r['http_status']===404,'A guessed student ID exposed an unimported or foreign student profile.');
    }
    $r=$request('reassigned');
    $check($r['session_college']===$r['foreign_scope']['college_id'] && $r['response']['summary']['students']===2,'The student directory retained stale Dean college access.');
    foreach (['scope_tamper'=>403,'bad_csrf'=>403,'revoked'=>403,'inactive'=>403,'other_role'=>403,'bad_method'=>405,
        'bad_term'=>422,'bad_sort'=>422,'bad_page'=>422,'array_input'=>400,'bad_action'=>422,'bad_email_status'=>422,'array_email_status'=>400] as $scenario=>$status) {
        $r=$request($scenario); $check($r['http_status']===$status,'Student directory request validation failed: ' . $scenario);
    }
    $check($snapshot()===$before,'Student directory tests changed real data.');
    echo 'College Dean students HTTP: ', $checks, ' checks passed on PHP ', $version, '. All fixture records rolled back.', PHP_EOL;
} finally { if (is_file($routePath)) unlink($routePath); }
