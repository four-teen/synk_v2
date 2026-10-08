<?php
include '../backend/db.php';

/* ============================================
   SIMPLE LOGGER (for debugging)
============================================ */
// function debug_log($msg) {
//     file_put_contents(
//         __DIR__ . '/log.txt',
//         "[" . date("Y-m-d H:i:s") . "] " . $msg . PHP_EOL,
//         FILE_APPEND
//     );
// }

/* ==========================================================
   LOAD SUBJECT MASTERLIST (WITH USED PROGRAMS + LOCK DELETE)
========================================================== */
if (isset($_POST['load_subjects'])) {

    $sql = "
SELECT 
    sm.sub_id,
    sm.sub_code,
    sm.sub_description,
    sm.status,

    COUNT(ps.ps_id) AS usage_count,

    GROUP_CONCAT(
        DISTINCT p.program_code
        ORDER BY p.program_code
        SEPARATOR ', '
    ) AS used_programs

FROM tbl_subject_masterlist sm

LEFT JOIN tbl_prospectus_subjects ps 
    ON ps.sub_id = sm.sub_id

LEFT JOIN tbl_prospectus_year_sem pys
    ON pys.pys_id = ps.pys_id

LEFT JOIN tbl_prospectus_header ph
    ON ph.prospectus_id = pys.prospectus_id

LEFT JOIN tbl_program p
    ON p.program_id = ph.program_id

GROUP BY sm.sub_id
ORDER BY sm.sub_code ASC

    ";

    $result = $conn->query($sql);

    $output = "";
    $i = 1;

    while ($row = $result->fetch_assoc()) {

        // STATUS BADGE
        $badge = ($row['status'] === 'active')
            ? "<span class='badge bg-success'>ACTIVE</span>"
            : "<span class='badge bg-secondary'>INACTIVE</span>";

        // USED PROGRAMS + DELETE LOCK
if ($row['usage_count'] > 0) {
    $usedPrograms = "<em>{$row['used_programs']}</em>";
    $deleteDisabled = "disabled";
    $deleteTitle = "title='Subject is already used in prospectus'";
} else {
    $usedPrograms = "<span class='text-muted'>—</span>";
    $deleteDisabled = "";
    $deleteTitle = "";
}

        $output .= "
        <tr>
            <td>{$i}</td>
            <td>{$row['sub_code']}</td>
            <td>{$row['sub_description']}</td>
            <td>{$usedPrograms}</td>
            <td>{$badge}</td>

            <td class='text-end text-nowrap'>
                <button class='btn btn-sm btn-warning btnEdit'
                    data-id='{$row['sub_id']}'
                    data-code=\"{$row['sub_code']}\"
                    data-desc=\"{$row['sub_description']}\"
                    data-status='{$row['status']}'>
                    <i class='bx bx-edit-alt'></i>
                </button>

                <button class='btn btn-sm btn-danger btnDelete'
                    data-id='{$row['sub_id']}'
                    {$deleteDisabled}
                    {$deleteTitle}>
                    <i class='bx bx-trash'></i>
                </button>
            </td>
        </tr>
        ";

        $i++;
    }

    echo $output;
    exit;
}

/* ==========================================================
   SAVE SUBJECT
========================================================== */
if (isset($_POST['save_subject'])) {

    $code = $conn->real_escape_string(strtoupper(trim($_POST['sub_code'])));
    $desc = $conn->real_escape_string(strtoupper(trim($_POST['sub_description'])));

    $dup = $conn->query("
        SELECT sub_id 
        FROM tbl_subject_masterlist 
        WHERE sub_code='$code' 
          AND sub_description='$desc'
    ");

    if ($dup->num_rows > 0) {
        echo "duplicate";
        exit;
    }

    $conn->query("
        INSERT INTO tbl_subject_masterlist (sub_code, sub_description)
        VALUES ('$code', '$desc')
    ");

    echo "success";
    exit;
}

/* ==========================================================
   UPDATE SUBJECT
========================================================== */
if (isset($_POST['update_subject'])) {

    $id     = (int)$_POST['sub_id'];
    $code   = $conn->real_escape_string(strtoupper($_POST['sub_code']));
    $desc   = $conn->real_escape_string(strtoupper($_POST['sub_description']));
    $status = $_POST['status'];

    $conn->query("
        UPDATE tbl_subject_masterlist
        SET sub_code='$code',
            sub_description='$desc',
            status='$status'
        WHERE sub_id='$id'
    ");

    echo "updated";
    exit;
}

/* ==========================================================
   DELETE SUBJECT (BLOCK IF USED)
========================================================== */
if (isset($_POST['delete_subject'])) {

    $id = (int)$_POST['sub_id'];

    $check = $conn->query("
        SELECT COUNT(*) AS cnt
        FROM tbl_prospectus_subjects
        WHERE sub_id = '$id'
    ")->fetch_assoc();

    if ($check['cnt'] > 0) {
        echo "in_use";
        exit;
    }

    $conn->query("
        DELETE FROM tbl_subject_masterlist 
        WHERE sub_id='$id'
    ");

    echo "deleted";
    exit;
}
?>
