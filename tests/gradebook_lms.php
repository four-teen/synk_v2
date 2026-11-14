<?php
// All data fixtures roll back. The dedicated installer owns schema changes.
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/gradebook_api_helper_lms.php';
require_once __DIR__.'/fixtures/professor-workload-students-data.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$denied=static function(callable $call,int $status)use($check):void{try{$call();$check(false,'Invalid gradebook operation accepted.');}catch(DomainException $e){$check($e->getCode()===$status,'Unexpected rejection: '.$e->getMessage());}};
$snapshot=static function(mysqli $db,string $tables):array{return $db->query('CHECKSUM TABLE '.$tables)->fetch_all(MYSQLI_ASSOC);};
$coreTables='tbl_class_schedule,tbl_faculty_workload_sched,tbl_academic_settings,tbl_student_management,tbl_student_management_enrolled_subjects,tbl_useraccount,tbl_useraccount_roles,tbl_useraccount_faculty_links,tbl_faculty';
$lmsTables='tbl_lms_gradebook_periods,tbl_lms_gradebook_items,tbl_lms_gradebook_scores,tbl_lms_gradebook_logs,tbl_lms_gradebook_visibility,tbl_lms_gradebook_visibility_logs,tbl_lms_grading_schemes,tbl_lms_grading_components,tbl_lms_grading_logs,tbl_lms_assessments,tbl_lms_assessment_questions,tbl_lms_assessment_logs,tbl_lms_assessment_attempts';
$lms=synk_database_connection_lms($conn);$check(synk_gradebook_ready_lms($lms),'Install LMS gradebook storage before running tests.');
$coreBefore=$snapshot($conn,$coreTables);$lmsBefore=$snapshot($lms,$lmsTables);$tablesBefore=$lms->query('SHOW TABLES')->fetch_all(MYSQLI_NUM);$originalSession=$_SESSION??[];
$check($conn->query("SHOW TABLES LIKE 'tbl_lms_gradebook_%'")->num_rows===0,'Gradebook tables must not be installed in core storage.');
foreach ([['0',0],['0.01',1],['9999.99',999999],['10000',1000000]] as $test)$check(synk_gradebook_points_lms($test[0])===$test[1],'Decimal point parsing failed.');
foreach ([-1,'1e2','NaN','10000.01',true,null,[],INF,'0.001'] as $bad)$denied(static function()use($bad){synk_gradebook_points_lms($bad);},422);
foreach ([[0,5],[72,5],[72.99,5],[73,4],[74.99,4],[75,3],[77.99,3],[78,2.75],[80.99,2.75],[81,2.5],[83.99,2.5],[84,2.25],[86.99,2.25],[87,2],[89.99,2],[90,1.75],[92.99,1.75],[93,1.5],[95.99,1.5],[96,1.25],[98.99,1.25],[99,1],[100,1]] as $test)$check(synk_gradebook_rating_lms($test[0])['rating']==$test[1],'Attached legend rating boundary failed.');
$zeroScheme=['total_weight'=>100,'components'=>[['component_id'=>1,'name'=>'Quizzes','weight'=>20],['component_id'=>2,'name'=>'Other work','weight'=>80]]];
$twoQuizzes=[['item_id'=>1,'component_id'=>1,'max_points'=>4],['item_id'=>2,'component_id'=>1,'max_points'=>15]];
$zeroResult=synk_gradebook_calculate_lms($zeroScheme,$twoQuizzes,[1=>[1=>['points'=>1]]],1);
$check($zeroResult['complete']&&abs($zeroResult['components'][0]['contribution']-1/19*20)<0.000000001&&$zeroResult['components'][1]['contribution']===0.0&&abs($zeroResult['percent']-1/19*20)<0.000000001,'Two assigned quizzes must use score + zero over both maximum scores, with empty components contributing zero.');
$updatedResult=synk_gradebook_calculate_lms($zeroScheme,$twoQuizzes,[1=>[1=>['points'=>1]],2=>[1=>['points'=>10]]],1);
$check(abs($updatedResult['percent']-11/19*20)<0.000000001,'A newly recorded second quiz score must update the weighted component and period total dynamically.');
$conn->begin_transaction();$lms->begin_transaction();
try {
    $fixture=professor_students_fixtures($conn);$actor=$fixture['user_id'];
    $_SESSION=['user_id'=>$actor,'role'=>'professor','lms_gradebook_csrf'=>bin2hex(random_bytes(32))];
    $params=[];parse_str((string)parse_url(synk_professor_class_url_lms(['faculty_id'=>$fixture['faculty_id'],'offering_id'=>$fixture['offerings'][0]['offering_id'],'ay_id'=>$fixture['term']['ay_id'],'semester'=>$fixture['term']['semester']],'gradebook'),PHP_URL_QUERY),$params);
    $context=synk_professor_class_context_lms($conn,$params);$student=(int)$fixture['students']['active'];$other=(int)$fixture['students']['failed'];
    $workspace=synk_gradebook_workspace_lms($conn,$lms,$context);
    $check(count($workspace['roster'])===3 && $workspace['periods']['midterm']['book_id']===0,'Gradebook must use the exact named roster and not initialize empty periods.');
    $check($workspace['visibility']===['ready'=>true,'version'=>0,'midterm'=>false,'final'=>false,'overall'=>false],'A class must default to all grades hidden without creating a visibility record.');
    $visibilitySave=static function(array $payload)use($conn,$lms,$context,$actor):array{return synk_gradebook_mutate_lms($conn,$lms,$context,'set_visibility',$payload,$actor);};
    $visibilitySave(['version'=>0,'grade_part'=>'midterm','visible'=>true]);
    $shown=synk_gradebook_visibility_lms($lms,$context);
    $check($shown['midterm']&&!$shown['final']&&!$shown['overall']&&$shown['version']===1,'Each visibility change must update only the selected result and its revision.');
    $check(synk_gradebook_period_lms($lms,$context,'midterm')['book_id']===0,'Visibility controls must not freeze a grading scheme or create a score book.');
    $denied(static function()use($visibilitySave){$visibilitySave(['version'=>0,'grade_part'=>'final','visible'=>true]);},409);
    foreach([['grade_part'=>'summary','visible'=>true],['grade_part'=>'final','visible'=>'1'],['grade_part'=>'final','visible'=>1],['grade_part'=>'final','visible'=>null]] as $invalid)$denied(static function()use($visibilitySave,$invalid){$visibilitySave(['version'=>1]+$invalid);},422);
    $check(synk_gradebook_visibility_lms($lms,$context)===$shown,'Stale or malformed release requests must leave every flag unchanged.');
    $visibilitySave(['version'=>1,'grade_part'=>'midterm','visible'=>false]);$initialVisibility=synk_gradebook_visibility_lms($lms,$context);
    foreach([['portal'=>['faculty_id'=>$fixture['faculty_id']+1000000],'class'=>$context['class']],['portal'=>$context['portal'],'class'=>array_replace($context['class'],['offering_id'=>$fixture['offerings'][1]['offering_id']])],['portal'=>$context['portal'],'class'=>array_replace($context['class'],['semester'=>2])],['portal'=>$context['portal'],'class'=>array_replace($context['class'],['ay_id'=>$fixture['term']['ay_id']+1000000])]] as $unrelated)$check(synk_gradebook_visibility_lms($lms,$unrelated)['version']===0,'Visibility must be isolated by professor, offering, academic year and semester.');
    $visibilityLogs=synk_grading_query_lms($lms,'SELECT l.* FROM tbl_lms_gradebook_visibility_logs l JOIN tbl_lms_gradebook_visibility v ON v.visibility_id=l.visibility_id WHERE v.faculty_id=?','i',[$fixture['faculty_id']]);
    $check(count($visibilityLogs)===2&&(int)$visibilityLogs[0]['was_visible']===0&&(int)$visibilityLogs[0]['is_visible']===1&&(int)$visibilityLogs[1]['is_visible']===0&&(int)$visibilityLogs[0]['actor_user_id']===$actor,'Showing and hiding must retain the actor, old/new state and revision in the LMS audit history.');
    $save=static function(string $action,array $payload)use($conn,$lms,$context,$actor):array{return synk_gradebook_mutate_lms($conn,$lms,$context,$action,$payload,$actor);};
    $revision=static function(string $period='midterm')use($lms,$context):array{$b=synk_gradebook_period_lms($lms,$context,$period);return ['grading_period'=>$period,'version'=>$b['version'],'scheme_version'=>$b['scheme']['version']];};
    $manual=['item_id'=>0,'entry_kind'=>'score','title'=>'Handwritten exam','max_points'=>'60','recorded_date'=>'2026-11-11'];
    $denied(static function()use($save,$revision,$manual){$save('save_item',$revision()+$manual+['component_id'=>1]);},422);
    $scheme=synk_grading_save_lms($lms,$context,['grading_period'=>'midterm','version'=>0,'components'=>[['name'=>'Exam','weight'=>'60'],['name'=>'Attendance','weight'=>'10'],['name'=>'Classwork','weight'=>'30']]],$actor);
    $component=array_column($scheme['components'],'component_id');
    $savedManual=$save('save_item',$revision()+$manual+['component_id'=>$component[0]]);
    $book=synk_gradebook_period_lms($lms,$context,'midterm');$items=synk_gradebook_items_lms($lms,$book['book_id']);$exam=$items[0]['item_id'];
    $check($savedManual===['saved_item_id'=>$exam],'Creating a manual entry must return its exact saved ID for immediate score encoding.');
    $check($book['version']===1 && $book['scheme']['version']===1,'Recording the first entry must freeze the grading scheme revision.');
    $save('save_item',$revision()+['entry_kind'=>'attendance','title'=>'Attendance Nov 11','component_id'=>$component[1],'max_points'=>1]);
    $items=synk_gradebook_items_lms($lms,$book['book_id']);$attendance=$items[1]['item_id'];
    $save('save_item',$revision()+['entry_kind'=>'score','title'=>'Activity 1','component_id'=>$component[2],'max_points'=>10]);
    $save('save_item',$revision()+['entry_kind'=>'score','title'=>'Activity 2','component_id'=>$component[2],'max_points'=>30]);
    $items=synk_gradebook_items_lms($lms,$book['book_id']);
    foreach ([[$exam,40],[$attendance,1],[$items[2]['item_id'],10],[$items[3]['item_id'],15]] as $score) $save('save_scores',$revision()+['item_id'=>$score[0],'scores'=>[['student_id'=>$student,'points'=>$score[1]]]]);
    $workspace=synk_gradebook_workspace_lms($conn,$lms,$context);$result=$workspace['periods']['midterm']['results']->{$student};
    $check(abs($result['percent']-68.75)<0.00000001,'Point-weighted component calculation must equal 40 + 10 + 18.75, without any extra project split.');
    $check($result['complete'] && $result['rating']===5.0 && $workspace['summary']->{$student}['average']===null,'Midterm must be complete while an unconfigured Final keeps the semester summary incomplete.');
    $save('save_scores',$revision()+['item_id'=>$exam,'scores'=>[['student_id'=>$other,'points'=>0]]]);
    $workspace=synk_gradebook_workspace_lms($conn,$lms,$context);
    $check($workspace['periods']['midterm']['results']->{$other}['graded_count']===1 && $workspace['periods']['midterm']['results']->{$other}['percent']==0 && $workspace['periods']['midterm']['results']->{$other}['rating']==5,'Recorded zeros and missing scores must produce a zero period grade with the legend rating.');
    foreach ([['student_id'=>$student,'points'=>61],['student_id'=>$fixture['students']['foreign'],'points'=>10],['student_id'=>$student,'points'=>-1]] as $bad) $denied(static function()use($save,$revision,$exam,$bad){$save('save_scores',$revision()+['item_id'=>$exam,'scores'=>[$bad]]);},422);
    $denied(static function()use($save,$revision,$attendance,$student){$save('save_scores',$revision()+['item_id'=>$attendance,'scores'=>[['student_id'=>$student,'points'=>'0.5']]]);},422);
    $before=synk_grading_query_lms($lms,'SELECT points FROM tbl_lms_gradebook_scores WHERE item_id=? AND student_id=?','ii',[$exam,$student]);
    $denied(static function()use($save,$revision,$exam,$student,$fixture){$save('save_scores',$revision()+['item_id'=>$exam,'scores'=>[['student_id'=>$student,'points'=>20],['student_id'=>$fixture['students']['foreign'],'points'=>10]]]);},422);
    $check(synk_grading_query_lms($lms,'SELECT points FROM tbl_lms_gradebook_scores WHERE item_id=? AND student_id=?','ii',[$exam,$student])===$before,'A bad batch must not partially update valid student scores.');
    $stale=$revision();$save('save_scores',$revision()+['item_id'=>$exam,'scores'=>[['student_id'=>$student,'points'=>45]]]);
    $denied(static function()use($save,$stale,$exam,$student){$save('save_scores',$stale+['item_id'=>$exam,'scores'=>[['student_id'=>$student,'points'=>50]]]);},409);
    $edited=$scheme;$edited['components'][0]['weight']=50;$edited['components'][2]['weight']=40;
    $newScheme=synk_grading_save_lms($lms,$context,$edited,$actor);
    $b=synk_gradebook_period_lms($lms,$context,'midterm');$check($b['scheme_changed'] && $b['scheme']['components'][0]['weight']==60,'New grading weights must not silently change recorded grade calculations.');
    $save('use_latest_scheme',$revision());$check(synk_gradebook_period_lms($lms,$context,'midterm')['scheme']['version']===2,'An explicit revision update must apply the latest grading weights.');
    $removedScheme=$newScheme;$removedScheme['components'][0]['weight']=90;array_splice($removedScheme['components'],2,1);
    $removedScheme=synk_grading_save_lms($lms,$context,$removedScheme,$actor);
    $denied(static function()use($save,$revision){$save('use_latest_scheme',$revision());},422);
    $check(synk_gradebook_period_lms($lms,$context,'midterm')['scheme']['version']===2,'A removed component still referenced by active entries must block revision application.');
    // Restore the same active IDs for subsequent scenarios, retaining the immutable book revision.
    synk_grading_query_lms($lms,"UPDATE tbl_lms_grading_components SET status='active' WHERE component_id=?",'i',[$component[2]]);
    synk_grading_query_lms($lms,'UPDATE tbl_lms_grading_components SET weight_bps=5000 WHERE component_id=?','i',[$component[0]]);
    $check(synk_gradebook_period_lms($lms,$context,'midterm')['scheme']['components'][0]['weight']==50,'Even further scheme edits must preserve the snapshot already used by the gradebook.');
    $final=synk_grading_save_lms($lms,$context,['grading_period'=>'final','version'=>0,'components'=>[['name'=>'Final exam','weight'=>100]]],$actor);
    $save('save_item',$revision('final')+['entry_kind'=>'score','title'=>'Final handwritten exam','component_id'=>$final['components'][0]['component_id'],'max_points'=>100]);
    $finalBook=synk_gradebook_period_lms($lms,$context,'final');$finalItem=synk_gradebook_items_lms($lms,$finalBook['book_id'])[0];
    $save('save_scores',$revision('final')+['item_id'=>$finalItem['item_id'],'scores'=>[['student_id'=>$student,'points'=>90]]]);
    $workspace=synk_gradebook_workspace_lms($conn,$lms,$context);$summary=$workspace['summary']->{$student};
    $check(abs($summary['midterm']-72.5)<0.00000001 && $summary['final']==90 && abs($summary['average']-81.25)<0.00000001 && $summary['rating']==2.5,'Period grades, the 50/50 semester average, and reference rating must agree.');
    $denied(static function()use($save,$revision,$exam,$final){$save('save_item',$revision()+['item_id'=>$exam,'component_id'=>$final['components'][0]['component_id'],'title'=>'Wrong component','max_points'=>60]);},422);
    // Objective assessment uses question points, never the count of correct answers.
    $assessment=synk_assessment_save_lms($lms,$context,['grading_period'=>'midterm','assessment_type'=>'quiz','title'=>'Weighted LMS quiz','questions'=>[['question_type'=>'multiple_choice','prompt'=>'A?','points'=>2,'options'=>['A','B'],'answer_key'=>0],['question_type'=>'true_false','prompt'=>'B?','points'=>8,'answer_key'=>true]]],$actor);
    synk_grading_query_lms($lms,"UPDATE tbl_lms_assessments SET status='published' WHERE assessment_id=?",'i',[$assessment['assessment_id']]);
    $now=synk_assessment_now_lms();$answers=json_encode([$assessment['questions'][0]['question_id']=>0,$assessment['questions'][1]['question_id']=>false]);
    synk_grading_query_lms($lms,"INSERT INTO tbl_lms_assessment_attempts (assessment_id,student_id,status,answers_json,assessment_snapshot_json,started_at,submitted_at,updated_at) VALUES (?,?,'submitted',?,?,?,?,?)",'iisssss',[$assessment['assessment_id'],$student,$answers,synk_assessment_snapshot_json_lms($assessment),$now,$now,$now]);
    $versionBeforeDiscovery=synk_gradebook_period_lms($lms,$context,'midterm')['version'];
    $discovered=synk_gradebook_available_assessments_lms($lms,$context,synk_gradebook_roster_lms($conn,$context));
    $check(count($discovered)===1 && $discovered[0]['link_status']==='unlinked' && $discovered[0]['submission_count']===1 && $discovered[0]['scores']->{$student}['points']==2,'Completed LMS work must be discoverable with its score before being mapped to a component.');
    $check(synk_gradebook_period_lms($lms,$context,'midterm')['version']===$versionBeforeDiscovery,'Discovering unlinked scores must not mutate a gradebook or assign weights.');
    $foreignContext=$context;$foreignContext['class']['offering_id']=(int)$fixture['offerings'][1]['offering_id'];
    $check(synk_gradebook_available_assessments_lms($lms,$foreignContext,synk_gradebook_roster_lms($conn,$foreignContext))===[],'Unlinked discovery must not expose submissions in another class.');
    $save('save_item',$revision()+['entry_kind'=>'lms','assessment_id'=>$assessment['assessment_id'],'title'=>'Linked quiz','component_id'=>$component[2],'max_points'=>999]);
    $items=synk_gradebook_items_lms($lms,$book['book_id']);$linked=end($items);
    $map=synk_gradebook_score_map_lms($lms,$context,$items,synk_gradebook_roster_lms($conn,$context));
    $check($linked['max_points']==10 && $map[$linked['item_id']][$student]['points']==2,'LMS maximum points come from the assessment; scores must use the 2-point correct answer, not 50% for one of two questions.');
    $attempt=['status'=>'submitted','answers_json'=>$answers,'assessment_snapshot_json'=>synk_assessment_snapshot_json_lms($assessment)];
    $check(synk_gradebook_auto_points_lms($assessment,$attempt,100)['points']==20,'Original attempt percentages must scale to the linked entry maximum.');
    $written=$assessment;$written['questions'][0]['question_type']='essay';$written['questions'][0]['answer_key']=null;
    $attempt['assessment_snapshot_json']=synk_assessment_snapshot_json_lms($written);
    $check(synk_gradebook_auto_points_lms($assessment,$attempt,10)['points']==0,'Unreviewed written answers count as zero in the gradebook.');
    $written['assessment_type']='assignment';$attempt['assessment_snapshot_json']=synk_assessment_snapshot_json_lms($written);
    $check(synk_gradebook_auto_points_lms($assessment,$attempt,10)['points']==0,'An assignment without a manual grade must contribute zero.');
    $denied(static function()use($save,$revision,$assessment,$component){$save('save_item',$revision()+['entry_kind'=>'lms','assessment_id'=>$assessment['assessment_id'],'title'=>'Duplicate LMS quiz','component_id'=>$component[2],'max_points'=>10]);},422);
    $denied(static function()use($save,$revision,$linked,$student){$save('save_scores',$revision()+['item_id'=>$linked['item_id'],'scores'=>[['student_id'=>$student,'points'=>7]]]);},422);
    $save('save_scores',$revision()+['item_id'=>$linked['item_id'],'scores'=>[['student_id'=>$student,'points'=>7,'remark'=>'Reviewed correction']]]);
    $map=synk_gradebook_score_map_lms($lms,$context,$items,synk_gradebook_roster_lms($conn,$context));$check($map[$linked['item_id']][$student]['points']==7 && $map[$linked['item_id']][$student]['source']==='manual' && $map[$linked['item_id']][$student]['automatic_points']==2,'Manual reviews must override the effective score while retaining the automatic score shown when clearing an override.');
    $save('save_scores',$revision()+['item_id'=>$linked['item_id'],'scores'=>[['student_id'=>$student,'points'=>null]]]);
    $map=synk_gradebook_score_map_lms($lms,$context,$items,synk_gradebook_roster_lms($conn,$context));$check($map[$linked['item_id']][$student]['points']==2,'Clearing a manual LMS override restores the automatic score.');
    $unmappedFinal=synk_assessment_save_lms($lms,$context,['grading_period'=>'final','assessment_type'=>'quiz','title'=>'Unmapped Final quiz','questions'=>[['question_type'=>'true_false','prompt'=>'Final quiz?','points'=>1,'answer_key'=>true]]],$actor);
    synk_grading_query_lms($lms,"UPDATE tbl_lms_assessments SET status='published' WHERE assessment_id=?",'i',[$unmappedFinal['assessment_id']]);
    synk_grading_query_lms($lms,"INSERT INTO tbl_lms_assessment_attempts (assessment_id,student_id,status,answers_json,assessment_snapshot_json,started_at,submitted_at,updated_at) VALUES (?,?,'submitted',?,?,?,?,?)",'iisssss',[$unmappedFinal['assessment_id'],$student,json_encode([$unmappedFinal['questions'][0]['question_id']=>false]),synk_assessment_snapshot_json_lms($unmappedFinal),$now,$now,$now]);
    synk_grading_query_lms($lms,"INSERT INTO tbl_lms_assessment_attempts (assessment_id,student_id,status,answers_json,assessment_snapshot_json,started_at,submitted_at,updated_at) VALUES (?,?,'submitted',?,?,?,?,?)",'iisssss',[$unmappedFinal['assessment_id'],$other,json_encode([$unmappedFinal['questions'][0]['question_id']=>true]),synk_assessment_snapshot_json_lms($unmappedFinal),$now,$now,$now]);
    $discovered=array_column(synk_gradebook_available_assessments_lms($lms,$context,synk_gradebook_roster_lms($conn,$context)),null,'assessment_id');
    $check($discovered[$assessment['assessment_id']]['link_status']==='active' && $discovered[$unmappedFinal['assessment_id']]['link_status']==='unlinked' && $discovered[$unmappedFinal['assessment_id']]['grading_period']==='final' && $discovered[$unmappedFinal['assessment_id']]['scores']->{$student}['points']==0,'Discovery must distinguish mapped work, Final work and an actual zero score.');
    if (($argv[1]??'')==='--render') {
        $renderHtml=$argv[2];$renderData=$argv[3];
        file_put_contents($renderData,json_encode(synk_gradebook_workspace_lms($conn,$lms,$context),JSON_UNESCAPED_UNICODE));
        $render=static function()use($conn,$fixture,$context):string {
            $root=dirname(__DIR__);$sessionState=$_SESSION;$previousSessionId=session_id();
            session_save_path($root.'/.tmp');session_id('gradebookqa'.bin2hex(random_bytes(10)));session_start();$_SESSION=$sessionState;
            $query=[];parse_str((string)parse_url(synk_professor_class_url_lms($context['class'],'gradebook'),PHP_URL_QUERY),$query);$_GET=$query;
            session_write_close();$_SERVER['REQUEST_METHOD']='GET';$_SERVER['PHP_SELF']='/synk/professor/class_lms.php';
            chdir($root.'/professor');ob_start();require $root.'/professor/class_lms.php';$html=ob_get_clean();session_destroy();session_id($previousSessionId);$_SESSION=$sessionState;return $html;
        };
        file_put_contents($renderHtml,$render());
    }
    // Exercise real LMS submissions after linking, period isolation, reassignment,
    // and the semester summary together. These fixtures still roll back below.
    $secondQuiz=synk_assessment_save_lms($lms,$context,['grading_period'=>'midterm','assessment_type'=>'quiz','title'=>'Second weighted LMS quiz','questions'=>[['question_type'=>'true_false','prompt'=>'Second quiz?','points'=>4,'answer_key'=>true]]],$actor);
    synk_grading_query_lms($lms,"UPDATE tbl_lms_assessments SET status='published' WHERE assessment_id=?",'i',[$secondQuiz['assessment_id']]);
    $save('save_item',$revision()+['entry_kind'=>'lms','assessment_id'=>$secondQuiz['assessment_id'],'title'=>'Second linked quiz','component_id'=>$component[2],'max_points'=>4]);
    $midtermItems=synk_gradebook_items_lms($lms,$book['book_id']);$secondItem=end($midtermItems);
    $workspace=synk_gradebook_workspace_lms($conn,$lms,$context);$r=$workspace['periods']['midterm']['results']->{$student};
    $check(abs($r['components'][2]['earned']-27)<0.00000001 && $r['components'][2]['possible']==54 && abs($r['percent']-67.5)<0.00000001,'Two linked quizzes and manual work must share the assigned component, with the unsubmitted quiz contributing zero and its maximum still counted.');
    $check($workspace['periods']['final']['results']->{$student}['percent']==90 && abs($workspace['summary']->{$student}['average']-78.75)<0.00000001,'Assigning Midterm work must immediately update the semester summary while preserving Final.');
    $versionBeforeSubmission=$revision();
    synk_grading_query_lms($lms,"INSERT INTO tbl_lms_assessment_attempts (assessment_id,student_id,status,answers_json,assessment_snapshot_json,started_at,submitted_at,updated_at) VALUES (?,?,'submitted',?,?,?,?,?)",'iisssss',[$secondQuiz['assessment_id'],$student,json_encode([$secondQuiz['questions'][0]['question_id']=>true]),synk_assessment_snapshot_json_lms($secondQuiz),$now,$now,$now]);
    $workspace=synk_gradebook_workspace_lms($conn,$lms,$context);$r=$workspace['periods']['midterm']['results']->{$student};$expectedMidterm=37.5+10+31/54*40;
    $check($workspace['periods']['midterm']['scores']->{$secondItem['item_id']}->{$student}['points']==4 && abs($r['percent']-$expectedMidterm)<0.00000001 && abs($workspace['summary']->{$student}['average']-($expectedMidterm+90)/2)<0.00000001,'A new LMS submission must update its score, weighted component, period grade and semester average on the next workspace read.');
    $check($revision()===$versionBeforeSubmission,'Synchronizing an LMS submission must not write artificial score rows or change the saved gradebook revision.');
    $save('save_item',$revision()+['item_id'=>$secondItem['item_id'],'entry_kind'=>'lms','assessment_id'=>$secondQuiz['assessment_id'],'title'=>'Second linked quiz','component_id'=>$component[0],'max_points'=>4]);
    $workspace=synk_gradebook_workspace_lms($conn,$lms,$context);$r=$workspace['periods']['midterm']['results']->{$student};
    $check(abs($r['components'][0]['contribution']-49/64*50)<0.00000001 && abs($r['components'][2]['contribution']-27/50*40)<0.00000001 && count($workspace['periods']['midterm']['items'])===6,'Reassigning a quiz must move its score and maximum to the selected component without counting it twice.');
    $denied(static function()use($save,$revision,$unmappedFinal,$component){$save('save_item',$revision()+['entry_kind'=>'lms','assessment_id'=>$unmappedFinal['assessment_id'],'title'=>'Wrong period quiz','component_id'=>$component[2],'max_points'=>1]);},422);
    $save('save_item',$revision('final')+['entry_kind'=>'lms','assessment_id'=>$unmappedFinal['assessment_id'],'title'=>'Linked Final quiz','component_id'=>$final['components'][0]['component_id'],'max_points'=>1]);
    $workspace=synk_gradebook_workspace_lms($conn,$lms,$context);$expectedFinal=90/101*100;$currentMidterm=$r['percent'];
    $check(abs($workspace['periods']['final']['results']->{$student}['percent']-$expectedFinal)<0.00000001 && abs($workspace['periods']['midterm']['results']->{$student}['percent']-$currentMidterm)<0.00000001 && abs($workspace['summary']->{$student}['average']-($currentMidterm+$expectedFinal)/2)<0.00000001,'Assigning a zero-score Final assessment must use the Final component weight and update the same student semester summary without changing Midterm.');
    $save('archive_item',$revision()+['item_id'=>$secondItem['item_id']]);
    $save('archive_item',$revision()+['item_id'=>$linked['item_id']]);
    $check(count(synk_gradebook_items_lms($lms,$book['book_id']))===4 && synk_grading_query_lms($lms,'SELECT status FROM tbl_lms_gradebook_items WHERE item_id=?','i',[$linked['item_id']])[0]['status']==='archived','Archiving removes an entry from calculations and preserves its history.');
    $discovered=array_column(synk_gradebook_available_assessments_lms($lms,$context,synk_gradebook_roster_lms($conn,$context)),null,'assessment_id');
    $check($discovered[$assessment['assessment_id']]['link_status']==='archived','Archived LMS entries must not reappear as unlinked work and invite double counting.');
    $request=['action'=>'get','context'=>$params,'csrf_token'=>$_SESSION['lms_gradebook_csrf']];
    $releaseRequest=$request;$releaseRequest['action']='set_visibility';$releaseRequest['payload']=['version'=>$initialVisibility['version'],'grade_part'=>'overall','visible'=>true];
    foreach([['csrf_token'=>'bad'],['context'=>array_diff_key($params,['signature'=>true])],['context'=>array_replace($params,['offering_id'=>$fixture['offerings'][1]['offering_id']])]] as $bad)$denied(static function()use($conn,$releaseRequest,$bad){synk_gradebook_request_lms($conn,array_replace($releaseRequest,$bad));},403);
    $withSelector=$releaseRequest;$withSelector['payload']['student_id']=$student;$denied(static function()use($conn,$withSelector){synk_gradebook_request_lms($conn,$withSelector);},400);
    $professorSession=$_SESSION;foreach(['student','admin',''] as $role){$_SESSION=['role'=>$role,'user_id'=>$actor];$denied(static function()use($conn,$releaseRequest){synk_gradebook_request_lms($conn,$releaseRequest);},401);}$_SESSION=$professorSession;
    $check(synk_gradebook_visibility_lms($lms,$context)===$initialVisibility,'Score entry, archiving and grading revision changes must not release any grades.');
    foreach ([['csrf_token'=>'bad'],['context'=>array_replace($params,['view'=>'grading'])],['context'=>array_diff_key($params,['signature'=>true])]] as $bad)$denied(static function()use($conn,$request,$bad){synk_gradebook_request_lms($conn,array_replace($request,$bad));},403);
    $denied(static function()use($conn,$request){synk_gradebook_request_lms($conn,$request+['faculty_id'=>1]);},400);
    $denied(static function()use($conn,$request){synk_gradebook_request_lms($conn,array_replace($request,['payload'=>['student_id'=>1]]));},400);
    $conn->query('DELETE FROM tbl_faculty_workload_sched WHERE faculty_id='.$fixture['faculty_id']);
    $denied(static function()use($conn,$request){synk_gradebook_request_lms($conn,$request);},404);
    $denied(static function()use($conn,$releaseRequest){synk_gradebook_request_lms($conn,$releaseRequest);},404);
    $logs=synk_grading_query_lms($lms,'SELECT action,details_json FROM tbl_lms_gradebook_logs WHERE book_id=?','i',[$book['book_id']]);
    $check(count($logs)>10 && json_decode($logs[0]['details_json'],true)['after']['title']==='Handwritten exam','Mutations must retain auditable before/after details.');
    $check($lms->query('SHOW TABLES')->fetch_all(MYSQLI_NUM)===$tablesBefore,'Gradebook requests must not create or alter tables.');
} finally {$lms->rollback();$conn->rollback();$_SESSION=$originalSession;}
$check($snapshot($conn,$coreTables)===$coreBefore,'Core schedules, workloads, student profiles or academic settings changed.');
$check($snapshot($lms,$lmsTables)===$lmsBefore,'Gradebook test fixtures were not rolled back.');
class GradebookAuditFailureLms extends mysqli
{
    #[\ReturnTypeWillChange]
    public function prepare($sql) {
        if (strpos($sql,'INSERT INTO tbl_lms_gradebook_logs')===0) throw new RuntimeException('Simulated gradebook audit failure.');
        if (strpos($sql,'INSERT INTO tbl_lms_gradebook_visibility_logs')===0) throw new RuntimeException('Simulated visibility audit failure.');
        return parent::prepare($sql);
    }
}
$settings=require __DIR__.'/../backend/db_config.local_lms.php';$failure=new GradebookAuditFailureLms($settings['host'],$settings['username'],$settings['password'],$settings['database']);unset($settings);$failure->set_charset('utf8mb4');$failure->begin_transaction();
try {
    $fake=['portal'=>['faculty_id'=>2147483000],'class'=>['offering_id'=>2147483000,'ay_id'=>2147483000,'semester'=>1]];
    $testScheme=synk_grading_save_lms($failure,$fake,['grading_period'=>'midterm','version'=>0,'components'=>[['name'=>'Audit failure QA','weight'=>100]]],2147483000);
    synk_gradebook_mutate_lms($conn,$failure,$fake,'save_item',['grading_period'=>'midterm','version'=>0,'scheme_version'=>1,'entry_kind'=>'score','title'=>'Audit failure entry','max_points'=>100,'component_id'=>$testScheme['components'][0]['component_id']],2147483000);
    $check(false,'A failed gradebook audit must fail the whole operation.');
} catch(RuntimeException $error) {$check($error->getMessage()==='Simulated gradebook audit failure.','Unexpected audit failure check error.');}
finally {$failure->rollback();}
try {
    $failure->begin_transaction();
    synk_gradebook_mutate_lms($conn,$failure,$fake,'set_visibility',['version'=>0,'grade_part'=>'overall','visible'=>true],2147483000);
    $check(false,'A failed visibility audit must fail the release.');
} catch(RuntimeException $error){$check($error->getMessage()==='Simulated visibility audit failure.','Unexpected visibility audit failure check error.');}
finally {$failure->rollback();$failure->close();}
$check($snapshot($lms,$lmsTables)===$lmsBefore,'Late audit failures must roll back period and entry writes.');
echo 'LMS gradebook: ',$checks,' checks passed. All fixtures rolled back.',PHP_EOL;
