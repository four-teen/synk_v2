<?php
// Accessible only through a random loopback test wrapper. All fixture writes roll back.
if (!defined('SYNK_PROFESSOR_LMS_QA') || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)
    || !is_string($_GET['qa'] ?? null) || !hash_equals(SYNK_PROFESSOR_LMS_QA, $_GET['qa'])) { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$root=dirname(__DIR__,2);
require_once $root.'/backend/db.php';
require_once $root.'/backend/auth_useraccount.php';
require_once $root.'/backend/professor_workload_students_helper.php';
require_once $root.'/backend/professor_class_link_helper_lms.php';
require_once __DIR__.'/professor-workload-students-data.php';
$conn->begin_transaction();
$fixture=professor_students_fixtures($conn);
$case=is_string($_GET['case']??null)?$_GET['case']:'class';
session_save_path($root.'/temp');session_id('professorlmsqa'.bin2hex(random_bytes(10)));session_start();
$_SESSION=['user_id'=>$fixture['user_id'],'role'=>'professor','username'=>'Professor LMS Preview','email'=>'professor-lms@example.test'];
if($case==='inactive')$conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id=".$fixture['user_id']);
if($case==='revoked')$conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id=".$fixture['user_id']);
if($case==='inactive_faculty')$conn->query("UPDATE tbl_faculty SET status='inactive' WHERE faculty_id=".$fixture['faculty_id']);
if($case==='unlinked')$conn->query('DELETE FROM tbl_useraccount_faculty_links WHERE user_id='.$fixture['user_id']);
if($case==='reassigned')$conn->query('DELETE FROM tbl_faculty_workload_sched WHERE faculty_id='.$fixture['faculty_id']);
$_GET+=['offering_id'=>(string)$fixture['offerings'][0]['offering_id'],'ay_id'=>(string)$fixture['term']['ay_id'],'semester'=>(string)$fixture['term']['semester']];
$requestedView=in_array($case,['students','assessments','grading'],true)?$case:'overview';
if($requestedView!=='overview')$_GET['view']=$requestedView;
$signingClass=['faculty_id'=>$fixture['faculty_id'],'offering_id'=>$fixture['offerings'][0]['offering_id'],
    'ay_id'=>$fixture['term']['ay_id'],'semester'=>$fixture['term']['semester']];
$signedQuery=[];parse_str((string)parse_url(synk_professor_class_url_lms($signingClass,$requestedView),PHP_URL_QUERY),$signedQuery);
$_GET['signature']=$signedQuery['signature'];
if($case==='anonymous')$_SESSION=[];
if($case==='wrong_role')$_SESSION['role']='student';
if($case==='foreign')$_GET['offering_id']=(string)$fixture['offerings'][2]['offering_id'];
if($case==='foreign_signed'){
    $signedQuery=[];parse_str((string)parse_url(synk_professor_class_url_lms(array_replace($signingClass,['offering_id'=>$fixture['offerings'][2]['offering_id']])),PHP_URL_QUERY),$signedQuery);
    $_GET=array_replace($_GET,$signedQuery);
}
if($case==='edited_assigned_class')$_GET['offering_id']=(string)$fixture['offerings'][1]['offering_id'];
if($case==='bad_id')$_GET['offering_id']=['invalid'];
if($case==='wrong_term')$_GET['ay_id']='999999999';
if($case==='wrong_semester')$_GET['semester']='4';
if($case==='edited_semester')$_GET['semester']=$_GET['semester']==='1'?'2':'1';
if($case==='edited_view')$_GET['view']='students';
if($case==='unsigned')unset($_GET['signature']);
if($case==='bad_signature')$_GET['signature']=str_repeat('0',64);
if($case==='array_signature')$_GET['signature']=['bad'];
if($case==='missing_state')unset($_SESSION['professor_lms_class_links']);
if($case==='rotated_secret')$_SESSION['professor_lms_class_links']['secret']=bin2hex(random_bytes(32));
if($case==='regenerated')session_regenerate_id(true);
if($case==='relinked'){
    $conn->query("INSERT INTO tbl_faculty (first_name,last_name,status) VALUES ('Reassigned','Professor LMS QA','active')");
    $replacementFaculty=(int)$conn->insert_id;
    $conn->query('UPDATE tbl_useraccount_faculty_links SET faculty_id='.$replacementFaculty.' WHERE user_id='.$fixture['user_id']);
}
if($case==='copied_link'){
    $copyTag='CopiedLmsQA'.bin2hex(random_bytes(8));
    $conn->query("INSERT INTO tbl_faculty (first_name,last_name,status) VALUES ('Other Professor','{$copyTag}','active')");
    $copyFaculty=(int)$conn->insert_id;
    $conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status) VALUES ('{$copyTag}','{$copyTag}@example.test','unusable-qa-password','professor','active')");
    $copyUser=(int)$conn->insert_id;synk_persist_useraccount_roles($conn,$copyUser,['professor'],'professor');
    $conn->query("INSERT INTO tbl_useraccount_faculty_links (user_id,faculty_id) VALUES ({$copyUser},{$copyFaculty})");
    $conn->query('INSERT INTO tbl_faculty_workload_sched (schedule_id,faculty_id,ay_id,semester) VALUES ('.(int)$fixture['offerings'][0]['schedule_id'].','.$copyFaculty.','.(int)$fixture['term']['ay_id'].','.(int)$fixture['term']['semester'].')');
    $_SESSION['user_id']=$copyUser;
}
if($case==='forged_identity'){$_GET['faculty_id']='999999999';$_GET['user_id']='999999999';}
$tables='tbl_class_schedule,tbl_faculty_workload_sched,tbl_academic_settings,tbl_student_management,tbl_student_management_enrolled_subjects,tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,'.synk_schedule_merge_table_name();
$before=$conn->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC);
register_shutdown_function(static function()use($conn,$tables,$before):void{
    header('X-QA-Core-Unchanged: '.($conn->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC)===$before?'yes':'no'));
    $conn->rollback();if(session_status()===PHP_SESSION_ACTIVE)session_destroy();
});
if(defined('SYNK_PROFESSOR_LMS_PREVIEW')){
    ob_start(static function($html):string{
        $html=preg_replace('/<head>/', '<head><base href="http://localhost/synk/professor/">', $html,1);
        return str_replace('href="class_lms.php?', 'href="'.rtrim(SYNK_PROFESSOR_LMS_PREVIEW,'/').'/class_lms.php?qa='.rawurlencode(SYNK_PROFESSOR_LMS_QA).'&amp;', $html);
    });
}
$_SERVER['REQUEST_METHOD']=$case==='method'?'POST':'GET';
if(in_array($case,['navigate_class','navigate_students','navigate_back','navigate_grading'],true)){
    // Follow the exact rendered links, persisting the login session between page renders.
    $render=static function(string $page)use($root,$conn):string{
        $_SERVER['PHP_SELF']='/synk/professor/'.$page;
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        chdir($root.'/professor');$level=ob_get_level();ob_start();require $root.'/professor/'.$page;
        $body='';while(ob_get_level()>$level)$body=ob_get_clean().$body;return $body;
    };
    $follow=static function(string $html,string $selector):void{
        $dom=new DOMDocument();@$dom->loadHTML($html);$xpath=new DOMXPath($dom);$links=$xpath->query($selector);
        if(!$links->length)throw new RuntimeException('The expected class navigation link is missing.');
        $params=[];parse_str((string)parse_url($links->item(0)->getAttribute('href'),PHP_URL_QUERY),$params);$_GET=$params;
    };
    $workloadHtml=$render('workload.php');
    $follow($workloadHtml,'//a[contains(@class,"workload-manage-class-lms")]');
    $classHtml=$render('class_lms.php');
    if($case==='navigate_grading'){
        $follow($classHtml,'//a[contains(@class,"class-lms-tool-link") and contains(@href,"view=grading")]');
        $classHtml=$render('class_lms.php');
    }elseif($case!=='navigate_class'){
        $follow($classHtml,'//nav[contains(@class,"class-lms-tabs")]/a[contains(@href,"view=students")]');
        $classHtml=$render('class_lms.php');
    }
    if($case==='navigate_back'){
        $follow($classHtml,'//nav[contains(@class,"class-lms-tabs")]/a[not(contains(@href,"view=students"))]');
        $classHtml=$render('class_lms.php');
    }
    ob_start();echo $classHtml;return;
}
$handler=$case==='workload'?'workload.php':'class_lms.php';
$_SERVER['PHP_SELF']='/synk/professor/'.$handler;
session_write_close();chdir($root.'/professor');require $root.'/professor/'.$handler;
