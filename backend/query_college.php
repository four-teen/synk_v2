<?php
include '../backend/db.php';

// =========================================
// LOAD COLLEGES (with campus join)
// =========================================
if (isset($_POST['load_colleges'])) {

    $sql = "
        SELECT c.college_id, c.campus_id, c.college_code, c.college_name, c.status,
               cp.campus_name
        FROM tbl_college c
        LEFT JOIN tbl_campus cp ON c.campus_id = cp.campus_id
        ORDER BY cp.campus_name ASC, c.college_name ASC
    ";

    $query = $conn->query($sql);
    $output = "";
    $i = 1;

    while ($row = $query->fetch_assoc()) {

        $badge = ($row['status'] == 'active')
            ? "<span class='badge bg-success'>ACTIVE</span>"
            : "<span class='badge bg-secondary'>INACTIVE</span>";

        $output .= "
        <tr>
            <td>{$i}</td>
            <td>{$row['campus_name']}</td>
            <td>{$row['college_code']}</td>
            <td>{$row['college_name']}</td>
            <td>{$badge}</td>

            <td class='text-end text-nowrap'>
                <button class='btn btn-sm btn-warning btnEdit'
                    data-id='{$row['college_id']}'
                    data-campus='{$row['campus_id']}'
                    data-code='{$row['college_code']}'
                    data-name='{$row['college_name']}'
                    data-status='{$row['status']}'>
                    <i class='bx bx-edit-alt'></i>
                </button>

                <button class='btn btn-sm btn-danger btnDelete'
                    data-id='{$row['college_id']}'>
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



// =========================================
// SAVE NEW COLLEGE
// =========================================
if (isset($_POST['save_college'])) {

    $campus_id = $_POST['campus_id'];
    $code      = strtoupper($_POST['college_code']);
    $name      = strtoupper($_POST['college_name']);

    $sql = "INSERT INTO tbl_college (campus_id, college_code, college_name)
            VALUES ('$campus_id', '$code', '$name')";
    $conn->query($sql);

    echo "success";
    exit;
}



// =========================================
// UPDATE COLLEGE
// =========================================
if (isset($_POST['update_college'])) {

    $college_id = $_POST['college_id'];
    $campus_id  = $_POST['campus_id'];
    $code       = strtoupper($_POST['college_code']);
    $name       = strtoupper($_POST['college_name']);
    $status     = $_POST['status'];

    $sql = "
        UPDATE tbl_college
        SET campus_id='$campus_id',
            college_code='$code',
            college_name='$name',
            status='$status'
        WHERE college_id='$college_id'
    ";

    $conn->query($sql);

    echo "updated";
    exit;
}



// =========================================
// DELETE COLLEGE
// =========================================
if (isset($_POST['delete_college'])) {

    $college_id = $_POST['college_id'];

    $sql = "DELETE FROM tbl_college WHERE college_id='$college_id'";
    $conn->query($sql);

    echo "deleted";
    exit;
}

?>
