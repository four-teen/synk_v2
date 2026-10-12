<?php
require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/auth_useraccount.php';
require_once __DIR__ . '/faculty_record_profile_helper.php';

function synk_faculty_record_statuses(): array
{
    return ['active' => 'Active', 'inactive' => 'Inactive', 'resigned' => 'Resigned',
        'retired' => 'Retired', 'on_leave' => 'On leave', 'other' => 'Other'];
}

function synk_faculty_record_status_color(string $status): string
{
    return ['active' => 'success', 'inactive' => 'secondary', 'resigned' => 'danger',
        'retired' => 'info', 'on_leave' => 'warning', 'other' => 'secondary'][$status] ?? 'secondary';
}

// Add persistent directory fields without changing any existing affiliations or statuses.
// Call before a transaction: MySQL DDL implicitly commits.
function synk_faculty_directory_ensure_schema(mysqli $conn): void
{
    static $ready = false;
    if ($ready) return;
    $columns = [];
    $result = $conn->query('SHOW COLUMNS FROM tbl_faculty');
    while ($row = $result->fetch_assoc()) $columns[$row['Field']] = $row;
    $result->close();
    $changes = [];
    foreach (['campus_id' => 'INT(10) UNSIGNED NULL', 'college_id' => 'INT(10) UNSIGNED NULL',
        'remarks' => 'TEXT NULL', 'record_revision' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'record_updated_at' => 'DATETIME NULL', 'record_updated_by' => 'INT NULL'] as $column => $definition) {
        if (!isset($columns[$column])) $changes[] = "ADD COLUMN `{$column}` {$definition}";
    }
    $statusType = strtolower((string)($columns['status']['Type'] ?? ''));
    $missingStatus = false;
    foreach (array_keys(synk_faculty_record_statuses()) as $status) if (strpos($statusType, "'{$status}'") === false) $missingStatus = true;
    if (strpos($statusType, 'enum(') === 0 && $missingStatus) {
        // Preserve existing enum values and append the new lifecycle values.
        $values = substr($statusType, 5, -1);
        foreach (array_keys(synk_faculty_record_statuses()) as $value) {
            if (strpos($values, "'{$value}'") === false) $values .= ", '{$value}'";
        }
        $changes[] = "MODIFY COLUMN status ENUM({$values}) NULL DEFAULT 'active'";
    }
    if ($changes && !$conn->query('ALTER TABLE tbl_faculty ' . implode(', ', $changes))) {
        throw new RuntimeException('Unable to prepare faculty directory storage.');
    }
    $ready = true;
}

function synk_faculty_directory_id($value): ?int
{
    if (is_int($value)) return $value >= 0 ? $value : null;
    return is_string($value) && preg_match('/^\d{1,9}$/D', $value) ? (int)$value : null;
}

function synk_faculty_directory_locations(mysqli $conn): array
{
    $locations = ['campuses' => [], 'colleges' => []];
    foreach (['campuses' => ['tbl_campus', 'campus_id', 'campus_name'], 'colleges' => ['tbl_college', 'college_id', 'college_name']] as $key => $source) {
        $result = $conn->query("SELECT * FROM {$source[0]} ORDER BY {$source[2]}");
        while ($row = $result->fetch_assoc()) $locations[$key][(int)$row[$source[1]]] = $row;
        $result->close();
    }
    return $locations;
}

