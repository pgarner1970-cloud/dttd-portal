<?php
require_once __DIR__ . '/includes/db.php';
dttd_no_cache_headers();

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
     * Use the shared PHP live-event helper rather than duplicating the SQL time
     * window here. This keeps the homepage in step with the public event gate,
     * event page and live soundtrack API, especially after an event end time is
     * extended while the event is already running.
     */
    $active_event = active_event();

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
        SELECT *,
            CASE
                WHEN start_time IS NULL OR start_time = '' THEN TIMESTAMP(event_date, '23:59:59')
                WHEN end_time IS NULL OR end_time = '' THEN TIMESTAMP(event_date, start_time)
                WHEN end_time < start_time THEN TIMESTAMP(DATE_ADD(event_date, INTERVAL 1 DAY), end_time)
                ELSE TIMESTAMP(event_date, end_time)
            END AS public_end_at
        FROM events
        WHERE is_active = 1
          AND (event_type IS NULL OR LOWER(event_type) = 'public')
          AND event_date IS NOT NULL
          AND CASE
                WHEN start_time IS NULL OR start_time = '' THEN TIMESTAMP(event_date, '23:59:59')
                WHEN end_time IS NULL OR end_time = '' THEN TIMESTAMP(event_date, start_time)
                WHEN end_time < start_time THEN TIMESTAMP(DATE_ADD(event_date, INTERVAL 1 DAY), end_time)
                ELSE TIMESTAMP(event_date, end_time)
              END > NOW()
        ORDER BY event_date ASC, start_time ASC, id ASC
        LIMIT 4
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
$homepageEventAlreadyConnected = function_exists('dttd_event_from_access_cookie') && $active_event ? ((($cookieEvent = dttd_event_from_access_cookie(false)) && (int)($cookieEvent['id'] ?? 0) === (int)($active_event['id'] ?? 0))) : false;
// Do not expose the event code in public homepage links.
// Guests must enter the event code or scan the QR code shown at the venue.
// If this browser is already attached to the live event, go directly to the requested feature.
$eventAccessBaseUrl = '/event.php';
$liveEventInfoUrl = $homepageEventAlreadyConnected ? '/event.php' : '/event.php?next=event';
$liveEventRequestUrl = $homepageEventAlreadyConnected ? '/request.php' : '/event.php?next=request';
$liveEventUploadUrl = $homepageEventAlreadyConnected ? '/upload.php' : '/event.php?next=upload';
$liveEventSelfieUrl = $homepageEventAlreadyConnected ? '/upload.php?selfie=1' : '/event.php?next=selfie';

