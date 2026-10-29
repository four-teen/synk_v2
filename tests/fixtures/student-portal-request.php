<?php
// Loopback-only, token-protected fixtures; all database writes are rolled back.
if (!defined('SYNK_STUDENT_PORTAL_QA') || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(SYNK_STUDENT_PORTAL_QA,$_GET['qa'])) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root = dirname(__DIR__,2);
require_once $root.'/backend/db.php';
require_once $root.'/backend/student_dashboard_helper.php';
require_once $root.'/backend/auth_config.php';
class StudentPortalQAConnection extends mysqli {
    public $requestMode=false;
    public $failActivityWrite=false;
    #[\ReturnTypeWillChange]
    public function prepare($sql){
        if($this->failActivityWrite && preg_match('/^\s*INSERT INTO tbl_student_login_activity\b/i',$sql)) throw new RuntimeException('Deliberate activity storage failure.');
        return parent::prepare($sql);
    }
    #[\ReturnTypeWillChange]
    public function begin_transaction($flags=0,$name=null){return $this->requestMode?$this->query('SAVEPOINT student_portal_request'):parent::begin_transaction($flags);}
    #[\ReturnTypeWillChange]
    public function commit($flags=0,$name=null){return $this->requestMode?$this->query('RELEASE SAVEPOINT student_portal_request'):parent::commit($flags);}
    #[\ReturnTypeWillChange]
    public function rollback($flags=0,$name=null){return $this->requestMode?$this->query('ROLLBACK TO SAVEPOINT student_portal_request'):parent::rollback($flags);}
    #[\ReturnTypeWillChange]
    public function query($sql,$mode=MYSQLI_STORE_RESULT){
        if(preg_match('/^\s*CREATE TABLE IF NOT EXISTS `(tbl_student_faculty_evaluations|tbl_student_faculty_evaluation_answers|tbl_student_portal_profile)`/i',$sql,$match)){
            if(!synk_table_exists($this,$match[1]))throw new RuntimeException('Prepare the evaluation schema before running fixtures.');
            return true;
        }
        if(preg_match('/^\s*(CREATE|ALTER|DROP|TRUNCATE)\b/i',$sql))throw new RuntimeException('Fixtures cannot change the schema.');return parent::query($sql,$mode);
    }
}
$conn->close();$conn=new StudentPortalQAConnection($servername,$username,$password,$dbase);$conn->set_charset('utf8mb4');$conn->begin_transaction();
$scenario=is_string($_GET['scenario']??null)?$_GET['scenario']:'dashboard';
$qaCameraPost=$_POST;
$loginActivityFloor=(int)$conn->query('SELECT COALESCE(MAX(activity_id),0) AS n FROM tbl_student_login_activity')->fetch_assoc()['n'];
$tag='PortalQA'.bin2hex(random_bytes(6));$domain=synk_auth_settings()['allowed_domain'];$email=strtolower($tag).'@'.$domain;
$term=synk_fetch_current_academic_term($conn);
$oldTerm=['ay_id'=>$term['ay_id'],'semester'=>max(1,$term['semester']-1)];
if($term['semester']===1){$prior=$conn->query("SELECT ay_id FROM tbl_academic_years WHERE ay<'".$conn->real_escape_string($term['ay_label'])."' ORDER BY ay DESC LIMIT 1")->fetch_assoc();if(!$prior)throw new RuntimeException('A previous academic year is needed.');$oldTerm=['ay_id'=>(int)$prior['ay_id'],'semester'=>3];}
$program=$conn->query("SELECT p.program_id,c.college_id,c.campus_id FROM tbl_program p JOIN tbl_college c ON c.college_id=p.college_id WHERE p.status='active' AND c.status='active' ORDER BY p.program_id LIMIT 1")->fetch_assoc();
$students=[];
foreach(['first','other'] as $i=>$key){$studentEmail=$key==='first'?$email:'other-'.$email;$number=$tag.'-'.$i;$first=$key==='first'?'Alex':'Other';
    $stmt=$conn->prepare('INSERT INTO tbl_student_management (student_number,email_address,first_name,last_name,ay_id,semester,program_id,year_level) VALUES (?,?,?,?,?,?,?,2)');
    $stmt->bind_param('ssssiii',$number,$studentEmail,$first,$tag,$term['ay_id'],$term['semester'],$program['program_id']);$stmt->execute();$students[$key]=(int)$conn->insert_id;$stmt->close();}
