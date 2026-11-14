<?php
require_once __DIR__.'/student_subject_helper_lms.php';
require_once __DIR__.'/gradebook_helper_lms.php';

function synk_student_gradebook_identity_lms(mysqli $core): int
{
    if (($_SESSION['role']??'')!=='student' || empty($_SESSION['user_id']) || !synk_student_login_session_record($core)) {
        throw new DomainException('Sign in with your student account to view your gradebook.',401);
    }
    return (int)$_SESSION['student_id'];
}

// Resolve only current enrollments against the same live class/merge matcher as delivery.
// Keep independently graded offerings separate; never average unrelated gradebooks.
function synk_student_gradebook_classes_lms(mysqli $core,int $studentId): array
{
    $term=synk_fetch_current_academic_term($core);$rows=[];$faculty=[];
    foreach(synk_student_subjects_lms($core,$studentId) as $subject){
        $facultyId=(int)$subject['faculty_id'];
        if(!isset($faculty[$facultyId])){
            $active=$facultyId>0?synk_grading_query_lms($core,"SELECT faculty_id FROM tbl_faculty WHERE faculty_id=? AND status='active'",'i',[$facultyId]):[];
            $classes=$active?synk_professor_fetch_subject_rows_by_academic_year($core,$facultyId,(int)$term['ay_id'],(int)$term['semester']):[];
            $faculty[$facultyId]=['classes'=>$classes,'members'=>synk_professor_workload_student_memberships($core,$classes,(int)$term['ay_id'],(int)$term['semester'])];
        }
        $matched=false;
        foreach($faculty[$facultyId]['classes'] as $class){
            $offeringId=(int)$class['offering_id'];
            if(!isset($faculty[$facultyId]['members'][$offeringId][$studentId]) || synk_student_subject_key_lms($facultyId,$class['subject_code'],$class['descriptive_title'])!==$subject['subject_key'])continue;
            $key=$facultyId.':'.$offeringId;
            $rows[$key]=['subject'=>$subject,'context'=>['portal'=>['faculty_id'=>$facultyId],'class'=>$class]];$matched=true;
        }
        if(!$matched)$rows['subject:'.$subject['subject_key']]=['subject'=>$subject,'context'=>null];
    }
    return ['term'=>$term,'classes'=>array_values($rows)];
}

// A student read never loads another student's score rows or assessment attempts.
function synk_student_gradebook_scores_lms(mysqli $lms,array $context,array $items,int $studentId): array
{
    if(!$items)return [];
    $ids=array_column($items,'item_id');$map=[];
    $recorded=synk_grading_query_lms($lms,'SELECT item_id,points FROM tbl_lms_gradebook_scores WHERE item_id IN ('.implode(',',array_fill(0,count($ids),'?')).') AND student_id=?',str_repeat('i',count($ids)+1),array_merge($ids,[$studentId]));
    foreach($recorded as $row)$map[(int)$row['item_id']][$studentId]=['points'=>(float)$row['points'],'source'=>'manual'];
    if(synk_assessment_delivery_ready_lms($lms))foreach($items as $item){
        if(!$item['assessment_id'])continue;
        $attempts=synk_grading_query_lms($lms,'SELECT status,answers_json,assessment_snapshot_json,deadline_at FROM tbl_lms_assessment_attempts WHERE assessment_id=? AND student_id=?','ii',[$item['assessment_id'],$studentId]);
        if(!$attempts)continue;$attempt=$attempts[0];
        if($attempt['status']==='in_progress' && $attempt['deadline_at']!==null && $attempt['deadline_at']<=synk_assessment_now_lms())$attempt['status']='expired';
        if(!in_array($attempt['status'],['submitted','expired'],true))continue;
        if(isset($map[$item['item_id']][$studentId]))continue;
        $record=synk_assessment_detail_lms($lms,$context,$item['assessment_id']);
        $automatic=synk_gradebook_auto_points_lms($record,$attempt,$item['max_points']);
        $map[$item['item_id']][$studentId]=['points'=>$automatic['points'],'source'=>'lms','reason'=>$automatic['reason']];
    }
    return $map;
}

