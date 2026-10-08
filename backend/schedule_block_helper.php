<?php

if (!defined('SYNK_LAB_LOAD_MULTIPLIER')) {
    define('SYNK_LAB_LOAD_MULTIPLIER', 0.75);
}

if (!defined('SYNK_LAB_CONTACT_HOURS_PER_UNIT')) {
    define('SYNK_LAB_CONTACT_HOURS_PER_UNIT', 3.0);
}

function synk_normalize_schedule_type(string $value): string
{
    $type = strtoupper(trim($value));
    return $type === 'LAB' ? 'LAB' : 'LEC';
}

function synk_normalize_schedule_days($days): array
{
    if (!is_array($days)) {
        return [];
    }

    $order = ['M', 'T', 'W', 'Th', 'F', 'S'];
    $seen = [];

    foreach ($days as $day) {
        $token = trim((string)$day);
        if ($token === 'TH') {
            $token = 'Th';
        }

        if ($token !== '' && in_array($token, $order, true)) {
            $seen[$token] = true;
        }
    }

    $result = [];
    foreach ($order as $token) {
        if (isset($seen[$token])) {
            $result[] = $token;
        }
    }

    return $result;
}

function synk_schedule_minutes_between(?string $timeStart, ?string $timeEnd): int
{
    $timeStart = trim((string)$timeStart);
    $timeEnd = trim((string)$timeEnd);

    if ($timeStart === '' || $timeEnd === '' || $timeEnd <= $timeStart) {
        return 0;
    }

    $start = strtotime('1970-01-01 ' . $timeStart);
    $end = strtotime('1970-01-01 ' . $timeEnd);
    if ($start === false || $end === false || $end <= $start) {
        return 0;
    }

    return (int)round(($end - $start) / 60);
}

function synk_schedule_weekly_minutes($days, ?string $timeStart, ?string $timeEnd): int
{
    $normalizedDays = synk_normalize_schedule_days($days);
    if (empty($normalizedDays)) {
        return 0;
    }

    return count($normalizedDays) * synk_schedule_minutes_between($timeStart, $timeEnd);
}

function synk_lab_is_credit(float $lecUnits, float $labValue, float $totalUnits): bool
{
    return $labValue > 0 && abs(($lecUnits + $labValue) - $totalUnits) < 0.0001;
}

function synk_lab_contact_hours(float $lecUnits, float $labValue, float $totalUnits): float
{
    if ($labValue <= 0) {
        return 0.0;
    }

    return synk_lab_is_credit($lecUnits, $labValue, $totalUnits)
        ? ($labValue * SYNK_LAB_CONTACT_HOURS_PER_UNIT)
        : $labValue;
}

function synk_required_minutes_by_type(float $lecUnits, float $labValue, float $totalUnits): array
{
    return [
        'LEC' => max(0, (int)round($lecUnits * 60)),
        'LAB' => max(0, (int)round(synk_lab_contact_hours($lecUnits, $labValue, $totalUnits) * 60))
    ];
}

function synk_subject_units_total(float $lecUnits, float $labValue, float $totalUnits): float
{
    return round(max(0.0, $lecUnits) + (max(0.0, $labValue) / SYNK_LAB_CONTACT_HOURS_PER_UNIT), 2);
}

function synk_prospectus_component_totals(float $lecUnits, float $labValue, float $totalUnits): array
{
    $lectureHours = max(0.0, $lecUnits);
    $labHours = synk_lab_contact_hours($lecUnits, $labValue, $totalUnits);
    $labUnits = $labHours / SYNK_LAB_CONTACT_HOURS_PER_UNIT;

    return [
        'LEC' => [
            'units' => $lectureHours,
            'hours_lec' => $lectureHours,
            'hours_lab' => 0.0,
            'faculty_load' => $lectureHours
        ],
        'LAB' => [
            'units' => $labUnits,
            'hours_lec' => 0.0,
            'hours_lab' => $labHours,
            'faculty_load' => $labHours * SYNK_LAB_LOAD_MULTIPLIER
        ]
    ];
}

function synk_schedule_row_count_totals(array $rows, string $typeKey = 'schedule_type'): array
{
    $totals = [
        'total_count' => 0,
        'lec_count' => 0,
        'lab_count' => 0
    ];

    foreach ($rows as $row) {
        $type = synk_normalize_schedule_type((string)($row[$typeKey] ?? 'LEC'));
        $totals['total_count']++;

        if ($type === 'LAB') {
            $totals['lab_count']++;
            continue;
        }

        $totals['lec_count']++;
    }

    return $totals;
}

