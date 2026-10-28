<?php
require_once __DIR__.'/management_catalog_helper.php';
require_once __DIR__.'/management_student_financial_helper.php';
require_once __DIR__.'/college_dean_students_helper.php';

function synk_management_payment_selection(array $input): array
{
    $json=$input['selected'] ?? '[]';
    $keys=is_string($json) && strlen($json)<20000 ? json_decode($json,true) : null;
    if (!is_array($keys) || array_values($keys)!==$keys || count($keys)>500) throw new DomainException('Choose valid payment items.');
    foreach ($keys as $key) if (!is_string($key) || !preg_match('/^[CEV]:[1-9][0-9]{0,9}$/D',$key)) throw new DomainException('Choose valid payment items.');
    if (count(array_unique($keys))!==count($keys)) throw new DomainException('Choose each payment item only once.');
    sort($keys,SORT_NATURAL);return $keys;
}

// Equal shares in whole pesos; saturate small balances, then redistribute the
// remainder. Stable charge order determines who receives each extra peso.
function synk_management_payment_allocate(array $charges,string $mode,int $amount,array $selected): array
{
    if (!in_array($mode,['DISTRIBUTE','SELECTED'],true)) throw new DomainException('Choose Distribute or Selected.');
    if ($amount<=0) throw new DomainException('Enter an amount greater than zero.');
    $available=[];
    foreach ($charges as $charge) if (synk_management_money_cents($charge['amount_due'])>0) $available[$charge['key']]=$charge;
    $allocations=[];
    if ($mode==='SELECTED') {
        if (!$selected) throw new DomainException('Select at least one unpaid charge.');
        $total=0;
        foreach ($selected as $key) {
            if (!isset($available[$key])) throw new SynkManagementConflict('A selected charge is no longer available. Reload the account.');
            $allocations[$key]=synk_management_money_cents($available[$key]['amount_due']);$total+=$allocations[$key];
        }
        if ($total!==$amount) throw new DomainException('Selected payments must equal the exact remaining balance of the checked charges.');
    } else {
        if ($selected) throw new DomainException('Distribute applies to all unpaid charges. Clear the selection.');
        if ($amount%100!==0) throw new DomainException('Enter whole pesos for Distribute. Use Selected for exact balances with centavos.');
        $capacity=[];
        foreach ($available as $key=>$charge) $capacity[$key]=intdiv(synk_management_money_cents($charge['amount_due']),100);
        $remaining=intdiv($amount,100);
        if ($remaining>array_sum($capacity)) throw new DomainException('This amount exceeds the balances available in whole pesos. Use Selected to settle any remaining centavos.');
        $pesos=array_fill_keys(array_keys($capacity),0);
        while ($remaining>0) {
            $active=array_filter($capacity,static function(int $value):bool{return $value>0;});
            $share=intdiv($remaining,count($active));
            $capped=array_filter($active,static function(int $value)use($share):bool{return $value<=$share;});
            if ($capped) {
                foreach ($capped as $key=>$value) {$pesos[$key]+=$value;$capacity[$key]=0;$remaining-=$value;}
                continue;
            }
            $extra=$remaining%count($active);$index=0;
            foreach ($active as $key=>$cap) {
                $give=$share+($index<$extra?1:0);$index++;
                $pesos[$key]+=$give;$capacity[$key]-=$give;$remaining-=$give;
            }
        }
        foreach ($pesos as $key=>$value) if ($value>0) $allocations[$key]=$value*100;
    }
    $items=[];
    foreach ($allocations as $key=>$value) {
        $row=$available[$key];
        $items[]=['key'=>$key,'title'=>$row['title'],'charge_amount'=>$row['amount'],'paid_before'=>$row['amount_paid'],
            'amount'=>synk_management_money_text($value),'balance_after'=>synk_management_money_text(synk_management_money_cents($row['amount_due'])-$value)];
    }
    return $items;
}

