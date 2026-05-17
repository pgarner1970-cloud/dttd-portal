<?php
require_once __DIR__ . '/_auth.php';

$events = db()->query("
    SELECT e.*, 
    (SELECT COUNT(*) FROM song_requests sr WHERE sr.event_id=e.id) AS request_count 
    FROM events e 
    ORDER BY event_date DESC, id DESC
")->fetchAll();

admin_header('Events - DJ Portal');
?>
<main class="touch-wrap">
  <nav class="touch-tile-nav">
    <a class="touch-tile" href="/admin/"><span class="tile-icon">♫</span><span>Requests</span></a>
    <a class="touch-tile active" href="/admin/events.php"><span class="tile-icon">▦</span><span>Events</span></a>
  </nav>

  <section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Events</h1>
        <p class="touch-subtitle">Manage events, guest links and request queues.</p>
      </div>
      <div>
        <a class="touch-btn blue" href="/admin/event-edit.php">＋ Add Event</a>
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
            <a class="action-tile maybe" href="/admin/event-edit.php?id=<?= (int)$e['id'] ?>">
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
        <div class="empty-queue">
          <p>No events yet.</p>
          <a class="touch-btn blue" href="/admin/event-edit.php">Create First Event</a>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