function synk_schedule_context_display_metrics(
    float $lecUnits,
    float $labValue,
    float $totalUnits,
    array $contextTotals,
    array $ownedTotals
): array {
    $componentTotals = synk_prospectus_component_totals($lecUnits, $labValue, $totalUnits);
    $totalLecCount = max(0, (int)($contextTotals['lec_count'] ?? 0));
    $totalLabCount = max(0, (int)($contextTotals['lab_count'] ?? 0));
    $ownedLecCount = max(0, min((int)($ownedTotals['lec_count'] ?? 0), $totalLecCount));
    $ownedLabCount = max(0, min((int)($ownedTotals['lab_count'] ?? 0), $totalLabCount));
    $lecRatio = $totalLecCount > 0 ? ($ownedLecCount / $totalLecCount) : 0.0;
    // A faculty who owns any lab meeting keeps the full prospectus lab component.
    $labRatio = ($totalLabCount > 0 && $ownedLabCount > 0) ? 1.0 : 0.0;

    $lectureHours = ((float)$componentTotals['LEC']['hours_lec']) * $lecRatio;
    $labHours = ((float)$componentTotals['LAB']['hours_lab']) * $labRatio;
    $units = (((float)$componentTotals['LEC']['units']) * $lecRatio) +
        (((float)$componentTotals['LAB']['units']) * $labRatio);

    return [
        'units' => round($units, 2),
        'lec' => round($lectureHours, 2),
        'lab' => round($labHours, 2),
        'lab_hours' => round($labHours, 2),
        'faculty_load' => round($lectureHours + ($labHours * SYNK_LAB_LOAD_MULTIPLIER), 2)
    ];
}

function synk_schedule_row_display_metrics(
    string $scheduleType,
    float $lecUnits,
    float $labValue,
    float $totalUnits,
    array $contextTotals
): array {
    $normalizedType = synk_normalize_schedule_type($scheduleType);
    $ownedTotals = [
        'total_count' => 1,
        'lec_count' => $normalizedType === 'LAB' ? 0 : 1,
        'lab_count' => $normalizedType === 'LAB' ? 1 : 0
    ];

    return synk_schedule_context_display_metrics(
        $lecUnits,
        $labValue,
        $totalUnits,
        $contextTotals,
        $ownedTotals
    );
}

function synk_schedule_component_totals(float $lecUnits, float $labValue, float $totalUnits): array
{
    $labIsCredit = synk_lab_is_credit($lecUnits, $labValue, $totalUnits);
    $labHours = synk_lab_contact_hours($lecUnits, $labValue, $totalUnits);

    return [
        'LEC' => [
            'units' => $lecUnits,
            'hours_lec' => $lecUnits,
            'hours_lab' => 0.0,
            'faculty_load' => $lecUnits
        ],
        'LAB' => [
            'units' => $labIsCredit ? $labValue : 0.0,
            'hours_lec' => 0.0,
            'hours_lab' => $labHours,
            'faculty_load' => $labHours * SYNK_LAB_LOAD_MULTIPLIER
        ]
    ];
}

function synk_schedule_component_display_totals(
    string $scheduleType,
    float $lecUnits,
    float $labValue,
    float $totalUnits
): array {
    $normalizedType = synk_normalize_schedule_type($scheduleType);
    $component = synk_prospectus_component_totals($lecUnits, $labValue, $totalUnits)[$normalizedType] ?? [
        'units' => 0.0,
        'hours_lec' => 0.0,
        'hours_lab' => 0.0,
        'faculty_load' => 0.0
    ];

    return [
        'units' => round((float)($component['units'] ?? 0), 2),
        'lec' => round((float)($component['hours_lec'] ?? 0), 2),
        'lab' => round((float)($component['hours_lab'] ?? 0), 2),
        'lab_hours' => round((float)($component['hours_lab'] ?? 0), 2),
        'faculty_load' => round((float)($component['faculty_load'] ?? 0), 2)
    ];
}

function synk_schedule_block_metrics(
    string $scheduleType,
    int $weeklyMinutes,
    float $lecUnits,
    float $labValue,
    float $totalUnits
): array {
    $scheduleType = synk_normalize_schedule_type($scheduleType);
    $requiredMinutes = synk_required_minutes_by_type($lecUnits, $labValue, $totalUnits);
    $componentTotals = synk_schedule_component_totals($lecUnits, $labValue, $totalUnits);
    $typeRequiredMinutes = max(0, (int)($requiredMinutes[$scheduleType] ?? 0));
    $ratio = $typeRequiredMinutes > 0 ? ($weeklyMinutes / $typeRequiredMinutes) : 0.0;
    $component = $componentTotals[$scheduleType] ?? [
        'units' => 0.0,
        'hours_lec' => 0.0,
        'hours_lab' => 0.0,
        'faculty_load' => 0.0
    ];

    return [
        'units' => round(((float)$component['units']) * $ratio, 2),
        'hours_lec' => round(((float)$component['hours_lec']) * $ratio, 2),
        'hours_lab' => round(((float)$component['hours_lab']) * $ratio, 2),
        'faculty_load' => round(((float)$component['faculty_load']) * $ratio, 2),
        'weekly_minutes' => $weeklyMinutes,
        'required_minutes' => $typeRequiredMinutes,
        'coverage_ratio' => $typeRequiredMinutes > 0 ? round($weeklyMinutes / $typeRequiredMinutes, 4) : 0.0
    ];
}

