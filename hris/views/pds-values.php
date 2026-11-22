<?php

function synk_hris_render_values(array $values): void
{
    if (!$values) return;
    ?>
    <dl class="hris-preview-values">
        <?php foreach ($values as $label => $value): ?>
            <div>
                <dt><?= synk_hris_h($label) ?></dt>
                <dd><?= trim((string)$value) === '' ? '<span class="hris-muted">Not recorded</span>' : synk_hris_h((string)$value) ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>
    <?php
}

function synk_hris_pds_full_name(array $values, string $prefix = ''): string
{
    $surname = trim($values[$prefix . 'surname'] ?? '');
    $givenNames = implode(' ', array_filter([
        trim($values[$prefix . 'first_name'] ?? ''),
        trim($values[$prefix . 'middle_name'] ?? ''),
        trim($values[$prefix . 'name_extension'] ?? ''),
    ], 'strlen'));
    return implode(', ', array_filter([$surname, $givenNames], 'strlen'));
}

function synk_hris_render_pds_family(array $values): void
{
    $spouses = [];
    foreach ($values['spouses'] ?? [] as $spouse) {
        $spouses[] = array_filter([
            'Full name' => synk_hris_pds_full_name($spouse),
            'Occupation' => $spouse['occupation'] ?? '',
            'Employer / business name' => $spouse['employer'] ?? '',
            'Business address' => $spouse['business_address'] ?? '',
            'Telephone number' => $spouse['telephone'] ?? '',
        ], 'strlen');
    }
    $parents = array_filter([
        "Father's name" => synk_hris_pds_full_name($values, 'father_'),
        "Mother's maiden name" => synk_hris_pds_full_name($values, 'mother_'),
    ], 'strlen');
    $children = [];
    foreach ($values['children'] ?? [] as $child) {
        $children[] = array_filter([
            'Full name' => $child['full_name'] ?? '',
            'Date of birth' => $child['date_of_birth'] ?? '',
        ], 'strlen');
    }
    $groups = array_filter([
        'Spouse' => array_filter($spouses),
        'Parents' => $parents ? [$parents] : [],
        'Children' => array_filter($children),
    ]);
    if (!$groups): ?>
        <p class="hris-preview-note">No family details recorded.</p>
    <?php endif; ?>
    <?php foreach ($groups as $heading => $records): ?>
        <section class="hris-preview-group"><h3><?= synk_hris_h($heading) ?></h3>
            <?php foreach ($records as $fields): ?>
                <div class="hris-preview-record"><?php synk_hris_render_values($fields); ?></div>
            <?php endforeach; ?>
        </section>
    <?php endforeach;
}

function synk_hris_render_pds_section(array $section, array $values): void
{
    if ($section['key'] === 'family') {
        synk_hris_render_pds_family($values);
        return;
    }
    $items = $section['groups'];
    if ($section['key'] !== 'personal') {
        synk_hris_render_pds_items($items, $values);
        return;
    }

    $nameKeys = array_fill_keys(['surname', 'first_name', 'middle_name', 'name_extension'], true);
    $personalDetails = array_filter(array_merge([], ...array_column($items, 'fields')), static function (array $item) use ($nameKeys): bool {
        return !isset($nameKeys[$item['key'] ?? '']);
    });
    synk_hris_render_pds_items($personalDetails, $values, ['Full name' => synk_hris_pds_full_name($values)]);
}

function synk_hris_render_pds_items(array $items, array $values, array $fields = []): void
{
    foreach ($items as $item) {
        if (isset($item['when'])) {
            [$key, $expected] = $item['when'];
            $actual = $values[$key] ?? '';
            if ($expected === 'unchecked' ? $actual === '1' : $actual !== $expected) continue;
        }
        $type = $item['type'];
        if ($type === 'file') continue;
        if (!in_array($type, ['group', 'list', 'photo_reference'], true)) {
            $value = (string)($values[$item['key']] ?? '');
            if ($type === 'checkbox' && $value !== '') $value = $value === '1' ? 'Yes' : 'No';
            $fields[$item['label']] = $value;
            continue;
        }
        synk_hris_render_values($fields);
        $fields = [];
        if ($type === 'photo_reference') {
            echo '<p class="hris-preview-note">Printed PDS only.</p>';
        } elseif ($type === 'group' && $item['fields']) {
            ?>
            <section class="hris-preview-group<?= empty($item['question']) ? '' : ' hris-preview-question' ?>">
                <h3><?= synk_hris_h($item['label']) ?></h3>
                <?php synk_hris_render_pds_items($item['fields'], $values); ?>
            </section>
            <?php
        } elseif ($type === 'list') {
            $records = $values[$item['key']] ?? [];
            ?>
            <section class="hris-preview-group"><h3><?= synk_hris_h($item['label']) ?></h3>
                <?php if (!$records): ?><p class="hris-preview-note">No entries recorded.</p><?php endif; ?>
                <?php foreach ($records as $recordValues): ?>
                    <div class="hris-preview-record">
                        <?php synk_hris_render_pds_items($item['fields'], $recordValues); ?>
                    </div>
                <?php endforeach; ?>
            </section>
            <?php
        }
    }
    synk_hris_render_values($fields);
}
