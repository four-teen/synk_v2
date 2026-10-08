<?php
require_once __DIR__ . '/schedule_tba_helper.php';

require_once __DIR__ . '/college_dean_dashboard_helper.php';
require_once __DIR__ . '/professor_portal_helper.php';
require_once __DIR__ . '/student_management_exam_permits_helper.php';
require_once __DIR__ . '/faculty_profile_image_helper.php';

function synk_dean_roster_execute(mysqli_stmt $stmt): void
{
    if (!$stmt->execute()) throw new RuntimeException('Unable to complete a roster database operation.');
}

// Reading and importing rosters never creates tables or changes class assignments.
function synk_dean_roster_ready(mysqli $conn): bool
{
    foreach (['tbl_student_management', 'tbl_student_management_enrolled_subjects', 'tbl_offering_enrollee_counts'] as $table) {
        if (!synk_table_exists($conn, $table)) return false;
    }
    return synk_student_identity_schema_ready($conn);
}

function synk_dean_workload_faculties(mysqli $conn, array $scope, array $term, int $facultyId = 0): array
{
    $source = synk_professor_workload_assignment_source_sql();
    // Profile uploads belong to login accounts, not to faculty record IDs.
    $profileAccount = 'NULL';
    $profileJoin = '';
    if (synk_professor_faculty_link_table_exists($conn)) {
        $profileAccount = 'profile_account.user_id';
        $profileJoin = "LEFT JOIN tbl_useraccount_faculty_links profile_link ON profile_link.faculty_id=f.faculty_id
            LEFT JOIN tbl_useraccount profile_account ON profile_account.user_id=profile_link.user_id AND profile_account.status='active'";
    }
    $stmt = $conn->prepare("SELECT DISTINCT f.faculty_id,
            CONCAT(f.last_name, ', ', TRIM(CONCAT_WS(' ', f.first_name, NULLIF(f.middle_name,''), NULLIF(f.ext_name,'')))) AS faculty_name,
            {$profileAccount} AS profile_user_id
        FROM tbl_faculty f {$profileJoin} WHERE f.status = 'active' AND (
            EXISTS (SELECT 1 FROM tbl_college_faculty cf WHERE cf.faculty_id=f.faculty_id
                AND cf.college_id=? AND cf.status='active' AND cf.ay_id=? AND cf.semester=?)
            OR EXISTS (SELECT 1 {$source} INNER JOIN tbl_program p ON p.program_id=po.program_id
                WHERE fw.faculty_id=f.faculty_id AND p.college_id=? AND fw.ay_id=? AND fw.semester=?))
        AND (?=0 OR f.faculty_id=?)
        ORDER BY f.last_name, f.first_name, f.faculty_id");
    $stmt->bind_param('iiiiiiii', $scope['college_id'], $term['ay_id'], $term['semester'], $scope['college_id'], $term['ay_id'], $term['semester'], $facultyId, $facultyId);
    synk_dean_roster_execute($stmt);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $row['faculty_id'] = (int)$row['faculty_id'];
        $row['profile_image_url'] = synk_faculty_profile_image_url((int)$row['profile_user_id']);
        unset($row['profile_user_id']);
    }
    return $rows;
}

