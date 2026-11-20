<?php
session_start();
require_once __DIR__ . '/auth.php';
synk_hris_headers();
[$conn, $account] = synk_hris_require_account();
if ($account['role_code'] === SYNK_HRIS_ADMIN) {
    header('Location: ./', true, 302);
    exit;
}
require __DIR__ . '/views/navigation.php';
$token = synk_hris_csrf();
session_write_close();
require_once __DIR__ . '/../backend/user_avatar_helper.php';
$view = 'workspace';
$pageTitle = 'HR Workspace';
require __DIR__ . '/views/layout-start.php';
?>
        <main id="main-content" class="hris-main" tabindex="-1">
            <section class="hris-hero hris-user-welcome" aria-labelledby="workspace-title">
                <div>
                    <span class="hris-user-eyebrow"><i class="bx bx-check-circle" aria-hidden="true"></i> Kawani access active</span>
                    <h1 id="workspace-title">Welcome to your HR workspace</h1>
                    <p>Your account is connected to your existing Synk employee record. HR administrator tools remain available only to assigned administrators.</p>
                </div>
                <div class="hris-hero-art" aria-hidden="true"><span class="hris-hero-document"><i class="bx bx-user-check"></i></span></div>
            </section>
            <section class="hris-card hris-user-access" aria-labelledby="access-title">
                <span class="hris-icon hris-tone-green"><i class="bx bx-shield-quarter" aria-hidden="true"></i></span>
                <div>
                    <h2 id="access-title">Your Kawani role</h2>
                    <p><strong>User</strong> access is active for <?= synk_hris_h($account['email']) ?>. Additional user features can be assigned as the HRIS workspace grows.</p>
                </div>
            </section>
<?php require __DIR__ . '/views/layout-end.php'; ?>
