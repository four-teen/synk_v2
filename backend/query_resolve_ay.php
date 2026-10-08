<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$ay = trim($_POST['ay'] ?? '');

if ($ay === '') {
    echo json_encode([]);
    exit;
}

$stmt = $conn->prepare("
    SELECT ay_id
    FROM tbl_academic_years
    WHERE ay = ?
    LIMIT 1
");
$stmt->bind_param("s", $ay);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
    echo json_encode([]);
    exit;
}

$row = $res->fetch_assoc();

echo json_encode([
    'ay_id' => (int)$row['ay_id']
]);
exit;
