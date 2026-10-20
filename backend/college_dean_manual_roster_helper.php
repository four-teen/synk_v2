<?php
require_once __DIR__.'/college_dean_workload_helper.php';
require_once __DIR__.'/college_dean_student_management_helper.php';
require_once __DIR__.'/student_class_roster_audit_helper.php';

function synk_dean_manual_student(mysqli $conn,array $class,string $number): array
{
    $number=synk_student_identity_number($number); $student=synk_student_identity_find($conn,$number);
    $programs=synk_dean_students_query($conn,'SELECT program_id,program_code,program_name,major FROM tbl_program WHERE college_id=? ORDER BY program_code,major,program_id','i',[$class['college_id']]);
    foreach ($programs as &$row) $row=['program_id'=>(int)$row['program_id'],'label'=>($row['program_code'] ?: $row['program_name']).(trim((string)$row['major'])!==''?' - '.$row['major']:'')];
    unset($row);
    // Exact-ID lookup supports transferees without exposing another college's email/contact details.
    return ['found'=>(bool)$student,'student'=>$student?['student_number'=>$number,'student_name'=>synk_dean_students_name($student),'version'=>synk_dean_student_profile_version($student)]:null,
        'programs'=>$programs,'reasons'=>synk_roster_manual_reasons()];
}