function synk_faculty_directory_records(mysqli $conn): array
{
    $locations = synk_faculty_directory_locations($conn);
    $campuses = $locations['campuses'];
    $colleges = $locations['colleges'];
    $accounts = [];
    $result = $conn->query('SELECT user_id, username, email, role, college_id, status FROM tbl_useraccount ORDER BY username, user_id');
    while ($row = $result->fetch_assoc()) $accounts[(int)$row['user_id']] = $row;
    $result->close();
    $profiles = [];
    if (synk_table_exists($conn, 'tbl_faculty_profiles')) {
        $result = $conn->query('SELECT * FROM tbl_faculty_profiles');
        while ($row = $result->fetch_assoc()) $profiles[(int)$row['user_id']] = $row;
        $result->close();
    }
    $recordProfiles = [];
    if (synk_faculty_record_profiles_ready($conn)) {
        $result = $conn->query('SELECT * FROM tbl_faculty_record_profiles');
        while ($row = $result->fetch_assoc()) $recordProfiles[(int)$row['faculty_id']] = synk_faculty_record_profile_decode($row);
        $result->close();
    }
    $links = [];
    if (synk_table_exists($conn, 'tbl_useraccount_faculty_links')) {
        $result = $conn->query('SELECT user_id, faculty_id FROM tbl_useraccount_faculty_links');
        while ($row = $result->fetch_assoc()) $links[(int)$row['faculty_id']] = (int)$row['user_id'];
        $result->close();
    }
    $rosters = [];
    if (synk_table_exists($conn, 'tbl_college_faculty')) {
        // Historical roster links are a fallback, never a filter by current semester.
        $result = $conn->query("SELECT DISTINCT faculty_id, college_id FROM tbl_college_faculty WHERE status = 'active'");
        while ($row = $result->fetch_assoc()) $rosters[(int)$row['faculty_id']][(int)$row['college_id']] = (int)$row['college_id'];
        $result->close();
    }
    $records = [];
    $result = $conn->query('SELECT f.*, COALESCE(d.designation_name, \'\') AS designation_name FROM tbl_faculty f
        LEFT JOIN tbl_designation d ON d.designation_id = f.designation_id ORDER BY f.last_name, f.first_name, f.faculty_id');
    while ($faculty = $result->fetch_assoc()) {
        $id = (int)$faculty['faculty_id'];
        $userId = $links[$id] ?? 0;
        $account = $accounts[$userId] ?? null;
        $profile = $profiles[$userId] ?? ($recordProfiles[$id] ?? null);
        $collegeIds = [];
        $campusIds = [];
        $source = 'Not recorded';
        $homeCollege = (int)($faculty['college_id'] ?? 0);
        $homeCampus = (int)($faculty['campus_id'] ?? 0);
        if ($homeCollege > 0 || $homeCampus > 0) {
            if (isset($colleges[$homeCollege])) $collegeIds[$homeCollege] = $homeCollege;
            if (isset($campuses[$homeCampus])) $campusIds[$homeCampus] = $homeCampus;
            $source = 'Faculty record';
        } else {
            $profileCampus = trim((string)($profile['campus_name'] ?? ''));
            $profileCollege = trim((string)($profile['college_name'] ?? ''));
            foreach ($campuses as $campusId => $campus) {
                if ($profileCampus !== '' && strcasecmp($profileCampus, $campus['campus_name']) === 0) $campusIds[$campusId] = $campusId;
            }
            foreach ($colleges as $collegeId => $college) {
                if ($profileCollege !== '' && strcasecmp($profileCollege, $college['college_name']) === 0
                    && isset($campusIds[(int)$college['campus_id']])) $collegeIds[$collegeId] = $collegeId;
            }
            if ($campusIds || $collegeIds) $source = 'Professor profile';
            if (!$campusIds && !$collegeIds && $profileCampus === '' && $profileCollege === '') {
                $accountCollege = (int)($account['college_id'] ?? 0);
                if (isset($colleges[$accountCollege])) {
                    $collegeIds[$accountCollege] = $accountCollege;
                    $source = 'Linked account';
                } else {
                    foreach ($rosters[$id] ?? [] as $collegeId) {
                        if (isset($colleges[$collegeId])) $collegeIds[$collegeId] = $collegeId;
                    }
                    if ($collegeIds) $source = 'College roster links (all terms)';
                }
            }
        }
        foreach ($collegeIds as $collegeId) {
            $campusId = (int)$colleges[$collegeId]['campus_id'];
            if (isset($campuses[$campusId])) $campusIds[$campusId] = $campusId;
        }
        $names = static function (array $ids, array $catalog, string $field): string {
            $labels = [];
            foreach ($ids as $locationId) $labels[] = $catalog[$locationId][$field];
            return $labels ? implode(' / ', $labels) : 'Not recorded';
        };
        $faculty['faculty_id'] = $id;
        $faculty['name'] = trim(implode(' ', array_filter([$faculty['first_name'], $faculty['middle_name'], $faculty['last_name'], $faculty['ext_name']])));
        $faculty['user_id'] = $userId;
        $faculty['account'] = $account;
        $faculty['profile'] = $profile;
        $faculty['faculty_id_number'] = (string)($profile['faculty_id_number'] ?? '');
        $faculty['college_ids'] = array_values($collegeIds);
        $faculty['campus_ids'] = array_values($campusIds);
        $faculty['college_name'] = $names($collegeIds, $colleges, 'college_name');
        $faculty['campus_name'] = $names($campusIds, $campuses, 'campus_name');
        $faculty['connection_source'] = $source;
        $explicitValid = ($homeCampus === 0 || isset($campuses[$homeCampus]))
            && ($homeCollege === 0 || (isset($colleges[$homeCollege]) && ($homeCampus === 0 || (int)$colleges[$homeCollege]['campus_id'] === $homeCampus)));
        $faculty['connection_complete'] = (bool)$collegeIds && (bool)$campusIds && $explicitValid;
        $records[$id] = $faculty;
    }
    $result->close();
    return $locations + ['faculty' => $records, 'accounts' => $accounts, 'profiles' => $profiles, 'links' => $links];
}

function synk_faculty_directory_validate(array $input, array $locations): array
{
    $errors = [];
    $status = is_string($input['status'] ?? null) ? $input['status'] : '';
    $campusId = synk_faculty_directory_id($input['campus_id'] ?? '0');
    $collegeId = synk_faculty_directory_id($input['college_id'] ?? '0');
    $remarks = is_string($input['remarks'] ?? null) ? trim($input['remarks']) : '';
    if (!isset(synk_faculty_record_statuses()[$status])) $errors['status'] = 'Choose a valid faculty status.';
    if ($campusId === null || ($campusId > 0 && !isset($locations['campuses'][$campusId]))) $errors['campus_id'] = 'Choose a valid campus.';
    if ($collegeId === null || ($collegeId > 0 && !isset($locations['colleges'][$collegeId]))) $errors['college_id'] = 'Choose a valid college.';
    if ($collegeId > 0 && isset($locations['colleges'][$collegeId]) && (int)$locations['colleges'][$collegeId]['campus_id'] !== $campusId) {
        $errors['college_id'] = 'Choose a college belonging to the selected campus.';
    }
    if (preg_match('//u', $remarks) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $remarks)
        || (function_exists('mb_strlen') ? mb_strlen($remarks, 'UTF-8') : strlen($remarks)) > 2000) {
        $errors['remarks'] = 'Use valid text with no more than 2,000 characters.';
    } elseif ($status === 'other' && $remarks === '') $errors['remarks'] = 'Add remarks to explain the other status.';
    return [['status' => $status, 'campus_id' => $campusId, 'college_id' => $collegeId, 'remarks' => $remarks], $errors];
}

