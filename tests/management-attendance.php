<?php
// Full attendance/financial integration, with every fixture and QR rolled back.
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/management_attendance_helper.php';
require_once __DIR__.'/../backend/management_student_financial_helper.php';
class ManagementAttendanceTestConnection extends mysqli {
    public function start():void { parent::begin_transaction(); }
    public function discard():void { parent::rollback(); }
    public function begin_transaction($flags=0,$name=null):bool { return $this->query('SAVEPOINT attendance_test'); }
    public function commit($flags=0,$name=null):bool { return $this->query('RELEASE SAVEPOINT attendance_test'); }
    public function rollback($flags=0,$name=null):bool { $this->query('ROLLBACK TO SAVEPOINT attendance_test');return $this->query('RELEASE SAVEPOINT attendance_test'); }
}
$tables=['tbl_academic_settings','tbl_academic_years','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_useraccount','tbl_useraccount_roles','tbl_class_schedule','tbl_faculty_workload_sched','tbl_management_organizations','tbl_management_contributions','tbl_management_events','tbl_management_event_sessions','tbl_management_attendance_events','tbl_management_attendance_roster','tbl_management_attendance_logs','tbl_management_attendance_audit','tbl_management_student_qr_tokens'];
$snapshot=static function(mysqli $db)use($tables):array {$result=[];foreach($tables as$table)$result[$table]=hash('sha256',json_encode($db->query('SELECT * FROM '.$table.' ORDER BY 1')->fetch_all(MYSQLI_ASSOC)));return $result;};
$before=$snapshot($conn);$checks=0;
$check=static function(bool $ok,string $message)use(&$checks):void {if(!$ok)throw new RuntimeException($message);$checks++;};
$db=new ManagementAttendanceTestConnection($servername,$username,$password,$dbase);$db->set_charset('utf8mb4');$db->start();
try {
    $tag='ATTENDANCE QA '.bin2hex(random_bytes(5));
    $college=$db->query("SELECT c.college_id,c.campus_id,p.program_id FROM tbl_college c JOIN tbl_program p ON p.college_id=c.college_id JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.status='active' AND cp.status='active' ORDER BY c.college_id LIMIT 1")->fetch_assoc();
    $insert=static function(string $table,array $values)use($db):int {synk_management_execute($db,'INSERT INTO '.$table.' (`'.implode('`,`',array_keys($values)).'`) VALUES ('.implode(',',array_fill(0,count($values),'?')).')',str_repeat('s',count($values)),array_values($values));return (int)$db->insert_id;};
    $org=$insert('tbl_management_organizations',['college_id'=>$college['college_id'],'organization_code'=>$tag,'organization_name'=>$tag]);
    $scope=['college_id'=>(int)$college['college_id'],'campus_id'=>(int)$college['campus_id'],'organization_id'=>$org];
    $user=$insert('tbl_useraccount',['username'=>$tag,'email'=>str_replace(' ','',$tag).'@gmail.com','password'=>'unused','role'=>'management','status'=>'active','management_college_id'=>$scope['college_id'],'management_organization_id'=>$org]);
    synk_persist_useraccount_roles($db,$user,['management'],'management');$_SESSION=['user_id'=>$user,'role'=>'management','auth_method'=>'google'];
    $period=synk_management_fetch_current_period($db,$scope);
    $input=static function(array $values=[])use($period):array {return array_merge(['term'=>$period['key'],'exam_term'=>$period['exam_term'],'current_term'=>$period['key'],'current_exam_term'=>$period['exam_term'],'current_period_version'=>$period['period_version']],$values);};
    $student=static function(string $suffix,array $values=[])use($insert,$tag,$period,$college):int {return $insert('tbl_student_management',array_merge(['student_number'=>str_replace(' ','',$tag).$suffix,'first_name'=>'TEST','last_name'=>$tag,'source_sheet_name'=>'DEAN MANUAL','program_id'=>$college['program_id'],'ay_id'=>$period['ay_id'],'semester'=>$period['semester']],$values));};
    $present=$student('P');$absent=$student('A');
    $event=synk_management_catalog_write($db,$scope,'events','save',$input(['title'=>$tag,'event_date'=>date('Y-m-d'),'absence_fine'=>'25.10']))['record_id'];
    $eventRow=static function()use($db,$scope,$period,$event):array{foreach(synk_management_catalog_list($db,$scope,$period,'events',[])['rows']as$row)if((int)$row['event_id']===$event)return $row;throw new RuntimeException('Event is missing.');};
    $check($eventRow()['attendance_status']==='NOT_STARTED' && $eventRow()['attendance_present']===0 && $eventRow()['attendance_remaining']>=2,'An unopened event does not show its eligible remaining students.');
    foreach(['0.10','0.20']as$amount)synk_management_catalog_write($db,$scope,'contributions','save',$input(['title'=>$tag.$amount,'contribution_date'=>date('Y-m-d'),'amount'=>$amount,'audience_mode'=>'SELECTED'])) ;
    $overview=static function(int $id)use($db,$scope,$period):array {return synk_management_student_financial_overview($db,$scope,$period,$id);};
    $check($overview($absent)['summary']['total_to_pay']==='0.30','Contributions were not automatically applied using exact decimal amounts.');
    $check($overview($absent)['summary']['event_fine_total']==='0.00' && $overview($absent)['events'][0]['student_attendance']==='PENDING','An unopened event produced an absence fine.');
    $opened=synk_management_attendance_write($db,$scope,'attendance_open',$input(['event_id'=>$event]));
    $check($opened['attendance']['attendance_status']==='OPEN' && $opened['attendance']['eligible']>=2,'Attendance did not open with the semester roster.');
    $check($eventRow()['attendance_status']==='OPEN' && $eventRow()['attendance_remaining']===$opened['attendance']['eligible'],'The event list did not show its opened roster.');
    $qr=synk_management_student_qr($db,$present);
    $check($qr===synk_management_student_qr($db,$present) && strpos($qr,'SYNK-ATTENDANCE:')===0,'Student QR identity was not stable.');
    $scan=synk_management_attendance_write($db,$scope,'attendance_scan',$input(['event_id'=>$event,'qr_code'=>$qr]));
    $check(!$scan['duplicate'] && $scan['student']['student_id']==$present,'A valid student QR was not recorded.');
    $check(!empty($scan['student']['recorded_at']) && array_key_exists('photo_url',$scan['student']),'A saved scan did not return its recorded time and student photo field.');
    $again=synk_management_attendance_write($db,$scope,'attendance_scan',$input(['event_id'=>$event,'qr_code'=>$qr]));
    $check($again['duplicate'] && $again['attendance']['present']===1,'A repeated scan duplicated attendance.');
    $check($eventRow()['attendance_present']===1 && $eventRow()['attendance_remaining']===$opened['attendance']['eligible']-1,'Event table counts duplicate scans or ignores saved attendance.');
    $check($again['student']['recorded_at']===$scan['student']['recorded_at'],'A duplicate scan changed the original attendance time.');
    $check($overview($absent)['summary']['total_to_pay']==='0.30','An open event fined a student without a scan.');
    $reject=static function(string $action,array $values)use($db,$scope,$input,$check):void {try{synk_management_attendance_write($db,$scope,$action,$input($values));$check(false,'Invalid attendance action was accepted.');}catch(DomainException|SynkManagementConflict|OutOfBoundsException $error){$check(true,'');}};
    $reject('attendance_scan',['event_id'=>$event,'qr_code'=>'12345']);
    $late=$student('L');$lateQr=synk_management_student_qr($db,$late);
    $check($eventRow()['attendance_remaining']===$opened['attendance']['eligible']-1,'A later student changed the event table frozen roster count.');
    $reject('attendance_scan',['event_id'=>$event,'qr_code'=>$lateQr]);
    $reject('attendance_close',['event_id'=>$event,'attendance_revision'=>'1','confirm'=>'0']);
    $reject('attendance_close',['event_id'=>$event,'attendance_revision'=>'999','confirm'=>'1']);
    $closed=synk_management_attendance_write($db,$scope,'attendance_close',$input(['event_id'=>$event,'attendance_revision'=>$again['attendance']['revision'],'confirm'=>'1']));
    $check($closed['attendance']['attendance_status']==='CLOSED','Attendance did not close.');
    $check($eventRow()['attendance_status']==='CLOSED' && $eventRow()['attendance_remaining']===$closed['attendance']['absent'],'Closed event counts differ from finalized absences.');
    $check($overview($absent)['summary']['total_to_pay']==='25.40' && $overview($absent)['summary']['absence_count']===1,'Closed attendance did not add the frozen absence fine exactly.');
    $check($overview($present)['summary']['total_to_pay']==='0.30' && $overview($present)['events'][0]['student_attendance']==='PRESENT','A student with attendance was fined.');
    $check($overview($late)['summary']['event_fine_total']==='0.00' && $overview($late)['events'][0]['student_attendance']==='NOT_IN_ROSTER','A student added after the roster snapshot was fined.');
    $reject('attendance_scan',['event_id'=>$event,'qr_code'=>$qr]);
    $record=synk_management_catalog_get($db,$scope,'events',$event);
    try{synk_management_catalog_write($db,$scope,'events','save',$input(['record_id'=>$event,'version'=>$record['version'],'title'=>$tag,'event_date'=>date('Y-m-d'),'absence_fine'=>'100.00']));$check(false,'An attendance event fine was changed.');}catch(SynkManagementConflict $error){$check(true,'');}
    try{synk_management_catalog_write($db,$scope,'events','delete',$input(['record_id'=>$event,'version'=>$record['version']]));$check(false,'An event with attendance was deleted.');}catch(SynkManagementConflict $error){$check(true,'');}
    $reopened=synk_management_attendance_write($db,$scope,'attendance_reopen',$input(['event_id'=>$event,'attendance_revision'=>$closed['attendance']['revision'],'confirm'=>'1']));
    $check($overview($absent)['summary']['total_to_pay']==='0.30','Reopening did not remove provisional absence fines.');
    $absentQr=synk_management_student_qr($db,$absent);
    synk_management_attendance_write($db,$scope,'attendance_scan',$input(['event_id'=>$event,'qr_code'=>$absentQr]));
    synk_management_attendance_write($db,$scope,'attendance_close',$input(['event_id'=>$event,'attendance_revision'=>$reopened['attendance']['revision'],'confirm'=>'1']));
    $check($overview($absent)['summary']['total_to_pay']==='0.30','A corrected attendance still left a fine.');
    $check(array_column($db->query('SELECT action FROM tbl_management_attendance_audit WHERE event_id='.$event.' ORDER BY audit_id')->fetch_all(MYSQLI_ASSOC),'action')===['OPEN','CLOSE','REOPEN','CLOSE'],'Attendance state changes were not audited.');
    $future=synk_management_catalog_write($db,$scope,'events','save',$input(['title'=>$tag.' FUTURE','event_date'=>(new DateTimeImmutable('tomorrow'))->format('Y-m-d'),'absence_fine'=>'50.00']))['record_id'];
    $reject('attendance_open',['event_id'=>$future]);
    $other=synk_management_catalog_write($db,$scope,'events','save',$input(['title'=>$tag.' WRONG PERIOD','event_date'=>date('Y-m-d')]))['record_id'];
    $db->query('UPDATE tbl_management_events SET exam_term='.($period['exam_term']==='MIDTERM'?"'FINAL'":"'MIDTERM'").' WHERE event_id='.$other);
    $reject('attendance_open',['event_id'=>$other]);
    $check($overview($present)['summary']['event_count']===2,'Events from another Management term were included.');
    $foreign=$insert('tbl_management_organizations',['college_id'=>$scope['college_id'],'organization_code'=>$tag.'X','organization_name'=>$tag.'X']);
    $db->query('UPDATE tbl_management_events SET organization_id='.$foreign.' WHERE event_id='.$other);
    $reject('attendance_get',['event_id'=>$other]);
} finally { $db->discard();$db->close(); }
$check($snapshot($conn)===$before,'Attendance checks changed stored or shared business data.');
echo 'Management attendance: ',$checks," checks passed; all fixtures rolled back. Scheduling, academic settings and student profiles unchanged.\n";
