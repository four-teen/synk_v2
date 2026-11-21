<?php

require_once __DIR__.'/schema_helper.php';

function synk_sef_report_faculty_info(mysqli $conn, array $scope, array $term, array $faculty): array
{
    $record = $faculty['supervisor_record'];
    $context = $record['context'] ?? [];
    if (!isset($context['subjects'])) {
        $context = synk_sef_context($conn, $scope, $term, (int)$faculty['faculty_id']);
    }

    $subjects = $context['subjects'];
    $programIds = array_map('intval', array_column($subjects, 'program_id'));
    $programCodes = [];
    if ($programIds) {
        $programs = synk_evaluation_query($conn, 'SELECT program_id, program_code FROM tbl_program WHERE college_id = ? ORDER BY program_code',
            'i', [$scope['college_id']]);
        $programCodes = array_column(array_filter($programs, static function (array $program) use ($programIds): bool {
            return in_array((int)$program['program_id'], $programIds, true);
        }), 'program_code');
    }

    return [
        'Name of Faculty:' => $record['faculty_name'] ?? $faculty['faculty_name'],
        'Department/Program:' => $programCodes ? implode(', ', array_unique($programCodes)) : $context['college_name'] ?? $scope['college_name'],
        'Semester/School Year:' => synk_sef_report_term_label($term),
        'Subject(s) Handled:' => implode(', ', array_unique(array_filter(array_column($subjects, 'subject_code')))),
    ];
}

function synk_sef_report_term_label(array $term): string
{
    return $term['ay_label'] !== ''
        ? $term['semester_label'].', A.Y. '.$term['ay_label']
        : $term['term_text'];
}

function synk_sef_report_groups(mysqli $conn, array $faculty): array
{
    $groups = ['I. REGULAR FACULTY' => [], 'II. CONTRACT OF SERVICE FACULTY' => []];
    $classifications = [];
    if ($faculty && synk_table_has_column($conn, 'tbl_faculty', 'employment_classification')) {
        $ids = array_keys($faculty);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = synk_evaluation_query($conn,
            "SELECT faculty_id, employment_classification FROM tbl_faculty WHERE faculty_id IN ($placeholders)",
            str_repeat('i', count($ids)), $ids);
        $classifications = array_column($rows, 'employment_classification', 'faculty_id');
    }

    $headings = [
        'permanent' => 'I. REGULAR FACULTY',
        'contract_of_service' => 'II. CONTRACT OF SERVICE FACULTY',
    ];
    foreach ($faculty as $id => $member) {
        $classification = $classifications[$id] ?? '';
        $groups[$headings[$classification] ?? 'III. OTHER FACULTY'][] = $member;
    }
    return $groups;
}
