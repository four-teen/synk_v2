<?php
// Evaluation projection regression checks. All fixture writes are rolled back.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/professor_evaluations_helper.php';
require_once __DIR__.'/fixtures/professor-workload-students-data.php';
$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void { if (!$ok) throw new RuntimeException($message); $checks++; };
$snapshot=static function()use($conn):string {
    $data=[];
    foreach (['tbl_faculty','tbl_useraccount','tbl_useraccount_roles','tbl_useraccount_faculty_links','tbl_faculty_workload_sched',
        'tbl_student_management','tbl_student_management_enrolled_subjects','tbl_offering_enrollee_counts','tbl_student_login_activity',
        'tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_student_faculty_evaluation_subject_scope','tbl_college_evaluation_subject_exclusions','tbl_college_evaluation_settings',
        'tbl_supervisor_evaluation_assignments','tbl_supervisor_evaluations',synk_schedule_merge_table_name(),'tbl_academic_settings'] as $table) {
        $data[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);
    }
    return hash('sha256',json_encode($data));
};
$before=$snapshot();
$conn->begin_transaction();
try {
    extract(professor_students_fixtures($conn));
    $_SESSION=['user_id'=>$user_id,'role'=>'professor'];
    $college=(int)$offerings[0]['college_id'];
    $scope=['college_id'=>$college,'ay_id'=>(int)$term['ay_id'],'semester'=>(int)$term['semester']];
    $current=synk_professor_evaluation_current_scope($conn);
    $check((int)$current['college_id']===$college && (int)$current['ay_id']===(int)$term['ay_id'] && (int)$current['semester']===(int)$term['semester'],'The current scope follows the administrator term and linked faculty college.');
    $otherCollege=$conn->query("SELECT college_id FROM tbl_college WHERE status='active' AND college_id<>{$college} LIMIT 1")->fetch_assoc();
    $conn->query('UPDATE tbl_useraccount SET college_id='.(int)$otherCollege['college_id']." WHERE user_id={$user_id}");
    $check((int)synk_professor_evaluation_current_scope($conn)['college_id']===(int)$otherCollege['college_id'],'An explicitly assigned account college takes precedence over the linked profile.');
    $conn->query("UPDATE tbl_useraccount SET college_id=NULL WHERE user_id={$user_id}");
    synk_evaluation_query($conn,'INSERT INTO tbl_college_evaluation_settings (college_id,ay_id,semester,faculty_released) VALUES (?,?,?,0) ON DUPLICATE KEY UPDATE faculty_released=0','iii',array_values($scope));
    $check(synk_professor_evaluation_options($conn,$faculty_id)===[],'Hidden periods have no options.');
    $check(synk_professor_evaluation_subjects($conn,$scope)===[],'A direct request cannot list hidden-period subjects.');
    synk_evaluation_query($conn,'INSERT INTO tbl_supervisor_evaluation_assignments (college_id,ay_id,semester,faculty_id,reviewer_user_id,reviewer_role,updated_by) VALUES (?,?,?,?,?,\'program_chair\',?)','iiiiii',[$college,$term['ay_id'],$term['semester'],$faculty_id,$user_id,$user_id]);
    $assignment=(int)$conn->insert_id;
    $answers=[];
    foreach (synk_sef_questions() as $key=>$question) $answers[$key]=$question+['rating'=>5];
    synk_evaluation_query($conn,'INSERT INTO tbl_supervisor_evaluations (assignment_id,assignment_revision,college_id,ay_id,semester,faculty_id,evaluator_user_id,evaluator_faculty_id,evaluator_role,evaluator_name,faculty_name,context_snapshot,instrument_version,answers_snapshot,comment_text,total_score,question_count,submission_status) VALUES (?,1,?,?,?,?,?,?,\'program_chair\',\'QA Reviewer\',\'QA Faculty\',\'{}\',?,?,?,75,15,\'draft\')','iiiiiiisss',
        [$assignment,$college,$term['ay_id'],$term['semester'],$faculty_id,$user_id,$faculty_id+1,synk_sef_version(),json_encode($answers),'SUPERVISOR <img src=x onerror=bad()> feedback']);
    $record=(int)$conn->insert_id;
    synk_evaluation_query($conn,'UPDATE tbl_college_evaluation_settings SET faculty_released=1 WHERE college_id=? AND ay_id=? AND semester=?','iii',array_values($scope));
    $check(synk_professor_supervisor_results($conn,$scope)===[],'Supervisor drafts never appear, even in released periods.');
    $conn->query("UPDATE tbl_supervisor_evaluations SET submission_status='submitted' WHERE evaluation_id={$record}");
    $check(count(synk_professor_evaluation_options($conn,$faculty_id))===1,'A supervisor-only period is available without student results.');
    $supervisor=synk_professor_supervisor_results($conn,$scope+['faculty_id'=>$faculty_id+1]);
    $check(count($supervisor)===1 && $supervisor[0]['score']['mean']===5.0 && $supervisor[0]['score']['percentage']===100.0,'Only the signed-in faculty projection is used.');
    $check(count($supervisor[0]['categories'])===3 && count($supervisor[0]['categories']['teaching_learning']['questions'])===6,'Supervisor results retain original categories and statements.');
    $check(synk_professor_supervisor_results($conn,['college_id'=>$college+100000]+$term)===[],'Another college cannot be queried.');
    $check(synk_professor_supervisor_results($conn,['college_id'=>$college,'ay_id'=>$term['ay_id']+100000,'semester'=>$term['semester']])===[],'Another term cannot be queried.');
    $conn->query("UPDATE tbl_supervisor_evaluation_assignments SET revision=2 WHERE assignment_id={$assignment}");
    $check(synk_professor_supervisor_results($conn,$scope)===[],'Superseded supervisor revisions are not exposed.');
    $conn->query("UPDATE tbl_supervisor_evaluation_assignments SET revision=1 WHERE assignment_id={$assignment}");
    $subjects=synk_professor_evaluation_subjects($conn,$scope);
    $expected=count(array_filter(array_slice($offerings,0,2),static function($row)use($college){return (int)$row['college_id']===$college;}));
    $check(count($subjects)===$expected,'All assigned classes in the released college appear once, using workload summaries.');
    $version=synk_student_faculty_evaluation_current_version(); $token=bin2hex(random_bytes(16));
    synk_evaluation_query($conn,'INSERT INTO tbl_student_faculty_evaluations (student_id,faculty_id,college_id,ay_id,semester,faculty_name,instrument_version,question_count,total_score,average_rating,evaluation_token,submission_status,final_submission_token,comment_text) VALUES (?,?,?,?,?,\'QA Faculty\',?,15,60,4,?,\'submitted\',?,?)','iiiiissss',
        [$students['active'],$faculty_id,$college,$term['ay_id'],$term['semester'],$version,$token,$token,'STUDENT <script>bad()</script> feedback']);
    $studentEvaluation=(int)$conn->insert_id;
    $label=trim($offerings[0]['sub_code'].' - '.$offerings[0]['sub_description']);
    synk_evaluation_query($conn,'UPDATE tbl_student_faculty_evaluations SET subject_summary=? WHERE evaluation_id=?','si',[$label,$studentEvaluation]);
    synk_evaluation_capture_subject_scope($conn,$studentEvaluation,$students['active'],$faculty_id,$college,$term['ay_id'],$term['semester'],$label);
    foreach (synk_student_faculty_evaluation_flat_questions($version) as $question) synk_evaluation_query($conn,'INSERT INTO tbl_student_faculty_evaluation_answers (evaluation_id,category_key,category_title,question_key,question_order,question_text,rating) VALUES (?,?,?,?,?,?,4)','isssis',
        [$studentEvaluation,$question['category_key'],$question['category_title'],$question['question_key'],$question['question_order'],$question['question_text']]);
    $student=synk_professor_evaluation_results($conn,$scope);
    $check(count($student)===1 && $student[0]['score']['percentage']===80.0,'Student results remain separate from supervisor scores.');
    $counts=synk_professor_evaluation_completion($conn,$scope,$subjects);
    $check($counts[$offerings[0]['offering_id']]===['total'=>3,'evaluated'=>1,'not_evaluated'=>2],'Counts use the three actual current students, not the recorded total of 260. Withdrawn and other-term rows are excluded.');
    if ($expected===2) $check($counts[$offerings[1]['offering_id']]===['total'=>2,'evaluated'=>1,'not_evaluated'=>1],'A shared student counts once in each class they attend.');
    $first=(int)$offerings[0]['offering_id'];
    $pending=synk_professor_evaluation_class_detail($conn,$first,'pending',$scope);
    $check(count($pending['students'])===2 && array_column($pending['students'],'full_name')===['Ana Test Student Jr.','Failed Test Student Jr.'],'The pending list contains only unmatched current roster students in name order.');
    $result=synk_professor_evaluation_class_detail($conn,$first,'results',$scope);
    $check(count($result['groups'])===1 && $result['groups'][0]['score']['percentage']===80.0 && $result['combined_count']===0,'A subject panel contains only its associated submitted response.');
    $check(strpos(json_encode($result),'student_number')===false && strpos(json_encode($result),'full_name')===false,'Subject results expose no individual evaluator identities.');
    $consolidated=synk_professor_evaluation_consolidated_results($conn,$scope);
    $check(count($consolidated['groups'])===1 && $consolidated['groups'][0]['response_count']===1 && $consolidated['groups'][0]['total_points']===60.0 && $consolidated['groups'][0]['answer_count']===15,'A response shared by multiple class cards contributes its raw points and answers only once.');
    if ($expected===2) $check($consolidated['counts']===['total'=>4,'evaluated'=>1,'not_evaluated'=>3],'Consolidated completion totals count shared roster students once.');
    $conn->query('SAVEPOINT consolidated_eligibility_qa');
    synk_evaluation_query($conn,'INSERT INTO tbl_college_evaluation_subject_exclusions (college_id,ay_id,semester,subject_id,excluded,reason,updated_by) VALUES (?,?,?,?,1,\'Consolidated score QA\',?) ON DUPLICATE KEY UPDATE excluded=1','iiiii',[$college,$term['ay_id'],$term['semester'],$offerings[0]['sub_id'],$user_id]);
    $excluded=synk_professor_evaluation_consolidated_results($conn,$scope);
    $check($excluded['groups'][0]['response_count']===0 && $excluded['groups'][0]['score']===null && $excluded['counts']['evaluated']===1 && count($excluded['groups'][0]['comments'])===1,'Score exclusions do not change completion or comments, and all-excluded responses have no artificial zero score.');
    $conn->query('ROLLBACK TO SAVEPOINT consolidated_eligibility_qa');
    $conn->query('RELEASE SAVEPOINT consolidated_eligibility_qa');
    $conn->query('SAVEPOINT consolidated_versions_qa');
    $legacyToken=bin2hex(random_bytes(16));
    synk_evaluation_query($conn,'INSERT INTO tbl_student_faculty_evaluations (student_id,faculty_id,college_id,ay_id,semester,faculty_name,instrument_version,question_count,total_score,average_rating,evaluation_token,submission_status,final_submission_token,comment_text) VALUES (?,?,?,?,?,\'QA Faculty\',\'legacy-20\',20,60,3,?,\'submitted\',?,\'LEGACY_CONSOLIDATED_QA\')','iiiiiss',[$students['inactive'],$faculty_id,$college,$term['ay_id'],$term['semester'],$legacyToken,$legacyToken]);
    $legacyId=(int)$conn->insert_id;
    foreach (synk_student_faculty_evaluation_flat_questions('legacy-20') as $question) synk_evaluation_query($conn,'INSERT INTO tbl_student_faculty_evaluation_answers (evaluation_id,category_key,category_title,question_key,question_order,question_text,rating) VALUES (?,?,?,?,?,?,3)','isssis',[$legacyId,$question['category_key'],$question['category_title'],$question['question_key'],$question['question_order'],$question['question_text']]);
    synk_evaluation_capture_subject_scope($conn,$legacyId,$students['inactive'],$faculty_id,$college,$term['ay_id'],$term['semester'],$label);
    $versions=array_column(synk_professor_evaluation_consolidated_results($conn,$scope)['groups'],null,'instrument_version');
    $check(count($versions)===2 && $versions[$version]['score']['percentage']===80.0 && $versions['legacy-20']['score']['percentage']===60.0,'Consolidated scores never blend different questionnaire versions.');
    $conn->query('ROLLBACK TO SAVEPOINT consolidated_versions_qa');
    $conn->query('RELEASE SAVEPOINT consolidated_versions_qa');
    if ($expected===2) {
        $otherToken=bin2hex(random_bytes(16));
        synk_evaluation_query($conn,'INSERT INTO tbl_student_faculty_evaluations (student_id,faculty_id,college_id,ay_id,semester,faculty_name,instrument_version,question_count,total_score,average_rating,evaluation_token,submission_status,final_submission_token,comment_text) VALUES (?,?,?,?,?,\'QA Faculty\',?,15,15,1,?,\'submitted\',?,\'OTHER_SECTION_ONLY\')','iiiiisss',[$students['second'],$faculty_id,$college,$term['ay_id'],$term['semester'],$version,$otherToken,$otherToken]);
        $otherEvaluation=(int)$conn->insert_id;
        foreach (synk_student_faculty_evaluation_flat_questions($version) as $question) synk_evaluation_query($conn,'INSERT INTO tbl_student_faculty_evaluation_answers (evaluation_id,category_key,category_title,question_key,question_order,question_text,rating) VALUES (?,?,?,?,?,?,1)','isssis',[$otherEvaluation,$question['category_key'],$question['category_title'],$question['question_key'],$question['question_order'],$question['question_text']]);
        synk_evaluation_capture_subject_scope($conn,$otherEvaluation,$students['second'],$faculty_id,$college,$term['ay_id'],$term['semester'],trim($offerings[1]['sub_code'].' - '.$offerings[1]['sub_description']));
        $firstReport=synk_professor_evaluation_class_detail($conn,$first,'results',$scope);
        $secondReport=synk_professor_evaluation_class_detail($conn,(int)$offerings[1]['offering_id'],'results',$scope);
        $check($firstReport['groups'][0]['score']['percentage']===80.0 && strpos(json_encode($firstReport),'OTHER_SECTION_ONLY')===false && strpos(json_encode($secondReport),'OTHER_SECTION_ONLY')!==false,'A different class roster cannot change the first class score or comments, even when both classes teach the same subject.');
        $consolidated=synk_professor_evaluation_consolidated_results($conn,$scope);
        $group=$consolidated['groups'][0];
        $check($group['response_count']===2 && $group['total_points']===75.0 && $group['answer_count']===30 && $group['score']['mean']===2.5 && $group['score']['percentage']===50.0,'Consolidation uses 75 raw points / 30 answers, not an average of overlapping subject-card means.');
        $check(count($group['comments'])===2 && $group['categories']['teaching_learning']['score']['mean']===2.5,'Consolidated category means and comments use the same distinct response set.');
        $conn->query("DELETE FROM tbl_student_faculty_evaluation_answers WHERE evaluation_id={$otherEvaluation}");
        $conn->query("DELETE FROM tbl_student_faculty_evaluation_subject_scope WHERE evaluation_id={$otherEvaluation}");
        $conn->query("DELETE FROM tbl_student_faculty_evaluations WHERE evaluation_id={$otherEvaluation}");
    }
    $snapshotSubjects=[['subject_id'=>(int)$offerings[0]['sub_id'],'label'=>$label],['subject_id'=>999999999,'label'=>'Other subject']];
    synk_evaluation_query($conn,'UPDATE tbl_student_faculty_evaluation_subject_scope SET subjects_json=? WHERE evaluation_id=?','si',[json_encode($snapshotSubjects),$studentEvaluation]);
    $check(synk_professor_evaluation_class_detail($conn,$first,'results',$scope)['combined_count']===1,'Combined-subject responses are explicitly counted for disclosure.');
    $consolidated=synk_professor_evaluation_consolidated_results($conn,$scope);
    $check($consolidated['combined_count']===1 && $consolidated['submitted_count']===1 && $consolidated['groups'][0]['response_count']===1,'A combined-subject response is disclosed and counted only once in the consolidated report.');
    $snapshotSubjects[0]['subject_id']=999999998;
    synk_evaluation_query($conn,'UPDATE tbl_student_faculty_evaluation_subject_scope SET subjects_json=? WHERE evaluation_id=?','si',[json_encode($snapshotSubjects),$studentEvaluation]);
    $check(synk_professor_evaluation_class_detail($conn,$first,'results',$scope)['groups']===[],'Another subject snapshot cannot enter the selected subject results.');
    $check(synk_professor_evaluation_consolidated_results($conn,$scope)['groups']===[],'Responses linked only to unassigned subjects cannot enter consolidation.');
    synk_evaluation_query($conn,'UPDATE tbl_student_faculty_evaluation_subject_scope SET subjects_json=? WHERE evaluation_id=?','si',['[]',$studentEvaluation]);
    $check(synk_professor_evaluation_class_detail($conn,$first,'results',$scope)['groups']===[],'Missing subject associations are not inferred from current enrollments.');
    synk_evaluation_capture_subject_scope($conn,$studentEvaluation,$students['active'],$faculty_id,$college,$term['ay_id'],$term['semester'],$label);
    try { synk_professor_evaluation_class_detail($conn,(int)$offerings[2]['offering_id'],'pending',$scope); $check(false,'A foreign class was exposed.'); } catch (DomainException $error) { $check($error->getCode()===404,'Foreign classes cannot be read or exported.'); }
    try { synk_professor_evaluation_class_detail($conn,$first,'results',['ay_id'=>(int)$term['ay_id']+1]+$scope); $check(false,'A stale term was accepted.'); } catch (DomainException $error) { $check($error->getCode()===409,'Stale term requests are rejected.'); }
    try { synk_professor_evaluation_consolidated_results($conn,['ay_id'=>(int)$term['ay_id']+1]+$scope); $check(false,'A stale consolidated term was accepted.'); } catch (DomainException $error) { $check($error->getCode()===409,'Consolidated reports reject stale term context.'); }
    $unsafe=$pending; $unsafe['students'][0]=['student_number'=>'=2+2','full_name'=>'@formula'];
    $csv=synk_professor_evaluation_pending_csv($unsafe);
    $check(strpos($csv,"'=2+2")!==false && strpos($csv,"'@formula")!==false && substr_count(trim($csv),"\n")===2,'CSV exports contain only pending rows and neutralize spreadsheet formulas.');
    $conn->query("UPDATE tbl_student_faculty_evaluations SET submission_status='draft' WHERE evaluation_id={$studentEvaluation}");
    $draftCounts=synk_professor_evaluation_completion($conn,$scope,$subjects);
    $check($draftCounts[$offerings[0]['offering_id']]['evaluated']===0 && $draftCounts[$offerings[0]['offering_id']]['not_evaluated']===3,'Saved drafts are not completed evaluations.');
    $conn->query("UPDATE tbl_student_faculty_evaluations SET submission_status='submitted',college_id=".(int)$otherCollege['college_id']." WHERE evaluation_id={$studentEvaluation}");
    $check(synk_professor_evaluation_completion($conn,$scope,$subjects)[$offerings[0]['offering_id']]['evaluated']===0,'Submissions for another college do not inflate completion counts.');
    $conn->query("UPDATE tbl_student_faculty_evaluations SET college_id={$college},ay_id=".((int)$term['ay_id']+100000)." WHERE evaluation_id={$studentEvaluation}");
    $check(synk_professor_evaluation_completion($conn,$scope,$subjects)[$offerings[0]['offering_id']]['evaluated']===0,'Submissions from another academic year do not inflate completion counts.');
    $conn->query("UPDATE tbl_student_faculty_evaluations SET ay_id=".(int)$term['ay_id']." WHERE evaluation_id={$studentEvaluation}");
    synk_evaluation_query($conn,'UPDATE tbl_college_evaluation_settings SET faculty_released=0 WHERE college_id=? AND ay_id=? AND semester=?','iii',array_values($scope));
    $check(synk_professor_evaluation_options($conn,$faculty_id)===[] && synk_professor_evaluation_subjects($conn,$scope)===[] && synk_professor_evaluation_results($conn,$scope)===[] && synk_professor_supervisor_results($conn,$scope)===[],'Hiding results suppresses both report types and subject cards.');
    $check(synk_professor_evaluation_completion($conn,$scope,$subjects)===[],'Hidden releases suppress completion counts too.');
    try { synk_professor_evaluation_class_detail($conn,$first,'pending',$scope); $check(false,'A hidden release was exported.'); } catch (DomainException $error) { $check($error->getCode()===403,'Hiding results blocks panel reads and exports.'); }
    try { synk_professor_evaluation_consolidated_results($conn,$scope); $check(false,'Hidden consolidated scores were exposed.'); } catch (DomainException $error) { $check($error->getCode()===403,'Hiding results also blocks consolidated scores.'); }
    synk_evaluation_query($conn,'UPDATE tbl_college_evaluation_settings SET faculty_released=1 WHERE college_id=? AND ay_id=? AND semester=?','iii',array_values($scope));
    $conn->query("UPDATE tbl_useraccount_roles SET status='inactive' WHERE user_id={$user_id}");
    try { synk_professor_supervisor_results($conn,$scope); $check(false,'Revoked Professor access can read results.'); }
    catch (DomainException $error) { $check(true,'Revoked Professor access is revalidated.'); }
    $conn->query("UPDATE tbl_useraccount_roles SET status='active' WHERE user_id={$user_id}");
    // Rendering is done before rollback so no real data is used in visual QA.
    if (($argv[1] ?? '')==='--render') {
        session_save_path(dirname(__DIR__).'/.tmp'); session_id('evaluationqa'.bin2hex(random_bytes(8))); session_start();
        $_SESSION=['user_id'=>$user_id,'role'=>'professor','username'=>'QA Professor','email'=>'qa@example.test']; session_write_close();
        $_GET=['college_id'=>(string)$otherCollege['college_id'],'ay_id'=>(string)((int)$term['ay_id']+100000),'semester'=>'3','period'=>'obsolete-selector','type'=>$argv[2] ?? 'student'];
        $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['PHP_SELF']='/synk/professor/faculty-evaluations.php';
        chdir(dirname(__DIR__).'/professor'); ob_start(); require 'faculty-evaluations.php'; $html=ob_get_clean();
        $check(strpos($html,'Warning:')===false && strpos($html,'Fatal error')===false,'The results page renders without PHP errors.');
        $check((int)$evaluationSelected['college_id']===$college && (int)$evaluationSelected['ay_id']===(int)$term['ay_id'] && (int)$evaluationSelected['semester']===(int)$term['semester'],'Old query parameters cannot override the account or administrator term.');
        $check(strpos($html,'evaluation-period-form')===false && strpos($html,'id="evaluation-period"')===false && strpos($html,'evaluation-release-status')===false,'The redundant selection controls and release banner are removed.');
        $check(substr_count($html,' data-offering-id=')===($evaluationType==='student'?$expected:0),'Only the Student tab renders subject cards.');
        $check($evaluationType==='supervisor' ? strpos($html,'evaluation-supervisor-placeholder')!==false : substr_count($html,'class="evaluation-student-counts"')===$expected && substr_count($html,'class="evaluation-card-panel"')===$expected,'Student cards have their own expandable panel; Supervisor remains a placeholder.');
        $check($evaluationType==='supervisor' ? strpos($html,'id="evaluation-consolidated"')===false : strpos($html,'id="evaluation-consolidated"')>strpos($html,'class="evaluation-subject-list"') && strpos($html,'STUDENT &lt;script&gt;bad()&lt;/script&gt; feedback')!==false,'The Student tab renders consolidated results below the subject list, with escaped comments; Supervisor has no consolidated report.');
        $check(strpos($html,$evaluationType==='supervisor' ? 'STUDENT &lt;script&gt;' : 'SUPERVISOR &lt;img')===false,'The unselected evaluation type is not sent to the browser.');
        $html=str_replace('<head>','<head><base href="/synk/professor/">',$html);
        if ($evaluationType==='student') {
            $preview=[];
            foreach ($subjects as $class) foreach (['pending','results','export'] as $previewView) {
                $evaluationDetail=synk_professor_evaluation_class_detail($conn,(int)$class['offering_id'],$previewView==='export'?'pending':$previewView,$scope);
                if ($previewView==='export') $preview[$class['offering_id']][$previewView]=['csv'=>synk_professor_evaluation_pending_csv($evaluationDetail),'filename'=>synk_professor_evaluation_pending_filename($evaluationDetail)];
                else {
                    $evaluationView=$previewView; ob_start(); require dirname(__DIR__).'/professor/faculty-evaluation-panel.php'; $panel=ob_get_clean();
                    $preview[$class['offering_id']][$previewView]=['ok'=>true,'view'=>$previewView,'offering_id'=>(int)$class['offering_id'],'counts'=>$evaluationDetail['counts'],'html'=>$panel];
                }
            }
            $previewToken=bin2hex(random_bytes(16));
            $previewRoute=dirname(__DIR__).'/tmp/professor-evaluation-preview-details.php';
            $route='<?php if (!in_array($_SERVER["REMOTE_ADDR"]??"",["127.0.0.1","::1"],true) || ($_GET["qa"]??"")!=='.var_export($previewToken,true).') {http_response_code(404);exit;} $data='.var_export($preview,true).'; $view=$_GET["view"]??""; $item=$data[$_GET["offering_id"]??""][$view]??null; if($item===null){http_response_code(404);exit;} header("Cache-Control: no-store"); if($view==="export"){header("Content-Type: text/csv");header("Content-Disposition: attachment; filename=\"".$item["filename"]."\"; filename*=UTF-8".chr(39).chr(39).rawurlencode($item["filename"]));echo $item["csv"];}else{header("Content-Type: application/json");echo json_encode($item);}';
            file_put_contents($previewRoute,$route);
            $html=str_replace('data-details-url="faculty-evaluation-details.php"','data-details-url="/synk/tmp/professor-evaluation-preview-details.php?qa='.$previewToken.'"',$html);
        }
        file_put_contents($argv[3],$html); session_destroy();
    }
} finally {
    $conn->rollback();
}
$check($snapshot()===$before,'All fixture records and academic settings remain unchanged after rollback.');
echo 'Professor evaluations: ',$checks,' checks passed. Fixtures rolled back.',PHP_EOL;
