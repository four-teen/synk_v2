<?php
require_once __DIR__ . '/db_lms.php';
require_once __DIR__ . '/assessment_helper_lms.php';

// Only pass classes already resolved from the signed-in professor's workload.
// Read LMS metadata in batches; never load questions, scores, or grading settings.
function synk_professor_workload_summaries_lms(mysqli $core, int $facultyId, array $classes, int $ayId, int $semester): array
{
    $summaries = [];
    foreach ($classes as $class) {
        $offeringId = (int)($class['offering_id'] ?? 0);
        if ($offeringId > 0 && $facultyId > 0 && $ayId > 0 && in_array($semester, [1, 2, 3], true)
            && (int)($class['faculty_id'] ?? 0) === $facultyId
            && (int)($class['ay_id'] ?? 0) === $ayId && (int)($class['semester'] ?? 0) === $semester) {
            $summaries[$offeringId] = ['activity_count' => 0, 'draft_count' => 0, 'next_deadline' => null];
        }
    }
    if (!$summaries) return [];

    try {
        $lms = synk_database_connection_lms($core);
        if (!synk_assessment_ready_lms($lms)) return [];
        $offeringIds = array_keys($summaries);
        $placeholders = implode(',', array_fill(0, count($offeringIds), '?'));
        $now = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s');
        $rows = synk_assessment_query_lms($lms, "SELECT offering_id,COUNT(*) AS activity_count,
            SUM(status='draft') AS draft_count,
            MIN(CASE WHEN status='published' AND due_at>? THEN due_at END) AS next_due_at
            FROM tbl_lms_assessments WHERE faculty_id=? AND ay_id=? AND semester=?
                AND offering_id IN ({$placeholders}) AND status<>'archived'
            GROUP BY offering_id", 'siii' . str_repeat('i', count($offeringIds)),
            array_merge([$now, $facultyId, $ayId, $semester], $offeringIds));

        $deadlineConditions = [];
        $deadlineParams = [$facultyId, $ayId, $semester];
        foreach ($rows as $row) {
            $offeringId = (int)$row['offering_id'];
            $summaries[$offeringId]['activity_count'] = (int)$row['activity_count'];
            $summaries[$offeringId]['draft_count'] = (int)$row['draft_count'];
            if ($row['next_due_at'] !== null) {
                $deadlineConditions[] = '(offering_id=? AND due_at=?)';
                $deadlineParams[] = $offeringId;
                $deadlineParams[] = $row['next_due_at'];
            }
        }
        if ($deadlineConditions) {
            $deadlines = synk_assessment_query_lms($lms, "SELECT offering_id,title,due_at
                FROM tbl_lms_assessments WHERE faculty_id=? AND ay_id=? AND semester=? AND status='published'
                    AND (" . implode(' OR ', $deadlineConditions) . ") ORDER BY assessment_id",
                'iii' . str_repeat('is', count($deadlineConditions)), $deadlineParams);
            foreach ($deadlines as $deadline) {
                $offeringId = (int)$deadline['offering_id'];
                // Keep a deterministic title when activities share the earliest deadline.
                if ($summaries[$offeringId]['next_deadline'] === null) {
                    $summaries[$offeringId]['next_deadline'] = ['title' => $deadline['title'], 'due_at' => $deadline['due_at']];
                }
            }
        }
        return $summaries;
    } catch (Throwable $error) {
        error_log('LMS workload activity summaries are temporarily unavailable.');
        return [];
    }
}
