<?php
require_once __DIR__ . '/assessment_helper_lms.php';
require_once __DIR__ . '/grading_helper_lms.php';

// Called only after the page resolves the signed-in professor's assigned class.
// Read aggregate LMS data without loading questions, answer keys, or student data.
function synk_class_overview_summary_lms(mysqli $lms, array $context): array
{
    $summary = ['assessment_count'=>null,'grading_periods'=>null];
    try {
        if (synk_assessment_ready_lms($lms)) {
            $rows = synk_assessment_query_lms($lms, "SELECT COUNT(*) AS total FROM tbl_lms_assessments
                WHERE faculty_id=? AND offering_id=? AND ay_id=? AND semester=? AND status<>'archived'",
                'iiii', synk_assessment_scope_params_lms($context));
            $summary['assessment_count'] = (int)$rows[0]['total'];
        }
    } catch (Throwable $error) { error_log('LMS overview assessment count is temporarily unavailable.'); }
    try {
        if (synk_grading_ready_lms($lms)) {
            $rows = synk_grading_query_lms($lms, "SELECT s.grading_period,COUNT(c.component_id) AS components,COALESCE(SUM(c.weight_bps),0) AS total
                FROM tbl_lms_grading_schemes s LEFT JOIN tbl_lms_grading_components c ON c.scheme_id=s.scheme_id AND c.status='active'
                WHERE s.faculty_id=? AND s.offering_id=? AND s.ay_id=? AND s.semester=?
                GROUP BY s.scheme_id,s.grading_period", 'iiii', synk_grading_scope_lms($context));
            $summary['grading_periods'] = ['midterm'=>false,'final'=>false];
            foreach ($rows as $row) {
                if (array_key_exists($row['grading_period'],$summary['grading_periods'])) {
                    $summary['grading_periods'][$row['grading_period']] = (int)$row['components'] > 0 && (int)$row['total'] === 10000;
                }
            }
        }
    } catch (Throwable $error) { error_log('LMS overview grading status is temporarily unavailable.'); }
    return $summary;
}
