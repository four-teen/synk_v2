<?php

require_once __DIR__ . '/auth_useraccount.php';
require_once __DIR__ . '/academic_term_helper.php';
require_once __DIR__ . '/offering_scope_helper.php';
require_once __DIR__ . '/schedule_merge_helper.php';
require_once __DIR__ . '/schedule_tba_helper.php';

function synk_subject_assignments_query(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to read subject assignments.');
    if ($types !== '') synk_bind_dynamic_params($stmt, $types, $params);
    if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Unable to read subject assignments.'); }
    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $result->close();
    $stmt->close();
    return $rows;
}

// Read access from the account, never from a submitted college or a stale session grant.
// Unlike the legacy bootstrap helpers, this viewing page does not install tables.
function synk_subject_assignments_scope(mysqli $conn): array
{
    if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'scheduler') {
        throw new DomainException('Sign in with your Scheduler account.', 401);
    }
    $accounts = synk_subject_assignments_query($conn,
        'SELECT user_id,username,email,role,status,college_id FROM tbl_useraccount WHERE user_id=?', 'i', [(int)$_SESSION['user_id']]);
    $account = $accounts[0] ?? null;
    if (!$account || $account['status'] !== 'active' || !synk_useraccount_has_active_role($conn, $account, 'scheduler')) {
        throw new DomainException('Your Scheduler access is no longer active.', 403);
    }
    $access = [];
    if (synk_scheduler_access_table_exists($conn)) {
        $access = synk_subject_assignments_query($conn, "SELECT a.college_id,a.is_default,c.college_code,c.college_name,c.campus_id,cp.campus_name
            FROM tbl_user_college_access a JOIN tbl_college c ON c.college_id=a.college_id
            LEFT JOIN tbl_campus cp ON cp.campus_id=c.campus_id
            WHERE a.user_id=? AND a.status='active' AND c.status='active'
            ORDER BY a.is_default DESC,c.college_name", 'i', [(int)$account['user_id']]);
    }
    if (!$access) $access = synk_build_scheduler_access_fallback($conn, (int)$account['college_id']);
    if (!$access) throw new DomainException('No college is assigned to your Scheduler account.', 403);
    foreach ($access as &$row) $row['display_label'] = synk_scheduler_access_option_label($row);
    unset($row);
    $account['role'] = 'scheduler';
    synk_scheduler_store_session_scope($account, $access, (int)($_SESSION['college_id'] ?? 0));
    return ['college_id'=>(int)$_SESSION['college_id'], 'college_name'=>(string)$_SESSION['college_name']];
}

function synk_subject_assignments_program_label(array $row): string
{
    $label = trim((string)$row['program_code']) ?: trim((string)$row['program_name']);
    $major = trim((string)($row['major'] ?? ''));
    return $label . ($major !== '' ? ' - ' . $major : '');
}

function synk_subject_assignments_options(mysqli $conn, array $scope): array
{
    $programs = synk_subject_assignments_query($conn,
        "SELECT program_id,program_code,program_name,COALESCE(major,'') AS major FROM tbl_program
         WHERE college_id=? ORDER BY program_code,program_name,major", 'i', [$scope['college_id']]);
    foreach ($programs as &$program) {
        $program['program_id'] = (int)$program['program_id'];
        $program['label'] = synk_subject_assignments_program_label($program);
    }
    unset($program);
    $joins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $rows = synk_subject_assignments_query($conn, "SELECT DISTINCT o.ay_id,o.semester,ay.ay AS ay_label
        FROM tbl_prospectus_offering o {$joins} JOIN tbl_program p ON p.program_id=o.program_id
        JOIN tbl_academic_years ay ON ay.ay_id=o.ay_id WHERE p.college_id=?", 'i', [$scope['college_id']]);
    $current = synk_fetch_current_academic_term($conn);
    if ($current['ay_id'] > 0 && $current['semester'] > 0) {
        $rows[] = ['ay_id'=>$current['ay_id'], 'semester'=>$current['semester'], 'ay_label'=>$current['ay_label']];
    }
    $terms = [];
    foreach ($rows as $row) {
        $semester = (int)$row['semester'];
        if ($semester < 1 || $semester > 3) continue;
        $key = (int)$row['ay_id'] . ':' . $semester;
        $terms[$key] = ['key'=>$key, 'ay_id'=>(int)$row['ay_id'], 'semester'=>$semester,
            'ay_label'=>(string)$row['ay_label'], 'label'=>$row['ay_label'] . ' - ' . synk_semester_label($semester)];
    }
    $terms = array_values($terms);
    usort($terms, static function(array $a, array $b): int {
        return strcmp($b['ay_label'], $a['ay_label']) ?: $b['semester'] - $a['semester'];
    });
    return ['programs'=>$programs, 'terms'=>$terms, 'current_term'=>$current['ay_id'] . ':' . $current['semester']];
}

function synk_subject_assignments_scalar(array $input, string $key, string $default = ''): string
{
    $value = $input[$key] ?? $default;
    if (!is_scalar($value)) throw new DomainException('Choose valid subject filters.', 422);
    return trim((string)$value);
}

function synk_subject_assignments_int(array $input, string $key, int $default = 0): int
{
    $value = synk_subject_assignments_scalar($input, $key, (string)$default);
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0, 'max_range'=>2147483647]]);
    if ($number === false) throw new DomainException('Choose valid subject filters.', 422);
    return $number;
}

