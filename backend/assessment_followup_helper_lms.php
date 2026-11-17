<?php
require_once __DIR__.'/assessment_delivery_helper_lms.php';

function synk_assessment_class_followup_lms(mysqli $core,mysqli $lms,array $context): array
{
    if (!synk_assessment_delivery_ready_lms($lms)) throw new DomainException('Assessment delivery storage is not ready yet.',503);
    $roster=synk_class_students_roster_lms($core,$lms,$context);
    $records=synk_assessment_query_lms($lms,"SELECT assessment_id,title,grading_period,assessment_type,status,opens_at,due_at FROM tbl_lms_assessments WHERE faculty_id=? AND offering_id=? AND ay_id=? AND semester=? AND status IN ('published','closed') ORDER BY FIELD(grading_period,'midterm','final'),due_at IS NULL,due_at,assessment_id",'iiii',synk_assessment_scope_params_lms($context));
    $attempts=[];
    if ($records) foreach (synk_assessment_query_lms($lms,'SELECT t.assessment_id,t.student_id,t.status,t.deadline_at,t.assessment_snapshot_json FROM tbl_lms_assessment_attempts t JOIN tbl_lms_assessments a ON a.assessment_id=t.assessment_id WHERE a.faculty_id=? AND a.offering_id=? AND a.ay_id=? AND a.semester=?', 'iiii',synk_assessment_scope_params_lms($context)) as $attempt) $attempts[(int)$attempt['student_id']][(int)$attempt['assessment_id']]=$attempt;
    $now=synk_assessment_now_lms();$zone=new DateTimeZone('Asia/Manila');
    $date=static function(?string $value)use($zone):string{return $value===null?'No due date':(new DateTimeImmutable($value,$zone))->format('M j, Y \a\t g:i A').' PHT';};
    $students=[];$missing=0;
    foreach ($roster['students'] as $student) {
        if ($student['class_status']!=='enrolled') continue;
        $items=[];
        foreach ($records as $record) {
            $id=(int)$record['assessment_id'];$attempt=$attempts[$student['student_id']][$id]??null;
            if ($attempt && $attempt['status']==='submitted') continue;
            $original=synk_assessment_attempt_record_lms($record,$attempt);
            $deadline=$attempt?$attempt['deadline_at']:$original['due_at'];
            if ($attempt && ($attempt['status']==='expired' || ($deadline!==null && $deadline<=$now))) {$status='expired';$label='Attempt ended';}
            elseif ($record['status']==='closed') {$status='closed';$label='Closed without submission';}
            elseif ($deadline!==null && $deadline<=$now) {$status='overdue';$label='Deadline passed';}
            elseif ($attempt) {$status='in_progress';$label='In progress';}
            elseif ($original['opens_at']!==null && $original['opens_at']>$now) {$status='upcoming';$label='Not open yet';}
            else {$status='not_started';$label='Not started';}
            $items[]=['assessment_id'=>$id,'title'=>$original['title'],'assessment_type'=>$original['assessment_type'],'grading_period'=>$original['grading_period'],
                'status'=>$status,'status_label'=>$label,'deadline_at'=>$deadline,'deadline_label'=>$date($deadline),
                'opens_at'=>$original['opens_at'],'opens_label'=>$original['opens_at']===null?null:$date($original['opens_at'])];
        }
        if (!$items) continue;
        $students[]=['student_name'=>$student['full_name'],'student_number'=>$student['student_number'],'assessments'=>$items];$missing+=count($items);
    }
    $class=$context['class'];
    $report=['class'=>['subject_code'=>$class['subject_code'],'title'=>$class['descriptive_title'],'section'=>(string)$class['section_display']],
        'counts'=>['students'=>$roster['counts']['students'],'eligible'=>$roster['counts']['enrolled'],'dropped'=>$roster['counts']['dropped'],'excluded'=>$roster['excluded_count'],
            'assessments'=>count($records),'missing_students'=>count($students),'missing_assessments'=>$missing],
        'students'=>$students,'generated_at'=>$now,'generated_label'=>$date($now),
        'notice'=>'Includes published and closed assessments across Midterm and Final. Future work is labeled Not open yet; unsubmitted work is not necessarily overdue. Dropped students and excluded accounts are omitted.'];
    $lines=[];$line='synk_assessment_followup_line_lms';
    if ($students) {
        $lines=['CLASS SUBMISSION FOLLOW-UP','Subject: '.$line($class['subject_code'].' - '.$class['descriptive_title'])];
        if ($class['section_display']!=='') $lines[]='Class: '.$line($class['section_display']);
        $lines[]='Scope: Midterm and Final; published and closed assessments';
        $lines[]='Real student total: '.$report['counts']['students'];$lines[]='As of: '.$report['generated_label'];$lines[]='';
        foreach ($students as $index=>$student) {
            $lines[]=($index+1).'. '.$line($student['student_name']);
            foreach ($student['assessments'] as $item) {
                $lines[]='   - '.$line($item['title']).' | '.ucfirst($item['grading_period']).' / '.ucfirst($item['assessment_type']);
                $lines[]='     Status: '.$item['status_label'].' | Due: '.$item['deadline_label'];
                if ($item['status']==='upcoming') $lines[]='     Opens: '.$item['opens_label'];
            }
            $lines[]='';
        }
        $lines[]='Follow-up total: '.count($students).' student'.(count($students)===1?'':'s').'; '.$missing.' outstanding assessment'.($missing===1?'':'s').'.';
        $lines[]='Not all outstanding work is overdue. Please check the status and deadline of each assessment.';
        $lines[]='If you submitted after this report was generated, please disregard that item.';
    }
    $report['message']=implode("\n",$lines);return $report;
}

