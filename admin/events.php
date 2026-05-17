<?php
require_once __DIR__ . '/_auth.php';

$events = db()->query("
    SELECT e.*,
           (SELECT COUNT(*) FROM song_requests sr WHERE sr.event_id = e.id) AS request_count
    FROM events e
    ORDER BY
      CASE WHEN e.event_date IS NULL THEN 1 ELSE 0 END,
      e.event_date ASC,
      e.start_time ASC,
      e.id ASC
")->fetchAll();

function event_row_state_class($event) {
    $state = dttd_calculated_event_state($event);

    if ($state === 'current') {
        return 'row-current';
    }

    if ($state === 'past') {
        return 'row-past';
    }

    return 'row-future';
}

function event_row_state_label($event) {
    $state = dttd_calculated_event_state($event);

    if ($state === 'current') {
        return '<div class="current-label">Current</div>';
    }

    if ($state === 'past') {
        return '<div class="past-label">Past</div>';
    }

    return '<div class="upcoming-label">Upcoming</div>';
}

admin_header('Events - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Events</h1>
        <p class="touch-subtitle">All events are listed. Current event is calculated automatically from the event date and time.</p>
      </div>
      <div>
        <a class="touch-btn blue" href="/admin/event-edit.php">＋ Add Event</a>
      </div>
    </div>

    <div class="event-list">
      <?php foreach ($events as $e): ?>
        <article class="event-row-card <?= h(event_row_state_class($e)) ?>">
          <div class="event-row-date">
            <?= h($e['event_date'] ? date('d M', strtotime($e['event_date'])) : 'No date') ?>
            <small>
              <?= h(input_time($e['start_time'])) ?><?= !empty($e['end_time']) ? ' - ' . h(input_time($e['end_time'])) : '' ?>
            </small>
          </div>

          <div class="event-row-type">
            <span class="req-person"><?= h(strtoupper(substr($e['event_name'], 0, 1))) ?></span>
            <span><?= h(event_type_label($e['event_type'] ?? 'public')) ?></span>
          </div>

          <div class="event-row-title">
            <strong><?= h($e['event_name']) ?></strong>
            <span><?= h($e['venue_name']) ?></span>
            <?php if (!empty($e['event_code'])): ?>
              <span>Code: <?= h($e['event_code']) ?></span>
            <?php endif; ?>
          </div>

          <div class="event-row-close">
            <strong>Requests close</strong>
            <span><?= h($e['requests_close_at'] ? date('d/m/Y H:i', strtotime($e['requests_close_at'])) : 'Not set') ?></span>
          </div>

          <div class="event-row-state">
            <?= event_row_state_label($e) ?>
          </div>

          <div class="event-row-actions">
            <a class="action-tile maybe" href="/admin/event-edit.php?id=<?= (int)$e['id'] ?>">
              <span class="big-icon">⚙</span>
              <span>Edit</span>
            </a>
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
