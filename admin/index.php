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

function status_label($status) {
    return strtolower((string)$status);
}

admin_header('DJ Portal');
?>
<main class="touch-wrap">
  <nav class="touch-tile-nav">
    <a class="touch-tile active" href="/admin/"><span class="tile-icon">♫</span><span>Requests</span></a>
    <a class="touch-tile" href="/admin/events.php"><span class="tile-icon">▦</span><span>Events</span></a>
    <a class="touch-tile" href="/request.php?event=<?= (int)$event['id'] ?>" target="_blank"><span class="tile-icon">🔗</span><span>Guest Link</span></a>
    <a class="touch-tile" href="/"><span class="tile-icon">⌂</span><span>Portal</span></a>
    <a class="touch-tile" href="/admin/events.php?edit=<?= (int)$event['id'] ?>"><span class="tile-icon">⚙</span><span>Settings</span></a>
  </nav>

  <section class="touch-grid">
    <aside class="touch-panel">
      <div class="touch-panel-pad">
        <p class="event-label">Active Event</p>
        <h1 class="event-name"><?= h($event['event_name']) ?></h1>
        <p class="event-meta"><?= h($event['venue_name']) ?><br><?= h(event_type_label($event['event_type'] ?? 'public')) ?></p>

        <div class="event-info">
          <div class="event-info-row">
            <div class="event-info-icon">◷</div>
            <div><?= h(input_time($event['start_time'])) ?> - <?= h(input_time($event['end_time'])) ?></div>
          </div>
          <div class="event-info-row">
            <div class="event-info-icon">▣</div>
            <div><?= h($event['event_date'] ? date('D, j M Y', strtotime($event['event_date'])) : 'Date not set') ?></div>
          </div>
          <div class="event-info-row">
            <div class="event-info-icon">⏱</div>
            <div>
              Requests close<br>
              <span class="countdown"><?= h($event['requests_close_at'] ? date('H:i', strtotime($event['requests_close_at'])) : 'Not set') ?></span>
            </div>
          </div>
        </div>

        <div class="stats-list">
          <div class="stat-line"><span class="stat-dot pending"></span><span>Pending</span><strong><?= (int)$counts['pending'] ?></strong></div>
          <div class="stat-line"><span class="stat-dot maybe"></span><span>Maybe</span><strong><?= (int)$counts['maybe'] ?></strong></div>
          <div class="stat-line"><span class="stat-dot played"></span><span>Played</span><strong><?= (int)$counts['played'] ?></strong></div>
          <div class="stat-line"><span class="stat-dot duplicate"></span><span>Duplicate</span><strong><?= (int)$counts['duplicate'] ?></strong></div>
          <div class="stat-line"><span class="stat-dot rejected"></span><span>Rejected</span><strong><?= (int)$counts['rejected'] ?></strong></div>
        </div>

        <div class="sidebar-actions">
          <a class="touch-btn blue full" href="/request.php?event=<?= (int)$event['id'] ?>" target="_blank">View Guest Portal</a>
          <a class="touch-btn purple full" href="/admin/events.php?edit=<?= (int)$event['id'] ?>">Edit Event</a>
        </div>
      </div>
    </aside>

    <section class="touch-panel">
      <div class="touch-panel-header">
        <div>
          <h2 class="touch-panel-title">Request Queue</h2>
          <p class="touch-subtitle">Pending requests first, oldest first within each status.</p>
        </div>
      </div>

      <form class="queue-toolbar" method="get">
        <select name="event" onchange="this.form.submit()">
          <?php foreach ($events as $e): ?>
            <option value="<?= (int)$e['id'] ?>" <?= (int)$event['id']===(int)$e['id']?'selected':'' ?>>
              <?= h($e['event_name']) ?> - <?= h($e['venue_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="sort-pill">Sort: Status queue</div>
      </form>

      <div class="request-list">
        <?php foreach ($requests as $r): ?>
          <article class="request-card status-<?= h($r['status']) ?>">
            <div class="req-time">
              <?= h(date('H:i', strtotime($r['created_at']))) ?>
              <small><?= h(date('d M', strtotime($r['created_at']))) ?></small>
            </div>

            <div class="req-guest">
              <span class="req-person"><?= h(strtoupper(substr($r['guest_name'], 0, 1))) ?></span>
              <span><?= h($r['guest_name']) ?></span>
            </div>

            <div>
              <div class="req-track-title"><?= h($r['song_title']) ?></div>
              <div class="req-track-artist"><?= h($r['artist']) ?></div>
            </div>

            <div class="req-message"><?= nl2br(h($r['dedication'] ?: '—')) ?></div>

            <div class="req-status">
              <span class="status-badge <?= h(status_label($r['status'])) ?>"><?= h($r['status']) ?></span>
            </div>

            <div class="req-actions">
              <?php
                $actions = [
                  'played' => ['icon' => '▶', 'label' => 'Played'],
                  'maybe' => ['icon' => '?', 'label' => 'Maybe'],
                  'duplicate' => ['icon' => '⧉', 'label' => 'Duplicate'],
                  'rejected' => ['icon' => '✕', 'label' => 'Reject'],
                ];
              ?>
              <?php foreach ($actions as $action => $meta): ?>
                <form method="post" class="req-action-form">
                  <input type="hidden" name="request_id" value="<?= (int)$r['id'] ?>">
                  <button class="action-tile <?= h($action) ?>" name="request_action" value="<?= h($action) ?>">
                    <span class="big-icon"><?= h($meta['icon']) ?></span>
                    <span><?= h($meta['label']) ?></span>
                  </button>
                </form>
              <?php endforeach; ?>
            </div>
          </article>
        <?php endforeach; ?>

        <?php if (!$requests): ?>
          <div class="empty-queue">No requests yet.</div>
        <?php endif; ?>
      </div>

      <div class="last-updated">Last updated: <?= h(date('H:i:s')) ?></div>
    </section>
  </section>
</main>
<?php admin_footer(); ?>
