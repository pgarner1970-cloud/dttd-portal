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
  data-current-pending="<?= (int)$counts['pending'] ?>"
  data-current-maybe="<?= (int)$counts['maybe'] ?>"
  data-current-played="<?= (int)$counts['played'] ?>"
  data-current-duplicate="<?= (int)$counts['duplicate'] ?>"
  data-current-rejected="<?= (int)$counts['rejected'] ?>"
  data-current-total="<?= (int)array_sum($counts) ?>"
>"
  data-request-fingerprint="<?= h($initial_fingerprint ?? '') ?>"
>"
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
      <small id="requestUpdateDebug">Waiting for queue check…</small>
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


function dttd_request_base_key($song_title, $artist) {
    return strtolower(trim((string)$song_title)) . '|' . strtolower(trim((string)$artist));
}

function dttd_new_request_group_id() {
    try {
        return 'grp_' . bin2hex(random_bytes(8));
    } catch (Throwable $e) {
        return 'grp_' . uniqid('', true);
    }
}

function dttd_group_id_column_exists() {
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = db()->query("SHOW COLUMNS FROM song_requests LIKE 'request_group_id'");
        $exists = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function dttd_ensure_request_group_ids($event_id) {
    if (!dttd_group_id_column_exists()) {
        return;
    }

    $stmt = db()->prepare("
        SELECT *
        FROM song_requests
        WHERE event_id = ?
        ORDER BY created_at ASC, id ASC
    ");
    $stmt->execute([(int)$event_id]);
    $requests = $stmt->fetchAll();

if (dttd_group_id_column_exists()) {
    dttd_ensure_request_group_ids((int)$event['id']);

    $stmt->execute([$event['id']]);
    $requests = $stmt->fetchAll();
}

    $open_groups = [];

    foreach ($requests as $request) {
        if (!empty($request['request_group_id'])) {
            continue;
        }

        $status = strtolower((string)($request['status'] ?? 'pending'));
        $base_key = dttd_request_base_key($request['song_title'] ?? '', $request['artist'] ?? '');

        if (in_array($status, ['pending', 'maybe', 'duplicate'], true)) {
            if (empty($open_groups[$base_key])) {
                $open_groups[$base_key] = dttd_new_request_group_id();
            }
            $group_id = $open_groups[$base_key];
        } else {
            $group_id = dttd_new_request_group_id();
        }

        $update = db()->prepare("UPDATE song_requests SET request_group_id = ? WHERE id = ? AND event_id = ?");
        $update->execute([$group_id, (int)$request['id'], (int)$event_id]);
    }
}

function dttd_open_group_id_for_request($event_id, $song_title, $artist) {
    if (!dttd_group_id_column_exists()) {
        return null;
    }

    $base_key = dttd_request_base_key($song_title, $artist);

    $stmt = db()->prepare("
        SELECT request_group_id
        FROM song_requests
        WHERE event_id = ?
        AND request_group_id IS NOT NULL
        AND request_group_id <> ''
        AND status IN ('pending','maybe','duplicate')
        AND CONCAT(LOWER(TRIM(song_title)), '|', LOWER(TRIM(artist))) = ?
        ORDER BY created_at ASC, id ASC
        LIMIT 1
    ");
    $stmt->execute([(int)$event_id, $base_key]);
    $existing = $stmt->fetchColumn();

    return $existing ?: dttd_new_request_group_id();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['merge_source_group'], $_POST['merge_target_group'])) {
    $source_group = (string)$_POST['merge_source_group'];
    $target_group = (string)$_POST['merge_target_group'];

    if (
        dttd_group_id_column_exists()
        && str_starts_with($source_group, 'gid:')
        && str_starts_with($target_group, 'gid:')
        && $source_group !== $target_group
    ) {
        $source_id = substr($source_group, 4);
        $target_id = substr($target_group, 4);

        // Only allow merging into open queue groups.
        $check = db()->prepare("
            SELECT COUNT(*)
            FROM song_requests
            WHERE event_id = ?
            AND request_group_id = ?
            AND status IN ('pending','maybe','duplicate')
        ");
        $check->execute([(int)$event['id'], $target_id]);
        $target_is_open = (int)$check->fetchColumn() > 0;

        if ($target_is_open) {
            $stmt = db()->prepare("
                UPDATE song_requests
                SET request_group_id = ?
                WHERE event_id = ?
                AND request_group_id = ?
            ");
            $stmt->execute([$target_id, (int)$event['id'], $source_id]);
        }
    }

    header('Location: /admin/requests.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_action'], $_POST['group_key'])) {
    $allowed = ['played','rejected','duplicate','maybe','pending'];
    $status = in_array($_POST['request_action'], $allowed, true) ? $_POST['request_action'] : 'pending';
    $reject_reason = null;

    if ($status === 'rejected') {
        $allowed_reasons = ['not_suitable','explicit','already_played','time_constraints','not_available'];
        $reason = (string)($_POST['reject_reason'] ?? 'not_suitable');
        $reject_reason = in_array($reason, $allowed_reasons, true) ? $reason : 'not_suitable';
    }

    $group_key = (string)$_POST['group_key'];

    if (dttd_group_id_column_exists() && str_starts_with($group_key, 'gid:')) {
        $group_id = substr($group_key, 4);

        $stmt = db()->prepare("
            UPDATE song_requests
            SET status = ?, reject_reason = ?
            WHERE event_id = ?
            AND request_group_id = ?
        ");
        $stmt->execute([$status, $reject_reason, (int)$event['id'], $group_id]);
    } else {
        $parts = explode('|', $group_key);
        $bucket = array_shift($parts);
        $song_artist_key = implode('|', $parts);

        if (str_starts_with($bucket, 'final-') && preg_match('/-(\\d+)$/', $bucket, $matches)) {
            $stmt = db()->prepare("
                UPDATE song_requests
                SET status = ?, reject_reason = ?
                WHERE event_id = ?
                AND id = ?
            ");
            $stmt->execute([$status, $reject_reason, (int)$event['id'], (int)$matches[1]]);
        } else {
            $stmt = db()->prepare("
                UPDATE song_requests
                SET status = ?, reject_reason = ?
                WHERE event_id = ?
                AND status IN ('pending','maybe','duplicate')
                AND CONCAT(LOWER(TRIM(song_title)), '|', LOWER(TRIM(artist))) = ?
            ");
            $stmt->execute([$status, $reject_reason, (int)$event['id'], $song_artist_key]);
        }
    }

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
    if (dttd_group_id_column_exists() && !empty($r['request_group_id'])) {
        $key = 'gid:' . (string)$r['request_group_id'];
    } else {
        $status_bucket = in_array(strtolower((string)($r['status'] ?? 'pending')), ['played', 'rejected'], true)
            ? 'final-' . strtolower((string)($r['status'] ?? 'pending')) . '-' . (int)($r['id'] ?? 0)
            : 'open';
        $key = $status_bucket . '|' . strtolower(trim($r['song_title'])) . '|' . strtolower(trim($r['artist']));
    }

    if (!isset($groups[$key])) {
        $groups[$key] = [
            'key' => $key,
            'group_id' => (dttd_group_id_column_exists() && !empty($r['request_group_id'])) ? (string)$r['request_group_id'] : (string)$key,
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
$initial_fingerprint = '';
/* v60 grouping rule note: grouping should keep played/rejected requests separate from open pending/maybe/duplicate groups. */

$merge_candidates = [];
foreach ($grouped_requests as $candidate_group) {
    if (!str_starts_with((string)$candidate_group['key'], 'gid:')) {
        continue;
    }

    if (!in_array((string)$candidate_group['status'], ['pending', 'maybe', 'duplicate'], true)) {
        continue;
    }

    $merge_candidates[] = [
        'key' => (string)$candidate_group['key'],
        'group_id' => (string)($candidate_group['group_id'] ?? str_replace('gid:', '', (string)$candidate_group['key'])),
        'song_title' => (string)$candidate_group['song_title'],
        'artist' => (string)$candidate_group['artist'],
        'status' => (string)$candidate_group['status'],
        'request_count' => count($candidate_group['items']),
        'created_at' => (string)$candidate_group['created_at'],
    ];
}

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

            <div class="req-actions">
              <?php
                $actions = [
                  'played' => ['icon' => '▶', 'label' => 'Played'],
                  'maybe' => ['icon' => '?', 'label' => 'Maybe'],
                  'duplicate' => ['icon' => '⧉', 'label' => 'Merge'],
                  'rejected' => ['icon' => '✕', 'label' => 'Reject'],
                ];
              ?>
              <?php foreach ($actions as $action => $meta): ?>
                <?php if ($action === 'rejected'): ?>
                  <button type="button" class="action-tile <?= h($action) ?> reject-modal-trigger" data-group-key="<?= h($group['key']) ?>">
                    <span class="big-icon"><?= h($meta['icon']) ?></span>
                    <span><?= h($meta['label']) ?></span>
                  </button>
                <?php elseif ($action === 'duplicate'): ?>
                  <button type="button" class="action-tile <?= h($action) ?> merge-modal-trigger"
                    data-group-key="<?= h($group['key']) ?>"
                    data-group-id="<?= h($group['group_id'] ?? str_replace('gid:', '', (string)$group['key'])) ?>"
                    data-song-title="<?= h($group['song_title']) ?>"
                    data-artist="<?= h($group['artist']) ?>">
                    <span class="big-icon"><?= h($meta['icon']) ?></span>
                    <span><?= h($meta['label']) ?></span>
                  </button>
                <?php else: ?>
                  <form method="post" class="req-action-form">
                    <input type="hidden" name="group_key" value="<?= h($group['key']) ?>">
                    <button class="action-tile <?= h($action) ?>" name="request_action" value="<?= h($action) ?>">
                      <span class="big-icon"><?= h($meta['icon']) ?></span>
                      <span><?= h($meta['label']) ?></span>
                    </button>
                  </form>
                <?php endif; ?>
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

      if (!lastFingerprint) {
        lastFingerprint = data.fingerprint;
        return;
      }

      if (data.fingerprint !== lastFingerprint) {
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
  const debug = document.getElementById('requestUpdateDebug');

  if (!wrap || !banner || !refreshButton) return;

  const eventId = wrap.dataset.eventId;
  const loadedCounts = {
    pending: Number(wrap.dataset.currentPending || 0),
    maybe: Number(wrap.dataset.currentMaybe || 0),
    played: Number(wrap.dataset.currentPlayed || 0),
    duplicate: Number(wrap.dataset.currentDuplicate || 0),
    rejected: Number(wrap.dataset.currentRejected || 0)
  };
  const loadedTotal = Number(wrap.dataset.currentTotal || 0);

  let hasUpdate = false;

  function summaryFromCounts(prefix, total, counts){
    counts = counts || {};
    return prefix + ' total ' + Number(total || 0) +
      ' | pending ' + Number(counts.pending || 0) +
      ', maybe ' + Number(counts.maybe || 0) +
      ', played ' + Number(counts.played || 0) +
      ', duplicate ' + Number(counts.duplicate || 0) +
      ', rejected ' + Number(counts.rejected || 0);
  }

  function countsChanged(data){
    const counts = data.status_counts || {};

    if (Number(data.total_requests || 0) !== loadedTotal) {
      return true;
    }

    for (const key of Object.keys(loadedCounts)) {
      if (Number(counts[key] || 0) !== loadedCounts[key]) {
        return true;
      }
    }

    return false;
  }

  function showUpdate(data){
    hasUpdate = true;
    text.textContent = 'The request queue changed at ' + (data.checked_at || 'now') + '.';
    if (debug) {
      debug.textContent =
        summaryFromCounts('Loaded', loadedTotal, loadedCounts) +
        ' → ' +
        summaryFromCounts('Server', data.total_requests, data.status_counts || {});
    }
    banner.hidden = false;
  }

  async function checkForUpdates(){
    if (document.hidden || hasUpdate) return;

    try {
      const response = await fetch('/admin/request-ping.php?event=' + encodeURIComponent(eventId) + '&_=' + Date.now(), {
        cache: 'no-store',
        credentials: 'same-origin'
      });

      if (!response.ok) {
        if (debug) debug.textContent = 'Queue check failed: HTTP ' + response.status;
        return;
      }

      const data = await response.json();

      if (!data.ok) {
        if (debug) debug.textContent = 'Queue check failed: ' + (data.error || 'unknown error');
        return;
      }

      if (countsChanged(data)) {
        showUpdate(data);
      } else if (debug) {
        debug.textContent =
          'Last checked ' + (data.checked_at || 'now') + ': no change. ' +
          summaryFromCounts('Server', data.total_requests, data.status_counts || {});
      }
    } catch (error) {
      if (debug) debug.textContent = 'Queue check error. See browser console.';
      console.error('Queue update check failed', error);
    }
  }

  refreshButton.addEventListener('click', function(){
    window.location.reload();
  });

  window.setTimeout(checkForUpdates, 1500);
  window.setInterval(checkForUpdates, 5000);
})();
</script>


<!-- Reject Reason Modal -->
<div class="dj-modal-backdrop" id="rejectReasonModal" hidden>
  <div class="dj-modal-card" role="dialog" aria-modal="true" aria-labelledby="rejectReasonTitle">
    <div class="dj-modal-header">
      <div>
        <h2 id="rejectReasonTitle">Reject request</h2>
        <p>Choose the reason for rejecting this request.</p>
      </div>
      <button type="button" class="dj-modal-close" id="rejectReasonCancelTop">×</button>
    </div>

    <form method="post" class="reject-reason-form">
      <input type="hidden" name="request_action" value="rejected">
      <input type="hidden" name="group_key" id="rejectReasonGroupKey" value="">

      <div class="reject-reason-grid">
        <button type="submit" name="reject_reason" value="not_suitable">
          <strong>Not suitable</strong>
          <span>Not right for this event, venue or crowd.</span>
        </button>

        <button type="submit" name="reject_reason" value="explicit">
          <strong>Explicit / inappropriate</strong>
          <span>Lyrics or content are not suitable.</span>
        </button>

        <button type="submit" name="reject_reason" value="already_played">
          <strong>Already played</strong>
          <span>The track has already been played tonight.</span>
        </button>

        <button type="submit" name="reject_reason" value="time_constraints">
          <strong>Time constraints</strong>
          <span>There is unlikely to be enough time.</span>
        </button>

        <button type="submit" name="reject_reason" value="not_available">
          <strong>Not available</strong>
          <span>The track is not available to play.</span>
        </button>
      </div>

      <div class="dj-modal-actions">
        <button type="button" class="touch-btn muted" id="rejectReasonCancelBottom">Cancel</button>
      </div>
    </form>
  </div>
</div>

<!-- Reject Reason Modal JS -->
<script>
(function(){
  const modal = document.getElementById('rejectReasonModal');
  const keyInput = document.getElementById('rejectReasonGroupKey');

  if (!modal || !keyInput) return;

  function openModal(groupKey){
    keyInput.value = groupKey || '';
    modal.hidden = false;
    document.body.classList.add('modal-open');

    const firstButton = modal.querySelector('.reject-reason-grid button');
    if (firstButton) firstButton.focus();
  }

  function closeModal(){
    modal.hidden = true;
    document.body.classList.remove('modal-open');
    keyInput.value = '';
  }

  document.addEventListener('click', function(event){
    const trigger = event.target.closest('.reject-modal-trigger');
    if (trigger) {
      event.preventDefault();
      event.stopPropagation();
      openModal(trigger.dataset.groupKey || '');
      return false;
    }

    if (event.target === modal) {
      closeModal();
    }
  }, true);

  ['rejectReasonCancelTop','rejectReasonCancelBottom'].forEach(function(id){
    const button = document.getElementById(id);
    if (button) button.addEventListener('click', closeModal);
  });

  document.addEventListener('keydown', function(event){
    if (event.key === 'Escape' && !modal.hidden) {
      closeModal();
    }
  });
})();
</script>


<!-- Merge Request Modal -->
<div class="dj-modal-backdrop" id="mergeRequestModal" hidden>
  <div class="dj-modal-card merge-modal-card" role="dialog" aria-modal="true" aria-labelledby="mergeRequestTitle">
    <div class="dj-modal-header">
      <div>
        <h2 id="mergeRequestTitle">Merge request</h2>
        <p id="mergeRequestSubtitle">Choose an open queue item to merge into.</p>
      </div>
      <button type="button" class="dj-modal-close" id="mergeRequestCancelTop">×</button>
    </div>

    <form method="post" class="merge-request-form">
      <input type="hidden" name="merge_source_group" id="mergeSourceGroup" value="">

      <div class="merge-target-list" id="mergeTargetList">
        <?php foreach ($merge_candidates as $candidate): ?>
          <label class="merge-target-card"
            data-group-key="<?= h($candidate['key']) ?>"
            data-group-id="<?= h($candidate['group_id'] ?? str_replace('gid:', '', (string)$candidate['key'])) ?>"
            data-song-title="<?= h(strtolower($candidate['song_title'])) ?>"
            data-artist="<?= h(strtolower($candidate['artist'])) ?>">
            <input type="radio" name="merge_target_group" value="<?= h($candidate['key']) ?>">
            <span>
              <strong><?= h($candidate['song_title']) ?></strong>
              <small><?= h($candidate['artist']) ?> · <?= h(ucfirst($candidate['status'])) ?> · <?= (int)$candidate['request_count'] ?> request<?= (int)$candidate['request_count'] === 1 ? '' : 's' ?></small>
            </span>
          </label>
        <?php endforeach; ?>
      </div>

      <div class="merge-empty" id="mergeEmptyMessage" hidden>
        No open groups are available to merge into.
      </div>

      <div class="dj-modal-actions">
        <button type="button" class="touch-btn muted" id="mergeRequestCancelBottom">Cancel</button>
        <button type="submit" class="touch-btn blue" id="mergeRequestSubmit">Merge Selected</button>
      </div>
    </form>
  </div>
</div>

<!-- Merge Request Modal JS -->
<script>
(function(){
  const modal = document.getElementById('mergeRequestModal');
  const sourceInput = document.getElementById('mergeSourceGroup');
  const subtitle = document.getElementById('mergeRequestSubtitle');
  const targetList = document.getElementById('mergeTargetList');
  const emptyMessage = document.getElementById('mergeEmptyMessage');
  const submitButton = document.getElementById('mergeRequestSubmit');

  if (!modal || !sourceInput || !targetList || !submitButton) return;

  function cleanGroup(value){
    return String(value || '').trim().replace(/^gid:/, '');
  }

  function normalise(value){
    return String(value || '')
      .toLowerCase()
      .replace(/&/g, ' and ')
      .replace(/[^a-z0-9]+/g, ' ')
      .replace(/\b(the|feat|featuring|ft|radio|edit|remix|version)\b/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function tokens(value){
    const n = normalise(value);
    return n ? n.split(' ').filter(function(token){ return token.length > 1; }) : [];
  }

  function tokenOverlap(a, b){
    const aa = new Set(tokens(a));
    const bb = new Set(tokens(b));
    if (!aa.size || !bb.size) return 0;
    let shared = 0;
    aa.forEach(function(token){ if (bb.has(token)) shared++; });
    return shared / Math.max(aa.size, bb.size);
  }

  function titleLooksClose(sourceTitle, candidateTitle){
    const source = normalise(sourceTitle);
    const candidate = normalise(candidateTitle);
    if (!source || !candidate) return false;
    if (source === candidate) return true;
    if (source.length >= 5 && candidate.includes(source)) return true;
    if (candidate.length >= 5 && source.includes(candidate)) return true;
    return tokenOverlap(source, candidate) >= 0.80;
  }

  function artistLooksClose(sourceArtist, candidateArtist){
    const source = normalise(sourceArtist);
    const candidate = normalise(candidateArtist);
    if (!source || !candidate) return false;
    if (source === candidate) return true;
    if (source.length >= 5 && candidate.includes(source)) return true;
    if (candidate.length >= 5 && source.includes(candidate)) return true;
    return tokenOverlap(source, candidate) >= 0.80;
  }

  function sameGroup(sourceKey, sourceId, card){
    const targetKey = card.dataset.groupKey || '';
    const targetId = card.dataset.groupId || '';

    const sKey = cleanGroup(sourceKey);
    const tKey = cleanGroup(targetKey);
    const sId = cleanGroup(sourceId);
    const tId = cleanGroup(targetId);

    return (
      (sourceKey && targetKey && sourceKey === targetKey) ||
      (sKey && tKey && sKey === tKey) ||
      (sId && tId && sId === tId) ||
      (sKey && tId && sKey === tId) ||
      (sId && tKey && sId === tKey)
    );
  }

  function isLikelyMerge(sourceTitle, sourceArtist, card){
    return titleLooksClose(sourceTitle, card.dataset.songTitle || '');
  }

  function scoreCandidate(card, sourceTitle, sourceArtist){
    let score = 0;
    if (normalise(sourceTitle) === normalise(card.dataset.songTitle)) score += 150;
    else if (titleLooksClose(sourceTitle, card.dataset.songTitle)) score += 100;
    if (artistLooksClose(sourceArtist, card.dataset.artist)) score += 60;
    score += Math.round(tokenOverlap(sourceTitle, card.dataset.songTitle) * 30);
    score += Math.round(tokenOverlap(sourceArtist, card.dataset.artist) * 15);
    return score;
  }

  function openModal(trigger){
    const sourceKey = trigger.dataset.groupKey || '';
    const sourceId = trigger.dataset.groupId || cleanGroup(sourceKey);
    const sourceTitle = trigger.dataset.songTitle || '';
    const sourceArtist = trigger.dataset.artist || '';

    sourceInput.value = sourceKey;

    if (subtitle) {
      subtitle.textContent = 'Merge "' + (sourceTitle || 'this request') + '" into a matching open queue item.';
    }

    const cards = Array.from(targetList.querySelectorAll('.merge-target-card'));
    let visibleCount = 0;

    cards.forEach(function(card){
      const isSelf = sameGroup(sourceKey, sourceId, card);
      const likely = isLikelyMerge(sourceTitle, sourceArtist, card);

      if (isSelf || !likely) {
        card.hidden = true;
        const input = card.querySelector('input');
        if (input) input.checked = false;
        return;
      }

      card.hidden = false;
      card.dataset.score = String(scoreCandidate(card, sourceTitle, sourceArtist));
      visibleCount++;
    });

    cards
      .filter(function(card){ return !card.hidden; })
      .sort(function(a, b){ return Number(b.dataset.score || 0) - Number(a.dataset.score || 0); })
      .forEach(function(card){ targetList.appendChild(card); });

    const firstVisible = cards.find(function(card){ return !card.hidden; });
    if (firstVisible) {
      const input = firstVisible.querySelector('input');
      if (input) input.checked = true;
    }

    if (emptyMessage) {
      emptyMessage.textContent = 'No matching open groups are available to merge into.';
      emptyMessage.hidden = visibleCount !== 0;
    }

    submitButton.disabled = visibleCount === 0;

    modal.hidden = false;
    document.body.classList.add('modal-open');
  }

  function closeModal(){
    modal.hidden = true;
    document.body.classList.remove('modal-open');
    sourceInput.value = '';
  }

  document.addEventListener('click', function(event){
    const trigger = event.target.closest('.merge-modal-trigger');
    if (trigger) {
      event.preventDefault();
      event.stopPropagation();
      openModal(trigger);
      return false;
    }

    if (event.target === modal) closeModal();
  }, true);

  ['mergeRequestCancelTop','mergeRequestCancelBottom'].forEach(function(id){
    const button = document.getElementById(id);
    if (button) button.addEventListener('click', closeModal);
  });

  document.addEventListener('keydown', function(event){
    if (event.key === 'Escape' && !modal.hidden) closeModal();
  });
})();
</script>

<?php admin_footer(); ?>
