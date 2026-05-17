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
<link rel="stylesheet" href="/assets/admin.css">
</head>
<body class="admin-body">
<main class="admin-login">
  <div class="admin-card">
    <div class="admin-card-header">
      <div>
        <h1 class="admin-title">DJ Portal Login</h1>
        <p class="admin-subtitle">Dance Thru the Decades Events</p>
      </div>
    </div>
    <div class="admin-card-body">
      <?php if (!empty($login_error)): ?><p class="status-chip status-rejected"><?= h($login_error) ?></p><?php endif; ?>
      <form method="post">
        <div class="field">
          <label>Password</label>
          <input type="password" name="password" required>
        </div>
        <button class="admin-btn" type="submit">Login</button>
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
<link rel="stylesheet" href="/assets/admin.css">
</head>
<body class="admin-body">
<header class="admin-topbar">
  <div class="admin-brand">
    <span>DJ Portal</span>
  </div>
  <nav class="admin-nav">
    <a href="/">Portal</a>
    <a href="/admin/">Requests</a>
    <a href="/admin/events.php">Events</a>
    <a href="/admin/?logout=1">Logout</a>
  </nav>
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