function synk_faculty_directory_save(mysqli $conn, int $facultyId, int $revision, int $actorId, array $values): bool
{
    $actor = synk_find_useraccount_by_id($conn, $actorId);
    if (!$actor || $actor['status'] !== 'active' || !synk_useraccount_has_active_role($conn, $actor, 'DI')) {
        throw new RuntimeException('Only an active DI account can review faculty records.');
    }
    if ($facultyId <= 0 || $revision < 0) throw new InvalidArgumentException('Invalid faculty record.');
    [$values, $errors] = synk_faculty_directory_validate($values, synk_faculty_directory_locations($conn));
    if ($errors) throw new InvalidArgumentException(implode(' ', $errors));
    $campusId = $values['campus_id'] ?: null;
    $collegeId = $values['college_id'] ?: null;
    $stmt = $conn->prepare('UPDATE tbl_faculty SET status = ?, campus_id = ?, college_id = ?, remarks = ?,
        record_revision = record_revision + 1, record_updated_at = NOW(), record_updated_by = ?
        WHERE faculty_id = ? AND record_revision = ?');
    $stmt->bind_param('siisiii', $values['status'], $campusId, $collegeId, $values['remarks'], $actorId, $facultyId, $revision);
    $stmt->execute();
    $saved = $stmt->affected_rows === 1;
    $stmt->close();
    return $saved;
}

function synk_di_faculty_filters(array $input, array $directory): array
{
    $campusId = synk_faculty_directory_id($input['campus_id'] ?? '0') ?? 0;
    $collegeId = synk_faculty_directory_id($input['college_id'] ?? '0') ?? 0;
    if (!isset($directory['campuses'][$campusId])) $campusId = 0;
    if (!isset($directory['colleges'][$collegeId]) || ($campusId > 0 && (int)$directory['colleges'][$collegeId]['campus_id'] !== $campusId)) $collegeId = 0;
    $status = is_string($input['status'] ?? null) && isset(synk_faculty_record_statuses()[$input['status']]) ? $input['status'] : '';
    $connection = is_string($input['connection'] ?? null) && in_array($input['connection'], ['needs_review', 'no_account'], true) ? $input['connection'] : '';
    $query = is_string($input['q'] ?? null) ? substr(trim($input['q']), 0, 200) : '';
    return ['campus_id' => $campusId, 'college_id' => $collegeId, 'status' => $status, 'connection' => $connection, 'q' => $query];
}

function synk_di_filter_faculty_records(array $records, array $filters): array
{
    $lower = static function (string $value): string { return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value); };
    $query = $lower($filters['q']);
    return array_values(array_filter($records, static function (array $row) use ($filters, $query, $lower): bool {
        if ($filters['campus_id'] && !in_array($filters['campus_id'], $row['campus_ids'], true)) return false;
        if ($filters['college_id'] && !in_array($filters['college_id'], $row['college_ids'], true)) return false;
        if ($filters['status'] !== '' && $row['status'] !== $filters['status']) return false;
        if ($filters['connection'] === 'needs_review' && $row['connection_complete']) return false;
        if ($filters['connection'] === 'no_account' && $row['account']) return false;
        $text = implode(' ', [$row['name'], $row['faculty_id_number'], $row['account']['email'] ?? '', $row['campus_name'], $row['college_name'], $row['designation_name'], $row['remarks'] ?? '']);
        return $query === '' || strpos($lower($text), $query) !== false;
    }));
}

