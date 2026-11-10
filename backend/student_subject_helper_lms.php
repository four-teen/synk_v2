<?php
require_once __DIR__.'/student_dashboard_helper.php';

// A subject taught by two professors is two separate student workspaces.
function synk_student_subject_key_lms(int $facultyId,string $code,string $title): string
{
    $identity=trim($code)!==''?$code:$title;
    $identity=function_exists('mb_strtoupper')?mb_strtoupper($identity,'UTF-8'):strtoupper($identity);
    return $facultyId.':'.sha1(preg_replace('/\s+/u','',$identity));
}

function synk_student_subjects_lms(mysqli $core,int $studentId): array
{
    $term=synk_fetch_current_academic_term($core);
    $rows=synk_student_dashboard_subjects($core,$studentId,$term);
    $subjects=[];
    foreach($rows as $row){
        $key=synk_student_subject_key_lms((int)$row['faculty_id'],$row['subject_code'],$row['descriptive_title']);
        if(!isset($subjects[$key])){
            $row['subject_key']=$key;
            $subjects[$key]=$row;
            continue;
        }
        foreach(['section_display','room_name','schedule_text'] as $field){
            $separator=$field==='schedule_text'?'; ':' / ';
            $values=explode($separator,$subjects[$key][$field]);
            if(trim($row[$field])!==''&&!in_array($row[$field],$values,true))$values[]=$row[$field];
            $subjects[$key][$field]=implode($separator,array_filter($values,'strlen'));
        }
    }
    return array_values($subjects);
}
