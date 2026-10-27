<?php
require_once __DIR__.'/management_catalog_helper.php';
require_once __DIR__.'/college_dean_students_helper.php';
require_once __DIR__.'/student_profile_image_helper.php';

function synk_management_attendance_ready(mysqli $conn): bool
{
    foreach (['tbl_management_attendance_events','tbl_management_attendance_roster','tbl_management_attendance_logs','tbl_management_student_qr_tokens','tbl_management_attendance_audit'] as $table) {
        if (!synk_table_exists($conn,$table)) return false;
    }
    return true;
}

function synk_management_student_qr(mysqli $conn,int $studentId): string
{
    if ($studentId<1 || !synk_management_attendance_ready($conn)) throw new RuntimeException('Attendance QR is unavailable.');
    $token=bin2hex(random_bytes(32));
    synk_management_execute($conn,'INSERT INTO tbl_management_student_qr_tokens (student_id,qr_token) VALUES (?,?) ON DUPLICATE KEY UPDATE student_id=VALUES(student_id)','is',[$studentId,$token]);
    $saved=synk_management_query($conn,'SELECT qr_token FROM tbl_management_student_qr_tokens WHERE student_id=?','i',[$studentId])[0];
    return 'SYNK-ATTENDANCE:'.$saved['qr_token'];
}

function synk_management_attendance_summary(mysqli $conn,array $scope,int $eventId): array
{
    $event=synk_management_catalog_get($conn,$scope,'events',$eventId);
    $settings=synk_management_query($conn,'SELECT attendance_status,absence_fine,revision,opened_at,closed_at FROM tbl_management_attendance_events WHERE event_id=? AND college_id=? AND organization_id=?','iii',[$eventId,$scope['college_id'],$scope['organization_id']]);
    $counts=synk_management_query($conn,'SELECT COUNT(*) AS eligible,COUNT(l.student_id) AS present FROM tbl_management_attendance_roster r LEFT JOIN tbl_management_attendance_logs l ON l.event_id=r.event_id AND l.student_id=r.student_id WHERE r.event_id=?','i',[$eventId])[0];
    $recent=synk_management_query($conn,'SELECT s.student_number,CONCAT_WS(\' \',s.first_name,s.middle_name,s.last_name,s.suffix_name) AS student_name,l.recorded_at FROM tbl_management_attendance_logs l JOIN tbl_student_management s ON s.student_id=l.student_id WHERE l.event_id=? ORDER BY l.recorded_at DESC,s.student_id DESC LIMIT 10','i',[$eventId]);
    return ['event_id'=>$eventId,'title'=>$event['title'],'event_date'=>$event['event_date'],
        'attendance_status'=>$settings[0]['attendance_status'] ?? 'NOT_STARTED','absence_fine'=>$settings[0]['absence_fine'] ?? ($event['absence_fine'] ?? '0.00'),
        'revision'=>(string)($settings[0]['revision'] ?? '0'),'eligible'=>(int)$counts['eligible'],'present'=>(int)$counts['present'],
        'absent'=>($settings[0]['attendance_status'] ?? '')==='CLOSED' ? (int)$counts['eligible']-(int)$counts['present'] : 0,'recent'=>$recent];
}