function synk_dean_workload_classes(mysqli $conn, array $scope, array $term, int $facultyId): array
{
    $source = synk_professor_workload_assignment_source_sql();
    $stmt = $conn->prepare("SELECT DISTINCT po.offering_id, po.program_id, po.section_id, po.year_level,
            sm.sub_id AS subject_id, sm.sub_code AS subject_code, sm.sub_description AS descriptive_title,
            sec.section_name, sec.full_section AS section_display, p.program_code,
            assigned_faculty.first_name, assigned_faculty.last_name, assigned_faculty.middle_name, assigned_faculty.ext_name,
            cs.schedule_id, cs.schedule_type, cs.schedule_status, cs.days_json, cs.time_start, cs.time_end, cs.room_id,
            TRIM(CONCAT_WS(' - ', NULLIF(r.room_code,''), NULLIF(r.room_name,''))) AS room_name
        {$source} INNER JOIN tbl_program p ON p.program_id=po.program_id
        LEFT JOIN tbl_rooms r ON r.room_id=cs.room_id
        WHERE p.college_id=? AND fw.ay_id=? AND fw.semester=? AND fw.faculty_id=?
        ORDER BY sm.sub_code, sec.full_section, po.offering_id, cs.schedule_type, cs.time_start, cs.schedule_id");
    $stmt->bind_param('iiii', $scope['college_id'], $term['ay_id'], $term['semester'], $facultyId);
    synk_dean_roster_execute($stmt);
    $result = $stmt->get_result();
    $classes = [];
    while ($row = $result->fetch_assoc()) {
        $id = (int)$row['offering_id'];
        if (!isset($classes[$id])) {
            $classes[$id] = array_merge($scope, $term, array_intersect_key($row, array_flip([
                'offering_id','program_id','section_id','year_level','subject_id','subject_code','descriptive_title',
                'section_name','section_display','program_code','first_name','last_name','middle_name','ext_name'
            ])));
            foreach (['offering_id','program_id','section_id','year_level','subject_id'] as $key) $classes[$id][$key] = (int)$classes[$id][$key];
            $classes[$id]['faculty_id'] = $facultyId;
            $classes[$id]['faculty_name'] = trim(implode(' ', array_filter([
                $row['first_name'], $row['middle_name'], $row['last_name'], $row['ext_name']
            ])));
            $classes[$id]['schedules'] = [];
            $classes[$id]['roster_count'] = 0;
        }
        $days = json_decode((string)$row['days_json'], true);
        $classes[$id]['schedules'][] = [
            'schedule_id' => (int)$row['schedule_id'], 'type' => $row['schedule_type'],
            'days' => is_array($days) && $days ? implode(', ', $days) : 'TBA',
            'schedule_status' => synk_schedule_status($row),
            'time_start' => (string)$row['time_start'], 'time_end' => (string)$row['time_end'],
            'room_id' => (int)$row['room_id'], 'room' => trim((string)$row['room_name']) !== '' ? $row['room_name'] : 'TBA',
        ];
    }
    $stmt->close();
    if ($classes && synk_dean_roster_ready($conn)) {
        $ids = array_keys($classes);
        $params = array_merge([$scope['college_id'], $scope['campus_id'], $term['ay_id'], $term['semester']], $ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT po.offering_id, COUNT(DISTINCT es.student_id) AS roster_count,
                MAX(es.created_at) AS last_imported_at
            FROM tbl_prospectus_offering po JOIN tbl_prospectus_subjects ps ON ps.ps_id=po.ps_id
            JOIN tbl_student_management_enrolled_subjects es ON (es.offering_id=po.offering_id OR es.offering_id=0)
                AND es.program_id=po.program_id AND es.section_id=po.section_id AND es.subject_id=ps.sub_id
            JOIN tbl_student_management s ON s.student_id=es.student_id
            WHERE es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=? AND es.is_active=1 AND po.offering_id IN ({$placeholders})
            GROUP BY po.offering_id");
        synk_bind_dynamic_params($stmt, str_repeat('i', count($params)), $params);
        synk_dean_roster_execute($stmt);
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $classes[(int)$row['offering_id']]['roster_count'] = (int)$row['roster_count'];
            $classes[(int)$row['offering_id']]['last_imported_at'] = $row['last_imported_at'];
        }
        $stmt->close();
    }
    return array_values($classes);
}

function synk_dean_workload_class(mysqli $conn, array $scope, array $term, int $facultyId, int $offeringId): array
{
    foreach (synk_dean_workload_classes($conn, $scope, $term, $facultyId) as $class) {
        if ($class['offering_id'] === $offeringId) return $class;
    }
    throw new DomainException('This class is not assigned to the selected faculty in your college and current term.');
}

function synk_dean_roster_rows(mysqli $conn, array $class): array
{
    $stmt = $conn->prepare("SELECT s.student_id, s.student_number, s.last_name, s.first_name, s.middle_name, s.suffix_name,
            MAX(es.created_at) AS imported_at
        FROM tbl_student_management_enrolled_subjects es
        INNER JOIN tbl_student_management s ON s.student_id=es.student_id
        WHERE (es.offering_id=? OR es.offering_id=0) AND es.program_id=? AND es.section_id=? AND es.subject_id=?
          AND es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=? AND es.is_active=1
        GROUP BY s.student_id, s.student_number, s.last_name, s.first_name, s.middle_name, s.suffix_name
        ORDER BY s.last_name, s.first_name, s.student_number");
    $stmt->bind_param('iiiiiiii', $class['offering_id'], $class['program_id'], $class['section_id'], $class['subject_id'], $class['college_id'], $class['campus_id'], $class['ay_id'], $class['semester']);
    synk_dean_roster_execute($stmt);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $row['student_id'] = (int)$row['student_id'];
        $row['student_number'] = (string)$row['student_number'];
        $row['student_name'] = $row['last_name'] . ', ' . trim(implode(' ', array_filter([
            $row['first_name'], $row['middle_name'], $row['suffix_name']
        ])));
    }
    return $rows;
}

function synk_dean_roster_key(string $value): string
{
    return synk_student_identity_name_key($value);
}

function synk_dean_roster_parse(string $path): array
{
    if (!is_file($path) || filesize($path) > 2 * 1024 * 1024) throw new DomainException('Choose a CSV file smaller than 2 MB.');
    $bytes = file_get_contents($path);
    if ($bytes === false) throw new RuntimeException('Unable to read the CSV file.');
    $handle = fopen('php://temp', 'w+b');
    if (!$handle) throw new RuntimeException('Unable to read the CSV file.');
    fwrite($handle, synk_student_identity_csv_utf8($bytes)); rewind($handle);
    $rows = [];
    try {
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
            if (count($rows) > 2010) throw new DomainException('A class CSV may contain up to 2,000 student rows.');
        }
    } finally { fclose($handle); }
    if (count($rows) < 8 || synk_dean_roster_key(synk_student_management_csv_cell($rows[3], 0)) !== 'SUBJECT SECTION'
        || synk_dean_roster_key(synk_student_management_csv_cell($rows[6], 0)) !== 'NO'
        || synk_dean_roster_key(synk_student_management_csv_cell($rows[6], 1)) !== 'STUDENT S NAME'
        || synk_dean_roster_key(synk_student_management_csv_cell($rows[6], 4)) !== 'ID NO') {
        throw new DomainException('Use the Exam Permits CSV format with Subject/Section, faculty, Student\'s Name, and ID No.');
    }
    $parsed = [
        'campus_header' => synk_student_management_csv_cell($rows[0], 0),
        'subject_section_raw' => synk_student_management_csv_cell($rows[3], 3),
        'descriptive_title' => synk_student_management_csv_cell($rows[4], 3),
        'class_schedule_text' => synk_student_management_csv_cell($rows[4], 9),
        'faculty_name' => synk_student_management_csv_cell($rows[5], 3),
        'room_name' => synk_student_management_csv_cell($rows[5], 9),
        'students' => [], 'duplicate_rows' => 0,
    ];
    $seen = [];
    for ($index = 7; $index < count($rows); $index++) {
        $row = $rows[$index];
        $no = synk_student_management_csv_cell($row, 0);
        $name = synk_student_management_csv_cell($row, 1);
        $id = synk_student_management_csv_cell($row, 4);
        $footer = preg_replace('/[^A-Z]/', '', strtoupper($no . ' ' . $name));
        if (in_array($footer, ['NOTHINGFOLLOWS','DATEPRINTED'], true)) break;
        if ($no === '' && $name === '' && $id === '') continue;
        if (!ctype_digit($no) || strpos($name, ',') === false) {
            throw new DomainException('CSV row ' . ($index + 1) . ' needs a row number, numeric student ID, and name in Last Name, First Name format.');
        }
        try { $number = synk_student_identity_number($id); }
        catch (DomainException $e) { throw new DomainException('CSV row ' . ($index + 1) . ': ' . $e->getMessage()); }
        $student = synk_student_management_parse_csv_student_name($name);
        if ($student['last_name'] === '' || $student['first_name'] === '') throw new DomainException('CSV row ' . ($index + 1) . ' has an incomplete student name.');
        foreach (['last_name'=>150, 'first_name'=>150, 'middle_name'=>150, 'suffix_name'=>50] as $field=>$limit) {
            $length = function_exists('mb_strlen') ? mb_strlen($student[$field], 'UTF-8') : strlen($student[$field]);
            if ($length > $limit || preg_match('/\p{Cc}/u', $student[$field])) throw new DomainException('CSV row ' . ($index + 1) . ' has an invalid or excessively long name.');
        }
        if (isset($seen[$number])) {
            if (synk_dean_roster_key($seen[$number]['student_name_raw']) !== synk_dean_roster_key($name)) {
                throw new DomainException('Student ID ' . $id . ' has conflicting names in the CSV.');
            }
            $parsed['duplicate_rows']++;
            continue;
        }
        $student['student_number'] = $number;
        $student['source_row_number'] = $index + 1;
        $seen[$number] = $student;
        $parsed['students'][] = $student;
        if (count($parsed['students']) > 2000) throw new DomainException('A class CSV may contain up to 2,000 students.');
    }
    if (!$parsed['students']) throw new DomainException('The CSV has no student rows to import.');
    return $parsed;
}

