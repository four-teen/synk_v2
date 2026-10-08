<?php

require_once __DIR__ . '/professor_portal_helper.php';
require_once __DIR__ . '/faculty_profile_helper.php';

function synk_professor_dashboard_term_summary(array $terms, array $currentTerm): array
{
    $current = null;
    foreach ($terms as $term) {
        if ((int)($term['ay_id'] ?? 0) === (int)($currentTerm['ay_id'] ?? 0)
            && (int)($term['semester'] ?? 0) === (int)($currentTerm['semester'] ?? 0)
            && (int)($term['ay_id'] ?? 0) > 0 && (int)($term['semester'] ?? 0) > 0) {
            $current = $term;
            break;
        }
    }
    return [
        'subjects' => max(0, (int)($current['workload_count'] ?? 0)),
        'enrollments' => max(0, (int)($current['student_count'] ?? 0)),
        'terms' => count($terms),
        'workload_url' => $current
            ? 'workload.php?ay_id=' . (int)$current['ay_id'] . '&semester=' . (int)$current['semester']
            : 'workload.php',
    ];
}

// Read only: opening the dashboard does not create profile tables or save drafts.
function synk_professor_dashboard_profile(mysqli $conn, int $userId, ?array $facultyLink): array
{
    $account = synk_faculty_profile_account($conn, $userId);
    if (!$account) {
        throw new RuntimeException('Faculty profile account is unavailable.');
    }
    $stored = null;
    if (synk_table_exists($conn, 'tbl_faculty_profiles')) {
        $stmt = $conn->prepare('SELECT * FROM tbl_faculty_profiles WHERE user_id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stored = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
    }
    $classification = '';
    if (!$stored && $facultyLink && synk_table_has_column($conn, 'tbl_faculty', 'employment_classification')) {
        $facultyId = (int)$facultyLink['faculty_id'];
        $stmt = $conn->prepare('SELECT employment_classification FROM tbl_faculty WHERE faculty_id = ?');
        $stmt->bind_param('i', $facultyId);
        $stmt->execute();
        $classification = (string)($stmt->get_result()->fetch_assoc()['employment_classification'] ?? '');
        $stmt->close();
    }
    $profile = $stored ?? synk_faculty_profile_defaults($account, $facultyLink, $classification);
    $ranks = synk_table_exists($conn, 'tbl_faculty_ranks') ? synk_faculty_profile_ranks($conn) : [];
    $locations = synk_faculty_profile_locations($conn);
    $fields = synk_faculty_profile_fields($ranks, $stored ?? [], $locations);
    $rules = synk_faculty_profile_completion_rules($fields);
    [$values, $errors] = synk_faculty_profile_validate($profile, null, $ranks, $stored ?? [], [], $locations);
    return [
        'saved' => $stored !== null,
        'updated_at' => $stored['updated_at'] ?? null,
        'completion' => synk_faculty_profile_completion($values, $rules, $errors),
    ];
}

function synk_professor_dashboard_actions(?array $profile, bool $facultyLinked, bool $facultyActive): array
{
    $actions = [];
    if (!$facultyLinked || !$facultyActive) {
        $actions[] = [
            'icon' => 'bx-user-check',
            'title' => !$facultyLinked ? 'Connect your faculty record' : 'Review faculty access',
            'description' => !$facultyLinked
                ? 'Ask your administrator to link your account so your teaching assignments can appear.'
                : 'Your faculty record is inactive. Contact your administrator to review it.',
            'url' => null,
            'action' => 'Contact your administrator',
        ];
    }
    if ($profile === null) {
        $actions[] = [
            'icon' => 'bx-info-circle', 'title' => 'Check your profile',
            'description' => 'Profile progress is temporarily unavailable. Open Manage Profile to review your details.',
            'url' => 'manage-profile.php', 'action' => 'Open profile',
        ];
        return $actions;
    }
    foreach (['personal' => 'personal details', 'employment' => 'employment details', 'education' => 'education details'] as $section => $label) {
        $progress = $profile['completion']['sections'][$section];
        $remaining = $progress['total'] - $progress['completed'];
        if ($remaining <= 0) {
            continue;
        }
        $actions[] = [
            'icon' => $section === 'education' ? 'bxs-graduation' : ($section === 'employment' ? 'bx-briefcase-alt' : 'bx-id-card'),
            'title' => 'Complete your ' . $label,
            'description' => $remaining . ($remaining === 1 ? ' detail needs' : ' details need') . ' attention.'
                . ($section === 'education' ? ' Choose Not applicable for any degree level you have not pursued.' : ''),
            'url' => 'manage-profile.php#' . $section, 'action' => 'Review details',
        ];
    }
    if (!$profile['saved'] && !$profile['completion']['missing']) {
        $actions[] = [
            'icon' => 'bx-save', 'title' => 'Save your faculty profile',
            'description' => 'Your details are filled in. Open your profile and save to keep them.',
            'url' => 'manage-profile.php', 'action' => 'Open profile',
        ];
    }
    return $actions;
}
