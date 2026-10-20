<?php
require_once __DIR__ . '/schedule_tba_helper.php';

require_once __DIR__ . '/college_dean_dashboard_helper.php';
require_once __DIR__ . '/professor_portal_helper.php';
require_once __DIR__ . '/student_management_exam_permits_helper.php';
require_once __DIR__ . '/faculty_profile_image_helper.php';
require_once __DIR__ . '/college_dean_roster_diagnostics.php';
require_once __DIR__ . '/student_class_roster_audit_helper.php';

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
    $current = synk_fetch_current_academic_term($conn);
    $statusFilter = (int)$term['ay_id'] === (int)$current['ay_id'] && (int)$term['semester'] === (int)$current['semester']
        ? "f.status = 'active' AND " : '';
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
        FROM tbl_faculty f {$profileJoin} WHERE {$statusFilter}(
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
        $row['profile_image_url'] = synk_faculty_profile_image_url((int)$row['profile_user_id'], $row['faculty_id']);
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
    $auditReady=synk_roster_audit_ready($conn);
    $auditFields=$auditReady?"MAX(a.audit_id) AS manual_audit_id,MAX(a.reason_code) AS manual_reason,MAX(a.reason_note) AS manual_note,MAX(a.actor_name) AS added_by,MAX(a.recorded_at) AS manually_added_at":"NULL AS manual_audit_id,NULL AS manual_reason,NULL AS manual_note,NULL AS added_by,NULL AS manually_added_at";
    $auditJoin=$auditReady?'LEFT JOIN tbl_student_class_roster_audit a ON a.student_enrollment_id=es.student_enrollment_id':'';
    $stmt = $conn->prepare("SELECT s.student_id, s.student_number, s.last_name, s.first_name, s.middle_name, s.suffix_name,
            MAX(es.created_at) AS imported_at,{$auditFields}
        FROM tbl_student_management_enrolled_subjects es
        INNER JOIN tbl_student_management s ON s.student_id=es.student_id
        {$auditJoin}
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
        $row['entry_source']=!empty($row['manual_audit_id'])?'manual':'csv';
        $row['manual_reason_label']=synk_roster_manual_reasons()[$row['manual_reason'] ?? ''] ?? '';
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
    if (!is_file($path)) throw new DomainException('The CSV upload is no longer available. Choose the file again.');
    if (filesize($path) > 2 * 1024 * 1024) throw new SynkDeanRosterValidationException([synk_dean_roster_issue(null,'','File size',
        number_format(filesize($path)/1024/1024,2) . ' MB','Up to 2 MB','The CSV exceeds the import size limit.', 'Export only the selected class student list.')]);
    $bytes = file_get_contents($path);
    if ($bytes === false) throw new RuntimeException('Unable to read the CSV file.');
    $handle = fopen('php://temp', 'w+b');
    if (!$handle) throw new RuntimeException('Unable to read the CSV file.');
    $encoding = substr($bytes,0,3) === "\xEF\xBB\xBF" ? 'UTF-8 BOM'
        : (substr($bytes,0,2) === "\xFF\xFE" ? 'UTF-16LE'
        : (substr($bytes,0,2) === "\xFE\xFF" ? 'UTF-16BE' : (preg_match('//u',$bytes) === 1 ? 'UTF-8' : 'Windows-1252')));
    try { $decoded = synk_student_identity_csv_utf8($bytes); }
    catch (DomainException $e) {
        fclose($handle);
        throw new SynkDeanRosterValidationException([synk_dean_roster_issue(null,'','File encoding','Unsupported or damaged text',
            'CSV UTF-8', $e->getMessage(), 'Export the original file as CSV UTF-8. Renaming the file does not change its encoding.')]);
    }
    $text = synk_dean_roster_repair_csv_text($decoded);
    fwrite($handle, $text); rewind($handle);
    $rows = [];
    try {
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
            if (count($rows) > 2010) throw new SynkDeanRosterValidationException([synk_dean_roster_issue(2011,'','CSV row limit',
                'More than 2,010 total CSV rows','Up to 2,000 student rows plus the export headers',
                'The CSV exceeds the class import row limit.', 'Export only the selected class student list.')]);
        }
    } finally { fclose($handle); }
    $issues = [];
    foreach ([[3,0,'A','Subject/Section label','Subject/Section'],[6,0,'A','Row number label','No.'],
        [6,1,'B','Student name label',"Student's Name"],[6,4,'E','Student ID label','ID No.']] as $check) {
        [$index,$columnIndex,$column,$field,$expected] = $check;
        $found = synk_student_management_csv_cell($rows[$index] ?? [], $columnIndex);
        if (synk_dean_roster_key($found) !== synk_dean_roster_key($expected)) {
            $issues[] = synk_dean_roster_issue($index+1,$column,$field,$found,$expected,
                'The required CSV label is missing or in a different column.',
                'Use the original Exam Permits export. Keep the header rows and comma-separated columns.');
        }
    }
    if ($issues) {
        if (count($rows[6] ?? []) === 1 && preg_match('/[;\t]/', (string)($rows[6][0] ?? ''))) {
            $issues = [synk_dean_roster_issue(7,'','CSV separator','Semicolon or tab-separated columns','Comma-separated columns',
                'The file uses a different column separator.', 'Export as CSV UTF-8 (comma delimited), then preview again.')];
        }
        throw new SynkDeanRosterValidationException($issues);
    }
    $parsed = [
        'campus_header' => synk_student_management_csv_cell($rows[0], 0),
        'subject_section_raw' => synk_student_management_csv_cell($rows[3], 3),
        'descriptive_title' => synk_student_management_csv_cell($rows[4], 3),
        'class_schedule_text' => synk_student_management_csv_cell($rows[4], 9),
        'faculty_name' => synk_student_management_csv_cell($rows[5], 3),
        'room_name' => synk_student_management_csv_cell($rows[5], 9),
        'students' => [], 'duplicate_rows' => 0, 'encoding'=>$encoding, 'encoding_repaired'=>$text !== $decoded,
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
        $rowNumber = $index + 1;
        $rowIssues = [];
        if (!ctype_digit($no)) $rowIssues[] = synk_dean_roster_issue($rowNumber,'A','Row number',$no,'Digits only',
            'The No. value is missing or is not a whole number.', 'Enter the student row number in column A.');
        if (strpos($name, ',') === false) $rowIssues[] = synk_dean_roster_issue($rowNumber,'B','Student name',$name,'Last Name, First Name',
            'The name is missing the comma separating the last and first names.', 'Keep the complete name in column B, with a comma after the last name.');
        if (synk_dean_roster_damaged_name($name)) $rowIssues[] = synk_dean_roster_issue($rowNumber,'B','Student name',$name,'The original accented name',
            'The name already contains ? or a replacement character; the original letters are missing.',
            'Correct the name using the original record, then export as CSV UTF-8. Saving an already damaged name as UTF-8 cannot restore it.');
        $number = '';
        try { $number = synk_student_identity_number($id); }
        catch (DomainException $e) { $rowIssues[] = synk_dean_roster_issue($rowNumber,'E','Student ID',$id,'1–32 digits, preserving leading zeros',
            $e->getMessage(), 'Correct ID No. in column E. Use text cells for IDs; do not use decimals or scientific notation.'); }
        $student = synk_student_management_parse_csv_student_name($name);
        if (strpos($name, ',') !== false && ($student['last_name'] === '' || $student['first_name'] === '')) {
            $rowIssues[] = synk_dean_roster_issue($rowNumber,'B','Student name',$name,'Last Name, First Name',
                'The last name or first name is empty.', 'Enter both the last and first names in column B.');
        }
        foreach (['last_name'=>150, 'first_name'=>150, 'middle_name'=>150, 'suffix_name'=>50] as $field=>$limit) {
            $length = function_exists('mb_strlen') ? mb_strlen($student[$field], 'UTF-8') : strlen($student[$field]);
            if ($length > $limit || preg_match('/\p{Cc}/u', $student[$field])) $rowIssues[] = synk_dean_roster_issue($rowNumber,'B',ucfirst(str_replace('_',' ',$field)),
                $student[$field], 'Up to ' . $limit . ' characters without control characters',
                'This part of the name is too long or contains control characters.', 'Correct the name in column B.');
        }
        if ($rowIssues) {
            $issues = array_merge($issues,$rowIssues);
            if (count($issues) >= 50) { $issues = array_slice($issues,0,50); break; }
            continue;
        }
        if (isset($seen[$number])) {
            if (synk_dean_roster_key($seen[$number]['student_name_raw']) !== synk_dean_roster_key($name)) {
                $issues[] = synk_dean_roster_issue($rowNumber,'B / E','Duplicate student ID',$id . ': ' . $name,
                    $seen[$number]['student_name_raw'] . ' (row ' . $seen[$number]['source_row_number'] . ')',
                    'This ID has different names in CSV rows ' . $seen[$number]['source_row_number'] . ' and ' . $rowNumber . '.',
                    'Verify the correct student ID and name in both rows.');
                if (count($issues) >= 50) break;
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
    if ($issues) throw new SynkDeanRosterValidationException($issues);
    if (!$parsed['students']) throw new SynkDeanRosterValidationException([synk_dean_roster_issue(8,'A / B / E','Student rows','','At least one student after row 7',
        'The CSV has no student rows to import.', 'Export the class student list, including student names and IDs.')]);
    return $parsed;
}

function synk_dean_roster_validate_header(array $parsed, array $class): array
{
    $issues = [];
    $header = $parsed['subject_section_raw'];
    $section = $class['program_code'] . ' ' . $class['year_level'] . $class['section_name'];
    if (!preg_match('/^(.+)\s*-\s*([^-]+)$/u', $header, $parts)) {
        $issues[] = synk_dean_roster_issue(4,'D','Subject / Section',$header,$class['subject_code'] . ' - ' . $class['section_display'],
            'The Subject/Section value is missing or incomplete.', 'Use the CSV for the selected class. Put the subject code and section in D4, separated by a hyphen.');
    } else {
        $csvCode = trim($parts[1]);
        $csvCode = preg_replace('/\s*-\s*' . preg_quote($class['college_code'], '/') . '\s*$/i', '', $csvCode);
        // Preserve the existing subject-code comparison used for old exports.
        $canonical = static function (string $code): string {
            return str_replace('ELECT', 'ELEC', str_replace(' ', '', synk_dean_roster_key($code)));
        };
        if ($canonical($csvCode) !== $canonical($class['subject_code'])) $issues[] = synk_dean_roster_issue(4,'D','Subject code',$csvCode,$class['subject_code'],
            'The CSV subject does not match the selected class.', 'Select the matching assigned class, or upload its correct CSV.');
        $csvSection = str_replace(' ', '', synk_dean_roster_key($parts[2]));
        $allowed = [str_replace(' ', '', synk_dean_roster_key($section)), str_replace(' ', '', synk_dean_roster_key($class['section_display']))];
        if (!in_array($csvSection, $allowed, true)) $issues[] = synk_dean_roster_issue(4,'D','Program / Section',trim($parts[2]),$class['section_display'],
            'The CSV program and section do not match the selected class.', 'Select the matching assigned section, or upload its correct CSV.');
    }
    $campus = preg_replace('/^.*?\s+-\s+/', '', $parsed['campus_header']);
    if (synk_dean_roster_key($campus) !== synk_dean_roster_key($class['campus_name'])) $issues[] = synk_dean_roster_issue(1,'A','Campus',$parsed['campus_header'],$class['campus_name'],
        'The CSV campus does not match your assigned college.', 'Upload the student list exported for your assigned campus.');
    $facultyKey = synk_dean_roster_faculty_key($parsed['faculty_name']);
    $expectedFaculty = trim(implode(' ',array_filter([$class['first_name'],$class['middle_name'],$class['last_name'],$class['ext_name']])));
    $facultyVariants = [];
    foreach (['', (string)$class['middle_name']] as $middle) {
        $facultyVariants[] = synk_dean_roster_faculty_key($class['first_name'] . ' ' . $middle . ' ' . $class['last_name'] . ' ' . $class['ext_name'], false);
        $facultyVariants[] = synk_dean_roster_faculty_key($class['last_name'] . ', ' . $class['first_name'] . ' ' . $middle . ' ' . $class['ext_name'], false);
    }
    if (synk_dean_roster_damaged_name($expectedFaculty)) {
        $issues[] = synk_dean_roster_issue(6,'D','Faculty record',$parsed['faculty_name'],$expectedFaculty,
            'The selected faculty record contains damaged letters (? or a replacement character).',
            'Correct the faculty record from its verified profile before importing. Changing CSV encoding cannot repair letters already lost in the database.');
    } elseif (synk_dean_roster_damaged_name($parsed['faculty_name'])) {
        $issues[] = synk_dean_roster_issue(6,'D','Faculty',$parsed['faculty_name'],$expectedFaculty,
            'The CSV faculty name contains damaged letters.', 'Correct D6 from the original faculty name, then export as CSV UTF-8.');
    } elseif (!in_array($facultyKey, $facultyVariants, true) && !in_array(synk_dean_roster_faculty_key($parsed['faculty_name'], false), $facultyVariants, true)) {
        $issues[] = synk_dean_roster_issue(6,'D','Faculty',$parsed['faculty_name'],$expectedFaculty,
            'The CSV faculty does not match the selected faculty assignment.', 'Select the correct faculty and class, or verify the faculty name in D6.');
    }
    if ($issues) throw new SynkDeanRosterValidationException($issues);
    $notes = [];
    if (($parsed['encoding'] ?? 'UTF-8') !== 'UTF-8') $notes[] = 'CSV text decoded from ' . $parsed['encoding'] . ' to UTF-8; accented characters are preserved.';
    if (!empty($parsed['encoding_repaired'])) $notes[] = 'Recovered misencoded accented characters in the CSV. Review the displayed names before confirming.';
    if (synk_dean_roster_key($parsed['faculty_name']) !== synk_dean_roster_key($expectedFaculty)) {
        $notes[] = 'Faculty header matched after allowing omitted middle names, recognized degree suffixes, and accented spelling. The faculty record is retained as ' . $expectedFaculty . '.';
    }
    if (synk_dean_roster_key($parsed['descriptive_title']) !== synk_dean_roster_key($class['descriptive_title'])) {
        $notes[] = 'The CSV title differs. The assigned subject title will be retained.';
    }
    $notes[] = 'Students will be added to ' . $class['term_text'] . '. Existing students in this class will be skipped.';
    $notes[] = 'Students are matched by their exact ID. Existing profiles are reused across subjects; only missing emails may be filled.';
    $notes[] = 'The assigned schedules and rooms will be retained. Lecture and laboratory share this student list.';
    return $notes;
}

function synk_dean_roster_names_match(array $csv, array $master): bool
{
    return synk_student_identity_names_match($csv, $master);
}

function synk_dean_roster_preview(mysqli $conn, array $class, array $parsed): array
{
    require_once __DIR__ . '/student_email_helper.php';
    $notes = synk_dean_roster_validate_header($parsed, $class);
    $numbers = array_column($parsed['students'], 'student_number');
    $placeholders = implode(',', array_fill(0, count($numbers), '?'));
    $stmt = $conn->prepare("SELECT student_id, student_number, last_name, first_name, middle_name, suffix_name, email_address
        FROM tbl_student_management WHERE student_number IN ({$placeholders}) ORDER BY student_id");
    synk_bind_dynamic_params($stmt, str_repeat('s', count($numbers)), $numbers);
    synk_dean_roster_execute($stmt);
    $result = $stmt->get_result();
    $masters = [];
    while ($row = $result->fetch_assoc()) $masters[(string)$row['student_number']][] = $row;
    $stmt->close();
    $catalog=synk_student_email_catalog($conn);
    // New students in the same CSV must also be checked against each other.
    foreach ($parsed['students'] as $incoming) {
        if (!isset($masters[$incoming['student_number']])) {
            $candidate=synk_student_email_candidate($incoming);
            if ($candidate['status']==='ready') $catalog['owners'][$candidate['email']][$incoming['student_number']]=true;
        }
    }
    $existingNumbers = array_fill_keys(array_column(synk_dean_roster_rows($conn, $class), 'student_number'), true);
    $transferredNumbers=synk_roster_transferred_students($conn,$class);
    $summary = ['students'=>count($numbers), 'add'=>0, 'existing'=>0, 'new_profiles'=>0, 'conflicts'=>0, 'duplicate_rows'=>$parsed['duplicate_rows']];
    $rows = [];
    foreach ($parsed['students'] as $student) {
        $number = $student['student_number'];
        $matches = $masters[$number] ?? [];
        $student['student_id'] = count($matches) === 1 ? (int)$matches[0]['student_id'] : 0;
        $student['issue'] = '';
        if (count($matches) > 1) $student['issue'] = 'Multiple student profiles use this ID. Resolve the duplicate profiles first.';
        elseif ($matches && !synk_dean_roster_names_match($student, $matches[0])) {
            $storedName = $matches[0]['last_name'] . ', ' . trim(implode(' ',array_filter([$matches[0]['first_name'],$matches[0]['middle_name'],$matches[0]['suffix_name']])));
            $student['issue'] = 'CSV row ' . $student['source_row_number'] . ', ID ' . $number . ': CSV name "' . $student['student_name_raw']
                . '" differs from the saved name "' . $storedName . '". Verify column B (name) and E (ID); existing profiles are kept unchanged.';
        }
        if ($student['issue']==='' && !isset($existingNumbers[$number]) && isset($transferredNumbers[$number])) {
            $student['issue']='This student was manually transferred to another section of this subject. Review the current class list before changing that transfer.';
        }
        if ($student['issue'] !== '') {
            $student['action'] = 'conflict'; $summary['conflicts']++;
        } elseif (isset($existingNumbers[$number])) {
            $student['action'] = 'existing'; $summary['existing']++;
        } else {
            $student['action'] = 'add'; $summary['add']++;
            if (!$matches) $summary['new_profiles']++;
        }
        $email=synk_student_email_check($matches ? $matches[0] : $student,$catalog['owners']);
        $student['email_address']=$email['status']==='review' ? '' : $email['email'];
        $student['email_status']=$email['status']; $student['email_reason']=$email['reason'];
        $student['email_preview']=$email['email'];
        $rows[] = $student;
    }
    $checks = [
        ['field'=>'Subject / Section','cell'=>'D4','found'=>$parsed['subject_section_raw'],'expected'=>$class['subject_code'] . ' - ' . $class['section_display']],
        ['field'=>'Campus','cell'=>'A1','found'=>$parsed['campus_header'],'expected'=>$class['campus_name']],
        ['field'=>'Faculty','cell'=>'D6','found'=>$parsed['faculty_name'],'expected'=>$class['faculty_name']],
    ];
    $review=count(array_filter($rows,static function ($row) { return $row['email_status']==='review'; }));
    $notes[]='Blank emails use complete stored first and last names at @sksu.edu.ph. Existing emails are retained; no middle names are added.';
    if ($review) $notes[]=$review . ' student emails need review. Their class imports can proceed; their blank emails will remain for correction.';
    return ['rows'=>$rows, 'summary'=>$summary, 'notes'=>$notes, 'checks'=>$checks, 'can_confirm'=>$summary['conflicts'] === 0 && $summary['add'] > 0];
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
    $transaction = false; $emailLocked=false;
    try {
        require_once __DIR__ . '/student_email_helper.php';
        synk_student_email_lock($conn); $emailLocked=true;
        if (!$conn->begin_transaction()) throw new RuntimeException('Unable to begin the roster import transaction.');
        $transaction = true;
        synk_student_email_catalog($conn,true);
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
        $added = 0; $profiles = 0; $skipped = $preview['summary']['existing']; $emailsAdded=0; $emailsReview=0;
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
            elseif ($student['email_status']==='ready') synk_student_email_fill($conn,$studentId,$student['email_address']);
            if ($student['email_status']==='ready') $emailsAdded++;
            if ($student['email_status']==='review') $emailsReview++;
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
        return ['added'=>$added, 'skipped'=>$skipped, 'new_profiles'=>$profiles, 'roster_count'=>$total,
            'emails_added'=>$emailsAdded,'emails_review'=>$emailsReview];
    } catch (Throwable $e) {
        if ($transaction) $conn->rollback();
        throw $e;
    } finally {
        if ($emailLocked) synk_student_email_unlock($conn);
        $conn->query("SELECT RELEASE_LOCK('synk_college_dean_roster_import')");
    }
}
