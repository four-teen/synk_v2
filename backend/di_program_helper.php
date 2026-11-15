<?php
require_once __DIR__ . '/di_curriculum_helper.php';

function synk_di_program_options(mysqli $conn): array
{
    return [
        'campuses' => synk_di_curriculum_query($conn, 'SELECT campus_id,campus_code,campus_name,status FROM tbl_campus ORDER BY campus_name,campus_id'),
        'colleges' => synk_di_curriculum_query($conn, 'SELECT college_id,campus_id,college_code,college_name,status FROM tbl_college ORDER BY college_name,college_id'),
    ];
}

function synk_di_program_scope(mysqli $conn, array $input, bool $requireCollege = false): array
{
    $campusId = synk_di_curriculum_id($input['campus_id'] ?? null);
    if (!synk_di_curriculum_query($conn, 'SELECT campus_id FROM tbl_campus WHERE campus_id=?', 'i', [$campusId])) throw new DomainException('Choose an available campus.', 422);
    $raw = $input['college_id'] ?? '';
    $collegeId = !$requireCollege && ($raw === '' || $raw === '0') ? 0 : synk_di_curriculum_id($raw);
    if ($collegeId && !synk_di_curriculum_query($conn, 'SELECT college_id FROM tbl_college WHERE college_id=? AND campus_id=?', 'ii', [$collegeId,$campusId])) {
        throw new DomainException('The selected college does not belong to this campus.', 422);
    }
    return ['campus_id'=>$campusId, 'college_id'=>$collegeId];
}

function synk_di_program_list(mysqli $conn, array $input): array
{
    $scope = synk_di_program_scope($conn, $input);
    $where = 'c.campus_id=?' . ($scope['college_id'] ? ' AND p.college_id=?' : '');
    $types = $scope['college_id'] ? 'ii' : 'i';
    $values = $scope['college_id'] ? [$scope['campus_id'],$scope['college_id']] : [$scope['campus_id']];
    $rows = synk_di_curriculum_query($conn,
        'SELECT p.program_id,p.college_id,p.program_code,p.program_name,p.major,p.status,
            (SELECT COUNT(*) FROM tbl_prospectus_header h WHERE h.program_id=p.program_id) AS prospectus_count
         FROM tbl_program p JOIN tbl_college c ON c.college_id=p.college_id WHERE ' . $where . '
         ORDER BY p.program_code,p.program_name,p.major,p.program_id', $types, $values);
    $active = 0; $prospectuses = 0;
    foreach ($rows as &$row) {
        foreach (['program_id','college_id','prospectus_count'] as $field) $row[$field] = (int)$row[$field];
        $row['major'] = (string)($row['major'] ?? '');
        $row['status'] = $row['status'] === 'active' ? 'active' : 'inactive';
        $active += $row['status'] === 'active' ? 1 : 0;
        $prospectuses += $row['prospectus_count'];
    }
    unset($row);
    $colleges = synk_di_curriculum_query($conn, 'SELECT COUNT(*) AS total FROM tbl_college WHERE campus_id=?' . ($scope['college_id'] ? ' AND college_id=?' : ''), $types, $values);
    $subjects = synk_di_curriculum_query($conn,
        'SELECT COUNT(DISTINCT ps.sub_id) AS total FROM tbl_program p JOIN tbl_college c ON c.college_id=p.college_id
         JOIN tbl_prospectus_header h ON h.program_id=p.program_id JOIN tbl_prospectus_year_sem y ON y.prospectus_id=h.prospectus_id
         JOIN tbl_prospectus_subjects ps ON ps.pys_id=y.pys_id WHERE ' . $where, $types, $values);
    return $scope + ['rows'=>$rows, 'summary'=>[
        'programs'=>count($rows), 'active'=>$active, 'inactive'=>count($rows)-$active,
        'colleges'=>(int)$colleges[0]['total'], 'prospectuses'=>$prospectuses, 'subjects'=>(int)$subjects[0]['total'],
    ]];
}
