<?php
session_start();
include 'db.php';
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/saved_schedule_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    http_response_code(403);
    echo json_encode([]);
    exit;
}

$role = (string)$_SESSION['role'];
$myCollege = (int)($_SESSION['college_id'] ?? 0);

$writeActions = ['update_prospectus_header', 'transfer_prospectus', 'delete_prospectus'];
foreach ($writeActions as $action) {
    if (!isset($_POST[$action])) {
        continue;
    }

    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    if (
        empty($_SESSION['csrf_token']) ||
        $csrfToken === '' ||
        !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)
    ) {
        echo "ERROR|CSRF validation failed.";
        exit;
    }

    if ($role !== 'admin') {
        echo "ERROR|Administrator access required.";
        exit;
    }
}

function can_access_browser_program(mysqli $conn, int $programId, string $role, int $myCollege): bool
{
    if ($programId <= 0) {
        return false;
    }

    if ($role === 'admin') {
        return true;
    }

    $stmt = $conn->prepare("
        SELECT program_id
        FROM tbl_program
        WHERE program_id = ?
          AND college_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $programId, $myCollege);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $allowed;
}

function can_access_browser_prospectus(mysqli $conn, int $prospectusId, string $role, int $myCollege): bool
{
    if ($prospectusId <= 0) {
        return false;
    }

    if ($role === 'admin') {
        return true;
    }

    $stmt = $conn->prepare("
        SELECT h.prospectus_id
        FROM tbl_prospectus_header h
        INNER JOIN tbl_program p
            ON p.program_id = h.program_id
        WHERE h.prospectus_id = ?
          AND p.college_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $prospectusId, $myCollege);
    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $allowed;
}

function synk_delete_rows_by_id_list(mysqli $conn, string $tableName, string $columnName, array $ids): int
{
    $normalized = [];
    foreach ($ids as $id) {
        $value = (int)$id;
        if ($value > 0) {
            $normalized[$value] = $value;
        }
    }

    if (empty($normalized)) {
        return 0;
    }

    $idList = implode(',', array_map('intval', array_values($normalized)));
    $sql = "DELETE FROM `{$tableName}` WHERE `{$columnName}` IN ({$idList})";

    if (!$conn->query($sql)) {
        throw new RuntimeException("Failed to delete related rows from {$tableName}.");
    }

    return max(0, (int)$conn->affected_rows);
}

