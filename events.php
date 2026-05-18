<?php
require_once __DIR__ . '/includes/db.php';

function public_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function public_column_exists($table, $column) {
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

function public_slugify($value) {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
    $value = trim($value, '-');
    return $value ?: 'event';
}

function public_event_slug($event) {
    if (!empty($event['public_slug'])) {
        return public_slugify($event['public_slug']);
    }

    if (!empty($event['slug'])) {
        return public_slugify($event['slug']);
    }

    $parts = [
        $event['event_name'] ?? $event['name'] ?? 'event',
        $event['venue_name'] ?? $event['venue'] ?? '',
    ];

    if (!empty($event['event_date'])) {
        try {
            $parts[] = (new DateTime($event['event_date']))->format('Y-m-d');
        } catch (Throwable $e) {
            $parts[] = (string)$event['event_date'];
        }
    }

    return public_slugify(implode(' ', array_filter($parts)));
}

function public_event_status($event) {
    return strtolower(trim((string)($event['status'] ?? 'scheduled'))) ?: 'scheduled';
}

function public_event_is_private($event) {
    $visibility = strtolower((string)($event['queue_visibility'] ?? $event['visibility'] ?? 'public'));
    $eventType = strtolower((string)($event['event_type'] ?? ''));
    $status = public_event_status($event);

    return (
        $status === 'private'
        || $visibility === 'private'
        || str_contains($eventType, 'private')
        || str_contains($eventType, 'wedding')
        || str_contains($eventType, 'birthday')
    );
}

function public_event_date($event) {
    if (empty($event['event_date'])) {
        return '';
    }

    try {
        return (new DateTime($event['event_date']))->format('D j M Y');
    } catch (Throwable $e) {
        return (string)$event['event_date'];
    }
}

function public_event_time_range($event) {
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

function public_event_image_url($image) {
    $image = trim((string)$image);

    if ($image === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $image)) {
        return $image;
    }

    $image = ltrim($image, '/');

    if (str_starts_with($image, 'uploads/')) {
        return '/' . $image;
    }

    if (str_contains($image, '/')) {
        return '/' . $image;
    }

    return '/uploads/events/' . $image;
}

$facebookUrl = 'https://www.facebook.com/profile.php?id=61579454050951';
$public_current = 'events';

$where = ["1=1"];

if (public_column_exists('events', 'event_date')) {
    $where[] = "(event_date >= CURDATE() OR event_date IS NULL)";
}

if (public_column_exists('events', 'status')) {
    $where[] = "(status IS NULL OR LOWER(status) IN ('scheduled','live','cancelled'))";
}

if (public_column_exists('events', 'queue_visibility')) {
    $where[] = "(queue_visibility IS NULL OR LOWER(queue_visibility) = 'public')";
}

if (public_column_exists('events', 'visibility')) {
    $where[] = "(visibility IS NULL OR LOWER(visibility) = 'public')";
}

if (public_column_exists('events', 'event_type')) {
    $where[] = "(event_type IS NULL OR (LOWER(event_type) NOT LIKE '%private%' AND LOWER(event_type) NOT LIKE '%wedding%' AND LOWER(event_type) NOT LIKE '%birthday%'))";
}

$order = public_column_exists('events', 'event_date')
    ? "event_date ASC, start_time ASC, id ASC"
    : "id DESC";

$events = [];
$error = '';

try {
    $sql = "SELECT * FROM events WHERE " . implode(" AND ", $where) . " ORDER BY " . $order;
    $loadedEvents = db()->query($sql)->fetchAll();

    foreach ($loadedEvents as $loadedEvent) {
        if (public_event_is_private($loadedEvent)) {
            continue;
        }

        $status = public_event_status($loadedEvent);
        if (in_array($status, ['draft', 'private'], true)) {
            continue;
        }

        $events[] = $loadedEvent;
    }
} catch (Throwable $e) {
    $error = 'Events could not be loaded just now.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Upcoming Events | Dance Thru the Decades</title>
  <meta name="description" content="Upcoming public Dance Thru the Decades events, party nights and request-enabled DJ events.">
  <link rel="stylesheet" href="/assets/public-site.css?v=151">
</head>
<body class="homepage-option-one public-list-page">
  <main class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>

    <section class="public-list-hero">
      <div class="option-one-logo-shell public-list-logo">
        <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=151" alt="Dance Thru The Decades Events logo">
      </div>

      <p class="option-one-eyebrow">Public Nights</p>
      <h1>
        <span class="headline-main">UPCOMING</span>
        <span class="headline-the"><i></i><b>EVENTS</b><i></i></span>
      </h1>

      <p class="option-one-subtitle">Public nights, party events and future dates.</p>
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
      <?php else: ?>
        <div class="public-events-grid">
          <?php foreach ($events as $event): ?>
            <?php
              $title = $event['event_name'] ?? $event['name'] ?? 'Dance Thru The Decades Event';
              $venue = $event['venue_name'] ?? $event['venue'] ?? '';
              $imageUrl = public_event_image_url($event['event_image'] ?? '');
              $detailsLink = '/events/' . rawurlencode(public_event_slug($event));
              $venueFacebook = $event['venue_facebook_url'] ?? $event['facebook_url'] ?? '';
              $status = public_event_status($event);
            ?>

            <article class="public-event-card <?= $status === 'cancelled' ? 'is-cancelled' : '' ?>">
              <div class="public-event-image <?= $imageUrl ? '' : 'public-event-placeholder' ?>">
                <?php if ($imageUrl): ?>
                  <img src="<?= public_h($imageUrl) ?>" alt="<?= public_h($title) ?> event image" onerror="this.closest('.public-event-image').classList.add('public-event-placeholder'); this.remove();">
                <?php else: ?>
                  <span>♫</span>
                <?php endif; ?>
              </div>

              <div class="public-event-body">
                <div class="public-event-date">
                  <strong><?= public_h(public_event_date($event)) ?></strong>
                  <?php if (public_event_time_range($event)): ?>
                    <span><?= public_h(public_event_time_range($event)) ?></span>
                  <?php endif; ?>
                </div>

                <?php if ($status === 'cancelled'): ?>
                  <span class="public-status-pill cancelled">Cancelled</span>
                <?php endif; ?>

                <h2><?= public_h($title) ?></h2>

                <?php if ($venue): ?>
                  <p><?= public_h($venue) ?></p>
                <?php endif; ?>

                <div class="public-event-actions">
                  <a class="public-neon-btn" href="<?= public_h($detailsLink) ?>">Event Details</a>
                  <a class="public-neon-btn subtle" href="<?= public_h($facebookUrl) ?>" target="_blank" rel="noopener">Our Facebook</a>

                  <?php if ($venueFacebook): ?>
                    <a class="public-neon-btn subtle" href="<?= public_h($venueFacebook) ?>" target="_blank" rel="noopener">Venue Facebook</a>
                  <?php endif; ?>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
      <?php require __DIR__ . '/includes/public-footer.php'; ?>
  </main>
</body>
</html>
