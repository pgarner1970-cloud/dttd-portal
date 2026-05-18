<?php
require_once __DIR__ . '/includes/db.php';

function public_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

$event = null;
$error = '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$code = trim((string)($_GET['code'] ?? ''));
$accessedByCode = $code !== '';

try {
    if ($id > 0) {
        $stmt = db()->prepare("
            SELECT *
            FROM events
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $event = $stmt->fetch();

        if ($event) {
            $visibility = strtolower((string)($event['queue_visibility'] ?? $event['visibility'] ?? 'public'));
            $eventType = strtolower((string)($event['event_type'] ?? ''));

            $looksPrivate = (
                $visibility === 'private'
                || str_contains($eventType, 'private')
                || str_contains($eventType, 'wedding')
                || str_contains($eventType, 'birthday')
            );

            if ($looksPrivate) {
                $event = null;
            }
        }
    } elseif ($code !== '') {
        $stmt = db()->prepare("
            SELECT *
            FROM events
            WHERE event_code = ?
            LIMIT 1
        ");
        $stmt->execute([$code]);
        $event = $stmt->fetch();
    }
} catch (Throwable $e) {
    $event = null;
    $error = 'Event details could not be loaded just now.';
}

$facebookUrl = 'https://www.facebook.com/profile.php?id=61579454050951';

if ($event) {
    $title = $event['event_name'] ?? $event['name'] ?? 'Dance Thru The Decades Event';
    $venue = $event['venue_name'] ?? $event['venue'] ?? '';
    $venueAddress = $event['venue_address'] ?? '';
    $postcode = $event['postcode'] ?? $event['venue_postcode'] ?? '';
    $venueFacebook = $event['venue_facebook_url'] ?? $event['facebook_url'] ?? '';
    $venueWebsite = $event['venue_website_url'] ?? $event['website_url'] ?? '';
    $ticketUrl = $event['ticketing_url'] ?? $event['tickets_url'] ?? '';
    $imageUrl = public_event_image_url($event['event_image'] ?? '');
    $mapQuery = trim($venue . ' ' . $venueAddress . ' ' . $postcode);
    $mapUrl = $mapQuery ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($mapQuery) : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $event ? public_h($title) : 'Event Not Found' ?> | Dance Thru the Decades</title>
  <meta name="description" content="Dance Thru the Decades event information.">
  <link rel="stylesheet" href="/assets/public-site.css?v=148">
</head>
<body class="homepage-option-one public-event-detail-page">
  <main class="home-option-one">
    <a class="public-dj-login public-home-link" href="/">
      <span class="login-icon">⌂</span>
      <span>Home</span>
    </a>

    <?php if (!$event): ?>
      <section class="public-event-detail-hero">
        <div class="option-one-logo-shell public-list-logo">
          <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=148" alt="Dance Thru The Decades Events logo">
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
          <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=148" alt="Dance Thru The Decades Events logo">
        </div>
        <p class="option-one-eyebrow">Event Details</p>
        <h1 class="event-detail-title"><?= public_h($title) ?></h1>

        <?php if ($venue): ?>
          <p class="option-one-subtitle"><?= public_h($venue) ?></p>
        <?php endif; ?>
      </section>

      <section class="public-event-detail-section">
        <article class="public-event-detail-card">
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

            <h2><?= public_h($title) ?></h2>

            <?php if ($venue): ?>
              <p><strong>Venue:</strong> <?= public_h($venue) ?></p>
            <?php endif; ?>

            <?php if ($venueAddress || $postcode): ?>
              <p><strong>Address:</strong> <?= public_h(trim($venueAddress . ' ' . $postcode)) ?></p>
            <?php endif; ?>

            <?php if (!empty($event['notes'])): ?>
              <p><?= nl2br(public_h($event['notes'])) ?></p>
            <?php endif; ?>

            <div class="public-event-actions">
              <?php if ($ticketUrl): ?>
                <a class="public-neon-btn" href="<?= public_h($ticketUrl) ?>" target="_blank" rel="noopener">Tickets</a>
              <?php endif; ?>

              <?php if ($mapUrl): ?>
                <a class="public-neon-btn subtle" href="<?= public_h($mapUrl) ?>" target="_blank" rel="noopener">Map</a>
              <?php endif; ?>

              <a class="public-neon-btn subtle" href="<?= public_h($facebookUrl) ?>" target="_blank" rel="noopener">Our Facebook</a>

              <?php if ($venueFacebook): ?>
                <a class="public-neon-btn subtle" href="<?= public_h($venueFacebook) ?>" target="_blank" rel="noopener">Venue Facebook</a>
              <?php endif; ?>

              <?php if ($venueWebsite): ?>
                <a class="public-neon-btn subtle" href="<?= public_h($venueWebsite) ?>" target="_blank" rel="noopener">Venue Website</a>
              <?php endif; ?>
            </div>

            <?php if ($accessedByCode): ?>
              <div class="public-qr-only-note">
                <strong>At the event?</strong>
                <span>Song requests and guest features are available from the venue QR/event code.</span>
              </div>
            <?php endif; ?>
          </div>
        </article>
      </section>
    <?php endif; ?>
  </main>
</body>
</html>
