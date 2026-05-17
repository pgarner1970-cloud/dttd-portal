<?php
require_once __DIR__ . '/_auth.php';

// v39: calculated current event.
// The request queue now follows the event timing automatically:
// current from 1 hour before start until event end.
// If no event is currently live, show the next upcoming event.
$event = dttd_get_calculated_current_event();

if (!$event) {
    admin_header('Requests - DJ Portal');
    ?>

<div class="header-event-summary"
  data-event-date="<?= h($event['event_date'] ?? '') ?>"
  data-start-time="<?= h(input_time($event['start_time'] ?? '')) ?>"
  data-end-time="<?= h(input_time($event['end_time'] ?? '')) ?>"
  data-requests-close="<?= h($event['requests_close_at'] ?? '') ?>"
>
  <div class="header-summary-item">
    <span>Now</span>
    <strong id="headerLiveClock">--:--</strong>
  </div>
  <div class="header-summary-item">
    <span>Status</span>
    <strong id="headerEventStatus">--</strong>
  </div>
  <div class="header-summary-item">
    <span>Requests</span>
    <strong id="headerRequestsCountdown">--</strong>
  </div>
  <div class="header-summary-item">
    <span>Ends</span>
    <strong id="headerEventCountdown">--</strong>
  </div>
</div>

    <main class="touch-wrap"
  data-event-id="<?= (int)$event['id'] ?>"
  data-request-fingerprint="<?= h($initial_fingerprint) ?>"
>"
  data-request-fingerprint="<?= h($initial_fingerprint ?? '') ?>"
>"
  data-request-fingerprint="<?= h($initial_fingerprint ?? '') ?>"
  data-event-date="<?= h($event['event_date'] ?? '') ?>"
  data-start-time="<?= h(input_time($event['start_time'] ?? '')) ?>"
  data-end-time="<?= h(input_time($event['end_time'] ?? '')) ?>"
  data-requests-close="<?= h($event['requests_close_at'] ?? '') ?>"
>"
  data-request-fingerprint="<?= h($initial_fingerprint ?? '') ?>"
  data-event-date="<?= h($event['event_date'] ?? '') ?>"
  data-start-time="<?= h(input_time($event['start_time'] ?? '')) ?>"
  data-end-time="<?= h(input_time($event['end_time'] ?? '')) ?>"
  data-requests-close="<?= h($event['requests_close_at'] ?? '') ?>"
>"
  data-start-time="<?= h(input_time($event['start_time'] ?? '')) ?>"
  data-end-time="<?= h(input_time($event['end_time'] ?? '')) ?>"
  data-requests-close="<?= h($event['requests_close_at'] ?? '') ?>"
>" data-request-fingerprint="<?= h($initial_fingerprint) ?>">

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

    header('Location: /admin/requests.php');
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

$requests_layout = app_setting('requests_layout', 'event_left');
$allowed_request_layouts = ['event_left', 'event_right', 'queue_only'];
if (!in_array($requests_layout, $allowed_request_layouts, true)) {
    $requests_layout = 'event_left';
}


function request_queue_fingerprint($event_id) {
    $stmt = db()->prepare("
        SELECT 
            id,
            status,
            guest_name,
            song_title,
            artist,
            message
        FROM song_requests
        WHERE event_id = ?
        ORDER BY id ASC
    ");
    $stmt->execute([(int)$event_id]);
    $rows = $stmt->fetchAll();

    $status_counts = [
        'pending' => 0,
        'maybe' => 0,
        'played' => 0,
        'duplicate' => 0,
        'rejected' => 0,
    ];

    $parts = [];

    foreach ($rows as $row) {
        $status = strtolower((string)($row['status'] ?? 'pending'));

        if (!array_key_exists($status, $status_counts)) {
            $status_counts[$status] = 0;
        }

        $status_counts[$status]++;

        $parts[] = implode('|', [
            (int)$row['id'],
            $status,
            (string)($row['guest_name'] ?? ''),
            (string)($row['song_title'] ?? ''),
            (string)($row['artist'] ?? ''),
            (string)($row['message'] ?? ''),
        ]);
    }

    return sha1((int)$event_id . '|' . count($rows) . '|' . json_encode($status_counts) . '|' . implode('~', $parts));
}

$initial_fingerprint = request_queue_fingerprint((int)$event['id']);

admin_header('DJ Portal');
?>
<main class="touch-wrap">
<section class="touch-grid requests-layout-<?= h($requests_layout) ?>">
    <aside class="touch-panel active-event-panel">
      <div class="touch-panel-pad">
<h1 class="event-name"><?= h($event['event_name']) ?></h1>
        <p class="event-meta"><?= h($event['venue_name']) ?><br><?= h(event_type_label($event['event_type'] ?? 'public')) ?></p>

        <div class="event-info">
          <div class="event-info-row">
            <div class="event-info-icon">◷</div>
            <div>
              <div class="event-info-title">Event Time</div>
              <div class="event-info-value">
                <?= h(input_time($event['start_time'])) ?><?= !empty($event['end_time']) ? ' - ' . h(input_time($event['end_time'])) : '' ?>
                <?php if (!empty($event['end_time'])): ?>
                  <span class="mini-countdown" id="eventEndCountdown"
                    data-event-date="<?= h($event['event_date'] ?? '') ?>"
                    data-start-time="<?= h(input_time($event['start_time'] ?? '')) ?>"
                    data-end-time="<?= h(input_time($event['end_time'] ?? '')) ?>"
                  >-- left</span>
                <?php endif; ?>
              </div>
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
              <div class="event-info-value countdown"><?= h($event['requests_close_at'] ? date('H:i', strtotime($event['requests_close_at'])) : 'Not set') ?>
                <?php if (!empty($event['requests_close_at'])): ?>
                  <span class="mini-countdown request-close-mini-countdown" id="requestCloseCountdown"
                    data-target="<?= h(date('Y-m-d H:i:s', strtotime($event['requests_close_at']))) ?>"
                  >-- left</span>
                <?php endif; ?>
              </div>
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

    <section class="touch-panel request-queue-panel">
      <form class="request-queue-compact-header no-event-selector" method="get">
        <div>
          <h2 class="touch-panel-title">Request Queue</h2>
          <p class="touch-subtitle">Automatically showing the current or next event.</p>
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
        const total = typeof data.total_requests !== 'undefined' ? ' (' + data.total_requests + ' total)' : ''; text.textContent = 'The request queue changed at ' + (data.checked_at || 'now') + total + '.';
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


<!-- Header Event Summary JS -->
<script>
(function(){
  function pad(num){ return String(num).padStart(2, '0'); }

  function formatClock(date){
    return pad(date.getHours()) + ':' + pad(date.getMinutes());
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
    const parsed = new Date(value.replace(' ', 'T'));
    return isNaN(parsed.getTime()) ? null : parsed;
  }

  const summary = document.querySelector('.header-event-summary');
  if (!summary) return;

  const liveClock = document.getElementById('headerLiveClock');
  const status = document.getElementById('headerEventStatus');
  const requests = document.getElementById('headerRequestsCountdown');
  const ends = document.getElementById('headerEventCountdown');

  const eventDate = summary.dataset.eventDate || '';
  const startTime = summary.dataset.startTime || '';
  const endTime = summary.dataset.endTime || '';
  const closeRaw = summary.dataset.requestsClose || '';

  const startDate = parseLocalDateTime(eventDate, startTime);
  let endDate = parseLocalDateTime(eventDate, endTime);
  const closeDate = parseSqlDateTime(closeRaw);

  if (startDate && endDate && endDate <= startDate) {
    endDate.setDate(endDate.getDate() + 1);
  }

  function setState(element, className){
    if (!element) return;
    element.classList.remove('timer-warning','timer-ended','timer-live');
    if (className) element.classList.add(className);
  }

  function update(){
    const now = new Date();

    if (liveClock) liveClock.textContent = formatClock(now);

    if (status) {
      if (!startDate) {
        status.textContent = 'No start';
        setState(status, 'timer-warning');
      } else if (now < startDate) {
        status.textContent = 'Starts ' + formatCountdown(startDate - now);
        setState(status, '');
      } else if (endDate && now > endDate) {
        status.textContent = 'Ended';
        setState(status, 'timer-ended');
      } else {
        status.textContent = 'Live';
        setState(status, 'timer-live');
      }
    }

    if (requests) {
      if (!closeDate) {
        requests.textContent = 'Not set';
        setState(requests, 'timer-warning');
      } else if (now >= closeDate) {
        requests.textContent = 'Closed';
        setState(requests, 'timer-ended');
      } else {
        const remaining = closeDate - now;
        requests.textContent = formatCountdown(remaining);
        setState(requests, remaining <= 15 * 60 * 1000 ? 'timer-warning' : '');
      }
    }

    if (ends) {
      if (!endDate) {
        ends.textContent = 'Not set';
        setState(ends, 'timer-warning');
      } else if (now >= endDate) {
        ends.textContent = 'Ended';
        setState(ends, 'timer-ended');
      } else {
        const remaining = endDate - now;
        ends.textContent = formatCountdown(remaining);
        setState(ends, remaining <= 30 * 60 * 1000 ? 'timer-warning' : '');
      }
    }
  }

  update();
  window.setInterval(update, 1000);
})();
</script>

<!-- Active Event Countdown JS -->
<script>
(function(){
  const requestCloseEl = document.getElementById('requestCloseCountdown');
  const eventEndEl = document.getElementById('eventEndCountdown');

  function pad(value){
    return String(value).padStart(2, '0');
  }

  function parseDateTime(dateValue, timeValue){
    if (!dateValue || !timeValue) return null;
    const d = String(dateValue).split('-').map(Number);
    const t = String(timeValue).split(':').map(Number);
    if (d.length < 3 || t.length < 2) return null;
    return new Date(d[0], d[1] - 1, d[2], t[0], t[1], 0);
  }

  function parseTarget(value){
    if (!value) return null;
    const parts = String(value).trim().split(/[ T]/);
    if (parts.length < 2) return null;
    return parseDateTime(parts[0], parts[1]);
  }

  function formatRemaining(ms){
    if (ms <= 0) return '00:00:00';

    const totalSeconds = Math.floor(ms / 1000);

    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    const totalHours = (days * 24) + hours;

    return pad(totalHours) + ':' + pad(minutes) + ':' + pad(seconds);
  }

  function setState(el, state){
    if (!el) return;
    el.classList.remove('mini-warning', 'mini-ended', 'mini-live');
    if (state) el.classList.add(state);
  }

  function getEventEndTarget(){
    if (!eventEndEl) return null;
    const eventDate = eventEndEl.dataset.eventDate || '';
    const startTime = eventEndEl.dataset.startTime || '';
    const endTime = eventEndEl.dataset.endTime || '';
    const startDate = parseDateTime(eventDate, startTime);
    const endDate = parseDateTime(eventDate, endTime);
    if (!startDate || !endDate) return null;
    if (endDate <= startDate) endDate.setDate(endDate.getDate() + 1);
    return endDate;
  }

  const eventEndTarget = getEventEndTarget();
  const requestCloseTarget = requestCloseEl ? parseTarget(requestCloseEl.dataset.target || '') : null;

  function updateCountdown(el, target, warningMs, endedText){
    if (!el) return;
    if (!target) {
      el.textContent = 'time not set';
      setState(el, 'mini-warning');
      return;
    }
    const now = new Date();
    if (now >= target) {
      el.textContent = endedText;
      setState(el, 'mini-ended');
      return;
    }
    const remaining = target - now;
    el.textContent = formatRemaining(remaining);
    setState(el, remaining <= warningMs ? 'mini-warning' : 'mini-live');
  }

  function update(){
    updateCountdown(requestCloseEl, requestCloseTarget, 15 * 60 * 1000, 'closed');
    updateCountdown(eventEndEl, eventEndTarget, 30 * 60 * 1000, 'ended');
  }

  update();
  window.setInterval(update, 1000);
})();
</script>


<!-- Request Queue Update Indicator JS -->
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
        const total = typeof data.total_requests !== 'undefined' ? ' (' + data.total_requests + ' total)' : ''; text.textContent = 'The request queue changed at ' + (data.checked_at || 'now') + total + '.';
        banner.hidden = false;
      } else {
        lastFingerprint = data.fingerprint;
      }
    } catch (error) {
      // Silent by design.
    }
  }

  refreshButton.addEventListener('click', function(){
    window.location.reload();
  });

  window.setInterval(checkForUpdates, 10000);
})();
</script>

<?php admin_footer(); ?>
