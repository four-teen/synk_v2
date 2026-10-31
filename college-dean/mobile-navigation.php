<?php
// Navigation presentation only. Reuse the existing Dean destinations and active keys.
if (!isset($collegeDeanSidebarItems,$collegeDeanSidebarActiveKey) || ($_SESSION['role'] ?? '')!=='college_dean') return;
$deanMobilePrimary=['dashboard'=>'Home','students'=>'Students','faculty-evaluations'=>'Evaluation','reports'=>'Reports'];
$deanMobileItems=array_column($collegeDeanSidebarItems,null,'key');
$deanMobileMoreActive=!isset($deanMobilePrimary[$collegeDeanSidebarActiveKey]);
?>
<link rel="stylesheet" href="../college-dean/mobile-navigation.css?v=<?= (int)filemtime(__DIR__.'/mobile-navigation.css') ?>">
<script>document.documentElement.classList.add('dean-mobile-shell');</script>
<nav class="dean-mobile-nav" id="dean-mobile-nav" aria-label="College Dean mobile navigation">
<?php foreach ($deanMobilePrimary as $deanMobileKey=>$deanMobileLabel): $deanMobileItem=$deanMobileItems[$deanMobileKey]; ?>
<a href="<?= htmlspecialchars($deanMobileItem['href'],ENT_QUOTES,'UTF-8') ?>"<?= $collegeDeanSidebarActiveKey===$deanMobileKey?' aria-current="page"':'' ?>><i class="bx <?= htmlspecialchars($deanMobileItem['icon'],ENT_QUOTES,'UTF-8') ?>" aria-hidden="true"></i><span><?= $deanMobileLabel ?></span></a>
<?php endforeach; ?>
<button type="button" id="dean-mobile-more-toggle" data-bs-toggle="offcanvas" data-bs-target="#dean-mobile-more" aria-controls="dean-mobile-more" aria-expanded="false"<?= $deanMobileMoreActive?' class="is-current"':'' ?>><i class="bx bx-grid-alt" aria-hidden="true"></i><span>More</span></button>
</nav>
<div class="offcanvas offcanvas-bottom dean-mobile-more" tabindex="-1" id="dean-mobile-more" aria-labelledby="deanMobileMoreTitle">
<div class="offcanvas-header"><h2 class="offcanvas-title fs-5" id="deanMobileMoreTitle">More Dean pages</h2><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close more pages"></button></div>
<div class="offcanvas-body"><nav aria-label="More College Dean pages">
<?php foreach ($collegeDeanSidebarItems as $deanMobileItem): ?>
<?php if (!isset($deanMobilePrimary[$deanMobileItem['key']])): ?><a href="<?= htmlspecialchars($deanMobileItem['href'],ENT_QUOTES,'UTF-8') ?>"<?= $collegeDeanSidebarActiveKey===$deanMobileItem['key']?' aria-current="page"':'' ?>><i class="bx <?= htmlspecialchars($deanMobileItem['icon'],ENT_QUOTES,'UTF-8') ?>" aria-hidden="true"></i><span><?= htmlspecialchars($deanMobileItem['title'],ENT_QUOTES,'UTF-8') ?></span></a><?php endif; ?>
<?php foreach ($deanMobileItem['children'] ?? [] as $deanMobileChild): ?><a href="<?= htmlspecialchars($deanMobileChild['href'],ENT_QUOTES,'UTF-8') ?>"<?= $collegeDeanSidebarCurrentPage===$deanMobileChild['href']?' aria-current="page"':'' ?>><i class="bx <?= htmlspecialchars($deanMobileChild['icon'],ENT_QUOTES,'UTF-8') ?>" aria-hidden="true"></i><span><?= htmlspecialchars($deanMobileChild['title'],ENT_QUOTES,'UTF-8') ?></span></a><?php endforeach; ?>
<?php endforeach; ?>
</nav></div></div>
<script src="../college-dean/mobile-navigation.js?v=<?= (int)filemtime(__DIR__.'/mobile-navigation.js') ?>" defer></script>
