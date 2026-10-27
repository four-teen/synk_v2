<?php
require_once __DIR__ . '/management_portal_helper.php';
require_once __DIR__ . '/management_term_helper.php';

function synk_management_catalog_kind(string $kind): array
{
    if ($kind === 'contributions') return ['table'=>'tbl_management_contributions','key'=>'contribution_id','date'=>'contribution_date','label'=>'Contribution'];
    if ($kind === 'events') return ['table'=>'tbl_management_events','key'=>'event_id','date'=>'event_date','label'=>'Event'];
    throw new DomainException('Choose Contributions or Events.');
}

function synk_management_catalog_status_options(string $kind): array
{
    synk_management_catalog_kind($kind);
    return ['ACTIVE'=>'Active','INACTIVE'=>'Inactive'];
}

function synk_management_catalog_ready(mysqli $conn): bool
{
    foreach (['tbl_management_organizations','tbl_management_contributions','tbl_management_events','tbl_management_event_sessions'] as $table) {
        if (!synk_table_exists($conn, $table)) return false;
    }
    return true;
}

function synk_management_execute(mysqli $conn, string $sql, string $types = '', array $values = []): int
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Unable to prepare the Management update.');
    try {
        if ($types !== '') synk_stmt_bind_params($stmt, $types, $values);
        if (!$stmt->execute()) {
            if ((int)$stmt->errno === 1451) throw new SynkManagementConflict('This record is in use. Remove its links first, or mark it inactive.');
            if ((int)$stmt->errno === 1062) throw new DomainException('That record already exists in your college.');
            throw new RuntimeException('Unable to save the Management record.');
        }
        return (int)$stmt->affected_rows;
    } catch (mysqli_sql_exception $error) {
        if ((int)$error->getCode() === 1451) throw new SynkManagementConflict('This record is in use. Remove its links first, or mark it inactive.');
        if ((int)$error->getCode() === 1062) throw new DomainException('That record already exists in your college.');
        throw $error;
    } finally { $stmt->close(); }
}

function synk_management_input_text(array $input, string $key, int $limit, bool $required = true): string
{
    $value = $input[$key] ?? '';
    if (!is_scalar($value)) throw new DomainException('Enter a valid ' . str_replace('_', ' ', $key) . '.');
    $value = trim((string)$value);
    if (!preg_match('//u', $value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f-\x9f]/u', $value)) throw new DomainException('Enter valid text.');
    $value = mb_strtoupper($value, 'UTF-8');
    if (($required && $value === '') || mb_strlen($value, 'UTF-8') > $limit) throw new DomainException('Enter ' . str_replace('_',' ', $key) . ' using up to ' . $limit . ' characters.');
    return $value;
}

function synk_management_input_id($value, bool $optional = false): ?int
{
    if ($optional && ($value === '' || $value === null || $value === '0' || $value === 0)) return null;
    if (!is_scalar($value)) throw new DomainException('Choose a valid record.');
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>4294967295]]);
    if ($id === false) throw new DomainException('Choose a valid record.');
    return (int)$id;
}

function synk_management_input_money($value, int $scale = 2, bool $optional = false): ?string
{
    if ($optional && ($value === '' || $value === null)) return null;
    if (!is_scalar($value) || !preg_match('/^(0|[1-9][0-9]{0,' . (11-$scale) . '})(?:\.([0-9]{1,' . $scale . '}))?$/D', trim((string)$value), $match)) {
        throw new DomainException('Enter a non-negative amount with up to ' . $scale . ' decimal places.');
    }
    return $match[1] . '.' . str_pad($match[2] ?? '', $scale, '0');
}

function synk_management_input_date($value, bool $time = false): string
{
    if (!is_string($value)) throw new DomainException('Enter a valid date.');
    if ($time) {
        $value = str_replace('T',' ', $value);
        if (strlen($value) === 16) $value .= ':00';
    }
    $format = $time ? 'Y-m-d H:i:s' : 'Y-m-d';
    $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
    if (!$date || $date->format($format) !== $value || (int)$date->format('Y') < 1000) throw new DomainException('Enter a valid date' . ($time ? ' and time.' : '.'));
    return $value;
}

