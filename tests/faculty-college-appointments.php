<?php
// php tests/faculty-college-appointments.php -- all data fixtures are rolled back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/college_dean_profile_helper.php';
require_once __DIR__ . '/../backend/college_dean_statistics_helper.php';
synk_faculty_college_appointments_ensure($conn);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    if (!$ok) throw new RuntimeException($message);
    $checks++;
};
$reject = static function (callable $action, int $status, string $message) use ($check): void {
    try { $action(); } catch (DomainException $exception) { $check($exception->getCode() === $status, $message . ': wrong status'); return; }
    throw new RuntimeException($message . ': accepted');
};
$tables = 'tbl_faculty_college_appointments,tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_faculty,tbl_college,tbl_program,tbl_college_faculty,tbl_faculty_term_designations,tbl_faculty_profiles,tbl_faculty_record_profiles';
$snapshot = static function () use ($conn, $tables): array { return $conn->query('CHECKSUM TABLE ' . $tables)->fetch_all(MYSQLI_ASSOC); };
$before = $snapshot();
$conn->begin_transaction();
try {
    $term = synk_fetch_current_academic_term($conn);
    $otherTerm = ['ay_id'=>$term['ay_id'], 'semester'=>$term['semester'] === 1 ? 2 : 1];
    $campuses = array_map('intval', array_column($conn->query("SELECT campus_id FROM tbl_campus WHERE status='active' ORDER BY campus_id LIMIT 2")->fetch_all(MYSQLI_ASSOC), 'campus_id'));
    if (count($campuses) < 2 || !$term['ay_id']) throw new RuntimeException('Two active campuses and a current term are required.');
    $tag = 'CollegeAppointmentQA' . bin2hex(random_bytes(7));
    $colleges = [];
    foreach (['A','B'] as $index=>$code) {
        $conn->query("INSERT INTO tbl_college (campus_id,college_code,college_name,status) VALUES ({$campuses[$index]},'QA{$code}','{$tag}{$code}','active')");
        $colleges[$code] = (int)$conn->insert_id;
    }
    $programs = [];
    foreach (['A1'=>'A','A2'=>'A','B1'=>'B'] as $code=>$college) {
        $conn->query("INSERT INTO tbl_program (college_id,program_code,program_name,status) VALUES ({$colleges[$college]},'{$code}','{$tag}{$code}','active')");
        $programs[$code] = (int)$conn->insert_id;
    }
    $conn->query("INSERT INTO tbl_designation (designation_name,status) VALUES ('DEAN / PROGRAM CHAIRMAN {$tag}','active')");
    $designationId = (int)$conn->insert_id;
    $faculty = [];
    foreach (['deanA'=>'A','deanB'=>'A','chairA'=>'B','dual'=>'A','unverified'=>'A','teacher'=>'B','unrelated'=>'B','old'=>'B','inactive_roster'=>'B','on_leave'=>'A','retired'=>'A'] as $key=>$home) {
        $campusId = $campuses[$home === 'A' ? 0 : 1];
        $status = in_array($key, ['on_leave','retired'], true) ? $key : 'active';
        $conn->query("INSERT INTO tbl_faculty (first_name,last_name,employment_classification,designation_id,status,campus_id,college_id)
            VALUES ('{$key}','{$tag}','permanent',{$designationId},'{$status}',{$campusId},{$colleges[$home]})");
        $faculty[$key] = (int)$conn->insert_id;
    }
    $roster = static function (string $key, string $college, array $viewTerm, string $status = 'active') use ($conn, $faculty, $colleges): void {
        $conn->query("INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status)
            VALUES ({$colleges[$college]},{$faculty[$key]},{$viewTerm['ay_id']},{$viewTerm['semester']},'{$status}')");
    };
    $roster('deanA','B',$term);
    $roster('teacher','A',$term);
    $roster('old','A',$otherTerm);
    $roster('inactive_roster','A',$term,'inactive');
    $conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_dean_college_id)
        VALUES ('{$tag}','{$tag}@example.test','unusable-test-password','admin','active',{$colleges['A']})");
    $actorId = (int)$conn->insert_id;
    synk_persist_useraccount_roles($conn,$actorId,['admin','college_dean'],'admin');
    $_SESSION = ['user_id'=>$actorId, 'role'=>'admin', 'faculty_appointment_csrf'=>$tag];
    $base = ['csrf_token'=>$tag,'college_id'=>$colleges['A'],'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],
        'appointment_id'=>0,'revision'=>0,'action'=>'save','appointment_role'=>'dean','program_id'=>0,'faculty_id'=>$faculty['deanA']];
    $save = static function (array $changes) use ($conn,$base): void { synk_faculty_appointment_save($conn, array_replace($base,$changes)); };
    $save([]);
    $save(['college_id'=>$colleges['B'],'faculty_id'=>$faculty['deanB']]);
    $save(['appointment_role'=>'chair','program_id'=>$programs['A1'],'faculty_id'=>$faculty['chairA']]);
    $save(['appointment_role'=>'chair','program_id'=>$programs['A2'],'faculty_id'=>$faculty['chairA']]);
    $save(['appointment_role'=>'chair','program_id'=>$programs['B1'],'college_id'=>$colleges['B'],'faculty_id'=>$faculty['deanB']]);
    $save(['semester'=>$otherTerm['semester'],'faculty_id'=>$faculty['dual']]);
    $scopeA = ['college_id'=>$colleges['A'],'campus_id'=>$campuses[0]];
    $scopeB = ['college_id'=>$colleges['B'],'campus_id'=>$campuses[1]];
    $rows = static function (array $scope, array $viewTerm, bool $includeTeaching = true) use ($conn): array {
        return array_column(synk_dean_profile_faculties($conn,$scope,$viewTerm,0,false,$includeTeaching),null,'faculty_id');
    };
    $a = $rows($scopeA,$term); $b = $rows($scopeB,$term);
    $check($a[$faculty['deanA']]['designation_group']==='dean' && $b[$faculty['deanA']]['designation_group']==='faculty', 'A Dean is leadership only in the appointed college and Faculty where teaching.');
    $check($a[$faculty['deanB']]['designation_group']==='faculty' && $b[$faculty['deanB']]['designation_group']==='dean', 'A home faculty member appointed Dean elsewhere is Faculty at home and Dean in the appointment college.');
    $check($a[$faculty['chairA']]['designation_group']==='chair' && $b[$faculty['chairA']]['designation_group']==='faculty', 'A cross-campus chair with no teaching roster appears in the appointment college.');
    $check($a[$faculty['chairA']]['home_college_name']===$tag.'B', 'An appointment preserves the personal profile home-college defaults.');
    $check(strpos($a[$faculty['chairA']]['designation_name'],'A1')!==false && strpos($a[$faculty['chairA']]['designation_name'],'A2')!==false, 'One Chair card lists multiple programs.');
    $check($b[$faculty['deanB']]['designation_name']==='College Dean' && count(array_filter($b,static function($row){return $row['designation_group']==='dean';}))===1, 'Dean takes precedence over Chair without duplicating the card.');
    $check($a[$faculty['unverified']]['designation_group']==='faculty' && $a[$faculty['unverified']]['designation_name']==='Faculty member', 'An unscoped legacy leadership title neither promotes the card nor labels it Dean.');
    $check(isset($a[$faculty['teacher']]) && !$a[$faculty['teacher']]['can_manage_profile'], 'A verified cross-campus teaching membership supplies a reference card.');
    $manageable = $rows($scopeA,$term,false);
    $check(!isset($manageable[$faculty['teacher']]) && isset($manageable[$faculty['chairA']]), 'Teaching membership does not expand editing authority; an appointment supplies management scope.');
    $check(!isset($a[$faculty['unrelated']]) && !isset($a[$faculty['old']]) && !isset($a[$faculty['inactive_roster']]), 'Unrelated, old-roster and inactive-roster faculty stay outside the directory.');
    $check(count($a)===count(array_unique(array_column($a,'faculty_id'))), 'Faculty have one card despite multiple appointments and roster sources.');
    $history = $rows($scopeA,$otherTerm);
    $check($history[$faculty['dual']]['designation_group']==='dean' && $history[$faculty['deanA']]['designation_group']==='faculty', 'Selected-term appointments do not leak between terms.');
    $check(isset($history[$faculty['old']]) && !isset($history[$faculty['teacher']]), 'Teaching membership also follows the selected term.');
    $check(synk_dean_faculty_statistics($conn,$scopeA,$otherTerm)['faculty']===2, 'Historical headcounts include the appointed leader even without a teaching roster.');
    $check($rows($scopeA,$term)===$a, 'Reading another term does not change current placements.');
    $reject(static function()use($save){$save([]);},409,'Duplicate Dean posts are rejected');
    $reject(static function()use($save,$programs){$save(['appointment_role'=>'chair','program_id'=>$programs['B1']]);},422,'A Chair program must belong to the selected college');
    $reject(static function()use($save){$save(['appointment_role'=>'chair']);},422,'A Chair requires a program');
    $reject(static function()use($save,$programs){$save(['program_id'=>$programs['A1']]);},422,'A Dean post cannot name a program');
    $reject(static function()use($save,$faculty){$save(['faculty_id'=>$faculty['retired']]);},422,'Retired faculty cannot receive new appointments');
    $reject(static function()use($save){$save(['faculty_id'=>4294967295]);},422,'Unknown faculty are rejected');
    $reject(static function()use($save){$save(['college_id'=>4294967295]);},422,'Unknown colleges are rejected');
    $reject(static function()use($save){$save(['ay_id'=>4294967295]);},422,'Unknown academic years are rejected');
    $reject(static function()use($save){$save(['semester'=>4]);},422,'Invalid semesters are rejected');
    $reject(static function()use($save){$save(['college_id'=>['malformed']]);},422,'Array identifiers are rejected');
    $reject(static function()use($save){$save(['appointment_role'=>['dean']]);},422,'Array roles are rejected');
    $reject(static function()use($save){$save(['csrf_token'=>'wrong']);},403,'Invalid CSRF is rejected');
    $reject(static function()use($save){$save(['csrf_token'=>['malformed']]);},403,'Array CSRF is rejected');
    $dean = array_values(array_filter(synk_faculty_college_appointments($conn,$colleges['A'],$term),static function($row){return $row['appointment_role']==='dean';}))[0];
    $edit = ['appointment_id'=>(int)$dean['appointment_id'],'revision'=>(int)$dean['revision'],'faculty_id'=>$faculty['on_leave']];
    $save($edit);
    $check($rows($scopeA,$term)[$faculty['on_leave']]['designation_group']==='dean', 'A replacement accepts an on-leave faculty member and moves the previous holder to Faculty.');
    $check($rows($scopeA,$term)[$faculty['deanA']]['designation_group']==='faculty', 'Replacing a Dean removes the previous holder from leadership in that term.');
    $reject(static function()use($save,$edit){$save($edit);},409,'Stale replacements are rejected');
    $reject(static function()use($save,$edit,$colleges){$save(array_replace($edit,['college_id'=>$colleges['B']]));},409,'Appointment identity cannot cross college scope');
    $reject(static function()use($save,$edit,$otherTerm){$save(array_replace($edit,['semester'=>$otherTerm['semester']]));},409,'Appointment identity cannot cross term scope');
    $save(array_replace($edit,['revision'=>2,'action'=>'end']));
    $check(count(array_filter($rows($scopeA,$term),static function($row){return $row['designation_group']==='dean';}))===0, 'Ending an appointment removes leadership without deleting the faculty card.');
    $save(array_replace($edit,['revision'=>3]));
    $check($rows($scopeA,$term)[$faculty['on_leave']]['designation_group']==='dean', 'Restoring an ended appointment restores leadership.');
    $reject(static function()use($save,$edit){$save(array_replace($edit,['revision'=>3,'action'=>'end']));},409,'Stale end operations are rejected');
    foreach (['college_dean','program_chair','professor'] as $role) {
        $_SESSION['role']=$role;
        $reject(static function()use($save){$save([]);},403,'Only an administrator may maintain appointments: '.$role);
    }
    $_SESSION['role']='admin';
    $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$actorId} AND role='admin'");
    $reject(static function()use($save){$save([]);},403,'Revoked administrator access is checked against current records');
    $conn->query("UPDATE tbl_useraccount_roles SET status='active' WHERE user_id={$actorId} AND role='admin'");
    $check(synk_fetch_useraccount_role_rows($conn,$actorId,'admin')===[['role'=>'admin','is_primary'=>true,'status'=>'active'],['role'=>'college_dean','is_primary'=>false,'status'=>'active']], 'Appointment changes preserve login roles.');
    $check($conn->query("SELECT designation_id,college_id FROM tbl_faculty WHERE faculty_id={$faculty['chairA']}")->fetch_assoc()===['designation_id'=>(string)$designationId,'college_id'=>(string)$colleges['B']], 'Appointment changes preserve workload designation and permanent affiliation.');
    $check($rows($scopeA,$otherTerm)[$faculty['dual']]['designation_group']==='dean', 'Replacing and ending current appointments preserve history.');
} finally {
    $conn->rollback();
    $_SESSION = [];
}
$check($snapshot()===$before,'Every data fixture was rolled back; real records are unchanged.');
$root=dirname(__DIR__);
$token=bin2hex(random_bytes(24));
$route=$root.'/tmp/college-appointment-'.bin2hex(random_bytes(8)).'.php';
file_put_contents($route,'<?php define(\'SYNK_COLLEGE_APPOINTMENT_QA\','.var_export($token,true).'); require '.var_export(__DIR__.'/fixtures/faculty-college-appointments-request.php',true).';');
$request=static function(string $scenario, ?array $post=null, bool $authorized=true)use($route,$token):array {
    $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$authorized?$token:'wrong','scenario'=>$scenario]));
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>20]);
    if($post!==null) curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post]);
    $raw=curl_exec($curl);if($raw===false)throw new RuntimeException(curl_error($curl));
    $size=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    return ['status'=>$status,'headers'=>substr($raw,0,$size),'body'=>substr($raw,$size)];
};
try {
    $check($request('current',null,false)['status']===404,'HTTP fixtures reject an invalid route token.');
    foreach(['current','historical','edit'] as $scenario) {
        $r=$request($scenario);
        $check($r['status']===200 && strpos($r['body'],'<h1 class="h4">Leadership appointments</h1>')!==false
            && !preg_match('/Fatal error|Parse error|<b>Warning<\/b>|xdebug-error/i',$r['body']), 'Administrator page renders cleanly: '.$scenario.' HTTP '.$r['status'].' '.substr(strip_tags($r['body']),0,180));
        $check(strpos($r['headers'],'X-QA-Other-Records: unchanged')!==false,'Viewing appointments preserves accounts, designations, profiles and signatories: '.$scenario);
        if($scenario==='historical') $check(strpos($r['body'],'<p class="mb-1">Historical Dean Appointment QA</p>')!==false
            && strpos($r['body'],'<p class="mb-1">Current Dean Appointment QA</p>')===false,'The real administrator page reads only the selected term appointments.');
        if($scenario==='edit') $check(strpos($r['body'],'Replace or restore appointment')!==false && preg_match('/id="appointment-role"[^>]*disabled/',$r['body'])===1,'Replacement forms keep the original leadership post fixed.');
    }
    foreach(['denied'=>403,'revoked'=>403,'foreign_edit'=>404,'array_scope'=>400] as $scenario=>$status) {
        $r=$request($scenario);
        $check($r['status']===$status,'HTTP access or scope denial: '.$scenario.' HTTP '.$r['status']);
    }
    foreach(['save'=>'Current%20Dean','replace'=>'Replacement%20Faculty','end'=>'none','restore'=>'Replacement%20Faculty'] as $scenario=>$deanName) {
        $r=$request($scenario,['csrf_token'=>$token]);
        $check($r['status']===303 && strpos($r['headers'],'Location: faculty-appointments.php?')!==false,'Successful administrator POST uses a redirect: '.$scenario.' HTTP '.$r['status'].' '.substr(strip_tags($r['body']),0,200));
        $check(strpos($r['headers'],'X-QA-Dean-Name: '.$deanName)!==false,'The actual POST saved the intended appointment state: '.$scenario);
        $check(strpos($r['headers'],'X-QA-Other-Records: unchanged')!==false,'Appointment POST preserves login roles, affiliations, designations and signatories: '.$scenario);
        if($scenario==='save') $check(strpos($r['headers'],'X-QA-Active-Appointments: 2')!==false,'The administrator can record a separate Program Chair post.');
    }
    foreach(['bad_csrf'=>403,'duplicate'=>409,'wrong_program'=>422,'stale'=>409] as $scenario=>$status) {
        $r=$request($scenario,['csrf_token'=>$scenario==='bad_csrf'?'wrong':$token]);
        $check($r['status']===$status && strpos($r['headers'],'X-QA-Active-Appointments: 1')!==false
            && strpos($r['headers'],'X-QA-Dean-Name: Current%20Dean')!==false,'Rejected administrator POST preserves the current appointment: '.$scenario.' HTTP '.$r['status']);
    }
} finally { if(is_file($route)) unlink($route); }
$check($snapshot()===$before,'Every HTTP fixture was rolled back; real records are unchanged.');
echo 'Faculty college appointments: ', $checks, ' checks passed. Fixtures rolled back.', PHP_EOL;
