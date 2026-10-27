<?php
require_once __DIR__.'/management_catalog_helper.php';
require_once __DIR__.'/management_officer_access_helper.php';
require_once __DIR__.'/college_dean_students_helper.php';

function synk_management_officers_ready(mysqli $conn): bool
{
    return synk_table_exists($conn,'tbl_management_officers') && synk_table_exists($conn,'tbl_management_officer_audit');
}

function synk_management_officer_search(mysqli $conn,array $scope,string $search): array
{
    synk_management_require_permission($conn,$scope,'officers');
    if(!synk_management_officers_ready($conn))throw new RuntimeException('Student officer storage has not been prepared.');
    $search=trim($search);if(mb_strlen($search)<2 || mb_strlen($search)>150)throw new DomainException('Enter at least two characters of a student name, ID, or email.');
    $period=synk_management_fetch_current_period($conn,$scope);
    $source=synk_dean_students_source($scope,['ay_id'=>$period['ay_id'],'semester'=>$period['semester']],$conn);
    $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
    $rows=synk_management_query($conn,"SELECT DISTINCT s.student_id,s.student_number,s.first_name,s.last_name,s.email_address ".$source['sql']." AND (CONCAT_WS(' ',s.first_name,s.last_name) LIKE ? ESCAPE '!' OR s.student_number LIKE ? ESCAPE '!' OR s.email_address LIKE ? ESCAPE '!') ORDER BY s.last_name,s.first_name LIMIT 20",$source['types'].'sss',array_merge($source['params'],[$pattern,$pattern,$pattern]));
    foreach($rows as &$row){$grant=synk_management_query($conn,'SELECT supervisor_user_id,organization_id FROM tbl_management_officers WHERE student_id=?','i',[(int)$row['student_id']]);$row['assigned_elsewhere']=$grant && ((int)$grant[0]['supervisor_user_id']!==(int)$_SESSION['user_id'] || (int)$grant[0]['organization_id']!==$scope['organization_id']);}
    unset($row);return $rows;
}

function synk_management_officers_list(mysqli $conn,array $scope): array
{
    synk_management_require_permission($conn,$scope,'officers');
    if(!synk_management_officers_ready($conn))return [];
    $rows=synk_management_query($conn,"SELECT o.*,s.student_number,s.first_name,s.last_name,u.email FROM tbl_management_officers o JOIN tbl_student_management s ON s.student_id=o.student_id JOIN tbl_useraccount u ON u.user_id=o.user_id WHERE o.supervisor_user_id=? AND o.organization_id=? AND o.college_id=? ORDER BY s.last_name,s.first_name",'iii',[(int)$_SESSION['user_id'],$scope['organization_id'],$scope['college_id']]);
    foreach($rows as &$row){$account=synk_find_useraccount_by_id($conn,(int)$row['user_id']);$row['effective_status']=$row['status']==='REVOKED'?'Revoked':(synk_management_account_has_access($conn,$account)?'Active':'Expired or unavailable');}
    unset($row);return $rows;
}

function synk_management_officer_audit(mysqli $conn,array $scope): array
{
    synk_management_require_permission($conn,$scope,'officers');
    if(!synk_management_officers_ready($conn))return [];
    return synk_management_query($conn,"SELECT a.action,a.details,a.recorded_at,s.first_name,s.last_name,u.username AS actor_name FROM tbl_management_officer_audit a JOIN tbl_management_officers o ON o.officer_id=a.officer_id JOIN tbl_student_management s ON s.student_id=o.student_id JOIN tbl_useraccount u ON u.user_id=a.actor_user_id WHERE o.supervisor_user_id=? AND a.organization_id=? ORDER BY a.audit_id DESC LIMIT 50",'ii',[(int)$_SESSION['user_id'],$scope['organization_id']]);
}

