<?php
require_once __DIR__ . '/includes/db.php';
dttd_redirect_public_feature_to_primary_domain();

if (!function_exists('public_h')) {
    function public_h($value) {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
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
    return dttd_event_status_value($event);
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

function public_event_description($event) {
    foreach (['public_description', 'event_description', 'description', 'public_notes', 'notes'] as $field) {
        if (!empty($event[$field])) {
            return trim((string)$event[$field]);
        }
    }

    return '';
}

function public_cancelled_message($event) {
    foreach (['cancelled_message', 'cancellation_message', 'status_message'] as $field) {
        if (!empty($event[$field])) {
            return trim((string)$event[$field]);
        }
    }

    return 'This event has been cancelled. Please check our Facebook page or the venue for further updates.';
}

function public_find_event_by_slug($slug) {
    $slug = public_slugify($slug);

    if ($slug === '') {
        return null;
    }

    try {
        if (dttd_event_column_exists('public_slug')) {
            $stmt = db()->prepare("SELECT * FROM events WHERE public_slug = ? LIMIT 1");
            $stmt->execute([$slug]);
            $event = $stmt->fetch();
            if ($event) {
                return $event;
            }
        }

        $candidateEvents = db()->query("SELECT * FROM events")->fetchAll();
        foreach ($candidateEvents as $candidate) {
            $status = public_event_status($candidate);

            if (in_array($status, ['draft', 'private'], true)) {
                continue;
            }

            if (public_event_is_private($candidate)) {
                continue;
            }

            if (public_event_slug($candidate) === $slug) {
                return $candidate;
            }
        }
    } catch (Throwable $e) {
        return null;
    }

    return null;
}

function public_recent_played_requests($event_id) {
    if (!dttd_table_exists('song_requests')) {
        return [];
    }

    try {
        $stmt = db()->prepare("\n            SELECT song_title, artist, created_at\n            FROM song_requests\n            WHERE event_id = ? AND status = 'played'\n            ORDER BY updated_at DESC, created_at DESC, id DESC\n            LIMIT 8\n        ");
        $stmt->execute([(int)$event_id]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function public_pending_request_count($event_id) {
    if (!dttd_table_exists('song_requests')) {
        return 0;
    }

    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM song_requests WHERE event_id = ? AND status IN ('pending','maybe')");
        $stmt->execute([(int)$event_id]);
        return (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

$facebookUrl = defined('FACEBOOK_URL') ? FACEBOOK_URL : 'https://www.facebook.com/profile.php?id=61579454050951';
$public_current = 'events';
$gate_error = '';
$error = '';
$slug = trim((string)($_GET['slug'] ?? ''));
$event = null;
$hasEventAccess = false;
$publicDetailsMode = false;

$is_access_attempt = isset($_GET['code']) || isset($_GET['token']) || isset($_GET['access']) || isset($_POST['event_access_code']) || isset($_POST['event_code']) || isset($_POST['code']) || isset($_POST['token']) || isset($_POST['access']);

if ($is_access_attempt) {
    [$access_event, $access_error] = dttd_handle_event_access_submission('/event.php');
    $gate_error = $access_error;
}

if ($slug !== '') {
    $event = public_find_event_by_slug($slug);
    $publicDetailsMode = true;
}

if (!$event) {
    $event = dttd_event_from_access_cookie(false);
    $hasEventAccess = (bool)$event && dttd_event_access_allowed($event);
} else {
    $cookieEvent = dttd_event_from_access_cookie(false);
    $hasEventAccess = $cookieEvent && (int)$cookieEvent['id'] === (int)$event['id'] && dttd_event_access_allowed($cookieEvent);
}

$showGate = (!$event && !$publicDetailsMode);
$notFound = (!$event && $publicDetailsMode);

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
    $playedRequests = $hasEventAccess ? public_recent_played_requests((int)$event['id']) : [];
    $pendingCount = $hasEventAccess ? public_pending_request_count((int)$event['id']) : 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $event ? public_h($title) : ($notFound ? 'Event Not Found' : 'Join Event') ?> | Dance Thru the Decades</title>
  <meta name="description" content="<?= $event ? public_h(($description ?: $title . ' at ' . $venue)) : 'Dance Thru the Decades event portal.' ?>">
  <link rel="stylesheet" href="/assets/public-site.css?v=167">
</head>
<body class="homepage-option-one public-event-detail-page public-event-portal-page">
  <main class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>

    <?php if ($showGate): ?>
      <section class="public-event-detail-hero public-feature-hero">
        <div class="option-one-logo-shell public-list-logo">
          <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=152" alt="Dance Thru The Decades Events logo">
        </div>
        <p class="option-one-eyebrow">Event Portal</p>
        <h1 class="event-detail-title">Join This Event</h1>
        <p class="option-one-subtitle">Scan the QR code at the venue or enter the event code to continue.</p>
      </section>

      <section class="public-event-detail-section public-feature-section">
        <article class="public-empty-card public-access-card">
          <h2>Event access required</h2>
          <p>Enter the code displayed around the venue. We will remember this device until the event closes.</p>

          <?php if ($gate_error): ?>
            <div class="public-alert error"><?= public_h($gate_error) ?></div>
          <?php endif; ?>

          <form class="public-access-form" method="post" action="/event.php">
            <label for="event_access_code">Event code</label>
            <input id="event_access_code" name="event_access_code" inputmode="text" autocomplete="off" autocapitalize="characters" placeholder="Example: 5MKDP2" required>
            <button class="public-neon-btn" type="submit">Continue</button>
          </form>

          <div class="public-event-actions public-centred-actions">
            <a class="public-neon-btn subtle" href="/">Back to Website</a>
            <a class="public-neon-btn subtle" href="/events">Public Events</a>
          </div>
        </article>
      </section>

    <?php elseif ($notFound): ?>
      <section class="public-event-detail-hero">
        <div class="option-one-logo-shell public-list-logo">
          <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=152" alt="Dance Thru The Decades Events logo">
        </div>
        <p class="option-one-eyebrow">Event Portal</p>
        <h1 class="event-detail-title">Event Not Found</h1>
        <p class="option-one-subtitle">This event link is not recognised.</p>

        <article class="public-empty-card">
          <h2>Check the link or QR code</h2>
          <p>Please check that the event link is correct, or scan the QR code again at the venue.</p>
          <a class="public-neon-btn" href="/">Back to Website</a>
        </article>
      </section>

    <?php else: ?>
      <section class="public-event-detail-hero">
        <div class="option-one-logo-shell public-list-logo">
          <img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=152" alt="Dance Thru The Decades Events logo">
        </div>
        <p class="option-one-eyebrow"><?= $hasEventAccess ? 'Event Portal' : ($isCancelled ? 'Cancelled Event' : 'Event Details') ?></p>
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

        <?php if ($hasEventAccess && !$isCancelled): ?>
          <article class="public-feature-card public-event-hub-card">
            <div class="public-feature-card-header">
              <div>
                <span class="public-feature-kicker">You are connected to this event</span>
                <h2>What would you like to do?</h2>
              </div>
              <span class="public-connected-pill">Access remembered</span>
            </div>

            <div class="public-event-action-grid">
              <a class="public-event-action-tile" href="/request.php">
                <span>🎵</span>
                <strong>Request a Song</strong>
                <em>Send a request to the DJ queue</em>
              </a>
              <a class="public-event-action-tile" href="/gallery.php">
                <span>📸</span>
                <strong>Upload Photos</strong>
                <em>Uploads wait for moderation</em>
              </a>
              <a class="public-event-action-tile" href="<?= public_h($facebookUrl) ?>" target="_blank" rel="noopener">
                <span>f</span>
                <strong>Follow Us</strong>
                <em>Facebook updates and photos</em>
              </a>
            </div>
          </article>

          <div class="public-event-live-grid">
            <article class="public-feature-card public-live-card">
              <span class="public-feature-kicker">Queue</span>
              <h2>Requests tonight</h2>
              <p><?= $pendingCount > 0 ? public_h($pendingCount . ' request' . ($pendingCount === 1 ? '' : 's') . ' waiting for the DJ.') : 'Requests you send will appear in the DJ queue.' ?></p>
            </article>

            <article class="public-feature-card public-live-card">
              <span class="public-feature-kicker">Played</span>
              <h2>Recently played</h2>
              <?php if ($playedRequests): ?>
                <ul class="public-mini-list">
                  <?php foreach ($playedRequests as $played): ?>
                    <li><strong><?= public_h($played['song_title'] ?? '') ?></strong><span><?= public_h($played['artist'] ?? '') ?></span></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p>Played-track history will appear here once songs have been marked as played.</p>
              <?php endif; ?>
            </article>
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
              <strong><?= public_h(dttd_public_event_date($event)) ?></strong>
              <?php if (dttd_public_event_time_range($event)): ?>
                <span><?= public_h(dttd_public_event_time_range($event)) ?></span>
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
                <a class="public-neon-btn subtle" href="<?= public_h($venueFacebook) ?>" target="_blank" rel="noopener"><span class="venue-label">Venue</span><span class="venue-facebook-icon" aria-hidden="true">f</span></a>
              <?php endif; ?>

              <?php if ($venueWebsite): ?>
                <a class="public-neon-btn subtle" href="<?= public_h($venueWebsite) ?>" target="_blank" rel="noopener">Venue Website</a>
              <?php endif; ?>

              <?php if ($mapExternalUrl): ?>
                <a class="public-neon-btn subtle" href="<?= public_h($mapExternalUrl) ?>" target="_blank" rel="noopener">Open Map</a>
              <?php endif; ?>
            </div>

            <?php if (!$hasEventAccess && !$isCancelled): ?>
              <div class="public-qr-only-note">
                <strong>At the event?</strong>
                <span>Song requests and guest photo uploads open after you scan the venue QR code or enter the event code.</span>
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
