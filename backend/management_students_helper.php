<?php
require_once __DIR__ . '/management_portal_helper.php';
require_once __DIR__ . '/college_dean_students_helper.php';

// Reuse the Dean's read-only directory queries, not its account or write routes.
function synk_management_directory_filters(mysqli $conn, array $scope, array $input): array
{
    foreach (['directory_term','search','program_id','section_id','email_status','sort','page','page_size'] as $key) {
        if (isset($input[$key]) && !is_scalar($input[$key])) throw new DomainException('Choose valid student filters.');
    }
    $view = synk_management_view_term($conn,$input,$scope);
    $directoryTerm = (string)($input['directory_term'] ?? $view['key']);
    if ($directoryTerm !== 'all') {
        if (preg_match('/^year:([1-9][0-9]{0,9})$/D',$directoryTerm,$match)) {
            if (!synk_management_query($conn,'SELECT ay_id FROM tbl_academic_years WHERE ay_id=?','i',[(int)$match[1]])) throw new DomainException('Choose an available academic year.');
        } else synk_management_view_term($conn,['term'=>$directoryTerm]);
    }
    if (isset($input['page_size']) && !in_array((string)$input['page_size'],['25','50','100'],true)) throw new DomainException('Choose 25, 50, or 100 students per page.');
    if (isset($input['search']) && !preg_match('//u',(string)$input['search'])) throw new DomainException('Enter valid search text.');
    $filters = synk_dean_students_filters(array_merge($input,['term'=>$directoryTerm]));
    if ($filters['program_id'] && !synk_management_query($conn,'SELECT program_id FROM tbl_program WHERE college_id=? AND program_id=?','ii',[$scope['college_id'],$filters['program_id']])) throw new DomainException('Choose a program in your college.');
    if ($filters['section_id']) {
        $section = synk_management_query($conn,'SELECT sec.program_id,sec.ay_id,sec.semester FROM tbl_sections sec JOIN tbl_program p ON p.program_id=sec.program_id WHERE p.college_id=? AND sec.section_id=?','ii',[$scope['college_id'],$filters['section_id']]);
        if (!$section || ($filters['program_id'] && (int)$section[0]['program_id'] !== $filters['program_id'])
            || ($filters['ay_id'] && (int)$section[0]['ay_id'] !== $filters['ay_id']) || ($filters['semester'] && (int)$section[0]['semester'] !== $filters['semester'])) throw new DomainException('Choose a section for the selected program and term.');
    }
    return $filters;
}

function synk_management_directory(mysqli $conn, array $scope, array $input): array
{
    $filters = synk_management_directory_filters($conn,$scope,$input);
    if (!synk_management_students_ready($conn)) return ['rows'=>[],'summary'=>['students'=>0,'classes'=>0,'programs'=>0],
        'pagination'=>['page'=>1,'pages'=>1,'page_size'=>$filters['page_size'],'total'=>0,'from'=>0,'to'=>0],
        'options'=>['programs'=>[],'sections'=>[],'terms'=>[]],'filters'=>$filters];
    return synk_dean_students_list($conn,$scope,$filters) + ['options'=>synk_dean_students_options($conn,$scope),'filters'=>$filters];
}

function synk_management_students_detail(mysqli $conn, array $scope, array $input): array
{
    $filters = synk_management_directory_filters($conn,$scope,$input);
    $id = synk_management_input_id($input['student_id'] ?? '');
    if (!synk_management_students_ready($conn)) throw new OutOfBoundsException('Student records are unavailable.');
    $detail = synk_dean_students_detail($conn,$scope,$id,$filters);
    if (!$detail) throw new OutOfBoundsException('This student is unavailable in your college.');
    // Return directory information only; sign-in histories and student editing stay in their existing roles.
    return $detail;
}
