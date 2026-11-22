<?php

function synk_hris_render_education(array $groups): void
{
    if (!$groups): ?>
        <p class="hris-preview-note">No education entries recorded.</p>
    <?php endif;

    $labels = [
        'institution' => 'School',
        'status' => 'Study status',
        'specialization' => 'Specialization',
        'year_from' => 'Attendance from',
        'year_to' => 'Attendance to',
        'year_graduated' => 'Year graduated',
        'units' => 'Highest level / units earned',
        'honors' => 'Scholarship / academic honors',
        'address' => 'School address',
    ];
    foreach ($groups as $level => $entries): ?>
        <section class="hris-education-level">
            <h3><?= synk_hris_h($level) ?></h3>
            <div class="hris-education-entries<?= count($entries) > 1 ? ' hris-education-entries-multiple' : '' ?>">
                <?php foreach ($entries as $entry):
                    $title = trim((string)($entry['degree'] ?? ''));
                    $fields = [];
                    foreach ($labels as $field => $label) {
                        $value = trim((string)($entry[$field] ?? ''));
                        if ($value !== '') $fields[$label] = $value;
                    }
                    if ($title === '' && isset($fields['School'])) {
                        $title = $fields['School'];
                        unset($fields['School']);
                    }
                ?>
                    <article class="hris-education-entry">
                        <h4><?= synk_hris_h($title ?: 'Education entry') ?></h4>
                        <?php synk_hris_render_values($fields); ?>
                        <?php if (isset($entry['source'])): ?><small>From <?= synk_hris_h($entry['source']) ?></small><?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach;
}
