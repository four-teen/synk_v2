<?php
require_once __DIR__.'/management_payment_helper.php';
require_once __DIR__.'/management_collection_helper.php';

function synk_management_correction_id($value): int
{
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,18}$/D',(string)$value)) {
        throw new DomainException('Select valid payments.',422);
    }
    $id = filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($id === false) throw new DomainException('Select valid payments.',422);
    return $id;
}

function synk_management_correction_search(mysqli $conn, array $scope, array $period, string $search, int $page): array
{
    $source = synk_management_collection_receipt_scope($scope,$period);
    $net = synk_management_payment_net_sql($conn);
    $matches = [];
    $params = $source['params'];
    foreach (preg_split('/[\s,]+/u',$search,-1,PREG_SPLIT_NO_EMPTY) as $word) {
        $pattern = '%'.str_replace(['!','%','_'],['!!','!%','!_'],$word).'%';
        $matches[] = "MAX(p.student_name LIKE ? ESCAPE '!' OR p.student_number LIKE ? ESCAPE '!'
            OR CONVERT(p.receipt_number USING utf8mb4) COLLATE utf8mb4_unicode_ci LIKE ? ESCAPE '!')=1";
        array_push($params,$pattern,$pattern,$pattern);
    }
    $having = $matches ? ' HAVING '.implode(' AND ',$matches) : '';
    $rows = synk_management_query($conn,"SELECT paid.student_id,paid.total_paid,
        latest.student_name,latest.student_number
        FROM (SELECT p.student_id,MAX(p.payment_id) AS latest_id,SUM($net) AS total_paid
            FROM tbl_management_payments p{$source['where']} GROUP BY p.student_id$having
            ORDER BY latest_id DESC LIMIT 26 OFFSET ?) paid
        JOIN tbl_management_payments latest ON latest.payment_id=paid.latest_id ORDER BY paid.latest_id DESC",
        $source['types'].str_repeat('sss',count($matches)).'i',array_merge($params,[($page-1)*25]));
    return ['rows'=>array_slice($rows,0,25),'has_next'=>count($rows)>25];
}

function synk_management_correction_receipts(mysqli $conn, array $scope, array $period, int $studentId): array
{
    $source = synk_management_collection_receipt_scope($scope,$period);
    $net = synk_management_payment_item_net_sql($conn);
    $rows = synk_management_query($conn,"SELECT p.payment_id,p.receipt_number,p.amount,p.recorded_at,
        p.student_name,p.student_number,i.payment_item_id,i.title,i.amount AS posted_item_amount,$net AS item_amount
        FROM tbl_management_payments p LEFT JOIN tbl_management_payment_items i ON i.payment_id=p.payment_id"
        .$source['where'].' AND p.student_id=? ORDER BY p.payment_id DESC,i.payment_item_id',
        $source['types'].'i',array_merge($source['params'],[$studentId]));
    if (!$rows) throw new OutOfBoundsException('This student has no receipts in the selected Management period.');

    $receipts = [];
    foreach ($rows as $row) {
        $id = (int)$row['payment_id'];
        if (!isset($receipts[$id])) {
            $receipts[$id] = array_intersect_key($row,array_flip([
                'receipt_number','recorded_at','student_name','student_number',
            ])) + ['posted'=>synk_management_money_cents($row['amount']),'allocated'=>0,'reversed'=>0,'items'=>[]];
        }
        if ($row['payment_item_id'] === null) continue;
        $posted = synk_management_money_cents($row['posted_item_amount']);
        $remaining = synk_management_money_cents($row['item_amount']);
        $receipts[$id]['allocated'] += $posted;
        $receipts[$id]['reversed'] += $posted-$remaining;
        $receipts[$id]['items'][(int)$row['payment_item_id']] = ['title'=>$row['title'],'posted'=>$posted,'remaining'=>$remaining];
    }
    foreach ($receipts as &$receipt) {
        $receipt['remaining'] = $receipt['posted']-$receipt['reversed'];
        $receipt['balanced'] = $receipt['items'] && $receipt['posted'] === $receipt['allocated'];
    }
    unset($receipt);
    return $receipts;
}

function synk_management_correction_history(mysqli $conn, array $scope, array $period, int $studentId): array
{
    $source = synk_management_collection_receipt_scope($scope,$period);
    return synk_management_query($conn,'SELECT p.reversal_id,p.reversal_type,p.reason,p.refund_reference,p.recorded_by_name,
        p.recorded_at,p.amount,p.balance_before,p.balance_after FROM tbl_management_payment_reversals p'
        .$source['where'].' AND p.student_id=? ORDER BY p.reversal_id DESC',$source['types'].'i',array_merge($source['params'],[$studentId]));
}

function synk_management_correction_selection($value): array
{
    $ids = is_string($value) ? json_decode($value) : null;
    if (!is_array($ids) || !$ids) {
        throw new DomainException('Select at least one payment to delete.',422);
    }
    $selected = [];
    foreach ($ids as $value) {
        $id = synk_management_correction_id($value);
        if (isset($selected[$id])) throw new DomainException('Select each payment once.',422);
        $selected[$id] = true;
    }
    ksort($selected,SORT_NUMERIC);
    return $selected;
}

function synk_management_correction_version(array $context, array $receipts): string
{
    return hash('sha256',json_encode([$context,$receipts]));
}

