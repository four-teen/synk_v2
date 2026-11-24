<?php
if (!isset($account, $token, $pageTitle, $view, $views)) { http_response_code(404); return; }
$accountEmail = (string)$account['email'];
$accountName = trim((string)$account['username']) ?: $accountEmail;
$headerRoleLabels = synk_hris_role_labels();
$activeRole = synk_hris_active_role($account);
$roleLabel = $headerRoleLabels[$activeRole];
$alternateRole = array_values(array_diff(synk_hris_assigned_roles($account), [$activeRole]))[0] ?? null;
$avatarFallback = synk_default_user_avatar_path();
$avatarUrl = synk_faculty_profile_image_url((int)$account['user_id']) ?: $avatarFallback;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= synk_hris_h($pageTitle) ?> | Kawani</title>
    <link rel="icon" href="../assets/img/favicon/synk-icon.png">
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
    <link rel="stylesheet" href="../assets/css/brand.css">
    <link rel="stylesheet" href="../assets/css/college-portal-sidebar.css">
    <?php if ($view === 'dashboard' && isset($rankCounts)): ?>
        <link rel="stylesheet" id="apexcharts-css" href="assets/vendor/apexcharts/dist/apexcharts.css">
        <script src="assets/vendor/apexcharts/dist/apexcharts.min.js" defer></script>
        <script src="assets/faculty-charts.js?v=<?= (int)filemtime(dirname(__DIR__) . '/assets/faculty-charts.js') ?>" nonce="<?= synk_hris_h($chartStyleNonce) ?>" defer></script>
    <?php endif; ?>
    <?php if ($view === 'accounts' || ($view === 'faculty' && isset($positionCatalog))): ?>
        <link rel="stylesheet" href="assets/vendor/select2/select2.min.css">
        <script src="assets/vendor/jquery/jquery-3.7.1.min.js" defer></script>
        <script src="assets/vendor/select2/select2.min.js" defer></script>
    <?php endif; ?>
    <?php if ($view === 'faculty' && (isset($visibleRecords) || isset($previewUrl))): ?>
        <link rel="stylesheet" href="../assets/css/faculty-pds-work.css?v=<?= filemtime(__DIR__ . '/../../assets/css/faculty-pds-work.css') ?>">
    <?php endif; ?>
    <link rel="stylesheet" href="assets/dashboard.css?v=<?= (int)filemtime(dirname(__DIR__) . '/assets/dashboard.css') ?>">
    <script src="assets/dashboard.js?v=<?= (int)filemtime(dirname(__DIR__) . '/assets/dashboard.js') ?>" defer></script>
    <?php if ($canManagePositions): ?>
        <script src="assets/position-catalog.js?v=<?= (int)filemtime(dirname(__DIR__) . '/assets/position-catalog.js') ?>" defer></script>
    <?php endif; ?>
    <?php if ($view === 'faculty' && isset($visibleRecords)): ?>
        <script src="assets/faculty-search.js?v=<?= (int)filemtime(dirname(__DIR__) . '/assets/faculty-search.js') ?>" defer></script>
        <?php if ($canDeleteEmployees): ?>
            <script src="assets/delete-employee.js?v=<?= (int)filemtime(dirname(__DIR__) . '/assets/delete-employee.js') ?>" defer></script>
        <?php endif; ?>
        <?php if (isset($positionCatalog)): ?>
            <script src="assets/new-employee.js?v=<?= (int)filemtime(dirname(__DIR__) . '/assets/new-employee.js') ?>" defer></script>
        <?php endif; ?>
        <?php if ($canManageEmployees && isset($facultyStatuses)): ?>
            <script src="assets/faculty-status.js?v=<?= (int)filemtime(dirname(__DIR__) . '/assets/faculty-status.js') ?>" defer></script>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($canManageEmployees && $view === 'faculty' && (isset($visibleRecords) || isset($previewUrl))): ?>
        <script src="assets/work-experience.js?v=<?= (int)filemtime(dirname(__DIR__) . '/assets/work-experience.js') ?>" defer></script>
    <?php endif; ?>
    <?php if ($view === 'faculty' && isset($previewUrl)): ?>
        <script src="assets/faculty-preview.js?v=<?= (int)filemtime(dirname(__DIR__) . '/assets/faculty-preview.js') ?>" defer></script>
    <?php endif; ?>
    <?php if ($view === 'accounts'): ?>
        <script src="assets/account-management.js?v=<?= (int)filemtime(dirname(__DIR__) . '/assets/account-management.js') ?>" defer></script>
    <?php endif; ?>
