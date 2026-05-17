<?php
require_once __DIR__ . '/_auth.php';

function post_value($key, $default = '') {
    return $_POST[$key] ?? $default;
}

$edit = null;
if (!empty($_GET['id'])) {
    $edit = get_event((int)$_GET['id']);
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

$event_type = $edit['event_type'] ?? 'public';
$close_mins = $edit['requests_close_minutes'] ?? 30;
$qv = $edit['queue_visibility'] ?? 'venue';

admin_header($edit ? 'Edit Event - DJ Portal' : 'Add Event - DJ Portal');
?>
<main class="touch-wrap">
  <nav class="touch-tile-nav">
    <a class="touch-tile" href="/admin/"><span class="tile-icon">♫</span><span>Requests</span></a>
    <a class="touch-tile" href="/admin/events.php"><span class="tile-icon">▦</span><span>Events</span></a>
    <a class="touch-tile active" href="/admin/event-edit.php<?= $edit ? '?id='.(int)$edit['id'] : '' ?>"><span class="tile-icon">＋</span><span><?= $edit ? 'Edit' : 'Add' ?></span></a>
    <a class="touch-tile" href="/"><span class="tile-icon">⌂</span><span>Portal</span></a>
    <a class="touch-tile" href="/admin/?logout=1"><span class="tile-icon">⏻</span><span>Logout</span></a>
  </nav>

  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title"><?= $edit ? 'Edit Event' : 'Add Event' ?></h1>
        <p class="touch-subtitle">Set event details, timing and request behaviour.</p>
      </div>
      <a class="touch-btn" href="/admin/events.php">Back to Events</a>
    </div>

    <div class="touch-panel-pad">
      <form method="post" class="event-form-shell">
        <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

        <section class="form-section">
          <div class="form-section-header">
            <div class="form-section-icon">▦</div>
            <div>
              <h2 class="form-section-title">Event Details</h2>
              <p class="form-section-subtitle">Name, venue and type of event.</p>
            </div>
          </div>

          <div class="form-section-body">
            <div class="form-grid">
              <div class="form-field span-6">
                <label>Event name *</label>
                <input name="event_name" required value="<?= h($edit['event_name'] ?? '') ?>" placeholder="80s & 90s Party Night">
              </div>

              <div class="form-field span-6">
                <label>Venue name *</label>
                <input name="venue_name" required value="<?= h($edit['venue_name'] ?? '') ?>" placeholder="The Crown Inn">
              </div>

              <div class="form-field span-4">
                <label>Event type</label>
                <select name="event_type">
                  <option value="public" <?= $event_type==='public'?'selected':'' ?>>Public Night</option>
                  <option value="private_party" <?= $event_type==='private_party'?'selected':'' ?>>Private Party</option>
                  <option value="wedding" <?= $event_type==='wedding'?'selected':'' ?>>Wedding</option>
                  <option value="corporate" <?= $event_type==='corporate'?'selected':'' ?>>Corporate Event</option>
                </select>
              </div>

              <div class="form-field span-8">
                <label>Notes</label>
                <textarea name="notes" placeholder="Internal event notes"><?= h($edit['notes'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
        </section>

        <section class="form-section">
          <div class="form-section-header">
            <div class="form-section-icon">◷</div>
            <div>
              <h2 class="form-section-title">Timing</h2>
              <p class="form-section-subtitle">End times earlier than start times are treated as after midnight.</p>
            </div>
          </div>

          <div class="form-section-body">
            <div class="form-grid">
              <div class="form-field span-3">
                <label>Event date</label>
                <input type="date" name="event_date" value="<?= h($edit['event_date'] ?? '') ?>">
              </div>

              <div class="form-field span-3">
                <label>Start time</label>
                <input type="time" name="start_time" value="<?= h(input_time($edit['start_time'] ?? '19:30')) ?>">
              </div>

              <div class="form-field span-3">
                <label>End time</label>
                <input type="time" name="end_time" value="<?= h(input_time($edit['end_time'] ?? '01:30')) ?>">
                <small>Example: 19:30 to 01:30 spans midnight.</small>
              </div>

              <div class="form-field span-3">
                <label>Close requests before end</label>
                <select name="requests_close_minutes">
                  <?php foreach ([15,30,45,60] as $m): ?>
                    <option value="<?= $m ?>" <?= (int)$close_mins===$m?'selected':'' ?>><?= $m ?> minutes</option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
        </section>

        <section class="form-section">
          <div class="form-section-header">
            <div class="form-section-icon">⚙</div>
            <div>
              <h2 class="form-section-title">Portal Behaviour</h2>
              <p class="form-section-subtitle">Control request visibility and whether this event is available.</p>
            </div>
          </div>

          <div class="form-section-body">
            <div class="form-grid">
              <div class="form-field span-4">
                <label>Queue visibility</label>
                <select name="queue_visibility">
                  <option value="venue" <?= $qv==='venue'?'selected':'' ?>>Defined by venue</option>
                  <option value="public" <?= $qv==='public'?'selected':'' ?>>Public</option>
                  <option value="private" <?= $qv==='private'?'selected':'' ?>>Private / admin only</option>
                </select>
              </div>

              <div class="form-field span-8">
                <label>Availability</label>
                <label class="form-check-card">
                  <input type="checkbox" name="is_active" value="1" <?= !empty($edit['is_active']) ? 'checked' : '' ?>>
                  <span>
                    <strong>Active / available for portal selection</strong>
                    <span>Active events can accept requests during their availability window.</span>
                  </span>
                </label>
              </div>
            </div>

            <details class="form-advanced">
              <summary>Advanced timing override</summary>
              <div class="details-body">
                <label class="form-check-card">
                  <input type="checkbox" name="manual_override" value="1">
                  <span>
                    <strong>Use manual override values</strong>
                    <span>Only use this for unusual events or testing.</span>
                  </span>
                </label>

                <div class="form-grid" style="margin-top:16px">
                  <div class="form-field span-4">
                    <label>Portal available from</label>
                    <input type="datetime-local" name="manual_portal_available_from" value="<?= h(html_dt($edit['portal_available_from'] ?? null)) ?>">
                  </div>

                  <div class="form-field span-4">
                    <label>Portal available until</label>
                    <input type="datetime-local" name="manual_portal_available_until" value="<?= h(html_dt($edit['portal_available_until'] ?? null)) ?>">
                  </div>

                  <div class="form-field span-4">
                    <label>Requests close at</label>
                    <input type="datetime-local" name="manual_requests_close_at" value="<?= h(html_dt($edit['requests_close_at'] ?? null)) ?>">
                  </div>
                </div>
              </div>
            </details>
          </div>
        </section>

        <div class="form-actions">
          <a class="touch-btn" href="/admin/events.php">Cancel</a>
          <?php if ($edit): ?>
            <a class="touch-btn green" href="/admin/?event=<?= (int)$edit['id'] ?>">View Requests</a>
            <a class="touch-btn purple" href="/request.php?event=<?= (int)$edit['id'] ?>" target="_blank">Guest Link</a>
          <?php endif; ?>
          <button class="touch-btn blue" type="submit"><?= $edit ? 'Save Event' : 'Create Event' ?></button>
        </div>
      </form>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
