<?php
// Loopback-only fixture for the real administrator page. Every write rolls back.
if (!defined('SYNK_COLLEGE_APPOINTMENT_QA') || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(SYNK_COLLEGE_APPOINTMENT_QA,$_GET['qa'])) {
    http_response_code(404); exit;
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__,2);
require_once $root . '/backend/db.php';
require_once $root . '/backend/college_dean_profile_helper.php';
class CollegeAppointmentQAConnection extends mysqli
{
    #[\ReturnTypeWillChange]
    public function query($sql, $resultMode=MYSQLI_STORE_RESULT) {
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i',$sql)) throw new RuntimeException('Fixture requests must not change the schema.');
        return parent::query($sql,$resultMode);
    }
}
$conn->close();
$conn = new CollegeAppointmentQAConnection($servername,$username,$password,$dbase);
$conn->set_charset('utf8mb4');
$conn->begin_transaction();
ob_start(static function(string $html):string { return str_replace('<head>','<head><base href="http://localhost/synk/administrator/">',$html); });
register_shutdown_function(static function() use($conn):void {
    try { if (!empty($GLOBALS['appointment_qa_ready'])) appointment_qa_capture(); }
    finally { $conn->rollback(); if(session_status()===PHP_SESSION_ACTIVE) session_destroy(); }
});
$scenario = is_string($_GET['scenario'] ?? null) ? $_GET['scenario'] : 'current';
$tag = 'AppointmentWebQA' . bin2hex(random_bytes(8));
$campus = (int)$conn->query("SELECT campus_id FROM tbl_campus WHERE status='active' ORDER BY campus_id LIMIT 1")->fetch_assoc()['campus_id'];
$conn->query("INSERT INTO tbl_college (campus_id,college_code,college_name,status) VALUES ({$campus},'QAA','Appointment QA College','active')");
$collegeId = (int)$conn->insert_id;
$conn->query("INSERT INTO tbl_college (campus_id,college_code,college_name,status) VALUES ({$campus},'QAB','Appointment Foreign College','active')");
$foreignCollege = (int)$conn->insert_id;
$programs = [];
foreach ([$collegeId,$foreignCollege] as $i=>$college) {
    $conn->query("INSERT INTO tbl_program (college_id,program_code,program_name,status) VALUES ({$college},'QAP{$i}','QA Program {$i}','active')");
    $programs[] = (int)$conn->insert_id;
}
$faculties = [];
foreach (['Current Dean','Historical Dean','Replacement Faculty'] as $name) {
    $conn->query("INSERT INTO tbl_faculty (first_name,last_name,employment_classification,status,campus_id,college_id)
        VALUES ('{$name}','Appointment QA','permanent','active',{$campus},{$collegeId})");
    $faculties[] = (int)$conn->insert_id;
}
$conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_id,college_dean_college_id)
    VALUES ('Appointment QA Admin','{$tag}@example.test','unusable-test-password','admin','active',{$collegeId},{$collegeId})");
$actorId = (int)$conn->insert_id;
synk_persist_useraccount_roles($conn,$actorId,['admin','college_dean','program_chair'],'admin');
$term = synk_fetch_current_academic_term($conn);
$otherSemester = $term['semester'] === 1 ? 2 : 1;
$conn->query("INSERT INTO tbl_faculty_college_appointments (college_id,ay_id,semester,appointment_role,program_id,faculty_id) VALUES
    ({$collegeId},{$term['ay_id']},{$term['semester']},'dean',0,{$faculties[0]}),
    ({$collegeId},{$term['ay_id']},{$otherSemester},'dean',0,{$faculties[1]})");
$deanId = (int)$conn->query("SELECT appointment_id FROM tbl_faculty_college_appointments WHERE college_id={$collegeId} AND semester={$term['semester']}")->fetch_assoc()['appointment_id'];
if ($scenario === 'restore') $conn->query("UPDATE tbl_faculty_college_appointments SET status='inactive' WHERE appointment_id={$deanId}");
session_save_path($root . '/temp'); session_id('appointmentqa' . bin2hex(random_bytes(12))); session_start();
$_SESSION = ['user_id'=>$actorId,'role'=>'admin','faculty_appointment_csrf'=>SYNK_COLLEGE_APPOINTMENT_QA];
if ($scenario === 'denied') $_SESSION['role']='college_dean';
if ($scenario === 'revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$actorId} AND role='admin'");
$selectedSemester = $scenario === 'historical' ? $otherSemester : $term['semester'];
$_GET['college_id']=(string)$collegeId; $_GET['ay_id']=(string)$term['ay_id']; $_GET['semester']=(string)$selectedSemester;
if ($scenario === 'array_scope') $_GET['college_id']=['malformed'];
if ($scenario === 'edit') $_GET['edit']=(string)$deanId;
if ($scenario === 'foreign_edit') { $_GET['college_id']=(string)$foreignCollege; $_GET['edit']=(string)$deanId; }
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $_POST = array_replace($_POST,['college_id'=>(string)$collegeId,'ay_id'=>(string)$term['ay_id'],'semester'=>(string)$term['semester'],
        'faculty_id'=>(string)$faculties[2],'appointment_id'=>'0','revision'=>'0','action'=>'save','appointment_role'=>'chair','program_id'=>(string)$programs[0]]);
    if ($scenario === 'duplicate') { $_POST['appointment_role']='dean'; $_POST['program_id']='0'; }
    if ($scenario === 'wrong_program') $_POST['program_id']=(string)$programs[1];
    if (in_array($scenario,['replace','stale','end','restore'],true)) {
        $_POST['appointment_id']=(string)$deanId; $_POST['revision']=$scenario==='stale'?'99':'1';
    }
    if ($scenario === 'end') $_POST['action']='end';
}
$snapshot=static function()use($conn):string {
    $data=[];
    foreach(['tbl_useraccount','tbl_useraccount_roles','tbl_faculty','tbl_program_chair_signatories'] as $table) $data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    return hash('sha256',json_encode($data));
};
$before=$snapshot();
function appointment_qa_capture(): void
{
    global $conn,$collegeId,$term,$snapshot,$before;
    $rows=synk_faculty_college_appointments($conn,$collegeId,$term);
    $active=array_values(array_filter($rows,static function($row){return $row['status']==='active';}));
    $deans=array_values(array_filter($active,static function($row){return $row['appointment_role']==='dean';}));
    header('X-QA-Active-Appointments: '.count($active));
    header('X-QA-Dean-Name: '.rawurlencode($deans[0]['first_name'] ?? 'none'));
    header('X-QA-Other-Records: '.($before===$snapshot()?'unchanged':'changed'));
}
$GLOBALS['appointment_qa_ready']=true;
session_write_close();
$_SERVER['PHP_SELF']='/synk/administrator/faculty-appointments.php';
require $root . '/administrator/faculty-appointments.php';
