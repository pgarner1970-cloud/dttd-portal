<?php
session_start();

require_once __DIR__ . '/../includes/db.php';

if (!defined('ADMIN_PASSWORD')) {
    define('ADMIN_PASSWORD', 'changeme');
}

if (isset($_GET['logout'])) {
    unset($_SESSION['dttd_admin']);
    header('Location: /admin/login.php');
    exit;
}

if (empty($_SESSION['dttd_admin'])) {
    header('Location: /admin/login.php');
    exit;
}

function admin_current_page() {
    return basename($_SERVER['SCRIPT_NAME']);
}

function admin_nav_active($page) {
    $script = admin_current_page();

    if ($page === 'requests' && in_array($script, ['requests.php', 'index.php'], true)) {
        return 'active';
    }

    if ($page === 'events' && $script === 'events.php') {
        return 'active';
    }

    if ($page === 'settings' && $script === 'event-edit.php') {
        return 'active';
    }

    return '';
}

function admin_header($title = 'DJ Portal') {
    $time = date('H:i');
    $date = date('D, j M');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($title) ?></title>
<link rel="stylesheet" href="/assets/admin-touch.css">
</head>
<body class="admin-body">
<header class="touch-topbar">
  <a class="touch-brand" href="/admin/index.php">
    <span class="touch-brand-mark">♫</span>
    <span>DJ Portal</span>
  </a>

  <div class="touch-clock">
    <strong><?= h($time) ?></strong>
    <span><?= h($date) ?></span>
  </div>

  <div class="touch-top-actions">
    <nav class="header-admin-nav" aria-label="Admin navigation">
      <a class="header-admin-nav-btn <?= admin_nav_active('requests') ?>" href="/admin/requests.php">Requests</a>
      <a class="header-admin-nav-btn <?= admin_nav_active('events') ?>" href="/admin/events.php">Events</a>
      <a class="header-admin-nav-btn <?= admin_nav_active('settings') ?>" href="/admin/events.php">Settings</a>
    </nav>

    <a class="touch-icon-btn" href="/" title="Public site">⌂</a>
    <a class="touch-icon-btn" href="/admin/logout.php" title="Logout">⏻</a>
  </div>
</header>
<?php
}

function admin_footer() {
    ?>
</body>
</html>
<?php
}
