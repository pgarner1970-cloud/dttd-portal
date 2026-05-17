<?php
require_once __DIR__ . '/_auth.php';

function post_value($key, $default = '') {
    return $_POST[$key] ?? $default;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = !empty($_POST['id']) ? (int)$_POST['id'] : 0;

    $event_name = trim(post_value('event_name'));
    $venue_name = trim(post_value('venue_name'));
    $event_type = post_value('event_type', 'public');
    $event_date = trim(post_value('event_date')) ?: null;
    $start_time = trim(post_value('start_time')) ?: null;
    $end_time = trim(post_value('end_time')) ?: null;
    $requests_close_minutes = (int)post_value('requests_close_minutes', 30);
    $queue_visibility = post_value('queue_visibility', 'venue');
    $notes = trim(post_value('notes'));
    $is_active = !empty($_POST['is_active']) ? 1 : 0;

    $portal_available_from = null;
    $portal_available_until = null;
    $requests_close_at = null;

    if ($event_date && $start_time && $end_time) {
        $times = build_event_times($event_date, $start_time, $end_time, $requests_close_minutes);
        $portal_available_from = $times['portal_available_from'];
        $portal_available_until = $times['portal_available_until'];
        $requests_close_at = $times['requests_close_at'];
    }

    if (!empty($_POST['manual_override'])) {
        $portal_available_from = trim(post_value('manual_portal_available_from')) ? str_replace('T', ' ', trim(post_value('manual_portal_available_from'))) . ':00' : $portal_available_from;
        $portal_available_until = trim(post_value('manual_portal_available_until')) ? str_replace('T', ' ', trim(post_value('manual_portal_available_until'))) . ':00' : $portal_available_until;
        $requests_close_at = trim(post_value('manual_requests_close_at')) ? str_replace('T', ' ', trim(post_value('manual_requests_close_at'))) . ':00' : $requests_close_at;
    }

    if ($event_name !== '' && $venue_name !== '') {
        if ($id) {
            $stmt = db()->prepare("
                UPDATE events SET
                event_name=?, venue_name=?, event_type=?, event_date=?, start_time=?, end_time=?,
                requests_close_minutes=?, portal_available_from=?, portal_available_until=?, requests_close_at=?,
                queue_visibility=?, notes=?, is_active=?
                WHERE id=?
            ");
            $stmt->execute([
                $event_name, $venue_name, $event_type, $event_date, $start_time, $end_time,
                $requests_close_minutes, $portal_available_from, $portal_available_until, $requests_close_at,
                $queue_visibility, $notes, $is_active, $id
            ]);
        } else {
            $stmt = db()->prepare("
                INSERT INTO events
                (event_name, venue_name, event_type, event_date, start_time, end_time, requests_close_minutes,
                portal_available_from, portal_available_until, requests_close_at, queue_visibility, notes, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $event_name, $venue_name, $event_type, $event_date, $start_time, $end_time,
                $requests_close_minutes, $portal_available_from, $portal_available_until, $requests_close_at,
                $queue_visibility, $notes, $is_active
            ]);
        }
    }
    header('Location: /admin/events.php');
    exit;
}

$edit = null;
if (!empty($_GET['edit'])) {
    $edit = get_event((int)$_GET['edit']);
}

$events = db()->query("SELECT e.*, (SELECT COUNT(*) FROM song_requests sr WHERE sr.event_id=e.id) AS request_count FROM events e ORDER BY event_date DESC, id DESC")->fetchAll();

$event_type = $edit['event_type'] ?? 'public';
$close_mins = $edit['requests_close_minutes'] ?? 30;
$qv = $edit['queue_visibility'] ?? 'venue';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Events Admin</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="topnav"><a href="/">Portal</a><a href="/admin/">Requests</a><a href="/admin/events.php">Events</a><a href="/admin/?logout=1">Logout</a></nav>
<main class="container">
  <div class="card">
    <h1><?= $edit ? 'Edit Event' : 'Create Event' ?></h1>
    <form method="post">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

      <div class="grid-tight">
        <div>
          <label>Event name *</label>
          <input name="event_name" required value="<?= h($edit['event_name'] ?? '') ?>" placeholder="Example: 80s & 90s Party Night">
        </div>
        <div>
          <label>Venue name *</label>
          <input name="venue_name" required value="<?= h($edit['venue_name'] ?? '') ?>" placeholder="Example: The Crown Inn">
        </div>
        <div>
          <label>Event type</label>
          <select name="event_type">
            <option value="public" <?= $event_type==='public'?'selected':'' ?>>Public Night</option>
            <option value="private_party" <?= $event_type==='private_party'?'selected':'' ?>>Private Party</option>
            <option value="wedding" <?= $event_type==='wedding'?'selected':'' ?>>Wedding</option>
            <option value="corporate" <?= $event_type==='corporate'?'selected':'' ?>>Corporate Event</option>
          </select>
        </div>
      </div>

      <div class="grid-tight">
        <div>
          <label>Event date</label>
          <input type="date" name="event_date" value="<?= h($edit['event_date'] ?? '') ?>">
        </div>
        <div>
          <label>Start time</label>
          <input type="time" name="start_time" value="<?= h(input_time($edit['start_time'] ?? '19:30')) ?>">
        </div>
        <div>
          <label>End time</label>
          <input type="time" name="end_time" value="<?= h(input_time($edit['end_time'] ?? '01:30')) ?>">
          <p class="small">If end time is earlier than start time, the event is treated as finishing after midnight.</p>
        </div>
        <div>
          <label>Close requests before end</label>
          <select name="requests_close_minutes">
            <?php foreach ([15,30,45,60] as $m): ?>
              <option value="<?= $m ?>" <?= (int)$close_mins===$m?'selected':'' ?>><?= $m ?> minutes</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <details>
        <summary>Advanced options</summary>
        <div class="grid-tight" style="margin-top:12px">
          <div>
            <label>Request queue visibility</label>
            <select name="queue_visibility">
              <option value="venue" <?= $qv==='venue'?'selected':'' ?>>Defined by venue</option>
              <option value="public" <?= $qv==='public'?'selected':'' ?>>Public</option>
              <option value="private" <?= $qv==='private'?'selected':'' ?>>Private / admin only</option>
            </select>
          </div>
          <div>
            <label><input type="checkbox" name="manual_override" value="1"> Manually override calculated times</label>
            <p class="small">Only use this for unusual events.</p>
          </div>
          <div>
            <label>Portal available from</label>
            <input type="datetime-local" name="manual_portal_available_from" value="<?= h(html_dt($edit['portal_available_from'] ?? null)) ?>">
          </div>
          <div>
            <label>Portal available until</label>
            <input type="datetime-local" name="manual_portal_available_until" value="<?= h(html_dt($edit['portal_available_until'] ?? null)) ?>">
          </div>
          <div>
            <label>Requests close at</label>
            <input type="datetime-local" name="manual_requests_close_at" value="<?= h(html_dt($edit['requests_close_at'] ?? null)) ?>">
          </div>
        </div>

        <label>Notes</label>
        <textarea name="notes" placeholder="Internal event notes"><?= h($edit['notes'] ?? '') ?></textarea>
      </details>

      <label><input type="checkbox" name="is_active" value="1" <?= !empty($edit['is_active']) ? 'checked' : '' ?>> Active / available for portal selection</label>

      <button class="btn btn-primary" type="submit"><?= $edit ? 'Save Event' : 'Create Event' ?></button>
      <?php if ($edit): ?><a class="btn btn-secondary" href="/admin/events.php">Cancel Edit</a><?php endif; ?>
    </form>
  </div>

  <div class="card" style="margin-top:16px">
    <h2>Events</h2>
    <table class="table">
      <thead><tr><th>Event</th><th>Type</th><th>Date/Time</th><th>Requests Close</th><th>Status</th><th>Requests</th><th>Actions</th></tr></thead>
      <tbody>
      <?php foreach ($events as $e): ?>
        <tr>
          <td><strong><?= h($e['event_name']) ?></strong><br><?= h($e['venue_name']) ?></td>
          <td><?= h(event_type_label($e['event_type'] ?? 'public')) ?></td>
          <td><?= h($e['event_date']) ?><br><?= h(input_time($e['start_time'])) ?> - <?= h(input_time($e['end_time'])) ?></td>
          <td><?= h($e['requests_close_at'] ? date('d/m/Y H:i', strtotime($e['requests_close_at'])) : '') ?></td>
          <td><?= $e['is_active'] ? '<span class="status-played">Active</span>' : '<span class="status-rejected">Inactive</span>' ?></td>
          <td><?= (int)$e['request_count'] ?></td>
          <td>
            <a class="btn btn-gold" href="/admin/events.php?edit=<?= (int)$e['id'] ?>">Edit</a>
            <a class="btn btn-secondary" href="/admin/?event=<?= (int)$e['id'] ?>">Requests</a>
            <a class="btn btn-green" href="/request.php?event=<?= (int)$e['id'] ?>" target="_blank">Guest Link</a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$events): ?><tr><td colspan="7">No events yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</main>
</body>
</html>
