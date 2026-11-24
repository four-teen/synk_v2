<?php
// Full attendance/financial integration, with every fixture and QR rolled back.
if (PHP_SAPI!=='cli') { http_response_code(404);exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__.'/../backend/db.php';
require_once __DIR__.'/../backend/management_attendance_helper.php';
require_once __DIR__.'/../backend/management_attendance_review_helper.php';
require_once __DIR__.'/../backend/management_payment_helper.php';
require_once __DIR__.'/../backend/management_attendance_workbook_helper.php';
require_once __DIR__.'/../backend/management_past_attendance_helper.php';
require_once __DIR__.'/../backend/management_flag_raising_helper.php';
require_once __DIR__.'/../backend/management_reports_helper.php';
require_once __DIR__.'/../backend/management_student_financial_helper.php';
require_once __DIR__.'/fixtures/management-frc-workbook.php';
class ManagementAttendanceTestConnection extends mysqli {
    public function start():void { parent::begin_transaction(); }
    public function discard():void { parent::rollback(); }
    public function begin_transaction($flags=0,$name=null):bool { return $this->query('SAVEPOINT attendance_test'); }
    public function commit($flags=0,$name=null):bool { return $this->query('RELEASE SAVEPOINT attendance_test'); }
    public function rollback($flags=0,$name=null):bool { $this->query('ROLLBACK TO SAVEPOINT attendance_test');return $this->query('RELEASE SAVEPOINT attendance_test'); }
}
$tables=['tbl_academic_settings','tbl_academic_years','tbl_student_management','tbl_student_management_enrolled_subjects','tbl_useraccount','tbl_useraccount_roles','tbl_useraccount_faculty_links','tbl_faculty','tbl_class_schedule','tbl_faculty_workload_sched','tbl_management_organizations','tbl_management_period_settings','tbl_management_contributions','tbl_management_events','tbl_management_event_sessions','tbl_management_attendance_events','tbl_management_attendance_roster','tbl_management_attendance_logs','tbl_management_attendance_audit','tbl_management_attendance_checkpoints','tbl_management_attendance_corrections','tbl_management_payments','tbl_management_payment_items','tbl_management_student_qr_tokens','tbl_management_exemptions','tbl_management_exemption_audit'];
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
    try{synk_management_catalog_write($db,$scope,'events','delete',$input(['record_id'=>$event,'version'=>$record['version'],'confirm'=>'1']));$check(false,'An unauthorized account deleted an event with attendance.');}catch(SynkManagementPermissionDenied $error){$check(true,'');}
    $reopened=synk_management_attendance_write($db,$scope,'attendance_reopen',$input(['event_id'=>$event,'attendance_revision'=>$closed['attendance']['revision'],'confirm'=>'1']));
    $check($overview($absent)['summary']['total_to_pay']==='0.30','Reopening did not remove provisional absence fines.');
    $absentQr=synk_management_student_qr($db,$absent);
    synk_management_attendance_write($db,$scope,'attendance_scan',$input(['event_id'=>$event,'qr_code'=>$absentQr]));
    synk_management_attendance_write($db,$scope,'attendance_close',$input(['event_id'=>$event,'attendance_revision'=>$reopened['attendance']['revision'],'confirm'=>'1']));
    $check($overview($absent)['summary']['total_to_pay']==='0.30','A corrected attendance still left a fine.');
    $check(array_column($db->query('SELECT action FROM tbl_management_attendance_audit WHERE event_id='.$event.' ORDER BY audit_id')->fetch_all(MYSQLI_ASSOC),'action')===['OPEN','CLOSE','REOPEN','CLOSE'],'Attendance state changes were not audited.');
    $closedRegular=synk_management_attendance_summary($db,$scope,$event);
    $regularEdit=$input(['event_id'=>$event,'student_id'=>$absent,'attendance_revision'=>$closedRegular['revision'],
        'corrected_status'=>'ABSENT','reason'=>'Test correction']);
    $regularChanged=synk_management_attendance_write($db,$scope,'attendance_correct',$regularEdit)['attendance'];
    $check($regularChanged['present']===$closedRegular['present']-1 && $overview($absent)['summary']['event_fine_total']==='25.10',
        'Correcting a regular event did not recalculate attendance and the fine.');
    synk_management_attendance_write($db,$scope,'attendance_correct',array_replace($regularEdit,
        ['attendance_revision'=>$regularChanged['revision'],'corrected_status'=>'PRESENT','reason'=>'Scanned student verified']));
    $check($overview($absent)['summary']['event_fine_total']==='0.00','Restoring regular attendance did not clear the unpaid fine.');
    $future=synk_management_catalog_write($db,$scope,'events','save',$input(['title'=>$tag.' FUTURE','event_date'=>(new DateTimeImmutable('tomorrow'))->format('Y-m-d'),'absence_fine'=>'50.00']))['record_id'];
    $reject('attendance_open',['event_id'=>$future]);
    $other=synk_management_catalog_write($db,$scope,'events','save',$input(['title'=>$tag.' WRONG PERIOD','event_date'=>date('Y-m-d')]))['record_id'];
    $db->query('UPDATE tbl_management_events SET exam_term='.($period['exam_term']==='MIDTERM'?"'FINAL'":"'MIDTERM'").' WHERE event_id='.$other);
    $reject('attendance_open',['event_id'=>$other]);
    $check($overview($present)['summary']['event_count']===2,'Events from another Management term were included.');
    $foreign=$insert('tbl_management_organizations',['college_id'=>$scope['college_id'],'organization_code'=>$tag.'X','organization_name'=>$tag.'X']);
    $db->query('UPDATE tbl_management_events SET organization_id='.$foreign.' WHERE event_id='.$other);
    $reject('attendance_get',['event_id'=>$other]);

    $startYear=(int)$period['ay_label'];
    $pastDate=$period['semester']===1 ? $startYear.'-08-01' : ($startYear+1).'-01-01';
    if (in_array($period['semester'],[1,2],true) && $pastDate<date('Y-m-d')) {
        synk_management_save_current_exam_term($db,$scope,$input(['exam_term'=>'FINAL']));
        $current=synk_management_fetch_current_period($db,$scope);
        $prefix=(string)random_int(100000000,999999999);
        $presentNumber=$prefix.'1';
        $pendingNumber=$prefix.'2';
        $missingNumber=$prefix.'3';
        $pastPresent=$student('', ['student_number'=>$presentNumber]);
        $pastPending=$student('', ['student_number'=>$pendingNumber]);
        $fixturePath=tempnam(sys_get_temp_dir(),'synk-frc-');
        if ($fixturePath===false) throw new RuntimeException('Unable to create the FRC workbook fixture.');
        try {
            synk_test_write_frc_workbook($fixturePath,$pastDate,[$presentNumber,$presentNumber,$missingNumber]);
            $workbook=synk_management_parse_attendance_workbook($fixturePath,'FRC sample.xlsx','FRC');
        } finally {
            unlink($fixturePath);
        }
        $check($workbook['event_date']===$pastDate && $workbook['source']['worksheet']==='FRC source tab'
            && array_column($workbook['entries'],'student_number')===[$presentNumber,$presentNumber,$missingNumber],'FRC workbook values were read incorrectly.');
        $pastInput=[
            'title'=>$tag.' PAST',
            'event_date'=>$pastDate,
            'import_ay_id'=>(string)$current['ay_id'],
            'import_semester'=>(string)$current['semester'],
            'import_exam_term'=>'MIDTERM',
            'current_term'=>$current['key'],
            'current_exam_term'=>$current['exam_term'],
            'current_period_version'=>$current['period_version'],
        ];
        $preview=synk_management_record_past_attendance($db,$scope,'attendance_past_preview',$pastInput,$workbook)['preview'];
        $unmatched=[['student_number'=>$missingNumber,'cells'=>['A4']]];
        $check($preview['new_present']===1 && $preview['duplicates']===1 && $preview['review']['unmatched']===$unmatched,
            'Past attendance did not distinguish new, duplicate and unmatched IDs.');
        $eventQuery='SELECT event_id,event_date,exam_term,source_metadata FROM tbl_management_events WHERE organization_id=? AND title=?';
        $check(!synk_management_query($db,$eventQuery,'is',[$org,$pastInput['title']]),'Preview created a past event.');
        $confirmation=$pastInput+['preview_token'=>$preview['token']];
        try {
            synk_management_record_past_attendance($db,$scope,'attendance_past_save',$confirmation,array_replace($workbook,['entries'=>[['student_number'=>$pendingNumber,'cell'=>'A2']]]));
            $check(false,'Past attendance accepted a changed workbook with a stale preview.');
        } catch (SynkManagementConflict $error) { $check(true,''); }
        $saved=synk_management_record_past_attendance($db,$scope,'attendance_past_save',$confirmation,$workbook);
        $created=synk_management_query($db,$eventQuery,'is',[$org,$pastInput['title']])[0];
        $source=json_decode($created['source_metadata'],true)['attendance_import'];
        $check($saved['recorded']===1 && $saved['review']['unmatched']===$unmatched && $created['event_date']===$pastDate && $created['exam_term']==='MIDTERM'
            && $source===$workbook['source'],'Past attendance lost the date, workbook provenance, or historical period.');
        $check(synk_management_fetch_current_period($db,$scope)===$current,'Past attendance changed the current Management period.');
        $pastPeriod=synk_management_view_term($db,['term'=>$saved['term'],'exam_term'=>$saved['exam_term']],$scope);
        $eventStatus=static function(int $studentId)use($db,$scope,$pastPeriod,$created): string {
            $data=synk_management_report_attendance($db,$scope,$pastPeriod,(int)$created['event_id']);
            foreach ($data['groups'] as $status=>$rows) foreach ($rows as $row) {
                if ((int)$row['student_id']===$studentId) return $status==='absent' && !$data['finalized'] ? 'pending' : $status;
            }
            throw new RuntimeException('Student is missing from the ceremony roster.');
        };
        $check($eventStatus($pastPresent)==='present' && $eventStatus($pastPending)==='pending'
            && !synk_management_flag_raising_absences($db,$scope,$current,[])['rows'],'Open ceremony attendance entered finalized absence totals.');
        $eventId=(int)$created['event_id'];
        $original=synk_management_catalog_get($db,$scope,'events',$eventId);
        $historical=$pastInput+['term'=>$saved['term'],'exam_term'=>$saved['exam_term']];
        $edit=array_replace($historical,['title'=>strtoupper($tag).' RENAMED','record_id'=>$eventId,'version'=>$original['version'],
            'event_date'=>$pastDate,'absence_fine'=>'10.00','description'=>'Updated details']);
        synk_management_catalog_write($db,$scope,'flag_raising','save',$edit);
        $updated=synk_management_catalog_get($db,$scope,'events',$eventId);
        $check($updated['title']===$edit['title'] && $updated['description']==='UPDATED DETAILS' && $updated['absence_fine']==='0.00'
            && $updated['source_metadata']===$created['source_metadata'],'Editing a ceremony modified its source or allowed a fine.');
        $repeat=synk_management_record_past_attendance($db,$scope,'attendance_past_preview',$pastInput,$workbook)['preview'];
        $check($repeat['existing_event'] && $repeat['title']===$edit['title'] && $repeat['already_present']===1 && $repeat['new_present']===0,
            'A repeat import did not recognize attendance after the event title changed.');
        synk_management_exemption_write($db,$scope,'exemption_apply',$historical+['student_ids'=>json_encode([$pastPending]),
            'event_ids'=>json_encode([$eventId]),'reason'=>'Approved reason']);
        $exemptions=synk_management_exemption_data($db,$scope,$pastPeriod,['event_id'=>$eventId]);
        $check($eventStatus($pastPending)==='exempted' && $exemptions['editable_imports']===[$eventId],
            'Open imported attendance could not receive an exemption in its original period.');
        $grant=$exemptions['grants'][0];
        synk_management_exemption_write($db,$scope,'exemption_revoke',$historical+['exemption_id'=>$grant['exemption_id'],
            'revision'=>$grant['revision'],'reason'=>'Updated exemption decision']);
        $check($eventStatus($pastPending)==='pending','Revoking the exemption changed pending attendance.');
        $attendance=synk_management_attendance_summary($db,$scope,$eventId);
        $closed=synk_management_attendance_write($db,$scope,'attendance_close',$historical+[
            'event_id'=>$eventId,'attendance_revision'=>$attendance['revision'],'confirm'=>'1'
        ])['attendance'];
        $check($closed['attendance_status']==='CLOSED' && $closed['present']===1 && $closed['absent']===$closed['eligible']-1
            && $eventStatus($pastPending)==='absent','Closing a past import did not finalize the remaining roster.');
        $studentAbsences=static function(string $number)use($db,$scope,$current): int {
            $data=synk_management_flag_raising_absences($db,$scope,$current,['absence_search'=>$number]);
            return (int)$data['rows'][0]['absences'];
        };
        $check($studentAbsences($pendingNumber)===1 && $studentAbsences($presentNumber)===0,
            'The ceremony did not update semester totals for both absent and present students.');
        $financial=synk_management_student_financial_overview($db,$scope,$pastPeriod,$pastPending);
        $check(!in_array($eventId,array_map('intval',array_column($financial['events'],'event_id')),true)
            && !synk_management_report_absence_fines($db,$scope,$pastPeriod,$eventId)['states'],'Ceremony attendance appeared in financial events or fines.');
        $closedPreview=synk_management_record_past_attendance($db,$scope,'attendance_past_preview',$pastInput,$workbook)['preview'];
        $check($closedPreview['event_id']===$eventId && $closedPreview['attendance_status']==='CLOSED' && !$closedPreview['can_import']
            && $closedPreview['review']['duplicates']===[['student_number'=>$presentNumber,'cells'=>['A2','A3']]],
            'A closed import preview did not retain its event link and duplicate list.');
        synk_management_exemption_write($db,$scope,'exemption_apply',$historical+['student_ids'=>json_encode([$pastPending]),
            'event_ids'=>json_encode([$eventId]),'reason'=>'Approved after closure']);
        $closedExempt=synk_management_attendance_summary($db,$scope,$eventId);
        $exemptions=synk_management_exemption_data($db,$scope,$pastPeriod,['event_id'=>$eventId]);
        $check($closedExempt['attendance_status']==='CLOSED' && $closedExempt['absent']===$closed['absent']-1
            && $closedExempt['exempted']===1 && $exemptions['editable_imports']===[$eventId]
            && $eventStatus($pastPending)==='exempted' && $studentAbsences($pendingNumber)===0,
            'Granting an exemption on a closed event did not replace the absence.');
        $grant=$exemptions['grants'][0];
        synk_management_exemption_write($db,$scope,'exemption_revoke',$historical+['exemption_id'=>$grant['exemption_id'],
            'revision'=>$grant['revision'],'reason'=>'Revised exemption decision']);
        $reverted=synk_management_attendance_summary($db,$scope,$eventId);
        $check($reverted['attendance_status']==='CLOSED' && $reverted['absent']===$closed['absent'] && $studentAbsences($pendingNumber)===1,
            'Revoking an exemption did not restore the absence on the closed event.');
        $linked=synk_management_catalog_list($db,$scope,$current,'flag_raising',['event_id'=>(string)$eventId]);
        $check(count($linked['rows'])===1 && (int)$linked['rows'][0]['event_id']===$eventId && $linked['rows'][0]['attendance_status']==='CLOSED'
            && $linked['rows'][0]['attendance_present']===1 && $linked['rows'][0]['attendance_remaining']===$closed['absent']
            && synk_management_fetch_current_period($db,$scope)===$current,'The event link did not show the closed import in its period.');
        $check(!synk_management_catalog_list($db,$scope,$pastPeriod,'events',['event_id'=>(string)$eventId])['rows'],
            'The regular Events catalog included a Flag Raising Ceremony.');
        $manual=synk_management_catalog_write($db,$scope,'flag_raising','save',$historical+[
            'event_date'=>$pastDate,'absence_fine'=>'25.00'
        ])['record_id'];
        $manualRecord=synk_management_catalog_get($db,$scope,'flag_raising',$manual);
        $manualAttendance=synk_management_attendance_write($db,$scope,'attendance_open',$historical+['event_id'=>$manual])['attendance'];
        $check($manualRecord['absence_fine']==='0.00' && synk_management_is_flag_raising($manualRecord)
            && $manualAttendance['attendance_status']==='OPEN' && $manualAttendance['absence_fine']==='0.00',
            'Adding a ceremony manually did not create a separate zero-fine attendance record.');

        synk_management_attendance_write($db,$scope,'attendance_correct',$historical+['event_id'=>$eventId,'student_id'=>$pastPending,
            'attendance_revision'=>$reverted['revision'],'corrected_status'=>'PRESENT','reason'=>'Review correction before deletion']);
        $insert('tbl_management_event_sessions',['college_id'=>$scope['college_id'],'event_id'=>$eventId,
            'title'=>'TEST CEREMONY SESSION','starts_at'=>$pastDate.' 08:00:00','ends_at'=>$pastDate.' 08:30:00']);
        $delete=$historical+['record_id'=>$eventId,'confirm'=>'1','version'=>synk_management_catalog_get($db,$scope,'flag_raising',$eventId)['version']];
        try {
            synk_management_catalog_write($db,$scope,'flag_raising','delete',$delete);
            $check(false,'An account with only Management access could delete a ceremony.');
        } catch (SynkManagementPermissionDenied $error) { $check(true,''); }
        synk_persist_useraccount_roles($db,$user,['management','admin'],'management');
        try {
            synk_management_catalog_write($db,$scope,'flag_raising','delete',array_replace($delete,['confirm'=>'0']));
            $check(false,'An administrator could delete without confirming.');
        } catch (DomainException $error) { $check(true,''); }
        try {
            synk_management_catalog_write($db,$scope,'flag_raising','delete',array_replace($delete,['version'=>'old']));
            $check(false,'An administrator could delete using a stale record version.');
        } catch (SynkManagementConflict $error) { $check(true,''); }
        try {
            synk_management_catalog_write($db,$scope,'flag_raising','delete',array_replace($delete,['record_id'=>$event]));
            $check(false,'A regular event could be deleted through the ceremony action.');
        } catch (OutOfBoundsException $error) { $check(true,''); }
        $eventTables=['tbl_management_events','tbl_management_event_sessions','tbl_management_attendance_events',
            'tbl_management_attendance_roster','tbl_management_attendance_logs','tbl_management_attendance_audit','tbl_management_attendance_corrections','tbl_management_exemptions'];
        foreach ($eventTables as $table) {
            $check((bool)synk_management_query($db,'SELECT 1 FROM '.$table.' WHERE event_id=? LIMIT 1','i',[$eventId]),
                'The deletion fixture is missing connected records in '.$table.'.');
        }
        synk_management_catalog_write($db,$scope,'flag_raising','delete',$delete);
        foreach ($eventTables as $table) {
            $check(!synk_management_query($db,'SELECT 1 FROM '.$table.' WHERE event_id=? LIMIT 1','i',[$eventId]),
                'Deleting the closed ceremony left connected records in '.$table.'.');
        }
        $check($eventRow()['attendance_status']==='CLOSED' && !synk_management_flag_raising_absences($db,$scope,$current,[])['rows']
            && synk_management_student_qr($db,$present)===$qr && synk_management_attendance_summary($db,$scope,$manual)['attendance_status']==='OPEN',
            'Deleting a ceremony left an absence or affected other records or student QR access.');

        synk_persist_useraccount_roles($db,$user,['management'],'management');
        $faculty=$insert('tbl_faculty',['first_name'=>'QA Faculty','last_name'=>$tag,'status'=>'active']);
        $insert('tbl_useraccount_faculty_links',['user_id'=>$user,'faculty_id'=>$faculty]);
        synk_management_catalog_write($db,$scope,'flag_raising','delete',array_replace($delete,['record_id'=>$manual,'version'=>$manualRecord['version']]));
        $check(!synk_management_catalog_list($db,$scope,$current,'flag_raising',[])['rows'],
            'A faculty supervisor could not delete a ceremony.');

        $finalInput=array_replace($pastInput,['title'=>$tag.' IMPORTED FINAL','import_exam_term'=>'FINAL']);
        $finalPreview=synk_management_record_past_attendance($db,$scope,'attendance_past_preview',$finalInput,$workbook)['preview'];
        $finalSaved=synk_management_record_past_attendance($db,$scope,'attendance_past_save',$finalInput+['preview_token'=>$finalPreview['token']],$workbook);
        $finalRecord=synk_management_catalog_get($db,$scope,'flag_raising',$finalSaved['event_id']);
        synk_management_catalog_write($db,$scope,'flag_raising','save',$finalInput+['record_id'=>$finalSaved['event_id'],
            'version'=>$finalRecord['version'],'term'=>$finalSaved['term'],'description'=>'Final ceremony details']);
        $finalEdited=synk_management_catalog_get($db,$scope,'flag_raising',$finalSaved['event_id']);
        $check($finalEdited['exam_term']==='FINAL' && $finalEdited['description']==='FINAL CEREMONY DETAILS',
            'Editing a ceremony changed or rejected the term chosen during import.');

        $cud=synk_management_parse_event_attendance_rows([
            1=>['D'=>'IN','E'=>'OUT'],
            2=>['D'=>$presentNumber,'E'=>$presentNumber],
            3=>['D'=>$pendingNumber],
            4=>['E'=>$pendingNumber],
        ]);
        $cud['source']=['template'=>'EVENT','filename'=>'CUD test.xlsx','worksheet'=>'CLEAN UP DRIVE','heading'=>'IN / OUT','sha256'=>str_repeat('C',64)];
        $title=$tag.' CLEAN UP DRIVE';
        $draft=$insert('tbl_management_events',['college_id'=>$scope['college_id'],'organization_id'=>$org,'ay_id'=>$current['ay_id'],
            'semester'=>$current['semester'],'title'=>$title,'event_date'=>$pastDate,'status'=>'DRAFT','description'=>'PRIOR DETAILS']);
        $otherEvent=$insert('tbl_management_events',['college_id'=>$scope['college_id'],'organization_id'=>$org,'ay_id'=>$current['ay_id'],
            'semester'=>$current['semester'],'exam_term'=>'MIDTERM','title'=>$tag.' OTHER EVENT','event_date'=>$pastDate,
            'status'=>'ACTIVE','updated_at'=>'2000-01-01 00:00:00']);
        $cudInput=array_replace($pastInput,['title'=>$title,'absence_fine'=>'25.50']);
        $cudPlan=synk_management_record_past_attendance($db,$scope,'attendance_past_preview',$cudInput,$cud)['preview'];
        $cudSaved=synk_management_record_past_attendance($db,$scope,'attendance_past_save',$cudInput+['confirm'=>'1','preview_token'=>$cudPlan['token']],$cud);
        $cudRecord=synk_management_catalog_get($db,$scope,'events',$draft);
        $check($cudSaved['event_id']===$draft && $cudRecord['status']==='ACTIVE' && $cudRecord['description']==='PRIOR DETAILS'
            && $cudRecord['exam_term']==='MIDTERM' && $cudSaved['present']===1 && $cudSaved['attendance_status']==='CLOSED'
            && synk_management_fetch_current_period($db,$scope)===$current,'CUD did not reuse the draft, finalize attendance, and preserve operating settings.');
        $cudList=synk_management_catalog_list($db,$scope,$pastPeriod,'events',['event_id'=>(string)$draft]);
        $check($cudList['rows'][0]['attendance_status']==='CLOSED' && $cudList['rows'][0]['attendance_present']===1
            && !synk_management_catalog_list($db,$scope,$current,'flag_raising',['event_id'=>(string)$draft])['rows'],'CUD did not appear in regular Events.');
        $fullList=synk_management_catalog_list($db,$scope,$pastPeriod,'events',['saved_event_id'=>(string)$draft]);
        $fullIds=array_map('intval',array_column($fullList['rows'],'event_id'));
        $check($fullIds[0]===$draft && in_array($otherEvent,$fullIds,true) && $fullList['pagination']['total']>1,
            'The Events list after importing filtered out other rows or failed to put the saved draft first.');
        $newest=synk_management_catalog_list($db,$scope,$pastPeriod,'events',[]);
        $check((int)$newest['rows'][0]['event_id']===$draft,'The saved draft was not ordered by its most recent update.');
        $storedSource=json_decode($cudRecord['source_metadata'],true);
        $check($storedSource['attendance_import']['template']==='EVENT','The new import was not stored as EVENT.');
        $storedSource['attendance_import']['template']='CUD';
        $legacy=$cudRecord;
        $legacy['source_metadata']=json_encode($storedSource);
        $check(synk_management_attendance_import_template($legacy)==='EVENT','The previous CUD template did not resolve to the EVENT rules.');
        $cudCharge=static function(int $id)use($db,$scope,$pastPeriod,$draft): array {
            $events=synk_management_student_financial_overview($db,$scope,$pastPeriod,$id)['events'];
            return array_column($events,null,'event_id')[$draft];
        };
        $check($cudCharge($pastPresent)['student_attendance']==='PRESENT' && $cudCharge($pastPresent)['amount_due']==='0.00'
            && $cudCharge($pastPending)['student_attendance']==='ABSENT' && $cudCharge($pastPending)['amount_due']==='25.50',
            'CUD attendance did not apply the event fine exclusively to absence.');
        $review=synk_management_closed_attendance_roster($db,$cudRecord,['roster_search'=>$pendingNumber,'roster_status'=>'ABSENT']);
        $check($review['total']===1 && $review['rows'][0]['checked_in'] && $review['rows'][0]['checked_out']
            && $review['rows'][0]['attendance_status']==='ABSENT','The review lost split checkpoints or changed the imported status.');
        $correction=$historical+['event_id'=>$draft,'student_id'=>$pastPending,'attendance_revision'=>$review['revision'],
            'checked_in'=>'1','checked_out'=>'1','reason'=>'Verified with paper sign-in'];
        $fixed=synk_management_attendance_write($db,$scope,'attendance_correct',$correction)['attendance'];
        $check($fixed['attendance_status']==='CLOSED' && $fixed['present']===2 && $fixed['opened_at'] && $fixed['closed_at']
            && $cudCharge($pastPending)['amount_due']==='0.00','Confirming both checkpoints did not clear the unpaid fine.');
        $unchanged=synk_management_attendance_write($db,$scope,'attendance_correct',array_replace($correction,['attendance_revision'=>$fixed['revision']]));
        $check(!$unchanged['corrected'] && $unchanged['attendance']['revision']===$fixed['revision'],'A no-op correction changed the roster revision.');
        try {
            synk_management_attendance_write($db,$scope,'attendance_correct',$correction);
            $check(false,'A stale correction overwrote newer attendance.');
        } catch (SynkManagementConflict $error) { $check(true,''); }
        try {
            synk_management_attendance_write($db,$scope,'attendance_correct',array_replace($correction,['attendance_revision'=>$fixed['revision'],'student_id'=>4294967295]));
            $check(false,'The correction accepted a student outside the roster.');
        } catch (OutOfBoundsException $error) { $check(true,''); }
        $reversed=synk_management_attendance_write($db,$scope,'attendance_correct',array_replace($correction,
            ['attendance_revision'=>$fixed['revision'],'checked_out'=>'0','reason'=>'OUT record belongs to another student']))['attendance'];
        $checked=synk_management_closed_attendance_roster($db,$cudRecord,['roster_search'=>$pendingNumber])['rows'][0];
        $check($reversed['present']===1 && $reversed['closed_at']===$fixed['closed_at'] && $checked['checked_in'] && !$checked['checked_out']
            && $checked['attendance_status']==='ABSENT' && $checked['correction_reason']==='OUT record belongs to another student'
            && $cudCharge($pastPending)['amount_due']==='25.50','The correction did not preserve IN, the closing time, or the reason for a changed absence.');
        $audit=synk_management_query($db,'SELECT previous_present,corrected_present,previous_in,previous_out,recorded_by FROM tbl_management_attendance_corrections WHERE event_id=? ORDER BY correction_id','i',[$draft]);
        $check(count($audit)===2 && (int)$audit[0]['previous_present']===0 && (int)$audit[0]['previous_in']===1
            && (int)$audit[0]['previous_out']===1 && (int)$audit[0]['recorded_by']===$user,'The correction lost its original evidence or editor.');
        $statusOnly=$historical+['event_id'=>$draft,'student_id'=>$pastPending,'attendance_revision'=>$reversed['revision'],
            'corrected_status'=>'PRESENT','reason'=>'  Confirmed with the cleanup lead.  '];
        try {
            synk_management_attendance_write($db,$scope,'attendance_correct',array_replace($statusOnly,['reason'=>'   ']));
            $check(false,'A blank comment was accepted for a status correction.');
        } catch (DomainException $error) { $check(strpos($error->getMessage(),'comment')!==false,'The missing-comment error was unclear.'); }
        $override=synk_management_attendance_write($db,$scope,'attendance_correct',$statusOnly)['attendance'];
        $markedPresent=synk_management_closed_attendance_roster($db,$cudRecord,['roster_search'=>$pendingNumber,'roster_status'=>'PRESENT'])['rows'][0];
        $check($override['attendance_status']==='CLOSED' && $override['present']===2 && $markedPresent['checked_in'] && !$markedPresent['checked_out']
            && $markedPresent['correction_reason']==='Confirmed with the cleanup lead.' && $cudCharge($pastPending)['amount_due']==='0.00',
            'Directly marking Present modified the checkpoint evidence or failed to save the comment and clear the unpaid fine.');
        $finalStatus=synk_management_attendance_write($db,$scope,'attendance_correct',array_replace($statusOnly,
            ['attendance_revision'=>$override['revision'],'corrected_status'=>'ABSENT','reason'=>'The sign-in was for a different event.']))['attendance'];
        $markedAbsent=synk_management_closed_attendance_roster($db,$cudRecord,['roster_search'=>$pendingNumber,'roster_status'=>'ABSENT'])['rows'][0];
        $check($finalStatus['present']===1 && $markedAbsent['checked_in'] && !$markedAbsent['checked_out']
            && $markedAbsent['correction_reason']==='The sign-in was for a different event.' && $cudCharge($pastPending)['amount_due']==='25.50',
            'Directly marking Absent did not preserve checkpoints, show the latest comment, or restore the fine.');
        try {
            synk_management_attendance_write($db,$scope,'attendance_reopen',$historical+['event_id'=>$draft,'attendance_revision'=>'2','confirm'=>'1']);
            $check(false,'CUD could be reopened for a single attendance scan.');
        } catch (SynkManagementConflict $error) { $check(true,''); }
        synk_management_exemption_write($db,$scope,'exemption_apply',$historical+['student_ids'=>json_encode([$pastPending]),
            'event_ids'=>json_encode([$draft]),'reason'=>'Approved CUD exemption']);
        $check($cudCharge($pastPending)['amount_due']==='0.00' && synk_management_attendance_summary($db,$scope,$draft)['exempted']===1,
            'An exemption did not remove the CUD absence fine.');

        synk_management_save_current_exam_term($db,$scope,$historical);
        $mid=synk_management_fetch_current_period($db,$scope);
        $midInput=array_replace($historical,['current_exam_term'=>$mid['exam_term'],'current_period_version'=>$mid['period_version']]);
        $balance=synk_management_student_financial_overview($db,$scope,$mid,$late);
        $paid=synk_management_payment_post($db,$scope,$midInput+['student_id'=>$late,'allocation_mode'=>'SELECTED','amount'=>'25.50',
            'selected'=>json_encode(['E:'.$draft]),'account_version'=>$balance['account_version'],'request_key'=>bin2hex(random_bytes(32))]);
        try {
            synk_management_attendance_write($db,$scope,'attendance_correct',$midInput+['event_id'=>$draft,'student_id'=>$late,
                'attendance_revision'=>$finalStatus['revision'],'corrected_status'=>'PRESENT','reason'=>'Verified after payment']);
            $check(false,'A correction invalidated a paid absence fine.');
        } catch (SynkManagementConflict $error) {
            $check(strpos($error->getMessage(),'payment')!==false && synk_management_payment_receipt($db,$paid['payment_id'],null,$scope)['items'][0]['amount']==='25.50',
                'The correction did not preserve the existing receipt.');
        }
    }
} finally { $db->discard();$db->close(); }
$check($snapshot($conn)===$before,'Attendance checks changed stored or shared business data.');
echo 'Management attendance: ',$checks," checks passed; all fixtures rolled back. Scheduling, academic settings and student profiles unchanged.\n";
