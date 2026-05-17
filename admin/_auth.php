<?php
ob_start();
session_start();
require_once __DIR__ . '/../includes/db.php';

if (isset($_POST['password'])) {
    if ($_POST['password'] === ADMIN_PASSWORD) {
        $_SESSION['admin'] = true;
    } else {
        $login_error = 'Incorrect password.';
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /admin/');
    exit;
}

if (empty($_SESSION['admin'])):
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DJ Portal Login</title>
<link rel="stylesheet" href="/assets/admin-touch.css">
</head>
<body class="admin-body">
<main class="touch-login">
  <div class="touch-panel">
    <div class="touch-panel-pad">
      <h1 class="login-title">DJ Portal Login</h1>
      <p class="login-subtitle">Dance Thru the Decades Events</p>
      <?php if (!empty($login_error)): ?><p class="status-badge rejected"><?= h($login_error) ?></p><?php endif; ?>
      <form method="post">
        <input class="login-input" type="password" name="password" placeholder="Password" required>
        <button class="touch-btn blue full" type="submit" style="margin-top:14px">Login</button>
      </form>
    </div>
  </div>
</main>
</body>
</html>
<?php exit; endif;

function admin_header($title = 'DJ Portal') {
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
  <div class="touch-brand">
    <span class="touch-brand-mark">♪</span>
    <span>DJ Portal</span>
  </div>
  <div class="touch-clock">
    <strong><?= date('H:i') ?></strong>
    <span><?= date('D, j M') ?></span>
  </div>
  <div class="touch-top-actions">
    <a class="touch-icon-btn" href="/">⌂</a>
    <a class="touch-icon-btn" href="/admin/?logout=1">⏻</a>
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
?>
