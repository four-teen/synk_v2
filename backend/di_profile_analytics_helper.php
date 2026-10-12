<?php

require_once __DIR__ . '/schema_helper.php';
require_once __DIR__ . '/auth_useraccount.php';
require_once __DIR__ . '/faculty_profile_helper.php';
require_once __DIR__ . '/faculty_directory_helper.php';

function synk_di_profile_summary(array $accounts): array
{
    $summary = [
        'accounts' => count($accounts), 'saved' => 0, 'qualified_25' => 0, 'started_below_25' => 0,
        'qualified_in_progress' => 0, 'complete' => 0, 'in_progress' => 0,
        'not_started' => 0, 'unavailable' => 0, 'linked' => 0, 'with_eligibility' => 0,
        'average_completion' => 0, 'coverage_percent' => 0,
        'employment' => [], 'ranks' => [], 'education' => [], 'gender' => [], 'eligibilities' => [],
        'sections' => ['personal' => ['completed' => 0, 'total' => 0], 'employment' => ['completed' => 0, 'total' => 0], 'education' => ['completed' => 0, 'total' => 0]],
    ];
    $completionSum = 0;
    foreach ($accounts as $account) {
        $summary[$account['profile_status']]++;
        $summary['linked'] += $account['faculty_id'] > 0 ? 1 : 0;
        if (!$account['has_profile']) {
            continue;
        }
        $summary['saved']++;
        $percent = (int)$account['completion']['percent'];
        if ($percent < 25) {
            $summary['started_below_25']++;
            continue;
        }
        $summary['qualified_25']++;
        $completionSum += $percent;
        if ($percent < 100) $summary['qualified_in_progress']++;
        foreach ($account['completion']['sections'] as $section => $counts) {
            $summary['sections'][$section]['completed'] += $counts['completed'];
            $summary['sections'][$section]['total'] += $counts['total'];
        }
        foreach (['employment' => 'employment_classification', 'ranks' => 'faculty_rank', 'gender' => 'gender'] as $group => $field) {
            $label = $account['analytics_values'][$field] ?: 'Not recorded';
            $summary[$group][$label] = ($summary[$group][$label] ?? 0) + 1;
        }
        $degree = $account['highest_degree'];
        $summary['education'][$degree] = ($summary['education'][$degree] ?? 0) + 1;
        if ($account['eligibility_labels'] || $account['has_other_eligibility']) {
            $summary['with_eligibility']++;
        }
        foreach (array_unique($account['eligibility_labels']) as $label) {
            $summary['eligibilities'][$label] = ($summary['eligibilities'][$label] ?? 0) + 1;
        }
    }
    if ($summary['qualified_25'] > 0) {
        $summary['average_completion'] = (int)round($completionSum / $summary['qualified_25']);
    }
    if ($summary['accounts'] > 0) {
        $summary['coverage_percent'] = (int)round($summary['qualified_25'] * 100 / $summary['accounts']);
    }
    foreach (['employment', 'ranks', 'education', 'gender', 'eligibilities'] as $group) {
        arsort($summary[$group], SORT_NUMERIC);
    }
    return $summary;
}

function synk_di_faculty_profile_completion_by_faculty(mysqli $conn, array $directory): array
{
    if (!synk_table_exists($conn, 'tbl_faculty_profiles')) return [];

    $ranks = synk_table_exists($conn, 'tbl_faculty_ranks') ? synk_faculty_profile_ranks($conn) : [];
    $eligibilities = synk_table_exists($conn, 'tbl_faculty_eligibilities')
        ? synk_faculty_profile_eligibility_catalog($conn) : [];
    $locations = synk_faculty_profile_locations($conn);
    $eligibilityIds = [];
    if (synk_table_exists($conn, 'tbl_faculty_profile_eligibilities')) {
        $result = $conn->query('SELECT user_id, eligibility_id FROM tbl_faculty_profile_eligibilities');
        while ($row = $result->fetch_assoc()) {
            $eligibilityIds[(int)$row['user_id']][] = (int)$row['eligibility_id'];
        }
        $result->close();
    }

    $today = new DateTimeImmutable('today');
    $completionByFaculty = [];
    foreach ($directory['faculty'] as $facultyId => $faculty) {
        $userId = (int)($faculty['user_id'] ?? 0);
        $profile = $faculty['profile'] ?? null;
        if (!is_array($profile)) continue;

        if (($profile['_storage'] ?? '') !== 'faculty') $profile['eligibility_ids'] = $eligibilityIds[$userId] ?? [];
        $fields = synk_faculty_profile_fields($ranks, $profile, $locations);
        [$values, $errors] = synk_faculty_profile_validate($profile, $today, $ranks, $profile, $eligibilities, $locations);
        $completion = synk_faculty_profile_completion($values, synk_faculty_profile_completion_rules($fields), $errors);
        $completionByFaculty[(int)$facultyId] = $completion['percent'];
    }

    return $completionByFaculty;
}

