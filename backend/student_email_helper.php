<?php
require_once __DIR__ . '/student_management_helper.php';

// Email addresses use the complete stored first and last names, never middle names.
function synk_student_email_candidate(array $student): array
{
    if (trim((string)($student['email_address'] ?? '')) !== '') {
        return ['email'=>(string)$student['email_address'], 'status'=>'preserved', 'reason'=>'Existing email will be kept.'];
    }
    $parts=[];
    foreach (['first_name','last_name'] as $field) {
        $name=trim((string)($student[$field] ?? ''));
        if ($name === '' || !preg_match('/^[\p{L}\p{M}]+(?:[\s.\x{2019}\x{0027}-]+[\p{L}\p{M}]+)*\.?$/u',$name)) {
            return ['email'=>'','status'=>'review','reason'=>'Verify the complete first and last names; letters are missing or damaged.'];
        }
        $folded=synk_student_management_fold_email_name(synk_student_identity_utf8($name));
        $folded=preg_replace('/\p{M}/u','',$folded);
        if (preg_match('/[^a-zA-Z\s.\x{2019}\x{0027}-]/u',$folded)) {
            return ['email'=>'','status'=>'review','reason'=>'Confirm the school email spelling for this name.'];
        }
        if ($field === 'first_name' && preg_match('/(?:^|[\s.]+)[a-zA-Z](?:[\s.]|$)/',$folded)) {
            return ['email'=>'','status'=>'review','reason'=>'A first name appears to be an initial. Verify the complete given names.'];
        }
        $parts[]=synk_student_management_ascii_token($folded);
    }
    $email=implode('',$parts) . '@sksu.edu.ph';
    if (strlen(implode('',$parts)) > 64 || !$parts[0] || !$parts[1] || !filter_var($email,FILTER_VALIDATE_EMAIL)) {
        return ['email'=>'','status'=>'review','reason'=>'The name does not produce a valid school email. Confirm its actual address.'];
    }
    return ['email'=>$email,'status'=>'ready','reason'=>'Uses the complete first name and last name; confirm the actual school address.'];
}

function synk_student_email_catalog(mysqli $conn, bool $lock=false): array
{
    $result=$conn->query('SELECT student_id,student_number,first_name,last_name,middle_name,suffix_name,email_address FROM tbl_student_management ORDER BY student_id' . ($lock ? ' FOR UPDATE' : ''));
    if (!$result) throw new RuntimeException('Unable to check student email identities.');
    $catalog=['students'=>[], 'owners'=>[]];
    while ($student=$result->fetch_assoc()) {
        $id=(int)$student['student_id']; $catalog['students'][$id]=$student;
        $candidate=synk_student_email_candidate($student);
        if ($candidate['email'] !== '') $catalog['owners'][strtolower(trim($candidate['email']))][(string)$student['student_number']]=true;
    }
    return $catalog;
}

// Include proposed blank addresses as well as existing ones, so neither namesake wins arbitrarily.
function synk_student_email_check(array $student, array $owners): array
{
    $candidate=synk_student_email_candidate($student);
    if ($candidate['status'] !== 'ready') return $candidate;
    $number=(string)$student['student_number'];
    foreach (array_keys($owners[$candidate['email']] ?? []) as $owner) {
        if ((string)$owner !== $number) {
            $candidate['status']='review';
            $candidate['reason']='Another student has or would receive this address. Confirm the actual school emails; no suffix will be invented.';
            break;
        }
    }
    return $candidate;
}

function synk_student_email_lock(mysqli $conn): void
{
    $row=$conn->query("SELECT GET_LOCK('synk_student_email_assignment',5) AS acquired")->fetch_assoc();
    if ((int)$row['acquired'] !== 1) throw new DomainException('Another student email update is being saved. Try again shortly.');
}

function synk_student_email_unlock(mysqli $conn): void
{
    $conn->query("SELECT RELEASE_LOCK('synk_student_email_assignment')");
}

function synk_student_email_fill(mysqli $conn, int $studentId, string $email): void
{
    $stmt=$conn->prepare("UPDATE tbl_student_management SET email_address=? WHERE student_id=? AND (email_address IS NULL OR TRIM(email_address)='')");
    $stmt->bind_param('si',$email,$studentId);
    if (!$stmt->execute() || $stmt->affected_rows !== 1) { $stmt->close(); throw new DomainException('A student email changed. Preview the missing emails again.'); }
    $stmt->close();
}
