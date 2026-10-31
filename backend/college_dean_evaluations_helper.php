<?php
require_once __DIR__ . '/college_dean_workload_helper.php';
require_once __DIR__ . '/student_faculty_evaluation_instrument.php';
require_once __DIR__ . '/evaluation_access_helper.php';
require_once __DIR__ . '/evaluation_subject_exclusion_helper.php';

function synk_dean_evaluations_query(mysqli $conn, string $sql, string $types, array $params): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare the faculty evaluation report.');
    try {
        synk_bind_dynamic_params($stmt, $types, $params);
        synk_dean_roster_execute($stmt);
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally { $stmt->close(); }
}

function synk_dean_evaluations_filters(array $input): array
{
    foreach (['faculty_id', 'instrument_version', 'search'] as $field) {
        if (isset($input[$field]) && !is_scalar($input[$field])) throw new DomainException('Choose valid evaluation filters.');
    }
    $facultyId = filter_var($input['faculty_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
    if ($facultyId === false) throw new DomainException('Choose a valid faculty member.');
    $version = (string)($input['instrument_version'] ?? 'all');
    if (!in_array($version, ['all', 'legacy-20', synk_student_faculty_evaluation_current_version()], true)) throw new DomainException('Choose an available evaluation instrument.');
    $search = trim((string)($input['search'] ?? ''));
    if ((function_exists('mb_strlen') ? mb_strlen($search, 'UTF-8') : strlen($search)) > 150) throw new DomainException('Search using up to 150 characters.');
    return ['faculty_id' => $facultyId, 'instrument_version' => $version, 'search' => $search];
}

function synk_dean_evaluations_source(mysqli $conn, array $scope, array $term, string $version): array
{
    $versionExpr = synk_table_has_column($conn, 'tbl_student_faculty_evaluations', 'instrument_version') ? 'e.instrument_version' : "'legacy-20'";
    $ownerSql = synk_evaluation_owner_sql($conn, 'e', (int)$scope['college_id']);
    $sql = "FROM tbl_student_faculty_evaluations e
        LEFT JOIN tbl_faculty f ON f.faculty_id=e.faculty_id
        WHERE e.ay_id=? AND e.semester=? AND {$ownerSql}";
    $types = 'ii';
    $params = [$term['ay_id'], $term['semester']];
    if ($version !== 'all') { $sql .= ' AND ' . $versionExpr . '=?'; $types .= 's'; $params[] = $version; }
    return ['sql' => $sql, 'types' => $types, 'params' => $params, 'version_expr' => $versionExpr];
}

function synk_dean_evaluations_data(mysqli $conn, array $scope, array $term, array $filters): array
{
    $scoreStates=synk_evaluation_score_states($conn,(int)$term['ay_id'],(int)$term['semester']);
    $rosterStudents = []; $subjectIds = [];
    $faculties = [];
    foreach (synk_dean_workload_faculties($conn, $scope, $term) as $faculty) {
        $faculties[$faculty['faculty_id']] = ['faculty_id' => $faculty['faculty_id'], 'faculty_name' => $faculty['faculty_name']];
    }
    if (synk_table_exists($conn, 'tbl_student_management_enrolled_subjects')) {
        $imports = synk_dean_evaluations_query($conn, "SELECT DISTINCT f.faculty_id,
            CONCAT(f.last_name, ', ', TRIM(CONCAT_WS(' ', f.first_name, NULLIF(f.middle_name,''), NULLIF(f.ext_name,'')))) AS faculty_name
            FROM tbl_student_management_enrolled_subjects es JOIN tbl_faculty f ON f.faculty_id=es.faculty_id
            WHERE es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=?", 'iiii',
            [$scope['college_id'], $scope['campus_id'], $term['ay_id'], $term['semester']]);
        foreach ($imports as $faculty) {
            $faculty['faculty_id'] = (int)$faculty['faculty_id'];
            $faculties[$faculty['faculty_id']] = $faculty;
        }
        $activeOnly = synk_evaluation_is_current($term, synk_fetch_current_academic_term($conn)) ? ' AND es.is_active=1' : '';
        $members = synk_dean_evaluations_query($conn, "SELECT DISTINCT es.faculty_id,es.student_id,es.subject_id,es.subject_code
            FROM tbl_student_management_enrolled_subjects es JOIN tbl_student_management s ON s.student_id=es.student_id
            WHERE es.college_id=? AND es.campus_id=? AND es.ay_id=? AND es.semester=? AND es.faculty_id>0{$activeOnly}",
            'iiii', [$scope['college_id'],$scope['campus_id'],$term['ay_id'],$term['semester']]);
        foreach ($members as $member) {
            $id=(int)$member['faculty_id'];
            $rosterStudents[$id][(int)$member['student_id']]=true;
            $subjectKey=(int)$member['subject_id']>0 ? 'id:'.(int)$member['subject_id'] : 'code:'.strtoupper(trim((string)$member['subject_code']));
            if ($subjectKey!=='code:') $subjectIds[$id][$subjectKey]=true;
        }
    }
    $assignmentSource=synk_professor_workload_assignment_source_sql();
    foreach (synk_dean_evaluations_query($conn,"SELECT DISTINCT fw.faculty_id,sm.sub_id AS subject_id {$assignmentSource}
        JOIN tbl_program p ON p.program_id=po.program_id WHERE p.college_id=? AND fw.ay_id=? AND fw.semester=?",
        'iii',[$scope['college_id'],$term['ay_id'],$term['semester']]) as $subject) {
        $subjectIds[(int)$subject['faculty_id']]['id:'.(int)$subject['subject_id']]=true;
    }
    $headers = []; $answerRows = [];
    $ready = synk_table_exists($conn, 'tbl_student_faculty_evaluations')
        && synk_table_exists($conn, 'tbl_student_faculty_evaluation_answers')
        && synk_table_exists($conn, 'tbl_student_management_enrolled_subjects');
    if ($ready) {
        $source = synk_dean_evaluations_source($conn, $scope, $term, $filters['instrument_version']);
        $headers = synk_dean_evaluations_query($conn, "SELECT e.evaluation_id,e.faculty_id,e.student_id,e.submission_status,
            e.total_score,e.question_count,{$source['version_expr']} AS instrument_version,
            COALESCE(NULLIF(TRIM(CONCAT(f.last_name, ', ', TRIM(CONCAT_WS(' ', f.first_name, NULLIF(f.middle_name,''), NULLIF(f.ext_name,''))))),''),NULLIF(e.faculty_name,''),'Faculty record unavailable') AS faculty_name,
            CASE WHEN e.submission_status='submitted' AND e.faculty_id=? THEN e.comment_text ELSE '' END AS comment_text,
            COALESCE(e.final_submitted_at,e.completed_at) AS submitted_at {$source['sql']}", 'i' . $source['types'], array_merge([$filters['faculty_id']], $source['params']));
        // The overview needs totals only. Fetch benchmark responses only for
        // the selected faculty instead of every answer in the whole college.
        if ($filters['faculty_id'] > 0) $answerRows = synk_dean_evaluations_query($conn, "SELECT e.faculty_id,{$source['version_expr']} AS instrument_version,
            a.category_key,a.category_title,a.question_key,a.question_order,a.question_text,a.rating "
            . str_replace('WHERE e.ay_id', 'JOIN tbl_student_faculty_evaluation_answers a ON a.evaluation_id=e.evaluation_id WHERE e.ay_id', $source['sql'])
            . " AND e.submission_status='submitted' AND ".synk_evaluation_score_predicate($scoreStates)." AND e.faculty_id=? ORDER BY a.question_order,a.answer_id", $source['types'] . 'i', array_merge($source['params'], [$filters['faculty_id']]));
    }
    $groups = []; $drafts = []; $submitted = []; $students = []; $submittedStudents = []; $excluded=[]; $review=[];
    foreach ($headers as $header) {
        $id = (int)$header['faculty_id'];
        $faculties[$id] = ['faculty_id' => $id, 'faculty_name' => $header['faculty_name']];
        if ((int)$header['student_id']>0) $rosterStudents[$id][(int)$header['student_id']]=true;
        if ($header['submission_status'] !== 'submitted') { $drafts[$id] = ($drafts[$id] ?? 0) + 1; continue; }
        $submitted[$id] = ($submitted[$id] ?? 0) + 1;
        $students[(int)$header['student_id']] = true;
        $submittedStudents[$id][(int)$header['student_id']] = true;
        $state=$scoreStates[(int)$header['evaluation_id']]['state'] ?? 'included';
        if ($state==='review') $review[$id]=($review[$id] ?? 0)+1;
        if ($state==='excluded') $excluded[$id]=($excluded[$id] ?? 0)+1;
        $version = $header['instrument_version'];
        if (!isset($groups[$id][$version])) $groups[$id][$version] = ['instrument_version' => $version,
            'instrument' => synk_student_faculty_evaluation_instrument($version), 'evaluation_count' => 0,
            'latest_submitted_at' => '', 'comments' => [], 'categories' => [], '_points' => 0, '_answers' => 0];
        $group = &$groups[$id][$version];
        if ($state!=='excluded') {
            $group['evaluation_count']++;
            $group['_points'] += (int)$header['total_score'];
            $group['_answers'] += (int)$header['question_count'];
        }
        $group['latest_submitted_at'] = max($group['latest_submitted_at'], (string)$header['submitted_at']);
        if (trim((string)$header['comment_text']) !== '') $group['comments'][] = (string)$header['comment_text'];
        unset($group);
    }
    foreach ($answerRows as $answer) {
        $id = (int)$answer['faculty_id']; $version = $answer['instrument_version'];
        if (!isset($groups[$id][$version])) continue;
        $categoryKey = $answer['category_key']; $questionKey = $answer['question_key'];
        $group = &$groups[$id][$version];
        if (!isset($group['categories'][$categoryKey])) $group['categories'][$categoryKey] = [
            'title' => $answer['category_title'], 'questions' => [], '_points' => 0, '_answers' => 0];
        $category = &$group['categories'][$categoryKey];
        if (!isset($category['questions'][$questionKey])) $category['questions'][$questionKey] = [
            'question_key' => $questionKey, 'order' => (int)$answer['question_order'], 'text' => $answer['question_text'], '_points' => 0, '_answers' => 0];
        $rating = (int)$answer['rating'];
        $category['_points'] += $rating; $category['_answers']++;
        $category['questions'][$questionKey]['_points'] += $rating;
        $category['questions'][$questionKey]['_answers']++;
        unset($category, $group);
    }
    foreach ($groups as &$facultyGroups) {
        foreach ($facultyGroups as &$group) {
            $group['score'] = $group['_answers']>0 ? synk_student_faculty_evaluation_score($group['_points'], $group['_answers']) : null;
            foreach ($group['categories'] as &$category) {
                $category['score'] = synk_student_faculty_evaluation_score($category['_points'], $category['_answers']);
                foreach ($category['questions'] as &$question) {
                    $question['score'] = synk_student_faculty_evaluation_score($question['_points'], $question['_answers']);
                    $question['response_count'] = $question['_answers'];
                    unset($question['_points'], $question['_answers']);
                }
                unset($question, $category['_points'], $category['_answers']);
            }
            unset($category, $group['_points'], $group['_answers']);
        }
        unset($group);
    }
    unset($facultyGroups);
    uasort($faculties, static function ($a, $b) { return strnatcasecmp($a['faculty_name'], $b['faculty_name']); });
    $detail = null;
    if ($filters['faculty_id']) {
        if (!isset($faculties[$filters['faculty_id']])) throw new OutOfBoundsException('This faculty member is outside your college and selected term.');
        $detail = ['faculty' => $faculties[$filters['faculty_id']], 'groups' => array_values($groups[$filters['faculty_id']] ?? []),
            'excluded_count'=>$excluded[$filters['faculty_id']] ?? 0,'review_count'=>$review[$filters['faculty_id']] ?? 0,
            'draft_count' => $drafts[$filters['faculty_id']] ?? 0];
    }
    $rows = [];
    foreach ($faculties as $id => $faculty) {
        $matches = true;
        foreach (preg_split('/\s+/u', $filters['search'], -1, PREG_SPLIT_NO_EMPTY) as $word) {
            if ((function_exists('mb_stripos') ? mb_stripos($faculty['faculty_name'], $word, 0, 'UTF-8') : stripos($faculty['faculty_name'], $word)) === false) $matches = false;
        }
        if (!$matches) continue;
        $facultyGroups = array_values(array_filter($groups[$id] ?? [],static function(array $group):bool { return $group['score']!==null; }));
        $rows[] = $faculty + ['submitted_count' => $submitted[$id] ?? 0, 'draft_count' => $drafts[$id] ?? 0,
            'excluded_count'=>$excluded[$id] ?? 0,'included_count'=>($submitted[$id] ?? 0)-($excluded[$id] ?? 0),'review_count'=>$review[$id] ?? 0,
            'subject_count'=>count($subjectIds[$id] ?? []), 'submitted_student_count'=>count($submittedStudents[$id] ?? []),
            'not_submitted_count'=>count(array_diff_key($rosterStudents[$id] ?? [],$submittedStudents[$id] ?? [])),
            'student_count'=>count($rosterStudents[$id] ?? []),
            'instrument_count' => count($facultyGroups), 'score' => count($facultyGroups) === 1 ? $facultyGroups[0]['score'] : null];
    }
    return ['rows' => $rows, 'detail' => $detail, 'summary' => ['faculty_count' => count($faculties),
        'submitted_count' => array_sum($submitted), 'draft_count' => array_sum($drafts), 'student_count' => count($students)]];
}
