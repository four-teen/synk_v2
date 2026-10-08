<?php
session_start();
ob_start();

include '../backend/db.php';
require_once '../backend/academic_term_helper.php';

if (!isset($_SESSION['user_id']) || (string)($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = (string)$_SESSION['csrf_token'];
$currentTerm = synk_fetch_current_academic_term($conn);
$currentTermText = trim((string)($currentTerm['term_text'] ?? 'Current academic term'));
$currentTermTextEscaped = htmlspecialchars($currentTermText, ENT_QUOTES, 'UTF-8');
$pageAlert = null;

function synk_admin_backup_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function synk_admin_backup_disabled_functions(): array
{
    $disabled = trim((string)ini_get('disable_functions'));
    if ($disabled === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $disabled))));
}

function synk_admin_backup_shell_ready(): bool
{
    $disabled = synk_admin_backup_disabled_functions();
    foreach (['proc_open', 'proc_close'] as $requiredFunction) {
        if (!function_exists($requiredFunction) || in_array($requiredFunction, $disabled, true)) {
            return false;
        }
    }

    return true;
}

function synk_admin_backup_candidate_paths(): array
{
    $candidates = [];

    $envPath = trim((string)getenv('SYNK_MYSQLDUMP_PATH'));
    if ($envPath !== '') {
        $candidates[] = $envPath;
    }

    $candidates[] = 'C:\\VertrigoServ\\Mysql\\bin\\mysqldump.exe';
    $candidates[] = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    $candidates[] = 'C:\\wamp64\\bin\\mysql\\mysql8.0.0\\bin\\mysqldump.exe';
    $candidates[] = 'C:\\wamp64\\bin\\mysql\\mysql5.7.0\\bin\\mysqldump.exe';

    $pathValue = trim((string)getenv('PATH'));
    if ($pathValue !== '') {
        foreach (explode(PATH_SEPARATOR, $pathValue) as $pathPart) {
            $pathPart = trim($pathPart, " \t\n\r\0\x0B\"'");
            if ($pathPart === '') {
                continue;
            }

            $candidates[] = rtrim($pathPart, '\\/') . DIRECTORY_SEPARATOR . 'mysqldump.exe';
            $candidates[] = rtrim($pathPart, '\\/') . DIRECTORY_SEPARATOR . 'mysqldump';
        }
    }

    $normalized = [];
    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate === '') {
            continue;
        }

        $key = strtolower($candidate);
        if (!isset($normalized[$key])) {
            $normalized[$key] = $candidate;
        }
    }

    return array_values($normalized);
}

function synk_admin_backup_find_mysqldump_path(): string
{
    foreach (synk_admin_backup_candidate_paths() as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return '';
}

function synk_admin_backup_runtime_dir(): string
{
    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.tmp' . DIRECTORY_SEPARATOR . 'database-backup';

    if (!is_dir($directory)) {
        if (!@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return '';
        }
    }

    return is_writable($directory) ? $directory : '';
}

function synk_admin_backup_parse_host(string $hostValue): array
{
    $hostValue = trim($hostValue);
    if ($hostValue === '') {
        return ['host' => 'localhost', 'port' => 0];
    }

    if (preg_match('/^\[(.+)\]:(\d+)$/', $hostValue, $matches) === 1) {
        return [
            'host' => trim((string)$matches[1]),
            'port' => (int)$matches[2],
        ];
    }

    if (substr_count($hostValue, ':') === 1 && preg_match('/^(.+):(\d+)$/', $hostValue, $matches) === 1) {
        return [
            'host' => trim((string)$matches[1]),
            'port' => (int)$matches[2],
        ];
    }

    return ['host' => $hostValue, 'port' => 0];
}

function synk_admin_backup_safe_filename(string $value): string
{
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '_', $value);
    $value = trim((string)$value, '._-');
    return $value !== '' ? $value : 'backup';
}

