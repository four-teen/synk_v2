<?php
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/auth_useraccount.php';
require_once __DIR__ . '/academic_term_helper.php';

function synk_faculty_college_appointments_ready(mysqli $conn): bool
{
    return $conn->query("SHOW TABLES LIKE 'tbl_faculty_college_appointments'")->num_rows > 0;
}

// Call outside transactions: the additive migration includes CREATE TABLE.
function synk_faculty_college_appointments_ensure(mysqli $conn): void
{
    if (synk_faculty_college_appointments_ready($conn)) return;
    $sql = file_get_contents(__DIR__ . '/sql/phase30_faculty_college_appointments.sql');
    if ($sql === false || !$conn->multi_query($sql)) throw new RuntimeException('Unable to prepare college appointments.');
    do {
        $result = $conn->store_result();
        if ($result instanceof mysqli_result) $result->free();
    } while ($conn->more_results() && $conn->next_result());
    if ($conn->errno) throw new RuntimeException('Unable to prepare college appointments.');
}

function synk_faculty_appointment_require_admin(mysqli $conn): array
{
    if (($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['user_id'])) {
        throw new DomainException('Administrator access is required.', 403);
    }
    $account = synk_find_useraccount_by_id($conn, (int)$_SESSION['user_id']);
    if (!$account || $account['status'] !== 'active' || !synk_useraccount_has_active_role($conn, $account, 'admin')) {
        throw new DomainException('Administrator access is no longer available.', 403);
    }
    return $account;
}

function synk_faculty_appointment_integer($value, bool $allowZero = false): int
{
    if ((!is_int($value) && !is_string($value)) || !preg_match('/^(0|[1-9][0-9]{0,9})$/D', (string)$value)
        || (float)$value > 4294967295 || (!$allowZero && (int)$value === 0)) {
        throw new DomainException('Choose valid appointment details.', 422);
    }
    return (int)$value;
}

