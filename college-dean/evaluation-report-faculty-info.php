<?php if (!isset($sefFacultyInfo)) { http_response_code(404); exit; } ?>
<section class="sef-word-faculty">
    <h2 class="sef-word-heading"><span>A.</span>Faculty Information</h2>
    <dl class="sef-word-fields">
        <?php foreach ($sefFacultyInfo as $label => $value): ?>
        <div>
            <dt><?= sef_h($label) ?></dt>
            <dd><?= sef_h($value) ?></dd>
        </div>
        <?php endforeach; ?>
    </dl>
</section>
