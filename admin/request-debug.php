<?php
require_once __DIR__ . '/_auth.php';

$events = db()->query("
    SELECT id, event_name, venue_name, event_date, start_time, end_time
    FROM events
    ORDER BY event_date DESC, start_time DESC, id DESC
")->fetchAll();

$current_event = function_exists('dttd_get_calculated_current_event') ? dttd_get_calculated_current_event() : null;
$selected_event_id = !empty($_GET['event']) ? (int)$_GET['event'] : ($current_event ? (int)$current_event['id'] : 0);

$loaded_counts = [
    'pending' => 0,
    'maybe' => 0,
    'played' => 0,
    'duplicate' => 0,
    'rejected' => 0,
];
$loaded_total = 0;

if ($selected_event_id) {
    $stmt = db()->prepare("
        SELECT LOWER(COALESCE(status, 'pending')) AS request_status, COUNT(*) AS request_total
        FROM song_requests
        WHERE event_id = ?
        GROUP BY LOWER(COALESCE(status, 'pending'))
    ");
    $stmt->execute([$selected_event_id]);

    foreach ($stmt->fetchAll() as $row) {
        $status = (string)($row['request_status'] ?? 'pending');
        $loaded_counts[$status] = (int)($row['request_total'] ?? 0);
    }

    $total_stmt = db()->prepare("SELECT COUNT(*) FROM song_requests WHERE event_id = ?");
    $total_stmt->execute([$selected_event_id]);
    $loaded_total = (int)$total_stmt->fetchColumn();
}

admin_header('Queue Debug - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Queue Update Debug</h1>
        <p class="touch-subtitle">Use this page to diagnose why the Requests page does or does not detect new requests.</p>
      </div>
      <div>
        <a class="touch-btn green" href="requests.php">Back to Requests</a>
      </div>
    </div>

    <div class="touch-panel-pad">
      <form method="get" class="debug-event-form">
        <label>
          <span>Event to test</span>
          <select name="event" onchange="this.form.submit()">
            <?php foreach ($events as $e): ?>
              <option value="<?= (int)$e['id'] ?>" <?= (int)$e['id'] === (int)$selected_event_id ? 'selected' : '' ?>>
                #<?= (int)$e['id'] ?> — <?= h($e['event_name']) ?> — <?= h($e['venue_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </form>

      <section class="queue-debug-card"
        data-event-id="<?= (int)$selected_event_id ?>"
        data-current-total="<?= (int)$loaded_total ?>"
        data-current-pending="<?= (int)$loaded_counts['pending'] ?>"
        data-current-maybe="<?= (int)$loaded_counts['maybe'] ?>"
        data-current-played="<?= (int)$loaded_counts['played'] ?>"
        data-current-duplicate="<?= (int)$loaded_counts['duplicate'] ?>"
        data-current-rejected="<?= (int)$loaded_counts['rejected'] ?>"
      >
        <div class="queue-debug-header">
          <h2>Live ping test</h2>
          <button type="button" id="queueDebugCheckNow">Check now</button>
        </div>

        <div class="queue-debug-grid">
          <div><span>Event ID</span><strong id="dbgEventId"><?= (int)$selected_event_id ?></strong></div>
          <div><span>Loaded counts</span><strong id="dbgLoadedCounts">--</strong></div>
          <div><span>Server counts</span><strong id="dbgServerCounts">--</strong></div>
          <div><span>Last checked</span><strong id="dbgLastChecked">Not checked</strong></div>
          <div><span>Status</span><strong id="dbgStatus">Waiting</strong></div>
          <div><span>Endpoint</span><strong id="dbgEndpoint">--</strong></div>
        </div>

        <div id="dbgChangeBanner" class="request-update-banner" hidden>
          <div>
            <strong>Change detected</strong>
            <span id="dbgChangeText">The server count differs from the loaded count.</span>
          </div>
          <button type="button" onclick="window.location.reload()">Reload debug page</button>
        </div>
      </section>
    </div>
  </section>
</main>

<script>
(function(){
  const card = document.querySelector('.queue-debug-card[data-event-id]');
  if (!card) return;

  const eventId = card.dataset.eventId;
  const endpoint = 'request-ping.php?event=' + encodeURIComponent(eventId);

  const loadedCounts = {
    pending: Number(card.dataset.currentPending || 0),
    maybe: Number(card.dataset.currentMaybe || 0),
    played: Number(card.dataset.currentPlayed || 0),
    duplicate: Number(card.dataset.currentDuplicate || 0),
    rejected: Number(card.dataset.currentRejected || 0)
  };
  const loadedTotal = Number(card.dataset.currentTotal || 0);

  const els = {
    loaded: document.getElementById('dbgLoadedCounts'),
    server: document.getElementById('dbgServerCounts'),
    last: document.getElementById('dbgLastChecked'),
    status: document.getElementById('dbgStatus'),
    endpoint: document.getElementById('dbgEndpoint'),
    button: document.getElementById('queueDebugCheckNow'),
    banner: document.getElementById('dbgChangeBanner'),
    changeText: document.getElementById('dbgChangeText')
  };

  function summary(total, counts){
    counts = counts || {};
    return 'T:' + Number(total || 0) +
      ' P:' + Number(counts.pending || 0) +
      ' M:' + Number(counts.maybe || 0) +
      ' Pl:' + Number(counts.played || 0) +
      ' D:' + Number(counts.duplicate || 0) +
      ' R:' + Number(counts.rejected || 0);
  }

  function changed(data){
    const counts = data.status_counts || {};
    if (Number(data.total_requests || 0) !== loadedTotal) return true;

    for (const key of Object.keys(loadedCounts)) {
      if (Number(counts[key] || 0) !== loadedCounts[key]) return true;
    }

    return false;
  }

  async function check(){
    els.status.textContent = 'Checking...';
    els.endpoint.textContent = endpoint;
    els.loaded.textContent = summary(loadedTotal, loadedCounts);

    try {
      const response = await fetch(endpoint + '&_=' + Date.now(), {
        cache: 'no-store',
        credentials: 'same-origin'
      });

      els.last.textContent = new Date().toLocaleTimeString('en-GB');

      if (!response.ok) {
        els.status.textContent = 'HTTP ' + response.status;
        return;
      }

      const raw = await response.text();
      let data;

      try {
        data = JSON.parse(raw);
      } catch (error) {
        els.status.textContent = 'Invalid JSON';
        console.error('Raw ping response:', raw);
        return;
      }

      if (!data.ok) {
        els.status.textContent = data.error || 'Ping failed';
        els.server.textContent = '--';
        return;
      }

      els.server.textContent = summary(data.total_requests, data.status_counts || {});

      if (changed(data)) {
        els.status.textContent = 'CHANGE DETECTED';
        els.banner.hidden = false;
        els.changeText.textContent = 'Loaded ' + summary(loadedTotal, loadedCounts) + ' → Server ' + summary(data.total_requests, data.status_counts || {});
      } else {
        els.status.textContent = 'No change';
        els.banner.hidden = true;
      }
    } catch (error) {
      els.status.textContent = 'Fetch error';
      console.error(error);
    }
  }

  if (els.button) els.button.addEventListener('click', check);

  check();
  window.setInterval(check, 5000);
})();
</script>
<?php admin_footer(); ?>
