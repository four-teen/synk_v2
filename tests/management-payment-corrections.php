<?php
// Exercises the Management correction ledger and readers against MySQL. All fixture rows roll back.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require __DIR__.'/../backend/db.php';
require __DIR__.'/../backend/management_payment_correction_helper.php';
require __DIR__.'/../backend/management_reports_helper.php';
require __DIR__.'/../backend/management_payment_register_helper.php';
require __DIR__.'/fixtures/management-payment-correction-data.php';

class ManagementCorrectionTestConnection extends mysqli
{
    public $failItems = false;
    public function startFixtures(): void { parent::begin_transaction(); }
    public function discardFixtures(): void { parent::rollback(); }
    public function begin_transaction($flags = 0, $name = null): bool { return $this->query('SAVEPOINT correction_qa'); }
    public function commit($flags = 0, $name = null): bool { return $this->query('RELEASE SAVEPOINT correction_qa'); }
    public function rollback($flags = 0, $name = null): bool
    {
        $this->query('ROLLBACK TO SAVEPOINT correction_qa');
        return $this->query('RELEASE SAVEPOINT correction_qa');
    }
    #[\ReturnTypeWillChange]
    public function prepare($query)
    {
        if ($this->failItems && strpos($query,'INSERT INTO tbl_management_payment_reversal_items') === 0) {
            throw new RuntimeException('Simulated allocation storage failure.');
        }
        return parent::prepare($query);
    }
}

