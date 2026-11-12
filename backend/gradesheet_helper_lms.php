<?php
require_once __DIR__.'/gradebook_helper_lms.php';
require_once __DIR__.'/professor_workload_students_helper.php';

function synk_gradesheet_name_lms(array $student): string
{
    $last=trim((string)($student['last_name']??''));
    $first=trim((string)($student['first_name']??''));
    $middle=trim((string)($student['middle_name']??''));
    $initials=[];
    foreach (preg_split('/\s+/u',$middle,-1,PREG_SPLIT_NO_EMPTY) as $part) {
        $initials[]=(function_exists('mb_substr')?mb_substr($part,0,1,'UTF-8'):substr($part,0,1)).'.';
    }
    $given=trim($first.' '.trim((string)($student['suffix_name']??'')).' '.implode(' ',$initials));
    $name=$last!==''?($last.($given!==''?', '.$given:'')):($given!==''?$given:(string)($student['full_name']??''));
    return function_exists('mb_strtoupper')?mb_strtoupper($name,'UTF-8'):strtoupper($name);
}

// Read enrollment details only for the already-authorized gradebook roster.
// The matcher includes merged classes and older enrollment rows without an offering ID.
function synk_gradesheet_metadata_lms(mysqli $core, array $context, array $roster): array
{
    if (!$roster) return ['students'=>[],'sex'=>[]];
    $ids=array_map('intval',array_column($roster,'student_id'));
    $slots=implode(',',array_fill(0,count($ids),'?'));
    $names=synk_grading_query_lms($core,'SELECT student_id,last_name,first_name,middle_name,suffix_name FROM tbl_student_management WHERE student_id IN ('.$slots.')',str_repeat('i',count($ids)),$ids);
    $students=array_column($names,null,'student_id');
    $class=$context['class'];$offering=(int)$class['offering_id'];
    $merged=synk_schedule_merge_load_display_context($core,[$offering]);
    $offerings=synk_normalize_offering_ids($merged[$offering]['group_offering_ids']??[$offering]);
    $params=array_merge([(int)$class['ay_id'],(int)$class['semester']],$offerings);
    $rows=synk_grading_query_lms($core,"SELECT es.student_id,es.program_id,es.section_id,es.year_level,es.section_text,
        p.program_code,sec.full_section,sec.section_name
        FROM tbl_prospectus_offering po
        JOIN tbl_prospectus_subjects ps ON ps.ps_id=po.ps_id
        JOIN tbl_program p ON p.program_id=po.program_id
        JOIN tbl_college c ON c.college_id=p.college_id
        JOIN tbl_student_management_enrolled_subjects es ON (es.offering_id=po.offering_id OR es.offering_id=0)
            AND es.program_id=po.program_id AND es.section_id=po.section_id AND es.subject_id=ps.sub_id
            AND es.college_id=p.college_id AND es.campus_id=c.campus_id
        LEFT JOIN tbl_sections sec ON sec.section_id=es.section_id
        WHERE es.ay_id=? AND es.semester=? AND es.is_active=1
            AND po.offering_id IN (".implode(',',array_fill(0,count($offerings),'?')).')
        ORDER BY es.student_enrollment_id',str_repeat('i',count($params)),$params);
    $labels=[];
    foreach ($rows as $row) {
        $id=(int)$row['student_id'];
        if (!isset($students[$id])) continue;
        $label=trim((string)$row['full_section']);
        if ($label==='') $label=trim((string)$row['section_text']);
        if ($label==='') $label=trim($row['program_code'].' '.($row['year_level']?(string)$row['year_level']:'').' '.$row['section_name']);
        if ($label!=='') $labels[$id][$label]=true;
    }
    foreach ($students as $id=>&$student) $student['program_section']=implode(' / ',array_keys($labels[$id]??[]));
    unset($student);
    $sex=[];
    // An approved enrollment may supply sex. Never infer it from a student's name.
    if (synk_table_exists($core,'tbl_enrollment_headers') && synk_table_has_column($core,'tbl_enrollment_headers','sex')) {
        $params=array_merge([(int)$class['ay_id'],(int)$class['semester']],$ids);
        $rows=synk_grading_query_lms($core,"SELECT student_id,sex FROM tbl_enrollment_headers
            WHERE ay_id=? AND semester=? AND workflow_status='approved' AND student_id IN (".$slots.')
            ORDER BY approved_at DESC,enrollment_id DESC',str_repeat('i',count($params)),$params);
        foreach ($rows as $row) {
            $id=(int)$row['student_id'];
            if (array_key_exists($id,$sex)) continue;
            $value=strtolower(trim((string)$row['sex']));
            $sex[$id]=in_array($value,['m','male'],true)?'male':(in_array($value,['f','female'],true)?'female':'unknown');
        }
    }
    return ['students'=>$students,'sex'=>$sex];
}

function synk_gradesheet_build_lms(array $workspace, array $metadata): array
{
    $summary=(array)$workspace['summary'];$rows=[];$counts=['male'=>0,'female'=>0,'unknown'=>0,'total'=>0];
    foreach ($workspace['roster'] as $student) {
        $id=(int)$student['student_id'];$details=$metadata['students'][$id]??[];
        $grade=$summary[$id];
        // Use the exact semester summary, including its zero-score and missing-scheme rules.
        $rows[]=['student_id'=>$id,'name'=>synk_gradesheet_name_lms($details+$student),
            'program_section'=>(string)($details['program_section']??''),
            'midterm'=>$grade['midterm'],'final'=>$grade['final'],'average'=>$grade['average'],
            'rating'=>$grade['rating'],'remark'=>$grade['average']===null?'':$grade['remark']];
        $sex=$metadata['sex'][$id]??'unknown';
        $counts[in_array($sex,['male','female'],true)?$sex:'unknown']++;$counts['total']++;
    }
    usort($rows,static function(array $a,array $b):int {return strnatcasecmp($a['name'],$b['name'])?:($a['student_id']<=>$b['student_id']);});
    return ['rows'=>$rows,'counts'=>$counts];
}

function synk_gradesheet_number_lms($value, bool $percentage = false): string
{
    return $value===null?'':number_format((float)$value,2,'.','').($percentage?'%':'');
}
