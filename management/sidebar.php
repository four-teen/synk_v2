<?php
if (!isset($scope,$navigation,$page)) { http_response_code(404); exit; }
$menuGroups = [
    'Overview'=>['dashboard'],
    'Daily Operations'=>['financial','students','reports'],
    'Setup'=>['contributions','events','violations','officers'],
];
?>
<aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme" aria-label="Management navigation">
  <div class="app-brand demo"><a href="<?= synk_management_h($pageUrl('index.php')) ?>" class="app-brand-link"><span class="app-brand-logo demo"><img src="../assets/img/favicon/synk-icon.png" width="40" height="40" alt=""></span><span class="app-brand-text demo menu-text fw-bolder ms-2">synQ</span></a><button type="button" class="layout-menu-toggle menu-link text-large ms-auto d-block d-xl-none management-menu-close" aria-label="Close navigation"><i class="bx bx-chevron-left bx-sm align-middle" aria-hidden="true"></i></button></div>
  <div class="menu-inner-shadow"></div>
  <ul class="menu-inner py-1 management-nav">
    <?php foreach($menuGroups as $groupLabel=>$keys): ?>
      <li class="menu-header small text-uppercase management-nav-section"><span class="menu-header-text"><?= synk_management_h($groupLabel) ?></span></li>
      <?php foreach($keys as $key): if(!synk_management_operation_allowed($permissions,$key==='students'?'student_records':($key==='financial'?'records':$key)) && $key!=='dashboard')continue;$item=$navigation[$key]; ?>
        <li class="menu-item px-2<?= $key===$page ? ' active' : '' ?>"><a href="<?= synk_management_h($pageUrl($item[1])) ?>" class="menu-link college-dean-menu-card<?= $key===$page ? ' active' : '' ?>"<?= $key===$page ? ' aria-current="page"' : '' ?>><span class="college-dean-menu-icon bg-label-<?= $item[4] ?>"><i class="bx <?= $item[2] ?>" aria-hidden="true"></i></span><div class="college-dean-menu-content"><div class="college-dean-menu-title"><?= synk_management_h($item[0]) ?></div><small class="college-dean-menu-desc"><?= synk_management_h($item[3]) ?></small></div></a></li>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </ul>
  <div class="management-sidebar-college" aria-label="Assigned college"><span>College</span><strong><?= synk_management_h($scope['college_name']) ?></strong></div>
</aside>
