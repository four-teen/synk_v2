<?php

// Copy this file to auth_config.local.php and configure Google OAuth locally.
// Keep auth_config.local.php out of Git. Blank credentials preserve email/password fallback.
return [
    'mode' => 'hybrid',
    'allowed_domain' => 'sksu.edu.ph',
    // Public Management login heading; authenticated pages use the assigned college.
    'management_login_title' => 'CCS Management.',
    'google_client_id' => '',
    'google_client_secret' => '',
    'google_redirect_uri' => '',
    'google_prompt' => 'select_account',
];
