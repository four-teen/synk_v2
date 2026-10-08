<?php
session_start();
ob_start();

include '../../backend/db.php';
require_once '../../backend/student_management_helper.php';

synk_student_management_require_admin();

$studentManagementPageError = '';

try {
    synk_student_management_ensure_schema($conn);
} catch (Throwable $e) {
    $studentManagementPageError = $e->getMessage();
}

function student_management_upload_error_message(int $errorCode): string
{
    $map = [
        UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the server upload limit.',
        UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the allowed form size.',
        UPLOAD_ERR_PARTIAL => 'The file upload was interrupted. Please try again.',
        UPLOAD_ERR_NO_FILE => 'Select an XLSX file to upload.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload folder.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded file.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
    ];

    return $map[$errorCode] ?? 'Unable to upload the selected file.';
}

function student_management_format_datetime(string $value): string
{
    if ($value === '') {
        return 'No uploads yet';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('F j, Y g:i A', $timestamp);
}

function student_management_push_flash(string $status, string $message, string $details = ''): void
{
    $_SESSION['student_management_flash'] = [
        'status' => $status,
        'message' => $message,
        'details' => $details,
    ];
}

function student_management_pull_flash(): ?array
{
    $flash = $_SESSION['student_management_flash'] ?? null;
    unset($_SESSION['student_management_flash']);

    return is_array($flash) ? $flash : null;
}

function student_management_redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function student_management_build_page_url(string $basePath, int $pageNumber): string
{
    $query = $_GET;

    if ($pageNumber <= 1) {
        unset($query['page']);
    } else {
        $query['page'] = $pageNumber;
    }

    $queryString = http_build_query($query);
    return $basePath . ($queryString !== '' ? ('?' . $queryString) : '');
}
