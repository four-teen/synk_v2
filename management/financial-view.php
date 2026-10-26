<?php
if(!isset($scope,$currentTerm)){http_response_code(404);exit;}
?>
<section class="card management-panel" aria-labelledby="financial-start-title" id="financial-fresh-start">
  <div class="management-panel-heading"><div><p class="card-section-title mb-1">NEW RECORDING</p><h2 id="financial-start-title">Financial records</h2><p>Starting period: <?= synk_management_h($currentTerm['period_text']) ?></p></div></div>
  <div class="management-empty"><i class="bx bx-receipt" aria-hidden="true"></i><h3>No financial records yet</h3><p>This workspace is ready for a fresh recording process.</p></div>
</section>
