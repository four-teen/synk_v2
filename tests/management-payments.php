<?php
// Real native ledger, allocation, receipts and account views; rollback all rows.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require __DIR__.'/../backend/db.php';
require __DIR__.'/../backend/management_payment_helper.php';
require __DIR__.'/../backend/management_attendance_helper.php';
require __DIR__.'/../backend/management_student_account_helper.php';
class PaymentQAConnection extends mysqli {
    public $failItems=false;
    public function start():void{parent::begin_transaction();}
    public function discard():void{parent::rollback();}
    public function begin_transaction($flags=0,$name=null):bool{return $this->query('SAVEPOINT payment_qa');}
    public function commit($flags=0,$name=null):bool{return $this->query('RELEASE SAVEPOINT payment_qa');}
    public function rollback($flags=0,$name=null):bool{$this->query('ROLLBACK TO SAVEPOINT payment_qa');return $this->query('RELEASE SAVEPOINT payment_qa');}
    public function prepare($sql){if($this->failItems && strpos($sql,'INSERT INTO tbl_management_payment_items')===0)throw new RuntimeException('Deliberate item write failure.');return parent::prepare($sql);}
}
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$tables=['tbl_management_payments','tbl_management_payment_items','tbl_management_organizations','tbl_management_period_settings','tbl_management_contributions','tbl_management_events','tbl_management_attendance_events','tbl_management_attendance_roster','tbl_management_attendance_logs','tbl_management_attendance_audit','tbl_useraccount','tbl_useraccount_roles','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_academic_settings','tbl_class_schedule','tbl_faculty_workload_sched'];
$snapshot=static function()use($conn,$tables):string{$rows=[];foreach($tables as$table)$rows[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($rows));};
$before=$snapshot();$db=new PaymentQAConnection($servername,$username,$password,$dbase);$db->set_charset('utf8mb4');$db->start();
$reject=static function(callable $fn,string $message)use($check):void{try{$fn();}catch(DomainException|SynkManagementConflict|OutOfBoundsException $error){$check(true,$message);return;}$check(false,$message);};
try{
    $insert=static function(string $table,array $row)use($db):int{synk_management_execute($db,'INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',str_repeat('s',count($row)),array_values($row));return (int)$db->insert_id;};
    $tag='PAYMENT QA '.bin2hex(random_bytes(5));$college=$db->query("SELECT p.program_id,c.college_id,c.campus_id FROM tbl_program p JOIN tbl_college c ON c.college_id=p.college_id WHERE p.status='active' AND c.status='active' ORDER BY p.program_id LIMIT 1")->fetch_assoc();
    $org=$insert('tbl_management_organizations',['college_id'=>$college['college_id'],'organization_code'=>$tag,'organization_name'=>$tag]);
    $scope=['college_id'=>(int)$college['college_id'],'campus_id'=>(int)$college['campus_id'],'organization_id'=>$org,'organization_code'=>$tag,'organization_name'=>$tag];
    $academic=synk_fetch_current_academic_term($db);
    $insert('tbl_management_period_settings',['organization_id'=>$org,'college_id'=>$college['college_id'],'ay_id'=>$academic['ay_id'],'semester'=>$academic['semester'],'current_exam_term'=>'FINAL']);
    $user=$insert('tbl_useraccount',['username'=>$tag,'email'=>str_replace(' ','',$tag).'@gmail.com','password'=>'unused','role'=>'management','status'=>'active','management_college_id'=>$college['college_id'],'management_organization_id'=>$org]);
    synk_persist_useraccount_roles($db,$user,['management'],'management');$_SESSION=['user_id'=>$user,'role'=>'management','auth_method'=>'google','username'=>$tag];
    $period=synk_management_fetch_current_period($db,$scope);
    $input=static function(array $row=[])use($period,$scope):array{return $row+['term'=>$period['key'],'exam_term'=>$period['exam_term'],'current_term'=>$period['key'],'current_exam_term'=>$period['exam_term'],'current_period_version'=>$period['period_version'],'organization_id'=>$scope['organization_id']];};
    $student=$insert('tbl_student_management',['student_number'=>$tag,'first_name'=>'TEST','last_name'=>'PAYMENT','email_address'=>'paymentqa-'.bin2hex(random_bytes(4)).'@sksu.edu.ph','source_sheet_name'=>'DEAN MANUAL','program_id'=>$college['program_id'],'ay_id'=>$period['ay_id'],'semester'=>$period['semester'],'year_level'=>2]);
    $contributions=[];foreach(['150.00','400.00','400.00']as$i=>$amount)$contributions[]=synk_management_catalog_write($db,$scope,'contributions','save',$input(['title'=>$tag.' ITEM '.$i,'contribution_date'=>date('Y-m-d'),'amount'=>$amount]))['record_id'];
    $overview=static function()use($db,$scope,$period,$student):array{return synk_management_student_financial_overview($db,$scope,$period,$student);};
    $make=static function(string $amount,string $mode='DISTRIBUTE',array $selected=[])use($input,$student,$overview):array{return $input(['student_id'=>(string)$student,'allocation_mode'=>$mode,'amount'=>$amount,'selected'=>json_encode($selected),'account_version'=>$overview()['account_version'],'request_key'=>bin2hex(random_bytes(32))]);};
    $payment=$make('500');$preview=synk_management_payment_preview($db,$scope,$payment);
    $check(array_column($preview['items'],'amount')===['150.00','175.00','175.00'],'Distribute did not cap the small charge and redistribute whole pesos.');
    $check($preview['balance_after']==='450.00','Preview balance is wrong.');
    $pure=$overview()['charges'];foreach($pure as&$row){$row['amount_due']='400.00';$row['amount']='400.00';}unset($row);
    $check(array_column(synk_management_payment_allocate($pure,'DISTRIBUTE',50000,[]),'amount')===['167.00','167.00','166.00'],'The extra pesos were lost or split into centavos.');
    foreach([1,2,3,7,1199,1200]as$pesos){$items=synk_management_payment_allocate($pure,'DISTRIBUTE',$pesos*100,[]);$sum=0;foreach($items as$item){$value=synk_management_money_cents($item['amount']);$sum+=$value;$check($value%100===0&&$value<=40000,'Distribution used centavos or exceeded an item balance.');}$check($sum===$pesos*100,'Distribution did not allocate every entered peso.');}
    $fractional=$pure;$fractional[0]['amount_due']='10.50';$fractional[1]['amount_due']='10.25';$fractional[2]['amount_due']='0.75';
    $check(array_column(synk_management_payment_allocate($fractional,'DISTRIBUTE',1500,[]),'amount')===['8.00','7.00'],'Whole-peso distribution rounded centavo balances upward.');
    $check(synk_management_payment_allocate($fractional,'SELECTED',1050,[$fractional[0]['key']])[0]['amount']==='10.50','Selected cannot settle an exact centavo balance.');
    $reject(static function()use($pure){synk_management_payment_allocate($pure,'DISTRIBUTE',10001,[]);},'Fractional Distribute payment was accepted.');
    $reject(static function()use($pure){synk_management_payment_allocate($pure,'DISTRIBUTE',120100,[]);},'Overpayment was accepted.');
    $reject(static function()use($db,$scope,$make){synk_management_payment_post($db,$scope,$make('0'));},'Zero payment was accepted.');
    $reject(static function()use($db,$scope,$make){synk_management_payment_post($db,$scope,$make('-10'));},'Negative payment was accepted.');
    $db->failItems=true;try{synk_management_payment_post($db,$scope,$payment);$check(false,'Partial ledger write was accepted.');}catch(RuntimeException $error){$check(strpos($error->getMessage(),'Deliberate')!==false,'Wrong simulated storage error.');}finally{$db->failItems=false;}
    $check(count(synk_management_payment_history($db,$student))===0 && $overview()['summary']['amount_paid']==='0.00','Failed allocation left a payment header or reduced the balance.');
    $posted=synk_management_payment_post($db,$scope,$payment);$check(!$posted['duplicate'],'First payment was marked duplicate.');
    $check($overview()['summary']['amount_paid']==='500.00' && $overview()['summary']['total_to_pay']==='450.00' && $overview()['summary']['payment_status']==='PARTIALLY_PAID','Posted payment did not update the account.');
    $duplicate=synk_management_payment_post($db,$scope,$payment);$check($duplicate['duplicate'] && $duplicate['payment_id']==$posted['payment_id'] && count(synk_management_payment_history($db,$student))===1,'Retry created a second payment.');
    $reject(static function()use($db,$scope,$payment){$payment['amount']='501';synk_management_payment_post($db,$scope,$payment);},'Reused request key accepted a different amount.');
    $reject(static function()use($db,$scope,$payment){$payment['request_key']=bin2hex(random_bytes(32));synk_management_payment_post($db,$scope,$payment);},'Stale balance accepted a concurrent second payment.');
    $reject(static function()use($db,$scope,$make){$p=$make('10');$p['exam_term']='MIDTERM';synk_management_payment_post($db,$scope,$p);},'Historical-period payment was accepted.');
    $reject(static function()use($db,$scope,$make){$p=$make('10');$p['organization_id']=$scope['organization_id']+1;synk_management_payment_post($db,$scope,$p);},'Another organization was accepted.');
    $reject(static function()use($db,$scope,$make){$p=$make('10');$p['student_id']='4294967295';synk_management_payment_post($db,$scope,$p);},'Unavailable student was accepted.');
    $keys=['C:'.$contributions[1],'C:'.$contributions[2]];
    $reject(static function()use($db,$scope,$make,$keys){synk_management_payment_post($db,$scope,$make('449','SELECTED',$keys));},'Selected accepted an amount that does not settle the checked charges.');
    $reject(static function()use($db,$scope,$make,$keys){synk_management_payment_post($db,$scope,$make('450','SELECTED',[$keys[0],$keys[0]]));},'A duplicate selected charge was accepted.');
    $settled=synk_management_payment_post($db,$scope,$make('450','SELECTED',$keys));
    $check($overview()['summary']['total_to_pay']==='0.00' && $overview()['summary']['payment_status']==='PAID' && !$overview()['can_pay'],'Selected did not fully settle the account.');
    $check(count(synk_management_payment_history($db,$student,$scope,$period))===2,'Management history omitted a receipt.');
    $account=synk_management_student_account($db,$student);$check(count($account['payments'])===2 && $account['totals']['paid']==='950.00','Student history or current paid total differs from Management.');
    $receipt=synk_management_payment_receipt($db,(int)$posted['payment_id'],$student);
    $check(count($receipt['items'])===3 && $receipt['amount']==='500.00' && $receipt['balance_after']==='450.00','Receipt itemization or snapshots differ from the posted payment.');
    $reject(static function()use($db,$posted,$student){synk_management_payment_receipt($db,(int)$posted['payment_id'],$student+1);},'Another student accessed a receipt.');
    $reject(static function()use($db,$posted,$scope){$scope['organization_id']++;synk_management_payment_receipt($db,(int)$posted['payment_id'],null,$scope);},'Another organization accessed a receipt.');
    $db->query("UPDATE tbl_student_management SET first_name='CHANGED FIXTURE' WHERE student_id=".$student);
    $check(synk_management_payment_receipt($db,(int)$posted['payment_id'],$student)['student_name']==='TEST PAYMENT','Receipt identity changed after a profile edit.');
    $record=synk_management_catalog_get($db,$scope,'contributions',$contributions[0]);
    $reject(static function()use($db,$scope,$input,$record,$contributions){synk_management_catalog_write($db,$scope,'contributions','delete',$input(['record_id'=>$contributions[0],'version'=>$record['version']]));},'A paid charge was deleted.');
    // Other real organizations can still assess this new fixture student. Render
    // the fully-settled display state without changing any organization's data.
    $financialAccount=$account;$financialAccount['totals']['due']='0.00';ob_start();require __DIR__.'/../management/student-financial-page.php';$html=ob_get_clean();
    $check(strpos($html,'student-finance-due is-paid')!==false && strpos($html,'payment-receipt.php?payment_id='.$posted['payment_id'])!==false,'Student view omitted paid styling or receipt links.');
    ob_start();require __DIR__.'/../management/payment-receipt-view.php';$html=ob_get_clean();$check(strpos($html,$posted['receipt_number'])!==false && strpos($html,'500.00')!==false,'Printable receipt did not render.');
    $event=synk_management_catalog_write($db,$scope,'events','save',$input(['title'=>$tag.' FINE','event_date'=>date('Y-m-d'),'absence_fine'=>'80.00']))['record_id'];
    $opened=synk_management_attendance_write($db,$scope,'attendance_open',$input(['event_id'=>$event]));
    synk_management_attendance_write($db,$scope,'attendance_close',$input(['event_id'=>$event,'attendance_revision'=>$opened['attendance']['revision'],'confirm'=>'1']));
    $fine=synk_management_payment_post($db,$scope,$make('80','SELECTED',['E:'.$event]));
    $check($overview()['summary']['total_to_pay']==='0.00' && $overview()['events'][0]['amount_due']==='0.00','A paid absence fine still appears outstanding.');
    $reject(static function()use($db,$scope,$input,$event){synk_management_attendance_write($db,$scope,'attendance_reopen',$input(['event_id'=>$event,'attendance_revision'=>'2','confirm'=>'1']));},'Attendance reopened after its fine was paid.');
    $db->query("UPDATE tbl_management_period_settings SET current_exam_term='MIDTERM' WHERE organization_id=".$org);
    $later=synk_management_student_account($db,$student);$check(count($later['payments'])===3 && $later['totals']['paid']==='0.00','A period change lost historical receipts or mixed their amounts into the new period.');
    $finalView=synk_management_student_account($db,$student,'FINAL');$midtermView=synk_management_student_account($db,$student,'MIDTERM');
    $check(count($finalView['payments'])===3 && $finalView['totals']['paid']==='1030.00' && count($midtermView['payments'])===0 && $midtermView['totals']['paid']==='0.00','Student financial selection mixed payments across terms.');
    $check(count($midtermView['all_payments'])===3 && $midtermView['payment_history_total']==='0.00','Other-term receipts were lost or added to selected-term totals.');
    $check($db->query('SELECT current_exam_term FROM tbl_management_period_settings WHERE organization_id='.$org)->fetch_assoc()['current_exam_term']==='MIDTERM','Reading Final financial details changed Management recording settings.');
    $_SESSION['management_student_financial_view']=['student_id'=>$student,'academic_key'=>$period['key'],'exam_term'=>'FINAL'];
    $check(synk_management_student_financial_view_term($db,$student,['exam_term'=>'MIDTERM','term'=>'999:3'])==='MIDTERM' && synk_management_student_financial_view_term($db,$student,[])==='MIDTERM','Financial viewing preference did not persist independently of academic input.');
}finally{$db->discard();$db->close();}
$check($snapshot()===$before,'Payment tests changed real records, shared profiles or academic settings.');
echo 'Management payments: ',$checks,' checks passed; all payment fixtures rolled back.',PHP_EOL;
