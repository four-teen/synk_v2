<?php
session_start();
ob_start();

require_once 'backend/db.php';
require_once 'backend/auth_config.php';
require_once 'backend/auth_useraccount.php';

$authSettings = synk_auth_settings();
$googleLoginEnabled = synk_google_login_enabled($authSettings);
$googleReady = synk_google_auth_ready($authSettings);
$legacyLoginEnabled = synk_legacy_login_enabled($authSettings);
$authStatus = trim((string)($_GET['auth_status'] ?? ''));
$showGoogleConfigNotice = $googleLoginEnabled && !$googleReady;
$showLegacyLogin = $legacyLoginEnabled && (!$googleLoginEnabled || !$googleReady);
$pendingRoleLogin = synk_get_pending_role_login();

function synk_login_json_response(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function synk_login_success_payload(string $role): array
{
    return [
        'status' => 'success',
        'role' => $role,
        'role_label' => synk_role_label($role),
        'redirect' => synk_role_redirect_path($role),
    ];
}

function synk_login_empty_role_error(array $configuredRoleRows): string
{
    foreach ($configuredRoleRows as $roleRow) {
        $role = (string)($roleRow['role'] ?? '');
        if ($role === 'scheduler' || $role === 'registrar') {
            return 'account_incomplete';
        }
    }

    return 'unsupported_role';
}

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $redirectPath = synk_role_redirect_path((string)$_SESSION['role']);
    if ($redirectPath !== null) {
        header('Location: ' . $redirectPath);
        exit;
    }
}

if (isset($_POST['complete_role_login'])) {
    $pendingLogin = synk_get_pending_role_login();
    if (!$pendingLogin) {
        synk_login_json_response(['status' => 'role_selection_missing']);
    }

    $selectedRole = synk_normalize_role_code((string)($_POST['selected_role'] ?? ''));
    $userId = (int)($pendingLogin['user_id'] ?? 0);
    $row = synk_find_useraccount_by_id($conn, $userId);

    if (!$row) {
        synk_clear_pending_role_login();
        synk_login_json_response(['status' => 'invalid']);
    }

    if (($row['status'] ?? '') !== 'active') {
        synk_clear_pending_role_login();
        synk_login_json_response(['status' => 'inactive']);
    }

    $configuredRoleRows = synk_fetch_useraccount_role_rows(
        $conn,
        (int)($row['user_id'] ?? 0),
        (string)($row['role'] ?? '')
    );
    $loginableRoleRows = synk_filter_loginable_role_rows($conn, $row, $configuredRoleRows);

    if (empty($loginableRoleRows)) {
        synk_clear_pending_role_login();
        synk_login_json_response(['status' => synk_login_empty_role_error($configuredRoleRows)]);
    }

    $allowedRoles = array_values(array_map(static function (array $roleRow): string {
        return (string)($roleRow['role'] ?? '');
    }, $loginableRoleRows));

    if ($selectedRole === '' || !in_array($selectedRole, $allowedRoles, true)) {
        synk_login_json_response(['status' => 'invalid_primary_role']);
    }

    $avatarUrl = trim((string)($pendingLogin['avatar_url'] ?? ''));
    $activeRole = synk_complete_user_login($row, $conn, $selectedRole, $loginableRoleRows);

    if ($avatarUrl !== '') {
        $_SESSION['user_avatar_url'] = $avatarUrl;
    }

    synk_login_json_response(synk_login_success_payload($activeRole));
}

if (isset($_POST['login'])) {
    if (!$legacyLoginEnabled) {
        synk_login_json_response(['status' => 'google_only']);
    }

    $email = synk_normalize_email((string)($_POST['email'] ?? ''));
    $password = trim((string)($_POST['password'] ?? ''));
    synk_clear_pending_role_login();

    if ($email === '' || $password === '') {
        synk_login_json_response(['status' => 'invalid']);
    }

    if (!synk_is_allowed_email_domain($email, (string)($authSettings['allowed_domain'] ?? 'sksu.edu.ph'))) {
        synk_login_json_response(['status' => 'invalid']);
    }

    $row = synk_find_useraccount_by_email($conn, $email);
    if (!$row) {
        synk_login_json_response(['status' => 'invalid']);
    }

    if (($row['status'] ?? '') !== 'active') {
        synk_login_json_response(['status' => 'inactive']);
    }

    $storedPassword = (string)($row['password'] ?? '');
    if ($storedPassword === '' || !password_verify($password, $storedPassword)) {
        synk_login_json_response(['status' => 'invalid']);
    }

    $configuredRoleRows = synk_fetch_useraccount_role_rows(
        $conn,
        (int)($row['user_id'] ?? 0),
        (string)($row['role'] ?? '')
    );
    $loginableRoleRows = synk_filter_loginable_role_rows($conn, $row, $configuredRoleRows);

    if (empty($loginableRoleRows)) {
        synk_login_json_response(['status' => synk_login_empty_role_error($configuredRoleRows)]);
    }

    if (count($loginableRoleRows) === 1) {
        $activeRole = synk_complete_user_login($row, $conn, null, $loginableRoleRows);
        synk_login_json_response(synk_login_success_payload($activeRole));
    }

    synk_store_pending_role_login($row, $loginableRoleRows);
    synk_login_json_response([
        'status' => 'choose_role',
        'email' => (string)($row['email'] ?? ''),
        'primary_role' => synk_useraccount_primary_role($loginableRoleRows, (string)($row['role'] ?? '')),
        'roles' => synk_role_rows_to_payload($loginableRoleRows),
    ]);
}
?>

