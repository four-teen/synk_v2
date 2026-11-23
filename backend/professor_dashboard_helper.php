<?php

require_once __DIR__ . '/professor_portal_helper.php';
require_once __DIR__ . '/faculty_profile_helper.php';
require_once __DIR__ . '/professor_workload_students_helper.php';

function synk_professor_dashboard_student_signins(mysqli $conn, array $classes, array $term, DateTimeImmutable $now): array
{
    $members = [];
    foreach (synk_professor_workload_student_memberships($conn,$classes,(int)$term['ay_id'],(int)$term['semester']) as $roster) {
        $members += $roster;
    }
    $total = count($members);
    $data = ['total'=>$total,'days'=>[],'available'=>synk_student_login_activity_ready($conn)];
    if (!$total || !$data['available']) return $data;

    $utc = new DateTimeZone('UTC');
    $today = $now->setTimezone(new DateTimeZone('Asia/Manila'))->setTime(0,0);
    $start = $today->modify('-6 days');
    $startUtc = $start->setTimezone($utc)->format('Y-m-d H:i:s');
    $endUtc = $today->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    for ($day=$start; $day<=$today; $day=$day->modify('+1 day')) {
        $data['days'][$day->format('Y-m-d')] = ['weekday'=>$day->format('D'),'date'=>$day->format('M j'),'count'=>0];
    }

    // The roster supplies unique IDs. Disjoint batches preserve exact daily totals.
    foreach (array_chunk(array_keys($members),500) as $ids) {
        $marks = implode(',',array_fill(0,count($ids),'?'));
        $stmt = $conn->prepare("SELECT DATE(occurred_at + INTERVAL 8 HOUR) AS local_day,
            COUNT(DISTINCT student_id) AS students
            FROM tbl_student_login_activity
            WHERE outcome='success' AND occurred_at>=? AND occurred_at<? AND student_id IN ({$marks})
            GROUP BY local_day");
        if (!$stmt) throw new RuntimeException('Unable to prepare the daily student sign-in query.');
        try {
            $params = array_merge([$startUtc,$endUtc],$ids);
            synk_bind_dynamic_params($stmt,'ss'.str_repeat('i',count($ids)),$params);
            if (!$stmt->execute()) throw new RuntimeException('Unable to load daily student sign-ins.');
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
                $data['days'][$row['local_day']]['count'] += (int)$row['students'];
            }
        } finally { $stmt->close(); }
    }
    return $data;
}

function synk_professor_dashboard_term_summary(array $terms, array $currentTerm, ?array $currentRows = null): array
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
    $chart = $currentRows !== null ? synk_professor_dashboard_workload_chart($currentRows, $currentTerm) : null;
    $subjects = $chart !== null ? count($chart['classes']) : max(0, (int)($current['workload_count'] ?? 0));
    return [
        'subjects' => $subjects,
        'enrollments' => $chart !== null ? array_sum(array_column($chart['classes'], 'enrollments')) : max(0, (int)($current['student_count'] ?? 0)),
        'terms' => count($terms),
        'workload_url' => $subjects > 0
            ? 'workload.php?ay_id=' . (int)$currentTerm['ay_id'] . '&semester=' . (int)$currentTerm['semester']
            : 'workload.php',
    ];
}

function synk_professor_dashboard_workload_chart(array $rows, array $term): array
{
    $ayId = (int)($term['ay_id'] ?? 0);
    $semester = (int)($term['semester'] ?? 0);
    $classes = [];
    foreach ($rows as $row) {
        $offeringId = (int)($row['offering_id'] ?? 0);
        if ($ayId < 1 || $semester < 1 || $semester > 3 || $offeringId < 1
            || (int)($row['ay_id'] ?? 0) !== $ayId || (int)($row['semester'] ?? 0) !== $semester
            || isset($classes[$offeringId])) { continue; }
        $subject = trim((string)($row['subject_code'] ?? '')) ?: 'Subject';
        $section = trim((string)($row['section_display'] ?? ''));
        $classes[$offeringId] = ['label' => $subject . ($section !== '' ? ' / ' . $section : ''),
            'subject' => (string)($row['descriptive_title'] ?? ''), 'program' => (string)($row['program_label'] ?? ''),
            'enrollments' => max(0, (int)($row['student_count'] ?? 0))];
    }
    $classes = array_values($classes);
    usort($classes, static function (array $a, array $b): int {
        return ($b['enrollments'] <=> $a['enrollments']) ?: strnatcasecmp($a['label'], $b['label']);
    });
    return ['ay_id' => $ayId, 'semester' => $semester, 'term' => (string)($term['term_text'] ?? 'Current term not set'), 'classes' => $classes];
}

