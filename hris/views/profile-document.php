<?php
if (empty($profile) || !isset($record)) { http_response_code(404); return; }

$formattedDate = static function (string $value): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $date->format('j F Y') : $value;
};
$profileName = trim((string)($profile['full_name'] ?? '')) ?: $record['name'];
$profileGroups = [
    'Personal information' => [
        'Faculty ID' => $profile['faculty_id_number'] ?? '',
        'Date of birth' => $formattedDate((string)($profile['date_of_birth'] ?? '')),
        'Gender' => $profile['gender'] ?? '',
        'Civil status' => $profile['civil_status'] ?? '',
    ],
    'Employment' => [
        'Academic rank' => $profile['faculty_rank'] ?? '',
        'College' => $profile['college_name'] ?? '',
        'Campus' => $profile['campus_name'] ?? '',
        'Employment classification' => $profile['employment_classification'] ?? '',
        'Salary grade' => $profile['salary_grade'] ?? '',
        'Service start date' => $formattedDate((string)($profile['service_start_date'] ?? '')),
        'Length of service' => $profile['length_of_service'] ?? '',
    ],
];
if (!empty($profile['work_status'])) $profileGroups['Employment']['Work status'] = $profile['work_status'];
$eligibility = trim((string)($profile['eligibility'] ?? ''));
$education = [];
foreach (['bachelor' => 'Bachelor’s', 'master' => 'Master’s', 'doctoral' => 'Doctoral'] as $prefix => $label) {
    $entry = [];
    foreach (['degree', 'institution', 'address', 'specialization', 'status'] as $key) {
        $entry[$key] = trim((string)($profile[$prefix . '_' . $key] ?? ''));
    }
    if (array_filter($entry, 'strlen')) $education[$label] = $entry;
}
?>

<article class="hris-profile-document" aria-labelledby="profile-document-title">
    <header class="hris-document-header">
        <h1 id="profile-document-title"><?= synk_hris_h($profileName) ?></h1>
        <?php require __DIR__ . '/faculty-avatar.php'; ?>
    </header>

    <?php foreach ($profileGroups as $heading => $fields): ?>
        <section class="hris-document-section">
            <h2><?= synk_hris_h($heading) ?></h2>
            <dl class="hris-document-facts">
                <?php foreach ($fields as $label => $value): ?>
                    <div>
                        <dt><?= synk_hris_h($label) ?></dt>
                        <dd><?= trim((string)$value) === '' ? '<span class="hris-muted">Not recorded</span>' : synk_hris_h((string)$value) ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </section>
    <?php endforeach; ?>

    <section class="hris-document-section" aria-labelledby="profile-education-title">
        <h2 id="profile-education-title">Education</h2>
        <?php if (!$education): ?><p class="hris-muted">No education entries recorded.</p><?php endif; ?>
        <?php foreach ($education as $level => $entry): ?>
            <div class="hris-document-education">
                <p class="hris-document-level"><?= synk_hris_h($level) ?><?= $entry['status'] !== '' ? ' · ' . synk_hris_h($entry['status']) : '' ?></p>
                <?php if ($entry['degree'] !== ''): ?><h3><?= synk_hris_h($entry['degree']) ?></h3><?php endif; ?>
                <?php if ($entry['institution'] !== ''): ?><p><?= synk_hris_h($entry['institution']) ?></p><?php endif; ?>
                <?php if ($entry['address'] !== ''): ?><p class="hris-muted"><?= synk_hris_h($entry['address']) ?></p><?php endif; ?>
                <?php if ($entry['specialization'] !== ''): ?><p class="hris-document-specialization">Specialization in <?= synk_hris_h($entry['specialization']) ?></p><?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <?php if ($eligibility !== ''): ?>
        <section class="hris-document-section" aria-labelledby="profile-eligibility-title">
            <h2 id="profile-eligibility-title">Eligibility</h2>
            <p class="hris-document-notes"><?= synk_hris_h($eligibility) ?></p>
        </section>
    <?php endif; ?>
</article>
