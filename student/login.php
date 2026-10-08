<?php
session_start();
ob_start();

require_once '../backend/auth_config.php';
require_once '../backend/auth_useraccount.php';

$authSettings = synk_auth_settings();
$googleLoginEnabled = synk_google_login_enabled($authSettings);
$googleReady = synk_google_auth_ready($authSettings);
$authStatus = trim((string)($_GET['auth_status'] ?? ''));

if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $redirectPath = synk_role_redirect_path((string)$_SESSION['role']);
    if ($redirectPath !== null) {
        header('Location: ../' . $redirectPath);
        exit;
    }
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
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />

    <title>Student Login | SKSU Synk</title>

    <link rel="icon" type="image/png" href="../assets/img/favicon/synk-icon.png" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
      rel="stylesheet"
    />

    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" class="template-customizer-core-css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="../assets/vendor/css/pages/page-auth.css" />

    <style>
      :root {
        --student-login-bg: linear-gradient(140deg, #eef4ff 0%, #f7fbff 45%, #eef9f2 100%);
        --student-login-accent: #1f4f8c;
        --student-login-soft: #6582a0;
        --student-login-card-border: #dce5f1;
        --student-login-card-shadow: 0 24px 46px rgba(15, 23, 42, 0.1);
      }

      body {
        min-height: 100vh;
        background: var(--student-login-bg);
      }

      .student-login-shell {
        min-height: 100vh;
        display: grid;
        place-items: center;
        padding: 1.5rem;
        position: relative;
        overflow: hidden;
      }

      .student-login-shell::before,
      .student-login-shell::after {
        content: "";
        position: absolute;
        border-radius: 999px;
        pointer-events: none;
        opacity: 0.6;
      }

      .student-login-shell::before {
        width: 24rem;
        height: 24rem;
        top: -6rem;
        right: -8rem;
        background: radial-gradient(circle, rgba(105, 108, 255, 0.18) 0%, rgba(105, 108, 255, 0) 70%);
      }

      .student-login-shell::after {
        width: 28rem;
        height: 28rem;
        left: -10rem;
        bottom: -10rem;
        background: radial-gradient(circle, rgba(113, 221, 55, 0.14) 0%, rgba(113, 221, 55, 0) 72%);
      }

      .student-login-card {
        width: 100%;
        max-width: 440px;
        border: 1px solid var(--student-login-card-border);
        border-radius: 22px;
        background: rgba(255, 255, 255, 0.96);
        box-shadow: var(--student-login-card-shadow);
        position: relative;
        z-index: 1;
      }

      .student-login-card .card-body {
        padding: 2.35rem 2rem 2rem;
      }

      .student-login-mark {
        width: 86px;
        height: 86px;
        border-radius: 50%;
        margin: 0 auto 1.15rem;
        padding: 0.35rem;
        background: #eff5ff;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.14);
      }

      .student-login-mark img {
        width: 100%;
        height: 100%;
        border-radius: 50%;
        object-fit: cover;
      }

      .student-kicker {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 100%;
        margin-bottom: 0.65rem;
        font-size: 0.78rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #48627d;
      }

      .student-title {
        margin: 0;
        text-align: center;
        font-size: 1.55rem;
        font-weight: 800;
        color: var(--student-login-accent);
      }

      .student-subtitle {
        max-width: 320px;
        margin: 0.8rem auto 0;
        text-align: center;
        color: var(--student-login-soft);
        line-height: 1.6;
        font-size: 0.95rem;
      }

      .student-info-note {
        margin-top: 1.25rem;
        padding: 0.95rem 1rem;
        border-radius: 14px;
        border: 1px solid #cfe1fb;
        background: #eef5ff;
        color: #24507e;
        font-size: 0.9rem;
        line-height: 1.55;
      }

      .student-info-note strong {
        color: #173f68;
      }

      .student-warning-note {
        margin-top: 0.85rem;
        padding: 0.9rem 1rem;
        border-radius: 14px;
        border: 1px solid #ffd7ad;
        background: #fff4e7;
        color: #8d520f;
        font-size: 0.88rem;
        line-height: 1.5;
      }

      .student-login-actions {
        margin-top: 1.35rem;
        display: grid;
        gap: 0.85rem;
      }

      .student-google-btn {
        min-height: 2.95rem;
        border-radius: 0.85rem;
        border: 1px solid #dfe6f0;
        background: #ffffff;
        color: #30445d;
        font-size: 0.95rem;
        font-weight: 700;
        box-shadow: 0 10px 22px rgba(15, 23, 42, 0.07);
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
      }

      .student-google-btn:hover,
      .student-google-btn:focus,
      .student-google-btn:focus-visible {
        transform: translateY(-1px);
        border-color: #d7b693;
        box-shadow: 0 12px 24px rgba(15, 23, 42, 0.09);
        color: #30445d;
      }

      .student-google-btn.disabled,
      .student-google-btn[aria-disabled="true"] {
        box-shadow: none;
      }

      .student-google-mark {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 1rem;
        font-size: 1.3rem;
        color: #db4437;
        font-weight: 800;
      }

      .student-secondary-link {
        color: #4b6179;
        text-align: center;
        font-size: 0.88rem;
      }

      .student-secondary-link a {
        font-weight: 700;
      }

      .student-footer {
        margin-top: 1.4rem;
        padding-top: 1rem;
        border-top: 1px solid #e6edf7;
        text-align: center;
      }

      .student-footer-note {
        color: #6a7f95;
        font-size: 0.84rem;
        line-height: 1.55;
      }

      @media (max-width: 575.98px) {
        .student-login-card .card-body {
          padding: 2rem 1.15rem 1.4rem;
        }
      }
    </style>

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
  </head>

  <body>
    <div class="student-login-shell">
      <div class="card student-login-card">
        <div class="card-body">
          <div class="student-login-mark">
            <img src="../assets/img/favicon/logo.png" alt="Sultan Kudarat State University" />
          </div>

          <div class="student-kicker">SKSU Synk Student Access</div>
          <h1 class="student-title">Student Sign-In</h1>
          <p class="student-subtitle">
            Use your institutional Google account to open the student portal for prospectus viewing
            and current class program browsing.
          </p>

          <div class="student-info-note">
            <strong>Access rule:</strong> sign in with your verified <code>@sksu.edu.ph</code>
            account, and the same email must already be encoded in the student directory.
          </div>

          <?php if ($googleLoginEnabled && !$googleReady): ?>
            <div class="student-warning-note">
              Google sign-in is enabled but the credentials are not fully configured yet.
            </div>
          <?php elseif (!$googleLoginEnabled): ?>
            <div class="student-warning-note">
              Google sign-in is currently disabled in the authentication settings.
            </div>
          <?php endif; ?>

          <div class="student-login-actions">
            <a
              href="../backend/auth_google_student_start.php"
              id="studentGoogleLogin"
              class="btn student-google-btn<?php echo (!$googleLoginEnabled || !$googleReady) ? ' disabled' : ''; ?>"
              aria-disabled="<?php echo ($googleLoginEnabled && $googleReady) ? 'false' : 'true'; ?>"
            >
              <span class="d-flex align-items-center justify-content-center gap-2">
                <span class="student-google-mark" aria-hidden="true">G</span>
                Continue with SKSU Google
              </span>
            </a>

            <div class="student-secondary-link">
              Need administrator or scheduler access?
              <a href="../index.php">Open the main portal</a>
            </div>
          </div>

          <div class="student-footer">
            <div class="student-footer-note">
              This student portal is read-only and focused on current schedule browsing and
              curriculum review.
            </div>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
      (function () {
        const authStatus = <?php echo json_encode($authStatus, JSON_UNESCAPED_SLASHES); ?>;
        const googleReady = <?php echo ($googleLoginEnabled && $googleReady) ? 'true' : 'false'; ?>;
        const loginButton = document.getElementById("studentGoogleLogin");

        const authStatusMessages = {
          google_unavailable: {
            icon: "warning",
            title: "Google Sign-In Not Ready",
            text: "Google credentials are not configured yet."
          },
          google_state_invalid: {
            icon: "error",
            title: "Login Expired",
            text: "Please start the student sign-in again."
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
            text: "The Google account did not return a verified SKSU email."
          },
          google_email_mismatch: {
            icon: "error",
            title: "Identity Check Failed",
            text: "The Google identity details did not match the returned profile."
          },
          email_domain_denied: {
            icon: "warning",
            title: "Email Not Allowed",
            text: "Only verified @sksu.edu.ph email addresses can sign in to the student portal."
          },
          student_directory_access_denied: {
            icon: "warning",
            title: "Student Record Not Found",
            text: "Your SKSU email passed Google sign-in, but it is not registered yet in the student directory."
          }
        };

        if (authStatus && authStatusMessages[authStatus]) {
          const message = authStatusMessages[authStatus];
          Swal.fire({
            icon: message.icon,
            title: message.title,
            text: message.text,
            width: "360px",
            padding: "1.5rem"
          });

          if (window.history && window.history.replaceState) {
            window.history.replaceState({}, document.title, window.location.pathname);
          }
        }

        if (loginButton) {
          loginButton.addEventListener("click", function (event) {
            if (googleReady) {
              return;
            }

            event.preventDefault();
            Swal.fire({
              icon: "info",
              title: "Google Sign-In Pending",
              text: "Google credentials still need to be configured for student access.",
              width: "360px",
              padding: "1.5rem"
            });
          });
        }
      })();
    </script>
  </body>
</html>
