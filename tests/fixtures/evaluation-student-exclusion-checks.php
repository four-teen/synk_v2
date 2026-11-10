<?php
// Extends the real report/persistence fixture while its isolated data is still in a transaction.
if (PHP_SAPI!=='cli' || !isset($r0,$r1,$r2,$r3,$r4,$deanSession,$check)) { http_response_code(404); exit; }
require_once __DIR__.'/../../backend/supervisor_evaluation_helper.php';
$_SESSION=$deanSession;
$studentInput=['csrf_token'=>'qa-csrf','college_id'=>$college,'ay_id'=>$ay,'semester'=>$semester,'student_id'=>$students[0],'excluded'=>1,'reason'=>'PRIVATE_STUDENT_REASON <script>private</script>','version'=>0];
synk_evaluation_save_student_exclusion($conn,$studentInput);
$states=synk_evaluation_score_states($conn,$ay,$semester);
$check($states[$r0['evaluation_id']]['state']==='excluded' && $states[$r3['evaluation_id']]['state']==='excluded','One student rule excludes every teacher and subject evaluated by that student.');
$check(!isset($states[$r1['evaluation_id']]) && !isset($states[$r4['evaluation_id']]),'Other students and other colleges remain unaffected when no subject rule is active.');
$report=synk_dean_evaluations_data($conn,$scope,$period,['faculty_id'=>$teachers[0],'instrument_version'=>'all','search'=>'']);
$row=array_values(array_filter($report['rows'],static fn(array $row):bool=>$row['faculty_id']===$teachers[0]))[0];
$check($row['submitted_count']===3 && $row['excluded_count']===1 && $row['score']['percentage']===80.0 && $report['detail']['groups'][0]['categories']['teaching_learning']['score']['percentage']===80.0,'Dean totals, categories and submission counts follow student eligibility.');
$combined=synk_sef_performance($conn,$scope,$period,['role'=>'college_dean','user_id'=>$dean,'faculty_id'=>0]);
$check($combined[$teachers[0]]['student']['score']['component']===48.0 && $combined[$teachers[0]]['student']['excluded_count']===1,'Combined performance uses eligible student ratings in its 60 percent component.');
$individual=synk_student_fetch_faculty_evaluation_individual_report_rows($conn,$ay,$semester);
$faculty=array_values(array_filter($individual,static fn(array $row):bool=>$row['faculty_id']===$teachers[0]))[0];
$check($faculty['overall_percentage']===80.0,'Administrator aggregates consistently exclude the student only in the matching college.');
$ratingSheet=synk_student_fetch_faculty_evaluation_rating_sheet_rows($conn,$ay,$semester);
$record=array_values(array_filter($ratingSheet,static fn(array $row):bool=>$row['evaluation_id']===$r0['evaluation_id']))[0];
$check($record['score_state']==='excluded' && $record['average_percentage']===20.0,'Original student ratings are retained and labelled excluded on the administrator rating sheet.');
synk_evaluation_query($conn,'UPDATE tbl_useraccount_faculty_links SET faculty_id=? WHERE user_id=?','ii',[$teachers[0],$professor]);
$_SESSION=['user_id'=>$professor,'role'=>'professor'];
$released=synk_professor_evaluation_results($conn,$period+['college_id'=>$college]);
$check($released[0]['score']['percentage']===80.0 && $released[0]['response_count']===2 && count($released[0]['comments'])===3,'Released professor scores exclude student ratings and preserve comments.');
$check(strpos(json_encode($released),'PRIVATE_STUDENT_REASON')===false,'Private student exclusion reasons never enter released professor results.');
$_SESSION=$deanSession;
$mixedInput=array_replace($studentInput,['student_id'=>$students[2],'reason'=>'Exclude all responses including combined subjects']);
synk_evaluation_save_student_exclusion($conn,$mixedInput);
$check(synk_evaluation_score_states($conn,$ay,$semester)[$r2['evaluation_id']]['state']==='excluded','A student rule fully excludes a combined-subject response.');
$conn->query('SAVEPOINT student_future_check');
$enroll($students[2],$teachers[1],1);
$future=$save($students[2],$teachers[1],$labelB,1,$college);
$check(!isset(synk_evaluation_score_states($conn,$ay,$semester)[$future['evaluation_id']]),'Excluded students can still save drafts without entering scores.');
synk_student_finalize_faculty_evaluations_for_term($conn,$students[2],$ay,$semester,$teachers,['college_id'=>$college]);
$check(synk_evaluation_score_states($conn,$ay,$semester)[$future['evaluation_id']]['state']==='excluded','The existing rule automatically excludes later submissions to another teacher.');
$conn->query('ROLLBACK TO SAVEPOINT student_future_check');

