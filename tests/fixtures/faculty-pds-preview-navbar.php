<?php if (PHP_SAPI !== 'cli') { http_response_code(404); exit; } ?>
<nav class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme professor-navbar">
  <div class="layout-menu-toggle navbar-nav d-xl-none me-3"><a class="nav-item nav-link px-0" href="javascript:void(0)"><i class="bx bx-menu bx-sm"></i></a></div>
  <div class="navbar-nav-right d-flex align-items-center justify-content-between gap-3 w-100">
    <div class="d-flex professor-term-labels" aria-label="Current academic term"><span class="professor-term-semester">1st Semester</span><span class="professor-term-year">2026-2027</span></div>
    <div class="text-end d-none d-md-block"><span class="fw-semibold d-block">Sample Faculty</span><small class="text-muted">faculty@example.edu</small></div>
  </div>
</nav>
