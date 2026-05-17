<?php
require_once __DIR__ . '/_auth.php';

$events = db()->query("
    SELECT e.*, 
    (SELECT COUNT(*) FROM song_requests sr WHERE sr.event_id=e.id) AS request_count 
    FROM events e 
    ORDER BY event_date DESC, id DESC
")->fetchAll();

$today = date('Y-m-d');

function event_row_state($event) {
    $state = dttd_calculated_event_state($event);

    if ($state === 'current') {
        return 'row-current';
    }

    if ($state === 'past') {
        return 'row-past';
    }

    return 'row-future';
}

admin_header('Events - DJ Portal');
?>
<main class="touch-wrap">
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
          $calculated_state = dttd_calculated_event_state($e);
          $is_current = $calculated_state === 'current';
          $is_past = $calculated_state === 'past';
        ?>
        <article class="event-row-card <?= h(event_row_state($e)) ?>">
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
            <?php if (!empty($e['event_code'])): ?><span>Code: <?= h($e['event_code']) ?></span><?php endif; ?>
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
            <?php if (!empty($e['event_code'])): ?>
              <a class="action-tile duplicate" href="/event.php?code=<?= h($e['event_code']) ?>" target="_blank">
                <span class="big-icon">🔗</span>
                <span>Guest</span>
              </a>
            <?php endif; ?>

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
