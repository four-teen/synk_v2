<?php
require_once __DIR__ . '/../backend/faculty_profile_helper.php';
require_once __DIR__ . '/faculty-data.php';
require_once __DIR__ . '/personnel-data.php';

const SYNK_HRIS_EMPLOYEE_NAME_FIELDS = ['surname', 'first_name', 'middle_name', 'name_extension'];
const SYNK_HRIS_TEMPORARY_ID_SUFFIX = '00000000';
const SYNK_HRIS_EMPLOYEE_PROFILE_FIELDS = [
    'date_of_birth', 'gender', 'civil_status', 'faculty_rank', 'employment_classification',
    'campus_name', 'college_name', 'service_start_date',
];

function synk_hris_employee_catalog(mysqli $conn): array
{
    return [
        synk_faculty_profile_ranks($conn),
        synk_faculty_profile_locations($conn),
    ];
}

function synk_hris_employee_fields(array $ranks, array $locations): array
{
    $fields = synk_faculty_profile_fields($ranks, [], $locations);
    $fields['faculty_rank']['label'] = 'Academic rank';
    $fields['service_start_date']['label'] = 'Date of entry';
    return [
        'surname' => ['label' => 'Last name', 'max' => 100, 'required' => true],
        'first_name' => ['label' => 'First name', 'max' => 100, 'required' => true],
        'middle_name' => ['label' => 'Middle name', 'max' => 100],
        'name_extension' => ['label' => 'Name extension', 'max' => 20, 'placeholder' => 'e.g. Jr.'],
        'employee_id' => ['label' => 'Employee ID', 'max' => $fields['faculty_id_number']['max']],
        'official_email' => ['label' => 'Official SKSU Google email (optional)', 'type' => 'email', 'max' => 254],
    ] + array_intersect_key($fields, array_flip(SYNK_HRIS_EMPLOYEE_PROFILE_FIELDS));
}

function synk_hris_temporary_employee_id(array $names): string
{
    $initials = [];
    foreach (['first_name', 'middle_name', 'surname'] as $key) {
        preg_match('/\p{L}/u', $names[$key] ?? '', $match);
        $initials[$key] = $match[0] ?? '';
    }
    if ($initials['first_name'] === '' || $initials['surname'] === '') return '';

    $prefix = implode('', $initials);
    $prefix = function_exists('mb_strtoupper') ? mb_strtoupper($prefix, 'UTF-8') : strtoupper($prefix);
    return $prefix . SYNK_HRIS_TEMPORARY_ID_SUFFIX;
}

function synk_hris_validate_new_employee(array $input, array $ranks, array $locations, DateTimeImmutable $today): array
{
    $fields = synk_hris_employee_fields($ranks, $locations);
    $names = [];
    $errors = [];
    foreach (SYNK_HRIS_EMPLOYEE_NAME_FIELDS as $key) {
        $raw = $input[$key] ?? '';
        $name = is_string($raw) ? trim($raw) : '';
        $field = $fields[$key];
        if (!is_string($raw) || !preg_match('//u', $name) || preg_match('/\p{C}/u', $name)) {
            $errors[$key] = 'Enter a valid ' . strtolower($field['label']) . '.';
            $names[$key] = '';
            continue;
        }

        $name = function_exists('mb_strtoupper') ? mb_strtoupper($name, 'UTF-8') : strtoupper($name);
        $names[$key] = $name;
        if ($name === '' && !empty($field['required'])) {
            $errors[$key] = $field['label'] . ' is required.';
        } elseif (preg_match_all('/./us', $name) > $field['max']) {
            $errors[$key] = $field['label'] . ' must be ' . $field['max'] . ' characters or fewer.';
        }
    }

    $profileInput = array_intersect_key($input, array_flip(SYNK_HRIS_EMPLOYEE_PROFILE_FIELDS));
    if (($input['personnel_category'] ?? '') === 'non_teaching') {
        unset($profileInput['faculty_rank'], $profileInput['college_name']);
    }
    $profileInput['full_name'] = implode(' ', array_filter([
        $names['first_name'], $names['middle_name'], $names['surname'], $names['name_extension'],
    ], 'strlen'));
    $employeeId = $input['employee_id'] ?? '';
    $profileInput['faculty_id_number'] = is_string($employeeId) && trim($employeeId) === ''
        ? synk_hris_temporary_employee_id($names) : $employeeId;
    [$profile, $profileErrors] = synk_faculty_profile_validate($profileInput, $today, $ranks, [], [], $locations);
    if (!$errors && isset($profileErrors['full_name'])) $errors['surname'] = $profileErrors['full_name'];
    unset($profileErrors['full_name']);
    if (isset($profileErrors['faculty_id_number'])) {
        $profileErrors['employee_id'] = str_replace('faculty ID', 'employee ID', $profileErrors['faculty_id_number']);
        unset($profileErrors['faculty_id_number']);
    } elseif ($profile['faculty_id_number'] === '') {
        $profileErrors['employee_id'] = 'Employee ID is required.';
    }

    return [$names + $profile, $errors + $profileErrors];
}

