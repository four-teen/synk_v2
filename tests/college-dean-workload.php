<?php
// php tests/college-dean-workload.php --http
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/college_dean_workload_helper.php';
$checks = 0;
$check = static function (bool $value, string $message) use (&$checks): void {
    if (!$value) throw new RuntimeException($message); $checks++;
};
$snapshot = static function () use ($conn): array {
    $result = [];
    foreach (['tbl_useraccount','tbl_useraccount_roles','tbl_useraccount_faculty_links','tbl_student_management','tbl_student_management_enrolled_subjects',
        'tbl_offering_enrollee_counts','tbl_faculty_workload_sched','tbl_class_schedule','tbl_sections','tbl_prospectus_offering'] as $table) {
        $result[$table] = hash('sha256',json_encode($conn->query('SELECT * FROM ' . $table . ' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }
    return $result;
};
$before = $snapshot();
$token = bin2hex(random_bytes(24));
$routeName = 'dean-roster-qa-' . bin2hex(random_bytes(8)) . '.php';
$routePath = __DIR__ . '/../tmp/' . $routeName;
$route = <<<'PHP'
<?php
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(QA_TOKEN,$_GET['qa'])) { http_response_code(404); exit; }
header('X-QA-PHP-Version: ' . PHP_VERSION);
define('SYNK_DEAN_ROSTER_QA',true);
require QA_FIXTURE;
PHP;
file_put_contents($routePath,str_replace(['QA_TOKEN','QA_FIXTURE'],[var_export($token,true),var_export(__DIR__ . '/fixtures/college-dean-roster-request.php',true)],$route));
$version = '';
$request = static function (string $scenario, string $csv = '') use ($routeName,$token,&$version): array {
    $boundary = 'DeanQA' . bin2hex(random_bytes(16));
    $body = '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"qa\"\r\n\r\n1\r\n";
    if ($csv !== '') $body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"csv_file\"; filename=\"qa-roster.csv\"\r\nContent-Type: text/csv\r\n\r\n" . $csv . "\r\n";
    $body .= '--' . $boundary . "--\r\n";
    $context = stream_context_create(['http'=>['method'=>'POST','ignore_errors'=>true,'follow_location'=>0,'timeout'=>30,
        'header'=>"Content-Type: multipart/form-data; boundary={$boundary}\r\nConnection: close\r\n",'content'=>$body]]);
    $response = file_get_contents('http://localhost/synk/tmp/' . $routeName . '?qa=' . $token . '&scenario=' . rawurlencode($scenario),false,$context);
    preg_match('/X-QA-PHP-Version: ([^\r\n]+)/i',implode("\n",$http_response_header ?? []),$match); $version=$match[1] ?? 'unknown';
    $result = json_decode((string)$response,true);
    if (!$result || isset($result['fixture_error'])) throw new RuntimeException($scenario . ': ' . substr((string)$response,0,1800));
    return $result;
};
$csvFor = static function (array $class, array $students): string {
    $rows = [[$class['campus_name']],[],['EXAM PERMITS'],['Subject/Section','','',$class['subject_code'] . ' - ' . $class['section_display']],
        ['Descriptive Title','','',$class['descriptive_title']],['Faculty','','',$class['first_name'] . ' ' . $class['last_name']],
        ['No.',"Student's Name",'','','ID No.']];
    foreach ($students as $i=>$student) $rows[] = [$i+1,$student[1],'','',$student[0]];
    $handle=fopen('php://temp','r+'); foreach ($rows as $row) fputcsv($handle,$row);
    rewind($handle); $csv=stream_get_contents($handle); fclose($handle); return $csv;
};
$parseCsv = static function (string $csv): array {
    $path = tempnam(__DIR__ . '/../tmp','dean-roster-parse-');
    try { file_put_contents($path,$csv); return synk_dean_roster_parse($path); } finally { unlink($path); }
};
$reject = static function (callable $fn, string $message) use ($check): void {
    try { $fn(); } catch (DomainException $e) { $check(true,$message); return; }
    $check(false,$message);
};
try {
    $r = $request('page'); $class = $r['target'];
    $check($r['http_status']===200 && is_string($r['response']) && !preg_match('/Fatal error|Parse error|<b>Warning|xdebug-error/i',$r['response']),'The workload page failed to render.');
    foreach (['Faculty Workload','faculty-workload.js','custom_css.css','Preview Import','Confirm Import','deanStudentSearch','deanMissingCount','deanRosterFilter','Faculty Student Summary'] as $text) $check(strpos($r['response'],$text)!==false,'The workload page is missing ' . $text);
    $r = $request('bootstrap');
    $check($r['response']['status']==='ok' && $r['response']['import_ready']===true && count($r['response']['faculties'])>0,'Faculty options failed to load.');
    $check(in_array($class['faculty_id'],array_column($r['response']['faculties'],'faculty_id'),true),'The assigned faculty is missing from the selector.');
    $r = $request('classes');
    $check($r['response']['status']==='ok' && count($r['response']['classes'])>0,'Assigned classes failed to load.');
    foreach ($r['response']['classes'] as $row) $check($row['college_id']===$class['college_id'] && $row['ay_id']===$class['ay_id'] && $row['semester']===$class['semester'],'Class scope leaked.');
    $check(count(array_unique(array_column($r['response']['classes'],'offering_id')))===count($r['response']['classes']),'Lecture and laboratory duplicated the class roster.');
    $r = $request('photo_bootstrap');
    $options = array_column($r['response']['faculties'],null,'faculty_id');
    $check($options[$class['faculty_id']]['profile_image_url']===$r['photo_expected_url'] && $r['photo_expected_url']!=='','The faculty selector did not resolve the linked Professor account image.');
    $check(strpos($r['photo_expected_url'],'/profile_' . $r['photo_user_id'] . '.jpg?v=')!==false,'The profile image URL used a faculty ID rather than its linked account ID.');
    $r = $request('photo_classes');
    $check($r['response']['faculty']['faculty_id']===$class['faculty_id'] && $r['response']['faculty']['profile_image_url']===$r['photo_expected_url'],'The workload response did not return the selected faculty image.');
    foreach (['photo_missing','photo_unlinked','photo_inactive'] as $scenario) {
        $r = $request($scenario);
        $check($r['response']['status']==='ok' && $r['response']['faculty']['profile_image_url']==='','A missing, unlinked or inactive profile image was exposed: ' . $scenario);
    }
    $r = $request('photo_replaced');
    $check($r['photo_before_url']!==$r['photo_expected_url'] && $r['response']['faculty']['profile_image_url']===$r['photo_expected_url'],'Refresh did not version a replaced Professor profile image.');
    $r = $request('photo_other_faculty');
    $check($r['http_status']===422 && !isset($r['response']['faculty']),'An unscoped faculty request exposed profile image details.');
    foreach (['bad_csrf'=>403,'other_college'=>403,'other_role'=>403,'revoked'=>403,'old_term'=>409,'wrong_method'=>405,'array_input'=>400,'unknown_action'=>400,'other_class'=>422,'preview_missing_file'=>422] as $scenario=>$status) {
        $r = $request($scenario);
        $check($r['http_status']===$status,'Request validation failed for ' . $scenario);
        $check($r['before_counts']===$r['after_counts'],'Rejected request wrote student data: ' . $scenario);
    }
    $randomNumber = (string)(3500000000 + random_int(1,10000000));
    $students = [[$randomNumber,'RosterQA, First Middle'],[$randomNumber+1,'RosterQA, Second Middle']];
    $csv = $csvFor($class,$students);
    $parsed = $parseCsv($csv);
    $check(count($parsed['students'])===2,'The CSV parser lost student rows.');
    $duplicateCsv = $csvFor($class,[$students[0],$students[0],$students[1]]);
    $parsed = $parseCsv($duplicateCsv);
    $check(count($parsed['students'])===2 && $parsed['duplicate_rows']===1,'Identical CSV IDs were not deduplicated.');
    $reject(static function () use ($parseCsv,$csvFor,$class,$students) { $parseCsv($csvFor($class,[$students[0],[$students[0][0],'DifferentQA, Person']])); },'Conflicting CSV IDs were accepted.');
    $reject(static function () use ($parseCsv,$csvFor,$class) { $parseCsv($csvFor($class,[['','RosterQA, Name']])); },'Missing IDs were accepted.');
    $reject(static function () use ($parseCsv,$csvFor,$class) { $parseCsv($csvFor($class,[['123abc','RosterQA, Name']])); },'Non-numeric IDs were silently normalized.');
    $reject(static function () use ($parseCsv) { $parseCsv("name,id\nSomeone,123\n"); },'An unrelated CSV format was accepted.');
    $r = $request('preview',$duplicateCsv);
    $check($r['response']['status']==='ok' && $r['response']['preview']['summary']['add']===2 && $r['response']['preview']['summary']['duplicate_rows']===1,'The upload preview does not match parsed rows.');
    $check(strlen($r['response']['preview_token'])===64 && $r['pending_count']===1,'The preview did not issue a server-side token.');
    $check($r['before_counts']===$r['after_counts'],'Preview saved student data before confirmation.');
    $check($r['response']['preview']['rows'][0]['email_address']==='firstrosterqa@sksu.edu.ph' && $r['response']['preview']['rows'][0]['email_status']==='ready','CSV preview did not show the proposed school email.');
    foreach (['subject_code'=>'UNRELATED 999','section_display'=>'UNRELATED 9Z','first_name'=>'DifferentFacultyQA','campus_name'=>'DifferentCampusQA'] as $field=>$value) {
        $other = $class; $other[$field]=$value;
        $r = $request('preview',$csvFor($other,$students));
        $check($r['http_status']===422 && $r['pending_count']===0,'The import accepted a mismatched ' . $field);
        $check(!empty($r['response']['issues'][0]['found']) && !empty($r['response']['issues'][0]['expected'])
            && !empty($r['response']['issues'][0]['fix']),'The mismatch does not explain found, expected and correction: ' . $field);
    }
    $other=$class;$other['subject_code']='WRONG 999';$other['section_display']='WRONG 9Z';
    $other['first_name']='OtherFacultyQA';$other['campus_name']='OtherCampusQA';
    $r=$request('preview',$csvFor($other,$students));
    $check($r['http_status']===422 && count($r['response']['issues'])===4,'Preview must report every header mismatch together.');
    $check(array_column($r['response']['issues'],'row')===[4,4,1,6],'Header diagnostics lost their precise CSV rows.');
    $check($r['pending_count']===0 && $r['before_counts']===$r['after_counts'],'Invalid header diagnostics issued a token or saved data.');
    $r=$request('preview',$csvFor($class,[['1.234E+10','Name without comma'],['ABC','Other without comma']]));
    $check($r['http_status']===422 && count($r['response']['issues'])===4,'Student diagnostics must include multiple rows and fields.');
    $check(array_column($r['response']['issues'],'column')===['B','E','B','E'] && array_column($r['response']['issues'],'row')===[8,8,9,9],
        'Invalid student names and IDs do not identify B8/E8 and B9/E9.');
    $check($r['response']['issues'][1]['found']==='1.234E+10','The diagnostic lost the original invalid ID value.');
    $r=$request('preview',$csvFor($class,[$students[0],[$students[0][0],'DifferentQA, Person']]));
    $issue=$r['response']['issues'][0];
    $check($r['http_status']===422 && $issue['row']===9 && strpos($issue['expected'],'row 8')!==false,
        'A conflicting duplicate ID must identify both CSV rows.');
    $r=$request('preview',$csvFor($class,[[$students[0][0],'Pe??a, Name']]));
    $check($r['http_status']===422 && $r['response']['issues'][0]['row']===8 && $r['response']['issues'][0]['column']==='B'
        && strpos($r['response']['issues'][0]['reason'],'original letters are missing')!==false,
        'Already-lost letters must be reported without guessing a replacement name.');
    $facultyClass=$class;$facultyClass['first_name']='SARIP';$facultyClass['last_name']='PANGSAYAN';$facultyClass['middle_name']='E';$facultyClass['ext_name']='';
    $header=$parseCsv($csvFor($facultyClass,$students));
    foreach (['SARIP PANGSAYAN, MAT','PANGSAYAN, SARIP, M.A.T.','SARIP E. PANGSAYAN, MAT, Ph.D.'] as $name) {
        $header['faculty_name']=$name;
        $check(count(synk_dean_roster_validate_header($header,$facultyClass))>0,'A recognized degree suffix blocked the faculty: ' . $name);
    }
    $header['faculty_name']='DIFFERENT PANGSAYAN, MAT';
    $reject(static function() use($header,$facultyClass) {synk_dean_roster_validate_header($header,$facultyClass);},'Degree handling accepted a different faculty.');
    $facultyClass['first_name']='MAT';$facultyClass['middle_name']='';$header['faculty_name']='PANGSAYAN, MAT';
    $check(count(synk_dean_roster_validate_header($header,$facultyClass))>0,'A real given name must not be removed as a credential.');
    $header['faculty_name']='PANGSAYAN';
    $reject(static function() use($header,$facultyClass) {synk_dean_roster_validate_header($header,$facultyClass);},'Credential handling accepted a surname without the selected given name.');
    $facultyClass['first_name']='GEROLD';$facultyClass['middle_name']='';$facultyClass['last_name']='DELA PEÑA';
    $header['faculty_name']='GEROLD DELA PENA';
    $check(count(synk_dean_roster_validate_header($header,$facultyClass))>0,'Legacy PENA headers do not match a verified PEÑA faculty.');
    $facultyClass['last_name']='DELA PE??A';
    try {synk_dean_roster_validate_header($header,$facultyClass);$check(false,'A damaged faculty record was accepted.');}
    catch(SynkDeanRosterValidationException $e) {$check($e->issues[0]['field']==='Faculty record','The error does not identify database name damage.');}
    $check(!synk_student_identity_names_match(['last_name'=>'Peña','first_name'=>'José'],['last_name'=>'Pena','first_name'=>'José']),
        'Faculty header normalization must not weaken student identity matching.');
    foreach (['confirm_forged','confirm_tampered','confirm_other_user','confirm_expired','confirm_removed','confirm_identity'] as $scenario) {
        $r = $request($scenario,$csv);
        $check($r['http_status']===422,'Confirmation validation failed for ' . $scenario);
        $check($r['before_counts']===$r['after_counts'],'Rejected confirmation wrote data: ' . $scenario);
    }
    $r = $request('confirm',$duplicateCsv);
    $check($r['response']['status']==='ok' && $r['response']['result']['added']===2 && $r['response']['result']['new_profiles']===2,'Confirmed import did not save the two unique students.');
    $check($r['after_counts']['tbl_student_management']-$r['before_counts']['tbl_student_management']===2,'The import created duplicate profiles.');
    $check($r['after_counts']['tbl_student_management_enrolled_subjects']-$r['before_counts']['tbl_student_management_enrolled_subjects']===2,'The import created duplicate class memberships.');
    $check(in_array($randomNumber,array_column($r['roster'],'student_number'),true),'The imported student does not appear in the roster.');
    $check((int)$r['count_map'][$class['offering_id']]===count($r['roster']),'The imported roster did not update workload student counts.');
    $check($r['pending_count']===0,'A confirmed token remained reusable.');
    $check($r['imported_profiles'][0]['email_address']==='firstrosterqa@sksu.edu.ph' && $r['imported_profiles'][1]['email_address']==='secondrosterqa@sksu.edu.ph','New CSV students were saved without generated emails.');
    $r = $request('confirm_existing',$csv);
    $check($r['response']['status']==='ok' && $r['response']['result']['added']===0 && $r['response']['result']['skipped']===2,'Re-import did not skip existing memberships.');
    $check($r['before_counts']===$r['after_counts'],'Re-import changed existing student records.');
    $r = $request('confirm_partial',$csv);
    $check($r['response']['status']==='ok' && $r['response']['result']['added']===1 && $r['response']['result']['skipped']===1,'A partial overlap did not add only the missing student.');
    $r = $request('confirm_profile',$csv);
    $check($r['response']['status']==='ok' && $r['response']['result']['new_profiles']===1 && $r['profile_unchanged']===true,'Matching an ID overwrote the existing student profile.');
    $r=$request('confirm_missing_email',$csv);
    $check($r['response']['status']==='ok' && $r['response']['result']['new_profiles']===1,'Filling a reused student email changed identity reuse.');
    $original=$r['original_profile']; $updated=$r['imported_profiles'][0];
    $check($original['email_address']==='' && $updated['email_address']===synk_student_management_build_email($original['first_name'],$original['last_name']),'A future CSV import did not fill the existing blank email.');
    foreach (['email_address','updated_at'] as $field) { unset($original[$field],$updated[$field]); }
    $check($original==$updated,'Filling a reused email changed other student profile fields.');
    $r=$request('confirm_email_collision',$csv);
    $check($r['response']['status']==='ok' && $r['response']['result']['added']===2 && $r['imported_profiles'][0]['email_address']==='','An email collision blocked class importing or assigned the same email.');
    $r = $request('confirm_failure',$csv);
    $check($r['http_status']===500 && $r['before_counts']===$r['after_counts'],'A failed second row did not roll back the whole import.');
    $r = $request('confirm_multisubject',$csv);
    $check($r['response']['status']==='ok' && $r['response']['result']['new_profiles']===0 && $r['response']['result']['added']===2,'A second subject duplicated the student profiles.');
    $check($r['before_counts']['tbl_student_management']===$r['after_counts']['tbl_student_management'] && $r['shared_profiles_unchanged'],'A second subject changed the original student identity.');
    $check($r['shared_subject_counts']===[2,2],'The same student ID does not have two separate subject memberships.');
    $accentName = 'Peña-Ñuñez, José Niño';
    $accentCsv = $csvFor($class,[['000' . $randomNumber,$accentName],[$randomNumber,'PEÑA-ÑUÑEZ, JOSÉ NIÑO']]);
    $r=$request('preview',$accentCsv);
    $check($r['response']['preview']['can_confirm'] && $r['response']['preview']['rows'][0]['email_status']==='review' && $r['response']['preview']['rows'][1]['email_status']==='review','Same-name students in one CSV were assigned colliding emails.');
    foreach (['UTF-8'=>$accentCsv,'UTF-8 BOM'=>"\xEF\xBB\xBF" . $accentCsv,
        'Windows-1252'=>iconv('UTF-8','Windows-1252',$accentCsv),
        'UTF-16LE'=>"\xFF\xFE" . iconv('UTF-8','UTF-16LE',$accentCsv),
        'UTF-16BE'=>"\xFE\xFF" . iconv('UTF-8','UTF-16BE',$accentCsv)] as $encoding=>$bytes) {
        $r = $request('preview',$bytes);
        $check($r['response']['status']==='ok' && $r['response']['preview']['rows'][0]['student_name_raw']===$accentName,'Accented characters were damaged in the ' . $encoding . ' preview.');
        $check($r['response']['preview']['rows'][0]['student_number']==='000' . $randomNumber,'Leading zeros were lost in the ' . $encoding . ' preview.');
        $r = $request('confirm',$bytes);
        $check($r['response']['status']==='ok' && $r['response']['result']['new_profiles']===2,'The ' . $encoding . ' upload combined two different IDs or failed to save.');
        $roster = array_column($r['roster'],null,'student_number');
        $check(($roster['000' . $randomNumber]['student_name'] ?? '')===$accentName
            && ($roster[$randomNumber]['student_name'] ?? '')==='PEÑA-ÑUÑEZ, JOSÉ NIÑO','Accented characters were damaged between database storage and roster display.');
    }
    $decomposed = str_replace(['ñ','Ñ'],["n\u{0303}","N\u{0303}"],$accentCsv);
    $r=$request('confirm',$decomposed);
    $roster=array_column($r['roster'],null,'student_number');
    $check(($roster['000' . $randomNumber]['student_name'] ?? '')===$accentName,'Combining marks were not preserved as the displayed accented name.');
    $misencoded=iconv('Windows-1252','UTF-8',$accentCsv);
    $r=$request('preview',$misencoded);
    $check($r['response']['status']==='ok' && $r['response']['preview']['rows'][0]['student_name_raw']===$accentName,
        'Recoverable Windows-1252/UTF-8 mojibake was not repaired in preview.');
    $check(strpos(implode(' ',$r['response']['preview']['notes']),'Recovered misencoded')!==false,'Encoding repairs must be disclosed in preview.');
    $r=$request('confirm',$misencoded);$roster=array_column($r['roster'],null,'student_number');
    $check(($roster['000'.$randomNumber]['student_name'] ?? '')===$accentName,'Repaired accented names did not survive database storage.');
    // Exercise the three supplied files without storing their real students.
    $folder = 'C:/Users/CCS-Dean/Downloads/FACULTY (1)/ANTONIO ELBREN/';
    foreach (['CS 111 - CCS - BSCS 1A.csv'=>[944,39],'CS ELECT 311 - BSCS 3A.csv'=>[958,36],'CS RES 400A - BSCS 4A.csv'=>[964,23]] as $file=>$expected) {
        if (!is_file($folder . $file)) continue;
        $parsed = synk_dean_roster_parse($folder . $file);
        $check(count($parsed['students'])===$expected[1] && $parsed['duplicate_rows']===0,'The supplied CSV parser count changed: ' . $file);
        $knownScope = $conn->query('SELECT c.college_id,c.college_code,c.college_name,cp.campus_id,cp.campus_name FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.college_id=1')->fetch_assoc();
        $knownScope['college_id']=(int)$knownScope['college_id']; $knownScope['campus_id']=(int)$knownScope['campus_id'];
        $knownClass = synk_dean_workload_class($conn,$knownScope,synk_fetch_current_academic_term($conn),411,$expected[0]);
        $preview = synk_dean_roster_preview($conn,$knownClass,$parsed);
        $check($preview['summary']['students']===$expected[1],'The supplied CSV cannot preview against its assigned class: ' . $file);
        echo $file, ': ', $expected[1], ' students; ', $preview['summary']['conflicts'], ' identity conflicts.', PHP_EOL;
    }
    $check($snapshot()===$before,'Workload tests changed real records.');
    echo 'College Dean workload HTTP: ', $checks, ' checks passed on PHP ', $version, '. All fixture records rolled back.', PHP_EOL;
} finally { if (is_file($routePath)) unlink($routePath); }
