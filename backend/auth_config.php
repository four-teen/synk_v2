<?php

function synk_auth_settings(): array
{
    $settings = [
        // Modes:
        // - legacy: current email/password login only
        // - hybrid: Google login when configured, keep legacy fallback
        // - google_only: Google login only
        'mode' => 'hybrid',

        // Restrict Google-authenticated users to this domain.
        'allowed_domain' => 'sksu.edu.ph',

        // Configure Google OAuth in the ignored auth_config.local.php file.
        'google_client_id' => '',
        'google_client_secret' => '',
        'google_redirect_uri' => '',
        'google_prompt' => 'select_account',
    ];

    $overridePath = __DIR__ . '/auth_config.local.php';
    if (is_file($overridePath)) {
        $overrides = require $overridePath;
        if (is_array($overrides)) {
            $settings = array_replace($settings, $overrides);
        }
    }

    return $settings;
}

function synk_google_auth_ready(array $settings): bool
{
    return trim((string)($settings['google_client_id'] ?? '')) !== ''
        && trim((string)($settings['google_client_secret'] ?? '')) !== '';
}

function synk_google_login_enabled(array $settings): bool
{
    return in_array(($settings['mode'] ?? 'legacy'), ['hybrid', 'google_only'], true);
}

function synk_legacy_login_enabled(array $settings): bool
{
    return ($settings['mode'] ?? 'legacy') !== 'google_only';
}
