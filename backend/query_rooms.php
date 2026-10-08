<?php
session_start();
ob_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'scheduler') {
    echo "unauthorized";
    exit;
}

$college_id = (int)($_SESSION['college_id'] ?? 0);
if ($college_id <= 0) {
    echo "unauthorized";
    exit;
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    echo "csrf_failed";
    exit;
}

function has_room_access_table($conn) {
    $q = $conn->query("SHOW TABLES LIKE 'tbl_room_college_access'");
    return $q && $q->num_rows > 0;
}

function has_room_term_columns($conn) {
    $ay = $conn->query("SHOW COLUMNS FROM tbl_rooms LIKE 'ay_id'");
    $sem = $conn->query("SHOW COLUMNS FROM tbl_rooms LIKE 'semester'");
    return ($ay && $ay->num_rows > 0) && ($sem && $sem->num_rows > 0);
}

function normalize_room_type($t) {
    $v = strtolower(trim((string)$t));
    if (!in_array($v, ['lecture', 'laboratory', 'lec_lab'], true)) {
        return 'lecture';
    }
    return $v;
}

function term_inputs_valid() {
    $ay_id = (int)($_POST['ay_id'] ?? 0);
    $semester = (int)($_POST['semester'] ?? 0);
    if ($ay_id <= 0 || !in_array($semester, [1,2,3], true)) {
        return [0,0,false];
    }
    return [$ay_id, $semester, true];
}

function get_college_campus_id($conn, $college_id) {
    $stmt = $conn->prepare("SELECT campus_id FROM tbl_college WHERE college_id = ? LIMIT 1");
    $stmt->bind_param("i", $college_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ? (int)$row['campus_id'] : 0;
}

function sanitize_shared_college_ids($conn, $incoming, $owner_college_id, $campus_id) {
    $valid = [];
    $seen = [];

    $check = $conn->prepare("SELECT 1 FROM tbl_college WHERE college_id = ? AND campus_id = ? AND status = 'active' LIMIT 1");
    foreach ((array)$incoming as $c) {
        $sharedCollegeId = (int)$c;
        if ($sharedCollegeId <= 0 || $sharedCollegeId === $owner_college_id || isset($seen[$sharedCollegeId])) {
            continue;
        }

        $seen[$sharedCollegeId] = true;
        $check->bind_param("ii", $sharedCollegeId, $campus_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $valid[] = $sharedCollegeId;
        }
    }

    return $valid;
}

$campus_id = get_college_campus_id($conn, $college_id);
if ($campus_id <= 0) {
    echo "unauthorized";
    exit;
}
$room_term_columns = has_room_term_columns($conn);

if (!has_room_access_table($conn)) {
    if (isset($_POST['load_rooms'])) {
        echo "schema_missing";
        exit;
    }
    if (isset($_POST['copy_rooms_from_term'])) {
        echo json_encode([
            'status' => 'schema_missing',
            'message' => 'Run backend/sql/phase3_room_term_sharing.sql first.',
        ]);
        exit;
    }
    echo "schema_missing";
    exit;
}

if (isset($_POST['copy_rooms_from_term'])) {
    [$target_ay_id, $target_semester, $target_ok] = term_inputs_valid();
    $source_ay_id = (int)($_POST['source_ay_id'] ?? 0);
    $source_semester = (int)($_POST['source_semester'] ?? 0);

    if (!$target_ok || $source_ay_id <= 0 || !in_array($source_semester, [1, 2, 3], true)) {
        echo json_encode([
            'status' => 'invalid_term',
            'message' => 'Select both source and target academic terms.',
        ]);
        exit;
    }

    if ($source_ay_id === $target_ay_id && $source_semester === $target_semester) {
        echo json_encode([
            'status' => 'same_term',
            'message' => 'Source and target terms must be different.',
        ]);
        exit;
    }

    $sourceSql = "
        SELECT DISTINCT
            src.room_id,
            r.room_code,
            CASE
                WHEN EXISTS (
                    SELECT 1
                    FROM tbl_room_college_access target
                    WHERE target.room_id = src.room_id
                      AND target.college_id = ?
                      AND target.ay_id = ?
                      AND target.semester = ?
                      AND target.access_type = 'owner'
                ) THEN 1
                ELSE 0
            END AS exists_in_target
        FROM tbl_room_college_access src
        INNER JOIN tbl_rooms r ON r.room_id = src.room_id
        WHERE src.college_id = ?
          AND src.ay_id = ?
          AND src.semester = ?
          AND src.access_type = 'owner'
          AND (r.status IS NULL OR r.status = 'active')
        ORDER BY r.room_code ASC, src.room_id ASC
    ";

    $sourceStmt = $conn->prepare($sourceSql);
    if (!($sourceStmt instanceof mysqli_stmt)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Unable to prepare room copy query.',
        ]);
        exit;
    }

    $sourceStmt->bind_param(
        "iiiiii",
        $college_id,
        $target_ay_id,
        $target_semester,
        $college_id,
        $source_ay_id,
        $source_semester
    );
    $sourceStmt->execute();
    $sourceRes = $sourceStmt->get_result();

    $roomIdsToCopy = [];
    $sourceCount = 0;
    $skippedCount = 0;

    while ($row = $sourceRes->fetch_assoc()) {
        $sourceCount++;
        if ((int)($row['exists_in_target'] ?? 0) === 1) {
            $skippedCount++;
            continue;
        }

        $roomIdsToCopy[] = (int)$row['room_id'];
    }
    $sourceStmt->close();

    if ($sourceCount === 0) {
        echo json_encode([
            'status' => 'source_empty',
            'message' => 'No owner rooms were found in the selected source term.',
            'copied_count' => 0,
            'skipped_count' => 0,
        ]);
        exit;
    }

    $copiedCount = 0;

    $conn->begin_transaction();
    try {
        $insertOwner = $conn->prepare("
            INSERT INTO tbl_room_college_access (room_id, college_id, ay_id, semester, access_type)
            VALUES (?, ?, ?, ?, 'owner')
            ON DUPLICATE KEY UPDATE access_type = 'owner'
        ");

        if (!($insertOwner instanceof mysqli_stmt)) {
            throw new RuntimeException('Unable to prepare room copy insert.');
        }

        foreach ($roomIdsToCopy as $roomIdToCopy) {
            $insertOwner->bind_param("iiii", $roomIdToCopy, $college_id, $target_ay_id, $target_semester);
            $insertOwner->execute();
            $copiedCount++;
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        echo json_encode([
            'status' => 'error',
            'message' => 'Unable to copy rooms into the selected term.',
        ]);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Rooms copied successfully.',
        'copied_count' => $copiedCount,
        'skipped_count' => $skippedCount,
        'source_count' => $sourceCount,
        'sharing_copied' => false,
    ]);
    exit;
}

