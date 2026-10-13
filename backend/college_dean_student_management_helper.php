<?php
require_once __DIR__.'/college_dean_student_email_helper.php';

function synk_dean_student_profile_version(array $student): string
{
    $values=[];
    foreach (['student_id','student_number','first_name','last_name','middle_name','suffix_name','email_address','program_id','ay_id','semester','year_level','source_sheet_name','updated_at'] as $field) $values[]=(string)($student[$field] ?? '');
    return hash('sha256',json_encode($values));
}

function synk_dean_student_manage_options(mysqli $conn, array $scope): array
{
    $programs=synk_dean_students_query($conn,'SELECT program_id,program_code,program_name,major FROM tbl_program WHERE college_id=? ORDER BY program_code,program_id','i',[$scope['college_id']]);
    foreach ($programs as &$row) { $row['program_id']=(int)$row['program_id']; $row['label']=($row['program_code'] ?: $row['program_name']).(trim((string)$row['major'])!=='' ? ' - '.$row['major'] : ''); }
    return ['programs'=>$programs,'academic_years'=>$conn->query('SELECT ay_id,ay FROM tbl_academic_years ORDER BY ay DESC')->fetch_all(MYSQLI_ASSOC),
        'current_term'=>synk_fetch_current_academic_term($conn)];
}

function synk_dean_student_manage_record(mysqli $conn, array $scope, int $id): array
{
    $source=synk_dean_students_source($scope,[]);
    $rows=synk_dean_students_query($conn,"SELECT DISTINCT s.* {$source['sql']} AND s.student_id=?",$source['types'].'i',array_merge($source['params'],[$id]));
    if (!$rows) throw new DomainException('This student is outside your college directory or has been removed.');
    $student=$rows[0]; $student['student_id']=(int)$student['student_id'];
    $student['version']=synk_dean_student_profile_version($student);
    $student['student_name']=synk_dean_students_name($student);
    return $student;
}

function synk_dean_student_manage_names(array $input): array
{
    $record=[];
    foreach (['first_name'=>150,'last_name'=>150,'middle_name'=>150,'suffix_name'=>50] as $field=>$limit) {
        $value=synk_student_identity_utf8((string)($input[$field] ?? ''));
        $value=trim((string)preg_replace('/\s+/u',' ',$value));
        $length=function_exists('mb_strlen') ? mb_strlen($value,'UTF-8') : preg_match_all('/./us',$value);
        $label=ucfirst(str_replace('_',' ',$field));
        if ($length>$limit || ($value!=='' && !preg_match('/^[\p{L}\p{M}\s.\x{0027}\x{2019}-]+$/u',$value))) throw new DomainException($label.' must contain valid letters and be no longer than '.$limit.' characters. Correct damaged letters such as ? first.');
        if (in_array($field,['first_name','last_name'],true) && !preg_match('/\p{L}/u',$value)) throw new DomainException($label.' is required and must include letters.');
        $record[$field]=$value;
    }
    return $record;
}

function synk_dean_student_manage_portal_email(mysqli $conn, array $before, string $email): void
{
    if ($email==='' || $email===(string)$before['email_address'] || !synk_table_exists($conn,'tbl_student_portal_profile')) return;
    $profiles=synk_dean_students_query($conn,'SELECT * FROM tbl_student_portal_profile WHERE email_address IN (?,?) OR student_number=? ORDER BY profile_id FOR UPDATE','sss',[$before['email_address'],$email,$before['student_number']]);
    $own=[];
    foreach ($profiles as $profile) {
        if ($profile['student_number']===$before['student_number'] || ($before['email_address']!=='' && $profile['email_address']===$before['email_address'])) {
            if ($profile['student_number']!=='' && $profile['student_number']!==$before['student_number']) throw new DomainException('The existing login profile has a different student ID. Verify the account with an administrator before changing its email.');
            $own[]=$profile;
        } elseif (strtolower($profile['email_address'])===$email) throw new DomainException('That email is already associated with another student login profile.');
    }
    if (count($own)>1) throw new DomainException('Multiple login profiles use this student ID. Ask an administrator to verify the account before changing its email.');
    if ($own) {
        $stmt=$conn->prepare('UPDATE tbl_student_portal_profile SET email_address=? WHERE profile_id=?');
        $id=(int)$own[0]['profile_id']; $stmt->bind_param('si',$email,$id);
        if (!$stmt->execute()) throw new RuntimeException('Unable to update the student login profile.');
        $stmt->close();
    }
}