function synk_assessment_followup_line_lms(string $value): string
{
    return preg_replace('/\s+/u',' ',trim($value));
}

function synk_assessment_followup_message_lms(array $report): string
{
    if (!$report['students']) return '';
    $a=$report['assessment'];$class=$report['class'];
    $line='synk_assessment_followup_line_lms';
    $lines=['ASSESSMENT REMINDER','Subject: '.$line($class['subject_code'].' - '.$class['title'])];
    if ($class['section']!=='') $lines[]='Class: '.$line($class['section']);
    $lines[]='Assessment: '.$line($a['title']);
    $lines[]='Grading period: '.ucfirst($a['grading_period']);
    $lines[]='Due: '.$report['due_label'];
    $lines[]='As of: '.$report['generated_label'];
    $lines[]='';$lines[]=$report['notice'];$lines[]='';
    $lines[]='Students without a submitted assessment:';
    foreach ($report['students'] as $index=>$student) {
        $detail=$student['status_label'];
        if ($student['deadline_at']!==$a['due_at']) $detail.='; individual deadline: '.$student['deadline_label'];
        $lines[]=($index+1).'. '.$line($student['student_name']).' — '.$line($a['title']).' ('.$detail.')';
    }
    $lines[]='';$lines[]='Total: '.count($report['students']).' student'.(count($report['students'])===1?'':'s').'.';
    $lines[]='Please check Activities & assessments. If you submitted after this list was generated, please disregard this reminder.';
    return implode("\n",$lines);
}

