<?php
require_once __DIR__ . '/schema_helper.php';

function synk_assessment_query_lms(mysqli $lms, string $sql, string $types = '', array $params = []): array
{
    $stmt = $lms->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare the assessment request.');
    try {
        if ($types !== '' && !synk_bind_dynamic_params($stmt, $types, $params)) throw new RuntimeException('Unable to bind the assessment request.');
        if (!$stmt->execute()) throw new RuntimeException('Unable to complete the assessment request.');
        $result = $stmt->get_result();
        return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    } finally { $stmt->close(); }
}

function synk_assessment_ready_lms(mysqli $lms): bool
{
    foreach (['tbl_lms_assessments','tbl_lms_assessment_questions','tbl_lms_assessment_logs'] as $table) {
        // Schema helper caches by table only; use this connection explicitly for LMS storage.
        $rows = synk_assessment_query_lms($lms,
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', 's', [$table]);
        if (!$rows) return false;
    }
    return true;
}

function synk_assessment_text_lms($value, string $label, int $limit, bool $required = false): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) throw new DomainException('Enter valid text for ' . $label . '.', 422);
    $text = trim(str_replace(["\r\n", "\r"], "\n", $value));
    $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : preg_match_all('/./us', $text);
    if ($length > $limit || ($required && $text === '')) throw new DomainException($label . ($required ? ' is required and' : '') . ' must be at most ' . $limit . ' characters.', 422);
    return $text;
}

function synk_assessment_id_lms($value, bool $optional = false): int
{
    if ($optional && ($value === null || $value === 0 || $value === '0')) return 0;
    if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) || (float)$value > 2147483647) {
        throw new DomainException('Choose a valid assessment record.', 422);
    }
    return (int)$value;
}

function synk_assessment_points_lms($value): float
{
    if ((!is_int($value) && !is_float($value) && !is_string($value))
        || !preg_match('/^(?:0|[1-9][0-9]{0,4})(?:\.[0-9]{1,2})?$/D', (string)$value)
        || (float)$value <= 0 || (float)$value > 10000) {
        throw new DomainException('Points must be greater than zero and at most 10,000, with up to two decimal places.', 422);
    }
    return round((float)$value, 2);
}

function synk_assessment_date_lms($value, string $label): ?string
{
    if ($value === null || $value === '') return null;
    if (!is_string($value)) throw new DomainException('Enter a valid ' . $label . '.', 422);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('Asia/Manila'));
    if (!$date || $date->format('Y-m-d\TH:i') !== $value || (int)$date->format('Y') < 2000 || (int)$date->format('Y') > 2100) {
        throw new DomainException('Enter a valid ' . $label . ' in Philippine time.', 422);
    }
    return $date->format('Y-m-d H:i:s');
}

