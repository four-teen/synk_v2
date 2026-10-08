<?php

require_once __DIR__ . '/schema_helper.php';

function synk_schedule_policy_day_order(): array
{
    return ['M', 'T', 'W', 'Th', 'F', 'S'];
}

function synk_schedule_policy_defaults(): array
{
    return [
        'day_start' => '07:30:00',
        'day_end' => '17:30:00',
        'blocked_days' => [],
        'blocked_times' => [],
    ];
}

function synk_schedule_policy_time_input(string $timeValue): string
{
    $timeValue = trim($timeValue);
    if ($timeValue === '') {
        return '';
    }

    return substr($timeValue, 0, 5);
}

function synk_schedule_policy_time_label(string $timeValue): string
{
    $timestamp = strtotime('1970-01-01 ' . trim($timeValue));
    if ($timestamp === false) {
        return trim($timeValue);
    }

    return date('g:i A', $timestamp);
}

function synk_schedule_policy_window_label(string $start, string $end): string
{
    return synk_schedule_policy_time_label($start) . ' to ' . synk_schedule_policy_time_label($end);
}

function synk_schedule_policy_normalize_time(?string $value, string $fallback = ''): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return $fallback;
    }

    if (!preg_match('/^\d{2}:\d{2}(?::\d{2})?$/', $value)) {
        return $fallback;
    }

    return strlen($value) === 5 ? ($value . ':00') : $value;
}

function synk_schedule_policy_normalize_days($days): array
{
    if (is_string($days) && trim($days) !== '') {
        $decoded = json_decode($days, true);
        $days = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($days)) {
        return [];
    }

    $allowed = synk_schedule_policy_day_order();
    $seen = [];

    foreach ($days as $day) {
        $token = strtoupper(trim((string)$day));
        if ($token === 'TH') {
            $token = 'Th';
        }

        if ($token !== '' && in_array($token, $allowed, true)) {
            $seen[$token] = true;
        }
    }

    $normalized = [];
    foreach ($allowed as $token) {
        if (isset($seen[$token])) {
            $normalized[] = $token;
        }
    }

    return $normalized;
}

function synk_schedule_policy_decode_blocked_times($raw): array
{
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : [];
    }

    return is_array($raw) ? $raw : [];
}

function synk_schedule_policy_normalize_blocked_times($raw, string $dayStart, string $dayEnd): array
{
    $rows = synk_schedule_policy_decode_blocked_times($raw);
    $normalized = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $start = synk_schedule_policy_normalize_time((string)($row['start'] ?? ''), '');
        $end = synk_schedule_policy_normalize_time((string)($row['end'] ?? ''), '');

        if ($start === '' || $end === '' || $end <= $start) {
            continue;
        }

        if ($start < $dayStart || $end > $dayEnd) {
            continue;
        }

        $normalized[] = [
            'start' => $start,
            'end' => $end,
        ];
    }

    usort($normalized, static function (array $left, array $right): int {
        if ($left['start'] === $right['start']) {
            return strcmp($left['end'], $right['end']);
        }

        return strcmp($left['start'], $right['start']);
    });

    $deduped = [];
    $seen = [];
    foreach ($normalized as $row) {
        $key = $row['start'] . '|' . $row['end'];
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $deduped[] = $row;
    }

    return $deduped;
}

function synk_schedule_policy_payload_to_array(array $policy): array
{
    $dayStart = synk_schedule_policy_normalize_time((string)($policy['day_start'] ?? ''), '07:30:00');
    $dayEnd = synk_schedule_policy_normalize_time((string)($policy['day_end'] ?? ''), '17:30:00');

    if ($dayEnd <= $dayStart) {
        $defaults = synk_schedule_policy_defaults();
        $dayStart = $defaults['day_start'];
        $dayEnd = $defaults['day_end'];
    }

    return [
        'day_start' => $dayStart,
        'day_end' => $dayEnd,
        'blocked_days' => synk_schedule_policy_normalize_days($policy['blocked_days'] ?? []),
        'blocked_times' => synk_schedule_policy_normalize_blocked_times(
            $policy['blocked_times'] ?? [],
            $dayStart,
            $dayEnd
        ),
    ];
}

