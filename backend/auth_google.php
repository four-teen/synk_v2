<?php

require_once __DIR__ . '/auth_config.php';
require_once __DIR__ . '/auth_useraccount.php';

function synk_auth_base_url(): string
{
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $isHttps ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $basePath = preg_replace('#/backend/[^/]+$#', '', $scriptName);

    return rtrim($scheme . '://' . $host . $basePath, '/');
}

function synk_google_redirect_uri(array $settings): string
{
    $configured = trim((string)($settings['google_redirect_uri'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }

    return synk_auth_base_url() . '/backend/auth_google_callback.php';
}

function synk_google_auth_url(array $settings, string $state, string $nonce): string
{
    $params = [
        'client_id' => $settings['google_client_id'],
        'redirect_uri' => synk_google_redirect_uri($settings),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'prompt' => $settings['google_prompt'] ?? 'select_account',
        'state' => $state,
        'nonce' => $nonce,
        'hd' => $settings['allowed_domain'] ?? 'sksu.edu.ph',
    ];

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

function synk_base64url_decode(string $value): string
{
    $remainder = strlen($value) % 4;
    if ($remainder > 0) {
        $value .= str_repeat('=', 4 - $remainder);
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return $decoded === false ? '' : $decoded;
}

function synk_google_decode_id_token(string $idToken): array
{
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        return [];
    }

    $payload = synk_base64url_decode($parts[1]);
    if ($payload === '') {
        return [];
    }

    $claims = json_decode($payload, true);
    return is_array($claims) ? $claims : [];
}

function synk_google_validate_id_token_claims(array $settings, string $idToken, array $claims, string $expectedNonce): ?string
{
    if (empty($claims)) {
        return 'google_id_token_invalid';
    }

    if (!synk_google_verify_id_token_signature($idToken)) {
        return 'google_id_token_invalid';
    }

    $issuer = trim((string)($claims['iss'] ?? ''));
    if (!in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true)) {
        return 'google_id_token_invalid';
    }

    $audience = $claims['aud'] ?? '';
    $clientId = trim((string)($settings['google_client_id'] ?? ''));
    $audienceMatch = false;

    if (is_array($audience)) {
        $audienceMatch = in_array($clientId, array_map('strval', $audience), true);
    } else {
        $audienceMatch = trim((string)$audience) === $clientId;
    }

    if (!$audienceMatch) {
        return 'google_id_token_invalid';
    }

    $expiresAt = (int)($claims['exp'] ?? 0);
    if ($expiresAt <= time()) {
        return 'google_id_token_invalid';
    }

    $nonce = trim((string)($claims['nonce'] ?? ''));
    if ($expectedNonce === '' || $nonce === '' || !hash_equals($expectedNonce, $nonce)) {
        return 'google_nonce_invalid';
    }

    return null;
}

function synk_http_post_form(string $url, array $formData): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($formData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => $response !== false && $httpCode >= 200 && $httpCode < 300,
            'status' => $httpCode,
            'body' => $response === false ? '' : $response,
            'error' => $error,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($formData),
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $status = isset($matches[1]) ? (int)$matches[1] : 0;

    return [
        'ok' => $response !== false && $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $response === false ? '' : $response,
        'error' => $response === false ? 'HTTP request failed.' : '',
    ];
}

function synk_http_get_json(string $url, array $headers = []): array
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => $response !== false && $httpCode >= 200 && $httpCode < 300,
            'status' => $httpCode,
            'body' => $response === false ? '' : $response,
            'error' => $error,
        ];
    }

    $headerText = '';
    if (!empty($headers)) {
        $headerText = implode("\r\n", $headers) . "\r\n";
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => $headerText,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    $statusLine = $http_response_header[0] ?? '';
    preg_match('/\s(\d{3})\s/', $statusLine, $matches);
    $status = isset($matches[1]) ? (int)$matches[1] : 0;

    return [
        'ok' => $response !== false && $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $response === false ? '' : $response,
        'error' => $response === false ? 'HTTP request failed.' : '',
    ];
}

function synk_google_verify_id_token_signature(string $idToken): bool
{
    if (!function_exists('openssl_verify')) {
        return false;
    }

    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        return false;
    }

    $header = json_decode(synk_base64url_decode($parts[0]), true);
    if (!is_array($header)) {
        return false;
    }

    $algorithm = trim((string)($header['alg'] ?? ''));
    $keyId = trim((string)($header['kid'] ?? ''));
    if ($algorithm !== 'RS256' || $keyId === '') {
        return false;
    }

    $response = synk_http_get_json('https://www.googleapis.com/oauth2/v1/certs');
    if (!$response['ok']) {
        return false;
    }

    $certificates = json_decode($response['body'] ?? '', true);
    if (!is_array($certificates)) {
        return false;
    }

    $certificate = $certificates[$keyId] ?? '';
    if (!is_string($certificate) || trim($certificate) === '') {
        return false;
    }

    $signature = synk_base64url_decode($parts[2]);
    if ($signature === '') {
        return false;
    }

    $verification = openssl_verify($parts[0] . '.' . $parts[1], $signature, $certificate, OPENSSL_ALGO_SHA256);
    return $verification === 1;
}

function synk_google_exchange_code(array $settings, string $code): array
{
    $response = synk_http_post_form(
        'https://oauth2.googleapis.com/token',
        [
            'code' => $code,
            'client_id' => $settings['google_client_id'],
            'client_secret' => $settings['google_client_secret'],
            'redirect_uri' => synk_google_redirect_uri($settings),
            'grant_type' => 'authorization_code',
        ]
    );

    $data = json_decode($response['body'] ?? '', true);
    if (!is_array($data)) {
        $data = [];
    }

    $response['json'] = $data;
    return $response;
}

function synk_google_fetch_userinfo(string $accessToken): array
{
    $response = synk_http_get_json(
        'https://openidconnect.googleapis.com/v1/userinfo',
        ['Authorization: Bearer ' . $accessToken]
    );

    $data = json_decode($response['body'] ?? '', true);
    if (!is_array($data)) {
        $data = [];
    }

    $response['json'] = $data;
    return $response;
}

function synk_auth_status_redirect(string $status, string $targetPath = '../index.php'): never
{
    $separator = strpos($targetPath, '?') === false ? '?' : '&';
    header('Location: ' . $targetPath . $separator . 'auth_status=' . urlencode($status));
    exit;
}
