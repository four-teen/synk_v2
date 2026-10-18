<?php
// Read-only browser queries. Browse filters never change account or scheduling context.
function synk_prospectus_browser_query(mysqli $conn, string $sql, string $types = '', array $values = []): array
{
    $stmt = $conn->prepare($sql);
    try {
        if ($types !== '') $stmt->bind_param($types, ...$values);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally { $stmt->close(); }
}

function synk_prospectus_browser_filters(mysqli $conn, string $role, int $assignedCollege): array
{
    $admin = $role === 'admin';
    if (!$admin && $assignedCollege <= 0) throw new DomainException('An assigned college is required.', 403);
    $types = $admin ? '' : 'i';
    $values = $admin ? [] : [$assignedCollege];
    $campuses = synk_prospectus_browser_query($conn,
        'SELECT cp.campus_id,cp.campus_code,cp.campus_name,cp.status FROM tbl_campus cp'
        . ($admin ? '' : ' WHERE EXISTS (SELECT 1 FROM tbl_college c WHERE c.campus_id=cp.campus_id AND c.college_id=?)')
        . ' ORDER BY cp.campus_name', $types, $values);
    $colleges = synk_prospectus_browser_query($conn,
        'SELECT c.college_id,c.campus_id,c.college_code,c.college_name,c.status FROM tbl_college c'
        . ($admin ? '' : ' WHERE c.college_id=?') . ' ORDER BY c.college_name', $types, $values);
    $programs = synk_prospectus_browser_query($conn,
        'SELECT p.program_id,p.college_id,c.campus_id,p.program_name,p.program_code,p.major,p.status,cp.campus_code,
            (SELECT COUNT(*) FROM tbl_prospectus_header h WHERE h.program_id=p.program_id) AS curriculum_count
         FROM tbl_program p JOIN tbl_college c ON c.college_id=p.college_id
         JOIN tbl_campus cp ON cp.campus_id=c.campus_id'
        . ($admin ? '' : ' WHERE p.college_id=?') . ' ORDER BY p.program_name,p.major,p.program_id', $types, $values);
    return ['campuses'=>$campuses,'colleges'=>$colleges,'programs'=>$programs];
}

function synk_prospectus_browser_filter_id($value, bool $optional = false): int
{
    if ($optional && ($value === '' || $value === null || $value === '0' || $value === 0)) return 0;
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) || (float)$value > 2147483647) {
        throw new DomainException('Choose a valid campus, college, and program.', 422);
    }
    return (int)$value;
}

function synk_prospectus_browser_rows(mysqli $conn, string $role, int $assignedCollege, array $filters): array
{
    $programId = synk_prospectus_browser_filter_id($filters['program_id'] ?? null, true);
    if ($role === 'admin') {
        $campusId = synk_prospectus_browser_filter_id($filters['campus_id'] ?? null);
        $collegeId = synk_prospectus_browser_filter_id($filters['college_id'] ?? null);
    } else {
        $scope = synk_prospectus_browser_query($conn, 'SELECT college_id,campus_id FROM tbl_college WHERE college_id=?', 'i', [$assignedCollege]);
        if (!$scope) throw new DomainException('An assigned college is required.', 403);
        $collegeId = (int)$scope[0]['college_id'];
        $campusId = (int)$scope[0]['campus_id'];
        if ((isset($filters['college_id']) && synk_prospectus_browser_filter_id($filters['college_id']) !== $collegeId)
            || (isset($filters['campus_id']) && synk_prospectus_browser_filter_id($filters['campus_id']) !== $campusId)) {
            throw new DomainException('You can browse prospectuses only in your assigned college.', 403);
        }
    }
    if (!synk_prospectus_browser_query($conn,
        'SELECT c.college_id FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.college_id=? AND cp.campus_id=?',
        'ii', [$collegeId,$campusId])) {
        throw new DomainException('The selected college does not belong to this campus.', 422);
    }
    if ($programId && !synk_prospectus_browser_query($conn,
        'SELECT program_id FROM tbl_program WHERE program_id=? AND college_id=?', 'ii', [$programId,$collegeId])) {
        throw new DomainException('The selected program does not belong to this college.', 403);
    }
    $sql = 'SELECT h.prospectus_id,h.program_id,h.cmo_no,h.effective_sy,
            p.program_name,p.program_code,p.major,cp.campus_code,
            COUNT(DISTINCT ys.pys_id) AS yearsem_count,COUNT(ps.ps_id) AS subject_count,
            ROUND(COALESCE(SUM(ps.total_units),0),2) AS total_units
        FROM tbl_prospectus_header h JOIN tbl_program p ON p.program_id=h.program_id
        JOIN tbl_college c ON c.college_id=p.college_id JOIN tbl_campus cp ON cp.campus_id=c.campus_id
        LEFT JOIN tbl_prospectus_year_sem ys ON ys.prospectus_id=h.prospectus_id
        LEFT JOIN tbl_prospectus_subjects ps ON ps.pys_id=ys.pys_id
        WHERE c.campus_id=? AND p.college_id=?' . ($programId ? ' AND p.program_id=?' : '') . '
        GROUP BY h.prospectus_id,h.program_id,h.cmo_no,h.effective_sy,p.program_name,p.program_code,p.major,cp.campus_code
        ORDER BY p.program_name,p.major,p.program_id,h.prospectus_id DESC';
    return synk_prospectus_browser_query($conn, $sql, $programId ? 'iii' : 'ii',
        $programId ? [$campusId,$collegeId,$programId] : [$campusId,$collegeId]);
}
