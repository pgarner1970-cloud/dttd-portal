<?php
/**
 * Public-safe configuration loader.
 *
 * Do not put live passwords or API secrets in this file. This file is intended
 * to be committed to GitHub.
 *
 * The live/private config should be created outside the web root on 20i, for
 * example:
 *
 *   /home/sites/YOUR_SITE_ID/dttd-private/config.local.php
 *
 * You may override the path per environment by setting DTTD_PRIVATE_CONFIG_PATH
 * in the hosting environment.
 */

$privateConfigCandidates = [];

if (getenv('DTTD_PRIVATE_CONFIG_PATH')) {
    $privateConfigCandidates[] = (string)getenv('DTTD_PRIVATE_CONFIG_PATH');
}

// Default expected 20i layout:
//   /home/sites/.../dttd-portal/includes/config.php
//   /home/sites/.../dttd-private/config.local.php
$privateConfigCandidates[] = dirname(__DIR__, 2) . '/dttd-private/config.local.php';

// Local development fallback, ignored by Git.
$privateConfigCandidates[] = __DIR__ . '/config.local.php';

$loadedPrivateConfig = false;

foreach ($privateConfigCandidates as $privateConfig) {
    if ($privateConfig && is_file($privateConfig)) {
        require $privateConfig;
        $loadedPrivateConfig = true;
        break;
    }
}

if (!$loadedPrivateConfig) {
    http_response_code(500);
    exit('Private configuration file missing.');
}

$requiredConstants = [
    'DB_HOST',
    'DB_NAME',
    'DB_USER',
    'DB_PASS',
];

foreach ($requiredConstants as $constant) {
    if (!defined($constant) || constant($constant) === '') {
        http_response_code(500);
        exit('Required private configuration value missing: ' . $constant);
    }
}

if (!defined('SITE_NAME')) {
    define('SITE_NAME', 'Dance Thru The Decades Events');
}

if (!defined('FACEBOOK_URL')) {
    define('FACEBOOK_URL', 'https://www.facebook.com/profile.php?id=61579454050951');
}

if (!defined('DEFAULT_QUEUE_VISIBILITY')) {
    define('DEFAULT_QUEUE_VISIBILITY', 'venue');
}

// Used for signed cookies and other server-side signatures. Set this in the
// private config to a long random string.
if (!defined('APP_SECRET')) {
    if (defined('ADMIN_PASSWORD_HASH')) {
        define('APP_SECRET', hash('sha256', (string)ADMIN_PASSWORD_HASH . '|' . (string)DB_NAME));
    } elseif (defined('ADMIN_PASSWORD')) {
        // Transitional fallback only. Replace ADMIN_PASSWORD with
        // ADMIN_PASSWORD_HASH and APP_SECRET in private config.
        define('APP_SECRET', hash('sha256', (string)ADMIN_PASSWORD . '|' . (string)DB_NAME));
    } else {
        define('APP_SECRET', hash('sha256', __DIR__ . '|' . (string)DB_NAME));
    }
}
