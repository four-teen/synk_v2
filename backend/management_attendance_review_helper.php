<?php
require_once __DIR__.'/management_attendance_helper.php';
require_once __DIR__.'/management_payment_read_helper.php';

function synk_management_attendance_review_ready(mysqli $conn): bool
{
    return synk_table_exists($conn,'tbl_management_attendance_checkpoints')
        && synk_table_exists($conn,'tbl_management_attendance_corrections');
}

function synk_management_store_event_checkpoints(mysqli $conn,int $eventId,array $checkpoints): void
{
    foreach (array_chunk($checkpoints,250,true) as $batch) {
        $values=[];
        foreach ($batch as $studentId=>$pair) array_push($values,$eventId,$studentId,$pair[0],$pair[1]);
        synk_management_execute($conn,'INSERT INTO tbl_management_attendance_checkpoints (event_id,student_id,checked_in,checked_out) VALUES '
            .implode(',',array_fill(0,count($batch),'(?,?,?,?)'))
            .' ON DUPLICATE KEY UPDATE checked_in=VALUES(checked_in),checked_out=VALUES(checked_out)',str_repeat('iiii',count($batch)),$values);
    }
}

function synk_management_closed_attendance_roster(mysqli $conn,array $event,array $input): array
{
    if (!synk_management_attendance_review_ready($conn)) throw new RuntimeException('Attendance review storage has not been prepared.');
    $eventId=(int)$event['event_id'];
    $state=synk_management_query($conn,'SELECT attendance_status,revision FROM tbl_management_attendance_events WHERE event_id=?','i',[$eventId])[0] ?? null;
    if (($state['attendance_status'] ?? null)!=='CLOSED') throw new SynkManagementConflict('Close attendance to review the finalized roster.');

    $search=trim((string)($input['roster_search'] ?? ''));
    if (mb_strlen($search,'UTF-8')>150) throw new DomainException('Enter a search of up to 150 characters.');
    $status=$input['roster_status'] ?? '';
    if (!in_array($status,['','PRESENT','ABSENT','EXEMPTED'],true)) throw new DomainException('Choose an attendance status.');
    $page=max(1,(int)($input['roster_page'] ?? 1));
    $pageSize=25;
    $exemptions=synk_management_exemptions_ready($conn);
    $exempt=$exemptions ? 'x.exemption_id IS NOT NULL' : 'FALSE';
    $exemptJoin=$exemptions ? "LEFT JOIN tbl_management_exemptions x ON x.event_id=r.event_id AND x.student_id=r.student_id AND x.status='ACTIVE'" : '';
    $twoCheckpoints=synk_management_attendance_import_template($event)==='EVENT';
    $from=" FROM (SELECT r.event_id,r.student_id,s.student_number,CONCAT_WS(', ',s.last_name,
            CONCAT_WS(' ',s.first_name,NULLIF(s.middle_name,''),NULLIF(s.suffix_name,''))) AS student_name,
            CASE WHEN l.student_id IS NOT NULL THEN 'PRESENT' WHEN {$exempt} THEN 'EXEMPTED' ELSE 'ABSENT' END AS attendance_status,
            c.checked_in,c.checked_out,l.student_id IS NOT NULL AS is_present
        FROM tbl_management_attendance_roster r JOIN tbl_student_management s ON s.student_id=r.student_id
        LEFT JOIN tbl_management_attendance_logs l ON l.event_id=r.event_id AND l.student_id=r.student_id
        LEFT JOIN tbl_management_attendance_checkpoints c ON c.event_id=r.event_id AND c.student_id=r.student_id
        {$exemptJoin} WHERE r.event_id=?) a WHERE 1=1";
    $types='i'; $params=[$eventId];
    foreach (preg_split('/[\s,]+/u',$search,-1,PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
        $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$word).'%';
        $from.=" AND (a.student_number LIKE ? ESCAPE '!' OR a.student_name LIKE ? ESCAPE '!')";
        $types.='ss'; array_push($params,$pattern,$pattern);
    }
    if ($status!=='') {
        $from.=' AND a.attendance_status=?'; $types.='s'; $params[]=$status;
    }
    $total=(int)synk_management_query($conn,'SELECT COUNT(*) AS total'.$from,$types,$params)[0]['total'];
    $pages=max(1,(int)ceil($total/$pageSize));
    $page=min($page,$pages);
    $rows=synk_management_query($conn,'SELECT a.*,(SELECT reason FROM tbl_management_attendance_corrections d
        WHERE d.event_id=a.event_id AND d.student_id=a.student_id ORDER BY d.correction_id DESC LIMIT 1) AS correction_reason'
        .$from.' ORDER BY student_name,student_id LIMIT ? OFFSET ?',$types.'ii',array_merge($params,[$pageSize,($page-1)*$pageSize]));
    foreach ($rows as &$row) {
        $row['student_id']=(int)$row['student_id'];
        foreach (['checked_in','checked_out'] as $key) {
            // Earlier imports kept confirmed pairs, with no evidence for missing or partial rows.
            $row[$key]=$row[$key]!==null ? (bool)$row[$key]
                : ($twoCheckpoints && $row['is_present'] && $row['correction_reason']===null ? true : null);
        }
        unset($row['is_present'],$row['event_id']);
    }
    unset($row);
    return ['rows'=>$rows,'page'=>$page,'pages'=>$pages,'total'=>$total,'revision'=>(string)$state['revision']];
}