function synk_student_gradebook_period_lms(mysqli $lms,array $context,string $period,int $studentId): array
{
    $book=synk_gradebook_period_lms($lms,$context,$period);
    $items=synk_gradebook_items_lms($lms,$book['book_id']);
    $scores=synk_student_gradebook_scores_lms($lms,$context,$items,$studentId);
    $result=synk_gradebook_calculate_lms($book['scheme'],$items,$scores,$studentId);
    $components=array_column($book['scheme']['components'],null,'component_id');$entries=[];
    foreach($items as $item){
        // Show exactly the active entries included by the frozen grading setup.
        if(!isset($components[$item['component_id']]))continue;
        $score=$scores[$item['item_id']][$studentId]??null;
        $entries[]=['title'=>$item['title'],'component'=>$components[$item['component_id']]['name'],'kind'=>$item['entry_kind'],
            'date'=>$item['recorded_date'],'maximum'=>$item['max_points'],'points'=>$score===null?null:$score['points'],'source'=>$score===null?'unrecorded':$score['source'],
            'explanation'=>$score===null?'Unrecorded; counts as zero':($score['source']==='lms'?$score['reason']:'Recorded by your instructor')];
    }
    // Explicit student-safe view: no answer keys, responses, other students or staff notes.
    return ['visible'=>true,'result'=>$result,'entries'=>$entries,'updated_at'=>$book['updated_at']];
}

function synk_student_gradebook_workspace_lms(mysqli $core,mysqli $lms): array
{
    $studentId=synk_student_gradebook_identity_lms($core);
    if(!synk_gradebook_ready_lms($lms))throw new DomainException('Your gradebook is temporarily unavailable. Please try again later.',503);
    $resolved=synk_student_gradebook_classes_lms($core,$studentId);$rows=[];
    foreach($resolved['classes'] as $resolvedClass){
        $subject=$resolvedClass['subject'];$context=$resolvedClass['context'];$periods=[];$calculated=[];
        $visibility=['midterm'=>false,'final'=>false,'overall'=>false];
        $summary=['midterm'=>null,'final'=>null,'average'=>null,'rating'=>null,'remark'=>'—'];
        if($context!==null){
            $visibility=array_intersect_key(synk_gradebook_visibility_lms($lms,$context),$visibility);
            foreach(['midterm','final'] as $period){
                if($visibility[$period]||$visibility['overall'])$calculated[$period]=synk_student_gradebook_period_lms($lms,$context,$period,$studentId);
                // Hidden period grades, components, entries and timestamps never reach the view.
                $periods[$period]=$visibility[$period]?$calculated[$period]:['visible'=>false,'result'=>null,'entries'=>[],'updated_at'=>null];
                if($visibility[$period])$summary[$period]=$calculated[$period]['result']['percent'];
            }
            if($visibility['overall']){
                $semester=synk_gradebook_semester_result_lms($calculated['midterm']['result'],$calculated['final']['result']);
                foreach(['average','rating','remark'] as $field)$summary[$field]=$semester[$field];
            }
        }
        $rows[]=['subject_code'=>$subject['subject_code'],'title'=>$subject['descriptive_title'],'instructor'=>$subject['faculty_name'],
            'section'=>$context===null?$subject['section_display']:$context['class']['section_display'],'schedule'=>$subject['schedule_text'],'room'=>$subject['room_name'],
            'available'=>$context!==null,'visibility'=>$visibility,'summary'=>$summary,'periods'=>$periods];
    }
    return ['term'=>$resolved['term'],'subjects'=>$rows];
}

function synk_student_gradebook_number_lms($value,bool $percent=false): string
{
    return $value===null?'—':number_format((float)$value,2,'.','').($percent?'%':'');
}

function synk_student_gradebook_display_lms($value,bool $visible,bool $percent=false): string
{
    return $visible?synk_student_gradebook_number_lms($value,$percent):'Not released';
}