function synk_management_catalog_options(mysqli $conn, array $scope, ?array $period = null): array
{
    $period = $period ?? synk_management_fetch_current_period($conn,$scope);
    return [
        'organizations'=>synk_management_query($conn, 'SELECT organization_id,organization_code,organization_name,status FROM tbl_management_organizations WHERE college_id=? AND organization_id=? ORDER BY organization_code', 'ii', [$scope['college_id'],$scope['organization_id']]),
        'events'=>synk_management_query($conn, 'SELECT event_id,title,event_date,ay_id,semester,exam_term FROM tbl_management_events WHERE college_id=? AND organization_id=? AND ay_id=? AND semester=? AND exam_term=? ORDER BY event_id DESC', 'iiiis', [$scope['college_id'],$scope['organization_id'],$period['ay_id'],$period['semester'],$period['exam_term']]),
    ];
}

function synk_management_catalog_get(mysqli $conn, array $scope, string $kind, int $id, bool $lock = false): array
{
    $meta = synk_management_catalog_kind($kind);
    $rows = synk_management_query($conn, 'SELECT * FROM ' . $meta['table'] . ' WHERE college_id=? AND organization_id=? AND ' . $meta['key'] . '=?' . ($lock ? ' FOR UPDATE' : ''), 'iii', [$scope['college_id'],$scope['organization_id'],$id]);
    if (!$rows) throw new OutOfBoundsException('This record is unavailable in your organization.');
    $record = $rows[0];
    if ($kind === 'events') {
        $record['sessions'] = synk_management_query($conn, 'SELECT * FROM tbl_management_event_sessions WHERE college_id=? AND event_id=? ORDER BY session_id' . ($lock ? ' FOR UPDATE' : ''), 'ii', [$scope['college_id'],$id]);
        $record['linked_contributions'] = (int)synk_management_query($conn, 'SELECT COUNT(*) AS n FROM tbl_management_contributions WHERE college_id=? AND event_id=?', 'ii', [$scope['college_id'],$id])[0]['n'];
    }
    $record['version'] = hash('sha256', json_encode($record));
    return $record;
}

function synk_management_catalog_in_period(array $record, array $period): bool
{
    return (int)($record['ay_id'] ?? 0) === (int)$period['ay_id']
        && (int)($record['semester'] ?? 0) === (int)$period['semester']
        && ($record['exam_term'] ?? null) === $period['exam_term'];
}