function synk_assessment_validate_lms(array $input): array
{
    $period = $input['grading_period'] ?? '';
    $type = $input['assessment_type'] ?? '';
    if (!is_string($period) || !in_array($period, ['midterm','final'], true)) throw new DomainException('Choose a grading period.', 422);
    if (!is_string($type) || !in_array($type, ['quiz','exam','assignment'], true)) throw new DomainException('Choose Quiz, Exam, or Assignment.', 422);
    $record = ['grading_period'=>$period, 'assessment_type'=>$type,
        'title'=>synk_assessment_text_lms($input['title'] ?? '', 'Title', 200, true),
        'instructions'=>synk_assessment_text_lms($input['instructions'] ?? '', 'Instructions', 20000),
        'topic'=>synk_assessment_text_lms($input['topic'] ?? '', 'Topic', 120),
        'opens_at'=>synk_assessment_date_lms($input['opens_at'] ?? null, 'opening date'),
        'due_at'=>synk_assessment_date_lms($input['due_at'] ?? null, 'due date'), 'time_limit_minutes'=>null];
    if ($record['opens_at'] !== null && $record['due_at'] !== null && $record['due_at'] <= $record['opens_at']) {
        throw new DomainException('The due date must be after the opening date.', 422);
    }
    $time = $input['time_limit_minutes'] ?? null;
    if ($type !== 'assignment' && $time !== null && $time !== '') {
        if (!is_string($time) && !is_int($time)) throw new DomainException('Enter a valid time limit.', 422);
        $time = filter_var($time, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>240]]);
        if ($time === false) throw new DomainException('A time limit must be between 1 and 240 minutes.', 422);
        $record['time_limit_minutes'] = $time;
    }
    $questions = $input['questions'] ?? [];
    if (!is_array($questions) || array_values($questions) !== $questions || count($questions) > 100) throw new DomainException('An assessment may contain at most 100 questions.', 422);
    if ($type === 'assignment' && $questions) throw new DomainException('Assignments use instructions and a total point value. Use a quiz or exam for questions.', 422);
    $record['questions'] = [];
    $cents = 0;
    foreach ($questions as $index=>$question) {
        if (!is_array($question)) throw new DomainException('Enter valid question details.', 422);
        $label = 'Question ' . ($index + 1);
        $kind = $question['question_type'] ?? '';
        if (!is_string($kind) || !in_array($kind, ['multiple_choice','true_false','short_answer','essay'], true)) throw new DomainException($label . ' has an invalid type.', 422);
        $item = ['question_type'=>$kind, 'prompt'=>synk_assessment_text_lms($question['prompt'] ?? '', $label, 10000, true),
            'points'=>synk_assessment_points_lms($question['points'] ?? null), 'options'=>[], 'answer_key'=>null];
        if ($kind === 'multiple_choice') {
            $options = $question['options'] ?? [];
            if (!is_array($options) || array_values($options) !== $options || count($options) < 2 || count($options) > 6) throw new DomainException($label . ' needs between 2 and 6 choices.', 422);
            $seen = [];
            foreach ($options as $option) {
                $text = synk_assessment_text_lms($option, $label . ' choice', 1000, true);
                $key = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
                if (isset($seen[$key])) throw new DomainException($label . ' contains duplicate choices.', 422);
                $seen[$key] = true; $item['options'][] = $text;
            }
            $answer = $question['answer_key'] ?? null;
            if (!is_int($answer) || $answer < 0 || $answer >= count($item['options'])) throw new DomainException('Choose the correct answer for ' . strtolower($label) . '.', 422);
            $item['answer_key'] = $answer;
        } elseif ($kind === 'true_false') {
            if (!is_bool($question['answer_key'] ?? null)) throw new DomainException('Choose True or False for ' . strtolower($label) . '.', 422);
            $item['answer_key'] = $question['answer_key'];
        } elseif ($kind === 'short_answer') {
            $answers = $question['answer_key'] ?? [];
            if (!is_array($answers) || array_values($answers) !== $answers || count($answers) < 1 || count($answers) > 10) throw new DomainException($label . ' needs 1 to 10 accepted answers.', 422);
            $item['answer_key'] = [];
            foreach ($answers as $answer) $item['answer_key'][] = synk_assessment_text_lms($answer, $label . ' accepted answer', 500, true);
        }
        $cents += (int)round($item['points'] * 100);
        $record['questions'][] = $item;
    }
    if ($cents > 1000000) throw new DomainException('The assessment total may not exceed 10,000 points.', 422);
    $record['total_points'] = $type === 'assignment' ? synk_assessment_points_lms($input['total_points'] ?? null) : $cents / 100;
    return $record;
}

function synk_assessment_scope_params_lms(array $context): array
{
    return [(int)$context['portal']['faculty_id'], (int)$context['class']['offering_id'], (int)$context['class']['ay_id'], (int)$context['class']['semester']];
}

