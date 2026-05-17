<?php
require_once __DIR__ . '/_auth.php';

$event_id = !empty($_GET['event']) ? (int)$_GET['event'] : 0;

if ($event_id) {
    $event = get_event($event_id);
} else {
    // Do not rely on active_event() here because that may apply timing/window rules
    // and redirect back to Events. The DJ dashboard should still be viewable.
    $event = db()->query("
        SELECT *
        FROM events
        WHERE is_active = 1
        ORDER BY event_date DESC, id DESC
        LIMIT 1
    ")->fetch();

    if (!$event) {
        $event = db()->query("
            SELECT *
            FROM events
            ORDER BY event_date DESC, id DESC
            LIMIT 1
        ")->fetch();
    }
}

if (!$event) {
    admin_header('Requests - DJ Portal');
    ?>

<div class="admin-floating-nav">
  <a class="admin-floating-nav-btn active" href="/admin/requests.php">Requests</a>
  <a class="admin-floating-nav-btn" href="/admin/events.php">Events</a>
  <a class="admin-floating-nav-btn" href="/admin/events.php">Settings</a>
</div>

<main class="touch-wrap">
<section class="touch-panel">
        <div class="touch-panel-header">
          <div>
            <h1 class="touch-panel-title">Request Queue</h1>
            <p class="touch-subtitle">No events exist yet.</p>
          </div>
          <a class="touch-btn blue" href="/admin/event-edit.php">＋ Add Event</a>
        </div>
      </section>
    </main>
    <?php
    admin_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_action'], $_POST['group_key'])) {
    $allowed = ['played','rejected','duplicate','maybe','pending'];
    $status = in_array($_POST['request_action'], $allowed, true) ? $_POST['request_action'] : 'pending';
    $group_key = (string)$_POST['group_key'];

    $stmt = db()->prepare("
        UPDATE song_requests 
        SET status = ? 
        WHERE event_id = ?
        AND CONCAT(LOWER(TRIM(song_title)), '|', LOWER(TRIM(artist))) = ?
    ");
    $stmt->execute([$status, (int)$event['id'], $group_key]);

    header('Location: /admin/requests.php?event=' . (int)$event['id']);
    exit;
}

$sort = $_GET['sort'] ?? 'queue';

$orderSql = "
    FIELD(status,'pending','maybe','duplicate','played','rejected'),
    created_at ASC,
    id ASC
";

if ($sort === 'newest') {
    $orderSql = "created_at DESC, id DESC";
} elseif ($sort === 'oldest') {
    $orderSql = "created_at ASC, id ASC";
}

$stmt = db()->prepare("
    SELECT *
    FROM song_requests
    WHERE event_id = ?
    ORDER BY $orderSql
");
$stmt->execute([$event['id']]);
$requests = $stmt->fetchAll();

$counts = ['pending'=>0,'played'=>0,'maybe'=>0,'duplicate'=>0,'rejected'=>0];
foreach ($requests as $r) {
    if (isset($counts[$r['status']])) $counts[$r['status']]++;
}

$groups = [];
foreach ($requests as $r) {
    $key = strtolower(trim($r['song_title'])) . '|' . strtolower(trim($r['artist']));

    if (!isset($groups[$key])) {
        $groups[$key] = [
            'key' => $key,
            'song_title' => $r['song_title'],
            'artist' => $r['artist'],
            'status' => $r['status'],
            'created_at' => $r['created_at'],
            'items' => [],
        ];
    }

    $groups[$key]['items'][] = $r;

    // Queue status for the group follows the highest priority status in the group.
    $priority = ['pending' => 1, 'maybe' => 2, 'duplicate' => 3, 'played' => 4, 'rejected' => 5];
    if (($priority[$r['status']] ?? 9) < ($priority[$groups[$key]['status']] ?? 9)) {
        $groups[$key]['status'] = $r['status'];
    }

    if (strtotime($r['created_at']) < strtotime($groups[$key]['created_at'])) {
        $groups[$key]['created_at'] = $r['created_at'];
    }
}

$grouped_requests = array_values($groups);

if ($sort === 'queue') {
    usort($grouped_requests, function($a, $b) {
        $priority = ['pending' => 1, 'maybe' => 2, 'duplicate' => 3, 'played' => 4, 'rejected' => 5];
        $pa = $priority[$a['status']] ?? 9;
        $pb = $priority[$b['status']] ?? 9;
        if ($pa !== $pb) return $pa <=> $pb;
        return strtotime($a['created_at']) <=> strtotime($b['created_at']);
    });
} elseif ($sort === 'newest') {
    usort($grouped_requests, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
} else {
    usort($grouped_requests, fn($a, $b) => strtotime($a['created_at']) <=> strtotime($b['created_at']));
}

$events = db()->query("SELECT id, event_name, venue_name, event_date, start_time, end_time, is_active FROM events ORDER BY event_date DESC, id DESC LIMIT 40")->fetchAll();

function status_label($status) {
    return strtolower((string)$status);
}

admin_header('DJ Portal');
?>
<main class="touch-wrap">
<section class="touch-grid">
    <aside class="touch-panel">
      <div class="touch-panel-pad">
        <p class="event-label">Active Event</p>
        <h1 class="event-name"><?= h($event['event_name']) ?></h1>
        <p class="event-meta"><?= h($event['venue_name']) ?><br><?= h(event_type_label($event['event_type'] ?? 'public')) ?></p>

        <div class="event-info">
          <div class="event-info-row">
            <div class="event-info-icon">◷</div>
            <div>
              <div class="event-info-title">Event time</div>
              <div class="event-info-value"><?= h(input_time($event['start_time'])) ?> - <?= h(input_time($event['end_time'])) ?></div>
            </div>
          </div>

          <div class="event-info-row">
            <div class="event-info-icon">▣</div>
            <div>
              <div class="event-info-title">Date</div>
              <div class="event-info-value"><?= h($event['event_date'] ? date('D, j M Y', strtotime($event['event_date'])) : 'Date not set') ?></div>
            </div>
          </div>

          <div class="event-info-row">
            <div class="event-info-icon">⏱</div>
            <div>
              <div class="event-info-title">Requests close</div>
              <div class="event-info-value countdown"><?= h($event['requests_close_at'] ? date('H:i', strtotime($event['requests_close_at'])) : 'Not set') ?></div>
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
      </div>
    </aside>

    <section class="touch-panel">
      <form class="request-queue-compact-header" method="get">
        <div>
          <h2 class="touch-panel-title">Request Queue</h2>
        </div>

        <div class="queue-selector">
          <label>Selected event</label>
          <select name="event" onchange="this.form.submit()">
            <?php foreach ($events as $e): ?>
              <option value="<?= (int)$e['id'] ?>" <?= (int)$event['id']===(int)$e['id']?'selected':'' ?>>
                <?= h($e['event_name']) ?> - <?= h($e['venue_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="sort-selector">
          <label>Sort</label>
          <select name="sort" onchange="this.form.submit()">
            <option value="queue" <?= $sort==='queue'?'selected':'' ?>>Queue: pending first</option>
            <option value="oldest" <?= $sort==='oldest'?'selected':'' ?>>Oldest first</option>
            <option value="newest" <?= $sort==='newest'?'selected':'' ?>>Newest first</option>
          </select>
        </div>
      </form>

      <div class="request-list">
        <?php foreach ($grouped_requests as $group): ?>
          <?php $first = $group['items'][0]; ?>
          <article class="request-card status-<?= h($group['status']) ?>">
            <div class="req-time">
              <?= h(date('H:i', strtotime($group['created_at']))) ?>
              <small><?= h(date('d M', strtotime($group['created_at']))) ?></small>
            </div>

            <div>
              <div class="req-track-title"><?= h($group['song_title']) ?></div>
              <div class="req-track-artist"><?= h($group['artist']) ?></div>
              <div class="group-count">
                <?= count($group['items']) ?> request<?= count($group['items']) === 1 ? '' : 's' ?>
              </div>
            </div>

            <div class="group-messages">
              <?php foreach ($group['items'] as $item): ?>
                <div class="group-message">
                  <span class="req-person"><?= h(strtoupper(substr($item['guest_name'], 0, 1))) ?></span>
                  <div>
                    <div class="message-person"><?= h($item['guest_name']) ?></div>
                    <div class="message-text"><?= nl2br(h($item['dedication'] ?: '—')) ?></div>
                    <div class="message-time"><?= h(date('H:i', strtotime($item['created_at']))) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="req-status">
              <span class="status-badge <?= h(status_label($group['status'])) ?>"><?= h($group['status']) ?></span>
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
                  <input type="hidden" name="group_key" value="<?= h($group['key']) ?>">
                  <button class="action-tile <?= h($action) ?>" name="request_action" value="<?= h($action) ?>">
                    <span class="big-icon"><?= h($meta['icon']) ?></span>
                    <span><?= h($meta['label']) ?></span>
                  </button>
                </form>
              <?php endforeach; ?>
            </div>
          </article>
        <?php endforeach; ?>

        <?php if (!$grouped_requests): ?>
          <div class="empty-queue">No requests yet.</div>
        <?php endif; ?>
      </div>

      <div class="last-updated">Last updated: <?= h(date('H:i:s')) ?></div>
    </section>
  </section>
</main>
<?php admin_footer(); ?>
