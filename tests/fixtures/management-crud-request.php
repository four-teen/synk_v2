<?php
if (PHP_SAPI !== 'cli' && !defined('SYNK_MANAGEMENT_CRUD_QA')) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__,2);
require_once $root . '/backend/db.php';
require_once $root . '/backend/management_catalog_helper.php';
require_once $root . '/backend/management_students_helper.php';
class ManagementCrudConnection extends mysqli
{
    public function query($sql,$mode=MYSQLI_STORE_RESULT) {
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i',$sql)) throw new RuntimeException('Unexpected schema mutation.');
        return parent::query($sql,$mode);
    }
    public function start(): void { parent::begin_transaction(); }
    public function discard(): void { parent::rollback(); }
    public function begin_transaction($flags=null,$name=null): bool { return $this->query('SAVEPOINT management_crud'); }
    public function commit($flags=null,$name=null): bool { return $this->query('RELEASE SAVEPOINT management_crud'); }
    public function rollback($flags=null,$name=null): bool { $this->query('ROLLBACK TO SAVEPOINT management_crud'); return $this->query('RELEASE SAVEPOINT management_crud'); }
}
$conn->close(); $conn = new ManagementCrudConnection($servername,$username,$password,$dbase); $conn->set_charset('utf8mb4'); $conn->start();
$scenario = (string)($_GET['scenario'] ?? 'get_contribution');
$scopes = $conn->query("SELECT c.college_id,c.college_code,c.college_name,c.campus_id,cp.campus_name FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.status='active' AND cp.status='active' ORDER BY c.college_id LIMIT 2")->fetch_all(MYSQLI_ASSOC);
if (count($scopes)<2) throw new RuntimeException('Two colleges required.');
$scope=$scopes[0]; $foreign=$scopes[1];
foreach (['college_id','campus_id'] as $key) { $scope[$key]=(int)$scope[$key]; $foreign[$key]=(int)$foreign[$key]; }
$term=synk_fetch_current_academic_term($conn); $term['key']=$term['ay_id'].':'.$term['semester'];
$tag='MANAGEMENT CRUD QA '.strtoupper(bin2hex(random_bytes(5)));
$insert=static function(string $table,array $row)use($conn):int {
    synk_management_execute($conn,'INSERT INTO `'.$table.'` (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',str_repeat('s',count($row)),array_values($row));
    return (int)$conn->insert_id;
};
$userId=$insert('tbl_useraccount',['username'=>$tag,'email'=>str_replace(' ','',strtolower($tag)).'@gmail.com','password'=>'unused','role'=>'management','status'=>'active','college_id'=>$foreign['college_id'],'management_college_id'=>$scope['college_id']]);
synk_persist_useraccount_roles($conn,$userId,['management'],'management');
$org=$insert('tbl_management_organizations',['college_id'=>$scope['college_id'],'organization_code'=>'QA'.substr(str_replace(' ','',$tag),-10),'organization_name'=>$tag]);
$scope['organization_id']=$org; $scope['organization_code']='QA ORG'; $scope['organization_name']=$tag;
$term=synk_management_fetch_current_period($conn,$scope);
$conn->query('UPDATE tbl_useraccount SET management_organization_id='.$org.' WHERE user_id='.$userId);
$foreignOrg=$insert('tbl_management_organizations',['college_id'=>$foreign['college_id'],'organization_code'=>'QA'.substr(str_replace(' ','',$tag),-10),'organization_name'=>$tag]);
$siblingOrg=$insert('tbl_management_organizations',['college_id'=>$scope['college_id'],'organization_code'=>'SIB'.substr(str_replace(' ','',$tag),-10),'organization_name'=>$tag.' SIBLING']);
$eventBase=['college_id'=>$scope['college_id'],'organization_id'=>$org,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'title'=>$tag.' EVENT','event_date'=>'2026-10-26','audience_mode'=>'ALL','status'=>'DRAFT','source_table'=>'TBLEVENT','source_record_id'=>4000000000,'source_checksum'=>str_repeat('A',64),'source_metadata'=>'{"STATUS":"ALL","STAT":0}'];
$eventBase['exam_term']=$term['exam_term'];$eventBase['status']='ACTIVE';
$event=$insert('tbl_management_events',$eventBase);
$siblingEvent=$insert('tbl_management_events',array_merge($eventBase,['organization_id'=>$siblingOrg,'source_table'=>null,'source_record_id'=>null,'source_checksum'=>null]));
$foreignEvent=$insert('tbl_management_events',array_merge($eventBase,['college_id'=>$foreign['college_id'],'organization_id'=>$foreignOrg]));
$session=$insert('tbl_management_event_sessions',['college_id'=>$scope['college_id'],'event_id'=>$event,'title'=>$tag.' SESSION','starts_at'=>'2026-10-26 08:00:00','ends_at'=>'2026-10-26 12:00:00','fine_per_minute'=>'0.1250','grace_minutes'=>15,'potential_fine'=>'28.12','status'=>'ACTIVE','source_table'=>'TBLEVENTS','source_record_id'=>4000000000,'source_checksum'=>str_repeat('B',64)]);
$contributionBase=['college_id'=>$scope['college_id'],'organization_id'=>$org,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'event_id'=>$event,'title'=>$tag.' CONTRIBUTION','contribution_date'=>'2026-10-26','amount'=>'25.50','audience_mode'=>'SELECTED','status'=>'DRAFT','source_table'=>'TBLCONTIBUTION','source_record_id'=>4000000000,'source_checksum'=>str_repeat('C',64),'source_metadata'=>'{"STATUS":"OTHERS"}'];
$contributionBase['exam_term']=$term['exam_term'];
$contributionBase['status']='INACTIVE';
$contribution=$insert('tbl_management_contributions',$contributionBase);
$siblingContribution=$insert('tbl_management_contributions',array_merge($contributionBase,['organization_id'=>$siblingOrg,'event_id'=>$siblingEvent,'source_table'=>null,'source_record_id'=>null,'source_checksum'=>null]));
$foreignContribution=$insert('tbl_management_contributions',array_merge($contributionBase,['college_id'=>$foreign['college_id'],'organization_id'=>$foreignOrg,'event_id'=>$foreignEvent]));
$violation=$insert('tbl_management_violations',['college_id'=>$scope['college_id'],'organization_id'=>$org,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'violation_type'=>$tag.' VIOLATION','amount'=>'50.50']);
$siblingViolation=$insert('tbl_management_violations',['college_id'=>$scope['college_id'],'organization_id'=>$siblingOrg,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'violation_type'=>$tag.' VIOLATION','amount'=>'80.50']);
$foreignViolation=$insert('tbl_management_violations',['college_id'=>$foreign['college_id'],'organization_id'=>$foreignOrg,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'violation_type'=>$tag.' VIOLATION','amount'=>'75.50']);
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
$_SESSION=['user_id'=>$userId,'role'=>'management','auth_method'=>'google','username'=>$tag,'email'=>'qa@gmail.com','college_id'=>$foreign['college_id'],'management_csrf_token'=>str_repeat('d',64)];
$_GET=['term'=>$term['key']]; $_SERVER['REQUEST_METHOD']='POST';
$kind=strpos($scenario,'event')!==false || strpos($scenario,'session')!==false ? 'events' : 'contributions';
$recordId=$kind==='events' ? $event : $contribution;
$record=synk_management_catalog_get($conn,$scope,$kind,$recordId);
$post=['action'=>'save','kind'=>$kind,'csrf_token'=>str_repeat('d',64),'college_id'=>$scope['college_id'],'campus_id'=>$scope['campus_id'],
    'record_id'=>$recordId,'version'=>$record['version'],'current_term'=>$term['key'],'current_exam_term'=>$term['exam_term'],'current_period_version'=>$term['period_version'],'term'=>$term['key'],'record_term'=>$term['key'],'organization_id'=>$org,'title'=>'new lowercase title ñ',
    'description'=>"description with <script>ignored</script>",'status'=>'ACTIVE','audience_mode'=>'ALL','contribution_date'=>'2026-10-27','amount'=>'123.45','event_id'=>$event,
    'event_date'=>'2026-10-26','absence_fine'=>'25.25','latitude'=>'6.6155942','longitude'=>'124.5997341','attendance_radius_m'=>'100'];
