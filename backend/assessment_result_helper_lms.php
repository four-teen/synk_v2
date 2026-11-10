<?php

function synk_assessment_result_text_lms(string $value): string
{
    $value=trim(preg_replace('/\s+/u',' ',$value));
    return function_exists('mb_strtolower')?mb_strtolower($value,'UTF-8'):strtolower($value);
}

// Published questions are immutable. Only aggregate counts leave the server,
// and only after an attempt is locked. These counts are not course grades.
function synk_assessment_result_lms(array $record,array $questions,?array $attempt): ?array
{
    if(!$attempt||!in_array($attempt['status'],['submitted','expired'],true))return null;
    $result=['correct_count'=>0,'mistake_count'=>0,'pending_review_count'=>0,'checked_count'=>0];
    if($record['assessment_type']==='assignment'){
        $result['pending_review_count']=1;
        return $result;
    }
    foreach($questions as $question){
        $kind=$question['question_type'];
        $key=json_decode($question['answer_key_json']??'null',true);
        $answer=$attempt['answers'][$question['question_id']]??null;
        if(($kind==='multiple_choice'&&is_int($key))||($kind==='true_false'&&is_bool($key))){
            $correct=$answer===$key;
        }elseif($kind==='short_answer'&&is_array($key)&&$key){
            $accepted=[];
            foreach($key as $value)if(is_string($value)&&synk_assessment_result_text_lms($value)!=='')$accepted[]=synk_assessment_result_text_lms($value);
            if(!$accepted){$result['pending_review_count']++;continue;}
            $correct=is_string($answer)&&in_array(synk_assessment_result_text_lms($answer),$accepted,true);
        }else{
            $result['pending_review_count']++;
            continue;
        }
        $result[$correct?'correct_count':'mistake_count']++;
        $result['checked_count']++;
    }
    return $result;
}