// Audit every account, including inactive accounts, without changing access scopes.
function synk_di_account_connections(mysqli $conn, array $directory): array
{
    $accounts = $directory['accounts'];
    $roles = [];
    if (synk_useraccount_role_table_exists($conn)) {
        $result = $conn->query('SELECT user_id, role, status FROM tbl_useraccount_roles');
        while ($row = $result->fetch_assoc()) $roles[(int)$row['user_id']][] = $row;
        $result->close();
    }
    $collegeAccess = [];
    if (synk_table_exists($conn, 'tbl_user_college_access')) {
        $result = $conn->query("SELECT user_id, college_id FROM tbl_user_college_access WHERE status = 'active'");
        while ($row = $result->fetch_assoc()) $collegeAccess[(int)$row['user_id']][] = (int)$row['college_id'];
        $result->close();
    }
    $registrarAccess = [];
    if (synk_table_exists($conn, 'tbl_useraccount_registrar_scopes')) {
        $result = $conn->query("SELECT user_id, campus_id FROM tbl_useraccount_registrar_scopes WHERE status = 'active'");
        while ($row = $result->fetch_assoc()) $registrarAccess[(int)$row['user_id']][] = (int)$row['campus_id'];
        $result->close();
    }
    $facultyByUser = array_flip($directory['links']);
    $rows = [];
    foreach ($accounts as $userId => $account) {
        $roleRows = $roles[$userId] ?? [['role' => $account['role'], 'status' => 'active']];
        $roleNames = array_values(array_unique(array_column($roleRows, 'role')));
        $issues = [];
        $collegeIds = [];
        $campusIds = [];
        $validCollege = static function (int $id) use ($directory): bool {
            return isset($directory['colleges'][$id], $directory['campuses'][(int)($directory['colleges'][$id]['campus_id'] ?? 0)])
                && $directory['colleges'][$id]['status'] === 'active'
                && $directory['campuses'][(int)$directory['colleges'][$id]['campus_id']]['status'] === 'active';
        };
        $directCollege = (int)$account['college_id'];
        if ($directCollege > 0 && !$validCollege($directCollege)) $issues[] = 'Account college or campus is unavailable';
        foreach ($roleNames as $role) {
            if ($role === 'scheduler' || $role === 'program_chair') {
                $assigned = $role === 'scheduler' ? ($collegeAccess[$userId] ?? [$directCollege]) : [$directCollege];
                $valid = array_filter($assigned, $validCollege);
                if (!$valid) $issues[] = synk_role_label($role) . ' has no valid college / campus';
                elseif (count($valid) !== count($assigned)) $issues[] = 'Some ' . strtolower(synk_role_label($role)) . ' college / campus links are unavailable';
                foreach ($valid as $id) $collegeIds[$id] = $id;
            } elseif ($role === 'registrar') {
                $validRegistrarCampuses = [];
                foreach ($registrarAccess[$userId] ?? [] as $id) {
                    if (isset($directory['campuses'][$id]) && $directory['campuses'][$id]['status'] === 'active') $validRegistrarCampuses[$id] = $id;
                }
                if (!$validRegistrarCampuses) $issues[] = 'Registrar has no valid campus';
                elseif (count($validRegistrarCampuses) !== count($registrarAccess[$userId] ?? [])) $issues[] = 'Some registrar campus links are unavailable';
                $campusIds += $validRegistrarCampuses;
            } elseif ($role === 'professor') {
                $faculty = $directory['faculty'][$facultyByUser[$userId] ?? 0] ?? null;
                if (!$faculty) $issues[] = 'Professor has no faculty link';
                elseif (!$faculty['connection_complete']) $issues[] = 'Faculty college / campus is not recorded';
                if ($faculty) {
                    foreach ($faculty['college_ids'] as $id) $collegeIds[$id] = $id;
                    foreach ($faculty['campus_ids'] as $id) $campusIds[$id] = $id;
                }
            }
        }
        foreach ($collegeIds as $id) {
            $campusId = (int)$directory['colleges'][$id]['campus_id'];
            if (isset($directory['campuses'][$campusId])) $campusIds[$campusId] = $campusId;
        }
        $scope = [];
        foreach ($collegeIds as $id) {
            $college = $directory['colleges'][$id];
            $scope[] = $college['college_code'] . ' / ' . ($directory['campuses'][(int)$college['campus_id']]['campus_name'] ?? 'Campus unavailable');
        }
        if (!$scope) foreach ($campusIds as $id) $scope[] = $directory['campuses'][$id]['campus_name'];
        $scopedRole = (bool)array_intersect($roleNames, ['professor', 'scheduler', 'program_chair', 'registrar']);
        $rows[] = $account + ['roles' => $roleNames, 'scope' => $scope ? implode('; ', $scope) : ($scopedRole ? 'Not recorded' : 'Institution-wide'),
            'issues' => $issues, 'connection_status' => $issues ? 'needs_review' : ($scopedRole ? 'connected' : 'institution_wide'),
            'faculty_id' => (int)($facultyByUser[$userId] ?? 0)];
    }
    return $rows;
}
