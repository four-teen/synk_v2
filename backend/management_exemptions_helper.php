<?php
require_once __DIR__.'/management_catalog_helper.php';
require_once __DIR__.'/college_dean_students_helper.php';
require_once __DIR__.'/management_payment_read_helper.php';

function synk_management_exemptions_ready(mysqli $conn): bool
{
    foreach (['tbl_management_exemption_lists','tbl_management_exemption_list_members','tbl_management_exemptions','tbl_management_exemption_audit'] as $table) {
        if (!synk_table_exists($conn,$table)) return false;
    }
    return true;
}

function synk_management_exemption_period_values(array $scope,array $period): array
{
    return [$scope['college_id'],$scope['organization_id'],$period['ay_id'],$period['semester'],$period['exam_term']];
}

function synk_management_exemption_ids($raw,bool $allowEmpty=false): array
{
    if (!is_string($raw) || strlen($raw)>30000) throw new DomainException('Choose up to 1000 records.');
    $ids=json_decode($raw,true);
    if (!is_array($ids) || array_values($ids)!==$ids || count($ids)>1000 || (!$allowEmpty && !$ids)) throw new DomainException('Choose between 1 and 1000 records.');
    return array_values(array_unique(array_map(static function($id){
        if (!is_int($id) && !is_string($id)) throw new DomainException('Choose valid record IDs.');
        return synk_management_input_id($id);
    },$ids)));
}

function synk_management_exemption_students(mysqli $conn,array $scope,array $period,array $ids): array
{
    if (!$ids) return [];
    $source=synk_dean_students_source($scope,['ay_id'=>$period['ay_id'],'semester'=>$period['semester']],$conn);
    $rows=synk_management_query($conn,"SELECT DISTINCT s.student_id,s.student_number,CONCAT_WS(' ',s.last_name,s.first_name,s.middle_name,s.suffix_name) AS student_name ".$source['sql'].' AND s.student_id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY s.last_name,s.first_name,s.student_id',$source['types'].str_repeat('i',count($ids)),array_merge($source['params'],$ids));
    if (count($rows)!==count($ids)) throw new DomainException('Some students are outside your college or selected academic year and semester. Update the selection before applying it.');
    return $rows;
}

function synk_management_exemption_search(mysqli $conn,array $scope,array $period,string $search): array
{
    $search=trim($search);
    if (mb_strlen($search)<2 || mb_strlen($search)>150 || !preg_match('//u',$search)) throw new DomainException('Enter 2 to 150 characters of a student name or ID.');
    $source=synk_dean_students_source($scope,['ay_id'=>$period['ay_id'],'semester'=>$period['semester']],$conn);
    $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
    return synk_management_query($conn,"SELECT DISTINCT s.student_id,s.student_number,CONCAT_WS(' ',s.last_name,s.first_name,s.middle_name,s.suffix_name) AS student_name ".$source['sql']." AND (CONCAT_WS(' ',s.first_name,s.middle_name,s.last_name) LIKE ? ESCAPE '!' OR s.student_number LIKE ? ESCAPE '!') ORDER BY s.last_name,s.first_name,s.student_id LIMIT 50",$source['types'].'ss',array_merge($source['params'],[$pattern,$pattern]));
}

function synk_management_exemption_list_get(mysqli $conn,array $scope,array $period,int $id,bool $lock=false): array
{
    $rows=synk_management_query($conn,'SELECT * FROM tbl_management_exemption_lists WHERE college_id=? AND organization_id=? AND ay_id=? AND semester=? AND exam_term=? AND list_id=?'.($lock?' FOR UPDATE':''),'iiiisi',array_merge(synk_management_exemption_period_values($scope,$period),[$id]));
    if (!$rows) throw new OutOfBoundsException('This exemption list is unavailable in the selected Management period.');
    $row=$rows[0];
    $row['students']=synk_management_query($conn,"SELECT s.student_id,s.student_number,CONCAT_WS(' ',s.last_name,s.first_name,s.middle_name,s.suffix_name) AS student_name FROM tbl_management_exemption_list_members m JOIN tbl_student_management s ON s.student_id=m.student_id WHERE m.list_id=? ORDER BY s.last_name,s.first_name,s.student_id",'i',[$id]);
    return $row;
}

