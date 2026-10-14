<?php
require_once __DIR__ . '/college_dean_dashboard_helper.php';

function synk_chair_faculty_query(mysqli $conn, string $sql, string $types = '', array $values = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare the faculty request.');
    try {
        if ($types !== '' && !$stmt->bind_param($types, ...$values)) throw new RuntimeException('Unable to prepare faculty request values.');
        if (!$stmt->execute()) throw new RuntimeException('Unable to complete the faculty database request.');
        $result = $stmt->get_result();
        return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    } finally { $stmt->close(); }
}

function synk_chair_faculty_classifications(): array
{
    return ['permanent' => 'Permanent', 'temporary' => 'Temporary',
        'contract_of_service' => 'Contract of Service', 'part_time' => 'Part Time'];
}

function synk_chair_faculty_require_scope(mysqli $conn, array $input = []): array
{
    if (($_SESSION['role'] ?? '') !== 'program_chair') throw new DomainException('Program Chair access is required.');
    $scope = synk_college_dean_dashboard_scope($conn);
    if (!$scope) throw new DomainException('Your Program Chair role or college assignment is no longer active.');
    foreach (['college_id', 'campus_id'] as $key) {
        if (isset($input[$key]) && (!is_scalar($input[$key]) || (string)$input[$key] !== (string)$scope[$key])) {
            throw new DomainException('You can only manage faculty in your assigned college.');
        }
    }
    return $scope;
}

function synk_chair_faculty_name(array $row): string
{
    return trim((string)$row['last_name']) . ', ' . trim(implode(' ', array_filter([
        $row['first_name'] ?? '', $row['middle_name'] ?? '', $row['ext_name'] ?? '',
    ])));
}

function synk_chair_faculty_list(mysqli $conn, array $scope, array $term): array
{
    $rows = synk_chair_faculty_query($conn, "SELECT f.faculty_id, f.first_name, f.middle_name, f.last_name,
        f.ext_name, f.status AS faculty_status, f.employment_classification, f.college_id, f.campus_id,
        EXISTS(SELECT 1 FROM tbl_college_faculty cf WHERE cf.faculty_id=f.faculty_id
            AND cf.college_id=? AND LOWER(TRIM(cf.status))='active'
            AND ((cf.ay_id=? AND cf.semester=?) OR (cf.ay_id IS NULL AND cf.semester IS NULL))) AS is_assigned,
        EXISTS(SELECT 1 FROM tbl_college_faculty cf WHERE cf.faculty_id=f.faculty_id
            AND cf.college_id=? AND cf.status='active' AND cf.ay_id=? AND cf.semester=?) AS has_term_assignment
        FROM tbl_faculty f ORDER BY f.last_name, f.first_name, f.faculty_id",
        'iiiiii', [$scope['college_id'], $term['ay_id'], $term['semester'], $scope['college_id'], $term['ay_id'], $term['semester']]);
    $assigned = []; $available = [];
    foreach ($rows as $row) {
        $faculty = ['faculty_id' => (int)$row['faculty_id'], 'faculty_name' => synk_chair_faculty_name($row),
            'faculty_status' => $row['faculty_status'], 'employment_classification' => $row['employment_classification'],
            'classification_label' => synk_chair_faculty_classifications()[$row['employment_classification']] ?? 'Not recorded',
            'can_manage_profile' => (((int)$row['college_id'] === 0 && $row['has_term_assignment']) || (int)$row['college_id'] === $scope['college_id'])
                && ((int)$row['campus_id'] === 0 || (int)$row['campus_id'] === $scope['campus_id'])];
        if ($row['is_assigned']) $assigned[] = $faculty;
        elseif ($row['faculty_status'] === 'active') $available[] = $faculty;
    }
    return ['assigned' => $assigned, 'available' => $available];
}

