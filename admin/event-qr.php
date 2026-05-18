<?php
require_once __DIR__ . '/_auth.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare("SELECT * FROM events WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$event = $stmt->fetch();

if (!$event) {
    admin_header('Event QR - DJ Portal');
    ?>
    <main class="touch-wrap">
      <section class="touch-panel">
        <div class="touch-panel-header">
          <div>
            <h1 class="touch-panel-title">Event QR Code</h1>
            <p class="touch-subtitle">Event not found.</p>
          </div>
          <a class="touch-btn" href="events.php">Back to Events</a>
        </div>
      </section>
    </main>
    <?php
    admin_footer();
    exit;
}

if (empty($event['event_code'])) {
    admin_header('Event QR - DJ Portal');
    ?>
    <main class="touch-wrap">
      <section class="touch-panel">
        <div class="touch-panel-header">
          <div>
            <h1 class="touch-panel-title">Event QR Code</h1>
            <p class="touch-subtitle">This event does not have an event code yet.</p>
          </div>
          <a class="touch-btn" href="/admin/event-edit.php?id=<?= (int)$event['id'] ?>">Edit Event</a>
        </div>
      </section>
    </main>
    <?php
    admin_footer();
    exit;
}

$public_request_url = rtrim(app_setting('public_request_base_url', ''), '/');
if ($public_request_url === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $public_request_url = $scheme . '://' . $host;
}

$event_request_url = $public_request_url . '/request.php?code=' . rawurlencode($event['event_code']);

admin_header('Event QR - DJ Portal');
?>
<main class="touch-wrap">
  <section class="touch-panel event-qr-page">
    <div class="touch-panel-header">
      <div>
        <h1 class="touch-panel-title">Event QR Code</h1>
        <p class="touch-subtitle"><?= h($event['event_name']) ?> — <?= h($event['venue_name']) ?></p>
      </div>
      <div class="settings-actions">
        <a class="touch-btn" href="events.php">Back to Events</a>
        <a class="touch-btn blue" href="/admin/event-edit.php?id=<?= (int)$event['id'] ?>">Edit Event</a>
      </div>
    </div>

    <div class="event-qr-body event-qr-standalone" data-qr-url="<?= h($event_request_url) ?>">
      <div class="event-code-panel">
        <span>Event code</span>
        <strong><?= h($event['event_code']) ?></strong>
        <small><?= h($event_request_url) ?></small>
      </div>

      <div class="event-qr-preview large">
        <canvas class="event-qr-canvas" width="360" height="360" aria-label="Event QR code"></canvas>
      </div>

      <div class="event-qr-actions">
        <button type="button" class="touch-btn blue qr-print-btn">Print QR</button>
        <button type="button" class="touch-btn qr-download-btn">Download PNG</button>
        <button type="button" class="touch-btn qr-copy-btn">Copy Link</button>
      </div>
    </div>
  </section>
</main>
<?php admin_footer(); ?>
