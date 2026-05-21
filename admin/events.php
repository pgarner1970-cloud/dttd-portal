


<?php
require_once __DIR__ . '/../includes/upload-paths.php';
require_once __DIR__ . '/_auth.php';

function dttd_event_image_column_exists() {
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        $stmt = db()->query("SHOW COLUMNS FROM events LIKE 'event_image'");
        $exists = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $exists = false;
    }

    return $exists;
}

function dttd_handle_event_image_upload($field_name = 'event_image_upload') {
    if (!isset($_FILES[$field_name]) || !is_array($_FILES[$field_name])) {
        return null;
    }

    $file = $_FILES[$field_name];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return null;
    }

    $tmp = $file['tmp_name'] ?? '';
    if (!$tmp || !is_uploaded_file($tmp)) {
        return null;
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    $mime = mime_content_type($tmp);
    if (!isset($allowed[$mime])) {
        return null;
    }

    $upload_dir = dirname(__DIR__) . 'https://dancethruthedecades.co.uk/uploads/events';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0775, true);
    }

    $filename = 'event-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    $target = $upload_dir . '/' . $filename;

    if (!move_uploaded_file($tmp, $target)) {
        return null;
    }

    return 'https://dancethruthedecades.co.uk/uploads/events/' . $filename;
}


$events = db()->query("
    SELECT e.*,
           (SELECT COUNT(*) FROM song_requests sr WHERE sr.event_id = e.id) AS request_count
    FROM events e
")->fetchAll();

usort($events, function($a, $b) {
    $state_a = dttd_calculated_event_state($a);
    $state_b = dttd_calculated_event_state($b);

    $rank = [
        'current' => 0,
        'upcoming' => 1,
        'past' => 2,
    ];

    $rank_a = $rank[$state_a] ?? 1;
    $rank_b = $rank[$state_b] ?? 1;

    if ($rank_a !== $rank_b) {
        return $rank_a <=> $rank_b;
    }

    $date_a = trim(($a['event_date'] ?? '') . ' ' . input_time($a['start_time'] ?? ''));
    $date_b = trim(($b['event_date'] ?? '') . ' ' . input_time($b['start_time'] ?? ''));

    $time_a = $date_a ? strtotime($date_a) : 0;
    $time_b = $date_b ? strtotime($date_b) : 0;

    if ($state_a === 'past') {
        // Past events newest first, oldest at the bottom.
        return $time_b <=> $time_a;
    }

    // Current/upcoming events soonest first.
    return $time_a <=> $time_b;
});

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

admin_header('Events - DJ Portal');
?>
<main class="touch-wrap">
<section class="touch-panel">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Events</h1>
        <p class="touch-subtitle">Current event is shown first, followed by upcoming and previous events.</p>
      </div>
      <div>
        <a class="touch-btn blue" href="event-edit.php">＋ Add Event</a>
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

          <div class="event-row-actions event-row-actions-only">
            
            <?php if (function_exists('dttd_event_image_column_exists') && dttd_event_image_column_exists() && !empty($e['event_image'])): ?>
              <div class="event-row-image event-row-image-actions">
                <img src="<?= h($e['event_image']) ?>" alt="<?= h($e['event_name']) ?> image">
              </div>
            <?php endif; ?>
            <a class="action-tile duplicate event-qr-link" href="event-qr.php?id=<?= (int)$e['id'] ?>">
                <span class="big-icon">▦</span>
                <span>QR</span>
              </a>

            <a class="action-tile maybe" href="event-edit.php?id=<?= (int)$e['id'] ?>">
              <span class="big-icon">⚙</span>
              <span>Edit</span>
            </a>
          </div>
        </article>
      <?php endforeach; ?>

      <?php if (!$events): ?>
        <div class="empty-queue">
          <p>No events yet.</p>
          <a class="touch-btn blue" href="event-edit.php">Create First Event</a>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
