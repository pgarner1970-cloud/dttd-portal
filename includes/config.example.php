<?php
// Copy this file to includes/config.php and add your 20i MySQL details.

define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');
define('DB_USER', 'your_database_user');
define('DB_PASS', 'your_database_password');

// Change this before going live.
define('ADMIN_PASSWORD', 'change-this-password');

// Public site settings.
define('SITE_NAME', 'Dance Thru the Decades Events');
define('FACEBOOK_URL', 'https://www.facebook.com/profile.php?id=61579454050951');

// Request queue visibility:
// venue = venue/event can decide in admin later
// public = guests can see queue
// private = only admin can see queue
define('DEFAULT_QUEUE_VISIBILITY', 'venue');
?>
