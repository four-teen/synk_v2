<?php
session_start();
require_once __DIR__ . '/auth.php';
$styleNonce = base64_encode(random_bytes(18));
synk_hris_headers($styleNonce);

$settings = synk_auth_settings();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    synk_hris_start_google($settings);
}

$ready = synk_google_login_enabled($settings) && synk_google_auth_ready($settings);
$status = is_string($_GET['auth_status'] ?? null) ? $_GET['auth_status'] : '';
$messages = [
    'hris_access_denied' => 'This account does not have access to Kawani. Use your assigned university Google account.',
    'google_identity_mismatch' => 'This Google account does not match the identity registered in Synk.',
    'sign_in_required' => '',
    'google_state_invalid' => 'This sign-in request has expired. Start again here.',
    'google_cancelled' => 'Sign-in was cancelled. You can try again below.',
    'login_request_invalid' => 'Refresh the page and try signing in again.',
    'hris_unavailable' => 'The Kawani workspace is unavailable. Contact your Kawani administrator.',
    'google_unavailable' => 'Google sign-in is currently unavailable.',
];
$message = $status === '' ? '' : ($messages[$status] ?? 'Google sign-in could not be completed. Please try again.');
$signedIn = is_array($_SESSION['hris_identity'] ?? null);
$token = synk_hris_csrf();
session_write_close();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kawani Sign In</title>
    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon-login.png">
    <link rel="preload" href="../assets/fonts/paola/paola.ttf" as="font" type="font/ttf" crossorigin>
    <link rel="preload" href="../assets/fonts/allura/Allura-Regular.woff2" as="font" type="font/woff2" crossorigin>
    <style nonce="<?= synk_hris_h($styleNonce) ?>">
        <?php
        // Inline the styles for first paint and resolve the brand fonts from this page.
        $brandStyles = file_get_contents(__DIR__ . '/../assets/css/brand.css');
        echo strtr($brandStyles, [
            '../fonts/' => '../assets/fonts/',
            // Keep the wordmarks from visibly moving when their fonts arrive.
            'font-display: swap;' => 'font-display: block;',
        ]);
        readfile(__DIR__ . '/assets/hris.css');
        ?>
    </style>
</head>
<body>
    <main class="hris-entry">
        <div class="hris-login-background" aria-hidden="true"></div>
        <div class="hris-login-card">
            <div class="hris-login-emblem">
                <img src="assets/sksu-seal.webp" alt="" width="96" height="96">
            </div>
            <header class="hris-login-identity">
                <h1 class="hris-wordmark">kawan<span>i</span></h1>
                <p class="hris-login-system">Human Resource Information System</p>
                <p class="hris-login-university">Sultan Kudarat State University</p>
                <p class="hris-login-access">Only official SKSU Google accounts can access this system.</p>
            </header>
            <section class="hris-login-panel" aria-labelledby="hris-login-title">
                <h2 id="hris-login-title"><?= $signedIn ? 'Your workspace' : 'Authorized Google Sign-In' ?></h2>
                <?php if ($message !== ''): ?>
                    <p class="hris-notice" role="status"><?= synk_hris_h($message) ?></p>
                <?php elseif (!$ready): ?>
                    <p class="hris-notice" role="status"><?= synk_hris_h($messages['google_unavailable']) ?></p>
                <?php endif; ?>
                <?php if ($signedIn): ?>
                    <a class="hris-button" href="./">Open Kawani workspace</a>
                <?php else: ?>
                    <form method="post" action="login.php">
                        <input type="hidden" name="csrf_token" value="<?= synk_hris_h($token) ?>">
                        <button class="hris-button hris-google" type="submit"<?= !$ready ? ' disabled' : '' ?>>
                            <img class="hris-google-mark" src="../assets/img/icons/brands/google.png" alt="" width="18" height="18">
                            <span>Continue with SKSU Google</span>
                        </button>
                    </form>
                <?php endif; ?>
                <p class="hris-login-tagline">One Institution. One Connected Ecosystem.</p>
                <p class="hris-login-credit"><span class="synk-wordmark">syn<span class="synk-wordmark-q">Q</span></span> 2026</p>
            </section>
        </div>
    </main>
</body>
</html>
