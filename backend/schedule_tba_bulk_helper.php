<?php

require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schedule_tba_helper.php';

// Build the same plan for preview and apply. Only live offerings in this program
// and college term are considered; merge owners and members are both excluded.
function synk_schedule_tba_bulk_plan(mysqli $conn, int $collegeId, int $programId, int $ayId, int $semester, string $mode): array
{
    if (!in_array($mode, ['generate', 'convert'], true)) {
        throw new DomainException('Select a valid TBA action.');
    }
    if ($collegeId <= 0 || $programId <= 0 || $ayId <= 0 || !in_array($semester, [1, 2, 3], true)) {
        throw new DomainException('Select Program, Academic Year, and Semester first.');
    }
    if (!synk_schedule_tba_schema_ready($conn)) {
        throw new DomainException('TBA setup is not available yet.');
    }
    $stmt = $conn->prepare('SELECT program_code FROM tbl_program WHERE program_id=? AND college_id=?');
    $stmt->bind_param('ii', $programId, $collegeId);
    $stmt->execute();
    $program = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$program) {
        throw new DomainException('This program is outside your college workspace.');
    }

    $joins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $stmt = $conn->prepare("SELECT o.offering_id,o.status AS offering_status,ps.lec_units,ps.lab_units,ps.total_units,
        COALESCE(NULLIF(sec.full_section,''),sec.section_name) AS section_name,sm.sub_code,sm.sub_description
        FROM tbl_prospectus_offering o {$joins}
        JOIN tbl_program p ON p.program_id=o.program_id
        JOIN tbl_subject_masterlist sm ON sm.sub_id=ps.sub_id
        WHERE o.program_id=? AND o.ay_id=? AND o.semester=? AND p.college_id=?
        ORDER BY o.offering_id");
    $stmt->bind_param('iiii', $programId, $ayId, $semester, $collegeId);
    $stmt->execute();
    $offerings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $rowsByOffering = [];
    $merged = [];
    $scheduleRows = [];
    $mergeRows = [];
    if ($offerings) {
        $ids = implode(',', array_map('intval', array_column($offerings, 'offering_id')));
        $scheduleRows = $conn->query("SELECT * FROM tbl_class_schedule WHERE offering_id IN ({$ids}) ORDER BY schedule_id")->fetch_all(MYSQLI_ASSOC);
        foreach ($scheduleRows as $row) {
            $rowsByOffering[(int)$row['offering_id']][] = $row;
        }
        // Do not run the legacy merge schema installer during a preview or save.
        if (synk_table_exists($conn, 'tbl_class_schedule_merge')) {
            $mergeRows = $conn->query("SELECT * FROM tbl_class_schedule_merge
                WHERE owner_offering_id IN ({$ids}) OR member_offering_id IN ({$ids}) ORDER BY schedule_merge_id")->fetch_all(MYSQLI_ASSOC);
            foreach ($mergeRows as $row) {
                $merged[(int)$row['owner_offering_id']] = true;
                $merged[(int)$row['member_offering_id']] = true;
            }
        }
    }

    $summary = ['total_count'=>count($offerings), 'eligible_count'=>0, 'meeting_count'=>0, 'skipped_count'=>0, 'unchanged_count'=>0];
    $items = [];
    $changes = [];
    foreach ($offerings as $offering) {
        $offeringId = (int)$offering['offering_id'];
        $rows = $rowsByOffering[$offeringId] ?? [];
        $item = ['offering_id'=>$offeringId, 'sub_code'=>$offering['sub_code'], 'sub_description'=>$offering['sub_description'],
            'section_name'=>$offering['section_name'], 'status'=>'skipped', 'reason'=>'', 'meeting_count'=>0];
        $types = (float)$offering['lab_units'] > 0 ? ['LEC', 'LAB'] : ['LEC'];
        $changedRows = array_values(array_filter($rows, static function (array $row): bool {
            return $row['schedule_status'] !== 'tba' || $row['room_id'] !== null || $row['time_start'] !== null
                || $row['time_end'] !== null || trim((string)$row['days_json']) !== '[]';
        }));
        if (strtolower(trim((string)$offering['offering_status'])) === 'locked') {
            $item['reason'] = 'Locked offering.';
        } elseif (isset($merged[$offeringId])) {
            $item['reason'] = 'Merged schedule. Manage its merge links first.';
        } elseif ($mode === 'generate' && $rows) {
            $item['reason'] = 'Schedule blocks already exist. Use Set All to TBA to convert them.';
        } elseif ($mode === 'convert' && !$rows) {
            $item['reason'] = 'No schedule blocks. Use Generate TBA to create them.';
        } elseif ($mode === 'convert' && !$changedRows) {
            $item['status'] = 'unchanged';
            $item['reason'] = 'Already TBA.';
        } elseif ($mode === 'convert' && array_diff(array_column($rows, 'schedule_type'), ['LEC', 'LAB'])) {
            $item['reason'] = 'Unsupported meeting type. Review this class individually.';
        } else {
            $item['status'] = 'ready';
            $item['meeting_count'] = $mode === 'generate' ? count($types) : count($changedRows);
            $item['reason'] = $mode === 'generate' ? 'Create ' . implode(' + ', $types) . ' as TBA.' : 'Set existing meetings to TBA; keep faculty assignments.';
            $changes[] = ['offering_id'=>$offeringId, 'types'=>$types, 'rows'=>$changedRows];
            $summary['eligible_count']++;
            $summary['meeting_count'] += $item['meeting_count'];
        }
        if ($item['status'] === 'skipped') { $summary['skipped_count']++; }
        if ($item['status'] === 'unchanged') { $summary['unchanged_count']++; }
        $items[] = $item;
    }

    return [
        'mode'=>$mode, 'program_code'=>$program['program_code'], 'summary'=>$summary, 'items'=>$items, 'changes'=>$changes,
        'preview_token'=>hash('sha256', json_encode([$collegeId,$programId,$ayId,$semester,$mode,$offerings,$scheduleRows,$mergeRows]))
    ];
}

function synk_schedule_tba_bulk_apply(mysqli $conn, int $collegeId, int $programId, int $ayId, int $semester, string $mode, string $previewToken): array
{
    synk_schedule_acquire_write_lock($conn);
    if (!$conn->begin_transaction()) {
        throw new RuntimeException('Unable to start the TBA update.');
    }
    try {
        $plan = synk_schedule_tba_bulk_plan($conn, $collegeId, $programId, $ayId, $semester, $mode);
        if (!preg_match('/^[a-f0-9]{64}$/D', $previewToken) || !hash_equals($plan['preview_token'], $previewToken)) {
            throw new DomainException('Schedules changed since this preview. Refresh the preview before applying.');
        }
        $createdBy = (int)($_SESSION['user_id'] ?? 0);
        foreach ($plan['changes'] as $change) {
            $offeringId = $change['offering_id'];
            if ($mode === 'generate') {
                $groupId = count($change['types']) > 1 ? random_int(100000, 2147483647) : null;
                foreach ($change['types'] as $type) {
                    $stmt = $conn->prepare("INSERT INTO tbl_class_schedule
                        (offering_id,schedule_type,schedule_status,schedule_group_id,room_id,days_json,time_start,time_end,created_by)
                        VALUES (?,?,'tba',?,NULL,'[]',NULL,NULL,?)");
                    $stmt->bind_param('isii', $offeringId, $type, $groupId, $createdBy);
                    if (!$stmt->execute()) { throw new RuntimeException('Unable to create a TBA meeting.'); }
                    $scheduleId = (int)$conn->insert_id;
                    $stmt->close();
                    $after = ['schedule_id'=>$scheduleId, 'offering_id'=>$offeringId, 'schedule_type'=>$type, 'schedule_status'=>'tba',
                        'schedule_group_id'=>$groupId, 'room_id'=>null, 'days_json'=>'[]', 'time_start'=>null, 'time_end'=>null];
                    synk_schedule_tba_record_event($conn, $scheduleId, $offeringId, $collegeId, [], $after);
                }
            } else {
                foreach ($change['rows'] as $before) {
                    $scheduleId = (int)$before['schedule_id'];
                    // Update in place: workload, enrollment and split-meeting load
                    // calculations continue to reference the original IDs/groups.
                    $stmt = $conn->prepare("UPDATE tbl_class_schedule SET schedule_status='tba',room_id=NULL,
                        days_json='[]',time_start=NULL,time_end=NULL WHERE schedule_id=? AND offering_id=?");
                    $stmt->bind_param('ii', $scheduleId, $offeringId);
                    if (!$stmt->execute() || $stmt->affected_rows !== 1) { throw new RuntimeException('Unable to update a TBA meeting.'); }
                    $stmt->close();
                    $after = array_merge($before, ['schedule_status'=>'tba', 'room_id'=>null, 'days_json'=>'[]', 'time_start'=>null, 'time_end'=>null]);
                    synk_schedule_tba_record_event($conn, $scheduleId, $offeringId, $collegeId, $before, $after);
                }
            }
            $stmt = $conn->prepare("UPDATE tbl_prospectus_offering SET status='pending'
                WHERE offering_id=? AND (status IS NULL OR status<>'locked')");
            $stmt->bind_param('i', $offeringId);
            if (!$stmt->execute()) { throw new RuntimeException('Unable to update the offering.'); }
            $stmt->close();
        }
        if (!$conn->commit()) { throw new RuntimeException('Unable to commit the TBA update.'); }
        unset($plan['changes']);
        return $plan;
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
