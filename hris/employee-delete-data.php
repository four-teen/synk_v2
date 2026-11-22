<?php

require_once __DIR__ . '/../backend/db_lms.php';
require_once __DIR__ . '/../backend/faculty_profile_image_helper.php';

function synk_hris_delete_identifier(string $value): string
{
    return '`' . str_replace('`', '``', $value) . '`';
}

function synk_hris_delete_query(mysqli $conn, string $sql, string $types, array $params): array
{
    $stmt = $conn->prepare($sql);
    try {
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    } finally {
        $stmt->close();
    }
}

function synk_hris_delete_scope(array $keys, string $prefix = ''): array
{
    $predicates = [];
    $types = '';
    foreach ($keys as $column => $value) {
        $predicates[] = $prefix . synk_hris_delete_identifier($column) . ' <=> ?';
        $types .= is_int($value) ? 'i' : 's';
    }
    return [implode(' AND ', $predicates), $types, array_values($keys)];
}

function synk_hris_delete_tables(mysqli $conn): array
{
    $result = $conn->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'");
    $tables = array_column($result->fetch_all(MYSQLI_ASSOC), 'ENGINE', 'TABLE_NAME');
    $result->close();
    return $tables;
}

function synk_hris_delete_pds_titles(): array
{
    return [
        'tbl_hr_faculty_pds_personal' => 'PDS personal details',
        'tbl_hr_faculty_pds_contact' => 'PDS contact and ID details',
        'tbl_hr_faculty_pds_family' => 'PDS family details',
        'tbl_hr_faculty_pds_spouses' => 'PDS spouse details',
        'tbl_hr_faculty_pds_children' => 'PDS children',
        'tbl_hr_faculty_pds_education' => 'PDS education section',
        'tbl_hr_faculty_pds_education_records' => 'PDS education entries',
        'tbl_hr_faculty_pds_eligibility' => 'PDS eligibility section',
        'tbl_hr_faculty_pds_eligibilities_records' => 'PDS eligibility entries',
        'tbl_hr_faculty_pds_work' => 'PDS work history section',
        'tbl_hr_faculty_pds_work_records' => 'PDS work history entries',
        'tbl_hr_faculty_pds_learning' => 'PDS learning and development section',
        'tbl_hr_faculty_pds_trainings_records' => 'PDS training entries',
        'tbl_hr_faculty_pds_voluntary' => 'PDS voluntary work section',
        'tbl_hr_faculty_pds_voluntary_records' => 'PDS voluntary work entries',
        'tbl_hr_faculty_pds_other' => 'PDS other details section',
        'tbl_hr_faculty_pds_skills_records' => 'PDS skills',
        'tbl_hr_faculty_pds_recognition_records' => 'PDS recognitions',
        'tbl_hr_faculty_pds_memberships_records' => 'PDS memberships',
        'tbl_hr_faculty_pds_references_records' => 'PDS references',
        'tbl_hr_faculty_pds_declarations' => 'PDS declarations',
        'tbl_hr_faculty_pds_certification' => 'PDS certification',
        'tbl_hr_faculty_pds_attachments' => 'PDS attachments',
    ];
}

function synk_hris_delete_group(mysqli $conn, array $tables, string $table, array $keys, string $rowLock): array
{
    if (!isset($tables[$table])) return [];

    [$predicate, $types, $params] = synk_hris_delete_scope($keys);
    // Fingerprint the attachment payload in SQL without loading it into PHP or the review.
    $fields = $table === 'tbl_hr_faculty_pds_attachments'
        ? 'hr_profile_id, slot, original_name, mime_type, byte_size, sha256, updated_by, updated_at, SHA2(content, 256) AS content_hash'
        : '*';
    $rows = synk_hris_delete_query($conn, 'SELECT ' . $fields . ' FROM ' . synk_hris_delete_identifier($table)
        . ' WHERE ' . $predicate . $rowLock, $types, $params);
    if (!$rows) return [];
    if (strcasecmp($tables[$table], 'InnoDB') !== 0) {
        throw new DomainException('Kawani could not remove the employee details in one transaction. The record was kept.', 503);
    }
    return ['keys' => $keys, 'rows' => $rows];
}

function synk_hris_delete_protected_label(string $table): string
{
    if (strpos($table, 'tbl_lms_') === 0) return 'LMS records';
    if (strpos($table, 'workload') !== false || strpos($table, 'schedule') !== false) return 'Scheduling or workload records';
    return [
        'tbl_useraccount_faculty_links' => 'Synk sign-in link',
        'tbl_college_faculty' => 'College roster',
        'tbl_faculty_college_appointments' => 'College appointments',
        'tbl_faculty_term_designations' => 'Faculty designations',
    ][$table] ?? 'Linked records';
}

