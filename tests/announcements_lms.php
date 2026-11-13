<?php
// php tests/announcements_lms.php. All fixture data is rolled back in both databases.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/announcement_api_helper_lms.php';
require_once __DIR__.'/fixtures/professor-workload-students-data.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$denied=static function(callable $fn,int $code)use($check):void{try{$fn();throw new RuntimeException('Unauthorized request succeeded.');}catch(DomainException $e){$check($e->getCode()===$code,$e->getMessage().' / expected '.$code);}};
$lms=synk_database_connection_lms($conn);$originalSession=$_SESSION??[];
$coreTables='tbl_academic_settings,tbl_class_schedule,tbl_faculty_workload_sched,tbl_student_management,tbl_student_management_enrolled_subjects,tbl_faculty,tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_offering_enrollee_counts,'.synk_schedule_merge_table_name();
$lmsTables='tbl_lms_announcements,tbl_lms_announcement_reads,tbl_lms_assessments,tbl_lms_assessment_logs,tbl_lms_assessment_questions';
$before=$conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC);
$lmsBefore=$lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC);
$conn->begin_transaction();$lms->begin_transaction();
try {
    $f=professor_students_fixtures($conn,true);$sid=$f['students']['active'];$actor=$f['user_id'];
    $_SESSION=['user_id'=>$actor,'role'=>'professor','lms_announcement_csrf'=>bin2hex(random_bytes(32))];
    $sign=static function(int $offering)use($f):array{
        $url=synk_professor_class_url_lms(['faculty_id'=>$f['faculty_id'],'offering_id'=>$offering,'ay_id'=>$f['term']['ay_id'],'semester'=>$f['term']['semester']],'announcements');
        parse_str(parse_url($url,PHP_URL_QUERY),$input);return $input;
    };
    $input=$sign((int)$f['offerings'][0]['offering_id']);$context=synk_professor_class_context_lms($conn,$input);
    $check($context['view']==='announcements','Announcement card uses a signed class view.');
    $other=synk_professor_class_context_lms($conn,$sign((int)$f['offerings'][1]['offering_id']));
    $feed=static function(int $student)use($conn,$lms):array{return synk_student_announcement_feed_lms($conn,$lms,$student);};
    $check($feed($sid)===[],'A fresh class has no announcements.');
    $payload=['title'=>'Reminder <img src=x onerror=alert(1)>','body'=>"Bring your notes.\nLiteral <script>code</script> and Unicode: café."];
    $posted=synk_announcement_write_lms($lms,$context,'save',$payload,$actor)['announcements'][0];$id=(int)$posted['announcement_id'];
    $check($posted['body']===$payload['body'],'Announcement text and line breaks round-trip without executing HTML.');
    $first=$feed($sid);$key=$first[0]['event_key'];
    $check(count($first)===1&&$first[0]['unread']&&$first[0]['title']===$payload['title'],'Enrolled students see a new class notice.');
    $check(count($feed($f['students']['inactive']))===1,'Legacy enrollments receive the same class notice.');
    foreach(['foreign','withdrawn','wrong_term','second'] as $keyName)$check($feed($f['students'][$keyName])===[],'Foreign, withdrawn, historical and other-class students cannot see the notice.');
    $denied(static function()use($lms,$other,$id,$actor){synk_announcement_write_lms($lms,$other,'archive',['announcement_id'=>$id,'version'=>1],$actor);},404);
    $denied(static function()use($lms,$context,$id,$actor,$payload){synk_announcement_write_lms($lms,$context,'save',$payload+['announcement_id'=>$id,'version'=>99],$actor);},409);
    foreach([['title'=>''],['body'=>' '],['title'=>str_repeat('x',201)],['body'=>str_repeat('x',10001)],['title'=>['invalid']]] as $bad){
        $denied(static function()use($lms,$context,$payload,$bad,$actor){synk_announcement_write_lms($lms,$context,'save',array_replace($payload,$bad),$actor);},422);
    }
    synk_student_announcement_read_lms($conn,$lms,$sid,[$key,$key]);
    $check(!$feed($sid)[0]['unread'],'Dismissed updates persist as read on a fresh fetch.');
    $check($feed($f['students']['inactive'])[0]['unread'],'Read status is private to each student.');
    $denied(static function()use($conn,$lms,$f,$key){synk_student_announcement_read_lms($conn,$lms,$f['students']['inactive'],[$key,'announcement:2147483647:1']);},404);
    $check($feed($f['students']['inactive'])[0]['unread'],'A mixed valid/foreign acknowledgment saves no partial read state.');
    $denied(static function()use($conn,$lms,$f,$key){synk_student_announcement_read_lms($conn,$lms,$f['students']['foreign'],[$key]);},404);
    $updated=synk_announcement_write_lms($lms,$context,'save',['announcement_id'=>$id,'version'=>1,'title'=>'Updated reminder','body'=>'Bring a calculator.'],$actor)['announcements'][0];
    $check((int)$updated['version']===2&&$feed($sid)[0]['event_key']!==$key&&$feed($sid)[0]['unread'],'Updating a notice creates one new unread version.');
    $second=synk_announcement_write_lms($lms,$other,'save',['title'=>'Other class','body'=>'Other section notice.'],$actor)['announcements'][0];
    $check(count($feed($sid))===2&&count($feed($f['students']['second']))===1,'Multi-class students get both notices with separate section membership.');
    $sample=['grading_period'=>'final','assessment_type'=>'assignment','title'=>'Published project','instructions'=>'Write your response.','total_points'=>100,'opens_at'=>'2099-01-01T08:00','due_at'=>'2099-01-02T17:00'];
    $draft=synk_assessment_save_lms($lms,$context,$sample,$actor);
    $check(count($feed($sid))===2,'Private activity drafts create no notice.');
    $activity=synk_assessment_publish_lms($lms,$context,$sample+['assessment_id'=>$draft['assessment_id'],'version'=>1],$actor);
    $notices=array_values(array_filter($feed($sid),static function(array $n):bool{return $n['kind']==='assignment';}));$activityKey=$notices[0]['event_key'];
    $check(count($notices)===1&&$notices[0]['assessment_id']==$activity['assessment_id']&&$notices[0]['opens_at']==='2099-01-01 08:00:00','Publishing automatically creates a scheduled activity notice.');
    $check(!str_contains(json_encode($feed($sid)),'answer_key')&&!str_contains(json_encode($feed($sid)),'instructions'),'Notices never load or expose assessment questions, instructions or answer keys.');
    synk_student_announcement_read_lms($conn,$lms,$sid,[$activityKey]);
    $edited=synk_assessment_save_lms($lms,$context,array_replace($sample,['assessment_id'=>$activity['assessment_id'],'version'=>$activity['version'],'title'=>'Updated project title']),$actor);
    $notices=array_values(array_filter($feed($sid),static function(array $n):bool{return $n['kind']==='assignment';}));
    $check(count($notices)===1&&$notices[0]['event_key']===$activityKey&&!$notices[0]['unread']&&$notices[0]['title']==='Updated project title','Ordinary activity edits update the notice without duplicate alerts.');
    $closed=synk_assessment_close_lms($lms,$context,['assessment_id'=>$activity['assessment_id'],'version'=>$edited['version']],$actor);
    $notices=array_values(array_filter($feed($sid),static function(array $n):bool{return $n['kind']==='assignment';}));
    $check($notices[0]['status']==='closed'&&!$notices[0]['unread'],'Closing an activity retains its notice with current status.');
    synk_assessment_publish_lms($lms,$context,$sample+['assessment_id'=>$activity['assessment_id'],'version'=>$closed['version']],$actor);
    $notices=array_values(array_filter($feed($sid),static function(array $n):bool{return $n['kind']==='assignment';}));
    $check(count($notices)===1&&$notices[0]['event_key']!==$activityKey&&$notices[0]['unread'],'Reopening updates the existing activity notice and alerts once.');
    $lms->query('SAVEPOINT notice_activity_types');
    foreach(['quiz','exam'] as $type){
        synk_assessment_publish_lms($lms,$context,['grading_period'=>'midterm','assessment_type'=>$type,'title'=>ucfirst($type).' notice','instructions'=>'Read carefully.','questions'=>[['question_type'=>'true_false','prompt'=>'A statement','points'=>1,'answer_key'=>true]]],$actor);
        $typeNotices=array_values(array_filter($feed($sid),static function(array $n)use($type):bool{return $n['kind']===$type;}));
        $check(count($typeNotices)===1&&$typeNotices[0]['url']!==null,'Published '.$type.' activities automatically receive a linked notice.');
    }
    $lms->query('ROLLBACK TO SAVEPOINT notice_activity_types');
    synk_announcement_write_lms($lms,$context,'archive',['announcement_id'=>$id,'version'=>2],$actor);
    $check(count($feed($sid))===2,'Removing a class notice hides it immediately.');
    $denied(static function()use($lms,$context,$id,$actor){synk_announcement_write_lms($lms,$context,'archive',['announcement_id'=>$id,'version'=>2],$actor);},404);
    $request=['csrf_token'=>$_SESSION['lms_announcement_csrf'],'action'=>'save','context'=>$input,'payload'=>$payload];
    $denied(static function()use($conn,$request){synk_announcement_request_lms($conn,array_replace($request,['csrf_token'=>'bad']),false);},403);
    $denied(static function()use($conn,$request){$request['payload']['faculty_id']=999999;synk_announcement_request_lms($conn,$request,false);},400);
    $denied(static function()use($conn,$request,$f){$request['context']['offering_id']=(string)$f['offerings'][1]['offering_id'];synk_announcement_request_lms($conn,$request,false);},403);
    $_SESSION['role']='student';$denied(static function()use($conn,$request){synk_announcement_request_lms($conn,$request,false);},401);$_SESSION['role']='professor';
    $conn->query('SAVEPOINT notice_merged');$merge=synk_schedule_merge_table_name();$a=(int)$f['offerings'][0]['offering_id'];$b=(int)$f['offerings'][1]['offering_id'];
    $conn->query("INSERT INTO {$merge} (owner_offering_id,member_offering_id,merge_scope) VALUES ({$a},{$b},'FULL')");
    $check(count($feed($f['students']['second']))===1,'Merged member students receive the owner activity notice once.');
    $conn->query('ROLLBACK TO SAVEPOINT notice_merged');
    $conn->query('SAVEPOINT notice_inactive');$conn->query("UPDATE tbl_faculty SET status='inactive' WHERE faculty_id=".$f['faculty_id']);
    $check($feed($sid)===[],'Inactive faculty updates are hidden.');$conn->query('ROLLBACK TO SAVEPOINT notice_inactive');
    $conn->query('DELETE FROM tbl_faculty_workload_sched WHERE faculty_id='.$f['faculty_id']);
    $check($feed($sid)===[],'Removing workload ownership revokes visibility immediately.');
} finally {$lms->rollback();$conn->rollback();$_SESSION=$originalSession;}
$check($conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC)===$before,'Core academic, student, account or schedule records changed.');
$check($lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC)===$lmsBefore,'LMS fixture data was not rolled back.');
foreach(['professor','student'] as $portal){
    $curl=curl_init('http://localhost/synk/'.$portal.'/announcements_api_lms.php');curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15]);$body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    $check($status===405&&json_decode($body,true)['ok']===false,'Announcement endpoints require POST.');
    $curl=curl_init('http://localhost/synk/'.$portal.'/announcements_api_lms.php');curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>'{}',CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);$body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
    $check($status===401&&json_decode($body,true)['ok']===false,'Anonymous requests cannot access announcement data.');
}
echo 'LMS announcements: ',$checks,' checks passed. All fixtures rolled back.',PHP_EOL;
