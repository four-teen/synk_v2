<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';require_once __DIR__.'/../backend/class_students_api_helper_lms.php';require_once __DIR__.'/../backend/student_assessment_helper_lms.php';require_once __DIR__.'/../backend/assessment_followup_helper_lms.php';require_once __DIR__.'/fixtures/professor-workload-students-data.php';
$lms=synk_database_connection_lms($conn);$checks=0;$session=$_SESSION??[];
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$coreTables='tbl_student_management,tbl_student_management_enrolled_subjects,tbl_faculty,tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_faculty_workload_sched,tbl_class_schedule,tbl_academic_settings,tbl_offering_enrollee_counts,'.synk_schedule_merge_table_name();
$lmsTables='tbl_lms_class_student_statuses,tbl_lms_class_student_status_logs,tbl_lms_assessments,tbl_lms_assessment_questions,tbl_lms_assessment_logs,tbl_lms_assessment_attempts';
$before=$conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC);$lmsBefore=$lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC);$conn->begin_transaction();$lms->begin_transaction();
$denied=static function(callable $fn,int $code)use($lms,$check):void{$lms->query('SAVEPOINT rejected_status');try{$fn();$check(false,'Invalid status change succeeded.');}catch(DomainException $error){$check($error->getCode()===$code,$error->getMessage());}finally{$lms->query('ROLLBACK TO SAVEPOINT rejected_status');}};
try {
    $f=professor_students_fixtures($conn,true);$_SESSION=['role'=>'professor','user_id'=>$f['user_id'],'lms_class_students_csrf'=>bin2hex(random_bytes(32))];
    $classes=array_column(synk_professor_fetch_subject_rows_by_academic_year($conn,$f['faculty_id'],(int)$f['term']['ay_id'],(int)$f['term']['semester']),null,'offering_id');
    $context=['portal'=>['faculty_id'=>$f['faculty_id']],'class'=>$classes[$f['offerings'][0]['offering_id']]];$secondContext=$context;$secondContext['class']=$classes[$f['offerings'][1]['offering_id']];
    $sid=$f['students']['active'];$second=$f['students']['inactive'];$actor=$f['user_id'];
    $roster=synk_class_students_roster_lms($conn,$lms,$context);
    $check($roster['status_ready']&&$roster['counts']===['students'=>3,'enrolled'=>3,'dropped'=>0],'Initial status defaults to enrolled for the real deduplicated roster.');
    $check(array_unique(array_column($roster['students'],'status_version'))===[0],'Unread status defaults do not create database rows.');
    $input=['student_id'=>$sid,'status'=>'dropped','version'=>0,'reason'=>'Requested class withdrawal <script>literal</script>'];
    $coreFixture=$conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC);
    $changed=synk_class_student_status_change_lms($conn,$lms,$context,$input,$actor);$students=array_column($changed['students'],null,'student_id');
    $check($changed['counts']===['students'=>3,'enrolled'=>2,'dropped'=>1]&&$students[$sid]['class_status']==='dropped'&&$students[$sid]['status_version']===1,'Dropping changes class status and counts while retaining the student in the roster.');
    $check($students[$sid]['status_reason']===$input['reason']&&$students[$sid]['status_updated_at']!==null,'The professor note and change timestamp are retained.');
    $check($conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC)===$coreFixture,'Class status must never change shared student profiles, enrollments, schedules or accounts.');
    $check(synk_class_students_roster_lms($conn,$lms,$secondContext)['counts']['dropped']===0,'Dropping one subject does not affect another subject for the same student.');
    $logs=synk_assessment_query_lms($lms,'SELECT previous_status,status,actor_user_id,version FROM tbl_lms_class_student_status_logs ORDER BY log_id DESC LIMIT 1');
    $check($logs[0]['previous_status']==='enrolled'&&$logs[0]['status']==='dropped'&&(int)$logs[0]['actor_user_id']===$actor&&(int)$logs[0]['version']===1,'Every change has an actor, previous state and versioned audit entry.');
    $denied(static function()use($conn,$lms,$context,$input,$actor){synk_class_student_status_change_lms($conn,$lms,$context,$input,$actor);},409);
    foreach ([['status'=>'inactive'],['version'=>-1],['version'=>'0'],['reason'=>str_repeat('x',501)],['student_id'=>$f['students']['foreign']],['student_id'=>$f['students']['withdrawn']],['faculty_id'=>9999]] as $bad) {
        $expected=isset($bad['student_id'])?404:(isset($bad['faculty_id'])?400:422);
        $denied(static function()use($conn,$lms,$context,$input,$bad,$actor){synk_class_student_status_change_lms($conn,$lms,$context,array_replace($input,$bad),$actor);},$expected);
    }
    $a=synk_assessment_publish_lms($lms,$context,['grading_period'=>'final','assessment_type'=>'quiz','title'=>'Status QA','due_at'=>'2099-01-01T17:00','questions'=>[['question_type'=>'true_false','prompt'=>'True statement','points'=>1,'answer_key'=>true]]],$actor);$id=$a['assessment_id'];
    $follow=synk_assessment_missing_submissions_lms($conn,$lms,$context,$id);
    $check($follow['counts']===['students'=>3,'submitted'=>0,'missing'=>2]&&$follow['excluded_dropped_count']===1,'Missing-submission reminders exclude dropped students while reporting the complete roster total.');
    $check(strpos($follow['message'],$students[$sid]['full_name'].' — ')===false&&count($follow['students'])===2,'Dropped students are absent from the copyable reminder.');
    $cards=array_column(synk_assessment_list_with_participation_lms($conn,$lms,$context),null,'assessment_id');
    $check($cards[$id]['missing_count']===2&&$cards[$id]['student_count']===3,'The card missing count excludes dropped students without changing recorded class totals.');
    $list=synk_student_assessment_list_lms($conn,$lms,$sid);$byId=array_column($list,null,'assessment_id');
    $check($byId[$id]['class_status']==='dropped'&&synk_student_assessment_deadline_notices_lms($list,synk_assessment_now_lms())===[],'Dropped students retain assessment access but do not receive dashboard deadline reminders.');
    $start=synk_student_assessment_action_lms($conn,$lms,$sid,'start',['assessment_id'=>$id]);$q=$start['assessment']['questions'][0]['question_id'];
    $done=synk_student_assessment_action_lms($conn,$lms,$sid,'submit',['assessment_id'=>$id,'version'=>1,'answers'=>[$q=>true]]);
    $check($done['attempt']['status']==='submitted'&&$done['result']['correct_count']===1,'Dropped students can still start and submit assessments, as explicitly requested.');
    $restored=synk_class_student_status_change_lms($conn,$lms,$context,['student_id'=>$sid,'status'=>'enrolled','version'=>1,'reason'=>'Restored by professor'],$actor);
    $check($restored['counts']['dropped']===0&&$restored['counts']['enrolled']===3,'Restore returns the student to enrolled without deleting the status history.');
    $after=synk_student_assessment_action_lms($conn,$lms,$sid,'detail',['assessment_id'=>$id]);
    $check($after['attempt']===$done['attempt']&&$after['result']===$done['result'],'Changing class status preserves submitted answers, timers and calculated results.');
    $notices=synk_student_assessment_deadline_notices_lms(synk_student_assessment_list_lms($conn,$lms,$sid),synk_assessment_now_lms());$check(count($notices)===1&&$notices[0]['is_complete'],'Restoring reenables deadline notices and retains completion.');
    $conn->query('SAVEPOINT status_merge');$mergeTable=synk_schedule_merge_table_name();$conn->query('INSERT INTO '.$mergeTable.' (owner_offering_id,member_offering_id,merge_scope) VALUES ('.(int)$f['offerings'][0]['offering_id'].','.(int)$f['offerings'][1]['offering_id'].",'FULL')");
    $merged=synk_class_student_status_change_lms($conn,$lms,$context,['student_id'=>$f['students']['second'],'status'=>'dropped','version'=>0],$actor);
    $check($merged['counts']===['students'=>4,'enrolled'=>3,'dropped'=>1],'Merged rosters allow status changes for current members and deduplicate shared students.');$conn->query('ROLLBACK TO SAVEPOINT status_merge');
    $params=[];parse_str((string)parse_url(synk_professor_class_url_lms($context['class'],'students'),PHP_URL_QUERY),$params);$request=['action'=>'list','payload'=>[],'context'=>$params,'csrf_token'=>$_SESSION['lms_class_students_csrf']];
    $check(count(synk_class_students_request_lms($conn,$request)['roster']['students'])===3,'The roster API accepts the exact signed class context.');
    foreach ([['csrf_token'=>'wrong'],['context'=>array_replace($params,['offering_id'=>(string)$f['offerings'][1]['offering_id']])],['context'=>array_replace($params,['faculty_id'=>999])],['payload'=>['student_id'=>$sid]]] as $bad){$expected=isset($bad['csrf_token'])||isset($bad['context'])&&!isset($bad['context']['faculty_id'])?403:400;$denied(static function()use($conn,$request,$bad){synk_class_students_request_lms($conn,array_replace($request,$bad));},$expected);}
    $_SESSION['role']='student';$denied(static function()use($conn,$request){synk_class_students_request_lms($conn,$request);},401);$_SESSION['role']='professor';
    synk_assessment_query_lms($conn,'DELETE FROM tbl_faculty_workload_sched WHERE faculty_id=?','i',[$f['faculty_id']]);
    $denied(static function()use($conn,$request){synk_class_students_request_lms($conn,$request);},404);
}finally{$lms->rollback();$conn->rollback();$_SESSION=$session;}
$check($conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC)===$before,'Core fixtures must roll back.');$check($lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC)===$lmsBefore,'LMS status, audit and assessment fixtures must roll back.');
echo 'LMS class student status: ',$checks,' checks passed. All fixtures rolled back.',PHP_EOL;