function synk_admin_backup_create_download(string $mysqldumpPath, array $config): void
{
    if (!synk_admin_backup_shell_ready()) {
        throw new RuntimeException('Backup tools are disabled in this PHP environment.');
    }

    $runtimeDir = synk_admin_backup_runtime_dir();
    if ($runtimeDir === '') {
        throw new RuntimeException('Backup workspace is not available. Please make sure the application .tmp folder exists and is writable.');
    }

    $hostParts = synk_admin_backup_parse_host((string)($config['host'] ?? 'localhost'));
    $databaseName = trim((string)($config['database'] ?? ''));
    if ($databaseName === '') {
        throw new RuntimeException('Database name is missing from the application configuration.');
    }

    $token = bin2hex(random_bytes(8));
    $dumpPath = $runtimeDir . DIRECTORY_SEPARATOR . 'synk_dump_' . $token . '.sql';
    $configPath = $runtimeDir . DIRECTORY_SEPARATOR . 'synk_cfg_' . $token . '.cnf';

    $configLines = [
        '[client]',
        'host=' . $hostParts['host'],
        'user=' . (string)($config['user'] ?? ''),
        'password=' . (string)($config['password'] ?? ''),
        'default-character-set=utf8mb4',
    ];

    if ((int)$hostParts['port'] > 0) {
        $configLines[] = 'port=' . (int)$hostParts['port'];
    }

    $configWritten = @file_put_contents($configPath, implode(PHP_EOL, $configLines) . PHP_EOL, LOCK_EX);
    if ($configWritten === false) {
        throw new RuntimeException('Unable to prepare the temporary MySQL client configuration.');
    }

    $commandParts = [
        escapeshellarg($mysqldumpPath),
        '--defaults-extra-file=' . escapeshellarg($configPath),
        '--single-transaction',
        '--skip-lock-tables',
        '--routines',
        '--triggers',
        '--events',
        '--hex-blob',
        '--default-character-set=utf8mb4',
        '--databases',
        escapeshellarg($databaseName),
        '--result-file=' . escapeshellarg($dumpPath),
    ];

    $command = implode(' ', $commandParts);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $stderr = '';
    $process = @proc_open(
        $command,
        $descriptors,
        $pipes,
        $runtimeDir,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        @unlink($configPath);
        @unlink($dumpPath);
        throw new RuntimeException('Unable to start mysqldump from the web application.');
    }

    try {
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            stream_get_contents($pipes[1]);
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $stderr = trim((string)stream_get_contents($pipes[2]));
            fclose($pipes[2]);
        }

        $exitCode = proc_close($process);
        $process = null;

        clearstatcache(true, $dumpPath);
        if ($exitCode !== 0) {
            $message = $stderr !== '' ? $stderr : 'mysqldump returned a non-zero exit code.';
            throw new RuntimeException('Backup failed: ' . $message);
        }

        if (!is_file($dumpPath) || (int)filesize($dumpPath) <= 0) {
            throw new RuntimeException('Backup failed because no SQL file was generated.');
        }

        $downloadName = synk_admin_backup_safe_filename($databaseName) . '_backup_' . date('Y-m-d_His') . '.sql';

        $stream = fopen($dumpPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Backup file was created but could not be opened for download.');
        }

        session_write_close();
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Description: File Transfer');
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . (string)filesize($dumpPath));
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        while (!feof($stream)) {
            echo (string)fread($stream, 1048576);
            flush();
        }

        fclose($stream);
        @unlink($configPath);
        @unlink($dumpPath);
        exit;
    } catch (Throwable $exception) {
        if (is_resource($process)) {
            proc_close($process);
        }

        @unlink($configPath);
        @unlink($dumpPath);
        throw $exception;
    }
}

