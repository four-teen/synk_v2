<?php
// Run only through the random localhost test route or CLI. All fixtures roll back.
if (PHP_SAPI !== 'cli' && !defined('SYNK_MANAGEMENT_QA')) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__, 2);
require_once $root . '/backend/db.php';
require_once $root . '/backend/management_portal_helper.php';
require_once $root . '/backend/auth_google.php';
class ManagementRequestConnection extends mysqli
{
    public function query($sql, $mode = MYSQLI_STORE_RESULT) {
        if (preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i', $sql)) throw new RuntimeException('Request attempted schema changes.');
        return parent::query($sql, $mode);
    }
    public function startFixtures(): void { parent::begin_transaction(); }
    public function discardFixtures(): void { parent::rollback(); }
    public function begin_transaction($flags = null, $name = null): bool { return $this->query('SAVEPOINT management_request'); }
    public function commit($flags = null, $name = null): bool { return $this->query('RELEASE SAVEPOINT management_request'); }
    public function rollback($flags = null, $name = null): bool { $this->query('ROLLBACK TO SAVEPOINT management_request'); return $this->query('RELEASE SAVEPOINT management_request'); }
}
$conn->close();
$conn = new ManagementRequestConnection($servername, $username, $password, $dbase); $conn->set_charset('utf8mb4');
$conn->startFixtures();
$scenario = (string)($_GET['scenario'] ?? ($argv[1] ?? 'dashboard'));
$tag = 'MgmtQA' . bin2hex(random_bytes(5));
$term = synk_fetch_current_academic_term($conn); $term['key'] = $term['ay_id'] . ':' . $term['semester'];
$colleges = $conn->query("SELECT c.college_id,c.college_code,c.college_name,c.campus_id,cp.campus_name FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.status='active' AND cp.status='active' ORDER BY c.college_id LIMIT 2")->fetch_all(MYSQLI_ASSOC);
if (count($colleges)<2) throw new RuntimeException('Two active colleges are required.');
$scope = $colleges[0]; $otherScope = $colleges[1];
foreach (['college_id','campus_id'] as $field) { $scope[$field]=(int)$scope[$field]; $otherScope[$field]=(int)$otherScope[$field]; }
$email = strtolower($tag) . '@gmail.com';
$stmt = $conn->prepare("INSERT INTO tbl_useraccount (username,email,password,role,status,college_id,management_college_id) VALUES (?,?,'unusable-test-password','management','active',?,?)");
$stmt->bind_param('ssii', $tag, $email, $otherScope['college_id'], $scope['college_id']); $stmt->execute(); $userId=(int)$conn->insert_id;
if (synk_persist_useraccount_roles($conn,$userId,['management'],'management') !== null) throw new RuntimeException('Role setup failed.');
$conn->query("INSERT INTO tbl_management_organizations (college_id,organization_code,organization_name) VALUES (".$scope['college_id'].",'".$tag."','".$tag."')");
$organizationId=(int)$conn->insert_id; $scope['organization_id']=$organizationId;
$term=synk_management_fetch_current_period($conn,$scope);
$conn->query('UPDATE tbl_useraccount SET management_organization_id='.$organizationId.' WHERE user_id='.$userId);
$conn->query("INSERT INTO tbl_management_organizations (college_id,organization_code,organization_name) VALUES (".$otherScope['college_id'].",'".$tag."','".$tag."')");
$otherOrganizationId=(int)$conn->insert_id;
$baseline = synk_management_analytics($conn, $scope, $term);
$clone = static function (string $table, array $row, array $values) use ($conn): int {
    $fields=[]; $params=[];
    foreach ($conn->query('SHOW COLUMNS FROM `'.$table.'`')->fetch_all(MYSQLI_ASSOC) as $column) {
        if (stripos($column['Extra'],'auto_increment')!==false || stripos($column['Extra'],'generated')!==false) continue;
        $key=$column['Field']; if (!array_key_exists($key,$values) && !array_key_exists($key,$row)) continue;
        $fields[]='`'.$key.'`'; $params[]=array_key_exists($key,$values)?$values[$key]:$row[$key];
    }
    $stmt=$conn->prepare('INSERT INTO `'.$table.'` ('.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');
    synk_stmt_bind_params($stmt,str_repeat('s',count($params)),$params); $stmt->execute(); $id=(int)$conn->insert_id; $stmt->close(); return $id;
};
$templates=$conn->query('SELECT es.* FROM tbl_student_management_enrolled_subjects es JOIN tbl_program p ON p.program_id=es.program_id AND p.college_id=es.college_id WHERE es.is_active=1 AND es.college_id='.$scope['college_id'].' AND es.ay_id='.$term['ay_id'].' AND es.semester='.$term['semester'].' GROUP BY es.offering_id ORDER BY es.offering_id LIMIT 2')->fetch_all(MYSQLI_ASSOC);
if(count($templates)<2) throw new RuntimeException('Two current imported classes are required.');
$foreignTemplate=$templates[0]; $foreignProgram=$conn->query('SELECT program_id FROM tbl_program WHERE college_id='.$otherScope['college_id'].' LIMIT 1')->fetch_assoc();
$foreignTemplate=array_merge($foreignTemplate,['college_id'=>$otherScope['college_id'],'campus_id'=>$otherScope['campus_id'],'program_id'=>$foreignProgram['program_id'],'offering_id'=>0,'section_id'=>0]);
$studentRow=$conn->query('SELECT * FROM tbl_student_management LIMIT 1')->fetch_assoc();
$students=[];
for($i=0;$i<28;$i++) {
    $id=$clone('tbl_student_management',$studentRow,['student_number'=>$tag.'-'.$i,'last_name'=>$tag,'first_name'=>sprintf('Student %02d',$i),'email_address'=>'','program_id'=>$i===27?$foreignProgram['program_id']:$templates[0]['program_id'],'source_sheet_name'=>'QA IMPORT']);
    $students[]=$id;
    $clone('tbl_student_management_enrolled_subjects',$templates[0],['student_id'=>$id,'source_file_name'=>$tag.'.xlsx']);
}
// Duplicate subject memberships must not duplicate the student count.
$clone('tbl_student_management_enrolled_subjects',$templates[1],['student_id'=>$students[0],'source_file_name'=>$tag.'.xlsx']);
// A shared identity has membership in both colleges; a foreign-only identity is excluded.
$clone('tbl_student_management_enrolled_subjects',$foreignTemplate,['student_id'=>$students[27],'source_file_name'=>$tag.'.xlsx']);
$foreignStudent=$clone('tbl_student_management',$studentRow,['student_number'=>$tag.'-foreign','last_name'=>$tag.'Foreign','first_name'=>'Outside College','email_address'=>'','program_id'=>$foreignProgram['program_id']]);
$clone('tbl_student_management_enrolled_subjects',$foreignTemplate,['student_id'=>$foreignStudent,'source_file_name'=>$tag.'.xlsx']);
if (session_status()!==PHP_SESSION_ACTIVE) session_start();
$_SESSION=['user_id'=>$userId,'role'=>'management','auth_method'=>'google','username'=>$tag,'email'=>$email,'college_id'=>$otherScope['college_id'],'csrf_token'=>'management-qa','management_login_csrf'=>'management-login-qa'];
$_POST=[]; $_GET=['term'=>$term['key']]; $_SERVER['REQUEST_METHOD']='GET';
$handler='management/index.php'; $result=null;
$base=['username'=>$tag,'email'=>'new-'.$email,'roles'=>['management'],'primary_role'=>'management','management_college_id'=>$scope['college_id'],'management_organization_id'=>$organizationId,'management_campus_id'=>$scope['campus_id'],'status'=>'active','csrf_token'=>'management-qa'];
if (strpos($scenario,'account_')===0) {
    $_SESSION['role']='admin'; $_SERVER['REQUEST_METHOD']='POST'; $_POST=$base+['save_account'=>1]; $handler='backend/query_accounts.php';
    if (in_array($scenario,['account_update','account_remove','account_identity_preserved','account_identity_changed'],true)) { $_POST['update_account']=1; unset($_POST['save_account']); $_POST['user_id']=$userId; $_POST['email']=$email; }
    if (strpos($scenario,'account_identity_')===0) {
        $conn->query("UPDATE tbl_useraccount SET google_sub='known-management-identity',google_email_verified=1 WHERE user_id={$userId}");
        if($scenario==='account_identity_changed')$_POST['email']='changed-'.$email;
    }
    if ($scenario==='account_remove') { $_POST['roles']=['admin']; $_POST['primary_role']='admin'; $_POST['email']=strtolower($tag).'@sksu.edu.ph'; }
    if ($scenario==='account_missing') unset($_POST['management_college_id']);
    if ($scenario==='account_missing_org') unset($_POST['management_organization_id']);
    if ($scenario==='account_foreign_org') $_POST['management_organization_id']=$otherOrganizationId;
    if ($scenario==='account_foreign_campus') $_POST['management_campus_id']=4294967295;
    if ($scenario==='account_inactive_org') $conn->query("UPDATE tbl_management_organizations SET status='INACTIVE' WHERE organization_id=".$organizationId);
    if ($scenario==='account_invalid') $_POST['management_college_id']=$otherScope['college_id']+999999;
    if ($scenario==='account_array') $_POST['management_college_id']=[1];
    if ($scenario==='account_bad_csrf') $_POST['csrf_token']='bad';
    if ($scenario==='account_mixed_gmail') { $_POST['roles']=['admin','management']; }
    if ($scenario==='account_multi') { $_POST['email']=strtolower($tag).'@sksu.edu.ph'; $_POST['roles']=['program_chair','management']; $_POST['program_chair_college_id']=$otherScope['college_id']; $_POST['primary_role']='program_chair'; }
    if ($scenario==='account_forbidden') $_SESSION['role']='management';
    if ($scenario==='account_load') $_POST=['load_accounts'=>1,'response_format'=>'json'];
    if ($scenario==='account_page') { $handler='administrator/manage-accounts.php'; $_POST=[]; $_SERVER['REQUEST_METHOD']='GET'; }
} elseif (strpos($scenario,'identity_')===0) {
    $handler='';
    $identity=['email'=>$email,'sub'=>'management-qa-google-sub','email_verified'=>true,'name'=>$tag];
    if($scenario==='identity_unknown')$identity['email']='unknown-'.$email;
    if($scenario==='identity_unverified')$identity['email_verified']=false;
    if($scenario==='identity_bad_domain')$identity['email']=strtolower($tag).'@example.org';
    if($scenario==='identity_sub')$conn->query("UPDATE tbl_useraccount SET google_sub='different-sub' WHERE user_id={$userId}");
    if($scenario==='identity_inactive')$conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$userId}");
    if($scenario==='identity_revoked')$conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId}");
    if($scenario==='identity_missing_college')$conn->query("UPDATE tbl_useraccount SET management_college_id=NULL WHERE user_id={$userId}");
    if($scenario==='identity_missing_org')$conn->query("UPDATE tbl_useraccount SET management_organization_id=NULL WHERE user_id={$userId}");
    if($scenario==='identity_inactive_org')$conn->query("UPDATE tbl_management_organizations SET status='INACTIVE' WHERE organization_id=".$organizationId);
    if($scenario==='identity_college_inactive')$conn->query('UPDATE tbl_college SET status=\'inactive\' WHERE college_id='.$scope['college_id']);
    $_SESSION=[];
} elseif (strpos($scenario,'oauth_')===0) {
    $_SESSION=[]; $_GET=[]; $handler='backend/auth_google_callback.php';
    $_SESSION=['management_login_csrf'=>'management-login-qa','management_google_oauth_state'=>'management-state','management_google_oauth_nonce'=>'management-nonce','management_google_oauth_started_at'=>time(),
        'google_oauth_state'=>'main-state','google_oauth_nonce'=>'main-nonce','student_google_oauth_state'=>'student-state','student_google_oauth_nonce'=>'student-nonce','google_oauth_portal'=>'management'];
    $_GET=['state'=>'management-state','error'=>'access_denied'];
    if($scenario==='oauth_invalid')$_GET['state']='unknown-state';
    if($scenario==='oauth_expired')$_SESSION['management_google_oauth_started_at']=time()-901;
    if($scenario==='oauth_main_cancelled')$_GET['state']='main-state';
    if($scenario==='oauth_student_cancelled') {
        $_GET['state']='student-state'; $_SESSION['student_google_oauth_email']=strtolower($tag).'@sksu.edu.ph'; $_SESSION['student_google_oauth_started_at']=time();
    }
    if($scenario==='oauth_start' || $scenario==='oauth_start_bad_csrf') {
        $handler='backend/auth_google_management_start.php';$_GET=[];$_POST=['csrf_token'=>$scenario==='oauth_start'?'management-login-qa':'bad'];$_SERVER['REQUEST_METHOD']='POST';
    }
} elseif (in_array($scenario,['legacy_login','legacy_choose_management'],true)) {
    $conn->query("UPDATE tbl_useraccount SET email='".strtolower($tag)."@sksu.edu.ph' WHERE user_id={$userId}");
    $_SESSION=[]; $_GET=[]; $_SERVER['REQUEST_METHOD']='POST'; $handler='index.php';
    if($scenario==='legacy_login') {
        $hash=password_hash('management-qa-password',PASSWORD_DEFAULT);$conn->query("UPDATE tbl_useraccount SET password='".$hash."' WHERE user_id={$userId}");
        $_POST=['login'=>1,'email'=>strtolower($tag).'@sksu.edu.ph','password'=>'management-qa-password'];
    } else {
        $account=synk_find_useraccount_by_id($conn,$userId);
        synk_store_pending_role_login($account,[['role'=>'management','is_primary'=>true,'status'=>'active']],['auth_method'=>'legacy']);
        $_POST=['complete_role_login'=>1,'selected_role'=>'management'];
    }
} else {
    if($scenario==='login') { $handler='management/login.php'; $_SESSION=[]; }
    if($scenario==='students' || strpos($scenario,'students_')===0) { $handler='management/students.php'; $_GET['search']=$tag; }
    if($scenario==='students_page')$_GET['page']=2;
    if($scenario==='students_foreign')$_GET['program_id']=$foreignProgram['program_id'];
    if($scenario==='students_array')$_GET['search']=['invalid'];
    if($scenario==='students_empty')$_GET['search']='unknown-student-'.$tag;
    if($scenario==='students_wildcard')$_GET['search']=$tag.'%';
    if($scenario==='students_xss')$_GET['search']='<img src=x onerror=alert(1)>';
    if($scenario==='students_otherterm')$_GET['term']=$term['ay_id'].':'.($term['semester']===1?2:1);
    if($scenario==='contributions') {
        $handler='management/contributions.php';
        $clone('tbl_management_contributions',[],['college_id'=>$scope['college_id'],'organization_id'=>$organizationId,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'exam_term'=>$term['exam_term'],'title'=>strtoupper($tag).' CONTRIBUTION','contribution_date'=>'2026-10-26','amount'=>'25.50']);
    }
    if($scenario==='events') {
        $handler='management/events.php';
        $clone('tbl_management_events',[],['college_id'=>$scope['college_id'],'organization_id'=>$organizationId,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'exam_term'=>$term['exam_term'],'title'=>strtoupper($tag).' EVENT','event_date'=>'2026-10-26']);
    }
    if($scenario==='violations') {
        $handler='management/violations.php';
        $clone('tbl_management_violations',[],['college_id'=>$scope['college_id'],'organization_id'=>$organizationId,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'violation_type'=>strtoupper($tag).' VIOLATION','amount'=>'50.25']);
    }
    if($scenario==='forged_college')$_GET['college_id']=$otherScope['college_id'];
    if($scenario==='forged_campus')$_GET['campus_id']=999999;
    if($scenario==='financial')$handler='management/financial.php';
    if($scenario==='bad_term')$_GET['term']='1:9';
    if($scenario==='bad_exam_term')$_GET['exam_term']='PRELIM';
    if($scenario==='array_exam_term')$_GET['exam_term']=['MIDTERM'];
    if($scenario==='view_final')$_GET['exam_term']='FINAL';
    if($scenario==='view_final_fields') { $_GET['view_ay_id']=(string)$term['ay_id']; $_GET['view_semester']=(string)$term['semester']; $_GET['exam_term']='FINAL'; }
    if($scenario==='revoked')$conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$userId}");
    if($scenario==='no_google')$_SESSION['auth_method']='legacy';
    if($scenario==='wrong_role')$_SESSION['role']='admin';
    if($scenario==='anonymous')$_SESSION=[];
    if($scenario==='reassigned')$conn->query('UPDATE tbl_useraccount SET management_organization_id='.$otherOrganizationId.',management_college_id='.$otherScope['college_id'].' WHERE user_id='.$userId);
    if($scenario==='analytics')$handler='';
    if($scenario==='organizations_page') {
        $_SESSION['role']='admin';
        $_SESSION['csrf_token']=bin2hex(random_bytes(32));
        synk_persist_useraccount_roles($conn,$userId,['admin'],'admin');
        $handler='administrator/manage-organizations.php';
    }
}
if(strpos($scenario,'officers_')===0){
    require_once $root.'/backend/management_officers_helper.php';
    $faculty=$clone('tbl_faculty',[],['first_name'=>'QA Faculty','last_name'=>$tag,'status'=>'active']);
    $clone('tbl_useraccount_faculty_links',[],['user_id'=>$userId,'faculty_id'=>$faculty]);
    $sid=$students[0];$studentEmail=strtolower($tag).'student@sksu.edu.ph';
    $stmt=$conn->prepare('UPDATE tbl_student_management SET email_address=? WHERE student_id=?');$stmt->bind_param('si',$studentEmail,$sid);$stmt->execute();$stmt->close();
    $flags=['can_view'=>'1','can_attendance'=>'0','can_payments'=>'0'];
    if($scenario==='officers_attendance')$flags=['can_view'=>'0','can_attendance'=>'1','can_payments'=>'0'];
    if($scenario==='officers_payments')$flags=['can_view'=>'0','can_attendance'=>'0','can_payments'=>'1'];
    synk_management_officer_write($conn,$scope,'officer_save',$flags+['student_id'=>(string)$sid,'expires_on'=>date('Y-m-d',strtotime('+30 days')),'current_term'=>$term['key'],'current_exam_term'=>$term['exam_term'],'current_period_version'=>$term['period_version']]);
    $handler='management/officers.php';
    if(in_array($scenario,['officers_attendance','officers_viewer'],true)){
        require_once $root.'/backend/management_attendance_helper.php';
        $eventId=$clone('tbl_management_events',[],['college_id'=>$scope['college_id'],'organization_id'=>$organizationId,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'exam_term'=>$term['exam_term'],'title'=>$tag.' EVENT','event_date'=>date('Y-m-d'),'status'=>'ACTIVE','absence_fine'=>'10.00']);
        synk_management_attendance_write($conn,$scope,'attendance_open',['event_id'=>$eventId,'current_term'=>$term['key'],'current_exam_term'=>$term['exam_term'],'current_period_version'=>$term['period_version']]);
    }
    if($scenario!=='officers_page'){
        $grant=synk_management_query($conn,'SELECT * FROM tbl_management_officers WHERE student_id=?','i',[$sid])[0];
        $_SESSION['user_id']=(int)$grant['user_id'];$_SESSION['email']=$studentEmail;
        if($scenario==='officers_attendance'||$scenario==='officers_viewer')$handler='management/events.php';
        if($scenario==='officers_payments'){$handler='management/students.php';$_GET['search']=$tag;}
    }
}
$take=static function () use($conn,$tag):array {
    return ['accounts'=>$conn->query("SELECT user_id,email,role,college_id,management_college_id,management_organization_id,status,google_sub FROM tbl_useraccount WHERE username='".$tag."' ORDER BY user_id")->fetch_all(MYSQLI_ASSOC),
        'roles'=>$conn->query("SELECT r.* FROM tbl_useraccount_roles r JOIN tbl_useraccount u ON u.user_id=r.user_id WHERE u.username='".$tag."' ORDER BY r.account_role_id")->fetch_all(MYSQLI_ASSOC)];
};
$before=$take();
register_shutdown_function(static function()use($conn,$take,$before,$scope,$otherScope,$userId,$tag,$baseline,$term,&$result,$scenario):void {
    $body='';while(ob_get_level()>0)$body=ob_get_clean().$body;
    $location='';foreach(headers_list()as$header)if(stripos($header,'Location:')===0)$location=substr($header,10);
    try {
        $after=$take();$session=$_SESSION;unset($session['management_login_csrf']);
        $decoded=json_decode($body,true); if($scenario==='account_load' && is_array($decoded)) $decoded['accounts']=array_values(array_filter($decoded['accounts'],static function($a)use($userId){return (int)$a['id']===$userId;}));
        $data=['response'=>$result ?? ($decoded ?? $body),'status'=>http_response_code()?:200,'location'=>$location,'before'=>$before,'after'=>$after,'session'=>$session,
            'scope'=>$scope,'other_scope'=>$otherScope,'baseline'=>$baseline,'term'=>$term,'tag'=>$tag];
        $conn->discardFixtures();if(session_status()===PHP_SESSION_ACTIVE)session_destroy();header_remove();http_response_code(200);
        if (defined('SYNK_MANAGEMENT_QA_RENDER') && is_string($data['response']) && strpos($data['response'],'<!DOCTYPE html>')!==false) {
            header('Content-Type: text/html; charset=utf-8');
            $base = in_array($scenario,['account_page','organizations_page'],true) ? '/synk/administrator/' : '/synk/management/';
            echo str_replace('<head>', '<head><base href="'.$base.'">', $data['response']);
        } else { header('Content-Type: application/json');echo json_encode($data); }
    } catch(Throwable$error){$conn->discardFixtures();http_response_code(500);echo json_encode(['fixture_error'=>$error->getMessage(),'output'=>substr($body,0,1800)]);}
});
ob_start();
if($handler==='') {
    if($scenario==='analytics')$result=synk_management_analytics($conn,$scope,$term);
    else {try{synk_management_complete_verified_login($conn,$identity);$result=['status'=>'ok'];}catch(DomainException$error){$result=['status'=>'error','message'=>$error->getMessage()];}}
    exit;
}
session_write_close(); $_SERVER['PHP_SELF']='/synk/'.$handler;
chdir(dirname($root.'/'.$handler));require $root.'/'.$handler;
