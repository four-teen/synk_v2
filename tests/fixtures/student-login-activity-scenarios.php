<?php
if (!defined('SYNK_STUDENT_PORTAL_QA') || !isset($students,$teachers,$program,$conn)) { http_response_code(404); exit; }
require_once $root.'/backend/college_dean_student_login_helper.php';
$case=substr($scenario,strlen('login_activity_'));
$conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_id,college_dean_college_id) VALUES ('Login Activity QA Dean','login-dean-{$email}','unusable','college_dean','active',{$program['college_id']},{$program['college_id']})");
$loginDeanId=(int)$conn->insert_id; synk_persist_useraccount_roles($conn,$loginDeanId,['college_dean'],'college_dean');
$_SESSION=['user_id'=>$loginDeanId,'role'=>'college_dean','email'=>'login-dean-'.$email,'username'=>'Login Activity QA Dean','csrf_token'=>$csrf];
$handler='backend/query_college_dean_students.php';
$_POST=['action'=>'login_activity','csrf_token'=>$csrf,'student_id'=>(string)$students['first']];
if ($case!=='empty') {
    for ($i=0;$i<31;$i++) {
        $success=$i%3!==0; $outcome=$success?'success':'failure'; $reason=$success?'login_success':'google_cancelled';
        $timestamp=$i===0?'2025-01-01 01:00:00':sprintf('2026-10-01 00:00:%02d',min(29,$i));
        $agent=$i===30?'<img src=x onerror=alert(1)>':'Login fixture '.$i;
        $usedEmail=$i===0?'old-address@sksu.edu.ph':$email;
        $stmt=$conn->prepare("INSERT INTO tbl_student_login_activity (student_id,submitted_email,outcome,reason_code,identity_verified,ip_address,user_agent,browser,operating_system,device_type,occurred_at) VALUES (?,?,?,?,?,'192.0.2.20',?,'Chrome','Windows','Desktop',?)");
        $verified=$success?1:0;
        $stmt->bind_param('isssiss',$students['first'],$usedEmail,$outcome,$reason,$verified,$agent,$timestamp); $stmt->execute(); $stmt->close();
    }
}
if ($case==='page') { $handler='college-dean/students.php'; $_POST=[]; }
if ($case==='list') $_POST=['action'=>'list','csrf_token'=>$csrf,'search'=>$tag,'term'=>'all'];
if ($case==='page2') $_POST['activity_page']='2';
if ($case==='success_only') $_POST['activity_outcome']='success';
if ($case==='failure_only') $_POST['activity_outcome']='failure';
if ($case==='bad_page') $_POST['activity_page']='0';
if ($case==='bad_outcome') $_POST['activity_outcome']='unknown';
if ($case==='bad_snapshot') $_POST['activity_snapshot']='-1';
if ($case==='array_input') $_POST['activity_outcome']=['success'];
if ($case==='bad_csrf') $_POST['csrf_token']='wrong';
if ($case==='all_time') $_POST['term']='999999:3';
if ($case==='revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$loginDeanId} AND role='college_dean'");
if ($case==='inactive') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$loginDeanId}");
if ($case==='chair') {
    synk_persist_useraccount_roles($conn,$loginDeanId,['program_chair'],'program_chair'); $_SESSION['role']='program_chair';
    $handler='backend/query_program_chair_students.php';
}
if ($case==='foreign') {
    $otherProgram=$conn->query('SELECT p.program_id,c.college_id FROM tbl_program p JOIN tbl_college c ON c.college_id=p.college_id WHERE c.college_id<>'.(int)$program['college_id'].' LIMIT 1')->fetch_assoc();
    if (!$otherProgram) throw new RuntimeException('A second college is required for login activity boundaries.');
    $conn->query("INSERT INTO tbl_student_management (student_number,first_name,last_name,email_address,program_id,ay_id,semester,source_sheet_name) VALUES ('{$tag}-F','Foreign','Login QA','foreign-{$email}',{$otherProgram['program_id']},{$term['ay_id']},{$term['semester']},'DEAN MANUAL')");
    $foreignId=(int)$conn->insert_id;
    $conn->query("INSERT INTO tbl_student_login_activity (student_id,submitted_email,outcome,reason_code,user_agent,occurred_at) VALUES ({$foreignId},'foreign-{$email}','success','login_success','FOREIGN_LOGIN_SECRET',UTC_TIMESTAMP())");
    $_POST['student_id']=(string)$foreignId;
}
if (in_array($case,['snapshot','refresh'],true)) {
    $snapshot=(int)$conn->query('SELECT MAX(activity_id) AS n FROM tbl_student_login_activity WHERE student_id='.(int)$students['first'])->fetch_assoc()['n'];
    $conn->query("INSERT INTO tbl_student_login_activity (student_id,submitted_email,outcome,reason_code,user_agent,occurred_at) VALUES ({$students['first']},'{$email}','success','login_success','NEWER_THAN_SNAPSHOT','2030-01-01 00:00:00')");
    $_POST['activity_snapshot']=$case==='snapshot'?(string)$snapshot:'0';
}
