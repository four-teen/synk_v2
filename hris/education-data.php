<?php

require_once __DIR__ . '/../backend/faculty_profile_degrees_helper.php';

function synk_hris_profile_education(array $profile, array $additionalDegrees): array
{
    $degrees = [];
    $fields = array_merge(['status'], array_keys(synk_faculty_education_types()));
    foreach (['bachelor', 'master', 'doctoral'] as $level) {
        $entry = ['level' => $level];
        foreach ($fields as $field) {
            $entry[$field] = trim((string)($profile[$level . '_' . $field] ?? ''));
        }
        if ($entry['status'] !== 'Not applicable'
            && (!synk_faculty_education_placeholder($entry['degree']) || !synk_faculty_education_placeholder($entry['institution']))) {
            $degrees[] = $entry;
        }
    }
    foreach ($additionalDegrees as $entry) {
        if (is_array($entry) && !synk_faculty_education_placeholder(trim((string)($entry['degree'] ?? '')))) {
            $degrees[] = $entry;
        }
    }
    return $degrees;
}

function synk_hris_education_identity(array $entry): string
{
    $parts = [];
    foreach (['degree', 'institution', 'specialization'] as $field) {
        $value = synk_faculty_education_normalize((string)($entry[$field] ?? ''));
        if (synk_faculty_education_placeholder($value)) $value = '';
        $parts[] = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }
    return $parts[0] === '' ? '' : implode("\0", $parts);
}

function synk_hris_education_groups(array $profileDegrees, ?array $pdsRecords = null): array
{
    $labels = [
        'Elementary' => 'Elementary education',
        'Secondary' => 'Secondary education',
        'Vocational / trade course' => 'Vocational / trade course',
        'bachelor' => "Bachelor's degree",
        'master' => "Master's degree",
        'doctoral' => 'Doctoral degree',
        'Graduate studies' => 'Graduate studies',
        'other' => 'Other education',
    ];
    $groups = array_fill_keys($labels, []);
    $profileMatches = [];
    foreach ($profileDegrees as $index => $entry) {
        $identity = synk_hris_education_identity($entry);
        if ($identity !== '') {
            $profileMatches[$identity][$entry['level'] ?? 'other'][] = $index;
        }
    }

    $matched = [];
    foreach ($pdsRecords ?? [] as $entry) {
        if (!array_filter(array_diff_key($entry, ['level' => true]), 'strlen')) continue;
        $level = ($entry['level'] ?? '') === 'College' ? 'bachelor' : ($entry['level'] ?? 'other');
        // The PDS uses one graduate level. Use a narrower level only when the profile identifies it.
        $compatibleLevels = $level === 'Graduate studies' ? ['master' => true, 'doctoral' => true] : [$level => true];
        $matches = array_intersect_key($profileMatches[synk_hris_education_identity($entry)] ?? [], $compatibleLevels);
        if (count($matches) === 1) {
            $level = key($matches);
            foreach ($matches[$level] as $index) {
                $matched[$index] = true;
            }
        }
        $groups[$labels[$level] ?? $labels['other']][] = $entry;
    }

    foreach ($profileDegrees as $index => $entry) {
        if (isset($matched[$index])) continue;
        if ($pdsRecords !== null) $entry['source'] = 'Professor Profile';
        $groups[$labels[$entry['level'] ?? 'other'] ?? $labels['other']][] = $entry;
    }
    return array_filter($groups);
}
