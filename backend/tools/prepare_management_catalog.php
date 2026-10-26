<?php
// CLI only: validates by default; --apply installs the additive schema and imports.
// Input is produced by extract_management_catalog.cjs, never executable source SQL.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function management_catalog_fields(array $row, array $fields): void
{
    if (array_diff(array_keys($row), $fields) || array_diff($fields, array_keys($row))) {
        throw new RuntimeException('Unexpected catalog fields. Regenerate the input file.');
    }
}

function management_catalog_text($value, int $length): string
{
    if (!is_string($value) || $value === '' || trim($value) !== $value || !mb_check_encoding($value, 'UTF-8')
        || mb_strlen($value, 'UTF-8') > $length || mb_strtoupper($value, 'UTF-8') !== $value
        || preg_match('/[\x00-\x1f\x7f-\x9f\x{fffd}]/u', $value)) {
        throw new RuntimeException('Catalog text must be valid uppercase text within its field length.');
    }
    return $value;
}

function management_catalog_enum($value, array $allowed): string
{
    if (!is_string($value) || !in_array($value, $allowed, true)) throw new RuntimeException('Invalid catalog option.');
    return $value;
}

function management_catalog_integer($value, int $minimum, int $maximum): int
{
    if (!is_int($value) || $value < $minimum || $value > $maximum) throw new RuntimeException('Invalid catalog integer.');
    return $value;
}

function management_catalog_decimal($value, int $precision, int $scale): string
{
    if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]*)(?:\.([0-9]+))?$/D', $value, $match)
        || strlen($match[1]) > $precision - $scale || strlen($match[2] ?? '') > $scale) {
        throw new RuntimeException('Invalid catalog amount; no rounding is performed.');
    }
    return $match[1] . '.' . str_pad($match[2] ?? '', $scale, '0');
}

function management_catalog_date($value, bool $withTime = false): string
{
    $format = $withTime ? 'Y-m-d H:i:s' : 'Y-m-d';
    $date = is_string($value) ? DateTimeImmutable::createFromFormat('!' . $format, $value) : false;
    if (!$date || $date->format($format) !== $value || (int)$date->format('Y') < 1000) throw new RuntimeException('Invalid catalog date or time.');
    return $value;
}

function management_catalog_coordinate($value, int $maximum): ?string
{
    if ($value === null) return null;
    if (!is_string($value) || !preg_match('/^-?[0-9]{1,3}(?:\.[0-9]{1,7})?$/D', $value) || abs((float)$value) > $maximum) {
        throw new RuntimeException('Invalid event coordinate.');
    }
    return $value;
}

function management_catalog_query(mysqli $conn, string $sql, array $values = []): mysqli_stmt
{
    $stmt = $conn->prepare($sql);
    if ($values) {
        $types = ''; $params = [];
        foreach ($values as $key => $value) { $types .= is_int($value) ? 'i' : 's'; $params[$key] = $value; }
        $bindings = [$types];
        foreach ($params as &$param) $bindings[] = &$param;
        unset($param);
        call_user_func_array([$stmt, 'bind_param'], $bindings);
    }
    $stmt->execute();
    return $stmt;
}

function management_catalog_rows(mysqli $conn, string $sql, array $values = []): array
{
    $stmt = management_catalog_query($conn, $sql, $values);
    try { return $stmt->get_result()->fetch_all(MYSQLI_ASSOC); }
    finally { $stmt->close(); }
}

