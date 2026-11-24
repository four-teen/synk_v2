<?php
require_once __DIR__.'/management_student_account_helper.php';

// Account totals for either the students already authorized by the directory
// or every eligible student in the period. Aggregate each charge source before
// joining it to the roster so subject rows cannot multiply a student's balance.
function synk_management_collection_source(mysqli $conn, array $scope, array $period, ?array $studentIds): array
{
    $periodTypes = 'iiiis';
    $periodValues = [$scope['college_id'],$scope['organization_id'],$period['ay_id'],$period['semester'],$period['exam_term']];
    $in = $studentIds ? implode(',',array_fill(0,count($studentIds),'?')) : '';
    $studentTypes = str_repeat('i',count($studentIds ?? []));
    if ($in !== '') {
        $members = "SELECT student_id FROM tbl_student_management WHERE student_id IN ($in)";
        $types = $studentTypes;
        $params = $studentIds;
    } elseif (synk_management_students_ready($conn)) {
        $source = synk_dean_students_source($scope,['ay_id'=>$period['ay_id'],'semester'=>$period['semester']],$conn);
        $members = 'SELECT DISTINCT s.student_id '.$source['sql'];
        $types = $source['types'];
        $params = $source['params'];
    } else {
        $members = 'SELECT NULL AS student_id FROM DUAL WHERE 1=0';
        $types = '';
        $params = [];
    }

    $periodWhere = static function (string $alias): string {
        return "$alias.college_id=? AND $alias.organization_id=? AND $alias.ay_id=? AND $alias.semester=? AND $alias.exam_term=?";
    };
    $studentWhere = static function (string $alias) use ($in): string {
        return $in !== '' ? " AND $alias.student_id IN ($in)" : '';
    };
    $joins = [];
    $parts = [];
    $defaultContribution = '0';
    $hasExemptions = synk_management_exemptions_ready($conn);
    if (synk_table_exists($conn,'tbl_management_contributions')) {
        $joins[] = 'CROSS JOIN (SELECT COALESCE(SUM(c.amount),0) AS amount FROM tbl_management_contributions c WHERE '
            .$periodWhere('c')." AND c.status='ACTIVE') contribution_catalog";
        $types .= $periodTypes;
        $params = array_merge($params,$periodValues);
        $defaultContribution = 'contribution_catalog.amount';
        if ($hasExemptions) {
            $parts[] = "SELECT x.student_id,-SUM(c.amount) AS assessed,0 AS collected
                FROM tbl_management_exemptions x JOIN tbl_management_contributions c ON c.contribution_id=x.contribution_id
                AND c.college_id=x.college_id AND c.organization_id=x.organization_id AND c.ay_id=x.ay_id
                AND c.semester=x.semester AND c.exam_term=x.exam_term AND c.status='ACTIVE'
                WHERE ".$periodWhere('x')." AND x.status='ACTIVE' AND x.event_id IS NULL"
                .$studentWhere('x').' GROUP BY x.student_id';
        }
    }
    if (synk_table_exists($conn,'tbl_management_events') && synk_table_exists($conn,'tbl_management_attendance_events')
        && synk_table_exists($conn,'tbl_management_attendance_roster') && synk_table_exists($conn,'tbl_management_attendance_logs')) {
        $withoutExemption = $hasExemptions ? " AND NOT EXISTS (SELECT 1 FROM tbl_management_exemptions x
            WHERE x.event_id=e.event_id AND x.student_id=r.student_id AND x.status='ACTIVE'
            AND x.college_id=e.college_id AND x.organization_id=e.organization_id AND x.ay_id=e.ay_id
            AND x.semester=e.semester AND x.exam_term=e.exam_term)" : '';
        $parts[] = "SELECT r.student_id,SUM(a.absence_fine) AS assessed,0 AS collected
            FROM tbl_management_events e JOIN tbl_management_attendance_events a ON a.event_id=e.event_id
            JOIN tbl_management_attendance_roster r ON r.event_id=a.event_id
            LEFT JOIN tbl_management_attendance_logs l ON l.event_id=r.event_id AND l.student_id=r.student_id
            WHERE ".$periodWhere('e')." AND e.status='ACTIVE' AND a.attendance_status='CLOSED' AND l.recorded_at IS NULL
            AND NOT ".synk_management_flag_raising_sql('e.source_metadata').$withoutExemption
            .$studentWhere('r').' GROUP BY r.student_id';
    }
    if (synk_management_student_violations_ready($conn)) {
        $parts[] = 'SELECT v.student_id,SUM(v.amount) AS assessed,0 AS collected FROM tbl_management_student_violations v WHERE '
            .$periodWhere('v').$studentWhere('v').' GROUP BY v.student_id';
    }
    $paymentTotal = '0';
    $paymentTypes = '';
    $paymentParams = [];
    if (synk_management_payments_ready($conn)) {
        $payments = ' FROM tbl_management_payments p WHERE '.$periodWhere('p');
        $parts[] = 'SELECT p.student_id,0 AS assessed,SUM(p.amount) AS collected'.$payments.$studentWhere('p').' GROUP BY p.student_id';
        $paymentTotal = '(SELECT COALESCE(SUM(p.amount),0)'.$payments.')';
        $paymentTypes = $periodTypes;
        $paymentParams = $periodValues;
    }

    $assessed = $defaultContribution;
    $collected = '0';
    if ($parts) {
        $joins[] = 'LEFT JOIN (SELECT entries.student_id,SUM(entries.assessed) AS assessed,SUM(entries.collected) AS collected
            FROM ('.implode(' UNION ALL ',$parts).') entries GROUP BY entries.student_id) amounts ON amounts.student_id=members.student_id';
        $types .= str_repeat($periodTypes.$studentTypes,count($parts));
        $groupParams = array_merge($periodValues,$studentIds ?? []);
        $params = array_merge($params,...array_fill(0,count($parts),$groupParams));
        $assessed .= '+COALESCE(amounts.assessed,0)';
        $collected = 'COALESCE(amounts.collected,0)';
    }
    return [
        'sql'=>"SELECT members.student_id,$assessed AS assessed,$collected AS collected FROM ($members) members ".implode(' ',$joins),
        'types'=>$types,'params'=>$params,
        'payment_total'=>$paymentTotal,'payment_types'=>$paymentTypes,'payment_params'=>$paymentParams,
    ];
}

function synk_management_student_balances(mysqli $conn, array $scope, array $period, array $studentIds): array
{
    if (!$studentIds) return [];
    $source = synk_management_collection_source($conn,$scope,$period,$studentIds);
    $balances = [];
    foreach (synk_management_query($conn,$source['sql'],$source['types'],$source['params']) as $row) {
        $balances[$row['student_id']] = synk_management_balance_summary(
            synk_management_money_cents((string)$row['assessed']),synk_management_money_cents((string)$row['collected'])
        );
    }
    return $balances;
}

function synk_management_collection_totals(mysqli $conn, array $scope, array $period): array
{
    $source = synk_management_collection_source($conn,$scope,$period,null);
    // Outstanding balances belong to individual students. A receipt on an
    // account no longer in the directory still counts as actual collection.
    return synk_management_query($conn,'SELECT COALESCE(SUM(accounts.assessed),0) AS expected,
        '.$source['payment_total'].' AS collected,COALESCE(SUM(GREATEST(0,accounts.assessed-accounts.collected)),0) AS outstanding
        FROM ('.$source['sql'].') accounts',$source['payment_types'].$source['types'],array_merge($source['payment_params'],$source['params']))[0];
}