function synk_subject_assignments_filters(array $input, array $options): array
{
    $key = synk_subject_assignments_scalar($input, 'term', $options['current_term']);
    $term = null;
    foreach ($options['terms'] as $candidate) if ($candidate['key'] === $key) $term = $candidate;
    if (!$term) throw new DomainException('Choose an academic term available to your college.', 422);
    $program = synk_subject_assignments_int($input, 'program_id');
    if ($program && !in_array($program, array_column($options['programs'], 'program_id'), true)) {
        throw new DomainException('Choose a program in your selected college.', 403);
    }
    $search = synk_subject_assignments_scalar($input, 'search');
    if (!preg_match('//u', $search) || mb_strlen($search, 'UTF-8') > 150) {
        throw new DomainException('Search using up to 150 characters.', 422);
    }
    $status = synk_subject_assignments_scalar($input, 'assignment', 'all');
    if (!in_array($status, ['all', 'assigned', 'unassigned', 'needs'], true)) {
        throw new DomainException('Choose a valid assignment filter.', 422);
    }
    return ['term'=>$term, 'program_id'=>$program, 'search'=>$search, 'assignment'=>$status,
        'page'=>max(1, synk_subject_assignments_int($input, 'page', 1))];
}

function synk_subject_assignments_data(mysqli $conn, array $scope, array $filters): array
{
    $term = $filters['term'];
    $joins = synk_section_curriculum_live_offering_join_sql('o', 'sec', 'sc', 'ps', 'pys', 'ph');
    $offerings = synk_subject_assignments_query($conn, "SELECT DISTINCT o.offering_id,o.program_id,ps.sub_id AS subject_id,
        sm.sub_code,sm.sub_description,p.program_code,p.program_name,COALESCE(p.major,'') AS major,
        sec.section_id,sec.section_name,sec.full_section,ps.lec_units,ps.lab_units
        FROM tbl_prospectus_offering o {$joins} JOIN tbl_program p ON p.program_id=o.program_id
        JOIN tbl_subject_masterlist sm ON sm.sub_id=ps.sub_id
        WHERE p.college_id=? AND o.ay_id=? AND o.semester=?
          AND (?=0 OR o.program_id=?) ORDER BY sm.sub_code,sm.sub_description,p.program_code,sec.full_section",
        'iiiii', [$scope['college_id'], $term['ay_id'], $term['semester'], $filters['program_id'], $filters['program_id']]);

    // Only the pure resolver is reused: the existing merge loaders also run schema updates.
    $mergeMap = [];
    if (synk_table_exists($conn, 'tbl_class_schedule_merge')) {
        $hasScope = synk_table_has_column($conn, 'tbl_class_schedule_merge', 'merge_scope');
        $merges = synk_subject_assignments_query($conn, "SELECT m.owner_offering_id,m.member_offering_id," .
            ($hasScope ? 'm.merge_scope' : "'FULL' AS merge_scope") . " FROM tbl_class_schedule_merge m
            JOIN tbl_prospectus_offering o ON o.offering_id=m.member_offering_id
            JOIN tbl_program p ON p.program_id=o.program_id
            WHERE p.college_id=? AND o.ay_id=? AND o.semester=?", 'iii', [$scope['college_id'], $term['ay_id'], $term['semester']]);
        foreach ($merges as $merge) $mergeMap[(int)$merge['member_offering_id']][synk_schedule_merge_normalize_scope($merge['merge_scope'])] = (int)$merge['owner_offering_id'];
    }
    $owners = []; $cache = []; $ownerIds = [];
    foreach ($offerings as $offering) {
        $id = (int)$offering['offering_id'];
        foreach (['LEC', 'LAB'] as $type) {
            $owner = synk_schedule_merge_resolve_effective_owner_for_type($id, $type, $mergeMap, $cache);
            $owners[$id][$type] = $owner;
            $ownerIds[$owner] = $owner;
        }
    }
    $schedules = []; $facultyBySchedule = []; $needsBySchedule = [];
    if ($ownerIds) {
        $ids = implode(',', $ownerIds); // IDs originate from integer casts, never submitted SQL.
        $hasStatus = synk_table_has_column($conn, 'tbl_class_schedule', 'schedule_status');
        $rows = synk_subject_assignments_query($conn, "SELECT DISTINCT cs.schedule_id,cs.offering_id,cs.schedule_type,
            " . ($hasStatus ? 'cs.schedule_status,' : '') . "cs.days_json,cs.time_start,cs.time_end,cs.room_id,r.room_code,r.room_name
            FROM tbl_class_schedule cs JOIN tbl_prospectus_offering o ON o.offering_id=cs.offering_id
            {$joins} JOIN tbl_program p ON p.program_id=o.program_id LEFT JOIN tbl_rooms r ON r.room_id=cs.room_id
            WHERE p.college_id=? AND o.ay_id=? AND o.semester=? AND o.offering_id IN ({$ids})
            ORDER BY cs.time_start,cs.schedule_id", 'iii', [$scope['college_id'], $term['ay_id'], $term['semester']]);
        $scheduleIds = [];
        foreach ($rows as $row) {
            $schedules[(int)$row['offering_id']][synk_normalize_schedule_type($row['schedule_type'])][] = $row;
            $scheduleIds[(int)$row['schedule_id']] = (int)$row['schedule_id'];
        }
        if ($scheduleIds) {
            $scheduleList = implode(',', $scheduleIds);
            $assigned = synk_subject_assignments_query($conn, "SELECT DISTINCT fw.schedule_id,f.faculty_id,f.last_name,f.first_name,f.middle_name,f.ext_name,
                EXISTS(SELECT 1 FROM tbl_college_faculty cf WHERE cf.faculty_id=f.faculty_id AND cf.college_id=?
                    AND cf.ay_id=fw.ay_id AND cf.semester=fw.semester AND cf.status='active') AS can_open_workload
                FROM tbl_faculty_workload_sched fw JOIN tbl_faculty f ON f.faculty_id=fw.faculty_id AND f.status='active'
                WHERE fw.ay_id=? AND fw.semester=? AND fw.schedule_id IN ({$scheduleList})", 'iii', [$scope['college_id'], $term['ay_id'], $term['semester']]);
            foreach ($assigned as $faculty) {
                $name = trim(implode(' ', array_filter([$faculty['first_name'], $faculty['middle_name'], $faculty['ext_name']], static function($part): bool { return trim((string)$part) !== ''; })));
                $facultyBySchedule[(int)$faculty['schedule_id']][(int)$faculty['faculty_id']] = [
                    'faculty_id'=>(int)$faculty['faculty_id'], 'name'=>trim($faculty['last_name']) . ', ' . $name,
                    'can_open_workload'=>(bool)$faculty['can_open_workload']];
            }
            if (synk_table_exists($conn, 'tbl_faculty_need') && synk_table_exists($conn, 'tbl_faculty_need_workload_sched')) {
                $needs = synk_subject_assignments_query($conn, "SELECT DISTINCT nw.schedule_id,n.faculty_need_id,n.need_label
                    FROM tbl_faculty_need_workload_sched nw JOIN tbl_faculty_need n ON n.faculty_need_id=nw.faculty_need_id
                    WHERE nw.ay_id=? AND nw.semester=? AND n.ay_id=nw.ay_id AND n.semester=nw.semester
                      AND n.college_id=? AND n.status='active' AND nw.schedule_id IN ({$scheduleList})", 'iii', [$term['ay_id'], $term['semester'], $scope['college_id']]);
                foreach ($needs as $need) $needsBySchedule[(int)$need['schedule_id']][(int)$need['faculty_need_id']] = [
                    'faculty_need_id'=>(int)$need['faculty_need_id'], 'label'=>(string)$need['need_label']];
            }
        }
    }
    $groups = [];
    foreach ($offerings as $offering) {
        $key = (int)$offering['subject_id'] . ':' . (int)$offering['program_id'];
        if (!isset($groups[$key])) $groups[$key] = ['subject_id'=>(int)$offering['subject_id'], 'program_id'=>(int)$offering['program_id'],
            'subject_code'=>$offering['sub_code'], 'description'=>$offering['sub_description'],
            'program'=>synk_subject_assignments_program_label($offering), 'faculty'=>[], 'needs'=>[], 'sections'=>[], 'meetings'=>[], 'unassigned_meetings'=>0];
        $group =& $groups[$key];
        $group['sections'][(int)$offering['section_id']] = true;
        $id = (int)$offering['offering_id'];
        foreach (['LEC', 'LAB'] as $type) {
            $rows = $schedules[$owners[$id][$type]][$type] ?? [];
            $required = (float)$offering[$type === 'LEC' ? 'lec_units' : 'lab_units'] > 0;
            if (!$rows && !$required) continue;
            if (!$rows) $rows = [['schedule_id'=>0, 'offering_id'=>$owners[$id][$type], 'schedule_status'=>'tba', 'days_json'=>'[]', 'room_id'=>null, 'time_start'=>null, 'time_end'=>null]];
            foreach ($rows as $row) {
                $sid = (int)$row['schedule_id'];
                $faculty = array_values($facultyBySchedule[$sid] ?? []);
                $needs = array_values($needsBySchedule[$sid] ?? []);
                foreach ($faculty as $person) $group['faculty'][$person['faculty_id']] = $person;
                foreach ($needs as $need) $group['needs'][$need['faculty_need_id']] = $need;
                $status = $sid ? synk_schedule_status($row) : 'unscheduled';
                $days = synk_normalize_schedule_days(json_decode((string)$row['days_json'], true));
                $group['meetings'][] = ['offering_id'=>$id, 'schedule_id'=>$sid,
                    'section'=>trim((string)$offering['full_section']) ?: (string)$offering['section_name'],
                    'type'=>$type, 'status'=>$status, 'merged'=>(int)$row['offering_id'] !== $id,
                    'days'=>$status === 'tba' || !$days ? 'TBA' : implode(', ', $days),
                    'time'=>$status === 'tba' || !$sid ? 'TBA' : synk_schedule_time_label($row['time_start'], $row['time_end'], 'g:i A', ' – '),
                    'room'=>in_array($status, ['tba','room_tba','unscheduled'], true) ? 'TBA' : (trim((string)($row['room_code'] ?? '')) ?: 'TBA'),
                    'faculty'=>$faculty, 'needs'=>$needs];
                if (!$faculty) $group['unassigned_meetings']++;
            }
        }
        unset($group);
    }
    return $groups;
}

function synk_subject_assignments_matches(array $group, array $filters): bool
{
    if ($filters['assignment'] === 'assigned' && !$group['faculty']) return false;
    if ($filters['assignment'] === 'unassigned' && $group['faculty']) return false;
    if ($filters['assignment'] === 'needs' && !$group['needs']) return false;
    $text = implode(' ', array_merge([$group['subject_code'], $group['description'], $group['program']], array_column($group['faculty'], 'name'), array_column($group['needs'], 'label')));
    foreach (preg_split('/\s+/u', $filters['search'], -1, PREG_SPLIT_NO_EMPTY) as $word) {
        if (mb_stripos($text, $word, 0, 'UTF-8') === false) return false;
    }
    return true;
}

function synk_subject_assignments_list(array $groups, array $filters): array
{
    $rows = []; $faculty = []; $summary = ['subjects'=>0, 'assigned'=>0, 'unassigned'=>0, 'faculty'=>0];
    foreach ($groups as $group) {
        if (!synk_subject_assignments_matches($group, $filters)) continue;
        $count = count($group['faculty']);
        $summary['subjects']++; $summary[$count ? 'assigned' : 'unassigned']++;
        foreach ($group['faculty'] as $id=>$person) $faculty[$id] = true;
        $rows[] = ['subject_id'=>$group['subject_id'], 'program_id'=>$group['program_id'], 'subject_code'=>$group['subject_code'],
            'description'=>$group['description'], 'program'=>$group['program'], 'faculty_count'=>$count,
            'faculty_names'=>array_column($group['faculty'], 'name'), 'section_count'=>count($group['sections']),
            'need_count'=>count($group['needs']), 'unassigned_meetings'=>$group['unassigned_meetings']];
    }
    $summary['faculty'] = count($faculty);
    $total = count($rows); $pages = max(1, (int)ceil($total / 25)); $page = min($pages, $filters['page']);
    return ['rows'=>array_slice($rows, ($page - 1) * 25, 25), 'summary'=>$summary, 'total'=>$total, 'page'=>$page, 'pages'=>$pages, 'per_page'=>25];
}