if (isset($_POST['load_rooms'])) {
    [$ay_id, $semester, $ok] = term_inputs_valid();
    if (!$ok) {
        echo "missing_term";
        exit;
    }

    $sql = "
        SELECT
            r.room_id,
            r.room_code,
            r.room_name,
            r.room_type,
            r.capacity,
            r.college_id AS owner_college_id,
            owner.college_code AS owner_college_code,
            owner.college_name AS owner_college_name,
            acc.access_type,
            COALESCE(shared.shared_ids, '') AS shared_ids,
            COALESCE(shared.shared_codes, '') AS shared_codes
        FROM tbl_room_college_access acc
        JOIN tbl_rooms r ON r.room_id = acc.room_id
        JOIN tbl_college owner ON owner.college_id = r.college_id
        LEFT JOIN (
            SELECT
                a.room_id,
                GROUP_CONCAT(a.college_id ORDER BY a.college_id SEPARATOR ',') AS shared_ids,
                GROUP_CONCAT(c.college_code ORDER BY c.college_code SEPARATOR ', ') AS shared_codes
            FROM tbl_room_college_access a
            JOIN tbl_college c ON c.college_id = a.college_id
            WHERE ay_id = ?
              AND semester = ?
              AND access_type = 'shared'
            GROUP BY a.room_id
        ) shared ON shared.room_id = r.room_id
        WHERE acc.college_id = ?
          AND acc.ay_id = ?
          AND acc.semester = ?
          AND (r.status IS NULL OR r.status = 'active')
        ORDER BY r.room_code ASC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iiiii", $ay_id, $semester, $college_id, $ay_id, $semester);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = "";
    $i = 1;
    while ($r = $res->fetch_assoc()) {
        $type_label = [
            "lecture" => "Lecture",
            "laboratory" => "Laboratory",
            "lec_lab" => "Lecture-Laboratory"
        ][strtolower((string)$r['room_type'])] ?? ucfirst((string)$r['room_type']);

        $accessType = strtolower((string)$r['access_type']);
        $sharedCodes = trim((string)$r['shared_codes']);
        $isShared = ($accessType === 'shared') || ($sharedCodes !== '');
        $accessBadge = $isShared
            ? "<span class='badge bg-label-info text-info'>Shared</span>"
            : "<span class='badge bg-primary'>Owner</span>";
        $sharedWithHtml = $sharedCodes !== ''
            ? htmlspecialchars($sharedCodes)
            : "<span class='text-muted'>-</span>";

        $canManage = ((int)$r['owner_college_id'] === $college_id);

        $actionHtml = "<span class='text-muted small'>View only</span>";
        if ($canManage) {
            $actionHtml = "
                <button class='btn btn-sm btn-info btnEdit'
                    data-id='" . (int)$r['room_id'] . "'
                    data-code='" . htmlspecialchars((string)$r['room_code'], ENT_QUOTES) . "'
                    data-name='" . htmlspecialchars((string)$r['room_name'], ENT_QUOTES) . "'
                    data-type='" . htmlspecialchars(strtolower((string)$r['room_type']), ENT_QUOTES) . "'
                    data-capacity='" . (int)$r['capacity'] . "'
                    data-shared-colleges='" . htmlspecialchars((string)$r['shared_ids'], ENT_QUOTES) . "'>
                    <i class='bx bx-edit'></i>
                </button>

                <button class='btn btn-sm btn-danger btnDelete'
                    data-id='" . (int)$r['room_id'] . "'>
                    <i class='bx bx-trash'></i>
                </button>
            ";
        }

        $rows .= "
            <tr>
                <td>{$i}</td>
                <td>" . htmlspecialchars((string)$r['room_code']) . "</td>
                <td>" . htmlspecialchars((string)$r['room_name']) . "</td>
                <td>" . htmlspecialchars((string)$r['owner_college_code']) . "</td>
                <td>{$accessBadge}</td>
                <td>{$sharedWithHtml}</td>
                <td>{$type_label}</td>
                <td>" . (int)$r['capacity'] . "</td>
                <td class='text-end'>{$actionHtml}</td>
            </tr>
        ";
        $i++;
    }

    echo $rows;
    exit;
}