</head>
<body>
    <a class="hris-skip" href="#main-content">Skip to content</a>
    <div class="hris-shell">
        <aside id="layout-menu" class="hris-sidebar">
            <a class="hris-brand hris-sidebar-brand" href="./" aria-label="Kawani workspace">
                <img src="assets/sksu-seal.webp" alt="" width="36" height="36">
                <span class="synk-wordmark">kawan<span class="hris-brand-accent">i</span></span>
            </a>
            <p class="hris-sidebar-label"><?= synk_hris_h($roleLabel) ?></p>
            <nav aria-label="Human Resources">
                <?php foreach ($views as $key => $item): ?>
                    <a class="college-dean-menu-card<?= $key === $view ? ' active' : '' ?>" href="<?= synk_hris_h($item['url']) ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>>
                        <span class="college-dean-menu-icon hris-tone-<?= $item['tone'] ?>"><i class="bx <?= $item['icon'] ?>" aria-hidden="true"></i></span>
                        <span class="college-dean-menu-content">
                            <span class="college-dean-menu-title"><?= synk_hris_h($item['title']) ?></span>
                            <small class="college-dean-menu-desc"><?= synk_hris_h($item['description']) ?></small>
                        </span>
                    </a>
                <?php endforeach; ?>
                <?php $mobileManagement = false; require __DIR__ . '/management-navigation.php'; ?>
            </nav>
        </aside>
        <div class="hris-workspace">
            <header class="hris-navbar">
                <div class="hris-navbar-context">
                    <a class="hris-brand hris-mobile-brand" href="./" aria-label="Kawani workspace">
                        <img src="assets/sksu-seal.webp" alt="" width="36" height="36">
                        <span class="synk-wordmark">kawan<span class="hris-brand-accent">i</span></span>
                    </a>
                    <span class="hris-navbar-title">Human Resources</span>
                </div>
                <div class="hris-navbar-account">
                    <div class="hris-account-identity">
                        <strong><?= synk_hris_h($accountName) ?></strong>
                        <?php if ($accountName !== $accountEmail): ?><small><?= synk_hris_h($accountEmail) ?></small><?php endif; ?>
                    </div>
                    <details class="hris-account-menu">
                        <summary class="hris-account-toggle" aria-label="Account menu">
                            <span class="hris-avatar">
                                <img src="<?= synk_hris_h($avatarUrl) ?>" data-avatar-fallback="<?= synk_hris_h($avatarFallback) ?>" alt="" width="40" height="40">
                            </span>
                        </summary>
                        <div class="hris-account-dropdown">
                            <div class="hris-account-profile">
                                <span class="hris-avatar">
                                    <img src="<?= synk_hris_h($avatarUrl) ?>" data-avatar-fallback="<?= synk_hris_h($avatarFallback) ?>" alt="" width="40" height="40">
                                </span>
                                <div>
                                    <strong><?= synk_hris_h($accountName) ?></strong>
                                    <span><?= synk_hris_h($roleLabel) ?></span>
                                    <?php if ($accountName !== $accountEmail): ?><small><?= synk_hris_h($accountEmail) ?></small><?php endif; ?>
                                </div>
                            </div>
                            <?php if ($alternateRole !== null): ?>
                                <form method="post" action="switch-account.php">
                                    <input type="hidden" name="csrf_token" value="<?= synk_hris_h($token) ?>">
                                    <input type="hidden" name="role_code" value="<?= synk_hris_h($alternateRole) ?>">
                                    <button class="hris-account-action" type="submit"><i class="bx bx-transfer-alt" aria-hidden="true"></i> Switch to <?= synk_hris_h($headerRoleLabels[$alternateRole]) ?></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="logout.php">
                                <input type="hidden" name="csrf_token" value="<?= synk_hris_h($token) ?>">
                                <button class="hris-account-action" type="submit"><i class="bx bx-power-off" aria-hidden="true"></i> Sign out</button>
                            </form>
                        </div>
                    </details>
                </div>
            </header>
