<?php
require_once __DIR__ . '/includes/db.php';

$active_event = null;
$active_event_is_public = false;
$active_event_is_private = false;
$homepage_state = 'no-event';

function home_public_slugify($value) {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
    $value = trim($value, '-');
    return $value ?: 'event';
}

function home_public_event_slug($event) {
    if (!empty($event['public_slug'])) {
        return home_public_slugify($event['public_slug']);
    }

    if (!empty($event['slug'])) {
        return home_public_slugify($event['slug']);
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

    return home_public_slugify(implode(' ', array_filter($parts)));
}

function home_public_event_url($event) {
    return '/event/' . rawurlencode(home_public_event_slug($event));
}

function home_public_event_title($event) {
    return trim((string)($event['event_name'] ?? $event['name'] ?? 'Event')) ?: 'Event';
}

function home_public_event_venue($event) {
    return trim((string)($event['venue_name'] ?? $event['venue'] ?? ''));
}

function home_event_is_private($event) {
    $visibility = strtolower((string)($event['queue_visibility'] ?? $event['visibility'] ?? 'public'));
    $eventType = strtolower((string)($event['event_type'] ?? ''));
    $status = strtolower((string)($event['status'] ?? ''));

    return (
        $status === 'private'
        || $visibility === 'private'
        || str_contains($eventType, 'private')
        || str_contains($eventType, 'wedding')
        || str_contains($eventType, 'corporate')
        || str_contains($eventType, 'birthday')
    );
}

$homepage_events = [];

try {
    /*
     * Important:
     * is_active means the event is available/selectable in the portal.
     * It does NOT mean the event is happening right now.
     *
     * The public homepage should only show live-event actions when the current
     * date/time sits inside the event start/end window.
     *
     * End times earlier than start times are treated as after midnight.
     */
    $active_event = db()->query("
        SELECT *,
            TIMESTAMP(event_date, start_time) AS live_start_at,
            CASE
                WHEN end_time IS NULL OR end_time = '' THEN TIMESTAMP(event_date, start_time)
                WHEN end_time < start_time THEN TIMESTAMP(DATE_ADD(event_date, INTERVAL 1 DAY), end_time)
                ELSE TIMESTAMP(event_date, end_time)
            END AS live_end_at
        FROM events
        WHERE is_active = 1
          AND event_date IS NOT NULL
          AND start_time IS NOT NULL
          AND start_time <> ''
          AND NOW() >= TIMESTAMP(event_date, start_time)
          AND NOW() <= CASE
                WHEN end_time IS NULL OR end_time = '' THEN DATE_ADD(TIMESTAMP(event_date, start_time), INTERVAL 6 HOUR)
                WHEN end_time < start_time THEN TIMESTAMP(DATE_ADD(event_date, INTERVAL 1 DAY), end_time)
                ELSE TIMESTAMP(event_date, end_time)
          END
        ORDER BY event_date ASC, start_time ASC, id ASC
        LIMIT 1
    ")->fetch();

    if ($active_event) {
        $visibility = strtolower((string)($active_event['queue_visibility'] ?? $active_event['visibility'] ?? 'public'));
        $eventType = strtolower((string)($active_event['event_type'] ?? ''));

        $active_event_is_private = (
            $visibility === 'private'
            || str_contains($eventType, 'private')
            || str_contains($eventType, 'wedding')
            || str_contains($eventType, 'birthday')
        );

        $active_event_is_public = !$active_event_is_private;
        $homepage_state = $active_event_is_private ? 'private-event' : 'public-event';
    }
} catch (Throwable $e) {
    $active_event = null;
    $homepage_state = 'no-event';
}

try {
    $stmt = db()->query("
        SELECT *
        FROM events
        WHERE is_active = 1
          AND (event_type IS NULL OR LOWER(event_type) = 'public')
          AND event_date IS NOT NULL
          AND event_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        ORDER BY event_date ASC, start_time ASC, id ASC
        LIMIT 8
    "
    );
    $homepage_events = array_values(array_filter($stmt->fetchAll() ?: [], function ($event) {
        return !home_event_is_private($event);
    }));
} catch (Throwable $e) {
    $homepage_events = [];
}

$facebookUrl = 'https://www.facebook.com/profile.php?id=61579454050951';
$privateEventName = ($homepage_state === 'private-event' && $active_event) ? home_public_event_title($active_event) : '';
$privateEventType = ($homepage_state === 'private-event' && $active_event) ? strtolower((string)($active_event['event_type'] ?? 'private_party')) : '';
// Do not expose the private event code in public homepage links.
// Guests must enter the event code or scan the QR code shown at the venue.
$privateEventRequestUrl = '/request.php';
$privateEventUploadUrl = '/upload.php';
$privateEventInfoUrl = '/event.php';
$public_current = 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dance Thru the Decades Events</title>
  <meta name="description" content="Dance Thru the Decades Events — 60s, 70s, 80s, 90s and 00s party nights, DJ events, song requests and Facebook event updates.">
  <link rel="stylesheet" href="/assets/public-site.css?v=284">
</head>
<body class="homepage-option-one">
  <main class="home-option-one">
    <?php require __DIR__ . '/includes/public-nav.php'; ?>
<section class="option-one-hero">
<div class="option-one-crowd" aria-hidden="true"></div>
      <div class="option-one-floor-glow" aria-hidden="true"></div>

      <div class="option-one-inner">
        <div class="option-one-logo-shell"><img class="option-one-logo" src="/assets/dttd-logo-inner.png?v=152" alt="Dance Thru The Decades Events logo"></div>

        <p class="option-one-eyebrow">60s · 70s · 80s · 90s · 00s</p>

        <h1>
          <span class="headline-main">DANCE THRU</span>
          <span class="headline-the"><i></i><b>THE</b><i></i></span>
          <span class="headline-main">DECADES</span>
          <span class="events-line">EVENTS</span>
        </h1>

        <p class="option-one-subtitle">
          Feel-good party nights, classic floor-fillers and moments worth sharing.
        </p>

        <div class="option-one-action-strip dynamic-action-strip" data-homepage-state="<?= htmlspecialchars($homepage_state) ?>" aria-label="Event actions">
          <?php if ($homepage_state === 'public-event' && $active_event && !empty($active_event['event_code'])): ?>
            <a class="option-one-action-card primary-action" href="/request.php">
              <span class="option-one-icon">♪</span>
              <span>
                <strong>Request a Song</strong>
                <em>Available via event QR/code at the venue</em>
              </span>
            </a>

            <a class="option-one-action-card" href="/event.php">
              <span class="option-one-icon">▣</span>
              <span>
                <strong>This Event</strong>
                <em>Venue, times and event info</em>
              </span>
            </a>

            <a class="option-one-action-card" href="/upload.php">
              <span class="option-one-icon">▧</span>
              <span>
                <strong>Upload Photos</strong>
                <em>Upload page opens from event QR/code</em>
              </span>
            </a>

          <?php elseif ($homepage_state === 'private-event' && $active_event && !empty($active_event['event_code'])): ?>
            <a class="option-one-action-card primary-action" href="/request.php">
              <span class="option-one-icon">♪</span>
              <span>
                <strong>Guest Requests</strong>
                <em>Enter the venue code or scan the QR</em>
              </span>
            </a>

            <a class="option-one-action-card" href="/upload.php">
              <span class="option-one-icon">▧</span>
              <span>
                <strong>Upload Photos</strong>
                <em>Moderated before display</em>
              </span>
            </a>

            <a class="option-one-action-card" href="/event.php">
              <span class="option-one-icon">▣</span>
              <span>
                <strong>Event Info</strong>
                <em>Private guest event page</em>
              </span>
            </a>

          <?php else: ?>
            <a class="option-one-action-card primary-action" href="/events.php">
              <span class="option-one-icon">▦</span>
              <span>
                <strong>Upcoming Events</strong>
                <em>Public nights and future dates</em>
              </span>
            </a>

            <a class="option-one-action-card" href="/gallery.php">
              <span class="option-one-icon">▧</span>
              <span>
                <strong>Photos & Memories</strong>
                <em>View and share event moments</em>
              </span>
            </a>

            <a class="option-one-action-card" href="<?= htmlspecialchars($facebookUrl) ?>" target="_blank" rel="noopener">
              <span class="option-one-icon">f</span>
              <span>
                <strong>Follow Us</strong>
                <em>Updates, photos and announcements</em>
              </span>
            </a>
          <?php endif; ?>
        </div>

        <?php if ($homepage_state === 'private-event' && $active_event): ?>
          <div class="private-event-access-card <?= $privateEventType === 'wedding' ? 'is-wedding' : ($privateEventType === 'private_party' ? 'is-party' : '') ?>">
            <div class="private-event-access-decor" aria-hidden="true">
              <?php if ($privateEventType === 'wedding'): ?>
                <span>♡</span><span>⚭</span><span>♡</span>
              <?php elseif ($privateEventType === 'private_party'): ?>
                <span>✦</span><span>✺</span><span>✦</span>
              <?php else: ?>
                <span>✦</span><span>•</span><span>✦</span>
              <?php endif; ?>
            </div>
            <div>
              <strong>Tonight we are hosting a private event<?= $privateEventName !== '' ? ' for ' . htmlspecialchars($privateEventName) : '' ?>.</strong>
              <p>Guests can enter the venue code or scan the QR code displayed at the event.</p>
            </div>
            <a class="private-event-access-button" href="/request.php">Enter event code</a>
          </div>
        <?php elseif ($homepage_state === 'no-event'): ?>
          <p class="homepage-state-note">Song requests open automatically when an event is live.</p>
        <?php endif; ?>
      </div>
    </section>
<section class="home-info-section" id="memories">
      <div class="home-info-grid <?= $homepage_state === 'no-event' ? '' : 'home-info-grid-secondary' ?>">
        <?php if ($homepage_state === 'no-event'): ?>
          <a class="home-info-card" href="/events.php">
            <span>📅</span>
            <h2>Public Nights</h2>
            <p>See upcoming Dance Thru The Decades events that are open to the public.</p>
          </a>

          <a class="home-info-card" href="/gallery.php">
            <span>📸</span>
            <h2>Photos & Memories</h2>
            <p>Gallery uploads will be reviewed before they appear publicly on the site.</p>
          </a>

          <a class="home-info-card" href="<?= htmlspecialchars($facebookUrl) ?>" target="_blank" rel="noopener">
            <span>👍</span>
            <h2>Follow Us</h2>
            <p>Keep up with upcoming nights, event photos, playlists and announcements.</p>
          </a>

        <?php else: ?>
          <a class="home-info-card" href="/events.php">
            <span>📅</span>
            <h2>Upcoming Events</h2>
            <p>See public nights, future dates and event details.</p>
          </a>

          <a class="home-info-card" href="/gallery.php">
            <span>📸</span>
            <h2>Photo Gallery</h2>
            <p>View approved event photos and shared memories.</p>
          </a>

          <a class="home-info-card" href="<?= htmlspecialchars($facebookUrl) ?>" target="_blank" rel="noopener">
            <span>👍</span>
            <h2>Follow Us</h2>
            <p>Updates, photos, playlists and announcements.</p>
          </a>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($homepage_state !== 'no-event' && !empty($homepage_events)): ?>
      <section class="home-coming-up" aria-label="Coming up events">
        <div class="home-coming-up-head">
          <span>Coming up</span>
          <h2>What’s happening</h2>
        </div>
        <div class="home-coming-up-track">
          <?php for ($loop = 0; $loop < 2; $loop++): ?>
            <?php foreach ($homepage_events as $i => $event): ?>
              <?php
                $isLive = $active_event && (int)($event['id'] ?? 0) === (int)($active_event['id'] ?? 0);
                $label = $isLive ? 'Live now' : ($i === 0 ? 'Next event' : 'Coming soon');
                $title = home_public_event_title($event);
                $venue = home_public_event_venue($event);
                $date = dttd_public_event_date($event);
                $time = dttd_public_event_time_range($event);
              ?>
              <a class="home-coming-up-card <?= $isLive ? 'is-live' : '' ?>" href="<?= htmlspecialchars(home_public_event_url($event)) ?>" <?= $loop ? 'aria-hidden="true" tabindex="-1"' : '' ?>>
                <strong><?= htmlspecialchars($label) ?></strong>
                <span><?= htmlspecialchars($title) ?></span>
                <em><?= htmlspecialchars(trim($date . ($time ? ' · ' . $time : '') . ($venue ? ' · ' . $venue : ''))) ?></em>
              </a>
            <?php endforeach; ?>
          <?php endfor; ?>
        </div>
      </section>
    <?php endif; ?>
      <?php require __DIR__ . '/includes/public-footer.php'; ?>
  </main>
</body>
</html>