function synk_schedule_block_metrics_from_row(array $row): array
{
    $days = [];

    if (isset($row['days']) && is_array($row['days'])) {
        $days = $row['days'];
    } elseif (isset($row['days_json'])) {
        $decoded = json_decode((string)$row['days_json'], true);
        $days = is_array($decoded) ? $decoded : [];
    }

    $weeklyMinutes = synk_schedule_weekly_minutes(
        $days,
        (string)($row['time_start'] ?? ''),
        (string)($row['time_end'] ?? '')
    );

    return synk_schedule_block_metrics(
        (string)($row['schedule_type'] ?? 'LEC'),
        $weeklyMinutes,
        (float)($row['lec_units'] ?? 0),
        (float)($row['lab_units'] ?? 0),
        (float)($row['total_units'] ?? 0)
    );
}

function synk_schedule_block_display_metrics(
    string $scheduleType,
    int $weeklyMinutes,
    float $lecUnits,
    float $labValue,
    float $totalUnits
): array {
    $normalizedType = synk_normalize_schedule_type($scheduleType);
    $metrics = synk_schedule_block_metrics(
        $normalizedType,
        $weeklyMinutes,
        $lecUnits,
        $labValue,
        $totalUnits
    );

    return [
        'units' => round((float)($metrics['units'] ?? 0), 2),
        'lec' => $normalizedType === 'LEC'
            ? round((float)($metrics['hours_lec'] ?? 0), 2)
            : 0.0,
        'lab' => $normalizedType === 'LAB'
            ? round((float)($metrics['hours_lab'] ?? 0), 2)
            : 0.0,
        'lab_hours' => $normalizedType === 'LAB'
            ? round((float)($metrics['hours_lab'] ?? 0), 2)
            : 0.0,
        'faculty_load' => round((float)($metrics['faculty_load'] ?? 0), 2),
        'weekly_minutes' => (int)($metrics['weekly_minutes'] ?? 0),
        'required_minutes' => (int)($metrics['required_minutes'] ?? 0),
        'coverage_ratio' => round((float)($metrics['coverage_ratio'] ?? 0), 4)
    ];
}

function synk_schedule_block_display_metrics_from_row(array $row): array
{
    $days = [];

    if (isset($row['days']) && is_array($row['days'])) {
        $days = $row['days'];
    } elseif (isset($row['days_json'])) {
        $decoded = json_decode((string)$row['days_json'], true);
        $days = is_array($decoded) ? $decoded : [];
    }

    return synk_schedule_block_display_metrics(
        (string)($row['schedule_type'] ?? 'LEC'),
        synk_schedule_weekly_minutes(
            $days,
            (string)($row['time_start'] ?? ''),
            (string)($row['time_end'] ?? '')
        ),
        (float)($row['lec_units'] ?? 0),
        (float)($row['lab_units'] ?? 0),
        (float)($row['total_units'] ?? 0)
    );
}

function synk_schedule_sum_display_metrics(array $rows, array $contextTotals = []): array
{
    if (empty($rows)) {
        return [
            'units' => 0.0,
            'lec' => 0.0,
            'lab' => 0.0,
            'lab_hours' => 0.0,
            'faculty_load' => 0.0
        ];
    }

    $first = $rows[0];
    $ownedTotals = synk_schedule_row_count_totals($rows);
    if (empty($contextTotals)) {
        $contextTotals = $ownedTotals;
    }

    return synk_schedule_context_display_metrics(
        (float)($first['lec_units'] ?? 0),
        (float)($first['lab_units'] ?? 0),
        (float)($first['total_units'] ?? 0),
        $contextTotals,
        $ownedTotals
    );
}

function synk_sum_scheduled_minutes_by_type(array $rows): array
{
    $totals = ['LEC' => 0, 'LAB' => 0];

    foreach ($rows as $row) {
        $type = synk_normalize_schedule_type((string)($row['schedule_type'] ?? 'LEC'));
        $days = [];

        if (isset($row['days']) && is_array($row['days'])) {
            $days = $row['days'];
        } elseif (isset($row['days_json'])) {
            $decoded = json_decode((string)$row['days_json'], true);
            $days = is_array($decoded) ? $decoded : [];
        }

        $totals[$type] += synk_schedule_weekly_minutes(
            $days,
            (string)($row['time_start'] ?? ''),
            (string)($row['time_end'] ?? '')
        );
    }

    return $totals;
}

