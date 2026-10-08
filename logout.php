<?php
session_start();

require_once __DIR__ . '/backend/auth_useraccount.php';

$redirectTarget = ((string)($_SESSION['role'] ?? '') === 'student')
    ? 'student/login.php'
    : 'index.php';

synk_logout_session();

header('Location: ' . $redirectTarget);
exit;