$conn->query('SAVEPOINT student_history_check');
synk_evaluation_query($conn,'DELETE FROM tbl_student_faculty_evaluation_subject_scope WHERE evaluation_id=?','i',[$r2['evaluation_id']]);
synk_evaluation_query($conn,"UPDATE tbl_student_faculty_evaluations SET college_id=0,subject_summary='Unverified historical subject' WHERE evaluation_id=?",'i',[$r2['evaluation_id']]);
$check(synk_evaluation_score_states($conn,$ay,$semester)[$r2['evaluation_id']]['state']==='excluded','Student rules apply to proven college ownership even without verifiable historical subjects.');
synk_evaluation_query($conn,'UPDATE tbl_student_faculty_evaluations SET college_id=0 WHERE evaluation_id=?','i',[$r0['evaluation_id']]);
$check(!isset(synk_evaluation_score_states($conn,$ay,$semester)[$r0['evaluation_id']]),'Ambiguous legacy college ownership is never guessed for student exclusion.');
synk_evaluation_query($conn,'DELETE FROM tbl_student_management_enrolled_subjects WHERE college_id=?','i',[$college]);
$candidates=synk_evaluation_student_candidates($conn,$college,$ay,$semester);
$check(isset($candidates[$students[0]],$candidates[$students[2]]),'Existing rules remain manageable after the original roster is removed.');
$check(synk_evaluation_score_states($conn,$ay,$semester)[$r3['evaluation_id']]['state']==='excluded','Explicit response ownership keeps student exclusions effective after roster removal.');
$conn->query('ROLLBACK TO SAVEPOINT student_history_check');

$conn->query('SAVEPOINT student_term_check');
$alternateSemester=$semester===1?2:1;
$alternateYear=(int)$conn->query('SELECT ay_id FROM tbl_academic_years WHERE ay_id<>'.$ay.' ORDER BY ay_id LIMIT 1')->fetch_assoc()['ay_id'];
foreach ([[$ay,$alternateSemester],[$alternateYear,$semester]] as [$testAy,$testSemester]) {
    synk_evaluation_query($conn,"INSERT INTO tbl_student_faculty_evaluations (student_id,faculty_id,college_id,ay_id,semester,faculty_name,instrument_version,question_count,total_score,evaluation_token,submission_status) VALUES (?,?,?,?,?,'Term fixture','set-15',15,15,?,'submitted')",'iiiiis',[$students[0],$teachers[0],$college,$testAy,$testSemester,$tag.bin2hex(random_bytes(5))]);
    $historicalId=(int)$conn->insert_id;
    $check((synk_evaluation_score_states($conn,$testAy,$testSemester)[$historicalId]['state'] ?? 'included')==='included','A student rule cannot carry into a different year or semester.');
}
$conn->query('ROLLBACK TO SAVEPOINT student_term_check');
foreach ([['csrf_token'=>'bad'],['version'=>0],['college_id'=>$colleges[1]],['student_id'=>2147483647],['student_id'=>[$students[0]]],['semester'=>4],['reason'=>'  '],['reason'=>str_repeat('x',1001)],['excluded'=>2],['reason'=>['bad']]] as $bad) {
    $rejected=false;
    try { synk_evaluation_save_student_exclusion($conn,array_replace($studentInput,['version'=>1],$bad)); } catch (DomainException $error) { $rejected=true; }
    $check($rejected,'Invalid, stale or unauthorized student exclusion rejected: '.json_encode(array_keys($bad)));
}
foreach (['program_chair','professor','student','admin'] as $role) {
    $_SESSION['role']=$role; $rejected=false;
    try { synk_evaluation_save_student_exclusion($conn,$studentInput); } catch (DomainException $error) { $rejected=true; }
    $check($rejected,'Only the assigned Dean may save student rules: '.$role);
}
$_SESSION=$deanSession;
$conn->query('SAVEPOINT student_revoked_check');
synk_evaluation_query($conn,"UPDATE tbl_useraccount SET status='inactive' WHERE user_id=?",'i',[$dean]);
$rejected=false;
try { synk_evaluation_save_student_exclusion($conn,array_replace($studentInput,['version'=>1])); } catch (DomainException $error) { $rejected=true; }
$check($rejected,'Student exclusion writes revalidate a revoked Dean account.');
$conn->query('ROLLBACK TO SAVEPOINT student_revoked_check');
$conn->failAudit=true; $failed=false;
try { synk_evaluation_save_student_exclusion($conn,array_replace($studentInput,['excluded'=>0,'version'=>1])); } catch (RuntimeException $error) { $failed=true; }
$conn->failAudit=false;
$check($failed && synk_evaluation_score_states($conn,$ay,$semester)[$r0['evaluation_id']]['state']==='excluded','Audit failure rolls back the student rule update.');

