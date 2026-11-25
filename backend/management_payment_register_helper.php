<?php
require_once __DIR__.'/management_catalog_helper.php';
require_once __DIR__.'/management_collection_helper.php';

function synk_management_payment_register(mysqli $conn, array $scope, array $term, array $currentTerm, array $input): array
{
    $filters = [
        'search'=>synk_management_input_text($input,'search',150,false,false),
        'date_from'=>$input['date_from'] ?? '',
        'date_to'=>$input['date_to'] ?? '',
        'cashier_id'=>synk_management_input_id($input['cashier_id'] ?? '',true),
    ];
    foreach (['date_from','date_to'] as $key) {
        if ($filters[$key] !== '') $filters[$key] = synk_management_input_date($filters[$key]);
    }
    $page = synk_management_input_id($input['page'] ?? 1);
    $local = new DateTimeZone('Asia/Manila');
    $utc = new DateTimeZone('UTC');
    $today = new DateTimeImmutable('today',$local);
    $dateFrom = $filters['date_from'];
    $dateTo = $filters['date_to'];
    $register = [
        'available'=>synk_management_payments_ready($conn),
        'today'=>$today,
        'today_totals'=>null,
        'filters'=>$filters,
        'cashiers'=>[],
        'filter_error'=>$dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo
            ? 'Choose an end date on or after the start date.' : '',
        'rows'=>[],
        'filtered_total'=>'0.00',
        'pagination'=>['page'=>1,'pages'=>1,'total'=>0,'from'=>0,'to'=>0],
    ];
    if (!$register['available']) return $register;

    $currentScope = synk_management_collection_receipt_scope($scope,$currentTerm);
    $dayBounds = [
        $today->setTimezone($utc)->format('Y-m-d H:i:s'),
        $today->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
    ];
    $viewScope = synk_management_collection_receipt_scope($scope,$term);

    // Keep the counts, cashier choices and visible receipts on the same ledger snapshot.
    $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
    try {
        $register['today_totals'] = synk_management_query($conn, 'SELECT COUNT(*) AS receipts,
            COALESCE(SUM(p.amount),0) AS collected FROM tbl_management_payments p'
            .$currentScope['where'].' AND p.recorded_at>=? AND p.recorded_at<?',
            $currentScope['types'].'ss',array_merge($currentScope['params'],$dayBounds))[0];

        $register['cashiers'] = synk_management_query($conn, 'SELECT latest.recorded_by, latest.recorded_by_name
            FROM (SELECT MAX(p.payment_id) AS payment_id FROM tbl_management_payments p'
            .$viewScope['where'].' AND p.recorded_by>0 GROUP BY p.recorded_by) cashiers
            JOIN tbl_management_payments latest ON latest.payment_id=cashiers.payment_id
            ORDER BY latest.recorded_by_name, latest.recorded_by', $viewScope['types'],$viewScope['params']);

        if ($register['filter_error'] === '') {
            $where = $viewScope['where'];
            $types = $viewScope['types'];
            $params = $viewScope['params'];
            foreach (preg_split('/[\s,]+/u',$filters['search'],-1,PREG_SPLIT_NO_EMPTY) as $word) {
                $pattern = '%'.str_replace(['!','%','_'],['!!','!%','!_'],$word).'%';
                $where .= " AND (p.student_name LIKE ? ESCAPE '!' OR p.student_number LIKE ? ESCAPE '!'
                    OR CONVERT(p.receipt_number USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ? ESCAPE '!')";
                $types .= 'sss';
                array_push($params,$pattern,$pattern,$pattern);
            }
            if ($dateFrom !== '') {
                $where .= ' AND p.recorded_at>=?';
                $types .= 's';
                $params[] = (new DateTimeImmutable($dateFrom,$local))->setTimezone($utc)->format('Y-m-d H:i:s');
            }
            if ($dateTo !== '') {
                $where .= ' AND p.recorded_at<?';
                $types .= 's';
                $params[] = (new DateTimeImmutable($dateTo,$local))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
            }
            if ($filters['cashier_id'] !== null) {
                $where .= ' AND p.recorded_by=?';
                $types .= 'i';
                $params[] = $filters['cashier_id'];
            }
            $source = ' FROM tbl_management_payments p'.$where;
            $summary = synk_management_query($conn, 'SELECT COUNT(*) AS total, COALESCE(SUM(p.amount),0) AS collected'.$source,$types,$params)[0];
            $total = (int)$summary['total'];
            $pageSize = 25;
            $pages = max(1,(int)ceil($total / $pageSize));
            $page = min($page,$pages);
            $offset = ($page - 1) * $pageSize;
            if ($total > 0) {
                $register['rows'] = synk_management_query($conn, 'SELECT p.payment_id, p.receipt_number, p.student_name,
                    p.student_number, p.recorded_by_name, p.recorded_at, p.amount'.$source.'
                    ORDER BY p.recorded_at DESC, p.payment_id DESC LIMIT ? OFFSET ?',
                    $types.'ii',array_merge($params,[$pageSize,$offset]));
            }
            $register['filtered_total'] = $summary['collected'];
            $register['pagination'] = [
                'page'=>$page,'pages'=>$pages,'total'=>$total,
                'from'=>$total ? $offset + 1 : 0,'to'=>$offset + count($register['rows']),
            ];
        }
        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
    return $register;
}