function synk_management_exemption_data(mysqli $conn,array $scope,array $period,array $input=[]): array
{
    if (!synk_management_exemptions_ready($conn)) throw new RuntimeException('Management exemption storage has not been prepared.');
    $values=synk_management_exemption_period_values($scope,$period);
    $lists=synk_management_query($conn,'SELECT l.*,(SELECT COUNT(*) FROM tbl_management_exemption_list_members m WHERE m.list_id=l.list_id) AS member_count FROM tbl_management_exemption_lists l WHERE l.college_id=? AND l.organization_id=? AND l.ay_id=? AND l.semester=? AND l.exam_term=? ORDER BY l.title,l.list_id','iiiis',$values);
    $events=synk_management_query($conn,"SELECT e.event_id,e.title,e.event_date,(SELECT COUNT(*) FROM tbl_management_contributions c WHERE c.event_id=e.event_id AND c.status='ACTIVE') AS contribution_count FROM tbl_management_events e WHERE e.college_id=? AND e.organization_id=? AND e.ay_id=? AND e.semester=? AND e.exam_term=? AND e.status='ACTIVE' ORDER BY e.event_id DESC",'iiiis',$values);
    $contributions=synk_management_query($conn,"SELECT contribution_id,title,amount,event_id FROM tbl_management_contributions WHERE college_id=? AND organization_id=? AND ay_id=? AND semester=? AND exam_term=? AND status='ACTIVE' ORDER BY contribution_id DESC",'iiiis',$values);
    $extra='';$extraTypes='';$extraValues=[];
    foreach (['event_id','contribution_id','student_id'] as $key) {
        $id=synk_management_input_id($input[$key] ?? '',true);
        if ($id && $key==='event_id') { $extra.=' AND (x.event_id=? OR x.source_event_id=?)';$extraTypes.='ii';$extraValues[]=$id;$extraValues[]=$id; }
        elseif ($id) { $extra.=' AND x.'.$key.'=?';$extraTypes.='i';$extraValues[]=$id; }
    }
    $search=trim((string)($input['search'] ?? ''));
    if (mb_strlen($search)>150 || !preg_match('//u',$search)) throw new DomainException('Search using up to 150 characters.');
    if ($search!=='') {
        $extra.=" AND (s.student_number LIKE ? ESCAPE '!' OR CONCAT_WS(' ',s.first_name,s.last_name) LIKE ? ESCAPE '!')";$extraTypes.='ss';
        $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';$extraValues[]=$pattern;$extraValues[]=$pattern;
    }
    $from=' FROM tbl_management_exemptions x JOIN tbl_student_management s ON s.student_id=x.student_id LEFT JOIN tbl_management_events e ON e.event_id=x.event_id LEFT JOIN tbl_management_contributions c ON c.contribution_id=x.contribution_id LEFT JOIN tbl_management_exemption_lists l ON l.list_id=x.list_id WHERE x.college_id=? AND x.organization_id=? AND x.ay_id=? AND x.semester=? AND x.exam_term=?'.$extra;
    $types='iiiis'.$extraTypes;$params=array_merge($values,$extraValues);
    $total=(int)synk_management_query($conn,'SELECT COUNT(*) AS n'.$from,$types,$params)[0]['n'];
    $page=filter_var($input['page'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($page===false) throw new DomainException('Choose a valid page.');
    $pages=max(1,(int)ceil($total/50));$page=min($page,$pages);
    $grants=synk_management_query($conn,"SELECT x.*,s.student_number,CONCAT_WS(' ',s.last_name,s.first_name,s.middle_name,s.suffix_name) AS student_name,COALESCE(e.title,c.title) AS title,l.title AS list_title".$from.' ORDER BY x.exemption_id DESC LIMIT 50 OFFSET ?',$types.'i',array_merge($params,[($page-1)*50]));
    $audit=synk_management_query($conn,'SELECT a.action,a.details,a.recorded_at,u.username AS actor_name FROM tbl_management_exemption_audit a LEFT JOIN tbl_useraccount u ON u.user_id=a.actor_user_id WHERE a.college_id=? AND a.organization_id=? AND a.ay_id=? AND a.semester=? AND a.exam_term=? ORDER BY a.audit_id DESC LIMIT 30','iiiis',$values);
    foreach ($audit as &$entry) $entry['recorded_at']=(new DateTimeImmutable($entry['recorded_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A').' PHT';
    unset($entry);
    return ['lists'=>$lists,'events'=>$events,'contributions'=>$contributions,'grants'=>$grants,'audit'=>$audit,'pagination'=>['page'=>$page,'pages'=>$pages,'total'=>$total]];
}

function synk_management_exemption_audit(mysqli $conn,array $scope,array $period,string $action,array $details): void
{
    synk_management_execute($conn,'INSERT INTO tbl_management_exemption_audit (college_id,organization_id,ay_id,semester,exam_term,actor_user_id,action,details,recorded_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP())','iiiisiss',array_merge(synk_management_exemption_period_values($scope,$period),[(int)$_SESSION['user_id'],$action,json_encode($details,JSON_UNESCAPED_UNICODE)]));
    synk_management_audit_officer_operation($conn,$scope,'EXEMPTION_'.$action,$details);
}

// Used only while the organization is locked. Never read reusable list membership here.
function synk_management_exemption_grant(mysqli $conn,array $scope,array $period,int $studentId,string $kind,int $chargeId,string $reason,?int $listId,?int $sourceEvent=null): bool
{
    $column=$kind==='events'?'event_id':'contribution_id';
    $rows=synk_management_query($conn,'SELECT * FROM tbl_management_exemptions WHERE student_id=? AND '.$column.'=? FOR UPDATE','ii',[$studentId,$chargeId]);
    if ($rows && $rows[0]['status']==='ACTIVE') {
        // An explicit contribution selection makes that existing waiver independent.
        if ($kind==='contributions' && $sourceEvent===null && $rows[0]['source_event_id']!==null) {
            synk_management_execute($conn,'UPDATE tbl_management_exemptions SET source_event_id=NULL,revision=revision+1 WHERE exemption_id=?','i',[(int)$rows[0]['exemption_id']]);
            return true;
        }
        return false;
    }
    if (synk_management_payments_ready($conn) && synk_management_query($conn,'SELECT i.payment_item_id FROM tbl_management_payment_items i JOIN tbl_management_payments p ON p.payment_id=i.payment_id WHERE p.student_id=? AND i.'.$column.'=? LIMIT 1','ii',[$studentId,$chargeId])) {
        throw new SynkManagementConflict('A selected student has already paid part or all of this charge. A separate payment correction is required before granting an exemption.');
    }
    if ($rows) {
        synk_management_execute($conn,"UPDATE tbl_management_exemptions SET status='ACTIVE',reason=?,list_id=?,source_event_id=?,recorded_by=?,recorded_at=UTC_TIMESTAMP(),revision=revision+1 WHERE exemption_id=?",'siiii',[$reason,$listId,$sourceEvent,(int)$_SESSION['user_id'],(int)$rows[0]['exemption_id']]);
    } else {
        synk_management_execute($conn,'INSERT INTO tbl_management_exemptions (college_id,organization_id,ay_id,semester,exam_term,student_id,event_id,contribution_id,source_event_id,list_id,reason,recorded_by,recorded_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())','iiiisiiiiisi',array_merge(synk_management_exemption_period_values($scope,$period),[$studentId,$kind==='events'?$chargeId:null,$kind==='contributions'?$chargeId:null,$sourceEvent,$listId,$reason,(int)$_SESSION['user_id']]));
    }
    return true;
}

// A newly linked contribution inherits saved event exemptions, never today's list members.
function synk_management_exemption_inherit_contribution(mysqli $conn,array $scope,array $period,int $contributionId,?int $eventId): void
{
    if (!$eventId || !synk_management_exemptions_ready($conn)) return;
    $rows=synk_management_query($conn,"SELECT student_id,reason,list_id FROM tbl_management_exemptions WHERE event_id=? AND status='ACTIVE'",'i',[$eventId]);
    $ids=[];
    foreach ($rows as $row) {
        // Catalog edits must not undo a student's explicit contribution revocation.
        if (synk_management_query($conn,'SELECT exemption_id FROM tbl_management_exemptions WHERE student_id=? AND contribution_id=?','ii',[(int)$row['student_id'],$contributionId])) continue;
        if (synk_management_exemption_grant($conn,$scope,$period,(int)$row['student_id'],'contributions',$contributionId,$row['reason'],$row['list_id']?(int)$row['list_id']:null,$eventId)) $ids[]=(int)$row['student_id'];
    }
    if ($ids) synk_management_exemption_audit($conn,$scope,$period,'INHERIT',['contribution_id'=>$contributionId,'event_id'=>$eventId,'student_ids'=>$ids]);
}

function synk_management_exemption_write(mysqli $conn,array $scope,string $action,array $input): array
{
    if (!synk_management_exemptions_ready($conn)) throw new RuntimeException('Management exemption storage has not been prepared.');
    $conn->begin_transaction();
    try {
        synk_management_lock_assignment($conn,$scope);
        $permissions=synk_management_require_permission($conn,$scope,'manage_exemptions');
        $period=synk_management_lock_current_term($conn,$input,$scope);
        if (($input['term'] ?? $period['key'])!==$period['key'] || ($input['exam_term'] ?? $period['exam_term'])!==$period['exam_term']) throw new SynkManagementConflict('Switch to the current Management period before changing exemptions.');
        $values=synk_management_exemption_period_values($scope,$period);
        if ($action==='exemption_list_save') {
            $id=synk_management_input_id($input['list_id'] ?? '',true);
            $old=$id?synk_management_exemption_list_get($conn,$scope,$period,$id,true):null;
            if ($old && (string)($input['revision'] ?? '')!==(string)$old['revision']) throw new SynkManagementConflict('This list changed. Open it again before saving.');
            $title=synk_management_input_text($input,'title',150);$reason=synk_management_input_text($input,'reason',1000);
            $ids=synk_management_exemption_ids($input['student_ids'] ?? '',true);
            synk_management_exemption_students($conn,$scope,$period,$ids);
            if ($id) synk_management_execute($conn,'UPDATE tbl_management_exemption_lists SET title=?,reason=?,revision=revision+1 WHERE list_id=?','ssi',[$title,$reason,$id]);
            else {
                synk_management_execute($conn,'INSERT INTO tbl_management_exemption_lists (college_id,organization_id,ay_id,semester,exam_term,title,reason) VALUES (?,?,?,?,?,?,?)','iiiisss',array_merge($values,[$title,$reason]));$id=(int)$conn->insert_id;
            }
            synk_management_execute($conn,'DELETE FROM tbl_management_exemption_list_members WHERE list_id=?','i',[$id]);
            foreach ($ids as $studentId) synk_management_execute($conn,'INSERT INTO tbl_management_exemption_list_members (list_id,student_id) VALUES (?,?)','ii',[$id,$studentId]);
            synk_management_exemption_audit($conn,$scope,$period,'LIST_SAVE',['list_id'=>$id,'title'=>$title,'reason'=>$reason,'student_ids'=>$ids,'previous_student_ids'=>$old?array_column($old['students'],'student_id'):[]]);
            $message='List saved. Previously granted exemptions are unchanged.';
        } elseif ($action==='exemption_apply') {
            $listId=synk_management_input_id($input['list_id'] ?? '',true);
            $list=$listId?synk_management_exemption_list_get($conn,$scope,$period,$listId,true):null;
            if ($list && (string)($input['list_revision'] ?? '')!==(string)$list['revision']) throw new SynkManagementConflict('The selected list changed. Reload it before applying exemptions.');
            $studentIds=$list?array_map('intval',array_column($list['students'],'student_id')):synk_management_exemption_ids($input['student_ids'] ?? '');
            if (!$studentIds) throw new DomainException('The selected list is empty. Add students first.');
            synk_management_exemption_students($conn,$scope,$period,$studentIds);
            if ($permissions['is_officer'] && in_array($permissions['student_id'],$studentIds,true)) throw new SynkManagementPermissionDenied('Ask your faculty manager or another authorized officer to grant exemptions on your own account.');
            $reason=$list?$list['reason']:synk_management_input_text($input,'reason',1000);
            $eventIds=synk_management_exemption_ids($input['event_ids'] ?? '[]',true);
            $contributionIds=synk_management_exemption_ids($input['contribution_ids'] ?? '[]',true);
            if (!$eventIds && !$contributionIds) throw new DomainException('Select at least one event or contribution.');
            $charges=[];
            foreach (['events'=>$eventIds,'contributions'=>$contributionIds] as $kind=>$ids) foreach ($ids as $id) {
                $charge=synk_management_catalog_get($conn,$scope,$kind,$id,true);
                if (!synk_management_catalog_in_period($charge,$period) || $charge['status']!=='ACTIVE') throw new DomainException('Select active events and contributions in the current Management period.');
                $charges[$kind.':'.$id]=['kind'=>$kind,'id'=>$id,'source_event_id'=>null];
                if ($kind==='events') foreach (synk_management_query($conn,"SELECT contribution_id FROM tbl_management_contributions WHERE event_id=? AND college_id=? AND organization_id=? AND ay_id=? AND semester=? AND exam_term=? AND status='ACTIVE'",'iiiiis',array_merge([$id],$values)) as $linked) {
                    $linkedId=(int)$linked['contribution_id'];$charges['contributions:'.$linkedId]=['kind'=>'contributions','id'=>$linkedId,'source_event_id'=>$id];
                }
            }
            $changed=0;
            foreach ($studentIds as $studentId) foreach ($charges as $charge) {
                if (synk_management_exemption_grant($conn,$scope,$period,$studentId,$charge['kind'],$charge['id'],$reason,$listId,$charge['source_event_id'])) $changed++;
            }
            synk_management_exemption_audit($conn,$scope,$period,'APPLY',['list_id'=>$listId,'list_title'=>$list['title'] ?? null,'student_ids'=>$studentIds,'charges'=>array_values($charges),'reason'=>$reason,'changed'=>$changed]);
            $message=$changed.' exemption(s) saved for '.count($studentIds).' student(s). Existing exemptions were retained.';
        } elseif ($action==='exemption_revoke') {
            $id=synk_management_input_id($input['exemption_id'] ?? '');
            $rows=synk_management_query($conn,'SELECT * FROM tbl_management_exemptions WHERE college_id=? AND organization_id=? AND ay_id=? AND semester=? AND exam_term=? AND exemption_id=? FOR UPDATE','iiiisi',array_merge($values,[$id]));
            if (!$rows) throw new OutOfBoundsException('This exemption is unavailable.');$row=$rows[0];
            if ($permissions['is_officer'] && (int)$row['student_id']===$permissions['student_id']) throw new SynkManagementPermissionDenied('Ask your faculty manager or another authorized officer to change exemptions on your own account.');
            if ($row['status']!=='ACTIVE' || (string)($input['revision'] ?? '')!==(string)$row['revision']) throw new SynkManagementConflict('This exemption changed. Reload before continuing.');
            $reason=synk_management_input_text($input,'reason',1000);
            $revoked=[$id];
            if ($row['event_id']) foreach (synk_management_query($conn,"SELECT exemption_id FROM tbl_management_exemptions WHERE student_id=? AND source_event_id=? AND status='ACTIVE' FOR UPDATE",'ii',[(int)$row['student_id'],(int)$row['event_id']]) as $linked) $revoked[]=(int)$linked['exemption_id'];
            foreach ($revoked as $exemptionId) synk_management_execute($conn,"UPDATE tbl_management_exemptions SET status='REVOKED',revision=revision+1 WHERE exemption_id=?",'i',[$exemptionId]);
            synk_management_exemption_audit($conn,$scope,$period,'REVOKE',['exemption_ids'=>$revoked,'student_id'=>(int)$row['student_id'],'reason'=>$reason]);
            $message='Exemption revoked. Any contributions inherited from this event were also revoked. Attendance scans and receipts were preserved.';
        } else throw new DomainException('Choose a valid exemption action.');
        $conn->commit();return ['message'=>$message];
    } catch (Throwable $error) { $conn->rollback();throw $error; }
}
