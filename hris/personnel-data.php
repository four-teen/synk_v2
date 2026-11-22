<?php

require_once __DIR__ . '/../backend/schema_helper.php';

const SYNK_HRIS_POSITION_REQUIRED_CLASSIFICATIONS = ['permanent', 'temporary'];

function synk_hris_personnel_categories(): array
{
    return ['teaching' => 'Teaching', 'non_teaching' => 'Non-teaching'];
}

function synk_hris_personnel_ready(mysqli $conn): bool
{
    return synk_table_exists($conn, 'tbl_hr_non_teaching_positions')
        && synk_table_exists($conn, 'tbl_hr_personnel_details');
}

function synk_hris_position_catalog(mysqli $conn): array
{
    $result = $conn->query('SELECT position_id, position_title, salary_grade FROM tbl_hr_non_teaching_positions ORDER BY position_title');
    $positions = [];
    while ($row = $result->fetch_assoc()) {
        $positions[(int)$row['position_id']] = $row;
    }
    $result->close();
    return $positions;
}

function synk_hris_load_personnel_details(mysqli $conn, string $owner = ''): ?array
{
    if (!synk_hris_personnel_ready($conn)) return null;

    $sql = 'SELECT d.record_key, d.personnel_category, d.position_id, d.office_unit, d.plantilla_item_number,
                d.revision, p.position_title, p.salary_grade
        FROM tbl_hr_personnel_details d LEFT JOIN tbl_hr_non_teaching_positions p ON p.position_id = d.position_id';
    if ($owner === '') {
        $result = $conn->query($sql);
        try {
            return array_column($result->fetch_all(MYSQLI_ASSOC), null, 'record_key');
        } finally {
            $result->close();
        }
    }

    $stmt = $conn->prepare($sql . ' WHERE d.record_key = ?');
    try {
        $stmt->bind_param('s', $owner);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? [$owner => $row] : [];
    } finally {
        $stmt->close();
    }
}

function synk_hris_validate_personnel(array $input, array $positions, string $classification): array
{
    $category = $input['personnel_category'] ?? '';
    $errors = [];
    if (!is_string($category) || !isset(synk_hris_personnel_categories()[$category])) {
        $errors['personnel_category'] = 'Select Teaching or Non-teaching.';
        $category = '';
    }
    $values = ['personnel_category' => $category, 'position_id' => null, 'office_unit' => '', 'plantilla_item_number' => ''];
    if ($category === 'non_teaching') {
        $position = $input['position_id'] ?? '';
        $requiresPosition = in_array(strtolower(trim($classification)), SYNK_HRIS_POSITION_REQUIRED_CLASSIFICATIONS, true);
        if ($requiresPosition || $position !== '') {
            $positionId = filter_var($position, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($positionId === false || !isset($positions[$positionId])) {
                $errors['position_id'] = 'Select a non-teaching position from the catalog.';
            } else {
                $values['position_id'] = $positionId;
            }
        }
    }

    $textFields = ['plantilla_item_number' => ['Plantilla item number', 64]];
    if ($category === 'non_teaching') $textFields['office_unit'] = ['Office or unit', 200];
    foreach ($textFields as $key => [$label, $limit]) {
        $raw = $input[$key] ?? '';
        $value = is_string($raw) ? trim($raw) : '';
        if (!is_string($raw) || !preg_match('//u', $value) || preg_match('/\p{C}/u', $value)) {
            $errors[$key] = 'Enter a valid ' . strtolower($label) . '.';
        } elseif (preg_match_all('/./us', $value) > $limit) {
            $errors[$key] = $label . ' must be ' . $limit . ' characters or fewer.';
        }
        $values[$key] = $value;
    }
    return [$values, $errors];
}

// The caller verifies the record and joins this write to the registration transaction when creating an employee.
function synk_hris_save_personnel_details(mysqli $conn, string $owner, array $values, int $revision, int $actorId): void
{
    if ($revision === 0) {
        $stmt = $conn->prepare('INSERT INTO tbl_hr_personnel_details
            (personnel_category, position_id, office_unit, plantilla_item_number, updated_by, record_key) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sissis', $values['personnel_category'], $values['position_id'], $values['office_unit'],
            $values['plantilla_item_number'], $actorId, $owner);
    } else {
        $stmt = $conn->prepare('UPDATE tbl_hr_personnel_details
            SET personnel_category = ?, position_id = ?, office_unit = ?, plantilla_item_number = ?,
                updated_by = ?, revision = revision + 1, updated_at = CURRENT_TIMESTAMP
            WHERE record_key = ? AND revision = ?');
        $stmt->bind_param('sissisi', $values['personnel_category'], $values['position_id'], $values['office_unit'],
            $values['plantilla_item_number'], $actorId, $owner, $revision);
    }
    $saved = false;
    try {
        $stmt->execute();
        $saved = $stmt->affected_rows === 1;
    } catch (mysqli_sql_exception $error) {
        if ($revision !== 0 || (int)$error->getCode() !== 1062) throw $error;
    } finally {
        $stmt->close();
    }
    if (!$saved) throw new DomainException('Personnel details changed in another window. Reload Employee Profiles and try again.');
}