// Keep the older variable names as aliases for any remaining template branches.
$privateEventInfoUrl = $liveEventInfoUrl;
$privateEventRequestUrl = $liveEventRequestUrl;
$privateEventUploadUrl = $liveEventUploadUrl;
$privateEventSelfieUrl = $liveEventSelfieUrl;
$public_current = 'home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?= dttd_cache_meta_tags() ?>
  <title>Dance Thru the Decades Events</title>
  <meta name="description" content="Dance Thru the Decades Events — 60s, 70s, 80s, 90s and 00s party nights, DJ events, song requests and Facebook event updates.">
  <link rel="stylesheet" href="<?= h(dttd_asset_url('assets/public-site.css')) ?>">
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

        <?php if ($homepageEventAlreadyConnected && $active_event): ?>
          <div class="connected-event-prompt" aria-label="Connected event actions">
            <div class="connected-event-prompt__message">
              <span class="connected-event-prompt__badge">Connected to event</span>
              <strong>You are currently attached to <?= htmlspecialchars(home_public_event_title($active_event)) ?>.</strong>
              <p>Use the cards below to request songs, upload photos or view the event page.</p>
            </div>
        <?php endif; ?>

        <div class="option-one-action-strip dynamic-action-strip <?= $homepageEventAlreadyConnected ? 'is-connected-event' : '' ?>" data-homepage-state="<?= htmlspecialchars($homepage_state) ?>" aria-label="Event actions">
          <?php if ($homepage_state === 'public-event' && $active_event): ?>
            <a class="option-one-action-card primary-action" href="<?= htmlspecialchars($liveEventInfoUrl) ?>" data-event-action="event">
              <span class="option-one-icon">▣</span>
              <span>
                <strong>This Event</strong>
                <em>Venue, times and live event info</em>
              </span>
            </a>

            <a class="option-one-action-card" href="<?= htmlspecialchars($liveEventRequestUrl) ?>" data-event-action="request">
              <span class="option-one-icon">♪</span>
              <span>
                <strong>Request a Song</strong>
                <em>Available via event QR/code at the venue</em>
              </span>
            </a>

            <a class="option-one-action-card" href="<?= htmlspecialchars($liveEventUploadUrl) ?>" data-event-action="upload">
              <span class="option-one-icon">▧</span>
              <span>
                <strong>Upload Photos</strong>
                <em>Upload page opens from event QR/code</em>
              </span>
            </a>

            <a class="option-one-action-card mobile-selfie-action" href="<?= htmlspecialchars($liveEventSelfieUrl) ?>" data-event-action="selfie">
              <span class="option-one-icon">🤳</span>
              <span>
                <strong>Take a Selfie</strong>
                <em>Open your phone camera</em>
              </span>
            </a>

          <?php elseif ($homepage_state === 'private-event' && $active_event): ?>
            <a class="option-one-action-card primary-action" href="<?= htmlspecialchars($liveEventInfoUrl) ?>" data-event-action="event">
              <span class="option-one-icon">▣</span>
              <span>
                <strong>Event Info</strong>
                <em>Private guest event page</em>
              </span>
            </a>

            <a class="option-one-action-card" href="<?= htmlspecialchars($liveEventRequestUrl) ?>" data-event-action="request">
              <span class="option-one-icon">♪</span>
              <span>
                <strong>Guest Requests</strong>
                <em>Enter the venue code or scan the QR</em>
              </span>
            </a>

            <a class="option-one-action-card" href="<?= htmlspecialchars($liveEventUploadUrl) ?>" data-event-action="upload">
              <span class="option-one-icon">▧</span>
              <span>
                <strong>Upload Photos</strong>
                <em>Moderated before display</em>
              </span>
            </a>

            <a class="option-one-action-card mobile-selfie-action" href="<?= htmlspecialchars($liveEventSelfieUrl) ?>" data-event-action="selfie">
              <span class="option-one-icon">🤳</span>
              <span>
                <strong>Take a Selfie</strong>
                <em>Open your phone camera</em>
              </span>
            </a>

          <?php else: ?>
            <a class="option-one-action-card primary-action" href="/events">
              <span class="option-one-icon">▦</span>
              <span>
                <strong>Upcoming Events</strong>
                <em>Public nights and future dates</em>
              </span>
            </a>

            <a class="option-one-action-card" href="/gallery">
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

        <?php if ($homepageEventAlreadyConnected && $active_event): ?>
          </div>
        <?php endif; ?>

        <?php if ($homepage_state === 'private-event' && $active_event && !$homepageEventAlreadyConnected): ?>
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
            <a class="private-event-access-button" href="<?= htmlspecialchars($privateEventInfoUrl) ?>">Enter event code</a>
          </div>
        <?php elseif ($homepage_state === 'no-event'): ?>
          <p class="homepage-state-note">Song requests open automatically when an event is live.</p>
        <?php endif; ?>

        <?php if ($homepage_state !== 'no-event' && $active_event): ?>
          <section class="home-now-playing" data-now-playing-section data-endpoint="/api/public-now-playing.php" aria-label="Now playing and recently played tracks" hidden>
            <div class="home-now-playing-head">
              <span>Live soundtrack</span>
              <strong>Now playing</strong>
              <em data-now-playing-updated>Live update</em>
            </div>
            <div class="home-now-playing-window" data-now-playing-track aria-live="polite"></div>
            <p class="home-now-playing-empty" data-now-playing-empty hidden>Track history will appear here once music is playing.</p>
          </section>
        <?php endif; ?>
      </div>
    </section>