function synk_chair_faculty_assign(mysqli $conn, array $input): array
{
    foreach (['faculty_id', 'employment_classification', 'ay_id', 'semester'] as $key) {
        if (!isset($input[$key]) || !is_string($input[$key])) throw new DomainException('Select faculty, classification, and the current term.');
    }
    $facultyId = filter_var($input['faculty_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $classification = $input['employment_classification'];
    if (!$facultyId || !isset(synk_chair_faculty_classifications()[$classification])) {
        throw new DomainException('Select an active faculty member and a valid employment classification.');
    }
    // Lock the actor, current term, and shared faculty record before checking for duplicates.
    $conn->begin_transaction();
    try {
        synk_chair_faculty_query($conn, 'SELECT user_id FROM tbl_useraccount WHERE user_id=? FOR UPDATE', 'i', [(int)($_SESSION['user_id'] ?? 0)]);
        synk_chair_faculty_query($conn, 'SELECT user_id FROM tbl_useraccount_roles WHERE user_id=? FOR UPDATE', 'i', [(int)($_SESSION['user_id'] ?? 0)]);
        $scope = synk_chair_faculty_require_scope($conn, $input);
        $college = synk_chair_faculty_query($conn, "SELECT c.college_id FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id
            WHERE c.college_id=? AND c.status='active' AND cp.status='active' FOR UPDATE", 'i', [$scope['college_id']]);
        if (!$college) throw new DomainException('Your assigned college is no longer active.',403);
        $settings = synk_chair_faculty_query($conn, 'SELECT current_ay_id,current_semester FROM tbl_academic_settings LIMIT 1 FOR UPDATE');
        $current = ['ay_id'=>(int)($settings[0]['current_ay_id'] ?? 0), 'semester'=>(int)($settings[0]['current_semester'] ?? 0)];
        $requested = synk_dean_term_from_ids($conn, $input['ay_id'], $input['semester']);
        if ($current['ay_id'] <= 0 || $current['semester'] <= 0 || $requested['ay_id'] !== $current['ay_id'] || $requested['semester'] !== $current['semester']) {
            throw new DomainException('Switch to the current academic year and semester to add faculty.');
        }
        $faculty = synk_chair_faculty_query($conn, "SELECT faculty_id FROM tbl_faculty WHERE faculty_id=? AND status='active' FOR UPDATE", 'i', [$facultyId]);
        if (!$faculty) throw new DomainException('This faculty member is no longer active or available.');
        $existing = synk_chair_faculty_query($conn, "SELECT college_faculty_id, LOWER(TRIM(status)) AS status
            FROM tbl_college_faculty WHERE college_id=? AND faculty_id=?
            AND ((ay_id=? AND semester=?) OR (ay_id IS NULL AND semester IS NULL))
            ORDER BY CASE WHEN LOWER(TRIM(status))='active' THEN 0 ELSE 1 END, college_faculty_id FOR UPDATE",
            'iiii', [$scope['college_id'], $facultyId, $current['ay_id'], $current['semester']]);
        if ($existing && $existing[0]['status'] === 'active') {
            $conn->rollback();
            return ['status' => 'duplicate', 'message' => 'This faculty member is already assigned to your college.'];
        }
        if ($existing) {
            synk_chair_faculty_query($conn, "UPDATE tbl_college_faculty SET status='active' WHERE college_faculty_id=?", 'i', [(int)$existing[0]['college_faculty_id']]);
        } else {
            synk_chair_faculty_query($conn, "INSERT INTO tbl_college_faculty (college_id,faculty_id,ay_id,semester,status) VALUES (?,?,?,?,'active')",
                'iiii', [$scope['college_id'], $facultyId, $current['ay_id'], $current['semester']]);
        }
        // Scheduler stores this classification on the shared faculty record too.
        synk_chair_faculty_query($conn, 'UPDATE tbl_faculty SET employment_classification=? WHERE faculty_id=?', 'si', [$classification, $facultyId]);
        $conn->commit();
        return ['status' => $existing ? 'reactivated' : 'inserted', 'message' => 'Faculty added to your college for the current term.'];
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
