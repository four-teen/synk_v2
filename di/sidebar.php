<?php
$diCurrentPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$diSidebarSections = [
    ['label' => 'Overview', 'items' => [
        ['index.php', 'bx-home-circle', 'Dashboard', 'University-wide faculty profile analytics', 'primary', ['index.php']],
    ]],
    ['label' => 'Faculty Management', 'items' => [
        ['professors.php', 'bx-user-voice', 'List of Professors', 'Review faculty profiles at 25% completion or more', 'info', ['professors.php', 'faculty-profile.php']],
    ]],
    ['label' => 'Faculty Insights', 'items' => [
        ['index.php#coverage', 'bx-list-check', 'Profile Coverage', 'Monitor saved and incomplete profiles', 'warning', []],
        ['index.php#qualifications', 'bx-book-reader', 'Qualifications', 'Education, ranks, and eligibility', 'primary', []],
        ['index.php#profiles', 'bx-id-card', 'Profile Register', 'Review professor profile completion', 'info', []],
    ]],
];
?>
<aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme" aria-label="DI navigation">
  <div class="app-brand demo">
    <a href="index.php" class="app-brand-link">
      <span class="app-brand-logo demo"><img src="../assets/img/favicon/synk-icon.png" width="40" height="40" alt="" /></span>
      <span class="app-brand-text demo menu-text fw-bolder ms-2">Synk</span>
    </a>
    <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto d-block d-xl-none" aria-label="Close menu"><i class="bx bx-chevron-left bx-sm align-middle"></i></a>
  </div>
  <div class="menu-inner-shadow"></div>
  <ul class="menu-inner py-1">
    <?php foreach ($diSidebarSections as $section): ?>
      <li class="menu-header small text-uppercase"><span class="menu-header-text"><?= synk_leadership_h($section['label']) ?></span></li>
      <?php foreach ($section['items'] as $item): $diActive = in_array($diCurrentPage, $item[5], true); ?>
        <li class="menu-item px-2<?= $diActive ? ' active' : '' ?>">
          <a href="<?= synk_leadership_h($item[0]) ?>" class="menu-link sidebar-action-card<?= $diActive ? ' active' : '' ?>"<?= $diActive ? ' aria-current="page"' : '' ?>>
            <span class="sidebar-action-icon bg-label-<?= $item[4] ?>"><i class="bx <?= $item[1] ?>" aria-hidden="true"></i></span>
            <span class="sidebar-action-content"><span class="sidebar-action-title"><?= synk_leadership_h($item[2]) ?></span><small class="sidebar-action-sub"><?= synk_leadership_h($item[3]) ?></small></span>
          </a>
        </li>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </ul>
</aside>