<section class="home-info-section" id="memories">
      <div class="home-info-grid <?= $homepage_state === 'no-event' ? '' : 'home-info-grid-secondary' ?>">
        <?php if ($homepage_state === 'no-event'): ?>
          <a class="home-info-card" href="/events">
            <span>📅</span>
            <h2>Public Nights</h2>
            <p>See upcoming Dance Thru The Decades events that are open to the public.</p>
          </a>

          <a class="home-info-card" href="/gallery">
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
          <a class="home-info-card" href="/events">
            <span>📅</span>
            <h2>Upcoming Events</h2>
            <p>See public nights, future dates and event details.</p>
          </a>

          <a class="home-info-card" href="/gallery">
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

    <?php if (!empty($homepage_events)): ?>
      <section class="home-coming-up" aria-label="Coming up events">
        <div class="home-coming-up-head">
          <span>Coming up</span>
          <h2>What’s happening</h2>
        </div>
        <div class="home-coming-up-track">
          <?php
            $fallbackCards = [
                [
                    'label' => 'More dates soon',
                    'title' => 'New events being planned',
                    'text' => 'Check back soon for more public party nights.',
                    'url' => '/events',
                ],
                [
                    'label' => 'Follow us',
                    'title' => 'See future announcements',
                    'text' => 'Facebook updates, event news and photo galleries.',
                    'url' => $facebookUrl,
                ],
                [
                    'label' => 'Get involved',
                    'title' => 'Requests, photos and party moments',
                    'text' => 'Use the event pages on the night for live features.',
                    'url' => '/gallery',
                ],
            ];

            $displayCards = [];
            foreach ($homepage_events as $i => $event) {
                $displayCards[] = ['type' => 'event', 'event' => $event, 'index' => $i];
            }

            $fallbackIndex = 0;
            while (count($displayCards) < 4 && isset($fallbackCards[$fallbackIndex])) {
                $displayCards[] = ['type' => 'fallback', 'card' => $fallbackCards[$fallbackIndex]];
                $fallbackIndex++;
            }
          ?>

          <?php foreach ($displayCards as $cardItem): ?>
            <?php if (($cardItem['type'] ?? '') === 'event'): ?>
              <?php
                $event = $cardItem['event'];
                $i = (int)($cardItem['index'] ?? 0);
                $label = $i === 0 ? 'Next event' : 'Coming soon';
                $title = home_public_event_title($event);
                $venue = home_public_event_venue($event);
                $date = dttd_public_event_date($event);
                $time = dttd_public_event_time_range($event);
              ?>
              <a class="home-coming-up-card" href="<?= htmlspecialchars(home_public_event_url($event)) ?>">
                <strong><?= htmlspecialchars($label) ?></strong>
                <span><?= htmlspecialchars($title) ?></span>
                <em><?= htmlspecialchars(trim($date . ($time ? ' · ' . $time : '') . ($venue ? ' · ' . $venue : ''))) ?></em>
              </a>
            <?php else: ?>
              <?php $fallback = $cardItem['card']; ?>
              <a class="home-coming-up-card is-placeholder" href="<?= htmlspecialchars($fallback['url']) ?>" <?= ($fallback['url'] === $facebookUrl) ? 'target="_blank" rel="noopener"' : '' ?>>
                <strong><?= htmlspecialchars($fallback['label']) ?></strong>
                <span><?= htmlspecialchars($fallback['title']) ?></span>
                <em><?= htmlspecialchars($fallback['text']) ?></em>
              </a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endif; ?>
      <?php require __DIR__ . '/includes/public-footer.php'; ?>
  </main>
<!-- homepage live-event action fallback -->

<script>
(function(){
  var strip = document.querySelector('.dynamic-action-strip[data-homepage-state="public-event"], .dynamic-action-strip[data-homepage-state="private-event"]');
  if (!strip) return;

  var connected = strip.classList.contains('is-connected-event');
  var targets = connected ? {
    event: '/event.php',
    request: '/request.php',
    upload: '/upload.php',
    selfie: '/upload.php?selfie=1'
  } : {
    event: '/event.php?next=event',
    request: '/event.php?next=request',
    upload: '/event.php?next=upload',
    selfie: '/event.php?next=selfie'
  };

  strip.querySelectorAll('[data-event-action]').forEach(function(link){
    var key = link.getAttribute('data-event-action');
    if (targets[key]) link.setAttribute('href', targets[key]);
  });
})();
</script>

<script src="<?= h(dttd_asset_url('assets/public-now-playing.js')) ?>"></script>
<?= dttd_bfcache_reload_script() ?>
</body>
</html>