function synk_assessment_list_lms(mysqli $lms, array $context): array
{
    $rows = synk_assessment_query_lms($lms, "SELECT a.assessment_id,a.grading_period,a.assessment_type,a.title,a.topic,a.status,
        a.total_points,a.opens_at,a.due_at,a.time_limit_minutes,a.version,a.updated_at,COUNT(q.question_id) AS question_count
        FROM tbl_lms_assessments a LEFT JOIN tbl_lms_assessment_questions q ON q.assessment_id=a.assessment_id
        WHERE a.faculty_id=? AND a.offering_id=? AND a.ay_id=? AND a.semester=? AND a.status<>'archived'
        GROUP BY a.assessment_id ORDER BY a.updated_at DESC,a.assessment_id DESC", 'iiii', synk_assessment_scope_params_lms($context));
    foreach ($rows as &$row) {
        foreach (['assessment_id','version','question_count'] as $field) $row[$field] = (int)$row[$field];
        $row['total_points'] = (float)$row['total_points'];
    }
    unset($row);
    return $rows;
}

function synk_assessment_detail_lms(mysqli $lms, array $context, int $id, bool $lock = false): array
{
    $rows = synk_assessment_query_lms($lms, 'SELECT * FROM tbl_lms_assessments WHERE assessment_id=? AND faculty_id=?
        AND offering_id=? AND ay_id=? AND semester=?' . ($lock ? ' FOR UPDATE' : ''), 'iiiii', array_merge([$id], synk_assessment_scope_params_lms($context)));
    if (!$rows) throw new DomainException('This assessment is unavailable in your class.', 404);
    $record = $rows[0];
    $record['questions'] = synk_assessment_questions_lms($lms, $id);
    foreach (['assessment_id','version'] as $field) $record[$field] = (int)$record[$field];
    $record['time_limit_minutes'] = $record['time_limit_minutes'] === null ? null : (int)$record['time_limit_minutes'];
    $record['total_points'] = (float)$record['total_points'];
    return $record;
}

function synk_assessment_questions_lms(mysqli $lms, int $id): array
{
    $questions = synk_assessment_query_lms($lms, 'SELECT question_id,question_type,prompt,points,options_json,answer_key_json
        FROM tbl_lms_assessment_questions WHERE assessment_id=? ORDER BY sort_order,question_id', 'i', [$id]);
    foreach ($questions as &$question) {
        $question['question_id'] = (int)$question['question_id']; $question['points'] = (float)$question['points'];
        $question['options'] = json_decode((string)$question['options_json'], true) ?: [];
        $question['answer_key'] = $question['answer_key_json'] === null ? null : json_decode($question['answer_key_json'], true);
        unset($question['options_json'], $question['answer_key_json']);
    }
    unset($question);
    return $questions;
}

function synk_assessment_snapshots_ready_lms(mysqli $lms): bool
{
    return (bool)synk_assessment_query_lms($lms, "SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='tbl_lms_assessment_attempts' AND COLUMN_NAME='assessment_snapshot_json'");
}

function synk_assessment_snapshot_json_lms(array $record): string
{
    $snapshot = array_intersect_key($record, array_flip(['version','grading_period','assessment_type','title','instructions','topic',
        'total_points','opens_at','due_at','time_limit_minutes','questions']));
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('Unable to preserve the original assessment.');
    return $json;
}

// Live status/ownership remain authoritative. Content and settings belong to the started attempt.
function synk_assessment_attempt_record_lms(array $record, ?array $attempt): array
{
    if (!$attempt || ($attempt['assessment_snapshot_json'] ?? null) === null) return $record;
    $snapshot = json_decode($attempt['assessment_snapshot_json'], true);
    if (!is_array($snapshot) || !isset($snapshot['questions'], $snapshot['assessment_type'])) {
        throw new RuntimeException('Unable to read the original assessment.');
    }
    return array_replace($record, array_intersect_key($snapshot, array_flip(['version','grading_period','assessment_type','title','instructions','topic',
        'total_points','opens_at','due_at','time_limit_minutes','questions'])));
}

function synk_assessment_validate_published_lms(array $record): void
{
    if ($record['assessment_type'] !== 'assignment' && !$record['questions']) throw new DomainException('Add at least one complete question before publishing or saving changes.', 422);
    if ($record['assessment_type'] === 'assignment' && $record['instructions'] === '') throw new DomainException('Add assignment instructions before publishing or saving changes.', 422);
}

function synk_assessment_log_lms(mysqli $lms, int $id, int $userId, string $action, array $details): void
{
    synk_assessment_query_lms($lms, 'INSERT INTO tbl_lms_assessment_logs (assessment_id,actor_user_id,action,details_json,recorded_at)
        VALUES (?,?,?,?,?)', 'iisss', [$id,$userId,$action,json_encode($details, JSON_UNESCAPED_UNICODE),date('Y-m-d H:i:s')]);
}

// The caller owns the transaction so the header, questions, and audit entry commit together.
function synk_assessment_save_lms(mysqli $lms, array $context, array $input, int $userId): array
{
    $record = synk_assessment_validate_lms($input);
    $id = synk_assessment_id_lms($input['assessment_id'] ?? null, true);
    $version = 1;
    if ($id) {
        $before = synk_assessment_detail_lms($lms, $context, $id, true);
        if (!in_array($before['status'], ['draft','published','closed'], true)) throw new DomainException('This assessment has been archived.', 409);
        if (synk_assessment_id_lms($input['version'] ?? null) !== $before['version']) throw new DomainException('This assessment changed in another tab. Reopen it before saving.', 409);
        if ($before['status'] !== 'draft') {
            synk_assessment_validate_published_lms($record);
            if (!synk_assessment_snapshots_ready_lms($lms)) throw new DomainException('Assessment version storage needs to be prepared before editing published work.', 503);
            // Assessment-row lock serializes this with student starts/saves and closing.
            // Preserve pre-upgrade attempts before replacing any question IDs or settings.
            synk_assessment_query_lms($lms, 'UPDATE tbl_lms_assessment_attempts SET assessment_snapshot_json=?
                WHERE assessment_id=? AND assessment_snapshot_json IS NULL', 'si', [synk_assessment_snapshot_json_lms($before),$id]);
        }
        $version = $before['version'] + 1;
    }
    $now = date('Y-m-d H:i:s');
    $params = [$record['grading_period'],$record['assessment_type'],$record['title'],$record['instructions'],$record['topic'],
        $record['total_points'],$record['opens_at'],$record['due_at'],$record['time_limit_minutes'],$version,$now];
    if ($id) {
        synk_assessment_query_lms($lms, 'UPDATE tbl_lms_assessments SET grading_period=?,assessment_type=?,title=?,instructions=?,topic=?,
            total_points=?,opens_at=?,due_at=?,time_limit_minutes=?,version=?,updated_at=? WHERE assessment_id=?', 'sssssdssiisi', array_merge($params,[$id]));
        synk_assessment_query_lms($lms, 'DELETE FROM tbl_lms_assessment_questions WHERE assessment_id=?', 'i', [$id]);
    } else {
        $scope = synk_assessment_scope_params_lms($context);
        synk_assessment_query_lms($lms, "INSERT INTO tbl_lms_assessments
            (grading_period,assessment_type,title,instructions,topic,total_points,opens_at,due_at,time_limit_minutes,version,updated_at,
             faculty_id,offering_id,ay_id,semester,created_by_user_id,created_at,status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft')", 'sssssdssiisiiiiis', array_merge($params,$scope,[$userId,$now]));
        $id = (int)$lms->insert_id;
    }
    foreach ($record['questions'] as $index=>$question) {
        synk_assessment_query_lms($lms, 'INSERT INTO tbl_lms_assessment_questions
            (assessment_id,question_type,prompt,points,options_json,answer_key_json,sort_order) VALUES (?,?,?,?,?,?,?)',
            'issdssi', [$id,$question['question_type'],$question['prompt'],$question['points'],json_encode($question['options'], JSON_UNESCAPED_UNICODE),
                $question['answer_key'] === null ? null : json_encode($question['answer_key'], JSON_UNESCAPED_UNICODE),$index+1]);
    }
    synk_assessment_log_lms($lms, $id, $userId, $version === 1 ? 'created' : 'updated',
        ['version'=>$version, 'grading_period'=>$record['grading_period'], 'question_count'=>count($record['questions']),
            'status'=>$before['status'] ?? 'draft', 'previous_version'=>$before['version'] ?? null]);
    return synk_assessment_detail_lms($lms, $context, $id);
}

function synk_assessment_duplicate_lms(mysqli $lms, array $context, array $input, int $userId): array
{
    $source = synk_assessment_detail_lms($lms, $context, synk_assessment_id_lms($input['assessment_id'] ?? null), true);
    if ($source['status'] === 'archived') throw new DomainException('This draft has been archived.', 409);
    if (synk_assessment_id_lms($input['version'] ?? null) !== $source['version']) throw new DomainException('This draft changed. Refresh the list before duplicating it.', 409);
    $title = 'Copy of ' . $source['title'];
    if (function_exists('mb_substr')) $title = mb_substr($title, 0, 200, 'UTF-8');
    else { preg_match('/^.{0,200}/us', $title, $match); $title = $match[0]; }
    $draft = $source;
    $draft['assessment_id'] = 0; $draft['version'] = 0;
    $draft['title'] = $title; $draft['grading_period'] = $input['grading_period'] ?? $source['grading_period'];
    $draft['opens_at'] = null; $draft['due_at'] = null;
    $saved = synk_assessment_save_lms($lms, $context, $draft, $userId);
    synk_assessment_log_lms($lms, $saved['assessment_id'], $userId, 'duplicated', ['source_assessment_id'=>$source['assessment_id']]);
    return $saved;
}

function synk_assessment_archive_lms(mysqli $lms, array $context, array $input, int $userId): void
{
    $record = synk_assessment_detail_lms($lms, $context, synk_assessment_id_lms($input['assessment_id'] ?? null), true);
    if ($record['status'] !== 'draft') throw new DomainException('Only a draft can be archived here.', 409);
    if (synk_assessment_id_lms($input['version'] ?? null) !== $record['version']) throw new DomainException('This draft changed. Refresh the list before archiving it.', 409);
    synk_assessment_query_lms($lms, "UPDATE tbl_lms_assessments SET status='archived',version=version+1,updated_at=? WHERE assessment_id=?",
        'si', [date('Y-m-d H:i:s'),$record['assessment_id']]);
    synk_assessment_log_lms($lms, $record['assessment_id'], $userId, 'archived', ['version'=>$record['version']+1]);
}
