<?php

function synk_faculty_education_types(): array
{
    return ['degree' => 500, 'institution' => 300, 'address' => 500, 'specialization' => 500];
}

function synk_faculty_education_normalize(string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function synk_faculty_education_placeholder(string $value): bool
{
    return in_array(strtolower($value), ['', 'n/a', 'na', 'none', 'not applicable', '-'], true);
}

function synk_faculty_profile_ensure_education(mysqli $conn): void
{
    $result = $conn->query("SHOW TABLES LIKE 'tbl_faculty_education_options'");
    $exists = $result->num_rows > 0;
    $result->close();
    if (!$exists) {
        $sql = file_get_contents(__DIR__ . '/sql/phase20_faculty_education_options.sql');
        if ($sql === false || !$conn->query($sql)) {
            throw new RuntimeException('Education suggestions could not be initialized.');
        }
    }
    $result = $conn->query('SELECT education_option_id FROM tbl_faculty_education_options LIMIT 1');
    $hasOptions = $result->num_rows > 0;
    $result->close();
    if (!$hasOptions) {
        // Backfill only education values, never profile identities or other data.
        // Also works when the SQL migration was applied before the page is opened.
        $columns = [];
        foreach (['bachelor', 'master', 'doctoral'] as $level) {
            foreach (synk_faculty_education_types() as $type => $max) {
                $columns[] = $level . '_' . $type;
            }
        }
        $result = $conn->query('SELECT ' . implode(', ', $columns) . ' FROM tbl_faculty_profiles');
        $conn->begin_transaction();
        try {
            while ($profile = $result->fetch_assoc()) {
                synk_faculty_profile_share_education($conn, $profile);
            }
            $conn->commit();
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        } finally {
            $result->close();
        }
    }
}

function synk_faculty_education_register(mysqli $conn, string $type, string $value): void
{
    $limits = synk_faculty_education_types();
    $value = synk_faculty_education_normalize($value);
    if (!isset($limits[$type]) || !preg_match('//u', $value) || preg_match_all('/./us', $value) > $limits[$type]) {
        throw new InvalidArgumentException('Invalid education entry.');
    }
    if (synk_faculty_education_placeholder($value)) {
        return;
    }
    // MySQL LOWER handles the database's UTF-8 case rules on every PHP version.
    // A fixed-length key avoids truncating long degree/address strings in indexes.
    $stmt = $conn->prepare('INSERT INTO tbl_faculty_education_options (option_type, value, value_key)
        VALUES (?, ?, SHA2(LOWER(?), 256))
        ON DUPLICATE KEY UPDATE education_option_id = education_option_id');
    try {
        $stmt->bind_param('sss', $type, $value, $value);
        $stmt->execute();
    } finally {
        $stmt->close();
    }
}

// Runs only after the account's profile revision has successfully been saved.
function synk_faculty_profile_share_education(mysqli $conn, array $profile): void
{
    // Stable ordering also reduces deadlock risk for concurrent shared entries.
    $entries = [];
    foreach (['bachelor', 'master', 'doctoral'] as $level) {
        foreach (synk_faculty_education_types() as $type => $max) {
            $value = synk_faculty_education_normalize((string)($profile[$level . '_' . $type] ?? ''));
            $entries[$type . ':' . $value] = [$type, $value];
        }
    }
    ksort($entries, SORT_STRING);
    foreach ($entries as [$type, $value]) {
        synk_faculty_education_register($conn, $type, $value);
    }
}

function synk_faculty_education_search(mysqli $conn, string $type, string $query = '', int $page = 1): array
{
    if (!isset(synk_faculty_education_types()[$type]) || !preg_match('//u', $query)
        || preg_match_all('/./us', $query) > 500 || $page < 1 || $page > 10000) {
        throw new InvalidArgumentException('Invalid education search.');
    }
    $query = synk_faculty_education_normalize($query);
    // Treat percent and underscore as text, not SQL wildcard input.
    $like = '%' . strtr($query, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $offset = ($page - 1) * 25;
    $stmt = $conn->prepare("SELECT value FROM tbl_faculty_education_options
        WHERE option_type = ? AND value LIKE ? ESCAPE '!'
        ORDER BY (LOWER(value) = LOWER(?)) DESC, value ASC, education_option_id ASC
        LIMIT 26 OFFSET ?");
    $stmt->bind_param('sssi', $type, $like, $query, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        // Text values preserve compatibility with existing profile columns.
        $rows[] = ['id' => $row['value'], 'text' => $row['value']];
    }
    $stmt->close();
    return ['results' => array_slice($rows, 0, 25), 'pagination' => ['more' => count($rows) > 25]];
}
