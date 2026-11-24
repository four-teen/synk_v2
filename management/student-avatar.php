<?php
require_once __DIR__ . '/../backend/management_student_avatar_helper.php';

$studentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$source = $studentId ? synk_management_student_avatar_source($studentId) : null;
header('Cache-Control: no-store');
if (!$source || !function_exists('imagecreatefromjpeg')) {
    http_response_code(404);
    exit;
}

$etag = '"' . $studentId . '-' . $source['version'] . '"';
$notModified = ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag;
if (!$notModified) {
    $original = @imagecreatefromjpeg($source['path']);
    if (!$original) {
        http_response_code(404);
        exit;
    }

    $width = imagesx($original);
    $height = imagesy($original);
    $crop = min($width, $height);
    $size = min($crop, SYNK_MANAGEMENT_STUDENT_AVATAR_SIZE);
    $avatar = imagecreatetruecolor($size, $size);
    if (!$avatar || !imagecopyresampled($avatar, $original, 0, 0, intdiv($width - $crop, 2), intdiv($height - $crop, 2), $size, $size, $crop, $crop)) {
        http_response_code(503);
        exit;
    }
}

$versioned = ($_GET['v'] ?? null) === $source['version'];
header('Cache-Control: private, ' . ($versioned ? 'max-age=86400' : 'no-cache'));
header('ETag: ' . $etag);
if ($notModified) {
    http_response_code(304);
    exit;
}

header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
imagejpeg($avatar, null, 80);
