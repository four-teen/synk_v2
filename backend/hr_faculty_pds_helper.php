<?php
require_once __DIR__.'/hr_faculty_pds_records_helper.php';
require_once __DIR__.'/hr_faculty_pds_remaining_helper.php';
require_once __DIR__.'/hr_faculty_pds_attachments_helper.php';

function synk_hr_pds_sections(): array { return ['personal','contact','family','education','eligibility','work','learning','voluntary','other','declarations','certification']; }

function synk_hr_pds_section_table(string $section): string
{
    if (!in_array($section,synk_hr_pds_sections(),true)) throw new InvalidArgumentException('This PDS section is not available for saving yet.');
    return 'tbl_hr_faculty_pds_'.$section;
}

function synk_hr_pds_fields(string $section): array
{
    if ($section === 'personal') return [
        'surname'=>['max'=>100], 'first_name'=>['max'=>100], 'middle_name'=>['max'=>100], 'name_extension'=>['max'=>30],
        'date_of_birth'=>['date'=>true], 'place_of_birth'=>['max'=>250], 'sex_at_birth'=>['options'=>['Male','Female']],
        'civil_status'=>['options'=>['Single','Married','Widowed','Separated','Other']], 'civil_status_other'=>['max'=>100],
        'height'=>['decimal'=>true,'max_value'=>3.5], 'weight'=>['decimal'=>true,'max_value'=>1000],
        'blood_type'=>['options'=>['A+','A-','B+','B-','AB+','AB-','O+','O-','Unknown']],
        'citizenship'=>['options'=>['Filipino','Dual Citizenship']],
        'citizenship_basis'=>['options'=>['By birth','By naturalization']], 'citizenship_country'=>['max'=>100],
    ];
    if ($section==='family') return synk_hr_pds_family_fields();
    if ($section==='education') return [];
    if (in_array($section,['eligibility','work','learning','voluntary','other','declarations','certification'],true)) return synk_hr_pds_remaining_fields($section);
    if ($section !== 'contact') throw new InvalidArgumentException('This PDS section is not available for saving yet.');
    $fields = [];
    foreach (['residential','permanent'] as $kind) {
        foreach (['house','street','village','barangay','city','province','zip'] as $part) $fields[$kind.'_'.$part] = ['max'=>$part==='zip'?20:250];
    }
    $fields += ['same_address'=>['boolean'=>true], 'telephone'=>['max'=>50], 'mobile'=>['max'=>50], 'email'=>['max'=>254,'email'=>true]];
    foreach (['umid','pagibig','philhealth','philsys_pcn','tin','agency_employee_number'] as $key) $fields[$key] = ['max'=>64];
    return $fields;
}

function synk_hr_pds_name_defaults(string $fullName, ?array $faculty = null): array
{
    $normalize = static function (string $name): string { return trim(preg_replace('/\s+/u',' ',$name) ?? $name); };
    $name = $normalize($fullName);
    $empty = ['surname'=>'','first_name'=>'','middle_name'=>'','name_extension'=>''];
    if ($faculty && !empty($faculty['last_name']) && !empty($faculty['first_name'])) {
        $structured = $normalize(implode(' ',array_filter([
            $faculty['first_name'],$faculty['middle_name'] ?? '',$faculty['last_name'],$faculty['ext_name'] ?? '',
        ],static function ($value): bool { return trim((string)$value)!==''; })));
        if ($name === '' || strcasecmp($name,$structured)===0) return [
            'surname'=>$normalize((string)$faculty['last_name']), 'first_name'=>$normalize((string)$faculty['first_name']),
            'middle_name'=>$normalize((string)($faculty['middle_name'] ?? '')), 'name_extension'=>$normalize((string)($faculty['ext_name'] ?? '')),
        ];
    }
    if ($name==='') return $empty;
    $suffix = '';
    if (preg_match('/(?:[\s,]+)(Jr\.?|Sr\.?|II|III|IV|V|VI|VII|VIII|IX|X)$/iu',$name,$match)) {
        $suffix = $match[1]; $name = trim(substr($name,0,-strlen($match[0])));
    }
    if (strpos($name,',')!==false) {
        [$surname,$given] = array_map('trim',explode(',',$name,2));
        $parts = preg_split('/\s+/u',$given,-1,PREG_SPLIT_NO_EMPTY) ?: [];
        $middle = count($parts)>1 ? array_pop($parts) : '';
        return ['surname'=>$surname,'first_name'=>implode(' ',$parts),'middle_name'=>$middle,'name_extension'=>$suffix];
    }
    $parts = preg_split('/\s+/u',$name,-1,PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($parts)===1) return ['surname'=>'','first_name'=>$parts[0],'middle_name'=>'','name_extension'=>$suffix];
    $surname = [array_pop($parts)];
    $particles = ['de','del','dela','la','las','los','da','dos','san','santa','van','von','bin','al'];
    while (count($parts)>1 && in_array(strtolower((string)end($parts)),$particles,true)) array_unshift($surname,array_pop($parts));
    $middle = count($parts)>1 ? array_pop($parts) : '';
    return ['surname'=>implode(' ',$surname),'first_name'=>implode(' ',$parts),'middle_name'=>$middle,'name_extension'=>$suffix];
}

