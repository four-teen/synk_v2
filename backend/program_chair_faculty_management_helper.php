<?php
require_once __DIR__ . '/program_chair_faculty_helper.php';
require_once __DIR__ . '/faculty_term_designation_helper.php';
require_once __DIR__ . '/scheduler_term_list_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/program_chair_faculty_account_helper.php';

function synk_chair_faculty_designation_style(int $id, string $name): string
{
    if (trim($name) === '') return '';
    $seed = $id > 0 ? $id : (int)sprintf('%u', crc32(strtolower(trim($name))));
    $hue = (($seed * 47) + 17) % 360;
    return "background-color:hsla({$hue}, 78%, 91%, 0.95);color:hsla({$hue}, 72%, 32%, 1);border-color:hsla({$hue}, 68%, 78%, 1);";
}

function synk_chair_faculty_management_list(mysqli $conn, array $scope, array $term, array $input): array
{
    $page = max(1, (int)($input['page'] ?? 1));
    $size = max(10, min(50, (int)($input['page_size'] ?? 20)));
    $offset = ($page - 1) * $size;
    $join = synk_faculty_designation_join_sql($conn, $term['ay_id'], $term['semester']);
    $base = "FROM tbl_college_faculty cf INNER JOIN tbl_faculty f ON f.faculty_id=cf.faculty_id {$join}
        LEFT JOIN tbl_useraccount_faculty_links account_link ON account_link.faculty_id=f.faculty_id
        LEFT JOIN tbl_useraccount login_account ON login_account.user_id=account_link.user_id";
    $where = "WHERE cf.college_id=? AND LOWER(TRIM(cf.status))='active'
        AND ((cf.ay_id=? AND cf.semester=?) OR (cf.ay_id IS NULL AND cf.semester IS NULL))";
    $params = [$scope['college_id'], $term['ay_id'], $term['semester']]; $types = 'iii';
    $search = trim((string)($input['search'] ?? ''));
    if ($search !== '') {
        $where .= " AND (CONCAT_WS(' ',f.last_name,f.first_name,f.middle_name,f.ext_name) LIKE ?
            OR COALESCE(d.designation_name,'') LIKE ? OR f.employment_classification LIKE ?
            OR REPLACE(f.employment_classification,'_',' ') LIKE ? OR login_account.email LIKE ?)";
        $types .= 'sssss'; $params = array_merge($params, array_fill(0, 5, '%' . $search . '%'));
    }
    $total = (int)synk_chair_faculty_query($conn, "SELECT COUNT(*) AS total {$base} {$where}", $types, $params)[0]['total'];
    // Same read-only assigned-class count and live-offering joins used by Scheduler.
    $liveJoins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $rows = synk_chair_faculty_query($conn, "SELECT cf.college_faculty_id,f.faculty_id,f.first_name,f.middle_name,f.last_name,f.ext_name,
        f.college_id,f.campus_id,f.status AS faculty_status,COALESCE(login_account.user_id,0) AS login_user_id,
        COALESCE(login_account.email,'') AS faculty_email,COALESCE(login_account.role,'') AS login_primary_role,
        COALESCE(login_account.college_id,0) AS login_college_id,COALESCE(login_account.status,'') AS login_status,
        COALESCE(d.designation_id,0) AS designation_id,COALESCE(d.designation_name,'') AS designation_name,
        COALESCE(f.employment_classification,'') AS employment_classification,LOWER(TRIM(cf.status)) AS status,
        COALESCE(term_assignments.assigned_count,0) AS assigned_count {$base}
        LEFT JOIN (SELECT fw.faculty_id,COUNT(DISTINCT fw.schedule_id) AS assigned_count
            FROM tbl_faculty_workload_sched fw INNER JOIN tbl_class_schedule cs ON cs.schedule_id=fw.schedule_id
            INNER JOIN tbl_prospectus_offering o ON o.offering_id=cs.offering_id {$liveJoins}
            INNER JOIN tbl_program p ON p.program_id=o.program_id
            WHERE fw.ay_id=? AND fw.semester=? AND o.ay_id=? AND o.semester=? AND p.college_id=?
            GROUP BY fw.faculty_id) term_assignments ON term_assignments.faculty_id=f.faculty_id
        {$where} ORDER BY f.last_name,f.first_name,f.faculty_id,cf.college_faculty_id LIMIT ? OFFSET ?",
        'iiiii' . $types . 'ii', array_merge([$term['ay_id'], $term['semester'], $term['ay_id'], $term['semester'], $scope['college_id']], $params, [$size, $offset]));
    $data = [];
    foreach (synk_chair_account_enrich_rows($conn,$rows,$scope) as $row) {
        $data[] = ['college_faculty_id'=>(int)$row['college_faculty_id'], 'faculty_id'=>(int)$row['faculty_id'],
            'full_name'=>synk_chair_faculty_name($row), 'designation_id'=>(int)$row['designation_id'],
            'designation_name'=>$row['designation_name'], 'designation_style'=>synk_chair_faculty_designation_style((int)$row['designation_id'], $row['designation_name']),
            'employment_classification'=>$row['employment_classification'],
            'employment_classification_label'=>synk_chair_faculty_classifications()[$row['employment_classification']] ?? '',
            'status'=>$row['status'], 'assigned_count'=>(int)$row['assigned_count'],
            'faculty_email'=>$row['faculty_email'],'login_status'=>$row['login_status'],'login_roles'=>$row['login_roles'],
            'login_can_manage'=>$row['login_can_manage'],'login_reason'=>$row['login_reason']];
    }
    return ['status'=>'success', 'data'=>$data, 'term'=>$term,
        'pagination'=>['page'=>$page, 'page_size'=>$size, 'total'=>$total, 'has_more'=>$offset + count($data) < $total]];
}