function synk_management_correct_closed_attendance(mysqli $conn,array $scope,array $event,?array $settings,array $input): bool
{
    if (!synk_management_attendance_review_ready($conn)) throw new RuntimeException('Attendance review storage has not been prepared.');
    if (($settings['attendance_status'] ?? null)!=='CLOSED') throw new SynkManagementConflict('Attendance must be closed before you can correct the roster.');
    if (($input['attendance_revision'] ?? null)!==(string)$settings['revision']) throw new SynkManagementConflict('Attendance changed. Reload the roster before saving the correction.');
    $eventId=(int)$event['event_id'];
    $studentId=synk_management_input_id($input['student_id'] ?? null);
    $reason=synk_management_input_text(['comment'=>$input['reason'] ?? ''],'comment',500,true,false);
    $row=synk_management_query($conn,'SELECT l.student_id IS NOT NULL AS is_present,c.checked_in,c.checked_out,
        EXISTS (SELECT 1 FROM tbl_management_attendance_corrections d WHERE d.event_id=r.event_id AND d.student_id=r.student_id) AS has_correction
        FROM tbl_management_attendance_roster r
        LEFT JOIN tbl_management_attendance_logs l ON l.event_id=r.event_id AND l.student_id=r.student_id
        LEFT JOIN tbl_management_attendance_checkpoints c ON c.event_id=r.event_id AND c.student_id=r.student_id
        WHERE r.event_id=? AND r.student_id=? FOR UPDATE','ii',[$eventId,$studentId])[0] ?? null;
    if (!$row) throw new OutOfBoundsException('This student is outside the event roster.');
    $twoCheckpoints=synk_management_attendance_import_template($event)==='EVENT';
    $wasPresent=(int)$row['is_present'];
    $inferred=$twoCheckpoints && $wasPresent && !$row['has_correction'] ? 1 : null;
    $previousIn=$row['checked_in']!==null ? (int)$row['checked_in'] : $inferred;
    $previousOut=$row['checked_out']!==null ? (int)$row['checked_out'] : $inferred;
    $nextIn=$previousIn;
    $nextOut=$previousOut;
    $status=$input['corrected_status'] ?? null;
    if ($twoCheckpoints && ($status===null || isset($input['checked_in']) || isset($input['checked_out']))) {
        $in=$input['checked_in'] ?? null;
        $out=$input['checked_out'] ?? null;
        if (!in_array($in,['0','1'],true) || !in_array($out,['0','1'],true)) throw new DomainException('Choose the verified IN and OUT entries.');
        $nextIn=(int)$in; $nextOut=(int)$out;
        $status=$status ?? ($nextIn && $nextOut ? 'PRESENT' : 'ABSENT');
    }
    if (!in_array($status,['PRESENT','ABSENT'],true)) throw new DomainException('Choose Present or Absent.');
    $present=(int)($status==='PRESENT');
    if ($wasPresent===$present && $previousIn===$nextIn && $previousOut===$nextOut) return false;
    if ($wasPresent!==$present && synk_management_payments_ready($conn) && synk_management_query($conn,
        'SELECT i.payment_item_id FROM tbl_management_payment_items i JOIN tbl_management_payments p ON p.payment_id=i.payment_id
         WHERE i.event_id=? AND p.student_id=? LIMIT 1','ii',[$eventId,$studentId])) {
        throw new SynkManagementConflict('This student has an absence-fine payment for this event. Review the payment before changing attendance.');
    }
    $actor=(int)$_SESSION['user_id'];
    if ($twoCheckpoints && $nextIn!==null && $nextOut!==null) {
        synk_management_store_event_checkpoints($conn,$eventId,[$studentId=>[$nextIn,$nextOut]]);
    }
    if (!$wasPresent && $present) synk_management_execute($conn,'INSERT INTO tbl_management_attendance_logs (event_id,student_id,recorded_by) VALUES (?,?,?)','iii',[$eventId,$studentId,$actor]);
    if ($wasPresent && !$present) synk_management_execute($conn,'DELETE FROM tbl_management_attendance_logs WHERE event_id=? AND student_id=?','ii',[$eventId,$studentId]);
    synk_management_execute($conn,'INSERT INTO tbl_management_attendance_corrections
        (event_id,student_id,previous_present,corrected_present,previous_in,previous_out,corrected_in,corrected_out,reason,recorded_by)
        VALUES (?,?,?,?,?,?,?,?,?,?)','iiiiiiiisi',[$eventId,$studentId,$wasPresent,$present,$previousIn,$previousOut,$nextIn,$nextOut,$reason,$actor]);
    synk_management_execute($conn,'UPDATE tbl_management_attendance_events SET revision=revision+1 WHERE event_id=?','i',[$eventId]);
    synk_management_audit_officer_operation($conn,$scope,'ATTENDANCE_CORRECT',['event_id'=>$eventId,'student_id'=>$studentId,'present'=>$present]);
    return true;
}
