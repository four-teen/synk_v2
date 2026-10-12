<?php

require_once __DIR__ . '/faculty_eligibility_helper.php';
require_once __DIR__ . '/faculty_education_helper.php';
require_once __DIR__ . '/faculty_record_profile_helper.php';

// Profile-only storage. Each caller verifies the actor and resolves the target identity.
function synk_faculty_profile_fields(array $ranks = [], array $existing = [], array $locations = []): array
{
    $collegeCampuses = [];
    foreach ($locations as $campus => $colleges) {
        foreach ($colleges as $college) {
            $collegeCampuses[$college][] = $campus;
        }
    }
    $fields = [
        'full_name' => ['label' => 'Full name', 'max' => 250, 'required' => true],
        'faculty_id_number' => ['label' => 'Faculty ID', 'max' => 50, 'placeholder' => 'e.g. FAC00123 or 00123-A'],
        'gender' => ['label' => 'Gender', 'options' => ['Male', 'Female', 'Other', 'Prefer not to say']],
        'date_of_birth' => ['label' => 'Date of birth', 'date' => true],
        'civil_status' => ['label' => 'Civil status', 'options' => ['Single', 'Married', 'Widowed', 'Separated', 'Annulled']],
        'campus_name' => ['label' => 'Campus', 'max' => 200, 'options' => array_keys($locations)],
        'college_name' => ['label' => 'College', 'max' => 200, 'options' => array_keys($collegeCampuses), 'campus_options' => $collegeCampuses],
        'faculty_rank' => ['label' => 'Faculty rank', 'max' => 150, 'options' => array_keys($ranks), 'rank_options' => $ranks],
        'salary_grade' => ['label' => 'Salary grade', 'max' => 100, 'readonly' => true, 'placeholder' => 'Automatically set by faculty rank'],
        'employment_classification' => ['label' => 'Employment classification', 'options' => ['Permanent', 'Temporary', 'Contract of Service', 'Part Time', 'Job Order']],
        'eligibility' => ['label' => 'Other / previously saved eligibility', 'max' => 5000, 'textarea' => true, 'placeholder' => 'Add any credentials not listed above, including the qualification or specialty.'],
        'service_start_date' => ['label' => 'Service start date', 'date' => true],
        'length_of_service' => ['label' => 'Length of service', 'max' => 150, 'placeholder' => 'e.g. 3 years and 9 months'],
    ];
    $previousRank = trim((string)($existing['faculty_rank'] ?? ''));
    if ($previousRank !== '' && !isset($ranks[$previousRank])) {
        // Keep a previously saved free-text rank until the owner replaces it.
        $fields['faculty_rank']['options'][] = $previousRank;
        $fields['faculty_rank']['rank_options'][$previousRank] = [
            'legacy' => true, 'salary_label' => (string)($existing['salary_grade'] ?? ''),
        ];
    }
    foreach (['bachelor' => "Bachelor's", 'master' => "Master's", 'doctoral' => 'Doctoral'] as $level => $label) {
        $fields[$level . '_degree'] = ['label' => 'Degree / program', 'max' => 500, 'education_type' => 'degree'];
        $fields[$level . '_institution'] = ['label' => 'University / college', 'max' => 300, 'education_type' => 'institution'];
        $fields[$level . '_address'] = ['label' => 'University / college address', 'max' => 500, 'education_type' => 'address'];
        $fields[$level . '_specialization'] = ['label' => 'Field of specialization', 'max' => 500, 'education_type' => 'specialization'];
        $fields[$level . '_status'] = ['label' => 'Study status', 'options' => ['Completed', 'Ongoing', 'Not applicable']];
    }
    return $fields;
}

