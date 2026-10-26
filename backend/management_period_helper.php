<?php
// Midterm / Final belong exclusively to Management. Shared academic helpers and
// Academic Settings continue to describe only academic year and semester.
function synk_management_exam_term_options(): array
{
    return ['MIDTERM'=>'Midterm', 'FINAL'=>'Final'];
}

function synk_management_exam_term($value): string
{
    if (!is_string($value) || !isset(synk_management_exam_term_options()[strtoupper(trim($value))])) {
        throw new DomainException('Choose Midterm or Final.');
    }
    return strtoupper(trim($value));
}

function synk_management_period_ready(mysqli $conn): bool
{
    return synk_table_exists($conn, 'tbl_management_period_settings');
}

function synk_management_fetch_current_period(mysqli $conn, array $scope, bool $lock = false): array
{
    // Read the existing year/semester; never write shared Academic Settings.
    $rows = synk_management_query($conn, 'SELECT s.current_ay_id,s.current_semester,ay.ay
        FROM tbl_academic_settings s LEFT JOIN tbl_academic_years ay ON ay.ay_id=s.current_ay_id
        ORDER BY s.setting_id LIMIT 1' . ($lock ? ' FOR UPDATE' : ''));
    $ayId = (int)($rows[0]['current_ay_id'] ?? 0);
    $semester = (int)($rows[0]['current_semester'] ?? 0);
    if (!$ayId || !in_array($semester,[1,2,3],true) || empty($rows[0]['ay'])) {
        throw new SynkManagementConflict('The current academic year and semester are unavailable.');
    }
    $settings = [];
    if (synk_management_period_ready($conn)) {
        $settings = synk_management_query($conn, 'SELECT current_exam_term,revision FROM tbl_management_period_settings
            WHERE college_id=? AND organization_id=? AND ay_id=? AND semester=?' . ($lock ? ' FOR UPDATE' : ''),
            'iiii', [$scope['college_id'],$scope['organization_id'],$ayId,$semester]);
    } elseif ($lock) throw new SynkManagementConflict('Management term storage has not been prepared.');
    $examTerm = synk_management_exam_term($settings[0]['current_exam_term'] ?? 'MIDTERM');
    $key = $ayId . ':' . $semester;
    $text = $rows[0]['ay'] . ' - ' . synk_semester_label($semester);
    return ['ay_id'=>$ayId,'semester'=>$semester,'ay_label'=>$rows[0]['ay'],
        'semester_label'=>synk_semester_label($semester),'key'=>$key,'term_text'=>$text,
        'exam_term'=>$examTerm,'exam_term_label'=>synk_management_exam_term_options()[$examTerm],
        'period_key'=>$key.':'.$examTerm,'period_text'=>$text.' - '.synk_management_exam_term_options()[$examTerm],
        'period_version'=>(string)($settings[0]['revision'] ?? '0')];
}
