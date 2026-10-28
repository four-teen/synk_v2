<?php
require_once __DIR__.'/management_violations_helper.php';

function synk_management_student_violations_ready(mysqli $conn): bool
{
    return synk_table_exists($conn,'tbl_management_student_violations')
        && synk_table_has_column($conn,'tbl_management_payment_items','student_violation_id');
}

function synk_management_student_violation_options(mysqli $conn,array $scope,array $period): array
{
    $rows=synk_management_query($conn,'SELECT * FROM tbl_management_violations WHERE college_id=? AND organization_id=? AND ay_id=? AND semester=? ORDER BY violation_type,violation_id','iiii',[$scope['college_id'],$scope['organization_id'],$period['ay_id'],$period['semester']]);
    $options=[];foreach($rows as$row)$options[]=['violation_id'=>(int)$row['violation_id'],'violation_type'=>$row['violation_type'],'amount'=>$row['amount'],'version'=>hash('sha256',json_encode($row))];
    return $options;
}

function synk_management_student_violation_apply(mysqli $conn,array $scope,array $input): array
{
    if(!synk_management_student_violations_ready($conn))throw new RuntimeException('Student violation recording has not been prepared.');
    $key=$input['request_key'] ?? '';
    if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))throw new DomainException('Reload the violation form before saving.');
    $raw=$input['selected_violations'] ?? '';
    $selected=is_string($raw)&&strlen($raw)<20000?json_decode($raw,true):null;
    if(!is_array($selected)||array_values($selected)!==$selected||!count($selected)||count($selected)>50)throw new DomainException('Select between 1 and 50 violations from the list.');
    $choices=[];
    foreach($selected as$row){
        if(!is_array($row))throw new DomainException('Choose valid violations.');
        $id=synk_management_input_id($row['violation_id'] ?? null);$version=$row['version'] ?? '';
        if(isset($choices[$id])||!is_string($version)||!preg_match('/^[a-f0-9]{64}$/D',$version))throw new DomainException('Select each violation once and reload changed rates.');
        $choices[$id]=$version;
    }
    ksort($choices,SORT_NUMERIC);
    $date=synk_management_input_date($input['occurred_on'] ?? '');
    if($date>date('Y-m-d'))throw new DomainException('The violation date cannot be in the future.');
    $notes=synk_management_input_text($input,'notes',1000,false);
    $studentId=synk_management_input_id($input['student_id'] ?? null);
    $hash=hash('sha256',json_encode([$studentId,$choices,$date,$notes,$input['account_version'] ?? null,$input['term'] ?? null,$input['exam_term'] ?? null]));
    $conn->begin_transaction();
    try{
        synk_management_lock_assignment($conn,$scope);
        synk_management_require_permission($conn,$scope,'manage_violations');
        $period=synk_management_lock_current_term($conn,$input,$scope);
        require_once __DIR__.'/management_payment_helper.php';
        // Reuse the current-period organization and student-membership checks.
        synk_management_payment_context($conn,$scope,$input,$period,'adding violations');
        $prior=synk_management_query($conn,'SELECT * FROM tbl_management_student_violations WHERE organization_id=? AND request_key=? ORDER BY violation_id','is',[$scope['organization_id'],$key]);
        if($prior){
            if(count($prior)!==count($choices))throw new SynkManagementConflict('This violation request was already used. Reload the account.');
            foreach($prior as$row)if(!hash_equals($row['payload_hash'],$hash)||(int)$row['student_id']!==$studentId||(int)$row['recorded_by']!==(int)$_SESSION['user_id'])throw new SynkManagementConflict('This violation request was already used. Reload the account.');
            $conn->commit();return ['message'=>'Violations already applied.','count'=>count($prior),'duplicate'=>true];
        }
        $financial=synk_management_student_financial_overview($conn,$scope,$period,$studentId);
        if(!is_string($input['account_version'] ?? null)||!hash_equals($financial['account_version'],$input['account_version']))throw new SynkManagementConflict('This student account changed. Reload the financial overview before adding violations.');
        $ids=[];
        foreach($choices as$id=>$version){
            $catalog=synk_management_violation_get($conn,$scope,$id,true);
            if((int)$catalog['ay_id']!==$period['ay_id']||(int)$catalog['semester']!==$period['semester'])throw new DomainException('Choose violations from the selected academic year and semester.');
            if(!hash_equals($catalog['version'],$version))throw new SynkManagementConflict('A violation or its amount changed. Reload the financial overview and select it again.');
            synk_management_execute($conn,'INSERT INTO tbl_management_student_violations (college_id,organization_id,student_id,violation_id,ay_id,semester,exam_term,occurred_on,violation_type,amount,notes,recorded_by,recorded_by_name,recorded_at,request_key,payload_hash) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),?,?)','iiiiiisssssisss',[$scope['college_id'],$scope['organization_id'],$studentId,$id,$period['ay_id'],$period['semester'],$period['exam_term'],$date,$catalog['violation_type'],$catalog['amount'],$notes,(int)$_SESSION['user_id'],(string)$_SESSION['username'],$key,$hash]);
            $ids[]=(int)$conn->insert_id;
        }
        synk_management_audit_officer_operation($conn,$scope,'VIOLATION_ADD',['student_id'=>$studentId,'student_violation_ids'=>$ids,'violation_ids'=>array_keys($choices),'period'=>$period['period_key']]);
        $conn->commit();return ['message'=>count($ids).' violation(s) applied to this student.','count'=>count($ids),'duplicate'=>false];
    }catch(Throwable $error){$conn->rollback();throw $error;}
}
