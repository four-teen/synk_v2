<?php
require_once __DIR__ . '/faculty_term_designation_helper.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/scheduler_term_helper.php';
require_once __DIR__ . '/auth_useraccount.php';
require_once __DIR__ . '/scheduler_access_helper.php';
require_once __DIR__ . '/schema_helper.php';

function synk_scheduler_list_query(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    try {
        if ($types !== '') synk_bind_dynamic_params($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    } finally { $stmt->close(); }
}

function synk_scheduler_list_id($value): int
{
    if (!is_int($value) && !is_string($value)) throw new DomainException('Choose a valid list entry.', 400);
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
    if ($id === false) throw new DomainException('Choose a valid list entry.', 400);
    return $id;
}

function synk_scheduler_list_scope(mysqli $conn, int $collegeId): array
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if (!$userId || ($_SESSION['role'] ?? '') !== 'scheduler') throw new DomainException('Sign in as a Scheduler to manage these lists.', 401);
    $account = synk_find_useraccount_by_id($conn, $userId);
    if (!$account || $account['status'] !== 'active' || !synk_useraccount_has_active_role($conn, $account, 'scheduler')) {
        throw new DomainException('Your Scheduler access is no longer available.', 403);
    }
    $access = [];
    if (synk_scheduler_access_table_exists($conn)) {
        $access = synk_scheduler_list_query($conn, 'SELECT college_id,status FROM tbl_user_college_access WHERE user_id=?', 'i', [$userId]);
    }
    $allowed = [];
    if ($access) {
        foreach ($access as $row) if ($row['status'] === 'active') $allowed[] = (int)$row['college_id'];
    } else { $allowed[] = (int)$account['college_id']; }
    if (!in_array($collegeId, $allowed, true)) throw new DomainException('This college is outside your Scheduler access.', 403);
    $rows = synk_scheduler_list_query($conn, "SELECT c.college_id,c.college_name,c.campus_id FROM tbl_college c
        JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.college_id=? AND c.status='active' AND cp.status='active'", 'i', [$collegeId]);
    if (!$rows) throw new DomainException('This college is unavailable.', 403);
    $scope = $rows[0];
    $scope['college_id'] = (int)$scope['college_id']; $scope['campus_id'] = (int)$scope['campus_id'];
    return $scope;
}

function synk_scheduler_list_current_term(mysqli $conn): array
{
    $term = ($_SESSION['role'] ?? '') === 'scheduler' ? synk_scheduler_request_term($conn, $_POST) : synk_fetch_current_academic_term($conn);
    if ($term['ay_id'] <= 0 || !in_array($term['semester'], [1, 2, 3], true)) throw new DomainException('Set the current academic year and semester before copying lists.', 409);
    return $term;
}

function synk_scheduler_list_lock_actor(mysqli $conn): void
{
    $id = (int)$_SESSION['user_id'];
    synk_scheduler_list_query($conn, 'SELECT user_id FROM tbl_useraccount WHERE user_id=? FOR UPDATE', 'i', [$id]);
    if (synk_useraccount_role_table_exists($conn)) synk_scheduler_list_query($conn, 'SELECT user_id FROM tbl_useraccount_roles WHERE user_id=? FOR UPDATE', 'i', [$id]);
    if (synk_scheduler_access_table_exists($conn)) synk_scheduler_list_query($conn, 'SELECT user_id FROM tbl_user_college_access WHERE user_id=? FOR UPDATE', 'i', [$id]);
}

function synk_scheduler_list_lock_current_term(mysqli $conn): array
{
    if (($_SESSION['role'] ?? '') === 'scheduler') {
        return synk_scheduler_require_writable_term($conn, synk_scheduler_request_term($conn, $_POST), true);
    }
    $rows = synk_scheduler_list_query($conn, 'SELECT s.current_ay_id AS ay_id,s.current_semester AS semester,ay.ay AS ay_label
        FROM tbl_academic_settings s LEFT JOIN tbl_academic_years ay ON ay.ay_id=s.current_ay_id LIMIT 1 FOR UPDATE');
    $row = $rows[0] ?? [];
    $year = (int)($row['ay_id'] ?? 0); $semester = (int)($row['semester'] ?? 0);
    if (!$year || !in_array($semester, [1,2,3], true)) throw new DomainException('Set the current academic year and semester before updating lists.', 409);
    $label = synk_semester_label($semester);
    return ['ay_id'=>$year,'semester'=>$semester,'ay_label'=>(string)$row['ay_label'],'semester_label'=>$label,'term_text'=>$row['ay_label'].' - '.$label];
}

function synk_scheduler_list_source_terms(mysqli $conn, array $scope, string $kind, array $target): array
{
    $official = synk_fetch_current_academic_term($conn);
    $source = $kind === 'faculty' ? "SELECT DISTINCT COALESCE(ay_id,{$official['ay_id']}) AS ay_id,COALESCE(semester,{$official['semester']}) AS semester FROM tbl_college_faculty WHERE college_id=? AND status='active'"
        : 'SELECT DISTINCT ay_id,semester FROM tbl_room_college_access WHERE college_id=?';
    $rows = synk_scheduler_list_query($conn, "SELECT t.ay_id,t.semester,ay.ay AS ay_label FROM ({$source}) t
        JOIN tbl_academic_years ay ON ay.ay_id=t.ay_id WHERE t.semester BETWEEN 1 AND 3
        AND (ay.ay<? OR (ay.ay=? AND t.semester<?)) ORDER BY ay.ay DESC,t.semester DESC", 'issi',
        [$scope['college_id'], $target['ay_label'], $target['ay_label'], $target['semester']]);
    foreach ($rows as &$row) {
        $row['ay_id'] = (int)$row['ay_id']; $row['semester'] = (int)$row['semester'];
        $row['key'] = $row['ay_id'] . ':' . $row['semester'];
        $row['label'] = $row['ay_label'] . ' - ' . synk_semester_label($row['semester']);
    }
    return $rows;
}

function synk_scheduler_list_source(mysqli $conn, array $scope, string $kind, int $ayId, int $semester, array $target): array
{
    foreach (synk_scheduler_list_source_terms($conn, $scope, $kind, $target) as $term) {
        if ($term['ay_id'] === $ayId && $term['semester'] === $semester) return $term;
    }
    throw new DomainException('Choose a previous term with a list for this college.', 422);
}

function synk_scheduler_list_preview_rows(mysqli $conn, array $scope, string $kind, array $source, array $target): array
{
    $params = [$scope['college_id'], $target['ay_id'], $target['semester'], $scope['college_id'], $source['ay_id'], $source['semester']];
    if ($kind === 'faculty') {
        $sourceCurrent = synk_fetch_current_academic_term($conn);
        $sourceLegacy = $source['ay_id']===$sourceCurrent['ay_id'] && $source['semester']===$sourceCurrent['semester'] ? 1 : 0;
        $sql = "SELECT DISTINCT f.faculty_id AS id,CONCAT(f.last_name,', ',TRIM(CONCAT_WS(' ',f.first_name,NULLIF(f.middle_name,''),NULLIF(f.ext_name,'')))) AS label,
            COALESCE(NULLIF(d.designation_name,''),'No designation') AS detail,f.status AS master_status,
            EXISTS(SELECT 1 FROM tbl_college_faculty dst WHERE dst.faculty_id=f.faculty_id AND dst.college_id=?
                AND ((dst.ay_id=? AND dst.semester=?) OR (dst.ay_id IS NULL AND dst.semester IS NULL AND " . synk_scheduler_legacy_term_flag($conn) . "=1)) AND dst.status='active') AS already_listed
            FROM tbl_college_faculty src JOIN tbl_faculty f ON f.faculty_id=src.faculty_id
            " . synk_faculty_designation_join_sql($conn, (int)$source['ay_id'], (int)$source['semester']) . "
            WHERE src.college_id=? AND ((src.ay_id=? AND src.semester=?) OR (src.ay_id IS NULL AND src.semester IS NULL AND {$sourceLegacy}=1)) AND src.status='active' ORDER BY label,f.faculty_id";
    } else {
        $sql = "SELECT r.room_id AS id,CONCAT(r.room_code,' — ',r.room_name) AS label,
            CONCAT(REPLACE(r.room_type,'_',' / '),' · ',r.capacity,' seats') AS detail,r.status AS master_status,r.college_id AS owner_college_id,
            EXISTS(SELECT 1 FROM tbl_room_college_access dst WHERE dst.room_id=r.room_id AND dst.college_id=? AND dst.ay_id=? AND dst.semester=?) AS already_listed
            FROM tbl_room_college_access src JOIN tbl_rooms r ON r.room_id=src.room_id
            WHERE src.college_id=? AND src.ay_id=? AND src.semester=? ORDER BY r.room_code,r.room_id";
    }
    $rows = synk_scheduler_list_query($conn, $sql, 'iiiiii', $params);
    $preview = [];
    foreach ($rows as $row) {
        $reason = '';
        if ($kind === 'rooms' && (int)$row['owner_college_id'] !== $scope['college_id']) $reason = 'Owner college controls this room';
        elseif ($kind === 'faculty' ? strtolower(trim((string)$row['master_status'])) !== 'active'
            : !in_array(strtolower(trim((string)$row['master_status'])), ['', 'active'], true)) $reason = 'Record is unavailable';
        if ((int)$row['already_listed'] === 1) $reason = 'Already in current list';
        $preview[] = ['id' => (int)$row['id'], 'label' => $row['label'], 'detail' => $row['detail'],
            'eligible' => $reason === '', 'reason' => $reason];
    }
    return $preview;
}

function synk_scheduler_list_stage_preview(mysqli $conn, array $scope, string $kind, array $source, array $target): array
{
    $rows = synk_scheduler_list_preview_rows($conn, $scope, $kind, $source, $target);
    $eligibleIds = array_column(array_filter($rows, static function (array $row): bool { return $row['eligible']; }), 'id');
    $previews = $_SESSION['scheduler_list_copy_previews'] ?? [];
    foreach ($previews as $token => $pending) if ($pending['expires_at'] < time()) unset($previews[$token]);
    while (count($previews) >= 4) array_shift($previews);
    $token = bin2hex(random_bytes(24));
    $previews[$token] = ['user_id' => (int)$_SESSION['user_id'], 'college_id' => $scope['college_id'], 'kind' => $kind,
        'source' => $source, 'target' => $target, 'eligible_ids' => $eligibleIds, 'expires_at' => time() + 900];
    $_SESSION['scheduler_list_copy_previews'] = $previews;
    return ['rows' => $rows, 'preview_token' => $token, 'source' => $source, 'target' => $target];
}

function synk_scheduler_list_selected_ids(string $json): array
{
    $ids = json_decode($json, true);
    if (!is_array($ids) || !$ids || array_values($ids) !== $ids || count($ids) > 1000) throw new DomainException('Select at least one entry to copy.', 422);
    $result = [];
    foreach ($ids as $value) {
        $id = synk_scheduler_list_id($value);
        if (isset($result[$id])) throw new DomainException('Select each entry only once.', 400);
        $result[$id] = $id;
    }
    sort($result, SORT_NUMERIC);
    return array_values($result);
}

function synk_scheduler_list_copy(mysqli $conn, array $scope, string $kind, string $token, array $selected): array
{
    $pending = $_SESSION['scheduler_list_copy_previews'][$token] ?? null;
    if (!$pending || $pending['user_id'] !== (int)$_SESSION['user_id'] || $pending['college_id'] !== $scope['college_id']
        || $pending['kind'] !== $kind || $pending['expires_at'] < time()) throw new DomainException('This preview expired. Open a new preview before copying.', 409);
    foreach ($selected as $id) if (!in_array($id, $pending['eligible_ids'], true)) throw new DomainException('Choose only available entries from this preview.', 403);
    $conn->begin_transaction();
    try {
        synk_scheduler_list_lock_actor($conn);
        $scope = synk_scheduler_list_scope($conn, $scope['college_id']);
        $target = synk_scheduler_list_lock_current_term($conn);
        if ($target['ay_id'] !== $pending['target']['ay_id'] || $target['semester'] !== $pending['target']['semester']) {
            throw new DomainException('The current academic term changed. Open a new preview.', 409);
        }
        $source = synk_scheduler_list_source($conn, $scope, $kind, $pending['source']['ay_id'], $pending['source']['semester'], $target);
        $placeholders = implode(',', array_fill(0, count($selected), '?'));
        $table = $kind === 'faculty' ? 'tbl_faculty' : 'tbl_rooms';
        $idColumn = $kind === 'faculty' ? 'faculty_id' : 'room_id';
        $masters = array_column(synk_scheduler_list_query($conn, "SELECT {$idColumn},status,college_id FROM {$table}
            WHERE {$idColumn} IN ({$placeholders}) ORDER BY {$idColumn} FOR UPDATE", str_repeat('i', count($selected)), $selected), null, $idColumn);
        $listTable = $kind === 'faculty' ? 'tbl_college_faculty' : 'tbl_room_college_access';
        $sourceStatus = $kind === 'faculty' ? " AND status='active'" : '';
        $officialSource = synk_fetch_current_academic_term($conn);
        $sourceLegacy = $kind==='faculty' && $source['ay_id']===$officialSource['ay_id'] && $source['semester']===$officialSource['semester'] ? 1 : 0;
        $sourcePeriod = $kind==='faculty' ? "((ay_id=? AND semester=?) OR (ay_id IS NULL AND semester IS NULL AND {$sourceLegacy}=1))" : '(ay_id=? AND semester=?)';
        $membership = synk_scheduler_list_query($conn, "SELECT {$idColumn} FROM {$listTable} WHERE college_id=? AND {$sourcePeriod}
            {$sourceStatus} AND {$idColumn} IN ({$placeholders}) ORDER BY {$idColumn} FOR UPDATE", 'iii'.str_repeat('i',count($selected)),
            array_merge([$scope['college_id'],$source['ay_id'],$source['semester']],$selected));
        $sourceIds = array_map('intval',array_column($membership,$idColumn));
        $targetPeriod = $kind === 'faculty' ? '((ay_id=? AND semester=?) OR (ay_id IS NULL AND semester IS NULL AND '.synk_scheduler_legacy_term_flag($conn).'=1))' : '(ay_id=? AND semester=?)';
        $existing = synk_scheduler_list_query($conn, "SELECT {$idColumn} FROM {$listTable} WHERE college_id=? AND {$targetPeriod}
            {$sourceStatus} AND {$idColumn} IN ({$placeholders}) ORDER BY {$idColumn} FOR UPDATE", 'iii'.str_repeat('i',count($selected)),
            array_merge([$scope['college_id'],$target['ay_id'],$target['semester']],$selected));
        $existingIds = array_map('intval',array_column($existing,$idColumn));
        $copied = 0; $skipped = 0;
        foreach ($selected as $id) {
            if (!isset($masters[$id]) || !in_array($id,$sourceIds,true)) {
                throw new DomainException('The source list changed. Review a new preview before copying.', 409);
            }
            if (in_array($id,$existingIds,true)) { $skipped++; continue; }
            $status = strtolower(trim((string)$masters[$id]['status']));
            if (($kind==='faculty' && $status!=='active') || ($kind==='rooms' && (!in_array($status,['','active'],true) || (int)$masters[$id]['college_id']!==$scope['college_id']))) {
                throw new DomainException('A selected record is no longer available. Review a new preview.',409);
            }
            $sql = $kind === 'faculty' ? "INSERT INTO tbl_college_faculty (faculty_id,college_id,ay_id,semester,status)
                VALUES (?,?,?,?,'active') ON DUPLICATE KEY UPDATE status='active'"
                : "INSERT INTO tbl_room_college_access (room_id,college_id,ay_id,semester,access_type)
                VALUES (?,?,?,?,'owner') ON DUPLICATE KEY UPDATE access_id=access_id";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param('iiii', $id, $scope['college_id'], $target['ay_id'], $target['semester']);
            $stmt->execute();
            if ($stmt->affected_rows > 0) $copied++; else $skipped++;
            $stmt->close();
            if ($kind === 'faculty') synk_faculty_term_designation_copy($conn, $id, $source, $target, (int)$_SESSION['user_id']);
        }
        $conn->commit();
        unset($_SESSION['scheduler_list_copy_previews'][$token]);
        return ['copied' => $copied, 'skipped' => $skipped, 'target' => $target];
    } catch (Throwable $exception) { $conn->rollback(); throw $exception; }
}

function synk_scheduler_list_remove_faculty(mysqli $conn, int $collegeId, int $assignmentId, array $expected): void
{
    $conn->begin_transaction();
    try {
        synk_scheduler_list_lock_actor($conn);
        synk_scheduler_list_scope($conn, $collegeId);
        $term = synk_scheduler_list_lock_current_term($conn);
        if ($term['ay_id'] !== $expected['ay_id'] || $term['semester'] !== $expected['semester']) throw new DomainException('The current term changed. Refresh the list before removing an entry.', 409);
        $rows = synk_scheduler_list_query($conn, 'SELECT college_faculty_id FROM tbl_college_faculty WHERE college_faculty_id=? AND college_id=?
            AND ((ay_id=? AND semester=?) OR (ay_id IS NULL AND semester IS NULL AND '.synk_scheduler_legacy_term_flag($conn).'=1)) FOR UPDATE', 'iiii', [$assignmentId, $collegeId, $term['ay_id'], $term['semester']]);
        if (!$rows) throw new DomainException('This assignment is no longer in the current list.', 404);
        synk_scheduler_list_query($conn, 'DELETE FROM tbl_college_faculty WHERE college_faculty_id=? AND college_id=?', 'ii', [$assignmentId, $collegeId]);
        $conn->commit();
    } catch (Throwable $exception) { $conn->rollback(); throw $exception; }
}
