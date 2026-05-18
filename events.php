<?php
require_once __DIR__ . '/includes/db.php';

function public_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function column_exists_public($table, $column) {
    static $cache = [];

    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        $cache[$key] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function event_time_range_public($event) {
    $start = trim((string)($event['start_time'] ?? ''));
    $end = trim((string)($event['end_time'] ?? ''));

    if ($start && strlen($start) >= 5) {
        $start = substr($start, 0, 5);
    }

    if ($end && strlen($end) >= 5) {
        $end = substr($end, 0, 5);
    }

    if ($start && $end) {
        return $start . ' - ' . $end;
    }

    return $start ?: $end;
}

function event_date_public($event) {
    $date = $event['event_date'] ?? null;

    if (!$date) {
        return '';
    }

    try {
        return (new DateTime($date))->format('D j M Y');
    } catch (Throwable $e) {
        return (string)$date;
    }
}

$where = [];
$where[] = "1=1";

if (column_exists_public('events', 'event_date')) {
    $where[] = "(event_date >= CURDATE() OR event_date IS NULL)";
}

if (column_exists_public('events', 'queue_visibility')) {
    $where[] = "(queue_visibility IS NULL OR LOWER(queue_visibility) = 'public')";
}

if (column_exists_public('events', 'visibility')) {
    $where[] = "(visibility IS NULL OR LOWER(visibility) = 'public')";
}

if (column_exists_public('events', 'event_type')) {
    $where[] = "(event_type IS NULL OR LOWER(event_type) NOT LIKE '%private%' AND LOWER(event_type) NOT LIKE '%wedding%' AND LOWER(event_type) NOT LIKE '%birthday%')";
}

$order = "id DESC";
if (column_exists_public('events', 'event_date')) {
    $order = "event_date ASC, start_time ASC, id ASC";
}

$events = [];
$error = '';

try {
    $sql = "SELECT * FROM events WHERE " . implode(" AND ", $where) . " ORDER BY " . $order;
    $events = db()->query($sql)->fetchAll();
} catch (Throwable $e) {
    $events = [];
    $error = 'Events could not be loaded just now.';
}

$facebookUrl = 'https://www.facebook.com/profile.php?id=61579454050951';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Upcoming Events | Dance Thru the Decades</title>
  <meta name="description" content="Upcoming public Dance Thru the Decades events, party nights and request-enabled DJ events.">
  <link rel="stylesheet" href="/assets/public-site.css?v=146">
</head>
<body class="homepage-option-one public-list-page">
  <main class="home-option-one">
    <a class="public-dj-login" href="/admin/">
      <span class="login-icon">♬</span>
      <span>DJ Login</span>
    </a>

    <section class="public-list-hero">
      <a class="public-back-link" href="/">← Home</a>

      <div class="option-one-logo-shell public-list-logo">
        <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=146" alt="Dance Thru The Decades Events logo">
      </div>

      <p class="option-one-eyebrow">Public Nights</p>
      <h1>
        <span class="headline-main">UPCOMING</span>
        <span class="headline-the"><i></i><b>EVENTS</b><i></i></span>
      </h1>

      <p class="option-one-subtitle">
        Public nights, party events and future dates.
      </p>
    </section>

    <section class="public-events-section">
      <?php if ($error): ?>
        <div class="public-alert error"><?= public_h($error) ?></div>
      <?php endif; ?>

      <?php if (!$events): ?>
        <article class="public-empty-card">
          <h2>No public events listed yet</h2>
          <p>Check back soon, or follow us on Facebook for updates and announcements.</p>
          <a class="public-neon-btn" href="<?= public_h($facebookUrl) ?>" target="_blank" rel="noopener">Follow us on Facebook</a>
        </article>
      <?php endif; ?>

      <div class="public-events-grid">
        <?php foreach ($events as $event): ?>
          <?php
            $title = $event['event_name'] ?? $event['name'] ?? 'Dance Thru The Decades Event';
            $venue = $event['venue_name'] ?? $event['venue'] ?? '';
            $image = $event['event_image'] ?? '';
            $publicId = $event['id'] ?? '';
            $link = $publicId ? '/event.php?id=' . urlencode((string)$publicId) : '#';
          ?>

          <article class="public-event-card">
            <?php if ($image): ?>
              <div class="public-event-image">
                <img src="/uploads/events/<?= public_h($image) ?>" alt="<?= public_h($title) ?> event image">
              </div>
            <?php else: ?>
              <div class="public-event-image public-event-placeholder">
                <span>♫</span>
              </div>
            <?php endif; ?>

            <div class="public-event-body">
              <div class="public-event-date">
                <strong><?= public_h(event_date_public($event)) ?></strong>
                <?php if (event_time_range_public($event)): ?>
                  <span><?= public_h(event_time_range_public($event)) ?></span>
                <?php endif; ?>
              </div>

              <h2><?= public_h($title) ?></h2>

              <?php if ($venue): ?>
                <p><?= public_h($venue) ?></p>
              <?php endif; ?>

              <div class="public-event-actions">
                <?php if ($publicId): ?>
                  <a class="public-neon-btn" href="<?= public_h($link) ?>">Event Details</a>
                <?php endif; ?>

                <a class="public-neon-btn subtle" href="<?= public_h($facebookUrl) ?>" target="_blank" rel="noopener">Facebook</a>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  </main>
</body>
</html>