function synk_management_payment_context(mysqli $conn,array $scope,array $input,array $period,string $operation='recording payments'): int
{
    foreach (['college_id','campus_id','organization_id'] as $field) {
        if (isset($input[$field]) && (string)$input[$field]!== (string)$scope[$field]) throw new DomainException('Use your assigned organization and campus.');
    }
    if (($input['term'] ?? '')!==$period['key'] || ($input['exam_term'] ?? '')!==$period['exam_term']) {
        throw new SynkManagementConflict('Switch to the current Management period before '.$operation.'.');
    }
    $studentId=synk_management_input_id($input['student_id'] ?? null);
    $source=synk_dean_students_source($scope,['ay_id'=>$period['ay_id'],'semester'=>$period['semester']],$conn);
    if (!synk_management_query($conn,'SELECT DISTINCT s.student_id '.$source['sql'].' AND s.student_id=? LIMIT 1',$source['types'].'i',array_merge($source['params'],[$studentId]))) {
        throw new OutOfBoundsException('This student is unavailable in your college and current academic period.');
    }
    return $studentId;
}

function synk_management_payment_plan(array $financial,array $input): array
{
    if (!is_string($input['account_version'] ?? null) || !hash_equals($financial['account_version'],$input['account_version'])) {
        throw new SynkManagementConflict('This account changed. Reload the financial overview before paying.');
    }
    $amount=synk_management_money_cents(synk_management_input_money($input['amount'] ?? ''));
    $mode=(string)($input['allocation_mode'] ?? 'DISTRIBUTE');
    $items=synk_management_payment_allocate($financial['charges'],$mode,$amount,synk_management_payment_selection($input));
    return ['items'=>$items,'amount'=>synk_management_money_text($amount),'balance_after'=>synk_management_money_text(synk_management_money_cents($financial['summary']['total_to_pay'])-$amount)];
}

function synk_management_payment_preview(mysqli $conn,array $scope,array $input): array
{
    synk_management_require_permission($conn,$scope,'payments',(int)($input['student_id'] ?? 0));
    if (!synk_management_payments_ready($conn)) throw new RuntimeException('Payment storage is unavailable.');
    $period=synk_management_fetch_current_period($conn,$scope);
    $id=synk_management_payment_context($conn,$scope,$input,$period);
    return synk_management_payment_plan(synk_management_student_financial_overview($conn,$scope,$period,$id),$input);
}

