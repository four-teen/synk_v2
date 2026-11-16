<?php
require_once __DIR__.'/evaluation_access_helper.php';

function synk_student_evaluation_reset_actor(mysqli $conn, bool $lock = false): array
{
    if (($_SESSION['role'] ?? '') !== 'admin' || empty($_SESSION['user_id'])) throw new DomainException('Administrator access is required.', 403);
    $id = (int)$_SESSION['user_id'];
    if ($lock) {
        synk_evaluation_query($conn, 'SELECT user_id FROM tbl_useraccount WHERE user_id=? FOR UPDATE', 'i', [$id]);
        synk_evaluation_query($conn, 'SELECT user_id FROM tbl_useraccount_roles WHERE user_id=? FOR UPDATE', 'i', [$id]);
    }
    $actor = synk_find_useraccount_by_id($conn, $id);
    if (!$actor || ($actor['status'] ?? '') !== 'active' || !synk_useraccount_has_active_role($conn, $actor, 'admin')) throw new DomainException('Your Administrator access is no longer active.', 403);
    return $actor;
}

function synk_student_evaluation_reset_id($value): int
{
    if ((!is_int($value) && !is_string($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) || (float)$value > 2147483647) throw new DomainException('Select a valid campus, college, academic year and semester.', 422);
    return (int)$value;
}

// Readiness checks never create or alter storage during page views or deletion.
function synk_student_evaluation_reset_ready(mysqli $conn): bool
{
    $tables = ['tbl_student_faculty_evaluations','tbl_student_faculty_evaluation_answers','tbl_college_evaluation_settings','tbl_student_evaluation_reset_history'];
    if (synk_evaluation_table_exists($conn, 'tbl_student_faculty_evaluation_subject_scope')) $tables[] = 'tbl_student_faculty_evaluation_subject_scope';
    $rows = synk_evaluation_query($conn, 'SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.implode(',',array_fill(0,count($tables),'?')).')', str_repeat('s',count($tables)), $tables);
    return count($rows) === count($tables) && !array_filter($rows, static function(array $row): bool { return strcasecmp((string)$row['ENGINE'], 'InnoDB') !== 0; }) && synk_evaluation_has_ownership($conn);
}

function synk_student_evaluation_reset_scope(mysqli $conn, array $input, bool $lock = false): array
{
    $ids = [];
    foreach (['campus_id','college_id','ay_id','semester'] as $key) $ids[$key] = synk_student_evaluation_reset_id($input[$key] ?? null);
    if (!in_array($ids['semester'], [1,2,3], true)) throw new DomainException('Select a valid semester.', 422);
    $rows = synk_evaluation_query($conn, 'SELECT c.college_id,c.campus_id,c.college_code,c.college_name,cp.campus_name FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.college_id=? AND cp.campus_id=?'.($lock ? ' LOCK IN SHARE MODE' : ''), 'ii', [$ids['college_id'],$ids['campus_id']]);
    $years = synk_evaluation_query($conn, 'SELECT ay FROM tbl_academic_years WHERE ay_id=?'.($lock ? ' LOCK IN SHARE MODE' : ''), 'i', [$ids['ay_id']]);
    if (!$rows || !$years) throw new DomainException('The selected college does not belong to this campus, or the academic year is unavailable.', 422);
    return $ids + array_intersect_key($rows[0], array_flip(['college_code','college_name','campus_name'])) + ['term_text'=>$years[0]['ay'].' / '.synk_semester_label($ids['semester'])];
}

function synk_student_evaluation_reset_plan(mysqli $conn, array $input, bool $lock = false): array
{
    if (!synk_student_evaluation_reset_ready($conn)) throw new DomainException('Student evaluation reset storage is not ready. Ask your administrator to run the reset preparation.', 503);
    // Student writers lock the current term, college and collection setting too.
    $current = synk_evaluation_query($conn, 'SELECT current_ay_id AS ay_id,current_semester AS semester FROM tbl_academic_settings LIMIT 1'.($lock ? ' LOCK IN SHARE MODE' : ''))[0] ?? [];
    $scope = synk_student_evaluation_reset_scope($conn, $input, $lock);
    $params = [$scope['college_id'],$scope['ay_id'],$scope['semester']];
    $settings = synk_evaluation_query($conn, 'SELECT student_open,faculty_released,version FROM tbl_college_evaluation_settings WHERE college_id=? AND ay_id=? AND semester=?'.($lock ? ' FOR UPDATE' : ''), 'iii', $params)[0] ?? ['student_open'=>0,'faculty_released'=>0,'version'=>0];
    $blocked = synk_evaluation_is_current($scope, $current) && !empty($settings['student_open']);
    // Only saved, explicit college ownership is eligible. Never guess from a
    // student's current profile or an ambiguous legacy enrollment.
    $headers = synk_evaluation_query($conn, 'SELECT * FROM tbl_student_faculty_evaluations WHERE college_id=? AND ay_id=? AND semester=? ORDER BY evaluation_id'.($lock ? ' FOR UPDATE' : ''), 'iii', $params);
    $counts = ['students'=>0,'submitted'=>0,'drafts'=>0,'records'=>count($headers),'answers'=>0,'snapshots'=>0];
    $students = []; $ids = []; $digest = hash_init('sha256');
    hash_update($digest, json_encode([$scope,$settings,$current], JSON_UNESCAPED_UNICODE));
    foreach ($headers as $header) {
        $ids[] = (int)$header['evaluation_id'];
        if ($header['submission_status'] === 'submitted') { $counts['submitted']++; $students[(int)$header['student_id']] = true; }
        else $counts['drafts']++;
        hash_update($digest, json_encode($header, JSON_UNESCAPED_UNICODE));
    }
    unset($headers);
    $counts['students'] = count($students);
    $hasSnapshots = synk_evaluation_table_exists($conn, 'tbl_student_faculty_evaluation_subject_scope');
    foreach (array_chunk($ids, 500) as $chunk) {
        $slots = implode(',', array_fill(0, count($chunk), '?')); $types = str_repeat('i', count($chunk));
        $answers = synk_evaluation_query($conn, 'SELECT * FROM tbl_student_faculty_evaluation_answers WHERE evaluation_id IN ('.$slots.') ORDER BY answer_id'.($lock ? ' FOR UPDATE' : ''), $types, $chunk);
        $counts['answers'] += count($answers);
        hash_update($digest, json_encode($answers, JSON_UNESCAPED_UNICODE));
        if ($hasSnapshots) {
            $snapshots = synk_evaluation_query($conn, 'SELECT * FROM tbl_student_faculty_evaluation_subject_scope WHERE evaluation_id IN ('.$slots.') ORDER BY evaluation_id'.($lock ? ' FOR UPDATE' : ''), $types, $chunk);
            $counts['snapshots'] += count($snapshots);
            hash_update($digest, json_encode($snapshots, JSON_UNESCAPED_UNICODE));
        }
    }
    $unassigned = synk_evaluation_query($conn, 'SELECT COUNT(*) AS total FROM tbl_student_faculty_evaluations WHERE college_id=0 AND ay_id=? AND semester=?', 'ii', [$scope['ay_id'],$scope['semester']]);
    return ['scope'=>$scope,'counts'=>$counts,'ids'=>$ids,'fingerprint'=>hash_final($digest),'blocked'=>$blocked,'unassigned'=>(int)$unassigned[0]['total'],'has_snapshots'=>$hasSnapshots];
}

function synk_student_evaluation_reset_preview(mysqli $conn, array $input): array
{
    $actor = synk_student_evaluation_reset_actor($conn);
    $plan = synk_student_evaluation_reset_plan($conn, $input);
    $pending = (array)($_SESSION['student_evaluation_reset_previews'] ?? []);
    foreach ($pending as $key=>$item) if (($item['expires_at'] ?? 0) < time()) unset($pending[$key]);
    while (count($pending) >= 5) array_shift($pending);
    $token = null;
    if ($plan['counts']['records'] && !$plan['blocked']) {
        $token = bin2hex(random_bytes(32));
        $pending[$token] = ['user_id'=>(int)$actor['user_id'],'session_id'=>session_id(),'scope'=>$plan['scope'],'fingerprint'=>$plan['fingerprint'],'expires_at'=>time()+900];
    }
    $_SESSION['student_evaluation_reset_previews'] = $pending;
    return ['scope'=>$plan['scope'],'counts'=>$plan['counts'],'blocked'=>$plan['blocked'],'unassigned'=>$plan['unassigned'],'preview_token'=>$token];
}

function synk_student_evaluation_reset_affected(mysqli $conn, string $sql, string $types, array $params): int
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare the evaluation deletion.');
    try {
        if (!synk_bind_dynamic_params($stmt, $types, $params) || !$stmt->execute()) throw new RuntimeException('Unable to delete the evaluation rows.');
        // Read before closing the statement: PHP 7.1 resets connection-level
        // affected_rows on close, so it cannot verify the deletion count later.
        return $stmt->affected_rows;
    } finally { $stmt->close(); }
}

