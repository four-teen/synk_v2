<?php if (!isset($sefActor)) { http_response_code(404); exit; } ?>
</main><?php include __DIR__.'/../footer.php'; ?><div class="content-backdrop fade"></div></div></div></div><div class="layout-overlay layout-menu-toggle"></div></div>
<script src="../assets/vendor/libs/jquery/jquery.js"></script><script src="../assets/vendor/libs/popper/popper.js"></script><script src="../assets/vendor/js/bootstrap.js"></script><script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script><script src="../assets/vendor/js/menu.js"></script><script src="../assets/js/main.js"></script><script src="../college-dean/supervisor-evaluations.js?v=<?= (int)filemtime(__DIR__.'/supervisor-evaluations.js') ?>"></script>
<?php if ($sefPage === 'report'): ?><script src="../college-dean/faculty-performance-report.js?v=<?= (int)filemtime(__DIR__.'/faculty-performance-report.js') ?>"></script><?php endif; ?>
<?php if ($sefPage === 'report' && $sefReportKey !== 'individual'): ?><script src="../college-dean/evaluation-report-forms.js?v=<?= (int)filemtime(__DIR__.'/evaluation-report-forms.js') ?>"></script><?php endif; ?>
</body></html>
