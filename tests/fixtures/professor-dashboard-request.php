<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
session_save_path(dirname(__DIR__, 2) . '/.tmp');
session_id('dashboardqa' . bin2hex(random_bytes(8)));
session_start();
$_SESSION = ($argv[2] ?? 'none') === 'none' ? [] : ['user_id' => 1, 'role' => $argv[2]];
$fixtureSession = session_id();
session_write_close();
$_SERVER['REQUEST_METHOD'] = $argv[1];
parse_str($argv[3] ?? '', $_GET);
ob_start();
require __DIR__ . '/../../professor/dashboard-research.php';
$body = json_decode(ob_get_clean(), true);
$status = http_response_code();
session_id($fixtureSession);
session_start(); session_destroy();
echo json_encode(['status' => $status, 'body' => $body]);
