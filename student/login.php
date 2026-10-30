<?php
session_start();
require_once __DIR__ . '/../backend/auth_config.php';
require_once __DIR__ . '/../backend/student_login_helper.php';
$settings = synk_auth_settings();
$ready = synk_google_login_enabled($settings) && synk_google_auth_ready($settings);
if (($_SESSION['role'] ?? '') === 'student') { header('Location: index.php'); exit; }
$status = is_string($_GET['auth_status'] ?? null) ? $_GET['auth_status'] : '';
$email = (string)($_SESSION['student_login_last_email'] ?? '');
$issueSaved = !empty($_SESSION['student_login_issue_saved']);
unset($_SESSION['student_login_issue_saved']);
$messages = [
    'student_email_invalid' => 'Enter a valid school email address.',
    'email_domain_denied' => 'Use your SKSU Google email to sign in.',
    'student_email_mismatch' => 'Choose the same Google account as the email you entered, then try again.',
    'student_directory_access_denied' => 'We could not match your school email to a student record. Ask your college office to check your email in the Student Directory.',
    'student_email_ambiguous' => 'Your email matches more than one student record. Ask your college office to correct the Student Directory.',
    'student_session_changed' => 'Your student record changed. Please sign in again with your current school email.',
    'student_idle_timeout' => 'You were signed out after 15 minutes without activity. Sign in again to continue.',
    'student_signed_out' => 'You have signed out of the Student Portal.',
    'google_cancelled' => 'Sign-in was cancelled. You can try again when you are ready.',
    'google_state_invalid' => 'Your sign-in link expired. Please start again from this page.',
    'student_login_request_invalid' => 'Refresh this page and start your sign-in again.',
    'student_login_rate_limited' => 'There have been several sign-in attempts. Please wait 15 minutes before trying again.',
    'student_portal_unavailable' => 'The Student Portal is temporarily unavailable. Please try again later.',
    'google_unavailable' => 'Google sign-in is currently unavailable. Please contact your college office.',
];
$message = $status !== '' ? ($messages[$status] ?? 'Google sign-in could not be completed. Please try again.') : '';
$h = static function ($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="en" class="light-style customizer-hide" dir="ltr" data-theme="theme-default" data-assets-path="../assets/">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Student Sign In | synQ</title>
  <link rel="icon" href="../assets/img/favicon/synk-icon.png" type="image/png">
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css">
  <link rel="stylesheet" href="../assets/vendor/css/core.css">
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css">
  <link rel="stylesheet" href="../assets/css/brand.css?v=<?= (int)filemtime(__DIR__.'/../assets/css/brand.css') ?>">
  <link rel="preload" href="../assets/vendor/fonts/space-grotesk-700.ttf" as="font" type="font/ttf" crossorigin>
  <link rel="stylesheet" href="../assets/css/login-intro.css?v=<?= (int)filemtime(__DIR__.'/../assets/css/login-intro.css') ?>">
  <link rel="stylesheet" href="login.css?v=<?= (int)filemtime(__DIR__.'/login.css') ?>">
</head>
<body class="synk-login-assemble">
  <div class="synk-login-splash" aria-hidden="true">
    <div class="synk-login-splash-brand">
      <div class="synk-login-logo">
        <img class="synk-login-logo-complete" src="../assets/img/favicon/synk-icon.png" alt="" width="144" height="144" fetchpriority="high">
        <i class="synk-login-logo-piece synk-login-logo-piece-cap"></i>
        <i class="synk-login-logo-piece synk-login-logo-piece-tassel"></i>
        <i class="synk-login-logo-piece synk-login-logo-piece-upper"></i>
        <i class="synk-login-logo-piece synk-login-logo-piece-ribbon"></i>
        <i class="synk-login-logo-piece synk-login-logo-piece-left"></i>
        <i class="synk-login-logo-piece synk-login-logo-piece-lower"></i>
      </div>
      <span class="synk-wordmark">syn<span class="synk-wordmark-q">Q</span></span>
    </div>
  </div>
  <main class="student-login-shell">
    <div class="student-login-wrap" data-synk-login-content>
      <a class="student-login-brand" href="login.php" aria-label="synQ Student Portal"><img src="../assets/img/favicon/synk-icon.png" alt="" width="44" height="44"><span class="synk-wordmark">syn<span class="synk-wordmark-q">Q</span></span><span class="badge bg-label-primary">Student Portal</span></a>
      <section class="card student-login-card" aria-labelledby="login-title">
        <div class="card-body">
          <h1 id="login-title"><span class="student-login-title-lead">Your subjects.</span> <span class="student-login-title-accent">One place.</span></h1>
          <p class="student-login-intro">Sign in to see your subjects, instructors, and imported class details.</p>
          <?php if ($message !== ''): ?><div class="alert alert-warning student-login-message" role="alert"><strong>Let’s check your sign-in</strong><p class="mb-0 mt-1"><?= $h($message) ?></p><?php if ($issueSaved): ?><p class="small mb-0 mt-2">Your sign-in email was saved for Administrator review.</p><?php endif; ?></div><?php endif; ?>
          <?php if (!$ready): ?><div class="alert alert-info" role="status">Google sign-in is currently unavailable. Please contact your college office.</div><?php endif; ?>
          <form action="../backend/auth_google_student_start.php" method="post" id="studentLoginForm">
            <input type="hidden" name="csrf_token" value="<?= $h(synk_student_login_csrf()) ?>">
            <label class="form-label" for="student-email">SKSU email</label>
            <input class="form-control form-control-lg" id="student-email" name="email" type="email" autocomplete="email" inputmode="email" maxlength="254" placeholder="your.name@<?= $h($settings['allowed_domain']) ?>" value="<?= $h($email) ?>" aria-describedby="student-email-help" required>
            <p class="form-text mb-4" id="student-email-help">Use the email listed in your student record, then choose that same Google account.</p>
            <button class="btn btn-primary student-google-button w-100" id="studentGoogleLogin" type="submit"<?= !$ready ? ' disabled' : '' ?>><svg viewBox="0 0 24 24" aria-hidden="true" width="20" height="20"><path fill="#fff" d="M21.6 12.2c0-.7-.1-1.4-.2-2.1H12v4h5.4a4.6 4.6 0 0 1-2 3v2.5h3.2c1.9-1.8 3-4.3 3-7.4ZM12 22c2.7 0 5-.9 6.6-2.4l-3.2-2.5c-.9.6-2 1-3.4 1-2.6 0-4.8-1.8-5.6-4.2H3.1v2.6A10 10 0 0 0 12 22ZM6.4 13.9a6 6 0 0 1 0-3.8V7.5H3.1a10 10 0 0 0 0 9l3.3-2.6ZM12 5.9c1.5 0 2.8.5 3.8 1.5l2.8-2.8A9.7 9.7 0 0 0 12 2a10 10 0 0 0-8.9 5.5l3.3 2.6A6 6 0 0 1 12 5.9Z"/></svg><span>Continue with Google</span><i class="bx bx-right-arrow-alt" aria-hidden="true"></i></button>
          </form>
          <div class="student-login-support"><i class="bx bx-help-circle" aria-hidden="true"></i><div><strong>Having trouble signing in?</strong><p>Ask your college office to check your school email in the Student Directory.</p></div></div>
        </div>
      </section>
      <p class="student-login-footer"><span class="synk-wordmark">syn<span class="synk-wordmark-q">Q</span></span> - One Institution. One Connected Ecosystem.</p>
    </div>
  </main>
  <script src="login.js?v=<?= (int)filemtime(__DIR__.'/login.js') ?>" defer></script>
</body>
</html>
