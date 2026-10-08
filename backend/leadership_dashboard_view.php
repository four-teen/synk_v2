<?php
if (!isset($moduleRole, $moduleIcon, $moduleAccount, $moduleTerm)) {
    http_response_code(404);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr" data-theme="theme-default" data-assets-path="../assets/" data-template="vertical-menu-template-free">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= synk_leadership_h($moduleRole) ?> Dashboard | Synk</title>
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon/favicon.ico" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
    <style>
      .module-hero { background: linear-gradient(135deg, #f6f7ff, #eef6ff); }
      .module-hero, .module-summary { border: 1px solid #e4e8f0; border-radius: 1rem; }
      .module-icon { font-size: 2rem; }
      .module-account-email { overflow-wrap: anywhere; }
      .module-navbar { gap: 1rem; flex-wrap: wrap; padding: 1rem; }
      .module-details dt { color: #8592a3; font-weight: 500; }
      .module-details dd { overflow-wrap: anywhere; }
      body[data-module="DI"] .module-hero { background: linear-gradient(135deg, #eef9fb, #f3f7ff); }
    </style>
  </head>
  <body data-module="<?= synk_leadership_h($moduleRole) ?>">
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">
          <div class="app-brand demo">
            <a href="index.php" class="app-brand-link">
              <span class="app-brand-logo demo"><i class="bx <?= synk_leadership_h($moduleIcon) ?> text-primary module-icon"></i></span>
              <span class="app-brand-text demo menu-text fw-bolder ms-2">Synk</span>
            </a>
            <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto d-block d-xl-none" aria-label="Close menu"><i class="bx bx-chevron-left bx-sm"></i></a>
          </div>
          <div class="menu-inner-shadow"></div>
          <ul class="menu-inner py-1">
            <li class="menu-header small text-uppercase"><span class="menu-header-text"><?= synk_leadership_h($moduleRole) ?> Portal</span></li>
            <li class="menu-item active"><a href="index.php" class="menu-link"><i class="menu-icon tf-icons bx bx-home-circle"></i><div>Dashboard</div></a></li>
          </ul>
        </aside>
        <div class="layout-page">
          <nav class="layout-navbar container-xxl navbar navbar-detached bg-navbar-theme module-navbar" id="layout-navbar" aria-label="Account navigation">
            <div class="layout-menu-toggle navbar-nav d-xl-none"><a class="nav-link" href="javascript:void(0);" aria-label="Open menu"><i class="bx bx-menu bx-sm"></i></a></div>
            <span class="badge bg-label-primary"><?= synk_leadership_h($moduleRole) ?> Portal</span>
            <div class="ms-auto text-end">
              <span class="fw-semibold d-block"><?= synk_leadership_h($moduleAccount['username']) ?></span>
              <small class="text-muted module-account-email"><?= synk_leadership_h($moduleAccount['email']) ?></small>
            </div>
            <a href="../logout.php" class="btn btn-outline-secondary btn-sm"><i class="bx bx-log-out me-1"></i>Log Out</a>
          </nav>
          <div class="content-wrapper">
            <main class="container-xxl flex-grow-1 container-p-y">
              <div class="card module-hero mb-4">
                <div class="card-body p-4">
                  <span class="badge bg-label-primary mb-3"><?= synk_leadership_h($moduleRole) ?> Workspace</span>
                  <h1 class="h3 mb-2"><?= synk_leadership_h($moduleRole) ?> Dashboard</h1>
                  <p class="mb-0">Welcome, <?= synk_leadership_h($moduleAccount['username']) ?>. View your current academic term and account access below.</p>
                </div>
              </div>
              <div class="row g-4">
                <section class="col-lg-6" aria-labelledby="academic-term-heading">
                  <div class="card module-summary h-100"><div class="card-body">
                    <div class="avatar mb-3"><span class="avatar-initial rounded bg-label-info"><i class="bx bx-calendar"></i></span></div>
                    <h2 id="academic-term-heading" class="h5">Current Academic Term</h2>
                    <p class="fw-semibold mb-0"><?= synk_leadership_h($moduleTerm['term_text']) ?></p>
                  </div></div>
                </section>
                <section class="col-lg-6" aria-labelledby="account-access-heading">
                  <div class="card module-summary h-100"><div class="card-body">
                    <div class="avatar mb-3"><span class="avatar-initial rounded bg-label-primary"><i class="bx <?= synk_leadership_h($moduleIcon) ?>"></i></span></div>
                    <h2 id="account-access-heading" class="h5">Account Access</h2>
                    <dl class="module-details mb-3">
                      <dt>Active module</dt><dd><?= synk_leadership_h($moduleRole) ?></dd>
                      <dt>Default role</dt><dd><?= synk_leadership_h(synk_role_label($moduleAccount['primary_role'])) ?></dd>
                      <dt>SKSU email</dt><dd><?= synk_leadership_h($moduleAccount['email']) ?></dd>
                    </dl>
                    <p class="small text-muted mb-2">Assigned roles</p>
                    <div class="d-flex flex-wrap gap-2">
                      <?php foreach ($moduleAccount['role_rows'] as $roleRow): ?>
                        <span class="badge <?= $roleRow['role'] === $moduleRole ? 'bg-label-primary' : 'bg-label-secondary' ?>"><?= synk_leadership_h(synk_role_label($roleRow['role'])) ?></span>
                      <?php endforeach; ?>
                    </div>
                  </div></div>
                </section>
              </div>
            </main>
            <?php include __DIR__ . '/../footer.php'; ?>
            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>
      <div class="layout-overlay layout-menu-toggle"></div>
    </div>
    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
  </body>
</html>
