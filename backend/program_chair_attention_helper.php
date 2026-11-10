<?php
require_once __DIR__ . '/program_chair_helper.php';

function synk_chair_attention_query(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare the student load review.');
    try {
        if ($types !== '') synk_bind_dynamic_params($stmt, $types, $params);
        if (!$stmt->execute()) throw new RuntimeException('Unable to load the student load review.');
        $result = $stmt->get_result();
        if (!$result) throw new RuntimeException('Unable to read the student load review.');
        return $result->fetch_all(MYSQLI_ASSOC);
    } finally { $stmt->close(); }
}

function synk_chair_attention_program_id($value): int
{
    if ($value === null || $value === '') return 0;
    if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', $value) || (float)$value > 2147483647) {
        throw new DomainException('Choose a valid program.');
    }
    return (int)$value;
}

function synk_chair_attention_year_level($value): int
{
    if ($value === null || $value === '') return 0;
    if (!is_string($value) || !preg_match('/^[1-6]$/D', $value)) {
        throw new DomainException('Choose a valid year level.');
    }
    return (int)$value;
}

// An unknown or inconsistent credit value must not turn into a zero-unit subject.
function synk_chair_attention_units(array $values): ?float
{
    $known = [];
    foreach ($values as $value) {
        if ($value === null || !is_numeric($value) || (float)$value < 0) return null;
        $known[(string)(int)round((float)$value * 100)] = (float)$value;
    }
    return count($known) === 1 ? round((float)reset($known), 2) : null;
}