// Synk also stores employee references in columns without foreign keys.
function synk_hris_employee_logical_references(mysqli $conn, array $tables, array $groups, int $facultyId,
    array $employeeNumbers, int $hrProfileId, int $accountId, bool $core, bool $lock): array
{
    $owner = 'faculty:' . $facultyId;
    $facultyColumns = ['faculty_id', 'evaluator_faculty_id', 'reviewer_faculty_id', 'supervisor_faculty_id',
        'instructor_id', 'professor_id', 'teacher_id'];
    $numberColumns = ['employee_id', 'employee_number', 'employee_no', 'agency_employee_number', 'faculty_id_number'];
    $columns = array_merge($facultyColumns, $numberColumns,
        ['record_key', 'owner_id', 'owner_type', 'details_json', 'hr_profile_id', 'created_by', 'updated_by', 'updated_by_hr_account']);
    $result = $conn->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('" . implode("','", $columns) . "')
        ORDER BY TABLE_NAME, ORDINAL_POSITION");
    $byTable = [];
    foreach ($result as $row) {
        if (isset($tables[$row['TABLE_NAME']])) $byTable[$row['TABLE_NAME']][] = $row['COLUMN_NAME'];
    }
    $result->close();

    $references = [];
    foreach ($byTable as $table => $tableColumns) {
        $keys = $groups[$table]['keys'] ?? [];
        $checks = $params = [];
        $types = '';
        $auditTable = strpos($table, 'tbl_hr_') === 0 || strpos($table, 'tbl_faculty_profile') === 0
            || $table === 'tbl_faculty_record_profiles';
        foreach ($tableColumns as $column) {
            if (isset($keys[$column])) continue;
            $field = synk_hris_delete_identifier($column);
            if (in_array($column, $facultyColumns, true)) {
                $checks[] = $field . ' = ?';
                $params[] = $facultyId;
                $types .= 'i';
            } elseif ($column === 'record_key') {
                $checks[] = $field . ' = ?';
                $params[] = $owner;
                $types .= 's';
            } elseif ($core && $column === 'hr_profile_id' && $hrProfileId > 0) {
                $checks[] = $field . ' = ?';
                $params[] = $hrProfileId;
                $types .= 'i';
            } elseif ($core && $column === 'updated_by_hr_account' && $accountId > 0) {
                $checks[] = $field . ' = ?';
                $params[] = $accountId;
                $types .= 'i';
            } elseif ($core && $accountId > 0 && $auditTable && in_array($column, ['created_by', 'updated_by'], true)) {
                $checks[] = $field . ' = ?';
                $params[] = -$accountId;
                $types .= 'i';
            } elseif (in_array($column, $numberColumns, true)) {
                foreach ($employeeNumbers as $number) {
                    $checks[] = 'LOWER(TRIM(' . $field . ')) = LOWER(?)';
                    $params[] = $number;
                    $types .= 's';
                }
            }
        }
        if (!isset($keys['owner_type']) && in_array('owner_type', $tableColumns, true) && in_array('owner_id', $tableColumns, true)) {
            $checks[] = "(owner_type = 'faculty' AND owner_id = ?)";
            $params[] = $facultyId;
            $types .= 'i';
        }
        if ($core && $table === 'tbl_scheduler_workload_audit_logs' && in_array('details_json', $tableColumns, true)) {
            $checks[] = "JSON_CONTAINS(CASE WHEN JSON_VALID(details_json) THEN details_json ELSE '{}' END, ?, '$.faculty_ids') = 1";
            $params[] = (string)$facultyId;
            $types .= 's';
        }
        if (!$checks) continue;

        $where = '(' . implode(' OR ', $checks) . ')';
        if ($keys) {
            [$scope, $scopeTypes, $scopeParams] = synk_hris_delete_scope($keys);
            $where .= ' AND NOT (' . $scope . ')';
            $types .= $scopeTypes;
            $params = array_merge($params, $scopeParams);
        }
        $sql = 'SELECT 1 FROM ' . synk_hris_delete_identifier($table) . ' WHERE ' . $where . ' LIMIT 1'
            . ($lock ? ' LOCK IN SHARE MODE' : '');
        if (synk_hris_delete_query($conn, $sql, $types, $params)) {
            $references[$table] = ['title' => synk_hris_delete_protected_label($table), 'table' => $table];
        }
    }
    return $references;
}