function synk_dean_roster_validate_header(array $parsed, array $class): array
{
    $header = $parsed['subject_section_raw'];
    if (!preg_match('/^(.+)\s*-\s*([^-]+)$/u', $header, $parts)) throw new DomainException('The CSV Subject/Section header is missing or incomplete.');
    $csvCode = trim($parts[1]);
    $csvCode = preg_replace('/\s*-\s*' . preg_quote($class['college_code'], '/') . '\s*$/i', '', $csvCode);
    // The old exports spell the same elective code ELECT rather than ELEC.
    $canonical = static function (string $code): string {
        return str_replace('ELECT', 'ELEC', str_replace(' ', '', synk_dean_roster_key($code)));
    };
    if ($canonical($csvCode) !== $canonical($class['subject_code'])) throw new DomainException('The CSV subject does not match the selected class.');
    $csvSection = str_replace(' ', '', synk_dean_roster_key($parts[2]));
    $section = $class['program_code'] . ' ' . $class['year_level'] . $class['section_name'];
    $allowed = [str_replace(' ', '', synk_dean_roster_key($section)), str_replace(' ', '', synk_dean_roster_key($class['section_display']))];
    if (!in_array($csvSection, $allowed, true)) throw new DomainException('The CSV program and section do not match the selected class.');
    $campus = preg_replace('/^.*?\s+-\s+/', '', $parsed['campus_header']);
    if (synk_dean_roster_key($campus) !== synk_dean_roster_key($class['campus_name'])) throw new DomainException('The CSV campus does not match your assigned college.');
    $facultyKey = synk_dean_roster_key($parsed['faculty_name']);
    $facultyVariants = [];
    foreach (['', (string)$class['middle_name']] as $middle) {
        $facultyVariants[] = synk_dean_roster_key($class['first_name'] . ' ' . $middle . ' ' . $class['last_name'] . ' ' . $class['ext_name']);
        $facultyVariants[] = synk_dean_roster_key($class['last_name'] . ', ' . $class['first_name'] . ' ' . $middle . ' ' . $class['ext_name']);
    }
    if (!in_array($facultyKey, $facultyVariants, true)) throw new DomainException('The CSV faculty does not match the selected faculty assignment.');
    $notes = [];
    if (synk_dean_roster_key($parsed['descriptive_title']) !== synk_dean_roster_key($class['descriptive_title'])) {
        $notes[] = 'The CSV title differs. The assigned subject title will be retained.';
    }
    $notes[] = 'Students will be added to ' . $class['term_text'] . '. Existing students in this class will be skipped.';
    $notes[] = 'Students are matched by their exact ID. Existing student profiles are reused across subjects and kept unchanged.';
    $notes[] = 'The assigned schedules and rooms will be retained. Lecture and laboratory share this student list.';
    return $notes;
}

