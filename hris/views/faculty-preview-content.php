<?php
if (!isset($record, $showProfile, $sectionKey, $previewUrl)) { http_response_code(404); return; }
require_once __DIR__ . '/pds-values.php';
require_once __DIR__ . '/education-details.php';
$profileLabel = ($personnel['personnel_category'] ?? '') === 'non_teaching' ? 'Employee Profile' : 'Professor Profile';
$previewHeading = $showProfile ? $profileLabel : $sections[$sectionKey]['title'];
?>
<section class="hris-preview <?= $profileDocument ? 'hris-document-preview' : 'hris-card' ?>"
    data-preview-region data-record="<?= synk_hris_h($record['owner']) ?>" data-preview-title="<?= synk_hris_h($previewHeading) ?>" aria-label="Faculty profile preview">
    <nav class="hris-preview-tabs" aria-label="Preview source">
        <a class="hris-action hris-action-pill hris-tone-<?= $showProfile ? 'blue' : 'neutral' ?>" href="<?= synk_hris_h($previewUrl . '&tab=profile') ?>" data-preview-link<?= $showProfile ? ' aria-current="page"' : '' ?>><i class="bx bx-id-card" aria-hidden="true"></i> <?= synk_hris_h($profileLabel) ?></a>
        <a class="hris-action hris-action-pill hris-tone-<?= $showProfile ? 'neutral' : 'green' ?>" href="<?= synk_hris_h($previewUrl . '&tab=pds&section=personal') ?>" data-preview-link<?= !$showProfile ? ' aria-current="page"' : '' ?>><i class="bx bx-file" aria-hidden="true"></i> Personal Data Sheet</a>
    </nav>

    <?php if ($profileDocument): ?>
        <?php require __DIR__ . '/profile-document.php'; ?>
    <?php elseif ($showProfile): ?>
        <div class="hris-panel hris-preview-data" data-preview-panel>
            <?php if ($loadError !== ''): ?><p class="hris-preview-note" role="status"><?= synk_hris_h($loadError) ?></p>
            <?php else: ?><p class="hris-preview-note">No profile details recorded.</p><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="hris-preview-shell">
            <nav class="hris-preview-sections" aria-label="Personal Data Sheet sections">
                <?php foreach ($sections as $key => $section): ?>
                    <a href="<?= synk_hris_h($previewUrl . '&tab=pds&section=' . $key . '#pds-preview') ?>" data-preview-link<?= $key === $sectionKey ? ' aria-current="page"' : '' ?>>
                        <i class="bx <?= synk_hris_h($section['icon']) ?>" aria-hidden="true"></i>
                        <span><?= synk_hris_h($section['title']) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
            <section class="hris-panel hris-preview-data" id="pds-preview" data-preview-panel aria-labelledby="pds-preview-title">
                <?php if ($sectionKey === 'work' && preg_match('/\A(?:faculty|account):/', $record['owner'])): ?>
                    <header class="hris-preview-title hris-preview-work-heading">
                        <h2 id="pds-preview-title"><?= synk_hris_h($previewHeading) ?></h2>
                        <button class="hris-action hris-action-pill hris-work-action" type="button"
                            data-preview-work data-work-record="<?= synk_hris_h($record['owner']) ?>"
                            data-work-name="<?= synk_hris_h($record['name']) ?>" aria-haspopup="dialog" aria-label="Add/Modify work experience">
                            <i class="bx bx-edit" aria-hidden="true"></i> Add/Modify
                        </button>
                    </header>
                <?php else: ?>
                    <h2 class="hris-preview-title" id="pds-preview-title"><?= synk_hris_h($previewHeading) ?></h2>
                <?php endif; ?>
                <?php if ($loadError !== ''): ?><p class="hris-preview-note" role="status"><?= synk_hris_h($loadError) ?></p><?php endif; ?>
                <?php if ($sectionKey === 'education'): ?>
                    <?php if ($educationNote !== ''): ?><p class="hris-preview-note" role="status"><?= synk_hris_h($educationNote) ?></p><?php endif; ?>
                    <?php if ($educationGroups || ($loadError === '' && $educationNote === '')) synk_hris_render_education($educationGroups); ?>
                <?php elseif ($sectionKey === 'work' && $loadError === ''): ?>
                    <?php $workRecords = $sectionData['values']['work'] ?? []; require __DIR__ . '/../../backend/faculty_pds_work_preview_view.php'; ?>
                <?php elseif ($loadError === '' && !$sectionData): ?><p class="hris-preview-note"><?= $sectionKey === 'family' ? 'No family details recorded.' : 'No details recorded for this section.' ?></p>
                <?php elseif ($loadError === ''): ?>
                    <?php synk_hris_render_pds_section($sections[$sectionKey], $sectionData['values']); ?>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
</section>
