<?php
if (!defined('SYNK_STUDENT_PORTAL_QA') || !isset($students,$program,$conn)) { http_response_code(404); exit; }
require_once $root.'/backend/college_dean_login_report_helper.php';
$case=substr($scenario,strlen('login_report_'));
$defaults=synk_dean_login_report_defaults(); $zone=new DateTimeZone('Asia/Manila'); $utc=new DateTimeZone('UTC');
$firstDay=new DateTimeImmutable($defaults['date_from'],$zone); $lastDay=new DateTimeImmutable($defaults['date_to'],$zone);
$conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_id,college_dean_college_id) VALUES ('Report QA Dean','report-dean-{$email}','unusable','college_dean','active',{$program['college_id']},{$program['college_id']})");
$reportDeanId=(int)$conn->insert_id; synk_persist_useraccount_roles($conn,$reportDeanId,['college_dean'],'college_dean');
$_SESSION=['user_id'=>$reportDeanId,'role'=>'college_dean','email'=>'report-dean-'.$email,'username'=>'Report QA Dean','csrf_token'=>$csrf];
$handler='backend/query_college_dean_login_activity.php';
$_POST=['csrf_token'=>$csrf,'search'=>$tag];
$addEvent=static function(?int $student,string $submitted,string $outcome,DateTimeImmutable $date,string $agent='Report fixture',string $ip='192.0.2.10')use($conn,$utc):void {
    $reason=$outcome==='success'?'login_success':'google_cancelled'; $verified=$outcome==='success'?1:0; $timestamp=$date->setTimezone($utc)->format('Y-m-d H:i:s');
    $stmt=$conn->prepare("INSERT INTO tbl_student_login_activity (student_id,submitted_email,outcome,reason_code,identity_verified,ip_address,user_agent,browser,operating_system,device_type,occurred_at) VALUES (?,?,?,?,?,?,?,'Chrome','Windows','Desktop',?)");
    $stmt->bind_param('isssisss',$student,$submitted,$outcome,$reason,$verified,$ip,$agent,$timestamp); $stmt->execute(); $stmt->close();
};
// Two students, multiple membership rows, with a zero-activity day and precise PH midnight boundaries.
for ($i=0;$i<30;$i++) {
    $date=$i<2?$firstDay->modify('+'.$i.' seconds'):$lastDay->setTime(8,0,min($i,28));
    if ($i===2) $date=$lastDay->setTime(23,59,59);
    $addEvent($students['first'],$i===1?'previous-'.$email:$email,$i%3===0?'failure':'success',$date,$i===2?'<img src=x onerror=alert(1)>':'Report fixture '.$i,$i===4?'198.51.100.77':'192.0.2.10');
}
for ($i=0;$i<3;$i++) $addEvent($students['other'],'other-'.$email,'failure',$lastDay->setTime(10,0,$i));
$addEvent($students['first'],$email,'success',$firstDay->modify('-1 second'),'BEFORE_REPORT_RANGE');
$addEvent($students['first'],$email,'success',$lastDay->modify('+1 day'),'AFTER_REPORT_RANGE');
$addEvent(null,$email,'failure',$lastDay->setTime(12,0),'UNASSIGNED_REPORT_SECRET');
$foreignProgram=$conn->query('SELECT p.program_id,c.college_id,c.campus_id FROM tbl_program p JOIN tbl_college c ON c.college_id=p.college_id WHERE c.college_id<>'.(int)$program['college_id'].' LIMIT 1')->fetch_assoc();
if (!$foreignProgram) throw new RuntimeException('A second college is required for report access tests.');
$conn->query("INSERT INTO tbl_student_management (student_number,first_name,last_name,email_address,program_id,ay_id,semester,source_sheet_name) VALUES ('{$tag}-FOREIGN','Foreign','{$tag}','foreign-{$email}',{$foreignProgram['program_id']},{$term['ay_id']},{$term['semester']},'DEAN MANUAL')");
$foreignStudent=(int)$conn->insert_id;
$addEvent($foreignStudent,'foreign-'.$email,'success',$lastDay->setTime(13,0),'FOREIGN_REPORT_SECRET');
if ($case==='page') { $handler='college-dean/login-activity.php'; $_POST=[]; }
if ($case==='day') $_POST+=['date_from'=>$defaults['date_from'],'date_to'=>$defaults['date_from']];
if ($case==='empty') $_POST['search']='NoStudentCanMatchThisReport';
if ($case==='failure') $_POST['outcome']='failure';
if ($case==='success') $_POST['outcome']='success';
if ($case==='page2') $_POST['page']='2';
if ($case==='size50') $_POST['page_size']='50';
if ($case==='email') $_POST['search']='previous-'.$email;
if ($case==='ip') $_POST['search']='198.51.100.77';
if ($case==='name') { $conn->query("UPDATE tbl_student_management SET first_name='José',last_name='Peña' WHERE student_id=".$students['first']); $_POST['search']='PEÑA JOSÉ'; }
if ($case==='wildcard') $_POST['search']='%';
if ($case==='injection') $_POST['search']="' OR 1=1 --";
if ($case==='student') $_POST['student_id']=(string)$students['other'];
if ($case==='foreign_student') $_POST['student_id']=(string)$foreignStudent;
if ($case==='program') $_POST['program_id']=(string)$program['program_id'];
if ($case==='foreign_program') $_POST['program_id']=(string)$foreignProgram['program_id'];
if ($case==='historical_view') $_SESSION['college_dean_view_term']=['user_id'=>$reportDeanId,'ay_id'=>$oldTerm['ay_id'],'semester'=>$oldTerm['semester']];
if ($case==='bad_date') $_POST['date_from']='2026-02-30';
if ($case==='reversed') { $_POST['date_from']=$defaults['date_to']; $_POST['date_to']=$defaults['date_from']; }
if ($case==='future') $_POST['date_to']=$lastDay->modify('+1 day')->format('Y-m-d');
if ($case==='too_long') $_POST['date_from']=$lastDay->modify('-366 days')->format('Y-m-d');
if ($case==='max_range') $_POST['date_from']=$lastDay->modify('-365 days')->format('Y-m-d');
if ($case==='bad_page') $_POST['page']='0';
if ($case==='bad_snapshot') $_POST['snapshot']='-1';
if ($case==='bad_size') $_POST['page_size']='100000';
if ($case==='bad_outcome') $_POST['outcome']='unknown';
if ($case==='bad_search') $_POST['search']=str_repeat('é',151);
if ($case==='array') $_POST['date_from']=[$defaults['date_from']];
if ($case==='bad_csrf') $_POST['csrf_token']='wrong';
if ($case==='scope') $_POST['college_id']=(string)$foreignProgram['college_id'];
if ($case==='campus') $_POST['campus_id']='999999';
if ($case==='revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$reportDeanId} AND role='college_dean'");
if ($case==='inactive') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$reportDeanId}");
if ($case==='reassigned') { $conn->query('UPDATE tbl_useraccount SET college_dean_college_id='.(int)$foreignProgram['college_id'].' WHERE user_id='.$reportDeanId); $_POST['college_id']=(string)$program['college_id']; }
if ($case==='chair') $_SESSION['role']='program_chair';
if ($case==='anonymous') unset($_SESSION['user_id']);
if ($case==='get') $_POST=[];
if (in_array($case,['snapshot','refresh'],true)) {
    $floor=(int)$conn->query('SELECT MAX(activity_id) AS n FROM tbl_student_login_activity')->fetch_assoc()['n'];
    $addEvent($students['first'],$email,'success',$lastDay->setTime(23,59,59),'NEW_REPORT_ARRIVAL');
    $_POST['snapshot']=$case==='snapshot'?(string)$floor:'0';
}
