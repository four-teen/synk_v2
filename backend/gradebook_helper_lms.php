<?php
require_once __DIR__.'/grading_helper_lms.php';
require_once __DIR__.'/assessment_delivery_helper_lms.php';
require_once __DIR__.'/assessment_result_helper_lms.php';

function synk_gradebook_ready_lms(mysqli $lms): bool
{
    if (!synk_grading_ready_lms($lms)) return false;
    foreach (['periods','items','scores','logs'] as $suffix) {
        if (!synk_grading_query_lms($lms,'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?','s',['tbl_lms_gradebook_'.$suffix])) return false;
    }
    return true;
}
function synk_gradebook_points_lms($value, bool $zero = true): int
{
    if ((!is_string($value) && !is_int($value) && !is_float($value)) || !preg_match('/^(?:0|[1-9][0-9]{0,4})(?:\.[0-9]{1,2})?$/D',(string)$value)) throw new DomainException('Use points from 0 to 10,000 with at most two decimal places.',422);
    $parts=explode('.',(string)$value);$cents=(int)$parts[0]*100+(int)str_pad($parts[1]??'',2,'0');
    if ($cents>1000000 || (!$zero && !$cents)) throw new DomainException('Maximum points must be greater than 0 and at most 10,000.',422);
    return $cents;
}
function synk_gradebook_rating_lms(float $percent): array
{
    if ($percent<0 || $percent>100.000000001) throw new RuntimeException('A calculated grade is outside the percentage range.');
    // Minimum percentages follow the supplied legend. Decimal percentages stay
    // in the lower band until they reach the next threshold; never round up.
    foreach ([[99,1.0],[96,1.25],[93,1.5],[90,1.75],[87,2.0],[84,2.25],[81,2.5],[78,2.75],[75,3.0],[73,4.0],[0,5.0]] as $band) {
        if ($percent+0.000000001 >= $band[0]) return ['rating'=>$band[1],'remark'=>$band[1]<=3?'Passed':($band[1]<=4?'Conditional':'Failed')];
    }
    throw new RuntimeException('Unable to rate the calculated grade.');
}
function synk_gradebook_period_lms(mysqli $lms, array $context, string $period, bool $lock = false): array
{
    synk_grading_period_lms($period);
    // Lock the grading scheme first in every mutation, including concurrent initialization.
    $latest=synk_grading_detail_lms($lms,$context,$period,$lock);
    $rows=synk_grading_query_lms($lms,'SELECT * FROM tbl_lms_gradebook_periods WHERE faculty_id=? AND offering_id=? AND ay_id=? AND semester=? AND grading_period=?'.($lock?' FOR UPDATE':''),'iiiis',array_merge(synk_grading_scope_lms($context),[$period]));
    $book=$rows[0]??null;
    $scheme=$book?json_decode($book['scheme_snapshot_json'],true):$latest;
    if (!is_array($scheme)) throw new RuntimeException('The gradebook grading snapshot is invalid.');
    return ['book_id'=>$book?(int)$book['book_id']:0,'version'=>$book?(int)$book['version']:0,'grading_period'=>$period,'scheme'=>$scheme,'latest_scheme'=>$latest,'scheme_changed'=>$book && (int)$book['scheme_version']!==$latest['version'],'updated_at'=>$book?$book['updated_at']:null];
}
function synk_gradebook_items_lms(mysqli $lms, int $bookId): array
{
    $rows=$bookId?synk_grading_query_lms($lms,"SELECT item_id,component_id,title,entry_kind,assessment_id,max_points,recorded_date FROM tbl_lms_gradebook_items WHERE book_id=? AND status='active' ORDER BY item_id",'i',[$bookId]):[];
    foreach ($rows as &$row) {
        $row['item_id']=(int)$row['item_id'];$row['component_id']=(int)$row['component_id'];$row['assessment_id']=$row['assessment_id']===null?null:(int)$row['assessment_id'];$row['max_points']=(float)$row['max_points'];
    } unset($row);
    return $rows;
}
function synk_gradebook_roster_lms(mysqli $core, array $context): array
{
    $class=$context['class'];
    $roster=synk_professor_workload_students($core,(int)$context['portal']['faculty_id'],(int)$class['ay_id'],(int)$class['semester'],(int)$class['offering_id']);
    return array_map(static function(array $student):array {return array_intersect_key($student,array_flip(['student_id','student_number','full_name']));},$roster['students']);
}
function synk_gradebook_auto_points_lms(array $record, array $attempt, float $itemMax): array
{
    $record=synk_assessment_attempt_record_lms($record,$attempt);
    if ($record['assessment_type']==='assignment') return ['points'=>0.0,'reason'=>'Written response counts as zero until manually scored'];
    $questions=$record['questions'];$earned=0;$pending=false;
    $attempt['answers']=json_decode($attempt['answers_json'],true)?:[];
    foreach ($questions as $question) {
        $result=synk_assessment_result_lms($record,[$question],$attempt);
        if ($result['pending_review_count']) $pending=true;
        elseif ($result['correct_count']) $earned+=synk_gradebook_points_lms($question['points']);
    }
    $max=synk_gradebook_points_lms($record['total_points'],false);
    if ($earned>$max) throw new RuntimeException('Assessment points exceed the recorded maximum.');
    // Normalize an older attempt version to the gradebook item's frozen maximum.
    return ['points'=>round($earned/$max*$itemMax,2),'reason'=>$pending?'Objective points; unreviewed answers count as zero':'Automatically checked LMS submission'];
}
function synk_gradebook_score_map_lms(mysqli $lms, array $context, array $items, array $roster): array
{
    $map=[];$students=array_column($roster,null,'student_id');$itemsById=array_column($items,null,'item_id');
    if (!$items) return [];
    $ids=array_keys($itemsById);
    $rows=synk_grading_query_lms($lms,'SELECT item_id,student_id,points,remark FROM tbl_lms_gradebook_scores WHERE item_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',str_repeat('i',count($ids)),$ids);
    foreach ($rows as $row) if (isset($students[(int)$row['student_id']])) $map[(int)$row['item_id']][(int)$row['student_id']]=['points'=>(float)$row['points'],'source'=>'manual','remark'=>$row['remark'],'reason'=>'Manually recorded'];
    if (synk_assessment_delivery_ready_lms($lms)) foreach ($items as $item) {
        if (!$item['assessment_id']) continue;
        $record=synk_assessment_detail_lms($lms,$context,$item['assessment_id']);
        $attempts=synk_grading_query_lms($lms,'SELECT status,student_id,answers_json,assessment_snapshot_json,deadline_at FROM tbl_lms_assessment_attempts WHERE assessment_id=?','i',[$item['assessment_id']]);
        foreach ($attempts as $attempt) {
            $studentId=(int)$attempt['student_id'];
            if (!isset($students[$studentId])) continue;
            if ($attempt['status']==='in_progress' && $attempt['deadline_at']!==null && $attempt['deadline_at']<=synk_assessment_now_lms()) $attempt['status']='expired';
            if (!in_array($attempt['status'],['submitted','expired'],true)) continue;
            $automatic=synk_gradebook_auto_points_lms($record,$attempt,$item['max_points']);
            if (isset($map[$item['item_id']][$studentId])) {
                $map[$item['item_id']][$studentId]['automatic_points']=$automatic['points'];
            } else {
                $map[$item['item_id']][$studentId]=$automatic+['source'=>'lms','remark'=>'','automatic_points'=>$automatic['points']];
            }
        }
    }
    return $map;
}
// Discover LMS work even before a professor has mapped it into a gradebook.
// This is read only: a component assignment is still required to affect grades.
function synk_gradebook_available_assessments_lms(mysqli $lms, array $context, array $roster): array
{
    if (!synk_assessment_ready_lms($lms)) return [];
    $assessments=synk_grading_query_lms($lms,"SELECT assessment_id,grading_period,assessment_type,title,total_points,status FROM tbl_lms_assessments WHERE faculty_id=? AND offering_id=? AND ay_id=? AND semester=? AND status IN ('published','closed') ORDER BY assessment_id DESC",'iiii',synk_grading_scope_lms($context));
    $links=synk_grading_query_lms($lms,'SELECT i.assessment_id,i.item_id,i.status,b.grading_period FROM tbl_lms_gradebook_items i JOIN tbl_lms_gradebook_periods b ON b.book_id=i.book_id WHERE b.faculty_id=? AND b.offering_id=? AND b.ay_id=? AND b.semester=? AND i.assessment_id IS NOT NULL','iiii',synk_grading_scope_lms($context));
    $links=array_column($links,null,'assessment_id');$students=array_column($roster,null,'student_id');$deliveryReady=synk_assessment_delivery_ready_lms($lms);
    foreach ($assessments as &$assessment) {
        $id=(int)$assessment['assessment_id'];$link=$links[$id]??null;
        $assessment['assessment_id']=$id;$assessment['total_points']=(float)$assessment['total_points'];
        $assessment['link_status']=$link?$link['status']:'unlinked';$assessment['linked_period']=$link?$link['grading_period']:null;
        $assessment['submission_count']=0;$assessment['automatic_score_count']=0;$scores=[];
        if (!$link && $deliveryReady) {
            $record=synk_assessment_detail_lms($lms,$context,$id);
            $attempts=synk_grading_query_lms($lms,'SELECT student_id,status,deadline_at,answers_json,assessment_snapshot_json FROM tbl_lms_assessment_attempts WHERE assessment_id=?','i',[$id]);
            foreach ($attempts as $attempt) {
                $studentId=(int)$attempt['student_id'];
                if (!isset($students[$studentId])) continue;
                if ($attempt['status']==='in_progress' && $attempt['deadline_at']!==null && $attempt['deadline_at']<=synk_assessment_now_lms()) $attempt['status']='expired';
                if (!in_array($attempt['status'],['submitted','expired'],true)) continue;
                $score=synk_gradebook_auto_points_lms($record,$attempt,$assessment['total_points']);
                $scores[$studentId]=$score+['status'=>$attempt['status']];$assessment['submission_count']++;
                if ($score['points']!==null) $assessment['automatic_score_count']++;
            }
        }
        $assessment['scores']=(object)$scores;
    } unset($assessment);
    return $assessments;
}
function synk_gradebook_calculate_lms(array $scheme, array $items, array $scores, int $studentId): array
{
    $components=[];$grade=0;$complete=!empty($scheme['components']) && abs(($scheme['total_weight']??0)-100)<0.00000001;$graded=0;$total=0;
    foreach ($scheme['components'] as $component) {
        $earned=0;$possible=0;$pending=0;$count=0;
        foreach ($items as $item) if ($item['component_id']===$component['component_id']) {
            $count++;$total++;$possible+=synk_gradebook_points_lms($item['max_points'],false);
            $value=$scores[$item['item_id']][$studentId]['points']??null;
            if ($value===null) $pending++;
            else {$earned+=synk_gradebook_points_lms($value);$graded++;}
        }
        $contribution=$possible?$earned/$possible*(float)$component['weight']:0.0;
        $grade+=$contribution;
        $components[]=['component_id'=>$component['component_id'],'name'=>$component['name'],'weight'=>$component['weight'],'item_count'=>$count,'pending_count'=>$pending,'earned'=>$earned/100,'possible'=>$possible/100,'contribution'=>$contribution,'complete'=>$complete];
    }
    return ['components'=>$components,'graded_count'=>$graded,'item_count'=>$total,'complete'=>$complete,'percent'=>$complete?$grade:null,'rating'=>$complete?synk_gradebook_rating_lms($grade)['rating']:null];
}
function synk_gradebook_workspace_lms(mysqli $core, mysqli $lms, array $context): array
{
    $roster=synk_gradebook_roster_lms($core,$context);$periods=[];
    foreach (['midterm','final'] as $period) {
        $book=synk_gradebook_period_lms($lms,$context,$period);$items=synk_gradebook_items_lms($lms,$book['book_id']);
        $scores=synk_gradebook_score_map_lms($lms,$context,$items,$roster);$results=[];
        foreach ($roster as $student) $results[$student['student_id']]=synk_gradebook_calculate_lms($book['scheme'],$items,$scores,(int)$student['student_id']);
        $periods[$period]=$book+['items'=>$items,'scores'=>(object)array_map(static function(array $v){return (object)$v;},$scores),'results'=>(object)$results];
    }
    // PHP 7.1 cannot read numeric properties on an object cast from an array.
    // Read the internal student maps as arrays before building the JSON payload.
    $midtermResults=(array)$periods['midterm']['results'];$finalResults=(array)$periods['final']['results'];$summary=[];
    foreach ($roster as $student) {
        $id=(int)$student['student_id'];$mid=$midtermResults[$id];$fin=$finalResults[$id];
        $average=$mid['complete']&&$fin['complete']?($mid['percent']+$fin['percent'])/2:null;
        $summary[$id]=['midterm'=>$mid['percent'],'final'=>$fin['percent'],'average'=>$average,'rating'=>$average===null?null:synk_gradebook_rating_lms($average)['rating'],'remark'=>$average===null?'—':synk_gradebook_rating_lms($average)['remark']];
    }
    $assessments=synk_gradebook_available_assessments_lms($lms,$context,$roster);
    return ['roster'=>$roster,'periods'=>$periods,'summary'=>(object)$summary,'assessments'=>$assessments];
}
