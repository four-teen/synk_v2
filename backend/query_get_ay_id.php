<?php
include 'db.php';

$ay = trim($_POST['ay'] ?? '');
if ($ay === '') exit;

$stmt = $conn->prepare("
    SELECT ay_id FROM tbl_academic_years
    WHERE ay = ? LIMIT 1
");
$stmt->bind_param("s", $ay);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo json_encode(['error' => 1]);
    exit;
}

echo json_encode($res->fetch_assoc());