$teachers=[];
foreach(['Ada','Ben'] as $teacher){$conn->query("INSERT INTO tbl_faculty (first_name,last_name,status) VALUES ('{$teacher}','{$tag}','active')");$teachers[]=(int)$conn->insert_id;}
$import=static function(int $studentId,array $period,string $code,string $title,int $teacher,int $active=1)use($conn,$tag,$program):void {
    $section='QA 2A';$room='QA Lab 201';$schedule=$code==='QA-MATH101'?'T / Th · 1:00–2:30 PM':'LEC M, W 09:00-10:00; LAB T, Th 07:30-09:00';
    $subjectId = array_search($code,['QA-CS101','QA-MATH101','QA-HIST101','QA-INACTIVE','SECRET101'],true)+1;
    $stmt=$conn->prepare('INSERT INTO tbl_student_management_enrolled_subjects (import_batch_key,campus_id,college_id,program_id,ay_id,semester,student_id,subject_id,subject_code,descriptive_title,faculty_id,section_text,room_text,schedule_text,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('siiiiiiississsi',$tag,$program['campus_id'],$program['college_id'],$program['program_id'],$period['ay_id'],$period['semester'],$studentId,$subjectId,$code,$title,$teacher,$section,$room,$schedule,$active);$stmt->execute();$stmt->close();
};
$import($students['first'],$term,'QA-CS101','Introduction to Computing',$teachers[0]);
$import($students['first'],$term,'QA-MATH101','Discrete Mathematics',$teachers[1]);
$import($students['first'],$oldTerm,'QA-HIST101','Prior Term Subject',$teachers[0]);
$import($students['first'],$term,'QA-INACTIVE','Inactive Subject',$teachers[0],0);
$import($students['other'],$term,'SECRET101','Other Student Only',$teachers[0]);
if($scenario==='latest_import')$conn->query("UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE student_id={$students['first']} AND ay_id={$term['ay_id']} AND semester={$term['semester']}");
if(in_array($scenario,['guard_duplicate','verified_duplicate','start_duplicate'],true))$conn->query("UPDATE tbl_student_management SET email_address='{$email}' WHERE student_id={$students['other']}");
$conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_id) VALUES ('Portal QA Admin','admin-{$email}','unusable-qa-password','admin','active',{$program['college_id']})");
$adminId=(int)$conn->insert_id;synk_persist_useraccount_roles($conn,$adminId,['admin'],'admin');
session_save_path($root.'/temp');session_id('studentportalqa'.bin2hex(random_bytes(10)));session_start();
$_SESSION=[];synk_complete_student_login($email,'Alex Portal QA','qa-sub-'.$tag);$_SESSION['student_id']=$students['first'];
$csrf=synk_student_login_csrf();$_POST=[];$handler='student/index.php';
if (strpos($scenario,'attendance_qr_')===0) {
    $_SESSION['management_student_qr_csrf']=$csrf;
    $handler=$scenario==='attendance_qr_page'?'student/attendance-qr.php':'backend/query_management_student_qr.php';
    if ($scenario!=='attendance_qr_page' && $scenario!=='attendance_qr_get') $_POST=['csrf_token'=>$csrf,'student_id'=>$students['other']];
    if ($scenario==='attendance_qr_csrf') $_POST['csrf_token']='wrong';
    if ($scenario==='attendance_qr_staff') $_SESSION['role']='admin';
    if ($scenario==='attendance_qr_anonymous') $_SESSION=[];
}
if ($scenario==='profile_page') $handler='student/profile.php';
if ($scenario==='profile_term_tamper') { $handler='student/profile.php';$_GET['subject_ay_id']=$oldTerm['ay_id'];$_GET['subject_semester']=$oldTerm['semester']; }
if ($scenario==='profile_complete_page') {
    synk_student_save_first_portal_profile_setup($conn,$email,(int)$program['program_id'],(string)(100000000+$students['first']));
    $handler='student/profile.php';
}
if ($scenario==='prospectus_page') $handler='student/prospectus.php';
if (in_array($scenario,['student_evaluation','student_legacy_draft'],true)) {
    $conn->query('INSERT INTO tbl_college_evaluation_settings (college_id,ay_id,semester,student_open) VALUES ('.(int)$program['college_id'].','.(int)$term['ay_id'].','.(int)$term['semester'].',1) ON DUPLICATE KEY UPDATE student_open=1');
}
if (in_array($scenario,['dean_preview','dean_evaluation','dean_evaluation_historical'],true)) {
    $conn->query('INSERT INTO tbl_college_evaluation_settings (college_id,ay_id,semester,student_open) VALUES ('.(int)$program['college_id'].','.(int)$term['ay_id'].','.(int)$term['semester'].',0) ON DUPLICATE KEY UPDATE student_open=0');
}
if (in_array($scenario,['dean_preview','dean_evaluation','dean_evaluation_historical'],true) || strpos($scenario,'dean_faculty_evaluations_')===0) {
    $conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_dean_college_id) VALUES ('QA Dean','dean-{$email}','unusable-qa-password','college_dean','active',{$program['college_id']})");
    $deanId=(int)$conn->insert_id;synk_persist_useraccount_roles($conn,$deanId,['college_dean'],'college_dean');
    $_SESSION=['user_id'=>$deanId,'role'=>'college_dean','username'=>'QA Dean','email'=>'dean-'.$email,'csrf_token'=>$csrf];
    $_GET['preview_student_id']=$students['first'];$_GET['student_term']=$term['ay_id'].':'.$term['semester'];$_GET['return_to']='../college-dean/students.php';
}
if (strpos($scenario,'dean_faculty_evaluations_')===0) {
    $handler='college-dean/faculty-evaluations.php';
    $seed=static function(int $student,int $faculty,array $period,string $version,string $status,int $rating,string $comment)use($conn,$tag):void{
        $questions=synk_student_faculty_evaluation_flat_questions($version);$count=count($questions);$score=$count*$rating;$mean=(float)$rating;
        $token=$tag.'-'.bin2hex(random_bytes(8));$final=$status==='submitted'?$token:'';
        $stmt=$conn->prepare('INSERT INTO tbl_student_faculty_evaluations (student_id,faculty_id,ay_id,semester,faculty_name,instrument_version,question_count,total_score,average_rating,evaluation_token,submission_status,final_submission_token,comment_text,final_submitted_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
        $name='Evaluation QA';$stmt->bind_param('iiiissiidssss',$student,$faculty,$period['ay_id'],$period['semester'],$name,$version,$count,$score,$mean,$token,$status,$final,$comment);$stmt->execute();$evaluationId=(int)$stmt->insert_id;$stmt->close();
        foreach($questions as $question){$stmt=$conn->prepare('INSERT INTO tbl_student_faculty_evaluation_answers (evaluation_id,category_key,category_title,question_key,question_order,question_text,rating) VALUES (?,?,?,?,?,?,?)');$stmt->bind_param('isssisi',$evaluationId,$question['category_key'],$question['category_title'],$question['question_key'],$question['question_order'],$question['question_text'],$rating);$stmt->execute();$stmt->close();}
    };
    if($scenario!=='dean_faculty_evaluations_empty'){
        $seed($students['first'],$teachers[0],$term,synk_student_faculty_evaluation_current_version(),'submitted',4,'Current SET feedback <script>bad()</script>');
        $seed($students['other'],$teachers[0],$term,'legacy-20','submitted',3,'Previous instrument feedback');
        $seed($students['first'],$teachers[1],$term,synk_student_faculty_evaluation_current_version(),'draft',5,'PRIVATE_DRAFT_FEEDBACK');
        $seed($students['first'],$teachers[0],$oldTerm,synk_student_faculty_evaluation_current_version(),'submitted',5,'Historical submitted feedback');
        $conn->query('UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE student_id='.$students['first'].' AND ay_id='.$oldTerm['ay_id'].' AND semester='.$oldTerm['semester']);
        $foreignProgram=$conn->query('SELECT p.program_id,c.college_id,c.campus_id FROM tbl_program p JOIN tbl_college c ON c.college_id=p.college_id WHERE c.college_id<>'.$program['college_id'].' ORDER BY p.program_id LIMIT 1')->fetch_assoc();
        if(!$foreignProgram)throw new RuntimeException('A second college is needed for access-boundary fixtures.');
        $conn->query("INSERT INTO tbl_faculty (first_name,last_name,status) VALUES ('Foreign','FOREIGN_EVALUATION_FACULTY','active')");$foreignFaculty=(int)$conn->insert_id;
        $stmt=$conn->prepare("INSERT INTO tbl_student_management_enrolled_subjects (import_batch_key,campus_id,college_id,program_id,ay_id,semester,student_id,subject_id,subject_code,descriptive_title,faculty_id,is_active) VALUES (?,?,?,?,?,?,?,1,'FOREIGN-QA','Foreign subject',?,1)");
        $stmt->bind_param('siiiiiii',$tag,$foreignProgram['campus_id'],$foreignProgram['college_id'],$foreignProgram['program_id'],$term['ay_id'],$term['semester'],$students['other'],$foreignFaculty);$stmt->execute();$stmt->close();
        $seed($students['other'],$foreignFaculty,$term,synk_student_faculty_evaluation_current_version(),'submitted',1,'FOREIGN_SUBMITTED_FEEDBACK');
        $_GET['faculty_id']=$teachers[0];
    }
    if($scenario==='dean_faculty_evaluations_legacy')$_GET['instrument_version']='legacy-20';
    if($scenario==='dean_faculty_evaluations_historical')$_SESSION['college_dean_view_term']=['user_id'=>$deanId,'ay_id'=>$oldTerm['ay_id'],'semester'=>$oldTerm['semester']];
    if($scenario==='dean_faculty_evaluations_foreign')$_GET['faculty_id']=$foreignFaculty;
    if($scenario==='dean_faculty_evaluations_revoked')$conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$deanId} AND role='college_dean'");
    if($scenario==='dean_faculty_evaluations_post')$_POST=['clear'=>'1'];
    if($scenario==='dean_faculty_evaluations_bad_filter')$_GET['instrument_version']=['legacy-20'];
}
if ($scenario==='student_evaluation') $handler='student/class-program.php';
if (in_array($scenario, ['dean_evaluation', 'dean_evaluation_historical', 'student_legacy_draft'], true)) {
    $handler='student/class-program.php';
    foreach ($teachers as $index=>$teacher) {
        $comment=$index===0?'A saved draft comment for Dean review.':'A submitted comment for Dean review.';
        $status=$scenario==='student_legacy_draft'||$index===0?'draft':'submitted';$rating=$index===0?4:5;$score=$rating*20;$average=(float)$rating;
        $evaluationToken=$tag.'-'.$teacher;$finalToken=$status==='draft'?'':$tag.'-final';
        $stmt=$conn->prepare('INSERT INTO tbl_student_faculty_evaluations (student_id,faculty_id,ay_id,semester,comment_text,question_count,total_score,average_rating,evaluation_token,submission_status,final_submission_token) VALUES (?,?,?,?,?,20,?,?,?,?,?)');
        $stmt->bind_param('iiiisidsss',$students['first'],$teacher,$term['ay_id'],$term['semester'],$comment,$score,$average,$evaluationToken,$status,$finalToken);
        $stmt->execute();$evaluationId=(int)$stmt->insert_id;$stmt->close();
        foreach (synk_student_faculty_evaluation_flat_questions('legacy-20') as $question) {
            $stmt=$conn->prepare('INSERT INTO tbl_student_faculty_evaluation_answers (evaluation_id,category_key,category_title,question_key,question_order,question_text,rating) VALUES (?,?,?,?,?,?,?)');
            $stmt->bind_param('isssisi',$evaluationId,$question['category_key'],$question['category_title'],$question['question_key'],$question['question_order'],$question['question_text'],$rating);
            $stmt->execute();$stmt->close();
        }
    }
}
if ($scenario==='dean_evaluation_historical') $_GET['term_key']=$oldTerm['ay_id'].'-'.$oldTerm['semester'];
if($scenario==='old_term')$_GET['student_term']=$oldTerm['ay_id'].':'.$oldTerm['semester'];
if($scenario==='empty_term')$conn->query("UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE student_id={$students['first']}");
if($scenario==='foreign_parameters'){$_GET['preview_student_id']=(string)$students['other'];$_GET['student_id']=(string)$students['other'];}
if($scenario==='foreign_term')$_GET['student_term']='999999:3';
if($scenario==='guard_changed')$conn->query("UPDATE tbl_student_management SET email_address='changed-{$email}' WHERE student_id={$students['first']}");
if($scenario==='guard_wrong_id')$_SESSION['student_id']=$students['other'];
if(strpos($scenario,'login_')===0){$handler='student/login.php';$_SESSION['role']='admin';if($scenario==='login_error'){synk_student_login_record_issue($conn,'wrong-'.$email,'student_directory_access_denied');$_SESSION['student_login_last_email']='wrong-'.$email;$_GET['auth_status']='student_directory_access_denied';}}
if(strpos($scenario,'start_')===0){$handler='backend/auth_google_student_start.php';unset($_SESSION['role'],$_SESSION['user_id']);$_POST=['csrf_token'=>$csrf,'email'=>$email];
    if($scenario==='start_invalid')$_POST['email']='invalid email';if($scenario==='start_domain')$_POST['email']='qa@example.com';if($scenario==='start_bad_csrf')$_POST['csrf_token']='wrong';
    if($scenario==='start_rate')$_SESSION['student_login_requests']=array_fill(0,10,time());}
