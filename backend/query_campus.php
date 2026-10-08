<?php
    session_start();
    ob_start();
    include '../backend/db.php';

// ===============================
// LOAD ALL CAMPUSES
// ===============================
if (isset($_POST['load_campuses'])) {

    $sql = "SELECT * FROM tbl_campus ORDER BY campus_name ASC";
    $query = $conn->query($sql);

    $output = '';
    $i = 1;

    while ($row = $query->fetch_assoc()) {

        // badge
        $badge = ($row['status'] == 'active')
            ? "<span class='badge bg-success'>Active</span>"
            : "<span class='badge bg-secondary'>Inactive</span>";

        $output .= "
        <tr>
            <td>{$i}</td>
            <td>{$row['campus_code']}</td>
            <td>{$row['campus_name']}</td>
            <td>{$badge}</td>
            <td class='text-end text-nowrap'>
                <button class='btn btn-sm btn-warning btnEdit' 
                        data-id='{$row['campus_id']}' 
                        data-code='{$row['campus_code']}' 
                        data-name='{$row['campus_name']}' 
                        data-status='{$row['status']}'>
                    <i class='bx bx-edit-alt'></i>
                </button>
                
                <button class='btn btn-sm btn-danger btnDelete' data-id='{$row['campus_id']}'>
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



// ===============================
// SAVE NEW CAMPUS
// ===============================
if (isset($_POST['save_campus'])) {

    $code = $_POST['campus_code'];
    $name = $_POST['campus_name'];

    $sql = "INSERT INTO tbl_campus (campus_code, campus_name) VALUES ('$code', '$name')";
    $conn->query($sql);

    echo "success";
    exit;
}



// ===============================
// UPDATE CAMPUS
// ===============================
if (isset($_POST['update_campus'])) {

    $id     = $_POST['campus_id'];
    $code   = $_POST['campus_code'];
    $name   = $_POST['campus_name'];
    $status = $_POST['status'];

    $sql = "UPDATE tbl_campus 
            SET campus_code='$code', campus_name='$name', status='$status'
            WHERE campus_id='$id'";

    $conn->query($sql);

    echo "updated";
    exit;
}



// ===============================
// DELETE CAMPUS
// ===============================
if (isset($_POST['delete_campus'])) {

    $id = $_POST['campus_id'];

    $sql = "DELETE FROM tbl_campus WHERE campus_id='$id'";
    $conn->query($sql);

    echo "deleted";
    exit;
}

?>
