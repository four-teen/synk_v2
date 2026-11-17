<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';require_once __DIR__.'/../backend/student_assessment_helper_lms.php';require_once __DIR__.'/../backend/assessment_followup_helper_lms.php';require_once __DIR__.'/../backend/professor_class_helper_lms.php';require_once __DIR__.'/fixtures/professor-workload-students-data.php';
$lms=synk_database_connection_lms($conn);$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$coreTables='tbl_student_management,tbl_student_management_enrolled_subjects,tbl_faculty,tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_faculty_workload_sched,tbl_class_schedule,tbl_academic_settings,tbl_offering_enrollee_counts,'.synk_schedule_merge_table_name();
$lmsTables='tbl_lms_assessments,tbl_lms_assessment_questions,tbl_lms_assessment_logs,tbl_lms_assessment_attempts,tbl_lms_class_student_statuses,tbl_lms_class_student_status_logs';
$coreBefore=$conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC);$lmsBefore=$lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC);$conn->begin_transaction();$lms->begin_transaction();
try{
    $f=professor_students_fixtures($conn,true);$sid=$f['students']['active'];$second=$f['students']['inactive'];$third=$f['students']['failed'];$actor=$f['user_id'];
    $classes=array_column(synk_professor_fetch_subject_rows_by_academic_year($conn,$f['faculty_id'],(int)$f['term']['ay_id'],(int)$f['term']['semester']),null,'offering_id');$context=['portal'=>['faculty_id'=>$f['faculty_id']],'class'=>$classes[$f['offerings'][0]['offering_id']]];
    $empty=synk_assessment_class_followup_lms($conn,$lms,$context);$check($empty['students']===[]&&$empty['message']===''&&$empty['counts']['assessments']===0,'Classes without published work generate no names or misleading reminder.');
    $midterm=synk_assessment_publish_lms($lms,$context,['grading_period'=>'midterm','assessment_type'=>'assignment','title'=>'Midterm written work','instructions'=>'Respond','total_points'=>10,'due_at'=>'2099-01-01T17:00'],$actor);
    $quizInput=['grading_period'=>'final','assessment_type'=>'quiz','title'=>'Final quiz original','time_limit_minutes'=>30,'questions'=>[['question_type'=>'true_false','prompt'=>'True statement','points'=>1,'answer_key'=>true]]];
    $quiz=synk_assessment_publish_lms($lms,$context,$quizInput,$actor);$start=synk_student_assessment_action_lms($conn,$lms,$sid,'start',['assessment_id'=>$quiz['assessment_id']]);
    $other=synk_student_assessment_action_lms($conn,$lms,$second,'start',['assessment_id'=>$quiz['assessment_id']]);synk_student_assessment_action_lms($conn,$lms,$second,'submit',['assessment_id'=>$quiz['assessment_id'],'version'=>1,'answers'=>[$other['assessment']['questions'][0]['question_id']=>true]]);
    synk_class_student_status_change_lms($conn,$lms,$context,['student_id'=>$third,'status'=>'dropped','version'=>0,'reason'=>'Private professor note'],$actor);
    $report=synk_assessment_class_followup_lms($conn,$lms,$context);$students=array_column($report['students'],null,'student_number');
    $check($report['counts']===['students'=>3,'eligible'=>2,'dropped'=>1,'excluded'=>0,'assessments'=>2,'missing_students'=>2,'missing_assessments'=>3],'One report totals both periods while counting each outstanding student once.');
    $numbers=synk_assessment_query_lms($conn,'SELECT student_id,student_number FROM tbl_student_management WHERE student_id IN (?,?,?)','iii',[$sid,$second,$third]);$numbers=array_column($numbers,'student_number','student_id');
    $check(count($students[$numbers[$sid]]['assessments'])===2&&count($students[$numbers[$second]]['assessments'])===1&&!isset($students[$numbers[$third]]),'Each student has their own outstanding items; submitted items and dropped students are absent.');
    $items=array_column($students[$numbers[$sid]]['assessments'],null,'assessment_id');
    $check($items[$quiz['assessment_id']]['status']==='in_progress'&&$items[$quiz['assessment_id']]['deadline_at']===$start['attempt']['deadline_at'],'In-progress work shows its original effective timer deadline.');
    $check(strpos($report['message'],"   - Midterm written work")!==false&&strpos($report['message'],"   - Final quiz original")!==false&&substr_count($report['message'],$students[$numbers[$sid]]['student_name'])===1,'The copyable text puts every assessment below a single student heading.');
    foreach(array_values($numbers) as $number)$check(strpos($report['message'],$number)===false,'Copy excludes student numbers.');
    $check(strpos(json_encode($report),'answer_key')===false&&strpos(json_encode($report),'answers_json')===false&&strpos(json_encode($report),'Private professor note')===false,'The report never exposes keys, saved answers or private status notes.');
    synk_assessment_save_lms($lms,$context,array_replace($quizInput,['assessment_id'=>$quiz['assessment_id'],'version'=>1,'title'=>'Edited quiz','grading_period'=>'midterm']),$actor);
    $snapshotReport=synk_assessment_class_followup_lms($conn,$lms,$context);$snapshotStudents=array_column($snapshotReport['students'],null,'student_number');$snapshotItems=array_column($snapshotStudents[$numbers[$sid]]['assessments'],null,'assessment_id');
    $check($snapshotItems[$quiz['assessment_id']]['title']==='Final quiz original'&&$snapshotItems[$quiz['assessment_id']]['grading_period']==='final','Started students retain original assessment metadata after a professor edits it.');
    synk_assessment_query_lms($lms,"UPDATE tbl_lms_assessment_attempts SET deadline_at='2000-01-01 00:00:00' WHERE assessment_id=? AND student_id=?",'ii',[$quiz['assessment_id'],$sid]);
    synk_assessment_close_lms($lms,$context,['assessment_id'=>$midterm['assessment_id'],'version'=>1],$actor);
    synk_assessment_publish_lms($lms,$context,['grading_period'=>'final','assessment_type'=>'assignment','title'=>'Upcoming project','instructions'=>'Respond later','total_points'=>10,'opens_at'=>'2098-01-01T08:00','due_at'=>'2098-01-02T17:00'],$actor);
    synk_assessment_save_lms($lms,$context,['grading_period'=>'final','assessment_type'=>'assignment','title'=>'Private draft','instructions'=>'Not published','total_points'=>10],$actor);
    $otherContext=$context;$otherContext['class']=$classes[$f['offerings'][1]['offering_id']];synk_assessment_publish_lms($lms,$otherContext,['grading_period'=>'final','assessment_type'=>'assignment','title'=>'Other class work','instructions'=>'Respond','total_points'=>10],$actor);
    synk_class_student_status_change_lms($conn,$lms,$context,['student_id'=>$second,'status'=>'excluded','version'=>0,'reason'=>'Test account'],$actor);
    $beforeRead=$lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC);$report=synk_assessment_class_followup_lms($conn,$lms,$context);
    $check($report['counts']['students']===2&&$report['counts']['excluded']===1&&$report['counts']['missing_students']===1&&$report['counts']['missing_assessments']===3,'Exclusion removes the account from actual totals and the grouped reminder.');
    $check($report['counts']['assessments']===3&&count($report['students'])===1,'Draft and other-class work are omitted from a class generation.');
    $statuses=array_column($report['students'][0]['assessments'],'status');$check(in_array('expired',$statuses,true)&&in_array('closed',$statuses,true)&&in_array('upcoming',$statuses,true),'Elapsed, closed and not-yet-open work each has the correct status.');
    $check(strpos($report['message'],'Status: Not open yet')!==false&&strpos($report['message'],'Opens:')!==false&&strpos($report['message'],'As of:')!==false,'Copy identifies upcoming work and the Philippine generation timestamp.');
    $check($lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC)===$beforeRead,'Generating the full class report does not modify assessments, attempts or status records.');
}finally{$lms->rollback();$conn->rollback();}
$check($conn->query('CHECKSUM TABLE '.$coreTables)->fetch_all(MYSQLI_ASSOC)===$coreBefore,'Core fixtures rolled back.');$check($lms->query('CHECKSUM TABLE '.$lmsTables)->fetch_all(MYSQLI_ASSOC)===$lmsBefore,'LMS fixtures rolled back.');
echo 'LMS class follow-up: ',$checks,' checks passed. All fixtures rolled back.',PHP_EOL;
