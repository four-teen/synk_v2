<?php
require_once __DIR__ . '/college_dean_workload_helper.php';

function synk_dean_faculty_students_query(mysqli $conn, string $sql, string $types, array $params): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare the faculty student report.');
    try {
        synk_bind_dynamic_params($stmt, $types, $params);
        synk_dean_roster_execute($stmt);
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally { $stmt->close(); }
}

function synk_dean_faculty_students_options(mysqli $conn, array $scope): array
{
    $programs = synk_dean_faculty_students_query($conn,
        'SELECT program_id,program_code,program_name,major FROM tbl_program WHERE college_id=? ORDER BY program_code,major,program_id',
        'i', [$scope['college_id']]);
    foreach ($programs as &$program) {
        $program['program_id'] = (int)$program['program_id'];
        $program['label'] = trim($program['program_code']) ?: $program['program_name'];
        if (trim((string)$program['major']) !== '') $program['label'] .= ' - ' . $program['major'];
    }
    unset($program);
    $current = synk_fetch_current_academic_term($conn);
    return ['programs'=>$programs, 'terms'=>synk_dean_term_catalog($conn), 'current_term'=>$current, 'view_term'=>synk_dean_view_term($conn)];
}

function synk_dean_faculty_students_filters(array $input, array $options): array
{
    $current = $options['view_term'] ?? $options['current_term'];
    $termKey = trim((string)($input['term'] ?? 'current'));
    if ($termKey === '' || $termKey === 'current') $termKey = $current['ay_id'] . ':' . $current['semester'];
    $term = null;
    foreach ($options['terms'] as $candidate) if ($candidate['key'] === $termKey) $term = $candidate;
    if (!$term) throw new DomainException('Choose an academic term available to your college.');
    $filters = ['term'=>$term, 'program_id'=>0, 'faculty_id'=>0, 'status'=>'all', 'search'=>''];
    foreach (['program_id','faculty_id'] as $field) {
        $value = filter_var($input[$field] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>2147483647]]);
        if ($value === false) throw new DomainException('Choose valid report filters.');
        $filters[$field] = $value;
    }
    if ($filters['program_id'] && !in_array($filters['program_id'], array_column($options['programs'], 'program_id'), true)) {
        throw new DomainException('Choose a program in your assigned college.');
    }
    $filters['status'] = (string)($input['status'] ?? 'all');
    if (!in_array($filters['status'], ['all','missing','complete','unassigned'], true)) throw new DomainException('Choose a valid student list status.');
    $filters['search'] = trim(synk_student_identity_utf8((string)($input['search'] ?? '')));
    $length = function_exists('mb_strlen') ? mb_strlen($filters['search'], 'UTF-8') : strlen($filters['search']);
    if ($length > 150) throw new DomainException('Search using up to 150 characters.');
    return $filters;
}

