<?php
// Read-only student access to Management accounts. No academic settings writes.
require_once __DIR__.'/management_student_financial_helper.php';
require_once __DIR__.'/college_dean_students_helper.php';

function synk_management_student_account(mysqli $conn,int $studentId): array
{
    if ($studentId<1) throw new OutOfBoundsException('Student record unavailable.');
    foreach (['tbl_management_organizations','tbl_management_contributions','tbl_management_events','tbl_management_period_settings',
        'tbl_management_attendance_events','tbl_management_attendance_roster','tbl_management_attendance_logs'] as $table) {
        if (!synk_table_exists($conn,$table)) throw new RuntimeException('Management financial storage is unavailable.');
    }
    $academic=synk_fetch_current_academic_term($conn);
    if (!$academic['ay_id'] || !$academic['semester']) throw new RuntimeException('The current academic period is unavailable.');
    $organizations=synk_management_query($conn,"SELECT o.organization_id,o.organization_code,o.organization_name,c.college_id,c.college_name,cp.campus_id,cp.campus_name
        FROM tbl_management_organizations o JOIN tbl_college c ON c.college_id=o.college_id JOIN tbl_campus cp ON cp.campus_id=c.campus_id
        WHERE o.status='ACTIVE' AND c.status='active' AND cp.status='active' ORDER BY c.college_name,o.organization_code,o.organization_id");
    $accounts=[];$contributions=[];$events=[];$violations=[];$payments=[];
    $totals=['contributions'=>0,'event_fines'=>0,'violations'=>0,'paid'=>0,'deductions'=>0,'due'=>0];
    $attendance=['events'=>0,'present'=>0,'absent'=>0,'pending'=>0,'not_in_roster'=>0];
    foreach ($organizations as $scope) {
        foreach (['college_id','campus_id','organization_id'] as $key) $scope[$key]=(int)$scope[$key];
        // Reuse Management membership, including retained directory connections.
        // The master profile's original college does not authorize current charges.
        $source=synk_dean_students_source($scope,['ay_id'=>$academic['ay_id'],'semester'=>$academic['semester']],$conn);
        if (!synk_management_query($conn,'SELECT s.student_id '.$source['sql'].' AND s.student_id=? LIMIT 1',$source['types'].'i',array_merge($source['params'],[$studentId]))) continue;
        $period=synk_management_fetch_current_period($conn,$scope);
        $account=synk_management_student_financial_overview($conn,$scope,$period,$studentId);
        if (!$account['contributions'] && !$account['events'] && !$account['violations'] && !$account['payments']) continue;
        $accounts[]=['organization'=>$account['organization'],'period'=>$account['period']];
        $context=['organization_code'=>$scope['organization_code'],'organization_name'=>$scope['organization_name'],'period_text'=>$period['period_text']];
        foreach ($account['contributions'] as $row) $contributions[]=$row+$context;
        foreach ($account['events'] as $row) {
            $events[]=$row+$context;$attendance['events']++;
            $status=['PRESENT'=>'present','ABSENT'=>'absent','PENDING'=>'pending','NOT_IN_ROSTER'=>'not_in_roster'][$row['student_attendance']];
            $attendance[$status]++;
        }
        foreach ($account['violations'] as $row) $violations[]=$row+$context;
        foreach ($account['payments'] as $row) $payments[]=$row+$context;
        foreach (['contributions'=>'contribution_catalog_total','event_fines'=>'event_fine_total','violations'=>'violation_total','paid'=>'amount_paid','deductions'=>'deductions','due'=>'total_to_pay'] as $key=>$field) {
            $totals[$key]+=synk_management_money_cents($account['summary'][$field]);
        }
    }
    usort($contributions,static function($a,$b){return (int)$b['contribution_id']<=>(int)$a['contribution_id'];});
    usort($events,static function($a,$b){return (int)$b['event_id']<=>(int)$a['event_id'];});
    // Receipt history stays accessible after a Management term changes, using
    // only this signed-in student's native payment records.
    $payments=synk_management_payment_history($conn,$studentId);
    $historyTotal=0;foreach($payments as$row)$historyTotal+=synk_management_money_cents($row['amount']);
    foreach ($totals as &$value) $value=synk_management_money_text($value);
    unset($value);
    return ['academic_period'=>$academic['term_text'],'accounts'=>$accounts,'contributions'=>$contributions,'events'=>$events,
        'violations'=>$violations,'payments'=>$payments,'payment_history_total'=>synk_management_money_text($historyTotal),'totals'=>$totals,'attendance'=>$attendance];
}

function synk_management_account_money(string $amount): string
{
    $text=synk_management_money_text(synk_management_money_cents($amount));
    [$whole,$fraction]=explode('.',$text);
    return '₱'.preg_replace('/\B(?=(\d{3})+(?!\d))/',',',$whole).'.'.$fraction;
}
