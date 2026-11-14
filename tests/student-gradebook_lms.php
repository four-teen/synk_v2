<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
session_save_path(__DIR__.'/../temp');session_start();
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';require_once __DIR__.'/../backend/db_lms.php';
require_once __DIR__.'/../backend/student_gradebook_helper_lms.php';require_once __DIR__.'/fixtures/student-gradebook-data_lms.php';
$lms=synk_database_connection_lms($conn);$checks=0;$originalSession=$_SESSION??[];
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$close=static function($a,$b):bool{return $a===null||$b===null?$a===$b:abs($a-$b)<0.00000001;};
$tables='tbl_student_management,tbl_student_management_enrolled_subjects,tbl_faculty,tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_class_schedule,tbl_faculty_workload_sched,tbl_academic_settings,tbl_student_portal_sessions';
$lmsTables='tbl_lms_grading_schemes,tbl_lms_grading_components,tbl_lms_grading_logs,tbl_lms_gradebook_periods,tbl_lms_gradebook_items,tbl_lms_gradebook_scores,tbl_lms_gradebook_logs,tbl_lms_gradebook_visibility,tbl_lms_gradebook_visibility_logs,tbl_lms_assessments,tbl_lms_assessment_questions,tbl_lms_assessment_logs,tbl_lms_assessment_attempts';
$snapshot=static function(mysqli $db,string $names):array{return $db->query('CHECKSUM TABLE '.$names)->fetch_all(MYSQLI_ASSOC);};
$coreBefore=$snapshot($conn,$tables);$lmsBefore=$snapshot($lms,$lmsTables);$schemaBefore=$lms->query('SHOW TABLES')->fetch_all(MYSQLI_NUM);
$conn->begin_transaction();$lms->begin_transaction();
try{
    $f=student_gradebook_fixtures_lms($conn,$lms);$id=$f['students']['active'];$context=$f['context'];$actor=$f['user_id'];
    $professor=synk_gradebook_workspace_lms($conn,$lms,$context);$professorSummary=(array)$professor['summary'];
    student_gradebook_fixture_login_lms($conn,$id);
    $hidden=synk_student_gradebook_workspace_lms($conn,$lms);
    foreach($hidden['subjects'] as $row){
        $check($row['visibility']===['midterm'=>false,'final'=>false,'overall'=>false]&&$row['summary']['midterm']===null&&$row['summary']['final']===null&&$row['summary']['average']===null&&$row['summary']['rating']===null,'All grades must default to hidden, including existing scored gradebooks.');
        foreach($row['periods'] as $period)$check($period['result']===null&&$period['entries']===[]&&$period['updated_at']===null,'A hidden period must not expose grades, components, work or update metadata.');
    }
    $show=static function(array $scope,string $part,bool $visible)use($conn,$lms,$actor):void{
        $current=synk_gradebook_visibility_lms($lms,$scope);
        synk_gradebook_mutate_lms($conn,$lms,$scope,'set_visibility',['version'=>$current['version'],'grade_part'=>$part,'visible'=>$visible],$actor);
    };
    foreach(['midterm','final','overall'] as $part)$show($context,$part,true);
    $onlyFirst=synk_student_gradebook_workspace_lms($conn,$lms);
    $check(count(array_filter($onlyFirst['subjects'],static function(array $row):bool{return $row['visibility']['overall'];}))===1,'Releasing one class must not release the student’s other subject.');
    foreach(synk_student_gradebook_classes_lms($conn,$id)['classes'] as $scope)if($scope['context']!==null)foreach(['midterm','final','overall'] as $part)$show($scope['context'],$part,true);
    $coreReadBefore=$snapshot($conn,$tables);$lmsReadBefore=$snapshot($lms,$lmsTables);
    $book=synk_student_gradebook_workspace_lms($conn,$lms);
    $check(count($book['subjects'])===2,'The student must see exactly their two current enrolled subjects.');
    $first=$book['subjects'][0];$second=$book['subjects'][1];
    if(!$first['periods']['midterm']['result']['complete']){$swap=$first;$first=$second;$second=$swap;}
    foreach(['midterm','final','average','rating'] as $key)$check($close($first['summary'][$key],$professorSummary[$id][$key]),'The student summary differs from professor gradebook: '.$key);
    $check($first['summary']['remark']===$professorSummary[$id]['remark'],'Remarks must match the professor gradebook.');
    $check($close($first['summary']['midterm'],61.6)&&$close($first['summary']['final'],90)&&$close($first['summary']['average'],75.8)&&$first['summary']['rating']===3.0,'Weighted scores, missing-score zeros, and 50/50 averaging must match the saved grading setup.');
    $check($second['summary']['midterm']===null&&$second['summary']['average']===null&&$second['summary']['rating']===null,'Unconfigured periods must remain null, not zero or Failed.');
    $check(synk_student_gradebook_number_lms(null,true)==='—'&&synk_student_gradebook_number_lms(0,true)==='0.00%'&&synk_student_gradebook_number_lms(3)==='3.00','Nulls, recorded zeros, and rating precision must stay distinct.');
    $entries=array_column($first['periods']['midterm']['entries'],null,'title');
    $check($entries['Class activity']['points']===null&&$entries['Written examination']['points']===80.0,'Unrecorded score cells must stay null while retaining recorded scores.');
    $check($entries['LMS concept check']['points']===2.0&&$entries['LMS concept check']['source']==='lms','Automatic points must use weighted questions and retain pending-written-work rules.');
    $serialized=json_encode($book);
    foreach(['PRIVATE CLASSMATE NOTE','PRIVATE QUESTION CONTENT','PRIVATE STUDENT ANSWER','answer_key','answers_json','assessment_snapshot_json','actor_user_id','score_id'] as $secret)$check(strpos($serialized,$secret)===false,'The student view leaked private gradebook data: '.$secret);
    $check($snapshot($conn,$tables)===$coreReadBefore&&$snapshot($lms,$lmsTables)===$lmsReadBefore,'Student gradebook reads must never write score, attempt, academic, student or workload data.');
    $render=static function(array $gradebook,string $gradebookError='')use($conn,$id):string{
        $student=synk_student_fetch_directory_record_by_student_id($conn,$id);
        $studentPortalViewTerm=$gradebook['term'];$studentPortalDisplayName='Student Gradebook QA';$studentPortalDisplayEmail='gradebook-qa@example.test';$studentPortalPreviewMode=false;
        $previousDirectory=getcwd();$_SERVER['PHP_SELF']='/synk/student/gradebook_lms.php';chdir(__DIR__.'/../student');
        ob_start();try{require __DIR__.'/../student/gradebook_view_lms.php';return ob_get_contents();}finally{ob_end_clean();chdir($previousDirectory);}
    };
    $html=$render($book);
    $check(strpos($html,'75.80%')!==false&&strpos($html,'3.00')!==false&&substr_count($html,'data-grade-details=')===2,'The seven-column student summary must render both enrolled subjects and the calculated grades.');
    $check(strpos($html,'Pending setup')!==false&&strpos($html,'Unrecorded; counts as zero')!==false,'Pending setups and unrecorded scores must have clear student labels.');
    foreach(['PRIVATE CLASSMATE NOTE','PRIVATE QUESTION CONTENT','PRIVATE STUDENT ANSWER','answer_key'] as $secret)$check(strpos($html,$secret)===false,'The rendered page leaked private data: '.$secret);
    $empty=$render(['term'=>$book['term'],'subjects'=>[]]);$check(strpos($empty,'No subjects for this term yet')!==false&&strpos($empty,'data-grade-details=')===false,'A student without enrollments must see an empty state without grades.');
    $unavailable=$render($book,'Your gradebook is temporarily unavailable. Please try again later.');
    $check(strpos($unavailable,'role="alert"')!==false&&strpos($unavailable,'data-grade-details=')===false,'A failed read must show an error without displaying stale grades.');
    if(($argv[1]??'')==='--render'){
        $directory=__DIR__.'/../.tmp';if(!is_dir($directory))mkdir($directory);
        file_put_contents($directory.'/student-gradebook_lms.html',str_replace('<head>','<head><base href="/synk/student/">',$html));
    }
    // Exercise all independent combinations, including showing only the overall final grade.
    for($mask=0;$mask<8;$mask++){
        foreach(['midterm','final','overall'] as $bit=>$part)$show($context,$part,(bool)($mask&(1<<$bit)));
        $combination=synk_student_gradebook_workspace_lms($conn,$lms);$subject=null;
        foreach($combination['subjects'] as $row)if($row['subject_code']===$first['subject_code'])$subject=$row;
        foreach(['midterm','final'] as $bit=>$part){
            $visible=(bool)($mask&(1<<$bit));
            $check($subject['visibility'][$part]===$visible&&$subject['summary'][$part]===($visible?$first['summary'][$part]:null),'A period summary must follow only its own release control.');
            $check($visible?$subject['periods'][$part]===$first['periods'][$part]:$subject['periods'][$part]===['visible'=>false,'result'=>null,'entries'=>[],'updated_at'=>null],'Hidden period data must be omitted from the student payload, including when the overall final grade is visible.');
        }
        foreach(['average','rating'] as $field)$check($subject['summary'][$field]===($mask&4?$first['summary'][$field]:null),'Average and rating must follow the overall final grade control.');
        $check($subject['summary']['remark']===($mask&4?$first['summary']['remark']:'—'),'Hidden final-grade remarks must not expose Passed, Failed or Conditional.');
        $rendered=$render($combination);
        foreach(['midterm'=>'61.60%','final'=>'90.00%','overall'=>'75.80%'] as $part=>$gradeText)$check((strpos($rendered,$gradeText)!==false)===$subject['visibility'][$part],'Rendered grades must honor the professor release controls: '.$part);
        if($mask===0)$check(strpos($rendered,'Written examination')===false&&strpos($rendered,'LMS concept check')===false&&strpos($rendered,'Not released')!==false,'Hidden score entries must not exist in page source.');
        if(($argv[1]??'')==='--render'&&in_array($mask,[0,1,4],true))file_put_contents($directory.'/student-gradebook-visibility-'.$mask.'_lms.html',str_replace('<head>','<head><base href="/synk/student/">',$rendered));
    }
    $_GET=['student_id'=>$f['students']['failed'],'ay_id'=>999999,'semester'=>3,'offering_id'=>$f['offerings'][2]['offering_id']];
    $check(synk_student_gradebook_workspace_lms($conn,$lms)===$book,'Client selectors must not change the server-owned student or term.');$_GET=[];
    // Manual reviewed LMS totals take precedence, exactly as in the professor view.
    $revision=static function(string $period='midterm')use($lms,$context):array{$b=synk_gradebook_period_lms($lms,$context,$period);return ['grading_period'=>$period,'version'=>$b['version'],'scheme_version'=>$b['scheme']['version']];};
    synk_gradebook_mutate_lms($conn,$lms,$context,'save_scores',$revision()+['item_id'=>$f['items']['lms'],'scores'=>[['student_id'=>$id,'points'=>10,'remark'=>'PRIVATE INSTRUCTOR REVIEW NOTE']]],$actor);
    $updated=synk_student_gradebook_workspace_lms($conn,$lms);$updatedFirst=null;foreach($updated['subjects'] as $row)if($row['periods']['midterm']['result']['complete'])$updatedFirst=$row;
    $check($close($updatedFirst['summary']['midterm'],68)&&$close($updatedFirst['summary']['average'],79),'A saved manual LMS override must immediately update the student grade.');
    $check(strpos(json_encode($updated),'PRIVATE INSTRUCTOR REVIEW NOTE')===false,'Staff-only score remarks must not be published.');
    $latest=$f['schemes']['midterm'];$latest['components'][0]['weight']=50;$latest['components'][1]['weight']=50;synk_grading_save_lms($lms,$context,$latest,$actor);
    $check(synk_student_gradebook_workspace_lms($conn,$lms)===$updated,'Later grading setup edits must not silently replace the frozen gradebook revision.');
    synk_gradebook_mutate_lms($conn,$lms,$context,'archive_item',$revision()+['item_id'=>$f['items']['missing']],$actor);
    $archived=synk_student_gradebook_workspace_lms($conn,$lms);$expected=synk_gradebook_workspace_lms($conn,$lms,$context);$expectedSummary=(array)$expected['summary'];
    foreach($archived['subjects'] as $row)if($row['periods']['midterm']['result']['complete'])$check($row['summary']===$expectedSummary[$id],'Archived entries must be excluded using the exact professor calculation.');
    student_gradebook_fixture_login_lms($conn,$f['students']['failed']);$other=synk_student_gradebook_workspace_lms($conn,$lms);
    $check(count($other['subjects'])===1&&$other['subjects'][0]['summary']['midterm']===0.0&&$other['subjects'][0]['summary']['final']===100.0,'A classmate must see only their own recorded zero and final score.');
    student_gradebook_fixture_login_lms($conn,$f['students']['inactive']);$legacy=synk_student_gradebook_workspace_lms($conn,$lms);
    $check(count($legacy['subjects'])===1&&$legacy['subjects'][0]['available']&&$legacy['subjects'][0]['summary']['average']===0.0,'Legacy enrollment without an offering ID must resolve using the existing membership rules.');
    foreach(['foreign','withdrawn','wrong_term'] as $who){student_gradebook_fixture_login_lms($conn,$f['students'][$who]);$check(synk_student_gradebook_workspace_lms($conn,$lms)['subjects']===[],'An inactive, foreign or old-term enrollment must not expose grades: '.$who);}
    student_gradebook_fixture_login_lms($conn,$id);$_SESSION['student_id']=$f['students']['failed'];
    try{synk_student_gradebook_workspace_lms($conn,$lms);$check(false,'A mismatched student identity was accepted.');}catch(DomainException $e){$check($e->getCode()===401,'A mismatched identity must be rejected.');}
    foreach(['admin','professor',''] as $role){$_SESSION=['role'=>$role,'user_id'=>$actor];try{synk_student_gradebook_workspace_lms($conn,$lms);$check(false,'A non-student role was accepted.');}catch(DomainException $e){$check($e->getCode()===401,'A non-student role must be rejected.');}}
    student_gradebook_fixture_login_lms($conn,$id);$conn->query('DELETE FROM tbl_faculty_workload_sched WHERE faculty_id='.$f['faculty_id']);
    $reassigned=synk_student_gradebook_workspace_lms($conn,$lms);foreach($reassigned['subjects'] as $row)$check(!$row['available']&&$row['summary']['average']===null&&$row['periods']===[],'Revoked class assignments must stop exposing their gradebook.');
    $check($lms->query('SHOW TABLES')->fetch_all(MYSQLI_NUM)===$schemaBefore,'Student reads must not install or alter LMS tables.');
}finally{$lms->rollback();$conn->rollback();$_SESSION=$originalSession;$_GET=[];session_destroy();}
$check($snapshot($conn,$tables)===$coreBefore,'Core fixture data was not rolled back.');$check($snapshot($lms,$lmsTables)===$lmsBefore,'LMS fixture data was not rolled back.');
echo 'Student LMS gradebook: ',$checks,' checks passed. All fixtures rolled back.',PHP_EOL;