function synk_chair_faculty_lock_write(mysqli $conn, array $input): array
{
    synk_chair_faculty_query($conn, 'SELECT user_id FROM tbl_useraccount WHERE user_id=? FOR UPDATE', 'i', [(int)($_SESSION['user_id'] ?? 0)]);
    if (synk_useraccount_role_table_exists($conn)) synk_chair_faculty_query($conn, 'SELECT user_id FROM tbl_useraccount_roles WHERE user_id=? FOR UPDATE', 'i', [(int)$_SESSION['user_id']]);
    $scope = synk_chair_faculty_require_scope($conn, $input);
    $college = synk_chair_faculty_query($conn,"SELECT c.college_id FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id
        WHERE c.college_id=? AND c.status='active' AND cp.status='active' FOR UPDATE",'i',[$scope['college_id']]);
    if (!$college) throw new DomainException('Your assigned college is no longer active.',403);
    $current = synk_scheduler_list_lock_current_term($conn);
    $expected = synk_dean_term_from_ids($conn, $input['ay_id'] ?? null, $input['semester'] ?? null);
    if ($current['ay_id'] !== $expected['ay_id'] || $current['semester'] !== $expected['semester']) {
        throw new DomainException('The current academic term changed. Switch to the current term and refresh the faculty list.', 409);
    }
    return ['scope'=>$scope, 'term'=>$current];
}

function synk_chair_faculty_lock_assignment(mysqli $conn, array $scope, array $term, int $facultyId): void
{
    synk_chair_faculty_query($conn, 'SELECT faculty_id FROM tbl_faculty WHERE faculty_id=? FOR UPDATE', 'i', [$facultyId]);
    $rows = synk_chair_faculty_query($conn, "SELECT college_faculty_id FROM tbl_college_faculty WHERE faculty_id=? AND college_id=?
        AND LOWER(TRIM(status))='active' AND ((ay_id=? AND semester=?) OR (ay_id IS NULL AND semester IS NULL)) FOR UPDATE",
        'iiii', [$facultyId, $scope['college_id'], $term['ay_id'], $term['semester']]);
    if (!$rows) throw new DomainException('This faculty member is not in the current college list.', 403);
}

function synk_chair_faculty_update(mysqli $conn, array $input, string $kind): array
{
    $facultyId = synk_scheduler_list_id($input['faculty_id'] ?? null);
    $conn->begin_transaction();
    try {
        $context = synk_chair_faculty_lock_write($conn, $input);
        $term = $context['term'];
        synk_chair_faculty_lock_assignment($conn, $context['scope'], $term, $facultyId);
        if ($kind === 'designation') {
            $raw = $input['designation_id'] ?? '';
            if (!is_string($raw) || !preg_match('/^(?:0|[1-9][0-9]{0,8})$/D', $raw)) throw new DomainException('Choose an active designation or No designation.', 422);
            $id = (int)$raw;
            $designation = ['designation_id'=>null,'designation_name'=>'','designation_units'=>0];
            if ($id > 0) {
                $rows = synk_chair_faculty_query($conn, "SELECT designation_id,designation_name,designation_units FROM tbl_designation WHERE designation_id=? AND status='active' FOR UPDATE", 'i', [$id]);
                if (!$rows) throw new DomainException('Choose an active designation or No designation.', 422);
                $designation = $rows[0];
            }
            synk_faculty_term_designation_record($conn, $facultyId, $term['ay_id'], $term['semester'], $designation, (int)$_SESSION['user_id']);
            $result = ['designation_id'=>$id,'designation_name'=>$designation['designation_name'],
                'designation_style'=>synk_chair_faculty_designation_style($id, $designation['designation_name'])];
        } else {
            $classification = $input['employment_classification'] ?? '';
            if (!is_string($classification) || !isset(synk_chair_faculty_classifications()[$classification])) throw new DomainException('Choose a valid employment classification.', 422);
            synk_chair_faculty_query($conn, 'UPDATE tbl_faculty SET employment_classification=? WHERE faculty_id=?', 'si', [$classification, $facultyId]);
            $result = ['employment_classification'=>$classification,'employment_classification_label'=>synk_chair_faculty_classifications()[$classification]];
        }
        $conn->commit();
        return ['status'=>'updated','term'=>$term] + $result;
    } catch (Throwable $exception) { $conn->rollback(); throw $exception; }
}

function synk_chair_faculty_remove(mysqli $conn, array $input): array
{
    $id = synk_scheduler_list_id($input['college_faculty_id'] ?? null);
    $conn->begin_transaction();
    try {
        $context = synk_chair_faculty_lock_write($conn, $input);
        $scope = $context['scope']; $term = $context['term'];
        $rows = synk_chair_faculty_query($conn, 'SELECT college_id,ay_id,semester FROM tbl_college_faculty WHERE college_faculty_id=? FOR UPDATE', 'i', [$id]);
        if (!$rows) throw new DomainException('This assignment is no longer in the list.', 404);
        if ((int)$rows[0]['college_id'] !== $scope['college_id']) throw new DomainException('This faculty assignment is outside your college.', 403);
        if (!(($rows[0]['ay_id'] === null && $rows[0]['semester'] === null)
            || ((int)$rows[0]['ay_id'] === $term['ay_id'] && (int)$rows[0]['semester'] === $term['semester']))) {
            throw new DomainException('This assignment is not in the current term. Refresh the list.', 409);
        }
        // Remove only the college-list membership; faculty and prepared class assignments remain.
        synk_chair_faculty_query($conn, 'DELETE FROM tbl_college_faculty WHERE college_faculty_id=? AND college_id=?', 'ii', [$id,$scope['college_id']]);
        $conn->commit();
        return ['status'=>'removed'];
    } catch (Throwable $exception) { $conn->rollback(); throw $exception; }
}

function synk_chair_faculty_stage_copy(mysqli $conn, array $scope, array $source, array $target): array
{
    $rows = synk_scheduler_list_preview_rows($conn, $scope, 'faculty', $source, $target);
    $previews = $_SESSION['program_chair_list_copy_previews'] ?? [];
    foreach ($previews as $key=>$pending) if ($pending['expires_at'] < time()) unset($previews[$key]);
    while (count($previews) >= 4) array_shift($previews);
    $token = bin2hex(random_bytes(24));
    $previews[$token] = ['user_id'=>(int)$_SESSION['user_id'], 'college_id'=>$scope['college_id'], 'source'=>$source, 'target'=>$target,
        'eligible_ids'=>array_column(array_filter($rows, static function (array $row): bool { return $row['eligible']; }), 'id'), 'expires_at'=>time()+900];
    $_SESSION['program_chair_list_copy_previews'] = $previews;
    return ['rows'=>$rows,'preview_token'=>$token,'source'=>$source,'target'=>$target];
}

function synk_chair_faculty_copy(mysqli $conn, array $scope, array $input): array
{
    $token = $input['preview_token'] ?? '';
    $pending = $_SESSION['program_chair_list_copy_previews'][$token] ?? null;
    if (!$pending || $pending['user_id'] !== (int)$_SESSION['user_id'] || $pending['college_id'] !== $scope['college_id'] || $pending['expires_at'] < time()) {
        throw new DomainException('This preview expired. Open a new preview before copying.', 409);
    }
    $selected = synk_scheduler_list_selected_ids($input['selected_ids'] ?? '');
    foreach ($selected as $id) if (!in_array($id, $pending['eligible_ids'], true)) throw new DomainException('Choose only available faculty from this preview.', 403);
    $conn->begin_transaction();
    try {
        $context = synk_chair_faculty_lock_write($conn, $input);
        $scope = $context['scope']; $target = $context['term'];
        if ($target['ay_id'] !== $pending['target']['ay_id'] || $target['semester'] !== $pending['target']['semester']) throw new DomainException('The current academic term changed. Open a new preview.', 409);
        $source = synk_scheduler_list_source($conn, $scope, 'faculty', $pending['source']['ay_id'], $pending['source']['semester'], $target);
        $marks = implode(',',array_fill(0,count($selected),'?'));
        $masters = array_column(synk_chair_faculty_query($conn, "SELECT faculty_id,status FROM tbl_faculty WHERE faculty_id IN ({$marks}) ORDER BY faculty_id FOR UPDATE", str_repeat('i',count($selected)),$selected),null,'faculty_id');
        $sourceRows = synk_chair_faculty_query($conn, "SELECT faculty_id FROM tbl_college_faculty WHERE college_id=? AND ay_id=? AND semester=? AND status='active' AND faculty_id IN ({$marks}) ORDER BY faculty_id FOR UPDATE",
            'iii'.str_repeat('i',count($selected)),array_merge([$scope['college_id'],$source['ay_id'],$source['semester']],$selected));
        $sourceIds = array_map('intval',array_column($sourceRows,'faculty_id'));
        $copied = 0; $skipped = 0;
        foreach ($selected as $id) {
            if (!isset($masters[$id]) || $masters[$id]['status'] !== 'active' || !in_array($id,$sourceIds,true)) throw new DomainException('The source list changed. Review a new preview before copying.',409);
            $existing = synk_chair_faculty_query($conn, "SELECT college_faculty_id,status FROM tbl_college_faculty WHERE faculty_id=? AND college_id=?
                AND ((ay_id=? AND semester=?) OR (ay_id IS NULL AND semester IS NULL)) ORDER BY CASE WHEN LOWER(TRIM(status))='active' THEN 0 ELSE 1 END,college_faculty_id FOR UPDATE",
                'iiii',[$id,$scope['college_id'],$target['ay_id'],$target['semester']]);
            if ($existing && strtolower(trim($existing[0]['status'])) === 'active') { $skipped++; continue; }
            if ($existing) synk_chair_faculty_query($conn,"UPDATE tbl_college_faculty SET status='active' WHERE college_faculty_id=?",'i',[(int)$existing[0]['college_faculty_id']]);
            else synk_chair_faculty_query($conn,"INSERT INTO tbl_college_faculty (faculty_id,college_id,ay_id,semester,status) VALUES (?,?,?,?,'active')",'iiii',[$id,$scope['college_id'],$target['ay_id'],$target['semester']]);
            synk_faculty_term_designation_copy($conn,$id,$source,$target,(int)$_SESSION['user_id']);
            $copied++;
        }
        $conn->commit();
        unset($_SESSION['program_chair_list_copy_previews'][$token]);
        return ['copied'=>$copied,'skipped'=>$skipped,'target'=>$target];
    } catch (Throwable $exception) { $conn->rollback(); throw $exception; }
}
