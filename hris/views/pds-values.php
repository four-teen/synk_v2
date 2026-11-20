<?php

function synk_hris_render_values(array $values): void
{
    if (!$values) return;
    ?>
    <dl class="hris-preview-values">
        <?php foreach ($values as $label => $value): ?>
            <div><dt><?= synk_hris_h($label) ?></dt>
                <dd><?= $value === '' ? '<span class="hris-muted">Not recorded</span>' : synk_hris_h($value) ?></dd>
            </div>
        <?php endforeach; ?>
    </dl>
    <?php
}

function synk_hris_render_pds_items(array $items, array $values): void
{
    $fields = [];
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
            echo '<p class="hris-printed-photo">Attach a printed photo to the PDS</p>';
        } elseif ($type === 'group' && $item['fields']) {
            ?>
            <section class="hris-preview-group"><h3><?= synk_hris_h($item['label']) ?></h3>
                <?php synk_hris_render_pds_items($item['fields'], $values); ?>
            </section>
            <?php
        } elseif ($type === 'list') {
            $records = $values[$item['key']] ?? [];
            ?>
            <section class="hris-preview-group"><h3><?= synk_hris_h($item['label']) ?></h3>
                <?php if (!$records): ?><p class="hris-preview-note">No records saved.</p><?php endif; ?>
                <?php foreach ($records as $index => $recordValues): ?>
                    <div class="hris-preview-record"><h4><?= synk_hris_h(ucfirst($item['singular'])) ?> <?= $index + 1 ?></h4>
                        <?php synk_hris_render_pds_items($item['fields'], $recordValues); ?>
                    </div>
                <?php endforeach; ?>
            </section>
            <?php
        }
    }
    synk_hris_render_values($fields);
}
