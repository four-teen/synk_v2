<?php
if (!isset($record, $showProfile, $sectionKey)) { http_response_code(404); return; }
require_once __DIR__ . '/pds-values.php';
?>
<section class="hris-preview <?= $profileDocument ? 'hris-document-preview' : 'hris-card' ?>" aria-label="Faculty profile preview">
    <div class="hris-preview-toolbar">
        <nav class="hris-preview-tabs" aria-label="Preview source">
            <a href="<?= synk_hris_h($previewUrl . '&tab=profile') ?>"<?= $showProfile ? ' aria-current="page"' : '' ?>><i class="bx bx-id-card" aria-hidden="true"></i> Professor Profile</a>
            <a href="<?= synk_hris_h($previewUrl . '&tab=pds') ?>"<?= !$showProfile ? ' aria-current="page"' : '' ?>><i class="bx bx-file" aria-hidden="true"></i> Personal Data Sheet</a>
        </nav>
        <?php if ($profileDocument): ?>
            <a class="hris-action" href="<?= synk_hris_h($manageUrl) ?>"><i class="bx bx-edit" aria-hidden="true"></i> Manage review</a>
        <?php endif; ?>
    </div>

    <?php if ($profileDocument): ?>
        <?php require __DIR__ . '/profile-document.php'; ?>
    <?php elseif ($showProfile): ?>
        <div class="hris-panel hris-preview-data">
            <?php if ($loadError !== ''): ?><p class="hris-empty" role="status"><?= synk_hris_h($loadError) ?></p>
            <?php else: ?><p class="hris-empty">There is no saved Professor Profile for this record.</p><?php endif; ?>
        </div>
    <?php else: ?>
        <div class="hris-preview-shell">
            <nav class="hris-preview-sections" aria-label="Personal Data Sheet sections">
                <?php foreach ($sections as $key => $section): ?>
                    <a href="<?= synk_hris_h($previewUrl . '&tab=pds&section=' . $key . '#pds-preview') ?>"<?= $key === $sectionKey ? ' aria-current="page"' : '' ?>>
                        <i class="bx <?= synk_hris_h($section['icon']) ?>" aria-hidden="true"></i>
                        <?= synk_hris_h($section['title']) ?>
                        <?php if (isset($record['saved_sections'][$key])): ?><i class="bx bx-check-circle" aria-label="Saved"></i><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <section class="hris-panel hris-preview-data" id="pds-preview" aria-labelledby="pds-preview-title">
                <div class="hris-panel-heading">
                    <div><h2 id="pds-preview-title"><?= synk_hris_h($sections[$sectionKey]['title']) ?></h2>
                        <?php if ($sectionData): ?><p>Saved <?= synk_hris_h(date('M j, Y, g:i A', strtotime($sectionData['updated_at']))) ?></p><?php endif; ?>
                    </div>
                    <span class="hris-status hris-tone-<?= $sectionData ? 'green' : 'neutral' ?>"><?= $sectionData ? 'Saved' : ($loadError !== '' ? 'Unavailable' : 'Not saved') ?></span>
                </div>
                <?php if ($loadError !== ''): ?><p class="hris-empty" role="status"><?= synk_hris_h($loadError) ?></p>
                <?php elseif (!$sectionData): ?><p class="hris-empty">The faculty member hasn’t saved this PDS section yet.</p>
                <?php else: ?>
                    <?php synk_hris_render_pds_items($sections[$sectionKey]['groups'], $sectionData['values']); ?>
                <?php endif; ?>
            </section>
        </div>
    <?php endif; ?>
</section>
