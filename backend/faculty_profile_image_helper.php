<?php

const SYNK_PROFILE_IMAGE_MAX_BYTES = 5 * 1024 * 1024;

function synk_faculty_profile_image_path(int $userId): string
{
    if ($userId <= 0) {
        throw new InvalidArgumentException('Invalid profile image account.');
    }
    return dirname(__DIR__) . '/uploads/profile/profile_' . $userId . '.jpg';
}

function synk_faculty_record_image_path(int $facultyId): string
{
    if ($facultyId <= 0) throw new InvalidArgumentException('Invalid faculty profile image.');
    return dirname(__DIR__) . '/uploads/profile/faculty_' . $facultyId . '.jpg';
}

function synk_faculty_profile_image_url(int $userId, int $facultyId = 0): string
{
    $path = $userId > 0 ? synk_faculty_profile_image_path($userId) : '';
    if ($path === '' || !is_file($path)) {
        $path = $facultyId > 0 ? synk_faculty_record_image_path($facultyId) : '';
    }
    if ($path === '' || !is_file($path)) return '';
    // Resolve the app's URL prefix, including portals in nested directories.
    $appRoot = str_replace('\\', '/', dirname(__DIR__));
    $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $scriptUrl = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $prefix = '..';
    if (stripos($scriptPath, $appRoot . '/') === 0 && $scriptUrl !== '') {
        $relativeScript = substr($scriptPath, strlen($appRoot));
        if (substr($scriptUrl, -strlen($relativeScript)) === $relativeScript) {
            $prefix = substr($scriptUrl, 0, -strlen($relativeScript));
        }
    }
    return $prefix . '/uploads/profile/' . basename($path) . '?v=' . substr(hash_file('sha256', $path), 0, 16);
}

// Validate an actual HTTP upload before decoding. Never trust its name or MIME field.
function synk_faculty_profile_image_info(string $path): array
{
    $size = is_file($path) ? filesize($path) : 0;
    if ($size <= 0 || $size > SYNK_PROFILE_IMAGE_MAX_BYTES) {
        throw new InvalidArgumentException('Choose a profile image no larger than 5 MB.');
    }
    $info = @getimagesize($path);
    $types = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/webp' => IMAGETYPE_WEBP];
    if (!$info || !in_array($info[2], $types, true)) {
        throw new InvalidArgumentException('Choose a valid JPG, PNG, or WebP profile image.');
    }
    // Fileinfo is optional on the web server. Header inspection is followed by
    // GD decoding and JPEG re-encoding before any uploaded bytes are published.
    if (class_exists('finfo')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!isset($types[$mime]) || $info[2] !== $types[$mime]) {
            throw new InvalidArgumentException('Choose a valid JPG, PNG, or WebP profile image.');
        }
    }
    if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
        throw new RuntimeException('The server image-processing library is unavailable.');
    }
    if ($info[0] > 6000 || $info[1] > 6000 || $info[0] * $info[1] > 16000000) {
        throw new InvalidArgumentException('Choose an image up to 6,000 pixels per side and 16 megapixels.');
    }
    return $info;
}

function synk_faculty_profile_stage_image(?array $upload, int $userId, int $facultyId = 0): ?string
{
    if ($upload === null) {
        return null;
    }
    $error = $upload['error'] ?? null;
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new InvalidArgumentException('Choose a profile image no larger than 5 MB.');
    }
    if ($error !== UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) {
        throw new InvalidArgumentException('The profile image upload failed. Choose the image again and retry.');
    }
    $info = synk_faculty_profile_image_info($upload['tmp_name']);
    // Explicit decoders also support WebP on the site's PHP 7.1 runtime.
    $decoder = [IMAGETYPE_JPEG => 'imagecreatefromjpeg', IMAGETYPE_PNG => 'imagecreatefrompng', IMAGETYPE_WEBP => 'imagecreatefromwebp'][$info[2]];
    if (!function_exists($decoder)) {
        throw new InvalidArgumentException('This image format is unavailable on the server. Choose a JPG or PNG image.');
    }
    $source = @$decoder($upload['tmp_name']);
    if (!$source) {
        throw new InvalidArgumentException('The profile image could not be read. Choose another JPG, PNG, or WebP image.');
    }
    $staged = null;
    $image = null;
    try {
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($upload['tmp_name']);
            $orientation = (int)($exif['Orientation'] ?? 1);
            if (in_array($orientation, [2, 5, 7], true)) {
                imageflip($source, IMG_FLIP_HORIZONTAL);
            } elseif ($orientation === 4) {
                imageflip($source, IMG_FLIP_VERTICAL);
            }
            $rotation = [3 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90][$orientation] ?? 0;
            if ($rotation) {
                $rotated = imagerotate($source, $rotation, 0);
                if (!$rotated) {
                    throw new RuntimeException('Profile image orientation could not be processed.');
                }
                imagedestroy($source);
                $source = $rotated;
            }
        }
        $scale = min(1, 1200 / max(imagesx($source), imagesy($source)));
        $width = max(1, (int)round(imagesx($source) * $scale));
        $height = max(1, (int)round(imagesy($source) * $scale));
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        $directory = dirname($userId > 0 ? synk_faculty_profile_image_path($userId) : synk_faculty_record_image_path($facultyId));
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Profile image storage is unavailable.');
        }
        $staged = tempnam($directory, '.profile-upload-');
        if ($staged === false || !imagejpeg($image, $staged, 88)) {
            throw new RuntimeException('Profile image could not be prepared.');
        }
        return $staged;
    } catch (Throwable $exception) {
        if (is_string($staged) && is_file($staged)) {
            unlink($staged);
        }
        throw $exception;
    } finally {
        imagedestroy($source);
        if ($image) {
            imagedestroy($image);
        }
    }
}

// Called while the profile row is locked by its save transaction. Keep the old
// bytes in memory so a failed database commit can restore the previous image.
function synk_faculty_profile_publish_image(string $staged, int $userId, int $facultyId = 0): array
{
    $path = $userId > 0 ? synk_faculty_profile_image_path($userId) : synk_faculty_record_image_path($facultyId);
    $previous = is_file($path) ? file_get_contents($path) : null;
    if ($previous === false || !rename($staged, $path)) {
        throw new RuntimeException('Profile image could not be replaced.');
    }
    clearstatcache(true, $path);
    return ['path' => $path, 'previous' => $previous];
}

function synk_faculty_profile_restore_image(array $replacement): void
{
    if ($replacement['previous'] === null) {
        if (is_file($replacement['path']) && !unlink($replacement['path'])) {
            throw new RuntimeException('Failed profile image could not be removed.');
        }
        return;
    }
    $temporary = tempnam(dirname($replacement['path']), '.profile-upload-');
    try {
        if ($temporary === false || file_put_contents($temporary, $replacement['previous']) === false || !rename($temporary, $replacement['path'])) {
            throw new RuntimeException('Previous profile image could not be restored.');
        }
    } finally {
        if (is_string($temporary) && is_file($temporary)) {
            unlink($temporary);
        }
        clearstatcache(true, $replacement['path']);
    }
}
