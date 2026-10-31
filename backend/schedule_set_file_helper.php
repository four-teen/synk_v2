<?php

// File sets are independent of the legacy saved-set tables. The scheduling and
// workload editors remain the authority for day/time, room and assignment rules.
require_once __DIR__ . '/workload_student_guard_helper.php';

function synk_set_file_columns(): array
{
    return ['record_type','format','set_name','exported_at','college_id','college','ay_id','academic_year','semester',
        'program_id','offering_id','ps_id','section_id','subject','section','schedule_id','schedule_type','schedule_group_id',
        'schedule_status','days','time_start','time_end','room_id','room','faculty_ids','faculty','faculty_need_ids','faculty_needs',
        'merge_owner_id','merge_member_id','merge_scope','meeting_count','workload_count','content_sha256'];
}

function synk_set_file_query(mysqli $conn, string $sql, array $params = []): array
{
    return synk_scheduler_list_query($conn, $sql, str_repeat('i', count($params)), $params);
}

function synk_set_file_state(mysqli $conn, int $collegeId, int $ayId, int $semester): array
{
    $scope = [$collegeId,$ayId,$semester];
    $offerings = synk_set_file_query($conn, 'SELECT o.*,p.college_id FROM tbl_prospectus_offering o
        JOIN tbl_program p ON p.program_id=o.program_id WHERE p.college_id=? AND o.ay_id=? AND o.semester=? ORDER BY o.offering_id', $scope);
    $rows = synk_set_file_query($conn, 'SELECT cs.* FROM tbl_class_schedule cs JOIN tbl_prospectus_offering o ON o.offering_id=cs.offering_id
        JOIN tbl_program p ON p.program_id=o.program_id WHERE p.college_id=? AND o.ay_id=? AND o.semester=? ORDER BY cs.schedule_id', $scope);
    $assignments = [];
    foreach (['faculty'=>'tbl_faculty_workload_sched','faculty_need'=>'tbl_faculty_need_workload_sched'] as $kind=>$table) {
        $assignments[$kind] = synk_table_exists($conn,$table) ? synk_set_file_query($conn, "SELECT w.* FROM {$table} w
            JOIN tbl_class_schedule cs ON cs.schedule_id=w.schedule_id JOIN tbl_prospectus_offering o ON o.offering_id=cs.offering_id
            JOIN tbl_program p ON p.program_id=o.program_id WHERE p.college_id=? AND o.ay_id=? AND o.semester=? ORDER BY w.schedule_id,1", $scope) : [];
        foreach ($assignments[$kind] as $row) {
            if ((int)$row['ay_id']!==$ayId || (int)$row['semester']!==$semester) {
                throw new DomainException('A workload assignment has a different term from its class. Review it before exporting or importing.');
            }
        }
    }
    $merges = [];
    if ($offerings && synk_table_exists($conn,'tbl_class_schedule_merge')) {
        $ids = implode(',',array_map('intval',array_column($offerings,'offering_id')));
        $merges = $conn->query("SELECT owner_offering_id,member_offering_id,merge_scope FROM tbl_class_schedule_merge
            WHERE owner_offering_id IN ({$ids}) OR member_offering_id IN ({$ids}) ORDER BY member_offering_id,merge_scope,owner_offering_id")->fetch_all(MYSQLI_ASSOC);
        $scopeIds = array_flip(array_column($offerings,'offering_id'));
        foreach ($merges as $row) {
            if (!isset($scopeIds[$row['owner_offering_id']],$scopeIds[$row['member_offering_id']])) {
                throw new DomainException('A merge crosses this college term. Review the merge before exporting or importing.');
            }
        }
    }
    return ['offerings'=>$offerings,'meetings'=>$rows,'assignments'=>$assignments,'merges'=>$merges];
}

function synk_set_file_cell($value): string
{
    $text = (string)($value ?? '');
    // Prevent spreadsheet formula execution in user-controlled display labels.
    return preg_match('/^[\s]*[=+@\-]/u',$text) ? "'".$text : $text;
}

function synk_set_file_checksum(array $rows): string
{
    $json=json_encode(array_values($rows),JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if($json===false) throw new DomainException('The set contains text that cannot be encoded as UTF-8.');
    return hash('sha256',$json);
}

function synk_set_file_csv(array $meta, array $records): string
{
    $columns = synk_set_file_columns();
    $values = [];
    foreach ($records as $record) {
        $values[] = array_map(static function(string $key) use($record):string { return synk_set_file_cell($record[$key] ?? ''); },$columns);
    }
    $meta['record_type']='metadata'; $meta['format']='SYNK_COLLEGE_SET_V1';
    $meta['content_sha256']=synk_set_file_checksum($values);
    $stream=fopen('php://temp','w+');
    fwrite($stream,"\xEF\xBB\xBF");
    // Use a reserved control byte as the escape character for PHP 7.2 support.
    // CSV still uses standard doubled quotes; literal backslashes remain literal.
    fputcsv($stream,$columns,',','"',chr(29));
    fputcsv($stream,array_map(static function(string $key) use($meta):string { return synk_set_file_cell($meta[$key] ?? ''); },$columns),',','"',chr(29));
    foreach($values as $row) fputcsv($stream,$row,',','"',chr(29));
    rewind($stream); $csv=stream_get_contents($stream); fclose($stream);
    return $csv;
}

function synk_set_file_parse(string $csv): array
{
    if ($csv==='' || strlen($csv)>8*1024*1024 || strpos($csv,"\0")!==false || strpos($csv,chr(29))!==false || !preg_match('//u',$csv)) {
        throw new DomainException('Choose an original Synk CSV set in UTF-8 format, up to 8 MB.');
    }
    $stream=fopen('php://temp','w+'); fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$csv)); rewind($stream);
    try {
        $columns=synk_set_file_columns();
        if (fgetcsv($stream,0,',','"',chr(29))!==$columns) throw new DomainException('This is not a Synk schedule-and-workload set. Choose the original exported CSV.');
        $rows=[];
        while (($row=fgetcsv($stream,0,',','"',chr(29)))!==false) {
            if ($row===[null]) continue;
            if (count($row)!==count($columns) || count($rows)>20000) throw new DomainException('The set is incomplete or has invalid columns.');
            foreach($row as $cell) if(strlen((string)$cell)>4096) throw new DomainException('The set contains an invalid value.');
            $rows[]=$row;
        }
        if (count($rows)<2) throw new DomainException('This set has no schedule meetings.');
        $meta=array_combine($columns,array_shift($rows));
        if ($meta['record_type']!=='metadata' || $meta['format']!=='SYNK_COLLEGE_SET_V1'
            || !hash_equals($meta['content_sha256'],synk_set_file_checksum($rows))) {
            throw new DomainException('This set is incomplete or was edited. Choose the original exported CSV.');
        }
        $records=array_map(static function(array $row) use($columns):array {return array_combine($columns,$row);},$rows);
        return ['meta'=>$meta,'records'=>$records];
    } finally { fclose($stream); }
}

function synk_set_file_ids(string $text): array
{
    if ($text==='') return [];
    $ids=[];
    foreach(explode('|',$text) as $value) {
        if (!preg_match('/^[1-9][0-9]{0,9}$/D',$value) || (int)$value>4294967295 || isset($ids[(int)$value])) {
            throw new DomainException('The set contains an invalid or duplicate assignment identifier.');
        }
        $ids[(int)$value]=(int)$value;
    }
    sort($ids,SORT_NUMERIC); return array_values($ids);
}

function synk_set_file_assignment_map(array $state): array
{
    $map=[];
    foreach($state['assignments'] as $kind=>$rows) foreach($rows as $row) {
        $map[(int)$row['schedule_id']][$kind][]=(int)$row[$kind==='faculty'?'faculty_id':'faculty_need_id'];
    }
    foreach($map as &$kinds) foreach($kinds as &$ids) {sort($ids,SORT_NUMERIC); $ids=array_values(array_unique($ids));}
    unset($kinds,$ids); return $map;
}

function synk_set_file_export(mysqli $conn, int $collegeId, int $ayId, int $semester, string $name): array
{
    $name=trim(preg_replace('/\s+/u',' ',$name));
    if ($name==='' || strlen($name)>120 || preg_match('/[\x00-\x1F]/',$name)) throw new DomainException('Enter a set name of up to 120 characters.');
    $state=synk_set_file_state($conn,$collegeId,$ayId,$semester);
    $snapshot=load_live_schedule_snapshot_rows_for_college_term($conn,$ayId,$semester,$collegeId);
    if (!$snapshot) throw new DomainException('There are no schedule meetings in this college term to export.');
    if(count($snapshot)!==count($state['meetings'])) throw new DomainException('Some class offerings are out of sync. Review them before exporting this college term.');
    $scope=synk_set_file_query($conn,'SELECT college_code,college_name FROM tbl_college WHERE college_id=?',[$collegeId])[0];
    $year=synk_set_file_query($conn,'SELECT ay FROM tbl_academic_years WHERE ay_id=?',[$ayId])[0]['ay'];
    $live=array_column($state['meetings'],null,'schedule_id'); $assigned=synk_set_file_assignment_map($state);
    $facultyIds=[]; $needIds=[];
    foreach($assigned as $kinds) {$facultyIds=array_merge($facultyIds,$kinds['faculty'] ?? []);$needIds=array_merge($needIds,$kinds['faculty_need'] ?? []);}
    $names=load_faculty_names_by_ids($conn,$facultyIds); $needs=[];
    if($needIds) foreach($conn->query('SELECT faculty_need_id,need_label FROM tbl_faculty_need WHERE faculty_need_id IN ('.implode(',',array_unique($needIds)).')')->fetch_all(MYSQLI_ASSOC) as $need) $needs[(int)$need['faculty_need_id']]=$need['need_label'];
    if(count(array_unique($facultyIds))!==count($names) || count(array_unique($needIds))!==count($needs)) throw new DomainException('A workload references a missing faculty or faculty need. Review it before exporting.');
    $records=[]; $programs=[]; $offeringIds=[]; $workloadCount=0;
    foreach($snapshot as $row) {
        $id=(int)$row['schedule_id']; $faculty=$assigned[$id]['faculty'] ?? []; $need=$assigned[$id]['faculty_need'] ?? [];
        $workloadCount+=count($faculty)+count($need); $programs[(int)$row['program_id']]=true; $offeringIds[(int)$row['offering_id']]=true;
        $records[]=['record_type'=>'meeting','program_id'=>$row['program_id'],'offering_id'=>$row['offering_id'],'ps_id'=>$row['ps_id'],
            'section_id'=>$row['section_id'],'subject'=>$row['sub_code'],'section'=>$row['section_name'],'schedule_id'=>$id,
            'schedule_type'=>$row['schedule_type'],'schedule_group_id'=>$live[$id]['schedule_group_id'],'schedule_status'=>$row['schedule_status'],
            'days'=>implode('|',synk_normalize_schedule_days(json_decode($row['days_json'],true))), 'time_start'=>$row['time_start'],'time_end'=>$row['time_end'],
            'room_id'=>$row['room_id'],'room'=>$row['room_label'],'faculty_ids'=>implode('|',$faculty),
            'faculty'=>implode(' | ',array_map(static function(int $id) use($names):string {return $names[$id];},$faculty)),
            'faculty_need_ids'=>implode('|',$need),'faculty_needs'=>implode(' | ',array_map(static function(int $id) use($needs):string {return $needs[$id];},$need))];
    }
    foreach($state['merges'] as $merge) $records[]=['record_type'=>'merge','merge_owner_id'=>$merge['owner_offering_id'],'merge_member_id'=>$merge['member_offering_id'],'merge_scope'=>$merge['merge_scope']];
    $meta=['set_name'=>$name,'exported_at'=>date('c'),'college_id'=>$collegeId,'college'=>trim($scope['college_code'].' - '.$scope['college_name']),
        'ay_id'=>$ayId,'academic_year'=>$year,'semester'=>$semester,'meeting_count'=>count($snapshot),'workload_count'=>$workloadCount];
    $csv=synk_set_file_csv($meta,$records);
    if(count($records)>20000 || strlen($csv)>8*1024*1024) {
        throw new DomainException('This college set exceeds the supported file size. No backup was downloaded. Contact the administrator before resetting schedules.');
    }
    $filename=preg_replace('/[^\pL\pN _.-]+/u','-',$name);
    $filename=trim($filename," .-") ?: 'Schedule set';
    return ['csv'=>$csv,'filename'=>$filename.'.csv','set_name'=>$name,'meeting_count'=>count($snapshot),
        'offering_count'=>count($offeringIds),'program_count'=>count($programs),'workload_count'=>$workloadCount,'college'=>$meta['college'],'term'=>$year.' / '.synk_semester_label($semester)];
}

function synk_set_file_plan(mysqli $conn, array $file, int $collegeId, int $ayId, int $semester, array $policy): array
{
    $meta=$file['meta'];
    if ((string)$collegeId!==$meta['college_id'] || (string)$ayId!==$meta['ay_id'] || (string)$semester!==$meta['semester']) {
        throw new DomainException('This file belongs to another college or academic term. Switch to its original college, academic year and semester.');
    }
    if(!synk_schedule_tba_schema_ready($conn)) throw new DomainException('Schedule history storage is not available.');
    $state=synk_set_file_state($conn,$collegeId,$ayId,$semester);
    $live=array_column($state['meetings'],null,'schedule_id'); $offerings=array_column($state['offerings'],null,'offering_id');
    $current=synk_set_file_assignment_map($state); $meetings=[]; $merges=[]; $assignments=[]; $contexts=[]; $byOffering=[];
    $workloadCount=0; $programs=[]; $changedAssignments=[]; $changedMeetings=0;
    foreach($file['records'] as $row) {
        if($row['record_type']==='merge') {
            $owner=synk_set_file_ids($row['merge_owner_id']); $member=synk_set_file_ids($row['merge_member_id']);
            if(count($owner)!==1 || count($member)!==1 || !in_array($row['merge_scope'],['FULL','LEC','LAB'],true)) throw new DomainException('Invalid merge information in this set.');
            $merges[]=['owner_offering_id'=>(string)$owner[0],'member_offering_id'=>(string)$member[0],'merge_scope'=>$row['merge_scope']]; continue;
        }
        if($row['record_type']!=='meeting') throw new DomainException('Unknown record in this set.');
        $ids=synk_set_file_ids($row['schedule_id']); $id=count($ids)===1?$ids[0]:0;
        if(!$id || !isset($live[$id]) || isset($meetings[$id])) throw new DomainException('A meeting is missing, duplicated, or was recreated since export. Use a set matching the existing meetings; use TBA when preparing alternatives.');
        $old=$live[$id]; $offeringId=(int)$old['offering_id']; $offering=$offerings[$offeringId];
        foreach(['offering_id','program_id','ps_id','section_id'] as $key) {
            if((string)$row[$key] !== (string)($key==='offering_id'?$offeringId:$offering[$key])) throw new DomainException('A class in this set no longer matches its original offering.');
        }
        if($row['schedule_type']!==$old['schedule_type'] || (string)$row['schedule_group_id']!==(string)($old['schedule_group_id'] ?? '')) throw new DomainException('Meeting types or groups changed since export. This set cannot replace that structure.');
        if(!isset($contexts[$offeringId])) {
            $contexts[$offeringId]=load_context_live($conn,$offeringId,$collegeId);
            if(!$contexts[$offeringId]) throw new DomainException('A class offering is out of sync. Review it before importing.');
        }
        $ctx=$contexts[$offeringId]; $label=$ctx['sub_code'].' / '.$ctx['section_name'].' / '.$row['schedule_type'];
        if(!in_array($row['schedule_type'],required_schedule_types_for_context($ctx),true)) throw new DomainException($label.' no longer has this meeting type.');
        $status=$row['schedule_status']; $days=$row['days']===''?[]:explode('|',$row['days']);
        if(!in_array($status,['scheduled','room_tba','tba'],true) || $days!==synk_normalize_schedule_days($days)) throw new DomainException($label.' has invalid days or status.');
        $roomIds=synk_set_file_ids($row['room_id']); $room=$roomIds[0] ?? null;
        if(count($roomIds)>1) throw new DomainException($label.' has an invalid room.');
        $start=$row['time_start']===''?null:$row['time_start']; $end=$row['time_end']===''?null:$row['time_end'];
        if($status==='tba') {
            if($days || $room || $start || $end) throw new DomainException($label.' has invalid TBA values.');
        } else {
            if(!$days || !$start || !$end || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D',$start)
                || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D',$end) || $end<=$start) throw new DomainException($label.' has invalid days or times.');
            if(synk_schedule_policy_disallowed_days($days,$policy) || !synk_schedule_policy_is_within_window($start,$end,$policy)
                || synk_schedule_policy_blocked_time_overlap($start,$end,$policy)!==null) throw new DomainException($label.' does not meet the current scheduling policy.');
            if($status==='scheduled') {
                $validation=preview_room_validation_result($conn,(int)$room,$collegeId,$ayId,$semester,$row['schedule_type']);
                if($validation['message']!==null) throw new DomainException(strip_tags($validation['message']));
            } elseif($room) throw new DomainException($label.' has an invalid room-TBA value.');
        }
        $assignment=['faculty'=>synk_set_file_ids($row['faculty_ids']),'faculty_need'=>synk_set_file_ids($row['faculty_need_ids'])];
        $oldAssignment=['faculty'=>$current[$id]['faculty'] ?? [],'faculty_need'=>$current[$id]['faculty_need'] ?? []];
        // Existing workload assignment permits a meeting to be assigned once.
        // Historical co-teaching rows can round-trip unchanged, without expanding it.
        if(count($assignment['faculty_need'])>1 || ($assignment['faculty_need'] && $assignment['faculty'])
            || (count($assignment['faculty'])>1 && $assignment!==$oldAssignment)) throw new DomainException($label.' has incompatible workload assignments.');
        $next=array_merge($old,['schedule_status'=>$status,'days'=>$days,'days_json'=>json_encode($days),'room_id'=>$room,'time_start'=>$start,'time_end'=>$end,'label'=>$label,
            'section_id'=>(int)$offering['section_id'],'section_name'=>$ctx['section_name']]);
        $changed=synk_set_file_meeting_changed($old,$next);
        if(strtolower($offering['status'])==='locked' && ($changed || $assignment!==$oldAssignment)) throw new DomainException($label.' is locked and would be changed.');
        if($changed) $changedMeetings++;
        if($assignment!==$oldAssignment) $changedAssignments[$offeringId]=true;
        $workloadCount+=count($assignment['faculty'])+count($assignment['faculty_need']);
        $programs[(int)$offering['program_id']]=true; $meetings[$id]=$next; $assignments[$id]=$assignment; $byOffering[$offeringId][]=$next;
    }
    if(count($meetings)!==count($live) || count($meetings)!==(int)$meta['meeting_count'] || $workloadCount!==(int)$meta['workload_count']) {
        throw new DomainException('The college meeting list changed since export, or the file is incomplete. Restore only a set with the same existing meeting blocks.');
    }
    $sortMerges=static function(array &$rows):void {usort($rows,static function(array $a,array $b):int{return [$a['member_offering_id'],$a['merge_scope'],$a['owner_offering_id']]<=>[$b['member_offering_id'],$b['merge_scope'],$b['owner_offering_id']];});};
    $sortMerges($merges); $sortMerges($state['merges']);
    if($merges!==$state['merges']) throw new DomainException('Merge relationships changed since export. Restore the original merge structure before importing this set.');
    // Offering status and locked-class checks must include inherited meetings too.
    $memberMap=[];$ownerCache=[];
    foreach($merges as $merge) $memberMap[(int)$merge['member_offering_id']][$merge['merge_scope']]=(int)$merge['owner_offering_id'];
    $byOffering=[];
    foreach($offerings as $offeringId=>$offering) {
        $effective=[];
        foreach(['LEC','LAB'] as $type) {
            $owner=synk_schedule_merge_resolve_effective_owner_for_type((int)$offeringId,$type,$memberMap,$ownerCache);
            foreach($meetings as $id=>$meeting) {
                if((int)$meeting['offering_id']!==$owner || $meeting['schedule_type']!==$type) continue;
                $effective[]=$meeting;
                $oldAssignment=['faculty'=>$current[$id]['faculty'] ?? [],'faculty_need'=>$current[$id]['faculty_need'] ?? []];
                if($assignments[$id]!==$oldAssignment) $changedAssignments[$offeringId]=true;
                if(strtolower($offering['status'])==='locked'
                    && (synk_set_file_meeting_changed($live[$id],$meeting) || $assignments[$id]!==$oldAssignment)) {
                    throw new DomainException('A locked class inherits a meeting that would change. Unlock or review the class first.');
                }
            }
        }
        if(!$effective) continue;
        if(!isset($contexts[$offeringId])) $contexts[$offeringId]=load_context_live($conn,(int)$offeringId,$collegeId);
        if(!$contexts[$offeringId]) throw new DomainException('A merged class offering is out of sync. Review it before importing.');
        $byOffering[$offeringId]=$effective;
    }
    $facultyIds=[];$needIds=[];
    foreach($assignments as $kinds) {$facultyIds=array_merge($facultyIds,$kinds['faculty']);$needIds=array_merge($needIds,$kinds['faculty_need']);}
    foreach(array_unique($facultyIds) as $facultyId) {
        $sql="SELECT 1 FROM tbl_college_faculty cf JOIN tbl_faculty f ON f.faculty_id=cf.faculty_id WHERE cf.college_id=? AND cf.faculty_id=? AND cf.status='active' AND f.status='active'
            AND (cf.ay_id=? OR (cf.ay_id IS NULL AND ".synk_scheduler_legacy_term_flag($conn)."=1)) AND (cf.semester IS NULL OR cf.semester=?) LIMIT 1";
        if(!synk_set_file_query($conn,$sql,[$collegeId,$facultyId,$ayId,$semester])) throw new DomainException('A saved faculty assignment is no longer active in this college term.');
    }
    foreach(array_unique($needIds) as $needId) {
        if(!synk_set_file_query($conn,"SELECT 1 FROM tbl_faculty_need WHERE faculty_need_id=? AND college_id=? AND ay_id=? AND semester=? AND status='active'",[$needId,$collegeId,$ayId,$semester])) throw new DomainException('A saved faculty need is no longer active in this college term.');
    }
    foreach(array_keys($changedAssignments) as $offeringId) {
        $ctx=$contexts[$offeringId];
        $class=['offering_id'=>$offeringId,'program_id'=>$offerings[$offeringId]['program_id'],'section_id'=>$offerings[$offeringId]['section_id'],
            'subject_id'=>$ctx['sub_id'],'college_id'=>$collegeId,'campus_id'=>(int)($_SESSION['campus_id'] ?? 0),'ay_id'=>$ayId,'semester'=>$semester];
        $campus=synk_set_file_query($conn,'SELECT campus_id FROM tbl_college WHERE college_id=?',[$collegeId])[0]; $class['campus_id']=(int)$campus['campus_id'];
        if(synk_workload_students_in_class($conn,$class,true)>0) throw new DomainException($ctx['sub_code'].' / '.$ctx['section_name'].' has active students. Its workload must stay unchanged; review its student list in the Dean module first.');
    }
    synk_set_file_validate_conflicts($conn,$meetings,$assignments,$state,$collegeId,$ayId,$semester,$contexts);
    $summary=['set_name'=>$meta['set_name'],'college'=>$meta['college'],'term'=>$meta['academic_year'].' / '.synk_semester_label($semester),
        'meeting_count'=>count($meetings),'offering_count'=>count($byOffering),'program_count'=>count($programs),'workload_count'=>$workloadCount,
        'changed_meeting_count'=>$changedMeetings,'changed_class_workload_count'=>count($changedAssignments),'exported_at'=>$meta['exported_at']];
    return ['state'=>$state,'meetings'=>$meetings,'assignments'=>$assignments,'contexts'=>$contexts,'by_offering'=>$byOffering,'summary'=>$summary,
        'preview_token'=>synk_set_file_checksum([$collegeId,$ayId,$semester,$file,$state])];
}

function synk_set_file_meeting_changed(array $before, array $after): bool
{
    return synk_schedule_status($before)!==$after['schedule_status'] || (string)($before['room_id'] ?? '')!==(string)($after['room_id'] ?? '')
        || synk_normalize_schedule_days(json_decode($before['days_json'],true))!==$after['days']
        || (string)($before['time_start'] ?? '')!==(string)($after['time_start'] ?? '') || (string)($before['time_end'] ?? '')!==(string)($after['time_end'] ?? '');
}

function synk_set_file_validate_conflicts(mysqli $conn, array $meetings, array $assignments, array $state, int $collegeId, int $ayId, int $semester, array $contexts): void
{
    $offeringIds=array_map('intval',array_column($state['offerings'],'offering_id'));
    // Resolve inheritance using the existing merge rules, without altering links.
    $memberMap=[]; foreach($state['merges'] as $row) $memberMap[(int)$row['member_offering_id']][$row['merge_scope']]=(int)$row['owner_offering_id'];
    $cache=[]; $sectionsByMeeting=[];
    foreach($state['offerings'] as $offering) foreach(['LEC','LAB'] as $type) {
        $owner=synk_schedule_merge_resolve_effective_owner_for_type((int)$offering['offering_id'],$type,$memberMap,$cache);
        foreach($meetings as $id=>$meeting) if((int)$meeting['offering_id']===$owner && $meeting['schedule_type']===$type) $sectionsByMeeting[$id][(int)$offering['section_id']]=true;
    }
    $rows=array_values($meetings);
    foreach($rows as $i=>$row) {
        if($row['schedule_status']==='tba') continue;
        foreach(array_slice($rows,$i+1) as $other) {
            if($other['schedule_status']==='tba' || !days_overlap($row['days'],$other['days']) || !time_overlap($row['time_start'],$row['time_end'],$other['time_start'],$other['time_end'])) continue;
            $left=$assignments[(int)$row['schedule_id']];$right=$assignments[(int)$other['schedule_id']];
            if(($row['room_id'] && $row['room_id']===$other['room_id'])
                || array_intersect_key($sectionsByMeeting[$row['schedule_id']] ?? [],$sectionsByMeeting[$other['schedule_id']] ?? [])
                || array_intersect($left['faculty'],$right['faculty']) || array_intersect($left['faculty_need'],$right['faculty_need'])) {
                throw new DomainException($row['label'].' conflicts with '.$other['label'].' in the imported set.');
            }
        }
        foreach(array_keys($sectionsByMeeting[$row['schedule_id']] ?? []) as $sectionId) {
            $conflict=find_conflict_excluding_offerings($conn,$ayId,$semester,$offeringIds,$sectionId,$row['section_name'],(int)$row['room_id'],$row['days'],$row['time_start'],$row['time_end'],$row['label']);
            if($conflict!==null) throw new DomainException(strip_tags(str_replace(['<br>','<br/>'],' ',$conflict)));
        }
    }
    foreach(['faculty'=>'tbl_faculty_workload_sched','faculty_need'=>'tbl_faculty_need_workload_sched'] as $kind=>$table) {
        if(!synk_table_exists($conn,$table)) continue;
        $column=$kind==='faculty'?'faculty_id':'faculty_need_id';
        $external=synk_set_file_query($conn,"SELECT w.{$column} AS assignee_id,cs.* FROM {$table} w JOIN tbl_class_schedule cs ON cs.schedule_id=w.schedule_id
            JOIN tbl_prospectus_offering o ON o.offering_id=cs.offering_id JOIN tbl_program p ON p.program_id=o.program_id
            WHERE w.ay_id=? AND w.semester=? AND (p.college_id<>? OR o.ay_id<>? OR o.semester<>?)",[$ayId,$semester,$collegeId,$ayId,$semester]);
        foreach($rows as $row) {
            if($row['schedule_status']==='tba') continue;
            foreach($external as $other) if(in_array((int)$other['assignee_id'],$assignments[(int)$row['schedule_id']][$kind],true)
                && days_overlap($row['days'],synk_normalize_schedule_days(json_decode($other['days_json'],true)))
                && time_overlap($row['time_start'],$row['time_end'],(string)$other['time_start'],(string)$other['time_end'])) {
                throw new DomainException($row['label'].' conflicts with an assigned faculty workload outside this college set.');
            }
        }
    }
}

function synk_set_file_apply(mysqli $conn, array $plan, int $collegeId, int $ayId, int $semester): void
{
    $oldMeetings=array_column($plan['state']['meetings'],null,'schedule_id');
    foreach($plan['meetings'] as $id=>$next) {
        if(!synk_set_file_meeting_changed($oldMeetings[$id],$next)) continue;
        $stmt=$conn->prepare('UPDATE tbl_class_schedule SET schedule_status=?,room_id=?,days_json=?,time_start=?,time_end=? WHERE schedule_id=?');
        $stmt->bind_param('sisssi',$next['schedule_status'],$next['room_id'],$next['days_json'],$next['time_start'],$next['time_end'],$id);
        if(!$stmt->execute()) throw new RuntimeException('Unable to restore the schedule.'); $stmt->close();
        synk_schedule_tba_record_event($conn,$id,(int)$next['offering_id'],$collegeId,$oldMeetings[$id],$next);
    }
    foreach(['faculty'=>'tbl_faculty_workload_sched','faculty_need'=>'tbl_faculty_need_workload_sched'] as $kind=>$table) {
        $column=$kind==='faculty'?'faculty_id':'faculty_need_id'; $idColumn=$kind==='faculty'?'workload_id':'need_workload_id';
        $existing=[];
        foreach($plan['state']['assignments'][$kind] as $row) {
            $sid=(int)$row['schedule_id']; $assignee=(int)$row[$column];
            if(in_array($assignee,$plan['assignments'][$sid][$kind],true)) {$existing[$sid][$assignee]=true;continue;}
            $stmt=$conn->prepare("DELETE FROM {$table} WHERE {$idColumn}=?"); $rowId=(int)$row[$idColumn];$stmt->bind_param('i',$rowId);
            if(!$stmt->execute()) throw new RuntimeException('Unable to restore workload.');$stmt->close();
        }
        foreach($plan['assignments'] as $sid=>$kinds) foreach($kinds[$kind] as $assignee) {
            if(isset($existing[$sid][$assignee])) continue;
            $stmt=$conn->prepare("INSERT INTO {$table} (schedule_id,{$column},ay_id,semester) VALUES (?,?,?,?)");
            $stmt->bind_param('iiii',$sid,$assignee,$ayId,$semester);
            if(!$stmt->execute()) throw new RuntimeException('Unable to restore workload.');$stmt->close();
        }
    }
    foreach($plan['by_offering'] as $offeringId=>$rows) {
        $coverage=build_schedule_coverage_summary($plan['contexts'][$offeringId],$rows);
        if(!mark_offering_schedule_state($conn,$offeringId,$coverage['status']==='complete'?'active':'pending')) throw new RuntimeException('Unable to restore offering status.');
    }
    if(synk_table_exists($conn,synk_workload_audit_table_name())) {
        if(!synk_workload_audit_record($conn,'schedule_set_import',['college_id'=>$collegeId,'ay_id'=>$ayId,'semester'=>$semester,
            'affected_count'=>count($plan['meetings']),'details'=>$plan['summary']])) throw new RuntimeException('Unable to record the set import.');
    }
}

function synk_set_file_dispatch(mysqli $conn, int $collegeId, array $policy): void
{
    $transaction=false;$rosterLock=false;
    try {
        $actions=array_intersect(['export_schedule_file','preview_schedule_file','apply_schedule_file'],array_keys($_POST));
        if(count($actions)!==1 || (isset($_POST['set_name']) && !is_string($_POST['set_name']))) throw new DomainException('Choose one valid set action.');
        $term=synk_scheduler_request_term($conn,$_POST);$ayId=(int)$term['ay_id'];$semester=(int)$term['semester'];
        synk_schedule_acquire_write_lock($conn);
        if(isset($_POST['export_schedule_file'])) {
            if(!$conn->begin_transaction()) throw new RuntimeException('Unable to start the set export.');$transaction=true;
            $result=synk_set_file_export($conn,$collegeId,$ayId,$semester,(string)($_POST['set_name'] ?? ''));
            if(!$conn->commit()) throw new RuntimeException('Unable to finish the set export.');$transaction=false;
            respond('ok','Set exported. Keep the downloaded CSV to restore it later.',$result);
        }
        $upload=$_FILES['schedule_set_file'] ?? null;
        if(!is_array($upload) || !isset($upload['error'],$upload['tmp_name'],$upload['size']) || !is_scalar($upload['tmp_name'])
            || $upload['error']!==UPLOAD_ERR_OK || $upload['size']>8*1024*1024 || !is_uploaded_file($upload['tmp_name'])) {
            throw new DomainException('Choose a Synk CSV set up to 8 MB. The upload could not be read.');
        }
        $file=synk_set_file_parse(file_get_contents($upload['tmp_name']));
        // Share the roster lock used by workload removal, before opening a transaction.
        $lock=$conn->query("SELECT GET_LOCK('synk_college_dean_roster_import',5) AS acquired")->fetch_assoc();
        if((int)$lock['acquired']!==1) throw new DomainException('A student list is being updated. Try importing again shortly.');
        $rosterLock=true;
        if(!$conn->begin_transaction()) throw new RuntimeException('Unable to start the set import.');$transaction=true;
        $plan=synk_set_file_plan($conn,$file,$collegeId,$ayId,$semester,$policy);
        if(isset($_POST['apply_schedule_file'])) {
            $token=$_POST['preview_token'] ?? '';
            if(!is_string($token) || !hash_equals($plan['preview_token'],$token)) throw new DomainException('The file or live workspace changed since preview. Preview the file again before importing.');
            synk_set_file_apply($conn,$plan,$collegeId,$ayId,$semester);
        }
        if(!$conn->commit()) throw new RuntimeException('Unable to finish the set import.');$transaction=false;
        $conn->query("SELECT RELEASE_LOCK('synk_college_dean_roster_import')");$rosterLock=false;
        respond('ok',isset($_POST['apply_schedule_file'])?'Schedule and workload restored together.':'Set checked. Review the details before importing.',
            ['summary'=>$plan['summary'],'preview_token'=>$plan['preview_token']]);
    } catch(Throwable $error) {
        if($transaction) $conn->rollback();
        if($rosterLock) $conn->query("SELECT RELEASE_LOCK('synk_college_dean_roster_import')");
        if(!($error instanceof DomainException)) error_log('Schedule file set: '.$error->getMessage());
        http_response_code($error instanceof DomainException?422:500);
        respond('error',$error instanceof DomainException?$error->getMessage():'Unable to process this set. Nothing was changed.');
    }
}
