<?php
session_start();

require_once __DIR__ . '/backend/auth_useraccount.php';

$redirectTarget = ((string)($_SESSION['role'] ?? '') === 'student')
    ? 'student/login.php'
    : 'index.php';

$studentPresence = ($_SESSION['role'] ?? '') === 'student' ? $_SESSION : null;
synk_logout_session();
if ($studentPresence !== null) {
    try {
        require_once __DIR__.'/backend/db.php';
        require_once __DIR__.'/backend/student_presence_helper.php';
        synk_student_presence_end($conn, 'logout', $studentPresence);
    } catch (Throwable $error) { error_log('Student sign-out presence could not be updated.'); }
}

header('Location: ' . $redirectTarget);
exit;
