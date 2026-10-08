<?php
require_once __DIR__ . '/schema_helper.php';

// Institution IDs are identifiers, not numbers to calculate with.
function synk_student_identity_number($value): string
{
    if (!is_scalar($value)) throw new DomainException('Provide a valid student ID.');
    $value = trim((string)$value);
    if (!preg_match('/^[0-9]{1,32}$/D', $value) || preg_match('/^0+$/D', $value)) {
        throw new DomainException('Student IDs must contain 1–32 digits and cannot be zero.');
    }
    return $value;
}

function synk_student_identity_utf8(string $value): string
{
    if (preg_match('//u', $value) !== 1) throw new DomainException('Text contains invalid characters. Save the CSV as UTF-8 and try again.');
    if (class_exists('Normalizer')) return Normalizer::normalize($value, Normalizer::FORM_C);
    // The installed PHP has no intl extension. Compose common Latin accents without removing them.
    $pairs = [
        "A\u{0300}"=>'À',"a\u{0300}"=>'à',"A\u{0301}"=>'Á',"a\u{0301}"=>'á',"A\u{0302}"=>'Â',"a\u{0302}"=>'â',
        "A\u{0303}"=>'Ã',"a\u{0303}"=>'ã',"A\u{0308}"=>'Ä',"a\u{0308}"=>'ä',"A\u{030A}"=>'Å',"a\u{030A}"=>'å',
        "E\u{0300}"=>'È',"e\u{0300}"=>'è',"E\u{0301}"=>'É',"e\u{0301}"=>'é',"E\u{0302}"=>'Ê',"e\u{0302}"=>'ê',"E\u{0308}"=>'Ë',"e\u{0308}"=>'ë',
        "I\u{0300}"=>'Ì',"i\u{0300}"=>'ì',"I\u{0301}"=>'Í',"i\u{0301}"=>'í',"I\u{0302}"=>'Î',"i\u{0302}"=>'î',"I\u{0308}"=>'Ï',"i\u{0308}"=>'ï',
        "O\u{0300}"=>'Ò',"o\u{0300}"=>'ò',"O\u{0301}"=>'Ó',"o\u{0301}"=>'ó',"O\u{0302}"=>'Ô',"o\u{0302}"=>'ô',"O\u{0303}"=>'Õ',"o\u{0303}"=>'õ',"O\u{0308}"=>'Ö',"o\u{0308}"=>'ö',
        "U\u{0300}"=>'Ù',"u\u{0300}"=>'ù',"U\u{0301}"=>'Ú',"u\u{0301}"=>'ú',"U\u{0302}"=>'Û',"u\u{0302}"=>'û',"U\u{0308}"=>'Ü',"u\u{0308}"=>'ü',
        "N\u{0303}"=>'Ñ',"n\u{0303}"=>'ñ',"C\u{0327}"=>'Ç',"c\u{0327}"=>'ç',"Y\u{0301}"=>'Ý',"y\u{0301}"=>'ý',"Y\u{0308}"=>'Ÿ',"y\u{0308}"=>'ÿ',
    ];
    return strtr($value, $pairs);
}

function synk_student_identity_name_key(string $value): string
{
    $value = synk_student_identity_utf8($value);
    $value = function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper(strtr($value, [
        'ñ'=>'Ñ','á'=>'Á','é'=>'É','í'=>'Í','ó'=>'Ó','ú'=>'Ú','ü'=>'Ü','ç'=>'Ç','à'=>'À','è'=>'È','â'=>'Â','ê'=>'Ê','ô'=>'Ô','ö'=>'Ö',
    ]));
    return trim((string)preg_replace('/[^\p{L}\p{N}\p{M}]+/u', ' ', $value));
}

function synk_student_identity_names_match(array $incoming, array $existing): bool
{
    if (synk_student_identity_name_key($incoming['last_name']) !== synk_student_identity_name_key($existing['last_name'])) return false;
    $first = synk_student_identity_name_key($incoming['first_name']);
    $storedFirst = synk_student_identity_name_key($existing['first_name']);
    $middle = synk_student_identity_name_key($incoming['middle_name'] ?? '');
    $storedMiddle = synk_student_identity_name_key($existing['middle_name'] ?? '');
    $sameGiven = trim($first . ' ' . $middle) === trim($storedFirst . ' ' . $storedMiddle);
    if ($first !== $storedFirst && !$sameGiven) return false;
    if (!$sameGiven && $middle !== '' && $storedMiddle !== '' && $middle !== $storedMiddle) {
        if (!preg_match('/^.$/us',$middle) || !preg_match('/^./us',$storedMiddle,$initial) || $initial[0] !== $middle) return false;
    }
    $suffix = synk_student_identity_name_key($incoming['suffix_name'] ?? '');
    $storedSuffix = synk_student_identity_name_key($existing['suffix_name'] ?? '');
    return $suffix === '' || $storedSuffix === '' || $suffix === $storedSuffix;
}

