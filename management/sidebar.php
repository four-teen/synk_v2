<?php if (!isset($scope,$navigation,$page)) { http_response_code(404); exit; } ?>
<aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme" aria-label="Management navigation">
  <div class="app-brand demo"><a href="<?= synk_management_h($pageUrl('index.php')) ?>" class="app-brand-link"><span class="app-brand-logo demo"><img src="../assets/img/favicon/synk-icon.png" width="40" height="40" alt=""></span><span class="app-brand-text demo menu-text fw-bolder ms-2">Synk</span></a><button type="button" class="layout-menu-toggle menu-link text-large ms-auto d-block d-xl-none management-menu-close" aria-label="Close navigation"><i class="bx bx-chevron-left bx-sm align-middle" aria-hidden="true"></i></button></div>
  <div class="menu-inner-shadow"></div>
  <ul class="menu-inner py-1 management-nav"><li class="menu-header small text-uppercase mt-1"><span class="menu-header-text">Management Portal</span></li>
    <?php foreach($navigation as $key=>$item): ?><li class="menu-item px-2<?= $key===$page ? ' active' : '' ?>"><a href="<?= synk_management_h($pageUrl($item[1])) ?>" class="menu-link college-dean-menu-card<?= $key===$page ? ' active' : '' ?>"<?= $key===$page ? ' aria-current="page"' : '' ?>><span class="college-dean-menu-icon bg-label-<?= $item[4] ?>"><i class="bx <?= $item[2] ?>" aria-hidden="true"></i></span><div class="college-dean-menu-content"><div class="college-dean-menu-title"><?= $item[0] ?></div><small class="college-dean-menu-desc"><?= $item[3] ?></small></div></a></li><?php endforeach; ?>
  </ul>
</aside>
