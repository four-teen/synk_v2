<?php
require_once __DIR__.'/management_catalog_helper.php';

function synk_management_permit_students(mysqli $conn, array $scope, array $period, array $section): array
{
    $periodValues = [$scope['college_id'],$scope['campus_id'],$period['ay_id'],$period['semester']];
    $rows = synk_management_query($conn,"SELECT s.student_id,s.last_name,s.first_name,s.middle_name,s.suffix_name,
        es.subject_code,es.descriptive_title
        FROM (SELECT DISTINCT student_id FROM tbl_student_management_enrolled_subjects
            WHERE college_id=? AND campus_id=? AND ay_id=? AND semester=? AND program_id=? AND section_id=? AND is_active=1) roster
        JOIN tbl_student_management s ON s.student_id=roster.student_id
        JOIN tbl_student_management_enrolled_subjects es ON es.student_id=s.student_id
            AND es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=? AND es.is_active=1
        ORDER BY s.last_name,s.first_name,s.middle_name,s.student_id,es.subject_code,es.student_enrollment_id",
        'iiiiiiiiii',array_merge($periodValues,[$section['program_id'],$section['section_id']],$periodValues));

    $students = [];
    foreach ($rows as $row) {
        $id = $row['student_id'];
        if (!isset($students[$id])) {
            $givenNames = [trim((string)$row['first_name']),trim((string)$row['middle_name']),trim((string)$row['suffix_name'])];
            $students[$id] = [
                'last_name'=>trim((string)$row['last_name']),
                'given_names'=>implode(' ',array_filter($givenNames,'strlen')),
                'subjects'=>[],
            ];
        }
        $code = trim($row['subject_code']) ?: trim($row['descriptive_title']);
        if ($code !== '') $students[$id]['subjects'][strtoupper($code)] = $code;
    }
    return array_values($students);
}

function synk_management_student_permits(mysqli $conn, array $scope, array $period, array $input): array
{
    $filters = [];
    foreach (['program_id','year_level','section_id'] as $field) {
        $filters[$field] = synk_management_input_id($input[$field] ?? null,true);
    }
    $generate = $input['generate'] ?? null;
    if ($generate !== null && $generate !== '1') throw new DomainException('Choose Generate preview.');

    $sections = [];
    if (synk_management_students_ready($conn)) {
        $rows = synk_management_query($conn,"SELECT DISTINCT sec.section_id,sec.year_level,
            COALESCE(NULLIF(TRIM(sec.full_section),''),TRIM(sec.section_name)) AS section_label,
            p.program_id,p.program_code,p.program_name,p.major
            FROM tbl_student_management_enrolled_subjects es
            JOIN tbl_student_management s ON s.student_id=es.student_id
            JOIN tbl_program p ON p.program_id=es.program_id AND p.college_id=es.college_id
            JOIN tbl_sections sec ON sec.section_id=es.section_id AND sec.program_id=es.program_id
                AND sec.ay_id=es.ay_id AND sec.semester=es.semester
            WHERE es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=? AND es.is_active=1 AND sec.year_level>0
            ORDER BY p.program_code,p.major,p.program_id,sec.year_level,section_label,sec.section_id",'iiii',
            [$scope['college_id'],$scope['campus_id'],$period['ay_id'],$period['semester']]);
        foreach ($rows as $row) {
            foreach (['program_id','section_id','year_level'] as $field) $row[$field] = (int)$row[$field];
            $row['program_code'] = trim((string)$row['program_code']);
            $row['course_label'] = $row['program_code'] ?: $row['program_name'];
            $major = trim((string)$row['major']);
            if ($major !== '') $row['course_label'] .= ' - '.$major;
            $sections[$row['section_id']] = $row;
        }
    }

    $section = $sections[$filters['section_id']] ?? null;
    $students = [];
    $error = '';
    if ($generate === '1') {
        if (!$section || $section['program_id'] !== $filters['program_id'] || $section['year_level'] !== $filters['year_level']) {
            $error = 'Choose a course, year level, and section in the selected semester.';
        } else {
            $students = synk_management_permit_students($conn,$scope,$period,$section);
            if (!$students) $error = 'No students are enrolled in this section.';
        }
    }
    return ['filters'=>$filters,'sections'=>$sections,'section'=>$section,'students'=>$students,'error'=>$error];
}
