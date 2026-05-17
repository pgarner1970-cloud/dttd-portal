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
    <main class="touch-wrap" data-event-id="<?= (int)$event['id'] ?>" data-request-fingerprint="<?= h($initial_fingerprint) ?>">

  <div id="requestUpdateBanner" class="request-update-banner" hidden>
    <div>
      <strong>Queue updates available</strong>
      <span id="requestUpdateText">New or changed requests have arrived.</span>
    </div>
    <button type="button" id="requestUpdateRefresh">Refresh queue</button>
  </div>
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

$ping_stmt = db()->prepare("
    SELECT 
        COUNT(*) AS total_requests,
        COALESCE(MAX(UNIX_TIMESTAMP(updated_at)), 0) AS latest_updated,
        COALESCE(MAX(UNIX_TIMESTAMP(created_at)), 0) AS latest_created
    FROM song_requests
    WHERE event_id = ?
");
$ping_stmt->execute([(int)$event['id']]);
$ping_row = $ping_stmt->fetch();
$initial_fingerprint = sha1((int)$event['id'] . '|' . (int)($ping_row['total_requests'] ?? 0) . '|' . (int)($ping_row['latest_updated'] ?? 0) . '|' . (int)($ping_row['latest_created'] ?? 0));

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

      <section class="live-event-timer"
        data-event-date="<?= h($event['event_date'] ?? '') ?>"
        data-start-time="<?= h(input_time($event['start_time'] ?? '')) ?>"
        data-end-time="<?= h(input_time($event['end_time'] ?? '')) ?>"
        data-requests-close="<?= h($event['requests_close_at'] ?? '') ?>"
      >
        <div class="timer-cell">
          <span>Live time</span>
          <strong id="liveClock">--:--:--</strong>
        </div>

        <div class="timer-cell">
          <span>Event status</span>
          <strong id="eventStatus">Checking...</strong>
        </div>

        <div class="timer-cell">
          <span>Requests close</span>
          <strong id="requestsCountdown">--</strong>
        </div>

        <div class="timer-cell">
          <span>Event ends</span>
          <strong id="eventCountdown">--</strong>
        </div>
      </section>


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

<script>
(function(){
  const wrap = document.querySelector('main.touch-wrap[data-event-id]');
  const banner = document.getElementById('requestUpdateBanner');
  const refreshButton = document.getElementById('requestUpdateRefresh');
  const text = document.getElementById('requestUpdateText');

  if (!wrap || !banner || !refreshButton) return;

  const eventId = wrap.dataset.eventId;
  let lastFingerprint = wrap.dataset.requestFingerprint || '';
  let hasUpdate = false;
  let isBusy = false;

  function markBusy(){
    isBusy = true;
    window.clearTimeout(window.__dttdBusyTimer);
    window.__dttdBusyTimer = window.setTimeout(function(){
      isBusy = false;
    }, 8000);
  }

  document.addEventListener('pointerdown', function(event){
    if (event.target.closest('button, a, select, input, textarea')) {
      markBusy();
    }
  }, {passive:true});

  document.addEventListener('change', markBusy, {passive:true});

  async function checkForUpdates(){
    if (document.hidden || isBusy || hasUpdate) return;

    try {
      const response = await fetch('/admin/request-ping.php?event=' + encodeURIComponent(eventId) + '&_=' + Date.now(), {
        cache: 'no-store',
        credentials: 'same-origin'
      });

      if (!response.ok) return;

      const data = await response.json();

      if (!data.ok || !data.fingerprint) return;

      if (lastFingerprint && data.fingerprint !== lastFingerprint) {
        hasUpdate = true;
        text.textContent = 'The request queue changed at ' + (data.checked_at || 'now') + '.';
        banner.hidden = false;
      } else {
        lastFingerprint = data.fingerprint;
      }
    } catch (error) {
      // Silent by design. DJ screen should not show technical errors.
    }
  }

  refreshButton.addEventListener('click', function(){
    window.location.reload();
  });

  window.setInterval(checkForUpdates, 10000);
})();
</script>

<!-- Live Event Timer Panel JS -->
<script>
(function(){
  function pad(num){ return String(num).padStart(2, '0'); }

  function formatClock(date){
    return pad(date.getHours()) + ':' + pad(date.getMinutes()) + ':' + pad(date.getSeconds());
  }

  function formatCountdown(ms){
    if (ms <= 0) return '0m';

    const totalSeconds = Math.floor(ms / 1000);
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);

    if (days > 0) return days + 'd ' + hours + 'h';
    if (hours > 0) return hours + 'h ' + minutes + 'm';
    return minutes + 'm';
  }

  function parseLocalDateTime(dateValue, timeValue){
    if (!dateValue || !timeValue) return null;

    const parts = dateValue.split('-').map(Number);
    const timeParts = timeValue.split(':').map(Number);

    if (parts.length < 3 || timeParts.length < 2) return null;

    return new Date(parts[0], parts[1] - 1, parts[2], timeParts[0], timeParts[1], 0);
  }

  function parseSqlDateTime(value){
    if (!value) return null;
    const normalised = value.replace(' ', 'T');
    const parsed = new Date(normalised);
    return isNaN(parsed.getTime()) ? null : parsed;
  }

  const panel = document.querySelector('.live-event-timer');
  if (!panel) return;

  const liveClock = document.getElementById('liveClock');
  const eventStatus = document.getElementById('eventStatus');
  const requestsCountdown = document.getElementById('requestsCountdown');
  const eventCountdown = document.getElementById('eventCountdown');

  const eventDate = panel.dataset.eventDate || '';
  const startTime = panel.dataset.startTime || '';
  const endTime = panel.dataset.endTime || '';
  const closeRaw = panel.dataset.requestsClose || '';

  const startDate = parseLocalDateTime(eventDate, startTime);
  let endDate = parseLocalDateTime(eventDate, endTime);
  const closeDate = parseSqlDateTime(closeRaw);

  if (startDate && endDate && endDate <= startDate) {
    endDate.setDate(endDate.getDate() + 1);
  }

  function updateTimers(){
    const now = new Date();

    if (liveClock) {
      liveClock.textContent = formatClock(now);
    }

    if (eventStatus) {
      if (!startDate) {
        eventStatus.textContent = 'No start time';
      } else if (now < startDate) {
        eventStatus.textContent = 'Starts in ' + formatCountdown(startDate - now);
      } else if (endDate && now > endDate) {
        eventStatus.textContent = 'Event ended';
      } else {
        eventStatus.textContent = 'Live now';
      }
    }

    if (requestsCountdown) {
      if (!closeDate) {
        requestsCountdown.textContent = 'Not set';
        requestsCountdown.classList.remove('timer-warning', 'timer-ended');
      } else if (now >= closeDate) {
        requestsCountdown.textContent = 'Closed';
        requestsCountdown.classList.add('timer-ended');
        requestsCountdown.classList.remove('timer-warning');
      } else {
        const remaining = closeDate - now;
        requestsCountdown.textContent = formatCountdown(remaining);
        requestsCountdown.classList.toggle('timer-warning', remaining <= 15 * 60 * 1000);
        requestsCountdown.classList.remove('timer-ended');
      }
    }

    if (eventCountdown) {
      if (!endDate) {
        eventCountdown.textContent = 'Not set';
        eventCountdown.classList.remove('timer-warning', 'timer-ended');
      } else if (now >= endDate) {
        eventCountdown.textContent = 'Ended';
        eventCountdown.classList.add('timer-ended');
        eventCountdown.classList.remove('timer-warning');
      } else {
        const remaining = endDate - now;
        eventCountdown.textContent = formatCountdown(remaining);
        eventCountdown.classList.toggle('timer-warning', remaining <= 30 * 60 * 1000);
        eventCountdown.classList.remove('timer-ended');
      }
    }
  }

  updateTimers();
  window.setInterval(updateTimers, 1000);
})();
</script>

<?php admin_footer(); ?>