$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
};
$reject = static function (callable $call, string $message) use ($check): void {
    try {
        $call();
    } catch (DomainException | OutOfBoundsException | SynkManagementConflict $error) {
        $check(true,$message);
        return;
    }
    $check(false,$message);
};
if (!synk_management_payment_reversals_ready($conn)) throw new RuntimeException('Apply management_payment_reversals.sql to the local test database.');
$snapshot = static function () use ($conn): array {
    $tables = ['tbl_management_payment_reversals','tbl_management_payment_reversal_items','tbl_management_payments',
        'tbl_management_payment_items','tbl_management_period_settings','tbl_academic_settings','tbl_student_management'];
    $hashes = [];
    foreach ($tables as $table) {
        $hashes[$table] = hash('sha256',json_encode($conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));
    }
    return $hashes;
};
$before = $snapshot();
$db = new ManagementCorrectionTestConnection($servername,$username,$password,$dbase);
$db->set_charset('utf8mb4');
$db->startFixtures();
try {
    ['scope'=>$scope,'otherScope'=>$otherScope,'period'=>$period,'midterm'=>$midterm,'admin'=>$admin,'cashier'=>$cashier,
        'supervisor'=>$supervisor,'faculty'=>$faculty,'officer'=>$officer,
        'student'=>$student,'otherStudent'=>$otherStudent,'materials'=>$materials,'event'=>$event,'tag'=>$tag,
        'split'=>$split,'single'=>$single,'historical'=>$historical,'foreign'=>$foreign,'sibling'=>$sibling] = synk_qa_management_payment_correction_data($db);
    $adminSession = ['user_id'=>$admin,'role'=>'admin','auth_method'=>'google'];
    $facultySession = ['user_id'=>$supervisor,'role'=>'management','auth_method'=>'google','username'=>'Stale session name'];
    $cashierSession = ['user_id'=>$cashier,'role'=>'management','auth_method'=>'google','username'=>"$tag management"];
    $_SESSION = $facultySession;
    $context = synk_management_correction_context($scope,$period,$student);
    $overview = static function () use ($db,$scope,$period,$student): array {
        return synk_management_student_financial_overview($db,$scope,$period,$student);
    };
    $receipts = static function () use ($db,$scope,$period,$student): array {
        return synk_management_correction_receipts($db,$scope,$period,$student);
    };
    $form = static function () use ($context,$receipts): array {
        return ['receipt_version'=>synk_management_correction_version($context,$receipts()),
            'request_key'=>bin2hex(random_bytes(32)),'reason'=>'Verified recording error'];
    };
    $select = static function (array $ids): array {
        return synk_management_correction_selection(json_encode($ids));
    };
    $post = static function (array $checked, array $input) use ($db,$scope,$period,$student): array {
        return synk_management_correction_post($db,$scope,$period,$student,$checked,$input);
    };
    $original = static function () use ($db,$scope,$student): array {
        return [synk_management_query($db,'SELECT * FROM tbl_management_payments WHERE organization_id=? AND student_id=? ORDER BY payment_id','ii',[$scope['organization_id'],$student]),
            synk_management_query($db,'SELECT i.* FROM tbl_management_payment_items i JOIN tbl_management_payments p USING (payment_id)
                WHERE p.organization_id=? AND p.student_id=? ORDER BY i.payment_item_id','ii',[$scope['organization_id'],$student])];
    };
    $originalRows = $original();

    $hits = synk_management_correction_search($db,$scope,$period,strtolower($split['number']),1)['rows'];
    $check(count($hits) === 1 && (int)$hits[0]['student_id'] === $student && $hits[0]['total_paid'] === '135.00',
        'Searching a receipt should return its whole student account.');
    $check(!synk_management_correction_search($db,$scope,$period,$historical['number'],1)['rows'],'Search leaked another period.');
    $check(!synk_management_correction_search($db,$scope,$period,'%',1)['rows'],'Search wildcard was not escaped.');
    $check($overview()['summary']['payment_status'] === 'PAID','Fixture should begin with a fully paid account.');
    $first = $select([$split['items']['E']]);
    $wholeReceipt = $select(array_values($single['items']));
    $initial = $form();
    foreach ([$sibling['items']['C'],$foreign['items']['C'],$historical['items']['C']] as $outside) {
        $reject(static function () use ($post,$select,$initial,$outside,$split) {
            $post($select([$split['items']['E'],$outside]),$initial);
        },'Deleted a payment alongside an item outside the selected student, organization, or exam term.');
    }
    $reject(static function () use ($select) { $select([]); },'An empty selection was accepted.');
    $reject(static function () use ($select,$split) { $select([$split['items']['E'],$split['items']['E']]); },'A payment was selected twice.');
    $reject(static function () use ($select,$split) {
        $select([['id'=>$split['items']['E'],'amount'=>'0.01']]);
    },'Client-supplied payment amounts were accepted.');
    $reject(static function () use ($post,$first,$initial) {
        $post($first,array_replace($initial,['reason'=>' ']));
    },'A payment was deleted without a reason.');
    $check(!synk_management_correction_history($db,$scope,$period,$student),'A rejected request inserted a correction.');
    $_SESSION = $adminSession;
    $reject(static function () use ($post,$first,$initial) { $post($first,$initial); },'An institutional administrator without a faculty Management session deleted a payment.');
    $_SESSION = $cashierSession;
    $reject(static function () use ($post,$first,$initial) { $post($first,$initial); },'An unlinked Management cashier deleted a payment.');
    $_SESSION = array_replace($cashierSession,['user_id'=>$officer]);
    $officerPermissions = synk_management_permissions($db,$scope);
    $check($officerPermissions['payments'] && !$officerPermissions['payment_corrections'],'A student officer payment grant included corrections.');
    $reject(static function () use ($post,$first,$initial) { $post($first,$initial); },'A student officer with the payment grant deleted a payment.');
    $_SESSION = $facultySession;
    $reject(static function () use ($db,$scope,$period,$otherStudent,$first,$initial) {
        synk_management_correction_post($db,$scope,$period,$otherStudent,$first,$initial);
    },'A receipt version for another student was accepted.');
    $reject(static function () use ($db,$otherScope,$period,$student,$first,$initial) {
        synk_management_correction_post($db,$otherScope,$period,$student,$first,$initial);
    },'A faculty supervisor deleted a payment while supplying another organization as the assignment.');
    synk_management_execute($db,"UPDATE tbl_useraccount SET status='inactive' WHERE user_id=?",'i',[$supervisor]);
    $reject(static function () use ($post,$first,$initial) { $post($first,$initial); },'A disabled Management account deleted a payment.');
    synk_management_execute($db,"UPDATE tbl_useraccount SET status='active' WHERE user_id=?",'i',[$supervisor]);
    synk_management_execute($db,"UPDATE tbl_faculty SET status='inactive' WHERE faculty_id=?",'i',[$faculty]);
    $reject(static function () use ($post,$first,$initial) { $post($first,$initial); },'A supervisor deleted a payment after the linked faculty record became inactive.');
    synk_management_execute($db,"UPDATE tbl_faculty SET status='active' WHERE faculty_id=?",'i',[$faculty]);

    $db->failItems = true;
    try {
        $post($first,$initial);
        $check(false,'A failed reversal allocation returned success.');
    } catch (RuntimeException $error) {
        $check($error->getMessage() === 'Simulated allocation storage failure.','Unexpected storage error during atomicity test.');
    } finally {
        $db->failItems = false;
    }
    $check(!synk_management_correction_history($db,$scope,$period,$student) && $overview()['summary']['amount_paid'] === '135.00',
        'Failed allocation write left a correction or reduced payment credit.');
    $saved = $post($first,$initial);
    $retry = $post($first,$initial);
    $check(!$saved['duplicate'] && $retry['duplicate'] && $retry['reference'] === $saved['reference'],'Retry recorded a second correction.');
    $reject(static function () use ($post,$first,$initial) { $post($first,array_replace($initial,['reason'=>'Different request'])); },
        'A used request key accepted a different correction.');
    $reject(static function () use ($post,$wholeReceipt,$initial) {
        $post($wholeReceipt,array_replace($initial,['request_key'=>bin2hex(random_bytes(32))]));
    },'A stale receipt list could delete payments after another correction.');

    $history = synk_management_correction_history($db,$scope,$period,$student);
    $check(count($history) === 1 && $history[0]['recorded_by_name'] === "$tag faculty" && $history[0]['reason'] === $initial['reason']
        && $history[0]['balance_before'] === '0.00' && $history[0]['balance_after'] === '25.00',
        'Audit history lost the faculty supervisor, reason, or account balances.');
    $after = $overview();
    $directory = synk_management_student_balances($db,$scope,$period,[$student])[$student];
    $check($after['summary']['amount_paid'] === '110.00' && $after['summary']['total_to_pay'] === '25.00' && $directory['total_to_pay'] === '25.00',
        'Student overview or batched directory balance missed the selected deletion.');
    $chargeAmounts = array_column($after['charges'],'amount_due','key');
    $splitAfter = $receipts()[$split['id']]['items'];
    $check($chargeAmounts['E:'.$event] === '25.00' && $chargeAmounts['C:'.$materials] === '0.00'
        && $splitAfter[$split['items']['C']]['remaining'] === 6000 && $splitAfter[$split['items']['V']]['remaining'] === 1000,
        'Deleting one checkbox affected an unchecked payment.');
    $account = synk_management_student_account($db,$student,'FINAL');
    $check($account['payment_history_total'] === '118.00','Student view lost the other organization or counted a Midterm receipt.');
    $dashboard = synk_management_collection_totals($db,$scope,$period);
    $detail = synk_management_collection_student_payments($db,$scope,$period,$student);
    $check($dashboard['collected'] === '170.00' && $detail['total_paid'] === '110.00' && $detail['receipts'][1]['payment_state'] === 'Partially reversed',
        'Dashboard summary or expanded receipts used gross totals.');
    $report = synk_management_report_collections($db,$scope,$period,true);
    $fines = synk_management_report_absence_fines($db,$scope,$period,$event);
    $check($report['totals']['reconciled'] && $report['totals']['collected'] === '170.00' && $report['totals']['voided'] === '25.00'
        && $report['categories']['contributions']['collected'] === '160.00' && $report['categories']['event_fines']['collected'] === '0.00'
        && $report['categories']['violations']['collected'] === '10.00','Collection report lost category reconciliation.');
    $check($fines['totals']['collected'] === '0.00' && $fines['totals']['outstanding'] === '25.00','Absence fine report ignored the deletion.');
    $register = synk_management_payment_register($db,$scope,$period,$period,['search'=>$split['number']]);
    $check($register['filtered_total'] === '70.00' && $register['filtered_reversed'] === '25.00'
        && $register['today_totals']['collected'] === '-25.00' && (int)$register['today_totals']['receipts'] === 0,
        'Register used gross totals or assigned a prior-day correction to the original receipt date.');
    $check(synk_management_account_money('-0.09') === '-₱0.09','Signed daily amount lost centavos.');

    $post($wholeReceipt,$form());
    $receipt = synk_management_payment_receipt($db,$single['id'],$student);
    $check($receipt['amount'] === '40.00' && $receipt['net_amount'] === '0.00' && $receipt['payment_state'] === 'Voided',
        'Full receipt void changed the original amount or failed to remove its remaining credit.');
    $check(!synk_management_student_charge_has_credit($db,$student,'contributions',$materials)
        && synk_management_charge_has_payments($db,'contributions',$materials),'Fully reversed allocation did not distinguish active credit from history.');
    $cashierVersion = $overview()['account_version'];
    $stale = $form();
    // A stored refund from the previous workflow leaves a partial payment available.
    $oldValues = [bin2hex(random_bytes(32)),str_repeat('a',64),$scope['college_id'],$scope['organization_id'],$student,$period['ay_id'],
        $period['semester'],$period['exam_term'],'REFUND','SELECTED','Previous refund','Voucher QA-55','12.34','65.00','77.34',$supervisor,"$tag faculty"];
    synk_management_execute($db,'INSERT INTO tbl_management_payment_reversals
        (request_key,payload_hash,college_id,organization_id,student_id,ay_id,semester,exam_term,reversal_type,selection_mode,
            reason,refund_reference,amount,balance_before,balance_after,recorded_by,recorded_by_name,recorded_at)
        VALUES ('.implode(',',array_fill(0,count($oldValues),'?')).',UTC_TIMESTAMP())',str_repeat('s',count($oldValues)),$oldValues);
    $oldId = (string)$db->insert_id;
    synk_management_execute($db,'INSERT INTO tbl_management_payment_reversal_items (reversal_id,payment_item_id,amount) VALUES (?,?,?)',
        'sss',[$oldId,$split['items']['C'],'12.34']);
    $last = $select([$split['items']['C'],$split['items']['V']]);
    $reject(static function () use ($post,$last,$stale) { $post($last,$stale); },'An updated payment could be deleted at a stale amount.');
    $deleted = $post($last,$form());
    $lastEntry = synk_management_correction_history($db,$scope,$period,$student)[0];
    $check($lastEntry['amount'] === '57.66' && $lastEntry['balance_before'] === '77.34' && $lastEntry['balance_after'] === '135.00'
        && $overview()['summary']['payment_status'] === 'UNPAID',
        'Selecting the whole receipt did not delete exactly the payments still paid.');
    $check(synk_management_payment_receipt($db,$historical['id'],$student)['net_amount'] === '7.00'
        && synk_management_payment_receipt($db,$foreign['id'],$student)['net_amount'] === '8.00'
        && synk_management_payment_receipt($db,$sibling['id'],$otherStudent)['net_amount'] === '60.00',
        'Deleting all paid payments affected another student, Management period, or organization.');
    $list = synk_management_collection_students($db,$scope,$period,1);
    $check($list['total_students'] === 1 && $list['total_collected'] === '60.00','A zero-credit student remained counted in collections.');
    $check(synk_management_report_collections($db,$scope,$period,false)['totals']['refunded'] === '12.34','The collection report lost previously saved cash refunds.');
    $receipt = synk_management_payment_receipt($db,$split['id'],$student);
    ob_start();
    require __DIR__.'/../management/payment-receipt-view.php';
    $html = ob_get_clean();
    $check($receipt['payment_state'] === 'Fully reversed' && count($receipt['adjustments']) === 4
        && strpos($html,$split['number']) !== false && strpos($html,'data-receipt-net>₱0.00') !== false
        && strpos($html,$deleted['reference']) !== false && strpos($html,'Voucher QA-55') === false,
        'A mixed correction, original receipt reference, or receipt privacy failed in the printout.');
    $check($original() === $originalRows,'A correction modified or removed original receipts or allocations.');
    $reject(static function () use ($post,$last,$form) { $post($last,$form()); },'Deleted payments could be deleted again.');

    $historicalContext = synk_management_correction_context($scope,$midterm,$student);
    $historicalVersion = synk_management_correction_version($historicalContext,synk_management_correction_receipts($db,$scope,$midterm,$student));
    synk_management_correction_post($db,$scope,$midterm,$student,$select(array_values($historical['items'])),
        ['receipt_version'=>$historicalVersion,'request_key'=>bin2hex(random_bytes(32)),'reason'=>'Correct historical receipt']);
    $check(synk_management_payment_receipt($db,$historical['id'],$student)['payment_state'] === 'Voided'
        && synk_management_fetch_current_period($db,$scope)['exam_term'] === 'FINAL','Historical correction changed the current Management term.');

    $_SESSION = $cashierSession;
    $paymentInput = ['student_id'=>$student,'term'=>$period['key'],'exam_term'=>'FINAL','current_term'=>$period['key'],
        'current_exam_term'=>'FINAL','current_period_version'=>$period['period_version'],'amount'=>'40.00','allocation_mode'=>'SELECTED',
        'selected'=>json_encode(['C:'.$materials]),'account_version'=>$overview()['account_version'],'request_key'=>bin2hex(random_bytes(32))];
    $reject(static function () use ($db,$scope,$paymentInput,$single) {
        synk_management_payment_preview($db,$scope,$paymentInput+['receipt_number'=>$single['number']]);
    },'A voided original receipt number could be reused.');
    $reject(static function () use ($db,$scope,$paymentInput,$cashierVersion) {
        synk_management_payment_post($db,$scope,array_replace($paymentInput,['account_version'=>$cashierVersion]));
    },'A cashier could post using a balance reviewed before a correction.');
    synk_management_payment_post($db,$scope,$paymentInput);
    $check($overview()['summary']['total_to_pay'] === '95.00','A cashier could not re-collect against reopened credit.');
} finally {
    $db->discardFixtures();
    $db->close();
}
$check($snapshot() === $before,'Fixture records escaped rollback.');
echo 'Management payment corrections: ',$checks,' checks passed. Fixtures rolled back.',PHP_EOL;
