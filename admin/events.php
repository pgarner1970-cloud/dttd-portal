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

admin_header('Events - DJ Portal');
?>
<main class="touch-wrap">
  <nav class="touch-tile-nav">
    <a class="touch-tile" href="/admin/"><span class="tile-icon">♫</span><span>Requests</span></a>
    <a class="touch-tile active" href="/admin/events.php"><span class="tile-icon">▦</span><span>Events</span></a>
    <a class="touch-tile" href="/"><span class="tile-icon">⌂</span><span>Portal</span></a>
    <a class="touch-tile" href="/admin/?logout=1"><span class="tile-icon">⏻</span><span>Logout</span></a>
  </nav>

  <section class="touch-grid">
    <aside class="touch-panel">
      <div class="touch-panel-header">
        <div>
          <h1 class="touch-panel-title"><?= $edit ? 'Edit Event' : 'Create Event' ?></h1>
          <p class="touch-subtitle">Timing, type and request behaviour</p>
        </div>
      </div>

      <div class="touch-panel-pad">
        <form method="post">
          <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

          <label>Event name *</label>
          <input name="event_name" required value="<?= h($edit['event_name'] ?? '') ?>" placeholder="80s & 90s Party Night">

          <label>Venue name *</label>
          <input name="venue_name" required value="<?= h($edit['venue_name'] ?? '') ?>" placeholder="The Crown Inn">

          <label>Event type</label>
          <select name="event_type">
            <option value="public" <?= $event_type==='public'?'selected':'' ?>>Public Night</option>
            <option value="private_party" <?= $event_type==='private_party'?'selected':'' ?>>Private Party</option>
            <option value="wedding" <?= $event_type==='wedding'?'selected':'' ?>>Wedding</option>
            <option value="corporate" <?= $event_type==='corporate'?'selected':'' ?>>Corporate Event</option>
          </select>

          <label>Event date</label>
          <input type="date" name="event_date" value="<?= h($edit['event_date'] ?? '') ?>">

          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
            <div>
              <label>Start time</label>
              <input type="time" name="start_time" value="<?= h(input_time($edit['start_time'] ?? '19:30')) ?>">
            </div>
            <div>
              <label>End time</label>
              <input type="time" name="end_time" value="<?= h(input_time($edit['end_time'] ?? '01:30')) ?>">
            </div>
          </div>

          <label>Close requests before end</label>
          <select name="requests_close_minutes">
            <?php foreach ([15,30,45,60] as $m): ?>
              <option value="<?= $m ?>" <?= (int)$close_mins===$m?'selected':'' ?>><?= $m ?> minutes</option>
            <?php endforeach; ?>
          </select>

          <label>Queue visibility</label>
          <select name="queue_visibility">
            <option value="venue" <?= $qv==='venue'?'selected':'' ?>>Defined by venue</option>
            <option value="public" <?= $qv==='public'?'selected':'' ?>>Public</option>
            <option value="private" <?= $qv==='private'?'selected':'' ?>>Private / admin only</option>
          </select>

          <details>
            <summary>Advanced timing override</summary>
            <div class="details-body">
              <label><input type="checkbox" name="manual_override" value="1"> Use manual override values</label>

              <label>Portal available from</label>
              <input type="datetime-local" name="manual_portal_available_from" value="<?= h(html_dt($edit['portal_available_from'] ?? null)) ?>">

              <label>Portal available until</label>
              <input type="datetime-local" name="manual_portal_available_until" value="<?= h(html_dt($edit['portal_available_until'] ?? null)) ?>">

              <label>Requests close at</label>
              <input type="datetime-local" name="manual_requests_close_at" value="<?= h(html_dt($edit['requests_close_at'] ?? null)) ?>">
            </div>
          </details>

          <label>Notes</label>
          <textarea name="notes" placeholder="Internal event notes"><?= h($edit['notes'] ?? '') ?></textarea>

          <label style="display:flex;gap:8px;align-items:center;margin:12px 0">
            <input type="checkbox" name="is_active" value="1" <?= !empty($edit['is_active']) ? 'checked' : '' ?>>
            Active / available for portal selection
          </label>

          <div class="sidebar-actions">
            <button class="touch-btn blue full" type="submit"><?= $edit ? 'Save Event' : 'Create Event' ?></button>
            <?php if ($edit): ?><a class="touch-btn full" href="/admin/events.php">Cancel Edit</a><?php endif; ?>
          </div>
        </form>
      </div>
    </aside>

    <section class="touch-panel">
      <div class="touch-panel-header">
        <div>
          <h2 class="touch-panel-title">Events</h2>
          <p class="touch-subtitle">Manage guest links and request queues</p>
        </div>
      </div>

      <div class="request-list">
        <?php foreach ($events as $e): ?>
          <article class="request-card status-<?= $e['is_active'] ? 'played' : 'rejected' ?>">
            <div class="req-time">
              <?= h($e['event_date'] ? date('d M', strtotime($e['event_date'])) : 'No date') ?>
              <small><?= h(input_time($e['start_time'])) ?> - <?= h(input_time($e['end_time'])) ?></small>
            </div>

            <div class="req-guest">
              <span class="req-person"><?= h(strtoupper(substr($e['event_name'], 0, 1))) ?></span>
              <span><?= h(event_type_label($e['event_type'] ?? 'public')) ?></span>
            </div>

            <div>
              <div class="req-track-title"><?= h($e['event_name']) ?></div>
              <div class="req-track-artist"><?= h($e['venue_name']) ?></div>
            </div>

            <div class="req-message">
              Requests close:<br>
              <?= h($e['requests_close_at'] ? date('d/m/Y H:i', strtotime($e['requests_close_at'])) : 'Not set') ?>
            </div>

            <div class="req-status">
              <?= $e['is_active'] ? '<span class="status-badge played">Active</span>' : '<span class="status-badge rejected">Inactive</span>' ?>
              <br><br>
              <span class="status-badge duplicate"><?= (int)$e['request_count'] ?> requests</span>
            </div>

            <div class="req-actions">
              <a class="action-tile maybe" href="/admin/events.php?edit=<?= (int)$e['id'] ?>">
                <span class="big-icon">⚙</span>
                <span>Edit</span>
              </a>
              <a class="action-tile played" href="/admin/?event=<?= (int)$e['id'] ?>">
                <span class="big-icon">♫</span>
                <span>Requests</span>
              </a>
              <a class="action-tile duplicate" href="/request.php?event=<?= (int)$e['id'] ?>" target="_blank">
                <span class="big-icon">🔗</span>
                <span>Guest</span>
              </a>
            </div>
          </article>
        <?php endforeach; ?>

        <?php if (!$events): ?>
          <div class="empty-queue">No events yet.</div>
        <?php endif; ?>
      </div>
    </section>
  </section>
</main>
<?php admin_footer(); ?>
