<?php
require_once __DIR__ . '/program_chair_faculty_helper.php';
require_once __DIR__ . '/auth_config.php';
require_once __DIR__ . '/professor_account_link_helper.php';

function synk_chair_account_field_limits(mysqli $conn): array
{
    static $limits = null;
    if ($limits === null) {
        $limits = [];
        $rows = synk_chair_faculty_query($conn,"SELECT COLUMN_NAME,CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tbl_useraccount' AND COLUMN_NAME IN ('username','email')");
        foreach ($rows as $row) $limits[$row['COLUMN_NAME']] = (int)$row['CHARACTER_MAXIMUM_LENGTH'];
        if (empty($limits['username']) || empty($limits['email'])) throw new RuntimeException('The faculty login schema is unavailable.');
    }
    return $limits;
}

function synk_chair_account_role_rows(mysqli $conn, array $userIds, bool $lock = false): array
{
    if (!$userIds) return [];
    $marks = implode(',', array_fill(0,count($userIds),'?'));
    $rows = synk_chair_faculty_query($conn,"SELECT user_id,role,status,is_primary FROM tbl_useraccount_roles
        WHERE user_id IN ({$marks}) ORDER BY user_id,role" . ($lock ? ' FOR UPDATE' : ''),str_repeat('i',count($userIds)),$userIds);
    $byUser = [];
    foreach ($rows as $row) $byUser[(int)$row['user_id']][] = $row;
    return $byUser;
}

function synk_chair_account_scope_rows(mysqli $conn, array $userIds, bool $lock = false): array
{
    if (!$userIds) return [];
    $marks = implode(',',array_fill(0,count($userIds),'?'));
    $rows = synk_chair_faculty_query($conn,"SELECT user_id,college_id,is_default,status FROM tbl_user_college_access
        WHERE user_id IN ({$marks}) ORDER BY user_id,college_id" . ($lock ? ' FOR UPDATE' : ''),str_repeat('i',count($userIds)),$userIds);
    $byUser = [];
    foreach ($rows as $row) $byUser[(int)$row['user_id']][] = $row;
    return $byUser;
}

function synk_chair_account_permission(array $faculty, ?array $account, array $roleRows, array $accessRows, array $scope): string
{
    if (((int)($faculty['college_id'] ?? 0) !== 0 && (int)$faculty['college_id'] !== $scope['college_id'])
        || ((int)($faculty['campus_id'] ?? 0) !== 0 && (int)$faculty['campus_id'] !== $scope['campus_id'])) {
        return 'This faculty member’s home college is outside your assigned college. Ask an administrator to manage this login.';
    }
    if (!$account) return '';
    $codes = array_column($roleRows,'role');
    $codes[] = (string)$account['role'];
    if (array_diff($codes,['professor','scheduler'])) {
        return 'This account has roles managed by an administrator. Its login access must be managed there.';
    }
    if ((int)($account['college_id'] ?? 0) !== 0 && (int)$account['college_id'] !== $scope['college_id']) {
        return 'This account belongs to another college. Ask an administrator to manage this login.';
    }
    foreach ($accessRows as $row) if ((int)$row['college_id'] !== $scope['college_id']) {
        return 'This account has access to other colleges. Ask an administrator to manage this login.';
    }
    return '';
}

function synk_chair_account_enrich_rows(mysqli $conn, array $rows, array $scope): array
{
    $ids = array_values(array_unique(array_filter(array_map(static function (array $row): int { return (int)$row['login_user_id']; },$rows))));
    $roles = synk_chair_account_role_rows($conn,$ids);
    $access = synk_chair_account_scope_rows($conn,$ids);
    foreach ($rows as &$row) {
        $id = (int)$row['login_user_id'];
        $account = $id ? ['role'=>$row['login_primary_role'],'college_id'=>$row['login_college_id']] : null;
        $roleRows = $roles[$id] ?? [];
        $row['faculty_email'] = synk_normalize_email((string)$row['faculty_email']);
        $reason = synk_chair_account_permission($row,$account,$roleRows,$access[$id] ?? [],$scope);
        $row['login_can_manage'] = $reason === '';
        $row['login_reason'] = $reason;
        $row['login_roles'] = $roleRows ? array_values(array_column(array_filter($roleRows,static function (array $role): bool { return $role['status']==='active'; }),'role')) : ($id ? [$row['login_primary_role']] : []);
    }
    unset($row);
    return $rows;
}

function synk_chair_account_target(mysqli $conn, array $scope, array $term, int $facultyId, bool $lock = false): array
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $facultyRows = synk_chair_faculty_query($conn,'SELECT faculty_id,first_name,middle_name,last_name,ext_name,college_id,campus_id,status FROM tbl_faculty WHERE faculty_id=?'.$suffix,'i',[$facultyId]);
    $memberships = synk_chair_faculty_query($conn,"SELECT college_faculty_id FROM tbl_college_faculty WHERE faculty_id=? AND college_id=? AND status='active'
        AND ((ay_id=? AND semester=?) OR (ay_id IS NULL AND semester IS NULL))".$suffix,'iiii',[$facultyId,$scope['college_id'],$term['ay_id'],$term['semester']]);
    if (!$facultyRows || !$memberships) throw new DomainException('This faculty member is not in your assigned college list for this term.',403);
    $faculty = $facultyRows[0];
    $links = synk_chair_faculty_query($conn,'SELECT user_id,faculty_id FROM tbl_useraccount_faculty_links WHERE faculty_id=?'.$suffix,'i',[$facultyId]);
    if (count($links)>1) throw new DomainException('This faculty record has conflicting account links. Ask an administrator to review it.',409);
    $account = null; $roles = []; $access = [];
    if ($links) {
        $id = (int)$links[0]['user_id'];
        $users = synk_chair_faculty_query($conn,'SELECT user_id,username,email,role,status,college_id FROM tbl_useraccount WHERE user_id=?'.$suffix,'i',[$id]);
        if (!$users) throw new DomainException('The linked login account could not be found. Ask an administrator to review it.',409);
        $account = $users[0];
        $roles = synk_chair_account_role_rows($conn,[$id],$lock)[$id] ?? [];
        $access = synk_chair_account_scope_rows($conn,[$id],$lock)[$id] ?? [];
    }
    $reason = synk_chair_account_permission($faculty,$account,$roles,$access,$scope);
    $revision = hash('sha256',json_encode([$faculty,$account,$roles,$access]));
    $activeRoles = $roles ? array_values(array_column(array_filter($roles,static function (array $row): bool { return $row['status']==='active'; }),'role')) : ($account ? [$account['role']] : []);
    return ['faculty'=>$faculty,'account'=>$account,'role_rows'=>$roles,'scope_rows'=>$access,
        'roles'=>$activeRoles,'can_manage'=>$reason==='', 'reason'=>$reason, 'revision'=>$revision];
}

function synk_chair_account_public(mysqli $conn, array $target, array $term): array
{
    $account = $target['account'];
    $reason = $target['reason'];
    if (empty($term['is_current'])) $reason = 'This term is for viewing only. Switch to the current term to manage login access.';
    return ['faculty_id'=>(int)$target['faculty']['faculty_id'],'faculty_name'=>synk_professor_full_name_from_row($target['faculty']),
        'exists'=>$account!==null, 'email'=>$account ? synk_normalize_email((string)$account['email']) : '',
        'status'=>$account['status'] ?? 'active','roles'=>$target['roles'],'primary_role'=>$account['role'] ?? 'professor',
        'can_manage'=>$reason==='', 'reason'=>$reason, 'revision'=>$target['revision'],
        'allowed_domain'=>synk_auth_settings()['allowed_domain'],'email_max_length'=>synk_chair_account_field_limits($conn)['email']];
}

function synk_chair_account_save(mysqli $conn, array $input): array
{
    $allowed = ['action','faculty_id','college_id','campus_id','ay_id','semester','csrf_token','account_revision','email','status','roles_json','primary_role'];
    if (array_diff(array_keys($input),$allowed)) throw new DomainException('This request contains account fields you cannot manage.',400);
    foreach (['account_revision','email','status','roles_json','primary_role'] as $field) if (!is_string($input[$field] ?? null)) throw new DomainException('Complete the login access form.',400);
    $roles = json_decode($input['roles_json'],true);
    if (!is_array($roles) || !$roles || array_values($roles)!==$roles || count($roles)>2) throw new DomainException('Select Professor, Scheduler, or both roles.',422);
    foreach ($roles as $role) if (!is_string($role) || !in_array($role,['professor','scheduler'],true)) throw new DomainException('Program Chair can assign only Professor and Scheduler roles.',403);
    if (count(array_unique($roles))!==count($roles)) throw new DomainException('Select each role once.',422);
    $primary = $input['primary_role'];
    if (!in_array($primary,$roles,true)) throw new DomainException('Choose a default workspace from the selected roles.',422);
    $status = $input['status'];
    if (!in_array($status,['active','inactive'],true)) throw new DomainException('Choose Active or Inactive login access.',422);
    $facultyId = synk_scheduler_list_id($input['faculty_id'] ?? null);
    $email = synk_normalize_email($input['email']);
    $conn->begin_transaction();
    try {
        $context = synk_chair_faculty_lock_write($conn,$input);
        $term = synk_dean_term_from_ids($conn,(string)$context['term']['ay_id'],(string)$context['term']['semester']);
        $target = synk_chair_account_target($conn,$context['scope'],$term,$facultyId,true);
        if (!$target['can_manage']) throw new DomainException($target['reason'],403);
        if (!hash_equals($target['revision'],$input['account_revision'])) throw new DomainException('This account changed since you opened it. Reopen Login Access before saving.',409);
        if (($status==='active' || !$target['account']) && $target['faculty']['status']!=='active') throw new DomainException('Activate the faculty record through the administrator before enabling its login.',422);
        $account = $target['account'];
        if ($account) {
            if ($email!==synk_normalize_email((string)$account['email'])) throw new DomainException('An existing account’s email must be changed by an administrator.',403);
            $userId = (int)$account['user_id'];
            synk_chair_faculty_query($conn,'UPDATE tbl_useraccount SET role=?,status=? WHERE user_id=?','ssi',[$primary,$status,$userId]);
        } else {
            $settings = synk_auth_settings();
            $limits = synk_chair_account_field_limits($conn);
            if (strlen($email)>$limits['email']) throw new DomainException('Use an institutional email with at most '.$limits['email'].' characters.',422);
            if (!filter_var($email,FILTER_VALIDATE_EMAIL) || !synk_is_allowed_email_domain($email,(string)$settings['allowed_domain'])) throw new DomainException('Enter a valid '.$settings['allowed_domain'].' email address.',422);
            if (synk_chair_faculty_query($conn,'SELECT user_id FROM tbl_useraccount WHERE LOWER(TRIM(email))=? FOR UPDATE','s',[$email])) throw new DomainException('This email already belongs to an account. Ask an administrator to link the correct faculty record.',409);
            $name = synk_professor_full_name_from_row($target['faculty']);
            $nameLength = function_exists('mb_strlen') ? mb_strlen($name,'UTF-8') : strlen($name);
            if ($name==='' || $nameLength>$limits['username']) throw new DomainException('This faculty name requires administrator review before creating a login.',422);
            $columns = ['username','email','password','role','status','college_id'];
            $values = [$name,$email,synk_build_placeholder_password(),$primary,$status,$context['scope']['college_id']];
            $types = 'sssssi';
            foreach (['auth_provider'=>'google','google_email_verified'=>0,'last_google_name'=>$name] as $column=>$value) {
                if (synk_useraccount_has_column($conn,$column)) { $columns[]=$column; $values[]=$value; $types.=is_int($value)?'i':'s'; }
            }
            $marks = implode(',',array_fill(0,count($columns),'?'));
            synk_chair_faculty_query($conn,'INSERT INTO tbl_useraccount ('.implode(',',$columns).') VALUES ('.$marks.')',$types,$values);
            $userId = (int)$conn->insert_id;
            $error = synk_professor_insert_auto_account_link($conn,$userId,$facultyId);
            if ($error!==null) throw new RuntimeException('Unable to link the faculty login account.');
        }
        if (synk_persist_useraccount_roles($conn,$userId,$roles,$primary)!==null) throw new RuntimeException('Unable to save faculty login roles.');
        if (synk_persist_scheduler_college_access($conn,$userId,$roles,[$context['scope']['college_id']],$context['scope']['college_id'])!==null) throw new RuntimeException('Unable to save faculty login college access.');
        // Shared-login setup clears the fallback college when Scheduler is absent.
        // Preserve the assigned college for these faculty logins as their account scope.
        synk_chair_faculty_query($conn,'UPDATE tbl_useraccount SET college_id=? WHERE user_id=?','ii',[$context['scope']['college_id'],$userId]);
        // Keep the explicit faculty identity link even when Scheduler is the sole selected role.
        $result = synk_chair_account_public($conn,synk_chair_account_target($conn,$context['scope'],$term,$facultyId,true),$term);
        $conn->commit();
        return ['status'=>'saved','account'=>$result,'message'=>'Faculty login access saved.'];
    } catch (Throwable $exception) { $conn->rollback(); throw $exception; }
}