function synk_schedule_policy_blocked_time_overlap(string $timeStart, string $timeEnd, array $policy): ?array
{
    foreach (($policy['blocked_times'] ?? []) as $blockedTime) {
        $blockedStart = (string)($blockedTime['start'] ?? '');
        $blockedEnd = (string)($blockedTime['end'] ?? '');

        if ($blockedStart === '' || $blockedEnd === '') {
            continue;
        }

        if ($timeStart < $blockedEnd && $timeEnd > $blockedStart) {
            return $blockedTime;
        }
    }

    return null;
}

function synk_schedule_policy_disallowed_days(array $days, array $policy): array
{
    $blockedDayMap = array_fill_keys($policy['blocked_days'] ?? [], true);
    $disallowed = [];

    foreach (synk_schedule_policy_normalize_days($days) as $day) {
        if (isset($blockedDayMap[$day])) {
            $disallowed[] = $day;
        }
    }

    return $disallowed;
}

function synk_schedule_policy_is_within_window(string $timeStart, string $timeEnd, array $policy): bool
{
    return $timeStart >= (string)($policy['day_start'] ?? '')
        && $timeEnd <= (string)($policy['day_end'] ?? '');
}

function synk_schedule_policy_format(array $policy): array
{
    $policy = synk_schedule_policy_payload_to_array($policy);
    $blockedTimes = [];

    foreach (($policy['blocked_times'] ?? []) as $blockedTime) {
        $start = (string)($blockedTime['start'] ?? '');
        $end = (string)($blockedTime['end'] ?? '');
        if ($start === '' || $end === '') {
            continue;
        }

        $blockedTimes[] = [
            'start' => $start,
            'end' => $end,
            'start_input' => synk_schedule_policy_time_input($start),
            'end_input' => synk_schedule_policy_time_input($end),
            'label' => synk_schedule_policy_window_label($start, $end),
        ];
    }

    return [
        'day_start' => $policy['day_start'],
        'day_end' => $policy['day_end'],
        'day_start_input' => synk_schedule_policy_time_input($policy['day_start']),
        'day_end_input' => synk_schedule_policy_time_input($policy['day_end']),
        'window_label' => synk_schedule_policy_window_label($policy['day_start'], $policy['day_end']),
        'blocked_days' => $policy['blocked_days'],
        'blocked_days_label' => empty($policy['blocked_days']) ? 'None' : implode(', ', $policy['blocked_days']),
        'blocked_times' => $blockedTimes,
        'blocked_times_label' => empty($blockedTimes)
            ? 'None'
            : implode(', ', array_column($blockedTimes, 'label')),
    ];
}

function synk_schedule_policy_validate_payload(array $payload): array
{
    $dayStart = synk_schedule_policy_normalize_time((string)($payload['schedule_day_start'] ?? ''), '');
    $dayEnd = synk_schedule_policy_normalize_time((string)($payload['schedule_day_end'] ?? ''), '');
    $blockedDays = synk_schedule_policy_normalize_days($payload['blocked_days_json'] ?? '[]');
    $blockedTimesRaw = synk_schedule_policy_decode_blocked_times($payload['blocked_times_json'] ?? '[]');

    if ($dayStart === '' || $dayEnd === '' || $dayEnd <= $dayStart) {
        return [
            'ok' => false,
            'message' => 'Invalid schedule window. End time must be later than start time.'
        ];
    }

    $normalizedBlockedTimes = [];
    foreach ($blockedTimesRaw as $entry) {
        if (!is_array($entry)) {
            return [
                'ok' => false,
                'message' => 'Invalid blocked time entry.'
            ];
        }

        $start = synk_schedule_policy_normalize_time((string)($entry['start'] ?? ''), '');
        $end = synk_schedule_policy_normalize_time((string)($entry['end'] ?? ''), '');

        if ($start === '' || $end === '') {
            return [
                'ok' => false,
                'message' => 'Each blocked time range must include a start and end time.'
            ];
        }

        if ($end <= $start) {
            return [
                'ok' => false,
                'message' => 'Each blocked time range must end later than it starts.'
            ];
        }

        if ($start < $dayStart || $end > $dayEnd) {
            return [
                'ok' => false,
                'message' => 'Blocked time ranges must stay inside the allowed daily scheduling window.'
            ];
        }

        $normalizedBlockedTimes[] = [
            'start' => $start,
            'end' => $end,
        ];
    }

    $normalizedBlockedTimes = synk_schedule_policy_normalize_blocked_times(
        $normalizedBlockedTimes,
        $dayStart,
        $dayEnd
    );

    for ($i = 1; $i < count($normalizedBlockedTimes); $i++) {
        $previous = $normalizedBlockedTimes[$i - 1];
        $current = $normalizedBlockedTimes[$i];

        if ((string)$current['start'] < (string)$previous['end']) {
            return [
                'ok' => false,
                'message' => 'Blocked time ranges must not overlap each other.'
            ];
        }
    }

    return [
        'ok' => true,
        'policy' => [
            'day_start' => $dayStart,
            'day_end' => $dayEnd,
            'blocked_days' => $blockedDays,
            'blocked_times' => $normalizedBlockedTimes,
        ]
    ];
}

