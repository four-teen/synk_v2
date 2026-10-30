<?php
require_once __DIR__.'/student_portal_helper.php';

// Read-only progress for the authenticated student. No questionnaire or roster writes.
function synk_student_evaluation_prompt_state(mysqli $conn,int $studentId): array
{
    $current=synk_fetch_current_academic_term($conn);
    $state=['required'=>false,'academic_period'=>$current['term_text'],'academic_key'=>$current['ay_id'].'-'.$current['semester'],'colleges'=>[],'remaining'=>0,'drafts'=>0,'missing'=>0];
    if ($studentId<1 || !synk_evaluation_table_exists($conn,'tbl_college_evaluation_settings')) return $state;
    $colleges=synk_evaluation_query($conn,"SELECT c.college_id,c.college_name FROM tbl_college_evaluation_settings s JOIN tbl_college c ON c.college_id=s.college_id AND c.status='active' JOIN tbl_campus cp ON cp.campus_id=c.campus_id AND cp.status='active'
        WHERE s.ay_id=? AND s.semester=? AND s.student_open=1 AND EXISTS (SELECT 1 FROM tbl_student_management_enrolled_subjects es WHERE es.student_id=? AND es.college_id=c.college_id AND es.campus_id=c.campus_id AND es.ay_id=s.ay_id AND es.semester=s.semester AND es.is_active=1 AND es.faculty_id>0) ORDER BY c.college_name,c.college_id",'iii',[(int)$current['ay_id'],(int)$current['semester'],$studentId]);
    $ready=synk_evaluation_table_exists($conn,'tbl_student_faculty_evaluations') && synk_evaluation_table_exists($conn,'tbl_student_faculty_evaluation_answers');
    $version=$ready && synk_student_faculty_evaluation_has_version_column($conn)?'e.instrument_version':"'legacy-20' AS instrument_version";
    foreach ($colleges as $college) {
        $collegeId=(int)$college['college_id'];
        $faculty=synk_evaluation_query($conn,'SELECT DISTINCT es.faculty_id FROM tbl_student_management_enrolled_subjects es JOIN tbl_college c ON c.college_id=es.college_id AND c.campus_id=es.campus_id WHERE es.student_id=? AND es.college_id=? AND es.ay_id=? AND es.semester=? AND es.is_active=1 AND es.faculty_id>0','iiii',[$studentId,$collegeId,(int)$current['ay_id'],(int)$current['semester']]);
        $saved=[];
        if ($ready) {
            $owner=synk_evaluation_owner_sql($conn,'e',$collegeId);
            $questionKeys=array_keys(synk_student_faculty_evaluation_flat_questions());
            $questionSlots=implode(',',array_fill(0,count($questionKeys),'?'));
            foreach (synk_evaluation_query($conn,"SELECT e.faculty_id,e.submission_status,e.final_submission_token,e.question_count,e.comment_text,{$version},(SELECT COUNT(DISTINCT a.question_key) FROM tbl_student_faculty_evaluation_answers a WHERE a.evaluation_id=e.evaluation_id AND a.rating BETWEEN 1 AND 5 AND a.question_key IN ({$questionSlots})) AS answer_count FROM tbl_student_faculty_evaluations e WHERE e.student_id=? AND e.ay_id=? AND e.semester=? AND {$owner}",str_repeat('s',count($questionKeys)).'iii',array_merge($questionKeys,[$studentId,(int)$current['ay_id'],(int)$current['semester']])) as $row) $saved[(int)$row['faculty_id']]=$row;
        }
        $submitted=0;$drafts=0;$missing=0;
        foreach ($faculty as $person) {
            $row=$saved[(int)$person['faculty_id']] ?? null;
            if ($row && $row['submission_status']==='submitted') $submitted++;
            elseif ($row && $row['submission_status']==='draft' && synk_student_faculty_evaluation_is_current($row) && (int)$row['answer_count']===15 && synk_student_faculty_evaluation_has_comment($row['comment_text'] ?? '')) $drafts++;
            else $missing++;
        }
        $remaining=count($faculty)-$submitted;
        if (!$remaining) continue;
        $state['colleges'][]=['college_id'=>$collegeId,'college_name'=>$college['college_name'],'faculty_count'=>count($faculty),'submitted'=>$submitted,'drafts'=>$drafts,'missing'=>$missing,'remaining'=>$remaining,
            'url'=>synk_student_build_portal_url('class-program.php',['term_key'=>$state['academic_key'],'evaluation_college_id'=>$collegeId]).'#facultyEvaluationOverview'];
        $state['remaining']+=$remaining;$state['drafts']+=$drafts;$state['missing']+=$missing;
    }
    $state['required']=(bool)$state['colleges'];
    $state['key']=hash('sha256',json_encode([$studentId,$state['academic_key'],$state['colleges']]));
    return $state;
}
