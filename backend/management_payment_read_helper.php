<?php
require_once __DIR__.'/management_portal_helper.php';

function synk_management_payments_ready(mysqli $conn): bool
{
    return synk_table_exists($conn,'tbl_management_payments') && synk_table_exists($conn,'tbl_management_payment_items');
}

function synk_management_payment_history(mysqli $conn,int $studentId,?array $scope=null,?array $period=null): array
{
    if (!synk_management_payments_ready($conn)) return [];
    $sql='SELECT payment_id,receipt_number AS reference,amount,allocation_mode,recorded_at AS date,organization_code,organization_name,period_text,recorded_by_name FROM tbl_management_payments WHERE student_id=?';
    $types='i';$params=[$studentId];
    if ($scope && $period) {
        $sql.=' AND college_id=? AND organization_id=? AND ay_id=? AND semester=? AND exam_term=?';
        $types.='iiiis';$params=array_merge($params,[$scope['college_id'],$scope['organization_id'],$period['ay_id'],$period['semester'],$period['exam_term']]);
    }
    $rows=synk_management_query($conn,$sql.' ORDER BY payment_id DESC',$types,$params);
    foreach ($rows as &$row) {
        $row['receipt_url']='payment-receipt.php?payment_id='.$row['payment_id'];
        $row['date']=(new DateTimeImmutable($row['date'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');
    }
    unset($row);return $rows;
}

function synk_management_charge_has_payments(mysqli $conn,string $kind,int $id): bool
{
    if (!synk_management_payments_ready($conn)) return false;
    $column=$kind==='contributions'?'contribution_id':'event_id';
    return (bool)synk_management_query($conn,'SELECT payment_item_id FROM tbl_management_payment_items WHERE '.$column.'=? LIMIT 1','i',[$id]);
}
