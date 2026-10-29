<?php
// Used by account authorization; no schema creation or session overrides.
class SynkManagementPermissionDenied extends DomainException {}

function synk_management_operation_labels(): array
{
    return ['view'=>'View records','attendance'=>'Scan attendance','payments'=>'Collect payments',
        'manage_contributions'=>'Manage contributions','manage_events'=>'Manage events','manage_violations'=>'Manage violations','manage_exemptions'=>'Manage exemptions'];
}

function synk_management_operation_summary(array $permissions,string $prefix=''): string
{
    $labels=[];
    foreach(synk_management_operation_labels() as $operation=>$label)if(!empty($permissions[$prefix.$operation]))$labels[]=$label;
    return implode(', ',$labels);
}

function synk_management_operation_allowed(array $permissions,string $operation): bool
{
    if($operation==='reports')return $permissions['view']||$permissions['payments']||$permissions['attendance']||$permissions['manage_events']||!empty($permissions['manage_exemptions']);
    if($operation==='records')return $permissions['view']||$permissions['payments']||!empty($permissions['manage_exemptions']);
    if($operation==='student_records')return $permissions['view']||$permissions['payments']||$permissions['manage_violations']||!empty($permissions['manage_exemptions']);
    if($operation==='contributions' || $operation==='violations')return $permissions['view']||$permissions['payments']||$permissions['manage_'.$operation]||($operation==='contributions' && !empty($permissions['manage_exemptions']));
    if($operation==='events')return $permissions['view']||$permissions['attendance']||$permissions['manage_events']||!empty($permissions['manage_exemptions']);
    if($operation==='exemptions')return $permissions['view']||!empty($permissions['manage_exemptions']);
    return !empty($permissions[$operation]);
}

function synk_management_officer_record(mysqli $conn,int $userId): ?array
{
    if(!synk_table_exists($conn,'tbl_management_officers'))return null;
    $stmt=$conn->prepare('SELECT * FROM tbl_management_officers WHERE user_id=?');
    $stmt->bind_param('i',$userId);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();return $row ?: null;
}

function synk_management_faculty_supervisor(mysqli $conn,array $account): bool
{
    if(synk_management_officer_record($conn,(int)$account['user_id']) || ($account['status'] ?? '')!=='active'
        || !synk_useraccount_has_active_role($conn,$account,'management'))return false;
    if(!synk_table_exists($conn,'tbl_useraccount_faculty_links'))return false;
    $stmt=$conn->prepare("SELECT l.user_id FROM tbl_useraccount_faculty_links l JOIN tbl_faculty f ON f.faculty_id=l.faculty_id WHERE l.user_id=? AND f.status='active'");
    $stmt->bind_param('i',$account['user_id']);$stmt->execute();$valid=(bool)$stmt->get_result()->fetch_assoc();$stmt->close();return $valid;
}

function synk_student_login_directory_for_officer(mysqli $conn,string $email): array
{
    $stmt=$conn->prepare('SELECT student_id FROM tbl_student_management WHERE LOWER(TRIM(email_address))=? LIMIT 2');
    $email=synk_normalize_email($email);$stmt->bind_param('s',$email);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();return $rows;
}

