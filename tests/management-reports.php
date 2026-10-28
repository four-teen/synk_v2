<?php
// Native ledger/attendance fixtures remain inside one rolled-back transaction.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require __DIR__.'/../backend/db.php';
require __DIR__.'/../backend/management_reports_helper.php';
require __DIR__.'/../backend/management_payment_helper.php';
require __DIR__.'/../backend/management_officers_helper.php';
class ManagementReportsQAConnection extends mysqli{
    public function start():void{parent::begin_transaction();}
    public function discard():void{parent::rollback();}
    public function begin_transaction($flags=0,$name=null):bool{return $this->query('SAVEPOINT reports_qa');}
    public function commit($flags=0,$name=null):bool{return $this->query('RELEASE SAVEPOINT reports_qa');}
    public function rollback($flags=0,$name=null):bool{$this->query('ROLLBACK TO SAVEPOINT reports_qa');return $this->query('RELEASE SAVEPOINT reports_qa');}
}
$checks=0;$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$reject=static function(callable $action,string $message)use($check):void{try{$action();}catch(DomainException|OutOfBoundsException $e){$check(true,$message);return;}$check(false,$message);};
$tables=['tbl_management_payments','tbl_management_payment_items','tbl_management_organizations','tbl_management_period_settings','tbl_management_contributions','tbl_management_events','tbl_management_event_sessions','tbl_management_violations','tbl_management_attendance_events','tbl_management_attendance_roster','tbl_management_attendance_logs','tbl_management_attendance_audit','tbl_management_student_qr_tokens','tbl_management_officers','tbl_management_officer_audit','tbl_useraccount','tbl_useraccount_roles','tbl_useraccount_faculty_links','tbl_faculty','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_academic_settings'];
$snapshot=static function()use($conn,$tables):string{$rows=[];foreach($tables as$table)$rows[$table]=$conn->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC);return hash('sha256',json_encode($rows));};$before=$snapshot();
$db=new ManagementReportsQAConnection($servername,$username,$password,$dbase);$db->set_charset('utf8mb4');$db->start();
try{
    $insert=static function(string $table,array $row)use($db):int{synk_management_execute($db,'INSERT INTO '.$table.' (`'.implode('`,`',array_keys($row)).'`) VALUES ('.implode(',',array_fill(0,count($row),'?')).')',str_repeat('s',count($row)),array_values($row));return (int)$db->insert_id;};
    $tag='REPORT QA '.bin2hex(random_bytes(5));$college=$db->query("SELECT p.program_id,c.college_id,c.campus_id FROM tbl_program p JOIN tbl_college c ON c.college_id=p.college_id WHERE p.status='active' AND c.status='active' ORDER BY p.program_id LIMIT 1")->fetch_assoc();
    $org=$insert('tbl_management_organizations',['college_id'=>$college['college_id'],'organization_code'=>$tag,'organization_name'=>$tag]);
    $sibling=$insert('tbl_management_organizations',['college_id'=>$college['college_id'],'organization_code'=>$tag.'S','organization_name'=>$tag.' SIBLING']);
    $scope=['college_id'=>(int)$college['college_id'],'campus_id'=>(int)$college['campus_id'],'organization_id'=>$org,'organization_code'=>$tag,'organization_name'=>$tag];
    $user=$insert('tbl_useraccount',['username'=>$tag,'email'=>str_replace(' ','',strtolower($tag)).'@sksu.edu.ph','password'=>'unused','role'=>'management','status'=>'active','management_college_id'=>$scope['college_id'],'management_organization_id'=>$org]);synk_persist_useraccount_roles($db,$user,['management'],'management');
    $session=static function(int $id)use($tag):void{$_SESSION=['user_id'=>$id,'role'=>'management','auth_method'=>'google','username'=>$tag];};$session($user);
    $academic=synk_fetch_current_academic_term($db);$insert('tbl_management_period_settings',['organization_id'=>$org,'college_id'=>$scope['college_id'],'ay_id'=>$academic['ay_id'],'semester'=>$academic['semester'],'current_exam_term'=>'FINAL']);
    $period=synk_management_fetch_current_period($db,$scope);
    $input=static function(array $row=[])use($period):array{return $row+['term'=>$period['key'],'exam_term'=>$period['exam_term'],'current_term'=>$period['key'],'current_exam_term'=>$period['exam_term'],'current_period_version'=>$period['period_version']];};
    $report=static function(string $type,array $row=[])use($db,$scope,$period):array{return synk_management_reports_prepare($db,$scope,$period,$row+['report_type'=>$type,'generate'=>'1']);};
    $students=[];foreach(['Alpha','Beta','Zeta']as$i=>$name)$students[]=$insert('tbl_student_management',['student_number'=>$tag.$i,'first_name'=>$name,'last_name'=>$tag,'email_address'=>str_replace(' ','',strtolower($tag)).$i.'@sksu.edu.ph','source_sheet_name'=>'DEAN MANUAL','program_id'=>$college['program_id'],'ay_id'=>$period['ay_id'],'semester'=>$period['semester']]);
    $event=synk_management_catalog_write($db,$scope,'events','save',$input(['title'=>$tag,'event_date'=>date('Y-m-d'),'absence_fine'=>'25.50']))['record_id'];
    $contribution=synk_management_catalog_write($db,$scope,'contributions','save',$input(['title'=>$tag,'event_id'=>$event,'contribution_date'=>date('Y-m-d'),'amount'=>'100']))['record_id'];
    $opened=synk_management_attendance_write($db,$scope,'attendance_open',$input(['event_id'=>$event]));
    $db->query('DELETE FROM tbl_management_attendance_roster WHERE event_id='.$event.' AND student_id NOT IN ('.implode(',',$students).')');
    synk_management_attendance_write($db,$scope,'attendance_scan',$input(['event_id'=>$event,'qr_code'=>synk_management_student_qr($db,$students[2])]));
    $draft=$report('event_attendance',['event_id'=>$event])['data'];$check(!$draft['finalized']&&count($draft['groups']['absent'])===2&&count($draft['groups']['present'])===1,'Open attendance is not provisional with separate pending/present groups.');
    synk_management_attendance_write($db,$scope,'attendance_close',$input(['event_id'=>$event,'attendance_revision'=>$opened['attendance']['revision'],'confirm'=>'1']));
    $closed=$report('event_attendance',['event_id'=>$event])['data'];$check($closed['finalized']&&$closed['total']===3,'Finalized attendance roster total is wrong.');
    $check(array_map('intval',array_column($closed['groups']['absent'],'student_id'))===array_slice($students,0,2),'Absent students did not appear first in name order.');
    $check(array_merge(array_column($closed['groups']['absent'],'number'),array_column($closed['groups']['present'],'number'))===[1,2,3],'Attendance numbering restarted between categories.');
    foreach([0=>['125.50',['C:'.$contribution,'E:'.$event]],1=>['100.00',['C:'.$contribution]]]as$i=>$payment){$financial=synk_management_student_financial_overview($db,$scope,$period,$students[$i]);synk_management_payment_post($db,$scope,$input(['student_id'=>$students[$i],'amount'=>$payment[0],'allocation_mode'=>'SELECTED','selected'=>json_encode($payment[1]),'account_version'=>$financial['account_version'],'request_key'=>bin2hex(random_bytes(32))]));}
    $insert('tbl_management_violations',['college_id'=>$scope['college_id'],'organization_id'=>$org,'ay_id'=>$period['ay_id'],'semester'=>$period['semester'],'violation_type'=>$tag,'amount'=>'999']);
    // Records in another organization/semester/exam term must never enter a report.
    $saved=$db->query('SELECT * FROM tbl_management_payments WHERE organization_id='.$org.' LIMIT 1')->fetch_assoc();unset($saved['payment_id']);
    foreach([['organization_id'=>$sibling],['semester'=>$period['semester']===1?2:1],['exam_term'=>'MIDTERM']]as$override){$other=array_merge($saved,$override,['receipt_number'=>'QA-'.bin2hex(random_bytes(8)),'request_key'=>bin2hex(random_bytes(32)),'amount'=>'999.00']);$id=$insert('tbl_management_payments',$other);$insert('tbl_management_payment_items',['payment_id'=>$id,'charge_key'=>'C:'.$contribution,'contribution_id'=>$contribution,'title'=>$tag,'charge_amount'=>'999.00','paid_before'=>'0','amount'=>'999.00','balance_after'=>'0']);}
    $summary=$report('collection_summary')['data'];$check($summary['totals']['collected']==='225.50'&&(int)$summary['totals']['receipts']===2&&(int)$summary['totals']['students']===2,'Collections included another period/scope or duplicated a split receipt.');
    $check($summary['categories']['contributions']['collected']==='200.00'&&$summary['categories']['event_fines']['collected']==='25.50'&&$summary['categories']['violations']['collected']==='0.00','Collection categories were merged or catalog rates counted as collections.');
    $check($summary['totals']['reconciled']&&$summary['totals']['allocated']==='225.50','Saved allocations did not reconcile.');
    $register=$report('collection_register')['data'];$check(count($register['rows'])===3&&count(array_unique(array_column($register['rows'],'payment_id')))===2,'Detailed register lost allocations or duplicated receipts.');
    $check(strpos($register['rows'][0]['recorded_at'],date('M j, Y'))===0,'Payment timestamp was not displayed in Philippine time.');
    $fines=$report('absence_fines')['data'];$check($fines['totals']===['assessed'=>'51.00','collected'=>'25.50','outstanding'=>'25.50']&&$fines['students']===2,'Fine assessments, collections or outstanding amounts are wrong.');
    $check($report('absence_fines',['event_id'=>$event])['data']['totals']===$fines['totals'],'Single-event fine filter changed totals.');
    $openEvent=synk_management_catalog_write($db,$scope,'events','save',$input(['title'=>$tag.' OPEN','event_date'=>date('Y-m-d'),'absence_fine'=>'99']))['record_id'];synk_management_attendance_write($db,$scope,'attendance_open',$input(['event_id'=>$openEvent]));
    $check($report('absence_fines')['data']['totals']===$fines['totals'],'Open attendance created fine assessments.');
    $check($report('absence_fines',['event_id'=>$openEvent])['data']['rows']===[],'Open event showed finalized fines.');
    $check(!$report('event_attendance')['generated'],'Attendance without an event generated a report.');
    $notStarted=synk_management_catalog_write($db,$scope,'events','save',$input(['title'=>$tag.' NOT STARTED','event_date'=>date('Y-m-d'),'absence_fine'=>'99']))['record_id'];$notStartedReport=$report('event_attendance',['event_id'=>$notStarted])['data'];$check($notStartedReport['status']==='NOT_STARTED'&&$notStartedReport['total']===0,'Unstarted event fabricated an absence roster.');
    $wrongEvent=$insert('tbl_management_events',['college_id'=>$scope['college_id'],'organization_id'=>$sibling,'ay_id'=>$period['ay_id'],'semester'=>$period['semester'],'exam_term'=>'FINAL','title'=>$tag,'event_date'=>date('Y-m-d')]);
    $reject(static function()use($report,$wrongEvent){$report('event_attendance',['event_id'=>$wrongEvent]);},'Foreign organization event was accepted.');
    $wrongPeriod=synk_management_view_term($db,['term'=>$period['key'],'exam_term'=>'MIDTERM'],$scope);$reject(static function()use($db,$scope,$wrongPeriod,$event){synk_management_reports_prepare($db,$scope,$wrongPeriod,['report_type'=>'event_attendance','generate'=>'1','event_id'=>$event]);},'Cross-term attendance event was accepted.');
    foreach([['report_type'=>['event_attendance']],['event_id'=>['1']],['institution'=>str_repeat('x',151)],['generate'=>'unexpected']]as$bad)$reject(static function()use($db,$scope,$period,$bad){synk_management_reports_prepare($db,$scope,$period,$bad);},'Invalid report input was accepted.');
    $check(!synk_management_reports_prepare($db,$scope,$period,[])['generated'],'Report automatically generated before the user selected Generate.');
    $faculty=$insert('tbl_faculty',['first_name'=>'QA','last_name'=>$tag,'status'=>'active']);$insert('tbl_useraccount_faculty_links',['user_id'=>$user,'faculty_id'=>$faculty]);
    synk_management_officer_write($db,$scope,'officer_save',$input(['student_id'=>$students[2],'can_attendance'=>'1','expires_on'=>date('Y-m-d',strtotime('+30 days'))]));
    $grant=$db->query('SELECT * FROM tbl_management_officers WHERE student_id='.$students[2])->fetch_assoc();$session((int)$grant['user_id']);
    $check(array_keys(synk_management_report_types(synk_management_permissions($db,$scope)))===['event_attendance'],'Attendance-only officer gained financial report access.');
    $check($report('event_attendance',['event_id'=>$event])['generated'],'Authorized attendance officer cannot generate an attendance report.');
    $reject(static function()use($report){$report('collection_summary');},'Attendance-only officer generated a financial report.');
    $db->query('UPDATE tbl_management_officers SET can_attendance=0,can_manage_contributions=1 WHERE officer_id='.(int)$grant['officer_id']);$reject(static function()use($report){$report('collection_summary');},'Contribution management alone exposed financial reports.');
    $db->query("UPDATE tbl_management_officers SET status='REVOKED' WHERE officer_id=".(int)$grant['officer_id']);$reject(static function()use($report,$event){$report('event_attendance',['event_id'=>$event]);},'Revoked officer accessed reports.');
    $session($user);$unallocated=array_merge($saved,['receipt_number'=>'QA-'.bin2hex(random_bytes(8)),'request_key'=>bin2hex(random_bytes(32)),'amount'=>'1.00']);$insert('tbl_management_payments',$unallocated);$check(!$report('collection_summary')['data']['totals']['reconciled'],'An unallocated receipt silently reconciled.');
}finally{$db->discard();$db->close();}
$check($snapshot()===$before,'Report tests changed persistent Management data or shared academic/student records.');
echo 'Management reports: ',$checks,' checks passed; all fixtures rolled back.',PHP_EOL;
