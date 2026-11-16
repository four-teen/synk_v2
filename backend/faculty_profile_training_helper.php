<?php
require_once __DIR__ . '/faculty_profile_helper.php';

function synk_faculty_profile_training_ensure(mysqli $conn): void
{
    $result = $conn->query("SHOW TABLES LIKE 'tbl_faculty_profile_trainings'");
    $exists = $result->num_rows > 0;
    $result->close();
    if (!$exists) {
        $sql = file_get_contents(__DIR__ . '/sql/faculty_profile_trainings.sql');
        if ($sql === false || !$conn->query($sql)) throw new RuntimeException('Training storage could not be initialized.');
    }
}

function synk_faculty_profile_training_owner(string $owner): array
{
    if (!preg_match('/^(account|faculty):([1-9][0-9]{0,9})$/D', $owner, $matches) || (float)$matches[2] > 2147483647) {
        throw new InvalidArgumentException('Invalid faculty profile owner.');
    }
    return [$matches[1], (int)$matches[2]];
}

function synk_faculty_profile_training_defaults(): array
{
    return ['training_id'=>0, 'revision'=>0, 'training_title'=>'', 'agency'=>'', 'date_from'=>'', 'date_to'=>'', 'venue'=>'', 'mode'=>''];
}

function synk_faculty_profile_training_fields(): array
{
    return [
        'training_title'=>['label'=>'Training title', 'max'=>500, 'required'=>true],
        'agency'=>['label'=>'Agency/Entity', 'max'=>300, 'required'=>true],
        'date_from'=>['label'=>'Date from', 'type'=>'date', 'required'=>true],
        'date_to'=>['label'=>'Date to', 'type'=>'date', 'required'=>true],
        'venue'=>['label'=>'Venue', 'max'=>300, 'required'=>true],
        'mode'=>['label'=>'Mode', 'options'=>['Face to face','Online'], 'required'=>true],
    ];
}

function synk_faculty_profile_training_validate(array $input): array
{
    $record = synk_faculty_profile_training_defaults();
    $errors = [];
    foreach (synk_faculty_profile_training_fields() as $key=>$field) {
        $value = $input[$key] ?? '';
        if (!is_string($value) || preg_match('//u', $value) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            $errors[$key] = 'Enter valid text for ' . strtolower($field['label']) . '.';
            continue;
        }
        $value = trim($value);
        if (!isset($field['type'])) $value = preg_replace('/\s+/u', ' ', $value);
        $record[$key] = $value;
        if ($value === '') $errors[$key] = $field['label'] . ' is required.';
        elseif (isset($field['options']) && !in_array($value, $field['options'], true)) $errors[$key] = 'Choose face to face or online.';
        elseif (isset($field['max']) && preg_match_all('/./us', $value) > $field['max']) $errors[$key] = $field['label'] . ' must be ' . $field['max'] . ' characters or fewer.';
        elseif (($field['type'] ?? '') === 'date' && (!synk_faculty_profile_date($value) || $value < '1900-01-01')) {
            $errors[$key] = 'Enter a valid date from 1900 onward.';
        }
    }
    if (!isset($errors['date_from']) && !isset($errors['date_to']) && $record['date_to'] < $record['date_from']) {
        $errors['date_to'] = 'The end date must be on or after the start date.';
    }
    return [$record, $errors];
}

function synk_faculty_profile_training_records(mysqli $conn, string $owner): array
{
    [$type, $id] = synk_faculty_profile_training_owner($owner);
    $stmt = $conn->prepare('SELECT training_id, training_title, agency, date_from, date_to, venue, mode, revision, created_at, updated_at
        FROM tbl_faculty_profile_trainings WHERE owner_type=? AND owner_id=? ORDER BY updated_at DESC, training_id DESC');
    $stmt->bind_param('si', $type, $id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Call inside the controller's ownership-checked transaction.
function synk_faculty_profile_training_save(mysqli $conn, string $owner, array $input, int $trainingId, int $revision): bool
{
    [$record, $errors] = synk_faculty_profile_training_validate($input);
    if ($errors) throw new InvalidArgumentException(implode(' ', $errors));
    if ($trainingId < 0 || $revision < 0 || ($trainingId === 0) !== ($revision === 0)) throw new InvalidArgumentException('Invalid training version.');
    [$type, $id] = synk_faculty_profile_training_owner($owner);
    $keys = array_keys(synk_faculty_profile_training_fields());
    $values = array_map(static function($key) use ($record) {return $record[$key];}, $keys);
    if ($trainingId === 0) {
        $stmt = $conn->prepare('INSERT INTO tbl_faculty_profile_trainings (training_title,agency,date_from,date_to,venue,mode,owner_type,owner_id) VALUES (?,?,?,?,?,?,?,?)');
        $params = array_merge($values, [$type,$id]);
        $stmt->bind_param('sssssssi', ...$params);
    } else {
        $stmt = $conn->prepare('UPDATE tbl_faculty_profile_trainings SET training_title=?,agency=?,date_from=?,date_to=?,venue=?,mode=?,revision=revision+1 WHERE owner_type=? AND owner_id=? AND training_id=? AND revision=?');
        $params = array_merge($values, [$type,$id,$trainingId,$revision]);
        $stmt->bind_param('sssssssiii', ...$params);
    }
    $stmt->execute();
    $saved = $stmt->affected_rows === 1;
    $stmt->close();
    return $saved;
}

function synk_faculty_profile_training_delete(mysqli $conn, string $owner, int $trainingId, int $revision): bool
{
    if ($trainingId < 1 || $revision < 1) throw new InvalidArgumentException('Invalid training version.');
    [$type, $id] = synk_faculty_profile_training_owner($owner);
    $stmt = $conn->prepare('DELETE FROM tbl_faculty_profile_trainings WHERE owner_type=? AND owner_id=? AND training_id=? AND revision=?');
    $stmt->bind_param('siii', $type, $id, $trainingId, $revision);
    $stmt->execute();
    $deleted = $stmt->affected_rows === 1;
    $stmt->close();
    return $deleted;
}
