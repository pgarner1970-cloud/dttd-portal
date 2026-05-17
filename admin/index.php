<?php
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
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Admin Login</title><link rel="stylesheet" href="/assets/style.css"></head>
<body>
<main class="container">
  <div class="card" style="margin-top:50px">
    <h1>Admin Login</h1>
    <?php if (!empty($login_error)): ?><div class="notice"><?= h($login_error) ?></div><?php endif; ?>
    <form method="post">
      <label>Password</label>
      <input type="password" name="password" required>
      <button class="btn btn-primary" type="submit">Login</button>
    </form>
  </div>
</main>
</body></html>
<?php exit; endif;

$event = current_event();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['request_id'])) {
    $allowed = ['played','rejected','duplicate','maybe','pending'];
    $status = in_array($_POST['action'], $allowed, true) ? $_POST['action'] : 'pending';
    $stmt = db()->prepare("UPDATE song_requests SET status = ? WHERE id = ?");
    $stmt->execute([$status, (int)$_POST['request_id']]);
    header('Location: /admin/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_update'])) {
    $stmt = db()->prepare("UPDATE events SET event_name=?, venue_name=?, queue_visibility=? WHERE id=?");
    $stmt->execute([
        trim($_POST['event_name']),
        trim($_POST['venue_name']),
        $_POST['queue_visibility'],
        $event['id']
    ]);
    header('Location: /admin/');
    exit;
}

$stmt = db()->prepare("SELECT * FROM song_requests WHERE event_id = ? ORDER BY FIELD(status,'pending','maybe','duplicate','played','rejected'), created_at DESC");
$stmt->execute([$event['id']]);
$requests = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DJ Admin Dashboard</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="topnav"><a href="/">Portal</a><a href="/admin/">Admin</a><a href="/admin/?logout=1">Logout</a></nav>
<main class="container">
  <div class="card">
    <h1>DJ Dashboard</h1>
    <form method="post">
      <input type="hidden" name="event_update" value="1">
      <label>Event name</label>
      <input name="event_name" value="<?= h($event['event_name']) ?>">
      <label>Venue name</label>
      <input name="venue_name" value="<?= h($event['venue_name']) ?>">
      <label>Request queue visibility</label>
      <select name="queue_visibility">
        <option value="venue" <?= $event['queue_visibility']==='venue'?'selected':'' ?>>Defined by venue</option>
        <option value="public" <?= $event['queue_visibility']==='public'?'selected':'' ?>>Public</option>
        <option value="private" <?= $event['queue_visibility']==='private'?'selected':'' ?>>Private / admin only</option>
      </select>
      <button class="btn btn-secondary" type="submit">Save Event Settings</button>
    </form>
  </div>

  <div class="card" style="margin-top:16px">
    <h2>Song Requests</h2>
    <table class="table">
      <thead><tr><th>Time</th><th>Guest</th><th>Song</th><th>Message</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($requests as $r): ?>
        <tr>
          <td><?= h(date('H:i', strtotime($r['created_at']))) ?></td>
          <td><?= h($r['guest_name']) ?></td>
          <td><strong><?= h($r['song_title']) ?></strong><br><?= h($r['artist']) ?></td>
          <td><?= nl2br(h($r['dedication'])) ?></td>
          <td class="status-<?= h($r['status']) ?>"><?= h($r['status']) ?></td>
          <td>
            <?php foreach (['played','maybe','duplicate','rejected','pending'] as $s): ?>
              <form method="post" style="display:inline">
                <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-gold" style="width:auto;min-height:34px;padding:7px 9px" name="action" value="<?= h($s) ?>"><?= h($s) ?></button>
              </form>
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$requests): ?><tr><td colspan="6">No requests yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</main>
</body>
</html>