$mysqldumpPath = synk_admin_backup_find_mysqldump_path();
$backupRuntimeDir = synk_admin_backup_runtime_dir();
$backupReady = synk_admin_backup_shell_ready() && $mysqldumpPath !== '' && $backupRuntimeDir !== '';
$backupChecks = [
    [
        'label' => 'Admin session',
        'value' => 'Ready',
        'ok' => true,
    ],
    [
        'label' => 'Database connection',
        'value' => trim((string)($dbase ?? '')) !== '' ? 'Connected' : 'Missing config',
        'ok' => trim((string)($dbase ?? '')) !== '',
    ],
    [
        'label' => 'Backup engine',
        'value' => synk_admin_backup_shell_ready() ? 'mysqldump enabled' : 'Shell functions unavailable',
        'ok' => synk_admin_backup_shell_ready(),
    ],
    [
        'label' => 'mysqldump path',
        'value' => $mysqldumpPath !== '' ? $mysqldumpPath : 'Not found',
        'ok' => $mysqldumpPath !== '',
    ],
    [
        'label' => 'Backup workspace',
        'value' => $backupRuntimeDir !== '' ? $backupRuntimeDir : 'Not writable',
        'ok' => $backupRuntimeDir !== '',
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_backup'])) {
    $postedToken = (string)($_POST['csrf_token'] ?? '');

    if (!hash_equals($csrfToken, $postedToken)) {
        $pageAlert = [
            'type' => 'danger',
            'message' => 'CSRF validation failed. Please refresh the page and try again.',
        ];
    } elseif (!$backupReady) {
        $pageAlert = [
            'type' => 'danger',
            'message' => 'Database backup is not ready on this server. Please confirm that mysqldump is installed and shell access is enabled for PHP.',
        ];
    } else {
        try {
            @set_time_limit(0);
            @ignore_user_abort(true);

            synk_admin_backup_create_download($mysqldumpPath, [
                'host' => (string)($servername ?? 'localhost'),
                'user' => (string)($username ?? ''),
                'password' => (string)($password ?? ''),
                'database' => (string)($dbase ?? ''),
            ]);
        } catch (Throwable $exception) {
            $pageAlert = [
                'type' => 'danger',
                'message' => $exception->getMessage(),
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style layout-menu-fixed"
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
    <title>Database Backup | Synk</title>

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

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
      .backup-hero {
        border: 1px solid #e6eaf1;
        border-radius: 1rem;
        background:
          radial-gradient(circle at top right, rgba(40, 199, 111, 0.16), transparent 34%),
          linear-gradient(135deg, #ffffff 0%, #f7fbf9 100%);
      }

      .backup-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        border-radius: 999px;
        padding: 0.42rem 0.8rem;
        font-size: 0.78rem;
        font-weight: 600;
        background: rgba(255, 255, 255, 0.88);
        border: 1px solid rgba(143, 156, 179, 0.18);
        color: #516177;
      }

      .backup-summary {
        border: 1px solid #eef1f6;
        border-radius: 0.95rem;
        background: #fbfcff;
        padding: 1rem;
        height: 100%;
      }

      .backup-summary-label {
        display: block;
        font-size: 0.73rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #8a94a6;
        margin-bottom: 0.25rem;
      }

      .backup-summary-value {
        color: #364152;
        font-weight: 600;
      }

      .backup-card,
      .backup-info-card {
        border: 1px solid #e8edf5;
        border-radius: 1rem;
        box-shadow: 0 8px 24px rgba(31, 41, 55, 0.04);
      }

      .backup-status-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
        gap: 1rem;
      }

      .backup-status-item {
        border: 1px solid #edf1f6;
        border-radius: 0.9rem;
        background: #fbfcff;
        padding: 0.95rem 1rem;
      }

      .backup-status-label {
        display: block;
        font-size: 0.72rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #8a94a6;
        margin-bottom: 0.3rem;
      }

      .backup-status-value {
        font-weight: 600;
        color: #39475a;
        word-break: break-word;
      }

      .backup-status-value.is-ok {
        color: #1f9254;
      }

      .backup-status-value.is-error {
        color: #cc3a3b;
      }

      .backup-inclusion-list,
      .backup-step-list {
        margin-bottom: 0;
        padding-left: 1rem;
      }

      .backup-step-list li + li,
      .backup-inclusion-list li + li {
        margin-top: 0.45rem;
      }

      .backup-note {
        border-radius: 0.9rem;
        background: #f5f7ff;
        border: 1px solid #e7ebff;
        padding: 0.95rem 1rem;
        color: #57657b;
      }
    </style>
  </head>

  <body>
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <?php include 'sidebar.php'; ?>

        <div class="layout-page">
          <?php include 'navbar.php'; ?>

          <div class="content-wrapper">
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="card backup-hero mb-4">
                <div class="card-body p-4 p-lg-5">
                  <div class="row g-4 align-items-center">
                    <div class="col-lg-8">
                      <div class="d-flex flex-wrap gap-2 mb-3">
                        <span class="backup-chip"><i class="bx bx-data"></i> Database Backup</span>
                        <span class="backup-chip"><i class="bx bx-download"></i> One-click SQL download</span>
                      </div>
                      <h4 class="fw-bold mb-2">Download a full database backup without opening phpMyAdmin</h4>
                      <p class="text-muted mb-3">
                        This page uses the application database connection and runs <strong>mysqldump</strong> from the server.
                        When the backup is ready, your browser downloads the SQL file directly and the temporary server files are removed.
                      </p>
                      <div class="alert alert-success mb-0">
                        Setup records, scheduling data, workload assignments, and other live tables are included in one export file for easier safekeeping.
                      </div>
                    </div>
                    <div class="col-lg-4">
                      <div class="backup-summary">
                        <span class="backup-summary-label">Current Academic Term</span>
                        <div class="backup-summary-value mb-3"><?php echo $currentTermTextEscaped; ?></div>

                        <span class="backup-summary-label">Database</span>
                        <div class="backup-summary-value mb-3"><?php echo synk_admin_backup_h((string)($dbase ?? '')); ?></div>

                        <span class="backup-summary-label">Download Type</span>
                        <div class="backup-summary-value mb-3">SQL dump via mysqldump</div>

                        <span class="backup-summary-label">Storage Behavior</span>
                        <div class="text-muted small">
                          The dump is generated only when requested and is streamed to your browser instead of being kept inside the public application folder.
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <?php if ($pageAlert): ?>
                <div class="alert alert-<?php echo synk_admin_backup_h((string)($pageAlert['type'] ?? 'info')); ?> alert-dismissible fade show" role="alert">
                  <?php echo synk_admin_backup_h((string)($pageAlert['message'] ?? '')); ?>
                  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
              <?php endif; ?>

              <div class="card backup-card mb-4">
                <div class="card-body p-4">
                  <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
                    <div>
                      <h5 class="mb-1">Backup Readiness</h5>
                      <p class="text-muted mb-0">
                        Review the server checks below, then click the download button to create a fresh SQL backup.
                      </p>
                    </div>
                    <span class="badge <?php echo $backupReady ? 'bg-label-success text-success' : 'bg-label-danger text-danger'; ?>">
                      <?php echo $backupReady ? 'Ready to Download' : 'Needs Attention'; ?>
                    </span>
                  </div>

                  <div class="backup-status-grid mb-4">
                    <?php foreach ($backupChecks as $check): ?>
                      <div class="backup-status-item">
                        <span class="backup-status-label"><?php echo synk_admin_backup_h((string)$check['label']); ?></span>
                        <div class="backup-status-value <?php echo !empty($check['ok']) ? 'is-ok' : 'is-error'; ?>">
                          <?php echo synk_admin_backup_h((string)$check['value']); ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <form method="post" class="d-flex flex-column flex-lg-row align-items-lg-center gap-3" id="backupDownloadForm">
                    <input type="hidden" name="csrf_token" value="<?php echo synk_admin_backup_h($csrfToken); ?>">
                    <input type="hidden" name="download_backup" value="1">

                    <button
                      type="submit"
                      class="btn btn-primary btn-lg"
                      id="downloadBackupButton"
                      <?php echo $backupReady ? '' : 'disabled'; ?>
                    >
                      <i class="bx bx-download me-1"></i> Download Database Backup
                    </button>

                    <div class="text-muted small">
                      The downloaded file name will include the database name and the current date and time in the server timezone.
                    </div>
                  </form>
                </div>
              </div>

              <div class="row g-4">
                <div class="col-lg-6">
                  <div class="card backup-info-card h-100">
                    <div class="card-header">
                      <h5 class="mb-1">How This Works</h5>
                      <small class="text-muted">A simple in-app workflow for safer routine backups.</small>
                    </div>
                    <div class="card-body">
                      <ol class="backup-step-list">
                        <li>Open this page from the administrator module.</li>
                        <li>Click <strong>Download Database Backup</strong>.</li>
                        <li>The server runs <strong>mysqldump</strong> using the current app database configuration.</li>
                        <li>Your browser downloads a fresh SQL file immediately.</li>
                        <li>Temporary dump files are removed from the server after the response is sent.</li>
                      </ol>
                    </div>
                  </div>
                </div>

                <div class="col-lg-6">
                  <div class="card backup-info-card h-100">
                    <div class="card-header">
                      <h5 class="mb-1">What Is Included</h5>
                      <small class="text-muted">The backup is meant to be practical for full database recovery work.</small>
                    </div>
                    <div class="card-body">
                      <ul class="backup-inclusion-list">
                        <li>Table structure and table data for <strong><?php echo synk_admin_backup_h((string)($dbase ?? '')); ?></strong>.</li>
                        <li>Triggers, routines, and events when they exist in the database.</li>
                        <li>Binary-safe export handling through <code>--hex-blob</code>.</li>
                        <li>Single-transaction dumping to reduce locking pressure during export.</li>
                      </ul>

                      <div class="backup-note mt-4">
                        Restore is not automated on this page. The goal here is to make regular backup download easy from the application itself without manual database login each time.
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <?php include '../footer.php'; ?>

            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>
    </div>

    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>

    <script>
      document.addEventListener('DOMContentLoaded', function () {
        var backupForm = document.getElementById('backupDownloadForm');
        var backupButton = document.getElementById('downloadBackupButton');

        if (!backupForm || !backupButton || backupButton.disabled) {
          return;
        }

        backupForm.addEventListener('submit', function () {
          backupButton.disabled = true;
          backupButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Preparing Backup...';
        });
      });
    </script>
  </body>
</html>