function synk_faculty_college_appointments(mysqli $conn, int $collegeId, array $term): array
{
    if (!synk_faculty_college_appointments_ready($conn)) return [];
    $stmt = $conn->prepare("SELECT a.*,f.first_name,f.middle_name,f.last_name,f.ext_name,
            COALESCE(p.program_code,'') AS program_code,COALESCE(p.program_name,'') AS program_name,
            COALESCE(p.major,'') AS major
        FROM tbl_faculty_college_appointments a JOIN tbl_faculty f ON f.faculty_id=a.faculty_id
        LEFT JOIN tbl_program p ON p.program_id=a.program_id AND p.college_id=a.college_id
        WHERE a.college_id=? AND a.ay_id=? AND a.semester=?
        ORDER BY a.appointment_role,p.program_code,p.major,a.appointment_id");
    $stmt->bind_param('iii', $collegeId, $term['ay_id'], $term['semester']);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function synk_faculty_appointment_save(mysqli $conn, array $input): void
{
    $account = synk_faculty_appointment_require_admin($conn);
    $token = $input['csrf_token'] ?? null;
    if (!is_string($token) || empty($_SESSION['faculty_appointment_csrf'])
        || !hash_equals($_SESSION['faculty_appointment_csrf'], $token)) {
        throw new DomainException('Refresh the page before saving an appointment.', 403);
    }
    $collegeId = synk_faculty_appointment_integer($input['college_id'] ?? null);
    $ayId = synk_faculty_appointment_integer($input['ay_id'] ?? null);
    $semester = synk_faculty_appointment_integer($input['semester'] ?? null);
    $revision = synk_faculty_appointment_integer($input['revision'] ?? null, true);
    $appointmentId = synk_faculty_appointment_integer($input['appointment_id'] ?? '0', true);
    $action = $input['action'] ?? '';
    if (!in_array($action, ['save','end'], true) || $semester > 3) throw new DomainException('Choose valid appointment details.', 422);

    $stmt = $conn->prepare("SELECT c.college_id FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id
        JOIN tbl_academic_years ay ON ay.ay_id=? WHERE c.college_id=? AND c.status='active' AND cp.status='active'");
    $stmt->bind_param('ii', $ayId, $collegeId);
    $stmt->execute();
    $validScope = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    if (!$validScope) throw new DomainException('Choose an active college and an available academic year.', 422);

    $existing = null;
    if ($appointmentId > 0) {
        $stmt = $conn->prepare('SELECT * FROM tbl_faculty_college_appointments WHERE appointment_id=? AND college_id=? AND ay_id=? AND semester=?');
        $stmt->bind_param('iiii', $appointmentId, $collegeId, $ayId, $semester);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$existing || (int)$existing['revision'] !== $revision) throw new DomainException('This appointment changed. Reload the page before saving.', 409);
    } elseif ($revision !== 0 || $action === 'end') {
        throw new DomainException('Reload the appointment before saving.', 409);
    }

    $actorId = (int)$account['user_id'];
    if ($action === 'end') {
        $stmt = $conn->prepare("UPDATE tbl_faculty_college_appointments SET status='inactive',revision=revision+1,
            updated_by=?,updated_at=CURRENT_TIMESTAMP,record_source='manual' WHERE appointment_id=? AND revision=?");
        $stmt->bind_param('iii', $actorId, $appointmentId, $revision);
    } else {
        $facultyId = synk_faculty_appointment_integer($input['faculty_id'] ?? null);
        $role = $existing ? $existing['appointment_role'] : ($input['appointment_role'] ?? '');
        $programId = $existing ? (int)$existing['program_id'] : synk_faculty_appointment_integer($input['program_id'] ?? '0', true);
        if (!in_array($role, ['dean','chair'], true) || ($role === 'dean' && $programId !== 0) || ($role === 'chair' && $programId === 0)) {
            throw new DomainException('Choose College Dean, or Program Chair with its program.', 422);
        }
        $stmt = $conn->prepare("SELECT faculty_id FROM tbl_faculty WHERE faculty_id=? AND status IN ('active','on_leave')");
        $stmt->bind_param('i', $facultyId);
        $stmt->execute();
        $validFaculty = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        if (!$validFaculty) throw new DomainException('Choose an active faculty member or a faculty member on leave.', 422);
        if ($role === 'chair') {
            $stmt = $conn->prepare("SELECT program_id FROM tbl_program WHERE program_id=? AND college_id=? AND status='active'");
            $stmt->bind_param('ii', $programId, $collegeId);
            $stmt->execute();
            $validProgram = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if (!$validProgram) throw new DomainException('Choose an active program belonging to this college.', 422);
        }
        if ($existing) {
            $stmt = $conn->prepare("UPDATE tbl_faculty_college_appointments SET faculty_id=?,status='active',revision=revision+1,
                updated_by=?,updated_at=CURRENT_TIMESTAMP,record_source='manual' WHERE appointment_id=? AND revision=?");
            $stmt->bind_param('iiii', $facultyId, $actorId, $appointmentId, $revision);
        } else {
            $stmt = $conn->prepare('INSERT INTO tbl_faculty_college_appointments
                (college_id,ay_id,semester,appointment_role,program_id,faculty_id,updated_by) VALUES (?,?,?,?,?,?,?)');
            $stmt->bind_param('iiisiii', $collegeId, $ayId, $semester, $role, $programId, $facultyId, $actorId);
        }
    }
    try {
        if (!$stmt->execute()) throw new RuntimeException('Unable to save the appointment.');
        if ($stmt->affected_rows !== 1) throw new DomainException('This appointment changed. Reload the page before saving.', 409);
    } catch (mysqli_sql_exception $exception) {
        if ($exception->getCode() === 1062) throw new DomainException('This leadership post already has a record. Use Replace to update it.', 409);
        throw $exception;
    } finally {
        $stmt->close();
    }
}