function synk_chair_attention_number(float $value): string
{
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

function synk_chair_attention_curricula(array $rows): array
{
    $catalog = [];
    foreach ($rows as $row) {
        $id = (int)$row['prospectus_id'];
        if (!isset($catalog[$id])) {
            $label = trim((string)$row['effective_sy']);
            $label = ($label !== '' ? 'SY ' . $label . ' / ' : '') . trim((string)$row['cmo_no']);
            $catalog[$id] = ['label'=>$label !== '' ? $label : 'Curriculum #' . $id, 'subjects'=>[], 'periods'=>[]];
        }
        if (empty($row['ps_id'])) continue;
        $subject = (int)$row['sub_id'];
        $period = (int)$row['year_level'] . ':' . (int)$row['semester'];
        $catalog[$id]['subjects'][$subject][] = $row['total_units'];
        $catalog[$id]['periods'][$period][$subject][] = $row['total_units'];
    }
    foreach ($catalog as &$curriculum) {
        foreach ($curriculum['subjects'] as &$values) $values = synk_chair_attention_units($values);
        unset($values);
        foreach ($curriculum['periods'] as &$subjects) {
            $cents = 0;
            foreach ($subjects as $values) {
                $units = synk_chair_attention_units($values);
                if ($units === null) { $cents = null; break; }
                $cents += (int)round($units * 100);
            }
            $subjects = $cents === null ? null : $cents / 100;
        }
        unset($subjects);
    }
    unset($curriculum);
    return $catalog;
}

function synk_chair_attention_analyze(array $members, array $curriculumRows, array $sectionRows, int $programId, array $term, int $yearLevel = 0): array
{
    $catalog = synk_chair_attention_curricula($curriculumRows);
    $yearCurricula = [];
    foreach ($sectionRows as $section) {
        $id = (int)($section['section_prospectus_id'] ?: $section['offering_prospectus_id']);
        if (isset($catalog[$id])) $yearCurricula[(int)$section['year_level']][$id] = true;
    }
    $byStudent = [];
    foreach ($members as $member) $byStudent[(int)$member['student_id']][] = $member;
    $result = ['groups'=>[], 'students'=>0, 'flagged'=>0, 'incomplete'=>0];
    foreach ($byStudent as $studentId=>$rows) {
        $student = $rows[0];
        $profileTerm = (int)$student['profile_ay_id'] === (int)$term['ay_id']
            && (int)$student['profile_semester'] === (int)$term['semester'];
        $programs = []; $years = [];
        foreach ($rows as $row) {
            $programs[(int)$row['program_id']] = true;
            if ((int)$row['program_id'] === $programId && (int)$row['year_level'] > 0) $years[(int)$row['year_level']] = true;
        }
        // A cross-program class does not change the student's home program.
        $homeProgram = $profileTerm && (int)$student['profile_program_id'] > 0
            ? (int)$student['profile_program_id'] : (count($programs) === 1 ? (int)key($programs) : 0);
        if ($homeProgram !== 0 && $homeProgram !== $programId) continue;
        $year = $profileTerm && $homeProgram === $programId && (int)$student['profile_year_level'] > 0
            ? (int)$student['profile_year_level'] : (count($years) === 1 ? (int)key($years) : 0);
        if ($yearLevel > 0 && $year !== $yearLevel) continue;
        $result['students']++;
        if (!$homeProgram || !$year) { $result['incomplete']++; continue; }
        $basis = [];
        foreach ($rows as $row) {
            if ((int)$row['program_id'] !== $programId || (int)$row['year_level'] !== $year) continue;
            $id = (int)($row['section_prospectus_id'] ?: $row['offering_prospectus_id']);
            if (isset($catalog[$id])) $basis[$id] = true;
        }
        if (!$basis) $basis = $yearCurricula[$year] ?? [];
        if (count($basis) !== 1) { $result['incomplete']++; continue; }
        $curriculumId = (int)key($basis);
        $period = $year . ':' . (int)$term['semester'];
        $limit = $catalog[$curriculumId]['periods'][$period] ?? null;
        if ($limit === null) { $result['incomplete']++; continue; }

        $subjects = []; $sections = [];
        foreach ($rows as $row) {
            $subjectId = (int)$row['subject_id'];
            $code = strtoupper(preg_replace('/\s+/u', '', trim((string)$row['subject_code'])));
            $key = $subjectId > 0 ? 'id:' . $subjectId : ($code !== '' ? 'code:' . $code : 'row:' . $row['student_enrollment_id']);
            $units = $row['linked_units'];
            $priority = $units !== null ? 2 : 1;
            if ($units === null && $subjectId > 0 && (int)$row['program_id'] === $programId) {
                $sourceId = (int)$row['section_prospectus_id'];
                if (!$sourceId) $sourceId = $curriculumId;
                $units = $catalog[$sourceId]['subjects'][$subjectId] ?? null;
            }
            if (!isset($subjects[$key])) {
                $subjects[$key] = ['code'=>(string)$row['subject_code'], 'title'=>(string)$row['descriptive_title'],
                    'values'=>[], 'priority'=>0, 'sections'=>[]];
            }
            if ($units !== null) {
                if ($priority > $subjects[$key]['priority']) $subjects[$key]['values'] = [];
                if ($priority >= $subjects[$key]['priority']) {
                    $subjects[$key]['values'][] = $units;
                    $subjects[$key]['priority'] = $priority;
                }
            }
            $section = trim((string)$row['section_display']);
            if ($section !== '') { $sections[$section] = true; $subjects[$key]['sections'][$section] = true; }
        }
        $cents = 0; $pending = 0;
        foreach ($subjects as &$subject) {
            $subject['units'] = synk_chair_attention_units($subject['values']);
            unset($subject['values'], $subject['priority']);
            $subject['sections'] = array_keys($subject['sections']);
            if ($subject['units'] === null) $pending++;
            else $cents += (int)round($subject['units'] * 100);
        }
        unset($subject);
        if ($pending) $result['incomplete']++;
        // A known subtotal above the limit is still a confirmed overload.
        $excessCents = $cents - (int)round($limit * 100);
        if ($excessCents <= 0) continue;
        $name = trim($student['last_name'] . ', ' . trim(implode(' ', array_filter([
            $student['first_name'], $student['middle_name'], $student['suffix_name']
        ]))), ' ,');
        $result['groups'][$year][] = ['student_id'=>$studentId, 'student_number'=>(string)$student['student_number'],
            'student_name'=>$name, 'year_level'=>$year, 'units'=>$cents / 100, 'unit_limit'=>$limit,
            'excess_units'=>$excessCents / 100, 'pending_subjects'=>$pending, 'subjects'=>array_values($subjects),
            'sections'=>array_keys($sections), 'curriculum_label'=>$catalog[$curriculumId]['label']];
        $result['flagged']++;
    }
    ksort($result['groups'], SORT_NUMERIC);
    foreach ($result['groups'] as &$group) {
        usort($group, static function (array $a, array $b): int {
            return strcasecmp($a['student_name'], $b['student_name']) ?: strcmp($a['student_number'], $b['student_number']);
        });
    }
    unset($group);
    return $result;
}

function synk_chair_attention_load(mysqli $conn, array $scope, array $term, int $programId, int $yearLevel = 0): array
{
    if (!synk_chair_attention_query($conn, 'SELECT program_id FROM tbl_program WHERE program_id=? AND college_id=?', 'ii', [$programId, $scope['college_id']])) {
        throw new DomainException('Choose a program assigned to your college.');
    }
    foreach (['tbl_student_management', 'tbl_student_management_enrolled_subjects', 'tbl_sections', 'tbl_prospectus_offering',
        'tbl_prospectus_header', 'tbl_prospectus_year_sem', 'tbl_prospectus_subjects'] as $table) {
        if (!synk_table_exists($conn, $table)) throw new DomainException('Student enrollment or curriculum data is not available yet.');
    }
    $hasAssignments = synk_table_exists($conn, 'tbl_section_curriculum');
    $assignmentField = $hasAssignments ? 'assigned.prospectus_id' : 'NULL';
    $assignmentJoin = $hasAssignments ? 'LEFT JOIN tbl_section_curriculum sc ON sc.section_id=sec.section_id
        LEFT JOIN tbl_prospectus_header assigned ON assigned.prospectus_id=sc.prospectus_id AND assigned.program_id=sec.program_id' : '';
    $curricula = synk_chair_attention_query($conn, 'SELECT h.prospectus_id,h.effective_sy,h.cmo_no,ys.year_level,ys.semester,
        ps.ps_id,ps.sub_id,ps.total_units FROM tbl_prospectus_header h
        LEFT JOIN tbl_prospectus_year_sem ys ON ys.prospectus_id=h.prospectus_id
        LEFT JOIN tbl_prospectus_subjects ps ON ps.pys_id=ys.pys_id WHERE h.program_id=?', 'i', [$programId]);
    $sections = synk_chair_attention_query($conn, "SELECT sec.year_level,{$assignmentField} AS section_prospectus_id,
        oh.prospectus_id AS offering_prospectus_id FROM tbl_sections sec {$assignmentJoin}
        LEFT JOIN tbl_prospectus_offering o ON o.section_id=sec.section_id AND o.program_id=sec.program_id
            AND o.ay_id=sec.ay_id AND o.semester=sec.semester AND o.year_level=sec.year_level
        LEFT JOIN tbl_prospectus_header oh ON oh.prospectus_id=o.prospectus_id AND oh.program_id=sec.program_id
        WHERE sec.program_id=? AND sec.ay_id=? AND sec.semester=?", 'iii', [$programId, $term['ay_id'], $term['semester']]);
    $members = synk_chair_attention_query($conn, "SELECT es.student_enrollment_id,es.student_id,es.program_id,es.year_level,
        es.subject_id,es.subject_code,es.descriptive_title,s.student_number,s.last_name,s.first_name,s.middle_name,s.suffix_name,
        s.ay_id AS profile_ay_id,s.semester AS profile_semester,s.program_id AS profile_program_id,s.year_level AS profile_year_level,
        COALESCE(NULLIF(sec.full_section,''),es.section_text) AS section_display,
        {$assignmentField} AS section_prospectus_id,linked.prospectus_id AS offering_prospectus_id,linked.total_units AS linked_units
        FROM tbl_student_management_enrolled_subjects es JOIN tbl_student_management s ON s.student_id=es.student_id
        JOIN tbl_program p ON p.program_id=es.program_id AND p.college_id=es.college_id
        LEFT JOIN tbl_sections sec ON sec.section_id=es.section_id AND sec.program_id=es.program_id
            AND sec.ay_id=es.ay_id AND sec.semester=es.semester {$assignmentJoin}
        LEFT JOIN (SELECT o.offering_id,o.program_id,o.section_id,o.ay_id,o.semester,o.prospectus_id,ps.sub_id,ps.total_units
            FROM tbl_prospectus_offering o JOIN tbl_prospectus_header h ON h.prospectus_id=o.prospectus_id AND h.program_id=o.program_id
            JOIN tbl_prospectus_subjects ps ON ps.ps_id=o.ps_id
            JOIN tbl_prospectus_year_sem ys ON ys.pys_id=ps.pys_id AND ys.prospectus_id=o.prospectus_id
                AND ys.year_level=o.year_level AND ys.semester=o.semester
            WHERE o.ay_id=? AND o.semester=?) linked
            ON (linked.offering_id=es.offering_id OR es.offering_id=0) AND linked.program_id=es.program_id
            AND linked.section_id=es.section_id AND linked.ay_id=es.ay_id AND linked.semester=es.semester AND linked.sub_id=es.subject_id
        WHERE es.is_active=1 AND es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=?
            AND (EXISTS (SELECT 1 FROM tbl_student_management_enrolled_subjects home WHERE home.student_id=es.student_id
                AND home.is_active=1 AND home.college_id=es.college_id AND home.campus_id=es.campus_id
                AND home.ay_id=es.ay_id AND home.semester=es.semester AND home.program_id=?)
                OR (s.ay_id=es.ay_id AND s.semester=es.semester AND s.program_id=?))
        ORDER BY es.student_id,es.student_enrollment_id", 'iiiiiiii', [$term['ay_id'], $term['semester'], $scope['college_id'],
            $scope['campus_id'], $term['ay_id'], $term['semester'], $programId, $programId]);
    return synk_chair_attention_analyze($members, $curricula, $sections, $programId, $term, $yearLevel);
}

// Dashboard counts use the same review as the detail page and contain no student identities.
function synk_chair_attention_dashboard(mysqli $conn, array $scope, array $term): array
{
    $programs = synk_chair_attention_query($conn,
        'SELECT program_id,program_code,program_name,major FROM tbl_program WHERE college_id=? ORDER BY program_code,program_name,major',
        'i', [$scope['college_id']]);
    $summary = ['programs'=>[], 'incomplete'=>0];
    foreach ($programs as $program) {
        $program['program_id'] = (int)$program['program_id'];
        $program['year_counts'] = array_fill(1, 4, 0);
        $program['unavailable'] = false;
        try {
            $report = synk_chair_attention_load($conn, $scope, $term, $program['program_id']);
            foreach ($report['groups'] as $year=>$students) $program['year_counts'][$year] = count($students);
            $summary['incomplete'] += $report['incomplete'];
        } catch (Throwable $exception) {
            error_log('Program Chair dashboard Need Attention: ' . $exception->getMessage());
            $program['unavailable'] = true;
        }
        $summary['programs'][] = $program;
    }
    return $summary;
}