function synk_management_catalog_filters(array $input, string $kind): array
{
    foreach (['search','organization_id','status','catalog_scope','page','page_size'] as $key) if (isset($input[$key]) && !is_scalar($input[$key])) throw new DomainException('Choose valid filters.');
    $search = trim((string)($input['search'] ?? ''));
    if (mb_strlen($search, 'UTF-8') > 150 || !preg_match('//u', $search)) throw new DomainException('Search using up to 150 characters.');
    $status = (string)($input['status'] ?? ''); $view = (string)($input['catalog_scope'] ?? 'term');
    if (($status !== '' && !isset(synk_management_catalog_status_options($kind)[$status])) || !in_array($view, ['term','all','unassigned'], true)) throw new DomainException('Choose valid catalog filters.');
    $page = filter_var($input['page'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $size = filter_var($input['page_size'] ?? 25,FILTER_VALIDATE_INT);
    if ($page === false || !in_array($size,[25,50,100],true)) throw new DomainException('Choose a valid page size.');
    return ['search'=>$search,'organization_id'=>synk_management_input_id($input['organization_id'] ?? '', true) ?? 0,'status'=>$status,'catalog_scope'=>$view,'page'=>$page,'page_size'=>$size];
}

function synk_management_catalog_list(mysqli $conn, array $scope, array $term, string $kind, array $input): array
{
    $meta = synk_management_catalog_kind($kind);
    // Both catalogs follow all three parts of the selected Management period.
    $input['catalog_scope'] = 'term';
    $filters = synk_management_catalog_filters($input,$kind);
    if (!$filters['organization_id']) $filters['organization_id'] = $scope['organization_id'];
    $sql = ' FROM ' . $meta['table'] . ' c LEFT JOIN tbl_management_organizations o ON o.organization_id=c.organization_id AND o.college_id=c.college_id LEFT JOIN tbl_academic_years ay ON ay.ay_id=c.ay_id WHERE c.college_id=? AND c.organization_id=?';
    $types = 'ii'; $values = [$scope['college_id'],$scope['organization_id']];
    if ($filters['catalog_scope'] === 'term') { $sql .= ' AND c.ay_id=? AND c.semester=?'; $types .= 'ii'; $values[] = $term['ay_id']; $values[] = $term['semester']; }
    $sql .= ' AND c.exam_term=?'; $types .= 's'; $values[] = synk_management_exam_term($term['exam_term'] ?? null);
    if ($filters['catalog_scope'] === 'unassigned') $sql .= ' AND (c.ay_id IS NULL OR c.semester IS NULL OR c.organization_id IS NULL)';
    if ($filters['organization_id']) {
        if ($filters['organization_id'] !== $scope['organization_id']) throw new DomainException('Choose your assigned organization.');
        $sql .= ' AND c.organization_id=?'; $types .= 'i'; $values[] = $filters['organization_id'];
    }
    if ($filters['status'] !== '') { $sql .= ' AND c.status=?'; $types .= 's'; $values[] = $filters['status']; }
    if ($filters['search'] !== '') {
        $sql .= " AND c.title LIKE ? ESCAPE '!'"; $types .= 's'; $values[] = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], $filters['search']) . '%';
    }
    $statusCounts = "COALESCE(SUM(c.status='ACTIVE'),0) AS active_count,COALESCE(SUM(c.status='INACTIVE'),0) AS inactive_count";
    $summary = synk_management_query($conn, 'SELECT COUNT(*) AS total,' . $statusCounts . $sql, $types, $values)[0];
    $total = (int)$summary['total']; $pages = max(1, (int)ceil($total / $filters['page_size'])); $page = min($filters['page'],$pages);
    $extras = $kind === 'events' ? ',(SELECT COUNT(*) FROM tbl_management_event_sessions s WHERE s.event_id=c.event_id AND s.college_id=c.college_id) AS session_count' : '';
    $order = 'c.' . $meta['key'] . ' DESC';
    $rows = synk_management_query($conn, 'SELECT c.*,o.organization_code,ay.ay' . $extras . $sql . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?', $types . 'ii', array_merge($values, [$filters['page_size'],($page-1)*$filters['page_size']]));
    if ($kind==='events' && $rows) {
        $attendance=[];
        if (synk_table_exists($conn,'tbl_management_attendance_events') && synk_table_exists($conn,'tbl_management_attendance_roster') && synk_table_exists($conn,'tbl_management_attendance_logs')) {
            $ids=array_map('intval',array_column($rows,'event_id'));
            $counts=synk_management_query($conn,'SELECT a.event_id,a.attendance_status,COUNT(r.student_id) AS eligible,COUNT(l.student_id) AS present
                FROM tbl_management_attendance_events a LEFT JOIN tbl_management_attendance_roster r ON r.event_id=a.event_id
                LEFT JOIN tbl_management_attendance_logs l ON l.event_id=r.event_id AND l.student_id=r.student_id
                WHERE a.college_id=? AND a.organization_id=? AND a.event_id IN ('.implode(',',array_fill(0,count($ids),'?')).')
                GROUP BY a.event_id,a.attendance_status','ii'.str_repeat('i',count($ids)),array_merge([$scope['college_id'],$scope['organization_id']],$ids));
            foreach ($counts as $count) $attendance[(int)$count['event_id']]=$count;
        }
        $eligible=null;
        foreach ($rows as &$row) {
            $count=$attendance[(int)$row['event_id']] ?? null;
            if (!$count && $eligible===null) {
                require_once __DIR__.'/college_dean_students_helper.php';
                $rosterScope=$scope;
                if (empty($rosterScope['campus_id'])) $rosterScope['campus_id']=(int)synk_management_query($conn,'SELECT campus_id FROM tbl_college WHERE college_id=?','i',[$scope['college_id']])[0]['campus_id'];
                $source=synk_dean_students_source($rosterScope,['ay_id'=>$term['ay_id'],'semester'=>$term['semester']],$conn);
                $eligible=(int)synk_management_query($conn,'SELECT COUNT(DISTINCT s.student_id) AS n '.$source['sql'],$source['types'],$source['params'])[0]['n'];
            }
            $row['attendance_status']=$count['attendance_status'] ?? 'NOT_STARTED';
            $row['attendance_present']=(int)($count['present'] ?? 0);
            $row['attendance_remaining']=max(0,(int)($count['eligible'] ?? $eligible)-$row['attendance_present']);
        }
        unset($row);
    }
    $unassigned = (int)synk_management_query($conn, 'SELECT COUNT(*) AS n FROM ' . $meta['table'] . ' WHERE college_id=? AND organization_id=? AND (ay_id IS NULL OR semester IS NULL)', 'ii', [$scope['college_id'],$scope['organization_id']])[0]['n'];
    return ['rows'=>$rows,'summary'=>$summary,'unassigned'=>$unassigned,'filters'=>$filters,'pagination'=>['page'=>$page,'pages'=>$pages,'total'=>$total,'page_size'=>$filters['page_size']]];
}

function synk_management_catalog_payload(mysqli $conn, array $scope, string $kind, array $input, array $currentTerm, ?array $existing = null): array
{
    $meta = synk_management_catalog_kind($kind);
    $organizationId = $scope['organization_id'];
    if (isset($input['organization_id']) && (string)$input['organization_id'] !== (string)$organizationId) throw new DomainException('Use your assigned organization.');
    // Editing never silently moves historical records to another academic term.
    $ayId = $existing ? $existing['ay_id'] : $currentTerm['ay_id'];
    $semester = $existing ? $existing['semester'] : $currentTerm['semester'];
    $key = $ayId && $semester ? $ayId.':'.$semester : '';
    if (isset($input['record_term']) && $input['record_term'] !== $key) throw new DomainException('The academic term is assigned automatically and cannot be changed.');
    $status = $input['status'] ?? 'ACTIVE'; $audience = $input['audience_mode'] ?? 'ALL';
    if (!is_string($status) || !isset(synk_management_catalog_status_options($kind)[$status]) || !in_array($audience, ['ALL','SELECTED','UNSPECIFIED'], true)) throw new DomainException('Choose a valid status and audience.');
    $record = ['college_id'=>$scope['college_id'],'organization_id'=>$organizationId,'ay_id'=>$ayId,'semester'=>$semester,
        'title'=>synk_management_input_text($input,'title',255),'description'=>synk_management_input_text($input,'description',10000,false),
        $meta['date']=>synk_management_input_date($input[$meta['date']] ?? ''),'audience_mode'=>$audience,'status'=>$status];
    // The locked server period is authoritative, never a date or client value.
    if ($existing && !synk_management_catalog_in_period($existing,$currentTerm)) {
        throw new SynkManagementConflict('Only records in the current Management period can be changed.');
    }
    $record['exam_term'] = synk_management_exam_term($currentTerm['exam_term']);
    if ($kind === 'contributions') {
        $record['amount'] = synk_management_input_money($input['amount'] ?? '');
        $record['event_id'] = synk_management_input_id($input['event_id'] ?? '',true);
        if ($record['event_id'] && !synk_management_query($conn, 'SELECT event_id FROM tbl_management_events WHERE college_id=? AND organization_id=? AND ay_id=? AND semester=? AND exam_term=? AND event_id=?', 'iiiisi', [$scope['college_id'],$organizationId,$ayId,$semester,$record['exam_term'],$record['event_id']])) throw new DomainException('Choose an event in your organization and the same Management period.');
    } else {
        $record['absence_fine'] = synk_management_input_money($input['absence_fine'] ?? '',2,true);
        foreach (['latitude'=>90,'longitude'=>180] as $key=>$max) {
            $value = array_key_exists($key,$input) ? $input[$key] : ($existing[$key] ?? '');
            if (!is_scalar($value) && $value !== null) throw new DomainException('Enter valid coordinates.');
            $value = trim((string)$value);
            if ($value !== '' && (!preg_match('/^-?[0-9]{1,3}(?:\.[0-9]{1,7})?$/D',$value) || abs((float)$value)>$max)) throw new DomainException('Enter valid coordinates with up to 7 decimal places.');
            $record[$key] = $value === '' ? null : $value;
        }
        if (($record['latitude'] === null) !== ($record['longitude'] === null)) throw new DomainException('Enter both latitude and longitude.');
        $radius = array_key_exists('attendance_radius_m',$input) ? $input['attendance_radius_m'] : ($existing['attendance_radius_m'] ?? '');
        $record['attendance_radius_m'] = synk_management_input_id($radius, true);
        if ($record['latitude'] !== null && $record['attendance_radius_m'] === null) throw new DomainException('Enter an attendance radius for this location.');
    }
    return $record;
}

function synk_management_lock_assignment(mysqli $conn, array $scope): void
{
    $org = synk_management_query($conn,"SELECT o.organization_id FROM tbl_management_organizations o JOIN tbl_college c ON c.college_id=o.college_id JOIN tbl_campus p ON p.campus_id=c.campus_id WHERE o.organization_id=? AND o.college_id=? AND o.status='ACTIVE' AND c.status='active' AND p.status='active' FOR UPDATE",'ii',[$scope['organization_id'],$scope['college_id']]);
    if (!$org) throw new SynkManagementConflict('Your organization access changed. Reload the page.');
    $account = synk_management_query($conn, 'SELECT * FROM tbl_useraccount WHERE user_id=? FOR UPDATE','i',[(int)$_SESSION['user_id']]);
    if (!$account || !synk_management_account_has_access($conn,$account[0]) || (int)$account[0]['management_college_id'] !== $scope['college_id'] || (int)$account[0]['management_organization_id'] !== $scope['organization_id']) throw new SynkManagementConflict('Your organization assignment changed. Reload the page.');
}

function synk_management_session_payload(array $sessions, array $event, array $existing): array
{
    if (array_values($sessions) !== $sessions || count($sessions) > 50) throw new DomainException('Use up to 50 event sessions.');
    $known = []; foreach ($existing as $session) $known[(int)$session['session_id']] = $session;
    $result = []; $seen = [];
    foreach ($sessions as $session) {
        if (!is_array($session)) throw new DomainException('Enter valid session details.');
        $id = synk_management_input_id($session['session_id'] ?? '',true);
        if ($id && (!isset($known[$id]) || isset($seen[$id]))) throw new DomainException('A session is unavailable in this event. Reload its details.');
        if ($id) $seen[$id] = true;
        $start = synk_management_input_date($session['starts_at'] ?? '',true); $end = synk_management_input_date($session['ends_at'] ?? '',true);
        if ($end <= $start || substr($start,0,10) < $event['event_date']) throw new DomainException('A session must end after it starts and cannot start before the event date.');
        $grace = filter_var($session['grace_minutes'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>65535]]);
        if ($grace === false) throw new DomainException('Enter a valid grace period in minutes.');
        $status = $session['status'] ?? 'ACTIVE';
        if (!is_string($status) || !isset(synk_management_catalog_status_options('events')[$status])) throw new DomainException('Choose Active or Inactive for the session.');
        $result[] = ['session_id'=>$id,'title'=>synk_management_input_text($session,'title',255),'starts_at'=>$start,'ends_at'=>$end,
            'fine_per_minute'=>synk_management_input_money($session['fine_per_minute'] ?? '',4),'grace_minutes'=>$grace,
            'potential_fine'=>synk_management_input_money($session['potential_fine'] ?? '',2,true),'status'=>$status];
    }
    return $result;
}

function synk_management_catalog_write(mysqli $conn, array $scope, string $kind, string $action, array $input): array
{
    $meta = synk_management_catalog_kind($kind);
    if (!in_array($action,['save','delete'],true)) throw new DomainException('Choose a valid action.');
    $id = synk_management_input_id($input['record_id'] ?? '',true);
    if ($action === 'delete' && !$id) throw new DomainException('Choose a record to delete.');
    $conn->begin_transaction();
    try {
        // Lock current assignment before the record so a simultaneous reassignment cannot widen a write.
        synk_management_lock_assignment($conn,$scope);
        synk_management_require_permission($conn,$scope,'setup');
        $currentTerm = synk_management_lock_current_term($conn,$input,$scope);
        if (($input['term'] ?? $currentTerm['key']) !== $currentTerm['key']
                || ($input['exam_term'] ?? $currentTerm['exam_term']) !== $currentTerm['exam_term']) {
            throw new SynkManagementConflict('Switch to the current Management period before saving records.');
        }
        $existing = $id ? synk_management_catalog_get($conn,$scope,$kind,$id,true) : null;
        if ($existing) {
            require_once __DIR__.'/management_payment_read_helper.php';
            if (synk_management_charge_has_payments($conn,$kind,$id)) throw new SynkManagementConflict('Payments have already been recorded for this charge. Its details must be preserved for the receipts.');
        }
        if ($existing && (!is_string($input['version'] ?? null) || !hash_equals($existing['version'],$input['version']))) throw new SynkManagementConflict('This record changed after you opened it. Reload its details before saving or deleting.');
        if ($existing && !synk_management_catalog_in_period($existing,$currentTerm)) {
            throw new SynkManagementConflict('Only records in the current Management period can be changed.');
        }
        $attendance=null;
        if ($kind==='events' && $existing && synk_table_exists($conn,'tbl_management_attendance_events')) {
            $attendance=synk_management_query($conn,'SELECT event_id FROM tbl_management_attendance_events WHERE event_id=? FOR UPDATE','i',[$id]);
            if ($attendance && $action==='delete') throw new SynkManagementConflict('This event has attendance records and cannot be deleted.');
        }
        if ($action === 'delete') {
            if ($kind === 'events') {
                if ($existing['linked_contributions']) throw new SynkManagementConflict('This event is linked to contributions. Remove those event links first, or mark the event inactive.');
                synk_management_execute($conn, 'DELETE FROM tbl_management_event_sessions WHERE college_id=? AND event_id=?', 'ii', [$scope['college_id'],$id]);
            }
            synk_management_execute($conn, 'DELETE FROM ' . $meta['table'] . ' WHERE college_id=? AND ' . $meta['key'] . '=?','ii',[$scope['college_id'],$id]);
            $conn->commit(); return ['message'=>$meta['label'] . ' deleted.','record_id'=>$id];
        }
        $record = synk_management_catalog_payload($conn,$scope,$kind,$input,$currentTerm,$existing);
        if ($attendance && ($record['event_date']!==$existing['event_date'] || $record['absence_fine']!==$existing['absence_fine'] || $record['status']!==$existing['status'])) {
            throw new SynkManagementConflict('The date, absence fine and active status are locked once attendance starts.');
        }
        $sessions = [];
        $writeSessions = $kind === 'events' && array_key_exists('sessions',$input);
        if ($writeSessions) {
            $raw = $input['sessions'];
            if (!is_string($raw) || strlen($raw) > 100000) throw new DomainException('Enter valid session details.');
            $decoded = json_decode($raw,true);
            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) throw new DomainException('Enter valid session details.');
            $sessions = synk_management_session_payload($decoded,$record,$existing['sessions'] ?? []);
        }
        $keys = array_keys($record); $values = array_values($record);
        if ($id) {
            synk_management_execute($conn,'UPDATE ' . $meta['table'] . ' SET ' . implode(',',array_map(static function($key){ return '`'.$key.'`=?'; },$keys)) . ' WHERE college_id=? AND ' . $meta['key'] . '=?',str_repeat('s',count($values)).'ii',array_merge($values,[$scope['college_id'],$id]));
        } else {
            synk_management_execute($conn,'INSERT INTO ' . $meta['table'] . ' (`' . implode('`,`',$keys) . '`) VALUES (' . implode(',',array_fill(0,count($keys),'?')) . ')',str_repeat('s',count($values)),$values);
            $id = (int)$conn->insert_id;
        }
        if ($writeSessions) {
            $retained = [];
            foreach ($sessions as $session) {
                $sessionId = $session['session_id']; unset($session['session_id']);
                $session['college_id'] = $scope['college_id']; $session['event_id'] = $id;
                $keys = array_keys($session); $values = array_values($session);
                if ($sessionId) synk_management_execute($conn,'UPDATE tbl_management_event_sessions SET ' . implode(',',array_map(static function($key){ return '`'.$key.'`=?'; },$keys)) . ' WHERE college_id=? AND event_id=? AND session_id=?',str_repeat('s',count($values)).'iii',array_merge($values,[$scope['college_id'],$id,$sessionId]));
                else {
                    synk_management_execute($conn,'INSERT INTO tbl_management_event_sessions (`' . implode('`,`',$keys) . '`) VALUES (' . implode(',',array_fill(0,count($keys),'?')) . ')',str_repeat('s',count($values)),$values);
                    $sessionId = (int)$conn->insert_id;
                }
                $retained[] = $sessionId;
            }
            foreach ($existing['sessions'] ?? [] as $old) if (!in_array((int)$old['session_id'],$retained,true)) {
                synk_management_execute($conn,'DELETE FROM tbl_management_event_sessions WHERE college_id=? AND event_id=? AND session_id=?','iii',[$scope['college_id'],$id,(int)$old['session_id']]);
            }
        }
        $conn->commit();
        return ['message'=>$meta['label'] . ' saved.','record_id'=>$id];
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
}
