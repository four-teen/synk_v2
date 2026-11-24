<?php
require_once __DIR__.'/management_attendance_helper.php';

function synk_management_past_attendance_period(array $current, string $eventDate, bool $twoCheckpoints=false): array
{
    $today=date('Y-m-d');
    if ($twoCheckpoints ? $eventDate>$today : $eventDate>=$today) throw new DomainException($twoCheckpoints?'Choose today or an earlier attendance date.':'Choose a date before today.');
    return synk_management_attendance_date_period($current,$eventDate);
}

function synk_management_past_attendance_encode(array $data): string
{
    $json=json_encode($data);
    if ($json===false) throw new DomainException('Use valid text in the workbook name, worksheet and heading.');
    return $json;
}

function synk_management_past_attendance_plan(mysqli $conn, array $scope, array $current, array $input, array $workbook, bool $lock=false): array
{
    $workbookSource=$workbook['source'];
    $twoCheckpoints=$workbookSource['template']==='EVENT';
    $title=synk_management_input_text($input,'title',255);
    $eventDate=synk_management_input_date($input['event_date'] ?? null);
    if (!$twoCheckpoints && $eventDate!==$workbook['event_date']) {
        throw new DomainException('The attendance date must match the workbook date ('.$workbook['event_date'].').');
    }
    $period=synk_management_past_attendance_period($current,$eventDate,$twoCheckpoints);
    if (($input['import_ay_id'] ?? null)!==(string)$period['ay_id'] || ($input['import_semester'] ?? null)!==(string)$period['semester']) {
        throw new SynkManagementConflict('The academic year or semester changed. Reload the page before importing.');
    }
    if (synk_management_exam_term($input['import_exam_term'] ?? null)!==$period['exam_term']) {
        throw new DomainException('Choose '.$period['exam_term_label'].' for this attendance date.');
    }
    $fine=$twoCheckpoints ? synk_management_input_money($input['absence_fine'] ?? null) : '0.00';
    $description=$twoCheckpoints ? synk_management_input_text($input,'description',10000,false) : '';
    if ($description==='') $description=null;
    $entries=$workbook['entries'];

    $numbers=[];
    foreach ($entries as $entry) {
        $key='id:'.$entry['student_number'];
        if (!isset($numbers[$key])) $numbers[$key]=['student_number'=>$entry['student_number'],'cells'=>[],'rows'=>0,'complete'=>false];
        $numbers[$key]['cells']=array_merge($numbers[$key]['cells'],$entry['cells'] ?? [$entry['cell']]);
        $numbers[$key]['rows']++;
        $numbers[$key]['complete']=$numbers[$key]['complete'] || ($entry['complete'] ?? true);
    }

    $lockSql=$lock ? ' FOR UPDATE' : '';
    $category=($twoCheckpoints?'NOT ':'').synk_management_flag_raising_sql('source_metadata');
    $events=synk_management_query($conn,'SELECT * FROM tbl_management_events WHERE college_id=? AND organization_id=?
        AND ay_id=? AND semester=? AND event_date=? AND '.$category.'
        AND (title=? OR JSON_UNQUOTE(JSON_EXTRACT(source_metadata,\'$.attendance_import.sha256\'))=?)
        ORDER BY event_id LIMIT 2'.$lockSql,'iiiisss',[$scope['college_id'],$scope['organization_id'],$period['ay_id'],$period['semester'],$eventDate,$title,$workbookSource['sha256']]);
    if (count($events)>1) throw new SynkManagementConflict('Several events have this title and date. Give the event a unique title before recording attendance.');
    $event=$events[0] ?? null;
    if ($event && ((!synk_management_catalog_in_period($event,$period) && !($twoCheckpoints && $event['exam_term']===null))
        || ($event['status']!=='ACTIVE' && !($twoCheckpoints && $event['status']==='DRAFT')))) {
        throw new SynkManagementConflict('An event with this title and date already exists in a different period or has an incompatible status. Review that event first.');
    }
    $settings=$event ? (synk_management_query($conn,'SELECT attendance_status,revision,absence_fine FROM tbl_management_attendance_events
        WHERE event_id=?'.$lockSql,'i',[(int)$event['event_id']])[0] ?? null) : null;
    $closed=($settings['attendance_status'] ?? '')==='CLOSED';
    if ($lock && $closed) {
        throw new SynkManagementConflict('Attendance for this event is closed.');
    }
    if ($twoCheckpoints && $event) {
        if ($settings && (!$closed || synk_management_attendance_import_template($event)!=='EVENT')) {
            throw new SynkManagementConflict('Attendance already exists for this event. Choose an event whose attendance has not started.');
        }
        require_once __DIR__.'/management_payment_read_helper.php';
        if (!$settings && synk_management_charge_has_payments($conn,'events',(int)$event['event_id'])) {
            throw new SynkManagementConflict('This event has recorded payments and its absence fine must be preserved.');
        }
    }
    if ($settings) {
        $roster=synk_management_query($conn,'SELECT s.student_id,s.student_number,l.student_id IS NOT NULL AS is_present
            FROM tbl_management_attendance_roster r JOIN tbl_student_management s ON s.student_id=r.student_id
            LEFT JOIN tbl_management_attendance_logs l ON l.event_id=r.event_id AND l.student_id=r.student_id
            WHERE r.event_id=? ORDER BY s.student_id','i',[(int)$event['event_id']]);
    } else {
        $source=synk_dean_students_source($scope,$period,$conn);
        $roster=synk_dean_students_query($conn,'SELECT DISTINCT s.student_id,s.student_number,0 AS is_present '.$source['sql'].
            ' ORDER BY s.student_id',$source['types'],$source['params']);
    }

    $byNumber=[];
    foreach ($roster as $student) $byNumber['id:'.$student['student_number']][]=$student;
    $rosterIds=array_map('intval',array_column($roster,'student_id'));
    $checkpoints=$twoCheckpoints ? array_fill_keys($rosterIds,[0,0]) : [];
    $newIds=[];
    $unmatched=[];
    $ambiguous=[];
    $duplicates=[];
    $alreadyPresent=0;
    foreach ($numbers as $key=>$entry) {
        $item=['student_number'=>$entry['student_number'],'cells'=>$entry['cells']];
        if ($entry['rows']>1) $duplicates[]=$twoCheckpoints ? $item+['occurrences'=>$entry['rows']] : $item;
        $matched=$byNumber[$key] ?? [];
        if (!$matched) $unmatched[]=$item;
        elseif (count($matched)>1) $ambiguous[]=$item;
        else {
            $studentId=(int)$matched[0]['student_id'];
            if ($twoCheckpoints) {
                foreach ($entry['cells'] as $cell) $checkpoints[$studentId][$cell[0]==='D' ? 0 : 1]=1;
            }
            if ($entry['complete']) {
                if ($matched[0]['is_present']) $alreadyPresent++;
                else $newIds[]=$studentId;
            }
        }
    }
    $closure=[];
    if ($twoCheckpoints) {
        $presentIds=$closed ? array_column(array_filter($roster,static function ($row) { return $row['is_present']; }),'student_id') : $newIds;
        $exemptIds=$event && synk_management_exemptions_ready($conn) ? array_column(synk_management_query($conn,
            "SELECT student_id FROM tbl_management_exemptions WHERE event_id=? AND status='ACTIVE' ORDER BY student_id".$lockSql,'i',[(int)$event['event_id']]),'student_id') : [];
        $exempted=count(array_diff(array_intersect($exemptIds,array_column($roster,'student_id')),$presentIds));
        $closure=['present'=>count($presentIds),'exempted'=>$exempted,'absent'=>count($roster)-count($presentIds)-$exempted,
            'absence_fine'=>$closed ? $settings['absence_fine'] : $fine,'workbook_counts'=>$workbook['counts']];
    }
    $token=hash('sha256',synk_management_past_attendance_encode([
        $scope['organization_id'],$current['period_version'],$title,$eventDate,$workbookSource,$period,
        $event,$settings['revision'] ?? null,$entries,$roster,$fine,$description,$closure
    ]));
    return [
        'event'=>$event,
        'has_roster'=>$settings!==null,
        'roster_ids'=>$rosterIds,
        'new_ids'=>$newIds,
        'event_checkpoints'=>$checkpoints,
        'period'=>$period,
        'description'=>$description,
        'preview'=>[
            'token'=>$token,
            'title'=>$event['title'] ?? $title,
            'event_date'=>$eventDate,
            'period'=>$period['period_text'],
            'term'=>$period['key'],
            'exam_term'=>$period['exam_term'],
            'worksheet'=>$workbookSource['worksheet'],
            'event_id'=>$event ? (int)$event['event_id'] : null,
            'existing_event'=>$event!==null,
            'attendance_status'=>$settings['attendance_status'] ?? 'NOT_STARTED',
            'can_import'=>!$closed && ($twoCheckpoints ? (bool)$roster : (bool)$newIds),
            'roster_count'=>count($roster),
            'distinct_count'=>count($numbers),
            'duplicates'=>count($entries)-count($numbers),
            'new_present'=>count($newIds),
            'already_present'=>$alreadyPresent,
            'unmatched'=>array_column($unmatched,'student_number'),
            'ambiguous'=>array_column($ambiguous,'student_number'),
            'review'=>['unmatched'=>$unmatched,'ambiguous'=>$ambiguous,'duplicates'=>$duplicates]+($twoCheckpoints?['problems'=>$workbook['problems']]:[]),
        ]+$closure,
    ];
}

function synk_management_require_attendance_import(mysqli $conn, array $scope): void
{
    $permissions=synk_management_permissions($conn,$scope);
    if (!$permissions['manage_events'] || !$permissions['attendance']) {
        throw new SynkManagementPermissionDenied('Importing attendance requires Manage events and Scan attendance permissions.');
    }
}

function synk_management_record_past_attendance(mysqli $conn, array $scope, string $action, array $input, array $workbook): array
{
    if (!synk_management_attendance_ready($conn)) throw new RuntimeException('Attendance storage has not been prepared.');
    $save=$action==='attendance_past_save';
    if ($save) $conn->begin_transaction();
    try {
        if ($save) synk_management_lock_assignment($conn,$scope);
        synk_management_require_attendance_import($conn,$scope);
        $current=$save ? synk_management_lock_current_term($conn,$input,$scope) : synk_management_fetch_current_period($conn,$scope);
        $plan=synk_management_past_attendance_plan($conn,$scope,$current,$input,$workbook,$save);
        $preview=$plan['preview'];
        if (!$save) return ['preview'=>$preview];

        $twoCheckpoints=$workbook['source']['template']==='EVENT';
        $token=$input['preview_token'] ?? null;
        if (!is_string($token) || !hash_equals($preview['token'],$token)) {
            throw new SynkManagementConflict('Attendance changed during the import. Import again to check the current records.');
        }
        if (!$preview['can_import']) throw new DomainException($twoCheckpoints?'There are no eligible students for this event.':'There are no new students to mark present.');
        if ($twoCheckpoints && ($input['confirm'] ?? '')!=='1') throw new DomainException('Confirm the closed attendance and absence fine.');

        $event=$plan['event'];
        $period=$plan['period'];
        $actor=(int)$_SESSION['user_id'];
        $metadata=json_decode($event['source_metadata'] ?? 'null',true);
        $metadata=is_array($metadata) ? $metadata : [];
        $fine=$twoCheckpoints ? $preview['absence_fine'] : '0.00';
        if (!$event) {
            if (!$twoCheckpoints) $metadata['attendance_category']='FRC';
            $metadata['attendance_import']=$workbook['source'];
            synk_management_execute($conn,"INSERT INTO tbl_management_events
                (college_id,organization_id,ay_id,semester,exam_term,title,source_metadata,event_date,description,audience_mode,status,absence_fine)
                VALUES (?,?,?,?,?,?,?,?,?,'ALL','ACTIVE',?)",'iiiissssss',[$scope['college_id'],$scope['organization_id'],
                $period['ay_id'],$period['semester'],$period['exam_term'],$preview['title'],synk_management_past_attendance_encode($metadata),$preview['event_date'],$plan['description'],$fine]);
            $event=$period+['event_id'=>(int)$conn->insert_id,'title'=>$preview['title'],'absence_fine'=>$fine,'source_metadata'=>synk_management_past_attendance_encode($metadata)];
        } elseif ($twoCheckpoints) {
            $metadata['attendance_import']=$workbook['source'];
            synk_management_execute($conn,"UPDATE tbl_management_events SET source_metadata=?,exam_term=?,absence_fine=?,description=COALESCE(?,description),status='ACTIVE' WHERE event_id=?",'ssssi',
                [synk_management_past_attendance_encode($metadata),$period['exam_term'],$fine,$plan['description'],(int)$event['event_id']]);
            $event['absence_fine']=$fine;
            $event['exam_term']=$period['exam_term'];
        } elseif (!isset($metadata['attendance_import'])) {
            $metadata['attendance_import']=$workbook['source'];
            synk_management_execute($conn,'UPDATE tbl_management_events SET source_metadata=? WHERE event_id=?','si',
                [synk_management_past_attendance_encode($metadata),(int)$event['event_id']]);
        }
        $eventId=(int)$event['event_id'];
        if (!$plan['has_roster']) {
            synk_management_attendance_open_roster($conn,$scope,$event);
            $frozen=synk_management_query($conn,'SELECT student_id FROM tbl_management_attendance_roster WHERE event_id=? ORDER BY student_id','i',[$eventId]);
            if (array_map('intval',array_column($frozen,'student_id'))!==$plan['roster_ids']) {
                throw new SynkManagementConflict('The student roster changed. Preview the list again before saving.');
            }
        }
        foreach (array_chunk($plan['new_ids'],250) as $chunk) {
            $values=[];
            foreach ($chunk as $studentId) array_push($values,$eventId,$studentId,$actor);
            $placeholders=implode(',',array_fill(0,count($chunk),'(?,?,?)'));
            synk_management_execute($conn,'INSERT INTO tbl_management_attendance_logs (event_id,student_id,recorded_by) VALUES '.$placeholders,
                str_repeat('iii',count($chunk)),$values);
        }
        if ($plan['has_roster']) {
            synk_management_execute($conn,'UPDATE tbl_management_attendance_events SET revision=revision+1 WHERE event_id=?','i',[$eventId]);
        } else {
            synk_management_execute($conn,"INSERT INTO tbl_management_attendance_audit (event_id,action,recorded_by,roster_count,present_count)
                VALUES (?,'OPEN',?,?,?)",'iiii',[$eventId,$actor,$preview['roster_count'],$preview['new_present']]);
        }
        if ($twoCheckpoints) {
            require_once __DIR__.'/management_attendance_review_helper.php';
            if (!synk_management_attendance_review_ready($conn)) throw new RuntimeException('Attendance review storage has not been prepared.');
            synk_management_store_event_checkpoints($conn,$eventId,$plan['event_checkpoints']);
            synk_management_attendance_set_state($conn,$eventId,'CLOSED');
            synk_management_execute($conn,"INSERT INTO tbl_management_attendance_audit (event_id,action,recorded_by,roster_count,present_count)
                VALUES (?,'CLOSE',?,?,?)",'iiii',[$eventId,$actor,$preview['roster_count'],$preview['present']]);
        }
        synk_management_audit_officer_operation($conn,$scope,'ATTENDANCE_PAST_RECORD',[
            'event_id'=>$eventId,'title'=>$event['title'],'present'=>$preview['new_present']
        ]);
        $conn->commit();
        return [
            'recorded'=>$preview['new_present'],'title'=>$event['title'],'event_id'=>$eventId,
            'term'=>$period['key'],'exam_term'=>$period['exam_term'],
            'review'=>$preview['review'],
        ]+($twoCheckpoints?['attendance_status'=>'CLOSED','present'=>$preview['present'],'absent'=>$preview['absent'],
            'exempted'=>$preview['exempted'],'absence_fine'=>$fine]:[]);
    } catch (Throwable $error) {
        if ($save) $conn->rollback();
        throw $error;
    }
}
