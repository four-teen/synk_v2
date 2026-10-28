<?php
// Fresh native assessments, allocations and permissions; all fixtures roll back.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require __DIR__.'/../backend/db.php';
require __DIR__.'/../backend/management_payment_helper.php';
require __DIR__.'/../backend/management_students_helper.php';
require __DIR__.'/../backend/management_student_account_helper.php';
require __DIR__.'/../backend/management_officers_helper.php';
require __DIR__.'/../backend/management_reports_helper.php';
class StudentViolationQAConnection extends mysqli{
    public $failAfter=-1;
    public function start():void{parent::begin_transaction();}
    public function discard():void{parent::rollback();}
    public function begin_transaction($flags=0,$name=null):bool{return $this->query('SAVEPOINT student_violation_qa');}
    public function commit($flags=0,$name=null):bool{return $this->query('RELEASE SAVEPOINT student_violation_qa');}
    public function rollback($flags=0,$name=null):bool{$this->query('ROLLBACK TO SAVEPOINT student_violation_qa');return $this->query('RELEASE SAVEPOINT student_violation_qa');}
    public function prepare($sql){if(strpos($sql,'INSERT INTO tbl_management_student_violations')===0&&$this->failAfter>=0){if($this->failAfter--===0)throw new RuntimeException('Deliberate assessment write failure.');}return parent::prepare($sql);}
}
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$reject=static function(callable $action,string $message)use($check):void{try{$action();}catch(DomainException|SynkManagementConflict|OutOfBoundsException $e){$check(true,$message);return;}$check(false,$message);};
$tables=['tbl_management_student_violations','tbl_management_violations','tbl_management_payments','tbl_management_payment_items','tbl_management_organizations','tbl_management_period_settings','tbl_management_officers','tbl_management_officer_audit','tbl_useraccount','tbl_useraccount_roles','tbl_useraccount_faculty_links','tbl_faculty','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_academic_settings'];
$snapshot=static function()use($conn,$tables):string{$rows=[];foreach($tables as$table)$rows[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($rows));};$before=$snapshot();
$db=new StudentViolationQAConnection($servername,$username,$password,$dbase);$db->set_charset('utf8mb4');$db->start();
try{
 $insert=static function(string $table,array $row)use($db):int{synk_management_execute($db,'INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',str_repeat('s',count($row)),array_values($row));return (int)$db->insert_id;};
 $tag='VIOLATIONQA'.bin2hex(random_bytes(5));$college=$db->query("SELECT p.program_id,c.college_id,c.campus_id FROM tbl_program p JOIN tbl_college c ON c.college_id=p.college_id WHERE p.status='active' AND c.status='active' ORDER BY p.program_id LIMIT 1")->fetch_assoc();
 $org=$insert('tbl_management_organizations',['college_id'=>$college['college_id'],'organization_code'=>$tag,'organization_name'=>$tag]);$scope=['college_id'=>(int)$college['college_id'],'campus_id'=>(int)$college['campus_id'],'organization_id'=>$org,'organization_code'=>$tag,'organization_name'=>$tag];
 $manager=$insert('tbl_useraccount',['username'=>$tag,'email'=>strtolower($tag).'faculty@sksu.edu.ph','password'=>'unused','role'=>'management','status'=>'active','management_college_id'=>$college['college_id'],'management_organization_id'=>$org]);synk_persist_useraccount_roles($db,$manager,['management'],'management');
 $faculty=$insert('tbl_faculty',['first_name'=>'QA','last_name'=>$tag,'status'=>'active']);$insert('tbl_useraccount_faculty_links',['user_id'=>$manager,'faculty_id'=>$faculty]);
 $session=static function(int $id)use($tag):void{$_SESSION=['user_id'=>$id,'role'=>'management','auth_method'=>'google','username'=>$tag];};$session($manager);
 $academic=synk_fetch_current_academic_term($db);$insert('tbl_management_period_settings',['organization_id'=>$org,'college_id'=>$scope['college_id'],'ay_id'=>$academic['ay_id'],'semester'=>$academic['semester'],'current_exam_term'=>'FINAL']);$period=synk_management_fetch_current_period($db,$scope);
 $input=static function(array $row=[])use($period):array{return $row+['term'=>$period['key'],'exam_term'=>$period['exam_term'],'current_term'=>$period['key'],'current_exam_term'=>$period['exam_term'],'current_period_version'=>$period['period_version']];};
 $students=[];foreach(['First','Second','Officer']as$i=>$first)$students[]=$insert('tbl_student_management',['student_number'=>$tag.$i,'first_name'=>$first,'last_name'=>$tag,'email_address'=>strtolower($tag).$i.'@sksu.edu.ph','source_sheet_name'=>'DEAN MANUAL','program_id'=>$college['program_id'],'ay_id'=>$period['ay_id'],'semester'=>$period['semester']]);
 $catalog=[];foreach(['100.00','25.50','0.00']as$i=>$amount)$catalog[]=synk_management_violation_write($db,$scope,'save',$input(['violation_type'=>$tag.' TYPE '.$i,'amount'=>$amount]))['record_id'];
 $overview=static function(int $student)use($db,$scope,$period):array{return synk_management_student_financial_overview($db,$scope,$period,$student);};
 $make=static function(int $student,array $ids)use($db,$scope,$period,$input,$overview):array{$options=array_column(synk_management_student_violation_options($db,$scope,$period),null,'violation_id');$chosen=[];foreach($ids as$id)$chosen[]=['violation_id'=>$id,'version'=>$options[$id]['version']];return $input(['student_id'=>$student,'selected_violations'=>json_encode($chosen),'occurred_on'=>date('Y-m-d'),'notes'=>'QA INCIDENT','request_key'=>bin2hex(random_bytes(32)),'account_version'=>$overview($student)['account_version']]);};
 $apply=static function(array $row)use($db,$scope):array{return synk_management_student_violation_apply($db,$scope,$row);};
 $check($overview($students[0])['summary']['total_to_pay']==='0.00','Catalog entries were automatically imposed on a student.');
 $payload=$make($students[0],array_slice($catalog,0,2));
 foreach([['selected_violations'=>'[]'],['selected_violations'=>'{}'],['selected_violations'=>'[1]'],['occurred_on'=>'2026-02-30'],['occurred_on'=>date('Y-m-d',strtotime('+1 day'))],['notes'=>str_repeat('x',1001)],['request_key'=>'bad'],['account_version'=>str_repeat('0',64)],['current_exam_term'=>'MIDTERM'],['term'=>$period['ay_id'].':3']]as$bad)$reject(static function()use($apply,$payload,$bad){$apply(array_replace($payload,$bad));},'Invalid or stale assessment request was accepted.');
 $duplicateSelection=json_decode($payload['selected_violations'],true);$duplicateSelection[]=$duplicateSelection[0];$reject(static function()use($apply,$payload,$duplicateSelection){$apply(array_replace($payload,['selected_violations'=>json_encode($duplicateSelection)]));},'Duplicate catalog selection was accepted.');
 $sibling=$insert('tbl_management_organizations',['college_id'=>$scope['college_id'],'organization_code'=>$tag.'S','organization_name'=>$tag.' SIBLING']);$foreignCatalog=$insert('tbl_management_violations',['college_id'=>$scope['college_id'],'organization_id'=>$sibling,'ay_id'=>$period['ay_id'],'semester'=>$period['semester'],'violation_type'=>$tag,'amount'=>'5']);
 $foreign=synk_management_query($db,'SELECT * FROM tbl_management_violations WHERE violation_id=?','i',[$foreignCatalog])[0];$chosen=json_decode($payload['selected_violations'],true);$chosen[]=['violation_id'=>$foreignCatalog,'version'=>hash('sha256',json_encode($foreign))];
 $reject(static function()use($apply,$payload,$chosen){$apply(array_replace($payload,['selected_violations'=>json_encode($chosen)]));},'Foreign catalog entry was applied.');
 $check(count($overview($students[0])['violations'])===0,'A rejected multi-violation batch was partially saved.');
 $db->failAfter=1;try{$apply($payload);$check(false,'Assessment write failure did not roll back.');}catch(RuntimeException $e){$check($e->getMessage()==='Deliberate assessment write failure.','Wrong simulated write failure.');}finally{$db->failAfter=-1;}
 $check($overview($students[0])['summary']['violation_total']==='0.00','Failed assessment batch left a charge.');
 // A posted amount cannot override the standard catalog rates.
 $payload['amount']='0.01';
 $result=$apply($payload);$financial=$overview($students[0]);$check(!$result['duplicate']&&$result['count']===2&&count($financial['violations'])===2,'Selected violations were not applied as one batch.');
 $check($financial['summary']['violation_total']==='125.50'&&$financial['summary']['total_to_pay']==='125.50'&&$financial['can_pay'],'Applied fines did not increase the payable balance.');
 $check($overview($students[1])['summary']['violation_total']==='0.00','Applying a violation charged another student.');
 $check($apply($payload)['duplicate']&&count($overview($students[0])['violations'])===2,'A retried request duplicated student charges.');
 $reject(static function()use($apply,$payload){$apply(array_replace($payload,['notes'=>'DIFFERENT INCIDENT']));},'A request key was reused with a different payload.');
 $reject(static function()use($apply,$payload){$apply(array_replace($payload,['request_key'=>bin2hex(random_bytes(32))]));},'Stale student account version was accepted.');
 $rows=array_column($financial['violations'],null,'violation_id');$catalogRecord=synk_management_violation_get($db,$scope,$catalog[0]);synk_management_violation_write($db,$scope,'save',$input(['record_id'=>$catalog[0],'version'=>$catalogRecord['version'],'violation_type'=>$tag.' CHANGED','amount'=>'999']));
 $check($overview($students[0])['summary']['total_to_pay']==='125.50'&&array_column($overview($students[0])['violations'],null,'violation_id')[$catalog[0]]['title']===$rows[$catalog[0]]['title'],'Catalog edits changed an already applied charge.');
 $catalogRecord=synk_management_violation_get($db,$scope,$catalog[0]);$reject(static function()use($db,$scope,$input,$catalogRecord,$catalog){synk_management_violation_write($db,$scope,'delete',$input(['record_id'=>$catalog[0],'version'=>$catalogRecord['version']]));},'Applied catalog entry was deleted.');
 $apply($make($students[1],[$catalog[0]]));$check($overview($students[1])['summary']['total_to_pay']==='999.00','New applications did not use the updated catalog rate.');
 $pay=static function(string $amount,string $mode,array $selected=[])use($db,$scope,$input,$overview,$students):array{return synk_management_payment_post($db,$scope,$input(['student_id'=>$students[0],'amount'=>$amount,'allocation_mode'=>$mode,'selected'=>json_encode($selected),'account_version'=>$overview($students[0])['account_version'],'request_key'=>bin2hex(random_bytes(32))]));};
 $paid=$pay('25.50','SELECTED',['V:'.$rows[$catalog[1]]['student_violation_id']]);$check($overview($students[0])['summary']['total_to_pay']==='100.00','Selected violation payment did not reduce the balance.');
 $item=synk_management_query($db,'SELECT * FROM tbl_management_payment_items WHERE payment_id=?','i',[$paid['payment_id']])[0];$check((int)$item['student_violation_id']===(int)$rows[$catalog[1]]['student_violation_id']&&$item['contribution_id']===null&&$item['event_id']===null,'Violation payment was misclassified as an event or contribution.');
 $receipt=synk_management_payment_receipt($db,(int)$paid['payment_id'],null,$scope);$check($receipt['amount']==='25.50'&&strpos($receipt['items'][0]['title'],'(violation)')!==false,'Receipt lost the violation assessment.');
 $pay('50','DISTRIBUTE');$financial=$overview($students[0]);$check($financial['summary']['amount_paid']==='75.50'&&$financial['summary']['total_to_pay']==='50.00','Distributed payment did not include unpaid violation charges.');
 $check(array_column($financial['violations'],null,'violation_id')[$catalog[0]]['payment_status']==='PARTIALLY_PAID','Partial violation payment status is wrong.');
 $report=synk_management_reports_prepare($db,$scope,$period,['report_type'=>'collection_register','generate'=>'1'])['data'];$check($report['categories']['violations']['collected']==='75.50'&&$report['categories']['contributions']['collected']==='0.00'&&$report['categories']['event_fines']['collected']==='0.00'&&$report['totals']['reconciled'],'Reports merged or omitted violation collections.');
 $account=synk_management_student_account($db,$students[0]);$check(count(array_filter($account['violations'],static function($row)use($tag){return $row['organization_code']===$tag;}))===2,'Student finance view does not include their applied violations.');
 $version=$overview($students[1])['account_version'];$apply($make($students[1],[$catalog[2]]));$check($overview($students[1])['summary']['total_to_pay']==='999.00'&&$overview($students[1])['account_version']!==$version,'Zero-amount violation changed the balance or failed to change the account version.');
 $apply($make($students[2],[$catalog[2]]));$check($overview($students[2])['summary']['payment_status']==='NO_CHARGES'&&count($overview($students[2])['violations'])===1,'Zero-amount incident was lost or became a payable charge.');
 $midterm=synk_management_view_term($db,['term'=>$period['key'],'exam_term'=>'MIDTERM'],$scope);$check(synk_management_student_financial_overview($db,$scope,$midterm,$students[0])['violations']===[],'Final assessments leaked into Midterm.');
 $check(synk_management_student_financial_overview($db,array_merge($scope,['organization_id'=>$sibling]),$period,$students[0])['violations']===[],'Assessments leaked into another organization.');
 synk_management_officer_write($db,$scope,'officer_save',$input(['student_id'=>$students[2],'can_manage_violations'=>'1','expires_on'=>date('Y-m-d',strtotime('+30 days'))]));$grant=$db->query('SELECT * FROM tbl_management_officers WHERE student_id='.$students[2])->fetch_assoc();$session((int)$grant['user_id']);
 $detail=synk_management_students_detail($db,$scope,$input(['student_id'=>$students[1]]));$check($detail['financial']['can_add_violations']&&!$detail['financial']['can_pay'],'Violation officer cannot access the form or gained collection permissions.');
 $apply($make($students[1],[$catalog[1]]));$check($overview($students[1])['summary']['total_to_pay']==='1024.50','Authorized officer could not apply the catalog violation.');
 $db->query('UPDATE tbl_management_officers SET can_manage_violations=0,can_view=1 WHERE officer_id='.(int)$grant['officer_id']);$reject(static function()use($apply,$make,$students,$catalog){$apply($make($students[1],[$catalog[1]]));},'Viewer applied a violation after permission removal.');
 $db->query("UPDATE tbl_management_officers SET status='REVOKED' WHERE officer_id=".(int)$grant['officer_id']);$reject(static function()use($apply,$make,$students,$catalog){$apply($make($students[1],[$catalog[1]]));},'Revoked officer applied a violation.');
 $session($manager);$actions=array_column(synk_management_officer_audit($db,$scope),'action');$check(in_array('VIOLATION_ADD',$actions,true),'Officer application was not attributed in recent activity.');
 $check(synk_management_fetch_current_period($db,$scope)['exam_term']==='FINAL','Recording violations changed the explicit Final setting.');
}finally{$db->discard();$db->close();}
$check($snapshot()===$before,'Student violation tests changed persistent records or shared profiles/settings.');
echo 'Management student violations: ',$checks,' checks passed; all fixtures rolled back.',PHP_EOL;