if (isset($_POST['load_prospectus_by_program'])) {
    $programId = (int)($_POST['program_id'] ?? 0);
    if ($programId <= 0 || !can_access_browser_program($conn, $programId, $role, $myCollege)) {
        header('Content-Type: application/json');
        echo json_encode([]);
        exit;
    }

    $sql = "
        SELECT
            h.prospectus_id,
            h.program_id,
            h.cmo_no,
            h.effective_sy,
            COUNT(DISTINCT pys.pys_id) AS yearsem_count,
            COUNT(ps.ps_id) AS subject_count,
            ROUND(COALESCE(SUM(ps.total_units), 0), 2) AS total_units
        FROM tbl_prospectus_header h
        LEFT JOIN tbl_prospectus_year_sem pys
            ON pys.prospectus_id = h.prospectus_id
        LEFT JOIN tbl_prospectus_subjects ps
            ON ps.pys_id = pys.pys_id
        WHERE h.program_id = ?
        GROUP BY h.prospectus_id, h.program_id, h.cmo_no, h.effective_sy
        ORDER BY h.prospectus_id DESC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $programId);
    $stmt->execute();
    $res = $stmt->get_result();

    $data = [];
    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }
    $stmt->close();

    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if (isset($_POST['update_prospectus_header'])) {
    $prospectusId = (int)($_POST['prospectus_id'] ?? 0);
    $cmoNo = trim((string)($_POST['cmo_no'] ?? ''));
    $effectiveSy = trim((string)($_POST['effective_sy'] ?? ''));

    if ($prospectusId <= 0 || $cmoNo === '' || $effectiveSy === '') {
        echo "ERROR|CMO number and effectivity are required.";
        exit;
    }

    if (strpos($cmoNo, '|') !== false || strpos($effectiveSy, '|') !== false) {
        echo "ERROR|Pipe character (|) is not allowed.";
        exit;
    }

    if (!can_access_browser_prospectus($conn, $prospectusId, $role, $myCollege)) {
        echo "ERROR|Unauthorized prospectus access.";
        exit;
    }

    $headerStmt = $conn->prepare("
        SELECT program_id
        FROM tbl_prospectus_header
        WHERE prospectus_id = ?
        LIMIT 1
    ");
    $headerStmt->bind_param("i", $prospectusId);
    $headerStmt->execute();
    $headerStmt->bind_result($programId);

    if (!$headerStmt->fetch()) {
        $headerStmt->close();
        echo "ERROR|Prospectus not found.";
        exit;
    }
    $headerStmt->close();

    $duplicateStmt = $conn->prepare("
        SELECT prospectus_id
        FROM tbl_prospectus_header
        WHERE program_id = ?
          AND cmo_no = ?
          AND effective_sy = ?
          AND prospectus_id <> ?
        LIMIT 1
    ");
    $duplicateStmt->bind_param("issi", $programId, $cmoNo, $effectiveSy, $prospectusId);
    $duplicateStmt->execute();
    $duplicateExists = $duplicateStmt->get_result()->num_rows > 0;
    $duplicateStmt->close();

    if ($duplicateExists) {
        echo "ERROR|Another prospectus in this program already uses that CMO and effectivity.";
        exit;
    }

    $updateStmt = $conn->prepare("
        UPDATE tbl_prospectus_header
        SET cmo_no = ?, effective_sy = ?
        WHERE prospectus_id = ?
        LIMIT 1
    ");
    $updateStmt->bind_param("ssi", $cmoNo, $effectiveSy, $prospectusId);

    if (!$updateStmt->execute()) {
        $error = $updateStmt->error;
        $updateStmt->close();
        echo "ERROR|Failed to update prospectus header: {$error}";
        exit;
    }

    $updateStmt->close();
    echo "OK|Prospectus header updated successfully.";
    exit;
}

if (isset($_POST['transfer_prospectus'])) {
    $prospectusId = (int)($_POST['prospectus_id'] ?? 0);
    $targetProgramId = (int)($_POST['target_program_id'] ?? 0);

    if ($prospectusId <= 0 || $targetProgramId <= 0) {
        echo "ERROR|Target program is required.";
        exit;
    }

    if (!can_access_browser_prospectus($conn, $prospectusId, $role, $myCollege)) {
        echo "ERROR|Unauthorized prospectus access.";
        exit;
    }

    $headerStmt = $conn->prepare("
        SELECT program_id, cmo_no, effective_sy
        FROM tbl_prospectus_header
        WHERE prospectus_id = ?
        LIMIT 1
    ");
    $headerStmt->bind_param("i", $prospectusId);
    $headerStmt->execute();
    $headerStmt->bind_result($currentProgramId, $cmoNo, $effectiveSy);

    if (!$headerStmt->fetch()) {
        $headerStmt->close();
        echo "ERROR|Prospectus not found.";
        exit;
    }
    $headerStmt->close();

    if ($currentProgramId === $targetProgramId) {
        echo "ERROR|Select a different target program.";
        exit;
    }

    $targetExistsStmt = $conn->prepare("
        SELECT program_id
        FROM tbl_program
        WHERE program_id = ?
          AND status = 'active'
        LIMIT 1
    ");
    $targetExistsStmt->bind_param("i", $targetProgramId);
    $targetExistsStmt->execute();
    $targetExists = $targetExistsStmt->get_result()->num_rows > 0;
    $targetExistsStmt->close();

    if (!$targetExists) {
        echo "ERROR|Target program not found.";
        exit;
    }

    $duplicateStmt = $conn->prepare("
        SELECT prospectus_id
        FROM tbl_prospectus_header
        WHERE program_id = ?
          AND cmo_no = ?
          AND effective_sy = ?
          AND prospectus_id <> ?
        LIMIT 1
    ");
    $duplicateStmt->bind_param("issi", $targetProgramId, $cmoNo, $effectiveSy, $prospectusId);
    $duplicateStmt->execute();
    $duplicateExists = $duplicateStmt->get_result()->num_rows > 0;
    $duplicateStmt->close();

    if ($duplicateExists) {
        echo "ERROR|Target program already has this CMO and effectivity.";
        exit;
    }

    $transferStmt = $conn->prepare("
        UPDATE tbl_prospectus_header
        SET program_id = ?
        WHERE prospectus_id = ?
        LIMIT 1
    ");
    $transferStmt->bind_param("ii", $targetProgramId, $prospectusId);

    if (!$transferStmt->execute()) {
        $error = $transferStmt->error;
        $transferStmt->close();
        echo "ERROR|Failed to transfer prospectus: {$error}";
        exit;
    }

    $transferStmt->close();
    echo "OK|Prospectus transferred successfully.";
    exit;
}

if (isset($_POST['delete_prospectus'])) {
    $prospectusId = (int)($_POST['prospectus_id'] ?? 0);

    if ($prospectusId <= 0) {
        echo "ERROR|Invalid prospectus reference.";
        exit;
    }

    if (!can_access_browser_prospectus($conn, $prospectusId, $role, $myCollege)) {
        echo "ERROR|Unauthorized prospectus access.";
        exit;
    }

    $headerStmt = $conn->prepare("
        SELECT cmo_no, effective_sy
        FROM tbl_prospectus_header
        WHERE prospectus_id = ?
        LIMIT 1
    ");
    $headerStmt->bind_param("i", $prospectusId);
    $headerStmt->execute();
    $header = $headerStmt->get_result()->fetch_assoc();
    $headerStmt->close();

    if (!$header) {
        echo "ERROR|Prospectus not found.";
        exit;
    }

    $conn->begin_transaction();

    try {
        $offeringIds = [];
        if (synk_table_exists($conn, 'tbl_prospectus_offering')) {
            $offeringStmt = $conn->prepare("
                SELECT offering_id
                FROM tbl_prospectus_offering
                WHERE prospectus_id = ?
            ");
            if (!$offeringStmt) {
                throw new RuntimeException('Failed to inspect prospectus offerings.');
            }

            $offeringStmt->bind_param("i", $prospectusId);
            $offeringStmt->execute();
            $offeringRes = $offeringStmt->get_result();

            while ($row = $offeringRes->fetch_assoc()) {
                $offeringId = (int)($row['offering_id'] ?? 0);
                if ($offeringId > 0) {
                    $offeringIds[$offeringId] = $offeringId;
                }
            }
            $offeringStmt->close();
        }

        $scheduleIds = [];
        if (!empty($offeringIds) && synk_table_exists($conn, 'tbl_class_schedule')) {
            $offeringIdList = implode(',', array_map('intval', array_values($offeringIds)));
            $scheduleRes = $conn->query("
                SELECT schedule_id
                FROM tbl_class_schedule
                WHERE offering_id IN ({$offeringIdList})
            ");
            if ($scheduleRes === false) {
                throw new RuntimeException('Failed to inspect class schedules.');
            }

            while ($row = $scheduleRes->fetch_assoc()) {
                $scheduleId = (int)($row['schedule_id'] ?? 0);
                if ($scheduleId > 0) {
                    $scheduleIds[$scheduleId] = $scheduleId;
                }
            }
        }

        if (!empty($offeringIds) && synk_saved_schedule_tables_exist($conn)) {
            $scheduleSetIds = [];
            $offeringIdList = implode(',', array_map('intval', array_values($offeringIds)));
            $setStmt = $conn->prepare("
                SELECT DISTINCT schedule_set_id
                FROM tbl_program_schedule_set_row
                WHERE offering_id IN ({$offeringIdList})
            ");
            if (!$setStmt) {
                throw new RuntimeException('Failed to inspect saved schedule sets.');
            }

            $setStmt->execute();
            $setRes = $setStmt->get_result();

            while ($row = $setRes->fetch_assoc()) {
                $scheduleSetId = (int)($row['schedule_set_id'] ?? 0);
                if ($scheduleSetId > 0) {
                    $scheduleSetIds[$scheduleSetId] = $scheduleSetId;
                }
            }
            $setStmt->close();

            if (!empty($scheduleSetIds)) {
                if (synk_saved_schedule_workspace_state_table_exists($conn)) {
                    $workspaceTable = synk_saved_schedule_workspace_state_table_name();
                    $scheduleSetIdList = implode(',', array_map('intval', array_values($scheduleSetIds)));
                    if (!$conn->query("
                        UPDATE `{$workspaceTable}`
                        SET last_loaded_schedule_set_id = NULL
                        WHERE last_loaded_schedule_set_id IN ({$scheduleSetIdList})
                    ")) {
                        throw new RuntimeException('Failed to clear loaded saved schedule state.');
                    }
                }

                if (synk_saved_schedule_workload_table_exists($conn)) {
                    synk_delete_rows_by_id_list(
                        $conn,
                        synk_saved_schedule_workload_table_name(),
                        'schedule_set_id',
                        array_values($scheduleSetIds)
                    );
                }

                synk_delete_rows_by_id_list($conn, 'tbl_program_schedule_set_row', 'schedule_set_id', array_values($scheduleSetIds));
                synk_delete_rows_by_id_list($conn, 'tbl_program_schedule_set', 'schedule_set_id', array_values($scheduleSetIds));
            }
        }

        if (!empty($scheduleIds) && synk_table_exists($conn, 'tbl_faculty_workload_sched')) {
            synk_delete_rows_by_id_list($conn, 'tbl_faculty_workload_sched', 'schedule_id', array_values($scheduleIds));
        }

        if (!empty($scheduleIds) && synk_table_exists($conn, 'tbl_faculty_need_workload_sched')) {
            synk_delete_rows_by_id_list($conn, 'tbl_faculty_need_workload_sched', 'schedule_id', array_values($scheduleIds));
        }

        if (synk_table_exists($conn, 'tbl_faculty_workload_simulation')) {
            if (!empty($scheduleIds)) {
                synk_delete_rows_by_id_list($conn, 'tbl_faculty_workload_simulation', 'schedule_id', array_values($scheduleIds));
            }
            if (!empty($offeringIds)) {
                synk_delete_rows_by_id_list($conn, 'tbl_faculty_workload_simulation', 'offering_id', array_values($offeringIds));
            }
        }

        if (!empty($offeringIds) && synk_table_exists($conn, 'tbl_class_schedule_merge')) {
            $offeringIdList = implode(',', array_map('intval', array_values($offeringIds)));
            if (!$conn->query("
                DELETE FROM tbl_class_schedule_merge
                WHERE owner_offering_id IN ({$offeringIdList})
                   OR member_offering_id IN ({$offeringIdList})
            ")) {
                throw new RuntimeException('Failed to delete merge links.');
            }
        }

        if (!empty($offeringIds) && synk_table_exists($conn, 'tbl_offering_enrollee_counts')) {
            synk_delete_rows_by_id_list($conn, 'tbl_offering_enrollee_counts', 'offering_id', array_values($offeringIds));
        }

        if (!empty($offeringIds) && synk_table_exists($conn, 'tbl_class_schedule')) {
            synk_delete_rows_by_id_list($conn, 'tbl_class_schedule', 'offering_id', array_values($offeringIds));
        }

        if (synk_table_exists($conn, 'tbl_prospectus_offering')) {
            $deleteOfferingStmt = $conn->prepare("
                DELETE FROM tbl_prospectus_offering
                WHERE prospectus_id = ?
            ");
            if (!$deleteOfferingStmt) {
                throw new RuntimeException('Failed to delete generated prospectus offerings.');
            }

            $deleteOfferingStmt->bind_param("i", $prospectusId);
            if (!$deleteOfferingStmt->execute()) {
                $deleteOfferingStmt->close();
                throw new RuntimeException('Failed to delete generated prospectus offerings.');
            }
            $deleteOfferingStmt->close();
        }

        $deleteHeaderStmt = $conn->prepare("
            DELETE FROM tbl_prospectus_header
            WHERE prospectus_id = ?
            LIMIT 1
        ");
        if (!$deleteHeaderStmt) {
            throw new RuntimeException('Failed to delete prospectus header.');
        }

        $deleteHeaderStmt->bind_param("i", $prospectusId);
        if (!$deleteHeaderStmt->execute()) {
            $deleteHeaderStmt->close();
            throw new RuntimeException('Failed to delete prospectus header.');
        }

        if ($deleteHeaderStmt->affected_rows < 1) {
            $deleteHeaderStmt->close();
            throw new RuntimeException('Prospectus was not deleted.');
        }
        $deleteHeaderStmt->close();

        $conn->commit();
        echo "OK|Prospectus removed successfully.|" . trim((string)$header['cmo_no']) . "|" . trim((string)$header['effective_sy']);
    } catch (Throwable $e) {
        $conn->rollback();
        echo "ERROR|" . $e->getMessage();
    }
    exit;
}

header('Content-Type: application/json');
echo json_encode([]);
