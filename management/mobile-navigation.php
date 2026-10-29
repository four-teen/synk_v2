<?php
if (!isset($scope,$navigation,$page,$pageUrl)) { http_response_code(404); exit; }
$mobilePages = ['dashboard'=>'Home','students'=>'Students','events'=>'Events','financial'=>'Financial'];
$moreActive = !isset($mobilePages[$page]);
?>
<nav class="management-mobile-nav" id="management-mobile-nav" aria-label="Management mobile navigation">
  <?php foreach ($mobilePages as $key=>$label): if(!synk_management_operation_allowed($permissions,$key==='students'?'student_records':($key==='financial'?'records':$key)) && $key!=='dashboard')continue;$item=$navigation[$key]; ?>
    <a href="<?= synk_management_h($pageUrl($item[1])) ?>"<?= $key===$page ? ' aria-current="page"' : '' ?>><i class="bx <?= $item[2] ?>" aria-hidden="true"></i><span><?= $label ?></span></a>
  <?php endforeach; ?>
  <button type="button" id="management-more-toggle" data-bs-toggle="offcanvas" data-bs-target="#management-mobile-more" aria-controls="management-mobile-more" aria-expanded="false"<?= $moreActive ? ' class="is-current"' : '' ?>><i class="bx bx-grid-alt" aria-hidden="true"></i><span>More</span></button>
</nav>
<div class="offcanvas offcanvas-bottom management-mobile-more" tabindex="-1" id="management-mobile-more" aria-labelledby="management-mobile-more-title">
  <div class="offcanvas-header"><div><h2 class="offcanvas-title fs-5" id="management-mobile-more-title">More options</h2><p><?= synk_management_h($scope['organization_name']) ?></p></div><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close more options"></button></div>
  <div class="offcanvas-body"><nav aria-label="More Management pages">
    <?php foreach ($navigation as $key=>$item): if($key!=='dashboard' && !synk_management_operation_allowed($permissions,$key==='students'?'student_records':($key==='financial'?'records':$key)))continue; ?>
      <a href="<?= synk_management_h($pageUrl($item[1])) ?>"<?= $key===$page ? ' aria-current="page"' : '' ?>><i class="bx <?= $item[2] ?>" aria-hidden="true"></i><span><?= synk_management_h($key==='dashboard'?'Home':$item[0]) ?></span></a>
    <?php endforeach; ?>
  </nav><p class="management-mobile-college"><?= synk_management_h($scope['college_name']) ?></p></div>
</div>
