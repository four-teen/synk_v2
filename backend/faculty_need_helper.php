<?php

require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/workload_audit_helper.php';

function synk_faculty_need_table_name(): string
{
    return 'tbl_faculty_need';
}

function synk_faculty_need_workload_table_name(): string
{
    return 'tbl_faculty_need_workload_sched';
}

function synk_faculty_need_ensure_tables(mysqli $conn): void
{
    $needTable = synk_faculty_need_table_name();
    $workloadTable = synk_faculty_need_workload_table_name();

    $conn->query("
        CREATE TABLE IF NOT EXISTS `{$needTable}` (
            `faculty_need_id` INT NOT NULL AUTO_INCREMENT,
            `college_id` INT(10) UNSIGNED NOT NULL,
            `ay_id` INT(10) UNSIGNED NOT NULL,
            `semester` TINYINT NOT NULL,
            `need_label` VARCHAR(120) NOT NULL,
            `notes` VARCHAR(255) NULL,
            `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
            `created_by` INT NULL,
            `date_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `date_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`faculty_need_id`),
            UNIQUE KEY `uniq_faculty_need_scope_label` (`college_id`, `ay_id`, `semester`, `need_label`),
            KEY `idx_faculty_need_scope_status` (`college_id`, `ay_id`, `semester`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS `{$workloadTable}` (
            `need_workload_id` INT NOT NULL AUTO_INCREMENT,
            `faculty_need_id` INT NOT NULL,
            `schedule_id` INT NOT NULL,
            `ay_id` INT(10) UNSIGNED NOT NULL,
            `semester` TINYINT NOT NULL,
            `date_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`need_workload_id`),
            UNIQUE KEY `uniq_need_schedule_term` (`schedule_id`, `ay_id`, `semester`),
            KEY `idx_need_workload_scope` (`faculty_need_id`, `ay_id`, `semester`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function synk_faculty_need_fetch_options(mysqli $conn, int $collegeId, int $ayId, int $semester): array
{
    if ($collegeId <= 0 || $ayId <= 0 || $semester <= 0) {
        return [];
    }

    synk_faculty_need_ensure_tables($conn);

    $stmt = $conn->prepare("
        SELECT faculty_need_id, need_label, notes
        FROM `" . synk_faculty_need_table_name() . "`
        WHERE college_id = ?
          AND ay_id = ?
          AND semester = ?
          AND status = 'active'
        ORDER BY faculty_need_id ASC
    ");

    if (!($stmt instanceof mysqli_stmt)) {
        return [];
    }

    $stmt->bind_param('iii', $collegeId, $ayId, $semester);
    $stmt->execute();
    $result = $stmt->get_result();
    $needs = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $needs[] = [
                'faculty_need_id' => (int)($row['faculty_need_id'] ?? 0),
                'need_label' => trim((string)($row['need_label'] ?? '')),
                'notes' => trim((string)($row['notes'] ?? '')),
            ];
        }
    }

    $stmt->close();
    return $needs;
}

function synk_faculty_need_find(mysqli $conn, int $collegeId, int $ayId, int $semester, int $facultyNeedId): ?array
{
    if ($collegeId <= 0 || $ayId <= 0 || $semester <= 0 || $facultyNeedId <= 0) {
        return null;
    }

    synk_faculty_need_ensure_tables($conn);

    $stmt = $conn->prepare("
        SELECT faculty_need_id, college_id, ay_id, semester, need_label, notes
        FROM `" . synk_faculty_need_table_name() . "`
        WHERE faculty_need_id = ?
          AND college_id = ?
          AND ay_id = ?
          AND semester = ?
          AND status = 'active'
        LIMIT 1
    ");

    if (!($stmt instanceof mysqli_stmt)) {
        return null;
    }

    $stmt->bind_param('iiii', $facultyNeedId, $collegeId, $ayId, $semester);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = ($result instanceof mysqli_result) ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    return [
        'faculty_need_id' => (int)($row['faculty_need_id'] ?? 0),
        'college_id' => (int)($row['college_id'] ?? 0),
        'ay_id' => (int)($row['ay_id'] ?? 0),
        'semester' => (int)($row['semester'] ?? 0),
        'need_label' => trim((string)($row['need_label'] ?? '')),
        'notes' => trim((string)($row['notes'] ?? '')),
    ];
}

function synk_faculty_need_create_next(
    mysqli $conn,
    int $collegeId,
    int $ayId,
    int $semester,
    int $createdBy = 0
): ?array {
    if ($collegeId <= 0 || $ayId <= 0 || $semester <= 0) {
        return null;
    }

    synk_faculty_need_ensure_tables($conn);

    $highest = 0;
    $stmt = $conn->prepare("
        SELECT need_label
        FROM `" . synk_faculty_need_table_name() . "`
        WHERE college_id = ?
          AND ay_id = ?
          AND semester = ?
        ORDER BY faculty_need_id ASC
    ");

    if ($stmt instanceof mysqli_stmt) {
        $stmt->bind_param('iii', $collegeId, $ayId, $semester);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                if (preg_match('/^FACULTY NEED\s+(\d+)$/i', (string)($row['need_label'] ?? ''), $matches)) {
                    $highest = max($highest, (int)$matches[1]);
                }
            }
        }

        $stmt->close();
    }

    $label = 'FACULTY NEED ' . ($highest + 1);
    $stmt = $conn->prepare("
        INSERT INTO `" . synk_faculty_need_table_name() . "` (
            college_id,
            ay_id,
            semester,
            need_label,
            created_by
        ) VALUES (?, ?, ?, ?, ?)
    ");

    if (!($stmt instanceof mysqli_stmt)) {
        return null;
    }

    $stmt->bind_param('iiisi', $collegeId, $ayId, $semester, $label, $createdBy);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $facultyNeedId = (int)$stmt->insert_id;
    $stmt->close();

    return synk_faculty_need_find($conn, $collegeId, $ayId, $semester, $facultyNeedId);
}

function synk_faculty_need_delete(
    mysqli $conn,
    int $collegeId,
    int $ayId,
    int $semester,
    int $facultyNeedId,
    int $actorUserId = 0
): ?array {
    if ($collegeId <= 0 || $ayId <= 0 || $semester <= 0 || $facultyNeedId <= 0) {
        return null;
    }

    synk_faculty_need_ensure_tables($conn);

    $need = synk_faculty_need_find($conn, $collegeId, $ayId, $semester, $facultyNeedId);
    if (!is_array($need)) {
        return null;
    }

    $workloadDeletedCount = 0;
    $auditWorkloadRows = [];
    $auditNeedWorkloadIds = [];
    $auditScheduleIds = [];
    if ($actorUserId > 0) {
        $auditIdStmt = $conn->prepare("
            SELECT need_workload_id
            FROM `" . synk_faculty_need_workload_table_name() . "`
            WHERE faculty_need_id = ?
        ");

        if ($auditIdStmt instanceof mysqli_stmt) {
            $auditIdStmt->bind_param('i', $facultyNeedId);
            $auditIdStmt->execute();
            $auditIdResult = $auditIdStmt->get_result();

            if ($auditIdResult instanceof mysqli_result) {
                while ($auditIdRow = $auditIdResult->fetch_assoc()) {
                    $needWorkloadId = (int)($auditIdRow['need_workload_id'] ?? 0);
                    if ($needWorkloadId > 0) {
                        $auditNeedWorkloadIds[] = $needWorkloadId;
                    }
                }
            }

            $auditIdStmt->close();
        }

        if (!empty($auditNeedWorkloadIds)) {
            $auditWorkloadRows = synk_workload_audit_fetch_workload_rows($conn, 'faculty_need', $auditNeedWorkloadIds);
            foreach ($auditWorkloadRows as $auditRow) {
                $scheduleId = (int)($auditRow['schedule_id'] ?? 0);
                if ($scheduleId > 0) {
                    $auditScheduleIds[] = $scheduleId;
                }
            }
        }
    }

    $conn->begin_transaction();

    $deleteWorkloadStmt = $conn->prepare("
        DELETE FROM `" . synk_faculty_need_workload_table_name() . "`
        WHERE faculty_need_id = ?
    ");

    if (!($deleteWorkloadStmt instanceof mysqli_stmt)) {
        $conn->rollback();
        return null;
    }

    $deleteWorkloadStmt->bind_param('i', $facultyNeedId);
    if (!$deleteWorkloadStmt->execute()) {
        $deleteWorkloadStmt->close();
        $conn->rollback();
        return null;
    }

    $workloadDeletedCount = (int)$deleteWorkloadStmt->affected_rows;
    $deleteWorkloadStmt->close();

    $deleteNeedStmt = $conn->prepare("
        DELETE FROM `" . synk_faculty_need_table_name() . "`
        WHERE faculty_need_id = ?
          AND college_id = ?
          AND ay_id = ?
          AND semester = ?
        LIMIT 1
    ");

    if (!($deleteNeedStmt instanceof mysqli_stmt)) {
        $conn->rollback();
        return null;
    }

    $deleteNeedStmt->bind_param('iiii', $facultyNeedId, $collegeId, $ayId, $semester);
    if (!$deleteNeedStmt->execute() || (int)$deleteNeedStmt->affected_rows < 1) {
        $deleteNeedStmt->close();
        $conn->rollback();
        return null;
    }

    $deleteNeedStmt->close();

    if (!$conn->commit()) {
        $conn->rollback();
        return null;
    }

    if ($actorUserId > 0) {
        foreach ($auditWorkloadRows as $auditRow) {
            synk_workload_audit_record_workload_event($conn, 'faculty_need_workload_delete', $auditRow, [
                'deleted_with_faculty_need_id' => $facultyNeedId,
                'deleted_workload_count' => $workloadDeletedCount,
            ]);
        }

        synk_workload_audit_record($conn, 'faculty_need_delete', [
            'college_id' => $collegeId,
            'ay_id' => $ayId,
            'semester' => $semester,
            'assignee_type' => 'faculty_need',
            'faculty_need_id' => $facultyNeedId,
            'entity_type' => 'faculty_need',
            'entity_id' => $facultyNeedId,
            'affected_count' => max(1, 1 + $workloadDeletedCount),
            'details' => [
                'faculty_need_id' => $facultyNeedId,
                'need_label' => (string)($need['need_label'] ?? ''),
                'deleted_workload_count' => $workloadDeletedCount,
                'need_workload_ids' => array_values(array_unique($auditNeedWorkloadIds)),
                'schedule_ids' => array_values(array_unique($auditScheduleIds)),
            ],
        ]);
    }

    return [
        'faculty_need_id' => $facultyNeedId,
        'need_label' => (string)($need['need_label'] ?? ''),
        'deleted_workload_count' => $workloadDeletedCount,
    ];
}