function synk_management_correction_amounts(array $receipts, array $selected): array
{
    $amounts = [];
    foreach ($receipts as $receipt) {
        $checked = array_intersect_key($receipt['items'],$selected);
        if (!$checked) continue;
        if (!$receipt['balanced']) {
            throw new SynkManagementConflict('Receipt '.$receipt['receipt_number'].' has a mismatch between its total and allocations. It needs review before correction.');
        }
        foreach ($checked as $id=>$item) {
            if ($item['remaining'] <= 0 || $item['remaining'] > $item['posted']) {
                throw new SynkManagementConflict('A selected payment is no longer available. Select the paid payments again.');
            }
            $amounts[$id] = $item['remaining'];
        }
    }
    if (!$amounts || count($amounts) !== count($selected)) {
        throw new DomainException('Select paid payments for this student in the selected Management period.',422);
    }
    return $amounts;
}

function synk_management_correction_context(array $scope, array $period, int $studentId): array
{
    return ['organization_id'=>$scope['organization_id'],'student_id'=>$studentId,'ay_id'=>$period['ay_id'],
        'semester'=>$period['semester'],'exam_term'=>$period['exam_term']];
}

function synk_management_correction_post(mysqli $conn, array $scope, array $period, int $studentId, array $selected, array $input): array
{
    if (!synk_management_payment_reversals_ready($conn)) throw new RuntimeException('Payment correction storage is unavailable.');
    if (($_SESSION['role'] ?? '') !== 'management' || ($_SESSION['auth_method'] ?? '') !== 'google' || empty($_SESSION['user_id'])) {
        throw new SynkManagementPermissionDenied('Use your assigned faculty account and Management organization for payment corrections.');
    }
    $key = $input['request_key'] ?? null;
    $version = $input['receipt_version'] ?? null;
    if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D',$key)
        || !is_string($version) || !preg_match('/^[a-f0-9]{64}$/D',$version)) {
        throw new DomainException('Reload the student’s paid receipts before deleting payments.',422);
    }
    $reason = synk_management_input_text($input,'reason',500,true,false);
    $context = synk_management_correction_context($scope,$period,$studentId);
    $conn->begin_transaction();
    try {
        // Lock the Management assignment and active faculty link until the reversal commits.
        $actor = synk_management_lock_assignment($conn,$scope);
        if (!synk_management_faculty_supervisor($conn,$actor,true)) {
            throw new SynkManagementPermissionDenied('Payment corrections are available to the assigned faculty supervisor.');
        }
        $actorId = (int)$actor['user_id'];
        $hash = hash('sha256',json_encode([$context,array_keys($selected),$reason,$actorId,$version]));
        $prior = synk_management_query($conn,'SELECT reversal_id,reversal_type,payload_hash FROM tbl_management_payment_reversals
            WHERE organization_id=? AND request_key=?','is',[$scope['organization_id'],$key])[0] ?? null;
        if ($prior) {
            if (!hash_equals($prior['payload_hash'],$hash)) throw new SynkManagementConflict('This correction request was already used.');
            $conn->commit();
            return ['reference'=>synk_management_payment_reversal_reference($prior['reversal_type'],$prior['reversal_id']),'duplicate'=>true];
        }
        $receipts = synk_management_correction_receipts($conn,$scope,$period,$studentId);
        if (!hash_equals(synk_management_correction_version($context,$receipts),$version)) {
            throw new SynkManagementConflict('The paid receipts changed. Select the payments again.');
        }
        $amounts = synk_management_correction_amounts($receipts,$selected);
        $total = array_sum($amounts);
        $summary = synk_management_student_financial_overview($conn,$scope,$period,$studentId)['summary'];
        $assessed = synk_management_money_cents($summary['contribution_catalog_total'])
            + synk_management_money_cents($summary['event_fine_total']) + synk_management_money_cents($summary['violation_total']);
        $credited = synk_management_money_cents($summary['amount_paid']);
        if ($total > $credited) throw new SynkManagementConflict('The selected payments exceed this student’s paid credit.');
        $after = synk_management_balance_summary($assessed,$credited-$total)['total_to_pay'];
        $values = [$key,$hash,$scope['college_id'],$scope['organization_id'],$studentId,$period['ay_id'],$period['semester'],
            $period['exam_term'],'VOID','SELECTED',$reason,null,
            synk_management_money_text($total),$summary['total_to_pay'],$after,$actorId,$actor['username']];
        synk_management_execute($conn,'INSERT INTO tbl_management_payment_reversals
            (request_key,payload_hash,college_id,organization_id,student_id,ay_id,semester,exam_term,reversal_type,
                selection_mode,reason,refund_reference,amount,balance_before,balance_after,recorded_by,recorded_by_name,recorded_at)
            VALUES ('.implode(',',array_fill(0,count($values),'?')).',UTC_TIMESTAMP())',str_repeat('s',count($values)),$values);
        $newId = (string)$conn->insert_id;
        foreach (array_chunk($amounts,200,true) as $chunk) {
            $params = [];
            foreach ($chunk as $itemId=>$amount) array_push($params,$newId,(string)$itemId,synk_management_money_text($amount));
            synk_management_execute($conn,'INSERT INTO tbl_management_payment_reversal_items (reversal_id,payment_item_id,amount)
                VALUES '.implode(',',array_fill(0,count($chunk),'(?,?,?)')),str_repeat('s',count($params)),$params);
        }
        $conn->commit();
        return ['reference'=>synk_management_payment_reversal_reference('VOID',$newId),'duplicate'=>false];
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