function synk_faculty_profile_locations(mysqli $conn): array
{
    $result = $conn->query("SELECT cp.campus_name, c.college_name
        FROM tbl_campus cp
        LEFT JOIN tbl_college c ON c.campus_id = cp.campus_id AND c.status = 'active'
        WHERE cp.status = 'active'
        ORDER BY cp.campus_name, c.college_name");
    $locations = [];
    while ($row = $result->fetch_assoc()) {
        $campus = trim((string)$row['campus_name']);
        $college = trim((string)($row['college_name'] ?? ''));
        if ($campus === '') {
            continue;
        }
        if (!isset($locations[$campus])) {
            $locations[$campus] = [];
        }
        if ($college !== '' && !in_array($college, $locations[$campus], true)) {
            $locations[$campus][] = $college;
        }
    }
    $result->close();
    return $locations;
}

function synk_faculty_profile_location_errors(array $data, array $locations): array
{
    $campus = $data['campus_name'] ?? '';
    $college = $data['college_name'] ?? '';
    $errors = [];
    if (!is_string($campus) || ($campus !== '' && !array_key_exists($campus, $locations))) {
        $errors['campus_name'] = 'Select a valid campus from the list.';
    }
    if (!is_string($college) || ($college !== '' && (isset($errors['campus_name']) || !in_array($college, $locations[$campus] ?? [], true)))) {
        $errors['college_name'] = 'Select a college belonging to your selected campus.';
    }
    return $errors;
}

function synk_faculty_profile_completion_rules(array $fields): array
{
    $groups = [
        'personal' => ['full_name', 'gender', 'civil_status', 'date_of_birth', 'campus_name', 'college_name'],
        'employment' => ['faculty_rank', 'employment_classification', 'length_of_service'],
    ];
    $rules = [];
    foreach ($groups as $section => $keys) {
        foreach ($keys as $key) {
            $rules[$key] = $fields[$key] + ['section' => $section];
        }
    }
    foreach (['bachelor' => "Bachelor's", 'master' => "Master's", 'doctoral' => 'Doctoral'] as $level => $label) {
        foreach (['status', 'degree', 'institution', 'address', 'specialization'] as $suffix) {
            $key = $level . '_' . $suffix;
            $rules[$key] = $fields[$key] + ['section' => 'education'];
            $rules[$key]['label'] = $label . ': ' . strtolower($fields[$key]['label']);
            if ($suffix !== 'status') {
                $rules[$key]['skip_when'] = $level . '_status';
            }
        }
    }
    return $rules;
}

// The caller supplies validated values and errors; completion never blocks partial saves.
function synk_faculty_profile_completion(array $profile, array $rules, array $errors = []): array
{
    $sections = [];
    $missing = [];
    $completed = 0;
    $total = 0;
    foreach ($rules as $key => $rule) {
        $section = $rule['section'];
        if (!isset($sections[$section])) {
            $sections[$section] = ['completed' => 0, 'total' => 0];
        }
        if (isset($rule['skip_when']) && ($profile[$rule['skip_when']] ?? '') === 'Not applicable') {
            continue;
        }
        $total++;
        $sections[$section]['total']++;
        $value = $profile[$key] ?? '';
        $valid = is_string($value) && trim($value) !== '' && !isset($errors[$key]);
        if ($key === 'length_of_service' && isset($errors['service_start_date'])) {
            $valid = false;
        }
        if ($valid) {
            $completed++;
            $sections[$section]['completed']++;
        } else {
            $missing[] = $key;
        }
    }
    return [
        'percent' => $total > 0 ? (int)floor($completed * 100 / $total) : 0,
        'completed' => $completed,
        'total' => $total,
        'missing' => $missing,
        'sections' => $sections,
    ];
}

function synk_faculty_profile_ensure_ranks(mysqli $conn): void
{
    $result = $conn->query("SHOW TABLES LIKE 'tbl_faculty_ranks'");
    $exists = $result->num_rows > 0;
    $result->close();
    if (!$exists) {
        $sql = file_get_contents(__DIR__ . '/sql/phase18_faculty_ranks.sql');
        if ($sql === false) {
            throw new RuntimeException('Faculty rank reference data could not be loaded.');
        }
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '' && !$conn->query($statement)) {
                throw new RuntimeException('Faculty rank reference data could not be initialized.');
            }
        }
    }
}

