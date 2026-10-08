<?php
session_start();
include 'db.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    echo 'unauthorized';
    exit;
}

function query_designation_status_badge(string $status): string
{
    return ($status === 'active')
        ? "<span class='badge bg-success'>ACTIVE</span>"
        : "<span class='badge bg-secondary'>INACTIVE</span>";
}

function query_designation_format_units(float $units): string
{
    return rtrim(rtrim(number_format($units, 2, '.', ''), '0'), '.');
}

if (isset($_POST['load_designations'])) {
    $sql = "
        SELECT designation_id, designation_name, designation_units, status
        FROM tbl_designation
        ORDER BY designation_name ASC
    ";

    $res = $conn->query($sql);

    $i = 1;
    $output = '';

    while ($row = $res->fetch_assoc()) {
        $badge = query_designation_status_badge($row['status']);
        $units = query_designation_format_units((float)$row['designation_units']);

        $output .= "
        <tr>
            <td>{$i}</td>
            <td>" . htmlspecialchars($row['designation_name'], ENT_QUOTES, 'UTF-8') . "</td>
            <td>{$units}</td>
            <td>{$badge}</td>
            <td class='text-end text-nowrap'>
                <button class='btn btn-sm btn-warning btnEditDesignation'
                    data-id='{$row['designation_id']}'
                    data-name=\"" . htmlspecialchars($row['designation_name'], ENT_QUOTES, 'UTF-8') . "\"
                    data-units='" . htmlspecialchars($row['designation_units'], ENT_QUOTES, 'UTF-8') . "'
                    data-status='" . htmlspecialchars($row['status'], ENT_QUOTES, 'UTF-8') . "'
                    type='button'>
                    <i class='bx bx-edit-alt'></i>
                </button>

                <button class='btn btn-sm btn-danger btnDeleteDesignation'
                    data-id='{$row['designation_id']}'
                    type='button'>
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

if (isset($_POST['load_designation_options'])) {
    $sql = "
        SELECT designation_id, designation_name
        FROM tbl_designation
        WHERE status = 'active'
        ORDER BY designation_name ASC
    ";

    $res = $conn->query($sql);

    $options = '<option value="">No Designation</option>';

    while ($row = $res->fetch_assoc()) {
        $id = (int)$row['designation_id'];
        $name = htmlspecialchars($row['designation_name'], ENT_QUOTES, 'UTF-8');
        $options .= "<option value=\"{$id}\">{$name}</option>";
    }

    echo $options;
    exit;
}

if (isset($_POST['save_designation'])) {
    $nameRaw = strtoupper(trim((string)($_POST['designation_name'] ?? '')));
    $unitsRaw = trim((string)($_POST['designation_units'] ?? ''));

    if ($nameRaw === '' || $unitsRaw === '') {
        echo 'missing';
        exit;
    }

    if (!is_numeric($unitsRaw)) {
        echo 'invalid_units';
        exit;
    }

    $units = (float) $unitsRaw;
    if ($units < 0) {
        echo 'invalid_units';
        exit;
    }

    $stmt = $conn->prepare("SELECT designation_id FROM tbl_designation WHERE designation_name = ? LIMIT 1");
    $stmt->bind_param("s", $nameRaw);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->close();
        echo 'duplicate';
        exit;
    }
    $stmt->close();

    $stmt = $conn->prepare("INSERT INTO tbl_designation (designation_name, designation_units) VALUES (?, ?)");
    $stmt->bind_param("sd", $nameRaw, $units);
    $stmt->execute();
    $stmt->close();

    echo 'success';
    exit;
}

if (isset($_POST['update_designation'])) {
    $id = (int)($_POST['designation_id'] ?? 0);
    $nameRaw = strtoupper(trim((string)($_POST['designation_name'] ?? '')));
    $unitsRaw = trim((string)($_POST['designation_units'] ?? ''));
    $status = trim((string)($_POST['status'] ?? ''));

    if ($id <= 0 || $nameRaw === '' || $unitsRaw === '' || $status === '') {
        echo 'missing';
        exit;
    }

    if (!in_array($status, ['active', 'inactive'], true)) {
        echo 'invalid_status';
        exit;
    }

    if (!is_numeric($unitsRaw)) {
        echo 'invalid_units';
        exit;
    }

    $units = (float)$unitsRaw;
    if ($units < 0) {
        echo 'invalid_units';
        exit;
    }

    $stmt = $conn->prepare("
        SELECT designation_id
        FROM tbl_designation
        WHERE designation_name = ? AND designation_id <> ?
        LIMIT 1
    ");
    $stmt->bind_param("si", $nameRaw, $id);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->close();
        echo 'duplicate';
        exit;
    }
    $stmt->close();

    $stmt = $conn->prepare("
        UPDATE tbl_designation
           SET designation_name = ?,
               designation_units = ?,
               status = ?
         WHERE designation_id = ?
    ");
    $stmt->bind_param("sdsi", $nameRaw, $units, $status, $id);
    $stmt->execute();
    $stmt->close();

    echo 'updated';
    exit;
}

if (isset($_POST['delete_designation'])) {
    $id = (int)($_POST['designation_id'] ?? 0);

    if ($id <= 0) {
        echo 'missing';
        exit;
    }

    $stmt = $conn->prepare("DELETE FROM tbl_designation WHERE designation_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    echo 'deleted';
    exit;
}
?>
