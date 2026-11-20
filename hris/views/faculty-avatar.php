<?php
if (!isset($record)) { http_response_code(404); return; }
$photo = synk_faculty_profile_image_url($record['photo_user_id'], $record['photo_faculty_id']);
?>
<span class="hris-faculty-avatar" aria-hidden="true">
    <span class="hris-faculty-portrait">
        <i class="bx bx-user"></i>
        <?php if ($photo !== ''): ?>
            <img src="<?= synk_hris_h($photo) ?>" alt="" width="100" height="100" loading="lazy" decoding="async">
        <?php endif; ?>
    </span>
</span>
