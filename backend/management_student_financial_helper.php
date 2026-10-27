<?php
require_once __DIR__ . '/management_portal_helper.php';
require_once __DIR__ . '/management_payment_read_helper.php';

function synk_management_money_cents(string $amount): int
{
    if (!preg_match('/^(\d{1,14})(?:\.(\d{1,2}))?$/D',$amount,$parts)) throw new RuntimeException('Invalid stored monetary amount.');
    return (int)$parts[1]*100+(int)str_pad($parts[2] ?? '',2,'0');
}

function synk_management_money_text(int $cents): string
{
    return intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
}

// All active native catalog entries apply automatically. Only CLOSED attendance
// creates absence fines, using its frozen roster and fine. No legacy history.
function synk_management_student_financial_overview(mysqli $conn, array $scope, array $period,int $studentId): array
{
    $values = [$scope['college_id'],$scope['organization_id'],$period['ay_id'],$period['semester'],$period['exam_term']];
    $where = ' WHERE college_id=? AND organization_id=? AND ay_id=? AND semester=? AND exam_term=? AND status=\'ACTIVE\'';
    $contributions = synk_management_query($conn,
        'SELECT contribution_id,title,contribution_date,amount,audience_mode FROM tbl_management_contributions' . $where . ' ORDER BY contribution_id DESC',
        'iiiis',$values);
    $events = synk_management_query($conn,'SELECT e.event_id,e.title,e.event_date,e.absence_fine,a.absence_fine AS recorded_fine,a.attendance_status,r.student_id AS roster_student,l.recorded_at
        FROM tbl_management_events e LEFT JOIN tbl_management_attendance_events a ON a.event_id=e.event_id
        LEFT JOIN tbl_management_attendance_roster r ON r.event_id=e.event_id AND r.student_id=?
        LEFT JOIN tbl_management_attendance_logs l ON l.event_id=r.event_id AND l.student_id=r.student_id
        WHERE e.college_id=? AND e.organization_id=? AND e.ay_id=? AND e.semester=? AND e.exam_term=? AND e.status=\'ACTIVE\' ORDER BY e.event_id DESC',
        'iiiiis',array_merge([$studentId],$values));
    $contributionCents=0;$fineCents=0;$absences=0;$pendingEvents=0;
    $ready=synk_management_payments_ready($conn);
    $payments=synk_management_payment_history($conn,$studentId,$scope,$period);
    $paid=[];$paidCents=0;$charges=[];
    if ($ready) {
        $rows=synk_management_query($conn,'SELECT i.charge_key,SUM(i.amount) AS amount FROM tbl_management_payment_items i JOIN tbl_management_payments p ON p.payment_id=i.payment_id WHERE p.student_id=? AND p.college_id=? AND p.organization_id=? AND p.ay_id=? AND p.semester=? AND p.exam_term=? GROUP BY i.charge_key','iiiiis',array_merge([$studentId],$values));
        foreach ($rows as $row) $paid[$row['charge_key']]=synk_management_money_cents($row['amount']);
        foreach ($payments as $row) $paidCents+=synk_management_money_cents($row['amount']);
    }
    $charge=static function(string $key,string $title,string $date,int $amount)use(&$charges,$paid):array {
        $received=$paid[$key] ?? 0;$balance=max(0,$amount-$received);
        $row=['key'=>$key,'title'=>$title,'date'=>$date,'amount'=>synk_management_money_text($amount),
            'amount_paid'=>synk_management_money_text($received),'amount_due'=>synk_management_money_text($balance),
            'payment_status'=>$balance===0?'PAID':($received>0?'PARTIALLY_PAID':'UNPAID')];
        if ($amount>0) $charges[]=$row;
        return $row;
    };
    foreach ($contributions as &$contribution) {
        $amount=synk_management_money_cents($contribution['amount']);$contributionCents+=$amount;
        $state=$charge('C:'.$contribution['contribution_id'],$contribution['title'],$contribution['contribution_date'],$amount);
        foreach (['payment_status','amount_paid','amount_due'] as $key) $contribution[$key]=$state[$key];
    }
    unset($contribution);
    foreach ($events as &$event) {
        $event['amount_due']='0.00';
        if ($event['recorded_at']!==null) $event['student_attendance']='PRESENT';
        elseif ($event['attendance_status']==='CLOSED' && $event['roster_student']!==null) {
            $event['student_attendance']='ABSENT';$absences++;
            $event['amount_due']=$event['recorded_fine'];$fineCents+=synk_management_money_cents($event['recorded_fine']);
        } elseif ($event['attendance_status']!==null && $event['roster_student']===null) $event['student_attendance']='NOT_IN_ROSTER';
        else { $event['student_attendance']='PENDING';$pendingEvents++; }
        $event['assessed_amount']=$event['amount_due'];
        $state=$charge('E:'.$event['event_id'],$event['title'].' (absence fine)',$event['event_date'],synk_management_money_cents($event['amount_due']));
        foreach (['payment_status','amount_paid','amount_due'] as $key) $event[$key]=$state[$key];
        unset($event['roster_student'],$event['recorded_fine']);
    }
    unset($event);
    $contributionTotal=synk_management_money_text($contributionCents);
    $eventTotal=synk_management_money_text($fineCents);
    $gross=$contributionCents+$fineCents;$balance=max(0,$gross-$paidCents);
    $total=synk_management_money_text($balance);
    usort($charges,static function(array $a,array $b):int {return strcmp($a['date'],$b['date']) ?: strnatcmp($a['key'],$b['key']);});
    $current=synk_management_fetch_current_period($conn,$scope);
    return [
        'period'=>['key'=>$period['period_key'],'text'=>$period['period_text']],
        'organization'=>['code'=>$scope['organization_code'] ?? '', 'name'=>$scope['organization_name'] ?? '',
            'college'=>$scope['college_name'] ?? '', 'campus'=>$scope['campus_name'] ?? ''],
        'contributions'=>$contributions,'events'=>$events,'violations'=>[], 'payments'=>$payments,'charges'=>$charges,
        'account_version'=>hash('sha256',json_encode([$studentId,$period['period_key'],$charges,array_column($payments,'payment_id')])),
        'can_pay'=>$ready && $balance>0 && $period['period_key']===$current['period_key'],
        'summary'=>['contribution_count'=>count($contributions),'contribution_catalog_total'=>$contributionTotal,
            'event_count'=>count($events),'absence_count'=>$absences,'attendance_pending_count'=>$pendingEvents,'event_fine_total'=>$eventTotal,'violation_total'=>'0.00',
            'amount_paid'=>synk_management_money_text($paidCents),'deductions'=>'0.00','total_to_pay'=>$total,'payment_status'=>$gross===0?'NO_CHARGES':($balance===0?'PAID':($paidCents>0?'PARTIALLY_PAID':'UNPAID'))],
        'availability'=>['student_charges'=>true,'payments'=>$ready,'attendance'=>true,'student_violations'=>false],
    ];
}
