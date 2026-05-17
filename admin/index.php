<?php
require_once __DIR__ . '/_auth.php';

$event_id = !empty($_GET['event']) ? (int)$_GET['event'] : 0;
$event = $event_id ? get_event($event_id) : active_event();

if (!$event) {
    header('Location: /admin/events.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_action'], $_POST['request_id'])) {
    $allowed = ['played','rejected','duplicate','maybe','pending'];
    $status = in_array($_POST['request_action'], $allowed, true) ? $_POST['request_action'] : 'pending';

    $stmt = db()->prepare("UPDATE song_requests SET status = ? WHERE id = ? AND event_id = ?");
    $stmt->execute([$status, (int)$_POST['request_id'], (int)$event['id']]);

    header('Location: /admin/?event=' . (int)$event['id']);
    exit;
}

$stmt = db()->prepare("
    SELECT *
    FROM song_requests
    WHERE event_id = ?
    ORDER BY
      FIELD(status,'pending','maybe','duplicate','played','rejected'),
      created_at ASC,
      id ASC
");
$stmt->execute([$event['id']]);
$requests = $stmt->fetchAll();

$counts = ['pending'=>0,'played'=>0,'maybe'=>0,'duplicate'=>0,'rejected'=>0];
foreach ($requests as $r) {
    if (isset($counts[$r['status']])) $counts[$r['status']]++;
}

$events = db()->query("SELECT id, event_name, venue_name, event_date, start_time, end_time, is_active FROM events ORDER BY event_date DESC, id DESC LIMIT 40")->fetchAll();

admin_header('DJ Admin Dashboard');
?>
<main class="admin-wrap">
  <section class="admin-card">
    <div class="admin-card-header">
      <div>
        <h1 class="admin-title">Song Requests</h1>
        <p class="admin-subtitle">
          <?= h($event['event_name']) ?> · <?= h($event['venue_name']) ?> · <?= h(event_type_label($event['event_type'] ?? 'public')) ?>
        </p>
      </div>
      <div class="admin-btn-row">
        <a class="admin-btn light" href="/request.php?event=<?= (int)$event['id'] ?>" target="_blank">Guest Link</a>
        <a class="admin-btn secondary" href="/admin/events.php?edit=<?= (int)$event['id'] ?>">Edit Event</a>
      </div>
    </div>

    <div class="admin-card-body">
      <form class="admin-toolbar" method="get">
        <div class="field">
          <label>Selected event</label>
          <select name="event" onchange="this.form.submit()">
            <?php foreach ($events as $e): ?>
              <option value="<?= (int)$e['id'] ?>" <?= (int)$event['id']===(int)$e['id']?'selected':'' ?>>
                <?= h($e['event_name']) ?> - <?= h($e['venue_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="admin-stat">
          <span>Date / time</span>
          <strong><?= h($event['event_date']) ?> · <?= h(input_time($event['start_time'])) ?>-<?= h(input_time($event['end_time'])) ?></strong>
        </div>
        <div class="admin-stat">
          <span>Requests close</span>
          <strong><?= h($event['requests_close_at'] ? date('H:i', strtotime($event['requests_close_at'])) : 'Not set') ?></strong>
        </div>
        <div class="admin-stat">
          <span>Total requests</span>
          <strong><?= count($requests) ?></strong>
        </div>
      </form>

      <div class="admin-summary">
        <div class="admin-stat"><span>Pending</span><strong><?= (int)$counts['pending'] ?></strong></div>
        <div class="admin-stat"><span>Maybe</span><strong><?= (int)$counts['maybe'] ?></strong></div>
        <div class="admin-stat"><span>Played</span><strong><?= (int)$counts['played'] ?></strong></div>
        <div class="admin-stat"><span>Duplicate</span><strong><?= (int)$counts['duplicate'] ?></strong></div>
        <div class="admin-stat"><span>Rejected</span><strong><?= (int)$counts['rejected'] ?></strong></div>
      </div>

      <div class="admin-table-wrap">
        <table class="admin-table">
          <thead>
            <tr>
              <th style="width:64px">Time</th>
              <th style="width:150px">Guest</th>
              <th>Track</th>
              <th>Message</th>
              <th style="width:90px">Status</th>
              <th style="width:300px">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($requests as $r): ?>
            <tr>
              <td><?= h(date('H:i', strtotime($r['created_at']))) ?></td>
              <td><?= h($r['guest_name']) ?></td>
              <td>
                <div class="track-title"><?= h($r['song_title']) ?></div>
                <div class="track-artist"><?= h($r['artist']) ?></div>
              </td>
              <td><?= nl2br(h($r['dedication'])) ?></td>
              <td><span class="status-chip status-<?= h($r['status']) ?>"><?= h($r['status']) ?></span></td>
              <td>
                <div class="admin-btn-row">
                <?php foreach (['played','maybe','duplicate','rejected','pending'] as $s): ?>
                  <form method="post" class="admin-action-form">
                    <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                    <button class="admin-btn <?= $s==='rejected' ? 'red' : ($s==='played' ? 'green' : 'amber') ?>" name="request_action" value="<?= h($s) ?>"><?= h($s) ?></button>
                  </form>
                <?php endforeach; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$requests): ?>
            <tr><td colspan="6"><span class="admin-note">No requests yet.</span></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
