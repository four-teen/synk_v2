<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'admin') {
    if (isset($_POST['load_programs'])) {
        header('Content-Type: application/json');
        echo json_encode([]);
    } else {
        echo "ERROR|Unauthorized access.";
    }
    exit;
}

if (isset($_POST['copy_prospectus'])) {
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    if (
        empty($_SESSION['csrf_token']) ||
        $csrfToken === '' ||
        !hash_equals((string)$_SESSION['csrf_token'], $csrfToken)
    ) {
        echo "ERROR|CSRF validation failed.";
        exit;
    }
}

if (isset($_POST['load_programs'])) {
    $sql = "
        SELECT
            p.program_id,
            p.program_name,
            p.program_code,
            p.major,
            c.campus_code
        FROM tbl_program p
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        INNER JOIN tbl_campus c
            ON c.campus_id = col.campus_id
        WHERE p.status = 'active'
        ORDER BY c.campus_code, p.program_name, p.major
    ";

    $res = $conn->query($sql);

    $data = [];
    while ($row = $res->fetch_assoc()) {
        $data[] = $row;
    }

    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

if (isset($_POST['copy_prospectus'])) {
    $source = (int)($_POST['source_prospectus_id'] ?? 0);
    $target = (int)($_POST['target_program_id'] ?? 0);
    $cmo = trim((string)($_POST['cmo_no'] ?? ''));
    $sy = trim((string)($_POST['effective_sy'] ?? ''));

    if ($source <= 0 || $target <= 0 || $cmo === '' || $sy === '') {
        echo "ERROR|Missing required fields.";
        exit;
    }

    if (strpos($cmo, '|') !== false || strpos($sy, '|') !== false) {
        echo "ERROR|Pipe character (|) is not allowed.";
        exit;
    }

    $sourceCheck = $conn->prepare("
        SELECT prospectus_id
        FROM tbl_prospectus_header
        WHERE prospectus_id = ?
        LIMIT 1
    ");
    $sourceCheck->bind_param("i", $source);
    $sourceCheck->execute();
    $sourceExists = $sourceCheck->get_result()->num_rows > 0;
    $sourceCheck->close();

    if (!$sourceExists) {
        echo "ERROR|Source prospectus not found.";
        exit;
    }

    $targetCheck = $conn->prepare("
        SELECT program_id
        FROM tbl_program
        WHERE program_id = ?
          AND status = 'active'
        LIMIT 1
    ");
    $targetCheck->bind_param("i", $target);
    $targetCheck->execute();
    $targetExists = $targetCheck->get_result()->num_rows > 0;
    $targetCheck->close();

    if (!$targetExists) {
        echo "ERROR|Target program not found.";
        exit;
    }

    $duplicateCheck = $conn->prepare("
        SELECT prospectus_id
        FROM tbl_prospectus_header
        WHERE program_id = ?
          AND cmo_no = ?
          AND effective_sy = ?
        LIMIT 1
    ");
    $duplicateCheck->bind_param("iss", $target, $cmo, $sy);
    $duplicateCheck->execute();
    $duplicateExists = $duplicateCheck->get_result()->num_rows > 0;
    $duplicateCheck->close();

    if ($duplicateExists) {
        echo "ERROR|Target program already has this CMO.";
        exit;
    }

    $stmt = $conn->prepare("
        INSERT INTO tbl_prospectus_header (program_id, cmo_no, effective_sy)
        VALUES (?, ?, ?)
    ");
    $stmt->bind_param("iss", $target, $cmo, $sy);

    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        echo "ERROR|Failed to create prospectus header: {$error}";
        exit;
    }

    $newProspectusId = $stmt->insert_id;
    $stmt->close();

    $map = [];

    $ys = $conn->prepare("
        SELECT pys_id, year_level, semester
        FROM tbl_prospectus_year_sem
        WHERE prospectus_id = ?
    ");
    $ys->bind_param("i", $source);
    $ys->execute();
    $res = $ys->get_result();

    while ($row = $res->fetch_assoc()) {
        $insertYearSem = $conn->prepare("
            INSERT INTO tbl_prospectus_year_sem
            (prospectus_id, year_level, semester)
            VALUES (?, ?, ?)
        ");
        $insertYearSem->bind_param("iss", $newProspectusId, $row['year_level'], $row['semester']);
        $insertYearSem->execute();
        $map[$row['pys_id']] = $insertYearSem->insert_id;
        $insertYearSem->close();
    }
    $ys->close();

    foreach ($map as $oldPys => $newPys) {
        $subjects = $conn->prepare("
            SELECT sub_id, lec_units, lab_units, total_units,
                   prerequisites, prerequisite_sub_ids, sort_order
            FROM tbl_prospectus_subjects
            WHERE pys_id = ?
        ");
        $subjects->bind_param("i", $oldPys);
        $subjects->execute();
        $subjectResult = $subjects->get_result();

        while ($subject = $subjectResult->fetch_assoc()) {
            $insertSubject = $conn->prepare("
                INSERT INTO tbl_prospectus_subjects
                (pys_id, sub_id, lec_units, lab_units, total_units,
                 prerequisites, prerequisite_sub_ids, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $insertSubject->bind_param(
                "iidddssi",
                $newPys,
                $subject['sub_id'],
                $subject['lec_units'],
                $subject['lab_units'],
                $subject['total_units'],
                $subject['prerequisites'],
                $subject['prerequisite_sub_ids'],
                $subject['sort_order']
            );
            $insertSubject->execute();
            $insertSubject->close();
        }
        $subjects->close();
    }

    echo "OK|{$newProspectusId}|Copied successfully.";
    exit;
}

echo "ERROR|Invalid request.";
