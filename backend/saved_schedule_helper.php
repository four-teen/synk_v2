<?php

require_once __DIR__ . '/schema_helper.php';

function synk_saved_schedule_tables_exist(mysqli $conn): bool
{
    foreach (['tbl_program_schedule_set', 'tbl_program_schedule_set_row'] as $tableName) {
        if (!synk_table_exists($conn, $tableName)) {
            return false;
        }
    }

    return true;
}

function synk_saved_schedule_workload_table_name(): string
{
    return 'tbl_program_schedule_set_workload_row';
}

function synk_saved_schedule_workload_table_exists(mysqli $conn): bool
{
    return synk_table_exists($conn, synk_saved_schedule_workload_table_name());
}

function synk_saved_schedule_workspace_state_table_name(): string
{
    return 'tbl_program_schedule_live_workspace_state';
}

function synk_saved_schedule_workspace_state_table_exists(mysqli $conn): bool
{
    return synk_table_exists($conn, synk_saved_schedule_workspace_state_table_name());
}

function synk_saved_schedule_scope_metadata(int $programId, string $programCode = '', string $programName = ''): array
{
    $programCode = trim($programCode);
    $programName = trim($programName);

    if ($programId <= 0) {
        return [
            'scope_key' => 'college',
            'scope_label' => 'College term',
        ];
    }

    $scopeParts = array_filter([$programCode, $programName], static function ($value): bool {
        return trim((string)$value) !== '';
    });

    return [
        'scope_key' => 'program',
        'scope_label' => !empty($scopeParts)
            ? 'Legacy program set: ' . implode(' - ', $scopeParts)
            : 'Legacy program set',
    ];
}

function synk_saved_schedule_load_workspace_state(mysqli $conn, int $collegeId, int $ayId, int $semester): ?array
{
    if (
        $collegeId <= 0 ||
        $ayId <= 0 ||
        $semester <= 0 ||
        !synk_table_exists($conn, 'tbl_program_schedule_set') ||
        !synk_saved_schedule_workspace_state_table_exists($conn)
    ) {
        return null;
    }

    $tableName = synk_saved_schedule_workspace_state_table_name();
    $sql = "
        SELECT
            ws.last_loaded_schedule_set_id,
            s.schedule_set_id,
            s.program_id,
            s.set_name,
            COALESCE(NULLIF(TRIM(p.program_code), ''), '') AS program_code,
            COALESCE(NULLIF(TRIM(p.program_name), ''), '') AS program_name
        FROM `{$tableName}` ws
        LEFT JOIN tbl_program_schedule_set s
            ON s.schedule_set_id = ws.last_loaded_schedule_set_id
           AND s.college_id = ws.college_id
           AND s.ay_id = ws.ay_id
           AND s.semester = ws.semester
        LEFT JOIN tbl_program p
            ON p.program_id = s.program_id
        WHERE ws.college_id = ?
          AND ws.ay_id = ?
          AND ws.semester = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!($stmt instanceof mysqli_stmt)) {
        return null;
    }

    $stmt->bind_param('iii', $collegeId, $ayId, $semester);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    $scheduleSetId = (int)($row['schedule_set_id'] ?? $row['last_loaded_schedule_set_id'] ?? 0);
    if ($scheduleSetId <= 0) {
        return null;
    }

    $scopeMeta = synk_saved_schedule_scope_metadata(
        (int)($row['program_id'] ?? 0),
        (string)($row['program_code'] ?? ''),
        (string)($row['program_name'] ?? '')
    );

    return [
        'schedule_set_id' => $scheduleSetId,
        'set_name' => trim((string)($row['set_name'] ?? '')),
        'scope_key' => (string)($scopeMeta['scope_key'] ?? 'college'),
        'scope_label' => (string)($scopeMeta['scope_label'] ?? 'College term'),
    ];
}