function management_catalog_validate(mysqli $conn, array $manifest): array
{
    management_catalog_fields($manifest, ['format_version','source_sha256','source_counts','normalizations','contributions','events']);
    if ($manifest['format_version'] !== 1 || !is_string($manifest['source_sha256']) || !preg_match('/^[A-F0-9]{64}$/D', $manifest['source_sha256'])) {
        throw new RuntimeException('Invalid catalog manifest.');
    }
    foreach (['source_counts','normalizations','contributions','events'] as $key) {
        if (!is_array($manifest[$key])) throw new RuntimeException('Invalid catalog manifest collection.');
    }
    management_catalog_fields($manifest['source_counts'], ['TBLCONTIBUTION','TBLEVENT','TBLEVENTS']);
    if (!$manifest['contributions'] || !$manifest['events']) throw new RuntimeException('The catalog is empty.');
    $colleges = [];
    foreach (management_catalog_rows($conn, "SELECT c.college_id,c.college_code FROM tbl_college c JOIN tbl_campus cp ON cp.campus_id=c.campus_id WHERE c.status='active' AND cp.status='active'") as $college) {
        $colleges[mb_strtoupper($college['college_code'], 'UTF-8')][] = (int)$college['college_id'];
    }
    $years = [];
    foreach (management_catalog_rows($conn, 'SELECT ay_id,ay FROM tbl_academic_years') as $year) $years[$year['ay']] = (int)$year['ay_id'];
    $seen = []; $counts = ['TBLCONTIBUTION'=>0,'TBLEVENT'=>0,'TBLEVENTS'=>0]; $organizations = []; $unassigned = 0; $sessions = 0;
    $baseFields = ['college_code','organization_code','academic_year','semester','title','source_table','source_record_id','source_checksum'];
    $result = ['contributions'=>[], 'events'=>[]];
    foreach (['contributions','events'] as $group) {
        foreach ($manifest[$group] as $row) {
            if (!is_array($row)) throw new RuntimeException('Invalid catalog record.');
            management_catalog_fields($row, array_merge($baseFields, $group === 'contributions'
                ? ['contribution_date','amount','audience_mode','status','source_metadata']
                : ['event_date','audience_mode','status','absence_fine','latitude','longitude','attendance_radius_m','source_metadata','session']));
            $collegeCode = management_catalog_text($row['college_code'], 20);
            if (count($colleges[$collegeCode] ?? []) !== 1) throw new RuntimeException('College code is missing or ambiguous: ' . $collegeCode);
            $collegeId = $colleges[$collegeCode][0];
            $source = management_catalog_enum($row['source_table'], $group === 'contributions' ? ['TBLCONTIBUTION'] : ['TBLEVENT','TBLEVENTS']);
            $sourceId = management_catalog_integer($row['source_record_id'], 1, 4294967295);
            $identity = $collegeId . ':' . $source . ':' . $sourceId;
            if (isset($seen[$identity])) throw new RuntimeException('Duplicate catalog source identity.');
            $seen[$identity] = true; $counts[$source]++;
            if (!is_string($row['source_checksum']) || !preg_match('/^[A-F0-9]{64}$/D', $row['source_checksum'])) throw new RuntimeException('Invalid source checksum.');
            $timed = $source === 'TBLEVENTS';
            if ($timed) {
                if ($row['academic_year'] !== null || $row['semester'] !== null || $row['organization_code'] !== null) {
                    throw new RuntimeException('Timed source events must retain their unassigned organization and academic term.');
                }
                $ayId = null; $semester = null; $organizationCode = null; $unassigned++;
            } else {
                if (!is_string($row['academic_year']) || !isset($years[$row['academic_year']])) throw new RuntimeException('Academic year is not available in Synk.');
                $ayId = $years[$row['academic_year']];
                $semester = management_catalog_integer($row['semester'], 1, 3);
                $organizationCode = management_catalog_text($row['organization_code'], 30);
                $organizations[$collegeId . ':' . $organizationCode] = ['college_id'=>$collegeId,'organization_code'=>$organizationCode];
            }
            $record = ['college_id'=>$collegeId,'organization_code'=>$organizationCode,'ay_id'=>$ayId,'semester'=>$semester,
                'title'=>management_catalog_text($row['title'], 255),
                'audience_mode'=>management_catalog_enum($row['audience_mode'], ['ALL','SELECTED','UNSPECIFIED']),
                'status'=>management_catalog_enum($row['status'], $timed ? ['OPEN','CLOSED'] : ['DRAFT']),
                'source_table'=>$source,'source_record_id'=>$sourceId,'source_checksum'=>$row['source_checksum']];
            if ($timed) {
                if ($row['source_metadata'] !== null || $record['audience_mode'] !== 'UNSPECIFIED') throw new RuntimeException('Invalid timed event metadata.');
                $record['source_metadata'] = null;
            } else {
                if (!is_array($row['source_metadata'])) throw new RuntimeException('Missing source metadata.');
                management_catalog_fields($row['source_metadata'], $group === 'contributions' ? ['STATUS'] : ['STATUS','STAT']);
                $oldAudience = management_catalog_enum($row['source_metadata']['STATUS'], ['ALL','OTHERS']);
                if ($record['audience_mode'] !== ($oldAudience === 'ALL' ? 'ALL' : 'SELECTED')) throw new RuntimeException('Audience mapping does not match its source.');
                if ($group === 'events') management_catalog_integer($row['source_metadata']['STAT'], -2147483648, 2147483647);
                $record['source_metadata'] = json_encode($row['source_metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            if ($group === 'contributions') {
                $record['event_id'] = null;
                $record['contribution_date'] = management_catalog_date($row['contribution_date']);
                $record['amount'] = management_catalog_decimal($row['amount'], 12, 2);
            } else {
                $record['event_date'] = management_catalog_date($row['event_date']);
                $record['absence_fine'] = $row['absence_fine'] === null ? null : management_catalog_decimal($row['absence_fine'], 12, 2);
                $record['latitude'] = management_catalog_coordinate($row['latitude'], 90);
                $record['longitude'] = management_catalog_coordinate($row['longitude'], 180);
                if (($record['latitude'] === null) !== ($record['longitude'] === null)) throw new RuntimeException('Incomplete event coordinates.');
                $record['attendance_radius_m'] = $row['attendance_radius_m'] === null ? null : management_catalog_integer($row['attendance_radius_m'], 1, 4294967295);
                if ($timed && ($record['absence_fine'] !== null || $record['latitude'] !== null || $record['longitude'] !== null || $record['attendance_radius_m'] !== null)) {
                    throw new RuntimeException('Timed event fields must not be inferred.');
                }
                $record['session'] = null;
                if ($timed) {
                    if (!is_array($row['session'])) throw new RuntimeException('Missing timed event session.');
                    $session = $row['session'];
                    management_catalog_fields($session, ['title','starts_at','ends_at','fine_per_minute','grace_minutes','potential_fine','status']);
                    $session['title'] = management_catalog_text($session['title'], 255);
                    $session['starts_at'] = management_catalog_date($session['starts_at'], true);
                    $session['ends_at'] = management_catalog_date($session['ends_at'], true);
                    if ($session['ends_at'] <= $session['starts_at'] || substr($session['starts_at'], 0, 10) !== $record['event_date'] || substr($session['ends_at'], 0, 10) !== $record['event_date']) {
                        throw new RuntimeException('Invalid session period.');
                    }
                    $session['fine_per_minute'] = management_catalog_decimal($session['fine_per_minute'], 12, 4);
                    $session['grace_minutes'] = management_catalog_integer($session['grace_minutes'], 0, 65535);
                    $session['potential_fine'] = management_catalog_decimal($session['potential_fine'], 12, 2);
                    if ($session['status'] !== $record['status']) throw new RuntimeException('Session and source event status differ.');
                    $record['session'] = $session; $sessions++;
                } elseif ($row['session'] !== null) throw new RuntimeException('An untimed source event cannot contain a session.');
            }
            $result[$group][] = $record;
        }
    }
    foreach ($counts as $source=>$count) {
        if (management_catalog_integer($manifest['source_counts'][$source], 1, 4294967295) !== $count) throw new RuntimeException('Source row totals do not match.');
    }
    $result['organizations'] = array_values($organizations);
    $result['summary'] = ['database'=>management_catalog_rows($conn, 'SELECT DATABASE() AS db')[0]['db'],
        'source_counts'=>$counts,'organizations'=>count($organizations),'contributions'=>count($result['contributions']),
        'events'=>count($result['events']),'sessions'=>$sessions,'events_awaiting_organization_and_term'=>$unassigned,
        'source_sha256'=>$manifest['source_sha256'],'text_encoding_repairs'=>count($manifest['normalizations'])];
    return $result;
}

function management_catalog_insert(mysqli $conn, string $table, string $primaryKey, array $record, array &$counts): int
{
    $existing = management_catalog_rows($conn, 'SELECT `' . $primaryKey . '`,source_checksum FROM `' . $table . '` WHERE college_id=? AND source_table=? AND source_record_id=? FOR UPDATE',
        [$record['college_id'],$record['source_table'],$record['source_record_id']]);
    if ($existing) {
        if (!hash_equals((string)$existing[0]['source_checksum'], $record['source_checksum'])) throw new RuntimeException('Source changed for ' . $record['source_table'] . ':' . $record['source_record_id'] . '. Existing data was preserved.');
        $counts[$table]['preserved']++;
        return (int)$existing[0][$primaryKey];
    }
    $fields = array_keys($record);
    $stmt = management_catalog_query($conn, 'INSERT INTO `' . $table . '` (`' . implode('`,`', $fields) . '`) VALUES (' . implode(',', array_fill(0, count($fields), '?')) . ')', array_values($record));
    $id = (int)$conn->insert_id; $stmt->close();
    $counts[$table]['inserted']++;
    return $id;
}

function management_catalog_apply(mysqli $conn, array $data): array
{
    $schema = file_get_contents(__DIR__ . '/../sql/create_management_catalog.sql');
    if ($schema === false) throw new RuntimeException('Management catalog schema is unavailable.');
    $schema = preg_replace('/^\s*--[^\r\n]*$/m', '', $schema);
    foreach (preg_split('/;[ \t]*(?:\r?\n|$)/', trim($schema)) as $statement) {
        if (trim($statement) !== '') $conn->query($statement);
    }
    $counts = [];
    foreach (['tbl_management_organizations','tbl_management_contributions','tbl_management_events','tbl_management_event_sessions'] as $table) {
        $counts[$table] = ['inserted'=>0,'preserved'=>0];
    }
    $conn->begin_transaction();
    try {
        $organizationIds = [];
        foreach ($data['organizations'] as $organization) {
            $key = $organization['college_id'] . ':' . $organization['organization_code'];
            $existing = management_catalog_rows($conn, 'SELECT organization_id FROM tbl_management_organizations WHERE college_id=? AND organization_code=? FOR UPDATE', array_values($organization));
            if ($existing) {
                $organizationIds[$key] = (int)$existing[0]['organization_id']; $counts['tbl_management_organizations']['preserved']++;
            } else {
                $stmt = management_catalog_query($conn, 'INSERT INTO tbl_management_organizations (college_id,organization_code,organization_name) VALUES (?,?,?)',
                    [$organization['college_id'],$organization['organization_code'],$organization['organization_code']]);
                $organizationIds[$key] = (int)$conn->insert_id; $stmt->close(); $counts['tbl_management_organizations']['inserted']++;
            }
        }
        foreach (['events','contributions'] as $group) {
            foreach ($data[$group] as $record) {
                $session = $record['session'] ?? null;
                unset($record['session']);
                $code = $record['organization_code']; unset($record['organization_code']);
                $record['organization_id'] = $code === null ? null : $organizationIds[$record['college_id'] . ':' . $code];
                $id = management_catalog_insert($conn, 'tbl_management_' . $group, $group === 'events' ? 'event_id' : 'contribution_id', $record, $counts);
                if ($session !== null) {
                    $session['college_id'] = $record['college_id']; $session['event_id'] = $id;
                    foreach (['source_table','source_record_id','source_checksum'] as $key) $session[$key] = $record[$key];
                    management_catalog_insert($conn, 'tbl_management_event_sessions', 'session_id', $session, $counts);
                }
            }
        }
        $conn->commit();
    } catch (Throwable $error) { $conn->rollback(); throw $error; }
    return $counts;
}

$conn = null; $lockName = null;
try {
    $input = $argv[1] ?? '';
    $apply = ($argv[2] ?? '') === '--apply';
    if (!$input || count($argv) > 3 || (isset($argv[2]) && !$apply)) throw new RuntimeException('Usage: php backend/tools/prepare_management_catalog.php <catalog.json> [--apply]');
    if (!extension_loaded('mysqli') || !extension_loaded('mbstring')) throw new RuntimeException('The importer requires mysqli and mbstring.');
    if (!is_file($input) || !is_readable($input) || filesize($input) > 10 * 1024 * 1024) throw new RuntimeException('Input must be a readable catalog JSON file up to 10 MB.');
    $manifest = json_decode(file_get_contents($input), true);
    if (!is_array($manifest) || json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('Unable to decode the catalog JSON file.');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require __DIR__ . '/../db.php';
    $data = management_catalog_validate($conn, $manifest);
    $report = ['mode'=>$apply ? 'APPLIED' : 'DRY RUN','catalog'=>$data['summary']];
    if ($apply) {
        $lockName = 'SYNK_MANAGEMENT_CATALOG_' . substr(hash('sha256', $data['summary']['database']), 0, 16);
        if ((int)management_catalog_rows($conn, 'SELECT GET_LOCK(?,10) AS acquired', [$lockName])[0]['acquired'] !== 1) throw new RuntimeException('Another Management catalog import is running.');
        $report['result'] = management_catalog_apply($conn, $data);
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Management catalog: ' . $error->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if ($conn instanceof mysqli && $lockName !== null) {
        $stmt = management_catalog_query($conn, 'SELECT RELEASE_LOCK(?)', [$lockName]); $stmt->close();
    }
}
