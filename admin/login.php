<?php
session_start();

require_once __DIR__ . '/../includes/db.php';
dttd_no_cache_headers();
require_once __DIR__ . '/_auth_cookie.php';

if (!defined('ADMIN_PASSWORD')) {
    define('ADMIN_PASSWORD', 'changeme');
}

$error = '';

if (!empty($_SESSION['dttd_admin']) || dttd_admin_auth_cookie_valid()) {
    $_SESSION['dttd_admin'] = true;
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';

    if (hash_equals((string)ADMIN_PASSWORD, (string)$password)) {
        session_regenerate_id(true);
        $_SESSION['dttd_admin'] = true;
        dttd_admin_set_auth_cookie();
        session_write_close();
        header('Location: index.php');
        exit;
    }

    $error = 'Incorrect password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DJ Portal Login</title>
<link rel="stylesheet" href="<?= h(dttd_asset_url('assets/admin-touch.css', true)) ?>">
</head>
<body class="admin-body">
  <main class="touch-login">
    <section class="touch-panel">
      <div class="touch-panel-pad">
        <h1 class="login-title">DJ Portal</h1>
        <p class="login-subtitle">Enter the admin password.</p>

        <?php if ($error): ?>
          <div class="form-alert"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post">
          <input class="login-input" type="password" name="password" placeholder="Password" required>
          <br><br>
          <button class="touch-btn blue full" type="submit">Login</button>
        </form>
      </div>
    </section>
  </main>
</body>
</html>
