<?php if ($flash): ?>
    <?php $success = $flash['kind'] === 'success'; ?>
    <div class="hris-account-alert hris-account-alert-<?= $success ? 'success' : 'error' ?>" role="<?= $success ? 'status' : 'alert' ?>">
        <i class="bx <?= $success ? 'bx-check-circle' : 'bx-error-circle' ?>" aria-hidden="true"></i>
        <span><?= synk_hris_h($flash['message']) ?></span>
    </div>
<?php endif; ?>
