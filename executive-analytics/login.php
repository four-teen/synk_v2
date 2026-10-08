<?php
session_start();
ob_start();

require_once '../backend/db.php';
require_once '../backend/executive_analytics_helper.php';

$responseFormat = strtolower(trim((string)($_POST['response_format'] ?? $_GET['response_format'] ?? '')));
$wantsJson = $responseFormat === 'json'
    || strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))) === 'xmlhttprequest';
$loginError = '';
$loginMessage = '';
$viewer = synk_exec_analytics_active_user();
$accessRows = synk_exec_analytics_access_rows($conn);

if ($viewer) {
    header('Location: index.php');
    exit;
}

if (isset($_GET['logged_out'])) {
    $loginMessage = 'Executive analytics session closed.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accessCode = trim((string)($_POST['access_code'] ?? ''));
    $result = synk_exec_analytics_attempt_login($conn, $accessCode);

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        exit;
    }

    if (($result['status'] ?? '') === 'success') {
        header('Location: index.php');
        exit;
    }

    $loginError = (string)($result['message'] ?? 'Access denied.');
}
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style customizer-hide"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../assets/"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <title>Executive Analytics Login</title>
    <meta name="description" content="Executive analytics access for the Vice President for Academics and the President." />

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;700&display=swap" rel="stylesheet" />

    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" class="template-customizer-core-css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="custom_css.css" />
  </head>

  <body class="exec-login-page">
    <div class="exec-login-shell">
      <div class="exec-login-orb exec-login-orb-one"></div>
      <div class="exec-login-orb exec-login-orb-two"></div>
      <div class="exec-login-grid"></div>

      <div class="exec-login-stage">
        <div class="exec-login-panel">
          <div class="exec-login-kicker">
            <span class="exec-login-kicker-chip">Separate Module</span>
            <span class="exec-login-kicker-chip accent">Code-Only Access</span>
          </div>

          <h1 class="exec-login-title">Executive Analytics Gateway</h1>
          <p class="exec-login-copy">
            Built for the Vice President for Academics and the President with a dedicated access-code entry point,
            a university-wide first view, and campus command pages behind one focused gateway.
          </p>

          <?php if ($loginMessage !== ''): ?>
            <div class="alert alert-success exec-login-alert" role="alert">
              <i class="bx bx-check-circle"></i>
              <span><?php echo synk_exec_analytics_h($loginMessage); ?></span>
            </div>
          <?php endif; ?>

          <?php if ($loginError !== ''): ?>
            <div class="alert alert-danger exec-login-alert" role="alert">
              <i class="bx bx-error-circle"></i>
              <span><?php echo synk_exec_analytics_h($loginError); ?></span>
            </div>
          <?php endif; ?>

          <form method="post" class="exec-login-form">
            <label class="form-label exec-login-label" for="access_code">Executive Access Code</label>
            <div class="exec-login-input-wrap">
              <i class="bx bx-key"></i>
              <input
                type="password"
                class="form-control exec-login-input"
                id="access_code"
                name="access_code"
                placeholder="Enter your access code"
                autocomplete="off"
                required
              />
            </div>

            <button type="submit" class="btn exec-login-button">
              <i class="bx bx-right-arrow-alt"></i>
              <span>Enter Command Deck</span>
            </button>

            <div class="exec-login-helper">
              Your custom login UI can also post a single field named <code>access_code</code> to this page.
              Add <code>response_format=json</code> if you want JSON responses for a custom front end.
            </div>
          </form>
        </div>

        <div class="exec-login-info">
          <div class="exec-login-card">
            <div class="exec-surface-header">
              <div>
                <span class="exec-surface-kicker">Authorized Roles</span>
                <h2 class="exec-surface-title">Executive Access Profiles</h2>
              </div>
              <span class="exec-surface-badge"><?php echo count($accessRows); ?> profiles</span>
            </div>

            <div class="exec-role-stack">
              <?php foreach ($accessRows as $accessRow): ?>
                <div class="exec-role-card">
                  <div class="exec-role-icon">
                    <i class="bx <?php echo (string)($accessRow['role_key'] ?? '') === 'president' ? 'bx-crown' : 'bx-briefcase-alt-2'; ?>"></i>
                  </div>
                  <div>
                    <div class="exec-role-title"><?php echo synk_exec_analytics_h((string)($accessRow['role_label'] ?? 'Executive')); ?></div>
                    <div class="exec-role-note">Separate access code, same full analytics command deck.</div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="exec-login-card compact">
            <div class="exec-surface-header">
              <div>
                <span class="exec-surface-kicker">Portal Shape</span>
                <h2 class="exec-surface-title">What Opens After Login</h2>
              </div>
            </div>

            <div class="exec-portal-feature-list">
              <div class="exec-portal-feature">
                <i class="bx bx-line-chart"></i>
                <span>Main page starts with the full analytics view across all campuses.</span>
              </div>
              <div class="exec-portal-feature">
                <i class="bx bx-buildings"></i>
                <span>The sidebar keeps all 7 active campuses visible for instant drill-down.</span>
              </div>
              <div class="exec-portal-feature">
                <i class="bx bx-table"></i>
                <span>Live tables show campus, college, workflow, and source-table readiness in one place.</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
  </body>
</html>
