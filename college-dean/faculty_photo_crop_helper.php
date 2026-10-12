<?php
// Compatibility entry points for the Dean's existing upload flow.
require_once __DIR__ . '/../backend/faculty_profile_crop_helper.php';

function synk_dean_profile_crop_rectangle($json, int $width, int $height): ?array
{
    return synk_faculty_profile_crop_rectangle($json, $width, $height);
}

function synk_dean_profile_crop_staged_image(string $path, $json): string
{
    return synk_faculty_profile_crop_staged_image($path, $json);
}

function synk_dean_profile_stage_image(?array $upload, int $userId, int $facultyId, $cropJson): ?string
{
    return synk_faculty_profile_stage_cropped_image($upload, $userId, $facultyId, $cropJson);
}