function synk_professor_dashboard_research_chart(array $items): array
{
    $years = []; $programs = []; $seen = []; $unclassified = 0;
    foreach ($items as $item) {
        $id = (int)($item['id'] ?? 0);
        if ($id < 1 || isset($seen[$id])) { continue; }
        $seen[$id] = true;
        $program = trim(preg_replace('/\s+/u', ' ', (string)($item['program'] ?? ''))) ?: 'Program not set';
        $programKey = function_exists('mb_strtolower') ? mb_strtolower($program, 'UTF-8') : strtolower($program);
        if (!isset($programs[$programKey])) { $programs[$programKey] = ['name' => $program, 'counts' => []]; }
        $academicYear = trim((string)($item['academic_year'] ?? ''));
        if (!preg_match('/\A(?:AY\s*)?((?:19|20|21)\d{2})\s*[-\/\x{2013}\x{2014}]\s*((?:19|20|21)\d{2})\z/ui', $academicYear, $parts)
            || (int)$parts[2] < (int)$parts[1]) { $unclassified++; continue; }
        $year = $parts[1] . '-' . $parts[2];
        $years[$year] = $year;
        $programs[$programKey]['counts'][$year] = ($programs[$programKey]['counts'][$year] ?? 0) + 1;
    }
    ksort($years, SORT_NATURAL);
    uasort($programs, static function (array $a, array $b): int { return strnatcasecmp($a['name'], $b['name']); });
    $series = [];
    foreach ($programs as $program) {
        if (!$program['counts']) { continue; }
        $values = [];
        foreach ($years as $year) { $values[] = $program['counts'][$year] ?? 0; }
        $series[] = ['name' => $program['name'], 'data' => $values];
    }
    return ['years' => array_values($years), 'series' => $series, 'without_year' => $unclassified];
}

// Accept only the portfolio already authorized and validated by SynkOracleResearch.
function synk_professor_dashboard_research_summary(array $portfolio, array $labels): array
{
    $items = $portfolio['items'];
    usort($items, static function (array $a, array $b): int {
        $aTime = strtotime((string)($a['updated_at'] ?? '')) ?: (strtotime((string)($a['submitted_at'] ?? '')) ?: 0);
        $bTime = strtotime((string)($b['updated_at'] ?? '')) ?: (strtotime((string)($b['submitted_at'] ?? '')) ?: 0);
        return $bTime <=> $aTime ?: $b['id'] <=> $a['id'];
    });
    $roles = [];
    foreach ($labels as $role => $label) {
        $count = $portfolio['role_counts'][$role] ?? 0;
        if ($count > 0) {
            $roles[] = ['role' => $role, 'label' => $label, 'count' => $count];
        }
    }
    $recent = [];
    foreach (array_slice($items, 0, 4) as $item) {
        $recent[] = [
            'id' => $item['id'], 'title' => $item['title'], 'authors' => $item['authors'],
            'status' => $item['status'], 'research_type' => $item['research_type'],
            'is_published' => $item['is_published'],
            'roles' => array_map(static function (string $role) use ($labels): string { return $labels[$role]; }, $item['roles']),
        ];
    }
    return [
        'total' => count($items),
        'published' => count(array_filter($items, static function (array $item): bool { return $item['is_published']; })),
        'roles' => $roles, 'recent' => $recent, 'trend' => synk_professor_dashboard_research_chart($items),
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
