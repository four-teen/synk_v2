<?php
if (!isset($professorSidebarItems, $professorSidebarActiveKey)) { http_response_code(404); exit; }
$professorMobileItems = array_column($professorSidebarItems, null, 'key');
$professorMobileKeys = ['dashboard', 'workload', 'profile', 'researches'];
$professorMoreActive = !in_array($professorSidebarActiveKey, $professorMobileKeys, true);
$professorMobileLabels = ['dashboard'=>'Home', 'workload'=>'Workload', 'profile'=>'Profile', 'researches'=>'Research'];
?>
<link rel="stylesheet" href="../professor/navigation.css?v=<?= filemtime(__DIR__ . '/navigation.css') ?>">
<nav class="professor-bottom-nav" id="professor-bottom-nav" aria-label="Professor mobile navigation">
  <?php foreach ($professorMobileKeys as $key): if (!isset($professorMobileItems[$key])) continue; $item = $professorMobileItems[$key]; ?>
    <a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"<?= $professorSidebarActiveKey === $key ? ' aria-current="page"' : '' ?>><i class="bx <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i><span><?= $professorMobileLabels[$key] ?></span></a>
  <?php endforeach; ?>
  <button type="button" id="professor-more-toggle" data-bs-toggle="offcanvas" data-bs-target="#professor-mobile-more" aria-controls="professor-mobile-more" aria-expanded="false"<?= $professorMoreActive ? ' class="is-current"' : '' ?>><i class="bx bx-grid-alt" aria-hidden="true"></i><span>More</span></button>
</nav>
<div class="offcanvas offcanvas-bottom professor-mobile-more" tabindex="-1" id="professor-mobile-more" aria-labelledby="professor-mobile-more-title">
  <div class="offcanvas-header"><div><h2 class="offcanvas-title fs-5" id="professor-mobile-more-title">More options</h2><p>Professor workspace</p></div><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close more options"></button></div>
  <div class="offcanvas-body"><nav aria-label="All Professor pages">
    <?php foreach ($professorSidebarItems as $item): ?><a href="<?= htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8') ?>"<?= $professorSidebarActiveKey === $item['key'] ? ' aria-current="page"' : '' ?>><i class="bx <?= htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i><span><?= htmlspecialchars($item['key'] === 'dashboard' ? 'Home' : $item['title'], ENT_QUOTES, 'UTF-8') ?></span></a><?php endforeach; ?>
  </nav></div>
</div>
<script src="../professor/navigation.js?v=<?= filemtime(__DIR__ . '/navigation.js') ?>" defer></script>