// Find references outside the planned rows, including foreign keys that cascade across tables or schemas.
function synk_hris_employee_foreign_references(mysqli $conn, array $groups, bool $lock): array
{
    $database = (string)$conn->query('SELECT DATABASE()')->fetch_row()[0];
    $result = $conn->query("SELECT TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_SCHEMA, TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION");
    $constraints = [];
    foreach ($result as $row) {
        if (!isset($groups[$row['REFERENCED_TABLE_NAME']])) continue;
        $key = $row['TABLE_SCHEMA'] . '.' . $row['TABLE_NAME'] . '.' . $row['CONSTRAINT_NAME'];
        $constraints[$key][] = $row;
    }
    $result->close();

    $children = $references = [];
    foreach ($constraints as $columns) {
        $reference = $columns[0];
        $table = $reference['TABLE_NAME'];
        $parent = $reference['REFERENCED_TABLE_NAME'];
        $schema = $reference['TABLE_SCHEMA'];
        $childTable = synk_hris_delete_identifier($schema) . '.' . synk_hris_delete_identifier($table);
        $parentTable = synk_hris_delete_identifier($database) . '.' . synk_hris_delete_identifier($parent);
        $on = [];
        foreach ($columns as $column) {
            $on[] = 'child.' . synk_hris_delete_identifier($column['COLUMN_NAME'])
                . ' = parent.' . synk_hris_delete_identifier($column['REFERENCED_COLUMN_NAME']);
        }
        [$where, $types, $params] = synk_hris_delete_scope($groups[$parent]['keys'], 'parent.');
        if ($schema === $database && isset($groups[$table])) {
            $children[$parent][$table] = true;
            [$scope, $scopeTypes, $scopeParams] = synk_hris_delete_scope($groups[$table]['keys'], 'child.');
            $where .= ' AND NOT (' . $scope . ')';
            $types .= $scopeTypes;
            $params = array_merge($params, $scopeParams);
        }
        $sql = "SELECT 1 FROM {$childTable} child JOIN {$parentTable} parent ON " . implode(' AND ', $on)
            . ' WHERE ' . $where . ' LIMIT 1' . ($lock ? ' LOCK IN SHARE MODE' : '');
        if (synk_hris_delete_query($conn, $sql, $types, $params)) {
            $key = $schema === $database ? $table : $schema . '.' . $table;
            $references[$key] = ['title' => synk_hris_delete_protected_label($table), 'table' => $key];
        }
    }
    return [$references, $children];
}

function synk_hris_employee_deletion_order(array $groups, array $children): array
{
    $order = [];
    $children['tbl_faculty'] = ($children['tbl_faculty'] ?? []) + array_diff_key($groups, ['tbl_faculty' => true]);
    while ($groups) {
        $ready = array_filter($groups, static function (string $table) use ($groups, $children): bool {
            return !array_intersect_key($children[$table] ?? [], $groups);
        }, ARRAY_FILTER_USE_KEY);
        if (!$ready) {
            throw new DomainException('Kawani could not safely remove these linked employee records. The record was kept.', 409);
        }
        $order = array_merge($order, array_keys($ready));
        $groups = array_diff_key($groups, $ready);
    }
    return $order;
}

function synk_hris_employee_deletion_preview(array $groups, array $profileData): array
{
    $titles = [
        'tbl_faculty' => 'Employee identity',
        'tbl_faculty_record_profiles' => 'Employee profile',
        'tbl_hr_personnel_details' => 'Personnel details',
        'tbl_hr_faculty_statuses' => 'Employment status',
        'tbl_hr_faculty_reviews' => 'HR review',
        'tbl_hr_employee_accounts' => 'Kawani account and official email',
        'tbl_hr_user_roles' => 'Previous Kawani role assignments',
        'tbl_faculty_profile_additional_degrees' => 'Additional degrees',
        'tbl_faculty_profile_researches' => 'Research entries',
        'tbl_faculty_profile_trainings' => 'Training entries',
        'tbl_hr_faculty_profiles' => 'Personal Data Sheet',
    ] + synk_hris_delete_pds_titles();
    $visible = [
        'tbl_faculty' => ['faculty_id' => 'Synk faculty ID', 'first_name' => 'First name', 'middle_name' => 'Middle name',
            'last_name' => 'Last name', 'ext_name' => 'Name extension'],
        'tbl_faculty_record_profiles' => ['faculty_id_number' => 'Employee ID', 'faculty_rank' => 'Academic rank',
            'employment_classification' => 'Employment classification'],
        'tbl_hr_personnel_details' => ['personnel_category' => 'Personnel category', 'office_unit' => 'Office or unit',
            'position_id' => 'Position ID', 'plantilla_item_number' => 'Plantilla item number'],
        'tbl_hr_faculty_statuses' => ['status' => 'Status'],
        'tbl_hr_faculty_reviews' => ['review_status' => 'Review status'],
        'tbl_hr_employee_accounts' => ['email' => 'Official SKSU email', 'role_code' => 'Kawani role'],
    ];
    $displayValues = ['hris_admin' => 'Administrator', 'hris_user' => 'User', 'non_teaching' => 'Non-teaching'];
    $records = [];
    foreach ($titles as $table => $title) {
        if (!isset($groups[$table])) continue;
        $rows = $groups[$table]['rows'];
        $first = $table === 'tbl_faculty_record_profiles' ? $profileData : $rows[0];
        $fields = [];
        foreach ($visible[$table] ?? [] as $name => $label) {
            $value = $first[$name] ?? null;
            if (!is_scalar($value) || $value === '') continue;
            if (in_array($name, ['personnel_category', 'status', 'review_status', 'role_code'], true)) {
                $value = $displayValues[$value] ?? ucfirst((string)$value);
            }
            $fields[] = ['label' => $label, 'value' => (string)$value];
        }
        $records[] = ['title' => $title, 'table' => $table, 'count' => count($rows), 'fields' => $fields];
    }
    return $records;
}