function synk_hris_employee_id_key(string $value): string
{
    $id = trim($value);
    return function_exists('mb_strtolower') ? mb_strtolower($id, 'UTF-8') : strtolower($id);
}

function synk_hris_save_initial_profile(mysqli $conn, int $facultyId, array $values, int $actorId): void
{
    if ($actorId > 0) {
        if (!synk_faculty_record_profile_save($conn, $facultyId, $values, 0, $actorId)) {
            throw new RuntimeException('The shared faculty profile could not be saved.');
        }
        return;
    }

    // The registration validator provides the profile payload for this new record.
    $profile = array_diff_key($values, array_flip(SYNK_HRIS_EMPLOYEE_NAME_FIELDS));
    $json = json_encode($profile, JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('The employee profile could not be encoded.');
    $stmt = $conn->prepare('INSERT INTO tbl_faculty_record_profiles (faculty_id, profile_data, updated_by) VALUES (?, ?, ?)');
    try {
        $stmt->bind_param('isi', $facultyId, $json, $actorId);
        $stmt->execute();
    } finally {
        $stmt->close();
    }
}

function synk_hris_create_employee(mysqli $conn, array $values, array $personnel, int $actorId, string $officialEmail = ''): int
{
    $employeeId = synk_hris_employee_id_key($values['faculty_id_number']);
    // Serialize registrations of the same ID while checking both existing profile sources.
    $lock = 'kawani.employee.' . substr(hash('sha256', $employeeId), 0, 48);
    $result = $conn->query("SELECT GET_LOCK('{$lock}', 5) AS acquired");
    $acquired = (int)$result->fetch_assoc()['acquired'] === 1;
    $result->close();
    if (!$acquired) throw new RuntimeException('The employee ID is being registered in another request.');

    try {
        $sources = synk_hris_faculty_sources($conn);
        if (!isset($sources['profiles'], $sources['pds'])) {
            throw new RuntimeException('The existing faculty records could not be checked.');
        }
        $existingIds = array_merge(array_column($sources['profiles'], 'faculty_id_number'), array_column($sources['pds'], 'employee_id'));
        foreach ($existingIds as $existingId) {
            if (synk_hris_employee_id_key((string)$existingId) === $employeeId) {
                $temporaryId = synk_hris_employee_id_key(synk_hris_temporary_employee_id($values));
                $message = $employeeId === $temporaryId
                    ? 'This temporary ID is already in use. Enter a unique Employee ID.'
                    : 'This employee ID is already recorded. Search for it in Employee Profiles.';
                throw new DomainException($message, 409);
            }
        }

        $conn->begin_transaction();
        try {
            // Roster eligibility is handled separately from the employee's shared profile.
            $stmt = $conn->prepare("INSERT INTO tbl_faculty (last_name, first_name, middle_name, ext_name, status)
                VALUES (?, ?, ?, ?, 'inactive')");
            try {
                $stmt->bind_param('ssss', $values['surname'], $values['first_name'], $values['middle_name'], $values['name_extension']);
                $stmt->execute();
                $facultyId = (int)$conn->insert_id;
            } finally {
                $stmt->close();
            }
            synk_hris_save_initial_profile($conn, $facultyId, $values, $actorId);
            synk_hris_save_personnel_details($conn, 'faculty:' . $facultyId, $personnel, 0, $actorId);
            if ($officialEmail !== '') synk_hris_record_employee_email($conn, $facultyId, $officialEmail, $actorId);
            $conn->commit();
            return $facultyId;
        } catch (Throwable $error) {
            $conn->rollback();
            throw $error;
        }
    } finally {
        try {
            $conn->query("SELECT RELEASE_LOCK('{$lock}')")->close();
        } catch (mysqli_sql_exception $error) {
            error_log('Kawani could not release an employee registration lock. Database error ' . $error->getCode());
        }
    }
}