if($scenario==='start_unknown')$_POST['email']='unknown-'.$email;
if(strpos($scenario,'callback_')===0){$handler='backend/auth_google_callback.php';$_SESSION['student_google_oauth_state']='qa-student-state';$_SESSION['student_google_oauth_nonce']='qa-student-nonce';$_SESSION['student_google_oauth_email']=$email;$_SESSION['student_google_oauth_started_at']=time();$_SESSION['google_oauth_state']='qa-main-state';$_SESSION['google_oauth_nonce']='qa-main-nonce';$_SESSION['google_oauth_portal']='main';$_GET['state']='qa-student-state';$_GET['error']='access_denied';
    if($scenario==='callback_main_cancelled'){$_GET['state']='qa-main-state';$_SESSION['google_oauth_portal']='student';}
    if($scenario==='callback_invalid_state'){$_GET['state']='wrong-state';$_SESSION['google_oauth_portal']='student';}
    if($scenario==='callback_expired')$_SESSION['student_google_oauth_started_at']=time()-1800;
    if($scenario==='callback_missing_code')unset($_GET['error']);}
if(strpos($scenario,'admin_')===0){$handler='administrator/student-login-issues.php';$_SESSION=['user_id'=>$adminId,'role'=>'admin','email'=>'admin-'.$email,'username'=>'Portal QA Admin','college_id'=>(int)$program['college_id'],'csrf_token'=>SYNK_STUDENT_PORTAL_QA,'student_login_csrf'=>$csrf];
    synk_student_login_record_issue($conn,$email,'student_directory_access_denied',$email,'Alex Portal QA');
    synk_student_login_record_issue($conn,'<img src=x onerror=alert(1)>-'.$email,'student_email_invalid');
    $_GET['search']=$tag;
    $issueId=(int)$conn->query("SELECT issue_id FROM tbl_student_login_issues WHERE submitted_email='{$email}' LIMIT 1")->fetch_assoc()['issue_id'];
    if($scenario==='admin_repeat'){synk_student_login_review_issue($conn,$issueId,'Previously checked.');synk_student_login_record_issue($conn,$email,'student_directory_access_denied',$email,'Alex Portal QA');synk_student_login_record_issue($conn,$email,'student_directory_access_denied',$email,'Alex Portal QA');}
    if(in_array($scenario,['admin_review','admin_bad_csrf'],true))$_POST=['issue_id'=>(string)$issueId,'csrf_token'=>$scenario==='admin_bad_csrf'?'wrong':$csrf,'review_note'=>'Email checked in the Student Directory.'];
    if($scenario==='admin_inactive')$conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$adminId}");
    if($scenario==='admin_revoked')$conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$adminId} AND role='admin'");
    if($scenario==='admin_wrong_role')$_SESSION['role']='student';}
