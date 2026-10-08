<?php
include '../backend/db.php';


// ====================================================
// LOAD PROGRAMS (with JOIN to College + Campus)
// ====================================================
if (isset($_POST['load_programs'])) {

    $sql = "
        SELECT 
            p.program_id,
            p.college_id,
            p.program_code,
            p.program_name,
            p.major,
            p.status,
            c.college_name
        FROM tbl_program p
        LEFT JOIN tbl_college c ON p.college_id = c.college_id
        ORDER BY c.college_name ASC, p.program_name ASC
    ";

    $query = $conn->query($sql);
    $output = "";
    $i = 1;

    while ($row = $query->fetch_assoc()) {

        $badge = ($row['status'] == 'active')
            ? "<span class='badge bg-success'>Active</span>"
            : "<span class='badge bg-secondary'>Inactive</span>";

        $major = ($row['major'] == "" || $row['major'] == null)
            ? "<span class='text-muted'>—</span>"
            : $row['major'];

        $output .= "
        <tr>
            <td>{$i}</td>
            <td>{$row['college_name']}</td>
            <td>{$row['program_code']}</td>
            <td>{$row['program_name']}</td>
            <td>{$major}</td>
            <td>{$badge}</td>

            <td class='text-end text-nowrap'>
                <button class='btn btn-sm btn-warning btnEdit'
                    data-id='{$row['program_id']}'
                    data-college='{$row['college_id']}'
                    data-code='{$row['program_code']}'
                    data-name='{$row['program_name']}'
                    data-major='{$row['major']}'
                    data-status='{$row['status']}'>
                    <i class='bx bx-edit-alt'></i>
                </button>

                <button class='btn btn-sm btn-danger btnDelete'
                    data-id='{$row['program_id']}'>
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



// ====================================================
// SAVE NEW PROGRAM
// ====================================================
if (isset($_POST['save_program'])) {

    $college_id   = $_POST['college_id'];
    $program_code = strtoupper($_POST['program_code']);
    $program_name = strtoupper($_POST['program_name']);
    $major        = strtoupper($_POST['major']);

    $sql = "INSERT INTO tbl_program (college_id, program_code, program_name, major)
            VALUES ('$college_id', '$program_code', '$program_name', '$major')";
    
    $conn->query($sql);

    echo "success";
    exit;
}



// ====================================================
// UPDATE PROGRAM
// ====================================================
if (isset($_POST['update_program'])) {

    $program_id   = $_POST['program_id'];
    $college_id   = $_POST['college_id'];
    $program_code = strtoupper($_POST['program_code']);
    $program_name = strtoupper($_POST['program_name']);
    $major        = strtoupper($_POST['major']);
    $status       = $_POST['status'];

    $sql = "
        UPDATE tbl_program 
        SET 
            college_id='$college_id',
            program_code='$program_code',
            program_name='$program_name',
            major='$major',
            status='$status'
        WHERE program_id='$program_id'
    ";

    $conn->query($sql);

    echo "updated";
    exit;
}



// ====================================================
// DELETE PROGRAM
// ====================================================
if (isset($_POST['delete_program'])) {

    $program_id = $_POST['program_id'];

    $sql = "DELETE FROM tbl_program WHERE program_id='$program_id'";
    $conn->query($sql);

    echo "deleted";
    exit;
}

?>
