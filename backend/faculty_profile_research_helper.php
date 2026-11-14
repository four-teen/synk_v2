<?php
require_once __DIR__ . '/faculty_profile_helper.php';

function synk_faculty_profile_research_ensure(mysqli $conn): void
{
    $result = $conn->query("SHOW TABLES LIKE 'tbl_faculty_profile_researches'");
    $exists = $result->num_rows > 0;
    $result->close();
    if (!$exists) {
        $sql = file_get_contents(__DIR__ . '/sql/faculty_profile_researches.sql');
        if ($sql === false || !$conn->query($sql)) throw new RuntimeException('Research storage could not be initialized.');
    }
}

function synk_faculty_profile_research_owner(string $owner): array
{
    if (!preg_match('/^(account|faculty):([1-9][0-9]{0,9})$/D', $owner, $matches) || (float)$matches[2] > 2147483647) {
        throw new InvalidArgumentException('Invalid faculty profile owner.');
    }
    return [$matches[1], (int)$matches[2]];
}

function synk_faculty_profile_research_defaults(): array
{
    return ['research_id'=>0, 'revision'=>0, 'research_title'=>'', 'research_scope'=>'', 'funding_type'=>'',
        'conference_name'=>'', 'conference_date'=>'', 'publisher'=>'', 'publisher_link'=>'', 'scopus_link'=>''];
}

function synk_faculty_profile_research_fields(): array
{
    return [
        'research_title'=>['label'=>'Research title', 'max'=>500, 'required'=>true],
        'research_scope'=>['label'=>'Local or international', 'options'=>['Local','International'], 'required'=>true],
        'funding_type'=>['label'=>'Funded or personal', 'options'=>['Funded','Personal'], 'required'=>true],
        'conference_name'=>['label'=>'Conference name', 'max'=>300],
        'conference_date'=>['label'=>'Conference date', 'type'=>'date'],
        'publisher'=>['label'=>'Publisher', 'max'=>300],
        'publisher_link'=>['label'=>'Publication link (publisher)', 'max'=>2048, 'type'=>'url'],
        'scopus_link'=>['label'=>'Publication link (Scopus)', 'max'=>2048, 'type'=>'url'],
    ];
}

function synk_faculty_profile_research_validate(array $input): array
{
    $record = synk_faculty_profile_research_defaults();
    $errors = [];
    foreach (synk_faculty_profile_research_fields() as $key=>$field) {
        $value = $input[$key] ?? '';
        if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            $errors[$key] = 'Enter valid text for ' . strtolower($field['label']) . '.';
            continue;
        }
        $value = trim($value);
        if (!isset($field['type'])) $value = preg_replace('/\s+/u', ' ', $value);
        $record[$key] = $value;
        if (!empty($field['required']) && $value === '') $errors[$key] = $field['label'] . ' is required.';
        elseif (isset($field['options']) && !in_array($value, $field['options'], true)) $errors[$key] = 'Choose ' . strtolower($field['label']) . '.';
        elseif (isset($field['max']) && preg_match_all('/./us', $value) > $field['max']) $errors[$key] = $field['label'] . ' must be ' . $field['max'] . ' characters or fewer.';
        if ($value !== '' && ($field['type'] ?? '') === 'url') {
            $parts = parse_url($value);
            if (!filter_var($value, FILTER_VALIDATE_URL) || !$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http','https'], true)
                || isset($parts['user']) || isset($parts['pass']) || preg_match('/\s/', $value)) {
                $errors[$key] = 'Enter a complete http:// or https:// publication link.';
            } elseif ($key === 'scopus_link' && !preg_match('/(^|\.)scopus\.com$/iD', $parts['host'] ?? '')) {
                $errors[$key] = 'Enter a link on scopus.com for the Scopus publication.';
            }
        }
    }
    if ($record['conference_date'] !== '' && (!synk_faculty_profile_date($record['conference_date']) || $record['conference_date'] < '1900-01-01')) {
        $errors['conference_date'] = 'Enter a valid conference date from 1900 onward.';
    }
    if ($record['conference_date'] !== '' && $record['conference_name'] === '') $errors['conference_name'] = 'Enter the conference name for this date.';
    return [$record, $errors];
}

function synk_faculty_profile_research_records(mysqli $conn, string $owner): array
{
    [$type, $id] = synk_faculty_profile_research_owner($owner);
    $stmt = $conn->prepare('SELECT research_id, research_title, research_scope, funding_type, conference_name, conference_date,
        publisher, publisher_link, scopus_link, revision, created_at, updated_at FROM tbl_faculty_profile_researches
        WHERE owner_type=? AND owner_id=? ORDER BY updated_at DESC, research_id DESC');
    $stmt->bind_param('si', $type, $id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Call inside the controller's ownership-checked transaction. IDs never select another owner's record.
function synk_faculty_profile_research_save(mysqli $conn, string $owner, array $input, int $researchId, int $revision): bool
{
    [$record, $errors] = synk_faculty_profile_research_validate($input);
    if ($errors) throw new InvalidArgumentException(implode(' ', $errors));
    if ($researchId < 0 || $revision < 0 || ($researchId === 0) !== ($revision === 0)) throw new InvalidArgumentException('Invalid research version.');
    [$type, $id] = synk_faculty_profile_research_owner($owner);
    $values = array_map(static function($key) use ($record) {return $key === 'conference_date' && $record[$key] === '' ? null : $record[$key];}, array_keys(synk_faculty_profile_research_fields()));
    if ($researchId === 0) {
        $stmt = $conn->prepare('INSERT INTO tbl_faculty_profile_researches (research_title,research_scope,funding_type,conference_name,conference_date,publisher,publisher_link,scopus_link,owner_type,owner_id) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $params = array_merge($values, [$type,$id]);
        $stmt->bind_param('sssssssssi', ...$params);
    } else {
        $stmt = $conn->prepare('UPDATE tbl_faculty_profile_researches SET research_title=?,research_scope=?,funding_type=?,conference_name=?,conference_date=?,publisher=?,publisher_link=?,scopus_link=?,revision=revision+1 WHERE owner_type=? AND owner_id=? AND research_id=? AND revision=?');
        $params = array_merge($values, [$type,$id,$researchId,$revision]);
        $stmt->bind_param('sssssssssiii', ...$params);
    }
    $stmt->execute();
    $saved = $stmt->affected_rows === 1;
    $stmt->close();
    return $saved;
}

function synk_faculty_profile_research_delete(mysqli $conn, string $owner, int $researchId, int $revision): bool
{
    if ($researchId < 1 || $revision < 1) throw new InvalidArgumentException('Invalid research version.');
    [$type, $id] = synk_faculty_profile_research_owner($owner);
    $stmt = $conn->prepare('DELETE FROM tbl_faculty_profile_researches WHERE owner_type=? AND owner_id=? AND research_id=? AND revision=?');
    $stmt->bind_param('siii', $type, $id, $researchId, $revision);
    $stmt->execute();
    $deleted = $stmt->affected_rows === 1;
    $stmt->close();
    return $deleted;
}
