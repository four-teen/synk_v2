<?php
if (PHP_SAPI!=='cli' && !defined('SYNK_HR_PDS_QA')) { http_response_code(404); exit; }
function hr_pds_empty_section(string $section): array
{
    $data=[];
    foreach (synk_hr_pds_fields($section) as $key=>$rule) $data[$key]=!empty($rule['boolean'])?'0':'';
    foreach (synk_hr_pds_record_lists($section) as $key=>$list) $data[$key]=[];
    return $data;
}
function hr_pds_remaining_examples(): array
{
    $data=[];
    foreach (array_slice(synk_hr_pds_sections(),4) as $section) {
        $values=hr_pds_empty_section($section);
        foreach (synk_hr_pds_record_lists($section) as $key=>$list) {
            $row=[];
            foreach ($list['fields'] as $field=>$rule) $row[$field]=!empty($rule['boolean'])?'0':'';
            $values[$key]=[$row];
        }
        $data[$section]=$values;
    }
    $data['eligibility']['eligibilities'][0]=array_replace($data['eligibility']['eligibilities'][0],[
        'name'=>'RA 1080 / Professional teacher','rating'=>'88.75','examination_date'=>'2020-03-01',
        'examination_place'=>'Sample City','license_number'=>'000123-A','valid_until'=>'2028-03-01']);
    $data['work']['work'][0]=array_replace($data['work']['work'][0],[
        'position'=>'Instructor','employer'=>'Sample University','date_from'=>'2021-06-01','current'=>'1',
        'salary'=>'24500.50','pay_grade'=>'23-1','appointment_status'=>'Permanent','government_service'=>'Yes',
        'office_unit'=>'Department of Education','supervisor'=>'Sample Supervisor','duties'=>"Teach courses.\nPrepare learning materials.",
        'accomplishments'=>"Created teaching resources.\nMentored students."]);
    $data['learning']['trainings'][0]=array_replace($data['learning']['trainings'][0],[
        'training_title'=>'Research methods','date_from'=>'2025-08-01','date_to'=>'2025-08-02',
        'hours'=>'6.50','type'=>'Other','type_other'=>'Research','agency'=>'Sample University','venue'=>'Sample Campus','mode'=>'Face to face']);
    $data['voluntary']['voluntary_applies']='Yes';
    $data['voluntary']['voluntary'][0]=array_replace($data['voluntary']['voluntary'][0],[
        'organization'=>'Community Learning Center','address'=>'Sample City','date_from'=>'2025-09-01','date_to'=>'2025-09-30',
        'hours'=>'12.00','role'=>'Volunteer teacher']);
    $data['other']=['skills'=>[['name'=>'C++ & <public> speaking']],'recognition'=>[['name'=>'Service award']],
        'memberships'=>[['name'=>'Professional association']],
        'references'=>[['name'=>'Sample Reference','address'=>'Sample City','contact'=>'reference@example.edu / 09123456789']]];
    $data['declarations']=array_replace($data['declarations'],[
        'q34a'=>'No','q34b'=>'Yes','q34b_details'=>'Sample relationship details.',
        'q35b'=>'Yes','q35b_details'=>"Sample case details.\nSecond line.",'case_date'=>'2020-01-01','case_status'=>'Dismissed',
        'q39'=>'Yes','resident_country'=>'Canada','q40a'=>'Yes','indigenous_group'=>'Sample group',
        'q40b'=>'Yes','q40b_id'=>'000123','q40c'=>'Yes','q40c_id'=>'000456']);
    $data['certification']=array_replace($data['certification'],[
        'certification_id_type'=>'Passport','certification_id_number'=>'0001234-A',
        'certification_id_date'=>'2020-01-01','certification_id_place'=>'Sample City','date_accomplished'=>'2026-11-15',
        'declaration_acknowledged'=>'1']);
    return $data;
}