function synk_schedule_policy_ensure_columns(mysqli $conn): void
{
    static $ensured = false;

    if ($ensured || !synk_table_exists($conn, 'tbl_academic_settings')) {
        $ensured = true;
        return;
    }

    $columns = [];
    $result = $conn->query("SHOW COLUMNS FROM `tbl_academic_settings`");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $fieldName = strtolower(trim((string)($row['Field'] ?? '')));
            if ($fieldName !== '') {
                $columns[$fieldName] = true;
            }
        }
    }

    $clauses = [];
    if (!isset($columns['schedule_day_start'])) {
        $clauses[] = "ADD COLUMN schedule_day_start TIME NOT NULL DEFAULT '07:30:00'";
    }
    if (!isset($columns['schedule_day_end'])) {
        $clauses[] = "ADD COLUMN schedule_day_end TIME NOT NULL DEFAULT '17:30:00'";
    }
    if (!isset($columns['blocked_schedule_days_json'])) {
        $clauses[] = "ADD COLUMN blocked_schedule_days_json TEXT NULL";
    }
    if (!isset($columns['blocked_schedule_times_json'])) {
        $clauses[] = "ADD COLUMN blocked_schedule_times_json LONGTEXT NULL";
    }

    if (!empty($clauses)) {
        $conn->query("ALTER TABLE `tbl_academic_settings` " . implode(', ', $clauses));
    }

    $ensured = true;
}

function synk_college_schedule_policy_table_name(): string
{
    return 'tbl_college_schedule_policy_overrides';
}

