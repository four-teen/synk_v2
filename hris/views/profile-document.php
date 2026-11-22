<?php
if (!isset($record) || (!$profile && empty($personnel))) { http_response_code(404); return; }

$formattedDate = static function (string $value): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $date->format('j F Y') : $value;
};
$category = $personnel['personnel_category'] ?? '';
$assignment = $category === 'non_teaching' ? [
    'Position title' => $personnel['position_title'] ?? '',
    'Office or unit' => $personnel['office_unit'] ?? '',
    'Salary grade' => isset($personnel['salary_grade']) ? 'SG ' . (int)$personnel['salary_grade'] : '',
] : [
    'Academic rank' => $profile['faculty_rank'] ?? '',
    'College' => $profile['college_name'] ?? '',
    'Salary grade' => $profile['salary_grade'] ?? '',
];
$profileGroups = [
    'Personal information' => [
        'Full name' => $profile['full_name'] ?? $record['name'],
        'Employee ID' => $record['employee_id'],
        'Date of birth' => $formattedDate((string)($profile['date_of_birth'] ?? '')),
        'Gender' => $profile['gender'] ?? '',
        'Civil status' => $profile['civil_status'] ?? '',
    ],
    'Employment' => ['Personnel category' => synk_hris_personnel_categories()[$category] ?? ''] + $assignment + [
        'Campus' => $profile['campus_name'] ?? '',
        'Employment classification' => $profile['employment_classification'] ?? '',
        'Service start date' => $formattedDate((string)($profile['service_start_date'] ?? '')),
        'Length of service' => $profile['length_of_service'] ?? '',
    ],
];
if (!empty($personnel['plantilla_item_number'])) $profileGroups['Employment']['Plantilla item number'] = $personnel['plantilla_item_number'];
if (!empty($profile['work_status'])) $profileGroups['Employment']['Work status'] = $profile['work_status'];
$eligibility = trim((string)($profile['eligibility'] ?? ''));
?>

<article class="hris-profile-document" data-preview-panel aria-labelledby="faculty-preview-name">
    <?php foreach ($profileGroups as $heading => $fields): ?>
        <section class="hris-document-section">
            <h2><?= synk_hris_h($heading) ?></h2>
            <?php synk_hris_render_values($fields); ?>
        </section>
    <?php endforeach; ?>

    <section class="hris-document-section" aria-labelledby="profile-education-title">
        <h2 id="profile-education-title">Education</h2>
        <?php if ($educationNote !== ''): ?><p class="hris-preview-note" role="status"><?= synk_hris_h($educationNote) ?></p><?php endif; ?>
        <?php if ($educationGroups || $educationNote === '') synk_hris_render_education($educationGroups); ?>
    </section>

    <?php if ($eligibility !== ''): ?>
        <section class="hris-document-section" aria-labelledby="profile-eligibility-title">
            <h2 id="profile-eligibility-title">Eligibility</h2>
            <p class="hris-document-notes"><?= synk_hris_h($eligibility) ?></p>
        </section>
    <?php endif; ?>
</article>
