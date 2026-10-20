<?php
require_once __DIR__ . '/professor_portal_helper.php';
require_once __DIR__ . '/evaluation_access_helper.php';
require_once __DIR__ . '/student_faculty_evaluation_instrument.php';

function synk_professor_evaluation_identity(mysqli $conn): array
{
    if (($_SESSION['role'] ?? '')!=='professor' || empty($_SESSION['user_id'])) throw new DomainException('Sign in to your professor account to view evaluations.');
    $account=synk_find_useraccount_by_id($conn,(int)$_SESSION['user_id']);
    if (!$account || ($account['status'] ?? '')!=='active' || !synk_useraccount_has_active_role($conn,$account,'professor')) throw new DomainException('Your professor access is no longer active.');
    $portal=synk_professor_resolve_portal_context($conn);
    if (empty($portal['faculty_id']) || empty($portal['faculty_is_active'])) throw new DomainException('An active faculty record must be linked to your account to view evaluation results.');
    return $portal;
}

// Only released college/term combinations are returned; drafts never create options.
function synk_professor_evaluation_options(mysqli $conn, int $facultyId): array
{
    if (!synk_evaluation_table_exists($conn,'tbl_college_evaluation_settings')
        || !synk_evaluation_has_ownership($conn)) return [];
    return synk_evaluation_query($conn,"SELECT s.college_id,s.ay_id,s.semester,c.college_name,ay.ay AS ay_label
        FROM tbl_college_evaluation_settings s JOIN tbl_college c ON c.college_id=s.college_id
        JOIN tbl_campus cp ON cp.campus_id=c.campus_id JOIN tbl_academic_years ay ON ay.ay_id=s.ay_id
        WHERE s.faculty_released=1 AND c.status='active' AND cp.status='active'
        AND EXISTS (SELECT 1 FROM tbl_student_faculty_evaluations e
            WHERE e.faculty_id=? AND e.submission_status='submitted' AND e.ay_id=s.ay_id AND e.semester=s.semester
            AND (e.college_id=s.college_id OR (e.college_id=0
                AND EXISTS (SELECT 1 FROM tbl_student_management_enrolled_subjects es WHERE es.student_id=e.student_id
                    AND es.faculty_id=e.faculty_id AND es.ay_id=e.ay_id AND es.semester=e.semester AND es.college_id=s.college_id AND es.campus_id=c.campus_id)
                AND NOT EXISTS (SELECT 1 FROM tbl_student_management_enrolled_subjects es WHERE es.student_id=e.student_id
                    AND es.faculty_id=e.faculty_id AND es.ay_id=e.ay_id AND es.semester=e.semester AND es.college_id>0 AND es.college_id<>s.college_id))))
        ORDER BY ay.ay DESC,s.ay_id DESC,s.semester DESC,c.college_name",'i',[$facultyId]);
}

function synk_professor_evaluation_results(mysqli $conn, array $term): array
{
    // Re-read account and faculty link here. A supplied faculty_id is never trusted.
    $portal=synk_professor_evaluation_identity($conn);
    $facultyId=(int)$portal['faculty_id']; $collegeId=(int)$term['college_id'];
    $owner=synk_evaluation_owner_sql($conn,'e',$collegeId);
    // Release is checked in each data query, including comments and question means.
    $source="FROM tbl_student_faculty_evaluations e
        JOIN tbl_college_evaluation_settings s ON s.college_id={$collegeId} AND s.ay_id=e.ay_id AND s.semester=e.semester AND s.faculty_released=1
        JOIN tbl_college c ON c.college_id=s.college_id AND c.status='active'
        JOIN tbl_campus cp ON cp.campus_id=c.campus_id AND cp.status='active'
        WHERE e.faculty_id=? AND e.ay_id=? AND e.semester=? AND e.submission_status='submitted' AND {$owner}";
    $params=[$facultyId,(int)$term['ay_id'],(int)$term['semester']];
    $headers=synk_evaluation_query($conn,"SELECT e.instrument_version,COUNT(*) AS response_count,SUM(e.total_score) AS points,SUM(e.question_count) AS answers {$source} GROUP BY e.instrument_version",'iii',$params);
    $answerSource=str_replace('WHERE e.faculty_id','JOIN tbl_student_faculty_evaluation_answers a ON a.evaluation_id=e.evaluation_id WHERE e.faculty_id',$source);
    $questions=synk_evaluation_query($conn,"SELECT e.instrument_version,a.category_key,a.category_title,a.question_key,a.question_order,a.question_text,SUM(a.rating) AS points,COUNT(*) AS answers {$answerSource} GROUP BY e.instrument_version,a.category_key,a.category_title,a.question_key,a.question_order,a.question_text ORDER BY a.question_order",'iii',$params);
    $comments=synk_evaluation_query($conn,"SELECT e.instrument_version,e.comment_text {$source} AND TRIM(COALESCE(e.comment_text,''))<>'' ORDER BY e.comment_text",'iii',$params);
    $groups=[];
    foreach ($headers as $header) {
        $version=$header['instrument_version'];
        $groups[$version]=['instrument_version'=>$version,'instrument'=>synk_student_faculty_evaluation_instrument($version),
            'response_count'=>(int)$header['response_count'],'score'=>synk_student_faculty_evaluation_score((float)$header['points'],(int)$header['answers']),
            'categories'=>[],'comments'=>[]];
    }
    foreach ($questions as $question) {
        $version=$question['instrument_version']; $key=$question['category_key'];
        if (!isset($groups[$version])) continue;
        if (!isset($groups[$version]['categories'][$key])) $groups[$version]['categories'][$key]=['title'=>$question['category_title'],'points'=>0,'answers'=>0,'questions'=>[]];
        $category=&$groups[$version]['categories'][$key];
        $category['points']+=(float)$question['points']; $category['answers']+=(int)$question['answers'];
        $category['questions'][]=['text'=>$question['question_text'],'order'=>(int)$question['question_order'],'response_count'=>(int)$question['answers'],
            'score'=>synk_student_faculty_evaluation_score((float)$question['points'],(int)$question['answers'])];
        unset($category);
    }
    foreach ($groups as &$group) foreach ($group['categories'] as &$category) $category['score']=synk_student_faculty_evaluation_score($category['points'],$category['answers']);
    unset($group,$category);
    foreach ($comments as $comment) if (isset($groups[$comment['instrument_version']])) $groups[$comment['instrument_version']]['comments'][]=$comment['comment_text'];
    // A concurrent hide must suppress the already-read result as well.
    if (empty(synk_evaluation_settings($conn,$collegeId,(int)$term['ay_id'],(int)$term['semester'])['faculty_released'])) return [];
    return array_values($groups);
}
