<?php if (!isset($record, $reviewOptions, $token)) { http_response_code(404); return; } ?>
<div class="hris-detail-grid">
    <section class="hris-card hris-panel" aria-labelledby="pds-checklist-title">
        <div class="hris-panel-heading">
            <div><h2 id="pds-checklist-title">PDS section checklist</h2><p><?= $savedCount ?> of <?= count($sections) ?> sections saved</p></div>
            <span class="hris-icon hris-tone-green"><i class="bx bx-list-check" aria-hidden="true"></i></span>
        </div>
        <?php if (isset($sources['pds'])): ?>
            <progress class="hris-progress hris-progress-green hris-checklist-progress" value="<?= $savedCount ?>" max="<?= count($sections) ?>" aria-label="Saved PDS sections"></progress>
            <ul class="hris-section-list">
                <?php foreach ($sections as $key => $section): ?>
                    <?php $saved = isset($record['saved_sections'][$key]); ?>
                    <li><a href="<?= synk_hris_h($previewUrl . '&tab=pds&section=' . $key) ?>">
                        <i class="bx <?= synk_hris_h($section['icon']) ?>" aria-hidden="true"></i>
                        <span><?= synk_hris_h($section['title']) ?></span>
                        <span class="hris-status hris-tone-<?= $saved ? 'green' : 'neutral' ?>"><?= $saved ? 'Saved' : 'Not saved' ?></span>
                        <i class="bx bx-chevron-right" aria-hidden="true"></i>
                    </a></li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?><p class="hris-empty">PDS progress is unavailable.</p><?php endif; ?>
    </section>

    <section class="hris-card hris-panel" aria-labelledby="hr-review-title">
        <div class="hris-panel-heading">
            <div><h2 id="hr-review-title">HR review</h2><p>Record an internal review status and any follow-up needed.</p></div>
            <?php if ($review && !$reviewStale): ?>
                <span class="hris-status hris-tone-<?= $review['review_status'] === 'reviewed' ? 'green' : 'neutral' ?>"><?= synk_hris_h($reviewOptions[$review['review_status']]) ?></span>
            <?php endif; ?>
        </div>

        <?php if ($notice === $owner): ?><p class="hris-detail-feedback hris-detail-success" role="status"><i class="bx bx-check-circle" aria-hidden="true"></i> HR review saved.</p><?php endif; ?>
        <?php if ($saveError !== ''): ?><p class="hris-detail-feedback hris-detail-warning" role="alert"><?= synk_hris_h($saveError) ?></p><?php endif; ?>
        <?php if ($loadError !== ''): ?><p class="hris-detail-feedback hris-detail-warning" role="status"><?= synk_hris_h($loadError) ?></p><?php endif; ?>
        <?php if (count($sources) !== 2): ?><p class="hris-detail-feedback hris-detail-warning">Some faculty data is unavailable. Reload before saving a review.</p><?php endif; ?>
        <?php if ($reviewStale): ?><p class="hris-detail-feedback hris-detail-warning">The saved faculty data changed after the last HR review. Check the latest entries before saving.</p><?php endif; ?>

        <?php if ($record['employee_id'] === '' || $record['id_mismatch'] || $record['duplicate_id'] || $record['link_issue'] !== ''): ?>
            <ul class="hris-review-flags" aria-label="Items to follow up">
                <?php if ($record['employee_id'] === ''): ?><li>Employee ID not recorded</li><?php endif; ?>
                <?php if ($record['id_mismatch']): ?><li>Professor Profile ID differs (<?= synk_hris_h($record['profile_id_number']) ?>)</li><?php endif; ?>
                <?php if ($record['duplicate_id']): ?><li>Employee ID is also used by another record</li><?php endif; ?>
                <?php if ($record['link_issue'] !== ''): ?><li><?= synk_hris_h($record['link_issue']) ?></li><?php endif; ?>
            </ul>
        <?php endif; ?>

        <?php if ($loadError === ''): ?>
            <form class="hris-review-form" method="post" action="<?= synk_hris_h($manageUrl) ?>">
                <input type="hidden" name="csrf_token" value="<?= synk_hris_h($token) ?>">
                <input type="hidden" name="revision" value="<?= $reviewRevision ?>">
                <input type="hidden" name="data_version" value="<?= synk_hris_h($record['data_version']) ?>">
                <label for="review-status">Review status<select id="review-status" name="review_status">
                    <?php foreach ($reviewOptions as $key => $label): ?>
                        <option value="<?= $key ?>"<?= $key === $reviewStatus ? ' selected' : '' ?>><?= synk_hris_h($label) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label for="review-note">Internal HR note<textarea id="review-note" name="review_note" rows="5" maxlength="2000" placeholder="Record findings or the next follow-up."><?= synk_hris_h($reviewNote) ?></textarea></label>
                <div class="hris-review-actions">
                    <button type="submit" class="hris-action hris-action-primary"<?= $reviewLocked ? ' disabled' : '' ?>><i class="bx bx-save" aria-hidden="true"></i> Save review</button>
                    <?php if ($reviewLocked): ?>
                        <a class="hris-text-link" href="<?= synk_hris_h($manageUrl) ?>">Reload review</a>
                    <?php elseif ($review): ?>
                        <small>Updated <?= synk_hris_h(date('M j, Y, g:i A', strtotime($review['updated_at']))) ?><?= $review['username'] !== '' ? ' by ' . synk_hris_h($review['username']) : '' ?></small>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>
