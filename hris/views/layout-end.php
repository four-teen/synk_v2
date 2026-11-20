<?php if (!isset($view, $views)) { http_response_code(404); return; } ?>
        </main>
        </div>
    </div>

    <nav class="hris-mobile-nav" aria-label="Human Resources">
        <?php foreach ($views as $key => $item): ?>
            <a href="<?= synk_hris_h($item['url']) ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><i class="bx <?= $item['icon'] ?>" aria-hidden="true"></i><?= synk_hris_h($item['mobile_label']) ?></a>
        <?php endforeach; ?>
    </nav>
</body>
</html>
