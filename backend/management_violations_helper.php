<?php
require_once __DIR__ . '/management_catalog_helper.php';

function synk_management_violation_get(mysqli $conn, array $scope, int $id, bool $lock = false): array
{
    $rows = synk_management_query($conn,'SELECT * FROM tbl_management_violations WHERE college_id=? AND organization_id=? AND violation_id=?' . ($lock ? ' FOR UPDATE' : ''),'iii',[$scope['college_id'],$scope['organization_id'],$id]);
    if (!$rows) throw new OutOfBoundsException('This violation is unavailable in your organization for this term.');
    $row = $rows[0]; $row['version'] = hash('sha256',json_encode($row));
    return $row;
}

function synk_management_violations_list(mysqli $conn, array $scope, array $input): array
{
    foreach (['search','page','page_size'] as $key) if (isset($input[$key]) && !is_scalar($input[$key])) throw new DomainException('Choose valid violation filters.');
    $search = trim((string)($input['search'] ?? ''));
    if (!preg_match('//u',$search) || mb_strlen($search,'UTF-8')>150) throw new DomainException('Search using up to 150 characters.');
    $page = filter_var($input['page'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $size = filter_var($input['page_size'] ?? 25,FILTER_VALIDATE_INT);
    if ($page === false || !in_array($size,[25,50,100],true)) throw new DomainException('Choose a valid page size.');
    $term = synk_management_view_term($conn,$input,$scope);
    $where = ' WHERE college_id=? AND organization_id=? AND ay_id=? AND semester=?'; $types = 'iiii'; $values = [$scope['college_id'],$scope['organization_id'],$term['ay_id'],$term['semester']];
    if ($search !== '') { $where .= " AND violation_type LIKE ? ESCAPE '!'"; $types .= 's'; $values[] = '%' . str_replace(['!','%','_'],['!!','!%','!_'],$search) . '%'; }
    $total = (int)synk_management_query($conn,'SELECT COUNT(*) AS n FROM tbl_management_violations' . $where,$types,$values)[0]['n'];
    $pages = max(1,(int)ceil($total/$size)); $page = min($pages,$page);
    $rows = synk_management_query($conn,'SELECT * FROM tbl_management_violations' . $where . ' ORDER BY violation_type,violation_id LIMIT ? OFFSET ?',$types . 'ii',array_merge($values,[$size,($page-1)*$size]));
    return ['rows'=>$rows,'filters'=>['search'=>$search,'page_size'=>$size], 'pagination'=>['total'=>$total,'page'=>$page,'pages'=>$pages]];
}

function synk_management_violation_write(mysqli $conn, array $scope, string $action, array $input): array
{
    if (!in_array($action,['save','delete'],true)) throw new DomainException('Choose a valid violation action.');
    $id = synk_management_input_id($input['record_id'] ?? '',true);
    if ($action === 'delete' && !$id) throw new DomainException('Choose a violation to delete.');
    $conn->begin_transaction();
    try {
        synk_management_lock_assignment($conn,$scope);
        $currentTerm = synk_management_lock_current_term($conn,$input,$scope);
        $existing = null;
        if ($id) {
            $existing = synk_management_violation_get($conn,$scope,$id,true);
            if (!is_string($input['version'] ?? null) || !hash_equals($existing['version'],$input['version'])) throw new SynkManagementConflict('This violation changed after you opened it. Reload its details.');
        }
        if ($action === 'delete') {
            synk_management_execute($conn,'DELETE FROM tbl_management_violations WHERE college_id=? AND violation_id=?','ii',[$scope['college_id'],$id]);
            $message = 'Violation deleted.';
        } else {
            $ayId = $existing ? $existing['ay_id'] : $currentTerm['ay_id'];
            $semester = $existing ? $existing['semester'] : $currentTerm['semester'];
            if (isset($input['record_term']) && $input['record_term'] !== $ayId.':'.$semester) throw new DomainException('The academic term cannot be changed.');
            $type = synk_management_input_text($input,'violation_type',150);
            $amount = synk_management_input_money($input['amount'] ?? '');
            if (synk_management_query($conn,'SELECT violation_id FROM tbl_management_violations WHERE organization_id=? AND ay_id=? AND semester=? AND violation_type=? AND violation_id<>?','iiisi',[$scope['organization_id'],$ayId,$semester,$type,$id ?? 0])) throw new DomainException('That violation type already exists in your organization for this term.');
            if ($id) synk_management_execute($conn,'UPDATE tbl_management_violations SET violation_type=?,amount=? WHERE college_id=? AND violation_id=?','ssii',[$type,$amount,$scope['college_id'],$id]);
            else {
                synk_management_execute($conn,'INSERT INTO tbl_management_violations (college_id,organization_id,ay_id,semester,violation_type,amount) VALUES (?,?,?,?,?,?)','iiiiss',[$scope['college_id'],$scope['organization_id'],$ayId,$semester,$type,$amount]);
                $id = (int)$conn->insert_id;
            }
            $message = 'Violation saved.';
        }
        $conn->commit(); return ['record_id'=>$id,'message'=>$message];
    } catch (mysqli_sql_exception $error) {
        $conn->rollback();
        if ((int)$error->getCode() === 1062) throw new DomainException('That violation type already exists in your organization for this term.');
        throw $error;
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}