function synk_dean_roster_names_match(array $csv, array $master): bool
{
    return synk_student_identity_names_match($csv, $master);
}

function synk_dean_roster_preview(mysqli $conn, array $class, array $parsed): array
{
    $notes = synk_dean_roster_validate_header($parsed, $class);
    $numbers = array_column($parsed['students'], 'student_number');
    $placeholders = implode(',', array_fill(0, count($numbers), '?'));
    $stmt = $conn->prepare("SELECT student_id, student_number, last_name, first_name, middle_name, suffix_name
        FROM tbl_student_management WHERE student_number IN ({$placeholders}) ORDER BY student_id");
    synk_bind_dynamic_params($stmt, str_repeat('s', count($numbers)), $numbers);
    synk_dean_roster_execute($stmt);
    $result = $stmt->get_result();
    $masters = [];
    while ($row = $result->fetch_assoc()) $masters[(string)$row['student_number']][] = $row;
    $stmt->close();
    $existingNumbers = array_fill_keys(array_column(synk_dean_roster_rows($conn, $class), 'student_number'), true);
    $summary = ['students'=>count($numbers), 'add'=>0, 'existing'=>0, 'new_profiles'=>0, 'conflicts'=>0, 'duplicate_rows'=>$parsed['duplicate_rows']];
    $rows = [];
    foreach ($parsed['students'] as $student) {
        $number = $student['student_number'];
        $matches = $masters[$number] ?? [];
        $student['student_id'] = count($matches) === 1 ? (int)$matches[0]['student_id'] : 0;
        $student['issue'] = '';
        if (count($matches) > 1) $student['issue'] = 'Multiple student profiles use this ID. Resolve the duplicate profiles first.';
        elseif ($matches && !synk_dean_roster_names_match($student, $matches[0])) $student['issue'] = 'This ID belongs to a different name in the student records. Review it before importing.';
        if ($student['issue'] !== '') {
            $student['action'] = 'conflict'; $summary['conflicts']++;
        } elseif (isset($existingNumbers[$number])) {
            $student['action'] = 'existing'; $summary['existing']++;
        } else {
            $student['action'] = 'add'; $summary['add']++;
            if (!$matches) $summary['new_profiles']++;
        }
        $rows[] = $student;
    }
    return ['rows'=>$rows, 'summary'=>$summary, 'notes'=>$notes, 'can_confirm'=>$summary['conflicts'] === 0 && $summary['add'] > 0];
}

function synk_dean_roster_save(mysqli $conn, array $scope, array $term, array $pending, int $userId): array
{
    if (!synk_dean_roster_ready($conn)) throw new DomainException('Student identity safeguards must be installed before importing.');
    if ($pending['user_id'] !== $userId || $pending['college_id'] !== $scope['college_id']
        || $pending['ay_id'] !== $term['ay_id'] || $pending['semester'] !== $term['semester']
        || $pending['expires_at'] < time()) throw new DomainException('This preview has expired or the college or term has changed. Preview the CSV again.');
    // Serializes Dean imports across accounts, including profile creation for overlapping student IDs.
    $lock = $conn->query("SELECT GET_LOCK('synk_college_dean_roster_import', 5) AS acquired")->fetch_assoc();
    if ((int)$lock['acquired'] !== 1) throw new DomainException('Another import is being saved. Please try again shortly.');
    $transaction = false;
    try {
        if (!$conn->begin_transaction()) throw new RuntimeException('Unable to begin the roster import transaction.');
        $transaction = true;
        $offeringId = (int)$pending['offering_id'];
        $stmt = $conn->prepare('SELECT offering_id FROM tbl_prospectus_offering WHERE offering_id=? FOR UPDATE');
        $stmt->bind_param('i', $offeringId); synk_dean_roster_execute($stmt); $stmt->get_result()->fetch_assoc(); $stmt->close();
        // Recheck the account, assignment, term, identities and duplicate membership at confirmation time.
        $freshScope = synk_college_dean_dashboard_scope($conn);
        $freshTerm = synk_fetch_current_academic_term($conn);
        if (!$freshScope || $freshScope['college_id'] !== $scope['college_id']
            || $freshTerm['ay_id'] !== $term['ay_id'] || $freshTerm['semester'] !== $term['semester']) {
            throw new DomainException('Your college access or the academic term changed. Reload this page.');
        }
        $class = synk_dean_workload_class($conn, $freshScope, $freshTerm, $pending['faculty_id'], $offeringId);
        $preview = synk_dean_roster_preview($conn, $class, $pending['parsed']);
        if ($preview['summary']['conflicts'] > 0) throw new DomainException('Student records have changed and contain conflicts. Preview the CSV again.');
        $added = 0; $profiles = 0; $skipped = $preview['summary']['existing'];
        $batch = 'dean-' . bin2hex(random_bytes(24));
        $fileName = $pending['file_name'];
        $scheduleParts = []; $roomParts = [];
        foreach ($class['schedules'] as $schedule) {
            $scheduleParts[] = $schedule['type'] . ' ' . $schedule['days'] . ' ' . substr($schedule['time_start'],0,5) . '-' . substr($schedule['time_end'],0,5);
            if ($schedule['room'] !== '') $roomParts[$schedule['room']] = true;
        }
        $scheduleText = substr(implode('; ', $scheduleParts), 0, 255);
        $roomText = substr(implode('; ', array_keys($roomParts)), 0, 255);
        // A combined lecture/lab roster has no single room; preserve its schedule display instead.
        $roomId = count($roomParts) === 1 ? (int)$class['schedules'][0]['room_id'] : 0;
        foreach ($preview['rows'] as $student) {
            if ($student['action'] !== 'add') continue;
            $record = $student + [
                'ay_id'=>$class['ay_id'], 'semester'=>$class['semester'], 'source_sheet_name'=>'DEAN CSV ROSTER',
                'source_file_name'=>$fileName, 'year_level'=>$class['year_level'], 'email_address'=>'',
                'program_id'=>$class['program_id'], 'uploaded_by'=>$userId,
            ];
            $identity = synk_student_identity_create_or_reuse($conn, $record);
            $studentId = $identity['student_id'];
            if ($identity['created']) $profiles++;
            $stmt = $conn->prepare("INSERT INTO tbl_student_management_enrolled_subjects
                (import_batch_key,source_file_name,campus_id,college_id,program_id,ay_id,semester,year_level,
                 section_id,section_text,offering_id,subject_id,subject_code,descriptive_title,student_id,faculty_id,
                 room_id,room_text,schedule_text,source_row_number,uploaded_by,is_active)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
                ON DUPLICATE KEY UPDATE student_enrollment_id=student_enrollment_id");
            $params = [$batch,$fileName,$class['campus_id'],$class['college_id'],$class['program_id'],$class['ay_id'],
                $class['semester'],$class['year_level'],$class['section_id'],$class['section_display'],$class['offering_id'],
                $class['subject_id'],$class['subject_code'],$class['descriptive_title'],$studentId,$class['faculty_id'],
                $roomId,$roomText,$scheduleText,$student['source_row_number'],$userId];
            synk_bind_dynamic_params($stmt, 'ssiiiiiiisiissiiissii', $params);
            synk_dean_roster_execute($stmt);
            if ($stmt->affected_rows === 1) $added++; else $skipped++;
            $stmt->close();
        }
        $total = count(synk_dean_roster_rows($conn, $class));
        if ($added > 0) {
            $stmt = $conn->prepare("INSERT INTO tbl_offering_enrollee_counts
                (offering_id,total_enrollees,source_kind,created_by,updated_by)
                VALUES (?,?,'csv_roster',?,?) ON DUPLICATE KEY UPDATE
                total_enrollees=VALUES(total_enrollees), source_kind='csv_roster', updated_by=VALUES(updated_by)");
            $stmt->bind_param('iiii', $offeringId, $total, $userId, $userId); synk_dean_roster_execute($stmt); $stmt->close();
        }
        if (!$conn->commit()) throw new RuntimeException('Unable to commit the roster import.');
        $transaction = false;
        return ['added'=>$added, 'skipped'=>$skipped, 'new_profiles'=>$profiles, 'roster_count'=>$total];
    } catch (Throwable $e) {
        if ($transaction) $conn->rollback();
        throw $e;
    } finally { $conn->query("SELECT RELEASE_LOCK('synk_college_dean_roster_import')"); }
}
