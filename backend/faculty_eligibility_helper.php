<?php

function synk_faculty_profile_ensure_eligibilities(mysqli $conn): void
{
    $ready = true;
    foreach (['tbl_faculty_eligibilities', 'tbl_faculty_profile_eligibilities'] as $table) {
        $result = $conn->query("SHOW TABLES LIKE '" . $table . "'");
        $ready = $ready && $result->num_rows > 0;
        $result->close();
    }
    if ($ready) {
        $result = $conn->query('SELECT eligibility_id FROM tbl_faculty_eligibilities LIMIT 1');
        $ready = $result->num_rows > 0;
        $result->close();
    }
    if (!$ready) {
        $sql = file_get_contents(__DIR__ . '/sql/phase19_faculty_eligibilities.sql');
        if ($sql === false) {
            throw new RuntimeException('Eligibility reference data could not be loaded.');
        }
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '' && !$conn->query($statement)) {
                throw new RuntimeException('Eligibility storage could not be initialized.');
            }
        }
    }
}

function synk_faculty_profile_eligibility_catalog(mysqli $conn): array
{
    $result = $conn->query('SELECT eligibility_id, code, name, category, issuing_body, search_terms
        FROM tbl_faculty_eligibilities ORDER BY sort_order, name, eligibility_id');
    $catalog = [];
    while ($row = $result->fetch_assoc()) {
        $row['eligibility_id'] = (int)$row['eligibility_id'];
        $catalog[$row['eligibility_id']] = $row;
    }
    $result->close();
    return $catalog;
}

function synk_faculty_profile_eligibility_ids(mysqli $conn, int $userId): array
{
    $stmt = $conn->prepare('SELECT eligibility_id FROM tbl_faculty_profile_eligibilities WHERE user_id = ? ORDER BY eligibility_id');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int)$row['eligibility_id'];
    }
    $stmt->close();
    return $ids;
}

function synk_faculty_profile_validate_eligibilities($input, array $catalog): array
{
    if (!is_array($input) || count($input) > 200) {
        return [[], 'Select eligibility or licenses from the list.'];
    }
    $ids = [];
    $error = '';
    foreach ($input as $value) {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) || !isset($catalog[(int)$value])) {
            $error = 'One or more eligibility choices are invalid. Select items from the list.';
            continue;
        }
        $ids[(int)$value] = (int)$value;
    }
    sort($ids, SORT_NUMERIC);
    return [array_values($ids), $error];
}

// Called inside the same transaction as the parent profile save.
function synk_faculty_profile_replace_eligibilities(mysqli $conn, int $userId, array $ids): void
{
    $stmt = $conn->prepare('DELETE FROM tbl_faculty_profile_eligibilities WHERE user_id = ?');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
    if (!$ids) {
        return;
    }
    $stmt = $conn->prepare('INSERT INTO tbl_faculty_profile_eligibilities (user_id, eligibility_id) VALUES (?, ?)');
    try {
        foreach ($ids as $eligibilityId) {
            $stmt->bind_param('ii', $userId, $eligibilityId);
            $stmt->execute();
        }
    } finally {
        $stmt->close();
    }
}
