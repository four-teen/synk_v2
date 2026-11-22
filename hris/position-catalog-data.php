<?php

require_once __DIR__ . '/personnel-data.php';

function synk_hris_catalog_position(array $row): array
{
    $position = [
        'position_id' => (int)$row['position_id'],
        'position_title' => $row['position_title'],
        'salary_grade' => $row['salary_grade'] === null ? null : (int)$row['salary_grade'],
    ];
    $position['version'] = hash('sha256', implode("\0", $position));
    return $position;
}

function synk_hris_validate_catalog_position(array $input): array
{
    $rawTitle = $input['position_title'] ?? '';
    $title = is_string($rawTitle) ? trim(preg_replace('/\s+/u', ' ', $rawTitle) ?? '') : '';
    if ($title === '' || preg_match('/\p{C}/u', $title) || preg_match_all('/./us', $title) > 200) {
        return [[], 'Enter an approved position title of up to 200 characters.'];
    }

    $rawGrade = $input['salary_grade'] ?? '';
    $grade = $rawGrade === '' ? null : filter_var($rawGrade, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 255],
    ]);
    if ($grade === false) return [[], 'Enter a salary grade from 1 to 255 or leave it blank.'];

    return [['position_title' => $title, 'salary_grade' => $grade], ''];
}

function synk_hris_add_catalog_position(mysqli $conn, array $values, int $actorId): array
{
    $stmt = $conn->prepare('INSERT INTO tbl_hr_non_teaching_positions (position_title, salary_grade, created_by) VALUES (?, ?, ?)');
    try {
        $stmt->bind_param('sii', $values['position_title'], $values['salary_grade'], $actorId);
        $stmt->execute();
        return synk_hris_catalog_position(['position_id' => $stmt->insert_id] + $values);
    } finally {
        $stmt->close();
    }
}

// A null payload deletes the entry. The row lock keeps the version check and write together.
function synk_hris_change_catalog_position(mysqli $conn, int $id, string $version, ?array $values): ?array
{
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT position_id, position_title, salary_grade FROM tbl_hr_non_teaching_positions WHERE position_id = ? FOR UPDATE');
        try {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
        } finally {
            $stmt->close();
        }
        if (!$row || !hash_equals(synk_hris_catalog_position($row)['version'], $version)) {
            throw new DomainException('This entry changed in another window. Select it again from the catalog.');
        }

        if ($values === null) {
            $stmt = $conn->prepare('DELETE FROM tbl_hr_non_teaching_positions WHERE position_id = ?');
            $stmt->bind_param('i', $id);
        } else {
            $stmt = $conn->prepare('UPDATE tbl_hr_non_teaching_positions SET position_title = ?, salary_grade = ? WHERE position_id = ?');
            $stmt->bind_param('sii', $values['position_title'], $values['salary_grade'], $id);
        }
        try {
            $stmt->execute();
        } finally {
            $stmt->close();
        }
        $conn->commit();
        return $values === null ? null : synk_hris_catalog_position(['position_id' => $id] + $values);
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