if (isset($_POST['save_room'])) {
    [$ay_id, $semester, $ok] = term_inputs_valid();
    if (!$ok) {
        echo "missing_term";
        exit;
    }

    $room_code = strtoupper(trim((string)($_POST['room_code'] ?? '')));
    $room_name = strtoupper(trim((string)($_POST['room_name'] ?? '')));
    $room_type = normalize_room_type($_POST['room_type'] ?? 'lecture');
    $capacity = (int)($_POST['capacity'] ?? 0);
    $shared_colleges = sanitize_shared_college_ids(
        $conn,
        $_POST['shared_colleges'] ?? [],
        $college_id,
        $campus_id
    );

    if ($room_code === '') {
        echo "error";
        exit;
    }

    $dup = $conn->prepare("SELECT room_id FROM tbl_rooms WHERE room_code = ? AND college_id = ? LIMIT 1");
    $dup->bind_param("si", $room_code, $college_id);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        echo "duplicate";
        exit;
    }

    $conn->begin_transaction();
    try {
        if ($room_term_columns) {
            $ins = $conn->prepare("INSERT INTO tbl_rooms (college_id, ay_id, semester, room_code, room_name, room_type, capacity, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
            $ins->bind_param("iiisssi", $college_id, $ay_id, $semester, $room_code, $room_name, $room_type, $capacity);
        } else {
            $ins = $conn->prepare("INSERT INTO tbl_rooms (college_id, room_code, room_name, room_type, capacity, status) VALUES (?, ?, ?, ?, ?, 'active')");
            $ins->bind_param("isssi", $college_id, $room_code, $room_name, $room_type, $capacity);
        }
        $ins->execute();
        $room_id = (int)$conn->insert_id;

        $own = $conn->prepare("INSERT INTO tbl_room_college_access (room_id, college_id, ay_id, semester, access_type) VALUES (?, ?, ?, ?, 'owner') ON DUPLICATE KEY UPDATE access_type='owner'");
        $own->bind_param("iiii", $room_id, $college_id, $ay_id, $semester);
        $own->execute();

        $insShared = $conn->prepare("INSERT INTO tbl_room_college_access (room_id, college_id, ay_id, semester, access_type) VALUES (?, ?, ?, ?, 'shared') ON DUPLICATE KEY UPDATE access_type='shared'");
        foreach ((array)$shared_colleges as $sharedCollegeId) {
            $insShared->bind_param("iiii", $room_id, $sharedCollegeId, $ay_id, $semester);
            $insShared->execute();
        }

        $conn->commit();
        echo "success";
    } catch (Throwable $e) {
        $conn->rollback();
        echo "error";
    }
    exit;
}

