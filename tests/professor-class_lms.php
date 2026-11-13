<?php
// php tests/professor-class_lms.php; fixture records are rolled back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/db_lms.php';
require_once __DIR__.'/../backend/professor_class_helper_lms.php';
require_once __DIR__.'/fixtures/professor-workload-students-data.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$snapshot=static function()use($conn):array{return $conn->query('CHECKSUM TABLE tbl_student_management,tbl_student_management_enrolled_subjects,tbl_faculty,tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_class_schedule,tbl_faculty_workload_sched,tbl_academic_settings,tbl_offering_enrollee_counts,'.synk_schedule_merge_table_name())->fetch_all(MYSQLI_ASSOC);};
$before=$snapshot();
$originalSession=$_SESSION??[];
$coreDatabase=$conn->query('SELECT DATABASE() AS name')->fetch_assoc()['name'];
$lms=synk_database_connection_lms($conn);
$check($lms!==$conn&&$lms->thread_id!==$conn->thread_id,'LMS reuses the core connection.');
$check($lms->query('SELECT DATABASE() AS name')->fetch_assoc()['name']==='synk_lms_db','LMS connection selected the wrong database.');
$check($conn->query('SELECT DATABASE() AS name')->fetch_assoc()['name']===$coreDatabase,'Opening LMS switched the core database.');
$lmsTablesBefore=$lms->query('SHOW TABLES')->fetch_all(MYSQLI_NUM);
$conn->begin_transaction();
try {
    $fixture=professor_students_fixtures($conn);
    $_SESSION=['user_id'=>$fixture['user_id'],'role'=>'professor'];
    $input=['offering_id'=>(string)$fixture['offerings'][0]['offering_id'],'ay_id'=>(string)$fixture['term']['ay_id'],'semester'=>(string)$fixture['term']['semester']];
    $sign=static function(array $values,string $view='overview')use($fixture):array{
        $url=synk_professor_class_url_lms(array_replace($values,['faculty_id'=>$fixture['faculty_id']]),$view);
        $params=[];parse_str((string)parse_url($url,PHP_URL_QUERY),$params);return $params;
    };
    $unsigned=$input;$input=$sign($input);
    $check(isset($input['signature'])&&preg_match('/^[a-f0-9]{64}$/D',$input['signature'])===1,'Generated class links are not signed.');
    $context=synk_professor_class_context_lms($conn,$input);
    $check($context['class']['offering_id']===(int)$input['offering_id']&&$context['portal']['faculty_id']===$fixture['faculty_id'],'The assigned class or professor was resolved incorrectly.');
    $check($context['view']==='overview'&&$context['class']['student_count']===260,'The initial class view changed recorded enrollments.');
    $studentInput=$sign($input,'students');
    $studentContext=synk_professor_class_context_lms($conn,$studentInput);
    $check($studentContext['view']==='students','Students view is unavailable.');
    $check($studentInput['signature']!==$input['signature'],'Overview and student links must have separate signatures.');
    $gradingInput=$sign($input,'grading');
    $check(synk_professor_class_context_lms($conn,$gradingInput)['view']==='grading','The signed grading view is unavailable.');
    $forged=synk_professor_class_context_lms($conn,$input+['faculty_id'=>'999999999','user_id'=>'999999999']);
    $check($forged['portal']['faculty_id']===$fixture['faculty_id'],'URL identity overrides the signed-in professor.');
    $denied=static function(array $request,int $code)use($conn,$check):void{
        try{synk_professor_class_context_lms($conn,$request);$check(false,'An unauthorized class was returned.');}
        catch(DomainException $error){$check($error->getCode()===$code,'Unexpected access validation status.');}
    };
    $denied($unsigned,403);
    foreach([
        ['offering_id'=>(string)$fixture['offerings'][1]['offering_id']],
        ['offering_id'=>(string)$fixture['offerings'][2]['offering_id']],
        ['ay_id'=>'999999999'],['semester'=>$input['semester']==='1'?'2':'1'],['view'=>'students'],['view'=>'grading'],
        ['signature'=>''],['signature'=>str_repeat('0',64)],['signature'=>['bad']],['signature'=>str_repeat('a',10000)]
    ] as $tampered)$denied(array_replace($input,$tampered),403);
    $denied(array_diff_key($studentInput,['view'=>true]),403);
    $otherInput=$sign(array_replace($input,['offering_id'=>(string)$fixture['offerings'][1]['offering_id']]));
    $check(synk_professor_class_context_lms($conn,$otherInput)['class']['offering_id']===(int)$fixture['offerings'][1]['offering_id'],
        'Other authorized class links do not work in the same session.');
    $check(synk_professor_class_context_lms($conn,$input)['class']['offering_id']===(int)$input['offering_id'],
        'Opening another class invalidated an earlier tab.');
    // Even a valid signature must still pass live workload ownership checks.
    $denied($sign(array_replace($input,['offering_id'=>(string)$fixture['offerings'][2]['offering_id']])),404);
    $denied($sign(array_replace($input,['ay_id'=>'999999999'])),404);
    foreach([['offering_id'=>['bad']],['offering_id'=>'0'],['offering_id'=>'2147483648'],['semester'=>'4'],['view'=>'unknown']] as $bad)$denied(array_replace($input,$bad),400);
    $signedSession=$_SESSION;
    unset($_SESSION['professor_lms_class_links']);$denied($input,403);
    $check(!isset($_SESSION['professor_lms_class_links']),'Unsigned or stale requests must not create signing credentials.');
    $_SESSION=$signedSession;$_SESSION['professor_lms_class_links']['session_id']='previous-login-session';$denied($input,403);
    $_SESSION=$signedSession;
    $tag='LmsSignedLinkQA'.bin2hex(random_bytes(8));
    $conn->query("INSERT INTO tbl_faculty (first_name,last_name,status) VALUES ('Other Professor','{$tag}','active')");
    $otherFacultyId=(int)$conn->insert_id;
    $conn->query("INSERT INTO tbl_useraccount (username,email,password,role,status) VALUES ('{$tag}','{$tag}@example.test','unusable-qa-password','professor','active')");
    $otherUserId=(int)$conn->insert_id;synk_persist_useraccount_roles($conn,$otherUserId,['professor'],'professor');
    $conn->query("INSERT INTO tbl_useraccount_faculty_links (user_id,faculty_id) VALUES ({$otherUserId},{$otherFacultyId})");
    $conn->query('INSERT INTO tbl_faculty_workload_sched (schedule_id,faculty_id,ay_id,semester) VALUES ('.(int)$fixture['offerings'][0]['schedule_id'].','.$otherFacultyId.','.(int)$input['ay_id'].','.(int)$input['semester'].')');
    $_SESSION=['user_id'=>$otherUserId,'role'=>'professor'];$denied($input,403);
    // Copying the first account's state cannot make its signature valid for the second account.
    $_SESSION['professor_lms_class_links']=$signedSession['professor_lms_class_links'];
    $_SESSION['professor_lms_class_links']['user_id']=$otherUserId;
    $_SESSION['professor_lms_class_links']['faculty_id']=$otherFacultyId;
    $denied($input,403);
    $otherUrl=synk_professor_class_url_lms($unsigned+['faculty_id'=>$otherFacultyId]);
    $otherRequest=[];parse_str((string)parse_url($otherUrl,PHP_URL_QUERY),$otherRequest);
    $check(synk_professor_class_context_lms($conn,$otherRequest)['portal']['faculty_id']===$otherFacultyId,
        'The second professor cannot open the same legitimately shared class using their own link.');
    $_SESSION=$signedSession;$denied($otherRequest,403);
    $_SESSION['professor_lms_class_links']['faculty_id']=$otherFacultyId;$denied($input,403);
    $_SESSION=$signedSession;
    $_SESSION['role']='student';$denied($input,401);$_SESSION['role']='professor';
    $conn->query("UPDATE tbl_useraccount SET status='inactive' WHERE user_id=".$fixture['user_id']);$denied($input,403);
    $conn->query("UPDATE tbl_useraccount SET status='active' WHERE user_id=".$fixture['user_id']);
    $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id=".$fixture['user_id']);$denied($input,403);
    $conn->query("UPDATE tbl_useraccount_roles SET status='active' WHERE user_id=".$fixture['user_id']);
    $conn->query("UPDATE tbl_faculty SET status='inactive' WHERE faculty_id=".$fixture['faculty_id']);$denied($input,403);
    $conn->query("UPDATE tbl_faculty SET status='active' WHERE faculty_id=".$fixture['faculty_id']);
    $mergeTable=synk_schedule_merge_table_name();
    $first=(int)$fixture['offerings'][0]['offering_id'];$second=(int)$fixture['offerings'][1]['offering_id'];
    $conn->query("INSERT INTO {$mergeTable} (owner_offering_id,member_offering_id,merge_scope) VALUES ({$first},{$second},'FULL')");
    $merged=synk_professor_class_context_lms($conn,$input);
    $check($merged['class']['offering_id']===$first&&$merged['class']['student_count']===290,'Merged class context differs from the workload.');
    $conn->query('DELETE FROM tbl_faculty_workload_sched WHERE faculty_id='.$fixture['faculty_id']);$denied($input,404);
    $conn->query('DELETE FROM tbl_useraccount_faculty_links WHERE user_id='.$fixture['user_id']);$denied($input,403);
    $_SESSION=[];$denied($input,401);
} finally {
    $conn->rollback();$_SESSION=$originalSession;
}
$token=bin2hex(random_bytes(24));
$route=__DIR__.'/../tmp/professor-class-'.bin2hex(random_bytes(8)).'_lms.php';
file_put_contents($route,'<?php define("SYNK_PROFESSOR_LMS_QA",'.var_export($token,true).');require '.var_export(__DIR__.'/fixtures/professor-class-request_lms.php',true).';');
try {
    foreach(['class'=>200,'students'=>200,'assessments'=>200,'grading'=>200,'gradebook'=>200,'announcements'=>200,'workload'=>200,'forged_identity'=>200,'foreign'=>403,'foreign_signed'=>404,
        'bad_id'=>400,'wrong_term'=>403,'wrong_semester'=>400,'edited_semester'=>403,'edited_assigned_class'=>403,'edited_view'=>403,
        'unsigned'=>403,'bad_signature'=>403,'array_signature'=>403,'missing_state'=>403,'rotated_secret'=>403,'regenerated'=>403,
        'copied_link'=>403,'relinked'=>403,'inactive'=>403,'revoked'=>403,'inactive_faculty'=>403,'unlinked'=>403,
        'reassigned'=>404,'method'=>405,'anonymous'=>302,'wrong_role'=>302,'navigate_class'=>200,'navigate_students'=>200,'navigate_back'=>200,'navigate_grading'=>200] as $case=>$status){
        $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'case'=>$case]));
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false]);
        $response=curl_exec($curl);if($response===false)throw new RuntimeException(curl_error($curl));
        $headerSize=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$actual=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        $headers=substr($response,0,$headerSize);$body=substr($response,$headerSize);
        $check($actual===$status,'Unexpected class page response for '.$case.': '.$actual);
        $check(strpos($headers,'X-QA-Core-Unchanged: yes')!==false,'Rendering a class page changed core records: '.$case);
        $check(!preg_match('/Fatal error|Parse error|xdebug-error|Warning(?:<\/b>)?:/i',$body),'PHP errors appeared in the class page: '.$case);
        if($status>=400)$check(strpos($body,'CLASS WORKSPACE')===false&&strpos($body,'class-lms-students"')===false,
            'A rejected class link exposed protected class or student content: '.$case);
        if(in_array($case,['foreign','wrong_term','edited_semester','edited_assigned_class','edited_view','unsigned','bad_signature',
            'array_signature','missing_state','rotated_secret','regenerated','copied_link','relinked'],true)){
            $check(strpos($body,'Invalid class link. Please open this class from your workload.')!==false,
                'A tampered or stale link did not explain how to reopen the class: '.$case);
        }
        if($case==='class')$check(strpos($body,'CLASS WORKSPACE')!==false&&strpos($body,'Class details')===false&&strpos($body,'Class meetings')===false&&strpos($body,'data-class-tool="students"')!==false&&strpos($body,'Grade Book')!==false,'The class overview must retain its tools and add Grade Book without the removed reference cards.');
        if($case==='assessments')$check(strpos($body,'id="assessmentWorkspace"')!==false&&strpos($body,'id="assessmentConfig"')!==false&&strpos($body,'Midterm')!==false&&strpos($body,'Final')!==false,'The professor assessment builder is unavailable.');
        if($case==='grading'||$case==='navigate_grading')$check(strpos($body,'id="gradingWorkspace"')!==false&&strpos($body,'id="gradingConfig"')!==false&&strpos($body,'Save Midterm')!==false&&strpos($body,'Save Final')!==false,'The grading card must open its signed workspace with both periods.');
        if($case==='gradebook')$check(strpos($body,'id="gradebookWorkspace"')!==false&&strpos($body,'id="gradebookConfig"')!==false&&strpos($body,'Semester summary')!==false,'The gradebook view must open with signed context and separate period controls.');
        if($case==='announcements')$check(strpos($body,'id="professorAnnouncements"')!==false&&strpos($body,'id="announcementConfig"')!==false,'The announcement card must open its signed class editor.');
        if($case==='students'||$case==='navigate_students')$check(strpos($body,'Jos')!==false&&strpos($body,'Foreign Test Student')===false&&strpos($body,'3 listed')!==false,'The class roster includes unrelated students or loses its named members.');
        if($case==='navigate_class'||$case==='navigate_back')$check(strpos($body,'CLASS WORKSPACE')!==false&&strpos($body,'Manage this subject')!==false,
            'Following the real workload and tab links did not reach the signed class overview.');
        if(in_array($case,['class','students','grading','navigate_class','navigate_students','navigate_back','navigate_grading'],true)){
            $dom=new DOMDocument();@$dom->loadHTML($body);$xpath=new DOMXPath($dom);
            foreach($xpath->query('//nav[contains(@class,"class-lms-tabs")]/a') as $link){
                $query=[];parse_str((string)parse_url($link->getAttribute('href'),PHP_URL_QUERY),$query);
                $check(isset($query['signature'])&&preg_match('/^[a-f0-9]{64}$/D',$query['signature'])===1,'A class tab omitted its signed link.');
            }
        }
        if($case==='workload'){
            $dom=new DOMDocument();@$dom->loadHTML($body);$xpath=new DOMXPath($dom);
            $check($xpath->query('//footer[contains(@class,"workload-class-footer")]//a[contains(@class,"workload-manage-class-lms")]')->length===2,'Each responsive subject card must include Manage Class in its footer.');
            $check($xpath->query('//button[contains(@class,"workload-show-students")]')->length===0,'Students must be accessed through Manage Class instead of a duplicate workload action.');
            foreach($xpath->query('//a[contains(@class,"workload-manage-class-lms")]') as $link){
                $query=[];parse_str((string)parse_url($link->getAttribute('href'),PHP_URL_QUERY),$query);
                $check(isset($query['offering_id'],$query['ay_id'],$query['semester'],$query['signature'])
                    &&preg_match('/^[a-f0-9]{64}$/D',$query['signature'])===1&&!isset($query['faculty_id'],$query['user_id']),
                    'Manage Class does not carry the exact signed class and term.');
            }
        }
    }
    $curl=curl_init('http://localhost/synk/professor/workload-action_lms.php');
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false]);
    $partialBody=curl_exec($curl);$partialStatus=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    $check($partialStatus===404&&$partialBody==='', 'The link-rendering include must not be available as a public signing endpoint.');
    $curl=curl_init('http://localhost/synk/professor/class_overview_lms.php');
    curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false]);
    $partialBody=curl_exec($curl);$partialStatus=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    $check($partialStatus===404&&$partialBody==='', 'The overview include must not expose class counts or grading status through a direct request.');
} finally {if(is_file($route))unlink($route);}
$check($snapshot()===$before,'Core academic, assignment or account records changed after rollback.');
$check($lms->query('SHOW TABLES')->fetch_all(MYSQLI_NUM)===$lmsTablesBefore,'Opening class context created LMS tables.');
echo 'Professor LMS class: ',$checks,' checks passed. Fixtures rolled back.',PHP_EOL;