function synk_dean_manual_input(array $input): array
{
    $number=synk_student_identity_number($input['student_number'] ?? '');
    $mode=(string)($input['student_mode'] ?? '');
    if (!in_array($mode,['existing','new'],true)) throw new DomainException('Look up the student ID before reviewing the addition.');
    $reason=(string)($input['reason_code'] ?? '');
    if (!isset(synk_roster_manual_reasons()[$reason])) throw new DomainException('Choose a reason for adding this student.');
    $note=trim(synk_student_identity_utf8((string)($input['reason_note'] ?? '')));
    if (preg_match_all('/./us',$note,$unused)>500 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u',$note)) throw new DomainException('Use up to 500 valid characters for the note.');
    $values=['student_number'=>$number,'student_mode'=>$mode,'reason_code'=>$reason,'reason_note'=>$note,'profile_version'=>(string)($input['profile_version'] ?? '')];
    if ($mode==='new') {
        $values+=synk_dean_student_manage_names($input);
        $email=strtolower(trim((string)($input['email_address'] ?? '')));
        if ($email!=='' && (strlen($email)>254 || !filter_var($email,FILTER_VALIDATE_EMAIL) || substr($email,-12)!=='@sksu.edu.ph')) throw new DomainException('Enter a valid @sksu.edu.ph email, or leave it blank pending verification.');
        $values['email_address']=$email;
        foreach (['student_program_id','student_year_level'] as $field) {
            $value=filter_var($input[$field] ?? '',FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$value || ($field==='student_year_level' && $value>5)) throw new DomainException('Choose the student program and year level.');
            $values[$field]=$value;
        }
    }
    return $values;
}

function synk_dean_manual_plan(mysqli $conn,array $class,array $values,bool $lock=false): array
{
    $student=synk_student_identity_find($conn,$values['student_number'],$lock);
    if ($values['student_mode']==='existing') {
        if (!$student || !hash_equals(synk_dean_student_profile_version($student),$values['profile_version'])) throw new DomainException('The student profile changed. Look up the ID again.');
    } else {
        if ($student) throw new DomainException('This student ID already exists. Look it up and use its existing profile.');
        $programs=synk_dean_students_query($conn,'SELECT program_id,program_code,program_name,major FROM tbl_program WHERE program_id=? AND college_id=?','ii',[$values['student_program_id'],$class['college_id']]);
        if (!$programs) throw new DomainException('Choose a student program within your assigned college.');
        $email=$values['email_address'];
        if ($email!=='' && synk_dean_students_query($conn,'SELECT student_id FROM tbl_student_management WHERE LOWER(TRIM(email_address))=?','s',[$email])) throw new DomainException('That school email already belongs to another student.');
        if (synk_table_exists($conn,'tbl_student_portal_profile') && synk_dean_students_query($conn,"SELECT profile_id FROM tbl_student_portal_profile WHERE student_number=? OR (email_address=? AND email_address<>'')",'ss',[$values['student_number'],$email])) throw new DomainException('This ID or email already has a login profile. Ask an administrator to verify the identity.');
        $student=$values+['student_id'=>0,'year_level'=>$values['student_year_level'],'program_id'=>$values['student_program_id']];
    }
    $previous=[];
    if ($student['student_id']) {
        $previous=synk_dean_students_query($conn,"SELECT es.*,CONCAT_WS(' ',f.first_name,f.middle_name,f.last_name) AS previous_faculty_name
            FROM tbl_student_management_enrolled_subjects es LEFT JOIN tbl_faculty f ON f.faculty_id=es.faculty_id
            WHERE es.student_id=? AND es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=? AND es.subject_id=? AND es.is_active=1
            ORDER BY es.student_enrollment_id".($lock?' FOR UPDATE':''),'iiiiii',[(int)$student['student_id'],$class['college_id'],$class['campus_id'],$class['ay_id'],$class['semester'],$class['subject_id']]);
        foreach ($previous as $row) {
            if ((int)$row['offering_id']===$class['offering_id'] || ((int)$row['program_id']===$class['program_id'] && (int)$row['section_id']===$class['section_id'])) throw new DomainException('This student is already listed in this class.');
        }
    }
    $target=array_intersect_key($class,array_flip(['offering_id','college_id','campus_id','ay_id','semester','program_id','section_id','subject_id','subject_code','descriptive_title','section_display','faculty_id','faculty_name','year_level','schedules']));
    $fingerprint=hash('sha256',json_encode([$target,$values,$student['student_id']?synk_dean_student_profile_version($student):$student,$previous]));
    $old=[];
    foreach ($previous as $row) $old[]=['student_enrollment_id'=>(int)$row['student_enrollment_id'],'offering_id'=>(int)$row['offering_id'],
        'section'=>trim((string)$row['section_text']) ?: 'Section '.$row['section_id'],'subject_code'=>$row['subject_code'],
        'faculty_name'=>$row['previous_faculty_name'] ?: 'Not recorded'];
    return ['student'=>$student,'previous'=>$previous,'fingerprint'=>$fingerprint,'review'=>[
        'student_number'=>$values['student_number'],'student_name'=>synk_dean_students_name($student),'new_profile'=>$values['student_mode']==='new',
        'email_address'=>$values['student_mode']==='new'?$values['email_address']:null,'target'=>$target,
        'student_program'=>$values['student_mode']==='new'?($programs[0]['program_code'] ?: $programs[0]['program_name']).(trim((string)$programs[0]['major'])!==''?' - '.$programs[0]['major']:''):null,
        'student_year_level'=>$values['student_mode']==='new'?$values['student_year_level']:null,
        'action'=>$previous?'transfer':'add','previous_classes'=>$old,'reason'=>synk_roster_manual_reasons()[$values['reason_code']],'note'=>$values['reason_note']]];
}

function synk_dean_manual_preview(mysqli $conn,array $class,array $input): array
{
    $values=synk_dean_manual_input($input); $plan=synk_dean_manual_plan($conn,$class,$values);
    $pending=(array)($_SESSION['dean_manual_roster_previews'] ?? []);
    foreach ($pending as $key=>$row) if ($row['expires_at']<time()) unset($pending[$key]);
    while (count($pending)>=3) array_shift($pending);
    $token=bin2hex(random_bytes(32));
    $pending[$token]=['user_id'=>(int)$_SESSION['user_id'],'college_id'=>$class['college_id'],'campus_id'=>$class['campus_id'],
        'ay_id'=>$class['ay_id'],'semester'=>$class['semester'],'faculty_id'=>$class['faculty_id'],'offering_id'=>$class['offering_id'],
        'values'=>$values,'fingerprint'=>$plan['fingerprint'],'expires_at'=>time()+900];
    $_SESSION['dean_manual_roster_previews']=$pending;
    return ['preview_token'=>$token,'review'=>$plan['review']];
}

function synk_dean_manual_count(mysqli $conn,array $class,int $userId): void
{
    $total=count(synk_dean_roster_rows($conn,$class));
    $stmt=$conn->prepare("INSERT INTO tbl_offering_enrollee_counts (offering_id,total_enrollees,source_kind,created_by,updated_by)
        VALUES (?,?,'csv_roster',?,?) ON DUPLICATE KEY UPDATE total_enrollees=IF(source_kind='csv_roster',VALUES(total_enrollees),total_enrollees),
        updated_by=IF(source_kind='csv_roster',VALUES(updated_by),updated_by)");
    $stmt->bind_param('iiii',$class['offering_id'],$total,$userId,$userId);
    try { synk_dean_roster_execute($stmt); } finally { $stmt->close(); }
}

function synk_dean_manual_save(mysqli $conn,array $scope,array $term,array $pending,string $token,bool $transferConfirmed): array
{
    $userId=(int)$_SESSION['user_id'];
    if ($pending['user_id']!==$userId || $pending['college_id']!==$scope['college_id'] || $pending['campus_id']!==$scope['campus_id']
        || $pending['ay_id']!==$term['ay_id'] || $pending['semester']!==$term['semester'] || $pending['expires_at']<time()) throw new DomainException('This review expired or your access changed. Review the addition again.');
    $row=$conn->query("SELECT GET_LOCK('synk_college_dean_roster_import',5) AS acquired")->fetch_assoc();
    if ((int)$row['acquired']!==1) throw new DomainException('Another class-list update is being saved. Please try again.');
    $transaction=false; $emailLocked=false;
    try {
        synk_student_email_lock($conn); $emailLocked=true;
        if (!$conn->begin_transaction()) throw new RuntimeException('Unable to begin the class-list update.');
        $transaction=true; synk_student_email_catalog($conn,true);
        $fresh=synk_college_dean_dashboard_scope($conn);
        synk_dean_require_current_term_for_write($conn,['ay_id'=>$pending['ay_id'],'semester'=>$pending['semester']]);
        if (($_SESSION['role'] ?? '')!=='college_dean' || !$fresh || $fresh['college_id']!==$scope['college_id'] || $fresh['campus_id']!==$scope['campus_id']) throw new DomainException('Your college access changed. Reload the page.');
        $class=synk_dean_workload_class($conn,$fresh,$term,$pending['faculty_id'],$pending['offering_id']);
        synk_dean_students_query($conn,'SELECT offering_id FROM tbl_prospectus_offering WHERE offering_id=? FOR UPDATE','i',[$class['offering_id']]);
        $plan=synk_dean_manual_plan($conn,$class,$pending['values'],true);
        if (!hash_equals($pending['fingerprint'],$plan['fingerprint'])) throw new DomainException('The student, class or section membership changed after review. Review again before saving.');
        if ($plan['previous'] && !$transferConfirmed) throw new DomainException('Confirm the section transfer before saving.');
        $values=$pending['values']; $student=$plan['student'];
        if (!$student['student_id']) {
            $student['student_id']=synk_student_identity_insert_record($conn,$values+['ay_id'=>$class['ay_id'],'semester'=>$class['semester'],
                'source_sheet_name'=>'DEAN MANUAL','source_file_name'=>'Manual class addition','year_level'=>$values['student_year_level'],
                'program_id'=>$values['student_program_id'],'uploaded_by'=>$userId,'source_row_number'=>0]);
        }
        foreach ($plan['previous'] as $old) {
            $stmt=$conn->prepare('UPDATE tbl_student_management_enrolled_subjects SET is_active=0 WHERE student_enrollment_id=? AND is_active=1');
            $id=(int)$old['student_enrollment_id']; $stmt->bind_param('i',$id);
            try { synk_dean_roster_execute($stmt); if ($stmt->affected_rows!==1) throw new RuntimeException('The previous membership changed.'); } finally { $stmt->close(); }
        }
        $schedule=[]; $rooms=[];
        foreach ($class['schedules'] as $part) { $schedule[]=$part['type'].' '.$part['days'].' '.substr($part['time_start'],0,5).'-'.substr($part['time_end'],0,5); if ($part['room']!=='') $rooms[$part['room']]=true; }
        $clip=static function(string $value):string { preg_match('/^.{0,255}/us',$value,$match); return $match[0] ?? ''; };
        $batch='dean-manual-'.bin2hex(random_bytes(24));
        $params=[$batch,'Manual class addition',$class['campus_id'],$class['college_id'],$class['program_id'],$class['ay_id'],$class['semester'],$class['year_level'],
            $class['section_id'],$class['section_display'],$class['offering_id'],$class['subject_id'],$class['subject_code'],$class['descriptive_title'],(int)$student['student_id'],$class['faculty_id'],
            count($rooms)===1?(int)$class['schedules'][0]['room_id']:0,$clip(implode('; ',array_keys($rooms))),$clip(implode('; ',$schedule)),0,$userId];
        $stmt=$conn->prepare("INSERT INTO tbl_student_management_enrolled_subjects
            (import_batch_key,source_file_name,campus_id,college_id,program_id,ay_id,semester,year_level,section_id,section_text,offering_id,subject_id,subject_code,descriptive_title,student_id,faculty_id,room_id,room_text,schedule_text,source_row_number,uploaded_by,is_active)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
        synk_bind_dynamic_params($stmt,'ssiiiiiiisiissiiissii',$params);
        try { synk_dean_roster_execute($stmt); $membershipId=(int)$stmt->insert_id; } finally { $stmt->close(); }
        $actor=synk_find_useraccount_by_id($conn,$userId);
        $audit=[$token,(int)$student['student_id'],$values['student_number'],$membershipId,$class['campus_id'],$class['college_id'],$class['ay_id'],$class['semester'],
            $class['program_id'],$class['section_id'],$class['offering_id'],$class['subject_id'],$class['faculty_id'],$plan['previous']?'transfer':'add',$values['reason_code'],$values['reason_note'],
            json_encode($plan['previous'],JSON_UNESCAPED_UNICODE),$userId,(string)($actor['username'] ?? 'College Dean')];
        $stmt=$conn->prepare("INSERT INTO tbl_student_class_roster_audit
            (operation_key,student_id,student_number,student_enrollment_id,campus_id,college_id,ay_id,semester,program_id,section_id,offering_id,subject_id,faculty_id,action,reason_code,reason_note,previous_memberships,actor_user_id,actor_name,recorded_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())");
        synk_bind_dynamic_params($stmt,'sisiiiiiiiiiissssis',$audit);
        try { synk_dean_roster_execute($stmt); } finally { $stmt->close(); }
        synk_dean_manual_count($conn,$class,$userId);
        foreach ($plan['previous'] as $old) {
            // Legacy memberships may not have an offering ID. Resolve their class before updating derived counts.
            $oldClasses=synk_dean_students_query($conn,'SELECT po.offering_id FROM tbl_prospectus_offering po JOIN tbl_prospectus_subjects ps ON ps.ps_id=po.ps_id WHERE po.program_id=? AND po.section_id=? AND ps.sub_id=? AND po.ay_id=? AND po.semester=?','iiiii',[(int)$old['program_id'],(int)$old['section_id'],(int)$old['subject_id'],$class['ay_id'],$class['semester']]);
            foreach ($oldClasses as $oldClass) synk_dean_manual_count($conn,['offering_id'=>(int)$oldClass['offering_id'],'program_id'=>(int)$old['program_id'],'section_id'=>(int)$old['section_id'],
                'subject_id'=>(int)$old['subject_id'],'college_id'=>$class['college_id'],'campus_id'=>$class['campus_id'],'ay_id'=>$class['ay_id'],'semester'=>$class['semester']],$userId);
        }
        $rosterCount=count(synk_dean_roster_rows($conn,$class));
        if (!$conn->commit()) throw new RuntimeException('Unable to commit the class-list update.');
        $transaction=false;
        return ['student_id'=>(int)$student['student_id'],'offering_id'=>$class['offering_id'],'roster_count'=>$rosterCount,
            'message'=>$plan['previous']?'Student transferred to this class. Previous section memberships were preserved as inactive records.':'Student added to this class.'];
    } catch (Throwable $error) { if ($transaction) $conn->rollback(); throw $error; }
    finally { if ($emailLocked) synk_student_email_unlock($conn); $conn->query("SELECT RELEASE_LOCK('synk_college_dean_roster_import')"); }
}
