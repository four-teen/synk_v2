<?php
// Temporary loopback routes only; all fixtures and form saves are rolled back.
if (!defined('SYNK_DEAN_PROFILE_QA') || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(SYNK_DEAN_PROFILE_QA, $_GET['qa'])) {
    http_response_code(404); exit;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__, 2);
require_once $root . '/backend/db.php';
require_once $root . '/backend/college_dean_profile_helper.php';
require_once $root . '/backend/faculty_directory_helper.php';
require_once $root . '/backend/di_profile_analytics_helper.php';
require_once $root . '/backend/faculty_profile_excel_helper.php';
class DeanProfileQAConnection extends mysqli
{
    public $requestMode = false;
    #[\ReturnTypeWillChange]
    public function query($sql, $resultMode=MYSQLI_STORE_RESULT) {
        if ($this->requestMode && preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $sql)) throw new RuntimeException('Schema changes are forbidden inside fixtures.');
        return parent::query($sql, $resultMode);
    }
    #[\ReturnTypeWillChange]
    public function begin_transaction($flags=0, $name=null) {
        return $this->requestMode ? $this->query('SAVEPOINT dean_profile_request') : ($name === null ? parent::begin_transaction($flags) : parent::begin_transaction($flags, $name));
    }
    #[\ReturnTypeWillChange]
    public function commit($flags=0, $name=null) {
        return $this->requestMode ? $this->query('RELEASE SAVEPOINT dean_profile_request') : ($name === null ? parent::commit($flags) : parent::commit($flags, $name));
    }
    #[\ReturnTypeWillChange]
    public function rollback($flags=0, $name=null) {
        return $this->requestMode ? $this->query('ROLLBACK TO SAVEPOINT dean_profile_request') : ($name === null ? parent::rollback($flags) : parent::rollback($flags, $name));
    }
}
$conn->close();
$conn = new DeanProfileQAConnection($servername, $username, $password, $dbase);
$conn->set_charset('utf8mb4');
$conn->begin_transaction();
$scenario = (string)($_GET['scenario'] ?? 'directory');
$campusId = (int)$conn->query("SELECT campus_id FROM tbl_campus WHERE status='active' ORDER BY campus_id LIMIT 1")->fetch_assoc()['campus_id'];
$tag = 'DeanProfileQA' . bin2hex(random_bytes(6));
$conn->query("INSERT INTO tbl_college (campus_id,college_code,college_name,status) VALUES ({$campusId},'QA','Faculty Profile QA College','active')");
$collegeId = (int)$conn->insert_id;
$conn->query("INSERT INTO tbl_college (campus_id,college_code,college_name,status) VALUES ({$campusId},'QAF','Foreign Profile QA College','active')");
$foreignCollege = (int)$conn->insert_id;
$conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_dean_college_id) VALUES ('Dean Profile QA','{$tag}@sksu.edu.ph','unusable-test-password','college_dean','active',{$collegeId})");
$actorId = (int)$conn->insert_id;
synk_persist_useraccount_roles($conn, $actorId, ['college_dean'], 'college_dean');
$conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_id) VALUES ('Faculty Profile QA','faculty{$tag}@sksu.edu.ph','unusable-test-password','professor','active',{$collegeId})");
$accountId = (int)$conn->insert_id;
synk_persist_useraccount_roles($conn, $accountId, ['professor'], 'professor');
$term = synk_fetch_current_academic_term($conn);
$faculty = [];
foreach (['dean'=>'COLLEGE DEAN & PROGRAM CHAIRPERSON', 'chair'=>'PROGRAM CHAIRMAN', 'chair2'=>'PROGRAM CHAIRPERSON',
    'record'=>'Instructor', 'linked'=>'Instructor', 'faculty2'=>'Instructor', 'faculty3'=>'Instructor', 'faculty4'=>'Instructor',
    'archived'=>'Instructor', 'foreign'=>'DEAN', 'roster'=>'Instructor', 'old_roster'=>'Instructor'] as $key=>$designation) {
    $designation .= ' ' . $tag . $key;
    $stmt=$conn->prepare("INSERT INTO tbl_designation (designation_name,status) VALUES (?,'active')");
    $stmt->bind_param('s',$designation);$stmt->execute();$designationId=(int)$stmt->insert_id;$stmt->close();
    $status=$key==='archived' ? 'retired' : 'active';
    $home=$key==='foreign' ? $foreignCollege : (in_array($key,['roster','old_roster'],true) ? 0 : $collegeId);
    $first=['dean'=>'Elena','chair'=>'Marco','chair2'=>'Andrea','record'=>'José','linked'=>'Maria','faculty2'=>'Antonio','faculty3'=>'Isabel','faculty4'=>'Luis'][$key] ?? ucfirst($key);
    $last='QA ' . ucfirst($key);
    $stmt=$conn->prepare('INSERT INTO tbl_faculty (first_name,last_name,employment_classification,designation_id,status,campus_id,college_id) VALUES (?,?,\'permanent\',?,?,?,?)');
    $stmt->bind_param('ssisii',$first,$last,$designationId,$status,$campusId,$home);$stmt->execute();$faculty[$key]=(int)$stmt->insert_id;$stmt->close();
    if (in_array($key,['foreign','roster','old_roster'],true)) {
        $ay=$key==='old_roster' ? max(1,$term['ay_id']-1) : $term['ay_id'];
        $conn->query("INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status) VALUES ({$collegeId},{$faculty[$key]},{$ay},{$term['semester']},'active')");
    }
}
$conn->query("INSERT INTO tbl_program (college_id,program_code,program_name,status) VALUES ({$collegeId},'QAC1','Profile QA Program One','active')");
$chairProgram1=(int)$conn->insert_id;
$conn->query("INSERT INTO tbl_program (college_id,program_code,program_name,status) VALUES ({$collegeId},'QAC2','Profile QA Program Two','active')");
$chairProgram2=(int)$conn->insert_id;
$conn->query("INSERT INTO tbl_faculty_college_appointments (college_id,ay_id,semester,appointment_role,program_id,faculty_id) VALUES
    ({$collegeId},{$term['ay_id']},{$term['semester']},'dean',0,{$faculty['dean']}),
    ({$collegeId},{$term['ay_id']},{$term['semester']},'chair',{$chairProgram1},{$faculty['chair']}),
    ({$collegeId},{$term['ay_id']},{$term['semester']},'chair',{$chairProgram2},{$faculty['chair2']}),
    ({$foreignCollege},{$term['ay_id']},{$term['semester']},'dean',0,{$faculty['foreign']})");
$conn->query("INSERT INTO tbl_useraccount_faculty_links (user_id,faculty_id) VALUES ({$accountId},{$faculty['linked']})");
$seed=synk_faculty_profile_defaults(['username'=>'Maria QA Linked','campus_name'=>'','college_name'=>''],null,'permanent');
synk_faculty_profile_save($conn,$accountId,$seed,0);
$targetId=$faculty['record'];
$owner='faculty:' . $targetId;
if (strpos($scenario,'linked_')===0) {$targetId=$faculty['linked'];$owner='account:' . $accountId;}
if (in_array($scenario,['record_update','stale','record_linked','professor_get','professor_save','professor_owner'],true)) {
    $seed['full_name']='José QA Record';
    synk_faculty_record_profile_save($conn,$faculty['record'],$seed,0,$actorId);
}
if (in_array($scenario,['record_linked','professor_get','professor_save','professor_owner'],true)) {
    $conn->query('DELETE FROM tbl_faculty_profiles WHERE user_id=' . $accountId);
    $conn->query('UPDATE tbl_useraccount_faculty_links SET faculty_id=' . $faculty['record'] . ' WHERE user_id=' . $accountId);
}
if ($scenario==='foreign') $targetId=$faculty['foreign'];
if ($scenario==='old_roster') $targetId=$faculty['old_roster'];
if ($scenario==='roster') {$targetId=$faculty['roster'];$owner='faculty:' . $targetId;}
session_save_path($root . '/temp');
session_id('deanprofileqa' . bin2hex(random_bytes(12)));
session_start();
$_SESSION=['user_id'=>$actorId,'role'=>'college_dean','dean_faculty_profile_csrf'=>SYNK_DEAN_PROFILE_QA];
if (strpos($scenario,'professor_')===0) $_SESSION=['user_id'=>$accountId,'role'=>'professor','faculty_profile_csrf'=>SYNK_DEAN_PROFILE_QA];
if ($scenario==='wrong_role') $_SESSION['role']='professor';
if ($scenario==='revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$actorId} AND role='college_dean'");
if ($scenario==='inactive') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$actorId}");
if ($scenario==='reassigned') $conn->query("UPDATE tbl_useraccount SET college_dean_college_id={$foreignCollege} WHERE user_id={$actorId}");
$_GET['faculty_id']=$scenario==='bad_id' ? ['malformed'] : (string)$targetId;
$uploaded=$_SERVER['REQUEST_METHOD']==='POST';
if ($uploaded) {
    $_POST['profile_owner']=in_array($scenario,['bad_owner','professor_owner'],true) ? 'faculty:999999' : $owner;
    if ($scenario==='bad_csrf') $_POST['csrf_token']='invalid';
}
$tables=['tbl_faculty_record_profiles','tbl_faculty_profiles','tbl_faculty_profile_eligibilities','tbl_faculty_education_options'];
$snapshot=static function() use($conn,$tables):array {
    $rows=[];foreach($tables as $table)$rows[$table]=hash('sha256',json_encode($conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));return $rows;
};
$before=$snapshot();
$imagePath=synk_faculty_record_image_path($targetId);
$userImage=synk_faculty_profile_image_path($accountId);
ob_start(static function($html) {return strpos($html,'<head>')!==false ? str_replace('<head>','<head><base href="http://localhost/synk/college-dean/">',$html) : $html;});
register_shutdown_function(static function() use($conn,$snapshot,$before,$targetId,$accountId,$actorId,$faculty,$imagePath,$userImage,$root):void {
    try {
        $row=synk_faculty_record_profile_fetch($conn,$targetId);
        $account=synk_faculty_profile_fetch($conn,$accountId);
        $directory=synk_faculty_directory_records($conn);
        $record=$directory['faculty'][$targetId] ?? [];
        $progress=synk_di_faculty_profile_completion_by_faculty($conn,$directory);
        $labels=synk_faculty_excel_eligibilities($conn,[$record]);
        header('X-QA-Changed: '.($snapshot()===$before ? 'no' : 'yes'));
        header('X-QA-Record-Revision: '.(int)($row['revision'] ?? 0));
        header('X-QA-Account-Revision: '.(int)($account['revision'] ?? 0));
        header('X-QA-Account-Storage: '.($account['_storage'] ?? 'account'));
        header('X-QA-Faculty-ID: '.$targetId);
        header('X-QA-Directory-Name: '.rawurlencode($record['profile']['full_name'] ?? ''));
        header('X-QA-Completion: '.($progress[$targetId] ?? 'none'));
        header('X-QA-Credential-Count: '.count($labels['faculty:'.$targetId] ?? []));
        header('X-QA-Photo: '.((is_file($imagePath)||is_file($userImage)) ? 'yes' : 'no'));
        $photoInfo = is_file($imagePath) ? getimagesize($imagePath) : (is_file($userImage) ? getimagesize($userImage) : null);
        if ($photoInfo) header('X-QA-Photo-Size: '.$photoInfo[0].'x'.$photoInfo[1]);
        $savedImagePath = is_file($imagePath) ? $imagePath : $userImage;
        header('X-QA-Photo-Readable: '.(is_file($savedImagePath) && (fileperms($savedImagePath)&0044)===0044 ? 'yes' : 'no'));
    } finally {
        $conn->requestMode=false;$conn->rollback();
        foreach ([$imagePath,$userImage] as $path) if (is_file($path)) unlink($path);
        if (session_status()===PHP_SESSION_ACTIVE) session_destroy();
    }
});
session_write_close();
$conn->requestMode=true;
$handler=$scenario==='directory' ? 'faculty-profile.php' : ($scenario==='education' ? 'education-options.php' : 'manage-faculty-profile.php');
if (strpos($scenario,'professor_')===0) {
    $_SERVER['PHP_SELF']='/synk/professor/manage-profile.php';
    chdir($root . '/professor');
    require $root . '/professor/manage-profile.php';
    exit;
}
$_SERVER['PHP_SELF']='/synk/college-dean/' . $handler;
chdir($root . '/college-dean');
require $root . '/college-dean/' . $handler;