function synk_faculty_profile_ranks(mysqli $conn): array
{
    $result = $conn->query('SELECT overall_level, academic_rank, salary_grade FROM tbl_faculty_ranks ORDER BY overall_level');
    $ranks = [];
    while ($row = $result->fetch_assoc()) {
        $row['overall_level'] = (int)$row['overall_level'];
        $row['salary_grade'] = (int)$row['salary_grade'];
        $row['salary_label'] = 'SG ' . $row['salary_grade'];
        $ranks[$row['academic_rank']] = $row;
    }
    $result->close();
    return $ranks;
}

function synk_faculty_profile_salary(string $rank, array $ranks, array $existing = []): string
{
    if (isset($ranks[$rank])) {
        return 'SG ' . (int)$ranks[$rank]['salary_grade'];
    }
    if ($rank !== '' && $rank === (string)($existing['faculty_rank'] ?? '')) {
        return (string)($existing['salary_grade'] ?? '');
    }
    if ($rank === '') {
        return '';
    }
    throw new InvalidArgumentException('Select a faculty rank from the list.');
}

function synk_faculty_profile_ensure_table(mysqli $conn): void
{
    $result = $conn->query("SHOW TABLES LIKE 'tbl_faculty_profiles'");
    $exists = $result->num_rows > 0;
    $result->close();
    if (!$exists) {
        // Additive and idempotent, matching the portal's existing schema helpers.
        $sql = file_get_contents(__DIR__ . '/sql/phase17_faculty_profiles.sql');
        if ($sql === false || !$conn->query($sql)) {
            throw new RuntimeException('Faculty profile storage could not be initialized.');
        }
    }
    $result = $conn->query("SHOW COLUMNS FROM tbl_faculty_profiles LIKE 'faculty_id_number'");
    $hasFacultyId = $result->num_rows > 0;
    $result->close();
    if (!$hasFacultyId) {
        try {
            $conn->query("ALTER TABLE tbl_faculty_profiles ADD COLUMN faculty_id_number VARCHAR(50) NOT NULL DEFAULT '' AFTER full_name");
        } catch (mysqli_sql_exception $exception) {
            // Another request may have applied the same additive migration.
            if ($exception->getCode() !== 1060) {
                throw $exception;
            }
        }
    }
}