function synk_management_payment_post(mysqli $conn,array $scope,array $input): array
{
    if (!synk_management_payments_ready($conn)) throw new RuntimeException('Payment storage is unavailable.');
    $key=$input['request_key'] ?? '';
    if (!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D',$key)) throw new DomainException('Reload the payment form before saving.');
    $amount=synk_management_input_money($input['amount'] ?? '');
    $mode=(string)($input['allocation_mode'] ?? 'DISTRIBUTE');
    $hash=hash('sha256',json_encode([$input['student_id'] ?? null,$mode,$amount,synk_management_payment_selection($input),$input['account_version'] ?? null]));
    $conn->begin_transaction();
    try {
        // Catalog, attendance and period writes also lock this organization.
        // Serialize recalculation and insert so concurrent cashiers cannot overpay.
        synk_management_lock_assignment($conn,$scope);
        synk_management_require_permission($conn,$scope,'payments',(int)($input['student_id'] ?? 0));
        $period=synk_management_lock_current_term($conn,$input,$scope);
        $id=synk_management_payment_context($conn,$scope,$input,$period);
        $prior=synk_management_query($conn,'SELECT payment_id,receipt_number,payload_hash,student_id,recorded_by FROM tbl_management_payments WHERE organization_id=? AND request_key=?','is',[$scope['organization_id'],$key]);
        if ($prior) {
            $saved=$prior[0];
            if ((int)$saved['student_id']!==$id || (int)$saved['recorded_by']!==(int)$_SESSION['user_id'] || !hash_equals($saved['payload_hash'],$hash)) throw new SynkManagementConflict('This payment request was already used. Reload the account.');
            $conn->commit();return ['payment_id'=>$saved['payment_id'],'receipt_number'=>$saved['receipt_number'],'receipt_url'=>'payment-receipt.php?payment_id='.$saved['payment_id'],'duplicate'=>true];
        }
        $financial=synk_management_student_financial_overview($conn,$scope,$period,$id);
        $plan=synk_management_payment_plan($financial,$input);
        $student=synk_management_query($conn,'SELECT student_number,first_name,last_name,middle_name,suffix_name FROM tbl_student_management WHERE student_id=? FOR UPDATE','i',[$id])[0];
        $name=trim(implode(' ',array_filter([$student['first_name'],$student['middle_name'],$student['last_name'],$student['suffix_name']])));
        $number='SQ-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(6)));
        $values=[$number,$key,$hash,$scope['college_id'],$scope['organization_id'],$id,$period['ay_id'],$period['semester'],$period['exam_term'],$mode,$plan['amount'],$financial['summary']['total_to_pay'],$plan['balance_after'],$student['student_number'],$name,$scope['organization_code'] ?? '',$scope['organization_name'] ?? '',$period['period_text'],(int)$_SESSION['user_id'],(string)($_SESSION['username'] ?? '')];
        synk_management_execute($conn,'INSERT INTO tbl_management_payments (receipt_number,request_key,payload_hash,college_id,organization_id,student_id,ay_id,semester,exam_term,allocation_mode,amount,balance_before,balance_after,student_number,student_name,organization_code,organization_name,period_text,recorded_by,recorded_by_name,recorded_at) VALUES ('.implode(',',array_fill(0,20,'?')).',UTC_TIMESTAMP())',str_repeat('s',20),$values);
        $paymentId=(int)$conn->insert_id;
        foreach ($plan['items'] as $item) {
            $chargeId=(int)substr($item['key'],2);
            $columns=['payment_id','charge_key','contribution_id','event_id','title','charge_amount','paid_before','amount','balance_after'];
            $values=[$paymentId,$item['key'],$item['key'][0]==='C'?$chargeId:null,$item['key'][0]==='E'?$chargeId:null,$item['title'],$item['charge_amount'],$item['paid_before'],$item['amount'],$item['balance_after']];
            if(synk_management_student_violations_ready($conn)){$columns[]='student_violation_id';$values[]=$item['key'][0]==='V'?$chargeId:null;}
            synk_management_execute($conn,'INSERT INTO tbl_management_payment_items ('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')',str_repeat('s',count($columns)),$values);
        }
        synk_management_audit_officer_operation($conn,$scope,'PAYMENT',['payment_id'=>$paymentId,'student_id'=>$id,'amount'=>$plan['amount'],'receipt'=>$number]);
        $conn->commit();
        return ['payment_id'=>$paymentId,'receipt_number'=>$number,'receipt_url'=>'payment-receipt.php?payment_id='.$paymentId,'duplicate'=>false];
    } catch (Throwable $error) { $conn->rollback();throw $error; }
}

function synk_management_payment_receipt(mysqli $conn,int $paymentId,?int $studentId=null,?array $scope=null): array
{
    if (!$studentId && !$scope) throw new OutOfBoundsException('Receipt unavailable.');
    $sql='SELECT * FROM tbl_management_payments WHERE payment_id=?';$types='i';$params=[$paymentId];
    if ($studentId) { $sql.=' AND student_id=?';$types.='i';$params[]=$studentId; }
    else { $sql.=' AND college_id=? AND organization_id=?';$types.='ii';$params[]=$scope['college_id'];$params[]=$scope['organization_id']; }
    $rows=synk_management_query($conn,$sql,$types,$params);
    if (!$rows) throw new OutOfBoundsException('Receipt unavailable.');
    $receipt=$rows[0];$receipt['items']=synk_management_query($conn,'SELECT title,charge_amount,paid_before,amount,balance_after FROM tbl_management_payment_items WHERE payment_id=? ORDER BY payment_item_id','i',[$paymentId]);
    $receipt['recorded_at']=(new DateTimeImmutable($receipt['recorded_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A');
    return $receipt;
}
