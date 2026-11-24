<?php
session_start();
require_once __DIR__ . '/auth.php';
synk_hris_headers();
synk_hris_require_account();
header('Location: ./', true, 302);
exit;
