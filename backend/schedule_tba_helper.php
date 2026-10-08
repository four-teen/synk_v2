<?php

require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/schedule_block_helper.php';

function synk_schedule_status(array $row): string
{
    if (isset($row['schedule_status']) && in_array($row['schedule_status'], ['scheduled', 'tba', 'room_tba'], true)) {
        return $row['schedule_status'];
    }
    // Readers of older snapshots can infer pending state from genuinely absent values.
    $days = $row['days'] ?? ($row['days_arr'] ?? json_decode((string)($row['days_json'] ?? '[]'), true));
    if (empty($days) && trim((string)($row['time_start'] ?? '')) === '' && trim((string)($row['time_end'] ?? '')) === '') {
        return 'tba';
    }
    return array_key_exists('room_id', $row) && $row['room_id'] === null ? 'room_tba' : 'scheduled';
}

function synk_schedule_time_label($start, $end, string $format = 'g:iA', string $separator = '-'): string
{
    if (trim((string)$start) === '' || trim((string)$end) === '') {
        return 'TBA';
    }
    return date($format, strtotime((string)$start)) . $separator . date($format, strtotime((string)$end));
}

function synk_schedule_tba_schema_ready(mysqli $conn): bool
{
    return synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_status')
        && synk_table_exists($conn, 'tbl_class_schedule_tba_event');
}

function synk_schedule_tba_has_assigned_need(mysqli $conn, array $offeringIds, int $ayId, int $semester): bool
{
    $ids=array_values(array_filter(array_unique(array_map('intval',$offeringIds)),static function(int $id):bool{return $id>0;}));
    if (!$ids || !synk_table_exists($conn,'tbl_faculty_need_workload_sched')) { return false; }
    $result=$conn->query("SELECT 1 FROM tbl_class_schedule cs JOIN tbl_faculty_need_workload_sched nw ON nw.schedule_id=cs.schedule_id
        WHERE cs.offering_id IN (" . implode(',',$ids) . ") AND cs.schedule_status<>'scheduled' AND nw.ay_id={$ayId} AND nw.semester={$semester} LIMIT 1");
    return $result && $result->num_rows>0;
}

// Scheduling and assignment must see the same state while a TBA class is resolved.
function synk_schedule_acquire_write_lock(mysqli $conn): void
{
    static $held = false;
    if ($held) {
        return;
    }
    $result = $conn->query("SELECT GET_LOCK('synk_schedule_workload_write_v1', 10) AS acquired");
    $row = $result ? $result->fetch_assoc() : null;
    if (!$row || (int)$row['acquired'] !== 1) {
        throw new RuntimeException('Another schedule update is in progress. Please try again.');
    }
    $held = true;
    register_shutdown_function(static function () use ($conn): void {
        try {
            $conn->query("SELECT RELEASE_LOCK('synk_schedule_workload_write_v1')");
        } catch (Throwable $ignored) {
            // Closing the connection also releases a MySQL advisory lock.
        }
    });
}

function synk_schedule_tba_record_event(mysqli $conn, int $scheduleId, int $offeringId, int $collegeId, array $before, array $after): void
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $oldStatus = $before ? synk_schedule_status($before) : '';
    $newStatus = synk_schedule_status($after);
    $beforeJson = json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $afterJson = json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $conn->prepare('INSERT INTO tbl_class_schedule_tba_event
        (schedule_id,offering_id,college_id,changed_by,previous_status,new_status,previous_values,new_values)
        VALUES (?,?,?,?,?,?,?,?)');
    $stmt->bind_param('iiiissss', $scheduleId, $offeringId, $collegeId, $userId, $oldStatus, $newStatus, $beforeJson, $afterJson);
    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to record the TBA schedule change.');
    }
    $stmt->close();
}
