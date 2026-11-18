<?php
// HR-only definitions for repeatable Family and Education records.
function synk_hr_pds_family_fields(): array
{
    $fields=['spouse_applies'=>['options'=>['Yes','Not applicable']],'children_apply'=>['options'=>['Yes','None']]];
    foreach (['father','mother'] as $parent) foreach (['surname','first_name','middle_name','name_extension'] as $part) {
        $fields[$parent.'_'.$part]=['max'=>$part==='name_extension'?30:100];
    }
    return $fields;
}

function synk_hr_pds_record_lists(string $section): array
{
    if ($section==='family') return [
        'spouses'=>['table'=>'tbl_hr_faculty_pds_spouses','limit'=>20,'fields'=>[
            'surname'=>['max'=>100],'first_name'=>['max'=>100],'middle_name'=>['max'=>100],'name_extension'=>['max'=>30],
            'occupation'=>['max'=>250],'employer'=>['max'=>300],'business_address'=>['max'=>500],'telephone'=>['max'=>50],
        ]],
        'children'=>['table'=>'tbl_hr_faculty_pds_children','limit'=>100,'fields'=>[
            'full_name'=>['max'=>250],'date_of_birth'=>['date'=>true],
        ]],
    ];
    if ($section==='education') return [
        'education'=>['table'=>'tbl_hr_faculty_pds_education_records','limit'=>100,'fields'=>[
            'level'=>['options'=>['Elementary','Secondary','Vocational / trade course','College','Graduate studies']],
            'institution'=>['max'=>300],'degree'=>['max'=>500],
            'year_from'=>['year'=>true],'year_to'=>['year'=>true],'units'=>['max'=>250],'year_graduated'=>['year'=>true],
            'honors'=>['max'=>500],'status'=>['options'=>['Completed','Ongoing']],
            'address'=>['max'=>500],'specialization'=>['max'=>500],
        ]],
    ];
    return synk_hr_pds_remaining_lists($section);
}

function synk_hr_pds_validate_records(string $section, $input, DateTimeImmutable $today): array
{
    $fields=synk_hr_pds_fields($section); $lists=synk_hr_pds_record_lists($section);
    $allowed=array_merge(array_keys($fields),array_keys($lists));
    if (!is_array($input) || array_diff(array_keys($input),$allowed) || array_diff($allowed,array_keys($input))) {
        return [[],['_section'=>'The section is incomplete or contains unsupported fields. Reload before saving.']];
    }
    [$data,$errors]=synk_hr_pds_validate_fields($fields,array_intersect_key($input,$fields),$today);
    foreach ($lists as $key=>$definition) {
        $rows=$input[$key]; $data[$key]=[];
        if (!is_array($rows) || count($rows)>$definition['limit'] || ($rows && array_keys($rows)!==range(0,count($rows)-1))) {
            $errors[$key]='Use a valid list of up to '.$definition['limit'].' records.'; continue;
        }
        foreach ($rows as $index=>$row) {
            [$values,$rowErrors]=synk_hr_pds_validate_fields($definition['fields'],$row,$today);
            if (!isset($rowErrors['_section'])) [$values,$rowErrors]=synk_hr_pds_normalize_record($section,$values,$rowErrors);
            if ($key==='education' && !$rowErrors) {
                if ($values['year_from']!=='' && $values['year_to']!=='' && (int)$values['year_to']<(int)$values['year_from']) $rowErrors['year_to']='The end year must be the same as or later than the start year.';
                if ($values['year_from']!=='' && $values['year_graduated']!=='' && (int)$values['year_graduated']<(int)$values['year_from']) $rowErrors['year_graduated']='Graduation cannot be before the attendance start year.';
                if ($values['status']==='Ongoing' && $values['year_graduated']!=='') $rowErrors['year_graduated']='Leave the graduation year empty for ongoing studies.';
            }
            $data[$key][]=$values;
            foreach ($rowErrors as $field=>$message) $errors[$key.'.'.$index.'.'.$field]=$message;
        }
    }
    if ($section==='family') {
        foreach (['spouses'=>['spouse_applies','Not applicable'],'children'=>['children_apply','None']] as $list=>[$flag,$none]) {
            if (($data[$flag] ?? '')===$none) {
                $data[$list]=[];
                foreach (array_keys($errors) as $error) if ($error===$list || strpos($error,$list.'.')===0) unset($errors[$error]);
            } elseif (!empty($data[$list]) && ($data[$flag] ?? '')!=='Yes') $errors[$flag]='Choose Yes to keep the records you entered, or select the applicable None option.';
        }
    }
    if ($section==='voluntary' && ($data['voluntary_applies'] ?? '')==='None') {
        $data['voluntary']=[];
        foreach (array_keys($errors) as $key) if ($key==='voluntary' || strpos($key,'voluntary.')===0) unset($errors[$key]);
    } elseif ($section==='voluntary' && !empty($data['voluntary']) && ($data['voluntary_applies'] ?? '')!=='Yes') $errors['voluntary_applies']='Choose Yes to keep your voluntary work records, or None to remove them.';
    return [$data,$errors];
}

function synk_hr_pds_load_records(mysqli $conn, int $profileId, string $section): array
{
    $values=[];
    foreach (synk_hr_pds_record_lists($section) as $key=>$definition) {
        $statement=$conn->prepare('SELECT * FROM '.$definition['table'].' WHERE hr_profile_id=? ORDER BY row_order');
        $statement->bind_param('i',$profileId); $statement->execute(); $rows=$statement->get_result(); $values[$key]=[];
        while ($row=$rows->fetch_assoc()) {
            $record=[];
            foreach ($definition['fields'] as $field=>$rule) $record[$field]=$row[$field]===null?'':(string)$row[$field];
            $values[$key][]=$record;
        }
        $statement->close();
    }
    return $values;
}

// The parent section revision and HR owner are already locked by the caller.
function synk_hr_pds_save_records(mysqli $conn, int $profileId, string $section, array $values): void
{
    foreach (synk_hr_pds_record_lists($section) as $key=>$definition) {
        $statement=$conn->prepare('DELETE FROM '.$definition['table'].' WHERE hr_profile_id=?');
        $statement->bind_param('i',$profileId); $statement->execute(); $statement->close();
        $fields=$definition['fields'];
        $query='INSERT INTO '.$definition['table'].' (hr_profile_id,row_order,`'.implode('`,`',array_keys($fields)).'`) VALUES ('.implode(',',array_fill(0,count($fields)+2,'?')).')';
        $statement=$conn->prepare($query);
        foreach ($values[$key] as $index=>$row) {
            $parameters=[$profileId,$index];
            foreach ($fields as $field=>$rule) $parameters[]=($row[$field]==='' && (!empty($rule['date']) || !empty($rule['year']) || !empty($rule['amount'])))?null:$row[$field];
            $types='ii'.str_repeat('s',count($fields)); $statement->bind_param($types,...$parameters); $statement->execute();
        }
        $statement->close();
    }
}
