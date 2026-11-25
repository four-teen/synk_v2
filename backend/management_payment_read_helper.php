<?php
require_once __DIR__.'/management_portal_helper.php';

function synk_management_payments_ready(mysqli $conn): bool
{
    return synk_table_exists($conn,'tbl_management_payments') && synk_table_exists($conn,'tbl_management_payment_items');
}

function synk_management_payment_reversals_ready(mysqli $conn): bool
{
    return synk_table_exists($conn,'tbl_management_payment_reversals')
        && synk_table_exists($conn,'tbl_management_payment_reversal_items');
}

function synk_management_money_cents(string $amount): int
{
    if (!preg_match('/^(\d{1,14})(?:\.(\d{1,2}))?$/D',$amount,$parts)) throw new RuntimeException('Invalid stored monetary amount.');
    return (int)$parts[1]*100+(int)str_pad($parts[2] ?? '',2,'0');
}

function synk_management_money_text(int $cents): string
{
    $absolute = abs($cents);
    return ($cents < 0 ? '-' : '').intdiv($absolute,100).'.'.str_pad((string)($absolute%100),2,'0',STR_PAD_LEFT);
}

// Indexed lookups preserve one row per receipt or allocation in the caller.
function synk_management_payment_net_sql(mysqli $conn, string $payment = 'p'): string
{
    if (!synk_management_payment_reversals_ready($conn)) return "$payment.amount";
    return "($payment.amount - COALESCE((SELECT SUM(reversed.amount)
        FROM tbl_management_payment_items original
        JOIN tbl_management_payment_reversal_items reversed ON reversed.payment_item_id=original.payment_item_id
        WHERE original.payment_id=$payment.payment_id),0))";
}

function synk_management_payment_item_net_sql(mysqli $conn, string $item = 'i'): string
{
    if (!synk_management_payment_reversals_ready($conn)) return "$item.amount";
    return "($item.amount - COALESCE((SELECT SUM(reversed.amount)
        FROM tbl_management_payment_reversal_items reversed WHERE reversed.payment_item_id=$item.payment_item_id),0))";
}

function synk_management_payment_state(string $posted, string $net, ?string $refunded = null): string
{
    $original = synk_management_money_cents($posted);
    $remaining = synk_management_money_cents($net);
    if ($original === $remaining) return 'Posted';
    if ($remaining > 0) return 'Partially reversed';
    if ($refunded !== null) {
        $cashReturned = synk_management_money_cents($refunded);
        if ($cashReturned === $original) return 'Refunded';
        if ($cashReturned === 0) return 'Voided';
    }
    return 'Fully reversed';
}

function synk_management_payment_reversal_reference(string $type, string $id): string
{
    return ($type === 'REFUND' ? 'REF-' : 'REV-').$id;
}

function synk_management_payment_history(mysqli $conn,int $studentId,?array $scope=null,?array $period=null): array
{
    if (!synk_management_payments_ready($conn)) return [];
    $net = synk_management_payment_net_sql($conn);
    $sql="SELECT p.payment_id,p.receipt_number AS reference,p.amount AS posted_amount,$net AS amount,
        p.allocation_mode,p.recorded_at AS date,p.organization_code,p.organization_name,p.period_text,p.recorded_by_name
        FROM tbl_management_payments p WHERE p.student_id=?";
    $types='i';$params=[$studentId];
    if ($scope && $period) {
        $sql.=' AND p.college_id=? AND p.organization_id=? AND p.ay_id=? AND p.semester=? AND p.exam_term=?';
        $types.='iiiis';$params=array_merge($params,[$scope['college_id'],$scope['organization_id'],$period['ay_id'],$period['semester'],$period['exam_term']]);
    }
    $rows=synk_management_query($conn,$sql.' ORDER BY p.payment_id DESC',$types,$params);
    foreach ($rows as &$row) {
        $row['payment_state']=synk_management_payment_state($row['posted_amount'],$row['amount']);
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

function synk_management_student_charge_has_credit(mysqli $conn, int $studentId, string $kind, int $chargeId): bool
{
    if (!synk_management_payments_ready($conn)) return false;
    $column = $kind === 'events' ? 'event_id' : 'contribution_id';
    $net = synk_management_payment_item_net_sql($conn);
    return (bool)synk_management_query($conn,"SELECT i.payment_item_id
        FROM tbl_management_payment_items i JOIN tbl_management_payments p ON p.payment_id=i.payment_id
        WHERE i.$column=? AND p.student_id=? AND $net>0 LIMIT 1",'ii',[$chargeId,$studentId]);
}
