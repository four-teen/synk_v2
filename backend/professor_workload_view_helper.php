<?php

require_once __DIR__ . '/professor_portal_helper.php';

// Navigation only: do not load every historical year's classes to build a dropdown.
function synk_professor_workload_available_terms(mysqli $conn, int $facultyId): array
{
    return synk_professor_fetch_assigned_term_rows($conn, $facultyId);
}

function synk_professor_workload_navigation(array $terms, array $currentTerm, int $requestedYear = 0, ?int $requestedSemester = null): array
{
    $years = [];
    foreach ($terms as $term) {
        $id = (int)($term['ay_id'] ?? 0);
        $semester = (int)($term['semester'] ?? 0);
        if ($id <= 0 || $semester < 1 || $semester > 3) {
            continue;
        }
        if (!isset($years[$id])) {
            $label = trim((string)($term['academic_year_label'] ?? ''));
            preg_match('/(?:19|20|21)\d{2}/', $label, $match);
            $years[$id] = ['ay_id' => $id, 'label' => $label !== '' ? $label : 'Academic year ' . $id,
                'start_year' => (int)($match[0] ?? 0), 'semesters' => []];
        }
        $years[$id]['semesters'][$semester] = $semester;
    }
    uasort($years, static function (array $left, array $right): int {
        return ($right['start_year'] <=> $left['start_year'])
            ?: strnatcasecmp($right['label'], $left['label'])
            ?: ($right['ay_id'] <=> $left['ay_id']);
    });
    foreach ($years as &$year) {
        sort($year['semesters']);
    }
    unset($year);
    $yearIds = array_keys($years);
    $latestId = (int)($yearIds[0] ?? 0);
    $selectedId = isset($years[$requestedYear]) ? $requestedYear : $latestId;
    $selected = $years[$selectedId] ?? null;
    $semester = 0;
    if ($selected) {
        if ($requestedSemester !== null && in_array($requestedSemester, $selected['semesters'], true)) {
            $semester = $requestedSemester;
        } elseif ($selectedId === (int)($currentTerm['ay_id'] ?? 0)
            && in_array((int)($currentTerm['semester'] ?? 0), $selected['semesters'], true)) {
            $semester = (int)$currentTerm['semester'];
        } else {
            $semester = max($selected['semesters']);
        }
    }
    $options = [];
    foreach ($years as $year) {
        foreach (array_reverse($year['semesters']) as $availableSemester) {
            $key = $year['ay_id'] . '-' . $availableSemester;
            $options[$key] = ['term_key' => $key, 'ay_id' => $year['ay_id'], 'semester' => $availableSemester,
                'label' => $year['label'] . ' / ' . synk_semester_label($availableSemester)];
        }
    }
    $selectedKey = $selectedId . '-' . $semester;
    $latestKey = $options ? (string)synk_schedule_merge_array_first_key($options) : '';
    return ['years' => $years, 'latest_id' => $latestId, 'selected_year' => $selected,
        'terms' => $options, 'selected_term' => $options[$selectedKey] ?? null,
        'latest_key' => $latestKey, 'selected_key' => isset($options[$selectedKey]) ? $selectedKey : '',
        'ay_id' => $selectedId, 'semester' => $semester];
}

function synk_professor_workload_query_term($value): ?array
{
    if (!is_string($value) || !preg_match('/^([1-9]\d{0,8})-([1-3])$/D', $value, $match)) {
        return null;
    }
    return ['ay_id' => (int)$match[1], 'semester' => (int)$match[2]];
}

function synk_professor_workload_query_integer($value): ?int
{
    if (!is_string($value) || !preg_match('/^\d{1,9}$/D', $value)) {
        return null;
    }
    return (int)$value;
}