function synk_college_schedule_policy_ensure_table(mysqli $conn): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $tableName = synk_college_schedule_policy_table_name();
    if (!synk_table_exists($conn, $tableName)) {
        $conn->query("
            CREATE TABLE IF NOT EXISTS `{$tableName}` (
                `college_policy_override_id` INT NOT NULL AUTO_INCREMENT,
                `college_id` INT NOT NULL,
                `is_override_enabled` TINYINT(1) NOT NULL DEFAULT 0,
                `schedule_day_start` TIME NOT NULL DEFAULT '07:30:00',
                `schedule_day_end` TIME NOT NULL DEFAULT '17:30:00',
                `blocked_schedule_days_json` TEXT NULL,
                `blocked_schedule_times_json` LONGTEXT NULL,
                `created_by` INT NULL,
                `updated_by` INT NULL,
                `date_created` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `date_updated` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`college_policy_override_id`),
                UNIQUE KEY `uniq_college_schedule_policy_override_college` (`college_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    $columns = synk_describe_columns($conn, $tableName);
    $clauses = [];

    if (!isset($columns['is_override_enabled'])) {
        $clauses[] = "ADD COLUMN is_override_enabled TINYINT(1) NOT NULL DEFAULT 0";
    }
    if (!isset($columns['schedule_day_start'])) {
        $clauses[] = "ADD COLUMN schedule_day_start TIME NOT NULL DEFAULT '07:30:00'";
    }
    if (!isset($columns['schedule_day_end'])) {
        $clauses[] = "ADD COLUMN schedule_day_end TIME NOT NULL DEFAULT '17:30:00'";
    }
    if (!isset($columns['blocked_schedule_days_json'])) {
        $clauses[] = "ADD COLUMN blocked_schedule_days_json TEXT NULL";
    }
    if (!isset($columns['blocked_schedule_times_json'])) {
        $clauses[] = "ADD COLUMN blocked_schedule_times_json LONGTEXT NULL";
    }
    if (!isset($columns['updated_by'])) {
        $clauses[] = "ADD COLUMN updated_by INT NULL";
    }
    if (!isset($columns['date_updated'])) {
        $clauses[] = "ADD COLUMN date_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP";
    }

    if (!empty($clauses)) {
        $conn->query("ALTER TABLE `{$tableName}` " . implode(', ', $clauses));
    }

    $ensured = true;
}

function synk_fetch_schedule_policy_row(mysqli $conn): ?array
{
    synk_schedule_policy_ensure_columns($conn);

    if (!synk_table_exists($conn, 'tbl_academic_settings')) {
        return null;
    }

    $result = $conn->query("
        SELECT
            schedule_day_start,
            schedule_day_end,
            blocked_schedule_days_json,
            blocked_schedule_times_json
        FROM tbl_academic_settings
        LIMIT 1
    ");

    if (!($result instanceof mysqli_result) || $result->num_rows === 0) {
        return null;
    }

    return $result->fetch_assoc() ?: null;
}

function synk_build_schedule_policy_from_row(?array $row, array $fallbackPolicy): array
{
    $fallback = synk_schedule_policy_payload_to_array($fallbackPolicy);
    if ($row === null) {
        return synk_schedule_policy_format($fallback);
    }

    $dayStart = synk_schedule_policy_normalize_time(
        (string)($row['schedule_day_start'] ?? ''),
        $fallback['day_start']
    );
    $dayEnd = synk_schedule_policy_normalize_time(
        (string)($row['schedule_day_end'] ?? ''),
        $fallback['day_end']
    );

    if ($dayEnd <= $dayStart) {
        $dayStart = $fallback['day_start'];
        $dayEnd = $fallback['day_end'];
    }

    return synk_schedule_policy_format([
        'day_start' => $dayStart,
        'day_end' => $dayEnd,
        'blocked_days' => synk_schedule_policy_normalize_days(
            $row['blocked_schedule_days_json'] ?? $fallback['blocked_days']
        ),
        'blocked_times' => synk_schedule_policy_normalize_blocked_times(
            $row['blocked_schedule_times_json'] ?? $fallback['blocked_times'],
            $dayStart,
            $dayEnd
        ),
    ]);
}

function synk_fetch_schedule_policy(mysqli $conn): array
{
    $defaults = synk_schedule_policy_defaults();
    $policy = synk_build_schedule_policy_from_row(
        synk_fetch_schedule_policy_row($conn),
        $defaults
    );
    $policy['source_scope'] = 'global_default';
    $policy['source_label'] = 'Global default';
    $policy['is_override_enabled'] = false;
    $policy['uses_global_default'] = true;
    return $policy;
}

function synk_fetch_college_schedule_policy_settings(mysqli $conn, int $collegeId): array
{
    $globalPolicy = synk_fetch_schedule_policy($conn);
    $basePolicy = synk_schedule_policy_payload_to_array($globalPolicy);
    $settings = synk_schedule_policy_format($basePolicy);
    $settings['college_id'] = $collegeId;
    $settings['is_override_enabled'] = false;
    $settings['uses_global_default'] = true;
    $settings['source_scope'] = 'global_default';
    $settings['source_label'] = 'Global default';

    if ($collegeId <= 0) {
        return $settings;
    }

    synk_college_schedule_policy_ensure_table($conn);
    $tableName = synk_college_schedule_policy_table_name();

    $stmt = $conn->prepare("
        SELECT
            college_id,
            is_override_enabled,
            schedule_day_start,
            schedule_day_end,
            blocked_schedule_days_json,
            blocked_schedule_times_json
        FROM `{$tableName}`
        WHERE college_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return $settings;
    }

    $stmt->bind_param('i', $collegeId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return $settings;
    }

    $settings = synk_build_schedule_policy_from_row($row, $basePolicy);
    $settings['college_id'] = $collegeId;
    $settings['is_override_enabled'] = ((int)($row['is_override_enabled'] ?? 0)) === 1;
    $settings['uses_global_default'] = !$settings['is_override_enabled'];
    $settings['source_scope'] = $settings['is_override_enabled'] ? 'college_override' : 'global_default';
    $settings['source_label'] = $settings['is_override_enabled'] ? 'College override' : 'Global default';
    return $settings;
}

function synk_fetch_effective_schedule_policy(mysqli $conn, int $collegeId = 0): array
{
    if ($collegeId <= 0) {
        return synk_fetch_schedule_policy($conn);
    }

    $collegeSettings = synk_fetch_college_schedule_policy_settings($conn, $collegeId);
    if (!empty($collegeSettings['is_override_enabled'])) {
        $collegeSettings['uses_global_default'] = false;
        $collegeSettings['source_scope'] = 'college_override';
        $collegeSettings['source_label'] = 'College override';
        return $collegeSettings;
    }

    $globalPolicy = synk_fetch_schedule_policy($conn);
    $globalPolicy['college_id'] = $collegeId;
    $globalPolicy['is_override_enabled'] = false;
    $globalPolicy['uses_global_default'] = true;
    $globalPolicy['source_scope'] = 'global_default';
    $globalPolicy['source_label'] = 'Global default';
    return $globalPolicy;
}

function synk_fetch_colleges_using_schedule_overrides(mysqli $conn): array
{
    synk_college_schedule_policy_ensure_table($conn);
    $tableName = synk_college_schedule_policy_table_name();
    $globalPolicy = synk_fetch_schedule_policy($conn);
    $basePolicy = synk_schedule_policy_payload_to_array($globalPolicy);
    $policies = [];

    $sql = "
        SELECT
            p.college_id,
            c.college_code,
            c.college_name,
            p.schedule_day_start,
            p.schedule_day_end,
            p.blocked_schedule_days_json,
            p.blocked_schedule_times_json
        FROM `{$tableName}` p
        INNER JOIN tbl_college c ON c.college_id = p.college_id
        WHERE p.is_override_enabled = 1
          AND c.status = 'active'
        ORDER BY c.college_name ASC, c.college_code ASC
    ";

    $result = $conn->query($sql);
    if (!$result) {
        return $policies;
    }

    while ($row = $result->fetch_assoc()) {
        $code = trim((string)($row['college_code'] ?? ''));
        $name = trim((string)($row['college_name'] ?? ''));
        $policy = synk_build_schedule_policy_from_row($row, $basePolicy);
        $policy['college_id'] = (int)($row['college_id'] ?? 0);
        $policy['college_label'] = $code !== '' ? ($code . ' - ' . $name) : $name;
        $policy['source_scope'] = 'college_override';
        $policy['source_label'] = 'College override';
        $policy['is_override_enabled'] = true;
        $policy['uses_global_default'] = false;
        $policies[] = $policy;
    }

    $result->free();
    return $policies;
}

function synk_save_college_schedule_policy_settings(mysqli $conn, int $collegeId, array $policy, bool $isOverrideEnabled, int $userId): bool
{
    if ($collegeId <= 0) {
        return false;
    }

    synk_college_schedule_policy_ensure_table($conn);
    $tableName = synk_college_schedule_policy_table_name();
    $payload = synk_schedule_policy_payload_to_array($policy);
    $blockedDaysJson = json_encode($payload['blocked_days']);
    $blockedTimesJson = json_encode($payload['blocked_times']);
    $enabledValue = $isOverrideEnabled ? 1 : 0;

    $stmt = $conn->prepare("
        INSERT INTO `{$tableName}` (
            college_id,
            is_override_enabled,
            schedule_day_start,
            schedule_day_end,
            blocked_schedule_days_json,
            blocked_schedule_times_json,
            created_by,
            updated_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            is_override_enabled = VALUES(is_override_enabled),
            schedule_day_start = VALUES(schedule_day_start),
            schedule_day_end = VALUES(schedule_day_end),
            blocked_schedule_days_json = VALUES(blocked_schedule_days_json),
            blocked_schedule_times_json = VALUES(blocked_schedule_times_json),
            updated_by = VALUES(updated_by),
            date_updated = CURRENT_TIMESTAMP
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'iissssii',
        $collegeId,
        $enabledValue,
        $payload['day_start'],
        $payload['day_end'],
        $blockedDaysJson,
        $blockedTimesJson,
        $userId,
        $userId
    );

    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}