synk_evaluation_save_subject_exclusion($conn,array_replace($input,['excluded'=>1,'version'=>2]));
synk_evaluation_save_student_exclusion($conn,array_replace($studentInput,['excluded'=>0,'version'=>1,'reason'=>'Restore student; keep subject exclusions']));
$states=synk_evaluation_score_states($conn,$ay,$semester);
$check($states[$r0['evaluation_id']]['state']==='excluded' && $states[$r0['evaluation_id']]['exclusion_source']==='subject','Restoring a student keeps a matching subject exclusion in effect.');
$check($states[$r2['evaluation_id']]['state']==='excluded' && $states[$r2['evaluation_id']]['exclusion_source']==='student','Student exclusions take precedence over mixed-subject review.');
synk_evaluation_save_subject_exclusion($conn,array_replace($input,['excluded'=>0,'version'=>3]));
$check(synk_evaluation_score_states($conn,$ay,$semester)[$r2['evaluation_id']]['state']==='excluded','Restoring a subject cannot undo an active student exclusion.');
synk_evaluation_save_student_exclusion($conn,array_replace($studentInput,['excluded'=>1,'version'=>2]));
synk_evaluation_save_student_exclusion($conn,array_replace($studentInput,['student_id'=>$students[1],'version'=>0]));
$report=synk_dean_evaluations_data($conn,$scope,$period,['faculty_id'=>$teachers[0],'instrument_version'=>'all','search'=>'']);
$row=array_values(array_filter($report['rows'],static fn(array $row):bool=>$row['faculty_id']===$teachers[0]))[0];
$check($row['score']===null && $row['submitted_count']===3 && $row['excluded_count']===3,'All-excluded results display no score and retain every submission count.');
$check(count($report['detail']['groups'][0]['comments'])===3,'All-excluded teacher feedback remains preserved.');
foreach ([[$studentInput,3],[$mixedInput,1],[array_replace($studentInput,['student_id'=>$students[1]]),1]] as [$restoreInput,$version]) synk_evaluation_save_student_exclusion($conn,array_replace($restoreInput,['excluded'=>0,'version'=>$version,'reason'=>'Restore after review']));
$restored=synk_dean_evaluations_data($conn,$scope,$period,['faculty_id'=>$teachers[0],'instrument_version'=>'all','search'=>'']);
$check($restored['detail']['groups'][0]['score']['percentage']===60.0,'Restoring student rules restores original scores.');
$check($original===$snapshot($conn,['tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_class_schedule','tbl_faculty_workload_sched','tbl_academic_settings']),'Student rule writes preserve source responses, profiles, enrollments, schedules, workloads and academic settings.');
$check((int)$conn->query('SELECT COUNT(*) n FROM tbl_college_evaluation_student_exclusion_audit WHERE college_id='.$college)->fetch_assoc()['n']===8,'Every committed student rule change has an audit entry and rejected writes have none.');
