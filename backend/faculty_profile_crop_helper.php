<?php
// Photo crop processing for opted-in faculty profile editors. The shared upload validator and save transaction stay in use.
require_once __DIR__ . '/faculty_profile_image_helper.php';

function synk_faculty_profile_crop_rectangle($json, int $width, int $height): ?array
{
    if ($json === '') return null;
    $error = 'The photo crop is invalid. Choose the image again and adjust its crop.';
    if (!is_string($json) || strlen($json) > 512) throw new InvalidArgumentException($error);
    $crop = json_decode($json, true);
    if (!is_array($crop)) throw new InvalidArgumentException($error);
    foreach (['x', 'y', 'width', 'height'] as $key) {
        if (!isset($crop[$key]) || (!is_int($crop[$key]) && !is_float($crop[$key]))
            || !is_finite((float)$crop[$key]) || $crop[$key] < 0 || $crop[$key] > 1) {
            throw new InvalidArgumentException($error);
        }
    }
    if ($crop['width'] <= 0 || $crop['height'] <= 0
        || $crop['x'] + $crop['width'] > 1.000001 || $crop['y'] + $crop['height'] > 1.000001) {
        throw new InvalidArgumentException($error);
    }
    $pixelWidth = $crop['width'] * $width;
    $pixelHeight = $crop['height'] * $height;
    // A one-pixel tolerance accounts for rounding during the shared 1,200px resize.
    if ($pixelWidth < 1 || $pixelHeight < 1 || abs($pixelWidth - $pixelHeight) > 2) {
        throw new InvalidArgumentException($error);
    }
    $side = max(1, (int)round(min($pixelWidth, $pixelHeight)));
    return [
        'x' => min($width - $side, (int)round($crop['x'] * $width)),
        'y' => min($height - $side, (int)round($crop['y'] * $height)),
        'size' => $side,
    ];
}

function synk_faculty_profile_crop_staged_image(string $path, $json): string
{
    $source = null;
    $image = null;
    $cropped = null;
    try {
        $info = getimagesize($path);
        if (!$info) throw new RuntimeException('The staged profile image could not be read.');
        $crop = synk_faculty_profile_crop_rectangle($json, $info[0], $info[1]);
        if ($crop === null) return $path;
        $source = imagecreatefromjpeg($path);
        if (!$source) throw new RuntimeException('The staged profile image could not be read.');
        $size = min(600, $crop['size']);
        $image = imagecreatetruecolor($size, $size);
        if (!$image || !imagecopyresampled($image, $source, 0, 0, $crop['x'], $crop['y'], $size, $size, $crop['size'], $crop['size'])) {
            throw new RuntimeException('The profile photo crop could not be prepared.');
        }
        $cropped = tempnam(dirname($path), '.profile-upload-');
        if ($cropped === false || !imagejpeg($image, $cropped, 88)) {
            throw new RuntimeException('The profile photo crop could not be prepared.');
        }
        if (!unlink($path)) throw new RuntimeException('The staged profile image could not be removed.');
        return $cropped;
    } catch (Throwable $exception) {
        foreach ([$path, $cropped] as $temporary) {
            if (is_string($temporary) && is_file($temporary)) unlink($temporary);
        }
        throw $exception;
    } finally {
        if ($source) imagedestroy($source);
        if ($image) imagedestroy($image);
    }
}

function synk_faculty_profile_stage_cropped_image(?array $upload, int $userId, int $facultyId, $cropJson): ?string
{
    $staged = synk_faculty_profile_stage_image($upload, $userId, $facultyId);
    if ($staged === null) {
        if ($cropJson !== '') throw new InvalidArgumentException('Choose a profile image before applying a crop.');
        return null;
    }
    return synk_faculty_profile_crop_staged_image($staged, $cropJson);
}
