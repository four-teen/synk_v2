<?php
if (!isset($record, $showProfile, $sectionKey)) { http_response_code(404); return; }

$surname = trim($nameParts['surname'] ?? '');
$firstName = trim($nameParts['first_name'] ?? '');
$firstName = function_exists('mb_convert_case')
    ? mb_convert_case($firstName, MB_CASE_TITLE, 'UTF-8')
    : ucwords(strtolower($firstName), " \t-'");
$middleName = trim($nameParts['middle_name'] ?? '');
$extension = trim($nameParts['name_extension'] ?? '');
$nonTeaching = ($personnel['personnel_category'] ?? '') === 'non_teaching';
$jobTitle = $nonTeaching ? ($personnel['position_title'] ?? '') : (synk_hris_rank_label($record['rank']) ?? $record['rank']);
$employment = $record['appointment'] ?: $record['work_status'];
?>
<main id="main-content" class="hris-main hris-detail" tabindex="-1" data-faculty-preview>
<nav aria-label="Faculty navigation">
    <a class="hris-action hris-action-pill hris-tone-blue" href="./?view=faculty"><i class="bx bx-left-arrow-alt" aria-hidden="true"></i> Employee Profiles</a>
</nav>

<header class="hris-card hris-detail-hero">
    <div class="hris-faculty-identity">
        <?php require __DIR__ . '/faculty-avatar.php'; ?>
        <div class="hris-faculty-info">
            <?php if ($jobTitle !== ''): ?><small class="hris-faculty-rank"><?= synk_hris_h($jobTitle) ?></small><?php endif; ?>
            <h1 class="hris-preview-name" id="faculty-preview-name">
                <?php if ($surname !== '' || $firstName !== ''): ?>
                    <?php if ($surname !== ''): ?><span class="hris-preview-surname"><?= synk_hris_h($surname) ?></span><?= $firstName !== '' ? ', ' : '' ?><?php endif; ?>
                    <?= synk_hris_h($firstName) ?>
                    <?php if ($middleName !== ''): ?><em><?= synk_hris_h($middleName) ?></em><?php endif; ?>
                    <?= synk_hris_h($extension) ?>
                <?php else: ?>
                    <?= synk_hris_h($record['name']) ?>
                <?php endif; ?>
            </h1>
            <small class="hris-faculty-id-line">
                <?php if ($record['employee_id'] !== ''): ?>
                    <b class="hris-employee-id"><?= synk_hris_h($record['employee_id']) ?></b>
                <?php else: ?>Employee ID not recorded<?php endif; ?>
                <span class="hris-faculty-id-divider" aria-hidden="true">|</span>
                <?= synk_hris_h($employment ?: 'Employment not recorded') ?>
            </small>
        </div>
    </div>
</header>

<p class="hris-preview-announcement" data-preview-announcement role="status"></p>
<p class="hris-detail-warning" data-preview-error role="alert" hidden>Could not open this section. Select it again to retry.</p>
<?php require __DIR__ . '/faculty-preview-content.php'; ?>
