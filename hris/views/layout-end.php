<?php if (!isset($view, $views)) { http_response_code(404); return; } ?>
        </main>
        </div>
    </div>

    <?php if ($views || $canManageAccounts || $canManagePositions): ?>
    <nav class="hris-mobile-nav" aria-label="Human Resources">
        <?php foreach ($views as $key => $item): ?>
            <a href="<?= synk_hris_h($item['url']) ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><i class="bx <?= $item['icon'] ?>" aria-hidden="true"></i><?= synk_hris_h($item['mobile_label']) ?></a>
        <?php endforeach; ?>
        <?php $mobileManagement = true; require __DIR__ . '/management-navigation.php'; ?>
    </nav>
    <?php endif; ?>
    <?php if ($canManagePositions) require __DIR__ . '/position-catalog-dialog.php'; ?>
</body>
</html>