if (strpos($scenario,'evaluation_access_')===0) require __DIR__.'/evaluation-access-scenarios.php';
if (strpos($scenario,'login_activity_')===0) require __DIR__.'/student-login-activity-scenarios.php';
if (strpos($scenario,'login_report_')===0) require __DIR__.'/dean-login-report-scenarios.php';
if (strpos($scenario,'presence_')===0 || strpos($scenario,'activity_grid_')===0) require __DIR__.'/student-live-activity-scenarios.php';
if (strpos($scenario,'finances_')===0) require __DIR__.'/management-student-finances-scenarios.php';
require_once $root.'/backend/student_profile_image_helper.php';
$qaPhotoFiles=[];$qaPhotoBefore=[];
foreach($students as$key=>$photoStudentId) {
    $photoPath=synk_student_profile_image_path((int)$photoStudentId);
    if (is_file($photoPath)) throw new RuntimeException('A fixture photo already exists; refusing to overwrite it.');
    $qaPhotoFiles[$key]=$photoPath;
}
register_shutdown_function(static function()use(&$qaPhotoFiles,&$qaPhotoBefore,$scenario):void {
    $state=[];
    foreach($qaPhotoFiles as$key=>$photoPath) {
        clearstatcache(true,$photoPath);
        $info=is_file($photoPath) ? @getimagesize($photoPath) : false;
        $state[$key]=['exists'=>is_file($photoPath),'hash'=>is_file($photoPath)?hash_file('sha256',$photoPath):null,'before'=>$qaPhotoBefore[$key] ?? null,
            'files'=>count(glob(substr($photoPath,0,-4).'.*')),'mime'=>$info['mime'] ?? null,'width'=>$info[0] ?? null,'height'=>$info[1] ?? null,
            'web_readable'=>is_file($photoPath) && (fileperms($photoPath)&0044)===0044];
    }
    header('X-QA-Student-Photos: '.rawurlencode(json_encode($state)));
    foreach($qaPhotoFiles as$key=>$photoPath)if(is_file($photoPath) && !($key==='first' && $scenario==='photo_required_browser'))unlink($photoPath);
});
foreach($qaPhotoFiles as$key=>$photoPath) {
    $missing=$key==='first' && (strpos($scenario,'photo_required_')===0 || $scenario==='finances_photo_required');
    if(!$missing) {
        if(!is_dir(dirname($photoPath)))mkdir(dirname($photoPath),0755,true);
        $image=imagecreatetruecolor(8,8);imagefill($image,0,0,imagecolorallocate($image,20,40,80));imagejpeg($image,$photoPath,88);imagedestroy($image);
    }
    if($key==='first' && $scenario==='photo_required_corrupt')file_put_contents($photoPath,'Invalid image');
    $qaPhotoBefore[$key]=is_file($photoPath)?hash_file('sha256',$photoPath):null;
}
if (strpos($scenario,'photo_required_')===0 || strpos($scenario,'photo_upload_')===0) {
    $handler='student/profile.php';
    if($scenario==='photo_required_dashboard')$handler='student/index.php';
    if($scenario==='photo_required_qr')$handler='student/attendance-qr.php';
    if($scenario==='photo_required_prospectus')$handler='student/prospectus.php';
    if($scenario==='photo_required_previous_login')synk_student_save_first_portal_profile_setup($conn,$email,(int)$program['program_id'],(string)(100000000+$students['first']));
    if(strpos($scenario,'photo_upload_')===0 || $scenario==='photo_required_upload' || ($scenario==='photo_required_browser' && $qaCameraPost)) {
        $_POST=['save_student_photo'=>'1','csrf_token'=>$scenario==='photo_upload_bad_csrf'?'invalid':$csrf,
            'student_id'=>$students['other'],'first_name'=>'FORGED NAME','student_number'=>'00000000'];
        // Transport fixtures supply a controlled browser report. The browser
        // suite separately exercises the real self-hosted face detector.
        $capture=synk_student_photo_begin_capture((int)$students['first']);
        $captureFace=['x'=>.30,'y'=>.22,'width'=>.40,'height'=>.43,
            'keypoints'=>[['x'=>.40,'y'=>.36],['x'=>.60,'y'=>.36],['x'=>.50,'y'=>.45]]];
        $captureReport=['face_count'=>1,'face'=>$captureFace,'sha256'=>isset($_FILES['profile_photo']['tmp_name']) ? hash_file('sha256',$_FILES['profile_photo']['tmp_name']) : str_repeat('0',64)];
        $_POST['capture_token']=$capture['capture_token'];
        if ($scenario==='photo_upload_no_token') unset($_POST['capture_token']);
        if ($scenario==='photo_upload_expired') $_SESSION['student_photo_capture']['started_at']=time()-601;
        if ($scenario==='photo_upload_wrong_student') $_SESSION['student_photo_capture']['student_id']=$students['other'];
        if ($scenario==='photo_upload_hash') $captureReport['sha256']=str_repeat('0',64);
        if ($scenario==='photo_upload_no_face') $captureReport['face_count']=0;
        if ($scenario==='photo_upload_multiple') $captureReport['face_count']=2;
        if ($scenario==='photo_upload_bad_framing') $captureReport['face']['x']=0;
        if ($scenario==='photo_upload_bad_points') $captureReport['face']['keypoints']='invalid';
        $_POST['capture_report']=json_encode($captureReport);
        if ($scenario==='photo_upload_start') $_POST['photo_action']='start';
        if ($scenario==='photo_required_browser') {
            // Preserve the real browser's report and JPEG. This fixture uses
            // fresh rollback-only students per request, so it binds a fresh
            // challenge here; challenge enforcement is tested by the PHP suite.
            $_POST['capture_report']=$qaCameraPost['capture_report'] ?? '';
            if (($qaCameraPost['photo_action'] ?? '')==='start') $_POST['photo_action']='start';
        }
    }
    if($scenario==='photo_upload_staff')$_SESSION['role']='admin';
    if($scenario==='photo_upload_anonymous')$_SESSION=[];
    if(in_array($scenario,['photo_required_preview','photo_upload_preview'],true)) {
        $_SESSION['role']='admin';$_SESSION['user_id']=$adminId;
        $_GET['preview_student_id']=$students['first'];$_GET['return_to']='../administrator/students/directory.php';
    }
}
$protected=['tbl_student_management','tbl_student_management_enrolled_subjects','tbl_faculty','tbl_college_faculty','tbl_class_schedule','tbl_faculty_workload_sched','tbl_academic_settings','tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_student_login_activity'];
$snapshot=static function(array $tables)use($conn):string{$values=[];foreach($tables as $table)$values[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($values));};
$protectedBefore=$snapshot($protected);
ob_start(static function($html)use($handler):string {if(strpos($handler,'student/')===0||strpos($handler,'administrator/')===0||strpos($handler,'college-dean/')===0||strpos($handler,'professor/')===0||strpos($handler,'program-chair/')===0){$base=dirname($handler);$html=preg_replace('/<head([^>]*)>/', '<head$1><base href="http://localhost/synk/'.$base.'/">',$html,1);}return $html;});
register_shutdown_function(static function()use($conn,$tag,$students,$teachers,$protected,$protectedBefore,$snapshot,$loginActivityFloor):void {
    $stmt=$conn->prepare('SELECT submitted_email,google_email,google_name,failure_code,identity_verified,attempt_count,review_status,review_note FROM tbl_student_login_issues WHERE submitted_email LIKE ? ORDER BY issue_id');$like='%'.$tag.'%';$stmt->bind_param('s',$like);$stmt->execute();$issues=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    // The deliberate invalid-address preflight scenarios use non-tagged emails.
    if(isset($_POST['email'])&&in_array($_POST['email'],['invalid email','qa@example.com'],true))$issues=$conn->query("SELECT submitted_email,google_email,failure_code,identity_verified,attempt_count,review_status FROM tbl_student_login_issues WHERE submitted_email IN ('invalid email','qa@example.com')")->fetch_all(MYSQLI_ASSOC);
    header('X-QA-Issues: '.rawurlencode(json_encode($issues)));header('X-QA-Students: '.rawurlencode(json_encode($students)));
    if (strpos($_GET['scenario'] ?? '','attendance_qr_')===0) {
        $owners=$conn->query('SELECT student_id FROM tbl_management_student_qr_tokens WHERE student_id IN ('.(int)$students['first'].','.(int)$students['other'].') ORDER BY student_id')->fetch_all(MYSQLI_ASSOC);
        header('X-QA-QR-Owners: '.rawurlencode(json_encode(array_map('intval',array_column($owners,'student_id')))));
    }
    header('X-QA-Protected-Unchanged: '.($snapshot($protected)===$protectedBefore?'yes':'no'));
    if (preg_match('/^(start_|callback_|verified_)/',$_GET['scenario'] ?? '')) {
        $activity=$conn->query('SELECT student_id,submitted_email,outcome,reason_code,identity_verified,auth_method,ip_address,browser,operating_system,device_type FROM tbl_student_login_activity WHERE activity_id>'.$loginActivityFloor.' ORDER BY activity_id')->fetch_all(MYSQLI_ASSOC);
        header('X-QA-Login-Activity: '.rawurlencode(json_encode($activity)));
    }
    if (strpos($_GET['scenario'] ?? '','evaluation_access_')===0) {
        $state=$conn->query('SELECT college_id,faculty_id,submission_status FROM tbl_student_faculty_evaluations WHERE student_id='.(int)$students['first'].' ORDER BY college_id,faculty_id')->fetch_all(MYSQLI_ASSOC);
        $controls=$conn->query('SELECT college_id,ay_id,semester,student_open,faculty_released,version FROM tbl_college_evaluation_settings ORDER BY college_id,ay_id,semester')->fetch_all(MYSQLI_ASSOC);
        $audit=$conn->query("SELECT control_name,previous_value,new_value FROM tbl_college_evaluation_audit WHERE user_id=".(int)($_SESSION['user_id'] ?? 0).' ORDER BY audit_id')->fetch_all(MYSQLI_ASSOC);
        header('X-QA-Evaluation-State: '.rawurlencode(json_encode(['responses'=>$state,'controls'=>$controls,'audit'=>$audit])));
    }
    header('X-QA-OAuth-Pending: '.rawurlencode(json_encode(['student'=>isset($_SESSION['student_google_oauth_state']),'main'=>isset($_SESSION['google_oauth_state'])])));
    $conn->requestMode=false;$conn->rollback();if(session_status()===PHP_SESSION_ACTIVE)session_destroy();
});
if(strpos($scenario,'verified_')===0){
    $identity=['sub'=>'qa-sub-'.$tag,'email'=>$email,'email_verified'=>true,'name'=>'Alex Portal QA'];$submitted=$email;
    if($scenario==='verified_unknown')$identity['email']=$submitted='unknown-'.$email;
    if($scenario==='verified_mismatch')$submitted='wrong-'.$email;
    if($scenario==='verified_mismatch_known_student')$identity['email']='other-'.$email;
    if($scenario==='verified_unverified')$identity['email_verified']=false;
    if($scenario==='verified_domain')$identity['email']=$submitted='outside-'.$tag.'@example.com';
    if(strpos($scenario,'verified_login_')===0) $_SESSION=[];
    header('Content-Type: application/json');
    if($scenario==='verified_login_storage_failure') $conn->failActivityWrite=true;
    try{$record=synk_student_login_verified_identity($conn,$submitted,$identity,$domain);if(in_array($scenario,['verified_login_success','verified_login_storage_failure'],true))synk_student_login_complete($conn,$record,$identity['email'],$identity['name'],$identity['sub']);echo json_encode(['status'=>'ok','student_id'=>(int)$record['student_id'],'authenticated'=>(($_SESSION['role'] ?? '')==='student' && (int)($_SESSION['student_id'] ?? 0)===(int)$record['student_id'])]);}
    catch(DomainException $e){http_response_code(403);echo json_encode(['status'=>'denied','reason'=>$e->getMessage()]);}
    exit;
}
session_write_close();$_SERVER['REQUEST_METHOD']=$_POST?'POST':'GET';$_SERVER['PHP_SELF']='/synk/'.$handler;$conn->requestMode=true;
chdir($root.'/'.dirname($handler));require $root.'/'.$handler;