function synk_student_identity_schema_ready(mysqli $conn): bool
{
    return synk_table_has_index($conn, 'tbl_student_management', 'uk_student_identity_number')
        && synk_table_has_index($conn, 'tbl_student_management_enrolled_subjects', 'uk_student_active_offering')
        && synk_table_has_index($conn, 'tbl_student_management_enrolled_subjects', 'uk_student_active_class');
}

function synk_student_identity_find(mysqli $conn, string $number, bool $lock = false): ?array
{
    $stmt = $conn->prepare('SELECT * FROM tbl_student_management WHERE student_number=?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->bind_param('s', $number);
    if (!$stmt->execute()) throw new RuntimeException('Unable to look up the student ID.');
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $row ?: null;
}

function synk_student_identity_create_or_reuse(mysqli $conn, array $record): array
{
    $record['student_number'] = synk_student_identity_number($record['student_number']);
    $existing = synk_student_identity_find($conn, $record['student_number'], true);
    if (!$existing) {
        try {
            return ['student_id'=>synk_student_identity_insert_record($conn, $record), 'created'=>true];
        } catch (Throwable $e) {
            if ((int)$e->getCode() !== 1062 && $conn->errno !== 1062) throw $e;
            // Another import may have created this ID while this transaction was waiting.
            $existing = synk_student_identity_find($conn, $record['student_number'], true);
            if (!$existing) throw $e;
        }
    }
    if (!synk_student_identity_names_match($record, $existing)) throw new DomainException('Student ID ' . $record['student_number'] . ' has a conflicting name. Review the student profile first.');
    return ['student_id'=>(int)$existing['student_id'], 'created'=>false];
}

function synk_student_identity_insert_record(mysqli $conn, array $record): int
{
    $record['student_number'] = synk_student_identity_number($record['student_number']);
    foreach (['last_name'=>150,'first_name'=>150,'middle_name'=>150,'suffix_name'=>50] as $field=>$limit) {
        $record[$field] = synk_student_identity_utf8((string)$record[$field]);
        $length = function_exists('mb_strlen') ? mb_strlen($record[$field],'UTF-8') : strlen($record[$field]);
        if ($length > $limit || preg_match('/\p{Cc}/u',$record[$field])) throw new DomainException('The student name contains invalid characters or is too long.');
    }
    if (trim($record['last_name']) === '' || trim($record['first_name']) === '') throw new DomainException('The student first and last names are required.');
    $fields = ['ay_id','semester','source_sheet_name','source_file_name','year_level','student_number',
        'last_name','first_name','middle_name','suffix_name','email_address','program_id','uploaded_by','source_row_number'];
    $values = [];
    foreach ($fields as $field) $values[] = $record[$field];
    $stmt = $conn->prepare('INSERT INTO tbl_student_management (`' . implode('`,`',$fields) . '`) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    synk_bind_dynamic_params($stmt,'iississssssiii',$values);
    try {
        if (!$stmt->execute()) throw new RuntimeException('Unable to insert the student profile.', $stmt->errno);
        return (int)$stmt->insert_id;
    } finally { $stmt->close(); }
}

function synk_student_identity_csv_utf8(string $bytes): string
{
    $encoding = '';
    if (substr($bytes,0,3) === "\xEF\xBB\xBF") $bytes = substr($bytes,3);
    elseif (substr($bytes,0,2) === "\xFF\xFE") { $encoding='UTF-16LE'; $bytes=substr($bytes,2); }
    elseif (substr($bytes,0,2) === "\xFE\xFF") { $encoding='UTF-16BE'; $bytes=substr($bytes,2); }
    elseif (preg_match('//u',$bytes) !== 1) $encoding='Windows-1252';
    if ($encoding !== '') {
        $converted = function_exists('iconv') ? @iconv($encoding,'UTF-8',$bytes) : false;
        if (!is_string($converted)) throw new DomainException('Unable to decode this CSV. Save it as CSV UTF-8 and try again.');
        $bytes = $converted;
    }
    if (strpos($bytes,"\0") !== false) throw new DomainException('This CSV encoding is unsupported. Save it as CSV UTF-8 and try again.');
    return synk_student_identity_utf8($bytes);
}
