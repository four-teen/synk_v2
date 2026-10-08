<?php
// Run: php tests/professor-dashboard.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../backend/professor_dashboard_helper.php';

$checks = 0;
function dashboard_check(bool $condition, string $description): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($description);
    }
    $checks++;
}

$terms = [
    ['ay_id' => 2, 'semester' => 2, 'workload_count' => 5, 'student_count' => 200],
    ['ay_id' => 2, 'semester' => 1, 'workload_count' => 3, 'student_count' => 150],
];
$current = synk_professor_dashboard_term_summary($terms, ['ay_id' => 2, 'semester' => 1]);
dashboard_check($current['subjects'] === 3 && $current['enrollments'] === 150, 'Count the current term even when another term appears first.');
dashboard_check($current['terms'] === 2 && $current['workload_url'] === 'workload.php?ay_id=2&semester=1', 'Keep history separate and link to the current workload.');
$noCurrent = synk_professor_dashboard_term_summary($terms, ['ay_id' => 3, 'semester' => 1]);
dashboard_check($noCurrent['subjects'] === 0 && $noCurrent['enrollments'] === 0, 'Historical assignments must not be labeled as current.');
dashboard_check($noCurrent['terms'] === 2 && $noCurrent['workload_url'] === 'workload.php', 'Historical terms remain accessible when no current workload exists.');
$empty = synk_professor_dashboard_term_summary([], []);
dashboard_check($empty['subjects'] === 0 && $empty['terms'] === 0, 'Empty or unconfigured accounts show an empty teaching summary.');

$profile = [
    'saved' => true,
    'completion' => [
        'percent' => 58, 'missing' => ['master_status'],
        'sections' => [
            'personal' => ['completed' => 6, 'total' => 6],
            'employment' => ['completed' => 3, 'total' => 3],
            'education' => ['completed' => 5, 'total' => 15],
        ],
    ],
];
$actions = synk_professor_dashboard_actions($profile, true, true);
dashboard_check(count($actions) === 1 && $actions[0]['url'] === 'manage-profile.php#education', 'Only the incomplete section becomes a profile reminder.');
dashboard_check(strpos($actions[0]['description'], '10 details') !== false, 'Reminders state the remaining applicable detail count.');
$profile['completion']['sections']['education'] = ['completed' => 7, 'total' => 7];
$profile['completion']['percent'] = 100;
$profile['completion']['missing'] = [];
dashboard_check(synk_professor_dashboard_actions($profile, true, true) === [], 'A saved complete profile with active faculty access has no invented actions.');
$profile['saved'] = false;
$actions = synk_professor_dashboard_actions($profile, true, true);
dashboard_check(count($actions) === 1 && $actions[0]['title'] === 'Save your faculty profile', 'A complete unsaved draft still needs saving.');
$profile['saved'] = true;
$unlinked = synk_professor_dashboard_actions($profile, false, false);
dashboard_check(count($unlinked) === 1 && $unlinked[0]['url'] === null, 'Missing faculty access prompts administrator help without a fake action link.');
$inactive = synk_professor_dashboard_actions($profile, true, false);
dashboard_check(count($inactive) === 1 && $inactive[0]['title'] === 'Review faculty access', 'Inactive faculty records need an access review.');
$unavailable = synk_professor_dashboard_actions(null, true, true);
dashboard_check(count($unavailable) === 1 && $unavailable[0]['url'] === 'manage-profile.php', 'Unavailable progress offers profile access instead of claiming zero completion.');

echo 'Professor dashboard: ', $checks, ' checks passed.', PHP_EOL;
