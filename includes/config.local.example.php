<?php
/**
 * Example private config for DTTD.
 *
 * Copy this file to a private location outside the web root, for example:
 *
 *   /home/sites/YOUR_SITE_ID/dttd-private/config.local.php
 *
 * Do not commit the real private config to GitHub.
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

define('SITE_NAME', 'Dance Thru The Decades Events');
define('FACEBOOK_URL', 'https://www.facebook.com/profile.php?id=61579454050951');
define('DEFAULT_QUEUE_VISIBILITY', 'venue');

// Generate with:
// php -r "echo password_hash('YOUR_NEW_ADMIN_PASSWORD', PASSWORD_DEFAULT) . PHP_EOL;"
define('ADMIN_PASSWORD_HASH', '$2y$10$replace_this_with_a_real_password_hash');

// Generate with:
// php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
define('APP_SECRET', 'replace_this_with_a_long_random_secret');

// Optional: move Spotify client details here later if you decide to remove them
// from the database/settings UI.
// define('SPOTIFY_CLIENT_ID', '');
// define('SPOTIFY_CLIENT_SECRET', '');