function synk_management_officer_write(mysqli $conn,array $scope,string $action,array $input): array
{
    if(!synk_management_officers_ready($conn))throw new RuntimeException('Student officer storage has not been prepared.');
    if(!in_array($action,['officer_save','officer_revoke'],true))throw new DomainException('Choose a valid officer action.');
    $conn->begin_transaction();
    try{
        synk_management_lock_assignment($conn,$scope);
        synk_management_require_permission($conn,$scope,'officers');
        $period=synk_management_lock_current_term($conn,$input,$scope);
        $studentId=synk_management_input_id($input['student_id'] ?? null);
        // Same lock as the directory's email editor/importer. No identity writes.
        $emailLock=synk_management_query($conn,"SELECT GET_LOCK('synk_student_email_assignment',5) AS ok")[0]['ok'];
        if((int)$emailLock!==1)throw new SynkManagementConflict('Student emails are being updated. Try again shortly.');
        try{
            $rows=synk_management_query($conn,'SELECT * FROM tbl_student_management WHERE student_id=? FOR UPDATE','i',[$studentId]);
            if(!$rows)throw new OutOfBoundsException('Student unavailable.');$student=$rows[0];
            $grants=synk_management_query($conn,'SELECT * FROM tbl_management_officers WHERE student_id=? FOR UPDATE','i',[$studentId]);$grant=$grants[0] ?? null;
            if($grant && ((int)$grant['supervisor_user_id']!==(int)$_SESSION['user_id'] || (int)$grant['organization_id']!==$scope['organization_id']))throw new SynkManagementPermissionDenied('This student officer belongs to another faculty manager. Ask the Administrator to review the assignment.');
            if($grant && (string)($input['revision'] ?? '')!==(string)$grant['revision'])throw new SynkManagementConflict('This assignment changed. Reload the Student Officers page.');
            if($action==='officer_revoke'){
                if(!$grant)throw new OutOfBoundsException('Officer assignment unavailable.');
                synk_management_execute($conn,"UPDATE tbl_management_officers SET status='REVOKED',revision=revision+1 WHERE officer_id=?",'i',[(int)$grant['officer_id']]);
                $details=['status'=>'REVOKED'];$auditAction='REVOKE';
            }else{
                $source=synk_dean_students_source($scope,['ay_id'=>$period['ay_id'],'semester'=>$period['semester']],$conn);
                if(!synk_management_query($conn,'SELECT DISTINCT s.student_id '.$source['sql'].' AND s.student_id=? LIMIT 1',$source['types'].'i',array_merge($source['params'],[$studentId])))throw new DomainException('Select a student from your college in the current academic year and semester.');
                $email=synk_normalize_email((string)$student['email_address']);
                if(strlen($email)>150 || !filter_var($email,FILTER_VALIDATE_EMAIL) || !synk_is_allowed_email_domain($email,(string)synk_auth_settings()['allowed_domain']))throw new DomainException('The student needs a valid school email in the Student Directory.');
                if(count(synk_student_login_directory_for_officer($conn,$email))!==1)throw new DomainException('This email is assigned to multiple student records. Ask the college office to correct it first.');
                $flags=[];foreach(['can_view','can_attendance','can_payments']as$key){$value=$input[$key] ?? '0';if(!in_array((string)$value,['0','1'],true))throw new DomainException('Choose valid permissions.');$flags[$key]=(int)$value;}
                if(!array_sum($flags))throw new DomainException('Enable at least one operation.');
                $expiry=synk_management_input_date($input['expires_on'] ?? '');
                $today=date('Y-m-d');if($expiry<$today || $expiry>(new DateTimeImmutable('today'))->modify('+1 year')->format('Y-m-d'))throw new DomainException('Choose an expiry date from today up to one year ahead. Access also ends when the academic semester changes.');
                $existing=synk_management_query($conn,'SELECT * FROM tbl_useraccount WHERE LOWER(TRIM(email))=? FOR UPDATE','s',[$email]);
                if(!$grant && $existing)throw new DomainException('This email already has a shared account. Ask the Administrator to review it; existing accounts will not be reassigned.');
                if($grant){
                    $userId=(int)$grant['user_id'];$account=synk_find_useraccount_by_id($conn,$userId);
                    if(!$account || synk_normalize_email($account['email'])!==$email || (int)$account['management_organization_id']!==$scope['organization_id'] || $account['status']!=='active')throw new SynkManagementConflict('The account identity, assignment, or status changed. Ask the Administrator to review it.');
                    synk_management_execute($conn,"UPDATE tbl_management_officers SET ay_id=?,semester=?,can_view=?,can_attendance=?,can_payments=?,expires_on=?,status='ACTIVE',revision=revision+1 WHERE officer_id=?",'iiiiisi',[$period['ay_id'],$period['semester'],$flags['can_view'],$flags['can_attendance'],$flags['can_payments'],$expiry,(int)$grant['officer_id']]);
                    $auditAction='UPDATE';
                }else{
                    $name=trim($student['first_name'].' '.$student['last_name']);$password=synk_build_placeholder_password();
                    synk_management_execute($conn,"INSERT INTO tbl_useraccount (username,email,password,role,status,auth_provider,management_college_id,management_organization_id) VALUES (?,?,?,'management','active','google',?,?)",'sssii',[$name,$email,$password,$scope['college_id'],$scope['organization_id']]);$userId=(int)$conn->insert_id;
                    if(synk_persist_useraccount_roles($conn,$userId,['management'],'management')!==null)throw new RuntimeException('The student officer role could not be saved.');
                    synk_management_execute($conn,'INSERT INTO tbl_management_officers (user_id,student_id,supervisor_user_id,organization_id,college_id,ay_id,semester,can_view,can_attendance,can_payments,expires_on) VALUES (?,?,?,?,?,?,?,?,?,?,?)','iiiiiiiiiis',[$userId,$studentId,(int)$_SESSION['user_id'],$scope['organization_id'],$scope['college_id'],$period['ay_id'],$period['semester'],$flags['can_view'],$flags['can_attendance'],$flags['can_payments'],$expiry]);
                    $grant=['officer_id'=>(int)$conn->insert_id];$auditAction='GRANT';
                }
                $details=$flags+['expires_on'=>$expiry,'ay_id'=>$period['ay_id'],'semester'=>$period['semester']];
            }
            synk_management_execute($conn,'INSERT INTO tbl_management_officer_audit (officer_id,organization_id,actor_user_id,action,details,recorded_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())','iiiss',[(int)$grant['officer_id'],$scope['organization_id'],(int)$_SESSION['user_id'],$auditAction,json_encode($details)]);
            $conn->commit();return ['message'=>$action==='officer_revoke'?'Student officer access revoked.':'Student officer access saved. The student can sign in through Management using their school Google account.'];
        }finally{$conn->query("SELECT RELEASE_LOCK('synk_student_email_assignment')");}
    }catch(Throwable $error){$conn->rollback();throw $error;}
}