function synk_management_attendance_write(mysqli $conn,array $scope,string $action,array $input): array
{
    if (!synk_management_attendance_ready($conn)) throw new RuntimeException('Attendance storage has not been prepared.');
    $eventId=synk_management_input_id($input['event_id'] ?? null);
    $conn->begin_transaction();
    try {
        synk_management_lock_assignment($conn,$scope);
        $period=synk_management_lock_current_term($conn,$input,$scope);
        $event=synk_management_catalog_get($conn,$scope,'events',$eventId,true);
        if (!synk_management_catalog_in_period($event,$period) || $event['status']!=='ACTIVE') throw new SynkManagementConflict('Attendance requires an active event in the current Management period.');
        if ($event['event_date']>date('Y-m-d')) throw new SynkManagementConflict('Attendance can open on the event date.');
        $rows=synk_management_query($conn,'SELECT * FROM tbl_management_attendance_events WHERE event_id=? FOR UPDATE','i',[$eventId]);
        $settings=$rows[0] ?? null;
        $auditAction=null;
        if ($action==='attendance_open') {
            if (!$settings) {
                synk_management_execute($conn,'INSERT INTO tbl_management_attendance_events (event_id,college_id,organization_id,ay_id,semester,exam_term,absence_fine,opened_by) VALUES (?,?,?,?,?,?,?,?)','iiiiissi',[$eventId,$scope['college_id'],$scope['organization_id'],$period['ay_id'],$period['semester'],$period['exam_term'],$event['absence_fine'] ?? '0.00',(int)$_SESSION['user_id']]);
                $source=synk_dean_students_source($scope,['ay_id'=>$period['ay_id'],'semester'=>$period['semester']],$conn);
                synk_management_execute($conn,'INSERT INTO tbl_management_attendance_roster (event_id,student_id) SELECT DISTINCT ?,s.student_id '.$source['sql'],'i'.$source['types'],array_merge([$eventId],$source['params']));
                $count=(int)synk_management_query($conn,'SELECT COUNT(*) AS n FROM tbl_management_attendance_roster WHERE event_id=?','i',[$eventId])[0]['n'];
                if (!$count) throw new SynkManagementConflict('There are no eligible students for this event period.');
                $auditAction='OPEN';
            } elseif ($settings['attendance_status']!=='OPEN') throw new SynkManagementConflict('Attendance is closed.');
        } elseif ($action==='attendance_scan') {
            if (!$settings || $settings['attendance_status']!=='OPEN') throw new SynkManagementConflict('Open attendance before scanning students.');
            $qr=$input['qr_code'] ?? '';
            if (!is_string($qr) || !preg_match('/^SYNK-ATTENDANCE:([a-f0-9]{64})$/D',trim($qr),$match)) throw new DomainException('This is not a Synk student attendance QR code.');
            $student=synk_management_query($conn,'SELECT s.student_id,s.student_number,CONCAT_WS(\' \',s.first_name,s.middle_name,s.last_name,s.suffix_name) AS student_name FROM tbl_management_student_qr_tokens q JOIN tbl_student_management s ON s.student_id=q.student_id JOIN tbl_management_attendance_roster r ON r.student_id=s.student_id AND r.event_id=? WHERE q.qr_token=?','is',[$eventId,$match[1]]);
            if (!$student) throw new DomainException('This student is not in this event\'s attendance roster.');
            $student=$student[0];
            $duplicate=(bool)synk_management_query($conn,'SELECT student_id FROM tbl_management_attendance_logs WHERE event_id=? AND student_id=?','ii',[$eventId,$student['student_id']]);
            if (!$duplicate) synk_management_execute($conn,'INSERT INTO tbl_management_attendance_logs (event_id,student_id,recorded_by) VALUES (?,?,?)','iii',[$eventId,$student['student_id'],(int)$_SESSION['user_id']]);
            $student['recorded_at']=synk_management_query($conn,'SELECT recorded_at FROM tbl_management_attendance_logs WHERE event_id=? AND student_id=?','ii',[$eventId,$student['student_id']])[0]['recorded_at'];
            $student['photo_url']=synk_student_profile_image_url((int)$student['student_id']);
        } elseif ($action==='attendance_close' || $action==='attendance_reopen') {
            if ($action==='attendance_reopen') {
                require_once __DIR__.'/management_payment_read_helper.php';
                if (synk_management_charge_has_payments($conn,'events',$eventId)) throw new SynkManagementConflict('Absence-fine payments have already been recorded. Attendance must remain closed to preserve those payments.');
            }
            if (!$settings || (string)($input['attendance_revision'] ?? '')!==(string)$settings['revision']) throw new SynkManagementConflict('Attendance changed. Reload before continuing.');
            $next=$action==='attendance_close'?'CLOSED':'OPEN';
            if ($settings['attendance_status']===$next) throw new SynkManagementConflict('Attendance is already '.strtolower($next).'.');
            if (($input['confirm'] ?? '')!=='1') throw new DomainException('Confirm the attendance change.');
            synk_management_execute($conn,'UPDATE tbl_management_attendance_events SET attendance_status=?,closed_by=?,closed_at='.($next==='CLOSED'?'CURRENT_TIMESTAMP':'NULL').',revision=revision+1 WHERE event_id=?','sii',[$next,$next==='CLOSED'?(int)$_SESSION['user_id']:null,$eventId]);
            $auditAction=$next==='CLOSED'?'CLOSE':'REOPEN';
        } else throw new DomainException('Choose a valid attendance action.');
        $summary=synk_management_attendance_summary($conn,$scope,$eventId);
        if ($auditAction) synk_management_execute($conn,'INSERT INTO tbl_management_attendance_audit (event_id,action,recorded_by,roster_count,present_count) VALUES (?,?,?,?,?)','isiii',[$eventId,$auditAction,(int)$_SESSION['user_id'],$summary['eligible'],$summary['present']]);
        $conn->commit();
        return ['attendance'=>$summary,'student'=>$student ?? null,'duplicate'=>$duplicate ?? false];
    } catch (Throwable $error) { $conn->rollback();throw $error; }
}
