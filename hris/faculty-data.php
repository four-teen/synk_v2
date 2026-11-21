<?php
require_once __DIR__ . '/../backend/faculty_record_profile_helper.php';
require_once __DIR__ . '/../backend/faculty_pds_preview_helper.php';

function synk_hris_saved_profiles(mysqli $conn): array
{
    $profiles = [];
    if (synk_faculty_record_profiles_ready($conn)) {
        $result = $conn->query('SELECT faculty_id, profile_data, revision, created_at, updated_at
            FROM tbl_faculty_record_profiles');
        while ($row = $result->fetch_assoc()) {
            $owner = synk_hr_pds_owner(0, (int)$row['faculty_id']);
            try {
                $profiles[$owner] = synk_faculty_record_profile_decode($row);
            } catch (RuntimeException $error) {
                error_log('Kawani could not decode a saved faculty profile.');
                $profiles[$owner] = [];
            }
        }
        $result->close();
    }

    if (synk_table_exists($conn, 'tbl_faculty_profiles')) {
        $idColumn = synk_table_has_column($conn, 'tbl_faculty_profiles', 'faculty_id_number')
            ? 'p.faculty_id_number' : "''";
        $entryDateColumn = synk_table_has_column($conn, 'tbl_faculty_profiles', 'service_start_date')
            ? 'p.service_start_date' : 'NULL';
        $serviceLengthColumn = synk_table_has_column($conn, 'tbl_faculty_profiles', 'length_of_service')
            ? 'p.length_of_service' : "''";
        $result = $conn->query("SELECT p.user_id, l.faculty_id, p.full_name, {$idColumn} AS faculty_id_number,
            p.campus_name, p.college_name, p.faculty_rank, p.employment_classification, p.work_status, p.revision,
            {$entryDateColumn} AS service_start_date, {$serviceLengthColumn} AS length_of_service,
            p.master_degree, p.master_status, p.doctoral_degree, p.doctoral_status
            FROM tbl_faculty_profiles p LEFT JOIN tbl_useraccount_faculty_links l ON l.user_id = p.user_id");
        while ($row = $result->fetch_assoc()) {
            // The Professor Portal prefers the account profile when both types exist.
            $profiles[synk_hr_pds_owner((int)$row['user_id'], (int)$row['faculty_id'])] = $row;
        }
        $result->close();
    }
    return $profiles;
}

function synk_hris_pds_drafts(mysqli $conn): array
{
    $sections = synk_hr_pds_sections();
    $columns = [];
    $joins = [];
    foreach ($sections as $section) {
        $table = synk_hr_pds_section_table($section);
        $alias = 'pds_' . $section;
        $columns[] = "{$alias}.updated_at AS `{$section}`";
        $columns[] = "{$alias}.revision AS `revision_{$section}`";
        $joins[] = "LEFT JOIN {$table} {$alias} ON {$alias}.hr_profile_id = h.hr_profile_id";
    }

    $result = $conn->query("SELECT h.hr_profile_id, h.faculty_id, h.account_user_id,
        COALESCE(h.account_user_id, linked.user_id) AS photo_user_id,
        COALESCE(h.faculty_id, l.faculty_id) AS owner_faculty_id,
        CONCAT_WS(' ', NULLIF(TRIM(f.first_name), ''), NULLIF(TRIM(f.middle_name), ''),
            NULLIF(TRIM(f.last_name), ''), NULLIF(TRIM(f.ext_name), '')) AS faculty_name,
        CASE WHEN TRIM(pds_personal.first_name) <> '' AND TRIM(pds_personal.surname) <> ''
            THEN CONCAT_WS(' ', NULLIF(TRIM(pds_personal.first_name), ''), NULLIF(TRIM(pds_personal.middle_name), ''),
                NULLIF(TRIM(pds_personal.surname), ''), NULLIF(TRIM(pds_personal.name_extension), ''))
        END AS pds_name,
        pds_contact.agency_employee_number, " . implode(', ', $columns) . '
        FROM tbl_hr_faculty_profiles h
        LEFT JOIN tbl_useraccount_faculty_links l ON l.user_id = h.account_user_id
        LEFT JOIN tbl_useraccount_faculty_links linked ON linked.faculty_id = h.faculty_id
        LEFT JOIN tbl_faculty f ON f.faculty_id = COALESCE(h.faculty_id, l.faculty_id)
        ' . implode("\n", $joins) . '
        ORDER BY h.faculty_id IS NULL, h.hr_profile_id');

    $drafts = [];
    $sectionKeys = array_fill_keys($sections, true);
    while ($row = $result->fetch_assoc()) {
        $facultyId = (int)$row['owner_faculty_id'];
        $userId = (int)$row['account_user_id'];
        $owner = $facultyId || $userId
            ? synk_hr_pds_owner($userId, $facultyId) : 'pds:' . (int)$row['hr_profile_id'];
        if (isset($drafts[$owner])) {
            // Keep the faculty-owned draft separate from any older account-owned draft.
            $drafts[$owner]['link_issue'] = 'Multiple PDS drafts need link review.';
            continue;
        }

        $savedSections = array_filter(array_intersect_key($row, $sectionKeys));
        $revisions = [];
        foreach (array_keys($savedSections) as $section) {
            $revisions[$section] = (int)$row['revision_' . $section];
        }
        $linkIssue = '';
        if ((int)$row['faculty_id'] === 0) {
            $linkIssue = $facultyId > 0 ? 'Draft is still stored under the account.' : 'PDS awaits a faculty link.';
        }
        $drafts[$owner] = [
            'name' => trim((string)$row['pds_name']),
            'owner_name' => trim((string)$row['faculty_name']),
            'photo_user_id' => (int)$row['photo_user_id'],
            'pds_id' => (int)$row['hr_profile_id'],
            'revisions' => $revisions,
            'employee_id' => trim((string)$row['agency_employee_number']),
            'saved_sections' => $savedSections,
            'last_saved' => $savedSections ? max($savedSections) : null,
            'link_issue' => $linkIssue,
        ];
    }
    $result->close();
    return $drafts;
}

function synk_hris_faculty_records(array $profiles, array $drafts, int $sectionCount): array
{
    $records = [];
    $employeeIdCounts = [];
    foreach (array_keys($profiles + $drafts) as $owner) {
        $profile = $profiles[$owner] ?? [];
        $pds = $drafts[$owner] ?? [];
        $pdsId = $pds['employee_id'] ?? '';
        $profileId = trim((string)($profile['faculty_id_number'] ?? ''));
        $employeeId = $pdsId !== '' ? $pdsId : $profileId;
        $idKey = 'id:' . strtolower($employeeId);
        if ($employeeId !== '') {
            $employeeIdCounts[$idKey] = ($employeeIdCounts[$idKey] ?? 0) + 1;
        }

        $name = $pds['name'] ?? '';
        $name = $name ?: trim((string)($profile['full_name'] ?? ''));
        $name = $name ?: ($pds['owner_name'] ?? '');
        $savedSections = $pds['saved_sections'] ?? [];
        $savedCount = count($savedSections);
        $status = 'not-started';
        if ($savedCount > 0) {
            $status = $savedCount === $sectionCount ? 'saved' : 'in-progress';
        }

        $records[] = [
            'owner' => $owner,
            'pds_id' => (int)($pds['pds_id'] ?? 0),
            'name' => $name ?: 'Name not recorded',
            'photo_user_id' => ($profile['_storage'] ?? '') === 'faculty' ? 0 : (int)($profile['user_id'] ?? $pds['photo_user_id'] ?? 0),
            'photo_faculty_id' => strpos($owner, 'faculty:') === 0 ? (int)substr($owner, strlen('faculty:')) : 0,
            'employee_id' => $employeeId,
            'profile_id_number' => $profileId,
            'id_source' => $pdsId !== '' ? 'PDS' : 'Professor Profile',
            'id_mismatch' => $pdsId !== '' && $profileId !== '' && strcasecmp($pdsId, $profileId) !== 0,
            'campus' => trim((string)($profile['campus_name'] ?? '')),
            'college' => trim((string)($profile['college_name'] ?? '')),
            'rank' => trim((string)($profile['faculty_rank'] ?? '')),
            'appointment' => trim((string)($profile['employment_classification'] ?? '')),
            'work_status' => trim((string)($profile['work_status'] ?? '')),
            'service_start_date' => trim((string)($profile['service_start_date'] ?? '')),
            'length_of_service' => trim((string)($profile['length_of_service'] ?? '')),
            'has_profile' => isset($profiles[$owner]),
            'saved_sections' => $savedSections,
            'last_saved' => $pds['last_saved'] ?? null,
            'pds_status' => $status,
            'link_issue' => $pds['link_issue'] ?? '',
            'data_version' => hash('sha256', serialize([
                $owner, $profile['_storage'] ?? '', (int)($profile['user_id'] ?? 0), (int)($profile['revision'] ?? 0),
                $pds['pds_id'] ?? 0, $pds['revisions'] ?? [], $pds['link_issue'] ?? '',
            ])),
        ];
    }

    foreach ($records as &$record) {
        $idKey = 'id:' . strtolower($record['employee_id']);
        $record['duplicate_id'] = ($employeeIdCounts[$idKey] ?? 0) > 1;
    }
    unset($record);
    usort($records, static function (array $left, array $right): int {
        return strnatcasecmp($left['name'], $right['name']) ?: strnatcasecmp($left['employee_id'], $right['employee_id']);
    });
    return $records;
}

function synk_hris_completed_additional_degrees(mysqli $conn): array
{
    if (!synk_table_exists($conn, 'tbl_faculty_profile_additional_degrees')) return [];

    $completed = [];
    $result = $conn->query('SELECT owner_type, owner_id, degrees_data FROM tbl_faculty_profile_additional_degrees');
    while ($row = $result->fetch_assoc()) {
        $owner = $row['owner_type'] . ':' . $row['owner_id'];
        $degrees = json_decode($row['degrees_data'], true);
        if (!is_array($degrees)) continue;

        foreach ($degrees as $degree) {
            if (!is_array($degree) || !in_array($degree['level'] ?? '', ['master', 'doctoral'], true)) continue;
            if (($degree['status'] ?? '') === 'Completed' && is_string($degree['degree'] ?? null) && trim($degree['degree']) !== '') {
                $completed[$owner][$degree['level']] = true;
            }
        }
    }
    $result->close();
    return $completed;
}

function synk_hris_faculty_sources(mysqli $conn, bool $includeDegrees = false): array
{
    $sources = [];
    try {
        $conn->begin_transaction(MYSQLI_TRANS_START_READ_ONLY | MYSQLI_TRANS_START_WITH_CONSISTENT_SNAPSHOT);
        $readers = ['profiles' => 'synk_hris_saved_profiles', 'pds' => 'synk_hris_pds_drafts'];
        if ($includeDegrees) $readers['degrees'] = 'synk_hris_completed_additional_degrees';
        foreach ($readers as $source => $read) {
            try {
                $sources[$source] = $read($conn);
            } catch (mysqli_sql_exception $error) {
                error_log('Kawani could not load ' . $source . '. Database error ' . $error->getCode());
            }
        }
        $conn->commit();
    } catch (mysqli_sql_exception $error) {
        $sources = [];
        error_log('Kawani could not read faculty records. Database error ' . $error->getCode());
    }
    return $sources;
}

function synk_hris_rank_aliases(): array
{
    static $aliases = [];
    if ($aliases) return $aliases;

    $levels = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI'];
    foreach (['Instructor' => 3, 'Assistant Professor' => 4, 'Associate Professor' => 5, 'Professor' => 6] as $rank => $lastLevel) {
        for ($level = 1; $level <= $lastLevel; $level++) {
            $label = $rank . ' ' . $levels[$level];
            $aliases[strtolower($label)] = $label;
            $aliases[strtolower($rank) . ' ' . $level] = $label;
        }
    }
    return $aliases;
}

function synk_hris_rank_label(string $value): ?string
{
    $key = strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));
    return synk_hris_rank_aliases()[$key] ?? null;
}

function synk_hris_rank_counts(array $profiles, array $additionalDegrees): array
{
    $counts = array_fill_keys(synk_hris_rank_aliases(), ['faculty' => 0, 'master' => 0, 'doctoral' => 0]);

    foreach ($profiles as $owner => $profile) {
        $label = synk_hris_rank_label((string)($profile['faculty_rank'] ?? ''));
        if ($label === null) continue;

        $degreeOwner = ($profile['_storage'] ?? '') !== 'faculty' && !empty($profile['user_id'])
            ? 'account:' . (int)$profile['user_id'] : $owner;
        $counts[$label]['faculty']++;
        foreach (['master', 'doctoral'] as $level) {
            $primaryCompleted = ($profile[$level . '_status'] ?? '') === 'Completed'
                && trim((string)($profile[$level . '_degree'] ?? '')) !== '';
            if ($primaryCompleted || !empty($additionalDegrees[$degreeOwner][$level])) {
                $counts[$label][$level]++;
            }
        }
    }
    return $counts;
}

function synk_hris_employment_counts(array $profiles): array
{
    require_once __DIR__ . '/../backend/faculty_profile_helper.php';
    $options = synk_faculty_profile_fields()['employment_classification']['options'];
    $counts = array_fill_keys(array_merge($options, ['Other', 'Not recorded']), 0);
    $labels = array_combine(array_map('strtolower', $options), $options);
    $labels['cos'] = 'Contract of Service';

    foreach ($profiles as $profile) {
        $stored = (string)($profile['employment_classification'] ?? '');
        $classification = strtolower(trim(preg_replace('/[\s_-]+/u', ' ', $stored) ?? $stored));
        $label = $classification === '' ? 'Not recorded' : ($labels[$classification] ?? 'Other');
        $counts[$label]++;
    }
    return $counts;
}