function synk_student_evaluation_reset_confirm(mysqli $conn, array $input): array
{
    synk_student_evaluation_reset_actor($conn);
    if (!is_string($input['csrf_token'] ?? null) || empty($_SESSION['student_evaluation_reset_csrf']) || !hash_equals($_SESSION['student_evaluation_reset_csrf'], $input['csrf_token'])) throw new DomainException('Reload the page before deleting evaluations.', 403);
    $token = $input['preview_token'] ?? null;
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) throw new DomainException('Filter and review the evaluation counts first.', 422);
    $pending = $_SESSION['student_evaluation_reset_previews'][$token] ?? null;
    if (!is_array($pending) || ($pending['expires_at'] ?? 0) < time() || $pending['user_id'] !== (int)$_SESSION['user_id'] || $pending['session_id'] !== session_id()) throw new DomainException('This review expired or was already used. Filter again before deleting.', 409);
    foreach (['campus_id','college_id','ay_id','semester'] as $key) if (synk_student_evaluation_reset_id($input[$key] ?? null) !== $pending['scope'][$key]) throw new DomainException('The selected scope changed. Filter and review again.', 409);
    if (($input['confirm_delete'] ?? null) !== '1' || ($input['confirmation'] ?? null) !== 'DELETE STUDENT EVALUATIONS') throw new DomainException('Confirm the deletion and type DELETE STUDENT EVALUATIONS exactly.', 422);
    if (!$conn->begin_transaction()) throw new RuntimeException('Unable to begin the student evaluation reset.');
    try {
        $actor = synk_student_evaluation_reset_actor($conn, true);
        $plan = synk_student_evaluation_reset_plan($conn, $pending['scope'], true);
        if ($plan['blocked']) throw new DomainException('Close student evaluation collection for this college before deleting.', 409);
        if (!$plan['ids'] || !hash_equals($pending['fingerprint'], $plan['fingerprint'])) throw new DomainException('The evaluations or collection settings changed after review. Nothing was deleted. Filter again.', 409);
        $scope = $plan['scope']; $counts = $plan['counts'];
        // Store only operation metadata, never a copy of deleted ratings/comments.
        synk_evaluation_query($conn, 'INSERT INTO tbl_student_evaluation_reset_history (operation_key,campus_id,college_id,ay_id,semester,student_count,submitted_count,draft_count,answer_count,snapshot_count,actor_user_id,actor_name,reset_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())',
            'siiiiiiiiiis', [$token,$scope['campus_id'],$scope['college_id'],$scope['ay_id'],$scope['semester'],$counts['students'],$counts['submitted'],$counts['drafts'],$counts['answers'],$counts['snapshots'],(int)$actor['user_id'],(string)$actor['username']]);
        $removed = ['records'=>0,'answers'=>0,'snapshots'=>0];
        foreach (array_chunk($plan['ids'], 500) as $chunk) {
            $slots = implode(',', array_fill(0, count($chunk), '?')); $types = str_repeat('i', count($chunk));
            $removed['answers'] += synk_student_evaluation_reset_affected($conn, 'DELETE FROM tbl_student_faculty_evaluation_answers WHERE evaluation_id IN ('.$slots.')', $types, $chunk);
            if ($plan['has_snapshots']) {
                $removed['snapshots'] += synk_student_evaluation_reset_affected($conn, 'DELETE FROM tbl_student_faculty_evaluation_subject_scope WHERE evaluation_id IN ('.$slots.')', $types, $chunk);
            }
            $removed['records'] += synk_student_evaluation_reset_affected($conn, 'DELETE FROM tbl_student_faculty_evaluations WHERE college_id=? AND ay_id=? AND semester=? AND evaluation_id IN ('.$slots.')', 'iii'.$types, array_merge([$scope['college_id'],$scope['ay_id'],$scope['semester']], $chunk));
        }
        foreach ($removed as $key=>$value) if ($value !== $counts[$key]) throw new RuntimeException('The evaluation rows could not be deleted together.');
        if (!$conn->commit()) throw new RuntimeException('The student evaluation reset could not be committed.');
        unset($_SESSION['student_evaluation_reset_previews'][$token]);
        return ['scope'=>$scope,'counts'=>$counts];
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}
