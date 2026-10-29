<?php
// Real Management transactions; every fixture is rolled back at the end.
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/management_exemptions_helper.php';
require_once __DIR__.'/../backend/management_attendance_helper.php';
require_once __DIR__.'/../backend/management_payment_helper.php';
require_once __DIR__.'/../backend/management_reports_helper.php';
require_once __DIR__.'/../backend/management_officers_helper.php';
require_once __DIR__.'/../backend/management_student_account_helper.php';
class ManagementExemptionsTestConnection extends mysqli {
    public function start():void { parent::begin_transaction(); }
    public function discard():void { parent::rollback(); }
    public function begin_transaction($flags=0,$name=null):bool { return $this->query('SAVEPOINT exemption_test'); }
    public function commit($flags=0,$name=null):bool { return $this->query('RELEASE SAVEPOINT exemption_test'); }
    public function rollback($flags=0,$name=null):bool { $this->query('ROLLBACK TO SAVEPOINT exemption_test');return $this->query('RELEASE SAVEPOINT exemption_test'); }
}
$tables=['tbl_academic_settings','tbl_student_management','tbl_useraccount','tbl_useraccount_roles','tbl_management_organizations','tbl_management_period_settings','tbl_management_exemption_lists','tbl_management_exemption_list_members','tbl_management_exemptions','tbl_management_exemption_audit','tbl_management_events','tbl_management_contributions','tbl_management_payments','tbl_management_payment_items','tbl_management_attendance_events','tbl_management_attendance_roster','tbl_management_attendance_logs','tbl_management_officers'];
$snapshot=static function(mysqli $db)use($tables):array{$state=[];foreach($tables as $table)$state[$table]=hash('sha256',json_encode($db->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));return $state;};
$before=$snapshot($conn);$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
$reject=static function(callable $callback,string $message)use($check):void{try{$callback();}catch(DomainException|OutOfBoundsException|SynkManagementConflict $error){$check(true,'');return;}throw new RuntimeException($message);};
$db=new ManagementExemptionsTestConnection($servername,$username,$password,$dbase);$db->set_charset('utf8mb4');$db->start();
try {
    $tag='EXEMPT QA '.bin2hex(random_bytes(5));
    $college=$db->query("SELECT c.college_id,c.campus_id,p.program_id FROM tbl_college c JOIN tbl_program p ON p.college_id=c.college_id JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.status='active' AND cp.status='active' ORDER BY c.college_id LIMIT 1")->fetch_assoc();
    $insert=static function(string $table,array $values)use($db):int{synk_management_execute($db,'INSERT INTO '.$table.' (`'.implode('`,`',array_keys($values)).'`) VALUES ('.implode(',',array_fill(0,count($values),'?')).')',str_repeat('s',count($values)),array_values($values));return (int)$db->insert_id;};
    $org=$insert('tbl_management_organizations',['college_id'=>$college['college_id'],'organization_code'=>$tag,'organization_name'=>$tag]);
    $scope=['college_id'=>(int)$college['college_id'],'campus_id'=>(int)$college['campus_id'],'organization_id'=>$org,'organization_code'=>$tag,'organization_name'=>$tag];
    $user=$insert('tbl_useraccount',['username'=>$tag,'email'=>str_replace(' ','',$tag).'@gmail.com','password'=>'unused','role'=>'management','status'=>'active','management_college_id'=>$scope['college_id'],'management_organization_id'=>$org]);
    synk_persist_useraccount_roles($db,$user,['management'],'management');$_SESSION=['user_id'=>$user,'role'=>'management','auth_method'=>'google','username'=>$tag];
    $period=synk_management_fetch_current_period($db,$scope);
    $input=static function(array $extra=[])use($period):array{return array_merge(['term'=>$period['key'],'exam_term'=>$period['exam_term'],'current_term'=>$period['key'],'current_exam_term'=>$period['exam_term'],'current_period_version'=>$period['period_version']],$extra);};
    $write=static function(string $action,array $extra=[])use($db,$scope,$input):array{return synk_management_exemption_write($db,$scope,$action,$input($extra));};
    $students=[];for($i=0;$i<10;$i++)$students[]=$insert('tbl_student_management',['student_number'=>str_replace(' ','',$tag).$i,'first_name'=>'TEST '.$i,'last_name'=>$tag,'source_sheet_name'=>'DEAN MANUAL','program_id'=>$college['program_id'],'ay_id'=>$period['ay_id'],'semester'=>$period['semester'],'email_address'=>'exemption-'.$i.'-'.str_replace(' ','',$tag).'@sksu.edu.ph']);
    $events=[];$contributions=[];for($i=1;$i<=2;$i++){
        $event=synk_management_catalog_write($db,$scope,'events','save',$input(['title'=>$tag.' EVENT '.$i,'event_date'=>date('Y-m-d'),'absence_fine'=>'25']))['record_id'];$events[]=$event;
        $contributions[]=synk_management_catalog_write($db,$scope,'contributions','save',$input(['title'=>$tag.' CONTRIBUTION '.$i,'contribution_date'=>date('Y-m-d'),'amount'=>'100','event_id'=>$event]))['record_id'];
    }
    $unrelated=synk_management_catalog_write($db,$scope,'contributions','save',$input(['title'=>$tag.' UNRELATED','contribution_date'=>date('Y-m-d'),'amount'=>'30']))['record_id'];
    $overview=static function(int $id)use($db,$scope,$period):array{return synk_management_student_financial_overview($db,$scope,$period,$id);};
    $write('exemption_list_save',['title'=>'OFFICIAL DUTY','reason'=>'REPRESENTING THE COLLEGE','student_ids'=>json_encode($students)]);
    $list=synk_management_exemption_data($db,$scope,$period)['lists'][0];$listId=(int)$list['list_id'];
    $apply=static function(int $event,array $list)use($write):array{return $write('exemption_apply',['list_id'=>$list['list_id'],'list_revision'=>(string)$list['revision'],'event_ids'=>json_encode([$event])]);};
    $beforeVersion=$overview($students[9])['account_version'];
    $apply($events[0],$list);
    $catalog=synk_management_catalog_list($db,$scope,$period,'events',[])['rows'];$eventOne=array_values(array_filter($catalog,static function($row)use($events){return (int)$row['event_id']===$events[0];}))[0];
    $check($eventOne['attendance_exempted']===10,'Unopened event did not show its saved exempted count.');
    $check($db->query('SELECT COUNT(*) AS n FROM tbl_management_exemptions WHERE event_id='.$events[0]." AND status='ACTIVE'")->fetch_assoc()['n']==='10','Event 1 did not snapshot ten students.');
    $check($db->query('SELECT COUNT(*) AS n FROM tbl_management_exemptions WHERE contribution_id='.$contributions[0]." AND status='ACTIVE'")->fetch_assoc()['n']==='10','Event 1 did not waive its linked contribution for ten students.');
    $old=$overview($students[9]);$check($old['account_version']!==$beforeVersion && $old['summary']['contribution_catalog_total']==='130.00','Exempt contribution still charged or account version did not change.');
    $reject(static function()use($db,$scope,$input,$students,$beforeVersion,$unrelated){synk_management_payment_preview($db,$scope,$input(['student_id'=>$students[9],'amount'=>'30','allocation_mode'=>'SELECTED','selected'=>json_encode(['C:'.$unrelated]),'account_version'=>$beforeVersion]));},'Stale payment preview remained valid after exemptions.');
    $write('exemption_list_save',['list_id'=>$listId,'revision'=>(string)$list['revision'],'title'=>'OFFICIAL DUTY','reason'=>'REPRESENTING THE COLLEGE','student_ids'=>json_encode(array_slice($students,0,9))]);
    $next=synk_management_exemption_list_get($db,$scope,$period,$listId);
    $check(count($next['students'])===9 && $overview($students[9])['account_version']===$old['account_version'],'Editing the reusable list changed an earlier grant or account.');
    $reject(static function()use($apply,$events,$list){$apply($events[1],$list);},'A stale ten-student list was applied to Event 2.');
    $apply($events[1],$next);
    $check($db->query('SELECT COUNT(*) AS n FROM tbl_management_exemptions WHERE event_id='.$events[1]." AND status='ACTIVE'")->fetch_assoc()['n']==='9','Event 2 did not snapshot nine students.');
    $again=$apply($events[0],$next);$check(strpos($again['message'],'0 exemption(s)')===0,'Reapplying a smaller list removed or duplicated prior students.');
    foreach($events as $event){$opened=synk_management_attendance_write($db,$scope,'attendance_open',$input(['event_id'=>$event]));synk_management_attendance_write($db,$scope,'attendance_close',$input(['event_id'=>$event,'attendance_revision'=>$opened['attendance']['revision'],'confirm'=>'1']));}
    $removed=$overview($students[9]);$kept=$overview($students[0]);
    $check($removed['summary']['total_to_pay']==='155.00' && $removed['summary']['absence_count']===1,'Removed student lost Event 1 exemption or wrongly gained Event 2 exemption.');
    $check($kept['summary']['total_to_pay']==='30.00' && $kept['summary']['absence_count']===0,'Retained students were fined or unrelated contributions were waived.');
    $studentAccount=synk_management_student_account($db,$students[0]);$check($studentAccount['attendance']['exempted']>=2,'Student account did not recognize exempted attendance.');
    $report=synk_management_report_attendance($db,$scope,$period,$events[1]);$check(count($report['groups']['exempted'])===9 && in_array($students[9],array_map('intval',array_column($report['groups']['absent'],'student_id')),true),'Attendance report misclassified exemptions.');
    $fines=synk_management_report_absence_fines($db,$scope,$period,$events[1]);$check(!in_array($students[0],array_map('intval',array_column($fines['rows'],'student_id')),true),'Fine report included an exempted student.');
    $summary=synk_management_attendance_summary($db,$scope,$events[1]);$check($summary['exempted']===9 && $summary['absent']===$summary['eligible']-$summary['present']-9,'Attendance counts still include exempted students as absent.');
    $opened=synk_management_attendance_write($db,$scope,'attendance_reopen',$input(['event_id'=>$events[1],'attendance_revision'=>$summary['revision'],'confirm'=>'1']));
    synk_management_attendance_write($db,$scope,'attendance_scan',$input(['event_id'=>$events[1],'qr_code'=>synk_management_student_qr($db,$students[0])]));
    synk_management_attendance_write($db,$scope,'attendance_close',$input(['event_id'=>$events[1],'attendance_revision'=>$opened['attendance']['revision'],'confirm'=>'1']));
    $present=$overview($students[0]);$presentEvent=array_values(array_filter($present['events'],static function($row)use($events){return (int)$row['event_id']===$events[1];}))[0];
    $check($presentEvent['student_attendance']==='PRESENT' && $presentEvent['is_exempt'] && $presentEvent['amount_due']==='0.00','Exemptions erased actual QR attendance or charged the student.');
    $later=synk_management_catalog_write($db,$scope,'contributions','save',$input(['title'=>$tag.' LATER LINK','contribution_date'=>date('Y-m-d'),'amount'=>'40','event_id'=>$events[0]]))['record_id'];
    $check($overview($students[9])['summary']['total_to_pay']==='155.00','A later contribution inherited current list membership instead of saved Event 1 students.');
    $laterGrant=synk_management_query($db,'SELECT * FROM tbl_management_exemptions WHERE student_id=? AND contribution_id=?','ii',[$students[0],$later])[0];
    $write('exemption_revoke',['exemption_id'=>$laterGrant['exemption_id'],'revision'=>(string)$laterGrant['revision'],'reason'=>'CONTRIBUTION STILL REQUIRED']);
    $laterRecord=synk_management_catalog_get($db,$scope,'contributions',$later);
    synk_management_catalog_write($db,$scope,'contributions','save',$input(['record_id'=>$later,'version'=>$laterRecord['version'],'title'=>$tag.' LATER LINK EDITED','contribution_date'=>date('Y-m-d'),'amount'=>'40','event_id'=>$events[0]]));
    $check($overview($students[0])['summary']['total_to_pay']==='70.00','Editing a linked contribution resurrected an explicitly revoked exemption.');
    $write('exemption_apply',['student_ids'=>json_encode([$students[0]]),'contribution_ids'=>json_encode([$later]),'reason'=>'INDEPENDENT WAIVER']);
    $eventGrant=synk_management_query($db,'SELECT * FROM tbl_management_exemptions WHERE student_id=? AND event_id=?','ii',[$students[0],$events[0]])[0];
    $write('exemption_revoke',['exemption_id'=>$eventGrant['exemption_id'],'revision'=>(string)$eventGrant['revision'],'reason'=>'EVENT WAIVER ENDED']);
    $check(synk_management_query($db,'SELECT status FROM tbl_management_exemptions WHERE exemption_id=?','i',[(int)$laterGrant['exemption_id']])[0]['status']==='ACTIVE','Event revocation removed a separately selected contribution exemption.');
    $write('exemption_apply',['student_ids'=>json_encode([$students[9]]),'contribution_ids'=>json_encode([$unrelated]),'reason'=>'INDIVIDUAL CONTRIBUTION WAIVER']);
    $check($overview($students[9])['summary']['total_to_pay']==='125.00','Individual contribution exemption failed.');
    $grant=$db->query('SELECT * FROM tbl_management_exemptions WHERE student_id='.$students[9].' AND event_id='.$events[0])->fetch_assoc();
    $write('exemption_revoke',['exemption_id'=>$grant['exemption_id'],'revision'=>$grant['revision'],'reason'=>'DUTY CANCELLED']);
    $check($overview($students[9])['summary']['total_to_pay']==='290.00','Event revocation did not restore exactly the event and inherited contribution charges.');
    $reject(static function()use($write,$grant){$write('exemption_revoke',['exemption_id'=>$grant['exemption_id'],'revision'=>$grant['revision'],'reason'=>'STALE']);},'Stale revocation was accepted.');
    $write('exemption_apply',['student_ids'=>json_encode([$students[9]]),'event_ids'=>json_encode([$events[0]]),'reason'=>'DUTY REINSTATED']);
    $check($overview($students[9])['summary']['total_to_pay']==='125.00','Regrant failed or duplicated exemptions.');
    $fin=$overview($students[9]);$paid=synk_management_payment_post($db,$scope,$input(['student_id'=>$students[9],'amount'=>'100','allocation_mode'=>'SELECTED','selected'=>json_encode(['C:'.$contributions[1]]),'account_version'=>$fin['account_version'],'request_key'=>bin2hex(random_bytes(32))]));
    $receipt=synk_management_payment_receipt($db,(int)$paid['payment_id'],null,$scope);
    $reject(static function()use($write,$students,$events){$write('exemption_apply',['student_ids'=>json_encode([$students[9]]),'event_ids'=>json_encode([$events[1]]),'reason'=>'PAID CHARGE']);},'Paid linked contribution was silently exempted.');
    $check(!synk_management_query($db,"SELECT exemption_id FROM tbl_management_exemptions WHERE event_id=? AND student_id=? AND status='ACTIVE'",'ii',[$events[1],$students[9]]),'A partially failed bulk operation left an event exemption behind.');
    $check(synk_management_payment_receipt($db,(int)$paid['payment_id'],null,$scope)===$receipt,'Exemptions changed a saved receipt.');
    $reject(static function()use($write,$students,$events,$period){$write('exemption_apply',['student_ids'=>json_encode([$students[0]]),'event_ids'=>json_encode([$events[1]]),'reason'=>'WRONG PERIOD','exam_term'=>$period['exam_term']==='FINAL'?'MIDTERM':'FINAL']);},'Historical viewing period accepted a write.');
    $foreignOrg=$insert('tbl_management_organizations',['college_id'=>$scope['college_id'],'organization_code'=>$tag.'X','organization_name'=>$tag.'X']);
    $foreign=$insert('tbl_management_events',['college_id'=>$scope['college_id'],'organization_id'=>$foreignOrg,'ay_id'=>$period['ay_id'],'semester'=>$period['semester'],'exam_term'=>$period['exam_term'],'title'=>$tag.' FOREIGN','event_date'=>date('Y-m-d')]);
    $reject(static function()use($write,$students,$foreign){$write('exemption_apply',['student_ids'=>json_encode([$students[0]]),'event_ids'=>json_encode([$foreign]),'reason'=>'FOREIGN']);},'Cross-organization event accepted.');
    $faculty=$insert('tbl_faculty',['first_name'=>'QA','last_name'=>$tag,'status'=>'active']);$insert('tbl_useraccount_faculty_links',['user_id'=>$user,'faculty_id'=>$faculty]);
    synk_management_officer_write($db,$scope,'officer_save',$input(['student_id'=>$students[0],'can_view'=>'1','expires_on'=>date('Y-m-d',strtotime('+7 days'))]));
    $officer=synk_management_officer_record($db,(int)$db->query('SELECT user_id FROM tbl_management_officers WHERE student_id='.$students[0])->fetch_assoc()['user_id']);
    $check((int)$officer['can_manage_exemptions']===0,'New exemption permission was enabled implicitly.');
    $_SESSION['user_id']=(int)$officer['user_id'];
    $reject(static function()use($write,$students,$events){$write('exemption_apply',['student_ids'=>json_encode([$students[1]]),'event_ids'=>json_encode([$events[0]]),'reason'=>'UNAUTHORIZED']);},'View-only officer could grant exemptions.');
    $db->query('UPDATE tbl_management_officers SET can_manage_exemptions=1 WHERE officer_id='.(int)$officer['officer_id']);
    $reject(static function()use($write,$students,$events){$write('exemption_apply',['student_ids'=>json_encode([$students[0]]),'event_ids'=>json_encode([$events[0]]),'reason'=>'SELF']);},'Officer could exempt their own account.');
    $_SESSION['user_id']=$user;
    $check((int)$db->query('SELECT COUNT(*) AS n FROM tbl_management_exemption_audit WHERE organization_id='.$org)->fetch_assoc()['n']>=8,'Exemption activity was not audited.');
} finally { $db->discard();$db->close(); }
$check($snapshot($conn)===$before,'Exemption checks changed stored business data or shared settings.');
echo 'Management exemptions: ',$checks," checks passed; fixtures rolled back, profiles and periods unchanged.\n";
