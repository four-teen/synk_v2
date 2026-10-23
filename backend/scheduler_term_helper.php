<?php
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/schema_helper.php';

function synk_scheduler_term_query(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to load the academic term.');
    try {
        if ($types !== '') synk_bind_dynamic_params($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    } finally { $stmt->close(); }
}

function synk_scheduler_academic_years(mysqli $conn): array
{
    $rows = $conn->query('SELECT ay_id,ay,status FROM tbl_academic_years ORDER BY ay DESC,ay_id DESC')->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as &$row) $row['ay_id'] = (int)$row['ay_id'];
    return $rows;
}

function synk_scheduler_term_id($value, int $max = 4294967295): int
{
    if (!is_int($value) && !is_string($value)) throw new DomainException('Choose a valid academic year and semester.', 400);
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
    if ($id === false) throw new DomainException('Choose a valid academic year and semester.', 400);
    return $id;
}

function synk_scheduler_term_from_ids(mysqli $conn, $ayId, $semester): array
{
    $id = synk_scheduler_term_id($ayId); $sem = synk_scheduler_term_id($semester, 3);
    $rows = synk_scheduler_term_query($conn, 'SELECT ay_id,ay,status FROM tbl_academic_years WHERE ay_id=?', 'i', [$id]);
    if (!$rows) throw new DomainException('This academic year is unavailable. Choose another year.', 422);
    $current = synk_fetch_current_academic_term($conn); $year = $rows[0];
    $isCurrent = $id === (int)$current['ay_id'] && $sem === (int)$current['semester'];
    $comparison = strcmp((string)$year['ay'], (string)$current['ay_label']);
    $isFuture = !$isCurrent && $current['ay_id'] > 0 && ($comparison > 0 || ($comparison === 0 && $sem > (int)$current['semester']));
    // A working term is independent of the official term and legacy planning flags.
    // Account, college, record-term, conflict, and student checks still apply to writes.
    return ['ay_id'=>$id, 'semester'=>$sem, 'ay_label'=>(string)$year['ay'], 'semester_label'=>synk_semester_label($sem),
        'term_text'=>$year['ay'].' - '.synk_semester_label($sem), 'key'=>$id.':'.$sem, 'is_current'=>$isCurrent,
        'is_future'=>$isFuture, 'can_write'=>true, 'mode'=>$isCurrent ? 'Current term' : ($isFuture ? 'Advance planning' : 'Working term')];
}

function synk_scheduler_working_term(mysqli $conn): array
{
    if (isset($GLOBALS['synk_scheduler_page_term'])) return $GLOBALS['synk_scheduler_page_term'];
    $saved = $_SESSION['scheduler_working_term'] ?? null;
    if (is_array($saved) && ($saved['user_id'] ?? 0) === (int)($_SESSION['user_id'] ?? 0)) {
        try { return synk_scheduler_term_from_ids($conn, $saved['ay_id'] ?? null, $saved['semester'] ?? null); }
        catch (DomainException $error) { unset($_SESSION['scheduler_working_term']); }
    }
    $current = synk_fetch_current_academic_term($conn);
    if ($current['ay_id'] > 0 && $current['semester'] > 0) return synk_scheduler_term_from_ids($conn,$current['ay_id'],$current['semester']);
    return $current + ['key'=>'','is_current'=>false,'is_future'=>false,'can_write'=>false,'mode'=>'View only'];
}

function synk_scheduler_select_term(mysqli $conn, array $input): array
{
    if (($_SESSION['role'] ?? '') !== 'scheduler' || empty($_SESSION['user_id'])) throw new DomainException('Sign in as a Scheduler.', 403);
    if (isset($input['scheduler_term']) && (!is_string($input['scheduler_term']) || $input['scheduler_term'] !== 'current')) throw new DomainException('Choose a valid working-term setting.',400);
    if (($input['scheduler_term'] ?? '') === 'current') {
        unset($_SESSION['scheduler_working_term']);
        unset($GLOBALS['synk_scheduler_page_term']);
        return synk_scheduler_working_term($conn);
    }
    $term = synk_scheduler_term_from_ids($conn,$input['scheduler_ay_id'] ?? null,$input['scheduler_semester'] ?? null);
    $_SESSION['scheduler_working_term'] = ['user_id'=>(int)$_SESSION['user_id'],'ay_id'=>$term['ay_id'],'semester'=>$term['semester']];
    return $term;
}

