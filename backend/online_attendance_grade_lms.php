<?php
require_once __DIR__.'/online_attendance_helper_lms.php';
require_once __DIR__.'/gradebook_api_helper_lms.php';

function synk_online_attendance_grade_item_lms(mysqli $lms, ?array $period, array $book): ?array
{
    if (empty($period['gradebook_item_id'])) return null;
    $item = synk_grading_query_lms($lms,'SELECT item_id,component_id,status FROM tbl_lms_gradebook_items
        WHERE item_id=? AND book_id=?','ii',[(int)$period['gradebook_item_id'],$book['book_id']])[0] ?? null;
    if (!$item) throw new RuntimeException('The online attendance entry does not match this gradebook.');
    return $item;
}

function synk_online_attendance_apply_lms(mysqli $lms, array $context, array $period, array $book, array $totals, array $input, int $actor): void
{
    $digest = $input['attendance_digest'] ?? null;
    if (!is_string($digest) || !hash_equals($totals['digest'],$digest)) {
        throw new DomainException('Attendance changed since you reviewed the totals. Refresh before applying scores.',409);
    }
    $item = synk_online_attendance_grade_item_lms($lms,$period,$book);
    $hasScores = $totals['scored_count'] > 0;
    if (!$hasScores && !$item) throw new DomainException('Close an attendance session with eligible students before applying scores.',422);
    $component = $hasScores ? synk_grading_integer_lms($input['component_id'] ?? null) : (int)$item['component_id'];
    if ($hasScores && !in_array($component,array_column($book['scheme']['components'],'component_id'),true)) {
        throw new DomainException('Choose a Grade Book component for this period.',422);
    }
    $status = $hasScores ? 'active' : 'archived';
    if ($item && $period['applied_digest'] === $digest && $item['status'] === $status && (int)$item['component_id'] === $component) {
        return;
    }

    $gradeTime = synk_assessment_now_lms();
    $book = synk_gradebook_open_for_write_lms($lms,$context,$book,$input,$gradeTime);
    if ($hasScores && (!$item || $item['status'] !== 'active') && count(synk_gradebook_items_lms($lms,$book['book_id'])) >= 300) {
        throw new DomainException('This Grade Book period already has 300 active entries.',422);
    }

    $title = 'Online attendance total';
    $beforeScores = $item ? synk_grading_query_lms($lms,'SELECT student_id,points FROM tbl_lms_gradebook_scores
        WHERE item_id=? ORDER BY student_id','i',[(int)$item['item_id']]) : [];
    if ($item) {
        $itemId = (int)$item['item_id'];
        synk_grading_query_lms($lms,'UPDATE tbl_lms_gradebook_items SET component_id=?,status=?,updated_at=? WHERE item_id=?',
            'issi',[$component,$status,$gradeTime,$itemId]);
    } else {
        synk_grading_query_lms($lms,"INSERT INTO tbl_lms_gradebook_items
            (book_id,component_id,title,entry_kind,max_points,created_at,updated_at) VALUES (?,?,?,'score',100,?,?)",
            'iisss',[$book['book_id'],$component,$title,$gradeTime,$gradeTime]);
        $itemId = (int)$lms->insert_id;
    }

    $entries = [];
    if ($hasScores) {
        foreach ($totals['rows'] as $row) {
            if ($row['score'] === null) continue;
            $entries[] = ['student_id'=>(int)$row['student_id'],'points'=>$row['score'],'remark'=>''];
        }
        synk_grading_query_lms($lms,'DELETE FROM tbl_lms_gradebook_scores WHERE item_id=?','i',[$itemId]);
        synk_gradebook_write_scores_lms($lms,$itemId,$entries,$actor,$gradeTime);
    }
    synk_grading_query_lms($lms,'UPDATE tbl_lms_online_attendance_periods SET gradebook_item_id=?,applied_digest=?,applied_at=?
        WHERE attendance_period_id=?','issi',[$itemId,$digest,synk_online_attendance_now_lms(),(int)$period['attendance_period_id']]);
    synk_gradebook_record_revision_lms($lms,$book,$actor,'apply_online_attendance',[
        'attendance_period_id'=>(int)$period['attendance_period_id'],'item_id'=>$itemId,'attendance_digest'=>$digest,
        'closed_sessions'=>$totals['closed_count'],'before'=>$item,'before_scores'=>$beforeScores,
        'after'=>['component_id'=>$component,'status'=>$status,'scores'=>$entries],
    ],$gradeTime);
}
