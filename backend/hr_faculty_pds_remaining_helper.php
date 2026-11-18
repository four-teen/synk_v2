<?php
// Definitions owned exclusively by the faculty HR Personal Data Sheet.
function synk_hr_pds_remaining_fields(string $section): array
{
    if ($section==='voluntary') return ['voluntary_applies'=>['options'=>['Yes','None']]];
    if ($section==='declarations') {
        $fields=[];
        foreach (['34a','34b','35a','35b','36','37','38a','38b','39','40a','40b','40c'] as $number) {
            $fields['q'.$number]=['options'=>['Yes','No']];
            if (!in_array($number,['39','40a','40b','40c'],true)) $fields['q'.$number.'_details']=['max'=>5000,'multiline'=>true];
        }
        return $fields+['case_date'=>['date'=>true],'case_status'=>['max'=>500],
            'resident_country'=>['max'=>100],'indigenous_group'=>['max'=>250],'q40b_id'=>['max'=>100],'q40c_id'=>['max'=>100]];
    }
    if ($section==='certification') {
        $fields=['photo_taken_date'=>['date'=>true],'certification_id_type'=>['max'=>150],
            'certification_id_number'=>['max'=>100],'certification_id_date'=>['date'=>true],
            'certification_id_place'=>['max'=>300],'date_accomplished'=>['date'=>true],
            'oath_date'=>['date'=>true],'oath_officer'=>['max'=>250],'declaration_acknowledged'=>['boolean'=>true]];
        for ($page=1;$page<=4;$page++) $fields['signature_date_'.$page]=['date'=>true];
        return $fields;
    }
    return [];
}

function synk_hr_pds_remaining_lists(string $section): array
{
    $lists=[];
    if ($section==='eligibility') $lists=['eligibilities'=>[
        'name'=>['max'=>500],'rating'=>['max'=>100],'examination_date'=>['date'=>true],
        'examination_place'=>['max'=>500],'license_number'=>['max'=>100],'valid_until'=>['date'=>true,'future'=>true]]];
    if ($section==='work') $lists=['work'=>[
        'position'=>['max'=>500],'employer'=>['max'=>500],'date_from'=>['date'=>true],
        'current'=>['boolean'=>true],'date_to'=>['date'=>true],
        'salary'=>['amount'=>true,'max_value'=>9999999999.99],'pay_grade'=>['max'=>100],
        'appointment_status'=>['max'=>150],'government_service'=>['options'=>['Yes','No']],
        'office_unit'=>['max'=>300],'supervisor'=>['max'=>250],
        'duties'=>['max'=>5000,'multiline'=>true],'accomplishments'=>['max'=>5000,'multiline'=>true]]];
    if ($section==='learning') $lists=['trainings'=>[
        'training_title'=>['max'=>500],'date_from'=>['date'=>true],'date_to'=>['date'=>true],
        'hours'=>['amount'=>true,'max_value'=>999999.99],
        'type'=>['options'=>['Managerial','Supervisory','Technical','Other']],'type_other'=>['max'=>250],
        'agency'=>['max'=>500],'venue'=>['max'=>500],'mode'=>['options'=>['Face to face','Online']]]];
    if ($section==='voluntary') $lists=['voluntary'=>[
        'organization'=>['max'=>500],'address'=>['max'=>500],'date_from'=>['date'=>true],'date_to'=>['date'=>true],
        'hours'=>['amount'=>true,'max_value'=>999999.99],'role'=>['max'=>500]]];
    if ($section==='other') $lists=[
        'skills'=>['name'=>['max'=>500]],'recognition'=>['name'=>['max'=>500]],
        'memberships'=>['name'=>['max'=>500]],'references'=>['name'=>['max'=>250],'address'=>['max'=>500],'contact'=>['max'=>300]]];
    $result=[];
    foreach ($lists as $key=>$fields) $result[$key]=['table'=>'tbl_hr_faculty_pds_'.$key.'_records','limit'=>100,'fields'=>$fields];
    return $result;
}

function synk_hr_pds_normalize_record(string $section, array $values, array $errors): array
{
    if ($section==='work' && ($values['current'] ?? '')==='1') { $values['date_to']=''; unset($errors['date_to']); }
    if ($section==='learning' && ($values['type'] ?? '')!=='Other') { $values['type_other']=''; unset($errors['type_other']); }
    if (isset($values['date_from'],$values['date_to']) && $values['date_from']!=='' && $values['date_to']!==''
        && !isset($errors['date_from']) && !isset($errors['date_to']) && $values['date_to']<$values['date_from']) $errors['date_to']='The end date cannot be before the start date.';
    return [$values,$errors];
}

function synk_hr_pds_normalize_section(string $section, array $values, array $errors): array
{
    if ($section==='declarations') {
        foreach (['34a','34b','35a','35b','36','37','38a','38b','39','40a','40b','40c'] as $number) {
            $followups=['q'.$number.'_details'];
            if ($number==='35b') $followups=array_merge($followups,['case_date','case_status']);
            if ($number==='39') $followups=['resident_country'];
            if ($number==='40a') $followups=['indigenous_group'];
            if (in_array($number,['40b','40c'],true)) $followups=['q'.$number.'_id'];
            // An unanswered question retains a follow-up draft. Only explicit No clears it.
            if (($values['q'.$number] ?? '')==='No') foreach ($followups as $key) { $values[$key]=''; unset($errors[$key]); }
        }
    }
    return [$values,$errors];
}
