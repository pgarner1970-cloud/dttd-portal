<?php
/**
 * Admin auth cookie fallback.
 *
 * The DJ portal primarily uses PHP sessions, but some hosting/proxy setups can
 * lose the session cookie after login. This signed, short-lived cookie gives a
 * safe fallback so a successful login does not loop straight back to login.php.
 */

function dttd_admin_cookie_name() {
    return 'dttd_admin_auth';
}

function dttd_admin_cookie_secret() {
    if (defined('APP_SECRET') && APP_SECRET !== '') {
        return hash('sha256', (string)APP_SECRET . '|dttd-admin-auth-v2');
    }

    // Transitional fallback for older private configs. Prefer APP_SECRET.
    $parts = [
        defined('ADMIN_PASSWORD_HASH') ? (string)ADMIN_PASSWORD_HASH : '',
        defined('ADMIN_PASSWORD') ? (string)ADMIN_PASSWORD : 'changeme',
        defined('DB_NAME') ? (string)DB_NAME : 'dttd',
        __DIR__,
        'dttd-admin-auth-v2',
    ];

    return hash('sha256', implode('|', $parts));
}

function dttd_admin_is_https() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }

    return false;
}

function dttd_admin_cookie_options($expires) {
    return [
        'expires' => (int)$expires,
        'path' => '/',
        'secure' => dttd_admin_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function dttd_admin_set_auth_cookie() {
    $expires = time() + (12 * 60 * 60);
    $payload = 'v1|' . $expires;
    $signature = hash_hmac('sha256', $payload, dttd_admin_cookie_secret());
    $value = base64_encode($payload . '|' . $signature);

    setcookie(dttd_admin_cookie_name(), $value, dttd_admin_cookie_options($expires));
}

function dttd_admin_clear_auth_cookie() {
    setcookie(dttd_admin_cookie_name(), '', dttd_admin_cookie_options(time() - 3600));
}

function dttd_admin_auth_cookie_valid() {
    $raw = $_COOKIE[dttd_admin_cookie_name()] ?? '';
    if (!$raw) {
        return false;
    }

    $decoded = base64_decode((string)$raw, true);
    if (!$decoded) {
        return false;
    }

    $parts = explode('|', $decoded);
    if (count($parts) !== 3 || $parts[0] !== 'v1') {
        return false;
    }

    $expires = (int)$parts[1];
    if ($expires < time()) {
        return false;
    }

    $payload = $parts[0] . '|' . $parts[1];
    $expected = hash_hmac('sha256', $payload, dttd_admin_cookie_secret());

    return hash_equals($expected, (string)$parts[2]);
}
