<?php
require_once __DIR__ . '/management_catalog_helper.php';

function synk_organization_options(mysqli $conn): array
{
    return synk_management_query($conn,"SELECT o.organization_id,o.organization_code,o.organization_name,o.status,c.college_id,c.college_code,c.college_name,p.campus_id,p.campus_name FROM tbl_management_organizations o JOIN tbl_college c ON c.college_id=o.college_id JOIN tbl_campus p ON p.campus_id=c.campus_id WHERE c.status='active' AND p.status='active' ORDER BY p.campus_name,c.college_code,o.organization_code");
}

function synk_organization_usage(mysqli $conn, int $id): array
{
    $usage = [];
    foreach (['accounts'=>['tbl_useraccount','management_organization_id'],'contributions'=>['tbl_management_contributions','organization_id'],'events'=>['tbl_management_events','organization_id'],'violations'=>['tbl_management_violations','organization_id']] as $key=>$source) {
        $usage[$key] = (int)synk_management_query($conn,'SELECT COUNT(*) AS n FROM '.$source[0].' WHERE '.$source[1].'=?','i',[$id])[0]['n'];
    }
    return $usage;
}

function synk_organization_get(mysqli $conn, int $id, bool $lock = false): array
{
    $rows = synk_management_query($conn,'SELECT o.*,c.campus_id,c.college_code,p.campus_name FROM tbl_management_organizations o JOIN tbl_college c ON c.college_id=o.college_id JOIN tbl_campus p ON p.campus_id=c.campus_id WHERE o.organization_id=?'.($lock ? ' FOR UPDATE' : ''),'i',[$id]);
    if (!$rows) throw new OutOfBoundsException('This organization is unavailable.');
    $record = $rows[0]; $record['version'] = hash('sha256',json_encode($record));
    $record['usage'] = synk_organization_usage($conn,$id);
    return $record;
}

function synk_organization_write(mysqli $conn, string $action, array $input): array
{
    if (!in_array($action,['save','delete'],true)) throw new DomainException('Choose a valid action.');
    $id = synk_management_input_id($input['organization_id'] ?? '',true);
    if ($action==='delete' && !$id) throw new DomainException('Choose an organization to delete.');
    $conn->begin_transaction();
    try {
        $existing = $id ? synk_organization_get($conn,$id,true) : null;
        if ($existing && (!is_string($input['version'] ?? null) || !hash_equals($existing['version'],$input['version']))) throw new SynkManagementConflict('This organization changed. Reload its details.');
        if ($action==='delete') {
            if (array_sum($existing['usage'])) throw new SynkManagementConflict('This organization has assigned accounts or records. Set it to INACTIVE instead.');
            synk_management_execute($conn,'DELETE FROM tbl_management_organizations WHERE organization_id=?','i',[$id]);
        } else {
            $campus = synk_management_input_id($input['campus_id'] ?? '');
            $college = synk_management_input_id($input['college_id'] ?? '');
            if (!synk_management_query($conn,"SELECT c.college_id FROM tbl_college c JOIN tbl_campus p ON p.campus_id=c.campus_id WHERE c.college_id=? AND p.campus_id=? AND c.status='active' AND p.status='active' FOR UPDATE",'ii',[$college,$campus])) throw new DomainException('Choose an active college in the selected campus.');
            if ($existing && (int)$existing['college_id']!==$college && array_sum($existing['usage'])) throw new SynkManagementConflict('An organization with accounts or records cannot be moved to another college.');
            $code = synk_management_input_text($input,'organization_code',30);
            $name = synk_management_input_text($input,'organization_name',150);
            if (!preg_match('/^[A-Z0-9][A-Z0-9 .&_-]*$/D',$code)) throw new DomainException('Use letters, numbers, spaces, or . & _ - in the organization code.');
            $status = $input['status'] ?? 'ACTIVE';
            if (!in_array($status,['ACTIVE','INACTIVE'],true)) throw new DomainException('Choose a valid status.');
            if (synk_management_query($conn,'SELECT organization_id FROM tbl_management_organizations WHERE college_id=? AND organization_code=? AND organization_id<>?','isi',[$college,$code,$id ?? 0])) throw new DomainException('That organization code already exists in the selected college.');
            if ($id) synk_management_execute($conn,'UPDATE tbl_management_organizations SET college_id=?,organization_code=?,organization_name=?,status=? WHERE organization_id=?','isssi',[$college,$code,$name,$status,$id]);
            else { synk_management_execute($conn,'INSERT INTO tbl_management_organizations (college_id,organization_code,organization_name,status) VALUES (?,?,?,?)','isss',[$college,$code,$name,$status]); $id=(int)$conn->insert_id; }
        }
        $conn->commit(); return ['organization_id'=>$id,'message'=>$action==='delete' ? 'Organization deleted.' : 'Organization saved.'];
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}