function synk_dean_faculty_students_data(mysqli $conn, array $scope, array $filters): array
{
    $term = $filters['term'];
    $facultyRows = synk_dean_workload_faculties($conn, $scope, $term, $filters['faculty_id']);
    $source = synk_professor_workload_assignment_source_sql();
    $classSql = "SELECT DISTINCT fw.faculty_id,po.offering_id,po.program_id,po.section_id,fw.ay_id,fw.semester,
        ps.sub_id AS subject_id,sm.sub_code AS subject_code,sm.sub_description AS descriptive_title,
        p.program_code,p.program_name,p.major,sec.full_section AS section_display
        {$source} JOIN tbl_program p ON p.program_id=po.program_id
        WHERE p.college_id=? AND fw.ay_id=? AND fw.semester=? AND (?=0 OR po.program_id=?) AND (?=0 OR fw.faculty_id=?)";
    $params = [$scope['college_id'], $term['ay_id'], $term['semester'], $filters['program_id'], $filters['program_id'], $filters['faculty_id'], $filters['faculty_id']];
    $classes = synk_dean_faculty_students_query($conn, $classSql . ' ORDER BY sm.sub_code,sec.full_section,po.offering_id,fw.faculty_id', 'iiiiiii', $params);
    // Use current workload assignments, not the faculty ID saved by an older import.
    // Every active membership must also match its program, section, subject and term.
    $members = synk_dean_faculty_students_query($conn, "SELECT DISTINCT a.faculty_id,a.offering_id,es.student_id
        FROM ({$classSql}) a JOIN tbl_student_management_enrolled_subjects es
          ON (es.offering_id=a.offering_id OR es.offering_id=0)
          AND es.program_id=a.program_id AND es.section_id=a.section_id AND es.subject_id=a.subject_id
          AND es.ay_id=a.ay_id AND es.semester=a.semester AND es.is_active=1
        JOIN tbl_student_management s ON s.student_id=es.student_id
        WHERE es.college_id=? AND es.campus_id=?", 'iiiiiiiii', array_merge($params, [$scope['college_id'], $scope['campus_id']]));
    $rosters = [];
    foreach ($members as $member) $rosters[(int)$member['faculty_id']][(int)$member['offering_id']][(int)$member['student_id']] = true;
    $byFaculty = [];
    foreach ($classes as $class) {
        foreach (['faculty_id','offering_id','program_id','section_id','ay_id','semester','subject_id'] as $key) $class[$key] = (int)$class[$key];
        $class['student_count'] = count($rosters[$class['faculty_id']][$class['offering_id']] ?? []);
        $class['program_label'] = trim($class['program_code']) ?: $class['program_name'];
        if (trim((string)$class['major']) !== '') $class['program_label'] .= ' - ' . $class['major'];
        $byFaculty[$class['faculty_id']][] = $class;
    }
    $rows = []; $detail = []; $collegeStudents = []; $collegeEnrollments = []; $collegeClasses = [];
    $summary = ['faculties'=>0,'assigned_classes'=>0,'with_lists'=>0,'without_lists'=>0,'faculties_missing'=>0,'unique_students'=>0,'total_enrollments'=>0];
    $current = synk_fetch_current_academic_term($conn);
    $isCurrent = $term['ay_id'] === $current['ay_id'] && $term['semester'] === $current['semester'];
    foreach ($facultyRows as $faculty) {
        $id = $faculty['faculty_id']; $facultyClasses = $byFaculty[$id] ?? [];
        if ($filters['program_id'] && !$facultyClasses) continue;
        $matches = true;
        foreach (preg_split('/\s+/u', $filters['search'], -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $found = function_exists('mb_stripos') ? mb_stripos($faculty['faculty_name'], $word, 0, 'UTF-8') : stripos($faculty['faculty_name'], $word);
            if ($found === false) $matches = false;
        }
        if (!$matches) continue;
        $students = []; $enrollments = 0; $withLists = 0;
        foreach ($facultyClasses as $class) {
            $withLists += $class['student_count'] > 0 ? 1 : 0;
            $enrollments += $class['student_count'];
            foreach (($rosters[$id][$class['offering_id']] ?? []) as $studentId=>$unused) $students[$studentId] = true;
        }
        $assigned = count($facultyClasses); $missing = $assigned - $withLists;
        if ($filters['status'] === 'missing' && !$missing) continue;
        if ($filters['status'] === 'complete' && (!$assigned || $missing)) continue;
        if ($filters['status'] === 'unassigned' && $assigned) continue;
        $row = ['faculty_id'=>$id,'faculty_name'=>$faculty['faculty_name'],'assigned_classes'=>$assigned,
            'with_lists'=>$withLists,'without_lists'=>$missing,'unique_students'=>count($students),'total_enrollments'=>$enrollments];
        $rows[] = $row;
        foreach ($facultyClasses as &$class) {
            $collegeClasses[$class['offering_id']] = $class['student_count'];
            foreach (($rosters[$id][$class['offering_id']] ?? []) as $studentId=>$unused) {
                $collegeStudents[$studentId] = true;
                $collegeEnrollments[$class['offering_id'] . ':' . $studentId] = true;
            }
            $class['workload_url'] = 'faculty-workload.php?' . http_build_query([
                'faculty_id'=>$id, 'offering_id'=>$class['offering_id'], 'list_status'=>$class['student_count'] ? 'all' : 'missing',
                'dean_ay_id'=>$term['ay_id'], 'dean_semester'=>$term['semester']
            ]);
        }
        unset($class);
        $detail[$id] = ['faculty'=>$row, 'classes'=>$facultyClasses, 'term'=>$term, 'is_current_term'=>$isCurrent];
        $summary['faculties_missing'] += $missing > 0 ? 1 : 0;
    }
    $summary['faculties'] = count($rows); $summary['assigned_classes'] = count($collegeClasses);
    $summary['with_lists'] = count(array_filter($collegeClasses, static function (int $count): bool { return $count > 0; }));
    $summary['without_lists'] = $summary['assigned_classes'] - $summary['with_lists'];
    $summary['unique_students'] = count($collegeStudents); $summary['total_enrollments'] = count($collegeEnrollments);
    return ['rows'=>$rows, 'summary'=>$summary, 'details'=>$detail, 'term'=>$term, 'is_current_term'=>$isCurrent];
}