function synk_management_officer_valid(mysqli $conn,array $account,array $grant): bool
{
    if($grant['status']!=='ACTIVE' || $grant['expires_on']<(new DateTimeImmutable('now',new DateTimeZone('Asia/Manila')))->format('Y-m-d')
        || (int)$grant['organization_id']!==(int)$account['management_organization_id'] || (int)$grant['college_id']!==(int)$account['management_college_id']
        || synk_management_operation_summary($grant,'can_')==='')return false;
    $supervisor=synk_find_useraccount_by_id($conn,(int)$grant['supervisor_user_id']);
    if(!$supervisor || !synk_management_faculty_supervisor($conn,$supervisor)
        || (int)$supervisor['management_organization_id']!==(int)$grant['organization_id'] || (int)$supervisor['management_college_id']!==(int)$grant['college_id'])return false;
    if(!synk_management_account_has_access($conn,$supervisor))return false;
    require_once __DIR__.'/college_dean_students_helper.php';
    $academic=synk_fetch_current_academic_term($conn);
    if((int)$grant['ay_id']!==$academic['ay_id'] || (int)$grant['semester']!==$academic['semester'])return false;
    $rows=synk_student_login_directory_for_officer($conn,(string)$account['email']);
    if(count($rows)!==1 || (int)$rows[0]['student_id']!==(int)$grant['student_id'])return false;
    $stmt=$conn->prepare('SELECT campus_id FROM tbl_college WHERE college_id=?');$stmt->bind_param('i',$grant['college_id']);$stmt->execute();$campus=$stmt->get_result()->fetch_assoc();$stmt->close();
    $source=synk_dean_students_source(['college_id'=>(int)$grant['college_id'],'campus_id'=>(int)$campus['campus_id']],['ay_id'=>$academic['ay_id'],'semester'=>$academic['semester']],$conn);
    return (bool)synk_dean_students_query($conn,'SELECT DISTINCT s.student_id '.$source['sql'].' AND s.student_id=? LIMIT 1',$source['types'].'i',array_merge($source['params'],[(int)$grant['student_id']]));
}

function synk_management_officer_account_allowed(mysqli $conn,array $account): bool
{
    $grant=synk_management_officer_record($conn,(int)$account['user_id']);
    if($grant)return synk_management_officer_valid($conn,$account,$grant);
    // Directory students cannot acquire unrestricted staff access via a bare role.
    return !synk_table_exists($conn,'tbl_student_management') || !synk_student_login_directory_for_officer($conn,(string)$account['email']);
}

function synk_management_permissions(mysqli $conn,array $scope): array
{
    if(($_SESSION['role'] ?? '')!=='management' || ($_SESSION['auth_method'] ?? '')!=='google')throw new SynkManagementPermissionDenied('Sign in to Management with your own Google account.');
    $account=synk_find_useraccount_by_id($conn,(int)($_SESSION['user_id'] ?? 0));
    if(!$account || !synk_management_account_has_access($conn,$account)
        || (int)$account['management_organization_id']!==(int)$scope['organization_id'] || (int)$account['management_college_id']!==(int)$scope['college_id'])throw new SynkManagementPermissionDenied('Your Management access changed. Sign in again.');
    $grant=synk_management_officer_record($conn,(int)$account['user_id']);
    $permissions=['is_officer'=>(bool)$grant,'setup'=>!$grant,'officers'=>!$grant && synk_management_faculty_supervisor($conn,$account),'student_id'=>(int)($grant['student_id'] ?? 0)];
    foreach(synk_management_operation_labels() as $operation=>$label)$permissions[$operation]=!$grant || !empty($grant['can_'.$operation]);
    return $permissions;
}

function synk_management_require_permission(mysqli $conn,array $scope,string $operation,?int $studentId=null): array
{
    $p=synk_management_permissions($conn,$scope);$allowed=synk_management_operation_allowed($p,$operation);
    if(!$allowed)throw new SynkManagementPermissionDenied('Your faculty manager has not enabled this operation for your account.');
    if($operation==='payments' && $p['is_officer'] && $studentId===$p['student_id'])throw new SynkManagementPermissionDenied('Ask your faculty manager or another authorized cashier to record payments on your own account.');
    return $p;
}

function synk_management_audit_officer_operation(mysqli $conn,array $scope,string $action,array $details): void
{
    $grant=synk_management_officer_record($conn,(int)($_SESSION['user_id'] ?? 0));if(!$grant)return;
    $json=json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $stmt=$conn->prepare('INSERT INTO tbl_management_officer_audit (officer_id,organization_id,actor_user_id,action,details,recorded_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP())');
    $user=(int)$_SESSION['user_id'];$stmt->bind_param('iiiss',$grant['officer_id'],$scope['organization_id'],$user,$action,$json);$stmt->execute();$stmt->close();
}
