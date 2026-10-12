<?php

require_once __DIR__ . '/schema_helper.php';

function synk_faculty_record_profiles_ready(mysqli $conn): bool
{
    $result = $conn->query("SHOW TABLES LIKE 'tbl_faculty_record_profiles'");
    $ready = $result instanceof mysqli_result && $result->num_rows > 0;
    if ($result instanceof mysqli_result) $result->close();
    return $ready;
}

function synk_faculty_record_profiles_ensure(mysqli $conn): void
{
    if (!synk_faculty_record_profiles_ready($conn)) {
        $sql = file_get_contents(__DIR__ . '/sql/phase22_faculty_record_profiles.sql');
        if ($sql === false || !$conn->query($sql)) throw new RuntimeException('Faculty profile storage could not be initialized.');
    }
}

function synk_faculty_record_profile_decode(array $row): array
{
    $data = json_decode((string)$row['profile_data'], true);
    if (!is_array($data)) throw new RuntimeException('Faculty profile data could not be read.');
    $data['revision'] = (int)$row['revision'];
    $data['created_at'] = $row['created_at'];
    $data['updated_at'] = $row['updated_at'];
    $data['_storage'] = 'faculty';
    $data['_faculty_id'] = (int)$row['faculty_id'];
    return $data;
}

function synk_faculty_record_profile_fetch(mysqli $conn, int $facultyId): ?array
{
    if ($facultyId <= 0 || !synk_faculty_record_profiles_ready($conn)) return null;
    $stmt = $conn->prepare('SELECT * FROM tbl_faculty_record_profiles WHERE faculty_id=?');
    $stmt->bind_param('i', $facultyId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? synk_faculty_record_profile_decode($row) : null;
}

function synk_faculty_record_profile_for_account(mysqli $conn, int $userId): ?array
{
    if (!synk_faculty_record_profiles_ready($conn) || !synk_table_exists($conn, 'tbl_useraccount_faculty_links')) return null;
    $stmt = $conn->prepare('SELECT p.* FROM tbl_faculty_record_profiles p
        JOIN tbl_useraccount_faculty_links l ON l.faculty_id=p.faculty_id WHERE l.user_id=? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? synk_faculty_record_profile_decode($row) : null;
}

// Caller owns the transaction and verifies the actor's access to this faculty record.
function synk_faculty_record_profile_save(mysqli $conn, int $facultyId, array $data, int $revision, int $actorId): bool
{
    if ($facultyId <= 0 || $actorId <= 0 || $revision < 0) throw new InvalidArgumentException('Invalid faculty profile identity.');
    $identity = $conn->prepare('SELECT faculty_id FROM tbl_faculty WHERE faculty_id=? FOR UPDATE');
    $identity->bind_param('i', $facultyId);
    $identity->execute();
    $exists = $identity->get_result()->num_rows === 1;
    $identity->close();
    if (!$exists) throw new InvalidArgumentException('The faculty record no longer exists.');
    $existing = synk_faculty_record_profile_fetch($conn, $facultyId) ?? [];
    [$values, $errors] = synk_faculty_profile_validate($data, new DateTimeImmutable('today'),
        synk_faculty_profile_ranks($conn), $existing, synk_faculty_profile_eligibility_catalog($conn), synk_faculty_profile_locations($conn));
    if ($errors) throw new InvalidArgumentException(implode(' ', $errors));
    $json = json_encode($values, JSON_UNESCAPED_UNICODE);
    if ($json === false) throw new RuntimeException('Faculty profile could not be encoded.');
    if ($revision === 0) {
        $stmt = $conn->prepare('INSERT INTO tbl_faculty_record_profiles (faculty_id,profile_data,updated_by) VALUES (?,?,?)');
        $stmt->bind_param('isi', $facultyId, $json, $actorId);
    } else {
        $stmt = $conn->prepare('UPDATE tbl_faculty_record_profiles SET profile_data=?,revision=revision+1,updated_at=CURRENT_TIMESTAMP,updated_by=?
            WHERE faculty_id=? AND revision=?');
        $stmt->bind_param('siii', $json, $actorId, $facultyId, $revision);
    }
    try {
        $stmt->execute();
        if ($stmt->affected_rows !== 1) return false;
        synk_faculty_profile_share_education($conn, $values);
        return true;
    } catch (mysqli_sql_exception $exception) {
        if ($exception->getCode() === 1062) return false;
        throw $exception;
    } finally {
        $stmt->close();
    }
}