function synk_hr_pds_validate(string $section, $input, DateTimeImmutable $today): array
{
    if (synk_hr_pds_record_lists($section)) return synk_hr_pds_validate_records($section,$input,$today);
    [$data,$errors]=synk_hr_pds_validate_fields(synk_hr_pds_fields($section),$input,$today);
    if (!$data) return [$data,$errors];
    if ($section==='personal') {
        if (($data['civil_status'] ?? '')!=='Other') { $data['civil_status_other']=''; unset($errors['civil_status_other']); }
        if (($data['citizenship'] ?? '')!=='Dual Citizenship') {
            $data['citizenship_basis']=''; $data['citizenship_country']=''; unset($errors['citizenship_basis'],$errors['citizenship_country']);
        }
    }
    if ($section==='contact' && ($data['same_address'] ?? '')==='1') {
        foreach (['house','street','village','barangay','city','province','zip'] as $part) {
            $data['permanent_'.$part] = $data['residential_'.$part]; unset($errors['permanent_'.$part]);
        }
    }
    return synk_hr_pds_normalize_section($section,$data,$errors);
}

function synk_hr_pds_validate_fields(array $fields, $input, DateTimeImmutable $today): array
{
    $data=[]; $errors=[];
    if (!is_array($input) || array_diff(array_keys($input),array_keys($fields)) || array_diff(array_keys($fields),array_keys($input))) {
        return [[],['_section'=>'The section is incomplete or contains unsupported fields. Reload before saving.']];
    }
    foreach ($fields as $key=>$rule) {
        $value = $input[$key];
        if (!empty($rule['boolean'])) {
            if (!in_array($value,['0','1',0,1,false,true],true)) $errors[$key] = 'Choose a valid checkbox option.';
            $data[$key] = in_array($value,['1',1,true],true)?'1':'0'; continue;
        }
        if (!is_string($value)) { $errors[$key] = 'Enter a valid value.'; $data[$key] = ''; continue; }
        $value = trim($value); $data[$key] = $value;
        $controls=!empty($rule['multiline'])?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u':'/[\x00-\x1f\x7f]/u';
        if (preg_match($controls,$value) || !preg_match('//u',$value)) { $errors[$key] = 'Use ordinary text without control characters.'; continue; }
        $length = function_exists('mb_strlen') ? mb_strlen($value,'UTF-8') : preg_match_all('/./us',$value);
        if (isset($rule['max']) && $length>$rule['max']) $errors[$key] = 'Use at most '.$rule['max'].' characters.';
        if ($value==='' || isset($errors[$key])) continue;
        if (isset($rule['options']) && !in_array($value,$rule['options'],true)) $errors[$key] = 'Choose an option from the list.';
        if (!empty($rule['email']) && !filter_var($value,FILTER_VALIDATE_EMAIL)) $errors[$key] = 'Enter a valid contact email address.';
        if (!empty($rule['work_date'])) {
            if (preg_match('/^[0-9]{4}$/D', $value) && $value >= '1900' && $value <= $today->format('Y')) continue;
            $format = strpos($value, '/') !== false ? 'd/m/Y' : 'Y-m-d';
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date && $date->format($format) === $value && $date->format('Y-m-d') >= '1900-01-01' && $date <= $today) {
                $data[$key] = $date->format('Y-m-d');
            } else {
                $errors[$key] = 'Enter a valid date as dd/mm/yyyy or a four-digit year, from 1900 through today.';
            }
        }
        if (!empty($rule['date'])) {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            if (!$date || $date->format('Y-m-d')!==$value || $value<'1900-01-01' || $value>'9999-12-31' || (empty($rule['future']) && $date>$today)) $errors[$key] = !empty($rule['future'])?'Enter a real date from 1900 onward.':'Enter a real date between 1900 and today.';
        }
        if (!empty($rule['year']) && (!preg_match('/^[0-9]{4}$/D',$value) || (int)$value<1900 || (int)$value>(int)$today->format('Y'))) $errors[$key]='Enter a year between 1900 and the current year, or leave it empty.';
        if (!empty($rule['decimal'])) {
            if (!preg_match('/^\d{1,4}(?:\.\d{1,2})?$/D',$value) || (float)$value<=0 || (float)$value>$rule['max_value']) $errors[$key] = 'Enter a positive measurement up to '.$rule['max_value'].' with at most two decimal places.';
            else $data[$key] = number_format((float)$value,2,'.','');
        }
        if (!empty($rule['amount'])) {
            if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',$value) || (float)$value>$rule['max_value']) $errors[$key]='Enter zero or a positive number up to '.$rule['max_value'].' with at most two decimal places.';
            else $data[$key]=number_format((float)$value,2,'.','');
        }
    }
    return [$data,$errors];
}