function synk_di_profiled_faculty_records(mysqli $conn, array $directory): array
{
    $completion = synk_di_faculty_profile_completion_by_faculty($conn, $directory);
    $records = [];
    foreach ($directory['faculty'] as $facultyId => $record) {
        $percent = $completion[$facultyId] ?? null;
        if ($percent === null || $percent < 25) continue;
        $record['profile_completion_percent'] = $percent;
        $records[$facultyId] = $record;
    }
    return $records;
}

function synk_di_highest_completed_degree(array $values, array $errors): string
{
    foreach (['doctoral' => 'Doctoral', 'master' => "Master's", 'bachelor' => "Bachelor's"] as $level => $label) {
        if (($values[$level . '_status'] ?? '') === 'Completed'
            && trim((string)($values[$level . '_degree'] ?? '')) !== ''
            && !isset($errors[$level . '_status']) && !isset($errors[$level . '_degree'])) {
            return $label;
        }
    }
    return 'Not recorded';
}

function synk_di_profile_dashboard(mysqli $conn, array $term, int $campusId = 0, int $collegeId = 0): array
{
    // This dashboard reads existing professor profiles; it never initializes or saves profile storage.
    $campuses = [];
    $colleges = [];
    $result = $conn->query("SELECT campus_id, campus_name, campus_code FROM tbl_campus WHERE status = 'active' ORDER BY campus_name");
    while ($row = $result->fetch_assoc()) {
        $campuses[(int)$row['campus_id']] = $row;
    }
    $result->close();
    $result = $conn->query("SELECT college_id, campus_id, college_name, college_code FROM tbl_college WHERE status = 'active' ORDER BY college_name");
    while ($row = $result->fetch_assoc()) {
        $colleges[(int)$row['college_id']] = $row;
    }
    $result->close();
    $campusId = isset($campuses[$campusId]) ? $campusId : 0;
    $collegeId = isset($colleges[$collegeId]) && ($campusId === 0 || (int)$colleges[$collegeId]['campus_id'] === $campusId) ? $collegeId : 0;

    $faculty = [];
    $result = $conn->query("SELECT faculty_id, first_name, middle_name, last_name, ext_name, employment_classification FROM tbl_faculty WHERE status = 'active'");
    while ($row = $result->fetch_assoc()) {
        $faculty[(int)$row['faculty_id']] = $row;
    }
    $result->close();
    // Directory affiliation is independent of scheduling terms. The term argument
    // remains for existing callers; it never restricts faculty or professor accounts.
    $directory = synk_faculty_directory_records($conn);

    $profilesAvailable = synk_table_exists($conn, 'tbl_faculty_profiles');
    $profiles = [];
    if ($profilesAvailable) {
        $result = $conn->query('SELECT * FROM tbl_faculty_profiles');
        while ($profile = $result->fetch_assoc()) {
            $profiles[(int)$profile['user_id']] = $profile;
        }
        $result->close();
    }
    $ranks = synk_table_exists($conn, 'tbl_faculty_ranks') ? synk_faculty_profile_ranks($conn) : [];
    $eligibilities = synk_table_exists($conn, 'tbl_faculty_eligibilities') ? synk_faculty_profile_eligibility_catalog($conn) : [];
    $eligibilityIds = [];
    if (synk_table_exists($conn, 'tbl_faculty_profile_eligibilities')) {
        $result = $conn->query('SELECT user_id, eligibility_id FROM tbl_faculty_profile_eligibilities');
        while ($row = $result->fetch_assoc()) {
            $eligibilityIds[(int)$row['user_id']][] = (int)$row['eligibility_id'];
        }
        $result->close();
    }
    $locations = synk_faculty_profile_locations($conn);
    $roleFilter = "LOWER(TRIM(ua.role)) = 'professor'";
    if (synk_useraccount_role_table_exists($conn)) {
        $roleFilter = "EXISTS (SELECT 1 FROM tbl_useraccount_roles r WHERE r.user_id = ua.user_id AND r.role = 'professor' AND r.status = 'active')
            OR (LOWER(TRIM(ua.role)) = 'professor' AND NOT EXISTS (SELECT 1 FROM tbl_useraccount_roles r WHERE r.user_id = ua.user_id))";
    }
    $linkJoin = synk_table_exists($conn, 'tbl_useraccount_faculty_links')
        ? 'LEFT JOIN tbl_useraccount_faculty_links fl ON fl.user_id = ua.user_id'
        : 'LEFT JOIN (SELECT NULL AS user_id, NULL AS faculty_id) fl ON fl.user_id = ua.user_id';
    $result = $conn->query("SELECT ua.user_id, ua.username, ua.email, ua.college_id, COALESCE(fl.faculty_id, 0) AS faculty_id,
        COALESCE(c.college_name, '') AS college_name, COALESCE(cp.campus_name, '') AS campus_name
        FROM tbl_useraccount ua {$linkJoin}
        LEFT JOIN tbl_college c ON c.college_id = ua.college_id
        LEFT JOIN tbl_campus cp ON cp.campus_id = c.campus_id
        WHERE ua.status = 'active' AND ({$roleFilter}) ORDER BY ua.username, ua.user_id");
    $accounts = [];
    $today = new DateTimeImmutable('today');
    while ($row = $result->fetch_assoc()) {
        $userId = (int)$row['user_id'];
        $facultyId = (int)$row['faculty_id'];
        $stored = $profiles[$userId] ?? ($directory['faculty'][$facultyId]['profile'] ?? null);
        $facultyRow = $faculty[$facultyId] ?? null;
        $facultyName = $facultyRow ? trim(implode(' ', array_filter([$facultyRow['first_name'], $facultyRow['middle_name'], $facultyRow['last_name'], $facultyRow['ext_name']]))) : '';
        $defaults = synk_faculty_profile_defaults($row, $facultyRow ? ['faculty_name' => $facultyName] : null, (string)($facultyRow['employment_classification'] ?? ''));
        $profile = $stored ?? $defaults;
        if (($profile['_storage'] ?? '') !== 'faculty') $profile['eligibility_ids'] = $eligibilityIds[$userId] ?? [];
        $fields = synk_faculty_profile_fields($ranks, $stored ?? [], $locations);
        [$values, $errors] = synk_faculty_profile_validate($profile, $today, $ranks, $stored ?? [], $eligibilities, $locations);
        $completion = synk_faculty_profile_completion($values, synk_faculty_profile_completion_rules($fields), $errors);
        if (!$stored) {
            $completion['percent'] = 0;
            $completion['completed'] = 0;
            foreach ($completion['sections'] as &$section) { $section['completed'] = 0; }
            unset($section);
        }
        $directoryFaculty = $directory['faculty'][$facultyId] ?? null;
        $scopeCollegeIds = [];
        $profileCampus = trim((string)($profile['campus_name'] ?? ''));
        $profileCollege = trim((string)($profile['college_name'] ?? ''));
        foreach ($colleges as $id => $college) {
            $campus = $campuses[(int)$college['campus_id']] ?? null;
            if ($directoryFaculty && in_array($id, $directoryFaculty['college_ids'], true)) {
                $scopeCollegeIds[$id] = $id;
            } elseif (!$directoryFaculty && $campus && $profileCampus !== '' && strcasecmp($profileCampus, $campus['campus_name']) === 0
                && $profileCollege !== '' && strcasecmp($profileCollege, $college['college_name']) === 0) {
                $scopeCollegeIds[$id] = $id;
            }
        }
        if (!$directoryFaculty && !$scopeCollegeIds && $profileCampus === '' && $profileCollege === '') {
            $accountCollege = (int)$row['college_id'];
            if (isset($colleges[$accountCollege])) $scopeCollegeIds[$accountCollege] = $accountCollege;
        }
        $scopeCampusIds = [];
        foreach ($scopeCollegeIds as $id) { $scopeCampusIds[(int)$colleges[$id]['campus_id']] = (int)$colleges[$id]['campus_id']; }
        // A partial profile may record a campus before it records a college.
        foreach ($campuses as $id => $campus) {
            if (($directoryFaculty && in_array($id, $directoryFaculty['campus_ids'], true))
                || (!$directoryFaculty && $profileCampus !== '' && strcasecmp($profileCampus, $campus['campus_name']) === 0)) { $scopeCampusIds[$id] = $id; }
        }
        $displayCampus = $directoryFaculty ? ($directoryFaculty['campus_ids'] ? $directoryFaculty['campus_name'] : '') : $profileCampus;
        $displayCollege = $directoryFaculty ? ($directoryFaculty['college_ids'] ? $directoryFaculty['college_name'] : '') : $profileCollege;
        if ($displayCampus === '' && count($scopeCampusIds) === 1) { $displayCampus = $campuses[reset($scopeCampusIds)]['campus_name']; }
        if ($displayCollege === '' && count($scopeCollegeIds) === 1) { $displayCollege = $colleges[reset($scopeCollegeIds)]['college_name']; }
        $analyticsValues = [];
        foreach (['faculty_rank', 'employment_classification', 'gender'] as $key) {
            $analyticsValues[$key] = isset($errors[$key]) ? '' : (string)($values[$key] ?? '');
        }
        $labels = [];
        foreach ($values['eligibility_ids'] as $id) {
            if (isset($eligibilities[$id])) { $labels[] = $eligibilities[$id]['code'] . ' - ' . $eligibilities[$id]['name']; }
        }
        $accounts[] = [
            'user_id' => $userId, 'faculty_id' => $facultyId,
            'name' => trim((string)($profile['full_name'] ?? '')) ?: $row['username'], 'email' => $row['email'],
            'campus_name' => $displayCampus ?: (count($scopeCampusIds) > 1 ? 'Multiple campuses' : 'Not recorded'),
            'college_name' => $displayCollege ?: (count($scopeCollegeIds) > 1 ? 'Multiple colleges' : 'Not recorded'),
            'campus_ids' => array_values($scopeCampusIds), 'college_ids' => array_values($scopeCollegeIds),
            'has_profile' => $stored !== null,
            'profile_status' => !$profilesAvailable ? 'unavailable' : (!$stored ? 'not_started' : ($completion['percent'] === 100 ? 'complete' : 'in_progress')),
            'completion' => $completion, 'analytics_values' => $analyticsValues,
            'highest_degree' => $stored ? synk_di_highest_completed_degree($values, $errors) : 'Not recorded',
            'eligibility_labels' => $labels, 'has_other_eligibility' => trim((string)($values['eligibility'] ?? '')) !== '',
            'updated_at' => (string)($profile['updated_at'] ?? ''),
        ];
    }
    $result->close();
    $scopedAccounts = array_values(array_filter($accounts, static function (array $account) use ($campusId, $collegeId): bool {
        return ($campusId === 0 || in_array($campusId, $account['campus_ids'], true))
            && ($collegeId === 0 || in_array($collegeId, $account['college_ids'], true));
    }));
    $scopedFaculty = [];
    foreach ($faculty as $id => $row) {
        $record = $directory['faculty'][$id];
        if (($campusId === 0 || in_array($campusId, $record['campus_ids'], true))
            && ($collegeId === 0 || in_array($collegeId, $record['college_ids'], true))) $scopedFaculty[$id] = true;
    }
    $campusRows = [];
    foreach ($campuses as $id => $campus) {
        if ($campusId > 0 && $id !== $campusId) { continue; }
        if ($collegeId > 0 && (int)$colleges[$collegeId]['campus_id'] !== $id) { continue; }
        $campusAccounts = array_values(array_filter($scopedAccounts, static function (array $account) use ($id): bool { return in_array($id, $account['campus_ids'], true); }));
        $facultyIds = [];
        foreach ($scopedFaculty as $facultyId => $present) {
            if (in_array($id, $directory['faculty'][$facultyId]['campus_ids'], true)) $facultyIds[$facultyId] = true;
        }
        $campusRows[] = ['campus' => $campus, 'faculty_count' => count($facultyIds), 'summary' => synk_di_profile_summary($campusAccounts)];
    }
    $unassignedAccounts = count(array_filter($scopedAccounts, static function (array $account): bool { return !$account['campus_ids']; }));
    $unassignedFaculty = 0;
    foreach (array_keys($scopedFaculty) as $id) {
        if (!$directory['faculty'][$id]['campus_ids']) $unassignedFaculty++;
    }
    return [
        'campuses' => $campuses, 'colleges' => $colleges, 'campus_id' => $campusId, 'college_id' => $collegeId,
        'faculty_count' => count($scopedFaculty), 'accounts' => $scopedAccounts,
        'summary' => synk_di_profile_summary($scopedAccounts), 'campus_rows' => $campusRows,
        'unassigned_accounts' => $unassignedAccounts, 'unassigned_faculty' => $unassignedFaculty, 'profiles_available' => $profilesAvailable,
        'scope_label' => $collegeId > 0 ? $colleges[$collegeId]['college_name'] : ($campusId > 0 ? $campuses[$campusId]['campus_name'] : 'All campuses'),
    ];
}
