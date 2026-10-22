<?php
if (!defined('SYNK_STUDENT_PORTAL_QA') || !isset($students,$conn)) { http_response_code(404); exit; }
require_once $root.'/backend/student_presence_helper.php';
$liveIds = $students;
if (strpos($scenario, 'presence_') === 0) {
    $case = substr($scenario, 9);
    if ($case === 'preview') {
        $_SESSION = ['role'=>'admin','user_id'=>$adminId];
        $_GET['preview_student_id'] = (string)$students['first'];
        $handler = 'student/index.php';
    } else {
        synk_student_presence_start($conn);
        $key = $_SESSION['student_presence_key'];
        $seconds = in_array($case, ['expired','boundary','revive','guard','guard_post'], true) ? ($case === 'boundary' ? 900 : 901) : 60;
        $conn->query("UPDATE tbl_student_portal_sessions SET last_activity_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$seconds} SECOND),last_seen_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 45 SECOND) WHERE presence_key='{$key}'");
        $handler = 'backend/student_presence.php';
        $_POST = ['action'=>in_array($case, ['activity','revive','foreign'], true) ? 'activity' : 'status','csrf_token'=>$_SESSION['student_presence_csrf']];
        if ($case === 'logout') $_POST['action'] = 'logout';
        if ($case === 'bad_csrf') $_POST['csrf_token'] = 'wrong';
        if ($case === 'array') $_POST['action'] = ['activity'];
        if ($case === 'foreign') $_POST['student_id'] = (string)$students['other'];
        if ($case === 'staff') { $_SESSION['role']='admin'; $_SESSION['user_id']=$adminId; }
        if ($case === 'missing') $conn->query("DELETE FROM tbl_student_portal_sessions WHERE presence_key='{$key}'");
        if ($case === 'changed') $conn->query("UPDATE tbl_student_management SET email_address='changed-{$email}' WHERE student_id={$students['first']}");
        if ($case === 'logout_page') { $handler='logout.php'; $_POST=[]; }
        if ($case === 'switch') {
            synk_complete_user_login(synk_find_useraccount_by_id($conn,$adminId),$conn,'admin');
            $handler='student/login.php'; $_POST=[];
        }
        if (in_array($case,['guard','guard_post'],true)) { $handler=$case==='guard'?'student/index.php':'student/profile.php'; $_POST=$case==='guard'?[]:['csrf_token'=>$csrf,'student_number'=>'SHOULD-NOT-SAVE','program_id'=>(string)$program['program_id']]; }
    }
} else {
    $case = substr($scenario, strlen('activity_grid_'));
    $conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status,college_id,college_dean_college_id) VALUES ('Live QA Dean','live-dean-{$email}','unusable','college_dean','active',{$program['college_id']},{$program['college_id']})");
    $liveDeanId = (int)$conn->insert_id; synk_persist_useraccount_roles($conn,$liveDeanId,['college_dean'],'college_dean');
    // More than 1,000 students, without adding teaching/import records.
    $insert = $conn->prepare("INSERT INTO tbl_student_management (student_number,email_address,first_name,last_name,ay_id,semester,program_id,source_sheet_name) VALUES (?,'','Grid',?,?,?,?,'DEAN MANUAL')");
    for ($i=0; $i<1003; $i++) {
        $number=$tag.'-grid-'.$i; $last=$tag.'-'.sprintf('%04d',$i);
        $insert->bind_param('ssiii',$number,$last,$term['ay_id'],$term['semester'],$program['program_id']); $insert->execute();
        if ($i<8) $liveIds['level'.$i]=(int)$conn->insert_id;
    }
    $insert->close();
    foreach ([0,1,4,5,9,10,14,1] as $index=>$count) {
        $id=$liveIds['level'.$index];
        for ($i=0; $i<$count; $i++) $conn->query("INSERT INTO tbl_student_login_activity (student_id,ay_id,semester,outcome,reason_code,occurred_at) VALUES ({$id},{$term['ay_id']},{$term['semester']},'success','login_success','2025-01-01 00:00:00')");
        $conn->query("INSERT INTO tbl_student_login_activity (student_id,outcome,reason_code,occurred_at) VALUES ({$id},'failure','google_cancelled',UTC_TIMESTAMP())");
    }
    $otherSemester=$term['semester']===1?2:1;
    $otherYear=(int)$conn->query('SELECT ay_id FROM tbl_academic_years WHERE ay_id<>'.(int)$term['ay_id'].' ORDER BY ay_id LIMIT 1')->fetch_assoc()['ay_id'];
    $addLogins=static function(int $id,int $ay,int $semester,int $count)use($conn):void {
        for($i=0;$i<$count;$i++) $conn->query("INSERT INTO tbl_student_login_activity (student_id,ay_id,semester,outcome,reason_code,occurred_at) VALUES ({$id},{$ay},{$semester},'success','login_success','2025-01-01 00:00:00')");
    };
    $addLogins($students['first'],$term['ay_id'],$term['semester'],2);
    $addLogins($students['first'],$oldTerm['ay_id'],$oldTerm['semester'],3);
    $addLogins($liveIds['level0'],$term['ay_id'],$otherSemester,7);
    $addLogins($liveIds['level0'],$otherYear,$term['semester'],11);
    $conn->query("INSERT INTO tbl_student_login_activity (student_id,outcome,reason_code,occurred_at) VALUES ({$liveIds['level0']},'success','login_success',UTC_TIMESTAMP())");
    foreach(['historical_only'=>[$oldTerm['ay_id'],$oldTerm['semester']], 'semester_only'=>[$term['ay_id'],$otherSemester], 'year_only'=>[$otherYear,$term['semester']]] as $label=>$period) {
        $number=$tag.'-'.substr($label,0,3);
        $conn->query("INSERT INTO tbl_student_management (student_number,first_name,last_name,program_id,ay_id,semester,source_sheet_name) VALUES ('{$number}','Term','{$tag}{$label}',{$program['program_id']},{$period[0]},{$period[1]},'DEAN MANUAL')");
        $liveIds[$label]=(int)$conn->insert_id;
        $addLogins($liveIds[$label],$period[0],$period[1],5);
    }
    foreach (['level1','level3','level5'] as $i=>$which) {
        $id=$liveIds[$which];
        $key=hash('sha256',$tag.$which);
        $age=$i===0?0:($i===1?901:60); $seen=$i===2?100:0;
        $conn->query("INSERT INTO tbl_student_portal_sessions (presence_key,student_id,signed_in_at,last_activity_at,last_seen_at) VALUES ('{$key}',{$id},UTC_TIMESTAMP(),DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$age} SECOND),DATE_SUB(UTC_TIMESTAMP(),INTERVAL {$seen} SECOND))");
    }
    // A logout on one device must not hide another live device.
    $key=hash('sha256',$tag.'ended-device');
    $conn->query("INSERT INTO tbl_student_portal_sessions (presence_key,student_id,signed_in_at,last_activity_at,last_seen_at,ended_at,end_reason) VALUES ('{$key}',{$liveIds['level1']},UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),UTC_TIMESTAMP(),'logout')");
    $other=$conn->query('SELECT p.program_id,c.college_id,c.campus_id FROM tbl_program p JOIN tbl_college c ON c.college_id=p.college_id WHERE c.college_id<>'.(int)$program['college_id'].' LIMIT 1')->fetch_assoc();
    $conn->query("INSERT INTO tbl_student_management (student_number,first_name,last_name,program_id,ay_id,semester,source_sheet_name) VALUES ('{$tag}-foreign','Foreign','LIVE_SECRET',{$other['program_id']},{$term['ay_id']},{$term['semester']},'DEAN MANUAL')");
    $liveIds['foreign']=(int)$conn->insert_id;
    $_SESSION=['user_id'=>$liveDeanId,'role'=>'college_dean','csrf_token'=>$csrf];
    $_POST=['csrf_token'=>$csrf,'college_id'=>(string)$program['college_id'],'campus_id'=>(string)$program['campus_id'],'ay_id'=>(string)$term['ay_id'],'semester'=>(string)$term['semester']];
    $handler='backend/query_college_dean_student_activity.php';
    if(in_array($case,['historical','page_historical','session_term','pinned','capture'],true)) {
        $_SESSION['college_dean_view_term']=['user_id'=>$liveDeanId,'ay_id'=>$oldTerm['ay_id'],'semester'=>$oldTerm['semester']];
        if($case==='historical') { $_POST['ay_id']=(string)$oldTerm['ay_id']; $_POST['semester']=(string)$oldTerm['semester']; }
        if($case==='session_term') { unset($_POST['ay_id'],$_POST['semester']); }
        if($case==='capture') synk_student_login_activity_record($conn,$email,'success','login_success',true,$students['first']);
    }
    if($case==='other_semester') $_POST['semester']=(string)$otherSemester;
    if($case==='other_year') $_POST['ay_id']=(string)$otherYear;
    if($case==='recovery') {
        $conn->query("UPDATE tbl_academic_settings SET date_updated=DATE_SUB(NOW(),INTERVAL 1 DAY)");
        $conn->query(file_get_contents($root.'/backend/sql/phase33_student_login_activity_term_recovery.sql'));
    }
    if($case==='bad_year') $_POST['ay_id']='4294967295';
    if($case==='bad_semester') $_POST['semester']='4';
    if($case==='partial_term') unset($_POST['semester']);
    if($case==='array_term') $_POST['semester']=['1'];
    if(in_array($case,['page_current','page_historical'],true)) { $handler='college-dean/index.php'; $_POST=[]; }
    if ($case==='bad_csrf') $_POST['csrf_token']='wrong';
    if ($case==='array') $_POST['college_id']=['malformed'];
    if ($case==='foreign') $_POST['college_id']=(string)$other['college_id'];
    if ($case==='revoked') $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$liveDeanId} AND role='college_dean'");
    if ($case==='inactive') $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id={$liveDeanId}");
    if ($case==='staff') $_SESSION['role']='admin';
    if ($case==='get') $_POST=[];
}
register_shutdown_function(static function()use($conn,$students,$liveIds):void {
    $rows=$conn->query("SELECT student_id,end_reason,ended_at,TIMESTAMPDIFF(SECOND,last_activity_at,UTC_TIMESTAMP()) AS activity_age,TIMESTAMPDIFF(SECOND,last_seen_at,UTC_TIMESTAMP()) AS seen_age FROM tbl_student_portal_sessions WHERE student_id IN ({$students['first']},{$students['other']}) ORDER BY student_id")->fetch_all(MYSQLI_ASSOC);
    header('X-QA-Live-State: '.rawurlencode(json_encode(['sessions'=>$rows,'role'=>$_SESSION['role'] ?? '', 'ids'=>$liveIds])));
});