<!DOCTYPE html>

<!-- =========================================================
* Sneat - Bootstrap 5 HTML Admin Template - Pro | v1.0.0
==============================================================

* Product Page: https://themeselection.com/products/sneat-bootstrap-html-admin-template/
* Created by: ThemeSelection
* License: You must have a valid license purchased in order to legally use the theme for your project.
* Copyright ThemeSelection (https://themeselection.com)

=========================================================
 -->
<!-- beautify ignore:start -->
<html
  lang="en"
  class="light-style customizer-hide"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="assets/"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />

    <title>SKSU Synk</title>

    <meta name="description" content="" />

    <link rel="icon" type="image/png" href="assets/img/favicon/synk-icon.png" />

    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
      rel="stylesheet"
    />

    <link rel="stylesheet" href="assets/vendor/fonts/boxicons.css" />

    <link rel="stylesheet" href="assets/vendor/css/core.css" class="template-customizer-core-css" />
    <link rel="stylesheet" href="assets/vendor/css/theme-default.css" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="assets/css/demo.css" />
    <link rel="stylesheet" href="assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="assets/vendor/css/pages/page-auth.css" />

    <style>
      :root {
        --login-bg: linear-gradient(135deg, #f3f6fb 0%, #f8fafc 46%, #edf7f1 100%);
        --login-card-border: #d9e2ef;
        --login-card-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
        --login-title: #1f3552;
        --login-subtitle: #1f5c3f;
        --login-note-bg: #edf5ff;
        --login-note-border: #cbdff8;
        --login-note-text: #1f4e7a;
        --login-footer-bg: linear-gradient(180deg, #f9fbff 0%, #edf6f0 100%);
        --login-footer-border: #e2eaf3;
        --login-footer-text: #5f7088;
      }

      body {
        min-height: 100vh;
        min-height: 100dvh;
        background: var(--login-bg);
      }

      .login-shell {
        min-height: 100vh;
        min-height: 100dvh;
        padding: 2rem 1rem;
        position: relative;
        overflow-x: hidden;
        overflow-y: auto;
      }

      .login-stage {
        min-height: calc(100vh - 4rem);
        min-height: calc(100dvh - 4rem);
        max-width: 980px;
        margin: 0 auto;
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        z-index: 1;
      }

      .login-dot-grid {
        position: absolute;
        width: 176px;
        height: 176px;
        background-image: radial-gradient(rgba(105, 108, 255, 0.36) 2px, transparent 2px);
        background-size: 18px 18px;
        pointer-events: none;
      }

      .login-dot-grid-left {
        left: 16%;
        bottom: 9%;
        opacity: 0.55;
      }

      .login-dot-grid-right {
        right: 14%;
        top: 10%;
      }

      .login-plate {
        position: absolute;
        border: 1px solid rgba(67, 89, 113, 0.14);
        border-radius: 0.9rem;
        background: rgba(255, 255, 255, 0.28);
        pointer-events: none;
      }

      .login-plate-left {
        left: 19%;
        bottom: 5%;
        width: 140px;
        height: 210px;
      }

      .login-plate-right {
        right: 22%;
        top: 17%;
        width: 120px;
        height: 120px;
      }

      .login-panel {
        width: 100%;
        max-width: 400px;
        position: relative;
        padding-top: 1.95rem;
      }

      .login-emblem {
        position: absolute;
        top: 0;
        left: 50%;
        transform: translate(-50%, -12%);
        width: 92px;
        height: 92px;
        padding: 0.2rem;
        background: #f3f6fb;
        border: 8px solid #f3f6fb;
        border-radius: 50%;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.18);
        z-index: 5;
      }

      .login-emblem-image {
        display: block;
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: cover;
      }

      .login-card {
        margin-top: 1rem;
        border: 1px solid var(--login-card-border);
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.96);
        box-shadow: var(--login-card-shadow);
      }

      .login-card .card-body {
        padding: 3.55rem 2rem 2rem;
      }

      .login-brand {
        text-align: center;
        font-size: 1.55rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        line-height: 1.1;
        text-transform: lowercase;
        color: var(--login-title);
      }

      .login-brand span:nth-child(1) {
        color: #007bff;
        text-shadow: 0 0 8px rgba(0, 123, 255, 0.3);
      }

      .login-brand span:nth-child(2) {
        color: #ff8a00;
        text-shadow: 0 0 8px rgba(255, 138, 0, 0.3);
      }

      .login-brand span:nth-child(3) {
        color: #e000d5;
        text-shadow: 0 0 8px rgba(224, 0, 213, 0.3);
      }

      .login-brand span:nth-child(4) {
        color: #00b85a;
        text-shadow: 0 0 8px rgba(0, 184, 90, 0.3);
      }

      .login-title {
        max-width: 280px;
        margin: 0.85rem auto 0.45rem;
        color: var(--login-title);
        font-size: 1rem;
        font-weight: 800;
        line-height: 1.35;
        text-align: center;
      }

      .login-subtitle {
        max-width: 285px;
        margin: 0 auto 1.2rem;
        color: var(--login-subtitle);
        font-size: 0.95rem;
        line-height: 1.58;
        text-align: center;
      }

      .login-subtitle small {
        display: block;
        margin-top: 0.35rem;
        color: #96a4b6 !important;
        font-size: 0.88rem;
      }

      .login-alert {
        max-width: 304px;
        margin: 0 auto 1rem;
        padding: 0.9rem 1rem;
        border-radius: 12px;
        border: 1px solid var(--login-note-border);
        font-size: 0.9rem;
        line-height: 1.5;
      }

      .login-alert.alert-info {
        background: var(--login-note-bg);
        color: var(--login-note-text);
      }

      .login-alert.alert-warning {
        background: #fff4e8;
        border-color: #ffd6aa;
        color: #8a4a07;
      }

      .login-divider {
        margin: 1.1rem 0 0.95rem;
        text-align: center;
      }

      .login-divider span {
        display: inline-block;
        font-size: 0.78rem;
        color: #6b7f98;
        text-transform: uppercase;
        letter-spacing: 0.08em;
      }

      .login-google-wrap {
        max-width: 234px;
        margin: 0 auto;
      }

      .btn-google-login {
        --login-google-radius: 0.75rem;
        min-height: 2.7rem;
        position: relative;
        border-radius: var(--login-google-radius);
        border: 1px solid #e4e8f0;
        background: #fff;
        color: #2f4058;
        font-size: 0.92rem;
        font-weight: 600;
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
        overflow: visible;
        isolation: isolate;
      }

      .btn-google-login::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        width: 0.34rem;
        height: 0.34rem;
        border-radius: 999px;
        background: radial-gradient(
          circle,
          rgba(255, 221, 186, 0.98) 0%,
          rgba(244, 149, 63, 0.96) 38%,
          rgba(179, 83, 15, 0.88) 62%,
          rgba(179, 83, 15, 0) 100%
        );
        box-shadow:
          0 0 4px rgba(201, 104, 28, 0.8),
          0 0 8px rgba(201, 104, 28, 0.3);
        opacity: 1;
        offset-anchor: center;
        offset-path: inset(0.5px round calc(var(--login-google-radius) - 0.5px));
        offset-distance: 0%;
        animation: login-border-orbit 4s linear infinite;
        transition: opacity 0.2s ease;
        pointer-events: none;
        z-index: 3;
      }

      .btn-google-login > span {
        position: relative;
        z-index: 2;
      }

      .btn-google-login .google-mark {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1rem;
        color: #db4437;
        font-size: 1.3rem;
        font-weight: 800;
        line-height: 1;
      }

      .btn-google-login:hover,
      .btn-google-login:focus,
      .btn-google-login:focus-visible {
        transform: translateY(-1px);
        border-color: #d7b693;
        box-shadow: 0 6px 14px rgba(51, 71, 103, 0.09);
        color: #2f4058;
      }

      .btn-google-login:hover::before,
      .btn-google-login:focus::before,
      .btn-google-login:focus-visible::before {
        opacity: 1;
      }

      .btn-google-login.disabled,
      .btn-google-login[aria-disabled="true"] {
        box-shadow: none;
      }

      .btn-google-login.disabled::before,
      .btn-google-login[aria-disabled="true"]::before {
        display: none;
      }

      .login-footer {
        margin: 1.4rem -2rem -2rem;
        padding: 1.15rem 2rem 1.35rem;
        border-top: 1px solid var(--login-footer-border);
        border-radius: 0 0 16px 16px;
        background: var(--login-footer-bg);
      }

      .login-footer .login-divider {
        margin: 0 0 0.9rem;
      }

      .login-footer .login-google-wrap {
        margin-bottom: 0.95rem;
      }

      .login-footnote {
        max-width: 288px;
        margin: 0 auto;
        color: var(--login-footer-text);
        text-align: center;
        font-size: 0.87rem;
        line-height: 1.5;
      }

      .login-project-note {
        margin-top: 0.55rem;
        color: #667b67;
        text-align: center;
        font-size: 0.8rem;
        font-weight: 700;
        letter-spacing: 0.03em;
      }

      .legacy-fallback {
        max-width: 304px;
        margin: 1.2rem auto 0;
        padding-top: 1rem;
        border-top: 1px solid #e6edf7;
      }

      .legacy-fallback .form-label {
        color: #5d7086;
        font-size: 0.76rem;
        font-weight: 800;
        letter-spacing: 0.06em;
        text-transform: uppercase;
      }

      .legacy-fallback .form-control,
      .legacy-fallback .input-group-text,
      .legacy-fallback .btn {
        border-radius: 10px;
      }

      .swal2-popup.role-choice-swal {
        padding: 1.45rem 1.35rem 1.35rem;
        border-radius: 22px;
      }

      .role-choice-lead {
        margin: 0 auto 1rem;
        max-width: 420px;
        color: #5d7086;
        line-height: 1.55;
        font-size: 0.96rem;
      }

      .role-choice-email {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        margin-top: 0.45rem;
        padding: 0.35rem 0.7rem;
        border-radius: 999px;
        background: #eef4ff;
        color: #48627d;
        font-size: 0.8rem;
        font-weight: 700;
        word-break: break-word;
      }

      .role-choice-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 0.85rem;
        margin-top: 1rem;
        text-align: left;
      }

      .role-choice-card {
        position: relative;
        display: block;
        border: 1px solid #dce5f1;
        border-radius: 18px;
        padding: 1rem 0.95rem;
        background: #fbfdff;
        cursor: pointer;
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, background 0.18s ease;
      }

      .role-choice-card:hover {
        transform: translateY(-1px);
        border-color: #cfdaf4;
        box-shadow: 0 14px 28px rgba(67, 89, 113, 0.1);
      }

      .role-choice-card.is-selected {
        border-color: #696cff;
        background: #f6f7ff;
        box-shadow: 0 14px 28px rgba(105, 108, 255, 0.14);
      }

      .role-choice-input {
        position: absolute;
        inset: 0;
        opacity: 0;
        pointer-events: none;
      }

      .role-choice-top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
      }

      .role-choice-icon {
        width: 42px;
        height: 42px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        flex: 0 0 42px;
      }

      .role-choice-state {
        width: 1rem;
        height: 1rem;
        border-radius: 999px;
        border: 2px solid #c4d0df;
        margin-top: 0.15rem;
        flex: 0 0 1rem;
        transition: border-color 0.18s ease, background 0.18s ease, box-shadow 0.18s ease;
      }

      .role-choice-card.is-selected .role-choice-state {
        border-color: #696cff;
        background: #696cff;
        box-shadow: inset 0 0 0 2px #ffffff;
      }

      .role-choice-title {
        margin: 0.85rem 0 0;
        color: #25364a;
        font-size: 1rem;
        font-weight: 800;
      }

      .role-choice-copy {
        margin: 0.35rem 0 0;
        color: #607389;
        font-size: 0.88rem;
        line-height: 1.5;
        min-height: 2.65rem;
      }

      .role-choice-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
        margin-top: 0.9rem;
      }

      .role-choice-badges .badge {
        font-size: 0.72rem;
        padding: 0.38rem 0.6rem;
      }

      @keyframes login-border-orbit {
        to {
          offset-distance: 100%;
        }
      }

      @media (max-width: 767.98px) {
        .login-shell {
          min-height: 100dvh;
          padding: 1rem 0.85rem 1.15rem;
        }

        .login-stage {
          min-height: auto;
          align-items: flex-start;
          padding: 0.35rem 0 0.85rem;
        }

        .login-panel {
          max-width: 100%;
          padding-top: 1.35rem;
        }

        .login-emblem {
          width: 72px;
          height: 72px;
          border-width: 6px;
        }

        .login-card {
          margin-top: 0.8rem;
        }

        .login-card .card-body {
          padding: 2.85rem 1rem 1rem;
        }

        .login-footer {
          margin: 1.1rem -1rem -1rem;
          padding: 1rem 1rem 1.05rem;
        }

        .login-brand {
          font-size: 1.34rem;
        }

        .login-title {
          max-width: 248px;
          font-size: 0.92rem;
        }

        .login-subtitle {
          margin-bottom: 0.95rem;
          font-size: 0.9rem;
          line-height: 1.45;
        }

        .login-alert {
          margin-bottom: 0.85rem;
          padding: 0.8rem 0.9rem;
          font-size: 0.86rem;
        }

        .login-footnote {
          font-size: 0.82rem;
          line-height: 1.45;
        }

        .login-dot-grid-right,
        .login-plate-right {
          display: none;
        }

        .login-dot-grid-left {
          left: 6%;
          bottom: 8%;
        }

        .login-plate-left {
          left: 8%;
        }

        .swal2-popup.role-choice-swal {
          width: calc(100vw - 1.5rem) !important;
          padding: 1.2rem 1rem 1rem;
        }

        .role-choice-grid {
          grid-template-columns: 1fr;
          gap: 0.7rem;
        }

        .role-choice-copy {
          min-height: auto;
        }
      }

      @media (max-height: 820px) {
        .login-stage {
          min-height: auto;
          align-items: flex-start;
          padding: 0.35rem 0 1rem;
        }
      }
    </style>

    <script src="assets/vendor/js/helpers.js"></script>
    <script src="assets/js/config.js"></script>
  </head>

  <body>
    <div class="login-shell">
      <div class="login-stage">
        <div class="login-dot-grid login-dot-grid-left"></div>
        <div class="login-dot-grid login-dot-grid-right"></div>
        <div class="login-plate login-plate-left"></div>
        <div class="login-plate login-plate-right"></div>

        <div class="login-panel">
          <div class="login-emblem">
            <img
              src="assets/img/favicon/logo.png"
              alt="Sultan Kudarat State University"
              class="login-emblem-image"
            />
          </div>

          <div class="card login-card">
            <div class="card-body">
              <div class="login-brand"><span>s</span><span>y</span><span>n</span><span>k</span></div>

              <h1 class="login-title">Centralized Academic Management Platform</h1>
              <p class="login-subtitle">
                Official platform for centralized academic operations, designed to unify scheduling,
                enrollment, faculty profiling, and other university processes in one system.
                <small>Sultan Kudarat State University</small>
              </p>

              <div class="alert alert-info login-alert" role="alert">
                Only official SKSU Google accounts with an exact matching Synk access record can
                access this system.
              </div>

              <?php if ($showGoogleConfigNotice): ?>
                <div class="alert alert-warning login-alert" role="alert">
                  Google sign-in is still being configured. Temporary fallback access remains
                  available until setup is complete.
                </div>
              <?php endif; ?>

              <?php if ($showLegacyLogin): ?>
                <div class="legacy-fallback">
                  <div class="login-divider mt-0">
                    <span>Temporary Legacy Fallback</span>
                  </div>

                  <form id="loginForm">
                    <div class="mb-3">
                      <label for="email" class="form-label">SKSU Email</label>
                      <input
                        type="email"
                        class="form-control"
                        id="email"
                        name="email-username"
                        placeholder="name@sksu.edu.ph"
                        autofocus
                      />
                    </div>
                    <div class="mb-3 form-password-toggle">
                      <div class="d-flex justify-content-between">
                        <label class="form-label" for="password">Password</label>
                      </div>
                      <div class="input-group input-group-merge">
                        <input
                          type="password"
                          id="password"
                          class="form-control"
                          name="password"
                          placeholder="&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;&#xb7;"
                          aria-describedby="password"
                        />
                        <span class="input-group-text cursor-pointer"><i class="bx bx-hide"></i></span>
                      </div>
                    </div>
                    <div class="d-grid">
                      <button type="button" id="btn_login" class="btn btn-primary">Sign in</button>
                    </div>
                  </form>
                </div>
              <?php endif; ?>

              <div class="login-footer">
                <div class="login-divider">
                  <span>Authorized Google Sign-In</span>
                </div>

                <?php if ($googleLoginEnabled): ?>
                  <div class="login-google-wrap d-grid">
                    <a
                      href="backend/auth_google_start.php"
                      id="btn_google_login"
                      class="btn btn-google-login d-grid w-100<?php echo !$googleReady ? ' disabled' : ''; ?>"
                      data-google-ready="<?php echo $googleReady ? '1' : '0'; ?>"
                      aria-disabled="<?php echo $googleReady ? 'false' : 'true'; ?>"
                    >
                      <span class="d-flex align-items-center justify-content-center gap-2">
                        <span class="google-mark" aria-hidden="true">G</span>
                        Continue with SKSU Google
                      </span>
                    </a>
                  </div>
                <?php else: ?>
                  <div class="alert alert-warning login-alert mb-3" role="alert">
                    Google sign-in is disabled in the current authentication settings.
                  </div>
                <?php endif; ?>

                <div class="login-footnote">
                  Access is limited to approved Synk administrator, scheduler, professor, program chair, college dean, registrar, VPAA, and DI accounts.
                </div>

                <div class="login-project-note">SYNKronized 2025</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script src="assets/vendor/libs/jquery/jquery.js"></script>
    <script src="assets/vendor/libs/popper/popper.js"></script>
    <script src="assets/vendor/js/bootstrap.js"></script>
    <script src="assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="assets/vendor/js/menu.js"></script>
    <script src="assets/js/main.js"></script>

    <script>
      $(document).ready(function () {
        const authStatus = <?php echo json_encode($authStatus, JSON_UNESCAPED_SLASHES); ?>;
        const googleReady = <?php echo $googleReady ? 'true' : 'false'; ?>;
        const pendingRoleLogin = <?php echo json_encode($pendingRoleLogin, JSON_UNESCAPED_SLASHES); ?>;

        const authStatusMessages = {
          google_unavailable: {
            icon: "warning",
            title: "Google Sign-In Not Ready",
            text: "Google credentials are not configured yet."
          },
          google_state_invalid: {
            icon: "error",
            title: "Login Expired",
            text: "Please try Google sign-in again."
          },
          google_cancelled: {
            icon: "info",
            title: "Sign-In Cancelled",
            text: "Google sign-in was cancelled."
          },
          google_code_missing: {
            icon: "error",
            title: "Google Sign-In Failed",
            text: "The authorization code was not returned."
          },
          google_token_failed: {
            icon: "error",
            title: "Google Sign-In Failed",
            text: "Unable to exchange the Google authorization code."
          },
          google_token_missing: {
            icon: "error",
            title: "Google Sign-In Failed",
            text: "Google did not return an access token."
          },
          google_id_token_missing: {
            icon: "error",
            title: "Google Sign-In Failed",
            text: "Google did not return a valid identity token."
          },
          google_id_token_invalid: {
            icon: "error",
            title: "Identity Check Failed",
            text: "The Google identity token could not be validated."
          },
          google_nonce_invalid: {
            icon: "error",
            title: "Identity Check Failed",
            text: "The Google sign-in response did not match this login request."
          },
          google_profile_failed: {
            icon: "error",
            title: "Profile Lookup Failed",
            text: "Unable to load the Google profile."
          },
          google_profile_invalid: {
            icon: "error",
            title: "Invalid Google Account",
            text: "The Google account did not return a verified email."
          },
          google_email_mismatch: {
            icon: "error",
            title: "Identity Check Failed",
            text: "The Google identity details did not match the returned profile."
          },
          email_domain_denied: {
            icon: "warning",
            title: "Email Not Allowed",
            text: "Only verified @sksu.edu.ph email addresses can sign in."
          },
          account_not_allowed: {
            icon: "warning",
            title: "Access Not Allowed",
            text: "Your account is not in the Synk access allowlist."
          },
          account_inactive: {
            icon: "warning",
            title: "Account Inactive",
            text: "Please contact the administrator."
          },
          account_incomplete: {
            icon: "warning",
            title: "Account Incomplete",
            text: "Your account is missing required access details."
          },
          role_not_supported: {
            icon: "warning",
            title: "Role Not Supported",
            text: "This account does not have a supported Synk module role."
          },
          google_identity_mismatch: {
            icon: "error",
            title: "Google Account Mismatch",
            text: "This email is already linked to a different Google account."
          },
          role_selection_missing: {
            icon: "warning",
            title: "Role Selection Expired",
            text: "Please sign in again to choose your workspace role."
          }
        };

        function clearAuthStatusFromUrl() {
          if (window.history && window.history.replaceState) {
            window.history.replaceState({}, document.title, window.location.pathname);
          }
        }

        function escapeHtmlText(value) {
          return String(value || "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
        }

        function showStatusAlert(status) {
          const message = authStatusMessages[status];
          if (!message) {
            return;
          }

          Swal.fire({
            icon: message.icon,
            title: message.title,
            text: message.text,
            width: "360px",
            padding: "1.5rem"
          });
        }

        function roleChoiceRows(roleRows) {
          if (!Array.isArray(roleRows)) {
            return [];
          }

          return roleRows.map(function (roleRow) {
            const rawRole = String(roleRow && roleRow.role ? roleRow.role : "").trim().toLowerCase();
            const role = ["vpaa", "di"].indexOf(rawRole) !== -1 ? rawRole.toUpperCase() : rawRole;
            const label = String(roleRow && roleRow.label ? roleRow.label : role);

            if (role === "") {
              return null;
            }

            const meta = {
              admin: {
                icon: "bx-shield-quarter",
                iconClass: "bg-label-primary",
                description: "System setup, academic configuration, and access control."
              },
              scheduler: {
                icon: "bx-calendar-edit",
                iconClass: "bg-label-info",
                description: "Scheduling, workload, and college-scoped schedule operations."
              },
              professor: {
                icon: "bx-user-voice",
                iconClass: "bg-label-warning",
                description: "Faculty portal, workload view, and subject teaching details."
              },
              program_chair: {
                icon: "bx-briefcase-alt-2",
                iconClass: "bg-label-success",
                description: "Program oversight, curriculum-based enrollment, and submissions."
              },
              college_dean: {
                icon: "bx-buildings",
                iconClass: "bg-label-success",
                description: "College programs, curriculum-based enrollment, and registrar submissions."
              },
              registrar: {
                icon: "bx-clipboard-check",
                iconClass: "bg-label-danger",
                description: "Campus registrar queue, draft review, and enrollment approval tracking."
              },
              VPAA: {
                icon: "bx-briefcase-alt-2",
                iconClass: "bg-label-primary",
                description: "Open the VPAA module and account dashboard."
              },
              DI: {
                icon: "bx-book-open",
                iconClass: "bg-label-info",
                description: "Open the DI module and account dashboard."
              }
            };

            return {
              role: role,
              label: label,
              isPrimary: Boolean(roleRow && roleRow.is_primary),
              icon: meta[role] ? meta[role].icon : "bx-grid-alt",
              iconClass: meta[role] ? meta[role].iconClass : "bg-label-secondary",
              description: meta[role] ? meta[role].description : "Open this assigned Synk workspace."
            };
          }).filter(Boolean);
        }

        function roleChoiceHtml(roleRows, selectedRole) {
          return roleRows.map(function (roleRow) {
            const checked = roleRow.role === selectedRole ? " checked" : "";
            const selectedClass = roleRow.role === selectedRole ? " is-selected" : "";
            const defaultBadge = roleRow.isPrimary
              ? '<span class="badge bg-label-primary">Default</span>'
              : '<span class="badge bg-label-secondary">Assigned</span>';

            return `
              <label class="role-choice-card${selectedClass}" data-role-card="${roleRow.role}">
                <input class="role-choice-input" type="radio" name="synk_role_choice" value="${roleRow.role}"${checked}>
                <div class="role-choice-top">
                  <span class="role-choice-icon ${roleRow.iconClass}">
                    <i class="bx ${roleRow.icon}"></i>
                  </span>
                  <span class="role-choice-state" aria-hidden="true"></span>
                </div>
                <div class="role-choice-title">${escapeHtmlText(roleRow.label)}</div>
                <div class="role-choice-copy">${escapeHtmlText(roleRow.description)}</div>
                <div class="role-choice-badges">${defaultBadge}</div>
              </label>
            `;
          }).join("");
        }

        function bindRoleChoiceCards() {
          const cards = Array.from(document.querySelectorAll("[data-role-card]"));

          cards.forEach(function (card) {
            card.addEventListener("click", function () {
              const input = card.querySelector('input[name="synk_role_choice"]');
              if (!input) {
                return;
              }

              input.checked = true;
              cards.forEach(function (item) {
                item.classList.toggle("is-selected", item === card);
              });
            });
          });
        }

        function selectedRoleChoiceFromPopup() {
          const checked = document.querySelector('input[name="synk_role_choice"]:checked');
          return checked ? String(checked.value || "") : "";
        }

        function redirectAfterLogin(response) {
          const roleLabel = String(response && response.role_label ? response.role_label : "User");
          const redirectPath = String(response && response.redirect ? response.redirect : "");

          Swal.fire({
            icon: "success",
            title: "Welcome " + roleLabel + "!",
            text: "Redirecting...",
            timer: 1500,
            showConfirmButton: false,
            width: "360px",
            padding: "1.5rem"
          });

          window.setTimeout(function () {
            window.location = redirectPath !== "" ? redirectPath : "index.php";
          }, 1500);
        }

        function submitRoleSelection(selectedRole) {
          $.ajax({
            url: "index.php",
            type: "POST",
            dataType: "json",
            data: {
              complete_role_login: 1,
              selected_role: selectedRole
            }
          }).done(function (response) {
            handleLoginResponse(response);
          }).fail(function () {
            Swal.fire({
              icon: "error",
              title: "Role Selection Failed",
              text: "Please sign in again to continue.",
              width: "360px",
              padding: "1.5rem"
            });
          });
        }

        function promptRoleSelection(context) {
          const roleRows = roleChoiceRows(context && context.roles);
          const optionKeys = roleRows.map(function (item) {
            return item.role;
          }).filter(Boolean);
          const defaultRole = String(context && context.primary_role ? context.primary_role : (optionKeys[0] || ""));

          if (optionKeys.length === 0) {
            showStatusAlert("role_selection_missing");
            return;
          }

          Swal.fire({
            title: "Choose Login Role",
            html: `
              <div class="role-choice-lead">
                Select the workspace to open for this session.
                <div class="role-choice-email">
                  <i class="bx bx-envelope"></i>
                  <span>${escapeHtmlText(String(context && context.email ? context.email : "This account"))}</span>
                </div>
              </div>
              <div class="role-choice-grid">
                ${roleChoiceHtml(roleRows, defaultRole)}
              </div>
            `,
            confirmButtonText: "Continue",
            allowOutsideClick: false,
            allowEscapeKey: false,
            width: "620px",
            customClass: {
              popup: "role-choice-swal"
            },
            didOpen: function () {
              bindRoleChoiceCards();
            },
            preConfirm: function () {
              const value = selectedRoleChoiceFromPopup();
              if (!value) {
                Swal.showValidationMessage("Select a role to continue.");
                return false;
              }

              return value;
            }
          }).then(function (result) {
            if (!result.isConfirmed || !result.value) {
              return;
            }

            submitRoleSelection(result.value);
          });
        }

        function handleLoginResponse(response) {
          const status = String(response && response.status ? response.status : "");

          if (status === "success") {
            redirectAfterLogin(response);
            return;
          }

          if (status === "choose_role") {
            promptRoleSelection(response);
            return;
          }

          if (status === "invalid") {
            Swal.fire({
              icon: "error",
              title: "Login Failed",
              text: "Incorrect email or password.",
              width: "360px",
              padding: "1.5rem"
            });
            return;
          }

          if (status === "inactive") {
            Swal.fire({
              icon: "warning",
              title: "Account Inactive",
              text: "Please contact the administrator.",
              width: "360px",
              padding: "1.5rem"
            });
            return;
          }

          if (status === "google_only") {
            Swal.fire({
              icon: "info",
              title: "Use Google Sign-In",
              text: "This system is configured for Google login only.",
              width: "360px",
              padding: "1.5rem"
            });
            return;
          }

          if (status === "unsupported_role") {
            Swal.fire({
              icon: "warning",
              title: "Role Not Supported",
              text: "This account does not have a supported Synk module role.",
              width: "360px",
              padding: "1.5rem"
            });
            return;
          }

          if (status === "account_incomplete") {
            Swal.fire({
              icon: "warning",
              title: "Account Incomplete",
              text: "Your account is missing required access details.",
              width: "360px",
              padding: "1.5rem"
            });
            return;
          }

          if (status === "role_selection_missing" || status === "invalid_primary_role") {
            Swal.fire({
              icon: "warning",
              title: "Choose Login Role",
              text: "Please choose one of your assigned roles to continue.",
              width: "360px",
              padding: "1.5rem"
            }).then(function () {
              if (pendingRoleLogin && Array.isArray(pendingRoleLogin.roles) && pendingRoleLogin.roles.length > 1) {
                promptRoleSelection(pendingRoleLogin);
              }
            });
            return;
          }

          Swal.fire({
            icon: "error",
            title: "Unexpected Error",
            text: "Please try again later.",
            width: "360px",
            padding: "1.5rem"
          });
        }

        if (authStatus && authStatus !== "choose_role" && authStatusMessages[authStatus]) {
          showStatusAlert(authStatus);
          clearAuthStatusFromUrl();
        } else if (authStatus === "choose_role" && (!pendingRoleLogin || !Array.isArray(pendingRoleLogin.roles) || pendingRoleLogin.roles.length === 0)) {
          showStatusAlert("role_selection_missing");
          clearAuthStatusFromUrl();
        }

        if (pendingRoleLogin && Array.isArray(pendingRoleLogin.roles) && pendingRoleLogin.roles.length > 1) {
          if (authStatus === "choose_role") {
            clearAuthStatusFromUrl();
          }

          window.setTimeout(function () {
            promptRoleSelection(pendingRoleLogin);
          }, 80);
        }

        $("#btn_google_login").on("click", function (e) {
          if (googleReady) {
            return;
          }

          e.preventDefault();
          Swal.fire({
            icon: "info",
            title: "Google Sign-In Pending",
            text: "Google credentials still need to be configured for this system.",
            width: "360px",
            padding: "1.5rem"
          });
        });

        $("#email, #password").on("keypress", function (e) {
          if (e.which === 13) {
            $("#btn_login").click();
          }
        });

        $("#btn_login").click(function () {
          const email = $("#email").val();
          const password = $("#password").val();

          if (email === "" || password === "") {
            Swal.fire({
              icon: "warning",
              title: "Incomplete Details",
              text: "Please enter both your email and password.",
              confirmButtonColor: "#3085d6",
              width: "360px",
              padding: "1.5rem",
              allowOutsideClick: false
            });
            return;
          }

          $.ajax({
            url: "index.php",
            type: "POST",
            dataType: "json",
            data: {
              login: 1,
              email: email,
              password: password
            }
          }).done(function (response) {
            handleLoginResponse(response);
          }).fail(function () {
            Swal.fire({
              icon: "error",
              title: "Unexpected Error",
              text: "Please try again later.",
              width: "360px",
              padding: "1.5rem"
            });
          });
        });
      });
    </script>
  </body>
</html>
