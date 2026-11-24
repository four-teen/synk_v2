<?php
require_once __DIR__ . '/student_profile_image_helper.php';

const SYNK_MANAGEMENT_STUDENT_AVATAR_SIZE = 160;

function synk_management_student_avatar_source(int $studentId): ?array
{
    $path = synk_student_profile_image_path($studentId);
    $stat = @stat($path);
    if (!$stat || !$stat['size'] || !is_file($path)) return null;

    return [
        'path' => $path,
        'version' => SYNK_MANAGEMENT_STUDENT_AVATAR_SIZE . '-' . $stat['mtime'] . '-' . $stat['size'],
    ];
}

function synk_management_student_avatar_url(int $studentId): string
{
    $source = synk_management_student_avatar_source($studentId);
    if (!$source) return '../student/profile-placeholder.svg';
    if (!function_exists('imagecreatefromjpeg')) {
        return '../uploads/student-profile/' . basename($source['path']) . '?v=' . $source['version'];
    }
    return 'student-avatar.php?' . http_build_query(['id' => $studentId, 'v' => $source['version']]);
}