// Read only. Use the same current, deduplicated roster as assessment participation.
function synk_assessment_missing_submissions_lms(mysqli $core,mysqli $lms,array $context,int $id): array
{
    $record=synk_assessment_detail_lms($lms,$context,$id,true);
    if (!in_array($record['status'],['published','closed'],true)) throw new DomainException('Publish this assessment before checking missing submissions.',409);
    if (!synk_assessment_delivery_ready_lms($lms)) throw new DomainException('Assessment delivery storage is not ready yet.',503);
    $class=$context['class'];$offering=(int)$class['offering_id'];
    $memberships=synk_professor_workload_student_memberships($core,[$class],(int)$class['ay_id'],(int)$class['semester']);
    $ids=array_keys($memberships[$offering]??[]);$roster=[];$attempts=[];
    $statuses=synk_class_student_status_map_lms($lms,$context);
    $ids=array_keys(synk_class_student_counted_members_lms(array_fill_keys($ids,true),$statuses));
    if ($ids) {
        $roster=synk_assessment_query_lms($core,'SELECT student_id,student_number,first_name,middle_name,last_name,suffix_name FROM tbl_student_management WHERE student_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY last_name,first_name,middle_name,student_id',str_repeat('i',count($ids)),$ids);
        foreach (synk_assessment_query_lms($lms,'SELECT student_id,status,deadline_at FROM tbl_lms_assessment_attempts WHERE assessment_id=?','i',[$id]) as $attempt) $attempts[(int)$attempt['student_id']]=$attempt;
    }
    $now=synk_assessment_now_lms();$zone=new DateTimeZone('Asia/Manila');
    $date=static function(?string $value)use($zone):string { return $value===null?'No due date':(new DateTimeImmutable($value,$zone))->format('M j, Y \a\t g:i A').' PHT'; };
    $students=[];$submitted=0;$excludedDropped=0;$statuses=synk_class_student_status_map_lms($lms,$context);
    foreach ($roster as $student) {
        $attempt=$attempts[(int)$student['student_id']]??null;
        if ($attempt && $attempt['status']==='submitted') { $submitted++;continue; }
        if (($statuses[(int)$student['student_id']]['status']??'enrolled')==='dropped') { $excludedDropped++;continue; }
        // Started students retain their original effective deadline even after edits.
        $deadline=$attempt?$attempt['deadline_at']:$record['due_at'];
        if ($attempt && ($attempt['status']==='expired' || ($deadline!==null && $deadline<=$now))) { $status='expired';$label='Attempt ended'; }
        elseif ($record['status']==='closed') { $status='closed';$label='Closed without submission'; }
        elseif ($deadline!==null && $deadline<=$now) { $status='overdue';$label='Deadline passed'; }
        elseif ($attempt) { $status='in_progress';$label='In progress'; }
        elseif ($record['opens_at']!==null && $record['opens_at']>$now) { $status='upcoming';$label='Not open yet'; }
        else { $status='not_started';$label='Not started'; }
        $given=trim(implode(' ',array_filter([$student['first_name'],$student['middle_name'],$student['suffix_name']],static function($value):bool{return trim((string)$value)!=='';})));
        $last=trim((string)$student['last_name']);$name=$last.($last!==''&&$given!==''?', ':'').$given;
        $students[]=['student_name'=>synk_assessment_followup_line_lms($name!==''?$name:'Name unavailable'),
            'student_number'=>(string)$student['student_number'],'status'=>$status,'status_label'=>$label,
            'deadline_at'=>$deadline,'deadline_label'=>$date($deadline)];
    }
    if ($record['status']==='closed') $notice='This assessment is closed. The students listed below have no submitted attempt.';
    elseif ($record['opens_at']!==null && $record['opens_at']>$now) $notice='This assessment has not opened yet. This is an unsubmitted list, not an overdue list.';
    elseif ($record['due_at']===null) $notice='No due date is set. These submissions are outstanding, not necessarily overdue.';
    elseif ($record['due_at']<=$now) $notice='The scheduled deadline has passed. Started students may retain a different original deadline; check their individual status.';
    else $notice='The scheduled deadline has not passed. Students may still be working.';
    if ($excludedDropped) $notice.=' '.$excludedDropped.' dropped student'.($excludedDropped===1?' is':'s are').' excluded from follow-up.';
    $report=['assessment'=>array_intersect_key($record,array_flip(['assessment_id','title','grading_period','assessment_type','status','opens_at','due_at'])),
        'class'=>['subject_code'=>$class['subject_code'],'title'=>$class['descriptive_title'],'section'=>(string)$class['section_display']],
        'counts'=>['students'=>count($roster),'submitted'=>$submitted,'missing'=>count($students)],
        'students'=>$students,'excluded_dropped_count'=>$excludedDropped,'notice'=>$notice,'due_label'=>$date($record['due_at']),'generated_at'=>$now,'generated_label'=>$date($now)];
    $report['message']=synk_assessment_followup_message_lms($report);
    return $report;
}