function synk_faculty_profile_account(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare("SELECT ua.user_id, ua.username, ua.email,
        COALESCE(c.college_name, '') AS college_name,
        COALESCE(ca.campus_name, '') AS campus_name
        FROM tbl_useraccount ua
        LEFT JOIN tbl_college c ON c.college_id = ua.college_id
        LEFT JOIN tbl_campus ca ON ca.campus_id = c.campus_id
        WHERE ua.user_id = ? AND ua.status = 'active' LIMIT 1");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function synk_faculty_profile_defaults(array $account, ?array $faculty, string $classification = ''): array
{
    $profile = array_fill_keys(array_keys(synk_faculty_profile_fields()), '');
    $profile['full_name'] = $faculty['faculty_name'] ?? $account['username'] ?? '';
    $profile['campus_name'] = $account['campus_name'] ?? '';
    $profile['college_name'] = $account['college_name'] ?? '';
    $labels = ['permanent' => 'Permanent', 'temporary' => 'Temporary', 'contract_of_service' => 'Contract of Service', 'part_time' => 'Part Time', 'job_order' => 'Job Order'];
    $profile['employment_classification'] = $labels[$classification] ?? '';
    $profile['revision'] = 0;
    $profile['updated_at'] = null;
    $profile['eligibility_ids'] = [];
    return $profile;
}

function synk_faculty_profile_fetch(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare('SELECT * FROM tbl_faculty_profiles WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($profile) {
        $profile['eligibility_ids'] = synk_faculty_profile_eligibility_ids($conn, $userId);
    }
    return $profile ?: synk_faculty_record_profile_for_account($conn, $userId);
}

function synk_faculty_profile_owner(int $userId, int $facultyId, ?array $profile): string
{
    return ($profile['_storage'] ?? '') === 'faculty' || $userId <= 0
        ? 'faculty:' . (int)($profile['_faculty_id'] ?? $facultyId) : 'account:' . $userId;
}

function synk_faculty_profile_date(string $value): ?DateTimeImmutable
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $date : null;
}

function synk_faculty_profile_service(string $value, ?DateTimeImmutable $today = null): string
{
    $start = synk_faculty_profile_date($value);
    $today = $today ?? new DateTimeImmutable('today');
    if (!$start || $start > $today) {
        return '';
    }
    $months = ((int)$today->format('Y') - (int)$start->format('Y')) * 12
        + (int)$today->format('n') - (int)$start->format('n')
        - ((int)$today->format('j') < (int)$start->format('j') ? 1 : 0);
    $years = intdiv($months, 12);
    $remainingMonths = $months % 12;
    if ($months === 0) {
        return 'Less than a month';
    }
    $parts = [];
    if ($years > 0) {
        $parts[] = $years . ($years === 1 ? ' year' : ' years');
    }
    if ($remainingMonths > 0) {
        $parts[] = $remainingMonths . ($remainingMonths === 1 ? ' month' : ' months');
    }
    return implode(' and ', $parts);
}

function synk_faculty_profile_validate(array $input, ?DateTimeImmutable $today = null, array $ranks = [], array $existing = [], array $eligibilities = [], array $locations = []): array
{
    $today = $today ?? new DateTimeImmutable('today');
    $data = [];
    $errors = [];
    foreach (synk_faculty_profile_fields($ranks, $existing, $locations) as $key => $field) {
        // Salary is derived from database reference data, never a posted amount.
        $raw = $key === 'salary_grade' ? '' : ($input[$key] ?? '');
        if (!is_string($raw) || !preg_match('//u', $raw)) {
            $data[$key] = '';
            $errors[$key] = 'Enter a valid value for ' . strtolower($field['label']) . '.';
            continue;
        }
        $value = trim(str_replace(["\r\n", "\r"], "\n", $raw));
        if (isset($field['education_type'])) {
            $value = synk_faculty_education_normalize($value);
        }
        $data[$key] = $value;
        if (!empty($field['required']) && $value === '') {
            $errors[$key] = $field['label'] . ' is required.';
        } elseif ($value !== '' && isset($field['options']) && !in_array($value, $field['options'], true)) {
            $errors[$key] = 'Select a valid ' . strtolower($field['label']) . '.';
        } elseif (isset($field['max']) && preg_match_all('/./us', $value) > $field['max']) {
            $errors[$key] = $field['label'] . ' must be ' . $field['max'] . ' characters or fewer.';
        } elseif ($key === 'faculty_id_number' && preg_match('/\p{C}/u', $value)) {
            $errors[$key] = 'Enter a faculty ID without line breaks or control characters.';
        } elseif (!empty($field['date']) && $value !== '') {
            $date = synk_faculty_profile_date($value);
            if (!$date || $date > $today || $date < new DateTimeImmutable('1900-01-01')) {
                $errors[$key] = 'Enter a valid date between January 1, 1900 and today.';
            }
        }
    }
    $errors = array_merge($errors, synk_faculty_profile_location_errors($data, $locations));
    if (!isset($errors['faculty_rank'])) {
        $data['salary_grade'] = synk_faculty_profile_salary($data['faculty_rank'], $ranks, $existing);
    }
    if ($data['service_start_date'] !== '' && !isset($errors['service_start_date'])) {
        if ($data['date_of_birth'] !== '' && !isset($errors['date_of_birth']) && $data['service_start_date'] < $data['date_of_birth']) {
            $errors['service_start_date'] = 'Service cannot start before your date of birth.';
        } else {
            $data['length_of_service'] = synk_faculty_profile_service($data['service_start_date'], $today);
        }
    }
    foreach (['bachelor', 'master', 'doctoral'] as $level) {
        if (in_array($data[$level . '_status'], ['Completed', 'Ongoing'], true) && $data[$level . '_degree'] === '') {
            $errors[$level . '_degree'] = 'Enter the degree / program for this study status.';
        }
    }
    [$data['eligibility_ids'], $eligibilityError] = synk_faculty_profile_validate_eligibilities($input['eligibility_ids'] ?? [], $eligibilities);
    if ($eligibilityError !== '') {
        $errors['eligibility_ids'] = $eligibilityError;
    }
    return [$data, $errors];
}

// Caller owns the transaction covering the profile, credentials, and suggestions.
function synk_faculty_profile_save(mysqli $conn, int $userId, array $data, int $revision): bool
{
    if ($userId <= 0 || $revision < 0) {
        throw new InvalidArgumentException('Invalid profile identity or revision.');
    }
    $fields = synk_faculty_profile_fields();
    $existing = synk_faculty_profile_fetch($conn, $userId) ?? [];
    if (($existing['_storage'] ?? '') === 'faculty') {
        return synk_faculty_record_profile_save($conn, (int)$existing['_faculty_id'], $data, $revision,
            (int)($_SESSION['user_id'] ?? $userId));
    }
    // Recheck at the persistence boundary, including callers outside the form.
    $locationErrors = synk_faculty_profile_location_errors($data, synk_faculty_profile_locations($conn));
    if ($locationErrors) {
        throw new InvalidArgumentException(implode(' ', $locationErrors));
    }
    $data['salary_grade'] = synk_faculty_profile_salary(
        (string)$data['faculty_rank'], synk_faculty_profile_ranks($conn),
        $existing
    );
    [$eligibilityIds, $eligibilityError] = synk_faculty_profile_validate_eligibilities(
        $data['eligibility_ids'] ?? ($existing['eligibility_ids'] ?? []),
        synk_faculty_profile_eligibility_catalog($conn)
    );
    if ($eligibilityError !== '') {
        throw new InvalidArgumentException($eligibilityError);
    }
    $values = [];
    foreach ($fields as $key => $field) {
        $values[] = !empty($field['date']) && $data[$key] === '' ? null : $data[$key];
    }
    if ($revision === 0) {
        $columns = implode('`, `', array_keys($fields));
        $placeholders = implode(', ', array_fill(0, count($values), '?'));
        $stmt = $conn->prepare("INSERT INTO tbl_faculty_profiles (`{$columns}`, user_id) VALUES ({$placeholders}, ?)");
        $values[] = $userId;
        $types = str_repeat('s', count($fields)) . 'i';
    } else {
        $assignments = implode(', ', array_map(static function ($key) { return '`' . $key . '` = ?'; }, array_keys($fields)));
        $stmt = $conn->prepare("UPDATE tbl_faculty_profiles SET {$assignments}, revision = revision + 1,
            updated_at = CURRENT_TIMESTAMP WHERE user_id = ? AND revision = ?");
        $values[] = $userId;
        $values[] = $revision;
        $types = str_repeat('s', count($fields)) . 'ii';
    }
    try {
        $stmt->bind_param($types, ...$values);
        if (!$stmt->execute()) {
            if ($stmt->errno === 1062) {
                return false;
            }
            throw new RuntimeException('Faculty profile could not be saved.');
        }
        if ($stmt->affected_rows !== 1) {
            return false;
        }
        synk_faculty_profile_replace_eligibilities($conn, $userId, $eligibilityIds);
        synk_faculty_profile_share_education($conn, $data);
        return true;
    } catch (mysqli_sql_exception $exception) {
        if ($exception->getCode() === 1062) {
            return false;
        }
        throw $exception;
    } finally {
        $stmt->close();
    }
}