function synk_hr_pds_ensure(mysqli $conn): void
{
    $ready = true;
    $tables=['tbl_hr_faculty_profiles','tbl_hr_faculty_pds_attachments'];
    foreach (synk_hr_pds_sections() as $section) {
        $tables[]=synk_hr_pds_section_table($section);
        foreach (synk_hr_pds_record_lists($section) as $list) $tables[]=$list['table'];
    }
    foreach ($tables as $table) {
        $statement = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $statement->bind_param('s',$table); $statement->execute(); $ready = $statement->get_result()->num_rows>0 && $ready; $statement->close();
    }
    foreach ($ready ? [] : ['hr_faculty_pds.sql','hr_faculty_pds_remaining.sql'] as $file) {
        $sql=file_get_contents(__DIR__.'/sql/'.$file);
        if ($sql===false) throw new RuntimeException('HR PDS storage could not be prepared.');
        foreach (explode(';',$sql) as $query) if (trim($query)!=='') $conn->query($query);
    }
    synk_hr_pds_ensure_work_dates($conn);
}

function synk_hr_pds_ensure_work_dates(mysqli $conn): void
{
    $result = $conn->query("SELECT 1 FROM information_schema.columns
        WHERE table_schema=DATABASE() AND table_name='tbl_hr_faculty_pds_work_records'
        AND column_name IN ('date_from','date_to') AND data_type='date'");
    $needsUpgrade = $result->num_rows > 0;
    $result->close();
    if ($needsUpgrade) {
        $conn->query('ALTER TABLE tbl_hr_faculty_pds_work_records
            MODIFY date_from VARCHAR(10) NULL, MODIFY date_to VARCHAR(10) NULL');
    }
}

function synk_hr_pds_owner(int $userId, int $facultyId): string
{
    return $facultyId>0 ? 'faculty:'.$facultyId : 'account:'.$userId;
}

function synk_hr_pds_profile(mysqli $conn, int $userId, int $facultyId, bool $lock = false): ?array
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $statement = $conn->prepare('SELECT * FROM tbl_hr_faculty_profiles WHERE account_user_id=? AND faculty_id IS NULL'.$suffix);
    $statement->bind_param('i',$userId); $statement->execute(); $pending=$statement->get_result()->fetch_assoc(); $statement->close();
    if ($facultyId<=0) return $pending;
    $statement = $conn->prepare('SELECT * FROM tbl_hr_faculty_profiles WHERE faculty_id=?'.$suffix);
    $statement->bind_param('i',$facultyId); $statement->execute(); $linked=$statement->get_result()->fetch_assoc(); $statement->close();
    if ($pending && $linked) throw new DomainException('Two PDS records need an administrator review before they can be connected.');
    return $linked ?: $pending;
}

function synk_hr_pds_section(mysqli $conn, int $profileId, string $section): ?array
{
    $fields = synk_hr_pds_fields($section);
    $table = synk_hr_pds_section_table($section);
    $statement = $conn->prepare('SELECT * FROM '.$table.' WHERE hr_profile_id=?');
    $statement->bind_param('i',$profileId); $statement->execute(); $row=$statement->get_result()->fetch_assoc(); $statement->close();
    if (!$row) return null;
    $values = [];
    foreach ($fields as $key=>$rule) $values[$key] = $row[$key]===null ? '' : (string)$row[$key];
    $values+=synk_hr_pds_load_records($conn,$profileId,$section);
    $state=['values'=>$values,'revision'=>(int)$row['revision'],'updated_at'=>$row['updated_at']];
    if ($section==='certification') $state['attachments']=synk_hr_pds_attachments($conn,$profileId);
    return $state;
}

function synk_hr_pds_load(mysqli $conn, int $userId, int $facultyId): array
{
    $profile = synk_hr_pds_profile($conn,$userId,$facultyId); $state=[];
    foreach (synk_hr_pds_sections() as $section) $state[$section] = $profile
        ? synk_hr_pds_section($conn,(int)$profile['hr_profile_id'],$section) : null;
    return $state;
}

// Caller starts the transaction. Lock account and faculty link before resolving the HR owner.
function synk_hr_pds_save(mysqli $conn, int $userId, string $postedOwner, string $section, array $values, int $revision, array $files=[], array $removed=[]): array
{
    if ($userId<=0 || $revision<0) throw new InvalidArgumentException('Invalid PDS version.');
    $statement = $conn->prepare("SELECT user_id FROM tbl_useraccount WHERE user_id=? AND status='active' FOR UPDATE");
    $statement->bind_param('i',$userId); $statement->execute(); $active=$statement->get_result()->num_rows===1; $statement->close();
    if (!$active) throw new DomainException('Your account is unavailable. Sign in again before saving.');
    $facultyId = 0;
    $statement = $conn->prepare('SELECT faculty_id FROM tbl_useraccount_faculty_links WHERE user_id=? FOR UPDATE');
    $statement->bind_param('i',$userId); $statement->execute(); $link=$statement->get_result()->fetch_assoc(); $statement->close();
    if ($link) $facultyId=(int)$link['faculty_id'];
    if (!hash_equals(synk_hr_pds_owner($userId,$facultyId),$postedOwner)) throw new DomainException('Your faculty connection changed. Reload this page before saving.');
    $profile = synk_hr_pds_profile($conn,$userId,$facultyId,true);
    $profileId = synk_hr_pds_prepare_profile($conn,$profile,$userId,$facultyId);
    return synk_hr_pds_save_section($conn,$profileId,$userId,$section,$values,$revision,$files,$removed);
}

// The caller resolves and locks the owner before preparing its profile.
function synk_hr_pds_prepare_profile(mysqli $conn, ?array $profile, int $userId, int $facultyId): int
{
    if (!$profile) {
        $faculty = $facultyId ?: null; $account = $facultyId>0 ? null : $userId;
        $statement = $conn->prepare('INSERT INTO tbl_hr_faculty_profiles (faculty_id,account_user_id) VALUES (?,?)');
        $statement->bind_param('ii',$faculty,$account); $statement->execute(); $profileId=(int)$conn->insert_id; $statement->close();
    } else {
        $profileId=(int)$profile['hr_profile_id'];
        if ($facultyId>0 && $profile['faculty_id']===null) {
            $statement=$conn->prepare('UPDATE tbl_hr_faculty_profiles SET faculty_id=?,account_user_id=NULL WHERE hr_profile_id=?');
            $statement->bind_param('ii',$facultyId,$profileId); $statement->execute(); $statement->close();
        }
    }
    return $profileId;
}

function synk_hr_pds_save_section(mysqli $conn, int $profileId, int $userId, string $section, array $values, int $revision, array $files=[], array $removed=[]): array
{
    $fields = synk_hr_pds_fields($section);
    $existing = synk_hr_pds_section($conn,$profileId,$section);
    if (($existing['revision'] ?? 0)!==$revision) throw new DomainException('This section was saved in another window. Your entries are still here. Reload to see the latest saved version.');
    // Validate again at the persistence boundary, including non-HTTP callers.
    [$data,$errors] = synk_hr_pds_validate($section,$values,new DateTimeImmutable('today'));
    if ($errors) throw new InvalidArgumentException('PDS fields are invalid.');
    // One faculty-entered date is authoritative for every printed PDS page.
    if ($section==='certification') for ($page=1;$page<=4;$page++) {
        $fields['signature_date_'.$page]=['date'=>true];
        $data['signature_date_'.$page]=$data['date_accomplished'];
    }
    $parameters=[];
    foreach ($fields as $key=>$rule) $parameters[] = ($data[$key]==='' && (!empty($rule['date']) || !empty($rule['decimal']) || !empty($rule['amount']))) ? null : $data[$key];
    $table = synk_hr_pds_section_table($section);
    $fieldColumns=$fields?'`'.implode('`,`',array_keys($fields)).'`,':'';
    if (!$existing) {
        $query = 'INSERT INTO '.$table.' ('.$fieldColumns.'hr_profile_id,updated_by) VALUES ('.implode(',',array_fill(0,count($fields)+2,'?')).')';
        $parameters[]=$profileId; $parameters[]=$userId; $types=str_repeat('s',count($fields)).'ii';
    } else {
        $assignments=$fields?'`'.implode('`=?,`',array_keys($fields)).'`=?,':'';
        $query = 'UPDATE '.$table.' SET '.$assignments.'revision=revision+1,updated_at=CURRENT_TIMESTAMP,updated_by=? WHERE hr_profile_id=? AND revision=?';
        $parameters[]=$userId; $parameters[]=$profileId; $parameters[]=$revision; $types=str_repeat('s',count($fields)).'iii';
    }
    $statement=$conn->prepare($query); $statement->bind_param($types,...$parameters); $statement->execute();
    if ($statement->affected_rows!==1) { $statement->close(); throw new DomainException('The section changed while saving. Reload before trying again.'); }
    $statement->close();
    synk_hr_pds_save_records($conn,$profileId,$section,$data);
    if ($section==='certification') synk_hr_pds_save_attachments($conn,$profileId,$userId,$files,$removed);
    return synk_hr_pds_section($conn,$profileId,$section);
}
