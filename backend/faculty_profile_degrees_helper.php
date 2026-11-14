<?php
require_once __DIR__.'/faculty_profile_helper.php';

function synk_faculty_profile_degrees_ensure(mysqli $conn): void
{
    $result = $conn->query("SHOW TABLES LIKE 'tbl_faculty_profile_additional_degrees'");
    $exists = $result->num_rows > 0; $result->close();
    if (!$exists) {
        $sql = file_get_contents(__DIR__.'/sql/faculty_profile_additional_degrees.sql');
        if ($sql === false || !$conn->query($sql)) throw new RuntimeException('Additional degree storage could not be initialized.');
    }
}

function synk_faculty_profile_degree_owner(string $owner): array
{
    if (!preg_match('/^(account|faculty):([1-9][0-9]{0,9})$/D',$owner,$matches) || (float)$matches[2]>2147483647) {
        throw new InvalidArgumentException('Invalid faculty profile owner.');
    }
    return [$matches[1],(int)$matches[2]];
}

function synk_faculty_profile_degrees_fetch(mysqli $conn,string $owner): array
{
    [$type,$id] = synk_faculty_profile_degree_owner($owner);
    $stmt = $conn->prepare('SELECT degrees_data FROM tbl_faculty_profile_additional_degrees WHERE owner_type=? AND owner_id=?');
    $stmt->bind_param('si',$type,$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
    if (!$row) return [];
    $degrees = json_decode($row['degrees_data'],true);
    if (!is_array($degrees)) throw new RuntimeException('Additional degrees could not be read.');
    return $degrees;
}

function synk_faculty_profile_degrees_validate($input): array
{
    $rows=[]; $errors=[];
    if (!is_array($input) || count($input)>50) return [[],['additional-degrees'=>'Enter a valid list of up to 50 additional degrees.']];
    $limits = synk_faculty_education_types();
    $allowed = array_merge(['level','status'],array_keys($limits));
    foreach (array_values($input) as $index=>$entry) {
        $prefix='additional_degree_'.$index.'_';$row=[];
        if (!is_array($entry) || array_diff(array_keys($entry),$allowed)) {
            $errors['additional-degrees']='One of the additional degrees contains unsupported fields.';
            continue;
        }
        foreach ($allowed as $key) {
            $raw=$entry[$key]??'';
            if (!is_string($raw) || preg_match('//u',$raw)!==1) {
                $row[$key]='';$errors[$prefix.$key]='Enter valid text for additional degree '.($index+1).'.';continue;
            }
            $row[$key]=synk_faculty_education_normalize($raw);
            if (isset($limits[$key]) && preg_match_all('/./us',$row[$key])>$limits[$key]) {
                $errors[$prefix.$key]='Additional degree '.($index+1).': '.$key.' must be '.$limits[$key].' characters or fewer.';
            }
        }
        if (!in_array($row['level'],['bachelor','master','doctoral'],true)) $errors[$prefix.'level']='Choose the level for additional degree '.($index+1).'.';
        if (!in_array($row['status'],['Completed','Ongoing'],true)) $errors[$prefix.'status']='Choose the study status for additional degree '.($index+1).'.';
        if ($row['degree']==='') $errors[$prefix.'degree']='Enter the degree / program for additional degree '.($index+1).'.';
        $rows[]=$row;
    }
    return [$rows,$errors];
}

// Caller has successfully saved the existing profile revision in this transaction.
// Ownership comes from the server's profile owner check, never a posted ID.
function synk_faculty_profile_degrees_save(mysqli $conn,string $owner,array $degrees): void
{
    [$rows,$errors]=synk_faculty_profile_degrees_validate($degrees);
    if ($errors) throw new InvalidArgumentException(implode(' ',$errors));
    [$type,$id]=synk_faculty_profile_degree_owner($owner);
    $json=json_encode($rows,JSON_UNESCAPED_UNICODE);
    if ($json===false) throw new RuntimeException('Additional degrees could not be encoded.');
    $stmt=$conn->prepare('INSERT INTO tbl_faculty_profile_additional_degrees (owner_type,owner_id,degrees_data)
        VALUES (?,?,?) ON DUPLICATE KEY UPDATE degrees_data=VALUES(degrees_data)');
    $stmt->bind_param('sis',$type,$id,$json);$stmt->execute();$stmt->close();
    $entries=[];
    foreach ($rows as $row) foreach (synk_faculty_education_types() as $key=>$limit) $entries[$key.':'.$row[$key]]=[$key,$row[$key]];
    ksort($entries,SORT_STRING);
    foreach ($entries as [$key,$value]) synk_faculty_education_register($conn,$key,$value);
}