// Without a reviewed fingerprint, the transaction can only return a preview.
function synk_hris_employee_deletion(mysqli $conn, int $facultyId, array $actor, ?string $reviewedFingerprint = null): array
{
    $deleting = $reviewedFingerprint !== null;
    $rowLock = $deleting ? ' FOR UPDATE' : '';
    $lms = null;
    if (is_file(__DIR__ . '/../backend/db_config.local_lms.php')) {
        try {
            $lms = synk_database_connection_lms($conn);
        } catch (Throwable $error) {
            throw new DomainException('Kawani could not check the LMS database. The record was kept.', 503, $error);
        }
    }

    $conn->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $conn->begin_transaction();
    try {
        $tables = synk_hris_delete_tables($conn);
        $identity = synk_hris_delete_group($conn, $tables, 'tbl_faculty', ['faculty_id' => $facultyId], $rowLock);
        if (!$identity) throw new DomainException('This employee record is no longer available.', 404);
        $actorAccountId = (int)$actor['account_id'];
        if ($deleting && isset($tables['tbl_hr_employee_accounts'])) {
            // Match the lock order used when assigning or removing administrator access.
            $result = $conn->query("SELECT account_id, revision FROM tbl_hr_employee_accounts
                WHERE role_code = 'hris_admin' ORDER BY account_id FOR UPDATE");
            $administrators = array_column($result->fetch_all(MYSQLI_ASSOC), 'revision', 'account_id');
            $result->close();
            if (!isset($administrators[$actorAccountId]) || (int)$administrators[$actorAccountId] !== (int)$actor['revision']) {
                throw new DomainException('Your Kawani administrator access changed. Sign in again.', 403);
            }
        } elseif ($deleting) {
            $grant = synk_hris_delete_query($conn, "SELECT user_id FROM tbl_hr_user_roles
                WHERE user_id = ? AND role_code = 'hris_admin' AND status = 'active' LOCK IN SHARE MODE", 'i', [(int)$actor['user_id']]);
            if (!$grant) throw new DomainException('Your Kawani administrator access changed. Sign in again.', 403);
        }

        $owner = 'faculty:' . $facultyId;
        $scopes = [
            'tbl_faculty_record_profiles' => ['faculty_id' => $facultyId],
            'tbl_hr_personnel_details' => ['record_key' => $owner],
            'tbl_hr_faculty_statuses' => ['record_key' => $owner],
            'tbl_hr_faculty_reviews' => ['record_key' => $owner],
            'tbl_hr_employee_accounts' => ['faculty_id' => $facultyId],
            'tbl_faculty_profile_additional_degrees' => ['owner_type' => 'faculty', 'owner_id' => $facultyId],
            'tbl_faculty_profile_researches' => ['owner_type' => 'faculty', 'owner_id' => $facultyId],
            'tbl_faculty_profile_trainings' => ['owner_type' => 'faculty', 'owner_id' => $facultyId],
            'tbl_hr_faculty_profiles' => ['faculty_id' => $facultyId],
        ];
        $groups = ['tbl_faculty' => $identity];
        foreach ($scopes as $table => $keys) {
            $group = synk_hris_delete_group($conn, $tables, $table, $keys, $rowLock);
            if ($group) $groups[$table] = $group;
        }

        $account = $groups['tbl_hr_employee_accounts']['rows'][0] ?? [];
        $accountId = (int)($account['account_id'] ?? 0);
        $legacyUserId = (int)($account['legacy_user_id'] ?? 0);
        if ($legacyUserId > 0) {
            $roles = synk_hris_delete_group($conn, $tables, 'tbl_hr_user_roles', ['user_id' => $legacyUserId], $rowLock);
            if ($roles) $groups['tbl_hr_user_roles'] = $roles;
        }

        $pdsProfile = $groups['tbl_hr_faculty_profiles']['rows'][0] ?? [];
        $hrProfileId = (int)($pdsProfile['hr_profile_id'] ?? 0);
        if ($hrProfileId > 0) {
            foreach (array_keys(synk_hris_delete_pds_titles()) as $table) {
                $group = synk_hris_delete_group($conn, $tables, $table, ['hr_profile_id' => $hrProfileId], $rowLock);
                if ($group) $groups[$table] = $group;
            }
        }

        $profileData = json_decode($groups['tbl_faculty_record_profiles']['rows'][0]['profile_data'] ?? '{}', true);
        if (!is_array($profileData)) {
            throw new DomainException('Kawani could not verify the employee profile. The record was kept.', 503);
        }
        $employeeNumbers = array_unique(array_filter([
            trim((string)($identity['rows'][0]['faculty_id_number'] ?? '')),
            trim((string)($profileData['faculty_id_number'] ?? '')),
            trim((string)($groups['tbl_hr_faculty_pds_contact']['rows'][0]['agency_employee_number'] ?? '')),
        ], 'strlen'));
        $protected = synk_hris_employee_logical_references($conn, $tables, $groups, $facultyId,
            $employeeNumbers, $hrProfileId, $accountId, true, $deleting);
        [$foreignReferences, $children] = synk_hris_employee_foreign_references($conn, $groups, $deleting);
        $protected += $foreignReferences;
        if ($accountId > 0 && $accountId === $actorAccountId) {
            $protected['own login'] = ['title' => 'Your own Kawani administrator account', 'table' => 'tbl_hr_employee_accounts'];
        }
        if (is_file(synk_faculty_record_image_path($facultyId))) {
            $protected['profile photo'] = ['title' => 'Faculty profile photo saved outside the database', 'table' => ''];
        }
        if ($lms) {
            try {
                $lms->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                $lms->begin_transaction();
                $protected += synk_hris_employee_logical_references($lms, synk_hris_delete_tables($lms), [],
                    $facultyId, $employeeNumbers, 0, 0, false, $deleting);
            } catch (Throwable $error) {
                throw new DomainException('Kawani could not check the LMS database. The record was kept.', 503, $error);
            }
        }

        $snapshot = [];
        foreach ($groups as $table => $group) {
            $rowHashes = array_map(static function (array $row): string {
                return hash('sha256', serialize($row));
            }, $group['rows']);
            sort($rowHashes, SORT_STRING);
            $snapshot[$table] = [$group['keys'], $rowHashes];
        }
        $fingerprint = hash('sha256', serialize($snapshot));
        if (!$deleting) {
            $conn->rollback();
            return [
                'fingerprint' => $fingerprint,
                'records' => synk_hris_employee_deletion_preview($groups, $profileData),
                'protected' => array_values($protected),
            ];
        }
        if ($protected || !hash_equals($fingerprint, $reviewedFingerprint)) {
            throw new DomainException('The linked records changed after the review. Review them again before deleting.', 409);
        }

        foreach (synk_hris_employee_deletion_order($groups, $children) as $table) {
            [$predicate, $types, $params] = synk_hris_delete_scope($groups[$table]['keys']);
            $stmt = $conn->prepare('DELETE FROM ' . synk_hris_delete_identifier($table) . ' WHERE ' . $predicate);
            try {
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                if ($stmt->affected_rows !== count($groups[$table]['rows'])) {
                    throw new DomainException('The employee records changed. Review them again before deleting.', 409);
                }
            } finally {
                $stmt->close();
            }
        }
        $conn->commit();
        return [];
    } catch (Throwable $error) {
        $conn->rollback();
        if ($error instanceof mysqli_sql_exception && (int)$error->getCode() === 1451) {
            throw new DomainException('The linked records changed after the review. Review them again before deleting.', 409, $error);
        }
        throw $error;
    } finally {
        if ($lms) {
            try {
                $lms->rollback();
            } catch (mysqli_sql_exception $error) {
                error_log('Kawani LMS dependency check could not be closed. Database error ' . $error->getCode());
            }
        }
    }
}
