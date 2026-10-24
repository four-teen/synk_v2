<?php

require_once __DIR__ . '/schedule_tba_helper.php';
require_once __DIR__ . '/faculty_need_helper.php';
class SynkScheduleTbaConflictException extends DomainException {}

// Pending meetings and assigned meetings share the same update-in-place path.
// Existing schedule IDs and groups keep workload and enrollment links intact.
function synk_schedule_save_tba_blocks(mysqli $conn, array $ctx, int $collegeId, array $blocks, array $mergeInfo, array $policy): array
{
    if (!synk_schedule_tba_schema_ready($conn)) { throw new DomainException('TBA setup is not available yet.'); }
    if (count($blocks) > 50) { throw new DomainException('Too many schedule blocks.'); }
    if (strtolower((string)($ctx['offering_status'] ?? '')) === 'locked') { throw new DomainException('This offering is locked.'); }
    if (!empty($mergeInfo['is_merged_member']) || !empty($mergeInfo['inherited_types']) || !empty($mergeInfo['has_merged_members']) || has_outgoing_merge_dependencies($mergeInfo)) {
        throw new DomainException('Merged schedules must remain managed through their owner. Manage the merge links before changing these schedule blocks.');
    }
    $offeringId = (int)$ctx['offering_id'];
    $conn->begin_transaction();
    try {
        $conn->query('SELECT offering_id FROM tbl_prospectus_offering WHERE offering_id=' . $offeringId . ' FOR UPDATE');
        $existing = [];
        $result = $conn->query('SELECT * FROM tbl_class_schedule WHERE offering_id=' . $offeringId . ' ORDER BY schedule_id FOR UPDATE');
        while ($row = $result->fetch_assoc()) { $existing[(int)$row['schedule_id']] = $row; }
        $owners = [];
        if ($existing) {
            $ids = implode(',', array_keys($existing));
            $result = $conn->query('SELECT schedule_id,faculty_id FROM tbl_faculty_workload_sched WHERE schedule_id IN (' . $ids . ') AND ay_id=' . (int)$ctx['ay_id'] . ' AND semester=' . (int)$ctx['semester']);
            while ($row = $result->fetch_assoc()) { $owners[(int)$row['schedule_id']][] = (int)$row['faculty_id']; }
            $needTable = 'tbl_faculty_need_workload_sched';
            if (function_exists('synk_faculty_need_workload_table_name')) { $needTable = synk_faculty_need_workload_table_name(); }
            if (synk_table_exists($conn, $needTable)) {
                $result = $conn->query('SELECT schedule_id,faculty_need_id FROM `' . $needTable . '` WHERE schedule_id IN (' . $ids . ') AND ay_id=' . (int)$ctx['ay_id'] . ' AND semester=' . (int)$ctx['semester']);
                while ($row = $result->fetch_assoc()) { $owners[(int)$row['schedule_id']][] = -(int)$row['faculty_need_id']; }
            }
        }
        $allowedTypes = required_schedule_types_for_context($ctx);
        $normalized = [];
        $seen = [];
        foreach ($blocks as $index => $block) {
            if (!is_array($block) || filter_var($block['is_inherited'] ?? false,FILTER_VALIDATE_BOOLEAN)) { throw new DomainException('Invalid schedule block.'); }
            foreach (['type','schedule_status','schedule_id','room_id','time_start','time_end','days_json'] as $field) {
                if (isset($block[$field]) && !is_scalar($block[$field])) { throw new DomainException('Invalid schedule block input.'); }
            }
            foreach (['schedule_id','room_id'] as $field) {
                if (isset($block[$field]) && (string)$block[$field] !== '' && !preg_match('/^\d{1,10}$/D',(string)$block[$field])) { throw new DomainException('Invalid schedule or room identifier.'); }
            }
            if (isset($block['days']) && (!is_array($block['days']) || array_filter($block['days'], static function ($day): bool { return !is_string($day); }))) { throw new DomainException('Invalid schedule days.'); }
            $type = strtoupper((string)($block['type'] ?? ''));
            $status = $block['schedule_status'] ?? 'scheduled';
            if (!is_string($status) || !in_array($status, ['scheduled','tba','room_tba'], true) || !in_array($type, $allowedTypes, true)) { throw new DomainException('Invalid schedule status or meeting type.'); }
            $id = (int)($block['schedule_id'] ?? 0);
            if ($id && (!isset($existing[$id]) || isset($seen[$id]) || $type !== $existing[$id]['schedule_type'])) { throw new DomainException('A schedule block does not match this offering.'); }
            if ($id) { $seen[$id] = true; }
            $daysRaw = $block['days'] ?? json_decode((string)($block['days_json'] ?? '[]'), true);
            if (!is_array($daysRaw)) { throw new DomainException('Invalid schedule days.'); }
            $days = synk_normalize_schedule_days(is_array($daysRaw) ? $daysRaw : []);
            $roomId = (int)($block['room_id'] ?? 0);
            $start = normalize_time_input($block['time_start'] ?? '');
            $end = normalize_time_input($block['time_end'] ?? '');
            $label = ($type === 'LAB' ? 'Laboratory' : 'Lecture') . ' ' . ($index + 1);
            if ($status === 'tba') {
                if ($roomId > 0 || trim((string)($block['time_start'] ?? '')) !== '' || trim((string)($block['time_end'] ?? '')) !== '' || $daysRaw) { throw new DomainException('TBA must have no reserved day, time, or room.'); }
                $roomId = null; $start = null; $end = null; $days = [];
            } else {
                if (!$start || !$end || !$days || $end <= $start) { throw new DomainException($label . ' requires valid days and times.'); }
                validate_schedule_policy($days, $start, $end, $label, $policy);
                if ($status === 'room_tba') {
                    if ($roomId > 0) { throw new DomainException('Room TBA cannot reserve a room.'); }
                    $roomId = null;
                } else {
                    if ($roomId <= 0) { throw new DomainException($label . ' requires a room.'); }
                    validate_room_for_schedule($conn, $roomId, $collegeId, (int)$ctx['ay_id'], (int)$ctx['semester'], $type);
                }
            }
            $next = ['schedule_id'=>$id, 'schedule_type'=>$type, 'type'=>$type, 'schedule_status'=>$status, 'label'=>$label,
                'room_id'=>$roomId, 'time_start'=>$start, 'time_end'=>$end, 'days'=>$days, 'days_json'=>json_encode($days)];
            if ($owners) {
                if (!$id) { throw new DomainException('Edit the existing meetings. Adding meetings to an assigned offering changes its load.'); }
            }
            if ($status !== 'tba') {
                check_conflict($conn, $ctx, $offeringId, (int)$roomId, $start, $end, $days, $label);
                foreach ($owners[$id] ?? [] as $facultyId) {
                    $facultyConflicts = $facultyId > 0 ? load_faculty_conflicts_for_schedule_block($conn, $facultyId, (int)$ctx['ay_id'], (int)$ctx['semester'], $offeringId, $days, $start, $end) : [];
                    if ($facultyId < 0) {
                        $needId = -$facultyId;
                        $needResult = $conn->query('SELECT cs.* FROM `' . $needTable . '` nw JOIN tbl_class_schedule cs ON cs.schedule_id=nw.schedule_id WHERE nw.faculty_need_id=' . $needId . ' AND nw.ay_id=' . (int)$ctx['ay_id'] . ' AND nw.semester=' . (int)$ctx['semester'] . ' AND cs.offering_id<>' . $offeringId);
                        while ($other = $needResult->fetch_assoc()) {
                            if (days_overlap($days, synk_normalize_schedule_days(json_decode((string)$other['days_json'], true))) && time_overlap($start,$end,(string)$other['time_start'],(string)$other['time_end'])) { $facultyConflicts[]=$other; }
                        }
                    }
                    if ($facultyConflicts) {
                        throw new SynkScheduleTbaConflictException($label . ' conflicts with an assigned faculty workload. Select another time.');
                    }
                }
            }
            $normalized[] = $next;
        }
        if (!$normalized) { throw new DomainException('Add at least one schedule block.'); }
        if ($owners && count($seen) !== count($existing)) { throw new DomainException('Keep all existing meetings while editing an assigned class.'); }
        $message = find_internal_schedule_conflict_message($normalized);
        if ($message !== '') { throw new SynkScheduleTbaConflictException(strip_tags($message)); }
        $groupId = null;
        if (count($normalized) > 1) {
            foreach ($existing as $old) { if (!empty($old['schedule_group_id'])) { $groupId = (int)$old['schedule_group_id']; break; } }
            if (!$groupId) { $groupId = random_int(100000, 2147483647); }
        }
        $kept = [];
        foreach ($normalized as $next) {
            $id = $next['schedule_id']; $type=$next['type']; $status=$next['schedule_status']; $room=$next['room_id'];
            $start=$next['time_start']; $end=$next['time_end']; $daysJson=$next['days_json'];
            $rowGroupId = $owners && $id ? $existing[$id]['schedule_group_id'] : $groupId;
            if ($id) {
                $stmt=$conn->prepare('UPDATE tbl_class_schedule SET schedule_status=?,schedule_group_id=?,room_id=?,days_json=?,time_start=?,time_end=? WHERE schedule_id=? AND offering_id=?');
                $stmt->bind_param('siisssii',$status,$rowGroupId,$room,$daysJson,$start,$end,$id,$offeringId);
            } else {
                $createdBy=(int)($_SESSION['user_id'] ?? 0);
                $stmt=$conn->prepare('INSERT INTO tbl_class_schedule (offering_id,schedule_type,schedule_status,schedule_group_id,room_id,days_json,time_start,time_end,created_by) VALUES (?,?,?,?,?,?,?,?,?)');
                $stmt->bind_param('issiisssi',$offeringId,$type,$status,$rowGroupId,$room,$daysJson,$start,$end,$createdBy);
            }
            if (!$stmt->execute()) { throw new RuntimeException('Unable to save the schedule.'); }
            if (!$id) { $id=(int)$conn->insert_id; }
            $stmt->close(); $kept[$id]=true; $next['schedule_id']=$id; $next['schedule_group_id']=$rowGroupId;
            $old = $existing[$id] ?? [];
            $changed = $old && (synk_schedule_status($old) !== $status || (int)$old['room_id'] !== (int)$room
                || synk_normalize_schedule_days(json_decode((string)$old['days_json'], true)) !== $next['days']
                || (string)$old['time_start'] !== (string)$start || (string)$old['time_end'] !== (string)$end);
            if ($status !== 'scheduled' || ($old && synk_schedule_status($old) !== 'scheduled') || ($owners && $changed)) {
                synk_schedule_tba_record_event($conn,$id,$offeringId,$collegeId,$existing[$id] ?? [],$next);
            }
        }
        foreach ($existing as $id=>$old) {
            if (!isset($kept[$id])) { $conn->query('DELETE FROM tbl_class_schedule WHERE schedule_id=' . $id . ' AND offering_id=' . $offeringId); }
        }
        $coverage=build_schedule_coverage_summary($ctx,$normalized);
        $hasPending=false;
        foreach ($normalized as $next) { if ($next['schedule_status'] !== 'scheduled') { $hasPending=true; } }
        if (!mark_offering_schedule_state($conn,$offeringId,!$hasPending && $coverage['status']==='complete' ? 'active' : 'pending')) { throw new RuntimeException('Unable to update the offering.'); }
        $conn->commit();
        return ['status_key'=>$hasPending ? 'tba' : $coverage['status'], 'required_minutes'=>$coverage['required_minutes'], 'scheduled_minutes'=>$coverage['scheduled_minutes']];
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}
