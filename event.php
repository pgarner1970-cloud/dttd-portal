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

function public_event_description($event) {
    $fields = [
        'public_description',
        'event_description',
        'description',
        'public_notes',
    ];

    foreach ($fields as $field) {
        if (!empty($event[$field])) {
            return trim((string)$event[$field]);
        }
    }

    return '';
}

function public_cancelled_message($event) {
    $fields = [
        'cancelled_message',
        'cancellation_message',
        'status_message',
    ];

    foreach ($fields as $field) {
        if (!empty($event[$field])) {
            return trim((string)$event[$field]);
        }
    }

    return 'This event has been cancelled. Please check our Facebook page or the venue for further updates.';
}

$event = null;
$error = '';
$slug = trim((string)($_GET['slug'] ?? ''));
$code = trim((string)($_GET['code'] ?? ''));
$accessedByCode = $code !== '';
$facebookUrl = 'https://www.facebook.com/profile.php?id=61579454050951';
$public_current = 'events';

try {
    if ($code !== '') {
        $stmt = db()->prepare("
            SELECT *
            FROM events
            WHERE event_code = ?
            LIMIT 1
        ");
        $stmt->execute([$code]);
        $event = $stmt->fetch();
    } elseif ($slug !== '') {
        $candidateEvents = db()->query("SELECT * FROM events")->fetchAll();

        foreach ($candidateEvents as $candidate) {
            $status = public_event_status($candidate);

            if (in_array($status, ['draft', 'private'], true)) {
                continue;
            }

            if (public_event_is_private($candidate)) {
                continue;
            }

            if (public_event_slug($candidate) === public_slugify($slug)) {
                $event = $candidate;
                break;
            }
        }
    }
} catch (Throwable $e) {
    $event = null;
    $error = 'Event details could not be loaded just now.';
}

if ($event) {
    $title = $event['event_name'] ?? $event['name'] ?? 'Dance Thru The Decades Event';
    $venue = $event['venue_name'] ?? $event['venue'] ?? '';
    $venueAddress = $event['venue_address'] ?? '';
    $postcode = $event['postcode'] ?? $event['venue_postcode'] ?? '';
    $venueFacebook = $event['venue_facebook_url'] ?? $event['facebook_url'] ?? '';
    $venueWebsite = $event['venue_website_url'] ?? $event['website_url'] ?? '';
    $ticketUrl = $event['ticketing_url'] ?? $event['tickets_url'] ?? $event['venue_ticket_url'] ?? '';
    $imageUrl = public_event_image_url($event['event_image'] ?? '');
    $description = public_event_description($event);
    $status = public_event_status($event);
    $isCancelled = $status === 'cancelled';
    $cancelledMessage = $isCancelled ? public_cancelled_message($event) : '';
    $mapQuery = trim($venue . ' ' . $venueAddress . ' ' . $postcode);
    $mapEmbedUrl = $mapQuery ? 'https://www.google.com/maps?q=' . urlencode($mapQuery) . '&output=embed' : '';
    $mapExternalUrl = $mapQuery ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($mapQuery) : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $event ? public_h($title) : 'Event Not Found' ?> | Dance Thru the Decades</title>
  <meta name="description" content="<?= $event ? public_h(($description ?: $title . ' at ' . $venue)) : 'Dance Thru the Decades event information.' ?>">
  <link rel="stylesheet" href="/assets/public-site.css?v=151">
</head>
<body class="homepage-option-one public-event-detail-page">
  <main class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>

    <?php if (!$event): ?>
      <section class="public-event-detail-hero">
        <div class="option-one-logo-shell public-list-logo">
          <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=151" alt="Dance Thru The Decades Events logo">
        </div>
        <p class="option-one-eyebrow">Event Portal</p>
        <h1 class="event-detail-title">Event Not Found</h1>
        <p class="option-one-subtitle"><?= public_h($error ?: 'This event link is not recognised.') ?></p>

        <article class="public-empty-card">
          <h2>Check the link or QR code</h2>
          <p>Please check that the event link is correct, or scan the QR code again at the venue.</p>
          <a class="public-neon-btn" href="/">Back to Website</a>
        </article>
      </section>
    <?php else: ?>
      <section class="public-event-detail-hero">
        <div class="option-one-logo-shell public-list-logo">
          <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=151" alt="Dance Thru The Decades Events logo">
        </div>
        <p class="option-one-eyebrow"><?= $isCancelled ? 'Cancelled Event' : 'Event Details' ?></p>
        <h1 class="event-detail-title"><?= public_h($title) ?></h1>

        <?php if ($venue): ?>
          <p class="option-one-subtitle"><?= public_h($venue) ?></p>
        <?php endif; ?>
      </section>

      <section class="public-event-detail-section">
        <?php if ($isCancelled): ?>
          <div class="public-cancelled-banner">
            <strong>Event Cancelled</strong>
            <span><?= public_h($cancelledMessage) ?></span>
          </div>
        <?php endif; ?>

        <article class="public-event-detail-card <?= $isCancelled ? 'is-cancelled' : '' ?>">
          <div class="public-event-detail-image <?= $imageUrl ? '' : 'public-event-placeholder' ?>">
            <?php if ($imageUrl): ?>
              <img src="<?= public_h($imageUrl) ?>" alt="<?= public_h($title) ?> event image" onerror="this.closest('.public-event-detail-image').classList.add('public-event-placeholder'); this.remove();">
            <?php else: ?>
              <span>♫</span>
            <?php endif; ?>
          </div>

          <div class="public-event-detail-body">
            <div class="public-event-date">
              <strong><?= public_h(public_event_date($event)) ?></strong>
              <?php if (public_event_time_range($event)): ?>
                <span><?= public_h(public_event_time_range($event)) ?></span>
              <?php endif; ?>
            </div>

            <?php if ($isCancelled): ?>
              <span class="public-status-pill cancelled">Cancelled</span>
            <?php endif; ?>

            <h2><?= public_h($title) ?></h2>

            <?php if ($description): ?>
              <div class="public-event-description">
                <?= nl2br(public_h($description)) ?>
              </div>
            <?php endif; ?>

            <?php if ($venue): ?>
              <p><strong>Venue:</strong> <?= public_h($venue) ?></p>
            <?php endif; ?>

            <?php if ($venueAddress || $postcode): ?>
              <p><strong>Address:</strong> <?= public_h(trim($venueAddress . ' ' . $postcode)) ?></p>
            <?php endif; ?>

            <div class="public-event-actions">
              <?php if (!$isCancelled && $ticketUrl): ?>
                <a class="public-neon-btn" href="<?= public_h($ticketUrl) ?>" target="_blank" rel="noopener">Tickets</a>
              <?php endif; ?>

              <a class="public-neon-btn subtle" href="<?= public_h($facebookUrl) ?>" target="_blank" rel="noopener">Our Facebook</a>

              <?php if ($venueFacebook): ?>
                <a class="public-neon-btn subtle" href="<?= public_h($venueFacebook) ?>" target="_blank" rel="noopener">Venue Facebook</a>
              <?php endif; ?>

              <?php if ($venueWebsite): ?>
                <a class="public-neon-btn subtle" href="<?= public_h($venueWebsite) ?>" target="_blank" rel="noopener">Venue Website</a>
              <?php endif; ?>

              <?php if ($mapExternalUrl): ?>
                <a class="public-neon-btn subtle" href="<?= public_h($mapExternalUrl) ?>" target="_blank" rel="noopener">Open Map</a>
              <?php endif; ?>
            </div>

            <?php if ($accessedByCode && !$isCancelled): ?>
              <div class="public-qr-only-note">
                <strong>At the event?</strong>
                <span>Song requests and guest features are available from the venue QR/event code.</span>
              </div>
            <?php endif; ?>
          </div>
        </article>

        <?php if ($mapEmbedUrl): ?>
          <section class="public-map-section">
            <h2>Venue Map</h2>
            <div class="public-map-frame">
              <iframe
                src="<?= public_h($mapEmbedUrl) ?>"
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade"
                title="<?= public_h($venue ?: 'Venue') ?> map"></iframe>
            </div>
          </section>
        <?php endif; ?>
      </section>
    <?php endif; ?>
      <?php require __DIR__ . '/includes/public-footer.php'; ?>
  </main>
</body>
</html>