$sessions=[['session_id'=>$session,'title'=>'updated session','starts_at'=>'2026-10-26T08:00','ends_at'=>'2026-10-26T12:00','fine_per_minute'=>'0.1250','grace_minutes'=>'20','potential_fine'=>'28.12','status'=>'ACTIVE']];
if ($scenario==='get_contribution' || $scenario==='get_event') $post['action']='get';
if ($scenario==='contribution_other_period_get') { $post['action']='get';$post['exam_term']=$term['exam_term']==='MIDTERM'?'FINAL':'MIDTERM'; }
if ($scenario==='event_other_period_get') { $post['action']='get';$post['exam_term']=$term['exam_term']==='MIDTERM'?'FINAL':'MIDTERM'; }
if ($scenario==='old_event_status') $post['status']='OPEN';
if ($scenario==='old_session_status') $sessions[0]['status']='OPEN';
if ($scenario==='add_contribution' || $scenario==='add_event') { $post['record_id']=''; $post['version']=''; if($kind==='events')$sessions[0]['session_id']=''; }
if ($scenario==='delete_contribution' || $scenario==='delete_event' || $scenario==='delete_linked_event') {
    $post['action']='delete';
    if ($scenario==='delete_event') {
        $conn->query('UPDATE tbl_management_contributions SET event_id=NULL WHERE contribution_id='.$contribution);
        $record=synk_management_catalog_get($conn,$scope,$kind,$recordId); $post['version']=$record['version'];
    }
}
if ($scenario==='stale_contribution') { $post['version']=str_repeat('0',64); }
if ($scenario==='stale_delete_event') { $post['action']='delete'; $post['version']=str_repeat('0',64); }
if ($scenario==='foreign_contribution' || $scenario==='foreign_event') $post['record_id']=$kind==='events' ? $foreignEvent : $foreignContribution;
if ($scenario==='foreign_org') $post['organization_id']=$foreignOrg;
if ($scenario==='foreign_event_link') { $kind='contributions'; $post['kind']=$kind; $post['status']='ACTIVE'; $post['record_id']=$contribution; $post['version']=synk_management_catalog_get($conn,$scope,$kind,$contribution)['version']; $post['event_id']=$foreignEvent; }
if ($scenario==='bad_amount') $post['amount']='0.001';
if ($scenario==='negative_amount') $post['amount']='-1';
if ($scenario==='large_amount') $post['amount']='10000000000';
if ($scenario==='bad_date') $post['contribution_date']='2026-02-30';
if ($scenario==='bad_term') $post['record_term']='999999:1';
if ($scenario==='bad_title') $post['title']='';
if ($scenario==='bad_utf8') $post['title']="bad\xff";
if ($scenario==='bad_coordinates_event') $post['latitude']='91';
if ($scenario==='partial_coordinates_event') $post['longitude']='';
if ($scenario==='bad_session') $sessions[0]['ends_at']='2026-10-26T07:00';
if ($scenario==='duplicate_session') $sessions[]=$sessions[0];
if ($scenario==='foreign_session') $sessions[0]['session_id']=4000000000;
if ($scenario==='remove_session') $sessions=[];
if ($scenario==='unassigned_event') { $post['record_term']=''; $post['organization_id']=''; }
if ($scenario==='add_second_session') { $sessions[] = array_merge($sessions[0],['session_id'=>'','title'=>'Afternoon session','starts_at'=>'2026-10-26T13:00','ends_at'=>'2026-10-26T17:00']); }
if ($scenario==='bad_csrf') $post['csrf_token']='invalid';
if ($scenario==='array_input') $post['title']=['unexpected'];
if ($scenario==='forged_college') $post['college_id']=$foreign['college_id'];
if ($scenario==='forged_campus') $post['campus_id']=99999;
if ($scenario==='no_google') $_SESSION['auth_method']='legacy';
if ($scenario==='no_role') $_SESSION['role']='admin';
if ($scenario==='anonymous') $_SESSION=[];
if ($scenario==='revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id=$userId");
if ($scenario==='reassigned') $conn->query('UPDATE tbl_useraccount SET management_organization_id=NULL,management_college_id='.$foreign['college_id'].' WHERE user_id='.$userId);
if ($scenario==='get_method') $_SERVER['REQUEST_METHOD']='GET';
if ($scenario==='invalid_action') $post['action']='student_save';
if ($scenario==='invalid_kind') $post['kind']='tbl_useraccount';
if (in_array($scenario,['add_organization','duplicate_organization','invalid_organization'],true)) {
    $post['action']='organization_add'; $post['organization_code']='QA-NEW'; $post['organization_name']='new organization';
    if($scenario==='duplicate_organization')$post['organization_code']='QA'.substr(str_replace(' ','',$tag),-10);
    if($scenario==='invalid_organization')$post['organization_code']='<SCRIPT>';
}
$post['sessions']=json_encode($sessions); $_POST=$post;
if ($scenario==='save_event_simple') {
    $conn->query('UPDATE tbl_management_events SET latitude=6.6155942,longitude=124.5997341,attendance_radius_m=100 WHERE event_id='.$event);
    $_POST['version']=synk_management_catalog_get($conn,$scope,'events',$event)['version'];
    foreach(['sessions','latitude','longitude','attendance_radius_m']as$key)unset($_POST[$key]);
}
if(strpos($scenario,'violation_')===0) {
    require_once $root.'/backend/management_violations_helper.php';
    $violationRecord=synk_management_violation_get($conn,$scope,$violation);
    $_POST=array_merge($post,['kind'=>'violations','record_id'=>$violation,'version'=>$violationRecord['version'],'violation_type'=>'new violation ñ','amount'=>'125.25']);
    if($scenario==='violation_get')$_POST['action']='get';
    if($scenario==='violation_add'){$_POST['record_id']='';$_POST['version']='';}
    if($scenario==='violation_delete')$_POST['action']='delete';
    if($scenario==='violation_foreign')$_POST['record_id']=$foreignViolation;
    if($scenario==='violation_stale')$_POST['version']=str_repeat('0',64);
    if($scenario==='violation_negative')$_POST['amount']='-5';
    if($scenario==='violation_precision')$_POST['amount']='10.001';
    if($scenario==='violation_blank')$_POST['violation_type']='';
    if($scenario==='violation_duplicate'){$_POST['record_id']='';$_POST['violation_type']=strtolower($tag.' VIOLATION');}
    if($scenario==='violation_duplicate_legacy'){$_POST['record_id']='';$_POST['violation_type']=strtolower($tag.' VIOLATION');mysqli_report(MYSQLI_REPORT_OFF);}
}
$handler='backend/query_management.php'; $fixtureResult=null;
if (in_array($scenario,['page_contributions','page_events','page_violations'],true)) {
    $handler='management/'.substr($scenario,5).'.php'; $_POST=[]; $_SERVER['REQUEST_METHOD']='GET'; $_GET['search']=$tag;
}
if ($scenario==='catalog_lists') {
    $handler='';
    $insert('tbl_management_events',array_merge($eventBase,['title'=>$tag.' UNASSIGNED','organization_id'=>$org,'ay_id'=>null,'semester'=>null,'source_table'=>null,'source_record_id'=>null,'source_checksum'=>null]));
}
if (strpos($scenario,'student_')===0 || $scenario==='directory_comparison') {
    $student=$conn->query('SELECT es.student_id FROM tbl_student_management_enrolled_subjects es WHERE es.is_active=1 AND es.college_id='.$scope['college_id'].' LIMIT 1')->fetch_assoc();
    if (!$student) throw new RuntimeException('Imported students required.');
    $_POST=['action'=>'student_detail','csrf_token'=>str_repeat('d',64),'term'=>$term['key'],'directory_term'=>'all','student_id'=>$student['student_id']];
    if($scenario==='student_foreign') {
        $foreignStudent=$conn->query('SELECT es.student_id FROM tbl_student_management_enrolled_subjects es WHERE es.is_active=1 AND es.college_id='.$foreign['college_id'].' AND NOT EXISTS (SELECT 1 FROM tbl_student_management_enrolled_subjects own WHERE own.student_id=es.student_id AND own.college_id='.$scope['college_id'].' AND own.is_active=1) LIMIT 1')->fetch_assoc();
        $_POST['student_id']=$foreignStudent ? $foreignStudent['student_id'] : 4294967295;
    }
    if($scenario==='student_foreign_program') $_POST['program_id']=$conn->query('SELECT program_id FROM tbl_program WHERE college_id='.$foreign['college_id'].' LIMIT 1')->fetch_assoc()['program_id'];
    if($scenario==='student_array') $_POST['search']=['invalid'];
    if($scenario==='directory_comparison') $handler='';
}
// Additional organization and current-term regression cases. Fixtures roll back.
if ($scenario==='sibling_event') { $_POST['record_id']=$siblingEvent; $_POST['action']='get'; }
if ($scenario==='sibling_contribution') { $_POST['record_id']=$siblingContribution; $_POST['action']='get'; }
if ($scenario==='sibling_violation') { $_POST['kind']='violations'; $_POST['record_id']=$siblingViolation; $_POST['action']='get'; }
if ($scenario==='sibling_event_link' || $scenario==='historical_event_link') {
    $_POST['kind']='contributions'; $_POST['status']='ACTIVE'; $_POST['record_id']=$contribution;
    $_POST['version']=synk_management_catalog_get($conn,$scope,'contributions',$contribution)['version'];
    $_POST['event_id']=$scenario==='sibling_event_link' ? $siblingEvent : $event;
}
if ($scenario==='sibling_scope') { $_POST['organization_id']=$siblingOrg; }
if ($scenario==='stale_term') $_POST['current_term']='1:3';
if ($scenario==='violation_stale_term') $_POST['current_term']='1:3';
if (in_array($scenario,['changed_exam_term','violation_changed_exam_term'],true)) {
    if ($scenario==='violation_changed_exam_term') { $_POST['kind']='violations'; $_POST['record_id']=$violation; }
    $insert('tbl_management_period_settings',['college_id'=>$scope['college_id'],'organization_id'=>$org,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'current_exam_term'=>'FINAL']);
}
if ($scenario==='missing_exam_term') unset($_POST['current_exam_term']);
if ($scenario==='invalid_exam_term') $_POST['current_exam_term']='PRELIM';
if (in_array($scenario,['changed_term','violation_changed_term','assignment_across_terms'],true)) $conn->query('UPDATE tbl_academic_settings SET current_semester='.($term['semester']===1?2:1));
if ($scenario==='auto_term_violation') {
    $_POST['kind']='violations'; $_POST['record_id']=''; $_POST['version']='';
    $_POST['violation_type']='automatic term violation'; $_POST['amount']='15.25';
    unset($_POST['organization_id'],$_POST['record_term']);
}
if ($scenario==='auto_term_contribution' || $scenario==='auto_term_event') {
    $_POST['record_id']=''; $_POST['version']=''; unset($_POST['organization_id'],$_POST['record_term']);
    if ($scenario==='auto_term_event') $_POST['sessions']='[]';
}
if ($scenario==='historical_edit') {
    $oldSemester=$term['semester']===1?2:1;
    $conn->query('UPDATE tbl_management_contributions SET event_id=NULL,semester='.$oldSemester.' WHERE contribution_id='.$contribution);
    $_POST['version']=synk_management_catalog_get($conn,$scope,'contributions',$contribution)['version'];
    $_POST['record_term']=$term['ay_id'].':'.$oldSemester; $_POST['event_id']='';
}
if ($scenario==='historical_event_link') {
    $conn->query('UPDATE tbl_management_events SET semester='.($term['semester']===1?2:1).' WHERE event_id='.$event);
}
if ($scenario==='assignment_across_terms' || strpos($scenario,'payment_context_')===0 || $scenario==='organization_options') $handler='';
if (strpos($scenario,'org_admin_')===0) {
    $handler='backend/query_organizations.php';
    $_SESSION['csrf_token']=str_repeat('e',64);
    $_POST=['action'=>'save','csrf_token'=>str_repeat('e',64),'organization_id'=>'','campus_id'=>$scope['campus_id'],'college_id'=>$scope['college_id'],'organization_code'=>'NEW-QA-'.substr($tag,-10),'organization_name'=>'new organization ñ','status'=>'ACTIVE'];
    if (!in_array($scenario,['org_admin_forbidden','org_admin_revoked'],true)) {
        $_SESSION['role']='admin'; synk_persist_useraccount_roles($conn,$userId,['admin'],'admin');
        $conn->query("UPDATE tbl_useraccount SET role='admin' WHERE user_id=".$userId);
    }
    if ($scenario==='org_admin_revoked') $_SESSION['role']='admin';
    if ($scenario==='org_admin_csrf') $_POST['csrf_token']='bad';
    if ($scenario==='org_admin_array') $_POST['organization_name']=['bad'];
    if ($scenario==='org_admin_college') $_POST['college_id']=4294967295;
    if ($scenario==='org_admin_campus') $_POST['campus_id']=4294967295;
    if ($scenario==='org_admin_all_colleges') $_POST['college_id']='';
    if ($scenario==='org_admin_code') $_POST['organization_code']='<SCRIPT>';
    if ($scenario==='org_admin_duplicate') $_POST['organization_code']='QA'.substr(str_replace(' ','',$tag),-10);
    if (in_array($scenario,['org_admin_get','org_admin_edit','org_admin_stale','org_admin_inactive','org_admin_delete_used','org_admin_move_used'],true)) {
        require_once $root.'/backend/management_organization_helper.php';
        $orgRecord=synk_organization_get($conn,$org);
        $_POST['organization_id']=$org; $_POST['version']=$orgRecord['version'];
        if ($scenario==='org_admin_get') $_POST['action']='get';
        if ($scenario==='org_admin_stale') $_POST['version']=str_repeat('0',64);
        if ($scenario==='org_admin_inactive') $_POST['status']='INACTIVE';
        if ($scenario==='org_admin_delete_used') $_POST['action']='delete';
        if ($scenario==='org_admin_move_used') { $_POST['college_id']=$foreign['college_id']; $_POST['campus_id']=$foreign['campus_id']; }
    }
    if ($scenario==='org_admin_delete_unused') {
        require_once $root.'/backend/management_organization_helper.php';
        $unused=$insert('tbl_management_organizations',['college_id'=>$scope['college_id'],'organization_code'=>'UNUSED'.substr($tag,-10),'organization_name'=>'UNUSED']);
        $_POST['organization_id']=$unused; $_POST['version']=synk_organization_get($conn,$unused)['version']; $_POST['action']='delete';
    }
}
if (strpos($scenario,'management_term_')===0) {
    $_POST=['action'=>'save_management_term','csrf_token'=>str_repeat('d',64),'current_term'=>$term['key'],
        'current_exam_term'=>$term['exam_term'],'current_period_version'=>$term['period_version'],'exam_term'=>'FINAL'];
    if ($scenario==='management_term_invalid') $_POST['exam_term']='PRELIM';
    if ($scenario==='management_term_missing') unset($_POST['exam_term']);
    if ($scenario==='management_term_stale') $_POST['current_period_version']='999';
    if ($scenario==='management_term_forbidden') $_SESSION['role']='admin';
    if ($scenario==='management_term_foreign') $_POST['organization_id']=$siblingOrg;
    if ($scenario==='management_term_csrf') $_POST['csrf_token']='bad';
    if ($scenario==='management_term_switch_back') $handler='';
}
if (strpos($scenario,'attendance_http_')===0) {
    require_once $root.'/backend/management_attendance_helper.php';
    $_POST=array_merge($post,['action'=>'attendance_open','event_id'=>$event]);
    if (in_array($scenario,['attendance_http_scan','attendance_http_duplicate','attendance_http_close'],true)) {
        $opened=synk_management_attendance_write($conn,$scope,'attendance_open',$_POST);
        $studentId=(int)$conn->query('SELECT student_id FROM tbl_management_attendance_roster WHERE event_id='.$event.' ORDER BY student_id LIMIT 1')->fetch_assoc()['student_id'];
        $_POST['qr_code']=synk_management_student_qr($conn,$studentId);$_POST['action']='attendance_scan';
        if ($scenario==='attendance_http_duplicate') synk_management_attendance_write($conn,$scope,'attendance_scan',$_POST);
        if ($scenario==='attendance_http_close') { $_POST['action']='attendance_close';$_POST['attendance_revision']=$opened['attendance']['revision'];$_POST['confirm']='1'; }
    }
    if ($scenario==='attendance_http_csrf') $_POST['csrf_token']='wrong';
    if ($scenario==='attendance_http_role') $_SESSION['role']='admin';
    if ($scenario==='attendance_http_foreign') $_POST['event_id']=$foreignEvent;
    if ($scenario==='attendance_http_stale') $_POST['current_period_version']='999';
    if ($scenario==='attendance_http_array') $_POST['qr_code']=['unexpected'];
}
if (strpos($scenario,'collect_')===0) {
    require_once $root.'/backend/management_payment_helper.php';
    $program=(int)$conn->query('SELECT program_id FROM tbl_program WHERE college_id='.$scope['college_id'].' ORDER BY program_id LIMIT 1')->fetch_assoc()['program_id'];
    $testStudent=$insert('tbl_student_management',['student_number'=>$tag,'first_name'=>'PAYMENT','last_name'=>'TEST','source_sheet_name'=>'DEAN MANUAL','program_id'=>$program,'ay_id'=>$term['ay_id'],'semester'=>$term['semester']]);
    $conn->query("UPDATE tbl_management_contributions SET status='ACTIVE',amount='300.00' WHERE contribution_id=".$contribution);
    $overview=synk_management_student_financial_overview($conn,$scope,$term,$testStudent);
    $_POST=$post+['student_id'=>(string)$testStudent,'allocation_mode'=>'DISTRIBUTE','account_version'=>$overview['account_version'],'request_key'=>bin2hex(random_bytes(32)),'selected'=>'[]'];
    $_POST['action']='payment_post';$_POST['amount']='100';$_POST['exam_term']=$term['exam_term'];
    if ($scenario==='collect_preview') $_POST['action']='payment_preview';
    if ($scenario==='collect_csrf') $_POST['csrf_token']='invalid';
    if ($scenario==='collect_role') $_SESSION['role']='student';
    if ($scenario==='collect_foreign') $_POST['organization_id']=$foreignOrg;
    if ($scenario==='collect_stale') $_POST['account_version']=str_repeat('0',64);
    if ($scenario==='collect_period') $_POST['exam_term']=$term['exam_term']==='FINAL'?'MIDTERM':'FINAL';
    if ($scenario==='collect_overpay') $_POST['amount']='301';
    if ($scenario==='collect_fraction') $_POST['amount']='100.50';
    if ($scenario==='collect_array') $_POST['selected']=[];
}
if(strpos($scenario,'officer_http_')===0 || strpos($scenario,'officer_catalog_')===0){
    require_once $root.'/backend/management_officers_helper.php';
    $faculty=$insert('tbl_faculty',['first_name'=>'OFFICER HTTP','last_name'=>$tag,'status'=>'active']);
    $insert('tbl_useraccount_faculty_links',['user_id'=>$userId,'faculty_id'=>$faculty]);
    $program=(int)$conn->query('SELECT program_id FROM tbl_program WHERE college_id='.$scope['college_id'].' ORDER BY program_id LIMIT 1')->fetch_assoc()['program_id'];
    $sid=$insert('tbl_student_management',['student_number'=>$tag,'first_name'=>'OFFICER','last_name'=>$tag,'email_address'=>str_replace(' ','',strtolower($tag)).'student@sksu.edu.ph','source_sheet_name'=>'DEAN MANUAL','program_id'=>$program,'ay_id'=>$term['ay_id'],'semester'=>$term['semester']]);
    $_POST=$post+['student_id'=>(string)$sid,'can_view'=>'1','can_attendance'=>'0','can_payments'=>'1','expires_on'=>date('Y-m-d',strtotime('+30 days'))];$_POST['action']='officer_save';
    if(strpos($scenario,'officer_catalog_')===0){
        require_once $root.'/backend/management_violations_helper.php';
        [,,$catalogKind,$mode]=explode('_',$scenario,4);
        foreach(array_keys(synk_management_operation_labels())as$op)$_POST['can_'.$op]='0';
        $_POST['can_manage_'.$catalogKind]='1';
        synk_management_officer_write($conn,$scope,'officer_save',$_POST);
        $grant=synk_management_officer_record($conn,(int)$conn->query('SELECT user_id FROM tbl_management_officers WHERE student_id='.$sid)->fetch_assoc()['user_id']);
        $_SESSION['user_id']=(int)$grant['user_id'];
        $catalogId=['contributions'=>$contribution,'events'=>$event,'violations'=>$violation][$catalogKind];
        $catalogRecord=$catalogKind==='violations'?synk_management_violation_get($conn,$scope,$catalogId):synk_management_catalog_get($conn,$scope,$catalogKind,$catalogId);
        $_POST=array_merge($post,['kind'=>$catalogKind,'action'=>'save','record_id'=>$catalogId,'version'=>$catalogRecord['version'],'violation_type'=>$tag.' HTTP VIOLATION']);unset($_POST['sessions']);
        if($mode==='get')$_POST['action']='get';
        if($mode==='add'){$_POST['record_id']='';$_POST['version']='';}
        if($mode==='delete'){$_POST['action']='delete';if($catalogKind==='events'){$conn->query('UPDATE tbl_management_contributions SET event_id=NULL WHERE contribution_id='.$contribution);$_POST['version']=synk_management_catalog_get($conn,$scope,'events',$event)['version'];}}
        if($mode==='denied'){$_POST['kind']=$catalogKind==='events'?'contributions':'events';}
        if($mode==='stale')$_POST['version']=str_repeat('0',64);
        if($mode==='foreign')$_POST['record_id']=['contributions'=>$foreignContribution,'events'=>$foreignEvent,'violations'=>$foreignViolation][$catalogKind];
        if($mode==='removed')$conn->query('UPDATE tbl_management_officers SET can_manage_'.$catalogKind.'=0,can_view=1 WHERE officer_id='.(int)$grant['officer_id']);
    }
    if($scenario==='officer_http_search'){$_POST['action']='officer_search';$_POST['search']=$tag;}
    if($scenario==='officer_http_csrf')$_POST['csrf_token']='wrong';
    if($scenario==='officer_http_foreign')$_POST['organization_id']=$foreignOrg;
    if($scenario==='officer_http_no_faculty')$conn->query('DELETE FROM tbl_useraccount_faculty_links WHERE user_id='.$userId);
    if(in_array($scenario,['officer_http_student_setup','officer_http_student_grant','officer_http_own_payment','officer_http_revoked','officer_http_expired','officer_http_revoke'],true)){
        synk_management_officer_write($conn,$scope,'officer_save',$_POST);
        $grant=synk_management_query($conn,'SELECT * FROM tbl_management_officers WHERE student_id=?','i',[$sid])[0];
        if($scenario==='officer_http_revoke'){$_POST['action']='officer_revoke';$_POST['revision']=(string)$grant['revision'];}
        else{
            $_SESSION['user_id']=(int)$grant['user_id'];
            if($scenario==='officer_http_student_setup'){$_POST['action']='save_management_term';$_POST['exam_term']=$term['exam_term'];}
            if($scenario==='officer_http_student_grant')$_POST['action']='officer_search';
            if($scenario==='officer_http_own_payment')$_POST['action']='payment_post';
            if($scenario==='officer_http_revoked'){$conn->query("UPDATE tbl_management_officers SET status='REVOKED' WHERE officer_id=".(int)$grant['officer_id']);$_POST['action']='student_detail';}
            if($scenario==='officer_http_expired'){$conn->query("UPDATE tbl_management_officers SET expires_on='2020-01-01' WHERE officer_id=".(int)$grant['officer_id']);$_POST['action']='student_detail';}
        }
    }
}
$take=static function()use($conn,$scope,$foreign):array {
    $data=[];
    foreach(['tbl_management_organizations','tbl_management_contributions','tbl_management_events','tbl_management_event_sessions','tbl_management_violations','tbl_management_period_settings'] as $table) $data[$table]=$conn->query('SELECT * FROM '.$table.' WHERE college_id IN ('.$scope['college_id'].','.$foreign['college_id'].') ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    $data['tbl_academic_settings']=$conn->query('SELECT * FROM tbl_academic_settings ORDER BY setting_id')->fetch_all(MYSQLI_ASSOC);
    foreach(['tbl_management_attendance_events','tbl_management_attendance_roster','tbl_management_attendance_logs','tbl_management_attendance_audit','tbl_management_student_qr_tokens']as$table)$data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetch_all(MYSQLI_ASSOC);
    foreach(['tbl_management_payments','tbl_management_payment_items']as$table)$data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    foreach(['tbl_management_officers','tbl_management_officer_audit']as$table)$data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    return $data;
};
$before=$take();
register_shutdown_function(static function()use($conn,$take,$before,$tag,$scope,$foreign,$contribution,$event,$session,$violation,$userId,$org,$scenario,&$fixtureResult):void {
    $body=''; while(ob_get_level()>0)$body=ob_get_clean().$body;
    try {
        $after=$take(); $status=http_response_code()?:200; $conn->discard();
        if(session_status()===PHP_SESSION_ACTIVE)session_destroy(); header_remove(); http_response_code(200);
        if(defined('SYNK_MANAGEMENT_CRUD_RENDER') && strpos($body,'<!DOCTYPE html>')!==false) {
            header('Content-Type: text/html; charset=utf-8'); echo str_replace('<head>','<head><base href="/synk/management/">',$body);
        } else { header('Content-Type: application/json'); echo json_encode(['status'=>$status,'response'=>$fixtureResult ?? (json_decode($body,true) ?? $body),
            'before'=>$before,'after'=>$after,'scope'=>$scope,'foreign'=>$foreign,'tag'=>$tag,'ids'=>['contribution'=>$contribution,'event'=>$event,'session'=>$session,'violation'=>$violation,'org'=>$org,'user'=>$userId]]); }
    } catch(Throwable $error) { $conn->discard(); http_response_code(500); echo json_encode(['fixture_error'=>$error->getMessage(),'body'=>substr($body,0,1500)]); }
});
ob_start();
if ($scenario==='management_term_switch_back') {
    $saved=synk_management_save_current_exam_term($conn,$scope,$_POST);
    $back=$_POST; $back['current_exam_term']=$saved['current_exam_term']; $back['current_period_version']=$saved['current_period_version']; $back['exam_term']='MIDTERM';
    synk_management_save_current_exam_term($conn,$scope,$back);
    $rejected=false;
    try { synk_management_save_current_exam_term($conn,$scope,$_POST); } catch(SynkManagementConflict $error) { $rejected=true; }
    $fixtureResult=['stale_rejected'=>$rejected,'period'=>synk_management_fetch_current_period($conn,$scope)]; exit;
}
if ($scenario==='assignment_across_terms') { $fixtureResult=['allowed'=>synk_management_account_has_access($conn,synk_find_useraccount_by_id($conn,$userId))]; exit; }
if ($scenario==='organization_options') { $fixtureResult=synk_management_catalog_options($conn,$scope); exit; }
if (strpos($scenario,'payment_context_')===0) {
    $charge=$contributionBase;
    if ($scenario==='payment_context_historical') $charge['semester']=$term['semester']===1?2:1;
    if ($scenario==='payment_context_sibling') $charge['organization_id']=$siblingOrg;
    if ($scenario==='payment_context_wrong_exam_term') $charge['exam_term']=$term['exam_term']==='MIDTERM'?'FINAL':'MIDTERM';
    try { synk_management_assert_payment_context($scope,$term,$charge); $fixtureResult=['allowed'=>true]; }
    catch (SynkManagementConflict $e) { http_response_code(409); $fixtureResult=['allowed'=>false]; }
    catch (DomainException $e) { http_response_code(422); $fixtureResult=['allowed'=>false]; }
    exit;
}
if($handler==='') {
    if($scenario==='catalog_lists') {
        $selected=synk_management_catalog_list($conn,$scope,$term,'events',['search'=>$tag]);
        $all=synk_management_catalog_list($conn,$scope,$term,'events',['search'=>$tag,'catalog_scope'=>'all']);
        $unassigned=synk_management_catalog_list($conn,$scope,$term,'events',['search'=>$tag,'catalog_scope'=>'unassigned']);
        $wildcard=synk_management_catalog_list($conn,$scope,$term,'events',['search'=>$tag.'%','catalog_scope'=>'all']);
        $inactive=synk_management_catalog_list($conn,$scope,$term,'events',['search'=>$tag,'status'=>'INACTIVE','catalog_scope'=>'all']);
        $organization=synk_management_catalog_list($conn,$scope,$term,'events',['search'=>$tag,'organization_id'=>$org,'catalog_scope'=>'all']);
        $fixtureResult=['selected'=>$selected['pagination']['total'],'all'=>$all['pagination']['total'],'unassigned'=>$unassigned['pagination']['total'],
            'wildcard'=>$wildcard['pagination']['total'],'inactive'=>$inactive['pagination']['total'],'organization'=>$organization['pagination']['total']]; exit;
    }
    $filters=synk_management_directory_filters($conn,$scope,['term'=>$term['key'],'directory_term'=>'all','email_status'=>'missing','page_size'=>'50']);
    $management=synk_management_directory($conn,$scope,['term'=>$term['key'],'directory_term'=>'all','email_status'=>'missing','page_size'=>'50']);
    $dean=synk_dean_students_list($conn,$scope,$filters);
    $fixtureResult=['same'=>$management['rows']===$dean['rows'] && $management['summary']===$dean['summary'] && $management['pagination']===$dean['pagination']]; exit;
}
session_write_close(); chdir(dirname($root.'/'.$handler)); require $root.'/'.$handler;
