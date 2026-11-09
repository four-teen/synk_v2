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
    $context=synk_professor_class_context_lms($conn,$input);
    $check($context['class']['offering_id']===(int)$input['offering_id']&&$context['portal']['faculty_id']===$fixture['faculty_id'],'The assigned class or professor was resolved incorrectly.');
    $check($context['view']==='overview'&&$context['class']['student_count']===260,'The initial class view changed recorded enrollments.');
    $studentContext=synk_professor_class_context_lms($conn,$input+['view'=>'students']);
    $check($studentContext['view']==='students','Students view is unavailable.');
    $forged=synk_professor_class_context_lms($conn,$input+['faculty_id'=>'999999999','user_id'=>'999999999']);
    $check($forged['portal']['faculty_id']===$fixture['faculty_id'],'URL identity overrides the signed-in professor.');
    $denied=static function(array $request,int $code)use($conn,$check):void{
        try{synk_professor_class_context_lms($conn,$request);$check(false,'An unauthorized class was returned.');}
        catch(DomainException $error){$check($error->getCode()===$code,'Unexpected access validation status.');}
    };
    $denied(array_replace($input,['offering_id'=>(string)$fixture['offerings'][2]['offering_id']]),404);
    $denied(array_replace($input,['ay_id'=>'999999999']),404);
    foreach([['offering_id'=>['bad']],['offering_id'=>'0'],['offering_id'=>'2147483648'],['semester'=>'4'],['view'=>'unknown']] as $bad)$denied(array_replace($input,$bad),400);
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
    foreach(['class'=>200,'students'=>200,'workload'=>200,'forged_identity'=>200,'foreign'=>404,'bad_id'=>400,'wrong_term'=>404,'wrong_semester'=>400,'inactive'=>403,'revoked'=>403,'inactive_faculty'=>403,'unlinked'=>403,'reassigned'=>404,'method'=>405,'anonymous'=>302,'wrong_role'=>302] as $case=>$status){
        $curl=curl_init('http://localhost/synk/tmp/'.basename($route).'?'.http_build_query(['qa'=>$token,'case'=>$case]));
        curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_FOLLOWLOCATION=>false]);
        $response=curl_exec($curl);if($response===false)throw new RuntimeException(curl_error($curl));
        $headerSize=curl_getinfo($curl,CURLINFO_HEADER_SIZE);$actual=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
        $headers=substr($response,0,$headerSize);$body=substr($response,$headerSize);
        $check($actual===$status,'Unexpected class page response for '.$case.': '.$actual);
        $check(strpos($headers,'X-QA-Core-Unchanged: yes')!==false,'Rendering a class page changed core records: '.$case);
        $check(!preg_match('/Fatal error|Parse error|xdebug-error|Warning(?:<\/b>)?:/i',$body),'PHP errors appeared in the class page: '.$case);
        if($case==='class')$check(strpos($body,'CLASS WORKSPACE')!==false&&strpos($body,'Class details')!==false&&strpos($body,'View class roster')!==false,'The dedicated class overview did not render.');
        if($case==='students')$check(strpos($body,'Jos')!==false&&strpos($body,'Foreign Test Student')===false&&strpos($body,'3 listed')!==false,'The class roster includes unrelated students or loses its named members.');
        if($case==='workload'){
            $dom=new DOMDocument();@$dom->loadHTML($body);$xpath=new DOMXPath($dom);
            $check($xpath->query('//a[contains(@class,"workload-manage-class-lms")]')->length===4,'Desktop and mobile rows do not each include Manage Class.');
            $check($xpath->query('//button[contains(@class,"workload-show-students")]')->length===4,'The existing Show Students actions changed.');
            foreach($xpath->query('//a[contains(@class,"workload-manage-class-lms")]') as $link){
                $query=[];parse_str((string)parse_url($link->getAttribute('href'),PHP_URL_QUERY),$query);
                $check(isset($query['offering_id'],$query['ay_id'],$query['semester'])&&!isset($query['faculty_id'],$query['user_id']),'Manage Class does not carry the exact class and term.');
            }
        }
    }
} finally {if(is_file($route))unlink($route);}
$check($snapshot()===$before,'Core academic, assignment or account records changed after rollback.');
$check($lms->query('SHOW TABLES')->fetch_all(MYSQLI_NUM)===$lmsTablesBefore,'Opening class context created LMS tables.');
echo 'Professor LMS class: ',$checks,' checks passed. Fixtures rolled back.',PHP_EOL;
