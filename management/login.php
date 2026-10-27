<?php
session_start();
require_once __DIR__ . '/../backend/auth_config.php';
$settings = synk_auth_settings();
$ready = synk_google_login_enabled($settings) && synk_google_auth_ready($settings);
if (($_SESSION['role'] ?? '') === 'management' && ($_SESSION['auth_method'] ?? '') === 'google') { header('Location: index.php'); exit; }
header('Cache-Control: no-store, private');
if (empty($_SESSION['management_login_csrf'])) $_SESSION['management_login_csrf'] = bin2hex(random_bytes(32));
$status = is_string($_GET['auth_status'] ?? null) ? $_GET['auth_status'] : '';
$messages = [
    'management_access_denied'=>'Your Google account needs active Management access for an organization. Student officers should ask their faculty manager to check permissions, expiry, and current-semester membership. Faculty managers can contact the synQ Administrator.',
    'google_identity_mismatch'=>'This Google identity does not match the account on record. Ask your synQ Administrator to review your account.',
    'google_cancelled'=>'Sign-in was cancelled. You can try again when you are ready.',
    'google_state_invalid'=>'Your sign-in link expired. Start again from this page.',
    'login_request_invalid'=>'Refresh this page and start your sign-in again.',
    'management_unavailable'=>'Management sign-in is temporarily unavailable. Please try again later.',
    'google_unavailable'=>'Google sign-in is currently unavailable. Please contact your Administrator.',
];
$message = $status === '' ? '' : ($messages[$status] ?? 'Google sign-in could not be completed. Please try again.');
$h = static function ($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="en" class="light-style customizer-hide" dir="ltr" data-theme="theme-default" data-assets-path="../assets/">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Management Sign In | synQ</title>
  <link rel="icon" href="../assets/img/favicon/synk-icon.png" type="image/png">
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
  <link rel="stylesheet" href="../assets/vendor/css/core.css">
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css">
  <link rel="preload" href="../assets/vendor/fonts/space-grotesk-700.ttf" as="font" type="font/ttf" crossorigin>
  <link rel="stylesheet" href="../assets/css/login-intro.css?v=<?= (int)filemtime(__DIR__.'/../assets/css/login-intro.css') ?>">
  <link rel="stylesheet" href="../student/login.css?v=<?= (int)filemtime(__DIR__.'/../student/login.css') ?>">
</head>
<body class="synk-login-assemble">
  <div class="synk-login-splash" aria-hidden="true"><div class="synk-login-splash-brand"><div class="synk-login-logo">
    <img class="synk-login-logo-complete" src="../assets/img/favicon/synk-icon.png" alt="" width="144" height="144" fetchpriority="high">
    <i class="synk-login-logo-piece synk-login-logo-piece-cap"></i><i class="synk-login-logo-piece synk-login-logo-piece-tassel"></i><i class="synk-login-logo-piece synk-login-logo-piece-upper"></i><i class="synk-login-logo-piece synk-login-logo-piece-ribbon"></i><i class="synk-login-logo-piece synk-login-logo-piece-left"></i><i class="synk-login-logo-piece synk-login-logo-piece-lower"></i>
  </div><span>synQ</span></div></div>
  <main class="student-login-shell"><div class="student-login-wrap" data-synk-login-content>
    <a class="student-login-brand" href="login.php" aria-label="synQ Management Portal"><img src="../assets/img/favicon/synk-icon.png" alt="" width="44" height="44"><span>synQ</span><span class="badge bg-label-primary">Management</span></a>
    <section class="card student-login-card" aria-labelledby="login-title"><div class="card-body">
      <h1 id="login-title"><span class="student-login-title-lead"><?= $h($settings['management_login_title']) ?></span><span class="student-login-title-accent">One Place.</span></h1>
      <p class="student-login-intro">Your college community, contributions, and events in one workspace.</p>
      <?php if ($message !== ''): ?><div class="alert alert-warning student-login-message" role="alert"><?= $h($message) ?></div><?php endif; ?>
      <?php if (!$ready): ?><div class="alert alert-info" role="status">Google sign-in is currently unavailable. Please contact your Administrator.</div><?php endif; ?>
      <form action="../backend/auth_google_management_start.php" method="post" id="managementLoginForm">
        <input type="hidden" name="csrf_token" value="<?= $h($_SESSION['management_login_csrf']) ?>">
        <button class="btn btn-primary student-google-button w-100" id="managementGoogleLogin" type="submit"<?= !$ready ? ' disabled' : '' ?>>
          <svg viewBox="0 0 24 24" aria-hidden="true" width="20" height="20"><path fill="#fff" d="M21.6 12.2c0-.7-.1-1.4-.2-2.1H12v4h5.4a4.6 4.6 0 0 1-2 3v2.5h3.2c1.9-1.8 3-4.3 3-7.4ZM12 22c2.7 0 5-.9 6.6-2.4l-3.2-2.5c-.9.6-2 1-3.4 1-2.6 0-4.8-1.8-5.6-4.2H3.1v2.6A10 10 0 0 0 12 22ZM6.4 13.9a6 6 0 0 1 0-3.8V7.5H3.1a10 10 0 0 0 0 9l3.3-2.6ZM12 5.9c1.5 0 2.8.5 3.8 1.5l2.8-2.8A9.7 9.7 0 0 0 12 2a10 10 0 0 0-8.9 5.5l3.3 2.6A6 6 0 0 1 12 5.9Z"/></svg>
          <span>Continue with Google</span><i class="bx bx-right-arrow-alt" aria-hidden="true"></i>
        </button>
      </form>
      <div class="student-login-support"><i class="bx bx-check-shield" aria-hidden="true"></i><div><strong>Access assigned by your Administrator</strong><p>Choose the Google account registered for Management. Your assigned college opens automatically.</p></div></div>
    </div></section>
    <nav class="student-login-footer" aria-label="Other sign-in portals"><a href="../index.php">Staff sign-in</a> <span aria-hidden="true">&middot;</span> <a href="../student/login.php">Student sign-in</a></nav>
    <p class="student-login-footer">synQ - One Institution. One Connected Ecosystem.</p>
  </div></main>
  <script src="login.js?v=<?= (int)filemtime(__DIR__.'/login.js') ?>" defer></script>
</body></html>
