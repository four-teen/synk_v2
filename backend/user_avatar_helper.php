<?php

require_once __DIR__ . '/faculty_profile_image_helper.php';

function synk_default_user_avatar_path(): string
{
    return '../assets/img/avatars/1.png';
}

function synk_is_safe_remote_avatar_url(string $url): bool
{
    if ($url === '') {
        return false;
    }

    $validated = filter_var($url, FILTER_VALIDATE_URL);
    if ($validated === false) {
        return false;
    }

    $scheme = strtolower((string)parse_url($validated, PHP_URL_SCHEME));
    return in_array($scheme, ['https', 'http'], true);
}

function synk_build_gravatar_url(string $email, int $size = 80): string
{
    $normalizedEmail = strtolower(trim($email));
    if ($normalizedEmail === '') {
        return synk_default_user_avatar_path();
    }

    $safeSize = max(40, min(240, $size));
    $emailHash = md5($normalizedEmail);

    return 'https://www.gravatar.com/avatar/' . $emailHash . '?s=' . $safeSize . '&d=mp';
}

function synk_resolve_user_avatar_url(?string $email, ?string $sessionAvatarUrl = null, int $size = 80): string
{
    $profileImage = synk_faculty_profile_image_url((int)($_SESSION['user_id'] ?? 0));
    if ($profileImage !== '') {
        return $profileImage;
    }
    $sessionAvatarUrl = trim((string)$sessionAvatarUrl);
    if (synk_is_safe_remote_avatar_url($sessionAvatarUrl)) {
        return $sessionAvatarUrl;
    }

    return synk_build_gravatar_url((string)$email, $size);
}

