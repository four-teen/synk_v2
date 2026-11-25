<?php
// Run only through the random localhost test route or CLI. All fixtures roll back.
if (PHP_SAPI !== 'cli' && !defined('SYNK_MANAGEMENT_QA')) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__, 2);
$reportInput=array_intersect_key($_GET,array_flip(['report_type','generate','institution','prepared_by','checked_by','approved_by','qa_event']));
$registerInput=array_intersect_key($_GET,array_flip(['search','date_from','date_to','page','exam_term','qa_cashier']));
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
    $evaluationDetailIndex = [
        'students_detail_submitted'=>0, 'students_detail_pending'=>1, 'students_detail_partial'=>2, 'students_detail_unlinked'=>3,
    ][$scenario] ?? null;
    if ($evaluationDetailIndex !== null || in_array($scenario, ['students','students_evaluation_midterm','students_evaluation_history'], true)) {
        [$faculty, $secondFaculty, $retainedFaculty] = array_map('intval', array_column($conn->query(
            "SELECT faculty_id FROM tbl_faculty WHERE status='active' ORDER BY faculty_id LIMIT 3"
        )->fetch_all(MYSQLI_ASSOC), 'faculty_id'));
        $college = $scope['college_id'];
        $otherCollege = $otherScope['college_id'];
        $semester = $term['semester'];
        $otherSemester = $semester === 1 ? 2 : 1;
        $clone('tbl_management_period_settings', [], ['college_id'=>$college, 'organization_id'=>$organizationId,
            'ay_id'=>$term['ay_id'], 'semester'=>$semester, 'current_exam_term'=>'FINAL']);
        $conn->query("UPDATE tbl_student_management_enrolled_subjects SET faculty_id={$faculty}
            WHERE college_id={$college} AND student_id IN (".implode(',', array_slice($students,0,8)).')');
        $conn->query('UPDATE tbl_student_management_enrolled_subjects SET faculty_id=0 WHERE student_id='.$students[3]);
        $enroll = static function (int $student, array $template, array $overrides) use ($clone, $students, $tag): void {
            $clone('tbl_student_management_enrolled_subjects', $template,
                $overrides + ['student_id'=>$students[$student], 'source_file_name'=>$tag.'.xlsx']);
        };
        $enroll(2, $templates[1], ['faculty_id'=>$secondFaculty]);
        $enroll(2, $foreignTemplate, ['faculty_id'=>$secondFaculty]);
        $enroll(5, $foreignTemplate, ['faculty_id'=>$faculty]);
        $enroll(6, $templates[1], ['faculty_id'=>$secondFaculty, 'is_active'=>0]);
        $otherTermEnrollment = ['semester'=>$otherSemester, 'offering_id'=>0, 'section_id'=>0];
        $enroll(7, $templates[0], ['faculty_id'=>$faculty] + $otherTermEnrollment);
        $enroll(7, $templates[1], ['faculty_id'=>$secondFaculty, 'is_active'=>0] + $otherTermEnrollment);
        foreach ([
            [0,$faculty,$college,$semester,'submitted'],
            [1,$faculty,$college,$semester,'draft'],
            [2,$faculty,$college,$semester,'submitted'],
            [2,$secondFaculty,$otherCollege,$semester,'submitted'],
            [4,$faculty,0,$semester,'submitted'],
            [5,$faculty,0,$semester,'submitted'],
            [6,$faculty,$college,$semester,'submitted'],
            [7,$faculty,$college,$otherSemester,'submitted'],
            [7,$retainedFaculty,$college,$otherSemester,'submitted'],
        ] as [$student,$instructor,$owner,$evaluationSemester,$status]) {
            $clone('tbl_student_faculty_evaluations', [], ['student_id'=>$students[$student], 'faculty_id'=>$instructor,
                'college_id'=>$owner, 'ay_id'=>$term['ay_id'], 'semester'=>$evaluationSemester, 'submission_status'=>$status,
                'question_count'=>15, 'comment_text'=>'QA comment', 'evaluation_token'=>bin2hex(random_bytes(16)),
                'final_submitted_at'=>$status==='submitted' ? date('Y-m-d H:i:s') : null]);
        }
        $_GET['exam_term'] = $scenario === 'students_evaluation_midterm' ? 'MIDTERM' : 'FINAL';
        if ($scenario === 'students_evaluation_history') $_GET['term'] = $term['ay_id'].':'.$otherSemester;
        if ($evaluationDetailIndex !== null) {
            $handler = 'backend/query_management.php';
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SESSION['management_csrf_token'] = 'evaluation-qa';
            $_POST = ['action'=>'student_detail','csrf_token'=>'evaluation-qa','term'=>$term['key'],'exam_term'=>'FINAL',
                'student_id'=>$students[$evaluationDetailIndex]];
        }
    }
    if($scenario==='students_page')$_GET['page']=2;
    if($scenario==='students_foreign')$_GET['program_id']=$foreignProgram['program_id'];
    if($scenario==='students_array')$_GET['search']=['invalid'];
    if($scenario==='students_empty')$_GET['search']='unknown-student-'.$tag;
    if($scenario==='students_wildcard')$_GET['search']=$tag.'%';
    if($scenario==='students_xss')$_GET['search']='<img src=x onerror=alert(1)>';
    if($scenario==='students_otherterm')$_GET['term']=$term['ay_id'].':'.($term['semester']===1?2:1);
    if (strpos($scenario,'permits')===0) {
        $handler='management/student-permits.php';
        $clone('tbl_management_period_settings',[],['college_id'=>$scope['college_id'],'organization_id'=>$organizationId,
            'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'current_exam_term'=>'FINAL']);
        $programId=(int)$templates[0]['program_id'];
        $sectionId=$clone('tbl_sections',[],['program_id'=>$programId,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],
            'year_level'=>2,'section_name'=>'2-QA','full_section'=>'Permit QA '.$tag,'status'=>'active']);
        $fixtureStudents=array_slice($students,0,5);
        $conn->query("UPDATE tbl_student_management SET middle_name='',suffix_name='' WHERE student_id IN (".implode(',',$fixtureStudents).')');
        $conn->query("UPDATE tbl_student_management SET last_name='DE LA CRUZ',first_name='JUAN MIGUEL',middle_name='SANTOS' WHERE student_id=".$fixtureStudents[0]);
        $conn->query('UPDATE tbl_student_management_enrolled_subjects SET section_id='.$sectionId.',year_level=2 WHERE student_id IN ('.implode(',',$fixtureStudents).') AND offering_id='.(int)$templates[0]['offering_id']);
        $conn->query("UPDATE tbl_student_management_enrolled_subjects SET subject_code='QA-ELECTIVE' WHERE student_id=".$students[0].' AND offering_id='.(int)$templates[1]['offering_id']);
        if ($scenario!=='permits') $_GET+=['generate'=>'1','program_id'=>(string)$programId,'year_level'=>'2','section_id'=>(string)$sectionId];
        if ($scenario==='permits_midterm') $_GET['exam_term']='MIDTERM';
        if ($scenario==='permits_wrong_year') $_GET['year_level']='1';
        if ($scenario==='permits_anonymous') $_SESSION=[];
    }
    if($scenario==='contributions') {
        $handler='management/contributions.php';
        $clone('tbl_management_contributions',[],['college_id'=>$scope['college_id'],'organization_id'=>$organizationId,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'exam_term'=>$term['exam_term'],'title'=>strtoupper($tag).' CONTRIBUTION','contribution_date'=>'2026-10-26','amount'=>'25.50']);
    }
    if($scenario==='events') {
        $handler='management/events.php';
        $clone('tbl_management_events',[],['college_id'=>$scope['college_id'],'organization_id'=>$organizationId,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'exam_term'=>$term['exam_term'],'title'=>strtoupper($tag).' EVENT','event_date'=>'2026-10-26']);
    }
    if(in_array($scenario,['imported_event','imported_event_default','imported_event_staff','closed_cleanup'],true)) {
        $cleanup=$scenario==='closed_cleanup';
        $handler=$cleanup ? 'management/events.php' : 'management/flag-raising.php';
        if ($scenario!=='imported_event_staff') synk_persist_useraccount_roles($conn,$userId,['management','admin'],'management');
        $period=['college_id'=>$scope['college_id'],'organization_id'=>$organizationId,'ay_id'=>$term['ay_id'],'semester'=>$term['semester']];
        $clone('tbl_management_period_settings',[], $period+['current_exam_term'=>'FINAL']);
        $period['exam_term']=$cleanup ? 'FINAL' : 'MIDTERM';
        $eventId=$clone('tbl_management_events',[], $period+['title'=>$cleanup ? 'CLEAN UP DRIVE QA' : 'FLAG RAISING CEREMONY QA',
            'event_date'=>$cleanup ? '2026-11-12' : '2026-10-10',
            'source_metadata'=>json_encode(['attendance_import'=>['template'=>$cleanup ? 'CUD' : 'FRC']])]);
        $clone('tbl_management_attendance_events',[], $period+['event_id'=>$eventId,'opened_by'=>$userId,'closed_by'=>$userId,
            'attendance_status'=>'CLOSED','closed_at'=>date('Y-m-d H:i:s')]);
        foreach (array_slice($students,0,2) as $studentId) {
            $clone('tbl_management_attendance_roster',[],['event_id'=>$eventId,'student_id'=>$studentId]);
        }
        $clone('tbl_management_attendance_logs',[],['event_id'=>$eventId,'student_id'=>$students[0],'recorded_by'=>$userId]);
        if ($scenario==='imported_event' || $cleanup) {
            $_GET['exam_term']=$period['exam_term'];
            $_GET['event_id']=(string)$eventId;
        } else {
            $clone('tbl_management_events',[], $period+['title'=>'REGULAR MIDTERM EVENT','event_date'=>'2026-10-10']);
        }
    }
    if($scenario==='violations') {
        $handler='management/violations.php';
        $clone('tbl_management_violations',[],['college_id'=>$scope['college_id'],'organization_id'=>$organizationId,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'violation_type'=>strtoupper($tag).' VIOLATION','amount'=>'50.25']);
    }
    if($scenario==='forged_college')$_GET['college_id']=$otherScope['college_id'];
    if($scenario==='forged_campus')$_GET['campus_id']=999999;
    if($scenario==='financial')$handler='management/financial.php';
    if($scenario==='exemptions')$handler='management/exemptions.php';
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
if (strpos($scenario,'payment_corrections_') === 0) {
    $handler = 'management/payment-corrections.php';
    if ($scenario !== 'payment_corrections_staff_denied') {
        $faculty = $clone('tbl_faculty',[],['first_name'=>'QA Faculty','last_name'=>$tag,'status'=>'active']);
        $clone('tbl_useraccount_faculty_links',[],['user_id'=>$userId,'faculty_id'=>$faculty]);
    }
    $_GET['exam_term'] = 'FINAL';
    if ($scenario === 'payment_corrections_dashboard') $handler = 'management/index.php';
    if ($scenario === 'payment_corrections_financial') $handler = 'management/financial.php';
    if ($scenario === 'payment_corrections_foreign') $_GET['organization_id'] = (string)$otherOrganizationId;
    $correctionPosts = ['payment_corrections_delete','payment_corrections_delete_one','payment_corrections_reason_required',
        'payment_corrections_empty_selection','payment_corrections_wrong_context','payment_corrections_csrf'];
    if (in_array($scenario,array_merge(['payment_corrections_search','payment_corrections_account'],$correctionPosts),true)) {
        require_once $root.'/backend/management_payment_correction_helper.php';
        $clone('tbl_management_period_settings',[],['organization_id'=>$organizationId,'college_id'=>$scope['college_id'],
            'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'current_exam_term'=>'FINAL']);
        $term = synk_management_fetch_current_period($conn,$scope);
        $sid = $students[0];
        $paymentInput = ['term'=>$term['key'],'exam_term'=>$term['exam_term'],'current_term'=>$term['key'],
            'current_exam_term'=>$term['exam_term'],'current_period_version'=>$term['period_version']];
        $charges = [];
        foreach (['50.00','25.25','12.50'] as $index=>$charge) {
            $charges[] = 'C:'.synk_management_catalog_write($conn,$scope,'contributions','save',$paymentInput+[
                'title'=>'Correction QA contribution '.($index+1),'contribution_date'=>date('Y-m-d'),'amount'=>$charge])['record_id'];
        }
        $pay = static function (int $student, string $amount, array $items, string $number) use ($conn,$scope,$term,$paymentInput): int {
            $financial = synk_management_student_financial_overview($conn,$scope,$term,$student);
            return (int)synk_management_payment_post($conn,$scope,$paymentInput+['student_id'=>$student,'amount'=>$amount,
                'allocation_mode'=>'SELECTED','selected'=>json_encode($items),'receipt_number'=>$number,
                'account_version'=>$financial['account_version'],'request_key'=>bin2hex(random_bytes(32))])['payment_id'];
        };
        $receiptNumber = $tag.'-Receipt';
        $paymentId = $pay($sid,'75.25',array_slice($charges,0,2),$receiptNumber);
        $otherPaymentId = $pay($sid,'12.50',[$charges[2]],$tag.'-Second');
        if ($scenario === 'payment_corrections_search') {
            $_GET['search'] = strtolower($receiptNumber);
        } else {
            $_GET['student_id'] = (string)$sid;
        }
        if (in_array($scenario,$correctionPosts,true)) {
            $receipts = synk_management_correction_receipts($conn,$scope,$term,$sid);
            $ids = array_keys($receipts[$paymentId]['items']);
            if ($scenario === 'payment_corrections_delete_one') $ids = array_slice($ids,0,1);
            if ($scenario === 'payment_corrections_empty_selection') $ids = [];
            $_SESSION['management_csrf_token'] = 'correction-qa';
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['action'=>'delete','csrf_token'=>$scenario === 'payment_corrections_csrf' ? 'invalid' : 'correction-qa',
                'reason'=>$scenario === 'payment_corrections_reason_required' ? ' ' : 'Correction QA',
                'selection'=>json_encode($ids),'request_key'=>bin2hex(random_bytes(32)),
                'receipt_version'=>synk_management_correction_version(synk_management_correction_context($scope,$term,$sid),$receipts)];
            if ($scenario === 'payment_corrections_wrong_context') {
                $pay($students[1],'50.00',[$charges[0]],$tag.'-DifferentStudent');
                $_GET['student_id'] = (string)$students[1];
            }
            $correctionState = static function () use ($conn,$scope,$term,$sid,$paymentId,$otherPaymentId): array {
                return [
                    'paid'=>[synk_management_payment_receipt($conn,$paymentId,$sid)['net_amount'],
                        synk_management_payment_receipt($conn,$otherPaymentId,$sid)['net_amount']],
                    'reasons'=>array_column(synk_management_correction_history($conn,$scope,$term,$sid),'reason'),
                ];
            };
        }
    }
}
if(strpos($scenario,'officers_')===0){
    require_once $root.'/backend/management_officers_helper.php';
    $faculty=$clone('tbl_faculty',[],['first_name'=>'QA Faculty','last_name'=>$tag,'status'=>'active']);
    $clone('tbl_useraccount_faculty_links',[],['user_id'=>$userId,'faculty_id'=>$faculty]);
    $sid=$students[0];$studentEmail=strtolower($tag).'student@sksu.edu.ph';
    $stmt=$conn->prepare('UPDATE tbl_student_management SET email_address=? WHERE student_id=?');$stmt->bind_param('si',$studentEmail,$sid);$stmt->execute();$stmt->close();
    $flags=['can_view'=>'1','can_attendance'=>'0','can_payments'=>'0'];
    if($scenario==='officers_page')$flags['can_manage_events']='1';
    if(in_array($scenario,['officers_attendance','officers_financial_denied'],true))$flags=['can_view'=>'0','can_attendance'=>'1','can_payments'=>'0'];
    if(in_array($scenario,['officers_payments','officers_payment_corrections_hidden','officers_payment_corrections_denied',
        'officers_payment_corrections_post_denied'],true))$flags=['can_view'=>'0','can_attendance'=>'0','can_payments'=>'1'];
    if(strpos($scenario,'officers_manage_')===0){$managedKind=substr($scenario,16);$flags=['can_view'=>'0','can_attendance'=>'0','can_payments'=>'0','can_manage_'.$managedKind=>'1'];}
    if(($managedKind ?? '')==='events')$clone('tbl_management_events',[],['college_id'=>$scope['college_id'],'organization_id'=>$organizationId,'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'exam_term'=>$term['exam_term'],'title'=>$tag.' EVENT','event_date'=>date('Y-m-d'),'status'=>'ACTIVE','absence_fine'=>'10.00']);
    synk_management_officer_write($conn,$scope,'officer_save',$flags+['student_id'=>(string)$sid,'expires_on'=>date('Y-m-d',strtotime('+30 days')),'current_term'=>$term['key'],'current_exam_term'=>$term['exam_term'],'current_period_version'=>$term['period_version']]);
    $handler='management/officers.php';
    if ($scenario==='officers_page') {
        $peerEmail=strtolower($tag).'peer@sksu.edu.ph';
        $peer=$clone('tbl_useraccount',[],['username'=>'Peer Faculty','email'=>$peerEmail,'password'=>'unusable-test-password',
            'role'=>'management','status'=>'active','management_college_id'=>$scope['college_id'],'management_organization_id'=>$organizationId]);
        synk_persist_useraccount_roles($conn,$peer,['management'],'management');
        $peerFaculty=$clone('tbl_faculty',[],['first_name'=>'Peer Faculty','last_name'=>$tag,'status'=>'active']);
        $clone('tbl_useraccount_faculty_links',[],['user_id'=>$peer,'faculty_id'=>$peerFaculty]);
        synk_management_execute($conn,'UPDATE tbl_student_management SET first_name=?,email_address=? WHERE student_id=?',
            'ssi',['Peer Officer',strtolower($tag).'peerstudent@sksu.edu.ph',$students[1]]);
        $viewerSession=$_SESSION;
        $_SESSION=array_replace($_SESSION,['user_id'=>$peer,'username'=>'Peer Faculty','email'=>$peerEmail]);
        synk_management_officer_write($conn,$scope,'officer_save',['student_id'=>(string)$students[1],'can_view'=>'1',
            'expires_on'=>date('Y-m-d',strtotime('+30 days')),'current_term'=>$term['key'],'current_exam_term'=>$term['exam_term'],
            'current_period_version'=>$term['period_version']]);
        $_SESSION=$viewerSession;
    }
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
        if($scenario==='officers_financial_denied')$handler='management/financial.php';
        if($scenario==='officers_payment_corrections_hidden')$handler='management/financial.php';
        if(in_array($scenario,['officers_payment_corrections_denied','officers_payment_corrections_post_denied'],true))$handler='management/payment-corrections.php';
        if($scenario==='officers_payment_corrections_post_denied'){
            $_SERVER['REQUEST_METHOD']='POST'; $_GET['student_id']=(string)$students[1];
            $_SESSION['management_csrf_token']='correction-qa';
            $_POST=['action'=>'delete','csrf_token'=>'correction-qa','selection'=>'[1]','reason'=>'Officer test'];
        }
        if(isset($managedKind))$handler='management/'.$managedKind.'.php';
    }
}
if($scenario==='reports'){
    require_once $root.'/backend/management_reports_helper.php';
    require_once $root.'/backend/management_payment_helper.php';
    $reportPost=['term'=>$term['key'],'exam_term'=>$term['exam_term'],'current_term'=>$term['key'],'current_exam_term'=>$term['exam_term'],'current_period_version'=>$term['period_version']];
    $reportEvents=[];
    foreach(['CLOSED','OPEN']as$state){
        $id=synk_management_catalog_write($conn,$scope,'events','save',$reportPost+['title'=>'General Assembly '.$state,'event_date'=>date('Y-m-d'),'absence_fine'=>'25.50'])['record_id'];
        $opened=synk_management_attendance_write($conn,$scope,'attendance_open',$reportPost+['event_id'=>$id]);
        $conn->query('DELETE FROM tbl_management_attendance_roster WHERE event_id='.$id.' AND student_id NOT IN ('.implode(',',$students).')');
        foreach(array_slice($students,-2)as$sid)synk_management_attendance_write($conn,$scope,'attendance_scan',$reportPost+['event_id'=>$id,'qr_code'=>synk_management_student_qr($conn,$sid)]);
        if($state==='CLOSED')synk_management_attendance_write($conn,$scope,'attendance_close',$reportPost+['event_id'=>$id,'attendance_revision'=>$opened['attendance']['revision'],'confirm'=>'1']);
        $reportEvents[$state]=$id;
    }
    $charge=synk_management_catalog_write($conn,$scope,'contributions','save',$reportPost+['title'=>'Organization Contribution','contribution_date'=>date('Y-m-d'),'amount'=>'100'])['record_id'];
    foreach([0=>['125.50',['C:'.$charge,'E:'.$reportEvents['CLOSED']]],1=>['100.00',['C:'.$charge]]]as$i=>$values){$financial=synk_management_student_financial_overview($conn,$scope,$term,$students[$i]);synk_management_payment_post($conn,$scope,$reportPost+['student_id'=>$students[$i],'amount'=>$values[0],'allocation_mode'=>'SELECTED','selected'=>json_encode($values[1]),'account_version'=>$financial['account_version'],'request_key'=>bin2hex(random_bytes(32))]);}
    $_GET=array_merge($_GET,array_diff_key($reportInput,['qa_event'=>true]));
    if(in_array($_GET['report_type'] ?? '',['event_attendance','absence_fines'],true))$_GET['event_id']=$reportEvents[($reportInput['qa_event'] ?? '')==='open'?'OPEN':'CLOSED'];
    $handler='management/reports.php';
}
$collectionFixture = $scenario === 'dashboard_collections' || strpos($scenario,'collection_') === 0;
if ($collectionFixture || $scenario === 'financial_register') {
    $clone('tbl_management_period_settings', [], ['college_id'=>$scope['college_id'],'organization_id'=>$organizationId,
        'ay_id'=>$term['ay_id'],'semester'=>$term['semester'],'current_exam_term'=>'FINAL']);
    $collectionPeriod = synk_management_fetch_current_period($conn,$scope);
    $record = static function (int $studentId, string $amount, array $overrides = []) use ($clone,$scope,$collectionPeriod,$tag,$userId): int {
        return $clone('tbl_management_payments', [], $overrides + [
            'receipt_number'=>$tag.'-'.bin2hex(random_bytes(5)), 'request_key'=>bin2hex(random_bytes(32)),
            'payload_hash'=>bin2hex(random_bytes(32)), 'student_id'=>$studentId, 'college_id'=>$scope['college_id'],
            'organization_id'=>$scope['organization_id'], 'ay_id'=>$collectionPeriod['ay_id'],
            'semester'=>$collectionPeriod['semester'], 'exam_term'=>$collectionPeriod['exam_term'], 'allocation_mode'=>'SELECTED',
            'amount'=>$amount, 'balance_before'=>$amount, 'balance_after'=>'0.00',
            'student_number'=>$tag.'-'.$studentId, 'student_name'=>'QA payer '.$studentId,
            'organization_code'=>$tag, 'organization_name'=>$tag, 'period_text'=>$collectionPeriod['period_text'],
            'recorded_by'=>$userId, 'recorded_by_name'=>'QA cashier', 'recorded_at'=>'2026-11-25 00:30:00',
        ]);
    };
}
if ($scenario === 'financial_register') {
    $manila = new DateTimeZone('Asia/Manila');
    $utc = new DateTimeZone('UTC');
    $day = new DateTimeImmutable('today',$manila);
    $start = $day->setTimezone($utc)->format('Y-m-d H:i:s');
    $lastSecond = $day->modify('+1 day -1 second')->setTimezone($utc)->format('Y-m-d H:i:s');
    $yesterday = $day->modify('-1 second')->setTimezone($utc)->format('Y-m-d H:i:s');
    $tomorrow = $day->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    $archive = $day->modify('-2 days')->setTimezone($utc)->format('Y-m-d H:i:s');
    $secondCashier = $clone('tbl_useraccount',[],[
        'username'=>$tag.' Cashier','email'=>'cashier-'.$email,'password'=>'fixture','role'=>'management','status'=>'inactive',
    ]);
    $siblingOrganization = $clone('tbl_management_organizations',[],[
        'college_id'=>$scope['college_id'],'organization_code'=>$tag.'-other','organization_name'=>$tag.' Other',
    ]);
    $receipt = $record($students[0],'125.50',[
        'receipt_number'=>$tag.'-EDGE-0001','student_name'=>'Alex Payer','student_number'=>'QA-2026-501',
        'recorded_at'=>$start,'recorded_by_name'=>'Morning Cashier',
    ]);
    $record($students[0],'50.00',[
        'receipt_number'=>$tag.'-EDGE-0002','student_name'=>'Alex Payer','student_number'=>'QA-2026-501',
        'recorded_at'=>$lastSecond,'recorded_by'=>$secondCashier,'recorded_by_name'=>'Evening Cashier',
    ]);
    $record($students[1],'50.00',['receipt_number'=>$tag.'-PREVIOUS','student_name'=>'Previous Day Payer','recorded_at'=>$yesterday,
        'recorded_by'=>$secondCashier,'recorded_by_name'=>'Evening Cashier']);
    $record($students[2],'95.75',['receipt_number'=>$tag.'-NEXT','student_name'=>'Next Day Payer','recorded_at'=>$tomorrow,
        'recorded_by_name'=>'Morning Cashier']);
    for ($i=1; $i<=24; $i++) {
        $record($students[$i],'1.00',['receipt_number'=>$tag.'-ARCHIVE-'.sprintf('%02d',$i),
            'student_name'=>'Earlier Payer '.$i,'recorded_at'=>$archive,'recorded_by_name'=>'Morning Cashier']);
    }
    $record($students[0],'80.00',['exam_term'=>'MIDTERM','student_name'=>'Midterm Payer','recorded_at'=>$start]);
    $record($students[0],'900.00',['semester'=>$term['semester']===1 ? 2 : 1,'student_name'=>'Other Semester Payer','recorded_at'=>$start]);
    $record($students[0],'900.00',['ay_id'=>$term['ay_id']+1,'student_name'=>'Other Year Payer','recorded_at'=>$start]);
    $record($students[0],'900.00',['organization_id'=>$siblingOrganization,'student_name'=>'Other Organization Payer','recorded_at'=>$start]);
    $record($foreignStudent,'900.00',['college_id'=>$otherScope['college_id'],'organization_id'=>$otherOrganizationId,
        'student_name'=>'Other College Payer','recorded_at'=>$start]);
    foreach (['C:1'=>'100.00','E:2'=>'25.50'] as $key=>$amount) {
        $clone('tbl_management_payment_items',[],['payment_id'=>$receipt,'charge_key'=>$key,'title'=>'QA '.$key,
            'charge_amount'=>$amount,'paid_before'=>'0.00','amount'=>$amount,'balance_after'=>'0.00']);
    }
    $handler = 'management/financial.php';
    $_GET = array_replace(['term'=>$term['key'],'exam_term'=>'FINAL'],array_diff_key($registerInput,['qa_cashier'=>true]));
    if (($registerInput['qa_cashier'] ?? '') === 'evening') $_GET['cashier_id'] = (string)$secondCashier;
}
if ($collectionFixture) {
    $firstReceipt = $record($students[0],'125.50',['student_name'=>'Older Payer Name']);
    $secondReceipt = $record($students[0],'50.00',['student_name'=>'Alex Payer']);
    $record($students[1],'50.00',['student_name'=>'Retained Payer']);
    $conn->query('UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE student_id='.(int)$students[1]);
    $record($students[0],'200.00',['exam_term'=>'MIDTERM','student_name'=>'Midterm Only']);
    $record($students[0],'300.00',['semester'=>$term['semester'] === 1 ? 2 : 1]);
    $record($students[0],'400.00',['ay_id'=>$term['ay_id'] + 1]);
    $record($foreignStudent,'500.00',['college_id'=>$otherScope['college_id'],'organization_id'=>$otherOrganizationId]);
    foreach ([[$firstReceipt,'C:1','QA Contribution','100.00'],[$firstReceipt,'E:2','QA Event Fine','25.50'],
        [$secondReceipt,'V:3','QA Violation Fine','50.00']] as [$paymentId,$chargeKey,$title,$amount]) {
        $clone('tbl_management_payment_items', [], ['payment_id'=>$paymentId,'charge_key'=>$chargeKey,
            'title'=>$title,'charge_amount'=>$amount,'paid_before'=>'0.00','amount'=>$amount,'balance_after'=>'0.00']);
    }
    $_GET['exam_term'] = 'MIDTERM';
    if ($scenario !== 'dashboard_collections') {
        $handler = 'backend/query_management.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION['management_csrf_token'] = 'collection-qa';
        $_POST = [
            'action'=>in_array($scenario,['collection_detail','collection_foreign_student'],true) ? 'collection_student_payments' : 'collection_students',
            'csrf_token'=>'collection-qa', 'collection_period'=>$collectionPeriod['period_key'],
            'term'=>$term['key'], 'exam_term'=>'MIDTERM',
        ];
        if ($scenario === 'collection_detail') $_POST['student_id'] = $students[0];
        if ($scenario === 'collection_foreign_student') $_POST['student_id'] = $foreignStudent;
        if ($scenario === 'collection_stale') $_POST['collection_period'] = $term['key'].':MIDTERM';
        if ($scenario === 'collection_wrong_scope') $_POST['organization_id'] = $otherOrganizationId;
        $searches = [
            'collection_search_name'=>'payer, aLeX',
            'collection_search_id'=>$tag.'-'.$students[1],
            'collection_search_wrong_period'=>'Midterm Only',
            'collection_search_wildcard'=>'Payer%',
        ];
        if (isset($searches[$scenario])) $_POST['search'] = $searches[$scenario];
    }
}
$take=static function () use($conn,$tag):array {
    return ['accounts'=>$conn->query("SELECT user_id,email,role,college_id,management_college_id,management_organization_id,status,google_sub FROM tbl_useraccount WHERE username='".$tag."' ORDER BY user_id")->fetch_all(MYSQLI_ASSOC),
        'roles'=>$conn->query("SELECT r.* FROM tbl_useraccount_roles r JOIN tbl_useraccount u ON u.user_id=r.user_id WHERE u.username='".$tag."' ORDER BY r.account_role_id")->fetch_all(MYSQLI_ASSOC)];
};
$before=$take();
$correctionState = $correctionState ?? null;
register_shutdown_function(static function()use($conn,$take,$before,$scope,$otherScope,$userId,$tag,$baseline,$term,&$result,$scenario,$correctionState):void {
    $body='';while(ob_get_level()>0)$body=ob_get_clean().$body;
    $location='';foreach(headers_list()as$header)if(stripos($header,'Location:')===0)$location=substr($header,10);
    try {
        $after=$take();$session=$_SESSION;unset($session['management_login_csrf']);
        $decoded=json_decode($body,true); if($scenario==='account_load' && is_array($decoded)) $decoded['accounts']=array_values(array_filter($decoded['accounts'],static function($a)use($userId){return (int)$a['id']===$userId;}));
        $data=['response'=>$result ?? ($decoded ?? $body),'status'=>http_response_code()?:200,'location'=>$location,'before'=>$before,'after'=>$after,'session'=>$session,
            'scope'=>$scope,'other_scope'=>$otherScope,'baseline'=>$baseline,'term'=>$term,'tag'=>$tag];
        if ($correctionState) $data['correction'] = $correctionState();
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