function synk_dean_student_manage_save(mysqli $conn, array $scope, array $input): array
{
    $names=synk_dean_student_manage_names($input);
    $number=synk_student_identity_number($input['student_number'] ?? '');
    $email=strtolower(trim((string)($input['email_address'] ?? '')));
    if ($email!=='' && (strlen($email)>255 || !filter_var($email,FILTER_VALIDATE_EMAIL) || substr($email,-12)!=='@sksu.edu.ph')) throw new DomainException('Enter a valid school email ending in @sksu.edu.ph, or leave it blank until verified.');
    $id=filter_var($input['student_id'] ?? 0,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
    if ($id===false) throw new DomainException('Select a valid student.');
    synk_student_email_lock($conn); $transaction=false;
    try {
        if (!$conn->begin_transaction()) throw new RuntimeException('Unable to begin student update.');
        $transaction=true; $catalog=synk_student_email_catalog($conn,true);
        $fresh=synk_college_dean_dashboard_scope($conn);
        if (!$fresh || $fresh['college_id']!==$scope['college_id'] || $fresh['campus_id']!==$scope['campus_id']) throw new DomainException('Your college access changed. Reload the page.');
        foreach ($catalog['students'] as $otherId=>$other) if ($email!=='' && $otherId!==$id && strtolower(trim((string)$other['email_address']))===$email) throw new DomainException('That school email already belongs to another student. Verify the actual school address.');
        if ($id) {
            $before=synk_dean_student_manage_record($conn,$fresh,$id);
            if ($number!==$before['student_number']) throw new DomainException('The student ID identifies the shared profile and cannot be changed here.');
            if (!is_string($input['version'] ?? null) || !hash_equals($before['version'],$input['version'])) throw new DomainException('This student was changed by another request. Reopen Edit Student before saving.');
            synk_dean_student_manage_portal_email($conn,$before,$email);
            $stmt=$conn->prepare('UPDATE tbl_student_management SET first_name=?,last_name=?,middle_name=?,suffix_name=?,email_address=? WHERE student_id=?');
            $params=[$names['first_name'],$names['last_name'],$names['middle_name'],$names['suffix_name'],$email,$id];
            synk_bind_dynamic_params($stmt,'sssssi',$params);
            if (!$stmt->execute()) throw new RuntimeException('Unable to update the student.');
            $stmt->close();
        } else {
            if (synk_student_identity_find($conn,$number,true)) throw new DomainException('This student ID already exists. Use Edit Student for a student in your directory.');
            $filters=synk_dean_students_filters(['term'=>$input['record_term'] ?? ''],$conn);
            if (!$filters['ay_id'] || !$filters['semester']) throw new DomainException('Choose an academic year and semester for the new student.');
            if (!synk_dean_students_query($conn,'SELECT ay_id FROM tbl_academic_years WHERE ay_id=?','i',[$filters['ay_id']])) throw new DomainException('Choose a valid academic year.');
            $program=filter_var($input['record_program_id'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $year=filter_var($input['year_level'] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>5]]);
            if (!$program || !$year || !synk_dean_students_query($conn,'SELECT program_id FROM tbl_program WHERE program_id=? AND college_id=?','ii',[$program,$fresh['college_id']])) throw new DomainException('Choose a program in your assigned college and a valid year level.');
            $record=$names+['student_number'=>$number,'email_address'=>$email,'ay_id'=>$filters['ay_id'],'semester'=>$filters['semester'],
                'program_id'=>$program,'year_level'=>$year,'source_sheet_name'=>'DEAN MANUAL','source_file_name'=>'Student Management',
                'source_row_number'=>0,'uploaded_by'=>(int)$_SESSION['user_id']];
            if (synk_table_exists($conn,'tbl_student_portal_profile') && synk_dean_students_query($conn,'SELECT profile_id FROM tbl_student_portal_profile WHERE student_number=? OR (email_address=? AND email_address<>\'\') FOR UPDATE','ss',[$number,$email])) throw new DomainException('This student ID or email already has a login profile. Ask an administrator to verify the existing account.');
            $id=synk_student_identity_insert_record($conn,$record);
        }
        if (!$conn->commit()) throw new RuntimeException('Unable to save the student.');
        $transaction=false;
        return ['student_id'=>$id,'message'=>'Student saved.'.($email==='' ? ' A verified school email is still needed for student sign-in.' : '')];
    } catch (Throwable $e) { if ($transaction) $conn->rollback(); throw $e; }
    finally { synk_student_email_unlock($conn); }
}

function synk_dean_students_selected_ids($input): array
{
    $ids=json_decode((string)$input,true);
    if (!is_array($ids) || array_values($ids)!==$ids || !$ids || count($ids)>5000) throw new DomainException('Select between 1 and 5,000 students.');
    $seen=[];
    foreach ($ids as $id) {
        if (!is_int($id) || $id<=0 || isset($seen[$id])) throw new DomainException('Select valid students without duplicate selections.');
        $seen[$id]=true;
    }
    sort($ids,SORT_NUMERIC); return $ids;
}

function synk_dean_students_selection(mysqli $conn, array $scope, array $filters): array
{
    $source=synk_dean_students_source($scope,$filters);
    $rows=synk_dean_students_query($conn,"SELECT DISTINCT s.student_id {$source['sql']} ORDER BY s.student_id LIMIT 5001",$source['types'],$source['params']);
    if (count($rows)>5000) throw new DomainException('Narrow the search, academic year, or program to select up to 5,000 students.');
    return array_map('intval',array_column($rows,'student_id'));
}

function synk_dean_students_delete_plan(mysqli $conn, array $scope, array $filters, array $ids, bool $lock=false): array
{
    if (!$filters['ay_id']) throw new DomainException('Choose a specific academic year or semester before deleting records.');
    $source=synk_dean_students_source($scope,$filters); $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $profiles=synk_dean_students_query($conn,"SELECT DISTINCT s.* {$source['sql']} AND s.student_id IN ({$placeholders}) ORDER BY s.student_id",$source['types'].str_repeat('i',count($ids)),array_merge($source['params'],$ids));
    if (array_map('intval',array_column($profiles,'student_id'))!==$ids) throw new DomainException('Some selected students no longer match your college and filters. Refresh the directory.');
    $rows=synk_dean_students_query($conn,"SELECT es.*,s.student_number,s.first_name,s.last_name,s.middle_name,s.suffix_name {$source['sql']} AND s.student_id IN ({$placeholders}) AND es.student_enrollment_id IS NOT NULL ORDER BY es.student_enrollment_id".($lock ? ' FOR UPDATE' : ''),$source['types'].str_repeat('i',count($ids)),array_merge($source['params'],$ids));
    if (count($rows)>50000) throw new DomainException('Select fewer students to delete up to 50,000 class-list records at a time.');
    $manual=[]; $versions=[]; $students=[]; $counts=[];
    foreach ($rows as $row) { $key=(int)$row['student_id']; $counts[$key]=($counts[$key] ?? 0)+1; }
    foreach ($profiles as $profile) {
        $id=(int)$profile['student_id']; $versions[$id]=synk_dean_student_profile_version($profile);
        if ($profile['source_sheet_name']==='DEAN MANUAL' && (int)$profile['ay_id']===$filters['ay_id'] && (!$filters['semester'] || (int)$profile['semester']===$filters['semester']) && (!$filters['program_id'] || (int)$profile['program_id']===$filters['program_id']) && !$filters['section_id']) $manual[]=$id;
        $students[]=['student_id'=>$id,'student_number'=>$profile['student_number'],'student_name'=>synk_dean_students_name($profile),'class_records'=>$counts[$id] ?? 0];
    }
    if (!$rows && !$manual) throw new DomainException('There are no matching student records to delete.');
    return ['students'=>$students,'rows'=>$rows,'manual'=>$manual,'versions'=>$versions];
}

function synk_dean_students_delete_preview(mysqli $conn, array $scope, array $filters, array $ids): array
{
    $plan=synk_dean_students_delete_plan($conn,$scope,$filters,$ids);
    $token=bin2hex(random_bytes(32));
    if (!isset($_SESSION['dean_student_delete_previews'])) $_SESSION['dean_student_delete_previews']=[];
    while (count($_SESSION['dean_student_delete_previews'])>=3) array_shift($_SESSION['dean_student_delete_previews']);
    $_SESSION['dean_student_delete_previews'][$token]=['user_id'=>(int)$_SESSION['user_id'],'college_id'=>$scope['college_id'],'campus_id'=>$scope['campus_id'],
        'expires_at'=>time()+900,'filters'=>$filters,'ids'=>$ids,'fingerprint'=>hash('sha256',json_encode($plan))];
    $classes=[];
    foreach ($plan['rows'] as $row) $classes[implode(':',[$row['program_id'],$row['ay_id'],$row['semester'],$row['section_id'],$row['subject_id']])]=true;
    return ['preview_token'=>$token,'students'=>$plan['students'],'summary'=>['students'=>count($ids),'class_records'=>count($plan['rows']),'classes'=>count($classes),'manual_records'=>count($plan['manual'])]];
}

function synk_dean_students_delete_save(mysqli $conn, array $scope, string $token): array
{
    $pending=$_SESSION['dean_student_delete_previews'][$token] ?? null;
    if (!$pending || $pending['expires_at']<time() || $pending['user_id']!==(int)$_SESSION['user_id'] || $pending['college_id']!==$scope['college_id'] || $pending['campus_id']!==$scope['campus_id']) throw new DomainException('The deletion preview expired or your college access changed. Preview again.');
    synk_dean_require_current_term_for_write($conn, $pending['filters']);
    synk_student_email_lock($conn); $transaction=false;
    try {
        if (!$conn->begin_transaction()) throw new RuntimeException('Unable to begin student deletion.');
        $transaction=true; synk_student_email_catalog($conn,true);
        $fresh=synk_college_dean_dashboard_scope($conn);
        if (!$fresh || $fresh['college_id']!==$scope['college_id'] || $fresh['campus_id']!==$scope['campus_id']) throw new DomainException('Your college access changed. Reload the page.');
        $plan=synk_dean_students_delete_plan($conn,$fresh,$pending['filters'],$pending['ids'],true);
        if (!hash_equals($pending['fingerprint'],hash('sha256',json_encode($plan)))) throw new DomainException('Student records changed after the preview. Nothing was deleted. Preview again.');
        $offeringIds=[];
        foreach ($plan['rows'] as $row) if ((int)$row['offering_id']>0) $offeringIds[(int)$row['offering_id']]=true;
        foreach (array_chunk(array_column($plan['rows'],'student_enrollment_id'),500) as $chunk) {
            $stmt=$conn->prepare('UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE is_active=1 AND student_enrollment_id IN ('.implode(',',array_fill(0,count($chunk),'?')).')');
            synk_bind_dynamic_params($stmt,str_repeat('i',count($chunk)),$chunk);
            if (!$stmt->execute() || $stmt->affected_rows!==count($chunk)) throw new RuntimeException('Unable to remove the selected class-list records.');
            $stmt->close();
        }
        foreach ($plan['manual'] as $id) {
            $stmt=$conn->prepare("UPDATE tbl_student_management SET source_sheet_name='DEAN MANUAL REMOVED' WHERE student_id=? AND source_sheet_name='DEAN MANUAL'");
            $stmt->bind_param('i',$id); if (!$stmt->execute()) throw new RuntimeException('Unable to remove the manual directory record.'); $stmt->close();
        }
        // Refresh only counts derived from imported rosters; scheduling calculations and assignments are untouched.
        foreach (array_keys($offeringIds) as $offeringId) {
            $stmt=$conn->prepare("UPDATE tbl_offering_enrollee_counts SET total_enrollees=(SELECT COUNT(DISTINCT es.student_id) FROM tbl_student_management_enrolled_subjects es WHERE es.offering_id=? AND es.is_active=1),updated_by=? WHERE offering_id=? AND source_kind='csv_roster'");
            $userId=(int)$_SESSION['user_id']; $stmt->bind_param('iii',$offeringId,$userId,$offeringId); if (!$stmt->execute()) throw new RuntimeException('Unable to refresh imported student counts.'); $stmt->close();
        }
        if (!$conn->commit()) throw new RuntimeException('Unable to complete student deletion.');
        $transaction=false; unset($_SESSION['dean_student_delete_previews'][$token]);
        return ['removed'=>count($plan['rows']),'manual_removed'=>count($plan['manual']),'message'=>count($plan['rows']).' class-list records and '.count($plan['manual']).' manual directory records removed for the selected academic term. Shared student profiles and other terms were kept.'];
    } catch (Throwable $e) { if ($transaction) $conn->rollback(); unset($_SESSION['dean_student_delete_previews'][$token]); throw $e; }
    finally { synk_student_email_unlock($conn); }
}
