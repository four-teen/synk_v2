<?php
// The caller must hold and roll back both core and LMS transactions.
if(PHP_SAPI!=='cli' && (!defined('SYNK_STUDENT_GRADEBOOK_QA') || !in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true) || !hash_equals(SYNK_STUDENT_GRADEBOOK_QA,(string)($_GET['qa']??'')))){http_response_code(404);exit;}
require_once __DIR__.'/professor-workload-students-data.php';
require_once __DIR__.'/../../backend/gradebook_api_helper_lms.php';
function student_gradebook_fixtures_lms(mysqli $core,mysqli $lms): array
{
    $f=professor_students_fixtures($core,true);$student=$f['students']['active'];$actor=$f['user_id'];
    $classes=synk_professor_fetch_subject_rows_by_academic_year($core,$f['faculty_id'],$f['term']['ay_id'],$f['term']['semester']);
    $class=null;foreach($classes as $candidate)if($candidate['offering_id']==$f['offerings'][0]['offering_id'])$class=$candidate;
    if(!$class)throw new RuntimeException('Missing fixture class.');
    $context=['portal'=>['faculty_id'=>$f['faculty_id']],'class'=>$class];
    $schemes=[];
    foreach(['midterm','final'] as $period)$schemes[$period]=synk_grading_save_lms($lms,$context,['grading_period'=>$period,'version'=>0,'components'=>$period==='midterm'?[['name'=>'Exams','weight'=>60],['name'=>'Classwork','weight'=>40]]:[['name'=>'Final requirement','weight'=>100]]],$actor);
    $revision=static function(string $period)use($lms,$context):array{$book=synk_gradebook_period_lms($lms,$context,$period);return ['grading_period'=>$period,'version'=>$book['version'],'scheme_version'=>$book['scheme']['version']];};
    $items=[];
    foreach([
        ['key'=>'exam','period'=>'midterm','title'=>'Written examination','max'=>100,'component'=>0,'points'=>80],
        ['key'=>'activity','period'=>'midterm','title'=>'Laboratory exercise','max'=>20,'component'=>1,'points'=>15],
        ['key'=>'missing','period'=>'midterm','title'=>'Class activity','max'=>20,'component'=>1,'points'=>null],
        ['key'=>'final','period'=>'final','title'=>'Final examination','max'=>100,'component'=>0,'points'=>90]
    ] as $entry){
        $period=$entry['period'];
        $saved=synk_gradebook_mutate_lms($core,$lms,$context,'save_item',$revision($period)+['entry_kind'=>'score','title'=>$entry['title'],'max_points'=>$entry['max'],'component_id'=>$schemes[$period]['components'][$entry['component']]['component_id'],'recorded_date'=>'2026-11-14'],$actor);
        $items[$entry['key']]=$saved['saved_item_id'];
        if($entry['points']!==null)synk_gradebook_mutate_lms($core,$lms,$context,'save_scores',$revision($period)+['item_id'=>$saved['saved_item_id'],'scores'=>[['student_id'=>$student,'points'=>$entry['points']]]],$actor);
    }
    // A deliberately different classmate score and staff-only note must never leak.
    synk_gradebook_mutate_lms($core,$lms,$context,'save_scores',$revision('midterm')+['item_id'=>$items['exam'],'scores'=>[['student_id'=>$f['students']['failed'],'points'=>0,'remark'=>'PRIVATE CLASSMATE NOTE']]],$actor);
    synk_gradebook_mutate_lms($core,$lms,$context,'save_scores',$revision('final')+['item_id'=>$items['final'],'scores'=>[['student_id'=>$f['students']['failed'],'points'=>100]]],$actor);
    $assessment=synk_assessment_publish_lms($lms,$context,['grading_period'=>'midterm','assessment_type'=>'quiz','title'=>'LMS concept check','questions'=>[
        ['question_type'=>'true_false','prompt'=>'PRIVATE QUESTION CONTENT','points'=>2,'answer_key'=>true],
        ['question_type'=>'essay','prompt'=>'PRIVATE WRITTEN PROMPT','points'=>8]
    ]],$actor);
    $now=synk_assessment_now_lms();
    synk_grading_query_lms($lms,"INSERT INTO tbl_lms_assessment_attempts (assessment_id,student_id,status,answers_json,assessment_snapshot_json,started_at,submitted_at,updated_at) VALUES (?,?,'submitted',?,?,?,?,?)",'iisssss',[$assessment['assessment_id'],$student,json_encode([$assessment['questions'][0]['question_id']=>true,$assessment['questions'][1]['question_id']=>'PRIVATE STUDENT ANSWER']),synk_assessment_snapshot_json_lms($assessment),$now,$now,$now]);
    $saved=synk_gradebook_mutate_lms($core,$lms,$context,'save_item',$revision('midterm')+['entry_kind'=>'lms','assessment_id'=>$assessment['assessment_id'],'title'=>'LMS concept check','max_points'=>10,'component_id'=>$schemes['midterm']['components'][1]['component_id']],$actor);$items['lms']=$saved['saved_item_id'];
    return $f+['context'=>$context,'schemes'=>$schemes,'items'=>$items,'assessment'=>$assessment];
}

function student_gradebook_fixture_login_lms(mysqli $core,int $studentId): void
{
    $record=synk_student_fetch_directory_record_by_student_id($core,$studentId);
    synk_complete_student_login($record['email_address'],$record['first_name'],'student-gradebook-qa');$_SESSION['student_id']=$studentId;
}
