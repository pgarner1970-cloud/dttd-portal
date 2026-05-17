<?php
require_once __DIR__ . '/_auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_active_event'])) {
    $set_id = (int)$_POST['set_active_event'];

    $stmt = db()->prepare("SELECT id, event_date FROM events WHERE id = ?");
    $stmt->execute([$set_id]);
    $target = $stmt->fetch();

    if ($target && !empty($target['event_date']) && $target['event_date'] >= date('Y-m-d')) {
        db()->beginTransaction();
        db()->exec("UPDATE events SET is_active = 0");
        $stmt = db()->prepare("UPDATE events SET is_active = 1 WHERE id = ?");
        $stmt->execute([$set_id]);
        db()->commit();
    }

    header('Location: /admin/events.php');
    exit;
}

$events = db()->query("
    SELECT e.*, 
    (SELECT COUNT(*) FROM song_requests sr WHERE sr.event_id=e.id) AS request_count 
    FROM events e 
    ORDER BY event_date DESC, id DESC
")->fetchAll();

$today = date('Y-m-d');

/*
  Pick one visual current event only.
  This avoids multiple rows showing as Current if older data has more than one is_active flag.
*/
$current_event_id = null;
foreach ($events as $e) {
    if (!empty($e['is_active']) && !empty($e['event_date']) && $e['event_date'] >= $today) {
        $current_event_id = (int)$e['id'];
        break;
    }
}
if ($current_event_id === null) {
    foreach ($events as $e) {
        if (!empty($e['is_active'])) {
            $current_event_id = (int)$e['id'];
            break;
        }
    }
}

function event_row_state($event, $current_event_id, $today) {
    if ((int)$event['id'] === (int)$current_event_id) {
        return 'row-current';
    }

    if (!empty($event['event_date']) && $event['event_date'] < $today) {
        return 'row-past';
    }

    return 'row-future';
}

function can_make_current($event, $current_event_id, $today) {
    if ((int)$event['id'] === (int)$current_event_id) {
        return false;
    }

    if (empty($event['event_date'])) {
        return false;
    }

    return $event['event_date'] >= $today;
}

admin_header('Events - DJ Portal');
?>
<main class="touch-wrap">
  <nav class="touch-tile-nav">
    <a class="touch-tile" href="/admin/index.php"><span class="tile-icon">♫</span><span>Requests</span></a>
    <a class="touch-tile active" href="/admin/events.php"><span class="tile-icon">▦</span><span>Events</span></a>
  </nav>

  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Events</h1>
        <p class="touch-subtitle">Manage events and choose the current event.</p>
      </div>
      <div>
        <a class="touch-btn blue" href="/admin/event-edit.php">＋ Add Event</a>
      </div>
    </div>

    <div class="event-list">
      <?php foreach ($events as $e): ?>
        <?php
          $is_current = (int)$e['id'] === (int)$current_event_id;
          $is_past = !empty($e['event_date']) && $e['event_date'] < $today;
        ?>
        <article class="event-row-card <?= h(event_row_state($e, $current_event_id, $today)) ?>">
          <div class="event-row-date">
            <?= h($e['event_date'] ? date('d M', strtotime($e['event_date'])) : 'No date') ?>
            <small><?= h(input_time($e['start_time'])) ?><?= !empty($e['end_time']) ? ' - ' . h(input_time($e['end_time'])) : '' ?></small>
          </div>

          <div class="event-row-type">
            <span class="req-person"><?= h(strtoupper(substr($e['event_name'], 0, 1))) ?></span>
            <span><?= h(event_type_label($e['event_type'] ?? 'public')) ?></span>
          </div>

          <div class="event-row-title">
            <strong><?= h($e['event_name']) ?></strong>
            <span><?= h($e['venue_name']) ?></span>
          </div>

          <div class="event-row-close">
            <strong>Requests close</strong>
            <span><?= h($e['requests_close_at'] ? date('d/m/Y H:i', strtotime($e['requests_close_at'])) : 'Not set') ?></span>
          </div>

          <div class="event-row-actions">
            <a class="action-tile maybe" href="/admin/event-edit.php?id=<?= (int)$e['id'] ?>">
              <span class="big-icon">⚙</span>
              <span>Edit</span>
            </a>

            <?php if ($is_current): ?>
              <div class="current-label">Current</div>
            <?php elseif ($is_past): ?>
              <div class="past-label">Past</div>
            <?php elseif (can_make_current($e, $current_event_id, $today)): ?>
              <form method="post" class="inline-form">
                <button class="action-tile make-current-btn" name="set_active_event" value="<?= (int)$e['id'] ?>" type="submit">
                  <span class="big-icon">✓</span>
                  <span>Make Current</span>
                </button>
              </form>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>

      <?php if (!$events): ?>
        <div class="empty-queue">
          <p>No events yet.</p>
          <a class="touch-btn blue" href="/admin/event-edit.php">Create First Event</a>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