if (isset($_POST['update_room'])) {
    [$ay_id, $semester, $ok] = term_inputs_valid();
    if (!$ok) {
        echo "missing_term";
        exit;
    }

    $room_id = (int)($_POST['room_id'] ?? 0);
    $room_code = strtoupper(trim((string)($_POST['room_code'] ?? '')));
    $room_name = strtoupper(trim((string)($_POST['room_name'] ?? '')));
    $room_type = normalize_room_type($_POST['room_type'] ?? 'lecture');
    $capacity = (int)($_POST['capacity'] ?? 0);
    $shared_colleges = sanitize_shared_college_ids(
        $conn,
        $_POST['shared_colleges'] ?? [],
        $college_id,
        $campus_id
    );

    if ($room_id <= 0 || $room_code === '') {
        echo "error";
        exit;
    }

    $ownerCheck = $conn->prepare("SELECT 1 FROM tbl_rooms WHERE room_id = ? AND college_id = ? LIMIT 1");
    $ownerCheck->bind_param("ii", $room_id, $college_id);
    $ownerCheck->execute();
    if ($ownerCheck->get_result()->num_rows === 0) {
        echo "forbidden";
        exit;
    }

    $dup = $conn->prepare("SELECT room_id FROM tbl_rooms WHERE room_code = ? AND college_id = ? AND room_id <> ? LIMIT 1");
    $dup->bind_param("sii", $room_code, $college_id, $room_id);
    $dup->execute();
    if ($dup->get_result()->num_rows > 0) {
        echo "duplicate";
        exit;
    }

    $conn->begin_transaction();
    try {
        if ($room_term_columns) {
            $upd = $conn->prepare("UPDATE tbl_rooms SET room_code = ?, room_name = ?, room_type = ?, capacity = ?, ay_id = ?, semester = ? WHERE room_id = ? AND college_id = ?");
            $upd->bind_param("sssiiiii", $room_code, $room_name, $room_type, $capacity, $ay_id, $semester, $room_id, $college_id);
        } else {
            $upd = $conn->prepare("UPDATE tbl_rooms SET room_code = ?, room_name = ?, room_type = ?, capacity = ? WHERE room_id = ? AND college_id = ?");
            $upd->bind_param("sssiii", $room_code, $room_name, $room_type, $capacity, $room_id, $college_id);
        }
        $upd->execute();

        $own = $conn->prepare("INSERT INTO tbl_room_college_access (room_id, college_id, ay_id, semester, access_type) VALUES (?, ?, ?, ?, 'owner') ON DUPLICATE KEY UPDATE access_type='owner'");
        $own->bind_param("iiii", $room_id, $college_id, $ay_id, $semester);
        $own->execute();

        $delShared = $conn->prepare("DELETE FROM tbl_room_college_access WHERE room_id = ? AND ay_id = ? AND semester = ? AND access_type = 'shared'");
        $delShared->bind_param("iii", $room_id, $ay_id, $semester);
        $delShared->execute();

        $insShared = $conn->prepare("INSERT INTO tbl_room_college_access (room_id, college_id, ay_id, semester, access_type) VALUES (?, ?, ?, ?, 'shared') ON DUPLICATE KEY UPDATE access_type='shared'");
        foreach ((array)$shared_colleges as $sharedCollegeId) {
            $insShared->bind_param("iiii", $room_id, $sharedCollegeId, $ay_id, $semester);
            $insShared->execute();
        }

        $conn->commit();
        echo "success";
    } catch (Throwable $e) {
        $conn->rollback();
        echo "error";
    }
    exit;
}

if (isset($_POST['delete_room'])) {
    [$ay_id, $semester, $ok] = term_inputs_valid();
    if (!$ok) {
        echo "missing_term";
        exit;
    }

    $room_id = (int)($_POST['room_id'] ?? 0);
    if ($room_id <= 0) {
        echo "error";
        exit;
    }

    $ownerCheck = $conn->prepare("SELECT 1 FROM tbl_rooms WHERE room_id = ? AND college_id = ? LIMIT 1");
    $ownerCheck->bind_param("ii", $room_id, $college_id);
    $ownerCheck->execute();
    if ($ownerCheck->get_result()->num_rows === 0) {
        echo "forbidden";
        exit;
    }

    $conn->begin_transaction();
    try {
        // Remove access for selected AY + Semester only (owner and shared rows).
        $delTermAccess = $conn->prepare("DELETE FROM tbl_room_college_access WHERE room_id = ? AND ay_id = ? AND semester = ?");
        $delTermAccess->bind_param("iii", $room_id, $ay_id, $semester);
        $delTermAccess->execute();

        // Cleanup orphaned room master if no access rows remain in any term.
        $chk = $conn->prepare("SELECT 1 FROM tbl_room_college_access WHERE room_id = ? LIMIT 1");
        $chk->bind_param("i", $room_id);
        $chk->execute();
        $hasAccess = $chk->get_result()->num_rows > 0;

        if (!$hasAccess) {
            $delRoom = $conn->prepare("DELETE FROM tbl_rooms WHERE room_id = ? AND college_id = ?");
            $delRoom->bind_param("ii", $room_id, $college_id);
            $delRoom->execute();
        }

        $conn->commit();
        echo "success";
    } catch (Throwable $e) {
        $conn->rollback();
        echo "error";
    }
    exit;
}

echo "invalid";
?>