function synk_scheduler_page_term(mysqli $conn): array
{
    if (isset($GLOBALS['synk_scheduler_page_term'])) return $GLOBALS['synk_scheduler_page_term'];
    $input = $_GET;
    if (isset($input['scheduler_ay_id']) || isset($input['scheduler_semester']) || isset($input['scheduler_term'])) {
        try { $term = synk_scheduler_select_term($conn,$input); }
        catch (DomainException $error) { http_response_code($error->getCode() ?: 422); exit(htmlspecialchars($error->getMessage(),ENT_QUOTES,'UTF-8')); }
    } else {
        $term = synk_scheduler_working_term($conn);
        // Direct print/report links carry their own term, independently of other tabs.
        if (isset($input['ay_id'],$input['semester'])) {
            try { $term = synk_scheduler_term_from_ids($conn,$input['ay_id'],$input['semester']); }
            catch (DomainException $error) { http_response_code(422); exit(htmlspecialchars($error->getMessage(),ENT_QUOTES,'UTF-8')); }
        }
    }
    return $GLOBALS['synk_scheduler_page_term'] = $term;
}

function synk_scheduler_request_term(mysqli $conn, array $input): array
{
    $bound = null;
    if (isset($input['scheduler_term_key'])) {
        if (!is_string($input['scheduler_term_key']) || !preg_match('/^([1-9][0-9]*):([1-3])$/D',$input['scheduler_term_key'],$parts)) {
            throw new DomainException('Reload the page to restore its academic term.',400);
        }
        $bound = synk_scheduler_term_from_ids($conn,$parts[1],$parts[2]);
    }
    $ayId = $input['ay_id'] ?? null;
    $sem = $input['semester_num'] ?? ($input['semester'] ?? null);
    if (is_string($sem)) $sem = ['1st'=>1,'2nd'=>2,'Midyear'=>3][$sem] ?? $sem;
    if ($ayId === null && isset($input['ay'])) {
        if (!is_string($input['ay'])) throw new DomainException('Choose a valid academic year.',400);
        $years = synk_scheduler_term_query($conn,'SELECT ay_id FROM tbl_academic_years WHERE ay=?','s',[$input['ay']]);
        if (!$years) throw new DomainException('Choose an academic year available in the system.',422);
        $ayId = $years[0]['ay_id'];
    }
    if (isset($input['semester_num'],$input['semester'])) {
        $uiSemester=is_string($input['semester']) ? (['1st'=>1,'2nd'=>2,'Midyear'=>3][$input['semester']] ?? $input['semester']) : $input['semester'];
        if (synk_scheduler_term_id($uiSemester,3)!==synk_scheduler_term_id($input['semester_num'],3)) throw new DomainException('The semester values do not match. Reload the page.',409);
    }
    if (isset($input['ay_id'],$input['ay'])) {
        if (!is_string($input['ay'])) throw new DomainException('Choose a valid academic year.',400);
        $yearCheck=synk_scheduler_term_query($conn,'SELECT ay FROM tbl_academic_years WHERE ay_id=?','i',[synk_scheduler_term_id($input['ay_id'])]);
        if (!$yearCheck || $yearCheck[0]['ay']!==$input['ay']) throw new DomainException('The academic year values do not match. Reload the page.',409);
    }
    $explicit = ($ayId !== null || $sem !== null) ? synk_scheduler_term_from_ids($conn,$ayId,$sem) : null;
    if ($bound && $explicit && $bound['key'] !== $explicit['key']) throw new DomainException('The selected term changed. Reload the page before continuing.',409);
    return $bound ?? $explicit ?? synk_scheduler_working_term($conn);
}

function synk_scheduler_require_writable_term(mysqli $conn, array $term, bool $lock = false): array
{
    if ($lock) {
        // Keep the official-term reference stable for undated legacy memberships.
        synk_scheduler_term_query($conn,'SELECT current_ay_id FROM tbl_academic_settings LIMIT 1 LOCK IN SHARE MODE');
    }
    $fresh = synk_scheduler_term_from_ids($conn,$term['ay_id'],$term['semester']);
    return $fresh;
}

function synk_scheduler_require_csrf(array $input): void
{
    if (!is_string($input['csrf_token'] ?? null) || empty($_SESSION['csrf_token']) || !hash_equals((string)$_SESSION['csrf_token'],$input['csrf_token'])) {
        throw new DomainException('Your session changed. Reload the page before saving.',403);
    }
}

// Undated memberships belong to the existing current list, never to a future planning list.
function synk_scheduler_legacy_term_flag(mysqli $conn): int
{
    return ($_SESSION['role'] ?? '') !== 'scheduler' || !empty(synk_scheduler_request_term($conn,$_POST)['is_current']) ? 1 : 0;
}
