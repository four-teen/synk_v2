<?php
session_start();
include 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'scheduler') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']);
    exit;
}

$csrf_token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
    echo json_encode(['status' => 'error', 'message' => 'CSRF validation failed.']);
    exit;
}

$college_id = (int)($_SESSION['college_id'] ?? 0);
$ay_id = (int)($_POST['ay_id'] ?? 0);
$semester = (int)($_POST['semester'] ?? 0);

if ($college_id <= 0 || $ay_id <= 0 || !in_array($semester, [1, 2, 3], true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid term context.']);
    exit;
}

$hasTable = $conn->query("SHOW TABLES LIKE 'tbl_room_college_access'");
if (!$hasTable || $hasTable->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Room access table missing.']);
    exit;
}

$sql = "
    SELECT DISTINCT
        r.room_id,
        r.room_code,
        r.room_name,
        LOWER(COALESCE(r.room_type, 'lecture')) AS room_type,
        acc.access_type,
        owner.college_code AS owner_code
    FROM tbl_room_college_access acc
    INNER JOIN tbl_rooms r ON r.room_id = acc.room_id
    INNER JOIN tbl_college owner ON owner.college_id = r.college_id
    WHERE acc.college_id = ?
      AND acc.ay_id = ?
      AND acc.semester = ?
      AND r.status = 'active'
    ORDER BY r.room_name ASC, r.room_code ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $college_id, $ay_id, $semester);
$stmt->execute();
$res = $stmt->get_result();

$rooms = [];
while ($row = $res->fetch_assoc()) {
    $name = trim((string)($row['room_name'] ?? ''));
    $code = trim((string)($row['room_code'] ?? ''));
    $label = $name !== '' ? $name : $code;
    if ($name !== '' && $code !== '' && strcasecmp($name, $code) !== 0) {
        $label = $code . " - " . $name;
    }

    $accessType = strtolower(trim((string)($row['access_type'] ?? 'owner')));
    $ownerCode = trim((string)($row['owner_code'] ?? ''));
    if ($accessType === 'shared') {
        $label .= $ownerCode !== ''
            ? " (Shared from {$ownerCode})"
            : " (Shared)";
    }

    $rooms[] = [
        'room_id' => (int)$row['room_id'],
        'label' => $label,
        'room_type' => $row['room_type'] ?? 'lecture',
        'access_type' => $accessType,
        'owner_code' => $ownerCode
    ];
}

echo json_encode([
    'status' => 'ok',
    'rooms' => $rooms
]);
exit;
?>
